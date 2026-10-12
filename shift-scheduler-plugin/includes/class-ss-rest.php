<?php
/**
 * REST API（/wp-json/shift/v1/…）。スタッフの管理（名簿・招待・社員番号でのログイン設定）。
 * お客様IDは常にログイン中ユーザーの所属から決め、リクエストの値は使わない。
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Rest {
    const NS = 'shift/v1';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'routes'));
    }

    public static function routes() {
        register_rest_route(self::NS, '/staff', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'list_staff'), 'permission_callback' => array(__CLASS__, 'can_manage_staff')),
            array('methods' => 'POST', 'callback' => array(__CLASS__, 'create_staff'), 'permission_callback' => array(__CLASS__, 'can_write_staff')),
        ));
        register_rest_route(self::NS, '/staff/(?P<id>\d+)', array(
            array('methods' => 'PUT,PATCH', 'callback' => array(__CLASS__, 'update_staff'), 'permission_callback' => array(__CLASS__, 'can_write_staff')),
            array('methods' => 'DELETE', 'callback' => array(__CLASS__, 'deactivate_staff'), 'permission_callback' => array(__CLASS__, 'can_write_staff')),
        ));
        register_rest_route(self::NS, '/staff/(?P<id>\d+)/invite', array(
            array('methods' => 'POST', 'callback' => array(__CLASS__, 'invite_staff'), 'permission_callback' => array(__CLASS__, 'can_write_staff')),
        ));
        register_rest_route(self::NS, '/staff/(?P<id>\d+)/password', array(
            array('methods' => 'POST', 'callback' => array(__CLASS__, 'set_staff_password'), 'permission_callback' => array(__CLASS__, 'can_write_staff')),
        ));
    }

    /* ---------- 権限 ---------- */

    public static function can_manage_staff() {
        return is_user_logged_in() && current_user_can('shift_manage_staff') && SS_Context::tenant_id() > 0;
    }

    /** 書き込み：上記に加えて、契約状態が書き込み可であること */
    public static function can_write_staff() {
        if (!self::can_manage_staff()) {
            return false;
        }
        $tenant = SS_Context::tenant();
        return $tenant && SS_Tenants::is_writable($tenant);
    }

    /* ---------- 処理 ---------- */

    public static function list_staff() {
        $repo = SS_Repo::current();
        $staff = $repo->all('staff', array(), 'sort_order', 'ASC', 1000);
        $users = $repo->all('users', array(), 'id', 'ASC', 1000);
        $by_staff = array();
        foreach ($users as $u) {
            if (!empty($u['staff_id'])) {
                $by_staff[(int) $u['staff_id']] = $u;
            }
        }
        $out = array();
        foreach ($staff as $s) {
            $u = isset($by_staff[(int) $s['id']]) ? $by_staff[(int) $s['id']] : null;
            $out[] = self::present($s, $u);
        }
        return rest_ensure_response(array('staff' => $out));
    }

    public static function create_staff(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $tenant = SS_Context::tenant();
        $data = self::sanitize($req);
        if (is_wp_error($data)) {
            return $data;
        }
        if ($repo->count('staff', array('active' => 1)) >= SS_Tenants::staff_limit($tenant)) {
            return new WP_Error('staff_limit', 'スタッフ数の上限に達しています。上位プランへの変更をご検討ください。', array('status' => 403));
        }
        if (!empty($data['employee_number']) && self::empno_taken($repo, $data['employee_number'], 0)) {
            return self::empno_error();
        }
        $data['created_at'] = SS_System::now();
        $id = $repo->insert('staff', $data);
        if (!$id) {
            return new WP_Error('failed', '登録に失敗しました。', array('status' => 500));
        }
        return rest_ensure_response(array('staff' => self::present($repo->find('staff', $id), null)));
    }

    public static function update_staff(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $id = (int) $req['id'];
        $row = $repo->find('staff', $id);
        if (!$row) {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        $data = self::sanitize($req, true);
        if (is_wp_error($data)) {
            return $data;
        }
        if (!empty($data['employee_number']) && self::empno_taken($repo, $data['employee_number'], $id)) {
            return self::empno_error();
        }
        // 無効→有効に戻す場合も上限を確認する
        if (array_key_exists('active', $data) && (int) $data['active'] === 1 && (int) $row['active'] === 0) {
            if ($repo->count('staff', array('active' => 1)) >= SS_Tenants::staff_limit(SS_Context::tenant())) {
                return new WP_Error('staff_limit', 'スタッフ数の上限に達しています。', array('status' => 403));
            }
        }
        $repo->update('staff', $id, $data);
        return rest_ensure_response(array('staff' => self::present($repo->find('staff', $id), self::user_of($repo, $id))));
    }

    /** 削除ではなく無効化（過去のシフトの記録を残すため）。ログインも止める。 */
    public static function deactivate_staff(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $id = (int) $req['id'];
        if (!$repo->find('staff', $id)) {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        $repo->update('staff', $id, array('active' => 0));
        $u = self::user_of($repo, $id);
        if ($u && $u['role'] === 'staff') {
            $repo->update('users', (int) $u['id'], array('status' => 'disabled'));
            WP_Session_Tokens::get_instance((int) $u['wp_user_id'])->destroy_all(); // 既存のログインも無効にする
        }
        return rest_ensure_response(array('ok' => true));
    }

    public static function invite_staff(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $tenant = SS_Context::tenant();
        $id = (int) $req['id'];
        $staff = $repo->find('staff', $id);
        if (!$staff || (int) $staff['active'] !== 1) {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        $email = sanitize_email((string) $req->get_param('email'));
        if (!is_email($email) || strlen($email) > 190) {
            return new WP_Error('email', 'メールアドレスの形式が正しくありません。', array('status' => 400));
        }

        $existing = self::user_of($repo, $id);

        // ご自身（ログイン中の管理者）のメールアドレスの場合：招待メールは送らず、このスタッフに自分のアカウントを紐づける
        $me = SS_Context::user();
        if ($me && strtolower((string) $me['email']) === strtolower($email)) {
            if (!empty($me['staff_id']) && (int) $me['staff_id'] !== $id) {
                return new WP_Error('already_linked', 'ご自身のアカウントは、すでに別のスタッフに紐づいています。', array('status' => 409));
            }
            if ($existing && (int) $existing['id'] !== (int) $me['id']) {
                return new WP_Error('already', 'このスタッフには、すでに別のアカウントが紐づいています。', array('status' => 409));
            }
            $repo->update('users', (int) $me['id'], array('staff_id' => $id));
            SS_Context::reset();
            return rest_ensure_response(array('ok' => true, 'linked' => true));
        }

        if ($existing && $existing['status'] === 'active') {
            return new WP_Error('already', 'このスタッフはすでに登録済みです。', array('status' => 409));
        }

        if ($existing) { // 招待中・無効 → 再招待（メールアドレスの変更も可）
            if (strtolower((string) $existing['email']) !== strtolower($email)) {
                $other = email_exists($email);
                if ($other && (int) $other !== (int) $existing['wp_user_id']) {
                    return new WP_Error('email_used', 'このメールアドレスは使用できません。すでにこのシステムで使われている可能性があります（ご自身や管理者のメールアドレスは招待できません）。別のメールアドレスをお試しください。', array('status' => 409));
                }
                wp_update_user(array('ID' => (int) $existing['wp_user_id'], 'user_email' => $email));
                $repo->update('users', (int) $existing['id'], array('email' => $email));
            }
            $repo->update('users', (int) $existing['id'], array('status' => 'invited'));
            SS_System::invalidate_tokens((int) $existing['id'], 'invite');
            $user_id = (int) $existing['id'];
        } else {
            if (email_exists($email) || username_exists($email)) {
                return new WP_Error('email_used', 'このメールアドレスは使用できません。すでにこのシステムで使われている可能性があります（ご自身や管理者のメールアドレスは招待できません）。別のメールアドレスをお試しください。', array('status' => 409));
            }
            $wp_user_id = wp_insert_user(array(
                'user_login'   => $email,
                'user_email'   => $email,
                'user_pass'    => wp_generate_password(32, true, true),
                'display_name' => $staff['name'],
                'role'         => 'shift_staff',
            ));
            if (is_wp_error($wp_user_id)) {
                return new WP_Error('failed', '招待に失敗しました。', array('status' => 500));
            }
            $user_id = $repo->insert('users', array(
                'wp_user_id' => $wp_user_id,
                'email'      => $email,
                'role'       => 'staff',
                'staff_id'   => $id,
                'status'     => 'invited',
                'created_at' => SS_System::now(),
            ));
            if (!$user_id) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user($wp_user_id);
                return new WP_Error('failed', '招待に失敗しました。', array('status' => 500));
            }
        }

        $token = SS_System::create_token($user_id, (int) $tenant['id'], 'invite', SS_Auth::INVITE_TTL);
        $link = SS_Router::url('invite', $token);
        SS_System::mail($email, '「' . $tenant['name'] . '」への招待（' . SS_PRODUCT_NAME . '）', $staff['name'] . " 様\n\n「" . $tenant['name'] . "」から、" . SS_PRODUCT_NAME . "に招待されました。\n下記のリンクからパスワードを設定して、登録を完了してください（7日間有効）。\n\n" . $link . "\n\n心当たりがない場合は、このメールを破棄してください。");

        return rest_ensure_response(array('ok' => true));
    }

    /**
     * 社員番号でログインするための、パスワードの設定（管理者が行う）。
     * メールアドレスがないスタッフにも使えるよう、アカウントがなければ作る。
     * 招待中・無効のアカウントは、パスワードを設定した時点で、ログインできる状態にする。
     */
    public static function set_staff_password(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $id = (int) $req['id'];
        $staff = $repo->find('staff', $id);
        if (!$staff || (int) $staff['active'] !== 1) {
            return new WP_Error('not_found', '見つかりません。', array('status' => 404));
        }
        if ((string) $staff['employee_number'] === '') {
            return new WP_Error('no_empno', '先に、社員番号を登録してください（ログインに使います）。', array('status' => 400));
        }
        $password = (string) $req->get_param('password');
        if (strlen($password) < 8 || strlen($password) > 100) {
            return new WP_Error('password', 'パスワードは8文字以上にしてください。', array('status' => 400));
        }
        $existing = self::user_of($repo, $id);
        if ($existing) {
            if ($existing['role'] !== 'staff') {
                // 管理者・副管理者のアカウント（ご自身など）のパスワードは、ここでは変えない
                return new WP_Error('not_staff', 'このスタッフには、管理者のアカウントが紐づいています。パスワードは、ご本人がログイン画面から変更してください。', array('status' => 409));
            }
            wp_set_password($password, (int) $existing['wp_user_id']);
            $repo->update('users', (int) $existing['id'], array('status' => 'active'));
            SS_System::invalidate_tokens((int) $existing['id'], 'invite'); // 送ってある招待リンクは使えなくする
            return rest_ensure_response(array('ok' => true));
        }
        // ログイン名は、社員番号とは別の、内部用の文字列（社員番号を変えても、ログインはそのまま使える）
        $login = '';
        for ($i = 0; $i < 5 && $login === ''; $i++) {
            $try = 'ssu_' . strtolower(wp_generate_password(16, false, false));
            if (!username_exists($try)) {
                $login = $try;
            }
        }
        $wp_user_id = $login === '' ? new WP_Error('failed', '') : wp_insert_user(array(
            'user_login'   => $login,
            'user_email'   => '',
            'user_pass'    => $password,
            'display_name' => $staff['name'],
            'role'         => 'shift_staff',
        ));
        if (is_wp_error($wp_user_id)) {
            return new WP_Error('failed', 'ログインの設定に失敗しました。', array('status' => 500));
        }
        $user_id = $repo->insert('users', array(
            'wp_user_id' => $wp_user_id,
            'email'      => null,
            'role'       => 'staff',
            'staff_id'   => $id,
            'status'     => 'active',
            'created_at' => SS_System::now(),
        ));
        if (!$user_id) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($wp_user_id);
            return new WP_Error('failed', 'ログインの設定に失敗しました。', array('status' => 500));
        }
        return rest_ensure_response(array('ok' => true));
    }

    /* ---------- 補助 ---------- */

    /** 社員番号は、同じお客様の中で重複させない（他のお客様の同じ番号とは関係しない） */
    private static function empno_taken(SS_Repo $repo, $empno, $except_id) {
        foreach ($repo->all('staff', array('employee_number' => (string) $empno), 'id', 'ASC', 5) as $row) {
            if ((int) $row['id'] !== (int) $except_id) {
                return true;
            }
        }
        return false;
    }

    private static function empno_error() {
        return new WP_Error('empno_used', 'この社員番号は、すでに別のスタッフに使われています。', array('status' => 409));
    }

    /** 社員番号の整形：全角の英数字は半角に、前後の空白は取る。使える文字は英数字とハイフン */
    public static function normalize_empno($v) {
        $v = trim((string) $v);
        if (function_exists('mb_convert_kana')) {
            $v = mb_convert_kana($v, 'as');
        }
        return trim($v);
    }

    private static function user_of(SS_Repo $repo, $staff_id) {
        $rows = $repo->all('users', array('staff_id' => (int) $staff_id), 'id', 'DESC', 1);
        return $rows ? $rows[0] : null;
    }

    private static function present(array $s, $u) {
        return array(
            'id'             => (int) $s['id'],
            'name'           => $s['name'],
            'kana'           => $s['kana'],
            'employee_number' => isset($s['employee_number']) ? (string) $s['employee_number'] : '',
            'department'     => isset($s['department']) ? (string) $s['department'] : '',
            'employment'     => $s['employment'],
            'roles'          => self::decode($s['roles']),
            'qualifications' => self::decode($s['qualifications']),
            'night_ok'       => (int) $s['night_ok'] === 1,
            'active'         => (int) $s['active'] === 1,
            'account'        => $u ? $u['status'] : 'none', // none / invited / active / disabled
            'email'          => $u ? (string) $u['email'] : '',
            'staff_login'    => $u && $u['role'] === 'staff' && in_array($u['status'], array('active', 'invited'), true) && !empty($s['employee_number']), // 社員番号でログインできる準備があるか
        );
    }

    private static function decode($json) {
        $v = $json ? json_decode($json, true) : array();
        return is_array($v) ? array_values($v) : array();
    }

    /** 入力の検証・整形。$partial=true のときは渡された項目だけ。 */
    private static function sanitize(WP_REST_Request $req, $partial = false) {
        $p = $req->get_json_params();
        $p = is_array($p) ? $p : array();
        $out = array();

        if (!$partial || array_key_exists('name', $p)) {
            $name = isset($p['name']) ? trim(sanitize_text_field((string) $p['name'])) : '';
            if ($name === '' || mb_strlen($name) > 100) {
                return new WP_Error('invalid', '名前を100文字以内で入力してください。', array('status' => 400));
            }
            $out['name'] = $name;
        }
        if (array_key_exists('kana', $p)) {
            $out['kana'] = mb_substr(trim(sanitize_text_field((string) $p['kana'])), 0, 100);
        }
        if (array_key_exists('employee_number', $p)) {
            $no = self::normalize_empno(sanitize_text_field((string) $p['employee_number']));
            if ($no !== '' && !preg_match('/^[A-Za-z0-9-]{1,20}$/', $no)) {
                return new WP_Error('invalid', '社員番号は、英数字とハイフンで20文字以内にしてください。', array('status' => 400));
            }
            $out['employee_number'] = $no;
        }
        if (array_key_exists('department', $p)) {
            $out['department'] = mb_substr(trim(sanitize_text_field((string) $p['department'])), 0, 50);
        }
        if (array_key_exists('employment', $p)) {
            if (!in_array($p['employment'], array('full', 'part', 'baito'), true)) {
                return new WP_Error('invalid', '雇用区分が正しくありません。', array('status' => 400));
            }
            $out['employment'] = $p['employment'];
        }
        foreach (array('roles', 'qualifications') as $key) {
            if (array_key_exists($key, $p)) {
                $list = is_array($p[$key]) ? $p[$key] : array();
                $list = array_slice(array_values(array_unique(array_filter(array_map(function ($v) {
                    return mb_substr(trim(sanitize_text_field((string) $v)), 0, 40);
                }, $list), 'strlen'))), 0, 20);
                $out[$key] = wp_json_encode($list, JSON_UNESCAPED_UNICODE);
            }
        }
        if (array_key_exists('night_ok', $p)) {
            $out['night_ok'] = empty($p['night_ok']) ? 0 : 1;
        }
        if ($partial && array_key_exists('active', $p)) {
            $out['active'] = empty($p['active']) ? 0 : 1;
        }
        if (!$out && !$partial) {
            return new WP_Error('invalid', '入力がありません。', array('status' => 400));
        }
        if (!$out) {
            return new WP_Error('invalid', '更新する項目がありません。', array('status' => 400));
        }
        return $out;
    }
}
