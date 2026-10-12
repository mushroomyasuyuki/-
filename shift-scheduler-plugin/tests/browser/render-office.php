<?php
// テスト用：マッシュオフィスシステムの画面ページ（templates/office-page.php）を、WordPressなしでHTMLにする
// 使い方：php render-office.php <画面のファイル名> <管理者なら1>
define('ABSPATH', __DIR__ . '/');
define('SS_DIR', dirname(__DIR__, 2) . '/');
define('SS_URL', '/');
define('SS_VERSION', 'test');
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function status_header() {} function nocache_headers() {}
require SS_DIR . 'includes/class-ss-view.php';
$file = $argv[1]; $admin = !empty($argv[2]);
$links = array('トップ' => '/s/abcdefghjkmn/', '日報' => '/s/abcdefghjkmn/nippo/', '予定進捗' => '/s/abcdefghjkmn/yotei/', '朝礼' => '/s/abcdefghjkmn/chorei/', '管理者集計' => '/s/abcdefghjkmn/shukei/');
$title = 'テスト'; $tenant_name = 'テスト株式会社'; $home_url = '/s/abcdefghjkmn/'; $logout = '/logout';
$menu = array(array('url' => '/s/abcdefghjkmn/', 'label' => 'ダッシュボード', 'current' => false));
$readonly = !empty($argv[3]);
$office_html = SS_View::office_html($file);
$mo_config = array('tenant' => 'abcdefghjkmn', 'base' => '/wp-json/mo/v1', 'nonce' => 'nonce-123', 'readonly' => $readonly, 'links' => $links,
    'auth' => array('staff' => array('staffId' => '2', 'staffName' => '佐藤', 'dept' => '製作'), 'admin' => $admin));
$scripts = array();
if (!empty($argv[4]) && $argv[4] === 'qr') { // QRコード画面：スタッフごとの打刻用URLと、QRを作る部品
    $scripts = array('/assets/qrcode.js');
    $mo_config['qr'] = array(
        array('id' => '1', 'name' => '山田', 'dept' => '営業', 'url' => 'https://example.test/s/abcdefghjkmn/punch/?s=1&k=' . str_repeat('a', 32)),
        array('id' => '2', 'name' => '<b>佐藤</b>', 'dept' => '製作', 'url' => 'https://example.test/s/abcdefghjkmn/punch/?s=2&k=' . str_repeat('b', 32)),
    );
}
include SS_DIR . 'templates/office-page.php';
