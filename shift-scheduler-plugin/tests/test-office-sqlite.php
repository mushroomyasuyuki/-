<?php
/**
 * 日報・予定進捗・操作ログ（マッシュオフィスシステムから移したAPI）と、名簿のCSV、非公開ファイルのテスト。
 * お客様A・Bの2社で、別のお客様のデータを「読めない・書けない・消せない」ことを中心に確かめる。
 * 実行：php tests/test-office-sqlite.php
 */
require __DIR__ . '/bootstrap-office.php';

/* ---- 権限 ---- */
as_user(0);
check('not logged in: no access', !SS_Rest_Office::can_use() && !SS_Rest_Office::can_write());
as_user(2);
check('staff: can use and write, but not admin-only actions', SS_Rest_Office::can_use() && SS_Rest_Office::can_write() && !SS_Rest_Office::can_admin() && !SS_Rest_Office::can_admin_write());
as_user(1);
check('owner: admin actions allowed', SS_Rest_Office::can_admin_write());
$wpdb->update('wp_shift_tenants', array('status' => 'readonly'), array('id' => $A));
SS_Context::reset();
check('read-only company: reading allowed, writing blocked', SS_Rest_Office::can_use() && !SS_Rest_Office::can_write() && !SS_Rest_Office::can_admin_write());
$wpdb->update('wp_shift_tenants', array('status' => 'trial'), array('id' => $A));
SS_Context::reset();

/* ---- ログイン情報・名簿・部署 ---- */
as_user(2);
$r = body(SS_Rest_Office::auth_login(req()));
check('auth/login returns the logged-in staff (not someone chosen by the screen)', $r['staffId'] === (string) $a2 && $r['staffName'] === '佐藤' && $r['isAdmin'] === false);
as_user(1);
check('auth/login: owner without a staff link gets a clear message', st(SS_Rest_Office::auth_login(req(array('staffId' => $a1)))) === 403);
$emp = body(SS_Rest_Office::employees(req()));
check('employees: only A\'s staff, sorted by number, admin sees account info', count($emp) === 2 && $emp[0]['name'] === '山田' && $emp[1]['name'] === '佐藤' && isset($emp[0]['has_login_password']));
as_user(2);
$emp = body(SS_Rest_Office::employees(req()));
check('employees: staff see only the basic fields', !isset($emp[0]['has_login_password']) && !isset($emp[0]['kana']));
$dep = body(SS_Rest_Office::departments(req()));
check('departments: built from A\'s roster only', count($dep) === 2 && $dep[0]['name'] === '営業' && $dep[1]['name'] === '製作' && $dep[0]['employeeCount'] === 1);
check('company-name: A\'s name', body(SS_Rest_Office::company_name(req()))['name'] === 'A社');

/* ---- 日報：保存・更新（重複防止）・代理登録 ---- */
as_user(2);
$base = array('date' => '2026-10-20', 'staffId' => $a2, 'category' => '製作', 'client' => '顧客X', 'project' => '案件1', 'start' => '09:00', 'end' => '12:00', 'status' => '予定');
$r = SS_Rest_Office::upsert_task_record(req($base + array('staffName' => '偽名', 'dept' => '偽部署')));
$t1 = body($r);
check('upsert: saved; name/department come from the roster (not from the screen)', st($r) === 200 && $t1['staffName'] === '佐藤' && $t1['dept'] === '製作');
$r = body(SS_Rest_Office::upsert_task_record(req(array('status' => '進行中', 'progress' => 50) + $base)));
check('upsert: same day/staff/client/project updates the same record', $r['id'] === $t1['id'] && $r['status'] === '進行中' && count_rows('task_records', $A) === 1);
$r = SS_Rest_Office::upsert_task_record(req(array('staffId' => $a1, 'client' => '顧客Y') + $base));
check('upsert: proxy entry for a colleague in the same company is allowed (as before)', st($r) === 200 && body($r)['staffId'] === (string) $a1);
check('upsert: a staff id from ANOTHER company is refused', st(SS_Rest_Office::upsert_task_record(req(array('staffId' => $b1) + $base))) === 400);
check('upsert: bad date refused', st(SS_Rest_Office::upsert_task_record(req(array('date' => '20261020') + $base))) === 400);
$bulk = body(SS_Rest_Office::upsert_task_record(req(array('bulkMode' => true, 'bulkCount' => '5', 'category' => '事務') + $base)));
$bulk2 = body(SS_Rest_Office::upsert_task_record(req(array('bulkMode' => true, 'bulkCount' => '3', 'category' => '事務') + $base)));
check('upsert: bulk entries are always added (client/project empty)', $bulk['id'] !== $bulk2['id'] && $bulk['client'] === null);
check('log: actions are recorded, proxy entries marked', count_rows('activity_log', $A) >= 3 && (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_shift_activity_log WHERE tenant_id = {$A} AND detail LIKE '代理操作%'") >= 1);

/* ---- 日報：B社からは見えない・変えられない ---- */
as_user(3);
$list = body(SS_Rest_Office::get_task_records(req(array(), array('dateFrom' => '2026-01-01'))));
check('B cannot see A\'s records', $list === array());
check('B cannot update A\'s record', st(SS_Rest_Office::admin_update_task_record(req(array('status' => '完了'), array('id' => $t1['id'])))) === 404);
check('B cannot delete A\'s record', st(SS_Rest_Office::delete_task_record(req(array(), array('id' => $t1['id'])))) === 404 && count_rows('task_records', $A) === 4);
check('B cannot duplicate A\'s record', st(SS_Rest_Office::duplicate_task_record(req(array(), array('id' => $t1['id'])))) === 404);
check('B cannot add an image to A\'s record', st(SS_Rest_Office::upload_task_image(req(array(), array('id' => $t1['id'])))) === 404);
check('B sees no A activity log', body(SS_Rest_Office::get_activity_log(req())) === array());
check('B employees: only B\'s staff', count(body(SS_Rest_Office::employees(req()))) === 1);

/* ---- 日報：絞り込み・並び順 ---- */
as_user(1);
SS_Rest_Office::upsert_task_record(req(array('date' => '2026-10-21', 'start' => '08:00', 'client' => '顧客Z') + $base));
$l = body(SS_Rest_Office::get_task_records(req(array(), array('dateFrom' => '2026-10-21', 'dateTo' => '2026-10-31'))));
check('filter by date range', count($l) === 1 && $l[0]['date'] === '2026-10-21');
$l = body(SS_Rest_Office::get_task_records(req(array(), array('staffId' => $a1))));
check('filter by staff', count($l) === 1 && $l[0]['staffId'] === (string) $a1);
$l = body(SS_Rest_Office::get_task_records(req()));
check('order: newest date first, then start time', $l[0]['date'] === '2026-10-21' && $l[1]['date'] === '2026-10-20');

/* ---- 日報：管理者の編集・重複の確認 ---- */
as_user(2);
$r = body(SS_Rest_Office::admin_update_task_record(req(array('client' => '書き換え', 'status' => '完了'), array('id' => $t1['id']))));
check('admin-update by staff: status changes, but client (admin-only) does not', $r['status'] === '完了' && $r['client'] === '顧客X');
as_user(1);
$other = body(SS_Rest_Office::upsert_task_record(req(array('client' => '顧客W') + $base)));
check('admin-update: moving onto an existing day/client/project is refused (409)', st(SS_Rest_Office::admin_update_task_record(req(array('client' => '顧客X'), array('id' => $other['id'])))) === 409);
$dup = SS_Rest_Office::duplicate_task_record(req(array(), array('id' => $t1['id'])));
check('duplicate: renamed with (複製) to avoid a clash', st($dup) === 201 && body($dup)['project'] === '案件1(複製)');
as_user(2);
check('staff cannot delete (admin only, as before)', !SS_Rest_Office::can_admin_write());

/* ---- 写真（非公開ファイル・署名つきURL） ---- */
as_user(2);
$png = "$tmp/p.png"; file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
$r = SS_Rest_Office::upload_task_image(req(array(), array('id' => $t1['id']), array('file' => array('tmp_name' => $png, 'name' => 'photo.png', 'type' => 'image/png', 'error' => 0))));
$img = body($r)['images'][0];
check('image: stored and returned with a signed URL', st($r) === 201 && strpos($img['url'], 'ss_file=task_image') !== false && strpos($img['url'], 'sig=') !== false);
$row = $wpdb->get_row("SELECT * FROM wp_shift_task_images WHERE id = " . (int) $img['id'], ARRAY_A);
$abs = SS_Files::abs_path($A, $row['file_path']);
check('image: saved inside A\'s private folder with a random name, folder denies direct access', strpos($abs, "/mush-office-tenants/{$A}/task_images/") !== false && is_file($abs) && strpos(file_get_contents(SS_Files::base_dir() . '.htaccess'), 'Deny') !== false && strpos($row['file_path'], 'photo') === false);
check('image: completed task → photo gets a deletion date (30 days)', !empty($row['expires_at']));
parse_str(parse_url($img['url'], PHP_URL_QUERY), $q);
check('signed URL resolves to the file', SS_Files::resolve($q['ss_file'], $q['id'], $q['exp'], $q['sig'])['path'] === $abs);
check('tampered signature is refused', SS_Files::resolve($q['ss_file'], $q['id'], $q['exp'], str_repeat('0', 64)) === null);
check('signature cannot be reused for another image id', SS_Files::resolve($q['ss_file'], $q['id'] + 1, $q['exp'], $q['sig']) === null);
check('expired URL is refused', SS_Files::resolve($q['ss_file'], $q['id'], time() - 10, SS_Files::sign($A, 'task_image', $q['id'], time() - 10)) === null);
check('a signature made for another company does not open A\'s image', SS_Files::resolve($q['ss_file'], $q['id'], $q['exp'], SS_Files::sign($B, 'task_image', $q['id'], $q['exp'])) === null);
$fake = "$tmp/fake.png"; file_put_contents($fake, "<?php echo 'not an image'; ?>");
check('image: a non-image disguised as .png is refused', st(SS_Rest_Office::upload_task_image(req(array(), array('id' => $t1['id']), array('file' => array('tmp_name' => $fake, 'name' => 'x.png', 'type' => 'image/png', 'error' => 0))))) === 400);
as_user(3);
check('B cannot delete A\'s image', st(SS_Rest_Office::delete_task_image(req(array(), array('id' => $img['id'])))) === 404 && is_file($abs));
as_user(1);
SS_Rest_Office::delete_task_record(req(array(), array('id' => $t1['id'])));
check('deleting a record also deletes its photos (file and row)', !is_file($abs) && count_rows('task_images', $A) === 0);

/* ---- 予定進捗 ---- */
as_user(2);
$s = body(SS_Rest_Office::upsert_schedule_task(req(array('date' => '2026-10-20', 'staffId' => $a2, 'name' => '図面', 'progress' => 30))));
check('schedule: created with roster name', $s['staffName'] === '佐藤' && $s['progress'] === 30);
$s2 = body(SS_Rest_Office::upsert_schedule_task(req(array('id' => $s['id'], 'name' => '図面', 'progress' => 80))));
check('schedule: updated by id', $s2['id'] === $s['id'] && $s2['progress'] === 80);
as_user(3);
check('B cannot update A\'s schedule task', st(SS_Rest_Office::upsert_schedule_task(req(array('id' => $s['id'], 'name' => 'x')))) === 404);
check('B cannot delete A\'s schedule task', st(SS_Rest_Office::delete_schedule_task(req(array(), array('id' => $s['id'])))) === 404 && count_rows('schedule_tasks', $A) === 1);
check('B sees none of A\'s schedule tasks', body(SS_Rest_Office::get_schedule_tasks(req())) === array());
check('B cannot create a schedule task for A\'s staff', st(SS_Rest_Office::upsert_schedule_task(req(array('date' => '2026-10-20', 'staffId' => $a2, 'name' => 'x')))) === 400);

/* ---- 操作ログ（画面からの記録） ---- */
as_user(2);
SS_Rest_Office::post_activity_log(req(array('action' => '印刷', 'actorName' => 'なりすまし', 'targetLabel' => '日報')));
check('activity-log POST: actor is the logged-in person, not the sent name', $wpdb->get_var("SELECT actor_name FROM wp_shift_activity_log WHERE tenant_id = {$A} AND action = '印刷'") === '佐藤');

/* ---- 名簿のCSV ---- */
as_user(1);
$ex = SS_Rest::export_staff();
check('export: header and A\'s staff only', strpos($ex['csv'], '"社員番号","名前"') === 0 && strpos($ex['csv'], '山田') !== false && strpos($ex['csv'], '鈴木') === false);
SS_Rest::create_staff(req(array('name' => '=HYPERLINK("x")', 'employee_number' => '103')));
check('export: values that Excel would run as formulas are neutralised', strpos(SS_Rest::export_staff()['csv'], '"\'=HYPERLINK(""x"")"') !== false);
$head = array('社員番号', '氏名', 'フリガナ', '部署', '雇用形態', '役割', '資格');
$r = SS_Rest::import_staff(req(array('dry_run' => true, 'rows' => array($head, array('１０１', '山田太郎', 'やまだ', '営業部', '正社員', 'リーダー、営業', ''), array('', '新人', '', '製作', 'アルバイト', '', '')))));
check('import dry run: 1 update (by number, full-width ok) + 1 create; nothing applied', $r['updated'] === 1 && $r['created'] === 1 && !$r['applied'] && !$r['errors'] && $wpdb->get_var("SELECT name FROM wp_shift_staff WHERE id = {$a1}") === '山田');
$r = SS_Rest::import_staff(req(array('rows' => array($head, array('101', '山田太郎', 'やまだ', '営業部', '正社員', 'リーダー、営業', ''), array('', '新人', '', '製作', 'アルバイト', '', '')))));
$y = $wpdb->get_row("SELECT * FROM wp_shift_staff WHERE id = {$a1}", ARRAY_A);
check('import: applied; update keeps the same person (id) and sets fields', $r['applied'] && $y['name'] === '山田太郎' && $y['department'] === '営業部' && $y['employment'] === 'full' && json_decode($y['roles'], true) === array('リーダー', '営業'));
check('import: did not touch company B', $wpdb->get_var("SELECT name FROM wp_shift_staff WHERE id = {$b1}") === '鈴木' && count_rows('staff', $B) === 1);
$before = count_rows('staff', $A);
$r = SS_Rest::import_staff(req(array('rows' => array($head, array('201', 'OK', '', '', '', '', ''), array('202', '', '', '', '', '', ''), array('203', 'X', '', '', '契約社員', '', ''), array('201', 'Y', '', '', '', '', '')))));
check('import: any error → nothing changed, each error reported with its line', !$r['applied'] && count($r['errors']) === 3 && $r['errors'][0]['line'] === 3 && count_rows('staff', $A) === $before);
check('import: a header row is required', is_wp_error(SS_Rest::import_staff(req(array('rows' => array(array('101', '山田'), array('102', '佐藤')))))));
$GLOBALS['opts']['x'] = 1;
$rows = array($head); for ($i = 0; $i < 199; $i++) { $rows[] = array('9' . $i, '人' . $i, '', '', '', '', ''); }
$r = SS_Rest::import_staff(req(array('dry_run' => true, 'rows' => $rows)));
check('import: staff limit is checked before applying', !empty($r['errors']) && strpos($r['errors'][0]['message'], '上限') !== false);
as_user(2);
check('import: staff (non-manager) cannot import', !SS_Rest::can_write_staff());

/* ---- 解約後の削除で、日報なども消える ---- */
as_user(1);
SS_Rest_Office::upsert_task_record(req($base));
$t = body(SS_Rest_Office::get_task_records(req()))[0];
SS_Rest_Office::upload_task_image(req(array(), array('id' => $t['id']), array('file' => array('tmp_name' => $png, 'name' => 'a.png', 'type' => 'image/png', 'error' => 0))));
$dir = SS_Files::base_dir() . $A;
SS_System::purge_tenant($A);
check('purge: A\'s records, photos, schedule, log and files are gone; B untouched', count_rows('task_records', $A) === 0 && count_rows('task_images', $A) === 0 && count_rows('schedule_tasks', $A) === 0 && count_rows('activity_log', $A) === 0 && !is_dir($dir) && count_rows('staff', $B) === 1);

/* ---- データ窓口：条件の書き方 ---- */
$repo = SS_Repo::for_tenant($B);
$threw = false; try { $repo->all('staff', array('name; DROP TABLE x' => 1)); } catch (SS_Repo_Exception $e) { $threw = true; }
check('repo: malformed condition keys are rejected', $threw);
$threw = false; try { $repo->all('staff', array('name LIKE' => '%')); } catch (SS_Repo_Exception $e) { $threw = true; }
check('repo: unsupported operators are rejected', $threw);
check('repo: empty IN matches nothing', $repo->all('staff', array('id IN' => array())) === array());
check('repo: tenant condition cannot be overridden by the caller', count($repo->all('staff', array('tenant_id' => $A))) === 1);

echo $failed ? "\n{$failed} FAILED\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
