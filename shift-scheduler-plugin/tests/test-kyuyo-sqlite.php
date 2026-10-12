<?php
/**
 * 給与（従業員の給与設定・税額表・給与明細・年末調整・本人の申告と照会）のテスト。
 * お客様A・Bの2社で、別のお客様の給与データを読めない・書けない・消せないこと、
 * 副管理者は許可がなければ給与を扱えないこと、スタッフは自分の分しか見られないことを確かめる。
 * 実行：php tests/test-kyuyo-sqlite.php
 */
require __DIR__ . '/bootstrap-office.php';

// A社の副管理者（wp4）
$wpdb->insert('wp_shift_users', array('tenant_id' => $A, 'wp_user_id' => 4, 'email' => 'u4@example.test', 'role' => 'manager', 'staff_id' => null, 'status' => 'active', 'created_at' => $now));
$GLOBALS['wp_roles'][4] = 'manager';
$route = function ($path) { return '/mo/v1/' . $path; };
$treq = function ($path, $json = array(), $query = array(), $files = array()) use ($route) { return new WP_REST_Request($json, $query, $files, $route($path)); };

/* ---- 権限 ---- */
as_user(1);
check('owner: can use payroll', SS_Rest_Kyuyo::can_payroll() && SS_Rest_Kyuyo::can_payroll_write());
as_user(4);
check('manager: payroll is off unless the owner allows it', SS_Rest_Office::can_admin() && !SS_Rest_Kyuyo::can_payroll());
SS_Tenants::save_settings($A, array('manager_payroll' => true)); SS_Context::reset();
check('manager: allowed after the owner turns it on', SS_Rest_Kyuyo::can_payroll());
SS_Tenants::save_settings($A, array('manager_payroll' => false)); SS_Context::reset();
as_user(2);
check('staff: no payroll', !SS_Rest_Kyuyo::can_payroll());

/* ---- 管理者権限（副管理者）の付与・解除 ---- */
as_user(4);
check('admin grant: a manager cannot grant', st(SS_Rest_Office::set_admin(req(array('isAdmin' => true), array('id' => $a2)))) === 403);
as_user(1);
check('admin grant: someone without a login account is refused with guidance', st(SS_Rest_Office::set_admin(req(array('isAdmin' => true), array('id' => $a1)))) === 400);
check('admin grant: owner makes the staff a manager', st(SS_Rest_Office::set_admin(req(array('isAdmin' => true), array('id' => $a2)))) === 200 && $wpdb->get_var("SELECT role FROM wp_shift_users WHERE wp_user_id = 2") === 'manager');
$e = array_values(array_filter(body(SS_Rest_Office::employees(req())), function ($e) use ($a2) { return $e['id'] === (string) $a2; }))[0];
check('admin grant: shown as 管理者 in the list', $e['is_admin'] === '1');
check('admin grant: revoke back to staff', st(SS_Rest_Office::set_admin(req(array('isAdmin' => false), array('id' => $a2)))) === 200 && $wpdb->get_var("SELECT role FROM wp_shift_users WHERE wp_user_id = 2") === 'staff');
as_user(3);
check('admin grant: B cannot touch A\'s staff', st(SS_Rest_Office::set_admin(req(array('isAdmin' => true), array('id' => $a2)))) === 404 && $wpdb->get_var("SELECT role FROM wp_shift_users WHERE wp_user_id = 2") === 'staff');

/* ---- 従業員の詳細・給与設定 ---- */
as_user(1);
check('profile: saved (name/department in the roster, address etc. in the profile)', st(SS_Rest_Kyuyo::save_profile(req(array('name' => '佐藤花子', 'department' => '製作', 'address' => '東京都', 'bankName' => 'A銀行', 'hireDate' => '2021-04-01'), array('id' => $a2)))) === 200
    && $wpdb->get_var("SELECT name FROM wp_shift_staff WHERE id = {$a2}") === '佐藤花子' && $wpdb->get_var("SELECT hire_date FROM wp_shift_staff WHERE id = {$a2}") === '2021-04-01');
check('wage: invalid type refused', st(SS_Rest_Kyuyo::save_wage(req(array('wageType' => '年俸', 'wageRate' => 1), array('id' => $a2)))) === 400);
SS_Rest_Kyuyo::save_wage(req(array('wageType' => '時給', 'wageRate' => '1200'), array('id' => $a2)));
SS_Rest_Kyuyo::save_allowances(req(array('commuteAllowance' => 5000, 'commuteTaxable' => 0, 'other1Label' => '皆勤', 'other1Amount' => 3000, 'other1Taxable' => 1), array('id' => $a2)));
SS_Rest_Kyuyo::save_deductions(req(array('residentTax' => 8000, 'dependents' => 2), array('id' => $a2)));
$emp = body(SS_Rest_Office::employees(req()));
$e2 = array_values(array_filter($emp, function ($e) use ($a2) { return $e['id'] === (string) $a2; }))[0];
check('employees (owner): profile and pay settings are included', $e2['address'] === '東京都' && $e2['wage_type'] === '時給' && $e2['wage_rate'] === 1200 && $e2['commute_allowance'] === 5000 && $e2['commute_taxable'] === 0
    && $e2['other1_label'] === '皆勤' && $e2['resident_tax_fixed'] === 8000 && $e2['dependents_count'] === 2 && $e2['hire_date'] === '2021-04-01' && $e2['address'] === '東京都');
as_user(4);
$e2m = array_values(array_filter(body(SS_Rest_Office::employees(req())), function ($e) use ($a2) { return $e['id'] === (string) $a2; }))[0];
check('employees (manager without payroll): address shown, pay settings hidden', $e2m['address'] === '東京都' && $e2m['wage_rate'] === 0 && $e2m['resident_tax_fixed'] === 0);
as_user(2);
check('employees (staff): no profile or pay fields', !isset(body(SS_Rest_Office::employees(req()))[0]['wage_rate']));
as_user(3);
check('B cannot change A\'s staff pay settings or profile', st(SS_Rest_Kyuyo::save_wage(req(array('wageType' => '月給', 'wageRate' => 1), array('id' => $a2)))) === 404
    && st(SS_Rest_Kyuyo::save_profile(req(array('name' => 'x'), array('id' => $a2)))) === 404 && $wpdb->get_var("SELECT name FROM wp_shift_staff WHERE id = {$a2}") === '佐藤花子');

/* ---- 税額表・保険料額表 ---- */
as_user(1);
check('tax table: data without the required part is refused', st(SS_Rest_Kyuyo::upload_table($treq('tax-tables', array(), array('fiscalYear' => 2026, 'dataJson' => '{"x":1}')))) === 400);
$t1 = body(SS_Rest_Kyuyo::upload_table($treq('tax-tables', array(), array('fiscalYear' => 2026, 'title' => '月額表', 'dataJson' => '{"brackets":[[0,88000,0]]}'))));
$ins = body(SS_Rest_Kyuyo::upload_table($treq('insurance-tables', array(), array('fiscalYear' => 2026, 'region' => '栃木', 'title' => '協会けんぽ', 'tiersJson' => '[{"grade":1}]'))));
check('tables: saved for A (tax data / insurance tiers)', $t1['data']['brackets'][0][1] === 88000 && $ins['region'] === '栃木' && $ins['tiers'][0]['grade'] === 1);
check('tables: listed by kind', count(body(SS_Rest_Kyuyo::get_tables($treq('tax-tables')))) === 1 && count(body(SS_Rest_Kyuyo::get_tables($treq('insurance-tables')))) === 1);
as_user(3);
check('B sees none of A\'s tables and cannot delete them', body(SS_Rest_Kyuyo::get_tables($treq('tax-tables'))) === array() && st(SS_Rest_Kyuyo::delete_table($treq('tax-tables/' . $t1['id'], array(), array('id' => $t1['id'])))) === 404);
// 運営者が、A社の表を全お客様の共通の表にする
check('operator: share A\'s tables with everyone', SS_System::share_pay_tables($A) === 2);
$bt = body(SS_Rest_Kyuyo::get_tables($treq('tax-tables')));
check('B now reads the shared table (marked, read-only)', count($bt) === 1 && $bt[0]['shared'] === true && strpos($bt[0]['title'], '【共通】') === 0 && $bt[0]['data']['brackets'][0][1] === 88000);
check('B cannot delete a shared table', st(SS_Rest_Kyuyo::delete_table($treq('tax-tables/' . $bt[0]['id'], array(), array('id' => $bt[0]['id'])))) === 404 && (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_shift_pay_tables WHERE tenant_id = 0') === 2);
check('operator: sharing again replaces instead of duplicating', SS_System::share_pay_tables($A) === 2 && (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_shift_pay_tables WHERE tenant_id = 0') === 2);
check('shared table rows cannot be opened through a file URL', SS_System::pay_table_by_id($bt[0]['id']) === null);
as_user(1);
check('a tax-table delete URL cannot delete an insurance table', st(SS_Rest_Kyuyo::delete_table($treq('tax-tables/' . $ins['id'], array(), array('id' => $ins['id'])))) === 404);

/* ---- 給与明細 ---- */
as_user(1);
$r = SS_Rest_Kyuyo::save_payslip(req(array('staffName' => 'なりすまし', 'periodStart' => '2026-09-01', 'periodEnd' => '2026-09-30', 'data' => array('net' => 200000)), array('staffId' => $a2, 'year' => 2026, 'month' => 9)));
$ps = $wpdb->get_row("SELECT * FROM wp_shift_payslips WHERE tenant_id = {$A}", ARRAY_A);
check('payslip: saved with the roster name and number', st($r) === 200 && $ps['staff_name'] === '佐藤花子' && $ps['employee_number'] === '102');
SS_Rest_Kyuyo::save_payslip(req(array('data' => array('net' => 200000)), array('staffId' => $a2, 'year' => 2026, 'month' => 9)));
check('payslip: same content again → no history', count_rows('payslip_history', $A) === 0);
SS_Rest_Kyuyo::save_payslip(req(array('data' => array('net' => 210000)), array('staffId' => $a2, 'year' => 2026, 'month' => 9)));
$h = body(SS_Rest_Kyuyo::get_history(req(array(), array('staffId' => $a2, 'year' => 2026, 'month' => 9))));
check('payslip: changed content → old version kept in history', count($h) === 1 && $h[0]['data']['net'] === 200000 && $h[0]['reason'] === '更新');
check('payslip: get returns the latest', body(SS_Rest_Kyuyo::get_payslip(req(array(), array('staffId' => $a2, 'year' => 2026, 'month' => 9))))['data']['net'] === 210000);
check('payslip: too large content refused', st(SS_Rest_Kyuyo::save_payslip(req(array('data' => array('x' => str_repeat('a', 500000))), array('staffId' => $a2, 'year' => 2026, 'month' => 10)))) === 400);
SS_Rest_Kyuyo::save_payslip(req(array('data' => array('net' => 1)), array('staffId' => $a2, 'year' => 2026, 'month' => 3)));
check('payslips list (before month 9): only earlier months', count(body(SS_Rest_Kyuyo::get_payslips(req(array(), array('staffId' => $a2, 'year' => 2026, 'beforeMonth' => 9))))) === 1);
as_user(3);
check('B cannot read A\'s payslip, list or history', body(SS_Rest_Kyuyo::get_payslip(req(array(), array('staffId' => $a2, 'year' => 2026, 'month' => 9)))) === false
    && st(SS_Rest_Kyuyo::get_payslips(req(array(), array('staffId' => $a2, 'year' => 2026)))) === 400 && body(SS_Rest_Kyuyo::get_history(req(array(), array('staffId' => $a2, 'year' => 2026, 'month' => 9)))) === array());
check('B cannot write a payslip for A\'s staff', st(SS_Rest_Kyuyo::save_payslip(req(array('data' => array()), array('staffId' => $a2, 'year' => 2026, 'month' => 9)))) === 400 && count_rows('payslips', $B) === 0);
check('B cannot delete A\'s payslip', st(SS_Rest_Kyuyo::delete_payslip(req(array(), array('id' => $ps['id'])))) === 404 && count_rows('payslips', $A) === 2);

/* ---- 本人の照会 ---- */
as_user(2);
$mine = body(SS_Rest_Kyuyo::my_payslips(req(array(), array('staffId' => $a1))));
check('my payslips: the logged-in staff\'s own, newest first', count($mine) === 2 && $mine[0]['payMonth'] === 9);
check('my payslip detail', body(SS_Rest_Kyuyo::my_payslip(req(array(), array('year' => 2026, 'month' => 9))))['data']['net'] === 210000);
as_user(3); // B社の管理者（B社のスタッフに紐づく）
check('B\'s person sees none of A\'s payslips', body(SS_Rest_Kyuyo::my_payslips(req())) === array());
as_user(1); // 名簿に紐づかない管理者
check('my payslips without a roster link: clear refusal', st(SS_Rest_Kyuyo::my_payslips(req())) === 400);

/* ---- 年末調整・申告 ---- */
as_user(2);
$d = body(SS_Rest_Kyuyo::save_my_decl(req(array('data' => array('prevWages' => '12,345円', 'spouse' => 'hack', 'student' => 1, 'evil' => '<script>')), array('year' => 2026))));
check('my declaration: cleaned (digits only, allowed choices, unknown keys dropped)', $d['data']['prevWages'] === '12345' && $d['data']['spouse'] === 'none' && $d['data']['student'] === true && !isset($d['data']['evil']) && $d['staffName'] === '佐藤花子');
as_user(1);
check('admin sees the declaration list and detail', count(body(SS_Rest_Kyuyo::list_decl(req(array(), array('year' => 2026))))) === 1 && body(SS_Rest_Kyuyo::get_decl(req(array(), array('staffId' => $a2, 'year' => 2026))))['data']['prevWages'] === '12345');
$ye = body(SS_Rest_Kyuyo::save_year_end(req(array('applyMonth' => 13, 'adjustment' => -5000, 'data' => array('a' => 1)), array('staffId' => $a2, 'year' => 2026))));
check('year-end: saved (bad month → 12)', $ye['applyMonth'] === 12 && $ye['adjustment'] === -5000 && $ye['data']['a'] === 1);
as_user(3);
check('B cannot read A\'s year-end or declarations', body(SS_Rest_Kyuyo::get_year_end(req(array(), array('staffId' => $a2, 'year' => 2026)))) === false && body(SS_Rest_Kyuyo::list_decl(req(array(), array('year' => 2026)))) === array()
    && body(SS_Rest_Kyuyo::list_year_end(req(array(), array('year' => 2026)))) === array());
SS_Rest_Kyuyo::delete_year_end(req(array(), array('staffId' => $a2, 'year' => 2026)));
check('B deleting A\'s year-end does nothing', count_rows('year_end', $A) === 1);

/* ---- 削除 ---- */
SS_System::purge_tenant($A);
check('purge: A\'s payroll data are gone; shared tables stay', count_rows('payslips', $A) === 0 && count_rows('payslip_history', $A) === 0 && count_rows('year_end', $A) === 0 && count_rows('year_end_decl', $A) === 0 && count_rows('pay_tables', $A) === 0
    && (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_shift_pay_tables WHERE tenant_id = 0') === 2);

echo $failed ? "\n{$failed} FAILED\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
