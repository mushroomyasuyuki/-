<?php
/**
 * 勤怠（タイムカード・QR打刻・勤怠区分・有給休暇）のテスト。
 * お客様A・Bの2社で、別のお客様のデータを読めない・書けないこと、QRの署名が偽造・使い回しできないことを確かめる。
 * 実行：php tests/test-kintai-sqlite.php
 */
require __DIR__ . '/bootstrap-office.php';

$K = 'SS_Rest_Kintai';
$today = gmdate('Y-m-d');
$tA = SS_System::tenant_by_id($A);
$tB = SS_System::tenant_by_id($B);
$staff = function ($id) { return SS_Repo::for_tenant($id === 3 ? 2 : 1)->find('staff', $id); };
$qr = function ($tenant, $staff_id) use ($staff) {
    $s = $staff($staff_id);
    return array('t' => $tenant['public_id'], 's' => (string) $staff_id, 'k' => SS_Rest_Kintai::punch_sig($tenant['id'], $s));
};

/* ---- QRの署名 ---- */
as_user(0);
$q = $qr($tA, $a2);
check('QR: the signed URL resolves to A and that staff member', ($r = SS_Rest_Kintai::resolve_punch($q['t'], $a2, $q['k'])) && (int) $r['tenant']['id'] === $A && (int) $r['staff']['id'] === $a2);
check('QR: URL contains no guessable ?staff= only link (has a signature)', strpos(SS_Rest_Kintai::punch_url($tA, $staff($a2)), 'k=' . $q['k']) !== false);
check('QR: another staff id with the same signature is rejected', SS_Rest_Kintai::resolve_punch($q['t'], $a1, $q['k']) === null);
check('QR: B\'s company id with A\'s signature is rejected', SS_Rest_Kintai::resolve_punch($tB['public_id'], $a2, $q['k']) === null);
check('QR: B\'s staff id under A\'s company id is rejected', SS_Rest_Kintai::resolve_punch($q['t'], $b1, SS_Rest_Kintai::punch_sig($A, $staff($b1))) === null);
check('QR: a malformed signature is rejected', SS_Rest_Kintai::resolve_punch($q['t'], $a2, 'abc') === null);
$GLOBALS['transients'] = array();

/* ---- QRでの打刻（ログインなし） ---- */
$st = body(SS_Rest_Kintai::punch_status(req(array(), $q)));
check('QR status: shows only that staff member, no punch yet', $st['staff']['name'] === '佐藤' && $st['today'] === null && $st['writable'] === true);
$r = SS_Rest_Kintai::punch(req(array('kind' => 'in', 'clockIn' => '05:00', 'location' => array('lat' => 35.1, 'lng' => 139.2, 'accuracy' => 12)), $q));
$row = $wpdb->get_row("SELECT * FROM wp_shift_time_cards WHERE tenant_id = {$A} AND staff_id = {$a2}", ARRAY_A);
check('QR punch in: saved for A with the server time (the sent time is ignored)', st($r) === 200 && $row && $row['record_date'] === $today && $row['clock_in'] === gmdate('H:i') && $row['clock_in'] !== '05:00');
check('QR punch in: name and department come from the roster; location saved', $row['staff_name'] === '佐藤' && $row['department'] === '製作' && (float) $row['clock_in_lat'] === 35.1);
check('QR punch in twice: refused (no overwrite)', st(SS_Rest_Kintai::punch(req(array('kind' => 'in'), $q))) === 409);
check('QR punch out: saved', st(SS_Rest_Kintai::punch(req(array('kind' => 'out'), $q))) === 200 && $wpdb->get_var("SELECT clock_out FROM wp_shift_time_cards WHERE staff_id = {$a2}") !== '');
check('QR punch out twice: refused', st(SS_Rest_Kintai::punch(req(array('kind' => 'out'), $q))) === 409);
check('QR status: history visible, without locations', ($h = body(SS_Rest_Kintai::punch_status(req(array(), $q)))) && count($h['history']) === 1 && !isset($h['history'][0]['clockInLocation']));
check('QR punch with a forged signature: refused, nothing written', st(SS_Rest_Kintai::punch(req(array('kind' => 'in'), array('t' => $q['t'], 's' => (string) $a1, 'k' => $q['k'])))) === 403 && (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_shift_time_cards WHERE staff_id = {$a1}") === 0);
check('B has no time cards', count_rows('time_cards', $B) === 0);

/* ---- QRの作り直し ---- */
as_user(3);
check('B cannot reissue A\'s staff QR', st(SS_Rest_Kintai::reissue_keys(req(array('staffId' => $a2)))) === 404);
as_user(1);
$re = body(SS_Rest_Kintai::reissue_keys(req(array('staffId' => $a2))));
check('reissue: returns the new URL', count($re) === 1 && $re[0]['id'] === (string) $a2);
as_user(0);
check('reissue: the old QR no longer works', SS_Rest_Kintai::resolve_punch($q['t'], $a2, $q['k']) === null);
$q2 = $qr($tA, $a2);
check('reissue: the new QR works', SS_Rest_Kintai::resolve_punch($q2['t'], $a2, $q2['k']) !== null);
$GLOBALS['transients'] = array();

/* ---- 総当たり対策 ---- */
for ($i = 0; $i < 30; $i++) { SS_Rest_Kintai::resolve_punch($q2['t'], $a2, str_repeat('0', 32)); }
check('QR: after many wrong signatures from one address, even a correct one is refused for a while', SS_Rest_Kintai::resolve_punch($q2['t'], $a2, $q2['k']) === null);
$GLOBALS['transients'] = array();

/* ---- 閲覧のみ・解約 ---- */
$wpdb->update('wp_shift_tenants', array('status' => 'readonly'), array('id' => $A));
check('read-only company: QR page opens but punching is refused', SS_Rest_Kintai::resolve_punch($q2['t'], $a2, $q2['k']) !== null && st(SS_Rest_Kintai::punch(req(array('kind' => 'in'), $q2))) === 403);
$wpdb->update('wp_shift_tenants', array('status' => 'suspended'), array('id' => $A));
check('suspended company: QR does not work', SS_Rest_Kintai::resolve_punch($q2['t'], $a2, $q2['k']) === null);
$wpdb->update('wp_shift_tenants', array('status' => 'trial'), array('id' => $A));
$wpdb->update('wp_shift_staff', array('active' => 0), array('id' => $a2));
check('retired (inactive) staff: QR does not work', SS_Rest_Kintai::resolve_punch($q2['t'], $a2, $q2['k']) === null);
$wpdb->update('wp_shift_staff', array('active' => 1), array('id' => $a2));
$GLOBALS['transients'] = array();

/* ---- ログインしての打刻 ---- */
as_user(1); // A社の管理者（名簿に紐づいていない）
check('logged-in punch without a roster link: clear refusal', st(SS_Rest_Kintai::punch_status(req())) === 403);
as_user(3);
$r = SS_Rest_Kintai::punch(req(array('kind' => 'in', 'staffId' => $a2)));
check('logged-in punch (B owner linked to B staff): writes to B\'s own staff, ignores a sent staffId', st($r) === 200 && body($r)['staffId'] === (string) $b1 && count_rows('time_cards', $B) === 1);
check('logged-in punch: did not touch A', (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_shift_time_cards WHERE tenant_id = {$A}") === 1);

/* ---- 一覧・修正（管理者集計） ---- */
as_user(1);
$list = body(SS_Rest_Kintai::get_time_cards(req(array(), array('dateFrom' => $today, 'dateTo' => $today))));
check('time-cards (A admin): only A\'s, with locations', count($list) === 1 && $list[0]['staffId'] === (string) $a2 && array_key_exists('clockInLocation', $list[0]));
as_user(2);
$wpdb->insert('wp_shift_time_cards', array('tenant_id' => $A, 'record_date' => '2026-01-05', 'staff_id' => $a1, 'staff_name' => '山田', 'clock_in' => '09:00', 'created_at' => gmdate('Y-m-d H:i:s')));
$list = body(SS_Rest_Kintai::get_time_cards(req(array(), array('staffId' => $a1))));
check('time-cards (staff): only their own, even when asking for someone else; no locations', count($list) === 1 && $list[0]['staffId'] === (string) $a2 && !isset($list[0]['clockInLocation']));
as_user(3);
check('time-cards (B): none of A\'s', count(body(SS_Rest_Kintai::get_time_cards(req()))) === 1);
check('B cannot correct A\'s time card', st(SS_Rest_Kintai::upsert_time_card(req(array('date' => $today, 'staffId' => $a2, 'clockIn' => '08:00')))) === 400);
as_user(1);
$r = body(SS_Rest_Kintai::upsert_time_card(req(array('date' => $today, 'staffId' => $a2, 'staffName' => 'なりすまし', 'clockIn' => '08:30', 'clockOut' => '17:30'))));
check('admin correction: updates the same row, roster name kept, logged', $r['clockIn'] === '08:30' && $r['staffName'] === '佐藤' && (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_shift_time_cards WHERE tenant_id = {$A} AND staff_id = {$a2}") === 1
    && (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_shift_activity_log WHERE tenant_id = {$A} AND action = 'タイムカードを修正'") === 1);
check('staff cannot use the admin correction', (function () { as_user(2); return !SS_Rest_Office::can_admin_write(); })());

/* ---- 勤怠区分 ---- */
as_user(1);
$m = substr($today, 0, 7);
$days = body(SS_Rest_Kintai::get_attendance_month(req(array(), array('staffId' => $a2, 'year' => (int) substr($m, 0, 4), 'month' => (int) substr($m, 5, 2)))));
$todayRow = array_values(array_filter($days, function ($d) use ($today) { return $d['date'] === $today; }))[0];
check('attendance month: every day of the month, today filled from the time card', count($days) === (int) gmdate('t') && $todayRow['clockIn'] === '08:30' && in_array($todayRow['dayType'], array('通常', '休日出勤'), true));
SS_Rest_Kintai::save_attendance_month(req(array('staffId' => $a2, 'days' => array(
    array('date' => $m . '-01', 'dayType' => '有休'), array('date' => $m . '-02', 'dayType' => '有休'), array('date' => $m . '-03', 'dayType' => 'ハック'),
))));
check('attendance save: valid types saved, unknown type ignored', count_rows('attendance_days', $A) === 2);
SS_Rest_Kintai::save_attendance_month(req(array('staffId' => $a2, 'days' => array(array('date' => $m . '-02', 'dayType' => '')))));
check('attendance save: empty type removes the row', count_rows('attendance_days', $A) === 1);
as_user(3);
check('B cannot read A\'s attendance', st(SS_Rest_Kintai::get_attendance_month(req(array(), array('staffId' => $a2, 'year' => 2026, 'month' => 1)))) === 400);
check('B cannot save attendance for A\'s staff', st(SS_Rest_Kintai::save_attendance_month(req(array('staffId' => $a2, 'days' => array(array('date' => $m . '-05', 'dayType' => '欠勤')))))) === 400 && count_rows('attendance_days', $B) === 0);

/* ---- 有給休暇 ---- */
as_user(1);
$wpdb->update('wp_shift_staff', array('hire_date' => '2020-04-01'), array('id' => $a2));
$g = SS_Rest_Kintai::create_leave_grant(req(array('staffId' => $a2, 'grantDate' => $m . '-01', 'days' => 10, 'note' => '定期付与')));
check('leave grant: created for A', st($g) === 201 && count_rows('leave_grants', $A) === 1);
$bal = body(SS_Rest_Kintai::get_leave_balance(req(array(), array('staffId' => $a2))));
check('leave balance: 10 granted, 1 used (有休 on the 1st), 9 left, standard days from hire date', $bal['granted'] == 10 && $bal['used'] === 1 && $bal['remaining'] == 9 && $bal['standardGrantDays'] === 20);
$w = body(SS_Rest_Kintai::get_five_day_warnings(req()));
check('five-day rule: warning listed for A\'s staff only', count($w) === 1 && $w[0]['staffName'] === '佐藤' && $w[0]['status'] === 'warning' && $w[0]['shortfall'] === 4);
as_user(3);
check('B cannot see A\'s leave grants or balance', st(SS_Rest_Kintai::get_leave_grants(req(array(), array('staffId' => $a2)))) === 400 && st(SS_Rest_Kintai::get_leave_balance(req(array(), array('staffId' => $a2)))) === 404);
check('B cannot grant leave to A\'s staff', st(SS_Rest_Kintai::create_leave_grant(req(array('staffId' => $a2, 'grantDate' => '2026-01-01', 'days' => 5)))) === 400);
check('B cannot delete A\'s grant', st(SS_Rest_Kintai::delete_leave_grant(req(array(), array('id' => body($g)['id'])))) === 404 && count_rows('leave_grants', $A) === 1);
check('B five-day warnings: none from A', body(SS_Rest_Kintai::get_five_day_warnings(req())) === array());
as_user(2);
$my = body(SS_Rest_Kintai::get_my_leave_balance(req(array(), array('staffId' => $a1))));
check('my leave: always the logged-in staff (ignores staffId)', $my['staffName'] === '佐藤' && count($my['grants']) === 1 && $my['remaining'] == 9);
check('staff cannot use the admin leave endpoints', !SS_Rest_Office::can_admin());

/* ---- 削除 ---- */
SS_System::purge_tenant($A);
check('purge: A\'s time cards, attendance and leave are gone; B untouched', count_rows('time_cards', $A) === 0 && count_rows('attendance_days', $A) === 0 && count_rows('leave_grants', $A) === 0 && count_rows('time_cards', $B) === 1);

echo $failed ? "\n{$failed} FAILED\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
