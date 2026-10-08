<?php
/**
 * 税込金額の計算と、料金・無料期間・請求・支払い・支払い失敗・プラン変更・解約の説明文のテスト。
 * 実行：php tests/test-terms.php
 */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('SS_TRIAL_MONTHS', 2);
define('SS_TRIAL_STAFF_LIMIT', 100);
$GLOBALS['opts'] = array();
function get_option($k, $d = '') { return array_key_exists($k, $GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $d; }
$GLOBALS['plans'] = array(
    array('id' => 1, 'name' => 'ライト', 'max_staff' => 50, 'price' => 500, 'active' => 1, 'sort_order' => 1),
    array('id' => 2, 'name' => 'スタンダード', 'max_staff' => 100, 'price' => 1000, 'active' => 1, 'sort_order' => 2),
);
class FakeWpdb { public $prefix = 'wp_'; function get_results($sql, $o = null) { return $GLOBALS['plans']; } }
$wpdb = new FakeWpdb();
foreach (array('system', 'tenants', 'tax', 'billing', 'terms') as $f) { require __DIR__ . "/../includes/class-ss-{$f}.php"; }

$failed = 0;
function check($n, $c, $x = '') { global $failed; echo ($c ? 'PASS ' : 'FAIL ') . $n . (!$c && $x ? '  ' . $x : '') . "\n"; if (!$c) { $failed++; } }
function section($title) { foreach (SS_Terms::sections() as $s) { if ($s['title'] === $title) { return $s['body']; } } return null; }

/* ---- 税込の計算 ---- */
check('default tax rate is 10%', SS_Tax::rate() === 10);
check('500 -> 550 and 1000 -> 1100 (tax included)', SS_Tax::incl(500) === 550 && SS_Tax::incl(1000) === 1100);
check('tax amount is rounded down (999 + floor(99.9) = 1098)', SS_Tax::incl(999) === 1098);
$GLOBALS['opts']['ss_tax_rate'] = 8;
check('rate is configurable (8%: 500 -> 540)', SS_Tax::rate() === 8 && SS_Tax::incl(500) === 540);
$GLOBALS['opts']['ss_tax_rate'] = 99; check('rate is capped at 30%', SS_Tax::rate() === 30);
$GLOBALS['opts']['ss_tax_rate'] = -5; check('rate is never negative', SS_Tax::rate() === 0 && SS_Tax::incl(500) === 500);
$GLOBALS['opts']['ss_tax_rate'] = 10;
check('yen format', SS_Tax::yen(1100) === '1,100円' && SS_Tax::yen(550) === '550円');

/* ---- 説明文 ---- */
$titles = array_map(function ($s) { return $s['title']; }, SS_Terms::sections());
check('all required items are written, in order: 料金・無料期間・請求の開始・お支払い方法・お支払いに失敗したとき・プランの変更・解約', array_slice($titles, 0, 7) === array('料金', '無料期間', '請求の開始', 'お支払い方法', 'お支払いに失敗したとき', 'プランの変更', '解約'), json_encode($titles, JSON_UNESCAPED_UNICODE));
$price = section('料金');
check('料金: tax-included price first, tax-excluded in parentheses, staff limit', strpos($price, 'ライト：月額550円（税込。税抜500円）、スタッフ50名まで') !== false && strpos($price, 'スタンダード：月額1,100円（税込。税抜1,000円）、スタッフ100名まで') !== false);
check('料金: states the tax rate and that displayed amounts are tax-included', strpos($price, '消費税率は10％') !== false && strpos($price, '表示の金額は税込') !== false);
$trial = section('無料期間');
check('無料期間: 2 months, no card needed, readonly afterwards and data kept', strpos($trial, '2か月間') !== false && strpos($trial, 'カード情報の登録は不要') !== false && strpos($trial, '閲覧のみ') !== false && strpos($trial, 'データは削除されません') !== false);
$start = section('請求の開始');
check('請求の開始: during trial → from the end date, no payment before; after trial → at signup, monthly', strpos($start, '無料期間の終了日から請求が始まります') !== false && strpos($start, 'お支払いは発生しません') !== false && strpos($start, 'お申し込みの時点で最初の請求') !== false && strpos($start, '毎月同じ日') !== false);
$pay = section('お支払い方法');
check('お支払い方法: credit card via PAY.JP; card data is not stored here', strpos($pay, 'クレジットカード') !== false && strpos($pay, 'PAY.JP') !== false && strpos($pay, '保存することもありません') !== false);
$fail = section('お支払いに失敗したとき');
check('支払い失敗: mail, ' . SS_Billing::GRACE_DAYS . '-day grace, recover by changing the card, then readonly', strpos($fail, 'メールでお知らせ') !== false && strpos($fail, SS_Billing::GRACE_DAYS . '日間') !== false && strpos($fail, 'カード情報を変更') !== false && strpos($fail, '閲覧のみ') !== false);
$chg = section('プランの変更');
check('プラン変更: anytime, new price from the NEXT billing date, no charge at change, blocked when staff exceeds the limit', strpos($chg, '次回の請求日から適用') !== false && strpos($chg, '変更の時点で請求は発生しません') !== false && strpos($chg, '上限を超える場合は、変更できません') !== false);
$cancel = section('解約');
check('解約: anytime from the billing screen, billing stops, usable until the paid period ends, then readonly', strpos($cancel, 'いつでも解約') !== false && strpos($cancel, '以後の請求は停止') !== false && strpos($cancel, 'お支払い済みの期間の終了日まで') !== false && strpos($cancel, '閲覧のみ') !== false);
check('解約: default refund sentence is present', strpos($cancel, '日割りによる返金は行いません。') !== false);
$GLOBALS['opts']['ss_refund_text'] = '解約月の料金は、返金いたしません。';
check('解約: refund sentence is configurable', strpos(section('解約'), '解約月の料金は、返金いたしません。') !== false && strpos(section('解約'), '日割り') === false);
$GLOBALS['opts']['ss_refund_text'] = '   ';
check('解約: a blank refund setting falls back to the default (never leaves the policy unwritten)', strpos(section('解約'), '日割りによる返金は行いません。') !== false);
$GLOBALS['opts']['ss_refund_text'] = '';

// 設定を変えると、文面も変わる（実際の動作とずれない）
$GLOBALS['plans'][0]['price'] = 600; $GLOBALS['opts']['ss_tax_rate'] = 8;
check('the text follows the settings: price 600 at 8% shows 648円 (税抜600円)', strpos(section('料金'), 'ライト：月額648円（税込。税抜600円）') !== false && strpos(section('料金'), '消費税率は8％') !== false);
$GLOBALS['plans'][0]['price'] = 500; $GLOBALS['opts']['ss_tax_rate'] = 10;
$text = SS_Terms::plain_text();
check('plain text for copy/paste: every section as ■heading + body', substr_count($text, '■') === count(SS_Terms::sections()) && strpos($text, '■解約') !== false && strpos($text, '■料金') !== false);

/* ---- 登録ページ ---- */
$GLOBALS['plans'] = array(
    array('id' => 1, 'name' => 'ライト<script>alert(1)</script>', 'max_staff' => 50, 'price' => 500, 'active' => 1, 'sort_order' => 1),
    array('id' => 2, 'name' => 'スタンダード', 'max_staff' => 100, 'price' => 1000, 'active' => 1, 'sort_order' => 2),
);
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function wp_create_nonce($a) { return 'nonce'; }
class SS_Router { static function url($p) { return 'https://example.test/' . $p . '/'; } }
class SS_Legal { static function url($k) { return ''; } }
ob_start(); $err = ''; $msg = ''; include __DIR__ . '/../templates/register.php'; $html = ob_get_clean();
check('register page shows tax-included prices and the tax-excluded price', strpos($html, '月額550円（税込。税抜500円）') !== false && strpos($html, '月額1,100円（税込。税抜1,000円）') !== false);
check('register page: all seven items are in the details block', (function () use ($html) { foreach (array('料金', '無料期間', '請求の開始', 'お支払い方法', 'お支払いに失敗したとき', 'プランの変更', '解約') as $t) { if (strpos($html, '<h3 class="ss-terms-h">' . $t . '</h3>') === false) { return false; } } return true; })());
check('register page: plan names are escaped (no script injection)', strpos($html, '<script>alert(1)</script>') === false && strpos($html, '&lt;script&gt;') !== false);

echo $failed ? "\n{$failed} FAILED\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
