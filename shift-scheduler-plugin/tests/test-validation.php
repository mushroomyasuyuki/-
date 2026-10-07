<?php
/**
 * 勤務区分・必要人数・希望期間の入力検証のテスト。
 * 実行：php tests/test-validation.php
 */
define('ABSPATH', __DIR__ . '/');
class WP_Error { public $code; public $msg; function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->msg = $m; } }
class WP_REST_Request { private $p; function __construct($p) { $this->p = $p; } function get_json_params() { return $this->p; } }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function add_action() {}
function is_wp_error($x) { return $x instanceof WP_Error; }
require __DIR__ . '/../includes/class-ss-rest-plan.php';

$failed = 0;
function check($n, $c) { global $failed; echo ($c ? 'PASS ' : 'FAIL ') . $n . "\n"; if (!$c) { $failed++; } }
function call($method, $args) {
    $m = new ReflectionMethod('SS_Rest_Plan', $method);
    $m->setAccessible(true);
    return $m->invokeArgs(null, $args);
}
function req($p) { return new WP_REST_Request($p); }

// 時刻・日付
check('time 09:00', SS_Rest_Plan::valid_time('09:00', false));
check('time 22:30', SS_Rest_Plan::valid_time('22:30', false));
check('time 09:15 rejected (30min unit)', !SS_Rest_Plan::valid_time('09:15', false));
check('time 24:00 only for end', !SS_Rest_Plan::valid_time('24:00', false) && SS_Rest_Plan::valid_time('24:00', true));
check('time 24:30 rejected', !SS_Rest_Plan::valid_time('24:30', true));
check('time 9:00 rejected (format)', !SS_Rest_Plan::valid_time('9:00', false));
check('date ok', SS_Rest_Plan::valid_date('2026-11-30'));
check('date 2026-02-30 rejected', !SS_Rest_Plan::valid_date('2026-02-30'));
check('date bad format rejected', !SS_Rest_Plan::valid_date('2026/11/30'));
check('minutes', SS_Rest_Plan::minutes('22:30') === 1350);

// 勤務区分
$p = call('sanitize_pattern', array(req(array('name' => '夜勤', 'start_time' => '16:00', 'end_time' => '09:00', 'break_minutes' => 120, 'counts_as_night' => true, 'color' => '#7E57C2')), false, null));
check('night pattern crosses midnight', !is_wp_error($p) && $p['crosses_midnight'] === 1 && $p['counts_as_night'] === 1 && $p['color'] === '#7e57c2');
$p = call('sanitize_pattern', array(req(array('name' => '日勤', 'start_time' => '09:00', 'end_time' => '18:00')), false, null));
check('day pattern does not cross midnight', !is_wp_error($p) && $p['crosses_midnight'] === 0 && $p['short_name'] === '日');
check('pattern same start/end rejected', is_wp_error(call('sanitize_pattern', array(req(array('name' => 'x', 'start_time' => '09:00', 'end_time' => '09:00')), false, null))));
check('pattern bad color rejected', is_wp_error(call('sanitize_pattern', array(req(array('name' => 'x', 'start_time' => '09:00', 'end_time' => '10:00', 'color' => 'red')), false, null))));
check('pattern bad break rejected', is_wp_error(call('sanitize_pattern', array(req(array('name' => 'x', 'start_time' => '09:00', 'end_time' => '10:00', 'break_minutes' => 999)), false, null))));
check('pattern empty name rejected', is_wp_error(call('sanitize_pattern', array(req(array('name' => ' ', 'start_time' => '09:00', 'end_time' => '10:00')), false, null))));

// 必要人数
$r = call('sanitize_required_staff', array(req(array('weekday' => 5, 'start' => '17:00', 'end' => '22:30', 'count' => 4, 'role' => 'ホール'))));
check('required staff weekday', !is_wp_error($r) && $r['weekday'] === 5 && $r['count'] === 4 && $r['date'] === '');
$r = call('sanitize_required_staff', array(req(array('weekday' => 2, 'date' => '2026-12-31', 'start' => '09:00', 'end' => '18:00', 'count' => 6))));
check('specific date overrides weekday', !is_wp_error($r) && $r['weekday'] === -1 && $r['date'] === '2026-12-31');
check('required staff count -1 rejected', is_wp_error(call('sanitize_required_staff', array(req(array('weekday' => 1, 'start' => '09:00', 'end' => '10:00', 'count' => -1))))));
check('required staff weekday 7 rejected', is_wp_error(call('sanitize_required_staff', array(req(array('weekday' => 7, 'start' => '09:00', 'end' => '10:00', 'count' => 1))))));
check('required staff odd time rejected', is_wp_error(call('sanitize_required_staff', array(req(array('weekday' => 1, 'start' => '09:10', 'end' => '10:00', 'count' => 1))))));
check('required staff 0 people allowed', !is_wp_error(call('sanitize_required_staff', array(req(array('weekday' => -1, 'start' => '02:00', 'end' => '05:00', 'count' => 0))))));

// 希望の収集期間
$r = call('sanitize_period', array(req(array('start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'deadline' => '2026-10-20'))));
check('period ok', !is_wp_error($r) && $r['start_date'] === '2026-11-01');
check('period end before start rejected', is_wp_error(call('sanitize_period', array(req(array('start_date' => '2026-11-30', 'end_date' => '2026-11-01', 'deadline' => '2026-10-20'))))));
check('period over 62 days rejected', is_wp_error(call('sanitize_period', array(req(array('start_date' => '2026-11-01', 'end_date' => '2027-02-01', 'deadline' => '2026-10-20'))))));
check('period with impossible date rejected', is_wp_error(call('sanitize_period', array(req(array('start_date' => '2026-11-01', 'end_date' => '2026-12-32', 'deadline' => '2026-10-20'))))));
check('period exactly 62 days allowed', !is_wp_error(call('sanitize_period', array(req(array('start_date' => '2026-11-01', 'end_date' => '2027-01-01', 'deadline' => '2026-10-20'))))));
check('period bad deadline rejected', is_wp_error(call('sanitize_period', array(req(array('start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'deadline' => 'x'))))));

echo $failed ? "\n{$failed} FAILED\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
