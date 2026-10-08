<?php
/**
 * WordPress管理画面（運営用）：「シフト作成」メニュー。
 *  - 利用状況（何社が使っているか など）とお客様の一覧
 *  - お客様向けページのURLの案内
 * WordPressの管理者（manage_options）だけが見られる。
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Admin {
    const PAGE = 'shift-scheduler';
    const PAGE_URLS = 'shift-scheduler-urls';
    const PAGE_BILLING = 'shift-scheduler-billing';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_action('admin_post_ss_export_tenants', array(__CLASS__, 'export_csv'));
        add_action('admin_post_ss_save_payjp', array(__CLASS__, 'save_payjp'));
        add_action('admin_post_ss_check_payjp', array(__CLASS__, 'check_payjp'));
        add_action('admin_post_ss_save_plans', array(__CLASS__, 'save_plans'));
        add_action('admin_post_ss_migrate_plan', array(__CLASS__, 'migrate_plan'));
    }

    public static function menu() {
        add_menu_page('シフト作成：利用状況', 'シフト作成', 'manage_options', self::PAGE, array(__CLASS__, 'stats_page'), 'dashicons-calendar-alt', 58);
        add_submenu_page(self::PAGE, '利用状況・お客様一覧', '利用状況・お客様一覧', 'manage_options', self::PAGE, array(__CLASS__, 'stats_page'));
        add_submenu_page(self::PAGE, 'プラン・決済設定', 'プラン・決済設定', 'manage_options', self::PAGE_BILLING, array(__CLASS__, 'billing_page'));
        add_submenu_page(self::PAGE, 'ページのURL', 'ページのURL', 'manage_options', self::PAGE_URLS, array(__CLASS__, 'urls_page'));
    }

    /** プラグイン一覧の画面に、利用状況・登録ページのURLを案内する。 */
    public static function notice() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'plugins' || !current_user_can('manage_options')) {
            return;
        }
        printf(
            '<div class="notice notice-info"><p><strong>シフト作成：</strong> お客様向けの登録ページは <a href="%1$s" target="_blank" rel="noopener">%1$s</a> です（<a href="%2$s">利用状況を見る</a>／<a href="%3$s">URLの一覧</a>）。</p></div>',
            esc_url(SS_Router::url('register')),
            esc_url(admin_url('admin.php?page=' . self::PAGE)),
            esc_url(admin_url('admin.php?page=' . self::PAGE_URLS))
        );
    }

    /* ---------- 利用状況 ---------- */

    private static function card($label, $value, $sub = '') {
        return '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 18px;min-width:150px">'
            . '<div style="color:#50575e;font-size:13px">' . esc_html($label) . '</div>'
            . '<div style="font-size:30px;font-weight:600;line-height:1.3">' . esc_html($value) . '</div>'
            . ($sub !== '' ? '<div style="color:#50575e;font-size:12px">' . esc_html($sub) . '</div>' : '')
            . '</div>';
    }

    public static function stats_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = SS_Stats::summary();
        $by = $s['by_status'];
        $paying = $by['active'] + $by['grace'];

        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $list = SS_Stats::tenants(array('search' => $search, 'status' => $status, 'page' => $paged, 'per_page' => 50));
        $pages = max(1, (int) ceil($list['total'] / 50));

        echo '<div class="wrap"><h1>シフト作成：利用状況</h1>';
        echo '<p style="color:#50575e">お客様のシフトの中身（勤務・希望休）は集計に含まれません。登録・状態・人数・利用の有無（最終利用日）だけを集計しています。</p>';

        echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin:16px 0">';
        echo self::card('ご利用中のお客様（社）', number_format($s['total']), '認証待ち ' . $by['unverified'] . '社は含みません');
        echo self::card('有料契約中（社）', number_format($paying), $by['grace'] ? 'うち支払い確認中 ' . $by['grace'] . '社' : '');
        echo self::card('無料期間中（社）', number_format($by['trial']), '14日以内に終了 ' . $s['trial_ending'] . '社');
        echo self::card('閲覧のみ（社）', number_format($by['readonly']), '無料期間が終わって未契約');
        echo self::card('停止中（社）', number_format($by['suspended']));
        echo self::card('月額の見込み（円）', number_format($s['mrr']), '契約中のプランの月額の合計');
        echo '</div>';

        echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin:16px 0">';
        echo self::card('今日の新規登録（社）', number_format($s['signups']['today']));
        echo self::card('7日間の新規登録（社）', number_format($s['signups']['d7']));
        echo self::card('30日間の新規登録（社）', number_format($s['signups']['d30']));
        $rate = $s['total'] > 0 ? round($s['active_30d'] * 100 / $s['total']) . '%' : '-';
        echo self::card('30日以内に利用した（社）', number_format($s['active_30d']), '全体の ' . $rate . '／7日以内 ' . $s['active_7d'] . '社');
        echo self::card('スタッフの合計（名）', number_format($s['staff_total']));
        echo self::card('作成されたシフト表（件）', number_format($s['schedules']), '公開中 ' . $s['schedules_pub'] . '件');
        echo '</div>';

        // 月別の新規登録と業種別
        $max = max(1, max($s['months']));
        echo '<div style="display:flex;flex-wrap:wrap;gap:32px;margin:24px 0">';
        echo '<div><h2>月別の新規登録（直近12か月）</h2><table class="widefat striped" style="min-width:360px"><tbody>';
        foreach ($s['months'] as $m => $n) {
            printf(
                '<tr><td style="width:80px">%s</td><td style="width:50px;text-align:right">%d社</td><td><div style="background:#2271b1;height:12px;border-radius:3px;width:%dpx"></div></td></tr>',
                esc_html($m), (int) $n, (int) round($n * 200 / $max)
            );
        }
        echo '</tbody></table></div>';
        echo '<div><h2>業種別</h2><table class="widefat striped"><tbody>';
        foreach ($s['industry'] as $k => $n) {
            printf('<tr><td>%s</td><td style="text-align:right">%d社</td></tr>', esc_html(SS_Stats::industry_label($k)), (int) $n);
        }
        echo '</tbody></table></div></div>';

        // お客様一覧
        echo '<h2>お客様の一覧（' . (int) $list['total'] . '社）</h2>';
        echo '<form method="get" style="margin:8px 0"><input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '">';
        echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="事業者名で検索"> ';
        echo '<select name="status"><option value="">すべての状態</option>';
        foreach (array('trial' => '無料期間中', 'active' => 'ご契約中', 'grace' => 'お支払い確認中', 'readonly' => '閲覧のみ', 'suspended' => '停止中', 'unverified' => 'メール認証待ち') as $k => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($status, $k, false), esc_html($label));
        }
        echo '</select> <button class="button">絞り込む</button> ';
        $export = wp_nonce_url(admin_url('admin-post.php?action=ss_export_tenants&s=' . rawurlencode($search) . '&status=' . rawurlencode($status)), 'ss_export_tenants');
        echo '<a class="button" href="' . esc_url($export) . '">CSVでダウンロード</a></form>';

        echo '<table class="widefat striped"><thead><tr><th>登録日</th><th>事業者名</th><th>業種</th><th>状態</th><th>管理者のメール</th><th>無料期間の終了日</th><th>スタッフ</th><th>シフト表</th><th>最終利用日</th></tr></thead><tbody>';
        if (!$list['rows']) {
            echo '<tr><td colspan="9">該当するお客様はいません。</td></tr>';
        }
        foreach ($list['rows'] as $r) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%d名</td><td>%d件</td><td>%s</td></tr>',
                esc_html(substr($r['created_at'], 0, 10)), esc_html($r['name']), esc_html(SS_Stats::industry_label($r['industry'])),
                esc_html(SS_Tenants::status_label($r['effective_status'])), esc_html((string) $r['owner_email']),
                esc_html($r['trial_end'] ? substr($r['trial_end'], 0, 10) : '-'), (int) $r['staff_count'], (int) $r['schedule_count'],
                esc_html($r['last_login'] ? substr($r['last_login'], 0, 10) : '-')
            );
        }
        echo '</tbody></table>';

        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links(array(
                'base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages,
                'prev_text' => '‹', 'next_text' => '›',
            ));
            echo '</div></div>';
        }
        echo '</div>';
    }

    public static function export_csv() {
        if (!current_user_can('manage_options')) {
            wp_die('権限がありません。', '', array('response' => 403));
        }
        check_admin_referer('ss_export_tenants');
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $list = SS_Stats::tenants(array('search' => $search, 'status' => $status, 'page' => 1, 'per_page' => 500));
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="shift-tenants-' . gmdate('Ymd') . '.csv"');
        echo SS_Stats::csv($list['rows']); // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /* ---------- URLの案内 ---------- */

    public static function urls_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $pretty = SS_Router::pretty_enabled();
        echo '<div class="wrap"><h1>シフト作成：ページのURL</h1>';
        echo '<p>このプラグインのページは、WordPressの「固定ページ」としては作られません。次のURLで表示されます。</p>';
        echo '<table class="widefat striped" style="max-width:760px"><tbody>';
        printf('<tr><th>無料登録ページ</th><td><a href="%1$s" target="_blank" rel="noopener">%1$s</a></td></tr>', esc_url(SS_Router::url('register')));
        printf('<tr><th>ログインページ</th><td><a href="%1$s" target="_blank" rel="noopener">%1$s</a></td></tr>', esc_url(SS_Router::url('login')));
        echo '</tbody></table>';
        echo '<p>サイトのメニューやボタンに、上のURLを設定してください。</p>';
        if (!$pretty) {
            echo '<div class="notice notice-warning inline"><p>現在のパーマリンク設定では、URLが <code>?ss_page=register</code> のような形式になります。URLをすっきりさせたい場合は、「設定 → パーマリンク」で「投稿名」などを選んで保存してください。</p></div>';
        }
        echo '</div>';
    }

    /* ---------- プラン・決済設定 ---------- */

    private static function flash($type, $message) {
        set_transient('ss_admin_flash_' . get_current_user_id(), array($type, $message), 120);
    }

    private static function back_billing() {
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_BILLING));
        exit;
    }

    private static function guard($action) {
        if (!current_user_can('manage_options')) {
            wp_die('権限がありません。', '', array('response' => 403));
        }
        check_admin_referer($action);
    }

    private static function mode_label($key) {
        if (strpos($key, '_live_') !== false) { return '本番'; }
        if (strpos($key, '_test_') !== false) { return 'テスト'; }
        return '不明';
    }

    public static function billing_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $flash = get_transient('ss_admin_flash_' . get_current_user_id());
        if ($flash) {
            delete_transient('ss_admin_flash_' . get_current_user_id());
            printf('<div class="notice notice-%s"><p>%s</p></div>', $flash[0] === 'ok' ? 'success' : 'error', esc_html($flash[1]));
        }
        $secret = SS_Payjp::secret_key();
        $locked = array(
            'public' => defined('SS_PAYJP_PUBLIC_KEY'), 'secret' => defined('SS_PAYJP_SECRET_KEY'), 'token' => defined('SS_PAYJP_WEBHOOK_TOKEN'),
        );
        echo '<div class="wrap"><h1>プラン・決済設定</h1>';

        // 1) PAY.JP の接続
        echo '<h2>1. PAY.JP との接続</h2>';
        if (SS_Payjp::configured()) {
            echo '<p>状態：<strong>設定済み</strong>（キーの種類：' . esc_html(self::mode_label($secret)) . 'モード）';
            if (self::mode_label($secret) !== self::mode_label(SS_Payjp::public_key())) {
                echo ' <span style="color:#b32d2e">※公開キーとシークレットキーのモードが違います</span>';
            }
            echo '</p>';
        } else {
            echo '<p>状態：<strong style="color:#b32d2e">未設定</strong>（キーを入力するまで、お客様は契約できません）</p>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ss_save_payjp');
        echo '<input type="hidden" name="action" value="ss_save_payjp"><table class="form-table"><tbody>';
        printf('<tr><th>公開キー（pk_…）</th><td><input type="text" class="regular-text" name="public" value="%s" autocomplete="off"%s></td></tr>', esc_attr(SS_Payjp::public_key()), $locked['public'] ? ' disabled' : '');
        printf('<tr><th>シークレットキー（sk_…）</th><td><input type="password" class="regular-text" name="secret" value="" autocomplete="new-password" placeholder="%s"%s><p class="description">空欄のままなら、今の設定を変えません。サーバーには保存されますが、画面には表示しません。<br>より安全にするには、wp-config.php に <code>define(\'SS_PAYJP_SECRET_KEY\', \'sk_…\');</code> と書く方法もあります。</p></td></tr>', $secret !== '' ? esc_attr('設定済み（末尾 ' . substr($secret, -4) . '）') : '', $locked['secret'] ? ' disabled' : '');
        printf('<tr><th>Webhookのトークン</th><td><input type="text" class="regular-text" name="token" value="%s" autocomplete="off"%s><p class="description">PAY.JPの管理画面でWebhookを登録すると表示される、確認用のトークンを貼り付けます。</p></td></tr>', esc_attr(SS_Payjp::webhook_token()), $locked['token'] ? ' disabled' : '');
        echo '<tr><th>WebhookのURL</th><td><code>' . esc_html(rest_url('shift/v1/payjp/webhook')) . '</code><p class="description">PAY.JPの管理画面（Webhookの設定）に、このURLを登録します。通知の種類は、定期課金（subscription）と支払い（charge）に関するものを選びます。</p></td></tr>';
        echo '</tbody></table><p><button class="button button-primary">キーを保存</button> ';
        echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=ss_check_payjp'), 'ss_check_payjp')) . '">接続を確認</a></p></form>';

        // 2) プラン
        echo '<h2>2. プラン</h2>';
        echo '<p>お客様が選べるプランです。<strong>金額を変えると、PAY.JPに新しいプランを作ります。</strong>すでに契約中のお客様は、下の「切り替える」を押すまで、これまでの金額のままです。値上げ・値下げは、事前にお客様へお知らせしてください（利用規約にも記載が必要です）。</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ss_save_plans');
        echo '<input type="hidden" name="action" value="ss_save_plans">';
        echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>名前</th><th>スタッフ数の上限</th><th>月額（円・税込）</th><th>表示順</th><th>有効</th><th>PAY.JPへの反映</th><th>契約中のお客様</th></tr></thead><tbody>';
        global $wpdb;
        $plans = $wpdb->get_results('SELECT * FROM ' . SS_System::table('plans') . ' ORDER BY sort_order ASC, id ASC', ARRAY_A);
        $plans[] = array('id' => 0, 'name' => '', 'max_staff' => '', 'price' => '', 'sort_order' => count($plans) + 1, 'active' => 1, 'payjp_plan_id' => '', 'payjp_amount' => 0);
        foreach ($plans as $i => $p) {
            $id = (int) $p['id'];
            $synced = $id > 0 && SS_Billing::plan_ready($p) || ($id > 0 && (int) $p['active'] === 0 && $p['payjp_plan_id'] !== '' && (int) $p['payjp_amount'] === (int) $p['price']);
            $subs = $id > 0 ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SS_System::table('tenants') . " WHERE plan_id = %d AND payjp_subscription_id <> '' AND deleted_at IS NULL", $id)) : 0;
            $stale = $id > 0 ? count(SS_Billing::stale_subscribers($id)) : 0;
            printf('<tr><td><input type="hidden" name="plans[%1$d][id]" value="%2$d"><input type="text" name="plans[%1$d][name]" value="%3$s" maxlength="60" placeholder="%4$s"></td>', $i, $id, esc_attr($p['name']), $id ? '' : '新しいプランの名前');
            printf('<td><input type="number" name="plans[%d][max_staff]" value="%s" min="1" max="1000" style="width:90px"></td>', $i, esc_attr($p['max_staff']));
            printf('<td><input type="number" name="plans[%d][price]" value="%s" min="50" max="1000000" style="width:100px"></td>', $i, esc_attr($p['price']));
            printf('<td><input type="number" name="plans[%d][sort_order]" value="%s" style="width:60px"></td>', $i, esc_attr($p['sort_order']));
            printf('<td><input type="checkbox" name="plans[%d][active]" value="1"%s></td>', $i, (int) $p['active'] === 1 ? ' checked' : '');
            echo '<td>' . ($id === 0 ? '-' : ($synced ? '反映済み' : '<span style="color:#b32d2e">未反映（お客様は選べません）</span>')) . '</td>';
            echo '<td>' . ($id === 0 ? '-' : esc_html($subs . '社')) . ($stale > 0 ? ' <span style="color:#b32d2e">（うち旧金額のまま ' . (int) $stale . '社）</span> <a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=ss_migrate_plan&plan_id=' . $id), 'ss_migrate_plan')) . '">切り替える（20社ずつ）</a>' : '') . '</td></tr>';
        }
        echo '</tbody></table><p><button class="button button-primary">プランを保存</button></p></form>';
        if (!SS_Payjp::configured()) {
            echo '<p class="description">※ PAY.JPのキーが未設定の間は、保存だけ行います。キーを設定してからもう一度「プランを保存」を押すと、PAY.JPに反映されます。</p>';
        }
        echo '</div>';
    }

    public static function save_payjp() {
        self::guard('ss_save_payjp');
        $errors = array();
        $pub = isset($_POST['public']) ? sanitize_text_field(wp_unslash($_POST['public'])) : null;
        $sec = isset($_POST['secret']) ? trim(sanitize_text_field(wp_unslash($_POST['secret']))) : '';
        $tok = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : null;
        if ($pub !== null && !defined('SS_PAYJP_PUBLIC_KEY')) {
            if ($pub !== '' && strpos($pub, 'pk_') !== 0) { $errors[] = '公開キーは「pk_」で始まる文字列です。'; } else { update_option('ss_payjp_public', $pub, false); }
        }
        $mode_changed = false;
        if ($sec !== '' && !defined('SS_PAYJP_SECRET_KEY')) {
            if (strpos($sec, 'sk_') !== 0) {
                $errors[] = 'シークレットキーは「sk_」で始まる文字列です。';
            } else {
                $mode_changed = SS_Payjp::secret_key() !== '' && self::mode_label(SS_Payjp::secret_key()) !== self::mode_label($sec);
                update_option('ss_payjp_secret', $sec, false);
            }
        }
        if ($mode_changed) {
            SS_Billing::reset_plan_sync(); // テスト⇔本番でプランIDは別物
        }
        if ($tok !== null && !defined('SS_PAYJP_WEBHOOK_TOKEN')) {
            update_option('ss_payjp_webhook_token', $tok, false);
        }
        $ok_msg = '保存しました。「接続を確認」で、PAY.JPと通信できるか確かめてください。';
        if ($mode_changed) {
            $ok_msg .= ' ※テストと本番を切り替えたため、プランのPAY.JPへの反映を取り消しました。下の「プランを保存」を押して、新しいモードに作り直してください。テスト中に作ったお客様の契約情報は、本番では使えません（公開前に、テスト用のお客様を削除してください）。';
        }
        self::flash($errors ? 'error' : 'ok', $errors ? implode(' ', $errors) : $ok_msg);
        self::back_billing();
    }

    public static function check_payjp() {
        self::guard('ss_check_payjp');
        if (!SS_Payjp::configured()) {
            self::flash('error', 'キーが未設定です。');
            self::back_billing();
        }
        $r = SS_Payjp::request('GET', 'plans', array('limit' => 1));
        if (is_wp_error($r)) {
            self::flash('error', 'PAY.JPに接続できませんでした：' . $r->get_error_message() . '（キーが正しいか、確認してください）');
        } else {
            $msg = 'PAY.JPに接続できました（' . self::mode_label(SS_Payjp::secret_key()) . 'モード）。';
            if (SS_Payjp::webhook_token() === '') {
                $msg .= ' ※Webhookのトークンが未設定です。設定しないと、PAY.JPからの通知を受け取れません（毎日の自動確認では、状態は更新されます）。';
            }
            self::flash('ok', $msg);
        }
        self::back_billing();
    }

    public static function save_plans() {
        self::guard('ss_save_plans');
        $rows = isset($_POST['plans']) && is_array($_POST['plans']) ? wp_unslash($_POST['plans']) : array();
        $res = SS_Billing::save_plans(array_values($rows));
        $msg = $res['saved'] . '件のプランを保存しました。';
        if ($res['synced']) {
            $msg .= 'PAY.JPに' . $res['synced'] . '件のプランを作成しました。';
        }
        if ($res['errors']) {
            $msg .= ' ' . implode(' ', $res['errors']);
        }
        self::flash($res['errors'] ? 'error' : 'ok', $msg);
        self::back_billing();
    }

    public static function migrate_plan() {
        self::guard('ss_migrate_plan');
        $id = isset($_GET['plan_id']) ? (int) $_GET['plan_id'] : 0;
        $r = SS_Billing::migrate_plan_subscribers($id, 20);
        $msg = $r['migrated'] . '社を新しい金額に切り替えました（新しい料金は、各お客様の次回の請求日から）。';
        if ($r['failed']) { $msg .= '（' . $r['failed'] . '社は切り替えられませんでした。カードの状態などをご確認ください）'; }
        if (!empty($r['skipped'])) { $msg .= $r['skipped'] . '社は、お支払い確認中・解約予約中のため、スキップしました（状態が戻ってから、もう一度押してください）。'; }
        if ($r['remaining']) { $msg .= '残り ' . $r['remaining'] . '社。もう一度押してください。'; }
        self::flash($r['failed'] ? 'error' : 'ok', $msg);
        self::back_billing();
    }
}
