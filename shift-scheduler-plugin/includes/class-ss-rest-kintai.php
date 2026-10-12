<?php
/**
 * 勤怠（タイムカード・QR打刻・勤怠区分・有給休暇）のAPI（/wp-json/mo/v1/…）。
 * マッシュオフィスシステムの mush-office/v1 と同じ形で返す。
 *
 * マッシュオフィスシステムとの違い：
 *  - 打刻の時刻は、サーバーの時計で決める（画面から送られた時刻は使わない）。
 *  - QR打刻のURLは「?staff=番号」ではなく、お客様・スタッフに結びついた署名つき（推測できない）。
 *    QRを作り直すと、古いQRは使えなくなる。
 *  - QRで開いた画面は、ログインなしで、そのスタッフの打刻と、直近の記録の確認だけができる。
 *  - 一覧・修正は、ログインした人の、お客様の分だけ（スタッフは自分の分だけ。管理者は全員分）。
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Rest_Kintai {
    const DAY_TYPES = array('通常', '欠勤', '有休', '特休', '休日出勤');

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'routes'));
    }

    public static function routes() {
        $c = __CLASS__;
        $o = 'SS_Rest_Office';
        $r = function ($path, $method, $cb, $perm, $perm_class = null) use ($c, $o) {
            register_rest_route(SS_Rest_Office::NS, $path, array(array('methods' => $method, 'callback' => array($c, $cb), 'permission_callback' => array($perm_class ? $perm_class : $o, $perm))));
        };
        // 打刻（QRの署名、またはログインで本人を確かめる）
        $r('/punch', 'GET', 'punch_status', 'punch_permission', $c);
        $r('/punch', 'POST', 'punch', 'punch_permission', $c);
        $r('/punch-keys/reissue', 'POST', 'reissue_keys', 'can_admin_write');
        // タイムカード一覧・修正（管理者集計）
        $r('/time-cards', 'GET', 'get_time_cards', 'can_use');
        $r('/time-cards/upsert', 'POST', 'upsert_time_card', 'can_admin_write');
        // 勤怠区分（給与計算）
        $r('/attendance-month', 'GET', 'get_attendance_month', 'can_admin');
        $r('/attendance-month', 'POST', 'save_attendance_month', 'can_admin_write');
        // 有給休暇
        $r('/leave-grants', 'GET', 'get_leave_grants', 'can_admin');
        $r('/leave-grants', 'POST', 'create_leave_grant', 'can_admin_write');
        $r('/leave-grants/(?P<id>\d+)', 'DELETE', 'delete_leave_grant', 'can_admin_write');
        $r('/leave-balance/(?P<staffId>\d+)', 'GET', 'get_leave_balance', 'can_admin');
        $r('/leave-five-day-check/(?P<staffId>\d+)', 'GET', 'get_five_day_check', 'can_admin');
        $r('/leave-five-day-warnings', 'GET', 'get_five_day_warnings', 'can_admin');
        $r('/my-leave-balance', 'GET', 'get_my_leave_balance', 'can_use');
    }

    /* ---------- QR打刻の署名 ---------- */

    /** スタッフのQR用の署名（お客様ID・スタッフID・発行回数から作る。作り直すと発行回数が変わり、古い署名は無効になる） */
    public static function punch_sig($tenant_id, array $staff) {
        $data = 'punch|' . (int) $tenant_id . '|' . (int) $staff['id'] . '|' . (int) (isset($staff['punch_ver']) ? $staff['punch_ver'] : 0);
        return substr(hash_hmac('sha256', $data, wp_salt('auth')), 0, 32);
    }

    /** QRに入れるURL */
    public static function punch_url(array $tenant, array $staff) {
        return add_query_arg(array('s' => (int) $staff['id'], 'k' => self::punch_sig($tenant['id'], $staff)), SS_View::app_url($tenant['public_id'], 'punch'));
    }

    const PUNCH_FAIL_LIMIT = 30; // 署名の誤りは、1つの接続元から1時間に30回まで（総当たり対策。正しい打刻は数えない）

    private static function fail_key() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return 'ss_rl_' . md5('punch_fail|' . $ip);
    }

    /**
     * QRの署名から、お客様とスタッフを確かめる（ログインなしの打刻用）。
     * @return array|null ['tenant' => , 'staff' => ]
     */
    public static function resolve_punch($public_id, $staff_id, $sig) {
        $fails = (int) get_transient(self::fail_key());
        if ($fails >= self::PUNCH_FAIL_LIMIT) {
            return null;
        }
        $found = self::check_punch($public_id, $staff_id, $sig);
        if (!$found) {
            set_transient(self::fail_key(), $fails + 1, HOUR_IN_SECONDS);
        }
        return $found;
    }

    private static function check_punch($public_id, $staff_id, $sig) {
        if (!preg_match('/^[a-z0-9]{12}$/', (string) $public_id) || !preg_match('/^[a-f0-9]{32}$/', (string) $sig) || (int) $staff_id <= 0) {
            return null;
        }
        $tenant = SS_System::tenant_by_public_id($public_id);
        if (!$tenant || !in_array(SS_Tenants::effective_status($tenant), array('trial', 'active', 'grace', 'readonly'), true)) {
            return null;
        }
        $staff = SS_Repo::for_tenant($tenant['id'])->find('staff', (int) $staff_id);
        if (!$staff || (int) $staff['active'] !== 1 || !hash_equals(self::punch_sig($tenant['id'], $staff), (string) $sig)) {
            return null;
        }
        return array('tenant' => $tenant, 'staff' => $staff);
    }

    /** 打刻する人：QRの署名があればそれで、なければログイン中のスタッフ */
    private static function punch_target(WP_REST_Request $req) {
        $k = (string) $req->get_param('k');
        if ($k !== '') {
            return self::resolve_punch((string) $req->get_param('t'), (int) $req->get_param('s'), $k);
        }
        if (!SS_Rest_Office::can_use()) {
            return null;
        }
        $staff = SS_Rest_Office::me_staff();
        $tenant = SS_Context::tenant();
        return $staff && $tenant ? array('tenant' => $tenant, 'staff' => $staff) : null;
    }

    public static function punch_permission(WP_REST_Request $req) {
        if ((string) $req->get_param('k') !== '') {
            return true; // 署名は、各処理の中で確かめる（誤りが続く接続元は止める）
        }
        return SS_Rest_Office::can_use();
    }

    /* ---------- 打刻 ---------- */

    public static function format_card(array $row, $with_location = false) {
        $card = array(
            'id' => (int) $row['id'], 'date' => $row['record_date'], 'staffId' => (string) $row['staff_id'],
            'staffName' => $row['staff_name'], 'dept' => $row['department'], 'clockIn' => (string) $row['clock_in'], 'clockOut' => (string) $row['clock_out'],
        );
        if ($with_location) {
            foreach (array('in' => 'clockInLocation', 'out' => 'clockOutLocation') as $kind => $key) {
                $lat = isset($row["clock_{$kind}_lat"]) ? $row["clock_{$kind}_lat"] : null;
                $lng = isset($row["clock_{$kind}_lng"]) ? $row["clock_{$kind}_lng"] : null;
                $acc = isset($row["clock_{$kind}_accuracy"]) ? $row["clock_{$kind}_accuracy"] : null;
                $card[$key] = ($lat === null || $lng === null) ? null : array('lat' => (float) $lat, 'lng' => (float) $lng, 'accuracy' => $acc === null ? null : (int) $acc);
            }
        }
        return $card;
    }

    private static function sanitize_location($loc) {
        if (!is_array($loc) || !isset($loc['lat'], $loc['lng']) || !is_numeric($loc['lat']) || !is_numeric($loc['lng'])) {
            return null;
        }
        $lat = (float) $loc['lat'];
        $lng = (float) $loc['lng'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }
        $acc = isset($loc['accuracy']) && is_numeric($loc['accuracy']) ? max(0, min(1000000, (int) round((float) $loc['accuracy']))) : null;
        return array('lat' => round($lat, 7), 'lng' => round($lng, 7), 'accuracy' => $acc);
    }

    private static function today() {
        return current_time('Y-m-d');
    }

    /** 打刻できないときの説明（QRか、ログインか） */
    private static function punch_denied(WP_REST_Request $req) {
        if ((string) $req->get_param('k') === '') {
            return SS_Rest_Office::err('このアカウントは、スタッフ名簿に紐づいていないため、打刻できません。従業員登録で、ご自身を名簿に登録して紐づけてください。', 403);
        }
        return SS_Rest_Office::err('このQRコードは使えません。管理者に、新しいQRコードをもらってください。', 403);
    }

    public static function punch_status(WP_REST_Request $req) {
        $t = self::punch_target($req);
        if (!$t) {
            return self::punch_denied($req);
        }
        $repo = SS_Repo::for_tenant($t['tenant']['id']);
        $sid = (int) $t['staff']['id'];
        $today = $repo->all('time_cards', array('staff_id' => $sid, 'record_date' => self::today()), 'id', 'ASC', 1);
        $history = $repo->all('time_cards', array('staff_id' => $sid), 'record_date', 'DESC', 7);
        return SS_Rest_Office::ok(array(
            'staff'    => array('id' => (string) $sid, 'name' => $t['staff']['name'], 'dept' => (string) $t['staff']['department']),
            'company'  => $t['tenant']['name'],
            'today'    => $today ? self::format_card($today[0]) : null,
            'history'  => array_map(array(__CLASS__, 'format_card'), $history),
            'date'     => self::today(),
            'writable' => SS_Tenants::is_writable($t['tenant']),
        ));
    }

    /** 出勤（kind=in）・退勤（kind=out）。時刻はサーバーの時計で決める */
    public static function punch(WP_REST_Request $req) {
        $t = self::punch_target($req);
        if (!$t) {
            return self::punch_denied($req);
        }
        if (!SS_Tenants::is_writable($t['tenant'])) {
            return SS_Rest_Office::err('現在は閲覧のみの状態のため、打刻できません。管理者にお問い合わせください。', 403);
        }
        $p = SS_Rest_Office::params($req);
        $kind = isset($p['kind']) ? (string) $p['kind'] : '';
        if (!in_array($kind, array('in', 'out'), true)) {
            return SS_Rest_Office::err('出勤か退勤かを指定してください。', 400);
        }
        $repo = SS_Repo::for_tenant($t['tenant']['id']);
        $staff = $t['staff'];
        $date = self::today();
        $time = current_time('H:i');
        $now = current_time('mysql');
        $rows = $repo->all('time_cards', array('staff_id' => (int) $staff['id'], 'record_date' => $date), 'id', 'ASC', 1);
        $row = $rows ? $rows[0] : null;
        $loc = self::sanitize_location(isset($p['location']) ? $p['location'] : null);
        if ($kind === 'in') {
            if ($row && $row['clock_in'] !== '') {
                return SS_Rest_Office::err('本日の出勤は、すでに記録されています（' . $row['clock_in'] . '）。', 409);
            }
            $data = array('clock_in' => $time, 'staff_name' => $staff['name'], 'department' => (string) $staff['department'], 'updated_at' => $now);
        } else {
            if (!$row || $row['clock_in'] === '') {
                return SS_Rest_Office::err('先に出勤を記録してください。', 409);
            }
            if ($row['clock_out'] !== '') {
                return SS_Rest_Office::err('本日の退勤は、すでに記録されています（' . $row['clock_out'] . '）。', 409);
            }
            $data = array('clock_out' => $time, 'updated_at' => $now);
        }
        if ($loc) {
            $data["clock_{$kind}_lat"] = $loc['lat'];
            $data["clock_{$kind}_lng"] = $loc['lng'];
            $data["clock_{$kind}_accuracy"] = $loc['accuracy'];
        }
        if ($row) {
            $repo->update('time_cards', (int) $row['id'], $data);
            $id = (int) $row['id'];
        } else {
            $id = (int) $repo->insert('time_cards', array_merge(array('record_date' => $date, 'staff_id' => (int) $staff['id'], 'created_at' => $now), $data));
        }
        $saved = $id ? $repo->find('time_cards', $id) : null;
        if (!$saved) {
            return SS_Rest_Office::err('打刻に失敗しました。もう一度お試しください。', 500);
        }
        $out = self::format_card($saved);
        $out['locationSaved'] = $loc ? array($kind) : array();
        $out['time'] = $time;
        return SS_Rest_Office::ok($out);
    }

    /** QRコードを作り直す（staffId を指定すればその人だけ、なければ全員）。古いQRは使えなくなる */
    public static function reissue_keys(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $p = SS_Rest_Office::params($req);
        $sid = isset($p['staffId']) ? (int) $p['staffId'] : 0;
        if ($sid > 0) {
            $s = SS_Rest_Office::staff_in_tenant($sid);
            if (!$s) {
                return SS_Rest_Office::err('スタッフが見つかりません。', 404);
            }
            $list = array($s);
        } else {
            $list = $repo->all('staff', array('active' => 1), 'id', 'ASC', 1000);
        }
        $tenant = SS_Context::tenant();
        $out = array();
        foreach ($list as $s) {
            $s['punch_ver'] = (int) $s['punch_ver'] + 1;
            $repo->update('staff', (int) $s['id'], array('punch_ver' => $s['punch_ver']));
            $out[] = array('id' => (string) $s['id'], 'url' => self::punch_url($tenant, $s));
        }
        SS_Rest_Office::log('QRコードを再発行', $sid > 0 ? $list[0]['name'] : '全員', count($out) . '人');
        return SS_Rest_Office::ok($out);
    }

    /* ---------- タイムカード一覧・修正 ---------- */

    public static function get_time_cards(WP_REST_Request $req) {
        $admin = SS_Rest_Office::is_admin();
        $where = array();
        foreach (array('date' => 'record_date', 'dateFrom' => 'record_date >=', 'dateTo' => 'record_date <=') as $param => $col) {
            $v = SS_Rest_Office::date_or_empty($req->get_param($param));
            if ($v !== '') {
                $where[$col] = $v;
            }
        }
        if ($admin) {
            $sid = (int) $req->get_param('staffId');
            if ($sid > 0) {
                $where['staff_id'] = $sid;
            }
        } else {
            // スタッフは、自分の記録だけ
            $me = SS_Rest_Office::me_staff();
            if (!$me) {
                return SS_Rest_Office::ok(array());
            }
            $where['staff_id'] = (int) $me['id'];
        }
        $limit = (int) $req->get_param('limit');
        $limit = $limit > 0 ? min($limit, SS_Rest_Office::MAX_ROWS) : SS_Rest_Office::MAX_ROWS;
        $rows = SS_Repo::current()->all('time_cards', $where, 'record_date', 'DESC', $limit);
        $out = array();
        foreach ($rows as $row) {
            $out[] = self::format_card($row, $admin); // 打刻の場所は個人情報のため、管理者にだけ返す
        }
        return SS_Rest_Office::ok($out);
    }

    /** 管理者による修正（管理者集計の「タイムカード修正」）。名前・部署は名簿の値で保存する */
    public static function upsert_time_card(WP_REST_Request $req) {
        $p = SS_Rest_Office::params($req);
        $date = SS_Rest_Office::date_or_empty(isset($p['date']) ? $p['date'] : '');
        $staff = SS_Rest_Office::staff_in_tenant(isset($p['staffId']) ? (int) $p['staffId'] : 0);
        if ($date === '' || !$staff) {
            return SS_Rest_Office::err('日付とスタッフを指定してください。', 400);
        }
        $in = SS_Rest_Office::time_or_empty(isset($p['clockIn']) ? $p['clockIn'] : '');
        $out = SS_Rest_Office::time_or_empty(isset($p['clockOut']) ? $p['clockOut'] : '');
        $repo = SS_Repo::current();
        $now = SS_Rest_Office::now();
        $rows = $repo->all('time_cards', array('staff_id' => (int) $staff['id'], 'record_date' => $date), 'id', 'ASC', 1);
        $data = array('staff_name' => $staff['name'], 'department' => (string) $staff['department'], 'clock_in' => $in, 'clock_out' => $out, 'updated_at' => $now);
        if ($rows) {
            $old = $rows[0];
            $repo->update('time_cards', (int) $old['id'], $data);
            $id = (int) $old['id'];
            $detail = $date . ' ' . ($old['clock_in'] ?: '--:--') . '〜' . ($old['clock_out'] ?: '--:--') . ' → ' . ($in ?: '--:--') . '〜' . ($out ?: '--:--');
        } else {
            $id = (int) $repo->insert('time_cards', array_merge($data, array('record_date' => $date, 'staff_id' => (int) $staff['id'], 'created_at' => $now)));
            $detail = $date . ' ' . ($in ?: '--:--') . '〜' . ($out ?: '--:--');
        }
        SS_Rest_Office::log('タイムカードを修正', $staff['name'], $detail);
        $r = self::format_card($repo->find('time_cards', $id), true);
        $r['locationSaved'] = array();
        return SS_Rest_Office::ok($r);
    }

    /* ---------- 勤怠区分（月ごと） ---------- */

    public static function is_company_holiday($date, array $info) {
        $dates = isset($info['holidayDates']) ? (array) $info['holidayDates'] : array();
        if (in_array($date, $dates, true)) {
            return true;
        }
        $ts = strtotime($date);
        $weekly = isset($info['weeklyHolidays']) ? array_map('intval', (array) $info['weeklyHolidays']) : array();
        return $ts !== false && in_array((int) gmdate('w', $ts), $weekly, true);
    }

    public static function get_attendance_month(WP_REST_Request $req) {
        $staff = SS_Rest_Office::staff_in_tenant((int) $req->get_param('staffId'));
        if (!$staff) {
            return SS_Rest_Office::err('staffIdを指定してください。', 400);
        }
        $ps = SS_Rest_Office::date_or_empty($req->get_param('periodStart'));
        $pe = SS_Rest_Office::date_or_empty($req->get_param('periodEnd'));
        if ($ps !== '' && $pe !== '') {
            $start = $ps;
            $end = $pe;
        } else {
            $y = (int) $req->get_param('year');
            $m = (int) $req->get_param('month');
            if ($y < 2000 || $y > 2100 || $m < 1 || $m > 12) {
                return SS_Rest_Office::err('staffId・year・monthを指定してください。', 400);
            }
            $start = sprintf('%04d-%02d-01', $y, $m);
            $end = gmdate('Y-m-t', strtotime($start . ' 00:00:00 UTC'));
        }
        if ($end < $start || (strtotime($end) - strtotime($start)) > 62 * DAY_IN_SECONDS) {
            return SS_Rest_Office::err('期間が正しくありません。', 400);
        }
        $repo = SS_Repo::current();
        $range = array('staff_id' => (int) $staff['id'], 'record_date >=' => $start, 'record_date <=' => $end);
        $saved = array();
        foreach ($repo->all('attendance_days', $range, 'record_date', 'ASC', 100) as $r) {
            $saved[$r['record_date']] = $r;
        }
        $cards = array();
        foreach ($repo->all('time_cards', $range, 'record_date', 'ASC', 100) as $r) {
            $cards[$r['record_date']] = $r;
        }
        $info = SS_Rest_Office::company_info_array();
        $out = array();
        for ($ts = strtotime($start . ' 00:00:00 UTC'), $end_ts = strtotime($end . ' 00:00:00 UTC'); $ts <= $end_ts; $ts += DAY_IN_SECONDS) {
            $d = gmdate('Y-m-d', $ts);
            $hol = self::is_company_holiday($d, $info);
            if (isset($saved[$d])) {
                $r = $saved[$d];
                $out[] = array('date' => $d, 'dayType' => $r['day_type'], 'clockIn' => (string) $r['clock_in'], 'clockOut' => (string) $r['clock_out'],
                    'earlyMinutes' => $r['early_minutes'] === null ? null : (int) $r['early_minutes'],
                    'overtimeMinutes' => $r['overtime_minutes'] === null ? null : (int) $r['overtime_minutes'], 'isHoliday' => $hol);
            } elseif (isset($cards[$d]) && $cards[$d]['clock_in'] !== '') {
                $out[] = array('date' => $d, 'dayType' => $hol ? '休日出勤' : '通常', 'clockIn' => $cards[$d]['clock_in'], 'clockOut' => $cards[$d]['clock_out'],
                    'earlyMinutes' => null, 'overtimeMinutes' => null, 'isHoliday' => $hol);
            } else {
                $out[] = array('date' => $d, 'dayType' => '', 'clockIn' => '', 'clockOut' => '', 'earlyMinutes' => null, 'overtimeMinutes' => null, 'isHoliday' => $hol);
            }
        }
        return SS_Rest_Office::ok($out);
    }

    public static function save_attendance_month(WP_REST_Request $req) {
        $p = SS_Rest_Office::params($req);
        $staff = SS_Rest_Office::staff_in_tenant(isset($p['staffId']) ? (int) $p['staffId'] : 0);
        $days = isset($p['days']) && is_array($p['days']) ? $p['days'] : array();
        if (!$staff || !$days || count($days) > 62) {
            return SS_Rest_Office::err('staffId・daysを指定してください。', 400);
        }
        $repo = SS_Repo::current();
        $now = SS_Rest_Office::now();
        $sid = (int) $staff['id'];
        $min = function ($v) {
            return ($v === null || $v === '' || !is_numeric($v)) ? null : max(0, min(1440, (int) $v));
        };
        foreach ($days as $d) {
            if (!is_array($d)) {
                continue;
            }
            $date = SS_Rest_Office::date_or_empty(isset($d['date']) ? $d['date'] : '');
            if ($date === '') {
                continue;
            }
            $type = isset($d['dayType']) ? (string) $d['dayType'] : '';
            $existing = $repo->all('attendance_days', array('staff_id' => $sid, 'record_date' => $date), 'id', 'ASC', 1);
            if ($type === '') {
                // 未区分に戻す：行を消し、タイムカードの打刻から補う
                $repo->delete_where('attendance_days', array('staff_id' => $sid, 'record_date' => $date));
                continue;
            }
            if (!in_array($type, self::DAY_TYPES, true)) {
                continue;
            }
            $row = array(
                'day_type' => $type,
                'clock_in' => SS_Rest_Office::time_or_empty(isset($d['clockIn']) ? $d['clockIn'] : ''),
                'clock_out' => SS_Rest_Office::time_or_empty(isset($d['clockOut']) ? $d['clockOut'] : ''),
                'early_minutes' => $min(isset($d['earlyMinutes']) ? $d['earlyMinutes'] : null),
                'overtime_minutes' => $min(isset($d['overtimeMinutes']) ? $d['overtimeMinutes'] : null),
                'updated_at' => $now,
            );
            if ($existing) {
                $repo->update('attendance_days', (int) $existing[0]['id'], $row);
            } else {
                $repo->insert('attendance_days', array_merge($row, array('staff_id' => $sid, 'record_date' => $date, 'created_at' => $now)));
            }
        }
        return SS_Rest_Office::ok(array('saved' => true));
    }

    /* ---------- 有給休暇 ---------- */

    public static function get_leave_grants(WP_REST_Request $req) {
        $staff = SS_Rest_Office::staff_in_tenant((int) $req->get_param('staffId'));
        if (!$staff) {
            return SS_Rest_Office::err('staffIdを指定してください。', 400);
        }
        $rows = SS_Repo::current()->all('leave_grants', array('staff_id' => (int) $staff['id']), 'grant_date', 'DESC', 500);
        $out = array();
        foreach ($rows as $r) {
            $out[] = array('id' => (string) $r['id'], 'staff_id' => (string) $r['staff_id'], 'grant_date' => $r['grant_date'], 'days' => (string) $r['days'], 'note' => (string) $r['note'], 'created_at' => $r['created_at']);
        }
        return SS_Rest_Office::ok($out);
    }

    public static function create_leave_grant(WP_REST_Request $req) {
        $p = SS_Rest_Office::params($req);
        $staff = SS_Rest_Office::staff_in_tenant(isset($p['staffId']) ? (int) $p['staffId'] : 0);
        $date = SS_Rest_Office::date_or_empty(isset($p['grantDate']) ? $p['grantDate'] : '');
        $days = isset($p['days']) && is_numeric($p['days']) ? round((float) $p['days'], 1) : 0;
        if (!$staff || $date === '' || $days <= 0 || $days > 100) {
            return SS_Rest_Office::err('従業員・付与日・日数を入力してください。', 400);
        }
        $id = SS_Repo::current()->insert('leave_grants', array(
            'staff_id' => (int) $staff['id'], 'grant_date' => $date, 'days' => $days,
            'note' => SS_Rest_Office::text(isset($p['note']) ? $p['note'] : '', 250), 'created_at' => SS_Rest_Office::now(),
        ));
        SS_Rest_Office::log('有給休暇を付与', $staff['name'], $date . ' ' . $days . '日');
        return SS_Rest_Office::ok(array('id' => (int) $id), 201);
    }

    public static function delete_leave_grant(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $row = $repo->find('leave_grants', (int) $req['id']);
        if (!$row) {
            return SS_Rest_Office::err('見つかりません。', 404);
        }
        $repo->delete('leave_grants', (int) $row['id']);
        $s = $repo->find('staff', (int) $row['staff_id']);
        SS_Rest_Office::log('有給休暇の付与を削除', $s ? $s['name'] : '', $row['grant_date'] . ' ' . (float) $row['days'] . '日');
        return SS_Rest_Office::ok(array('deleted' => true));
    }

    /** 労働基準法の原則的な付与日数（週5日以上・週30時間以上の労働者） */
    public static function standard_grant_days($hire_date, $as_of) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $hire_date) || $as_of < $hire_date) {
            return null;
        }
        $diff = (new DateTime($hire_date))->diff(new DateTime($as_of));
        $months = $diff->y * 12 + $diff->m;
        $days = null;
        foreach (array(6 => 10, 18 => 11, 30 => 12, 42 => 14, 54 => 16, 66 => 18, 78 => 20) as $m => $d) {
            if ($months >= $m) {
                $days = $d;
            }
        }
        return $days;
    }

    private static function plus_years($date, $years) {
        return gmdate('Y-m-d', strtotime($date . ' 00:00:00 UTC +' . (int) $years . ' years'));
    }

    /** 付与（古い順）に、消化日（古い順）を先入れ先出しで割り当て、2年の時効・残日数を出す */
    public static function leave_ledger(SS_Repo $repo, $staff_id, $as_of) {
        $grants = $repo->all('leave_grants', array('staff_id' => (int) $staff_id, 'grant_date <=' => $as_of), 'grant_date', 'ASC', 500);
        $usage = $repo->all('attendance_days', array('staff_id' => (int) $staff_id, 'day_type' => '有休', 'record_date <=' => $as_of), 'record_date', 'ASC', 5000);
        $ledger = array();
        foreach ($grants as $g) {
            $ledger[] = array('grantDate' => $g['grant_date'], 'days' => (float) $g['days'], 'remaining' => (float) $g['days'], 'expiry' => self::plus_years($g['grant_date'], 2));
        }
        $details = array();
        foreach ($usage as $u) {
            $from = '';
            foreach ($ledger as &$g) {
                if ($g['remaining'] <= 0 || $g['grantDate'] > $u['record_date'] || $g['expiry'] <= $u['record_date']) {
                    continue;
                }
                $g['remaining'] -= 1;
                $from = $g['grantDate'];
                break;
            }
            unset($g);
            $details[] = array('date' => $u['record_date'], 'grantDate' => $from);
        }
        $granted = 0;
        $expired = 0;
        $remaining = 0;
        $next = null;
        foreach ($ledger as $g) {
            $granted += $g['days'];
            if ($g['expiry'] <= $as_of) {
                $expired += max(0, $g['remaining']);
            } else {
                $remaining += max(0, $g['remaining']);
                if ($g['remaining'] > 0 && ($next === null || $g['expiry'] < $next['date'])) {
                    $next = array('date' => $g['expiry'], 'days' => $g['remaining']);
                }
            }
        }
        return array('granted' => $granted, 'used' => count($usage), 'usedDetails' => $details, 'expired' => $expired, 'remaining' => $remaining, 'nextExpiry' => $next);
    }

    /** 年5日の取得義務（付与日数10日以上の付与ごと） */
    public static function five_day_checks(SS_Repo $repo, $staff_id) {
        $today = current_time('Y-m-d');
        $out = array();
        foreach ($repo->all('leave_grants', array('staff_id' => (int) $staff_id, 'days >=' => 10), 'grant_date', 'ASC', 500) as $g) {
            $deadline = self::plus_years($g['grant_date'], 1);
            $used = $repo->count('attendance_days', array('staff_id' => (int) $staff_id, 'day_type' => '有休', 'record_date >=' => $g['grant_date'], 'record_date <' => $deadline));
            $short = max(0, 5 - $used);
            $out[] = array('grantDate' => $g['grant_date'], 'days' => (float) $g['days'], 'deadline' => $deadline, 'used' => $used, 'shortfall' => $short,
                'status' => $short > 0 ? ($deadline < $today ? 'violated' : 'warning') : 'ok');
        }
        return $out;
    }

    public static function get_leave_balance(WP_REST_Request $req) {
        $staff = SS_Rest_Office::staff_in_tenant((int) $req['staffId']);
        if (!$staff) {
            return SS_Rest_Office::err('従業員が見つかりません。', 404);
        }
        $as_of = SS_Rest_Office::date_or_empty($req->get_param('asOf'));
        $as_of = $as_of !== '' ? $as_of : current_time('Y-m-d');
        $l = self::leave_ledger(SS_Repo::current(), (int) $staff['id'], $as_of);
        $l['standardGrantDays'] = self::standard_grant_days($staff['hire_date'], $as_of);
        $l['hireDate'] = (string) $staff['hire_date'];
        return SS_Rest_Office::ok($l);
    }

    public static function get_five_day_check(WP_REST_Request $req) {
        $staff = SS_Rest_Office::staff_in_tenant((int) $req['staffId']);
        if (!$staff) {
            return SS_Rest_Office::err('従業員が見つかりません。', 404);
        }
        return SS_Rest_Office::ok(self::five_day_checks(SS_Repo::current(), (int) $staff['id']));
    }

    public static function get_five_day_warnings(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $out = array();
        foreach ($repo->all('staff', array('active' => 1), 'id', 'ASC', 1000) as $s) {
            foreach (self::five_day_checks($repo, (int) $s['id']) as $c) {
                if ($c['status'] !== 'ok') {
                    $c['staffId'] = (int) $s['id'];
                    $c['staffName'] = $s['name'];
                    $out[] = $c;
                }
            }
        }
        return SS_Rest_Office::ok($out);
    }

    /** 本人の有給（ログインした本人の分だけ。他の人のIDは受け取らない） */
    public static function get_my_leave_balance(WP_REST_Request $req) {
        $me = SS_Rest_Office::me_staff();
        if (!$me) {
            return SS_Rest_Office::err('このアカウントは、スタッフ名簿に紐づいていません。', 400);
        }
        $repo = SS_Repo::current();
        $as_of = current_time('Y-m-d');
        $l = self::leave_ledger($repo, (int) $me['id'], $as_of);
        $l['hireDate'] = (string) $me['hire_date'];
        $l['staffName'] = $me['name'];
        $l['department'] = (string) $me['department'];
        $l['grants'] = array();
        foreach ($repo->all('leave_grants', array('staff_id' => (int) $me['id']), 'grant_date', 'DESC', 500) as $g) {
            $l['grants'][] = array('grantDate' => $g['grant_date'], 'days' => (float) $g['days'], 'note' => (string) $g['note'], 'expiry' => self::plus_years($g['grant_date'], 2));
        }
        $l['fiveDayCheck'] = self::five_day_checks($repo, (int) $me['id']);
        return SS_Rest_Office::ok($l);
    }
}
