<?php
/** URLの割り当て：/register /login /verify/… /invite/… /s/<お客様ID>/… */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Router {
    public static function init() {
        add_action('init', array(__CLASS__, 'add_rules'));
        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        add_action('template_redirect', array(__CLASS__, 'dispatch'), 1);
    }

    public static function add_rules() {
        add_rewrite_rule('^register/?$', 'index.php?ss_page=register', 'top');
        add_rewrite_rule('^login/?$', 'index.php?ss_page=login', 'top');
        add_rewrite_rule('^verify/([a-f0-9]{64})/?$', 'index.php?ss_page=verify&ss_token=$matches[1]', 'top');
        add_rewrite_rule('^invite/([a-f0-9]{64})/?$', 'index.php?ss_page=invite&ss_token=$matches[1]', 'top');
        add_rewrite_rule('^s/([a-z0-9]{12})(?:/([A-Za-z0-9/_-]*))?/?$', 'index.php?ss_page=app&ss_tenant=$matches[1]&ss_path=$matches[2]', 'top');
    }

    public static function query_vars($vars) {
        return array_merge($vars, array('ss_page', 'ss_token', 'ss_tenant', 'ss_path'));
    }

    public static function dispatch() {
        $page = get_query_var('ss_page');
        if (!$page) {
            return;
        }
        $err = isset($_GET['ss_err']) ? SS_View::error_text(sanitize_key(wp_unslash($_GET['ss_err']))) : '';
        $msg = isset($_GET['ss_msg']) ? SS_View::error_text(sanitize_key(wp_unslash($_GET['ss_msg']))) : '';

        switch ($page) {
            case 'register':
            case 'login':
                if (is_user_logged_in() && SS_Context::user()) {
                    wp_safe_redirect(SS_Auth::landing_url(get_current_user_id()));
                    exit;
                }
                SS_View::render($page, array('title' => $page === 'register' ? '無料登録' : 'ログイン', 'err' => $err, 'msg' => $msg));
                break;

            case 'verify':
                $token = (string) get_query_var('ss_token');
                if (!SS_System::peek_token($token, 'verify')) {
                    SS_View::message('リンクが無効です', 'リンクの有効期限が切れているか、すでに使用されています。', 400, home_url('/register/'), '登録ページへ');
                }
                SS_View::render('verify', array('title' => '登録の確認', 'token' => $token));
                break;

            case 'invite':
                $token = (string) get_query_var('ss_token');
                $row = SS_System::peek_token($token, 'invite');
                if (!$row) {
                    SS_View::message('招待リンクが無効です', '有効期限が切れているか、すでに使用されています。管理者に再招待を依頼してください。', 400, home_url('/login/'), 'ログインページへ');
                }
                $user = SS_System::user_by_id($row['user_id']);
                $tenant = $user ? SS_System::tenant_by_id($user['tenant_id']) : null;
                SS_View::render('invite', array(
                    'title' => 'スタッフ登録',
                    'token' => $token,
                    'tenant_name' => $tenant ? $tenant['name'] : '',
                    'email' => $user ? $user['email'] : '',
                    'err' => $err,
                ));
                break;

            case 'app':
                self::render_app();
                break;
        }
    }

    private static function render_app() {
        if (!is_user_logged_in()) {
            wp_safe_redirect(home_url('/login/'));
            exit;
        }
        $user = SS_Context::user();
        $tenant = SS_Context::tenant();
        $requested = (string) get_query_var('ss_tenant');
        // 自分のお客様以外のIDは、存在の有無も分からないよう同じ「見つかりません」を返す
        if (!$user || !$tenant || !hash_equals((string) $tenant['public_id'], $requested)) {
            SS_View::message('ページが見つかりません', 'お探しのページは存在しないか、表示する権限がありません。', 404, home_url('/login/'), 'ログインページへ');
        }

        $path = trim((string) get_query_var('ss_path'), '/');
        $common = array(
            'tenant'  => $tenant,
            'me'      => $user,
            'status'  => SS_Tenants::effective_status($tenant),
            'nav'     => self::nav($tenant, $path),
            'logout'  => wp_logout_url(home_url('/login/')),
        );

        if ($path === '') {
            $repo = SS_Repo::current();
            SS_View::render('app-dashboard', array_merge($common, array(
                'title'       => 'ダッシュボード',
                'staff_count' => $repo->count('staff', array('active' => 1)),
                'staff_limit' => SS_Tenants::staff_limit($tenant),
                'days_left'   => SS_Tenants::trial_days_left($tenant),
                'plans'       => SS_Tenants::plans(),
            )));
        }
        if ($path === 'staff') {
            if (!current_user_can('shift_manage_staff')) {
                SS_View::message('権限がありません', 'この画面は管理者のみ利用できます。', 403, SS_View::app_url($tenant['public_id']), 'ダッシュボードへ');
            }
            $config = array(
                'rest'     => esc_url_raw(rest_url('shift/v1/')),
                'nonce'    => wp_create_nonce('wp_rest'),
                'writable' => SS_Tenants::is_writable($tenant),
                'limit'    => SS_Tenants::staff_limit($tenant),
            );
            SS_View::render('app-staff', array_merge($common, array(
                'title'   => 'スタッフ管理',
                'config'  => $config,
                'scripts' => SS_URL . 'assets/staff.js?ver=' . SS_VERSION,
            )));
        }
        SS_View::message('ページが見つかりません', 'お探しのページは存在しないか、準備中です。', 404, SS_View::app_url($tenant['public_id']), 'ダッシュボードへ');
    }

    private static function nav($tenant, $current) {
        $items = array(array('', 'ダッシュボード'));
        if (current_user_can('shift_manage_staff')) {
            $items[] = array('staff', 'スタッフ');
        }
        $nav = array();
        foreach ($items as $item) {
            $nav[] = array('url' => SS_View::app_url($tenant['public_id'], $item[0]), 'label' => $item[1], 'current' => $item[0] === $current);
        }
        return $nav;
    }
}
