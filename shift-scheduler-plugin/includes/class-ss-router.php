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
        $requested = (string) get_query_var('ss_tenant');
        $path = trim((string) get_query_var('ss_path'), '/');
        if ($path === 'login') {
            self::render_staff_login($requested);
        }
        if (!is_user_logged_in()) {
            wp_safe_redirect(SS_Router::url('login'));
            exit;
        }
        $user = SS_Context::user();
        $tenant = SS_Context::tenant();
        // 自分のお客様以外のIDは、存在の有無も分からないよう同じ「見つかりません」を返す
        if (!$user || !$tenant || !hash_equals((string) $tenant['public_id'], $requested)) {
            SS_View::message('ページが見つかりません', 'お探しのページは存在しないか、表示する権限がありません。', 404, SS_Router::url('login'), 'ログインページへ');
        }

        SS_Auth::touch_last_seen($user);
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
            self::render_top($tenant, $user, $common);
        }
        $pages = array(
            'staff'       => array('cap' => 'shift_manage_staff', 'template' => 'app-staff', 'title' => 'スタッフ管理', 'script' => 'staff.js', 'page' => 'staff'),
            'patterns'    => array('cap' => 'shift_manage_schedule', 'template' => 'app-page', 'title' => '勤務区分', 'script' => array('ui.js', 'pages.js'), 'page' => 'patterns'),
            'rules'       => array('cap' => 'shift_manage_schedule', 'template' => 'app-page', 'title' => 'ルール設定', 'script' => array('ui.js', 'pages.js'), 'page' => 'rules'),
            'requests'    => array('cap' => 'shift_manage_schedule', 'template' => 'app-page', 'title' => '希望の収集', 'script' => array('ui.js', 'pages.js'), 'page' => 'requests'),
            'me/requests' => array('cap' => 'shift_submit_requests', 'needs_staff' => true, 'template' => 'app-page', 'title' => '希望休の提出', 'script' => array('ui.js', 'pages.js'), 'page' => 'me-requests'),
        );
        $pages['schedules'] = array('cap' => 'shift_manage_schedule', 'template' => 'app-page', 'title' => 'シフト表', 'script' => array('ui.js', 'pages.js'), 'page' => 'schedules');
        $pages['me/schedule'] = array('cap' => 'shift_view', 'template' => 'app-page', 'title' => 'シフトを見る', 'script' => array('ui.js', 'pages.js'), 'page' => 'me-schedule');
        $pages['billing'] = array('cap' => 'shift_manage_billing', 'template' => 'app-page', 'title' => 'ご契約・お支払い', 'script' => array('ui.js', 'pages.js'), 'page' => 'billing');
        // マッシュオフィスシステムから移した画面（全幅で表示）
        $office = self::office_pages();
        foreach ($office as $key => $def) {
            $pages[$key] = $def;
        }
        $pages['settings'] = array('cap' => 'shift_manage_settings', 'template' => 'app-page', 'title' => '設定', 'script' => array('ui.js', 'pages.js'), 'page' => 'settings');
        $extra = array();
        if (preg_match('#^schedules/(\d+)$#', $path, $m)) {
            // シフト表の編集画面（計算用のスクリプトを先に読み込む）
            $pages[$path] = array('cap' => 'shift_manage_schedule', 'template' => 'app-page', 'title' => 'シフト表の編集', 'script' => array('ui.js', 'solver.js', 'schedule.js'), 'page' => 'schedule-edit');
            $extra = array(
                'schedule_id' => (int) $m[1],
                'worker_url'  => SS_URL . 'assets/solver-worker.js?ver=' . SS_VERSION,
                'back_url'    => SS_View::app_url($tenant['public_id'], 'schedules'),
            );
        }
        if (isset($pages[$path])) {
            $def = $pages[$path];
            if (!current_user_can($def['cap']) || (!empty($def['needs_staff']) && empty($user['staff_id']))) {
                SS_View::message('権限がありません', 'この画面は利用できません。', 403, SS_View::app_url($tenant['public_id']), 'トップへ');
            }
            if (!empty($def['office'])) {
                self::render_office($tenant, $user, $path, $def, $common);
            }
            $config = array(
                'page'     => $def['page'],
                'rest'     => esc_url_raw(rest_url('shift/v1/')),
                'nonce'    => wp_create_nonce('wp_rest'),
                'writable' => SS_Tenants::is_writable($tenant),
                'limit'    => SS_Tenants::staff_limit($tenant),
                'industry' => $tenant['industry'],
                'today'    => wp_date('Y-m-d'),
                'schedule_url' => SS_View::app_url($tenant['public_id'], 'schedules/__ID__'),
            );
            $config['staff_login_url'] = SS_View::app_url($tenant['public_id'], 'login');
            if ($def['page'] === 'billing') {
                $config['legal'] = SS_Legal::links_map(); // 特定商取引法に基づく表記・利用規約・プライバシーポリシー
            }
            $config = array_merge($config, $extra);
            $scripts = array();
            foreach ((array) $def['script'] as $file) {
                $scripts[] = SS_URL . 'assets/' . $file . '?ver=' . SS_VERSION;
            }
            SS_View::render($def['template'], array_merge($common, array(
                'title'   => $def['title'],
                'config'  => $config,
                'scripts' => $scripts,
            )));
        }
        SS_View::message('ページが見つかりません', 'お探しのページは存在しないか、準備中です。', 404, SS_View::app_url($tenant['public_id']), 'トップへ');
    }

    /**
     * お客様ごとの、スタッフ用ログインページ（社員番号＋パスワード）。
     * 存在しないお客様IDでも、同じ画面を出す（お客様IDが正しいかどうかを、外から確かめられないようにする）。
     */
    private static function render_staff_login($public_id) {
        $err = isset($_GET['ss_err']) ? SS_View::error_text(sanitize_key(wp_unslash($_GET['ss_err']))) : '';
        $tenant = preg_match('/^[a-z0-9]{12}$/', $public_id) ? SS_System::tenant_by_public_id($public_id) : null;
        if ($tenant && is_user_logged_in()) {
            $me = SS_Context::user();
            if ($me && (int) $me['tenant_id'] === (int) $tenant['id']) {
                wp_safe_redirect(SS_View::app_url($tenant['public_id']));
                exit;
            }
        }
        SS_View::render('staff-login', array(
            'title'       => 'スタッフのログイン',
            'public_id'   => $public_id,
            'tenant_name' => $tenant && in_array($tenant['status'], array('trial', 'active', 'grace', 'readonly'), true) ? $tenant['name'] : '',
            'err'         => $err,
        ));
    }

    /**
     * お客様のトップページ（マッシュオフィスシステムの「業務システム」トップと同じ構成）。
     * 従業員ページ：日報・朝礼・経理・タイムカード・給与照会・シフト
     * 管理者ページ：従業員登録・管理者集計・シフト作成・給与計算・QRコード生成・自社情報（＋契約者はご契約・設定）
     * まだ移していない機能は url を空にして「準備中」で出す。
     */
    private static function render_top(array $tenant, array $user, array $common) {
        $pid = $tenant['public_id'];
        $u = function ($path) use ($pid) { return SS_View::app_url($pid, $path); };
        $status = SS_Tenants::effective_status($tenant);
        $staff = !empty($user['staff_id']) ? SS_Repo::current()->find('staff', (int) $user['staff_id']) : null;
        $wp_user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $me_name = $staff ? $staff['name'] : ($wp_user && !empty($wp_user->display_name) ? $wp_user->display_name : '');

        $staff_cards = array(
            array('color' => 'nippo', 'icon' => '📝', 'label' => '日報', 'desc' => '本日の予定・実績を入力します', 'url' => $u('nippo')),
            array('color' => 'chorei', 'icon' => '☀️', 'label' => '朝礼', 'desc' => '本日の全体予定・未提出者を確認します', 'url' => $u('chorei')),
            array('color' => 'keiri', 'icon' => '🧾', 'label' => '経理', 'desc' => '見積もり・請求書・顧客一覧・商品一覧', 'url' => ''),
            array('color' => 'timecard', 'icon' => '⏱️', 'label' => 'タイムカード', 'desc' => '出勤・退勤を打刻します', 'url' => ''),
            array('color' => 'myleave', 'icon' => '🌴', 'label' => '給与照会', 'desc' => '自分の給与明細・有給休暇の残日数を確認します', 'url' => ''),
            array('color' => 'shift', 'icon' => '🗓️', 'label' => 'シフト', 'desc' => '公開されたシフトの確認・希望休の提出', 'url' => $u(!empty($user['staff_id']) && current_user_can('shift_submit_requests') ? 'me/requests' : 'me/schedule')),
        );
        $admin_cards = array();
        if (current_user_can('shift_manage_staff')) {
            $admin_cards = array(
                array('color' => 'touroku', 'icon' => '👤', 'label' => '従業員登録', 'desc' => '従業員の登録・社員番号とパスワード・CSVの取り込み', 'url' => $u('staff')),
                array('color' => 'shukei', 'icon' => '📈', 'label' => '管理者集計', 'desc' => '部署別・担当者別の実績を集計します', 'url' => $u('shukei')),
                array('color' => 'shift', 'icon' => '🗓️', 'label' => 'シフト作成', 'desc' => '勤務区分・ルール・希望休の収集・シフト表の自動作成', 'url' => current_user_can('shift_manage_schedule') ? $u('schedules') : ''),
                array('color' => 'kyuyo', 'icon' => '💰', 'label' => '給与計算', 'desc' => '給与明細・賞与・年末調整', 'url' => ''),
                array('color' => 'qr', 'icon' => '🔳', 'label' => 'QRコード生成', 'desc' => 'タイムカード用QRコードの発行', 'url' => ''),
                array('color' => 'jisha', 'icon' => '🏢', 'label' => '自社情報', 'desc' => '会社情報・振込先・印影・書類の保管', 'url' => ''),
            );
            if (current_user_can('shift_manage_billing')) {
                $admin_cards[] = array('color' => 'billing', 'icon' => '💳', 'label' => 'ご契約・お支払い', 'desc' => 'プラン・カード・解約', 'url' => $u('billing'));
            }
            if (current_user_can('shift_manage_settings')) {
                $admin_cards[] = array('color' => 'settings', 'icon' => '⚙️', 'label' => '設定', 'desc' => '会社の設定', 'url' => $u('settings'));
            }
        }

        $notices = array();
        if (current_user_can('shift_manage_billing')) {
            $days = SS_Tenants::trial_days_left($tenant);
            if ($status === 'trial' && $days !== null) {
                $notices[] = array('error' => false, 'text' => '無料期間中です（あと' . (int) $days . '日）。すべての機能をお使いいただけます。', 'url' => $u('billing'), 'link' => 'ご契約・お支払いへ');
            } elseif ($status === 'grace') {
                $notices[] = array('error' => true, 'text' => 'お支払いを確認できませんでした。カードの変更をお願いします。', 'url' => $u('billing'), 'link' => 'ご契約・お支払いへ');
            }
        }
        if ($status === 'readonly') {
            $notices[] = array('error' => true, 'text' => '現在は閲覧のみの状態です。ご契約いただくと、編集を再開できます。', 'url' => current_user_can('shift_manage_billing') ? $u('billing') : '', 'link' => 'ご契約・お支払いへ');
        }
        if (!$staff && current_user_can('shift_manage_staff')) {
            $notices[] = array('error' => false, 'text' => '日報を入力するには、ご自身をスタッフ名簿に登録して、アカウントと紐づけてください（従業員登録で、ご自身の行の「メールで招待」に、ご自身のメールアドレスを入れます）。', 'url' => $u('staff'), 'link' => '従業員登録へ');
        }

        SS_View::render_standalone('app-top', array(
            'tenant' => $tenant, 'me_name' => $me_name, 'logout' => $common['logout'],
            'staff_cards' => $staff_cards, 'admin_cards' => $admin_cards, 'notices' => $notices,
        ));
    }

    /** マッシュオフィスシステムから移した画面の一覧（パス => 定義） */
    public static function office_pages() {
        return array(
            'nippo'  => array('cap' => 'shift_view', 'office' => 'nippo1-fixed.html', 'title' => '日報', 'label' => '日報'),
            'yotei'  => array('cap' => 'shift_view', 'office' => 'yotei-jisseki-input.html', 'title' => '予定・進捗', 'label' => '予定進捗'),
            'chorei' => array('cap' => 'shift_view', 'office' => 'chorei-fixed.html', 'title' => '朝礼（全体スケジュール）', 'label' => '朝礼'),
            'shukei' => array('cap' => 'shift_manage_staff', 'office' => 'kanri-shukei.html', 'title' => '管理者集計', 'label' => '管理者集計'),
        );
    }

    private static function render_office(array $tenant, array $user, $path, array $def, array $common) {
        $repo = SS_Repo::current();
        $staff = !empty($user['staff_id']) ? $repo->find('staff', (int) $user['staff_id']) : null;
        $pid = $tenant['public_id'];
        $links = array('トップ' => SS_View::app_url($pid));
        $labels = array('nippo' => '日報', 'yotei' => '予定進捗', 'chorei' => '朝礼', 'shukei' => '管理者集計');
        $menu = array(array('url' => SS_View::app_url($pid), 'label' => 'トップ', 'current' => false));
        foreach (self::office_pages() as $key => $p) {
            if (current_user_can($p['cap'])) {
                $links[$labels[$key]] = SS_View::app_url($pid, $key);
                $menu[] = array('url' => SS_View::app_url($pid, $key), 'label' => $p['label'], 'current' => $key === $path);
            }
        }
        $menu[] = array('url' => SS_View::app_url($pid, current_user_can('shift_manage_schedule') ? 'schedules' : 'me/schedule'), 'label' => 'シフト', 'current' => false);
        SS_View::render_standalone('office-page', array(
            'title'       => $def['title'],
            'tenant_name' => $tenant['name'],
            'home_url'    => SS_View::app_url($pid),
            'menu'        => $menu,
            'logout'      => $common['logout'],
            'readonly'    => !SS_Tenants::is_writable($tenant),
            'need_staff_link' => !($staff && (int) $staff['active'] === 1) && $path !== 'shukei',
            'office_html' => SS_View::office_html($def['office']),
            'mo_config'   => array(
                'tenant'   => $pid,
                'base'     => esc_url_raw(rest_url(SS_Rest_Office::NS)),
                'nonce'    => wp_create_nonce('wp_rest'),
                'readonly' => !SS_Tenants::is_writable($tenant),
                'links'    => $links,
                'auth'     => array(
                    'staff' => $staff && (int) $staff['active'] === 1 ? array('staffId' => (string) $staff['id'], 'staffName' => $staff['name'], 'dept' => (string) $staff['department']) : null,
                    'admin' => current_user_can('shift_manage_staff'),
                ),
            ),
        ));
    }

    private static function nav($tenant, $current) {
        $items = array(array('', 'トップ'));
        if (current_user_can('shift_manage_staff')) {
            $items[] = array('staff', 'スタッフ');
        }
        if (current_user_can('shift_manage_schedule')) {
            $items[] = array('patterns', '勤務区分');
            $items[] = array('rules', 'ルール');
            $items[] = array('requests', '希望の収集');
            $items[] = array('schedules', 'シフト表');
        }
        $me = SS_Context::user();
        if ($me && !empty($me['staff_id']) && current_user_can('shift_submit_requests')) {
            $items[] = array('me/requests', '希望休の提出');
        }
        $items[] = array('me/schedule', 'シフトを見る');
        if (current_user_can('shift_manage_billing')) {
            $items[] = array('billing', 'ご契約・お支払い');
        }
        if (current_user_can('shift_manage_settings')) {
            $items[] = array('settings', '設定');
        }
        $nav = array();
        foreach ($items as $item) {
            $nav[] = array('url' => SS_View::app_url($tenant['public_id'], $item[0]), 'label' => $item[1], 'current' => $item[0] === $current || ($item[0] === 'schedules' && strpos($current, 'schedules/') === 0));
        }
        return $nav;
    }
}
