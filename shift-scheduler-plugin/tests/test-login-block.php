<?php
/**
 * wp-login.php 経由でのお客様ログインを拒否することのテスト。
 * 実行：php tests/test-login-block.php
 */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
class WP_User { public $ID; public $roles; public $caps; function __construct($id, $roles, $admin = false) { $this->ID = $id; $this->roles = $roles; $this->caps = $admin; } }
class WP_Error { public $code; function __construct($c, $m = '') { $this->code = $c; } }
function user_can($u, $cap) { return $cap === 'manage_options' && $u->caps; }
function add_action() {} function add_filter() {}
function home_url($p = '') { return 'https://example.test' . $p; }
function get_option($k) { return ''; }
function add_query_arg($k, $v = null, $u = '') { return $u . '?x'; }
class FakeWpdb { public $prefix = 'wp_'; function prepare($s) { return $s; } function get_row($s, $o = null) { return null; } }
$wpdb = new FakeWpdb();
require __DIR__ . '/../includes/class-ss-system.php';
require __DIR__ . '/../includes/class-ss-view.php';
require __DIR__ . '/../includes/class-ss-router.php';
require __DIR__ . '/../includes/class-ss-auth.php';

$failed = 0;
function check($n, $c) { global $failed; echo ($c ? 'PASS ' : 'FAIL ') . $n . "\n"; if (!$c) { $failed++; } }

$owner = new WP_User(1, array('shift_owner'));
$staff = new WP_User(2, array('shift_staff'));
$admin = new WP_User(3, array('administrator'), true);

$r = SS_Auth::block_inactive($owner, 'x');
check('owner via wp-login.php is rejected', $r instanceof WP_Error && $r->code === 'ss_use_portal');
$r = SS_Auth::block_inactive($staff, 'x');
check('staff via wp-login.php is rejected', $r instanceof WP_Error && $r->code === 'ss_use_portal');
check('admin via wp-login.php is allowed', SS_Auth::block_inactive($admin, 'x') === $admin);

$ref = new ReflectionProperty('SS_Auth', 'portal_login');
$ref->setAccessible(true);
$ref->setValue(null, true);
$r = SS_Auth::block_inactive($owner, 'x');
check('owner via portal login is allowed through this filter', $r === $owner);
$ref->setValue(null, false);

echo $failed ? "\n{$failed} FAILED\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
