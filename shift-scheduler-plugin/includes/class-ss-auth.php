<?php
/** 登録・メール認証・ログイン・スタッフ招待の受諾。 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Auth {
    const VERIFY_TTL = 172800;  // 48時間
    const INVITE_TTL = 604800;  // 7日

    /** 専用ログインページ経由のログイン処理中だけ true */
    private static $portal_login = false;

    public static function init() {
        foreach (array('register', 'verify', 'login', 'accept_invite') as $action) {
            add_action('admin_post_nopriv_ss_' . $action, array(__CLASS__, 'handle_' . $action));
            add_action('admin_post_ss_' . $action, array(__CLASS__, 'handle_' . $action));
        }
        add_filter('wp_authenticate_user', array(__CLASS__, 'block_inactive'), 10, 2);
        add_filter('login_redirect', array(__CLASS__, 'login_redirect'), 10, 3);
        add_filter('show_admin_bar', array(__CLASS__, 'show_admin_bar'));
        add_action('admin_init', array(__CLASS__, 'keep_out_of_admin'));
    }

    /* ---------- 登録 ---------- */

    public static function handle_register() {
        $back = SS_Router::url('register');
        self::verify_nonce('ss_register', $back);
        if (!empty($_POST['website'])) { // ボット対策（人には見えない欄）
            wp_safe_redirect($back);
            exit;
        }
        if (!self::rate_ok('register', 5, HOUR_IN_SECONDS)) {
            self::back($back, 'rate');
        }

        $tenant_name = self::text('tenant_name', 190);
        $person      = self::text('person_name', 100);
        $industry    = isset($_POST['industry']) ? sanitize_key(wp_unslash($_POST['industry'])) : '';
        $email       = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password    = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';

        if ($tenant_name === '' || $person === '' || !in_array($industry, array('restaurant', 'care', 'other'), true)) {
            self::back($back, 'invalid');
        }
        if (!is_email($email) || strlen($email) > 190) {
            self::back($back, 'email');
        }
        if (strlen($password) < 8) {
            self::back($back, 'password');
        }
        if (empty($_POST['agree'])) {
            self::back($back, 'terms');
        }
        // メール認証が終わっていない登録が残っている場合は、やり直せるように古い登録を取り消す
        // （確認メールが届かなかった場合など。認証済みのアカウントには何もしない）
        $existing = get_user_by('email', $email);
        if ($existing) {
            $existing_row = SS_System::user_by_wp_id($existing->ID);
            $existing_tenant = $existing_row ? SS_System::tenant_by_id($existing_row['tenant_id']) : null;
            if ($existing_row && $existing_row['status'] === 'pending' && $existing_tenant && $existing_tenant['status'] === 'unverified') {
                self::discard_registration((int) $existing->ID, (int) $existing_tenant['id']);
            }
        }
        if (email_exists($email) || username_exists($email)) {
            self::back($back, 'email_used');
        }

        global $wpdb;
        $wp_user_id = wp_insert_user(array(
            'user_login'   => $email,
            'user_email'   => $email,
            'user_pass'    => $password,
            'display_name' => $person,
            'role'         => 'shift_owner',
        ));
        if (is_wp_error($wp_user_id)) {
            self::back($back, 'failed');
        }

        $public_id = SS_System::new_public_id();
        $ok = $public_id !== '' && $wpdb->insert(SS_System::table('tenants'), array(
            'public_id'  => $public_id,
            'name'       => $tenant_name,
            'industry'   => $industry,
            'status'     => 'unverified',
            'settings'   => wp_json_encode(array('staff_view' => 'all')),
            'created_at' => SS_System::now(),
        ));
        $tenant_id = $ok ? (int) $wpdb->insert_id : 0;
        $user_row_ok = $tenant_id && $wpdb->insert(SS_System::table('users'), array(
            'tenant_id'  => $tenant_id,
            'wp_user_id' => $wp_user_id,
            'email'      => $email,
            'role'       => 'owner',
            'status'     => 'pending',
            'created_at' => SS_System::now(),
        ));
        if (!$user_row_ok) {
            self::discard_registration($wp_user_id, $tenant_id);
            self::back($back, 'failed');
        }
        $user_id = (int) $wpdb->insert_id;

        $token = SS_System::create_token($user_id, $tenant_id, 'verify', self::VERIFY_TTL);
        $link = SS_Router::url('verify', $token);
        SS_System::mail($email, 'メールアドレスの確認', $person . " 様\n\nご登録ありがとうございます。\n下記のリンクを開いて、登録を完了してください（48時間有効）。\n\n" . $link . "\n\n心当たりがない場合は、このメールを破棄してください。");

        wp_safe_redirect(add_query_arg('ss_msg', 'verify_sent', $back));
        exit;
    }

    /** 未認証の登録を取り消す（登録失敗時・古い未認証の掃除で使う）。 */
    public static function discard_registration($wp_user_id, $tenant_id) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/user.php';
        if ($wp_user_id) {
            $wpdb->delete(SS_System::table('users'), array('wp_user_id' => (int) $wp_user_id));
            wp_delete_user((int) $wp_user_id);
        }
        if ($tenant_id) {
            $wpdb->delete(SS_System::table('tokens'), array('tenant_id' => (int) $tenant_id));
            $wpdb->delete(SS_System::table('tenants'), array('id' => (int) $tenant_id, 'status' => 'unverified'));
        }
    }

    /* ---------- メール認証（確認ボタンを押したときに確定する） ---------- */

    public static function handle_verify() {
        self::verify_nonce('ss_verify', SS_Router::url('login'));
        $raw = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        $row = SS_System::consume_token($raw, 'verify');
        if (!$row) {
            SS_View::message('リンクが無効です', 'リンクの有効期限が切れているか、すでに使用されています。お手数ですが、もう一度登録してください。', 400, SS_Router::url('register'), '登録ページへ');
        }
        global $wpdb;
        $user = SS_System::user_by_id($row['user_id']);
        $tenant = $user ? SS_System::tenant_by_id($user['tenant_id']) : null;
        if (!$user || !$tenant) {
            SS_View::message('リンクが無効です', '登録情報が見つかりません。', 400, SS_Router::url('register'), '登録ページへ');
        }

        if ($tenant['status'] === 'unverified') {
            $wpdb->update(SS_System::table('tenants'), array(
                'status'      => 'trial',
                'trial_start' => SS_System::now(),
                'trial_end'   => gmdate('Y-m-d H:i:s', strtotime('+' . (int) SS_TRIAL_MONTHS . ' months')),
            ), array('id' => (int) $tenant['id']));
        }
        $wpdb->update(SS_System::table('users'), array('status' => 'active'), array('id' => (int) $user['id']));

        self::login_as((int) $user['wp_user_id']);
        wp_safe_redirect(SS_View::app_url($tenant['public_id']));
        exit;
    }

    /* ---------- ログイン ---------- */

    public static function handle_login() {
        $back = SS_Router::url('login');
        self::verify_nonce('ss_login', $back);
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';

        if (!self::rate_ok('login_ip', 30, 15 * MINUTE_IN_SECONDS) || !self::rate_ok('login_' . strtolower($email), 10, 15 * MINUTE_IN_SECONDS)) {
            self::back($back, 'rate');
        }
        self::$portal_login = true;
        $user = wp_signon(array('user_login' => $email, 'user_password' => $password, 'remember' => true), is_ssl());
        self::$portal_login = false;
        if (is_wp_error($user)) {
            $code = $user->get_error_code();
            $map = array('ss_pending' => 'pending', 'ss_invited' => 'pending', 'ss_disabled' => 'disabled');
            self::back($back, isset($map[$code]) ? $map[$code] : 'login');
        }
        $row = SS_System::user_by_wp_id($user->ID);
        if ($row) {
            global $wpdb;
            $wpdb->update(SS_System::table('users'), array('last_login_at' => SS_System::now()), array('id' => (int) $row['id']));
        }
        wp_safe_redirect(self::landing_url($user->ID));
        exit;
    }

    /** ログイン後に移動する先 */
    public static function landing_url($wp_user_id) {
        $row = SS_System::user_by_wp_id($wp_user_id);
        $tenant = $row ? SS_System::tenant_by_id($row['tenant_id']) : null;
        if ($tenant) {
            return SS_View::app_url($tenant['public_id']);
        }
        return admin_url();
    }

    /* ---------- スタッフ招待の受諾 ---------- */

    public static function handle_accept_invite() {
        self::verify_nonce('ss_accept_invite', SS_Router::url('login'));
        $raw = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        $back = SS_Router::url('invite', $raw);
        if (strlen($password) < 8) {
            self::back($back, 'password');
        }
        $row = SS_System::consume_token($raw, 'invite');
        if (!$row) {
            SS_View::message('招待リンクが無効です', '有効期限が切れているか、すでに使用されています。管理者に再招待を依頼してください。', 400, SS_Router::url('login'), 'ログインページへ');
        }
        $user = SS_System::user_by_id($row['user_id']);
        $tenant = $user ? SS_System::tenant_by_id($user['tenant_id']) : null;
        if (!$user || !$tenant || $user['status'] !== 'invited') {
            SS_View::message('招待リンクが無効です', '招待が取り消されたか、すでに登録済みです。', 400, SS_Router::url('login'), 'ログインページへ');
        }
        global $wpdb;
        wp_set_password($password, (int) $user['wp_user_id']);
        $wpdb->update(SS_System::table('users'), array('status' => 'active'), array('id' => (int) $user['id']));
        self::login_as((int) $user['wp_user_id']);
        wp_safe_redirect(SS_View::app_url($tenant['public_id']));
        exit;
    }

    /* ---------- フィルタ ---------- */

    /** 認証前・招待中・無効のアカウントはログインさせない。 */
    public static function block_inactive($user, $password) {
        if (!($user instanceof WP_User)) {
            return $user;
        }
        // お客様側のユーザーは、WordPress標準のログイン画面（wp-login.php・XML-RPCなど）からは入れない
        if (!self::$portal_login && !user_can($user, 'manage_options')
            && array_intersect(array('shift_owner', 'shift_manager', 'shift_staff'), (array) $user->roles)) {
            return new WP_Error('ss_use_portal', 'お客様のログインは、専用のログインページからお願いします：' . SS_Router::url('login'));
        }
        $row = SS_System::user_by_wp_id($user->ID);
        if ($row && in_array($row['status'], array('pending', 'invited', 'disabled'), true)) {
            return new WP_Error('ss_' . $row['status'], 'このアカウントではログインできません。');
        }
        if ($row) {
            $tenant = SS_System::tenant_by_id($row['tenant_id']);
            if (!$tenant || $tenant['status'] === 'suspended') {
                return new WP_Error('ss_disabled', 'このアカウントではログインできません。');
            }
        }
        return $user;
    }

    public static function login_redirect($redirect_to, $request, $user) {
        if ($user instanceof WP_User && SS_System::user_by_wp_id($user->ID)) {
            return self::landing_url($user->ID);
        }
        return $redirect_to;
    }

    public static function show_admin_bar($show) {
        return self::is_shift_only_user() ? false : $show;
    }

    /** お客様側のユーザーはWordPressの管理画面に入れない。 */
    public static function keep_out_of_admin() {
        if (!is_user_logged_in() || wp_doing_ajax() || !self::is_shift_only_user()) {
            return;
        }
        $script = isset($_SERVER['SCRIPT_NAME']) ? basename($_SERVER['SCRIPT_NAME']) : '';
        if ($script === 'admin-post.php') {
            return;
        }
        wp_safe_redirect(self::landing_url(get_current_user_id()));
        exit;
    }

    private static function is_shift_only_user() {
        if (!is_user_logged_in() || current_user_can('manage_options')) {
            return false;
        }
        $user = wp_get_current_user();
        return (bool) array_intersect(array('shift_owner', 'shift_manager', 'shift_staff'), (array) $user->roles);
    }

    /* ---------- 共通 ---------- */

    private static function login_as($wp_user_id) {
        wp_clear_auth_cookie();
        wp_set_current_user($wp_user_id);
        wp_set_auth_cookie($wp_user_id, true, is_ssl());
        SS_Context::reset();
    }

    private static function verify_nonce($action, $back) {
        $nonce = isset($_POST['_ss_nonce']) ? sanitize_text_field(wp_unslash($_POST['_ss_nonce'])) : '';
        if (!wp_verify_nonce($nonce, $action)) {
            self::back($back, 'failed');
        }
    }

    private static function back($url, $code) {
        wp_safe_redirect(add_query_arg('ss_err', $code, $url));
        exit;
    }

    private static function text($key, $max) {
        $v = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
        return mb_substr($v, 0, $max);
    }

    /** 一定時間あたりの回数制限（超えたら false） */
    private static function rate_ok($bucket, $limit, $window) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $key = 'ss_rl_' . md5($bucket . '|' . $ip);
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return false;
        }
        set_transient($key, $count + 1, $window);
        return true;
    }
}
