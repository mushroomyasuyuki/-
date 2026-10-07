<?php
/**
 * プラグイン削除時の処理。
 * お客様のデータを誤って消さないよう、wp-config.php で
 *   define('SS_REMOVE_DATA_ON_UNINSTALL', true);
 * を明示した場合だけ、テーブルとロールを削除する。
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
if (!defined('SS_REMOVE_DATA_ON_UNINSTALL') || SS_REMOVE_DATA_ON_UNINSTALL !== true) {
    return;
}
global $wpdb;
foreach (array('tenants', 'users', 'staff', 'tokens', 'plans', 'billing_events') as $name) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'shift_' . $name); // phpcs:ignore
}
foreach (array('shift_owner', 'shift_manager', 'shift_staff') as $role) {
    remove_role($role);
}
delete_option('ss_db_version');
delete_option('ss_terms_url');
wp_clear_scheduled_hook('ss_daily');
