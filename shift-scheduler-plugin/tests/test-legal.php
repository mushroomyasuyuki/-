<?php
/**
 * 規約・表記ページへのリンク（自動検出・上書き）のテスト。実行：php tests/test-legal.php
 */
define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
function add_action() {}
$GLOBALS['opts'] = array(); $GLOBALS['tr'] = array(); $GLOBALS['pages'] = array(); $GLOBALS['queries'] = 0;
function get_option($k, $d = '') { return array_key_exists($k, $GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['opts'][$k] = $v; return true; }
function get_transient($k) { return array_key_exists($k, $GLOBALS['tr']) ? $GLOBALS['tr'][$k] : false; }
function set_transient($k, $v, $t) { $GLOBALS['tr'][$k] = $v; $GLOBALS['ttl'][$k] = $t; return true; }
function delete_transient($k) { unset($GLOBALS['tr'][$k]); }
function get_pages($a) { $GLOBALS['queries']++; return array_values(array_filter($GLOBALS['pages'], function ($p) { return $p->post_status === 'publish' && $p->post_type === 'page'; })); }
function get_post($id) { foreach ($GLOBALS['pages'] as $p) { if ($p->ID === $id) { return $p; } } return null; }
function get_permalink($id) { $p = get_post($id); return 'https://example.test/' . $p->post_name . '/'; }
require __DIR__ . '/../includes/class-ss-legal.php';
function pg($id, $title, $slug, $status = 'publish', $type = 'page') { $GLOBALS['pages'][] = (object) array('ID' => $id, 'post_title' => $title, 'post_name' => $slug, 'post_status' => $status, 'post_type' => $type); SS_Legal::clear_cache(); /* 本番では save_post_page で消える */ }

$failed = 0;
function check($n, $c, $x = '') { global $failed; echo ($c ? 'PASS ' : 'FAIL ') . $n . (!$c && $x ? '  ' . $x : '') . "\n"; if (!$c) { $failed++; } }

check('no pages: nothing linked, no errors', SS_Legal::links_map() === array() && SS_Legal::url('terms') === '');

pg(1, 'ホーム', 'home'); pg(2, 'お問い合わせ', 'contact');
pg(10, '特定商取引法に基づく表記', 'tokushoho'); pg(11, 'プライバシーポリシー', 'privacy-policy'); pg(12, '利用規約', 'terms');
pg(13, '下書きの利用規約', 'draft-terms', 'draft'); pg(14, '利用規約（投稿）', 'post-terms', 'publish', 'post');
$m = SS_Legal::links_map();
check('auto-detects all three pages from titles', $m['tokushoho']['url'] === 'https://example.test/tokushoho/' && $m['terms']['url'] === 'https://example.test/terms/' && $m['privacy']['url'] === 'https://example.test/privacy-policy/');
check('labels are the legal names', $m['tokushoho']['label'] === '特定商取引法に基づく表記' && $m['terms']['label'] === '利用規約' && $m['privacy']['label'] === 'プライバシーポリシー');
check('unrelated, draft and non-page content is never picked', SS_Legal::resolve('terms')['title'] === '利用規約');
check('source is reported as auto', SS_Legal::resolve('privacy')['source'] === 'auto');
$q = $GLOBALS['queries']; SS_Legal::links_map(); SS_Legal::links_map();
check('guess is cached (no repeated page queries)', $GLOBALS['queries'] === $q);

// スラッグだけで判別できる（タイトルが英語・別名）
$GLOBALS['pages'] = array(); $GLOBALS['tr'] = array();
pg(20, '法的表記', 'tokusho-law'); pg(21, 'Privacy Policy', 'privacy'); pg(22, 'Terms of Service', 'terms-of-service');
$m = SS_Legal::links_map();
check('detects by slug / English title', isset($m['tokushoho']) && isset($m['privacy']) && isset($m['terms']), json_encode(array_keys($m)));

// 取り違え防止：「利用規約・プライバシーポリシー」を1ページにまとめている場合は、両方に同じページ
$GLOBALS['pages'] = array(); $GLOBALS['tr'] = array();
pg(30, '利用規約とプライバシーポリシー', 'legal');
$m = SS_Legal::links_map();
check('a combined page can serve both (not mistaken for tokushoho)', isset($m['terms']) && isset($m['privacy']) && !isset($m['tokushoho']));

// 管理画面での指定が、自動検出より優先
$GLOBALS['pages'] = array(); $GLOBALS['tr'] = array();
pg(10, '特定商取引法に基づく表記', 'tokushoho'); pg(12, '利用規約', 'terms'); pg(40, '利用規約（新）', 'terms-new'); pg(41, '会社情報', 'company'); pg(42, '下書き', 'wip', 'draft');
$res = SS_Legal::save(array('terms' => array('page' => 40, 'url' => ''), 'tokushoho' => array('page' => 0, 'url' => 'https://other.example/legal'), 'privacy' => array('page' => 42, 'url' => 'javascript:alert(1)')));
check('save: bad URL scheme and unpublished page are rejected with messages', count($res['errors']) === 2);
check('chosen page overrides auto-detect', SS_Legal::resolve('terms')['url'] === 'https://example.test/terms-new/' && SS_Legal::resolve('terms')['source'] === 'page');
check('custom URL overrides everything', SS_Legal::resolve('tokushoho')['url'] === 'https://other.example/legal' && SS_Legal::resolve('tokushoho')['source'] === 'custom');
check('rejected values are not used (privacy: nothing to detect → no link)', SS_Legal::url('privacy') === '' && !isset(SS_Legal::links_map()['privacy']));
SS_Legal::save(array('terms' => array('page' => 41, 'url' => '')));
$GLOBALS['pages'][3]->post_status = 'draft'; // 選んだページ（ID 41）が、あとで非公開になった
SS_Legal::clear_cache();
check('a chosen page that became unpublished falls back to auto-detect', SS_Legal::resolve('terms')['source'] !== 'page');
check('legacy ss_terms_url is used only as the last resort', (function () { $GLOBALS['pages'] = array(); SS_Legal::clear_cache(); $GLOBALS['opts'] = array('ss_terms_url' => 'https://legacy.example/t'); return SS_Legal::resolve('terms')['source'] === 'legacy' && SS_Legal::url('privacy') === ''; })());
check('unknown key is safe', SS_Legal::resolve('x')['url'] === '');

// 見つからなかった結果は短く保存し、ページを追加したら（フックで）すぐ反映される
$GLOBALS['pages'] = array(); $GLOBALS['tr'] = array();
SS_Legal::links_map();
check('empty result is cached only briefly (5 min); a found page for 1 hour', $GLOBALS['ttl']['ss_legal_guess_terms'] === 300);
pg(50, '特定商取引法に基づく表記', 'tokushoho');
check('adding a page is picked up immediately (cache cleared by the save hook)', isset(SS_Legal::links_map()['tokushoho']) && $GLOBALS['ttl']['ss_legal_guess_tokushoho'] === 3600);

/* ---- 登録ページの表示 ---- */
define('SS_TRIAL_MONTHS', 2);
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function wp_create_nonce($a) { return 'nonce'; }
class SS_Router { static function url($p) { return 'https://example.test/' . $p . '/'; } }
class SS_Terms { static function plan_lines() { return array(); } static function sections() { return array(); } } // 料金の説明文は test-terms.php で確認する
function render_register() { ob_start(); $err = ''; $msg = ''; include __DIR__ . '/../templates/register.php'; return ob_get_clean(); }
$GLOBALS['pages'] = array(); $GLOBALS['tr'] = array(); $GLOBALS['opts'] = array();
pg(60, '特定商取引法に基づく表記', 'tokushoho'); pg(61, '利用規約', 'terms'); pg(62, 'プライバシーポリシー', 'privacy');
$html = render_register();
check('register page: agree label links 利用規約 and プライバシーポリシー (new tab)', preg_match('#<a href="https://example.test/terms/" target="_blank" rel="noopener">利用規約</a>.*<a href="https://example.test/privacy/" target="_blank" rel="noopener">プライバシーポリシー</a>に同意します#su', $html) === 1);
check('register page: 特定商取引法に基づく表記 linked below the form', strpos($html, 'href="https://example.test/tokushoho/"') !== false && strpos($html, '特定商取引法に基づく表記</a>') !== false);
$GLOBALS['pages'] = array(); SS_Legal::clear_cache();
$html = render_register();
check('register page without any legal pages: plain text, no links, still renders', strpos($html, '利用規約に同意します') !== false && strpos($html, 'target="_blank"') === false && strpos($html, 'プライバシーポリシー') === false);

echo $failed ? "\n{$failed} FAILED\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
