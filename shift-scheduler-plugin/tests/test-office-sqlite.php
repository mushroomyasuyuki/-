<?php
/**
 * 日報・予定進捗・操作ログ（マッシュオフィスシステムから移したAPI）と、名簿のCSV、非公開ファイルのテスト。
 * お客様A・Bの2社で、別のお客様のデータを「読めない・書けない・消せない」ことを中心に確かめる。
 * 実行：php tests/test-office-sqlite.php
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
    private $p; private $q; private $f;
    function __construct($json = array(), $query = array(), $files = array()) { $this->p = $json; $this->q = $query; $this->f = $files; }
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
function current_time($t) { return $t === 'timestamp' ? time() : gmdate('Y-m-d H:i:s'); }
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
    'owner' => array('shift_manage_staff', 'shift_manage_schedule', 'shift_view'),
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
            'staff' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, name, kana DEFAULT "", employee_number DEFAULT "", department DEFAULT "", employment DEFAULT "part", roles, qualifications, available_weekdays DEFAULT "0,1,2,3,4,5,6", max_hours_week, max_hours_month, max_days_row, night_ok DEFAULT 1, active DEFAULT 1, sort_order DEFAULT 0, created_at',
            'plans' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, name, max_staff, price, payjp_plan_id DEFAULT "", payjp_amount DEFAULT 0, active DEFAULT 1, sort_order DEFAULT 0',
            'task_records' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, record_date, staff_id, staff_name DEFAULT "", department DEFAULT "", category DEFAULT "", client, project, start_time DEFAULT "", end_time DEFAULT "", status DEFAULT "予定", is_outside DEFAULT 0, area DEFAULT "", prefecture DEFAULT "", travel_type DEFAULT "", note, bulk_mode DEFAULT 0, bulk_count DEFAULT "", progress DEFAULT 0, current_day DEFAULT 0, total_days DEFAULT 0, due_date DEFAULT "", holiday_work DEFAULT 0, early_work DEFAULT 0, night_work DEFAULT 0, images_cleared DEFAULT 0, created_at, updated_at',
            'task_images' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, task_record_id, file_path DEFAULT "", file_name DEFAULT "", mime DEFAULT "", uploaded_by DEFAULT "", created_at, expires_at',
            'schedule_tasks' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, record_date, staff_id, staff_name DEFAULT "", department DEFAULT "", name DEFAULT "", start_time DEFAULT "", end_time DEFAULT "", due_date DEFAULT "", memo, progress DEFAULT 0, created_at, updated_at',
            'activity_log' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, occurred_at, actor_name DEFAULT "", action DEFAULT "", target_label DEFAULT "", detail DEFAULT ""',
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
    function insert($t, $data) { $this->pdo->exec("INSERT INTO {$t} (" . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_map(array($this, 'lit'), array_values($data))) . ')'); $this->insert_id = (int) $this->pdo->lastInsertId(); return 1; }
    function update($t, $data, $where) { $set = array(); foreach ($data as $k => $v) { $set[] = "{$k} = " . $this->lit($v); } return $this->pdo->exec("UPDATE {$t} SET " . implode(', ', $set) . ' WHERE ' . $this->cond($where)); }
    function delete($t, $where) { return $this->pdo->exec("DELETE FROM {$t} WHERE " . $this->cond($where)); }
}
$wpdb = new FakeWpdb();
foreach (array('system', 'context', 'repo', 'tenants', 'rest', 'files', 'rest-office') as $f) { require __DIR__ . "/../includes/class-ss-{$f}.php"; }
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

/* ---- 権限 ---- */
as_user(0);
check('not logged in: no access', !SS_Rest_Office::can_use() && !SS_Rest_Office::can_write());
as_user(2);
check('staff: can use and write, but not admin-only actions', SS_Rest_Office::can_use() && SS_Rest_Office::can_write() && !SS_Rest_Office::can_admin() && !SS_Rest_Office::can_admin_write());
as_user(1);
check('owner: admin actions allowed', SS_Rest_Office::can_admin_write());
$wpdb->update('wp_shift_tenants', array('status' => 'readonly'), array('id' => $A));
SS_Context::reset();
check('read-only company: reading allowed, writing blocked', SS_Rest_Office::can_use() && !SS_Rest_Office::can_write() && !SS_Rest_Office::can_admin_write());
$wpdb->update('wp_shift_tenants', array('status' => 'trial'), array('id' => $A));
SS_Context::reset();

/* ---- ログイン情報・名簿・部署 ---- */
as_user(2);
$r = body(SS_Rest_Office::auth_login(req()));
check('auth/login returns the logged-in staff (not someone chosen by the screen)', $r['staffId'] === (string) $a2 && $r['staffName'] === '佐藤' && $r['isAdmin'] === false);
as_user(1);
check('auth/login: owner without a staff link gets a clear message', st(SS_Rest_Office::auth_login(req(array('staffId' => $a1)))) === 403);
$emp = body(SS_Rest_Office::employees(req()));
check('employees: only A\'s staff, sorted by number, admin sees account info', count($emp) === 2 && $emp[0]['name'] === '山田' && $emp[1]['name'] === '佐藤' && isset($emp[0]['has_login_password']));
as_user(2);
$emp = body(SS_Rest_Office::employees(req()));
check('employees: staff see only the basic fields', !isset($emp[0]['has_login_password']) && !isset($emp[0]['kana']));
$dep = body(SS_Rest_Office::departments(req()));
check('departments: built from A\'s roster only', count($dep) === 2 && $dep[0]['name'] === '営業' && $dep[1]['name'] === '製作' && $dep[0]['employeeCount'] === 1);
check('company-name: A\'s name', body(SS_Rest_Office::company_name(req()))['name'] === 'A社');

/* ---- 日報：保存・更新（重複防止）・代理登録 ---- */
as_user(2);
$base = array('date' => '2026-10-20', 'staffId' => $a2, 'category' => '製作', 'client' => '顧客X', 'project' => '案件1', 'start' => '09:00', 'end' => '12:00', 'status' => '予定');
$r = SS_Rest_Office::upsert_task_record(req($base + array('staffName' => '偽名', 'dept' => '偽部署')));
$t1 = body($r);
check('upsert: saved; name/department come from the roster (not from the screen)', st($r) === 200 && $t1['staffName'] === '佐藤' && $t1['dept'] === '製作');
$r = body(SS_Rest_Office::upsert_task_record(req(array('status' => '進行中', 'progress' => 50) + $base)));
check('upsert: same day/staff/client/project updates the same record', $r['id'] === $t1['id'] && $r['status'] === '進行中' && count_rows('task_records', $A) === 1);
$r = SS_Rest_Office::upsert_task_record(req(array('staffId' => $a1, 'client' => '顧客Y') + $base));
check('upsert: proxy entry for a colleague in the same company is allowed (as before)', st($r) === 200 && body($r)['staffId'] === (string) $a1);
check('upsert: a staff id from ANOTHER company is refused', st(SS_Rest_Office::upsert_task_record(req(array('staffId' => $b1) + $base))) === 400);
check('upsert: bad date refused', st(SS_Rest_Office::upsert_task_record(req(array('date' => '20261020') + $base))) === 400);
$bulk = body(SS_Rest_Office::upsert_task_record(req(array('bulkMode' => true, 'bulkCount' => '5', 'category' => '事務') + $base)));
$bulk2 = body(SS_Rest_Office::upsert_task_record(req(array('bulkMode' => true, 'bulkCount' => '3', 'category' => '事務') + $base)));
check('upsert: bulk entries are always added (client/project empty)', $bulk['id'] !== $bulk2['id'] && $bulk['client'] === null);
check('log: actions are recorded, proxy entries marked', count_rows('activity_log', $A) >= 3 && (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_shift_activity_log WHERE tenant_id = {$A} AND detail LIKE '代理操作%'") >= 1);

/* ---- 日報：B社からは見えない・変えられない ---- */
as_user(3);
$list = body(SS_Rest_Office::get_task_records(req(array(), array('dateFrom' => '2026-01-01'))));
check('B cannot see A\'s records', $list === array());
check('B cannot update A\'s record', st(SS_Rest_Office::admin_update_task_record(req(array('status' => '完了'), array('id' => $t1['id'])))) === 404);
check('B cannot delete A\'s record', st(SS_Rest_Office::delete_task_record(req(array(), array('id' => $t1['id'])))) === 404 && count_rows('task_records', $A) === 4);
check('B cannot duplicate A\'s record', st(SS_Rest_Office::duplicate_task_record(req(array(), array('id' => $t1['id'])))) === 404);
check('B cannot add an image to A\'s record', st(SS_Rest_Office::upload_task_image(req(array(), array('id' => $t1['id'])))) === 404);
check('B sees no A activity log', body(SS_Rest_Office::get_activity_log(req())) === array());
check('B employees: only B\'s staff', count(body(SS_Rest_Office::employees(req()))) === 1);

/* ---- 日報：絞り込み・並び順 ---- */
as_user(1);
SS_Rest_Office::upsert_task_record(req(array('date' => '2026-10-21', 'start' => '08:00', 'client' => '顧客Z') + $base));
$l = body(SS_Rest_Office::get_task_records(req(array(), array('dateFrom' => '2026-10-21', 'dateTo' => '2026-10-31'))));
check('filter by date range', count($l) === 1 && $l[0]['date'] === '2026-10-21');
$l = body(SS_Rest_Office::get_task_records(req(array(), array('staffId' => $a1))));
check('filter by staff', count($l) === 1 && $l[0]['staffId'] === (string) $a1);
$l = body(SS_Rest_Office::get_task_records(req()));
check('order: newest date first, then start time', $l[0]['date'] === '2026-10-21' && $l[1]['date'] === '2026-10-20');

/* ---- 日報：管理者の編集・重複の確認 ---- */
as_user(2);
$r = body(SS_Rest_Office::admin_update_task_record(req(array('client' => '書き換え', 'status' => '完了'), array('id' => $t1['id']))));
check('admin-update by staff: status changes, but client (admin-only) does not', $r['status'] === '完了' && $r['client'] === '顧客X');
as_user(1);
$other = body(SS_Rest_Office::upsert_task_record(req(array('client' => '顧客W') + $base)));
check('admin-update: moving onto an existing day/client/project is refused (409)', st(SS_Rest_Office::admin_update_task_record(req(array('client' => '顧客X'), array('id' => $other['id'])))) === 409);
$dup = SS_Rest_Office::duplicate_task_record(req(array(), array('id' => $t1['id'])));
check('duplicate: renamed with (複製) to avoid a clash', st($dup) === 201 && body($dup)['project'] === '案件1(複製)');
as_user(2);
check('staff cannot delete (admin only, as before)', !SS_Rest_Office::can_admin_write());

/* ---- 写真（非公開ファイル・署名つきURL） ---- */
as_user(2);
$png = "$tmp/p.png"; file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
$r = SS_Rest_Office::upload_task_image(req(array(), array('id' => $t1['id']), array('file' => array('tmp_name' => $png, 'name' => 'photo.png', 'type' => 'image/png', 'error' => 0))));
$img = body($r)['images'][0];
check('image: stored and returned with a signed URL', st($r) === 201 && strpos($img['url'], 'ss_file=task_image') !== false && strpos($img['url'], 'sig=') !== false);
$row = $wpdb->get_row("SELECT * FROM wp_shift_task_images WHERE id = " . (int) $img['id'], ARRAY_A);
$abs = SS_Files::abs_path($A, $row['file_path']);
check('image: saved inside A\'s private folder with a random name, folder denies direct access', strpos($abs, "/mush-office-tenants/{$A}/task_images/") !== false && is_file($abs) && strpos(file_get_contents(SS_Files::base_dir() . '.htaccess'), 'Deny') !== false && strpos($row['file_path'], 'photo') === false);
check('image: completed task → photo gets a deletion date (30 days)', !empty($row['expires_at']));
parse_str(parse_url($img['url'], PHP_URL_QUERY), $q);
check('signed URL resolves to the file', SS_Files::resolve($q['ss_file'], $q['id'], $q['exp'], $q['sig'])['path'] === $abs);
check('tampered signature is refused', SS_Files::resolve($q['ss_file'], $q['id'], $q['exp'], str_repeat('0', 64)) === null);
check('signature cannot be reused for another image id', SS_Files::resolve($q['ss_file'], $q['id'] + 1, $q['exp'], $q['sig']) === null);
check('expired URL is refused', SS_Files::resolve($q['ss_file'], $q['id'], time() - 10, SS_Files::sign($A, 'task_image', $q['id'], time() - 10)) === null);
check('a signature made for another company does not open A\'s image', SS_Files::resolve($q['ss_file'], $q['id'], $q['exp'], SS_Files::sign($B, 'task_image', $q['id'], $q['exp'])) === null);
$fake = "$tmp/fake.png"; file_put_contents($fake, "<?php echo 'not an image'; ?>");
check('image: a non-image disguised as .png is refused', st(SS_Rest_Office::upload_task_image(req(array(), array('id' => $t1['id']), array('file' => array('tmp_name' => $fake, 'name' => 'x.png', 'type' => 'image/png', 'error' => 0))))) === 400);
as_user(3);
check('B cannot delete A\'s image', st(SS_Rest_Office::delete_task_image(req(array(), array('id' => $img['id'])))) === 404 && is_file($abs));
as_user(1);
SS_Rest_Office::delete_task_record(req(array(), array('id' => $t1['id'])));
check('deleting a record also deletes its photos (file and row)', !is_file($abs) && count_rows('task_images', $A) === 0);

/* ---- 予定進捗 ---- */
as_user(2);
$s = body(SS_Rest_Office::upsert_schedule_task(req(array('date' => '2026-10-20', 'staffId' => $a2, 'name' => '図面', 'progress' => 30))));
check('schedule: created with roster name', $s['staffName'] === '佐藤' && $s['progress'] === 30);
$s2 = body(SS_Rest_Office::upsert_schedule_task(req(array('id' => $s['id'], 'name' => '図面', 'progress' => 80))));
check('schedule: updated by id', $s2['id'] === $s['id'] && $s2['progress'] === 80);
as_user(3);
check('B cannot update A\'s schedule task', st(SS_Rest_Office::upsert_schedule_task(req(array('id' => $s['id'], 'name' => 'x')))) === 404);
check('B cannot delete A\'s schedule task', st(SS_Rest_Office::delete_schedule_task(req(array(), array('id' => $s['id'])))) === 404 && count_rows('schedule_tasks', $A) === 1);
check('B sees none of A\'s schedule tasks', body(SS_Rest_Office::get_schedule_tasks(req())) === array());
check('B cannot create a schedule task for A\'s staff', st(SS_Rest_Office::upsert_schedule_task(req(array('date' => '2026-10-20', 'staffId' => $a2, 'name' => 'x')))) === 400);

/* ---- 操作ログ（画面からの記録） ---- */
as_user(2);
SS_Rest_Office::post_activity_log(req(array('action' => '印刷', 'actorName' => 'なりすまし', 'targetLabel' => '日報')));
check('activity-log POST: actor is the logged-in person, not the sent name', $wpdb->get_var("SELECT actor_name FROM wp_shift_activity_log WHERE tenant_id = {$A} AND action = '印刷'") === '佐藤');

/* ---- 名簿のCSV ---- */
as_user(1);
$ex = SS_Rest::export_staff();
check('export: header and A\'s staff only', strpos($ex['csv'], '"社員番号","名前"') === 0 && strpos($ex['csv'], '山田') !== false && strpos($ex['csv'], '鈴木') === false);
SS_Rest::create_staff(req(array('name' => '=HYPERLINK("x")', 'employee_number' => '103')));
check('export: values that Excel would run as formulas are neutralised', strpos(SS_Rest::export_staff()['csv'], '"\'=HYPERLINK(""x"")"') !== false);
$head = array('社員番号', '氏名', 'フリガナ', '部署', '雇用形態', '役割', '資格');
$r = SS_Rest::import_staff(req(array('dry_run' => true, 'rows' => array($head, array('１０１', '山田太郎', 'やまだ', '営業部', '正社員', 'リーダー、営業', ''), array('', '新人', '', '製作', 'アルバイト', '', '')))));
check('import dry run: 1 update (by number, full-width ok) + 1 create; nothing applied', $r['updated'] === 1 && $r['created'] === 1 && !$r['applied'] && !$r['errors'] && $wpdb->get_var("SELECT name FROM wp_shift_staff WHERE id = {$a1}") === '山田');
$r = SS_Rest::import_staff(req(array('rows' => array($head, array('101', '山田太郎', 'やまだ', '営業部', '正社員', 'リーダー、営業', ''), array('', '新人', '', '製作', 'アルバイト', '', '')))));
$y = $wpdb->get_row("SELECT * FROM wp_shift_staff WHERE id = {$a1}", ARRAY_A);
check('import: applied; update keeps the same person (id) and sets fields', $r['applied'] && $y['name'] === '山田太郎' && $y['department'] === '営業部' && $y['employment'] === 'full' && json_decode($y['roles'], true) === array('リーダー', '営業'));
check('import: did not touch company B', $wpdb->get_var("SELECT name FROM wp_shift_staff WHERE id = {$b1}") === '鈴木' && count_rows('staff', $B) === 1);
$before = count_rows('staff', $A);
$r = SS_Rest::import_staff(req(array('rows' => array($head, array('201', 'OK', '', '', '', '', ''), array('202', '', '', '', '', '', ''), array('203', 'X', '', '', '契約社員', '', ''), array('201', 'Y', '', '', '', '', '')))));
check('import: any error → nothing changed, each error reported with its line', !$r['applied'] && count($r['errors']) === 3 && $r['errors'][0]['line'] === 3 && count_rows('staff', $A) === $before);
check('import: a header row is required', is_wp_error(SS_Rest::import_staff(req(array('rows' => array(array('101', '山田'), array('102', '佐藤')))))));
$GLOBALS['opts']['x'] = 1;
$rows = array($head); for ($i = 0; $i < 199; $i++) { $rows[] = array('9' . $i, '人' . $i, '', '', '', '', ''); }
$r = SS_Rest::import_staff(req(array('dry_run' => true, 'rows' => $rows)));
check('import: staff limit is checked before applying', !empty($r['errors']) && strpos($r['errors'][0]['message'], '上限') !== false);
as_user(2);
check('import: staff (non-manager) cannot import', !SS_Rest::can_write_staff());

/* ---- 解約後の削除で、日報なども消える ---- */
as_user(1);
SS_Rest_Office::upsert_task_record(req($base));
$t = body(SS_Rest_Office::get_task_records(req()))[0];
SS_Rest_Office::upload_task_image(req(array(), array('id' => $t['id']), array('file' => array('tmp_name' => $png, 'name' => 'a.png', 'type' => 'image/png', 'error' => 0))));
$dir = SS_Files::base_dir() . $A;
SS_System::purge_tenant($A);
check('purge: A\'s records, photos, schedule, log and files are gone; B untouched', count_rows('task_records', $A) === 0 && count_rows('task_images', $A) === 0 && count_rows('schedule_tasks', $A) === 0 && count_rows('activity_log', $A) === 0 && !is_dir($dir) && count_rows('staff', $B) === 1);

/* ---- データ窓口：条件の書き方 ---- */
$repo = SS_Repo::for_tenant($B);
$threw = false; try { $repo->all('staff', array('name; DROP TABLE x' => 1)); } catch (SS_Repo_Exception $e) { $threw = true; }
check('repo: malformed condition keys are rejected', $threw);
$threw = false; try { $repo->all('staff', array('name LIKE' => '%')); } catch (SS_Repo_Exception $e) { $threw = true; }
check('repo: unsupported operators are rejected', $threw);
check('repo: empty IN matches nothing', $repo->all('staff', array('id IN' => array())) === array());
check('repo: tenant condition cannot be overridden by the caller', count($repo->all('staff', array('tenant_id' => $A))) === 1);

echo $failed ? "\n{$failed} FAILED\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
