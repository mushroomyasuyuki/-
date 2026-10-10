<?php
/**
 * ライセンス確認・更新配布のテスト（SQLite＋模擬のWordPress関数）。実行：php tests/test-checkout.php
 * ①（マッシュオフィスシステム）が実際に読む形（status / version / package）で確かめる。
 */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('HOUR_IN_SECONDS', 3600);
define('MCO_REST_NS', 'mush-checkout/v1');

class WP_Error { public $code; public $msg; public $data; function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->msg = $m; $this->data = $d; } function get_error_code() { return $this->code; } function get_error_message() { return $this->msg; } function get_error_data() { return $this->data; } }
class WP_REST_Request { private $p; function __construct($p = array()) { $this->p = $p; } function get_param($k) { return isset($this->p[$k]) ? $this->p[$k] : null; } }
function is_wp_error($x) { return $x instanceof WP_Error; }
function rest_ensure_response($x) { return $x; }
function add_action() {}
$GLOBALS['opts'] = array(); $GLOBALS['transients'] = array();
function get_option($k, $d = '') { return isset($GLOBALS['opts'][$k]) ? $GLOBALS['opts'][$k] : $d; }
function get_transient($k) { return isset($GLOBALS['transients'][$k]) ? $GLOBALS['transients'][$k] : false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function wp_salt($s = '') { return 'test-salt-' . $s; }
function rest_url($p = '') { return 'https://example.test/wp-json/' . $p; }
$tmp = sys_get_temp_dir() . '/mco-test-' . getmypid();
@mkdir($tmp, 0777, true);
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

$failed = 0;
function check($name, $ok) { global $failed; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$ok) { $failed++; } }
function code($r) { return is_wp_error($r) ? $r->code : ''; }
function make_zip($path, $version, $slug = 'mush-office-system', $header = true) {
    $z = new ZipArchive(); $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString($slug . '/' . $slug . '.php', $header ? "<?php\n/**\n * Plugin Name: マッシュオフィスシステム\n * Version: {$version}\n */\n" : "<?php\necho 1;\n");
    $z->addFromString($slug . '/readme.txt', 'x'); $z->close();
}
function reset_limits() { $GLOBALS['transients'] = array(); }

/* ---- キー ---- */
$k = MCO_Keys::generate();
check('key format MOS-XXXX-XXXX-XXXX-XXXX, no confusing chars', MCO_Keys::looks_valid($k) && !preg_match('/[01OI]/', substr($k, 4)));
check('keys are unique', count(array_unique(array_map(function () { return MCO_Keys::generate(); }, range(1, 200)))) === 200);
check('normalize: trims, uppercases, drops spaces', MCO_Keys::normalize("  " . strtolower($k) . " \n") === $k);

/* ---- ライセンス ---- */
check('create requires a customer name', code(MCO_Licenses::create(array('customer_name' => ' '))) === 'invalid');
check('create rejects a malformed expiry', code(MCO_Licenses::create(array('customer_name' => 'A社', 'expires_at' => 'next year'))) === 'invalid');
$L = MCO_Licenses::create(array('customer_name' => 'A社', 'email' => 'a@example.test', 'plan_label' => '月額プラン'));
check('create returns an active license with a valid key', !is_wp_error($L) && $L['status'] === 'active' && MCO_Keys::looks_valid($L['license_key']));
check('find_by_key is case/space tolerant; garbage returns null', MCO_Licenses::find_by_key(' ' . strtolower($L['license_key'])) ['id'] === $L['id'] && MCO_Licenses::find_by_key("MOS-' OR 1=1 --") === null);

/* ---- license-status（①が読む形） ---- */
$_SERVER['REMOTE_ADDR'] = '203.0.113.5'; $_SERVER['HTTP_USER_AGENT'] = 'WordPress/6.7; https://customer.example';
$r = MCO_Rest::license_status(new WP_REST_Request(array('key' => $L['license_key'])));
check('license-status: active license => status active', $r['status'] === 'active' && $r['plan'] === '月額プラン');
$row = MCO_Licenses::get($L['id']);
check('license-status: check is recorded (time, count, calling site)', (int) $row['check_count'] === 1 && $row['last_checked_at'] !== null && strpos($row['last_check_agent'], 'customer.example') !== false);
MCO_Licenses::set_status($L['id'], 'suspended');
check('license-status: suspended => not active', MCO_Rest::license_status(new WP_REST_Request(array('key' => $L['license_key'])))['status'] === 'suspended');
MCO_Licenses::set_status($L['id'], 'canceled');
check('license-status: canceled => not active', MCO_Rest::license_status(new WP_REST_Request(array('key' => $L['license_key'])))['status'] === 'canceled');
MCO_Licenses::set_status($L['id'], 'active');
$GLOBALS['wpdb']->update('wp_mco_licenses', array('expires_at' => gmdate('Y-m-d H:i:s', time() - 60)), array('id' => $L['id']));
check('license-status: expired => "expired" (①は active 以外を停止扱い)', MCO_Rest::license_status(new WP_REST_Request(array('key' => $L['license_key'])))['status'] === 'expired');
$GLOBALS['wpdb']->update('wp_mco_licenses', array('expires_at' => null), array('id' => $L['id']));
check('license-status: unknown key => "unknown", nothing recorded elsewhere', MCO_Rest::license_status(new WP_REST_Request(array('key' => 'MOS-AAAA-BBBB-CCCC-DDDD')))['status'] === 'unknown');
check('set_status refuses an unknown value', MCO_Licenses::set_status($L['id'], 'hacked') === false);

/* ---- 回数制限 ---- */
reset_limits();
$blocked = false;
for ($i = 0; $i < 25; $i++) { $x = MCO_Rest::license_status(new WP_REST_Request(array('key' => 'MOS-ZZZZ-ZZZZ-ZZZZ-ZZZ' . ($i % 9 + 2)))); if (is_wp_error($x) && $x->code === 'rate_limited') { $blocked = true; } }
check('rate limit: repeated unknown-key guesses are blocked (429)', $blocked);
$ok = MCO_Rest::license_status(new WP_REST_Request(array('key' => $L['license_key'])));
check('rate limit: a valid key still works for the same address', !is_wp_error($ok) && $ok['status'] === 'active');
reset_limits();

/* ---- 製品ファイル ---- */
$zip1 = "$tmp/in1.zip"; make_zip($zip1, '1.121.0');
$P1 = MCO_Products::add_from_path($zip1, 'x.zip');
check('add zip: version read from the plugin header, stored privately', !is_wp_error($P1) && $P1['version'] === '1.121.0' && is_file(MCO_Products::path_of($P1)) && is_file(MCO_Products::dir() . '.htaccess'));
check('add zip: same version twice is refused', code(MCO_Products::add_from_path($zip1, 'x.zip')) === 'dup');
$bad = "$tmp/bad.zip"; make_zip($bad, '9.9.9', 'other', false);
check('add zip: a zip that is not a plugin is refused', code(MCO_Products::add_from_path($bad, 'bad.zip')) === 'not_plugin');
file_put_contents("$tmp/notzip.zip", 'hello');
check('add zip: a non-zip file is refused', code(MCO_Products::add_from_path("$tmp/notzip.zip", 'n.zip')) === 'bad_zip');
$zip2 = "$tmp/in2.zip"; make_zip($zip2, '1.122.0'); $P2 = MCO_Products::add_from_path($zip2, 'y.zip');
$zip3 = "$tmp/in3.zip"; make_zip($zip3, '1.9.0'); $P3 = MCO_Products::add_from_path($zip3, 'z.zip');
check('latest = highest version (1.122.0 beats 1.9.0, compared as versions)', MCO_Products::latest()['id'] === $P2['id']);
check('admin can pick which version is distributed', MCO_Products::set_latest($P1['id']) && MCO_Products::latest()['id'] === $P1['id']);
MCO_Products::recalc_latest();

/* ---- update-check ---- */
$uc = MCO_Rest::update_check(new WP_REST_Request(array('key' => $L['license_key'])));
check('update-check: active license => version + signed package URL', $uc['version'] === '1.122.0' && strpos($uc['package'], 'download') !== false && strpos($uc['package'], 'sig=') !== false);
$un = MCO_Rest::update_check(new WP_REST_Request(array('key' => 'MOS-AAAA-BBBB-CCCC-DDDD')));
check('update-check: unknown key => version but NO package', $un['version'] === '1.122.0' && $un['package'] === '');
MCO_Licenses::set_status($L['id'], 'suspended');
$us = MCO_Rest::update_check(new WP_REST_Request(array('key' => $L['license_key'])));
check('update-check: suspended license => version but NO package', $us['package'] === '');
MCO_Licenses::set_status($L['id'], 'active');

/* ---- download ---- */
parse_str(parse_url($uc['package'], PHP_URL_QUERY), $q);
$sent = array(); MCO_Products::$sender = function ($row, $path) use (&$sent) { $sent[] = $row['version']; return array('sent' => true); };
$d = MCO_Rest::download(new WP_REST_Request($q));
check('download: valid signed URL delivers the file', is_array($d) && $sent === array('1.122.0'));
$sent = array();
$bad_sig = $q; $bad_sig['sig'] = str_repeat('a', 64);
check('download: tampered signature is refused', code(MCO_Rest::download(new WP_REST_Request($bad_sig))) === 'forbidden' && !$sent);
$other_product = $q; $other_product['p'] = $P1['id'];
check('download: signature is bound to the product', code(MCO_Rest::download(new WP_REST_Request($other_product))) === 'forbidden');
$expired = $q; $expired['exp'] = time() - 5; $expired['sig'] = MCO_Products::sign($q['p'], $L['id'], $expired['exp']);
check('download: expired URL is refused', code(MCO_Rest::download(new WP_REST_Request($expired))) === 'forbidden');
$L2 = MCO_Licenses::create(array('customer_name' => 'B社'));
$other_license = $q; $other_license['key'] = $L2['license_key'];
check('download: URL cannot be used with another license key', code(MCO_Rest::download(new WP_REST_Request($other_license))) === 'forbidden');
MCO_Licenses::set_status($L['id'], 'canceled');
check('download: a license canceled after the URL was issued is refused', code(MCO_Rest::download(new WP_REST_Request($q))) === 'forbidden' && !$sent);
check('download: the stored zip is not reachable by name (private dir has deny rules)', strpos(file_get_contents(MCO_Products::dir() . '.htaccess'), 'Deny') !== false);

echo $failed ? "\n{$failed} FAILED\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
