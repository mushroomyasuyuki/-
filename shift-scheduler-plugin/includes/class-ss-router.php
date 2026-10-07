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
        add_action('init', array(__CLASS__, 'maybe_flush'), 99);
    }

    /** 有効化の時点で反映されなかった場合に備え、バージョンが変わったら一度だけURLの割り当てを作り直す。 */
    public static function maybe_flush() {
        if (get_option('ss_rewrite_version') !== SS_VERSION) {
            flush_rewrite_rules(false);
            update_option('ss_rewrite_version', SS_VERSION);
        }
    }

    /** 「きれいなURL」（/register/ など）が使える設定か。 */
    public static function pretty_enabled() {
        $structure = (string) get_option('permalink_structure');
        return $structure !== '' && strpos($structure, '/index.php') !== 0;
    }

    /**
     * 各ページのURL。パーマリンクが「基本」などの場合は、
     * /?ss_page=register のような形式にして、設定に関係なく表示できるようにする。
     */
    public static function url($page, $arg = '', $path = '') {
        $pretty = self::pretty_enabled();
        switch ($page) {
            case 'register':
            case 'login':
                return $pretty ? home_url('/' . $page . '/') : add_query_arg('ss_page', $page, home_url('/'));
            case 'verify':
            case 'invite':
                return $pretty
                    ? home_url('/' . $page . '/' . $arg . '/')
                    : add_query_arg(array('ss_page' => $page, 'ss_token' => $arg), home_url('/'));
            case 'app':
                $path = trim((string) $path, '/');
                if ($pretty) {
                    return home_url('/s/' . $arg . '/' . ($path === '' ? '' : $path . '/'));
                }
                $args = array('ss_page' => 'app', 'ss_tenant' => $arg);
                if ($path !== '') {
                    $args['ss_path'] = $path;
                }
                return add_query_arg($args, home_url('/'));
        }
        return home_url('/');
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
        if (!$page || !in_array($page, array('register', 'login', 'verify', 'invite', 'app'), true)) {
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
                    SS_View::message('リンクが無効です', 'リンクの有効期限が切れているか、すでに使用されています。', 400, SS_Router::url('register'), '登録ページへ');
                }
                SS_View::render('verify', array('title' => '登録の確認', 'token' => $token));
                break;

            case 'invite':
                $token = (string) get_query_var('ss_token');
                $row = SS_System::peek_token($token, 'invite');
                if (!$row) {
                    SS_View::message('招待リンクが無効です', '有効期限が切れているか、すでに使用されています。管理者に再招待を依頼してください。', 400, SS_Router::url('login'), 'ログインページへ');
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
            wp_safe_redirect(SS_Router::url('login'));
            exit;
        }
        $user = SS_Context::user();
        $tenant = SS_Context::tenant();
        $requested = (string) get_query_var('ss_tenant');
        // 自分のお客様以外のIDは、存在の有無も分からないよう同じ「見つかりません」を返す
        if (!$user || !$tenant || !hash_equals((string) $tenant['public_id'], $requested)) {
            SS_View::message('ページが見つかりません', 'お探しのページは存在しないか、表示する権限がありません。', 404, SS_Router::url('login'), 'ログインページへ');
        }

        $path = trim((string) get_query_var('ss_path'), '/');
        if (!preg_match('/^[A-Za-z0-9\/_-]*$/', $path)) {
            $path = '-';
        }
        $common = array(
            'tenant'  => $tenant,
            'me'      => $user,
            'tenant_status' => SS_Tenants::effective_status($tenant),
            'nav'     => self::nav($tenant, $path),
            'logout'  => wp_logout_url(SS_Router::url('login')),
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
        $pages = array(
            'staff'       => array('cap' => 'shift_manage_staff', 'template' => 'app-staff', 'title' => 'スタッフ管理', 'script' => 'staff.js', 'page' => 'staff'),
            'patterns'    => array('cap' => 'shift_manage_schedule', 'template' => 'app-page', 'title' => '勤務区分', 'script' => 'pages.js', 'page' => 'patterns'),
            'rules'       => array('cap' => 'shift_manage_schedule', 'template' => 'app-page', 'title' => 'ルール設定', 'script' => 'pages.js', 'page' => 'rules'),
            'requests'    => array('cap' => 'shift_manage_schedule', 'template' => 'app-page', 'title' => '希望の収集', 'script' => 'pages.js', 'page' => 'requests'),
            'me/requests' => array('cap' => 'shift_submit_requests', 'needs_staff' => true, 'template' => 'app-page', 'title' => '希望休の提出', 'script' => 'pages.js', 'page' => 'me-requests'),
        );
        if (isset($pages[$path])) {
            $def = $pages[$path];
            if (!current_user_can($def['cap']) || (!empty($def['needs_staff']) && empty($user['staff_id']))) {
                SS_View::message('権限がありません', 'この画面は利用できません。', 403, SS_View::app_url($tenant['public_id']), 'ダッシュボードへ');
            }
            $config = array(
                'page'     => $def['page'],
                'rest'     => esc_url_raw(rest_url('shift/v1/')),
                'nonce'    => wp_create_nonce('wp_rest'),
                'writable' => SS_Tenants::is_writable($tenant),
                'limit'    => SS_Tenants::staff_limit($tenant),
                'industry' => $tenant['industry'],
                'today'    => wp_date('Y-m-d'),
            );
            SS_View::render($def['template'], array_merge($common, array(
                'title'   => $def['title'],
                'config'  => $config,
                'scripts' => SS_URL . 'assets/' . $def['script'] . '?ver=' . SS_VERSION,
            )));
        }
        SS_View::message('ページが見つかりません', 'お探しのページは存在しないか、準備中です。', 404, SS_View::app_url($tenant['public_id']), 'ダッシュボードへ');
    }

    private static function nav($tenant, $current) {
        $items = array(array('', 'ダッシュボード'));
        if (current_user_can('shift_manage_staff')) {
            $items[] = array('staff', 'スタッフ');
        }
        if (current_user_can('shift_manage_schedule')) {
            $items[] = array('patterns', '勤務区分');
            $items[] = array('rules', 'ルール');
            $items[] = array('requests', '希望の収集');
        }
        $me = SS_Context::user();
        if ($me && !empty($me['staff_id']) && current_user_can('shift_submit_requests')) {
            $items[] = array('me/requests', '希望休の提出');
        }
        $nav = array();
        foreach ($items as $item) {
            $nav[] = array('url' => SS_View::app_url($tenant['public_id'], $item[0]), 'label' => $item[1], 'current' => $item[0] === $current);
        }
        return $nav;
    }
}
