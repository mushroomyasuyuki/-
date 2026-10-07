<?php
/**
 * プラグインを削除したときの処理：お客様のデータをすべて消す（元に戻せません）。
 *
 * 削除するもの：
 *  - 専用テーブル（お客様・ユーザー・スタッフ・トークン・プラン・決済イベント）
 *  - お客様側のWordPressユーザー（管理者・副管理者・スタッフのアカウント）
 *  - ロール、オプション、一時データ、定期処理
 * ※ 「無効化」では何も消えません。削除（アンインストール）したときだけ実行されます。
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;
require_once ABSPATH . 'wp-admin/includes/user.php';

$users_table = $wpdb->prefix . 'shift_users';

// お客様側のWordPressユーザーを削除（テーブルを消す前に一覧を取る）
$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $users_table));
if ($exists === $users_table) {
    $ids = $wpdb->get_col("SELECT wp_user_id FROM {$users_table}"); // phpcs:ignore
    foreach ((array) $ids as $wp_user_id) {
        $wp_user_id = (int) $wp_user_id;
        $user = $wp_user_id ? get_userdata($wp_user_id) : false;
        // 念のため：管理権限のあるユーザーは消さない
        if ($user && !user_can($user, 'manage_options')) {
            wp_delete_user($wp_user_id);
        }
    }
}
// 上の一覧に残っていない、お客様側ロールのユーザーも削除
foreach (array('shift_owner', 'shift_manager', 'shift_staff') as $role) {
    foreach (get_users(array('role' => $role, 'fields' => 'ID')) as $wp_user_id) {
        $user = get_userdata((int) $wp_user_id);
        if ($user && !user_can($user, 'manage_options')) {
            wp_delete_user((int) $wp_user_id);
        }
    }
}

foreach (array('tenants', 'users', 'staff', 'tokens', 'plans', 'billing_events', 'patterns', 'rules', 'request_periods', 'requests', 'request_submissions', 'schedules', 'entries') as $name) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'shift_' . $name); // phpcs:ignore
}

foreach (array('shift_owner', 'shift_manager', 'shift_staff') as $role) {
    remove_role($role);
}

delete_option('ss_db_version');
delete_option('ss_terms_url');
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ss\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ss\\_rl\\_%'"); // phpcs:ignore
wp_clear_scheduled_hook('ss_daily');
