<?php
/**
 * REST API（フェーズ3）：シフト表の作成・保存・公開、スタッフ向けの閲覧、設定。
 * お客様IDは常にログイン中ユーザーの所属から決める（SS_Repo::current()）。
 *
 * 自動作成の計算とルール違反のチェックはブラウザ側（assets/solver.js）で行い、
 * サーバーは保存時に、スタッフ・勤務区分・日付・時刻の整合性を確認する。
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Rest_Schedule {
    const NS = 'shift/v1';
    const MAX_ENTRIES = 7000;

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'routes'));
    }

    public static function routes() {
        $read = array('SS_Rest_Plan', 'can_read_plan');
        $write = array('SS_Rest_Plan', 'can_write_plan');
        $view = array(__CLASS__, 'can_view');

        register_rest_route(self::NS, '/schedules', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'list_schedules'), 'permission_callback' => $read),
            array('methods' => 'POST', 'callback' => array(__CLASS__, 'create_schedule'), 'permission_callback' => $write),
        ));
        register_rest_route(self::NS, '/schedules/(?P<id>\d+)', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'get_schedule'), 'permission_callback' => $read),
            array('methods' => 'PUT,PATCH', 'callback' => array(__CLASS__, 'update_schedule'), 'permission_callback' => $write),
            array('methods' => 'DELETE', 'callback' => array(__CLASS__, 'delete_schedule'), 'permission_callback' => $write),
        ));
        register_rest_route(self::NS, '/schedules/(?P<id>\d+)/entries', array(
            array('methods' => 'PUT,POST', 'callback' => array(__CLASS__, 'replace_entries'), 'permission_callback' => $write),
        ));

        register_rest_route(self::NS, '/me/schedules', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'my_schedules'), 'permission_callback' => $view),
        ));
        register_rest_route(self::NS, '/me/schedules/(?P<id>\d+)', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'my_schedule'), 'permission_callback' => $view),
        ));

        register_rest_route(self::NS, '/settings', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'get_settings'), 'permission_callback' => array(__CLASS__, 'can_read_settings')),
            array('methods' => 'PUT,POST', 'callback' => array(__CLASS__, 'save_settings'), 'permission_callback' => array(__CLASS__, 'can_write_settings')),
        ));
    }

    /* ---------- 権限 ---------- */

    public static function can_view() {
        return is_user_logged_in() && current_user_can('shift_view') && SS_Context::tenant_id() > 0;
    }

    public static function can_read_settings() {
        return is_user_logged_in() && current_user_can('shift_manage_settings') && SS_Context::tenant_id() > 0;
    }

    public static function can_write_settings() {
        if (!self::can_read_settings()) {
            return false;
        }
        $tenant = SS_Context::tenant();
        return $tenant && SS_Tenants::is_writable($tenant);
    }

    /* ---------- シフト表（管理者） ---------- */

    public static function list_schedules() {
        $repo = SS_Repo::current();
        $out = array();
        foreach ($repo->all('schedules', array(), 'start_date', 'DESC', 200) as $r) {
            $out[] = array_merge(self::present_schedule($r), array(
                'entries' => $repo->count('entries', array('schedule_id' => (int) $r['id'])),
            ));
        }
        return rest_ensure_response(array('schedules' => $out));
    }

    public static function create_schedule(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $p = $req->get_json_params();
        $p = is_array($p) ? $p : array();
        $start = isset($p['start_date']) ? (string) $p['start_date'] : '';
        $end = isset($p['end_date']) ? (string) $p['end_date'] : '';
        if (!SS_Rest_Plan::valid_date($start) || !SS_Rest_Plan::valid_date($end) || $end < $start) {
            return new WP_Error('invalid', '日付が正しくありません。', array('status' => 400));
        }
        if (self::days($start, $end) > SS_Rest_Plan::MAX_PERIOD_DAYS) {
            return new WP_Error('invalid', '期間は' . SS_Rest_Plan::MAX_PERIOD_DAYS . '日以内にしてください。', array('status' => 400));
        }
        if ($repo->count('schedules') >= 200) {
            return new WP_Error('limit', 'シフト表は200件までです。古いものを削除してください。', array('status' => 403));
        }
        $user = SS_Context::user();
        $id = $repo->insert('schedules', array(
            'start_date' => $start, 'end_date' => $end, 'status' => 'draft',
            'created_by' => (int) $user['id'], 'created_at' => SS_System::now(),
        ));
        return $id ? rest_ensure_response(array('schedule' => self::present_schedule($repo->find('schedules', $id))))
                   : new WP_Error('failed', '作成に失敗しました。', array('status' => 500));
    }

    /** 編集画面に必要なものをまとめて返す */
    public static function get_schedule(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $sch = $repo->find('schedules', (int) $req['id']);
        if (!$sch) {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        $staff = array();
        foreach ($repo->all('staff', array(), 'sort_order', 'ASC', 1000) as $s) {
            $weekdays = array();
            foreach (explode(',', (string) $s['available_weekdays']) as $w) {
                if ($w !== '' && (int) $w >= 0 && (int) $w <= 6) {
                    $weekdays[] = (int) $w;
                }
            }
            $staff[] = array(
                'id' => (int) $s['id'], 'name' => $s['name'], 'employment' => $s['employment'],
                'roles' => self::decode_list($s['roles']), 'qualifications' => self::decode_list($s['qualifications']),
                'night_ok' => (int) $s['night_ok'] === 1, 'weekdays' => $weekdays, 'active' => (int) $s['active'] === 1,
            );
        }
        $patterns = array();
        foreach ($repo->all('patterns', array(), 'sort_order', 'ASC', 200) as $r) {
            $patterns[] = SS_Rest_Plan::present_pattern($r);
        }
        return rest_ensure_response(array(
            'schedule' => self::present_schedule($sch),
            'staff' => $staff,
            'patterns' => $patterns,
            'rules' => SS_Rest_Plan::rules_data($repo),
            'requests' => self::collect_requests($repo, $sch),
            'entries' => self::collect_entries($repo, (int) $sch['id'], null),
        ));
    }

    public static function update_schedule(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $id = (int) $req['id'];
        if (!$repo->find('schedules', $id)) {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        $p = $req->get_json_params();
        $status = is_array($p) && isset($p['status']) ? (string) $p['status'] : '';
        if (!in_array($status, array('draft', 'published'), true)) {
            return new WP_Error('invalid', '状態が正しくありません。', array('status' => 400));
        }
        $repo->update('schedules', $id, array(
            'status' => $status,
            'published_at' => $status === 'published' ? SS_System::now() : null,
        ));
        return rest_ensure_response(array('schedule' => self::present_schedule($repo->find('schedules', $id))));
    }

    public static function delete_schedule(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $id = (int) $req['id'];
        if (!$repo->find('schedules', $id)) {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        $repo->delete_where('entries', array('schedule_id' => $id));
        $repo->delete('schedules', $id);
        return rest_ensure_response(array('ok' => true));
    }

    /**
     * 割り当てを、まるごと置き換えて保存する（画面の内容が正）。
     * entries：[{ staff_id, date, pattern_id?, start_time, end_time, break_minutes?, locked?, note? }]
     * 時刻が空で locked=true のものは「固定の休み」。
     */
    public static function replace_entries(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $id = (int) $req['id'];
        $sch = $repo->find('schedules', $id);
        if (!$sch) {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        $p = $req->get_json_params();
        $items = is_array($p) && isset($p['entries']) && is_array($p['entries']) ? $p['entries'] : null;
        if ($items === null) {
            return new WP_Error('invalid', '入力がありません。', array('status' => 400));
        }
        if (count($items) > self::MAX_ENTRIES) {
            return new WP_Error('invalid', '件数が多すぎます。', array('status' => 400));
        }

        $staff_ok = array();
        foreach ($repo->all('staff', array('active' => 1), 'id', 'ASC', 1000) as $s) {
            $staff_ok[(int) $s['id']] = true;
        }
        $pattern_ok = array();
        foreach ($repo->all('patterns', array(), 'id', 'ASC', 200) as $r) {
            $pattern_ok[(int) $r['id']] = $r;
        }

        $rows = array();
        $now = SS_System::now();
        foreach ($items as $it) {
            $it = is_array($it) ? $it : array();
            $sid = isset($it['staff_id']) ? (int) $it['staff_id'] : 0;
            $date = isset($it['date']) ? (string) $it['date'] : '';
            if (!isset($staff_ok[$sid])) {
                return new WP_Error('invalid', '登録されていないスタッフが含まれています。', array('status' => 400));
            }
            if (!SS_Rest_Plan::valid_date($date) || $date < $sch['start_date'] || $date > $sch['end_date']) {
                return new WP_Error('invalid', '期間外の日付が含まれています。', array('status' => 400));
            }
            $pid = isset($it['pattern_id']) && $it['pattern_id'] !== null && $it['pattern_id'] !== '' ? (int) $it['pattern_id'] : null;
            if ($pid !== null && !isset($pattern_ok[$pid])) {
                return new WP_Error('invalid', '登録されていない勤務区分が含まれています。', array('status' => 400));
            }
            $start = isset($it['start_time']) ? (string) $it['start_time'] : '';
            $end = isset($it['end_time']) ? (string) $it['end_time'] : '';
            if ($start === '' && $end === '' && $pid !== null) {
                $start = $pattern_ok[$pid]['start_time'];
                $end = $pattern_ok[$pid]['end_time'];
            }
            $locked = empty($it['locked']) ? 0 : 1;
            $brk = isset($it['break_minutes']) ? (int) $it['break_minutes'] : ($pid !== null ? (int) $pattern_ok[$pid]['break_minutes'] : 0);
            if ($start === '' && $end === '') {
                if (!$locked) {
                    continue; // 固定でない休みは保存しない
                }
                $pid = null;
                $brk = 0;
            } elseif (!SS_Rest_Plan::valid_time($start, false) || !SS_Rest_Plan::valid_time($end, true) || $start === $end) {
                return new WP_Error('invalid', '時刻は30分単位（例 09:00）で入力してください。', array('status' => 400));
            }
            if ($brk < 0 || $brk > 480) {
                return new WP_Error('invalid', '休憩は0〜480分で入力してください。', array('status' => 400));
            }
            $rows[$sid . '|' . $date] = array(
                'schedule_id' => $id, 'staff_id' => $sid, 'date' => $date, 'pattern_id' => $pid,
                'start_time' => $start, 'end_time' => $end, 'break_minutes' => $brk, 'locked' => $locked,
                'note' => mb_substr(trim(sanitize_text_field(isset($it['note']) ? (string) $it['note'] : '')), 0, 200),
                'created_at' => $now,
            );
        }

        $repo->delete_where('entries', array('schedule_id' => $id));
        $saved = $repo->insert_many('entries', array_values($rows));
        return rest_ensure_response(array('ok' => true, 'saved' => $saved));
    }

    /* ---------- スタッフ向けの閲覧 ---------- */

    public static function my_schedules() {
        $repo = SS_Repo::current();
        $out = array();
        foreach ($repo->all('schedules', array('status' => 'published'), 'start_date', 'DESC', 60) as $r) {
            $out[] = self::present_schedule($r);
        }
        return rest_ensure_response(array('schedules' => $out));
    }

    /** 公開されたシフト。スタッフには、設定に応じて「全員分」または「自分の分だけ」を返す。 */
    public static function my_schedule(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $sch = $repo->find('schedules', (int) $req['id']);
        if (!$sch || $sch['status'] !== 'published') {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        $tenant = SS_Context::tenant();
        $me = SS_Context::user();
        $only_self = !current_user_can('shift_manage_schedule') && SS_Tenants::staff_view($tenant) === 'self';
        $my_staff = !empty($me['staff_id']) ? (int) $me['staff_id'] : 0;

        $staff = array();
        foreach ($repo->all('staff', array('active' => 1), 'sort_order', 'ASC', 1000) as $s) {
            if ($only_self && (int) $s['id'] !== $my_staff) {
                continue;
            }
            $staff[] = array('id' => (int) $s['id'], 'name' => $s['name']);
        }
        $patterns = array();
        foreach ($repo->all('patterns', array(), 'sort_order', 'ASC', 200) as $r) {
            $patterns[] = SS_Rest_Plan::present_pattern($r);
        }
        $entries = $only_self && !$my_staff ? array() : self::collect_entries($repo, (int) $sch['id'], $only_self ? $my_staff : null);
        return rest_ensure_response(array(
            'schedule' => self::present_schedule($sch),
            'staff' => $staff, 'patterns' => $patterns, 'entries' => $entries,
            'my_staff_id' => $my_staff, 'scope' => $only_self ? 'self' : 'all',
        ));
    }

    /* ---------- 設定 ---------- */

    public static function get_settings() {
        $tenant = SS_Context::tenant();
        return rest_ensure_response(array('staff_view' => SS_Tenants::staff_view($tenant)));
    }

    public static function save_settings(WP_REST_Request $req) {
        $p = $req->get_json_params();
        $view = is_array($p) && isset($p['staff_view']) ? (string) $p['staff_view'] : '';
        if (!in_array($view, array('all', 'self'), true)) {
            return new WP_Error('invalid', '設定が正しくありません。', array('status' => 400));
        }
        SS_Tenants::save_settings(SS_Context::tenant_id(), array('staff_view' => $view));
        return rest_ensure_response(array('ok' => true, 'staff_view' => $view));
    }

    /* ---------- 補助 ---------- */

    private static function present_schedule(array $r) {
        return array(
            'id' => (int) $r['id'], 'start_date' => $r['start_date'], 'end_date' => $r['end_date'],
            'status' => $r['status'], 'published_at' => $r['published_at'],
        );
    }

    private static function decode_list($json) {
        $v = $json ? json_decode($json, true) : array();
        return is_array($v) ? array_values($v) : array();
    }

    private static function days($a, $b) {
        return (int) round((strtotime($b . ' UTC') - strtotime($a . ' UTC')) / 86400) + 1;
    }

    private static function collect_entries(SS_Repo $repo, $schedule_id, $only_staff_id) {
        $where = array('schedule_id' => (int) $schedule_id);
        if ($only_staff_id) {
            $where['staff_id'] = (int) $only_staff_id;
        }
        $out = array();
        $offset = 0;
        do {
            $rows = $repo->all('entries', $where, 'date', 'ASC', 1000, $offset);
            foreach ($rows as $r) {
                $out[] = array(
                    'staff_id' => (int) $r['staff_id'], 'date' => $r['date'],
                    'pattern_id' => $r['pattern_id'] === null ? null : (int) $r['pattern_id'],
                    'start_time' => $r['start_time'], 'end_time' => $r['end_time'],
                    'break_minutes' => (int) $r['break_minutes'], 'locked' => (int) $r['locked'] === 1,
                    'note' => $r['note'],
                );
            }
            $offset += 1000;
        } while (count($rows) === 1000 && $offset < 20000);
        return $out;
    }

    /** 期間に重なる「希望の収集」の希望を集める（管理者の設定が優先） */
    private static function collect_requests(SS_Repo $repo, array $sch) {
        $merged = array();
        foreach ($repo->all('request_periods', array(), 'start_date', 'ASC', 200) as $period) {
            if ($period['end_date'] < $sch['start_date'] || $period['start_date'] > $sch['end_date']) {
                continue;
            }
            $offset = 0;
            do {
                $rows = $repo->all('requests', array('period_id' => (int) $period['id']), 'date', 'ASC', 1000, $offset);
                foreach ($rows as $r) {
                    if ($r['date'] < $sch['start_date'] || $r['date'] > $sch['end_date']) {
                        continue;
                    }
                    $key = $r['staff_id'] . '|' . $r['date'];
                    if (!isset($merged[$key]) || ($r['source'] === 'admin' && $merged[$key]['source'] !== 'admin')) {
                        $merged[$key] = array('staff_id' => (int) $r['staff_id'], 'date' => $r['date'], 'kind' => $r['kind'], 'source' => $r['source']);
                    }
                }
                $offset += 1000;
            } while (count($rows) === 1000 && $offset < 20000);
        }
        return array_values($merged);
    }
}
