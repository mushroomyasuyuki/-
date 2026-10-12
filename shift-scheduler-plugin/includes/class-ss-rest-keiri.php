<?php
/**
 * 自社情報（会社情報・書類・印影）と、経理（顧客・商品・見積もり・請求書・原価計算書）のAPI（/wp-json/mo/v1/…）。
 * マッシュオフィスシステムの mush-office/v1 と同じ形で返す。
 *
 * マッシュオフィスシステムとの違い：
 *  - すべて、ログインした人の、お客様の分だけ（SS_Repo）。番号（見積もりNOなど）も、お客様ごとに振る。
 *  - 書類・印影は、だれでも開ける場所（メディアライブラリ）ではなく、お客様ごとの非公開フォルダに保存し、
 *    署名つきの、有効期限のあるURLで渡す。
 *  - 担当者・作業者・顧客のIDは、同じお客様の名簿・顧客一覧にあるものだけを受け付ける。
 *  - 操作ログの「誰が」は、送られてきた名前ではなく、ログインした人。
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Rest_Keiri {
    const DOC_KINDS = array('quotes' => 'Q', 'invoices' => 'I', 'costs' => 'C');

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'routes'));
    }

    public static function routes() {
        $c = __CLASS__;
        $r = function ($path, $method, $cb, $perm) use ($c) {
            register_rest_route(SS_Rest_Office::NS, $path, array(array('methods' => $method, 'callback' => array($c, $cb), 'permission_callback' => array('SS_Rest_Office', $perm))));
        };
        // 自社情報（管理者）
        $r('/company-info', 'POST', 'save_company_info', 'can_admin_write');
        $r('/company-documents', 'GET', 'get_documents', 'can_admin');
        $r('/company-documents', 'POST', 'upload_document', 'can_admin_write');
        $r('/company-documents/(?P<id>\d+)', 'DELETE', 'delete_document', 'can_admin_write');
        $r('/company-seal', 'POST', 'upload_seal', 'can_admin_write');
        $r('/company-seal', 'DELETE', 'delete_seal', 'can_admin_write');
        // 自社情報の画面にある「管理者共通パスワード」は、このサービスでは使わない（ログインは一人ずつ）
        $r('/admin/password-status', 'GET', 'password_status', 'can_admin');
        // 経理（スタッフも使う。マッシュオフィスシステムと同じ）
        foreach (array('clients' => 'client', 'products' => 'product', 'quotes' => 'quote', 'invoices' => 'invoice', 'costs' => 'cost') as $path => $one) {
            $r('/' . $path, 'GET', 'get_' . $path, 'can_use');
            $r('/' . $path . '/upsert', 'POST', 'upsert_' . $one, 'can_write');
            $r('/' . $path . '/(?P<id>\d+)', 'DELETE', 'delete_' . $one, 'can_write');
        }
    }

    private static function p(WP_REST_Request $req) {
        return SS_Rest_Office::params($req);
    }

    private static function s(array $p, $key, $max = 191) {
        return isset($p[$key]) ? SS_Rest_Office::text($p[$key], $max) : '';
    }

    private static function area(array $p, $key, $max = 5000) {
        return isset($p[$key]) ? mb_substr(sanitize_textarea_field((string) $p[$key]), 0, $max) : '';
    }

    /* ---------- 自社情報 ---------- */

    private static function day_or_eom($v) {
        $v = sanitize_text_field((string) $v);
        if ($v === '末日' || (int) $v < 1) {
            return '末日';
        }
        return (string) max(1, min(31, (int) $v));
    }

    public static function save_company_info(WP_REST_Request $req) {
        $p = self::p($req);
        $text = array('name', 'repName', 'postalCode', 'address', 'phone', 'fax', 'website', 'invoiceRegNumber',
            'bankName', 'bankBranch', 'bankAccountType', 'bankAccountNumber', 'bankAccountHolder',
            'bankName2', 'bankBranch2', 'bankAccountType2', 'bankAccountNumber2', 'bankAccountHolder2');
        $info = array();
        foreach ($text as $k) {
            $info[$k] = self::s($p, $k, 255);
        }
        $weekly = array();
        foreach ((array) (isset($p['weeklyHolidays']) ? $p['weeklyHolidays'] : array()) as $v) {
            if (is_numeric($v) && (int) $v >= 0 && (int) $v <= 6 && !in_array((int) $v, $weekly, true)) {
                $weekly[] = (int) $v;
            }
        }
        sort($weekly);
        $dates = array();
        foreach ((array) (isset($p['holidayDates']) ? $p['holidayDates'] : array()) as $v) {
            $d = SS_Rest_Office::date_or_empty($v);
            if ($d !== '' && !in_array($d, $dates, true)) {
                $dates[] = $d;
            }
        }
        sort($dates);
        $dates = array_slice($dates, -1000);
        $num = function ($key, $def, $min, $max) use ($p) {
            return isset($p[$key]) && $p[$key] !== '' && is_numeric($p[$key]) ? max($min, min($max, (float) $p[$key])) : $def;
        };
        $info += array(
            'sealSize'          => (int) $num('sealSize', 44, 20, 120),
            'workStartTime'     => SS_Rest_Office::time_or_empty(isset($p['workStartTime']) ? $p['workStartTime'] : '') ?: '09:00',
            'workEndTime'       => SS_Rest_Office::time_or_empty(isset($p['workEndTime']) ? $p['workEndTime'] : '') ?: '18:00',
            'breakMinutes'      => (int) $num('breakMinutes', 60, 0, 480),
            'timeRoundingMinutes' => (int) $num('timeRoundingMinutes', 1, 1, 30),
            'overtimeRate'      => $num('overtimeRate', 25, 0, 200),
            'nightRate'         => $num('nightRate', 25, 0, 200),
            'holidayRate'       => $num('holidayRate', 35, 0, 200),
            'employmentInsuranceIndustry' => in_array(isset($p['employmentInsuranceIndustry']) ? $p['employmentInsuranceIndustry'] : '', array('general', 'agriculture', 'construction'), true) ? $p['employmentInsuranceIndustry'] : 'general',
            'weeklyHolidays'    => $weekly,
            'holidayDates'      => $dates,
            'payrollCutoffDay'  => self::day_or_eom(isset($p['payrollCutoffDay']) ? $p['payrollCutoffDay'] : '末日'),
            'payrollPaymentOffsetMonths' => (int) $num('payrollPaymentOffsetMonths', 0, 0, 2),
            'payrollPaymentDay' => self::day_or_eom(isset($p['payrollPaymentDay']) ? $p['payrollPaymentDay'] : '末日'),
        );
        $tenant = SS_Context::tenant();
        $settings = SS_Tenants::settings($tenant);
        $is_new = empty($settings['company_info']);
        $old = $is_new ? array() : (array) $settings['company_info'];
        $info['sealWhitened'] = !empty($old['sealWhitened']); // 印影の白抜きは、印影の登録時だけ変わる
        SS_Tenants::save_settings($tenant['id'], array('company_info' => $info));
        SS_Context::reset();
        SS_Rest_Office::log($is_new ? '自社情報を登録' : '自社情報を変更', $info['name']);
        return SS_Rest_Office::ok(SS_Rest_Office::company_info_array());
    }

    public static function password_status(WP_REST_Request $req) {
        return SS_Rest_Office::ok(array('isSet' => true, 'managed' => true));
    }

    /** 印影の、画面用URL（なければ空） */
    public static function seal_url() {
        $rows = SS_Repo::current()->all('company_documents', array('kind' => 'seal'), 'id', 'DESC', 1);
        return $rows ? SS_Files::url(SS_Context::tenant_id(), 'company_doc', (int) $rows[0]['id']) : '';
    }

    private static function format_document(array $row) {
        return array(
            'id' => (int) $row['id'], 'title' => $row['title'], 'fileName' => $row['file_name'],
            'fileUrl' => SS_Files::url(SS_Context::tenant_id(), 'company_doc', (int) $row['id']),
            'uploadedBy' => $row['uploaded_by'], 'createdAt' => $row['created_at'],
        );
    }

    public static function get_documents(WP_REST_Request $req) {
        $rows = SS_Repo::current()->all('company_documents', array('kind' => 'doc'), 'id', 'DESC', 1000);
        return SS_Rest_Office::ok(array_map(array(__CLASS__, 'format_document'), $rows));
    }

    /** 画面が、題名を onclick の文字列に入れるため、引用符は全角にしておく */
    private static function safe_title($v) {
        return str_replace(array("'", '"', '\\', '`'), array('’', '”', '＼', '｀'), $v);
    }

    public static function upload_document(WP_REST_Request $req) {
        $files = $req->get_file_params();
        if (empty($files['file'])) {
            return SS_Rest_Office::err('ファイルを選択してください。', 400);
        }
        $repo = SS_Repo::current();
        if ($repo->count('company_documents', array('kind' => 'doc')) >= 500) {
            return SS_Rest_Office::err('書類は500件までです。不要な書類を削除してから登録してください。', 400);
        }
        $saved = SS_Files::store_document($files['file'], SS_Context::tenant_id(), 'docs');
        if (is_wp_error($saved)) {
            return SS_Rest_Office::err($saved->get_error_message(), 400);
        }
        $title = self::safe_title(SS_Rest_Office::text($req->get_param('title'), 191));
        $name = self::safe_title($saved['name'] !== '' ? $saved['name'] : 'file');
        $id = $repo->insert('company_documents', array(
            'kind' => 'doc', 'title' => $title !== '' ? $title : $name, 'file_name' => $name, 'file_path' => $saved['path'], 'mime' => $saved['mime'],
            'file_size' => (int) $saved['size'], 'uploaded_by' => mb_substr(SS_Rest_Office::actor_name(), 0, 191), 'created_at' => SS_Rest_Office::now(),
        ));
        if (!$id) {
            SS_Files::delete(SS_Context::tenant_id(), $saved['path']);
            return SS_Rest_Office::err('登録に失敗しました。', 500);
        }
        $row = $repo->find('company_documents', $id);
        SS_Rest_Office::log('書類アップロード', $row['title']);
        return SS_Rest_Office::ok(self::format_document($row), 201);
    }

    public static function delete_document(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $row = $repo->find('company_documents', (int) $req['id']);
        if (!$row || $row['kind'] !== 'doc') {
            return SS_Rest_Office::err('見つかりません。', 404);
        }
        $repo->delete('company_documents', (int) $row['id']);
        SS_Files::delete(SS_Context::tenant_id(), $row['file_path']);
        SS_Rest_Office::log('書類削除', $row['title']);
        return SS_Rest_Office::ok(array('deleted' => true));
    }

    public static function upload_seal(WP_REST_Request $req) {
        $files = $req->get_file_params();
        if (empty($files['file'])) {
            return SS_Rest_Office::err('画像ファイルを選択してください。', 400);
        }
        $saved = SS_Files::store_image($files['file'], SS_Context::tenant_id(), 'seal', array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif'));
        if (is_wp_error($saved)) {
            return SS_Rest_Office::err($saved->get_error_message(), 400);
        }
        $repo = SS_Repo::current();
        $old = $repo->all('company_documents', array('kind' => 'seal'), 'id', 'ASC', 10);
        $id = $repo->insert('company_documents', array(
            'kind' => 'seal', 'title' => '印影', 'file_name' => 'seal.' . pathinfo($saved['path'], PATHINFO_EXTENSION), 'file_path' => $saved['path'], 'mime' => $saved['mime'],
            'file_size' => (int) $saved['size'], 'uploaded_by' => mb_substr(SS_Rest_Office::actor_name(), 0, 191), 'created_at' => SS_Rest_Office::now(),
        ));
        if (!$id) {
            SS_Files::delete(SS_Context::tenant_id(), $saved['path']);
            return SS_Rest_Office::err('登録に失敗しました。', 500);
        }
        foreach ($old as $o) { // 差し替え：古い印影は消す
            $repo->delete('company_documents', (int) $o['id']);
            SS_Files::delete(SS_Context::tenant_id(), $o['file_path']);
        }
        $whitened = !empty($req->get_param('whitened')) && (string) $req->get_param('whitened') !== '0';
        self::merge_company_info(array('sealWhitened' => $whitened));
        SS_Rest_Office::log('社印画像をアップロード');
        return SS_Rest_Office::ok(array('sealImageUrl' => self::seal_url(), 'sealWhitened' => $whitened));
    }

    public static function delete_seal(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        foreach ($repo->all('company_documents', array('kind' => 'seal'), 'id', 'ASC', 10) as $o) {
            $repo->delete('company_documents', (int) $o['id']);
            SS_Files::delete(SS_Context::tenant_id(), $o['file_path']);
        }
        self::merge_company_info(array('sealWhitened' => false));
        SS_Rest_Office::log('社印画像を削除');
        return SS_Rest_Office::ok(array('deleted' => true));
    }

    private static function merge_company_info(array $changes) {
        $tenant = SS_Context::tenant();
        $settings = SS_Tenants::settings($tenant);
        $info = isset($settings['company_info']) && is_array($settings['company_info']) ? $settings['company_info'] : array();
        SS_Tenants::save_settings($tenant['id'], array('company_info' => array_merge($info, $changes)));
        SS_Context::reset();
    }

    /* ---------- 経理：共通 ---------- */

    /** 同じお客様の名簿にいる人なら、そのIDと名簿の名前。いなければ null と、送られてきた名前 */
    private static function person(array $p, $id_key, $name_key) {
        $id = isset($p[$id_key]) ? (int) $p[$id_key] : 0;
        $s = $id > 0 ? SS_Repo::current()->find('staff', $id) : null;
        return $s ? array((int) $s['id'], $s['name']) : array(null, self::s($p, $name_key));
    }

    private static function client_ref(array $p) {
        $id = isset($p['clientId']) ? (int) $p['clientId'] : 0;
        $c = $id > 0 ? SS_Repo::current()->find('clients', $id) : null;
        return array($c ? (int) $c['id'] : null, self::s($p, 'clientName'));
    }

    /** 見積もり・請求書・原価計算書の共通の列 */
    private static function doc_common(array $p) {
        list($cid, $cname) = self::client_ref($p);
        list($sid, $sname) = self::person($p, 'staffId', 'staffName');
        list($w1, $w1n) = self::person($p, 'worker1Id', 'worker1Name');
        list($w2, $w2n) = self::person($p, 'worker2Id', 'worker2Name');
        $issue = SS_Rest_Office::date_or_empty(isset($p['issueDate']) ? $p['issueDate'] : '');
        return array(
            'client_id' => $cid, 'client_name' => $cname, 'project_name' => self::s($p, 'projectName'),
            'staff_id' => $sid, 'staff_name' => $sname, 'worker1_id' => $w1, 'worker1_name' => $w1n, 'worker2_id' => $w2, 'worker2_name' => $w2n,
            'issue_date' => $issue !== '' ? $issue : current_time('Y-m-d'), 'memo' => self::area($p, 'memo'),
        );
    }

    /** 品目（名前・数量・単価）。金額は円未満を四捨五入 */
    private static function items(array $p, $with_ref = false) {
        $items = array();
        $sum = 0;
        foreach (array_slice(isset($p['items']) && is_array($p['items']) ? $p['items'] : array(), 0, 500) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $qty = isset($it['quantity']) && is_numeric($it['quantity']) ? (float) $it['quantity'] : 0;
            $price = isset($it['unitPrice']) && is_numeric($it['unitPrice']) ? (float) $it['unitPrice'] : 0;
            $amount = round($qty * $price);
            $row = array('name' => isset($it['name']) ? SS_Rest_Office::text($it['name'], 191) : '', 'quantity' => $qty, 'unitPrice' => $price, 'amount' => $amount);
            if ($with_ref && isset($it['refPrice']) && trim((string) $it['refPrice']) !== '' && is_numeric($it['refPrice'])) {
                $row['refPrice'] = round((float) $it['refPrice'], 2);
            }
            $items[] = $row;
            $sum += $amount;
        }
        return array($items, $sum);
    }

    /** お客様ごとの連番：Q202610-001 など（その月の最大の番号＋1） */
    private static function next_no($table, $col) {
        $prefix = self::DOC_KINDS[$table] . current_time('Ym') . '-';
        $max = 0;
        foreach (SS_Repo::current()->all($table, array($col . ' >=' => $prefix, $col . ' <' => substr($prefix, 0, -1) . '.'), 'id', 'ASC', 10000) as $r) {
            $max = max($max, (int) substr($r[$col], strlen($prefix)));
        }
        return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    private static function ids(array $row, array $keys) {
        $out = array();
        foreach ($keys as $k => $col) {
            $out[$k] = $row[$col] ? (string) $row[$col] : '';
        }
        return $out;
    }

    private static function decode_items($json) {
        $v = json_decode((string) $json, true);
        return is_array($v) ? $v : array();
    }

    private static function people(array $row) {
        return self::ids($row, array('clientId' => 'client_id', 'staffId' => 'staff_id', 'worker1Id' => 'worker1_id', 'worker2Id' => 'worker2_id')) + array(
            'clientName' => $row['client_name'], 'projectName' => $row['project_name'], 'staffName' => $row['staff_name'],
            'worker1Name' => $row['worker1_name'], 'worker2Name' => $row['worker2_name'], 'issueDate' => $row['issue_date'],
        );
    }

    /**
     * 保存（idがあれば、同じお客様の行を更新。なければ番号を振って作成）。
     * @return array|WP_REST_Response 保存した行
     */
    private static function save_row($table, $no_col, $id, array $data, $label) {
        $repo = SS_Repo::current();
        $now = SS_Rest_Office::now();
        if ($id > 0) {
            if (!$repo->find($table, $id)) {
                return SS_Rest_Office::err('見つかりません。', 404);
            }
            $data['updated_at'] = $now;
            $repo->update($table, $id, $data);
            $is_new = false;
        } else {
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            $id = 0;
            for ($try = 0; $try < 3 && !$id; $try++) { // 同時に作った場合、番号の重複で失敗するので、振り直す
                $data[$no_col] = self::next_no($table, $no_col);
                $id = $repo->insert($table, $data);
            }
            if (!$id) {
                return SS_Rest_Office::err('登録に失敗しました。', 500);
            }
            $is_new = true;
        }
        $row = $repo->find($table, $id);
        SS_Rest_Office::log($label . ($is_new ? '新規作成' : '変更'), $row[$no_col]);
        return $row;
    }

    private static function delete_row($table, WP_REST_Request $req, $label, $no_col = '') {
        $repo = SS_Repo::current();
        $row = $repo->find($table, (int) $req['id']);
        if (!$row) {
            return SS_Rest_Office::err('見つかりません。', 404);
        }
        $repo->delete($table, (int) $row['id']);
        SS_Rest_Office::log($label . '削除', $no_col !== '' ? $row[$no_col] : $row['name']);
        return SS_Rest_Office::ok(array('deleted' => true));
    }

    /* ---------- 顧客 ---------- */

    private static function format_client(array $r) {
        return array('id' => (int) $r['id'], 'name' => $r['name'], 'rep1' => $r['rep1'], 'rep2' => $r['rep2'], 'postalCode' => $r['postal_code'],
            'address' => $r['address'], 'phone' => $r['phone'], 'projects' => (string) $r['projects'], 'memo' => (string) $r['memo'],
            'createdAt' => $r['created_at'], 'updatedAt' => $r['updated_at']);
    }

    public static function get_clients(WP_REST_Request $req) {
        return SS_Rest_Office::ok(array_map(array(__CLASS__, 'format_client'), SS_Repo::current()->all('clients', array(), 'name', 'ASC', 10000)));
    }

    public static function upsert_client(WP_REST_Request $req) {
        $p = self::p($req);
        $name = self::s($p, 'name');
        if ($name === '') {
            return SS_Rest_Office::err('顧客名を入力してください。', 400);
        }
        $data = array('name' => $name, 'rep1' => self::s($p, 'rep1'), 'rep2' => self::s($p, 'rep2'), 'postal_code' => self::s($p, 'postalCode', 10),
            'address' => self::s($p, 'address', 255), 'phone' => self::s($p, 'phone', 50), 'projects' => self::area($p, 'projects'), 'memo' => self::area($p, 'memo'));
        $repo = SS_Repo::current();
        $id = isset($p['id']) ? (int) $p['id'] : 0;
        $now = SS_Rest_Office::now();
        if ($id > 0) {
            if (!$repo->find('clients', $id)) {
                return SS_Rest_Office::err('見つかりません。', 404);
            }
            $repo->update('clients', $id, $data + array('updated_at' => $now));
        } else {
            $id = $repo->insert('clients', $data + array('created_at' => $now, 'updated_at' => $now));
        }
        SS_Rest_Office::log(isset($p['id']) && (int) $p['id'] > 0 ? '顧客情報変更' : '顧客新規登録', $name);
        return SS_Rest_Office::ok(self::format_client($repo->find('clients', $id)));
    }

    public static function delete_client(WP_REST_Request $req) {
        return self::delete_row('clients', $req, '顧客');
    }

    /* ---------- 商品 ---------- */

    private static function format_product(array $r) {
        return array('id' => (int) $r['id'], 'name' => $r['name'], 'cost' => round((float) $r['cost'], 2), 'price' => round((float) $r['price'], 2),
            'maker' => $r['maker'], 'createdAt' => $r['created_at'], 'updatedAt' => $r['updated_at']);
    }

    public static function get_products(WP_REST_Request $req) {
        return SS_Rest_Office::ok(array_map(array(__CLASS__, 'format_product'), SS_Repo::current()->all('products', array(), 'name', 'ASC', 10000)));
    }

    public static function upsert_product(WP_REST_Request $req) {
        $p = self::p($req);
        $name = self::s($p, 'name');
        if ($name === '') {
            return SS_Rest_Office::err('商品名を入力してください。', 400);
        }
        $num = function ($k) use ($p) { return isset($p[$k]) && is_numeric($p[$k]) ? round((float) $p[$k], 2) : 0; };
        $data = array('name' => $name, 'cost' => $num('cost'), 'price' => $num('price'), 'maker' => self::s($p, 'maker'));
        $repo = SS_Repo::current();
        $id = isset($p['id']) ? (int) $p['id'] : 0;
        $now = SS_Rest_Office::now();
        if ($id > 0) {
            if (!$repo->find('products', $id)) {
                return SS_Rest_Office::err('見つかりません。', 404);
            }
            $repo->update('products', $id, $data + array('updated_at' => $now));
        } else {
            $id = $repo->insert('products', $data + array('created_at' => $now, 'updated_at' => $now));
        }
        SS_Rest_Office::log(isset($p['id']) && (int) $p['id'] > 0 ? '商品情報変更' : '商品新規登録', $name);
        return SS_Rest_Office::ok(self::format_product($repo->find('products', $id)));
    }

    public static function delete_product(WP_REST_Request $req) {
        return self::delete_row('products', $req, '商品');
    }

    /* ---------- 見積もり ---------- */

    private static function format_quote(array $r) {
        return array('id' => (int) $r['id'], 'quoteNo' => $r['quote_no']) + self::people($r) + array(
            'validMonths' => (int) $r['valid_months'], 'costNo' => (string) $r['cost_no'], 'items' => self::decode_items($r['items_json']),
            'totalAmount' => (int) $r['total_amount'], 'memo' => (string) $r['memo'], 'createdAt' => $r['created_at'], 'updatedAt' => $r['updated_at']);
    }

    public static function get_quotes(WP_REST_Request $req) {
        return SS_Rest_Office::ok(array_map(array(__CLASS__, 'format_quote'), SS_Repo::current()->all('quotes', array(), 'id', 'DESC', 10000)));
    }

    public static function upsert_quote(WP_REST_Request $req) {
        $p = self::p($req);
        list($items, $sub) = self::items($p);
        $data = self::doc_common($p) + array(
            'valid_months' => isset($p['validMonths']) ? max(0, min(120, (int) $p['validMonths'])) : 1,
            'items_json' => wp_json_encode($items), 'total_amount' => $sub + floor($sub * 0.1), // 消費税10%（端数切り捨て）込み
        );
        if (isset($p['costNo'])) {
            $data['cost_no'] = self::s($p, 'costNo', 30);
        }
        $row = self::save_row('quotes', 'quote_no', isset($p['id']) ? (int) $p['id'] : 0, $data, '見積もり');
        if ($row instanceof WP_REST_Response) {
            return $row;
        }
        // 原価計算書から作った見積もり：その原価計算書に、見積もりNOを（まだなければ）書き戻す
        if ($row['cost_no'] !== '') {
            SS_Repo::current()->update_where('costs', array('report_no' => $row['cost_no'], 'quote_no' => ''), array('quote_no' => $row['quote_no']));
        }
        return SS_Rest_Office::ok(self::format_quote($row));
    }

    public static function delete_quote(WP_REST_Request $req) {
        return self::delete_row('quotes', $req, '見積もり', 'quote_no');
    }

    /* ---------- 請求書 ---------- */

    private static function format_invoice(array $r) {
        return array('id' => (int) $r['id'], 'invoiceNo' => $r['invoice_no'], 'quoteNo' => (string) $r['quote_no']) + self::people($r) + array(
            'items' => self::decode_items($r['items_json']), 'totalAmount' => (int) $r['total_amount'], 'memo' => (string) $r['memo'],
            'createdAt' => $r['created_at'], 'updatedAt' => $r['updated_at']);
    }

    public static function get_invoices(WP_REST_Request $req) {
        return SS_Rest_Office::ok(array_map(array(__CLASS__, 'format_invoice'), SS_Repo::current()->all('invoices', array(), 'id', 'DESC', 10000)));
    }

    public static function upsert_invoice(WP_REST_Request $req) {
        $p = self::p($req);
        list($items, $sub) = self::items($p);
        $data = self::doc_common($p) + array(
            'quote_no' => self::s($p, 'quoteNo', 30), 'items_json' => wp_json_encode($items), 'total_amount' => $sub + floor($sub * 0.1),
        );
        $row = self::save_row('invoices', 'invoice_no', isset($p['id']) ? (int) $p['id'] : 0, $data, '請求書');
        if ($row instanceof WP_REST_Response) {
            return $row;
        }
        // 見積もりから作った請求書：その見積もりにつながる原価計算書に、請求書NOを（まだなければ）書き戻す
        if ($row['quote_no'] !== '') {
            SS_Repo::current()->update_where('costs', array('quote_no' => $row['quote_no'], 'invoice_no' => ''), array('invoice_no' => $row['invoice_no']));
        }
        return SS_Rest_Office::ok(self::format_invoice($row));
    }

    public static function delete_invoice(WP_REST_Request $req) {
        return self::delete_row('invoices', $req, '請求書', 'invoice_no');
    }

    /* ---------- 原価計算書 ---------- */

    private static function format_cost(array $r) {
        return array('id' => (int) $r['id'], 'reportNo' => $r['report_no'], 'quoteNo' => (string) $r['quote_no'], 'invoiceNo' => (string) $r['invoice_no']) + self::people($r) + array(
            'revenueAmount' => (int) $r['revenue_amount'], 'items' => self::decode_items($r['items_json']), 'costTotal' => (int) $r['cost_total'],
            'memo' => (string) $r['memo'], 'createdAt' => $r['created_at'], 'updatedAt' => $r['updated_at']);
    }

    public static function get_costs(WP_REST_Request $req) {
        return SS_Rest_Office::ok(array_map(array(__CLASS__, 'format_cost'), SS_Repo::current()->all('costs', array(), 'id', 'DESC', 10000)));
    }

    public static function upsert_cost(WP_REST_Request $req) {
        $p = self::p($req);
        list($items, $sum) = self::items($p, true);
        $data = self::doc_common($p) + array(
            'quote_no' => self::s($p, 'quoteNo', 30), 'invoice_no' => self::s($p, 'invoiceNo', 30),
            'revenue_amount' => isset($p['revenueAmount']) && is_numeric($p['revenueAmount']) ? (int) $p['revenueAmount'] : 0,
            'items_json' => wp_json_encode($items), 'cost_total' => $sum,
        );
        $row = self::save_row('costs', 'report_no', isset($p['id']) ? (int) $p['id'] : 0, $data, '原価計算書');
        if ($row instanceof WP_REST_Response) {
            return $row;
        }
        return SS_Rest_Office::ok(self::format_cost($row));
    }

    public static function delete_cost(WP_REST_Request $req) {
        return self::delete_row('costs', $req, '原価計算書', 'report_no');
    }
}
