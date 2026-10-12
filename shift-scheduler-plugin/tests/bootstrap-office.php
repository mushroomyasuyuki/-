<?php
/**
 * マッシュオフィスの機能（mo/v1）のテスト用の共通準備：WordPressの代わりの関数、SQLiteの仮のデータベース、
 * お客様A（管理者wp1・スタッフwp2）とB（管理者wp3）の2社。
 */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('SS_TRIAL_STAFF_LIMIT', 200);
define('SS_TRIAL_MONTHS', 1);
define('SS_PRODUCT_NAME', 'マッシュオフィス 経理・管理・勤怠');

class WP_Error { public $code; public $msg; public $data; function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->msg = $m; $this->data = $d; } function get_error_code() { return $this->code; } function get_error_message() { return $this->msg; } }
class WP_REST_Response { public $data; public $status; function __construct($d = null, $s = 200) { $this->data = $d; $this->status = $s; } }
class WP_REST_Request implements ArrayAccess {
    private $p; private $q; private $f; private $route;
    function __construct($json = array(), $query = array(), $files = array(), $route = '') { $this->p = $json; $this->q = $query; $this->f = $files; $this->route = $route; }
    function get_route() { return $this->route; }
    function get_json_params() { return $this->p; }
    function get_params() { return array_merge($this->p, $this->q); }
    function get_param($k) { return isset($this->q[$k]) ? $this->q[$k] : (isset($this->p[$k]) ? $this->p[$k] : null); }
    function get_file_params() { return $this->f; }
    function offsetExists($o): bool { return isset($this->q[$o]); }
    function offsetGet($o): mixed { return $this->q[$o]; }
    function offsetSet($o, $v): void { $this->q[$o] = $v; }
    function offsetUnset($o): void { unset($this->q[$o]); }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
function rest_ensure_response($x) { return $x; }
function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string) $s))); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function add_action() {}
function add_filter() {}
function current_time($t) { return $t === 'timestamp' ? time() : gmdate($t === 'mysql' ? 'Y-m-d H:i:s' : $t); }
$GLOBALS['opts'] = array(); $GLOBALS['transients'] = array();
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $d; }
function get_transient($k) { return isset($GLOBALS['transients'][$k]) ? $GLOBALS['transients'][$k] : false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function wp_salt($s = '') { return 'salt-' . $s; }
function home_url($p = '') { return 'https://example.test' . $p; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
$tmp = sys_get_temp_dir() . '/ss-office-' . getmypid(); @mkdir($tmp, 0777, true);
function wp_upload_dir() { global $tmp; return array('basedir' => $tmp . '/uploads'); }
function wp_mkdir_p($d) { return @mkdir($d, 0777, true) || is_dir($d); }
function wp_get_current_user() { return (object) array('display_name' => 'WPユーザー' . $GLOBALS['uid']); }

$GLOBALS['uid'] = 0;
$GLOBALS['caps'] = array(
    'owner' => array('shift_manage_staff', 'shift_manage_schedule', 'shift_view', 'shift_manage_settings', 'shift_manage_billing'),
    'manager' => array('shift_manage_staff', 'shift_manage_schedule', 'shift_view'),
    'staff' => array('shift_view'),
);
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
            'staff' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, name, kana DEFAULT "", employee_number DEFAULT "", department DEFAULT "", employment DEFAULT "part", roles, qualifications, available_weekdays DEFAULT "0,1,2,3,4,5,6", max_hours_week, max_hours_month, max_days_row, night_ok DEFAULT 1, active DEFAULT 1, sort_order DEFAULT 0, hire_date DEFAULT "", punch_ver DEFAULT 0, profile, created_at',
            'plans' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, name, max_staff, price, payjp_plan_id DEFAULT "", payjp_amount DEFAULT 0, active DEFAULT 1, sort_order DEFAULT 0',
            'task_records' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, record_date, staff_id, staff_name DEFAULT "", department DEFAULT "", category DEFAULT "", client, project, start_time DEFAULT "", end_time DEFAULT "", status DEFAULT "予定", is_outside DEFAULT 0, area DEFAULT "", prefecture DEFAULT "", travel_type DEFAULT "", note, bulk_mode DEFAULT 0, bulk_count DEFAULT "", progress DEFAULT 0, current_day DEFAULT 0, total_days DEFAULT 0, due_date DEFAULT "", holiday_work DEFAULT 0, early_work DEFAULT 0, night_work DEFAULT 0, images_cleared DEFAULT 0, created_at, updated_at',
            'task_images' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, task_record_id, file_path DEFAULT "", file_name DEFAULT "", mime DEFAULT "", uploaded_by DEFAULT "", created_at, expires_at',
            'schedule_tasks' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, record_date, staff_id, staff_name DEFAULT "", department DEFAULT "", name DEFAULT "", start_time DEFAULT "", end_time DEFAULT "", due_date DEFAULT "", memo, progress DEFAULT 0, created_at, updated_at',
            'activity_log' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, occurred_at, actor_name DEFAULT "", action DEFAULT "", target_label DEFAULT "", detail DEFAULT ""',
            'time_cards' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, record_date, staff_id, staff_name DEFAULT "", department DEFAULT "", clock_in DEFAULT "", clock_out DEFAULT "", clock_in_lat, clock_in_lng, clock_in_accuracy, clock_out_lat, clock_out_lng, clock_out_accuracy, created_at, updated_at, UNIQUE (tenant_id, record_date, staff_id)',
            'attendance_days' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, staff_id, record_date, day_type DEFAULT "通常", clock_in DEFAULT "", clock_out DEFAULT "", early_minutes, overtime_minutes, created_at, updated_at, UNIQUE (tenant_id, staff_id, record_date)',
            'leave_grants' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, staff_id, grant_date, days DEFAULT 0, note DEFAULT "", created_at',
            'pay_tables' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, kind, fiscal_year, region DEFAULT "", title DEFAULT "", file_name DEFAULT "", file_path DEFAULT "", mime DEFAULT "", data_json, uploaded_by DEFAULT "", created_at',
            'payslips' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, staff_id, staff_name DEFAULT "", department DEFAULT "", employee_number DEFAULT "", pay_year, pay_month, period_start DEFAULT "", period_end DEFAULT "", data_json, created_by DEFAULT "", created_at, updated_at, UNIQUE (tenant_id, staff_id, pay_year, pay_month)',
            'payslip_history' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, payslip_id, staff_id, staff_name DEFAULT "", pay_year, pay_month, data_json, saved_at, replaced_at, replaced_by DEFAULT "", reason DEFAULT ""',
            'year_end' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, staff_id, staff_name DEFAULT "", adjust_year, apply_month DEFAULT 12, adjustment DEFAULT 0, data_json, updated_by DEFAULT "", created_at, updated_at, UNIQUE (tenant_id, staff_id, adjust_year)',
            'year_end_decl' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, staff_id, staff_name DEFAULT "", decl_year, data_json, submitted_at, updated_at, UNIQUE (tenant_id, staff_id, decl_year)',
            'company_documents' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, kind DEFAULT "doc", title DEFAULT "", file_name DEFAULT "", file_path DEFAULT "", mime DEFAULT "", file_size DEFAULT 0, uploaded_by DEFAULT "", created_at',
            'clients' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, name, rep1 DEFAULT "", rep2 DEFAULT "", postal_code DEFAULT "", address DEFAULT "", phone DEFAULT "", projects, memo, created_at, updated_at',
            'products' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, name, cost DEFAULT 0, price DEFAULT 0, maker DEFAULT "", created_at, updated_at',
            'quotes' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, quote_no, client_id, client_name DEFAULT "", project_name DEFAULT "", staff_id, staff_name DEFAULT "", worker1_id, worker1_name DEFAULT "", worker2_id, worker2_name DEFAULT "", issue_date DEFAULT "", memo, created_at, updated_at, valid_months DEFAULT 1, cost_no DEFAULT "", items_json, total_amount DEFAULT 0, UNIQUE (tenant_id, quote_no)',
            'invoices' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, invoice_no, quote_no DEFAULT "", client_id, client_name DEFAULT "", project_name DEFAULT "", staff_id, staff_name DEFAULT "", worker1_id, worker1_name DEFAULT "", worker2_id, worker2_name DEFAULT "", issue_date DEFAULT "", memo, created_at, updated_at, items_json, total_amount DEFAULT 0, UNIQUE (tenant_id, invoice_no)',
            'costs' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, report_no, quote_no DEFAULT "", invoice_no DEFAULT "", client_id, client_name DEFAULT "", project_name DEFAULT "", staff_id, staff_name DEFAULT "", worker1_id, worker1_name DEFAULT "", worker2_id, worker2_name DEFAULT "", issue_date DEFAULT "", memo, created_at, updated_at, revenue_amount DEFAULT 0, items_json, cost_total DEFAULT 0, UNIQUE (tenant_id, report_no)',
        );
        foreach (array('entries', 'schedules', 'request_submissions', 'requests', 'request_periods', 'rules', 'patterns', 'tokens') as $n) { $t[$n] = 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id'; }
        foreach ($t as $name => $cols) { $this->pdo->exec("CREATE TABLE wp_shift_{$name} ({$cols})"); }
    }
    function prepare($sql, $args = null) { $args = is_array($args) ? $args : array_slice(func_get_args(), 1); $i = 0; return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) { $v = $args[$i++]; return $m[0] === '%d' ? (string) (int) $v : $this->pdo->quote((string) $v); }, $sql); }
    function get_row($sql, $o = null) { $r = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC); return $r ? $r : null; }
    function get_results($sql, $o = null) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    function get_var($sql) { $r = $this->pdo->query($sql)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
    function get_col($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN); }
    function query($sql) { $GLOBALS['sql_log'][] = $sql; return $this->pdo->exec($sql); }
    private function lit($v) { return $v === null ? 'NULL' : (is_int($v) ? (string) $v : $this->pdo->quote((string) $v)); }
    private function cond($w) { $p = array(); foreach ($w as $k => $v) { $p[] = "{$k} = " . $this->lit($v); } return implode(' AND ', $p); }
    function insert($t, $data) { try { $this->pdo->exec("INSERT INTO {$t} (" . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_map(array($this, 'lit'), array_values($data))) . ')'); $this->insert_id = (int) $this->pdo->lastInsertId(); return 1; } catch (PDOException $e) { return false; } }
    function update($t, $data, $where) { $set = array(); foreach ($data as $k => $v) { $set[] = "{$k} = " . $this->lit($v); } return $this->pdo->exec("UPDATE {$t} SET " . implode(', ', $set) . ' WHERE ' . $this->cond($where)); }
    function delete($t, $where) { return $this->pdo->exec("DELETE FROM {$t} WHERE " . $this->cond($where)); }
}
$wpdb = new FakeWpdb();
foreach (array('system', 'context', 'repo', 'tenants', 'rest', 'files', 'rest-office', 'rest-kintai', 'rest-keiri', 'rest-kyuyo', 'auth', 'view', 'router') as $f) { require __DIR__ . "/../includes/class-ss-{$f}.php"; }
SS_Files::$allow_local_files = true;

$failed = 0;
function check($n, $c) { global $failed; echo ($c ? 'PASS ' : 'FAIL ') . $n . "\n"; if (!$c) { $failed++; } }
function as_user($wp_id) { $GLOBALS['uid'] = $wp_id; SS_Context::reset(); }
function req($json = array(), $query = array(), $files = array()) { return new WP_REST_Request($json, $query, $files); }
function st($r) { return $r instanceof WP_REST_Response ? $r->status : (is_wp_error($r) ? $r->code : 200); }
function body($r) { return $r instanceof WP_REST_Response ? $r->data : $r; }
function count_rows($table, $tenant) { global $wpdb; return (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_shift_{$table} WHERE tenant_id = " . (int) $tenant); }

/* ---- 準備：A社（管理者wp1・スタッフwp2）、B社（管理者wp3） ---- */
$now = gmdate('Y-m-d H:i:s');
foreach (array(array('aaaaaaaaaaaa', 'A社'), array('bbbbbbbbbbbb', 'B社')) as $t) {
    $wpdb->insert('wp_shift_tenants', array('public_id' => $t[0], 'name' => $t[1], 'industry' => 'other', 'status' => 'trial', 'trial_end' => gmdate('Y-m-d H:i:s', time() + 86400 * 20), 'created_at' => $now));
}
$A = 1; $B = 2;
$wpdb->insert('wp_shift_staff', array('tenant_id' => $A, 'name' => '山田', 'employee_number' => '101', 'department' => '営業', 'created_at' => $now));
$wpdb->insert('wp_shift_staff', array('tenant_id' => $A, 'name' => '佐藤', 'employee_number' => '102', 'department' => '製作', 'created_at' => $now));
$wpdb->insert('wp_shift_staff', array('tenant_id' => $B, 'name' => '鈴木', 'employee_number' => '101', 'department' => '介護', 'created_at' => $now));
$a1 = 1; $a2 = 2; $b1 = 3;
foreach (array(array($A, 1, 'owner', null), array($A, 2, 'staff', $a2), array($B, 3, 'owner', $b1)) as $u) {
    $wpdb->insert('wp_shift_users', array('tenant_id' => $u[0], 'wp_user_id' => $u[1], 'email' => "u{$u[1]}@example.test", 'role' => $u[2], 'staff_id' => $u[3], 'status' => 'active', 'created_at' => $now));
    $GLOBALS['wp_roles'][$u[1]] = $u[2];
}

