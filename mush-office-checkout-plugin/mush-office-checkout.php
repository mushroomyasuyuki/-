<?php
/**
 * Plugin Name: マッシュオフィス 決済・ライセンス管理
 * Description: マッシュオフィスシステムのライセンスキーの発行・確認と、更新ファイルの配布を行います（mush-office.com 側のプラグイン）。
 * Version: 0.1.0
 * Author: mushroomyasuyuki
 * License: MIT
 * Text Domain: mush-office-checkout
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MCO_VERSION', '0.1.0');
define('MCO_DIR', plugin_dir_path(__FILE__));
// マッシュオフィスシステム（①）が問い合わせる窓口。①の側に固定で書かれているため、変えないこと。
define('MCO_REST_NS', 'mush-checkout/v1');

require_once MCO_DIR . 'includes/class-mco-keys.php';
require_once MCO_DIR . 'includes/class-mco-licenses.php';
require_once MCO_DIR . 'includes/class-mco-products.php';
require_once MCO_DIR . 'includes/class-mco-installer.php';
require_once MCO_DIR . 'includes/class-mco-rest.php';
require_once MCO_DIR . 'includes/class-mco-admin.php';

register_activation_hook(__FILE__, array('MCO_Installer', 'activate'));

add_action('plugins_loaded', function () {
    MCO_Installer::maybe_upgrade();
    MCO_Rest::init();
    MCO_Admin::init();
});
