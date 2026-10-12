<?php
/**
 * 給与（従業員の給与設定・税額表／保険料額表・給与明細・保存履歴・年末調整・本人の申告）と、
 * 給与照会（本人の給与明細・年末調整の申告）のAPI（/wp-json/mo/v1/…）。
 * マッシュオフィスシステムの mush-office/v1 と同じ形で返す。
 *
 * マッシュオフィスシステムとの違い：
 *  - すべて、ログインした人の、お客様の分だけ（SS_Repo）。
 *  - 給与の情報を扱えるのは、契約者（管理者）と、契約者が「設定」で許可した副管理者だけ。
 *  - スタッフ本人の照会は、ログインした本人の分だけ（他の人のIDは受け取らない）。
 *  - 税額表・保険料額表は、お客様ごとの表に加え、運営者が用意した「共通の表」も読める（共通の表は、お客様からは消せない）。
 *  - 元のExcelファイルは、お客様ごとの非公開フォルダに保存する。
 *  - 給与明細の名前・部署・社員番号は、名簿の値で保存する。
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Rest_Kyuyo {
    const MAX_JSON_BYTES = 400000; // 明細・年末調整1件の内容の上限（文字数）
    /** 税額表・保険料額表の種類：URL => [kind, 読み取りに必要な項目, 読み取り失敗時の文言, 操作ログの名前] */
    const TABLE_KINDS = array(
        'insurance-tables'  => array('insurance', '', '保険料額表の読み取りに失敗しました。Excelの内容(列見出し)をご確認ください。', '保険料額表'),
        'tax-tables'        => array('tax', 'brackets', '源泉所得税額表の読み取りに失敗しました。', '源泉所得税額表'),
        'bonus-tax-tables'  => array('bonus_tax', 'kouBrackets', '算出率表の読み取りに失敗しました。', '賞与の算出率表'),
        'daily-tax-tables'  => array('daily_tax', 'brackets', '源泉所得税額表(日額表)の読み取りに失敗しました。', '源泉所得税額表(日額表)'),
    );
    /** 従業員の詳細（マッシュオフィスシステムの従業員の列と同じ名前）。給与に関わる項目は PAY_KEYS */
    const PROFILE_DEFAULTS = array(
        'birthdate' => '', 'zip' => '', 'address' => '', 'phone' => '', 'mobile' => '', 'memo' => '',
        'bank_name' => '', 'bank_branch' => '', 'bank_account_type' => '', 'bank_account_number' => '', 'bank_account_holder' => '',
        'wage_type' => '', 'wage_rate' => 0,
        'housing_allowance' => 0, 'position_allowance' => 0, 'commute_allowance' => 0, 'qualification_allowance' => 0, 'family_allowance' => 0,
        'other1_label' => '', 'other1_amount' => 0, 'other2_label' => '', 'other2_amount' => 0, 'other3_label' => '', 'other3_amount' => 0,
        'housing_taxable' => 1, 'position_taxable' => 1, 'commute_taxable' => 0, 'qualification_taxable' => 1, 'family_taxable' => 1,
        'other1_taxable' => 1, 'other2_taxable' => 1, 'other3_taxable' => 1,
        'employment_insurance_fixed' => 0, 'resident_tax_fixed' => 0, 'life_insurance_fixed' => 0, 'wage_savings_fixed' => 0, 'stock_ownership_fixed' => 0,
        'dependents_count' => 0,
    );
    const PERSONAL_KEYS = array('birthdate', 'zip', 'address', 'phone', 'mobile', 'memo', 'bank_name', 'bank_branch', 'bank_account_type', 'bank_account_number', 'bank_account_holder');

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'routes'));
    }

    public static function routes() {
        $c = __CLASS__;
        $r = function ($path, $method, $cb, $perm) use ($c) {
            $cls = in_array($perm, array('can_payroll', 'can_payroll_write'), true) ? $c : 'SS_Rest_Office';
            register_rest_route(SS_Rest_Office::NS, $path, array(array('methods' => $method, 'callback' => array($c, $cb), 'permission_callback' => array($cls, $perm))));
        };
        // 従業員の詳細・給与設定
        $r('/employees/(?P<id>\d+)/profile', 'POST', 'save_profile', 'can_admin_write');
        $r('/employees/(?P<id>\d+)/hire-date', 'POST', 'save_hire_date', 'can_admin_write');
        $r('/employees/(?P<id>\d+)/wage', 'POST', 'save_wage', 'can_payroll_write');
        $r('/employees/(?P<id>\d+)/allowances', 'POST', 'save_allowances', 'can_payroll_write');
        $r('/employees/(?P<id>\d+)/deductions', 'POST', 'save_deductions', 'can_payroll_write');
        // 税額表・保険料額表
        foreach (array_keys(self::TABLE_KINDS) as $path) {
            $r('/' . $path, 'GET', 'get_tables', 'can_payroll');
            $r('/' . $path, 'POST', 'upload_table', 'can_payroll_write');
            $r('/' . $path . '/(?P<id>\d+)', 'DELETE', 'delete_table', 'can_payroll_write');
        }
        // 給与明細
        $r('/payslips', 'GET', 'get_payslips', 'can_payroll');
        $r('/payslips/(?P<staffId>\d+)/(?P<year>\d+)/(?P<month>\d+)', 'GET', 'get_payslip', 'can_payroll');
        $r('/payslips/(?P<staffId>\d+)/(?P<year>\d+)/(?P<month>\d+)', 'POST', 'save_payslip', 'can_payroll_write');
        $r('/payslips/(?P<staffId>\d+)/(?P<year>\d+)/(?P<month>\d+)/history', 'GET', 'get_history', 'can_payroll');
        $r('/payslips/(?P<id>\d+)', 'DELETE', 'delete_payslip', 'can_payroll_write');
        // 年末調整
        $r('/year-end', 'GET', 'list_year_end', 'can_payroll');
        $r('/year-end/(?P<staffId>\d+)/(?P<year>\d+)', 'GET', 'get_year_end', 'can_payroll');
        $r('/year-end/(?P<staffId>\d+)/(?P<year>\d+)', 'POST', 'save_year_end', 'can_payroll_write');
        $r('/year-end/(?P<staffId>\d+)/(?P<year>\d+)', 'DELETE', 'delete_year_end', 'can_payroll_write');
        $r('/year-end-decl', 'GET', 'list_decl', 'can_payroll');
        $r('/year-end-decl/(?P<staffId>\d+)/(?P<year>\d+)', 'GET', 'get_decl', 'can_payroll');
        // 本人の照会・申告
        $r('/my-payslips', 'GET', 'my_payslips', 'can_use');
        $r('/my-payslips/(?P<year>\d+)/(?P<month>\d+)', 'GET', 'my_payslip', 'can_use');
        $r('/my-year-end/(?P<year>\d+)', 'GET', 'my_decl', 'can_use');
        $r('/my-year-end/(?P<year>\d+)', 'POST', 'save_my_decl', 'can_write');
    }

    /* ---------- 権限 ---------- */

    /** 給与を扱える人：契約者（管理者）、または契約者が許可した副管理者 */
    public static function can_payroll() {
        if (!SS_Rest_Office::can_admin()) {
            return false;
        }
        if (current_user_can('shift_manage_settings')) {
            return true;
        }
        $t = SS_Context::tenant();
        return $t && SS_Tenants::manager_payroll($t);
    }

    public static function can_payroll_write() {
        return self::can_payroll() && SS_Rest_Office::can_write();
    }

    /* ---------- 共通 ---------- */

    private static function p(WP_REST_Request $req) {
        return SS_Rest_Office::params($req);
    }

    private static function int_param($v, $min, $max) {
        $n = (int) $v;
        return ($n < $min || $n > $max) ? null : $n;
    }

    private static function year_month(WP_REST_Request $req) {
        return array(self::int_param($req['year'], 2000, 2100), self::int_param(isset($req['month']) ? $req['month'] : 1, 1, 12));
    }

    /** 画面から受け取る、自由な形の内容（明細・年末調整）。大きすぎるものは受け付けない */
    private static function json_data($v) {
        $json = wp_json_encode(is_array($v) ? $v : array());
        return ($json === false || strlen($json) > self::MAX_JSON_BYTES) ? null : $json;
    }

    private static function decode($json) {
        $v = json_decode((string) $json, true);
        return is_array($v) ? $v : null;
    }

    /* ---------- 従業員の詳細・給与設定 ---------- */

    public static function profile_of(array $staff) {
        $saved = !empty($staff['profile']) ? json_decode($staff['profile'], true) : array();
        return array_merge(self::PROFILE_DEFAULTS, is_array($saved) ? array_intersect_key($saved, self::PROFILE_DEFAULTS) : array());
    }

    /** 管理者向けの名簿の行に、詳細を加える（給与を扱えない人には、給与に関わる項目は初期値のまま） */
    public static function employee_extra(array $staff) {
        $p = self::profile_of($staff);
        if (!self::can_payroll()) {
            foreach (self::PROFILE_DEFAULTS as $k => $def) {
                if (!in_array($k, self::PERSONAL_KEYS, true)) {
                    $p[$k] = $def;
                }
            }
        }
        $p['hire_date'] = (string) $staff['hire_date'];
        return $p;
    }

    private static function save_profile_fields(array $staff, array $changes, $log_action) {
        $p = array_merge(self::profile_of($staff), $changes);
        SS_Repo::current()->update('staff', (int) $staff['id'], array('profile' => wp_json_encode($p)));
        SS_Rest_Office::log($log_action, $staff['name']);
    }

    private static function staff_or_404(WP_REST_Request $req) {
        return SS_Rest_Office::staff_in_tenant((int) $req['id']);
    }

    public static function save_profile(WP_REST_Request $req) {
        $s = self::staff_or_404($req);
        if (!$s) {
            return SS_Rest_Office::err('従業員が見つかりません。', 404);
        }
        $p = self::p($req);
        $name = isset($p['name']) ? SS_Rest_Office::text($p['name'], 100) : '';
        if ($name === '') {
            return SS_Rest_Office::err('氏名を入力してください。', 400);
        }
        $changes = array();
        foreach (array('birthdate' => 'birthdate', 'zip' => 'zip', 'address' => 'address', 'phone' => 'phone', 'mobile' => 'mobile',
            'bankName' => 'bank_name', 'bankBranch' => 'bank_branch', 'bankAccountType' => 'bank_account_type', 'bankAccountNumber' => 'bank_account_number', 'bankAccountHolder' => 'bank_account_holder') as $in => $col) {
            $changes[$col] = isset($p[$in]) ? SS_Rest_Office::text($p[$in], 255) : '';
        }
        $changes['memo'] = isset($p['memo']) ? mb_substr(sanitize_textarea_field((string) $p['memo']), 0, 2000) : '';
        $row = array('name' => $name, 'department' => isset($p['department']) ? SS_Rest_Office::text($p['department'], 50) : (string) $s['department']);
        if (isset($p['hireDate'])) {
            $row['hire_date'] = SS_Rest_Office::date_or_empty($p['hireDate']);
        }
        SS_Repo::current()->update('staff', (int) $s['id'], $row);
        self::save_profile_fields(array_merge($s, $row), $changes, '従業員情報を変更');
        return SS_Rest_Office::ok(array('saved' => true));
    }

    public static function save_hire_date(WP_REST_Request $req) {
        $s = self::staff_or_404($req);
        if (!$s) {
            return SS_Rest_Office::err('従業員が見つかりません。', 404);
        }
        $p = self::p($req);
        $d = SS_Rest_Office::date_or_empty(isset($p['hireDate']) ? $p['hireDate'] : '');
        SS_Repo::current()->update('staff', (int) $s['id'], array('hire_date' => $d));
        SS_Rest_Office::log('入社日を変更', $s['name']);
        return SS_Rest_Office::ok(array('saved' => true, 'hireDate' => $d));
    }

    private static function yen($v) {
        return is_numeric($v) ? max(-99999999, min(99999999, (int) $v)) : 0;
    }

    public static function save_wage(WP_REST_Request $req) {
        $s = self::staff_or_404($req);
        if (!$s) {
            return SS_Rest_Office::err('従業員が見つかりません。', 404);
        }
        $p = self::p($req);
        $type = isset($p['wageType']) ? (string) $p['wageType'] : '';
        if ($type !== '' && !in_array($type, array('時給', '日給', '月給'), true)) {
            return SS_Rest_Office::err('給与形態は時給・日給・月給のいずれかを選択してください。', 400);
        }
        $rate = self::yen(isset($p['wageRate']) ? $p['wageRate'] : 0);
        self::save_profile_fields($s, array('wage_type' => $type, 'wage_rate' => $rate), '給与形態を変更');
        return SS_Rest_Office::ok(array('saved' => true, 'wageType' => $type, 'wageRate' => $rate));
    }

    public static function save_allowances(WP_REST_Request $req) {
        $s = self::staff_or_404($req);
        if (!$s) {
            return SS_Rest_Office::err('従業員が見つかりません。', 404);
        }
        $p = self::p($req);
        $c = array();
        foreach (array('housing', 'position', 'commute', 'qualification', 'family') as $k) {
            $c[$k . '_allowance'] = self::yen(isset($p[$k . 'Allowance']) ? $p[$k . 'Allowance'] : 0);
            $c[$k . '_taxable'] = !empty($p[$k . 'Taxable']) ? 1 : 0;
        }
        for ($i = 1; $i <= 3; $i++) {
            $c["other{$i}_label"] = isset($p["other{$i}Label"]) ? SS_Rest_Office::text($p["other{$i}Label"], 50) : '';
            $c["other{$i}_amount"] = self::yen(isset($p["other{$i}Amount"]) ? $p["other{$i}Amount"] : 0);
            $c["other{$i}_taxable"] = !empty($p["other{$i}Taxable"]) ? 1 : 0;
        }
        self::save_profile_fields($s, $c, '手当を変更');
        return SS_Rest_Office::ok(array('saved' => true));
    }

    public static function save_deductions(WP_REST_Request $req) {
        $s = self::staff_or_404($req);
        if (!$s) {
            return SS_Rest_Office::err('従業員が見つかりません。', 404);
        }
        $p = self::p($req);
        $c = array();
        foreach (array('employmentInsurance' => 'employment_insurance_fixed', 'residentTax' => 'resident_tax_fixed', 'lifeInsurance' => 'life_insurance_fixed',
            'wageSavings' => 'wage_savings_fixed', 'stockOwnership' => 'stock_ownership_fixed') as $in => $col) {
            $c[$col] = self::yen(isset($p[$in]) ? $p[$in] : 0);
        }
        $c['dependents_count'] = max(0, min(20, (int) (isset($p['dependents']) ? $p['dependents'] : 0)));
        self::save_profile_fields($s, $c, '固定控除額を変更');
        return SS_Rest_Office::ok(array('saved' => true));
    }

    /* ---------- 税額表・保険料額表 ---------- */

    private static function kind_of(WP_REST_Request $req) {
        $route = (string) $req->get_route();
        foreach (self::TABLE_KINDS as $path => $def) {
            if (preg_match('#/' . preg_quote($path, '#') . '(/\d+)?$#', $route)) {
                return $def;
            }
        }
        return null;
    }

    private static function format_table(array $row, $shared) {
        $data = self::decode($row['data_json']);
        $out = array(
            'id' => (int) $row['id'], 'fiscalYear' => (int) $row['fiscal_year'],
            'title' => ($shared ? '【共通】' : '') . $row['title'], 'fileName' => $row['file_name'],
            'fileUrl' => !$shared && $row['file_path'] !== '' ? SS_Files::url(SS_Context::tenant_id(), 'pay_table', (int) $row['id']) : '',
            'uploadedBy' => $shared ? '運営者（全お客様共通）' : $row['uploaded_by'], 'createdAt' => $row['created_at'], 'shared' => $shared,
        );
        if ($row['kind'] === 'insurance') {
            $out['region'] = (string) $row['region'];
            $out['tiers'] = $data ? $data : array();
        } else {
            $out['data'] = $data ? $data : array();
        }
        return $out;
    }

    public static function get_tables(WP_REST_Request $req) {
        $def = self::kind_of($req);
        $own = SS_Repo::current()->all('pay_tables', array('kind' => $def[0]), 'fiscal_year', 'DESC', 500);
        $out = array();
        foreach ($own as $row) {
            $out[] = self::format_table($row, false);
        }
        foreach (SS_System::shared_pay_tables($def[0]) as $row) {
            $out[] = self::format_table($row, true);
        }
        usort($out, function ($a, $b) { return $b['fiscalYear'] - $a['fiscalYear'] ?: strcmp($b['createdAt'], $a['createdAt']); });
        return SS_Rest_Office::ok($out);
    }

    public static function upload_table(WP_REST_Request $req) {
        $def = self::kind_of($req);
        $year = self::int_param($req->get_param('fiscalYear'), 2000, 2100);
        if (!$year) {
            return SS_Rest_Office::err('年度を入力してください。', 400);
        }
        $raw = (string) $req->get_param($def[0] === 'insurance' ? 'tiersJson' : 'dataJson');
        $raw = function_exists('wp_unslash') ? wp_unslash($raw) : $raw;
        $data = strlen($raw) <= 2000000 ? json_decode($raw, true) : null;
        if (!is_array($data) || !$data || ($def[1] !== '' && empty($data[$def[1]]))) {
            return SS_Rest_Office::err($def[2], 400);
        }
        $repo = SS_Repo::current();
        if ($repo->count('pay_tables', array()) >= 300) {
            return SS_Rest_Office::err('表は300件までです。古い表を削除してから登録してください。', 400);
        }
        $file_path = '';
        $file_name = '';
        $mime = '';
        $files = $req->get_file_params();
        if (!empty($files['file']) && (int) $files['file']['error'] === UPLOAD_ERR_OK) {
            $saved = SS_Files::store_document($files['file'], SS_Context::tenant_id(), 'pay_tables');
            if (is_wp_error($saved)) {
                return SS_Rest_Office::err($saved->get_error_message(), 400);
            }
            $file_path = $saved['path'];
            $file_name = $saved['name'];
            $mime = $saved['mime'];
        }
        $title = SS_Rest_Office::text($req->get_param('title'), 191);
        $region = SS_Rest_Office::text($req->get_param('region'), 20);
        $id = $repo->insert('pay_tables', array(
            'kind' => $def[0], 'fiscal_year' => $year, 'region' => $def[0] === 'insurance' ? $region : '', 'title' => $title,
            'file_name' => $file_name, 'file_path' => $file_path, 'mime' => $mime, 'data_json' => wp_json_encode($data),
            'uploaded_by' => mb_substr(SS_Rest_Office::actor_name(), 0, 191), 'created_at' => SS_Rest_Office::now(),
        ));
        if (!$id) {
            if ($file_path !== '') {
                SS_Files::delete(SS_Context::tenant_id(), $file_path);
            }
            return SS_Rest_Office::err('登録に失敗しました。', 500);
        }
        SS_Rest_Office::log($def[3] . 'アップロード', $year . '年度 ' . ($region !== '' ? $region . ' ' : '') . $title);
        return SS_Rest_Office::ok(self::format_table($repo->find('pay_tables', $id), false), 201);
    }

    public static function delete_table(WP_REST_Request $req) {
        $def = self::kind_of($req);
        $repo = SS_Repo::current();
        $row = $repo->find('pay_tables', (int) $req['id']);
        if (!$row || $row['kind'] !== $def[0]) {
            return SS_Rest_Office::err('この表は削除できません（運営者が用意した共通の表は、削除できません）。', 404);
        }
        $repo->delete('pay_tables', (int) $row['id']);
        if ($row['file_path'] !== '') {
            SS_Files::delete(SS_Context::tenant_id(), $row['file_path']);
        }
        SS_Rest_Office::log($def[3] . '削除', $row['fiscal_year'] . '年度 ' . $row['title']);
        return SS_Rest_Office::ok(array('deleted' => true));
    }

    /* ---------- 給与明細 ---------- */

    private static function payslip_out(array $row) {
        $out = $row;
        unset($out['data_json'], $out['tenant_id']);
        $out['data'] = self::decode($row['data_json']);
        return $out;
    }

    private static function find_payslip(SS_Repo $repo, $staff_id, $year, $month) {
        $rows = $repo->all('payslips', array('staff_id' => (int) $staff_id, 'pay_year' => (int) $year, 'pay_month' => (int) $month), 'id', 'ASC', 1);
        return $rows ? $rows[0] : null;
    }

    public static function get_payslips(WP_REST_Request $req) {
        $staff = SS_Rest_Office::staff_in_tenant((int) $req->get_param('staffId'));
        $year = self::int_param($req->get_param('year'), 2000, 2100);
        if (!$staff || !$year) {
            return SS_Rest_Office::err('staffId・yearを指定してください。', 400);
        }
        $where = array('staff_id' => (int) $staff['id'], 'pay_year' => $year);
        $before = (int) $req->get_param('beforeMonth');
        if ($before > 0) {
            $where['pay_month <'] = $before;
        }
        $out = array();
        foreach (SS_Repo::current()->all('payslips', $where, 'pay_month', 'ASC', 24) as $r) {
            $out[] = array('payMonth' => (int) $r['pay_month'], 'data' => self::decode($r['data_json']));
        }
        return SS_Rest_Office::ok($out);
    }

    public static function get_payslip(WP_REST_Request $req) {
        list($y, $m) = self::year_month($req);
        $staff = SS_Rest_Office::staff_in_tenant((int) $req['staffId']);
        if (!$staff || !$y || !$m) {
            return SS_Rest_Office::ok(false);
        }
        $row = self::find_payslip(SS_Repo::current(), (int) $staff['id'], $y, $m);
        return SS_Rest_Office::ok($row ? self::payslip_out($row) : false);
    }

    private static function snapshot(array $row, $reason) {
        SS_Repo::current()->insert('payslip_history', array(
            'payslip_id' => (int) $row['id'], 'staff_id' => (int) $row['staff_id'], 'staff_name' => $row['staff_name'],
            'pay_year' => (int) $row['pay_year'], 'pay_month' => (int) $row['pay_month'], 'data_json' => $row['data_json'],
            'saved_at' => !empty($row['updated_at']) ? $row['updated_at'] : $row['created_at'], 'replaced_at' => SS_Rest_Office::now(),
            'replaced_by' => mb_substr(SS_Rest_Office::actor_name(), 0, 191), 'reason' => $reason,
        ));
    }

    public static function save_payslip(WP_REST_Request $req) {
        list($y, $m) = self::year_month($req);
        $staff = SS_Rest_Office::staff_in_tenant((int) $req['staffId']);
        if (!$staff || !$y || !$m) {
            return SS_Rest_Office::err('従業員・年月を指定してください。', 400);
        }
        $p = self::p($req);
        $json = self::json_data(isset($p['data']) ? $p['data'] : array());
        if ($json === null) {
            return SS_Rest_Office::err('明細の内容が大きすぎます。', 400);
        }
        $repo = SS_Repo::current();
        $now = SS_Rest_Office::now();
        $existing = self::find_payslip($repo, (int) $staff['id'], $y, $m);
        $row = array(
            'staff_name' => $staff['name'], 'department' => (string) $staff['department'], 'employee_number' => (string) $staff['employee_number'],
            'period_start' => SS_Rest_Office::date_or_empty(isset($p['periodStart']) ? $p['periodStart'] : ''),
            'period_end' => SS_Rest_Office::date_or_empty(isset($p['periodEnd']) ? $p['periodEnd'] : ''),
            'data_json' => $json, 'updated_at' => $now,
        );
        if ($existing) {
            // 内容が変わるときだけ、上書き前の内容を履歴に残す
            if (wp_json_encode(self::decode($existing['data_json'])) !== $json) {
                self::snapshot($existing, '更新');
            }
            $repo->update('payslips', (int) $existing['id'], $row);
            $id = (int) $existing['id'];
        } else {
            $id = $repo->insert('payslips', $row + array('staff_id' => (int) $staff['id'], 'pay_year' => $y, 'pay_month' => $m,
                'created_by' => mb_substr(SS_Rest_Office::actor_name(), 0, 191), 'created_at' => $now));
            if (!$id) {
                return SS_Rest_Office::err('保存に失敗しました。', 500);
            }
        }
        SS_Rest_Office::log('給与明細書を保存', $staff['name'] . "({$y}年{$m}月分)");
        return SS_Rest_Office::ok(array('saved' => true, 'id' => (int) $id));
    }

    public static function get_history(WP_REST_Request $req) {
        list($y, $m) = self::year_month($req);
        $staff = SS_Rest_Office::staff_in_tenant((int) $req['staffId']);
        if (!$staff || !$y || !$m) {
            return SS_Rest_Office::ok(array());
        }
        $out = array();
        foreach (SS_Repo::current()->all('payslip_history', array('staff_id' => (int) $staff['id'], 'pay_year' => $y, 'pay_month' => $m), 'id', 'DESC', 100) as $r) {
            $out[] = array('id' => (int) $r['id'], 'savedAt' => $r['saved_at'], 'replacedAt' => $r['replaced_at'], 'replacedBy' => $r['replaced_by'],
                'reason' => $r['reason'], 'data' => self::decode($r['data_json']));
        }
        return SS_Rest_Office::ok($out);
    }

    public static function delete_payslip(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $row = $repo->find('payslips', (int) $req['id']);
        if (!$row) {
            return SS_Rest_Office::err('見つかりません。', 404);
        }
        self::snapshot($row, '削除');
        $repo->delete('payslips', (int) $row['id']);
        SS_Rest_Office::log('給与明細書を削除', $row['staff_name'] . "({$row['pay_year']}年{$row['pay_month']}月分)");
        return SS_Rest_Office::ok(array('deleted' => true));
    }

    /* ---------- 年末調整 ---------- */

    private static function year_end_out(array $row, $with_data) {
        $out = array('staffId' => (int) $row['staff_id'], 'staffName' => $row['staff_name'], 'year' => (int) $row['adjust_year'],
            'applyMonth' => (int) $row['apply_month'], 'adjustment' => (int) $row['adjustment'], 'updatedBy' => $row['updated_by'], 'updatedAt' => $row['updated_at']);
        if ($with_data) {
            $out['data'] = self::decode($row['data_json']);
        }
        return $out;
    }

    private static function find_year_end(SS_Repo $repo, $staff_id, $year) {
        $rows = $repo->all('year_end', array('staff_id' => (int) $staff_id, 'adjust_year' => (int) $year), 'id', 'ASC', 1);
        return $rows ? $rows[0] : null;
    }

    public static function list_year_end(WP_REST_Request $req) {
        $y = self::int_param($req->get_param('year'), 2000, 2100);
        if (!$y) {
            return SS_Rest_Office::err('yearを指定してください。', 400);
        }
        $out = array();
        foreach (SS_Repo::current()->all('year_end', array('adjust_year' => $y), 'staff_id', 'ASC', 1000) as $r) {
            $out[] = self::year_end_out($r, false);
        }
        return SS_Rest_Office::ok($out);
    }

    public static function get_year_end(WP_REST_Request $req) {
        $staff = SS_Rest_Office::staff_in_tenant((int) $req['staffId']);
        $y = self::int_param($req['year'], 2000, 2100);
        $row = $staff && $y ? self::find_year_end(SS_Repo::current(), (int) $staff['id'], $y) : null;
        return SS_Rest_Office::ok($row ? self::year_end_out($row, true) : false);
    }

    public static function save_year_end(WP_REST_Request $req) {
        $staff = SS_Rest_Office::staff_in_tenant((int) $req['staffId']);
        $y = self::int_param($req['year'], 2000, 2100);
        if (!$staff || !$y) {
            return SS_Rest_Office::err('従業員と年を指定してください。', 400);
        }
        $p = self::p($req);
        $json = self::json_data(isset($p['data']) ? $p['data'] : array());
        if ($json === null) {
            return SS_Rest_Office::err('内容が大きすぎます。', 400);
        }
        $apply = isset($p['applyMonth']) ? (int) $p['applyMonth'] : 12;
        $repo = SS_Repo::current();
        $now = SS_Rest_Office::now();
        $row = array('staff_name' => $staff['name'], 'apply_month' => ($apply >= 1 && $apply <= 12) ? $apply : 12,
            'adjustment' => self::yen(isset($p['adjustment']) ? $p['adjustment'] : 0), 'data_json' => $json,
            'updated_by' => mb_substr(SS_Rest_Office::actor_name(), 0, 191), 'updated_at' => $now);
        $existing = self::find_year_end($repo, (int) $staff['id'], $y);
        if ($existing) {
            $repo->update('year_end', (int) $existing['id'], $row);
        } else {
            $repo->insert('year_end', $row + array('staff_id' => (int) $staff['id'], 'adjust_year' => $y, 'created_at' => $now));
        }
        SS_Rest_Office::log('年末調整を保存', $staff['name'], $y . '年分');
        return SS_Rest_Office::ok(self::year_end_out(self::find_year_end($repo, (int) $staff['id'], $y), true));
    }

    public static function delete_year_end(WP_REST_Request $req) {
        $staff = SS_Rest_Office::staff_in_tenant((int) $req['staffId']);
        $y = self::int_param($req['year'], 2000, 2100);
        if ($staff && $y) {
            SS_Repo::current()->delete_where('year_end', array('staff_id' => (int) $staff['id'], 'adjust_year' => $y));
            SS_Rest_Office::log('年末調整を削除', $staff['name'], $y . '年分');
        }
        return SS_Rest_Office::ok(array('deleted' => true));
    }

    /* ---------- 年末調整の申告（本人が入力） ---------- */

    public static function sanitize_decl($raw) {
        $raw = is_array($raw) ? $raw : array();
        $numeric = array('prevWages', 'prevSocial', 'prevWithheld', 'extraSocial', 'lifeNewGeneral', 'lifeNewCare', 'lifeNewPension', 'lifeOldGeneral', 'lifeOldPension',
            'quake', 'quakeOld', 'smallBiz', 'spouseIncome', 'depGeneral', 'depSpecial', 'depElderly', 'depElderlyLive', 'depSpecialRelative', 'depUnder16',
            'depDisabled', 'depSpecialDisabled', 'depLiveSpecialDisabled', 'housingLoan');
        $choices = array('spouse' => array('none', 'yes'), 'selfDisability' => array('none', 'general', 'special'), 'single' => array('none', 'singleParent', 'widow'));
        $out = array();
        foreach ($numeric as $k) {
            $out[$k] = substr(preg_replace('/[^0-9]/', '', isset($raw[$k]) ? (string) $raw[$k] : ''), 0, 12);
        }
        foreach ($choices as $k => $allowed) {
            $v = isset($raw[$k]) ? (string) $raw[$k] : $allowed[0];
            $out[$k] = in_array($v, $allowed, true) ? $v : $allowed[0];
        }
        $out['spouseElderly'] = !empty($raw['spouseElderly']);
        $out['student'] = !empty($raw['student']);
        $out['memo'] = isset($raw['memo']) ? mb_substr(sanitize_textarea_field((string) $raw['memo']), 0, 1000) : '';
        return $out;
    }

    private static function decl_out(array $row) {
        return array('staffId' => (int) $row['staff_id'], 'staffName' => $row['staff_name'], 'year' => (int) $row['decl_year'],
            'data' => self::decode($row['data_json']), 'submittedAt' => $row['submitted_at'], 'updatedAt' => $row['updated_at']);
    }

    private static function find_decl(SS_Repo $repo, $staff_id, $year) {
        $rows = $repo->all('year_end_decl', array('staff_id' => (int) $staff_id, 'decl_year' => (int) $year), 'id', 'ASC', 1);
        return $rows ? $rows[0] : null;
    }

    public static function list_decl(WP_REST_Request $req) {
        $y = self::int_param($req->get_param('year'), 2000, 2100);
        $out = array();
        foreach ($y ? SS_Repo::current()->all('year_end_decl', array('decl_year' => $y), 'submitted_at', 'DESC', 1000) : array() as $r) {
            $out[] = array('staffId' => (int) $r['staff_id'], 'staffName' => $r['staff_name'], 'year' => (int) $r['decl_year'], 'submittedAt' => $r['submitted_at']);
        }
        return SS_Rest_Office::ok($out);
    }

    public static function get_decl(WP_REST_Request $req) {
        $staff = SS_Rest_Office::staff_in_tenant((int) $req['staffId']);
        $y = self::int_param($req['year'], 2000, 2100);
        $row = $staff && $y ? self::find_decl(SS_Repo::current(), (int) $staff['id'], $y) : null;
        return SS_Rest_Office::ok($row ? self::decl_out($row) : false);
    }

    /* ---------- 本人の照会 ---------- */

    private static function me_or_error() {
        $me = SS_Rest_Office::me_staff();
        return $me ? $me : SS_Rest_Office::err('このアカウントは、スタッフ名簿に紐づいていません。', 400);
    }

    public static function my_payslips(WP_REST_Request $req) {
        $me = self::me_or_error();
        if ($me instanceof WP_REST_Response) {
            return $me;
        }
        $out = array();
        foreach (SS_Repo::current()->all('payslips', array('staff_id' => (int) $me['id']), 'id', 'DESC', 1000) as $r) {
            $out[] = array('id' => (int) $r['id'], 'payYear' => (int) $r['pay_year'], 'payMonth' => (int) $r['pay_month'], 'periodStart' => $r['period_start'], 'periodEnd' => $r['period_end']);
        }
        usort($out, function ($a, $b) { return ($b['payYear'] * 100 + $b['payMonth']) - ($a['payYear'] * 100 + $a['payMonth']); });
        return SS_Rest_Office::ok($out);
    }

    public static function my_payslip(WP_REST_Request $req) {
        $me = self::me_or_error();
        if ($me instanceof WP_REST_Response) {
            return $me;
        }
        list($y, $m) = self::year_month($req);
        $row = $y && $m ? self::find_payslip(SS_Repo::current(), (int) $me['id'], $y, $m) : null;
        return SS_Rest_Office::ok($row ? self::payslip_out($row) : false);
    }

    public static function my_decl(WP_REST_Request $req) {
        $me = self::me_or_error();
        if ($me instanceof WP_REST_Response) {
            return $me;
        }
        $y = self::int_param($req['year'], 2000, 2100);
        $row = $y ? self::find_decl(SS_Repo::current(), (int) $me['id'], $y) : null;
        return SS_Rest_Office::ok($row ? self::decl_out($row) : false);
    }

    public static function save_my_decl(WP_REST_Request $req) {
        $me = self::me_or_error();
        if ($me instanceof WP_REST_Response) {
            return $me;
        }
        $y = self::int_param($req['year'], 2000, 2100);
        if (!$y) {
            return SS_Rest_Office::err('年が正しくありません。', 400);
        }
        $p = self::p($req);
        $repo = SS_Repo::current();
        $now = SS_Rest_Office::now();
        $row = array('staff_name' => $me['name'], 'data_json' => wp_json_encode(self::sanitize_decl(isset($p['data']) ? $p['data'] : array())), 'submitted_at' => $now, 'updated_at' => $now);
        $existing = self::find_decl($repo, (int) $me['id'], $y);
        if ($existing) {
            $repo->update('year_end_decl', (int) $existing['id'], $row);
        } else {
            $repo->insert('year_end_decl', $row + array('staff_id' => (int) $me['id'], 'decl_year' => $y));
        }
        SS_Rest_Office::log('年末調整の申告を提出', $me['name'], $y . '年分');
        return SS_Rest_Office::ok(self::decl_out(self::find_decl($repo, (int) $me['id'], $y)));
    }
}
