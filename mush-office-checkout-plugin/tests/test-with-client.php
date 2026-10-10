<?php
/**
 * 結合テスト：マッシュオフィスシステム（①）の「実際のコード」を取り出して、新しい②の窓口に向けて動かす。
 * ①のライセンス確認・更新確認・ロック判定の関数を、ZIPからそのまま読み込む（書き写しではない）。
 * 実行：php tests/test-with-client.php [①のZIPのパス]
 */
$zip_path = isset($argv[1]) ? $argv[1] : (getenv('MOS_ZIP') ?: '');
if ($zip_path === '' || !is_readable($zip_path)) {
    fwrite(STDERR, "①のZIPのパスを指定してください：php tests/test-with-client.php /path/to/mush-office-system-1.122.0.zip\n");
    exit(2);
}
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MCO_REST_NS', 'mush-checkout/v1');

/* ---- ②側の、模擬のWordPress（test-checkout.php と同じ） ---- */
class WP_Error { public $code; public $msg; public $data; function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->msg = $m; $this->data = $d; } function get_error_code() { return $this->code; } function get_error_message() { return $this->msg; } function get_error_data() { return $this->data; } }
class WP_REST_Request { private $p; function __construct($p = array()) { $this->p = $p; } function get_param($k) { return isset($this->p[$k]) ? $this->p[$k] : null; } }
function is_wp_error($x) { return $x instanceof WP_Error; }
function rest_ensure_response($x) { return $x; }
function add_action() {}
$GLOBALS['opts'] = array(); $GLOBALS['transients'] = array(); $GLOBALS['filters'] = array();
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['opts'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['opts'][$k]); return true; }
function get_transient($k) { return isset($GLOBALS['transients'][$k]) ? $GLOBALS['transients'][$k] : false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
function wp_salt($s = '') { return 'test-salt'; }
function rest_url($p = '') { return 'https://mush-office.com/wp-json/' . $p; }
$tmp = sys_get_temp_dir() . '/mco-client-' . getmypid(); @mkdir($tmp, 0777, true);
function wp_upload_dir() { global $tmp; return array('basedir' => $tmp . '/uploads'); }
function wp_mkdir_p($d) { return @mkdir($d, 0777, true); }
class FakeWpdb {
    public $prefix = 'wp_'; public $pdo; public $insert_id = 0;
    function __construct() {
        $this->pdo = new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE wp_mco_licenses (id INTEGER PRIMARY KEY AUTOINCREMENT, license_key UNIQUE, customer_name, email, plan_label, status, expires_at, payjp_customer_id DEFAULT "", payjp_subscription_id DEFAULT "", note, created_at, last_checked_at, last_check_agent DEFAULT "", check_count DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE wp_mco_products (id INTEGER PRIMARY KEY AUTOINCREMENT, version, file_name, size, sha256, is_latest DEFAULT 0, uploaded_at)');
    }
    function prepare($sql, $args = null) { $args = is_array($args) ? $args : array_slice(func_get_args(), 1); $i = 0; return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) { $v = $args[$i++]; return $m[0] === '%d' ? (string) (int) $v : $this->pdo->quote((string) $v); }, $sql); }
    function get_row($sql, $o = null) { $r = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
    function get_results($sql, $o = null) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    function get_var($sql) { $r = $this->pdo->query($sql)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
    function query($sql) { return $this->pdo->exec($sql); }
    private function lit($v) { return $v === null ? 'NULL' : (is_int($v) ? (string) $v : $this->pdo->quote((string) $v)); }
    function insert($t, $d) { try { $this->pdo->exec("INSERT INTO {$t} (" . implode(',', array_keys($d)) . ') VALUES (' . implode(',', array_map(array($this, 'lit'), $d)) . ')'); } catch (PDOException $e) { return false; } $this->insert_id = (int) $this->pdo->lastInsertId(); return 1; }
    function update($t, $d, $w) { $s = array(); foreach ($d as $k => $v) { $s[] = "{$k} = " . $this->lit($v); } $c = array(); foreach ($w as $k => $v) { $c[] = "{$k} = " . $this->lit($v); } return $this->pdo->exec("UPDATE {$t} SET " . implode(', ', $s) . ' WHERE ' . implode(' AND ', $c)); }
}
$wpdb = new FakeWpdb();
foreach (array('keys', 'licenses', 'products', 'rest') as $f) { require __DIR__ . "/../includes/class-mco-{$f}.php"; }

/* ---- ①側の関数が使うWordPress関数：HTTP通信は、②の窓口へ直接つなぐ ---- */
$GLOBALS['net_down'] = false; $GLOBALS['downloaded'] = array();
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function esc_url_raw($u) { return (string) $u; }
function add_query_arg($k, $v = null, $url = '') { return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query(array($k => $v)); }
function add_filter($name, $cb) { $GLOBALS['filters'][$name][] = $cb; }
function plugin_basename($f) { return 'mush-office-system/mush-office-system.php'; }
function get_file_data($f, $h) { return array('Version' => $GLOBALS['installed_version']); }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_get($url, $args = array()) {
    if ($GLOBALS['net_down']) { return new WP_Error('http_request_failed', 'timeout'); }
    $u = parse_url($url);
    if (strpos($url, 'mush-office.com/wp-json/mush-checkout/v1/') === false) { return new WP_Error('unexpected_host', $url); }
    $route = substr($u['path'], strlen('/wp-json/mush-checkout/v1/'));
    parse_str(isset($u['query']) ? $u['query'] : '', $q);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7'; $_SERVER['HTTP_USER_AGENT'] = 'WordPress/6.7; https://customer.example';
    if ($route === 'license-status') { $r = MCO_Rest::license_status(new WP_REST_Request($q)); }
    elseif ($route === 'update-check') { $r = MCO_Rest::update_check(new WP_REST_Request($q)); }
    elseif ($route === 'download') { $r = MCO_Rest::download(new WP_REST_Request($q)); }
    else { return array('code' => 404, 'body' => ''); }
    if (is_wp_error($r)) { $d = $r->get_error_data(); return array('code' => isset($d['status']) ? $d['status'] : 500, 'body' => json_encode(array('code' => $r->code))); }
    return array('code' => 200, 'body' => json_encode($r));
}

/* ---- ①のZIPから、実際のコードを取り出して読み込む ---- */
$work = sys_get_temp_dir() . '/mco-client-src-' . getmypid(); @mkdir($work, 0777, true);
$z = new ZipArchive(); if ($z->open($zip_path) !== true) { fwrite(STDERR, "ZIPを開けません\n"); exit(2); } $z->extractTo($work); $z->close();
$src = file_get_contents($work . '/mush-office-system/mush-office-system.php');
$cut = function ($from, $to) use ($src) {
    $a = strpos($src, $from); $b = strpos($src, $to, $a);
    if ($a === false || $b === false) { fwrite(STDERR, "①のコードの目印が見つかりません：{$from}\n"); exit(2); }
    return substr($src, $a, $b - $a);
};
$client = $cut("define( 'MUSH_OFFICE_LICENSE_API'", "/**\n * ============================================================\n * 自動更新")
        . $cut("define( 'MUSH_OFFICE_UPDATE_API'", "// 更新が完了したら");
eval('?>' . "<?php\n" . $client);
$client_version = preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $src, $m) ? $m[1] : '?';
echo "① のバージョン: {$client_version}\n";

$failed = 0;
function check($name, $ok) { global $failed; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$ok) { $failed++; } }
function run_update_filter() { $t = (object) array('response' => array(), 'no_update' => array()); foreach ($GLOBALS['filters']['pre_set_site_transient_update_plugins'] as $cb) { $t = $cb($t); } return $t; }
function mk_zip($path, $version) { $z = new ZipArchive(); $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE); $z->addFromString("mush-office-system/mush-office-system.php", "<?php\n/**\n * Plugin Name: マッシュオフィスシステム\n * Version: {$version}\n */\n"); $z->close(); }

$GLOBALS['installed_version'] = '1.122.0';
$L = MCO_Licenses::create(array('customer_name' => 'テスト株式会社', 'plan_label' => '月額プラン'));
$key = $L['license_key'];

/* ---- ライセンス確認（①のロック判定） ---- */
update_option('mush_office_license_activated_at', time() - 30 * DAY_IN_SECONDS); // 猶予期間（7日）は過ぎている
check('① before any key: locked after the grace period', mush_office_is_licensed() === false);
update_option('mush_office_license_key', 'MOS-AAAA-BBBB-CCCC-DDDD');
mush_office_run_license_check();
check('① with an unknown key: status "unknown" and locked', get_option('mush_office_license_status') === 'unknown' && !mush_office_is_licensed());
update_option('mush_office_license_key', strtolower($key));
update_option('mush_office_license_key', strtoupper(trim(strtolower($key)))); // ①は、保存時に大文字へそろえる
mush_office_run_license_check();
check('① with a valid key: status "active" and unlocked (even after the grace period)', get_option('mush_office_license_status') === 'active' && mush_office_is_licensed());
$row = MCO_Licenses::get($L['id']);
check('② recorded the check made by ①', (int) $row['check_count'] >= 1 && strpos($row['last_check_agent'], 'customer.example') !== false);
MCO_Licenses::set_status($L['id'], 'suspended'); mush_office_run_license_check();
check('① after suspension in ②: locked on the next check', get_option('mush_office_license_status') === 'suspended' && !mush_office_is_licensed());
MCO_Licenses::set_status($L['id'], 'active'); mush_office_run_license_check();
check('① after re-activation in ②: unlocked again', mush_office_is_licensed());
MCO_Licenses::set_status($L['id'], 'canceled'); mush_office_run_license_check();
check('① after cancellation in ②: locked', !mush_office_is_licensed());
MCO_Licenses::set_status($L['id'], 'active'); mush_office_run_license_check();
$GLOBALS['net_down'] = true; MCO_Licenses::set_status($L['id'], 'suspended');
mush_office_run_license_check();
check('① when ② is unreachable: keeps the previous state (fail-open)', get_option('mush_office_license_status') === 'active' && mush_office_is_licensed());
$GLOBALS['net_down'] = false; mush_office_run_license_check();
check('① recovers the real state once ② is reachable again', !mush_office_is_licensed());
MCO_Licenses::set_status($L['id'], 'active'); mush_office_run_license_check();

/* ---- 更新の確認と配布 ---- */
$zA = "$tmp/a.zip"; mk_zip($zA, '1.122.0'); MCO_Products::add_from_path($zA, 'a.zip');
$zB = "$tmp/b.zip"; mk_zip($zB, '1.123.0'); MCO_Products::add_from_path($zB, 'b.zip');
$sent = array(); MCO_Products::$sender = function ($row, $path) use (&$sent) { $sent[] = $row['version']; return array('sent' => true); };
delete_transient('mush_office_update_info'); $GLOBALS['installed_version'] = '1.122.0';
$info = mush_office_get_update_info(true);
check('① reads version and package from ②', $info['version'] === '1.123.0' && strpos($info['package'], 'download') !== false);
$t = run_update_filter();
$basename = 'mush-office-system/mush-office-system.php';
check('① shows "update available" when ② has a newer version', isset($t->response[$basename]) && $t->response[$basename]->new_version === '1.123.0' && $t->response[$basename]->package !== '');
$pkg = $t->response[$basename]->package;
$res = wp_remote_get($pkg);
check('the package URL ① received actually downloads (WordPress would fetch it)', $res['code'] === 200 && $sent === array('1.123.0'));
$GLOBALS['installed_version'] = '1.123.0';
$t = run_update_filter();
check('① shows no update when already on the latest', !isset($t->response[$basename]));
MCO_Licenses::set_status($L['id'], 'suspended'); delete_transient('mush_office_update_info'); $GLOBALS['installed_version'] = '1.122.0'; $sent = array();
$t = run_update_filter();
check('① gets no downloadable package when the license is suspended', !isset($t->response[$basename]));
update_option('mush_office_license_key', 'MOS-AAAA-BBBB-CCCC-DDDD'); delete_transient('mush_office_update_info');
$t = run_update_filter();
check('① gets no downloadable package with an unknown key', !isset($t->response[$basename]) && !$sent);

echo $failed ? "\n{$failed} FAILED\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
