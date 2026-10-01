<?php
/**
 * プラグイン削除時のクリーンアップ処理
 * （「無効化」ではなく管理画面から「削除」した場合にのみ実行されます）
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$table_name = $wpdb->prefix . 'domailer_files';

// 保存されているデザインファイルを全て削除
$rows = $wpdb->get_results( "SELECT file_path FROM {$table_name}" );
if ( $rows ) {
	foreach ( $rows as $row ) {
		if ( ! empty( $row->file_path ) && file_exists( $row->file_path ) ) {
			@unlink( $row->file_path );
		}
	}
}

// 管理テーブルを削除
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// オプションを削除
delete_option( 'domailer_recipient_email' );

// スケジュールされたcronを解除
$timestamp = wp_next_scheduled( 'domailer_cleanup_event' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'domailer_cleanup_event' );
}
