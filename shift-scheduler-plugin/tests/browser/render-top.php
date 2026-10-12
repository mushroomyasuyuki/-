<?php
// テスト用：お客様のトップページ（templates/app-top.php）を、WordPressなしでHTMLにする。使い方：php render-top.php <管理者なら1>
define('ABSPATH', __DIR__ . '/');
define('SS_PRODUCT_NAME', 'マッシュオフィス 経理・管理・勤怠');
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
$admin = !empty($argv[1]);
$b = '/s/abcdefghjkmn/';
$tenant = array('name' => 'テスト株式会社', 'public_id' => 'abcdefghjkmn');
$me_name = '佐藤'; $logout = '/logout';
$staff_cards = array(
    array('color' => 'nippo', 'icon' => '📝', 'label' => '日報', 'desc' => '本日の予定・実績を入力します', 'url' => $b . 'nippo/'),
    array('color' => 'chorei', 'icon' => '☀️', 'label' => '朝礼', 'desc' => '本日の全体予定', 'url' => $b . 'chorei/'),
    array('color' => 'keiri', 'icon' => '🧾', 'label' => '経理', 'desc' => '見積もり', 'url' => $b . 'keiri/'),
    array('color' => 'timecard', 'icon' => '⏱️', 'label' => 'タイムカード', 'desc' => '打刻', 'url' => $b . 'timecard/'),
    array('color' => 'myleave', 'icon' => '🌴', 'label' => '給与照会', 'desc' => '給与明細', 'url' => $b . 'myleave/'),
    array('color' => 'shift', 'icon' => '🗓️', 'label' => 'シフト', 'desc' => 'シフトの確認', 'url' => $b . 'me/requests/'),
);
$admin_cards = $admin ? array(
    array('color' => 'touroku', 'icon' => '👤', 'label' => '従業員登録', 'desc' => '登録', 'url' => $b . 'staff/'),
    array('color' => 'shukei', 'icon' => '📈', 'label' => '管理者集計', 'desc' => '集計', 'url' => $b . 'shukei/'),
    array('color' => 'shift', 'icon' => '🗓️', 'label' => 'シフト作成', 'desc' => '自動作成', 'url' => $b . 'schedules/'),
    array('color' => 'kyuyo', 'icon' => '💰', 'label' => '給与計算', 'desc' => '給与', 'url' => '', 'badge' => '契約者の許可が必要'),
    array('color' => 'qr', 'icon' => '🔳', 'label' => 'QRコード生成', 'desc' => 'QR', 'url' => $b . 'qr/'),
    array('color' => 'jisha', 'icon' => '🏢', 'label' => '自社情報', 'desc' => '会社', 'url' => $b . 'jisha/'),
) : array();
$notices = $admin ? array(array('error' => false, 'text' => '無料期間中です（あと20日）。', 'url' => $b . 'billing/', 'link' => 'ご契約・お支払いへ')) : array();
include __DIR__ . '/../../templates/app-top.php';
