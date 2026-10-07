<?php
/**
 * REST API（フェーズ2）：勤務区分・ルール・希望（休み）の収集。
 * お客様IDは常にログイン中ユーザーの所属から決める（SS_Repo::current()）。
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Rest_Plan {
    const NS = 'shift/v1';
    const MAX_PERIOD_DAYS = 62;

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'routes'));
    }

    public static function routes() {
        $read = array(__CLASS__, 'can_read_plan');
        $write = array(__CLASS__, 'can_write_plan');

        register_rest_route(self::NS, '/patterns', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'list_patterns'), 'permission_callback' => $read),
            array('methods' => 'POST', 'callback' => array(__CLASS__, 'create_pattern'), 'permission_callback' => $write),
        ));
        register_rest_route(self::NS, '/patterns/preset', array(
            array('methods' => 'POST', 'callback' => array(__CLASS__, 'preset_patterns'), 'permission_callback' => $write),
        ));
        register_rest_route(self::NS, '/patterns/(?P<id>\d+)', array(
            array('methods' => 'PUT,PATCH', 'callback' => array(__CLASS__, 'update_pattern'), 'permission_callback' => $write),
            array('methods' => 'DELETE', 'callback' => array(__CLASS__, 'deactivate_pattern'), 'permission_callback' => $write),
        ));

        register_rest_route(self::NS, '/rules', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'list_rules'), 'permission_callback' => $read),
            array('methods' => 'POST', 'callback' => array(__CLASS__, 'create_rule'), 'permission_callback' => $write),
        ));
        register_rest_route(self::NS, '/rules/global', array(
            array('methods' => 'PUT,POST', 'callback' => array(__CLASS__, 'save_global_rules'), 'permission_callback' => $write),
        ));
        register_rest_route(self::NS, '/rules/preset', array(
            array('methods' => 'POST', 'callback' => array(__CLASS__, 'preset_rules'), 'permission_callback' => $write),
        ));
        register_rest_route(self::NS, '/rules/(?P<id>\d+)', array(
            array('methods' => 'PUT,PATCH', 'callback' => array(__CLASS__, 'update_rule'), 'permission_callback' => $write),
            array('methods' => 'DELETE', 'callback' => array(__CLASS__, 'delete_rule'), 'permission_callback' => $write),
        ));

        register_rest_route(self::NS, '/periods', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'list_periods'), 'permission_callback' => $read),
            array('methods' => 'POST', 'callback' => array(__CLASS__, 'create_period'), 'permission_callback' => $write),
        ));
        register_rest_route(self::NS, '/periods/(?P<id>\d+)', array(
            array('methods' => 'PUT,PATCH', 'callback' => array(__CLASS__, 'update_period'), 'permission_callback' => $write),
            array('methods' => 'DELETE', 'callback' => array(__CLASS__, 'delete_period'), 'permission_callback' => $write),
        ));
        register_rest_route(self::NS, '/periods/(?P<id>\d+)/status', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'period_status'), 'permission_callback' => $read),
        ));

        register_rest_route(self::NS, '/periods/(?P<id>\d+)/requests', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'period_requests'), 'permission_callback' => $read),
        ));
        register_rest_route(self::NS, '/periods/(?P<id>\d+)/bulk', array(
            array('methods' => 'POST', 'callback' => array(__CLASS__, 'bulk_requests'), 'permission_callback' => $write),
        ));

        register_rest_route(self::NS, '/me/periods', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'my_periods'), 'permission_callback' => array(__CLASS__, 'can_read_mine')),
        ));
        register_rest_route(self::NS, '/me/requests', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'my_requests'), 'permission_callback' => array(__CLASS__, 'can_read_mine')),
            array('methods' => 'PUT,POST', 'callback' => array(__CLASS__, 'save_my_requests'), 'permission_callback' => array(__CLASS__, 'can_write_mine')),
        ));
    }

    /* ---------- 権限 ---------- */

    public static function can_read_plan() {
        return is_user_logged_in() && current_user_can('shift_manage_schedule') && SS_Context::tenant_id() > 0;
    }

    public static function can_write_plan() {
        if (!self::can_read_plan()) {
            return false;
        }
        $tenant = SS_Context::tenant();
        return $tenant && SS_Tenants::is_writable($tenant);
    }

    /** 自分の希望：スタッフに紐づいているユーザーだけ */
    public static function can_read_mine() {
        $u = SS_Context::user();
        return is_user_logged_in() && current_user_can('shift_submit_requests') && $u && !empty($u['staff_id']);
    }

    public static function can_write_mine() {
        if (!self::can_read_mine()) {
            return false;
        }
        $tenant = SS_Context::tenant();
        return $tenant && SS_Tenants::is_writable($tenant);
    }

    /* ---------- 勤務区分 ---------- */

    public static function list_patterns() {
        $repo = SS_Repo::current();
        $rows = $repo->all('patterns', array(), 'sort_order', 'ASC', 200);
        return rest_ensure_response(array('patterns' => array_map(array(__CLASS__, 'present_pattern'), $rows)));
    }

    public static function create_pattern(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $data = self::sanitize_pattern($req, false);
        if (is_wp_error($data)) {
            return $data;
        }
        if ($repo->count('patterns', array('active' => 1)) >= 30) {
            return new WP_Error('limit', '勤務区分は30件までです。', array('status' => 403));
        }
        $data['active'] = 1;
        $data['sort_order'] = $repo->count('patterns');
        $data['created_at'] = SS_System::now();
        $id = $repo->insert('patterns', $data);
        return $id ? rest_ensure_response(array('pattern' => self::present_pattern($repo->find('patterns', $id))))
                   : new WP_Error('failed', '登録に失敗しました。', array('status' => 500));
    }

    public static function update_pattern(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $id = (int) $req['id'];
        $row = $repo->find('patterns', $id);
        if (!$row) {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        $data = self::sanitize_pattern($req, true, $row);
        if (is_wp_error($data)) {
            return $data;
        }
        $repo->update('patterns', $id, $data);
        return rest_ensure_response(array('pattern' => self::present_pattern($repo->find('patterns', $id))));
    }

    /** 削除ではなく無効化（過去のシフトで使われている可能性があるため） */
    public static function deactivate_pattern(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $id = (int) $req['id'];
        if (!$repo->find('patterns', $id)) {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        $repo->update('patterns', $id, array('active' => 0));
        return rest_ensure_response(array('ok' => true));
    }

    public static function preset_patterns() {
        $tenant = SS_Context::tenant();
        $n = SS_Presets::apply_patterns(SS_Repo::current(), $tenant['industry']);
        return rest_ensure_response(array('added' => $n));
    }

    private static function present_pattern(array $r) {
        return array(
            'id' => (int) $r['id'], 'name' => $r['name'], 'short_name' => $r['short_name'],
            'start_time' => $r['start_time'], 'end_time' => $r['end_time'],
            'break_minutes' => (int) $r['break_minutes'],
            'crosses_midnight' => (int) $r['crosses_midnight'] === 1,
            'counts_as_night' => (int) $r['counts_as_night'] === 1,
            'color' => $r['color'], 'active' => (int) $r['active'] === 1,
        );
    }

    private static function sanitize_pattern(WP_REST_Request $req, $partial, $current = null) {
        $p = $req->get_json_params();
        $p = is_array($p) ? $p : array();
        $out = array();

        if (!$partial || array_key_exists('name', $p)) {
            $name = isset($p['name']) ? trim(sanitize_text_field((string) $p['name'])) : '';
            if ($name === '' || mb_strlen($name) > 60) {
                return new WP_Error('invalid', '名前を60文字以内で入力してください。', array('status' => 400));
            }
            $out['name'] = $name;
        }
        if (array_key_exists('short_name', $p)) {
            $out['short_name'] = mb_substr(trim(sanitize_text_field((string) $p['short_name'])), 0, 10);
        } elseif (!$partial) {
            $out['short_name'] = mb_substr($out['name'], 0, 1);
        }
        foreach (array('start_time', 'end_time') as $k) {
            if (!$partial || array_key_exists($k, $p)) {
                $t = isset($p[$k]) ? (string) $p[$k] : '';
                if (!self::valid_time($t, $k === 'end_time')) {
                    return new WP_Error('invalid', '時刻は30分単位（例 09:00、22:30）で入力してください。', array('status' => 400));
                }
                $out[$k] = $t;
            }
        }
        if (array_key_exists('break_minutes', $p)) {
            $b = (int) $p['break_minutes'];
            if ($b < 0 || $b > 480) {
                return new WP_Error('invalid', '休憩は0〜480分で入力してください。', array('status' => 400));
            }
            $out['break_minutes'] = $b;
        }
        if (array_key_exists('counts_as_night', $p)) {
            $out['counts_as_night'] = empty($p['counts_as_night']) ? 0 : 1;
        }
        if (array_key_exists('color', $p)) {
            $c = (string) $p['color'];
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $c)) {
                return new WP_Error('invalid', '色の形式が正しくありません。', array('status' => 400));
            }
            $out['color'] = strtolower($c);
        }
        if ($partial && array_key_exists('active', $p)) {
            $out['active'] = empty($p['active']) ? 0 : 1;
        }
        if (!$out) {
            return new WP_Error('invalid', '入力がありません。', array('status' => 400));
        }
        // 終了時刻が開始以前なら、日をまたぐ勤務（夜勤など）
        $start = isset($out['start_time']) ? $out['start_time'] : ($current ? $current['start_time'] : '');
        $end = isset($out['end_time']) ? $out['end_time'] : ($current ? $current['end_time'] : '');
        if ($start !== '' && $end !== '') {
            if ($start === $end) {
                return new WP_Error('invalid', '開始と終了が同じ時刻です。', array('status' => 400));
            }
            $out['crosses_midnight'] = self::minutes($end) <= self::minutes($start) ? 1 : 0;
        }
        return $out;
    }

    /* ---------- ルール ---------- */

    public static function list_rules() {
        $repo = SS_Repo::current();
        $rows = $repo->all('rules', array(), 'id', 'ASC', 500);
        $defs = SS_Presets::global_rule_defs();
        $global = array();
        $required = array();
        foreach ($rows as $r) {
            $params = self::decode($r['params']);
            if ($r['type'] === 'required_staff') {
                $required[] = array('id' => (int) $r['id'], 'params' => $params, 'hard' => (int) $r['hard'] === 1, 'active' => (int) $r['active'] === 1);
            } elseif (isset($defs[$r['type']]) && (int) $r['active'] === 1) {
                $global[$r['type']] = isset($params['value']) ? (int) $params['value'] : null;
            }
        }
        $out_defs = array();
        foreach ($defs as $type => $d) {
            $out_defs[] = array_merge(array('type' => $type), $d);
        }
        return rest_ensure_response(array('global' => (object) $global, 'defs' => $out_defs, 'required_staff' => $required));
    }

    /** 全体ルールをまとめて保存。値が空のものは、そのルールを外す。 */
    public static function save_global_rules(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $defs = SS_Presets::global_rule_defs();
        $p = $req->get_json_params();
        $p = is_array($p) ? $p : array();
        $values = array();
        foreach ($defs as $type => $d) {
            if (!array_key_exists($type, $p)) {
                continue;
            }
            if ($p[$type] === null || $p[$type] === '') {
                $values[$type] = null;
                continue;
            }
            $v = (int) $p[$type];
            if (!is_numeric($p[$type]) || $v < $d['min'] || $v > $d['max']) {
                return new WP_Error('invalid', $d['label'] . 'は' . $d['min'] . '〜' . $d['max'] . 'の範囲で入力してください。', array('status' => 400));
            }
            $values[$type] = $v;
        }
        foreach ($values as $type => $v) {
            $repo->delete_where('rules', array('type' => $type));
            if ($v !== null) {
                $repo->insert('rules', array(
                    'type' => $type, 'params' => wp_json_encode(array('value' => $v)),
                    'hard' => 1, 'weight' => 1, 'active' => 1, 'created_at' => SS_System::now(),
                ));
            }
        }
        return rest_ensure_response(array('ok' => true));
    }

    public static function preset_rules() {
        $tenant = SS_Context::tenant();
        $n = SS_Presets::apply_rules(SS_Repo::current(), $tenant['industry']);
        return rest_ensure_response(array('added' => $n));
    }

    public static function create_rule(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $params = self::sanitize_required_staff($req);
        if (is_wp_error($params)) {
            return $params;
        }
        if ($repo->count('rules', array('type' => 'required_staff')) >= 300) {
            return new WP_Error('limit', '必要人数の設定は300件までです。', array('status' => 403));
        }
        $id = $repo->insert('rules', array(
            'type' => 'required_staff', 'params' => wp_json_encode($params, JSON_UNESCAPED_UNICODE),
            'hard' => 1, 'weight' => 1, 'active' => 1, 'created_at' => SS_System::now(),
        ));
        return $id ? rest_ensure_response(array('id' => $id)) : new WP_Error('failed', '登録に失敗しました。', array('status' => 500));
    }

    public static function update_rule(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $id = (int) $req['id'];
        $row = $repo->find('rules', $id);
        if (!$row || $row['type'] !== 'required_staff') {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        $params = self::sanitize_required_staff($req);
        if (is_wp_error($params)) {
            return $params;
        }
        $repo->update('rules', $id, array('params' => wp_json_encode($params, JSON_UNESCAPED_UNICODE)));
        return rest_ensure_response(array('ok' => true));
    }

    public static function delete_rule(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $id = (int) $req['id'];
        $row = $repo->find('rules', $id);
        if (!$row || $row['type'] !== 'required_staff') {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        $repo->delete('rules', $id);
        return rest_ensure_response(array('ok' => true));
    }

    /**
     * 必要人数：曜日（0=日〜6=土、-1=毎日）または特定日、30分単位の時間帯、人数、役割・資格（任意）
     */
    private static function sanitize_required_staff(WP_REST_Request $req) {
        $p = $req->get_json_params();
        $p = is_array($p) ? $p : array();
        $date = isset($p['date']) ? trim((string) $p['date']) : '';
        $weekday = isset($p['weekday']) ? (int) $p['weekday'] : -1;
        if ($date !== '') {
            if (!self::valid_date($date)) {
                return new WP_Error('invalid', '日付の形式が正しくありません。', array('status' => 400));
            }
            $weekday = -1;
        } elseif ($weekday < -1 || $weekday > 6) {
            return new WP_Error('invalid', '曜日が正しくありません。', array('status' => 400));
        }
        $start = isset($p['start']) ? (string) $p['start'] : '';
        $end = isset($p['end']) ? (string) $p['end'] : '';
        if (!self::valid_time($start, false) || !self::valid_time($end, true) || $start === $end) {
            return new WP_Error('invalid', '時間帯は30分単位（例 09:00〜12:30）で入力してください。', array('status' => 400));
        }
        $count = isset($p['count']) ? (int) $p['count'] : -1;
        if ($count < 0 || $count > 200) {
            return new WP_Error('invalid', '人数は0〜200で入力してください。', array('status' => 400));
        }
        return array(
            'weekday' => $weekday,
            'date' => $date,
            'start' => $start,
            'end' => $end,
            'count' => $count,
            'role' => mb_substr(trim(sanitize_text_field(isset($p['role']) ? (string) $p['role'] : '')), 0, 40),
            'qualification' => mb_substr(trim(sanitize_text_field(isset($p['qualification']) ? (string) $p['qualification'] : '')), 0, 40),
        );
    }

    /* ---------- 希望の収集期間（管理者） ---------- */

    public static function list_periods() {
        $repo = SS_Repo::current();
        $periods = $repo->all('request_periods', array(), 'start_date', 'DESC', 100);
        $active_staff = $repo->count('staff', array('active' => 1));
        $out = array();
        foreach ($periods as $r) {
            $out[] = array_merge(self::present_period($r), array(
                'submitted' => $repo->count('request_submissions', array('period_id' => (int) $r['id'])),
                'staff_total' => $active_staff,
            ));
        }
        return rest_ensure_response(array('periods' => $out));
    }

    public static function create_period(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $p = self::sanitize_period($req);
        if (is_wp_error($p)) {
            return $p;
        }
        $p['status'] = 'open';
        $p['created_at'] = SS_System::now();
        $id = $repo->insert('request_periods', $p);
        return $id ? rest_ensure_response(array('period' => self::present_period($repo->find('request_periods', $id))))
                   : new WP_Error('failed', '登録に失敗しました。', array('status' => 500));
    }

    public static function update_period(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $id = (int) $req['id'];
        $row = $repo->find('request_periods', $id);
        if (!$row) {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        $params = $req->get_json_params();
        $data = array();
        if (is_array($params) && isset($params['status'])) {
            if (!in_array($params['status'], array('open', 'closed'), true)) {
                return new WP_Error('invalid', '状態が正しくありません。', array('status' => 400));
            }
            $data['status'] = $params['status'];
        }
        if (is_array($params) && isset($params['deadline'])) {
            if (!self::valid_date((string) $params['deadline'])) {
                return new WP_Error('invalid', '締切日の形式が正しくありません。', array('status' => 400));
            }
            $data['deadline'] = (string) $params['deadline'];
        }
        if (!$data) {
            return new WP_Error('invalid', '更新する項目がありません。', array('status' => 400));
        }
        $repo->update('request_periods', $id, $data);
        return rest_ensure_response(array('period' => self::present_period($repo->find('request_periods', $id))));
    }

    public static function delete_period(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $id = (int) $req['id'];
        if (!$repo->find('request_periods', $id)) {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        $repo->delete_where('requests', array('period_id' => $id));
        $repo->delete_where('request_submissions', array('period_id' => $id));
        $repo->delete('request_periods', $id);
        return rest_ensure_response(array('ok' => true));
    }

    /** 誰が提出済みか */
    public static function period_status(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $id = (int) $req['id'];
        if (!$repo->find('request_periods', $id)) {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        $submitted = array();
        foreach ($repo->all('request_submissions', array('period_id' => $id), 'id', 'ASC', 1000) as $s) {
            $submitted[(int) $s['staff_id']] = $s['submitted_at'];
        }
        $counts = array();
        foreach ($repo->all('requests', array('period_id' => $id), 'date', 'ASC', 1000) as $r) {
            $sid = (int) $r['staff_id'];
            $counts[$sid] = isset($counts[$sid]) ? $counts[$sid] + 1 : 1;
        }
        $out = array();
        foreach ($repo->all('staff', array('active' => 1), 'sort_order', 'ASC', 1000) as $s) {
            $sid = (int) $s['id'];
            $out[] = array(
                'staff_id' => $sid, 'name' => $s['name'],
                'submitted' => isset($submitted[$sid]),
                'count' => isset($counts[$sid]) ? $counts[$sid] : 0,
            );
        }
        return rest_ensure_response(array('staff' => $out));
    }

    /** 日ごとの「希望休」「出勤不可」の一覧（管理者が確認する用） */
    public static function period_requests(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $id = (int) $req['id'];
        if (!$repo->find('request_periods', $id)) {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        $names = array();
        foreach ($repo->all('staff', array(), 'id', 'ASC', 1000) as $s) {
            $names[(int) $s['id']] = $s['name'];
        }
        $days = array();
        $offset = 0;
        do {
            $rows = $repo->all('requests', array('period_id' => $id), 'date', 'ASC', 1000, $offset);
            foreach ($rows as $r) {
                $d = $r['date'];
                if (!isset($days[$d])) {
                    $days[$d] = array('date' => $d, 'off' => array(), 'ng' => array());
                }
                $days[$d][$r['kind']][] = array(
                    'name' => isset($names[(int) $r['staff_id']]) ? $names[(int) $r['staff_id']] : '（不明）',
                    'admin' => $r['source'] === 'admin',
                );
            }
            $offset += 1000;
        } while (count($rows) === 1000 && $offset < 20000);
        ksort($days);
        return rest_ensure_response(array('days' => array_values($days)));
    }

    /**
     * 管理者による一括設定：日付の範囲（＋曜日の指定）× 全員または選んだスタッフ。
     * action=set で「希望休」「出勤不可」を設定、action=clear で管理者が設定した分を解除する。
     * 同じ日にスタッフ自身が入力済みの分は、設定時に上書きされる。
     */
    public static function bulk_requests(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $id = (int) $req['id'];
        $period = $repo->find('request_periods', $id);
        if (!$period) {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        $p = $req->get_json_params();
        $p = is_array($p) ? $p : array();
        $action = isset($p['action']) ? (string) $p['action'] : '';
        if (!in_array($action, array('set', 'clear'), true)) {
            return new WP_Error('invalid', '操作が正しくありません。', array('status' => 400));
        }
        $start = isset($p['start_date']) && $p['start_date'] !== '' ? (string) $p['start_date'] : $period['start_date'];
        $end = isset($p['end_date']) && $p['end_date'] !== '' ? (string) $p['end_date'] : $period['end_date'];
        if (!self::valid_date($start) || !self::valid_date($end) || $end < $start
            || $start < $period['start_date'] || $end > $period['end_date']) {
            return new WP_Error('invalid', '日付は、この期間の中で指定してください。', array('status' => 400));
        }
        $weekdays = array();
        if (!empty($p['weekdays']) && is_array($p['weekdays'])) {
            foreach ($p['weekdays'] as $w) {
                if (is_numeric($w) && (int) $w >= 0 && (int) $w <= 6) {
                    $weekdays[(int) $w] = true;
                }
            }
        }
        $dates = array();
        for ($t = strtotime($start . ' UTC'); $t <= strtotime($end . ' UTC'); $t += 86400) {
            if (!$weekdays || isset($weekdays[(int) gmdate('w', $t)])) {
                $dates[] = gmdate('Y-m-d', $t);
            }
        }
        if (!$dates) {
            return new WP_Error('invalid', '対象になる日がありません。', array('status' => 400));
        }

        // 対象のスタッフ（自社の有効なスタッフだけ。他社のIDなどは無視される）
        $active = array();
        foreach ($repo->all('staff', array('active' => 1), 'sort_order', 'ASC', 1000) as $s) {
            $active[(int) $s['id']] = true;
        }
        if (!isset($p['staff_ids']) || $p['staff_ids'] === 'all') {
            $ids = array_keys($active);
        } elseif (is_array($p['staff_ids'])) {
            $ids = array();
            foreach ($p['staff_ids'] as $sid) {
                if (isset($active[(int) $sid])) {
                    $ids[(int) $sid] = (int) $sid;
                }
            }
            $ids = array_values($ids);
        } else {
            $ids = array();
        }
        if (!$ids) {
            return new WP_Error('invalid', '対象のスタッフがいません。', array('status' => 400));
        }
        if (count($dates) * count($ids) > 3000) {
            return new WP_Error('invalid', '対象が多すぎます。日付の範囲を分けて設定してください。', array('status' => 400));
        }
        $all_staff = count($ids) === count($active);

        if ($action === 'set') {
            $kind = isset($p['kind']) ? (string) $p['kind'] : '';
            if (!in_array($kind, array('off', 'ng'), true)) {
                return new WP_Error('invalid', '種類が正しくありません。', array('status' => 400));
            }
            $note = mb_substr(trim(sanitize_text_field(isset($p['note']) ? (string) $p['note'] : '')), 0, 200);
            $now = SS_System::now();
            foreach ($dates as $d) {
                if ($all_staff) {
                    $repo->delete_where('requests', array('period_id' => $id, 'date' => $d));
                } else {
                    foreach ($ids as $sid) {
                        $repo->delete_where('requests', array('period_id' => $id, 'staff_id' => $sid, 'date' => $d));
                    }
                }
                foreach ($ids as $sid) {
                    $repo->insert('requests', array(
                        'period_id' => $id, 'staff_id' => $sid, 'date' => $d,
                        'kind' => $kind, 'note' => $note, 'source' => 'admin', 'submitted_at' => $now,
                    ));
                }
            }
        } else {
            foreach ($dates as $d) {
                if ($all_staff) {
                    $repo->delete_where('requests', array('period_id' => $id, 'date' => $d, 'source' => 'admin'));
                } else {
                    foreach ($ids as $sid) {
                        $repo->delete_where('requests', array('period_id' => $id, 'staff_id' => $sid, 'date' => $d, 'source' => 'admin'));
                    }
                }
            }
        }
        return rest_ensure_response(array('ok' => true, 'dates' => count($dates), 'staff' => count($ids)));
    }

    private static function present_period(array $r) {
        return array(
            'id' => (int) $r['id'], 'start_date' => $r['start_date'], 'end_date' => $r['end_date'],
            'deadline' => $r['deadline'], 'status' => $r['status'],
        );
    }

    private static function sanitize_period(WP_REST_Request $req) {
        $p = $req->get_json_params();
        $p = is_array($p) ? $p : array();
        $start = isset($p['start_date']) ? (string) $p['start_date'] : '';
        $end = isset($p['end_date']) ? (string) $p['end_date'] : '';
        $deadline = isset($p['deadline']) ? (string) $p['deadline'] : '';
        if (!self::valid_date($start) || !self::valid_date($end) || !self::valid_date($deadline)) {
            return new WP_Error('invalid', '日付の形式が正しくありません。', array('status' => 400));
        }
        if ($end < $start) {
            return new WP_Error('invalid', '終了日は開始日以降にしてください。', array('status' => 400));
        }
        if (self::days_between($start, $end) > self::MAX_PERIOD_DAYS) {
            return new WP_Error('invalid', '期間は' . self::MAX_PERIOD_DAYS . '日以内にしてください。', array('status' => 400));
        }
        return array('start_date' => $start, 'end_date' => $end, 'deadline' => $deadline);
    }

    /* ---------- 自分の希望（スタッフ・管理者がスタッフに紐づいている場合） ---------- */

    /** 受付中（締切前）の期間と、自分の提出状況 */
    public static function my_periods() {
        $repo = SS_Repo::current();
        $staff_id = (int) SS_Context::user()['staff_id'];
        $today = wp_date('Y-m-d');
        $out = array();
        foreach ($repo->all('request_periods', array('status' => 'open'), 'start_date', 'ASC', 100) as $r) {
            if ($r['deadline'] < $today) {
                continue;
            }
            $out[] = array_merge(self::present_period($r), array(
                'submitted' => $repo->count('request_submissions', array('period_id' => (int) $r['id'], 'staff_id' => $staff_id)) > 0,
            ));
        }
        return rest_ensure_response(array('periods' => $out, 'today' => $today));
    }

    public static function my_requests(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $staff_id = (int) SS_Context::user()['staff_id'];
        $period_id = (int) $req->get_param('period_id');
        $period = $repo->find('request_periods', $period_id);
        if (!$period) {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        $items = array();
        foreach ($repo->all('requests', array('period_id' => $period_id, 'staff_id' => $staff_id), 'date', 'ASC', 100) as $r) {
            $items[] = array('date' => $r['date'], 'kind' => $r['kind'], 'note' => $r['note'], 'source' => $r['source']);
        }
        return rest_ensure_response(array(
            'period' => self::present_period($period),
            'items' => $items,
            'submitted' => $repo->count('request_submissions', array('period_id' => $period_id, 'staff_id' => $staff_id)) > 0,
            'editable' => $period['status'] === 'open' && $period['deadline'] >= wp_date('Y-m-d'),
        ));
    }

    /** 自分の希望をまとめて保存（その期間の分を置き換える） */
    public static function save_my_requests(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $staff_id = (int) SS_Context::user()['staff_id'];
        $staff = $repo->find('staff', $staff_id);
        if (!$staff || (int) $staff['active'] !== 1) {
            return new WP_Error('forbidden', 'スタッフとして登録されていません。', array('status' => 403));
        }
        $p = $req->get_json_params();
        $p = is_array($p) ? $p : array();
        $period = $repo->find('request_periods', isset($p['period_id']) ? (int) $p['period_id'] : 0);
        if (!$period) {
            return new WP_Error('not_found', '期間が見つかりません。', array('status' => 404));
        }
        if ($period['status'] !== 'open' || $period['deadline'] < wp_date('Y-m-d')) {
            return new WP_Error('closed', 'この期間の受付は終了しました。', array('status' => 403));
        }
        $items = isset($p['items']) && is_array($p['items']) ? $p['items'] : array();
        $clean = array();
        foreach ($items as $it) {
            $date = isset($it['date']) ? (string) $it['date'] : '';
            $kind = isset($it['kind']) ? (string) $it['kind'] : '';
            if (!self::valid_date($date) || $date < $period['start_date'] || $date > $period['end_date']) {
                return new WP_Error('invalid', '期間外の日付が含まれています。', array('status' => 400));
            }
            if (!in_array($kind, array('off', 'ng'), true)) {
                return new WP_Error('invalid', '種類が正しくありません。', array('status' => 400));
            }
            $clean[$date] = array(
                'date' => $date, 'kind' => $kind,
                'note' => mb_substr(trim(sanitize_text_field(isset($it['note']) ? (string) $it['note'] : '')), 0, 200),
            );
        }
        $period_id = (int) $period['id'];
        $now = SS_System::now();
        // 管理者が設定した日は、スタッフの提出では変更できない（管理者の設定を優先）
        $locked = array();
        foreach ($repo->all('requests', array('period_id' => $period_id, 'staff_id' => $staff_id, 'source' => 'admin'), 'date', 'ASC', 100) as $r) {
            $locked[$r['date']] = true;
        }
        $repo->delete_where('requests', array('period_id' => $period_id, 'staff_id' => $staff_id, 'source' => 'staff'));
        $skipped = 0;
        foreach ($clean as $c) {
            if (isset($locked[$c['date']])) {
                $skipped++;
                continue;
            }
            $repo->insert('requests', array(
                'period_id' => $period_id, 'staff_id' => $staff_id, 'date' => $c['date'],
                'kind' => $c['kind'], 'note' => $c['note'], 'source' => 'staff', 'submitted_at' => $now,
            ));
        }
        $repo->delete_where('request_submissions', array('period_id' => $period_id, 'staff_id' => $staff_id));
        $repo->insert('request_submissions', array('period_id' => $period_id, 'staff_id' => $staff_id, 'submitted_at' => $now));
        return rest_ensure_response(array('ok' => true, 'saved' => count($clean) - $skipped, 'skipped' => $skipped));
    }

    /* ---------- 補助 ---------- */

    private static function decode($json) {
        $v = $json ? json_decode($json, true) : array();
        return is_array($v) ? $v : array();
    }

    /** "HH:MM"（30分単位）。終了時刻だけ 24:00 も可。 */
    public static function valid_time($t, $allow_24) {
        if (!preg_match('/^(\d{2}):(00|30)$/', $t, $m)) {
            return false;
        }
        $h = (int) $m[1];
        return $h < 24 || ($allow_24 && $h === 24 && $m[2] === '00');
    }

    public static function minutes($t) {
        list($h, $m) = array_map('intval', explode(':', $t));
        return $h * 60 + $m;
    }

    public static function valid_date($d) {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function days_between($a, $b) {
        return (int) round((strtotime($b . ' UTC') - strtotime($a . ' UTC')) / 86400) + 1;
    }
}
