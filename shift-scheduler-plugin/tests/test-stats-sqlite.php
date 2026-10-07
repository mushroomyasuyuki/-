<?php
/**
 * 運営者向けの統計（何社が使っているか）のテスト。SQLite上で実行。
 * 実行：php tests/test-stats-sqlite.php
 */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('DAY_IN_SECONDS', 86400);
define('SS_TRIAL_STAFF_LIMIT', 100);

class FakeWpdb {
    public $prefix = 'wp_'; public $pdo;
    function __construct() {
        $this->pdo = new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $t = array(
            'tenants' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, public_id, name, industry, status, trial_start, trial_end, plan_id, payjp_subscription_id DEFAULT "", settings, created_at, deleted_at',
            'plans' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, name, price',
            'users' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, wp_user_id, email, role, staff_id, status, last_login_at, created_at',
            'staff' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, name, active DEFAULT 1',
            'schedules' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, start_date, end_date, status, created_at',
        );
        foreach ($t as $n => $c) { $this->pdo->exec("CREATE TABLE wp_shift_{$n} ({$c})"); }
    }
    function prepare($sql, $args = null) {
        $args = is_array($args) ? $args : array_slice(func_get_args(), 1); $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) { $v = $args[$i++]; return $m[0] === '%d' ? (string) (int) $v : $this->pdo->quote((string) $v); }, $sql);
    }
    function esc_like($s) { return addcslashes($s, '_%\\'); }
    function get_results($sql, $o = null) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    function get_var($sql) { $r = $this->pdo->query($sql)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
    function get_col($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN); }
    function get_row($sql, $o = null) { $r = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
    function insert($t, $d) { $cols = implode(',', array_keys($d)); $vals = implode(',', array_map(function ($v) { return $v === null ? 'NULL' : (is_int($v) ? (string) $v : $this->pdo->quote((string) $v)); }, array_values($d))); $this->pdo->exec("INSERT INTO {$t} ({$cols}) VALUES ({$vals})"); }
}
$wpdb = new FakeWpdb();
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function selected($a, $b, $echo = true) { return $a === $b ? ' selected="selected"' : ''; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function wp_nonce_url($u, $a) { return $u . '&_wpnonce=abc'; }
function add_query_arg($k, $v = null) { return '?x'; }
function paginate_links($a) { return '<span>pages</span>'; }
function current_user_can($c) { return $GLOBALS['is_admin_user']; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function wp_unslash($s) { return $s; }
function add_action() {}
function add_menu_page() {} function add_submenu_page() {}
$GLOBALS['is_admin_user'] = true;
foreach (array('system', 'tenants', 'stats') as $f) { require __DIR__ . "/../includes/class-ss-{$f}.php"; }

$failed = 0;
function check($n, $c, $x = '') { global $failed; echo ($c ? 'PASS ' : 'FAIL ') . $n . (!$c && $x ? '  ' . $x : '') . "\n"; if (!$c) { $failed++; } }

$NOW = '2026-10-20 12:00:00';
function tenant($name, $industry, $status, $created, $trial_end = null) {
    global $wpdb;
    static $n = 0; $n++;
    $wpdb->insert('wp_shift_tenants', array('public_id' => 'pid' . $n, 'name' => $name, 'industry' => $industry, 'status' => $status, 'trial_end' => $trial_end, 'created_at' => $created));
    return $n;
}
function user($tenant, $email, $role, $last) { global $wpdb; $wpdb->insert('wp_shift_users', array('tenant_id' => $tenant, 'wp_user_id' => rand(1, 99999), 'email' => $email, 'role' => $role, 'status' => 'active', 'last_login_at' => $last, 'created_at' => '2026-01-01 00:00:00')); }

// 無料期間中（終了まで50日）、無料期間中（終了まで5日）、無料期間が終わって未更新（storedは trial のまま）、有料、支払い確認中、閲覧のみ、停止、認証待ち、削除済み
$a = tenant('飲食A', 'restaurant', 'trial', '2026-10-20 08:00:00', '2026-12-19 00:00:00');
$b = tenant('介護B', 'care', 'trial', '2026-10-16 08:00:00', '2026-10-25 00:00:00');
$c = tenant('飲食C', 'restaurant', 'trial', '2026-08-01 08:00:00', '2026-10-01 00:00:00');
$d = tenant('介護D', 'care', 'active', '2026-05-10 08:00:00', '2026-07-10 00:00:00');
$e = tenant('その他E', 'other', 'grace', '2026-09-03 08:00:00', '2026-11-03 00:00:00');
$f = tenant('飲食F', 'restaurant', 'readonly', '2026-03-03 08:00:00', '2026-05-03 00:00:00');
$g = tenant('介護G', 'care', 'suspended', '2025-12-01 08:00:00', '2026-02-01 00:00:00');
$h = tenant('未認証H', 'other', 'unverified', '2026-10-19 08:00:00');
$i = tenant('削除済みI', 'other', 'active', '2026-04-01 08:00:00');
$wpdb->pdo->exec("UPDATE wp_shift_tenants SET deleted_at = '2026-10-01 00:00:00' WHERE id = {$i}");

user($a, 'a-owner@example.test', 'owner', '2026-10-20 09:00:00');   // 今日
user($a, 'a-staff@example.test', 'staff', '2026-10-01 09:00:00');
user($b, 'b-owner@example.test', 'owner', '2026-10-15 09:00:00');   // 5日前
user($c, 'c-owner@example.test', 'owner', '2026-09-30 09:00:00');   // 20日前
user($d, 'd-owner@example.test', 'owner', '2026-08-01 09:00:00');   // 80日前
user($e, 'e-owner@example.test', 'owner', null);
foreach (array(array($a, 3), array($b, 2), array($d, 4)) as $x) { for ($k = 0; $k < $x[1]; $k++) { $wpdb->insert('wp_shift_staff', array('tenant_id' => $x[0], 'name' => 's' . $k, 'active' => 1)); } }
$wpdb->insert('wp_shift_staff', array('tenant_id' => $a, 'name' => '退職者', 'active' => 0));
$wpdb->insert('wp_shift_schedules', array('tenant_id' => $a, 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'status' => 'published', 'created_at' => $NOW));
$wpdb->insert('wp_shift_schedules', array('tenant_id' => $a, 'start_date' => '2026-12-01', 'end_date' => '2026-12-31', 'status' => 'draft', 'created_at' => $NOW));
$wpdb->insert('wp_shift_schedules', array('tenant_id' => $d, 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'status' => 'draft', 'created_at' => $NOW));

$s = SS_Stats::summary($NOW);
check('total in use excludes unverified and deleted (7 companies)', $s['total'] === 7, 'total=' . $s['total']);
check('trial counts only current trials (2)', $s['by_status']['trial'] === 2, 'trial=' . $s['by_status']['trial']);
check('expired trial counted as readonly (readonly 1 + expired 1 = 2)', $s['by_status']['readonly'] === 2);
check('paying and grace', $s['by_status']['active'] === 1 && $s['by_status']['grace'] === 1);
check('suspended and unverified', $s['by_status']['suspended'] === 1 && $s['by_status']['unverified'] === 1);
check('trial ending within 14 days (1: 介護B)', $s['trial_ending'] === 1, 'ending=' . $s['trial_ending']);
check('signups today/7d/30d', $s['signups']['today'] === 1 && $s['signups']['d7'] === 2 && $s['signups']['d30'] === 2, json_encode($s['signups']));
check('active in last 7d: A(today), B(5d ago) = 2', $s['active_7d'] === 2, 'a7=' . $s['active_7d']);
check('active in last 30d: A, B, C(20d ago) = 3', $s['active_30d'] === 3, 'a30=' . $s['active_30d']);
check('staff total counts active only (3+2+4)', $s['staff_total'] === 9);
check('schedules total and published', $s['schedules'] === 3 && $s['schedules_pub'] === 1);
check('industry (unverified/deleted excluded): restaurant 3, care 3, other 1', $s['industry'] === array('restaurant' => 3, 'care' => 3, 'other' => 1), json_encode($s['industry']));
check('12 months listed, current month = 2026-10 with 2 signups', count($s['months']) === 12 && array_keys($s['months'])[11] === '2026-10' && $s['months']['2026-10'] === 2 && $s['months']['2026-09'] === 1, json_encode($s['months']));
check('months sum = verified signups within 12 months (A,B,C,D,E,F,G = 7; unverified/deleted excluded)', array_sum($s['months']) === 7, 'sum=' . array_sum($s['months']));

// 管理画面の表示（HTML）。実際の現在時刻で集計されるため、数値の細部ではなく構造と安全性を確認する
require __DIR__ . '/../includes/class-ss-router.php';
require __DIR__ . '/../includes/class-ss-admin.php';
$_GET = array('s' => '<script>alert(1)</script>', 'status' => '');
ob_start(); SS_Admin::stats_page(); $html = ob_get_clean();
check('admin page shows headline numbers and sections', strpos($html, 'ご利用中のお客様') !== false && strpos($html, '月別の新規登録') !== false && strpos($html, '業種別') !== false && strpos($html, 'CSVでダウンロード') !== false);
check('admin page escapes the search text', strpos($html, '<script>alert(1)</script>') === false);
$_GET = array();
ob_start(); SS_Admin::stats_page(); $html = ob_get_clean();
check('admin page lists company names and owner emails', strpos($html, '飲食A') !== false && strpos($html, 'a-owner@example.test') !== false && strpos($html, '未認証H') !== false);
check('admin page does not list deleted company', strpos($html, '削除済みI') === false);
$GLOBALS['is_admin_user'] = false;
ob_start(); SS_Admin::stats_page(); $denied = ob_get_clean();
check('non-admin sees nothing', $denied === '');
$GLOBALS['is_admin_user'] = true;

// 12か月より前の登録は月別に含まれない
tenant('古い会社', 'other', 'active', '2024-01-01 00:00:00', null);
check('signups older than 12 months are not in monthly counts', array_sum(SS_Stats::summary($NOW)['months']) === 7 && SS_Stats::summary($NOW)['total'] === 8);
$wpdb->pdo->exec("UPDATE wp_shift_tenants SET deleted_at = '2026-10-01 00:00:00' WHERE name = '古い会社'");

// 削除済み・認証待ちのお客様のスタッフ・ログインは、集計に含めない
$wpdb->insert('wp_shift_staff', array('tenant_id' => $i, 'name' => '削除済みの会社のスタッフ', 'active' => 1));
user($i, 'deleted@example.test', 'owner', '2026-10-20 10:00:00');
user($h, 'unverified@example.test', 'owner', '2026-10-20 10:00:00');
$s2 = SS_Stats::summary($NOW);
check('deleted/unverified tenants do not affect staff and activity counts', $s2['staff_total'] === 9 && $s2['active_7d'] === 2 && $s2['active_30d'] === 3);

// 一覧
$l = SS_Stats::tenants(array(), $NOW);
check('list: 8 companies (deleted excluded), newest first', $l['total'] === 8 && $l['rows'][0]['name'] === '飲食A' && $l['rows'][1]['name'] === '未認証H');
$rowA = $l['rows'][0];
check('list: counts and owner email', (int) $rowA['staff_count'] === 3 && (int) $rowA['schedule_count'] === 2 && $rowA['owner_email'] === 'a-owner@example.test' && substr($rowA['last_login'], 0, 10) === '2026-10-20');
$byName = array(); foreach ($l['rows'] as $r) { $byName[$r['name']] = $r; }
check('list: expired trial shows as readonly', $byName['飲食C']['effective_status'] === 'readonly' && $byName['飲食A']['effective_status'] === 'trial');
check('filter status=trial', SS_Stats::tenants(array('status' => 'trial'), $NOW)['total'] === 2);
check('filter status=readonly includes expired trial', SS_Stats::tenants(array('status' => 'readonly'), $NOW)['total'] === 2);
check('filter status=active', SS_Stats::tenants(array('status' => 'active'), $NOW)['total'] === 1);
check('filter status=unverified', SS_Stats::tenants(array('status' => 'unverified'), $NOW)['total'] === 1);
check('search by name', SS_Stats::tenants(array('search' => '介護'), $NOW)['total'] === 3);
check('search with SQL-like chars is safe', SS_Stats::tenants(array('search' => "'; DROP TABLE wp_shift_tenants;--"), $NOW)['total'] === 0 && SS_Stats::tenants(array(), $NOW)['total'] === 8);
check('search with % matches literally (not wildcard)', SS_Stats::tenants(array('search' => '%'), $NOW)['total'] === 0);
$p1 = SS_Stats::tenants(array('per_page' => 3, 'page' => 1), $NOW); $p3 = SS_Stats::tenants(array('per_page' => 3, 'page' => 3), $NOW);
check('pagination', count($p1['rows']) === 3 && count($p3['rows']) === 2 && $p1['total'] === 8);

// CSV
$csv = SS_Stats::csv($l['rows']);
check('csv has BOM and header', strpos($csv, "\xEF\xBB\xBF") === 0 && strpos($csv, '事業者名') !== false);
check('csv row count (header + 8)', substr_count($csv, "\r\n") === 9);
check('csv neutralizes formulas', SS_Stats::csv_cell('=HYPERLINK("x")') === '"\'=HYPERLINK(""x"")"' && SS_Stats::csv_cell('+1') === '"\'+1"' && SS_Stats::csv_cell('@a') === '"\'@a"' && SS_Stats::csv_cell('普通') === '"普通"');

// 月額の見込み：契約中（active／お支払い確認中）のプランの月額の合計。無料期間中の契約・契約なしは含めない
$wpdb->insert('wp_shift_plans', array('name' => 'ライト', 'price' => 500));
$wpdb->insert('wp_shift_plans', array('name' => 'スタンダード', 'price' => 1000));
$wpdb->pdo->exec("UPDATE wp_shift_tenants SET plan_id = 1, payjp_subscription_id = 'sub_d' WHERE id = {$d}");   // 契約中・ライト
$wpdb->pdo->exec("UPDATE wp_shift_tenants SET plan_id = 2, payjp_subscription_id = 'sub_e' WHERE id = {$e}");   // お支払い確認中・スタンダード
$wpdb->pdo->exec("UPDATE wp_shift_tenants SET plan_id = 1, payjp_subscription_id = 'sub_a' WHERE id = {$a}");   // 無料期間中に契約済み（まだ請求なし）
check('monthly revenue estimate: active + grace only (500 + 1000), trial-period subscriptions excluded', SS_Stats::summary($NOW)['mrr'] === 1500, 'mrr=' . SS_Stats::summary($NOW)['mrr']);

// 無料期間が終わっても、定期課金がある（まだ状態が更新されていない）お客様は「閲覧のみ」ではなく「契約中」として数える
$before = SS_Stats::summary($NOW);
$k = tenant('契約済みK', 'restaurant', 'trial', '2026-07-01 00:00:00', '2026-09-01 00:00:00');
$wpdb->pdo->exec("UPDATE wp_shift_tenants SET plan_id = 1, payjp_subscription_id = 'sub_k' WHERE id = {$k}");
$after = SS_Stats::summary($NOW);
check('expired trial with a subscription counts as paying, not readonly', $after['by_status']['active'] === $before['by_status']['active'] + 1 && $after['by_status']['readonly'] === $before['by_status']['readonly'] && $after['by_status']['trial'] === $before['by_status']['trial'] && $after['total'] === $before['total'] + 1);
check('...and adds to monthly revenue', $after['mrr'] === 2000, 'mrr=' . $after['mrr']);
$byK = array(); foreach (SS_Stats::tenants(array(), $NOW)['rows'] as $r) { $byK[$r['name']] = $r; }
check('list shows it as active', $byK['契約済みK']['effective_status'] === 'active');
check('status filter: active includes it, readonly does not', SS_Stats::tenants(array('status' => 'active'), $NOW)['total'] === 2 && SS_Stats::tenants(array('status' => 'readonly'), $NOW)['total'] === 2);
check('status filter: trial excludes it', SS_Stats::tenants(array('status' => 'trial'), $NOW)['total'] === 2);

// 空の状態でも落ちない
$wpdb->pdo->exec('DELETE FROM wp_shift_tenants');
$e = SS_Stats::summary($NOW);
check('empty database: zeros, no errors', $e['total'] === 0 && $e['active_30d'] === 0 && array_sum($e['months']) === 0 && SS_Stats::tenants(array(), $NOW)['total'] === 0);

echo $failed ? "\n{$failed} FAILED\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
