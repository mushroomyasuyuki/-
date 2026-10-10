<?php
/**
 * プラグインを削除したときの処理。
 * 「削除したときにデータも消す」にチェックが入っている場合だけ、ライセンスと配布ファイルを削除する。
 * （ライセンスの記録は、お客様への対応に必要なので、初期値では残す）
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

if (get_option('mco_delete_on_uninstall') === '1') {
    $u = wp_upload_dir();
    $dir = rtrim($u['basedir'], '/') . '/mush-office-private/';
    if (is_dir($dir)) {
        foreach ((array) glob($dir . '*') as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        foreach (array('.htaccess') as $hidden) {
            if (file_exists($dir . $hidden)) {
                @unlink($dir . $hidden);
            }
        }
        @rmdir($dir);
    }
    foreach (array('mco_licenses', 'mco_products') as $t) {
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $t); // phpcs:ignore
    }
    delete_option('mco_db_version');
    delete_option('mco_delete_on_uninstall');
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mco\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mco\\_rl\\_%'"); // phpcs:ignore
}
