<?php
/**
 * 自社情報（会社情報・書類・印影）と、経理（顧客・商品・見積もり・請求書・原価計算書）のテスト。
 * お客様A・Bの2社で、別のお客様のデータ・ファイルを読めない・書けない・消せないことを中心に確かめる。
 * 実行：php tests/test-keiri-sqlite.php
 */
require __DIR__ . '/bootstrap-office.php';

$KR = 'SS_Rest_Keiri';
$file = function ($path, $name) { return array('file' => array('tmp_name' => $path, 'name' => $name, 'type' => 'application/octet-stream', 'error' => 0)); };
$png = "$tmp/s.png"; file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
$pdf = "$tmp/a.pdf"; file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
$html = "$tmp/evil.pdf"; file_put_contents($html, "<html><script>alert(1)</script></html>");
$docx = "$tmp/c.docx"; $z = new ZipArchive(); $z->open($docx, ZipArchive::CREATE); $z->addFromString('[Content_Types].xml', '<Types/>'); $z->addFromString('word/document.xml', '<w:document/>'); $z->close();

/* ---- 自社情報 ---- */
as_user(1);
$r = body(SS_Rest_Keiri::save_company_info(req(array('name' => 'A商事', 'bankName' => 'A銀行', 'weeklyHolidays' => array(0, 6, 9, 'x'), 'holidayDates' => array('2026-12-31', 'bad'),
    'breakMinutes' => 9999, 'sealImageUrl' => 'https://evil.example/seal.png', 'employmentInsuranceIndustry' => 'hack'))));
check('company info: saved for A, values cleaned (weekdays 0-6, dates, limits)', $r['name'] === 'A商事' && $r['weeklyHolidays'] === array(0, 6) && $r['holidayDates'] === array('2026-12-31') && $r['breakMinutes'] === 480 && $r['employmentInsuranceIndustry'] === 'general');
check('company info: a seal URL sent by the screen is ignored', $r['sealImageUrl'] === '');
as_user(3);
$rb = body(SS_Rest_Office::company_info(req()));
check('company info: B does not see A\'s info', $rb['name'] === 'B社' && $rb['bankName'] === '');
as_user(2);
check('company info: staff cannot change it', !SS_Rest_Office::can_admin_write());

/* ---- 書類 ---- */
as_user(1);
$d = SS_Rest_Keiri::upload_document(req(array(), array('title' => "契約書'); alert(1);//"), $file($pdf, '契約書.pdf')));
$doc = body($d);
check('document: PDF saved privately, title quotes made harmless', st($d) === 201 && strpos($doc['title'], "'") === false && strpos($doc['fileUrl'], 'ss_file=company_doc') !== false);
$row = $wpdb->get_row("SELECT * FROM wp_shift_company_documents WHERE id = {$doc['id']}", ARRAY_A);
check('document: stored in A\'s private folder with a random name', strpos($row['file_path'], 'docs/') === 0 && is_file(SS_Files::abs_path($A, $row['file_path'])) && strpos($row['file_path'], '契約書') === false);
check('document: Word (docx) accepted', st(SS_Rest_Keiri::upload_document(req(array(), array(), $file($docx, '規則.docx')))) === 201);
check('document: an HTML file named .pdf is refused', st(SS_Rest_Keiri::upload_document(req(array(), array(), $file($html, 'evil.pdf')))) === 400);
parse_str(parse_url($doc['fileUrl'], PHP_URL_QUERY), $q);
$f = SS_Files::resolve($q['ss_file'], $q['id'], $q['exp'], $q['sig']);
check('document: the signed URL opens it (PDF)', $f && $f['mime'] === 'application/pdf');
check('document: another id with the same signature does not open', SS_Files::resolve('company_doc', $q['id'] + 1, $q['exp'], $q['sig']) === null);
check('document: a task-image signature cannot open a document', SS_Files::resolve('task_image', $q['id'], $q['exp'], $q['sig']) === null);
as_user(3);
check('document: B does not see A\'s documents', body(SS_Rest_Keiri::get_documents(req())) === array());
check('document: B cannot delete A\'s document', st(SS_Rest_Keiri::delete_document(req(array(), array('id' => $doc['id'])))) === 404 && is_file(SS_Files::abs_path($A, $row['file_path'])));

/* ---- 印影 ---- */
as_user(1);
$s1 = body(SS_Rest_Keiri::upload_seal(req(array(), array('whitened' => '1'), $file($png, 'seal.png'))));
check('seal: saved; URL is signed; whitened flag kept', strpos($s1['sealImageUrl'], 'ss_file=company_doc') !== false && $s1['sealWhitened'] === true);
check('seal: a PDF is not accepted as a seal', st(SS_Rest_Keiri::upload_seal(req(array(), array(), $file($pdf, 'x.pdf')))) === 400);
$old = $wpdb->get_row("SELECT * FROM wp_shift_company_documents WHERE tenant_id = {$A} AND kind = 'seal'", ARRAY_A);
SS_Rest_Keiri::upload_seal(req(array(), array(), $file($png, 'seal2.png')));
check('seal: replacing removes the old image and row', (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_shift_company_documents WHERE tenant_id = {$A} AND kind = 'seal'") === 1 && !is_file(SS_Files::abs_path($A, $old['file_path'])));
$info = body(SS_Rest_Office::company_info(req()));
check('seal: company info returns the signed seal URL; seal is not in the documents list', strpos($info['sealImageUrl'], 'ss_file=company_doc') !== false && count(body(SS_Rest_Keiri::get_documents(req()))) === 2);
SS_Rest_Keiri::save_company_info(req(array('name' => 'A商事')));
check('seal: saving company info keeps the seal', strpos(body(SS_Rest_Office::company_info(req()))['sealImageUrl'], 'ss_file=') !== false);
as_user(3);
check('seal: B has no seal', body(SS_Rest_Office::company_info(req()))['sealImageUrl'] === '');
SS_Rest_Keiri::delete_seal(req());
check('seal: B deleting "its" seal does not touch A\'s', (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_shift_company_documents WHERE tenant_id = {$A} AND kind = 'seal'") === 1);

/* ---- 顧客・商品 ---- */
as_user(2); // 経理はスタッフも使う
$c = body(SS_Rest_Keiri::upsert_client(req(array('name' => '顧客X', 'projects' => "案件1\n案件2"))));
check('client: created by staff', $c['name'] === '顧客X' && $c['projects'] === "案件1\n案件2");
$pr = body(SS_Rest_Keiri::upsert_product(req(array('name' => '部品', 'cost' => '100.555', 'price' => 'abc'))));
check('product: numbers cleaned', $pr['cost'] == 100.56 && $pr['price'] == 0);
as_user(3);
check('B sees none of A\'s clients/products', body(SS_Rest_Keiri::get_clients(req())) === array() && body(SS_Rest_Keiri::get_products(req())) === array());
check('B cannot update A\'s client (by id)', st(SS_Rest_Keiri::upsert_client(req(array('id' => $c['id'], 'name' => '乗っ取り')))) === 404 && $wpdb->get_var("SELECT name FROM wp_shift_clients WHERE id = {$c['id']}") === '顧客X');
check('B cannot delete A\'s product', st(SS_Rest_Keiri::delete_product(req(array(), array('id' => $pr['id'])))) === 404 && count_rows('products', $A) === 1);

/* ---- 原価計算書 → 見積もり → 請求書 ---- */
as_user(2);
$cost = body(SS_Rest_Keiri::upsert_cost(req(array('clientId' => $c['id'], 'clientName' => '顧客X', 'staffId' => $a1, 'staffName' => 'なりすまし', 'items' => array(array('name' => '部品', 'quantity' => 3, 'unitPrice' => 100.5, 'refPrice' => '150'))))));
$ym = gmdate('Ym');
check('cost: numbered per company (C' . $ym . '-001), roster name used for the staff', $cost['reportNo'] === 'C' . $ym . '-001' && $cost['staffName'] === '山田' && $cost['costTotal'] === 302 && $cost['items'][0]['refPrice'] == 150);
$q1 = body(SS_Rest_Keiri::upsert_quote(req(array('clientId' => $c['id'], 'costNo' => $cost['reportNo'], 'items' => array(array('name' => '作業', 'quantity' => 2, 'unitPrice' => 1000))))));
check('quote: total includes 10% tax; numbered Q' . $ym . '-001', $q1['quoteNo'] === 'Q' . $ym . '-001' && $q1['totalAmount'] === 2200);
check('quote from a cost report: the report gets the quote number', $wpdb->get_var("SELECT quote_no FROM wp_shift_costs WHERE id = {$cost['id']}") === $q1['quoteNo']);
$inv = body(SS_Rest_Keiri::upsert_invoice(req(array('quoteNo' => $q1['quoteNo'], 'items' => array(array('name' => '作業', 'quantity' => 1, 'unitPrice' => 999))))));
check('invoice: numbered, linked back to the cost report', $inv['invoiceNo'] === 'I' . $ym . '-001' && $wpdb->get_var("SELECT invoice_no FROM wp_shift_costs WHERE id = {$cost['id']}") === $inv['invoiceNo']);
$q2 = body(SS_Rest_Keiri::upsert_quote(req(array('items' => array()))));
SS_Rest_Keiri::delete_quote(req(array(), array('id' => $q1['id'])));
$q3 = body(SS_Rest_Keiri::upsert_quote(req(array('items' => array()))));
check('quote numbers do not repeat after a deletion', $q2['quoteNo'] === 'Q' . $ym . '-002' && $q3['quoteNo'] === 'Q' . $ym . '-003');
as_user(3);
$qb = body(SS_Rest_Keiri::upsert_quote(req(array('clientId' => $c['id'], 'clientName' => 'X', 'staffId' => $a1, 'staffName' => '手入力', 'costNo' => $cost['reportNo'], 'items' => array()))));
check('B: own numbering starts at 001; A\'s client/staff ids are not linked', $qb['quoteNo'] === 'Q' . $ym . '-001' && $qb['clientId'] === '' && $qb['staffId'] === '' && $qb['staffName'] === '手入力');
check('B: A\'s cost report was not linked to B\'s quote', $wpdb->get_var("SELECT quote_no FROM wp_shift_costs WHERE id = {$cost['id']}") === $q1['quoteNo']);
check('B sees only its own quotes, none of A\'s invoices/costs', count(body(SS_Rest_Keiri::get_quotes(req()))) === 1 && body(SS_Rest_Keiri::get_invoices(req())) === array() && body(SS_Rest_Keiri::get_costs(req())) === array());
check('B cannot update or delete A\'s invoice', st(SS_Rest_Keiri::upsert_invoice(req(array('id' => $inv['id'], 'items' => array())))) === 404 && st(SS_Rest_Keiri::delete_invoice(req(array(), array('id' => $inv['id'])))) === 404 && count_rows('invoices', $A) === 1);
$wpdb->update('wp_shift_tenants', array('status' => 'readonly'), array('id' => $B));
SS_Context::reset();
check('read-only company: cannot write accounting data', !SS_Rest_Office::can_write());
$wpdb->update('wp_shift_tenants', array('status' => 'trial'), array('id' => $B));
SS_Context::reset();

/* ---- 操作ログ ---- */
check('log: actor is the logged-in person', $wpdb->get_var("SELECT actor_name FROM wp_shift_activity_log WHERE tenant_id = {$A} AND action = '原価計算書新規作成'") === '佐藤');

/* ---- 削除 ---- */
$dir = SS_Files::base_dir() . $A;
SS_System::purge_tenant($A);
check('purge: A\'s documents, seal files, clients, quotes, invoices, costs are gone; B untouched', count_rows('company_documents', $A) === 0 && count_rows('clients', $A) === 0 && count_rows('quotes', $A) === 0 && count_rows('invoices', $A) === 0 && count_rows('costs', $A) === 0 && !is_dir($dir) && count_rows('quotes', $B) === 1);

echo $failed ? "\n{$failed} FAILED\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
