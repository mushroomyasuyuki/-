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

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_action('admin_post_ss_export_tenants', array(__CLASS__, 'export_csv'));
    }

    public static function menu() {
        add_menu_page('シフト作成：利用状況', 'シフト作成', 'manage_options', self::PAGE, array(__CLASS__, 'stats_page'), 'dashicons-calendar-alt', 58);
        add_submenu_page(self::PAGE, '利用状況・お客様一覧', '利用状況・お客様一覧', 'manage_options', self::PAGE, array(__CLASS__, 'stats_page'));
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
}
