<?php
/**
 * Plugin Name: Design Order Mailer
 * Plugin URI:  https://mush-office.com/
 * Description: 注文フォームからアップロードされたデザインファイル（PSD/PNG等）をメールに直接添付せず、サーバーに保存してダウンロードリンクをメール本文に記載して送信します。保存ファイルは60日後に自動削除されます。
 * Version:     1.0.0
 * Author:      yasu
 * Text Domain: design-order-mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 直接アクセス禁止
}

define( 'DOMAILER_VERSION', '1.0.0' );
define( 'DOMAILER_PLUGIN_FILE', __FILE__ );
define( 'DOMAILER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DOMAILER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DOMAILER_EXPIRY_DAYS', 60 ); // ファイル保存期間（日数）。変更したい場合はここを編集してください。

require_once DOMAILER_PLUGIN_DIR . 'includes/class-domailer.php';

register_activation_hook( __FILE__, array( 'DOMailer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DOMailer', 'deactivate' ) );

// カスタムのcron間隔として「daily」は標準で用意されているためそのまま使用します。
DOMailer::instance();
