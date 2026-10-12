<?php
// テスト用：QRコードから開く打刻ページ（templates/punch-page.php）を、WordPressなしでHTMLにする
define('ABSPATH', __DIR__ . '/');
define('SS_DIR', dirname(__DIR__, 2) . '/');
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
require SS_DIR . 'includes/class-ss-view.php';
$title = 'タイムカード'; $tenant_name = 'テスト株式会社';
$office_html = SS_View::office_html('timecard.html');
$punch = array('api' => '/wp-json/mo/v1', 't' => 'abcdefghjkmn', 's' => '2', 'k' => str_repeat('c', 32));
include SS_DIR . 'templates/punch-page.php';
