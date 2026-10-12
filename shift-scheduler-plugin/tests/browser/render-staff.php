<?php
// テスト用：スタッフ管理のテンプレートを、WordPressなしでHTMLにする
define('ABSPATH', __DIR__ . '/');
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
$config = array('rest' => '/api/', 'nonce' => 'n', 'writable' => true, 'limit' => 50, 'staff_login_url' => 'https://mush-office.com/s/abcdefghjkmn/login/');
include __DIR__ . '/../../templates/app-staff.php';
