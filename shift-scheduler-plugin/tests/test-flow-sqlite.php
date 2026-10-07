<?php
/**
 * お客様A・Bの2社を使った、通しのテスト（SQLite上で実行。WordPress本体は不要）。
 * 実行：php tests/test-flow-sqlite.php
 *
 * 確認すること：
 *  - 他のお客様のスタッフ・勤務区分・ルール・希望の収集期間・希望を、読めない・変えられない・消せない
 *  - 希望の提出（置き換え・期間外・締切後・受付終了）
 *  - 閲覧のみの状態では書き込み権限がない
 */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('DAY_IN_SECONDS', 86400);
define('SS_TRIAL_STAFF_LIMIT', 100);
define('SS_TRIAL_MONTHS', 2);

class WP_Error { public $code; public $msg; public $data; function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->msg = $m; $this->data = $d; } }
class WP_REST_Request implements ArrayAccess {
    private $p; private $q;
    function __construct($json = array(), $query = array()) { $this->p = $json; $this->q = $query; }
    function get_json_params() { return $this->p; }
    function get_param($k) { return isset($this->q[$k]) ? $this->q[$k] : (isset($this->p[$k]) ? $this->p[$k] : null); }
    function offsetExists($o): bool { return isset($this->q[$o]); }
    function offsetGet($o): mixed { return $this->q[$o]; }
    function offsetSet($o, $v): void { $this->q[$o] = $v; }
    function offsetUnset($o): void { unset($this->q[$o]); }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
function rest_ensure_response($x) { return $x; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_email($s) { return trim((string) $s); }
function is_email($s) { return (bool) preg_match('/^[^@\s]+@[^@\s]+$/', $s); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function add_action() {}
function add_filter() {}
$GLOBALS['today'] = '2026-10-20';
function wp_date($fmt) { return $GLOBALS['today']; }
$GLOBALS['uid'] = 0;
$GLOBALS['caps'] = array(
    'owner' => array('shift_manage_staff', 'shift_manage_schedule', 'shift_submit_requests', 'shift_view'),
    'staff' => array('shift_submit_requests', 'shift_view'),
);
$GLOBALS['wp_roles'] = array(); // wp_user_id => role
function is_user_logged_in() { return $GLOBALS['uid'] > 0; }
function get_current_user_id() { return $GLOBALS['uid']; }
function current_user_can($cap) { $r = isset($GLOBALS['wp_roles'][$GLOBALS['uid']]) ? $GLOBALS['wp_roles'][$GLOBALS['uid']] : ''; return in_array($cap, isset($GLOBALS['caps'][$r]) ? $GLOBALS['caps'][$r] : array(), true); }

/* ---- SQLite 上の簡易 wpdb ---- */
class FakeWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $pdo;
    function __construct() {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $t = array(
            'tenants' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, public_id, name, industry, status, trial_start, trial_end, plan_id, payjp_customer_id DEFAULT "", payjp_subscription_id DEFAULT "", next_billing_at, settings, created_at, deleted_at',
            'users' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, wp_user_id, email, role, staff_id, status, last_login_at, created_at',
            'staff' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, name, kana DEFAULT "", employment DEFAULT "part", roles, qualifications, available_weekdays DEFAULT "0,1,2,3,4,5,6", max_hours_week, max_hours_month, max_days_row, night_ok DEFAULT 1, active DEFAULT 1, sort_order DEFAULT 0, created_at',
            'plans' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, name, max_staff, price, payjp_plan_id DEFAULT "", active DEFAULT 1, sort_order DEFAULT 0',
            'patterns' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, name, short_name, start_time, end_time, break_minutes, crosses_midnight, counts_as_night, color, active, sort_order, created_at',
            'rules' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, type, params, hard, weight, active, created_at',
            'request_periods' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, start_date, end_date, deadline, status, created_at',
            'requests' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, period_id, staff_id, date, kind, note, submitted_at',
            'request_submissions' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, period_id, staff_id, submitted_at',
        );
        foreach ($t as $name => $cols) { $this->pdo->exec("CREATE TABLE wp_shift_{$name} ({$cols})"); }
    }
    function prepare($sql, $args = null) {
        $args = is_array($args) ? $args : array_slice(func_get_args(), 1);
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $v = $args[$i++];
            return $m[0] === '%d' ? (string) (int) $v : $this->pdo->quote((string) $v);
        }, $sql);
    }
    function get_row($sql, $o = null) { $r = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC); return $r ? $r : null; }
    function get_results($sql, $o = null) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    function get_var($sql) { $r = $this->pdo->query($sql)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
    function get_col($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN); }
    function query($sql) { return $this->pdo->exec($sql); }
    private function lit($v) { return $v === null ? 'NULL' : (is_int($v) ? (string) $v : $this->pdo->quote((string) $v)); }
    private function cond($w) { $p = array(); foreach ($w as $k => $v) { $p[] = "{$k} = " . $this->lit($v); } return implode(' AND ', $p); }
    function insert($t, $data) {
        $cols = implode(',', array_keys($data));
        $vals = implode(',', array_map(array($this, 'lit'), array_values($data)));
        $this->pdo->exec("INSERT INTO {$t} ({$cols}) VALUES ({$vals})");
        $this->insert_id = (int) $this->pdo->lastInsertId();
        return 1;
    }
    function update($t, $data, $where) {
        $set = array(); foreach ($data as $k => $v) { $set[] = "{$k} = " . $this->lit($v); }
        return $this->pdo->exec("UPDATE {$t} SET " . implode(', ', $set) . ' WHERE ' . $this->cond($where));
    }
    function delete($t, $where) { return $this->pdo->exec("DELETE FROM {$t} WHERE " . $this->cond($where)); }
}
$wpdb = new FakeWpdb();

foreach (array('system', 'context', 'repo', 'tenants', 'presets', 'rest', 'rest-plan') as $f) {
    require __DIR__ . "/../includes/class-ss-{$f}.php";
}

$failed = 0;
function check($n, $c) { global $failed; echo ($c ? 'PASS ' : 'FAIL ') . $n . "\n"; if (!$c) { $failed++; } }
function as_user($wp_id) { $GLOBALS['uid'] = $wp_id; SS_Context::reset(); }
function req($json = array(), $query = array()) { return new WP_REST_Request($json, $query); }
function code($r) { return is_wp_error($r) ? $r->code : 'ok'; }
function scalar($sql) { global $wpdb; return (int) $wpdb->get_var($sql); }

/* ---- 準備：お客様A（飲食）とB（介護） ---- */
$wpdb->insert('wp_shift_plans', array('name' => 'ライト', 'max_staff' => 50, 'price' => 500));
$now = gmdate('Y-m-d H:i:s');
foreach (array(array('aaaaaaaaaaaa', 'A店', 'restaurant'), array('bbbbbbbbbbbb', 'B施設', 'care')) as $t) {
    $wpdb->insert('wp_shift_tenants', array('public_id' => $t[0], 'name' => $t[1], 'industry' => $t[2], 'status' => 'trial', 'trial_end' => gmdate('Y-m-d H:i:s', time() + 86400 * 30), 'created_at' => $now));
}
$A = 1; $B = 2;
SS_Presets::apply(SS_Repo::for_tenant($A), 'restaurant');
SS_Presets::apply(SS_Repo::for_tenant($B), 'care');
check('preset restaurant patterns', scalar("SELECT COUNT(*) FROM wp_shift_patterns WHERE tenant_id = {$A}") === 3);
check('preset care patterns', scalar("SELECT COUNT(*) FROM wp_shift_patterns WHERE tenant_id = {$B}") === 4);
check('preset care has night pattern crossing midnight', scalar("SELECT COUNT(*) FROM wp_shift_patterns WHERE tenant_id = {$B} AND counts_as_night = 1 AND crosses_midnight = 1") === 1);
check('preset is not applied twice', SS_Presets::apply(SS_Repo::for_tenant($A), 'restaurant') === 0);

// ユーザー：A管理者(wp1)、Aスタッフ(wp2)、B管理者(wp3)、Bスタッフ(wp4)
$users = array(array($A, 1, 'owner', null), array($A, 2, 'staff', null), array($B, 3, 'owner', null), array($B, 4, 'staff', null));
foreach ($users as $u) {
    $wpdb->insert('wp_shift_users', array('tenant_id' => $u[0], 'wp_user_id' => $u[1], 'email' => "u{$u[1]}@example.test", 'role' => $u[2], 'status' => 'active', 'created_at' => $now));
    $GLOBALS['wp_roles'][$u[1]] = $u[2];
}

/* ---- スタッフ ---- */
as_user(1);
$r = SS_Rest::create_staff(req(array('name' => 'A太郎')));
$a_staff = $r['staff']['id'];
$r = SS_Rest::create_staff(req(array('name' => 'A花子')));
$a_staff2 = $r['staff']['id'];
as_user(3);
$r = SS_Rest::create_staff(req(array('name' => 'B次郎')));
$b_staff = $r['staff']['id'];
check('B list sees only B staff', count(SS_Rest::list_staff()['staff']) === 1 && SS_Rest::list_staff()['staff'][0]['name'] === 'B次郎');
check('B cannot update A staff', code(SS_Rest::update_staff(req(array('name' => '乗っ取り'), array('id' => $a_staff)))) === 'not_found');
check('B cannot deactivate A staff', code(SS_Rest::deactivate_staff(req(array(), array('id' => $a_staff)))) === 'not_found');
check('B cannot invite A staff', code(SS_Rest::invite_staff(req(array('email' => 'x@example.test'), array('id' => $a_staff)))) === 'not_found');
check('A staff unchanged', scalar("SELECT COUNT(*) FROM wp_shift_staff WHERE tenant_id = {$A} AND active = 1 AND name IN ('A太郎','A花子')") === 2);

// Aスタッフ(wp2)・Bスタッフ(wp4)を、それぞれのスタッフに紐づける
$wpdb->update('wp_shift_users', array('staff_id' => $a_staff), array('wp_user_id' => 2));
$wpdb->update('wp_shift_users', array('staff_id' => $b_staff), array('wp_user_id' => 4));

/* ---- 勤務区分 ---- */
as_user(1);
$a_patterns = SS_Rest_Plan::list_patterns()['patterns'];
$a_pid = $a_patterns[0]['id'];
as_user(3);
$b_patterns = SS_Rest_Plan::list_patterns()['patterns'];
check('B sees 4 care patterns only', count($b_patterns) === 4 && $b_patterns[0]['name'] === '早番');
check('B cannot update A pattern', code(SS_Rest_Plan::update_pattern(req(array('name' => 'x'), array('id' => $a_pid)))) === 'not_found');
check('B cannot deactivate A pattern', code(SS_Rest_Plan::deactivate_pattern(req(array(), array('id' => $a_pid)))) === 'not_found');
$r = SS_Rest_Plan::create_pattern(req(array('name' => '特別', 'start_time' => '10:00', 'end_time' => '14:00', 'break_minutes' => 0, 'color' => '#112233')));
check('B can create own pattern', code($r) === 'ok' && scalar("SELECT COUNT(*) FROM wp_shift_patterns WHERE tenant_id = {$B}") === 5);
check('A patterns untouched', scalar("SELECT COUNT(*) FROM wp_shift_patterns WHERE tenant_id = {$A}") === 3);

/* ---- ルール ---- */
as_user(1);
$r = SS_Rest_Plan::create_rule(req(array('weekday' => 5, 'start' => '17:00', 'end' => '22:30', 'count' => 4, 'role' => 'ホール')));
$a_rule = $r['id'];
$r = SS_Rest_Plan::save_global_rules(req(array('max_consecutive_days' => 4, 'min_rest_hours' => '')));
as_user(3);
$rules_b = SS_Rest_Plan::list_rules();
check('B sees no A required_staff', count($rules_b['required_staff']) === 0);
check('B global rules are B preset (care: 5 days)', $rules_b['global']->max_consecutive_days === 5);
check('B cannot update A rule', code(SS_Rest_Plan::update_rule(req(array('weekday' => 1, 'start' => '09:00', 'end' => '10:00', 'count' => 9), array('id' => $a_rule)))) === 'not_found');
check('B cannot delete A rule', code(SS_Rest_Plan::delete_rule(req(array(), array('id' => $a_rule)))) === 'not_found');
check('A rule still exists', scalar("SELECT COUNT(*) FROM wp_shift_rules WHERE id = {$a_rule} AND tenant_id = {$A}") === 1);
as_user(1);
$rules_a = SS_Rest_Plan::list_rules();
check('A global saved (4 days) and rest removed', $rules_a['global']->max_consecutive_days === 4 && !isset($rules_a['global']->min_rest_hours));
check('B global unchanged after A saved', scalar("SELECT COUNT(*) FROM wp_shift_rules WHERE tenant_id = {$B} AND type = 'min_rest_hours'") === 1);
check('global rule bad value rejected', code(SS_Rest_Plan::save_global_rules(req(array('max_consecutive_days' => 99)))) === 'invalid');

/* ---- 希望の収集期間 ---- */
as_user(1);
$r = SS_Rest_Plan::create_period(req(array('start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'deadline' => '2026-10-25')));
$a_period = $r['period']['id'];
as_user(3);
$r = SS_Rest_Plan::create_period(req(array('start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'deadline' => '2026-10-25')));
$b_period = $r['period']['id'];
check('B list_periods sees only own', count(SS_Rest_Plan::list_periods()['periods']) === 1 && SS_Rest_Plan::list_periods()['periods'][0]['id'] === $b_period);
check('B cannot update A period', code(SS_Rest_Plan::update_period(req(array('status' => 'closed'), array('id' => $a_period)))) === 'not_found');
check('B cannot see A period status', code(SS_Rest_Plan::period_status(req(array(), array('id' => $a_period)))) === 'not_found');
check('B cannot delete A period', code(SS_Rest_Plan::delete_period(req(array(), array('id' => $a_period)))) === 'not_found');

/* ---- 希望の提出 ---- */
as_user(2); // Aスタッフ
check('A staff can read mine', SS_Rest_Plan::can_read_mine() && SS_Rest_Plan::can_write_mine());
check('A staff cannot manage plan', !SS_Rest_Plan::can_read_plan());
check('A staff sees open period', count(SS_Rest_Plan::my_periods()['periods']) === 1);
$r = SS_Rest_Plan::save_my_requests(req(array('period_id' => $a_period, 'items' => array(
    array('date' => '2026-11-03', 'kind' => 'off', 'note' => '通院'),
    array('date' => '2026-11-10', 'kind' => 'ng'),
))));
check('A staff saves requests', code($r) === 'ok' && $r['saved'] === 2);
$r = SS_Rest_Plan::save_my_requests(req(array('period_id' => $a_period, 'items' => array(array('date' => '2026-11-15', 'kind' => 'off')))));
check('resubmit replaces previous', scalar("SELECT COUNT(*) FROM wp_shift_requests WHERE tenant_id = {$A} AND staff_id = {$a_staff}") === 1 && scalar("SELECT COUNT(*) FROM wp_shift_request_submissions WHERE tenant_id = {$A} AND staff_id = {$a_staff}") === 1);
check('date outside period rejected', code(SS_Rest_Plan::save_my_requests(req(array('period_id' => $a_period, 'items' => array(array('date' => '2026-12-01', 'kind' => 'off')))))) === 'invalid');
check('bad kind rejected', code(SS_Rest_Plan::save_my_requests(req(array('period_id' => $a_period, 'items' => array(array('date' => '2026-11-02', 'kind' => 'prefer')))))) === 'invalid');
check('rejected submit keeps previous data', scalar("SELECT COUNT(*) FROM wp_shift_requests WHERE tenant_id = {$A} AND staff_id = {$a_staff}") === 1);
$mine = SS_Rest_Plan::my_requests(req(array(), array('period_id' => $a_period)));
check('my_requests returns own items and editable', count($mine['items']) === 1 && $mine['submitted'] === true && $mine['editable'] === true);

as_user(4); // Bスタッフが、Aの期間に提出しようとする
check('B staff cannot submit to A period', code(SS_Rest_Plan::save_my_requests(req(array('period_id' => $a_period, 'items' => array(array('date' => '2026-11-03', 'kind' => 'off')))))) === 'not_found');
check('B staff cannot read A period requests', code(SS_Rest_Plan::my_requests(req(array(), array('period_id' => $a_period)))) === 'not_found');
check('B staff submit to own period ok', code(SS_Rest_Plan::save_my_requests(req(array('period_id' => $b_period, 'items' => array(array('date' => '2026-11-03', 'kind' => 'off')))))) === 'ok');

as_user(1); // 管理者：提出状況
$st = SS_Rest_Plan::period_status(req(array(), array('id' => $a_period)))['staff'];
$by = array(); foreach ($st as $s) { $by[$s['name']] = $s; }
check('status shows A太郎 submitted, A花子 not', $by['A太郎']['submitted'] === true && $by['A太郎']['count'] === 1 && $by['A花子']['submitted'] === false && !isset($by['B次郎']));

// 受付終了・締切後
SS_Rest_Plan::update_period(req(array('status' => 'closed'), array('id' => $a_period)));
as_user(2);
check('closed period rejects submission', code(SS_Rest_Plan::save_my_requests(req(array('period_id' => $a_period, 'items' => array())))) === 'closed');
as_user(1);
SS_Rest_Plan::update_period(req(array('status' => 'open'), array('id' => $a_period)));
$GLOBALS['today'] = '2026-10-26'; // 締切の翌日
as_user(2);
check('after deadline rejects submission', code(SS_Rest_Plan::save_my_requests(req(array('period_id' => $a_period, 'items' => array())))) === 'closed');
check('after deadline period not listed', count(SS_Rest_Plan::my_periods()['periods']) === 0);
$GLOBALS['today'] = '2026-10-20';

// 期間の削除は自社の希望だけを消す
as_user(1);
SS_Rest_Plan::delete_period(req(array(), array('id' => $a_period)));
check('delete period removes A requests', scalar("SELECT COUNT(*) FROM wp_shift_requests WHERE tenant_id = {$A}") === 0 && scalar("SELECT COUNT(*) FROM wp_shift_request_submissions WHERE tenant_id = {$A}") === 0);
check('delete period keeps B requests', scalar("SELECT COUNT(*) FROM wp_shift_requests WHERE tenant_id = {$B}") === 1 && scalar("SELECT COUNT(*) FROM wp_shift_request_periods WHERE tenant_id = {$B}") === 1);

// 閲覧のみ（無料期間終了）では、書き込み権限がない
$wpdb->update('wp_shift_tenants', array('status' => 'readonly'), array('id' => $A));
as_user(1);
check('readonly tenant: can read but not write plan', SS_Rest_Plan::can_read_plan() && !SS_Rest_Plan::can_write_plan() && !SS_Rest::can_write_staff());
as_user(2);
check('readonly tenant: staff cannot submit', SS_Rest_Plan::can_read_mine() && !SS_Rest_Plan::can_write_mine());
as_user(3);
check('other tenant still writable', SS_Rest_Plan::can_write_plan());

echo $failed ? "\n{$failed} FAILED\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
