<?php
/** WordPress管理画面（運営用）：各ページのURLの案内。設定 → シフト作成。 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
    }

    public static function menu() {
        add_options_page('シフト作成', 'シフト作成', 'manage_options', 'shift-scheduler', array(__CLASS__, 'page'));
    }

    /** プラグイン一覧の画面に、登録ページ・ログインページのURLを案内する。 */
    public static function notice() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'plugins' || !current_user_can('manage_options')) {
            return;
        }
        printf(
            '<div class="notice notice-info"><p><strong>シフト作成：</strong> お客様向けの登録ページは <a href="%1$s" target="_blank" rel="noopener">%1$s</a> です（<a href="%2$s">URLの一覧</a>）。</p></div>',
            esc_url(SS_Router::url('register')),
            esc_url(admin_url('options-general.php?page=shift-scheduler'))
        );
    }

    public static function page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $pretty = SS_Router::pretty_enabled();
        echo '<div class="wrap"><h1>シフト作成</h1>';
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
