<?php
/**
 * スタッフ名簿（社員番号・部署）と、お客様ごとの「社員番号＋パスワード」ログインのテスト。
 * 実行：php tests/test-staff-login.php
 *
 * 確認すること：
 *  - 社員番号は、同じお客様の中だけで重複を禁止する（他のお客様の同じ番号は別人として扱う）
 *  - ログインは、URLのお客様の中だけで社員番号を探す。他のお客様のスタッフには、決して当たらない
 *  - 無効・招待中・パスワード未設定・停止中のお客様などは、ログインできない
 *  - 料金の切り替え（0.6.0）は、初期値のままのときだけ行う
 */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('DAY_IN_SECONDS', 86400);
define('SS_TRIAL_STAFF_LIMIT', 200);
define('SS_TRIAL_MONTHS', 1);
define('SS_PRODUCT_NAME', 'マッシュオフィス 経理・管理・勤怠');

class WP_Error { public $code; public $msg; public $data; function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->msg = $m; $this->data = $d; } function get_error_code() { return $this->code; } function get_error_message() { return $this->msg; } }
class WP_User { public $ID; public $user_pass; public $roles; function __construct($id, $pass, $roles) { $this->ID = $id; $this->user_pass = $pass; $this->roles = $roles; } }
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
$GLOBALS['opts'] = array();
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $d; }

/* ---- WordPressのユーザー（模擬） ---- */
$GLOBALS['wp_users'] = array(); $GLOBALS['next_wp_id'] = 100;
function username_exists($login) { foreach ($GLOBALS['wp_users'] as $u) { if ($u['login'] === $login) { return $u['id']; } } return false; }
function wp_generate_password($n = 12, $s = true, $x = false) { return substr(bin2hex(random_bytes($n)), 0, $n); }
function wp_insert_user($d) { $id = $GLOBALS['next_wp_id']++; $GLOBALS['wp_users'][$id] = array('id' => $id, 'login' => $d['user_login'], 'email' => $d['user_email'], 'hash' => password_hash($d['user_pass'], PASSWORD_DEFAULT), 'roles' => array($d['role'])); return $id; }
function wp_set_password($pw, $id) { $GLOBALS['wp_users'][$id]['hash'] = password_hash($pw, PASSWORD_DEFAULT); }
function get_userdata($id) { return isset($GLOBALS['wp_users'][$id]) ? new WP_User($id, $GLOBALS['wp_users'][$id]['hash'], $GLOBALS['wp_users'][$id]['roles']) : false; }
function wp_check_password($pw, $hash, $id) { return password_verify($pw, $hash); }
function user_can($u, $cap) { return false; }
class WP_Session_Tokens { static function get_instance($id) { return new self(); } function destroy_all() {} }

$GLOBALS['uid'] = 0;
$GLOBALS['caps'] = array('owner' => array('shift_manage_staff', 'shift_manage_schedule', 'shift_view'), 'staff' => array('shift_view'));
$GLOBALS['wp_roles'] = array();
function is_user_logged_in() { return $GLOBALS['uid'] > 0; }
function get_current_user_id() { return $GLOBALS['uid']; }
function current_user_can($cap) { $r = isset($GLOBALS['wp_roles'][$GLOBALS['uid']]) ? $GLOBALS['wp_roles'][$GLOBALS['uid']] : ''; return in_array($cap, isset($GLOBALS['caps'][$r]) ? $GLOBALS['caps'][$r] : array(), true); }

class FakeWpdb {
    public $prefix = 'wp_'; public $insert_id = 0; public $pdo;
    function __construct() {
        $this->pdo = new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $t = array(
            'tenants' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, public_id, name, industry, status, trial_start, trial_end, plan_id, payjp_customer_id DEFAULT "", payjp_subscription_id DEFAULT "", next_billing_at, settings, created_at, deleted_at',
            'users' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, wp_user_id, email, role, staff_id, status, last_login_at, created_at',
            'staff' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, name, kana DEFAULT "", employee_number DEFAULT "", department DEFAULT "", employment DEFAULT "part", roles, qualifications, available_weekdays DEFAULT "0,1,2,3,4,5,6", max_hours_week, max_hours_month, max_days_row, night_ok DEFAULT 1, active DEFAULT 1, sort_order DEFAULT 0, created_at',
            'tokens' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, user_id, type, token_hash, expires_at, used_at, created_at',
            'plans' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, name, max_staff, price, payjp_plan_id DEFAULT "", payjp_amount DEFAULT 0, active DEFAULT 1, sort_order DEFAULT 0',
        );
        foreach ($t as $name => $cols) { $this->pdo->exec("CREATE TABLE wp_shift_{$name} ({$cols})"); }
    }
    function prepare($sql, $args = null) { $args = is_array($args) ? $args : array_slice(func_get_args(), 1); $i = 0; return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) { $v = $args[$i++]; return $m[0] === '%d' ? (string) (int) $v : $this->pdo->quote((string) $v); }, $sql); }
    function get_row($sql, $o = null) { $r = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC); return $r ? $r : null; }
    function get_results($sql, $o = null) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    function get_var($sql) { $r = $this->pdo->query($sql)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
    function get_col($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN); }
    function query($sql) { return $this->pdo->exec($sql); }
    private function lit($v) { return $v === null ? 'NULL' : (is_int($v) ? (string) $v : $this->pdo->quote((string) $v)); }
    private function cond($w) { $p = array(); foreach ($w as $k => $v) { $p[] = $v === null ? "{$k} IS NULL" : "{$k} = " . $this->lit($v); } return implode(' AND ', $p); }
    function insert($t, $data) { $this->pdo->exec("INSERT INTO {$t} (" . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_map(array($this, 'lit'), array_values($data))) . ')'); $this->insert_id = (int) $this->pdo->lastInsertId(); return 1; }
    function update($t, $data, $where) { $set = array(); foreach ($data as $k => $v) { $set[] = "{$k} = " . $this->lit($v); } return $this->pdo->exec("UPDATE {$t} SET " . implode(', ', $set) . ' WHERE ' . $this->cond($where)); }
    function delete($t, $where) { return $this->pdo->exec("DELETE FROM {$t} WHERE " . $this->cond($where)); }
}
$wpdb = new FakeWpdb();
foreach (array('system', 'context', 'repo', 'tenants', 'rest', 'auth', 'installer') as $f) { require __DIR__ . "/../includes/class-ss-{$f}.php"; }

$failed = 0;
function check($n, $c) { global $failed; echo ($c ? 'PASS ' : 'FAIL ') . $n . "\n"; if (!$c) { $failed++; } }
function as_user($wp_id) { $GLOBALS['uid'] = $wp_id; SS_Context::reset(); }
function req($json = array(), $query = array()) { return new WP_REST_Request($json, $query); }
function code($r) { return is_wp_error($r) ? $r->code : 'ok'; }
function T($id) { return SS_System::tenant_by_id($id); }

/* ---- 準備：お客様A・B（どちらも管理者1人） ---- */
$now = gmdate('Y-m-d H:i:s');
foreach (array(array('aaaaaaaaaaaa', 'A店'), array('bbbbbbbbbbbb', 'B施設')) as $t) {
    $wpdb->insert('wp_shift_tenants', array('public_id' => $t[0], 'name' => $t[1], 'industry' => 'care', 'status' => 'trial', 'trial_end' => gmdate('Y-m-d H:i:s', time() + 86400 * 20), 'created_at' => $now));
}
$A = 1; $B = 2;
foreach (array(array($A, 1), array($B, 3)) as $u) {
    $wpdb->insert('wp_shift_users', array('tenant_id' => $u[0], 'wp_user_id' => $u[1], 'email' => "owner{$u[1]}@example.test", 'role' => 'owner', 'status' => 'active', 'created_at' => $now));
    $GLOBALS['wp_roles'][$u[1]] = 'owner';
}

/* ---- 社員番号・部署 ---- */
as_user(1);
$r = SS_Rest::create_staff(req(array('name' => 'A太郎', 'employee_number' => '１０１', 'department' => 'ホール')));
check('employee number: full-width digits become half-width', !is_wp_error($r) && $r['staff']['employee_number'] === '101' && $r['staff']['department'] === 'ホール');
$a1 = $r['staff']['id'];
check('employee number: duplicate within the same company is refused', code(SS_Rest::create_staff(req(array('name' => 'A次郎', 'employee_number' => '101')))) === 'empno_used');
check('employee number: invalid characters are refused', code(SS_Rest::create_staff(req(array('name' => 'A三郎', 'employee_number' => '10 1; DROP')))) === 'invalid');
$r = SS_Rest::create_staff(req(array('name' => 'A花子', 'employee_number' => '102')));
$a2 = $r['staff']['id'];
check('employee number: changing to a number already used is refused', code(SS_Rest::update_staff(req(array('employee_number' => '101'), array('id' => $a2)))) === 'empno_used');
check('employee number: keeping your own number is allowed', code(SS_Rest::update_staff(req(array('employee_number' => '102', 'department' => '厨房'), array('id' => $a2)))) === 'ok');
$r = SS_Rest::create_staff(req(array('name' => '番号なし')));
$a3 = $r['staff']['id'];
check('employee number: optional (blank is allowed, several blanks are fine)', code($r) === 'ok' && code(SS_Rest::create_staff(req(array('name' => '番号なし2')))) === 'ok');
as_user(3);
$r = SS_Rest::create_staff(req(array('name' => 'B一郎', 'employee_number' => '101')));
check('employee number: another company may use the same number', code($r) === 'ok');
$b1 = $r['staff']['id'];

/* ---- パスワードの設定（管理者） ---- */
as_user(1);
check('password: needs an employee number first', code(SS_Rest::set_staff_password(req(array('password' => 'longpassword'), array('id' => $a3)))) === 'no_empno');
check('password: at least 8 characters', code(SS_Rest::set_staff_password(req(array('password' => 'short'), array('id' => $a1)))) === 'password');
check('password: cannot touch another company\'s staff', code(SS_Rest::set_staff_password(req(array('password' => 'longpassword'), array('id' => $b1)))) === 'not_found');
check('password: creates a staff account without email', code(SS_Rest::set_staff_password(req(array('password' => 'a-taro-pass'), array('id' => $a1)))) === 'ok');
$row = SS_Repo::for_tenant($A)->all('users', array('staff_id' => $a1));
check('password: account is active, role staff, email empty, tied to the staff', count($row) === 1 && $row[0]['status'] === 'active' && $row[0]['role'] === 'staff' && $row[0]['email'] === null);
check('password: internal login name is not the employee number', strpos($GLOBALS['wp_users'][(int) $row[0]['wp_user_id']]['login'], 'ssu_') === 0);
as_user(3);
SS_Rest::set_staff_password(req(array('password' => 'b-ichiro-pass'), array('id' => $b1)));
$list = SS_Rest::list_staff();
$by = array(); foreach ($list['staff'] as $s) { $by[$s['id']] = $s; }
check('list: B sees only its own staff, with login ready', count($list['staff']) === 1 && $by[$b1]['staff_login'] === true);

/* ---- ログイン（社員番号＋パスワード） ---- */
$login = function ($tenant_id, $no, $pw) { $u = SS_Auth::find_staff_login(T($tenant_id), $no, $pw); return is_wp_error($u) ? $u->code : $u->ID; };
$a1_wp = (int) SS_Repo::for_tenant($A)->all('users', array('staff_id' => $a1))[0]['wp_user_id'];
$b1_wp = (int) SS_Repo::for_tenant($B)->all('users', array('staff_id' => $b1))[0]['wp_user_id'];
check('login: A + 101 + A\'s password → A太郎', $login($A, '101', 'a-taro-pass') === $a1_wp);
check('login: B + 101 + B\'s password → B一郎 (same number, different company)', $login($B, '101', 'b-ichiro-pass') === $b1_wp);
check('login: A + 101 + B\'s password fails (no cross-company login)', $login($A, '101', 'b-ichiro-pass') === 'ss_login');
check('login: B + 101 + A\'s password fails', $login($B, '101', 'a-taro-pass') === 'ss_login');
check('login: full-width number is accepted after normalising', SS_Auth::find_staff_login(T($A), SS_Rest::normalize_empno('１０１'), 'a-taro-pass')->ID === $a1_wp);
check('login: unknown number fails', $login($A, '999', 'a-taro-pass') === 'ss_login');
check('login: staff without a password fails', $login($A, '102', 'anything-long') === 'ss_login');
check('login: unknown company fails', SS_Auth::find_staff_login(null, '101', 'a-taro-pass')->code === 'ss_login');
as_user(1);
SS_Rest::deactivate_staff(req(array(), array('id' => $a1)));
check('login: deactivated staff cannot log in', $login($A, '101', 'a-taro-pass') === 'ss_login');
SS_Rest::update_staff(req(array('active' => true), array('id' => $a1)));
check('login: reactivated staff stays blocked until the password is set again', $login($A, '101', 'a-taro-pass') === 'ss_disabled');
SS_Rest::set_staff_password(req(array('password' => 'a-taro-new'), array('id' => $a1)));
check('login: after re-setting the password, login works (new password only)', $login($A, '101', 'a-taro-new') === $a1_wp && $login($A, '101', 'a-taro-pass') === 'ss_login');
$wpdb->update('wp_shift_tenants', array('status' => 'readonly'), array('id' => $A));
check('login: read-only company can still log in (to view)', $login($A, '101', 'a-taro-new') === $a1_wp);
$wpdb->update('wp_shift_tenants', array('status' => 'suspended'), array('id' => $A));
check('login: suspended company cannot log in', $login($A, '101', 'a-taro-new') === 'ss_login');
$wpdb->update('wp_shift_tenants', array('status' => 'trial'), array('id' => $A));

/* ---- 料金の切り替え（0.6.0） ---- */
$wpdb->insert('wp_shift_plans', array('name' => 'ライト', 'max_staff' => 50, 'price' => 500, 'payjp_plan_id' => 'pln_old1', 'payjp_amount' => 550, 'sort_order' => 1));
$wpdb->insert('wp_shift_plans', array('name' => 'スタンダード', 'max_staff' => 100, 'price' => 1000, 'payjp_plan_id' => 'pln_old2', 'payjp_amount' => 1100, 'sort_order' => 2));
check('plans v6: old default prices are switched to the bundle prices', SS_Installer::migrate_plans_v6() === true);
$plans = $wpdb->get_results('SELECT name, max_staff, price FROM wp_shift_plans ORDER BY sort_order');
check('plans v6: ライト 50/3980, スタンダード 100/5980, プレミアム 200/9800', json_encode($plans) === json_encode(array(
    array('name' => 'ライト', 'max_staff' => 50, 'price' => 3980), array('name' => 'スタンダード', 'max_staff' => 100, 'price' => 5980), array('name' => 'プレミアム', 'max_staff' => 200, 'price' => 9800))));
check('plans v6: running again changes nothing', SS_Installer::migrate_plans_v6() === false && (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_shift_plans') === 3);
$wpdb->query('DELETE FROM wp_shift_plans');
$wpdb->insert('wp_shift_plans', array('name' => 'ライト', 'max_staff' => 50, 'price' => 700, 'sort_order' => 1));
$wpdb->insert('wp_shift_plans', array('name' => 'スタンダード', 'max_staff' => 100, 'price' => 1000, 'sort_order' => 2));
check('plans v6: prices the operator already changed are left alone', SS_Installer::migrate_plans_v6() === false && (int) $wpdb->get_var("SELECT price FROM wp_shift_plans WHERE name = 'ライト'") === 700);

echo $failed ? "\n{$failed} FAILED\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
