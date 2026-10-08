<?php
/**
 * 決済（PAY.JP）の通しのテスト。SQLite＋PAY.JPの模擬サーバーで実行。実際の通信はしない。
 * 実行：php tests/test-billing-sqlite.php
 *
 * 注意：PAY.JPの実際の挙動（解約・再開・プラン変更のタイミングなど）は、模擬では確かめられない。
 *       PAY.JPのテストモードで、README の「要確認」の項目を必ず確認すること。
 */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('DAY_IN_SECONDS', 86400);
define('SS_TRIAL_STAFF_LIMIT', 100);
define('SS_TRIAL_MONTHS', 2);

class WP_Error { public $code; public $msg; public $data; function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->msg = $m; $this->data = $d; } function get_error_code() { return $this->code; } function get_error_message() { return $this->msg; } function get_error_data() { return $this->data; } }
class WP_REST_Request { private $p; private $h; function __construct($json = array(), $headers = array()) { $this->p = $json; $this->h = $headers; } function get_json_params() { return $this->p; } function get_header($k) { return isset($this->h[strtolower($k)]) ? $this->h[strtolower($k)] : null; } }
function is_wp_error($x) { return $x instanceof WP_Error; }
function rest_ensure_response($x) { return $x; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function add_action() {} function add_filter() {}
$GLOBALS['opts'] = array();
function get_option($k, $d = '') { return isset($GLOBALS['opts'][$k]) ? $GLOBALS['opts'][$k] : $d; }
function home_url($p = '') { return 'https://example.test' . $p; }
function add_query_arg($a, $b = null, $c = '') { return 'https://example.test/?' . http_build_query(is_array($a) ? $a : array($a => $b)); }
function get_bloginfo($k) { return 'テストサイト'; }
function wp_specialchars_decode($s) { return $s; }
$GLOBALS['mails'] = array();
function wp_mail($to, $subject, $body) { $GLOBALS['mails'][] = array($to, $subject, $body); return true; }
function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
$GLOBALS['uid'] = 0; $GLOBALS['wp_roles'] = array();
$GLOBALS['caps'] = array('owner' => array('shift_manage_billing', 'shift_manage_staff', 'shift_view'), 'staff' => array('shift_view'));
function is_user_logged_in() { return $GLOBALS['uid'] > 0; }
function get_current_user_id() { return $GLOBALS['uid']; }
function current_user_can($cap) { $r = isset($GLOBALS['wp_roles'][$GLOBALS['uid']]) ? $GLOBALS['wp_roles'][$GLOBALS['uid']] : ''; return in_array($cap, isset($GLOBALS['caps'][$r]) ? $GLOBALS['caps'][$r] : array(), true); }

class FakeWpdb {
    public $prefix = 'wp_'; public $pdo; public $insert_id = 0;
    function __construct() {
        $this->pdo = new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $t = array(
            'tenants' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, public_id, name, industry, status, trial_start, trial_end, plan_id, payjp_customer_id DEFAULT "", payjp_subscription_id DEFAULT "", next_billing_at, settings, created_at, deleted_at',
            'users' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, wp_user_id, email, role, staff_id, status, last_login_at, created_at',
            'staff' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, name, active DEFAULT 1',
            'plans' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, name, max_staff, price, payjp_plan_id DEFAULT "", payjp_amount DEFAULT 0, active DEFAULT 1, sort_order DEFAULT 0',
            'billing_events' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, event_id UNIQUE, type, payload, processed_at',
            'schedules' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id, status',
        );
        foreach ($t as $n => $c) { $this->pdo->exec("CREATE TABLE wp_shift_{$n} ({$c})"); }
    }
    function prepare($sql, $args = null) { $args = is_array($args) ? $args : array_slice(func_get_args(), 1); $i = 0; return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) { $v = $args[$i++]; return $m[0] === '%d' ? (string) (int) $v : $this->pdo->quote((string) $v); }, $sql); }
    function get_row($sql, $o = null) { $r = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
    function get_results($sql, $o = null) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    function get_var($sql) { $r = $this->pdo->query($sql)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
    function get_col($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN); }
    function query($sql) { return $this->pdo->exec($sql); }
    private function lit($v) { return $v === null ? 'NULL' : (is_int($v) ? (string) $v : $this->pdo->quote((string) $v)); }
    function insert($t, $d) { $this->pdo->exec("INSERT INTO {$t} (" . implode(',', array_keys($d)) . ') VALUES (' . implode(',', array_map(array($this, 'lit'), array_values($d))) . ')'); $this->insert_id = (int) $this->pdo->lastInsertId(); return 1; }
    function update($t, $d, $w) { $s = array(); foreach ($d as $k => $v) { $s[] = "{$k} = " . $this->lit($v); } $c = array(); foreach ($w as $k => $v) { $c[] = "{$k} = " . $this->lit($v); } return $this->pdo->exec("UPDATE {$t} SET " . implode(', ', $s) . ' WHERE ' . implode(' AND ', $c)); }
}
$wpdb = new FakeWpdb();
foreach (array('system', 'context', 'repo', 'tenants', 'tax', 'view', 'router', 'payjp', 'billing', 'terms', 'rest-billing', 'stats') as $f) { require __DIR__ . "/../includes/class-ss-{$f}.php"; }

/* ---- PAY.JP の模擬サーバー ---- */
$pj = array('calls' => array(), 'subs' => array(), 'n' => 0, 'fail' => array());
function pj_resp($code, $body) { return array('response' => array('code' => $code), 'body' => json_encode($body)); }
SS_Payjp::$transport = function ($method, $url, $args) use (&$pj) {
    $path = substr($url, strlen(SS_Payjp::BASE));
    parse_str(isset($args['body']) ? $args['body'] : '', $p);
    $pj['calls'][] = array($method, $path, $p);
    $key = $method . ' ' . preg_replace('#/(cus|sub)_[A-Za-z0-9]+#', '/ID', $path);
    if (isset($pj['fail'][$key])) { return pj_resp($pj['fail'][$key][0], array('error' => $pj['fail'][$key][1])); }
    $pj['n']++;
    if ($key === 'POST customers') { return pj_resp(200, array('id' => 'cus_' . $pj['n'], 'object' => 'customer')); }
    if ($key === 'POST plans') { return pj_resp(200, array('id' => 'plan_n' . $pj['n'], 'amount' => (int) $p['amount'])); }
    if ($key === 'POST customers/ID') { return pj_resp(200, array('id' => 'cus_x')); }
    if ($key === 'POST subscriptions') {
        $id = 'sub_' . $pj['n'];
        $te = isset($p['trial_end']) ? (int) $p['trial_end'] : null;
        $pj['subs'][$id] = array('id' => $id, 'status' => $te ? 'trial' : 'active', 'trial_end' => $te, 'current_period_end' => $te ? $te : time() + 30 * 86400, 'plan' => array('id' => $p['plan']));
        return pj_resp(200, $pj['subs'][$id]);
    }
    if (preg_match('#^(GET|POST) subscriptions/ID(/(pause|resume|cancel))?$#', $key, $m)) {
        preg_match('#subscriptions/(sub_[A-Za-z0-9]+)#', $path, $mm);
        $id = $mm[1];
        if (!isset($pj['subs'][$id])) { return pj_resp(404, array('error' => array('type' => 'client_error', 'code' => 'invalid_id', 'message' => 'x'))); }
        $s =& $pj['subs'][$id];
        $act = isset($m[3]) ? $m[3] : '';
        if ($act === 'pause') { $s['status'] = 'paused'; }
        elseif ($act === 'resume') { if (isset($p['trial_end'])) { $s['status'] = 'trial'; $s['trial_end'] = (int) $p['trial_end']; $s['current_period_end'] = (int) $p['trial_end']; } else { $s['status'] = 'active'; $s['current_period_end'] = time() + 30 * 86400; } }
        elseif ($act === 'cancel') { $s['status'] = 'canceled'; }
        elseif ($m[1] === 'POST' && isset($p['plan'])) { $s['plan'] = array('id' => $p['plan']); }
        return pj_resp(200, $s);
    }
    return pj_resp(404, array('error' => array('type' => 'client_error', 'code' => 'not_found', 'message' => $key)));
};
function calls($method, $pattern) { global $pj; return array_values(array_filter($pj['calls'], function ($c) use ($method, $pattern) { return $c[0] === $method && preg_match($pattern, $c[1]); })); }

$failed = 0;
function check($n, $c, $x = '') { global $failed; echo ($c ? 'PASS ' : 'FAIL ') . $n . (!$c && $x ? '  ' . $x : '') . "\n"; if (!$c) { $failed++; } }
function code($r) { return is_wp_error($r) ? $r->code : 'ok'; }
function scalar($sql) { global $wpdb; return (int) $wpdb->get_var($sql); }
function T($id) { return SS_System::tenant_by_id($id); }
function as_user($id) { $GLOBALS['uid'] = $id; SS_Context::reset(); }
function dt($offset_days) { return gmdate('Y-m-d H:i:s', time() + (int) round($offset_days * 86400)); }
function mk_tenant($name, $status, $trial_days, $staff = 3, $extra = array()) {
    global $wpdb;
    static $n = 0; $n++;
    $wpdb->insert('wp_shift_tenants', array_merge(array('public_id' => 'pid' . $n, 'name' => $name, 'industry' => 'care', 'status' => $status, 'trial_end' => dt($trial_days), 'created_at' => dt(-60)), $extra));
    $id = $wpdb->insert_id;
    $wpdb->insert('wp_shift_users', array('tenant_id' => $id, 'wp_user_id' => 1000 + $id, 'email' => "owner{$id}@example.test", 'role' => 'owner', 'status' => 'active', 'created_at' => dt(-60)));
    $wpdb->insert('wp_shift_users', array('tenant_id' => $id, 'wp_user_id' => 2000 + $id, 'email' => "staff{$id}@example.test", 'role' => 'staff', 'status' => 'active', 'created_at' => dt(-60)));
    $GLOBALS['wp_roles'][1000 + $id] = 'owner'; $GLOBALS['wp_roles'][2000 + $id] = 'staff';
    for ($k = 0; $k < $staff; $k++) { $wpdb->insert('wp_shift_staff', array('tenant_id' => $id, 'name' => 's' . $k, 'active' => 1)); }
    return $id;
}
$wpdb->insert('wp_shift_plans', array('name' => 'ライト', 'max_staff' => 50, 'price' => 500, 'payjp_plan_id' => 'plan_light', 'payjp_amount' => 550, 'active' => 1, 'sort_order' => 1));
$wpdb->insert('wp_shift_plans', array('name' => 'スタンダード', 'max_staff' => 100, 'price' => 1000, 'payjp_plan_id' => 'plan_std', 'payjp_amount' => 1100, 'active' => 1, 'sort_order' => 2));
$wpdb->insert('wp_shift_plans', array('name' => '準備中', 'max_staff' => 200, 'price' => 2000, 'payjp_plan_id' => '', 'active' => 1, 'sort_order' => 3));
$wpdb->insert('wp_shift_plans', array('name' => '停止中', 'max_staff' => 10, 'price' => 100, 'payjp_plan_id' => 'plan_old', 'payjp_amount' => 110, 'active' => 0, 'sort_order' => 4));
$LIGHT = 1; $STD = 2; $NOTREADY = 3; $INACTIVE = 4;

/* ---- 設定前 ---- */
$A = mk_tenant('A店', 'trial', 20, 3);
check('not configured: subscribe is refused', code(SS_Billing::subscribe(T($A), SS_Billing::plan($LIGHT), 'tok_abc')) === 'not_configured' && count($pj['calls']) === 0);
$GLOBALS['opts'] = array('ss_payjp_secret' => 'sk_test_secret', 'ss_payjp_public' => 'pk_test_public', 'ss_payjp_webhook_token' => 'whtok');
check('configured() true after keys are set', SS_Payjp::configured());

/* ---- 契約（無料期間中） ---- */
check('invalid card token rejected without calling PAY.JP', code(SS_Billing::subscribe(T($A), SS_Billing::plan($LIGHT), 'bad token!')) === 'invalid_card' && count($pj['calls']) === 0);
check('plan without PAY.JP plan id rejected', code(SS_Billing::subscribe(T($A), SS_Billing::plan($NOTREADY), 'tok_abc')) === 'plan_not_ready');
check('inactive plan rejected', code(SS_Billing::subscribe(T($A), SS_Billing::plan($INACTIVE), 'tok_abc')) === 'plan_not_ready');
$B = mk_tenant('B施設', 'trial', 20, 60);
check('plan smaller than staff count rejected', code(SS_Billing::subscribe(T($B), SS_Billing::plan($LIGHT), 'tok_abc')) === 'plan_too_small' && count($pj['calls']) === 0);

$trial_ts = strtotime(T($A)['trial_end'] . ' UTC');
$r = SS_Billing::subscribe(T($A), SS_Billing::plan($LIGHT), 'tok_abc');
check('subscribe during trial succeeds', !is_wp_error($r) && $r['payjp_subscription_id'] !== '' && $r['payjp_customer_id'] !== '' && (int) $r['plan_id'] === $LIGHT, is_wp_error($r) ? $r->msg : '');
$cc = calls('POST', '#^customers$#');
check('customer created with card token, email and tenant metadata', count($cc) === 1 && $cc[0][2]['card'] === 'tok_abc' && $cc[0][2]['email'] === "owner{$A}@example.test" && (int) $cc[0][2]['metadata']['tenant_id'] === $A);
$sc = calls('POST', '#^subscriptions$#');
check('subscription created with plan and trial_end = end of free trial (no charge before)', count($sc) === 1 && $sc[0][2]['plan'] === 'plan_light' && (int) $sc[0][2]['trial_end'] === $trial_ts);
check('tenant stays in trial status; next billing = trial end', $r['status'] === 'trial' && substr($r['next_billing_at'], 0, 10) === gmdate('Y-m-d', $trial_ts));
check('subscribing twice is refused', code(SS_Billing::subscribe(T($A), SS_Billing::plan($LIGHT), 'tok_abc')) === 'already');
check('not expired: effective status trial', SS_Tenants::effective_status(T($A)) === 'trial');

/* ---- カードが通らない場合 ---- */
$C = mk_tenant('C店', 'trial', 10, 2);
$pj['fail']['POST subscriptions'] = array(402, array('type' => 'card_error', 'code' => 'card_declined', 'message' => 'The card was declined'));
$r = SS_Billing::subscribe(T($C), SS_Billing::plan($STD), 'tok_c1');
check('card declined: friendly Japanese message, no subscription saved', code($r) === 'payjp_card_declined' && strpos($r->msg, 'カードが承認されませんでした') !== false && T($C)['payjp_subscription_id'] === '');
check('customer id kept after failure (no duplicate customers on retry)', T($C)['payjp_customer_id'] !== '');
unset($pj['fail']['POST subscriptions']);
$before = count(calls('POST', '#^customers$#'));
$r = SS_Billing::subscribe(T($C), SS_Billing::plan($STD), 'tok_c2');
check('retry succeeds and reuses the customer (card updated, no new customer)', !is_wp_error($r) && count(calls('POST', '#^customers$#')) === $before && count(calls('POST', '#^customers/cus_#')) === 1);
$pj['fail']['POST customers'] = array(500, array('type' => 'server_error', 'code' => '', 'message' => 'boom <script>'));
$Cn = mk_tenant('通信エラー店', 'trial', 10, 2);
$r = SS_Billing::subscribe(T($Cn), SS_Billing::plan($STD), 'tok_zz');
check('server error: generic message (PAY.JP text not shown)', is_wp_error($r) && strpos($r->msg, 'boom') === false && strpos($r->msg, 'エラー') !== false);
unset($pj['fail']['POST customers']);

/* ---- 無料期間が終わった後に契約 ---- */
$D = mk_tenant('D店', 'trial', -3, 4);
check('expired trial without subscription = readonly', SS_Tenants::effective_status(T($D)) === 'readonly' && !SS_Tenants::is_writable(T($D)));
as_user(1000 + $D);
check('readonly tenant owner can still open billing', SS_Rest_Billing::can_manage_billing());
$r = SS_Billing::subscribe(T($D), SS_Billing::plan($LIGHT), 'tok_d');
$sc = calls('POST', '#^subscriptions$#'); $last = end($sc);
check('after trial: subscription starts immediately (no trial_end), tenant becomes active/writable', !is_wp_error($r) && !isset($last[2]['trial_end']) && $r['status'] === 'active' && SS_Tenants::is_writable(T($D)));

/* ---- Webhook ---- */
$subA = T($A)['payjp_subscription_id'];
$req = function ($event, $token) { return new WP_REST_Request($event, array('x-payjp-webhook-token' => $token)); };
check('webhook: wrong token rejected (403)', code(SS_Rest_Billing::webhook($req(array('id' => 'evnt_1', 'type' => 'subscription.renewed', 'data' => array('id' => $subA)), 'nope'))) === 'forbidden');
check('webhook: missing token rejected', code(SS_Rest_Billing::webhook(new WP_REST_Request(array('id' => 'evnt_1', 'type' => 'x')))) === 'forbidden');
$GLOBALS['opts']['ss_payjp_webhook_token'] = '';
check('webhook: rejected when no token is configured, even with empty header', code(SS_Rest_Billing::webhook($req(array('id' => 'evnt_1', 'type' => 'x'), ''))) === 'forbidden');
$GLOBALS['opts']['ss_payjp_webhook_token'] = 'whtok';
check('webhook: bad payload rejected', code(SS_Rest_Billing::webhook($req(array('type' => 'x'), 'whtok'))) === 'invalid');

$pj['subs'][$subA]['status'] = 'active'; // 無料期間が終わって、最初の課金に成功した
$gets = count(calls('GET', '#^subscriptions/#'));
$ev = array('id' => 'evnt_100', 'type' => 'subscription.renewed', 'data' => array('id' => $subA, 'status' => 'whatever-the-payload-says'));
check('webhook accepted', code(SS_Rest_Billing::webhook($req($ev, 'whtok'))) === 'ok');
check('state comes from PAY.JP lookup, not from the payload', T($A)['status'] === 'active' && count(calls('GET', '#^subscriptions/#')) === $gets + 1);
check('event recorded with tenant', scalar("SELECT COUNT(*) FROM wp_shift_billing_events WHERE event_id = 'evnt_100' AND tenant_id = {$A}") === 1);
SS_Rest_Billing::webhook($req($ev, 'whtok'));
check('same event is processed only once', count(calls('GET', '#^subscriptions/#')) === $gets + 1 && scalar("SELECT COUNT(*) FROM wp_shift_billing_events WHERE event_id = 'evnt_100'") === 1);
check('unknown subscription: accepted, ignored, no API call', code(SS_Rest_Billing::webhook($req(array('id' => 'evnt_101', 'type' => 'subscription.updated', 'data' => array('id' => 'sub_unknown')), 'whtok'))) === 'ok' && count(calls('GET', '#^subscriptions/sub_unknown#')) === 0);
check('charge event with subscription id is mapped to the tenant', code(SS_Rest_Billing::webhook($req(array('id' => 'evnt_102', 'type' => 'charge.succeeded', 'data' => array('id' => 'ch_1', 'subscription' => $subA)), 'whtok'))) === 'ok' && scalar("SELECT COUNT(*) FROM wp_shift_billing_events WHERE event_id = 'evnt_102' AND tenant_id = {$A}") === 1);
$pj['fail']['GET subscriptions/ID'] = array(500, array('type' => 'server_error', 'code' => '', 'message' => 'down'));
$r = SS_Rest_Billing::webhook($req(array('id' => 'evnt_103', 'type' => 'subscription.updated', 'data' => array('id' => $subA)), 'whtok'));
check('PAY.JP lookup failure => 500 (so PAY.JP retries) and event not marked processed', is_wp_error($r) && $r->data['status'] === 500 && scalar("SELECT COUNT(*) FROM wp_shift_billing_events WHERE event_id = 'evnt_103'") === 0);
unset($pj['fail']['GET subscriptions/ID']);

/* ---- 支払い失敗 → 猶予 → カード更新で復帰 ---- */
$GLOBALS['mails'] = array();
$pj['subs'][$subA]['status'] = 'paused';
SS_Rest_Billing::webhook($req(array('id' => 'evnt_110', 'type' => 'subscription.paused', 'data' => array('id' => $subA)), 'whtok'));
$b = SS_Billing::billing_settings(T($A));
check('payment failure: grace status, grace_since set, still writable', T($A)['status'] === 'grace' && !empty($b['grace_since']) && SS_Tenants::is_writable(T($A)));
check('payment failure: owner gets one email', count($GLOBALS['mails']) === 1 && $GLOBALS['mails'][0][0] === "owner{$A}@example.test" && strpos($GLOBALS['mails'][0][1], 'お支払い') !== false && strpos($GLOBALS['mails'][0][2], 'billing') !== false);
SS_Rest_Billing::webhook($req(array('id' => 'evnt_111', 'type' => 'subscription.updated', 'data' => array('id' => $subA)), 'whtok'));
check('no second email for the same state', count($GLOBALS['mails']) === 1);
$r = SS_Billing::update_card(T($A), 'tok_new');
check('card update during grace: customer card updated and subscription resumed', !is_wp_error($r) && count(calls('POST', '#^subscriptions/sub_[A-Za-z0-9]+/resume$#')) >= 1 && $r['status'] === 'active' && empty(SS_Billing::billing_settings($r)['grace_since']));
check('card update with bad token rejected', code(SS_Billing::update_card(T($A), 'x')) === 'invalid_card');

/* ---- プラン変更：PAY.JPのプラン更新APIは使わない（無料期間中でも即時課金されるため） ----
 * 「一時停止 → 新しい定期課金を作る（最初の請求日 = 無料期間／現在の期間の終了日）→ 古い定期課金を解約」 */
$seq_keys = function ($calls) { return array_map(function ($c) { return $c[0] . ' ' . preg_replace('#sub_[A-Za-z0-9]+#', 'SUB', $c[1]); }, $calls); };
$wpdb->pdo->exec("UPDATE wp_shift_tenants SET trial_end = '" . dt(-30) . "' WHERE id = {$A}");   // Aは、無料期間が終わって有料の契約中
$oldA = T($A)['payjp_subscription_id'];
$period_end = $pj['subs'][$oldA]['current_period_end'];
$n0 = count($pj['calls']);
$r = SS_Billing::change_plan(T($A), SS_Billing::plan($STD));
$seq = array_slice($pj['calls'], $n0);
check('plan change (paid period): get → pause old → create new → cancel old (no plan-update call)', !is_wp_error($r) && $seq_keys($seq) === array('GET subscriptions/SUB', 'POST subscriptions/SUB/pause', 'POST subscriptions', 'POST subscriptions/SUB/cancel'), json_encode($seq_keys($seq)));
$newA = T($A)['payjp_subscription_id'];
check('plan change (paid period): new subscription is created with the new plan and its first charge at the END of the current period', $newA !== $oldA && $seq[2][2]['plan'] === 'plan_std' && (int) $seq[2][2]['trial_end'] === $period_end && $pj['subs'][$newA]['status'] === 'trial');
check('plan change: old subscription canceled, tenant points to the new one, plan updated, still active', $pj['subs'][$oldA]['status'] === 'canceled' && (int) T($A)['plan_id'] === $STD && SS_Billing::billing_settings(T($A))['payjp_plan_id'] === 'plan_std' && empty(SS_Billing::billing_settings(T($A))['cancel_old_sub']) && T($A)['status'] === 'active' && SS_Tenants::is_writable(T($A)));
check('plan change: next billing date shown = end of current period', abs(strtotime(T($A)['next_billing_at'] . ' UTC') - $period_end) <= 2);
check('plan change to the same plan is a no-op (no API calls)', (function () use (&$pj, $A, $STD) { $n = count($pj['calls']); $r = SS_Billing::change_plan(T($A), SS_Billing::plan($STD)); return !is_wp_error($r) && count($pj['calls']) === $n; })());

// 無料期間中の変更：最初の請求日は、無料期間の終了日のまま（今は課金されない）
$T1 = mk_tenant('無料中の変更', 'trial', 20, 3);
SS_Billing::subscribe(T($T1), SS_Billing::plan($LIGHT), 'tok_t1');
$oldT = T($T1)['payjp_subscription_id'];
$trial_ts1 = strtotime(T($T1)['trial_end'] . ' UTC');
$n0 = count($pj['calls']);
$r = SS_Billing::change_plan(T($T1), SS_Billing::plan($STD));
$seq = array_slice($pj['calls'], $n0);
$newT = T($T1)['payjp_subscription_id'];
check('plan change during the free trial: new subscription keeps the SAME trial end (no charge now)', !is_wp_error($r) && (int) $seq[2][2]['trial_end'] === $trial_ts1 && $pj['subs'][$newT]['status'] === 'trial' && (int) $pj['subs'][$newT]['trial_end'] === $trial_ts1 && T($T1)['status'] === 'trial');
check('plan change during the free trial: no charge of any kind was requested', count(array_filter(calls('POST', '#^charges#'), function ($c) { return true; })) === 0);

// 失敗しても二重請求・請求日の変更にならない
$T2 = mk_tenant('作成失敗', 'trial', 20, 3);
SS_Billing::subscribe(T($T2), SS_Billing::plan($LIGHT), 'tok_t2');
$oldT2 = T($T2)['payjp_subscription_id']; $trial_ts2 = strtotime(T($T2)['trial_end'] . ' UTC');
$pj['fail']['POST subscriptions'] = array(500, array('type' => 'server_error', 'code' => '', 'message' => 'x'));
$n0 = count($pj['calls']);
$r = SS_Billing::change_plan(T($T2), SS_Billing::plan($STD));
$seq = array_slice($pj['calls'], $n0);
unset($pj['fail']['POST subscriptions']);
$resume = array_values(array_filter($seq, function ($c) { return preg_match('#/resume$#', $c[1]); }));
check('plan change: if creating the new subscription fails, the old one is resumed with the SAME billing date; nothing changes', is_wp_error($r) && count($resume) === 1 && (int) $resume[0][2]['trial_end'] === $trial_ts2 && $pj['subs'][$oldT2]['status'] === 'trial' && (int) $pj['subs'][$oldT2]['trial_end'] === $trial_ts2 && T($T2)['payjp_subscription_id'] === $oldT2 && (int) T($T2)['plan_id'] === $LIGHT);
check('...and no cancel was sent', count(array_filter($seq, function ($c) { return preg_match('#/cancel$#', $c[1]); })) === 0);

$T3 = mk_tenant('解約失敗', 'trial', 20, 3);
SS_Billing::subscribe(T($T3), SS_Billing::plan($LIGHT), 'tok_t3');
$oldT3 = T($T3)['payjp_subscription_id'];
$pj['fail']['POST subscriptions/ID/cancel'] = array(500, array('type' => 'server_error', 'code' => '', 'message' => 'x'));
$r = SS_Billing::change_plan(T($T3), SS_Billing::plan($STD));
unset($pj['fail']['POST subscriptions/ID/cancel']);
check('plan change: if canceling the old one fails, the change still succeeds; old stays paused (no billing); cleanup is remembered', !is_wp_error($r) && T($T3)['payjp_subscription_id'] !== $oldT3 && $pj['subs'][$oldT3]['status'] === 'paused' && SS_Billing::billing_settings(T($T3))['cancel_old_sub'] === $oldT3);
SS_Billing::daily();
check('daily retries the cancel of the old subscription and clears the note', $pj['subs'][$oldT3]['status'] === 'canceled' && empty(SS_Billing::billing_settings(T($T3))['cancel_old_sub']));

// 変更できない状態
$T4 = mk_tenant('停止中の変更', 'grace', -30, 3, array('payjp_subscription_id' => 'sub_t4', 'payjp_customer_id' => 'cus_t4', 'plan_id' => $LIGHT));
$pj['subs']['sub_t4'] = array('id' => 'sub_t4', 'status' => 'paused', 'current_period_end' => time() + 86400, 'plan' => array('id' => 'plan_light'));
check('plan change refused while payment is failing (paused): update the card first', code(SS_Billing::change_plan(T($T4), SS_Billing::plan($STD))) === 'paused');
SS_Billing::set_billing($T1, array('cancel_at' => dt(10)));
check('plan change refused while a cancellation is reserved', code(SS_Billing::change_plan(T($T1), SS_Billing::plan($LIGHT))) === 'cancel_reserved');
SS_Billing::set_billing($T1, array('cancel_at' => null));
check('plan change without a subscription refused', code(SS_Billing::change_plan(T($B), SS_Billing::plan($LIGHT))) === 'no_subscription');
$E = mk_tenant('E施設', 'active', -10, 70, array('payjp_subscription_id' => 'sub_e', 'payjp_customer_id' => 'cus_e', 'plan_id' => $STD));
$pj['subs']['sub_e'] = array('id' => 'sub_e', 'status' => 'active', 'current_period_end' => time() + 86400 * 20, 'plan' => array('id' => 'plan_std'));
check('plan change too small for staff rejected', code(SS_Billing::change_plan(T($E), SS_Billing::plan($LIGHT))) === 'plan_too_small');
check('the PAY.JP plan-update API is never used (it charges immediately, even during a free trial)', count(array_filter($pj['calls'], function ($c) { return $c[0] === 'POST' && preg_match('#^subscriptions/sub_[A-Za-z0-9]+$#', $c[1]) && isset($c[2]['plan']); })) === 0);

/* ---- 解約の予約と取り消し ---- */
$subD = T($D)['payjp_subscription_id'];
$r = SS_Billing::cancel(T($D));
$b = SS_Billing::billing_settings(T($D));
check('cancel: subscription paused (no more charges); access continues until period end', !is_wp_error($r) && $pj['subs'][$subD]['status'] === 'paused' && !empty($b['cancel_at']) && T($D)['status'] === 'active' && SS_Tenants::is_writable(T($D)));
check('cancel reserved until PAY.JP period end', abs(strtotime($b['cancel_at'] . ' UTC') - $pj['subs'][$subD]['current_period_end']) <= 2);
SS_Rest_Billing::webhook($req(array('id' => 'evnt_120', 'type' => 'subscription.paused', 'data' => array('id' => $subD)), 'whtok'));
check('paused webhook during a reserved cancel does not put the tenant into grace', T($D)['status'] === 'active');
check('cancel twice refused', code(SS_Billing::cancel(T($D))) === 'already');
$r = SS_Billing::undo_cancel(T($D));
$rs = calls('POST', '#^subscriptions/' . $subD . '/resume$#'); $lastrs = end($rs);
check('undo cancel: resumed with trial_end = period end (no immediate charge), reservation cleared', !is_wp_error($r) && (int) $lastrs[2]['trial_end'] === $pj['subs'][$subD]['current_period_end'] && empty(SS_Billing::billing_settings(T($D))['cancel_at']) && T($D)['status'] === 'active');
check('undo without reservation refused', code(SS_Billing::undo_cancel(T($D))) === 'no_cancel');

/* ---- 毎日の処理 ---- */
SS_Billing::cancel(T($D));
SS_Billing::set_billing($D, array('cancel_at' => dt(-1)));  // 期間末を過ぎた
SS_Billing::daily();
check('daily: due cancellation => PAY.JP cancel, readonly, subscription cleared', $pj['subs'][$subD]['status'] === 'canceled' && T($D)['status'] === 'readonly' && T($D)['payjp_subscription_id'] === '' && empty(SS_Billing::billing_settings(T($D))['cancel_at']));
check('canceled customer can subscribe again (customer id kept)', T($D)['payjp_customer_id'] !== '' && !is_wp_error(SS_Billing::subscribe(T($D), SS_Billing::plan($LIGHT), 'tok_again')));

$G1 = mk_tenant('猶予8日', 'grace', -30, 2, array('payjp_subscription_id' => 'sub_g1', 'next_billing_at' => dt(30)));
$G2 = mk_tenant('猶予3日', 'grace', -30, 2, array('payjp_subscription_id' => 'sub_g2', 'next_billing_at' => dt(30)));
SS_Billing::set_billing($G1, array('grace_since' => dt(-8)));
SS_Billing::set_billing($G2, array('grace_since' => dt(-3)));
SS_Billing::daily();
check('daily: grace over 7 days => readonly; within 7 days stays', T($G1)['status'] === 'readonly' && T($G2)['status'] === 'grace');

$S = mk_tenant('同期店', 'active', -30, 2, array('payjp_subscription_id' => 'sub_sync', 'plan_id' => $LIGHT, 'next_billing_at' => dt(-1)));
$pj['subs']['sub_sync'] = array('id' => 'sub_sync', 'status' => 'canceled', 'current_period_end' => time() - 100);
SS_Billing::daily();
check('daily: missed webhook is caught by sync (canceled => readonly)', T($S)['status'] === 'readonly' && T($S)['payjp_subscription_id'] === '');

$SU = mk_tenant('運営停止', 'suspended', -30, 2, array('payjp_subscription_id' => 'sub_susp', 'next_billing_at' => dt(-1)));
$pj['subs']['sub_susp'] = array('id' => 'sub_susp', 'status' => 'active', 'current_period_end' => time() + 86400);
SS_Billing::daily();
check('suspended tenant is not reactivated by payment sync', T($SU)['status'] === 'suspended');

/* ---- 無料期間の案内メール ---- */
$GLOBALS['mails'] = array();
$R1 = mk_tenant('案内A', 'trial', 13.5, 2);   // 残り14日（切り上げ）→ 14日前の案内
SS_Billing::send_trial_reminders();
$mine = array_values(array_filter($GLOBALS['mails'], function ($m) use ($R1) { return $m[0] === "owner{$R1}@example.test"; }));
check('reminder: sent once when 14 days remain', count($mine) === 1 && strpos($mine[0][1], '無料期間') !== false && strpos($mine[0][2], 'billing') !== false);
SS_Billing::send_trial_reminders();
$mine = array_values(array_filter($GLOBALS['mails'], function ($m) use ($R1) { return $m[0] === "owner{$R1}@example.test"; }));
check('reminder: not repeated on the next run', count($mine) === 1);
$wpdb->pdo->exec("UPDATE wp_shift_tenants SET trial_end = '" . dt(6.5) . "' WHERE id = {$R1}");
SS_Billing::send_trial_reminders();
$wpdb->pdo->exec("UPDATE wp_shift_tenants SET trial_end = '" . dt(0.5) . "' WHERE id = {$R1}");
SS_Billing::send_trial_reminders();
$mine = array_values(array_filter($GLOBALS['mails'], function ($m) use ($R1) { return $m[0] === "owner{$R1}@example.test"; }));
check('reminder: 7 days and 1 day also sent (3 in total)', count($mine) === 3);
$R2 = mk_tenant('案内B', 'trial', 3.5, 2); // 遅れて導入：残り4日 → 7日前の案内のみ（14日前は送らない）
SS_Billing::send_trial_reminders();
$m2 = array_values(array_filter($GLOBALS['mails'], function ($m) use ($R2) { return $m[0] === "owner{$R2}@example.test"; }));
$wpdb->pdo->exec("UPDATE wp_shift_tenants SET trial_end = '" . dt(0.5) . "' WHERE id = {$R2}");
SS_Billing::send_trial_reminders();
$m2b = array_values(array_filter($GLOBALS['mails'], function ($m) use ($R2) { return $m[0] === "owner{$R2}@example.test"; }));
check('reminder: late install sends only the nearest one, then the 1-day one', count($m2) === 1 && count($m2b) === 2);
$R3 = mk_tenant('案内C（契約済み）', 'trial', 5, 2, array('payjp_subscription_id' => 'sub_r3'));
$R4 = mk_tenant('案内D（遠い）', 'trial', 40, 2);
SS_Billing::send_trial_reminders();
check('reminder: not sent to subscribed tenants or to trials far from the end', count(array_filter($GLOBALS['mails'], function ($m) use ($R3, $R4) { return $m[0] === "owner{$R3}@example.test" || $m[0] === "owner{$R4}@example.test"; })) === 0);

/* ---- 運営：金額変更後に、既存の契約者を新しい金額へ ---- */
$wpdb->pdo->exec("UPDATE wp_shift_plans SET payjp_plan_id = 'plan_light_v2', payjp_amount = 660, price = 600 WHERE id = {$LIGHT}");
$light_subs = scalar("SELECT COUNT(*) FROM wp_shift_tenants WHERE plan_id = {$LIGHT} AND payjp_subscription_id <> ''");
$res = SS_Billing::migrate_plan_subscribers($LIGHT, 1);
check('migrate: batch limit respected, remaining reported', $res['migrated'] === 1 && $res['remaining'] === $light_subs - 1, json_encode($res) . ' subs=' . $light_subs);
$res2 = SS_Billing::migrate_plan_subscribers($LIGHT, 50);
$res3 = SS_Billing::migrate_plan_subscribers($LIGHT, 50);
check('migrate: finishes, then nothing left to do', $res2['remaining'] === 0 && $res3['migrated'] === 0);

/* ---- 運営：プランの保存とPAY.JPへの反映 ---- */
$wpdb->pdo->exec("UPDATE wp_shift_plans SET price = 700 WHERE id = {$STD}");   // 金額だけ変えた状態（PAY.JP側は1000円のまま）
check('plan with price different from PAY.JP plan is not selectable (page price must equal the charge)', !SS_Billing::plan_ready(SS_Billing::plan($STD)) && code(SS_Billing::subscribe(T(mk_tenant('金額ずれ', 'trial', 5, 2)), SS_Billing::plan($STD), 'tok_q')) === 'plan_not_ready');
$wpdb->pdo->exec("UPDATE wp_shift_plans SET price = 1000 WHERE id = {$STD}");
check('plan in sync is ready', SS_Billing::plan_ready(SS_Billing::plan($STD)));
$n0 = count(calls('POST', '#^plans$#'));
$res = SS_Billing::save_plans(array(
    array('id' => $STD, 'name' => 'スタンダード', 'max_staff' => 100, 'price' => 1200, 'active' => 1, 'sort_order' => 2),   // 金額変更
    array('id' => $LIGHT, 'name' => 'ライト', 'max_staff' => 50, 'price' => 600, 'active' => 1, 'sort_order' => 1),         // 変更なし（v2の金額と同じ）
    array('id' => 0, 'name' => '', 'max_staff' => 0, 'price' => 0),                                                         // 空の新規行は無視
    array('id' => 0, 'name' => 'プレミアム', 'max_staff' => 300, 'price' => 3000, 'active' => 1, 'sort_order' => 5),         // 新規
    array('id' => 0, 'name' => '安すぎ', 'max_staff' => 10, 'price' => 10, 'active' => 1),                                  // エラー
));
$plans_calls = array_slice(calls('POST', '#^plans$#'), $n0);
check('save_plans: saved 3, synced 2 (changed price + new plan), 1 error', $res['saved'] === 3 && $res['synced'] === 2 && count($res['errors']) === 1, json_encode($res));
check('save_plans: PAY.JP plan is created with the TAX-INCLUDED amount (1200 + 10% = 1320), JPY, monthly', count($plans_calls) === 2 && $plans_calls[0][2]['amount'] === '1320' && $plans_calls[0][2]['currency'] === 'jpy' && $plans_calls[0][2]['interval'] === 'month');
$std = SS_Billing::plan($STD);
check('save_plans: tenant-facing plan now points at the new PAY.JP plan and is ready', (int) $std['price'] === 1200 && (int) $std['payjp_amount'] === 1320 && $std['payjp_plan_id'] !== 'plan_std' && SS_Billing::plan_ready($std));
check('save_plans: new plan stored and ready', scalar("SELECT COUNT(*) FROM wp_shift_plans WHERE name = 'プレミアム' AND price = 3000 AND payjp_amount = 3300 AND payjp_plan_id <> ''") === 1);
$pj['fail']['POST plans'] = array(500, array('type' => 'server_error', 'code' => '', 'message' => 'x'));
$res = SS_Billing::save_plans(array(array('id' => $STD, 'name' => 'スタンダード', 'max_staff' => 100, 'price' => 1500, 'active' => 1)));
check('save_plans: PAY.JP failure reported; plan stays not-ready (never charges a wrong amount)', count($res['errors']) === 1 && !SS_Billing::plan_ready(SS_Billing::plan($STD)));
unset($pj['fail']['POST plans']);
SS_Billing::save_plans(array(array('id' => $STD, 'name' => 'スタンダード', 'max_staff' => 100, 'price' => 1500, 'active' => 1)));
check('save_plans: retry after failure syncs', SS_Billing::plan_ready(SS_Billing::plan($STD)));
$stale = SS_Billing::stale_subscribers($STD);
check('stale subscribers on the standard plan are listed (they still pay the old amount)', count($stale) >= 1);
$m = SS_Billing::migrate_plan_subscribers($STD, 50);
check('migrate moves them to the new PAY.JP plan', $m['migrated'] === count($stale) && $m['remaining'] === 0 && count(SS_Billing::stale_subscribers($STD)) === 0);
$wpdb->pdo->exec("UPDATE wp_shift_plans SET price = 1800 WHERE id = {$STD}");
check('migrate does nothing while the plan is not in sync', SS_Billing::migrate_plan_subscribers($STD, 50)['migrated'] === 0);
$wpdb->pdo->exec("UPDATE wp_shift_plans SET price = 1500 WHERE id = {$STD}");
SS_Billing::set_billing(1000, array());

// テスト⇔本番の切り替え：反映済みの状態が消え、お客様は選べなくなる（本番で作り直すまで）
$ready_before = scalar("SELECT COUNT(*) FROM wp_shift_plans WHERE payjp_plan_id <> ''");
SS_Billing::reset_plan_sync();
check('switching test/live resets plan sync (no stale test plan ids are ever used in live)', $ready_before > 0 && scalar("SELECT COUNT(*) FROM wp_shift_plans WHERE payjp_plan_id <> '' OR payjp_amount <> 0") === 0 && !SS_Billing::plan_ready(SS_Billing::plan($LIGHT)));
SS_Billing::save_plans(array(
    array('id' => $LIGHT, 'name' => 'ライト', 'max_staff' => 50, 'price' => 600, 'active' => 1, 'sort_order' => 1),
    array('id' => $STD, 'name' => 'スタンダード', 'max_staff' => 100, 'price' => 1500, 'active' => 1, 'sort_order' => 2),
));
check('re-saving plans recreates them in the new mode', SS_Billing::plan_ready(SS_Billing::plan($LIGHT)) && SS_Billing::plan_ready(SS_Billing::plan($STD)));

/* ---- 他社への影響・権限 ---- */
as_user(1000 + $A);
$info = SS_Rest_Billing::get_billing();
check('get_billing returns own tenant only (plan, staff count)', (int) $info['plan_id'] === $STD && $info['staff_count'] === 3 && $info['has_subscription'] === true && $info['configured'] === true && $info['public_key'] === 'pk_test_public');
check('get_billing shows TAX-INCLUDED prices (price) and the tax-excluded price (price_ex), plus the tax rate', $info['tax_rate'] === 10 && count(array_filter($info['plans'], function ($p) { return $p['price'] === SS_Tax::incl($p['price_ex']) && $p['price'] > $p['price_ex']; })) === count($info['plans']));
check('get_billing includes the generated terms (料金・無料期間・請求の開始…)', count($info['terms']) >= 7 && $info['terms'][0]['title'] === '料金');
// 税込への切り替え：PAY.JP側が税抜の金額のまま（古いデータ）や、税率変更後は、選べない（請求額と画面の金額がずれないように）
$std_amount = (int) SS_Billing::plan($STD)['payjp_amount'];
$wpdb->pdo->exec("UPDATE wp_shift_plans SET payjp_amount = price WHERE id = {$STD}");
check('a PAY.JP plan that still has the pre-tax amount is NOT ready (would undercharge)', !SS_Billing::plan_ready(SS_Billing::plan($STD)));
$wpdb->pdo->exec("UPDATE wp_shift_plans SET payjp_amount = {$std_amount} WHERE id = {$STD}");
check('...and is ready again with the tax-included amount', SS_Billing::plan_ready(SS_Billing::plan($STD)));
$GLOBALS['opts']['ss_tax_rate'] = 8;
check('after the tax rate changes, plans are not ready until re-synced', !SS_Billing::plan_ready(SS_Billing::plan($STD)) && !SS_Billing::plan_ready(SS_Billing::plan($LIGHT)));
$n0 = count(calls('POST', '#^plans$#'));
SS_Billing::save_plans(array(array('id' => $STD, 'name' => 'スタンダード', 'max_staff' => 100, 'price' => 1500, 'active' => 1, 'sort_order' => 2)));
$pc = array_slice(calls('POST', '#^plans$#'), $n0);
check('re-saving with the new tax rate creates a PAY.JP plan with the new amount (1500 + 8% = 1620)', count($pc) === 1 && $pc[0][2]['amount'] === '1620' && SS_Billing::plan_ready(SS_Billing::plan($STD)));
$GLOBALS['opts']['ss_tax_rate'] = 10;
SS_Billing::save_plans(array(array('id' => $STD, 'name' => 'スタンダード', 'max_staff' => 100, 'price' => 1500, 'active' => 1, 'sort_order' => 2), array('id' => $LIGHT, 'name' => 'ライト', 'max_staff' => 50, 'price' => 600, 'active' => 1, 'sort_order' => 1)));
check('...and back to 10%: plans re-synced', SS_Billing::plan_ready(SS_Billing::plan($STD)) && SS_Billing::plan_ready(SS_Billing::plan($LIGHT)));
check('get_billing never exposes secret key or customer ids', strpos(json_encode($info), 'sk_test_secret') === false && strpos(json_encode($info), 'cus_') === false && strpos(json_encode($info), 'sub_') === false);
check('plans listed with fit and ready flags (Light fits 3 staff; unsynced plan not ready)', $info['plans'][0]['fits'] === true && $info['plans'][0]['ready'] === true && count($info['plans']) >= 3 && count(array_filter($info['plans'], function ($p) { return $p['name'] === '準備中' && !$p['ready']; })) === 1);
as_user(2000 + $A);
check('staff (non-owner) cannot manage billing', !SS_Rest_Billing::can_manage_billing());
as_user(0);
check('logged out cannot manage billing', !SS_Rest_Billing::can_manage_billing());
as_user(1000 + $C);
$r = SS_Rest_Billing::cancel();
check('owner of C cancels only C (A unaffected)', code($r) === 'ok' && !empty(SS_Billing::billing_settings(T($C))['cancel_at']) && empty(SS_Billing::billing_settings(T($A))['cancel_at']));

/* ---- 統計（月額の見込み） ---- */
$st = SS_Stats::summary(gmdate('Y-m-d H:i:s'));
$expect = 0; $expect_ex = 0;
foreach ($wpdb->get_results("SELECT p.price AS price FROM wp_shift_tenants t JOIN wp_shift_plans p ON p.id = t.plan_id WHERE t.payjp_subscription_id <> '' AND (t.status IN ('active','grace'))", ARRAY_A) as $row) { $expect += SS_Tax::incl($row['price']); $expect_ex += (int) $row['price']; }
check('stats: monthly revenue estimate = sum of TAX-INCLUDED plan prices of paying tenants (and the tax-excluded sum is reported too)', $st['mrr'] === $expect && $st['mrr_ex'] === $expect_ex && $st['mrr'] > $st['mrr_ex'], 'mrr=' . $st['mrr'] . ' expect=' . $expect);

/* ---- PAY.JPへのリクエストの形 ---- */
$cap = null;
$old = SS_Payjp::$transport;
SS_Payjp::$transport = function ($m, $u, $a) use (&$cap) { $cap = array($m, $u, $a); return pj_resp(200, array('id' => 'x')); };
SS_Payjp::create_customer('a@example.test', '店', 'tok_1', array('tenant_id' => 5));
check('request: Basic auth with secret key as user and empty password', $cap[2]['headers']['Authorization'] === 'Basic ' . base64_encode('sk_test_secret:'));
check('request: form-encoded body with nested metadata', strpos($cap[2]['body'], 'metadata%5Btenant_id%5D=5') !== false && $cap[2]['headers']['Content-Type'] === 'application/x-www-form-urlencoded' && $cap[1] === 'https://api.pay.jp/v1/customers');
SS_Payjp::get_subscription('sub_1');
check('request: GET has no body', $cap[0] === 'GET' && !isset($cap[2]['body']));
SS_Payjp::$transport = $old;

echo $failed ? "\n{$failed} FAILED\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
