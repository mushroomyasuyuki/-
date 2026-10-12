<?php
/**
 * Plugin Name: マッシュオフィス 経理・管理・勤怠
 * Description: シフト作成・日報・勤怠などを、お客様ごとの専用URLで提供する月額サービス（mush-office.com 用）。お客様ごとにデータを分離します。
 * Version: 0.7.1
 * Author: mushroomyasuyuki
 * License: MIT
 * Text Domain: shift-scheduler
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SS_VERSION', '0.7.1');
define('SS_PRODUCT_NAME', 'マッシュオフィス 経理・管理・勤怠');
define('SS_DIR', plugin_dir_path(__FILE__));
define('SS_URL', plugin_dir_url(__FILE__));
if (!defined('SS_TRIAL_MONTHS')) {
    define('SS_TRIAL_MONTHS', 1); // 無料期間（月）
}
if (!defined('SS_TRIAL_STAFF_LIMIT')) {
    define('SS_TRIAL_STAFF_LIMIT', 200); // 無料期間中のスタッフ上限（いちばん上のプランと同じ）
}

require_once SS_DIR . 'includes/class-ss-system.php';
require_once SS_DIR . 'includes/class-ss-context.php';
require_once SS_DIR . 'includes/class-ss-repo.php';
require_once SS_DIR . 'includes/class-ss-tenants.php';
require_once SS_DIR . 'includes/class-ss-tax.php';
require_once SS_DIR . 'includes/class-ss-presets.php';
require_once SS_DIR . 'includes/class-ss-payjp.php';
require_once SS_DIR . 'includes/class-ss-billing.php';
require_once SS_DIR . 'includes/class-ss-legal.php';
require_once SS_DIR . 'includes/class-ss-terms.php';
require_once SS_DIR . 'includes/class-ss-installer.php';
require_once SS_DIR . 'includes/class-ss-view.php';
require_once SS_DIR . 'includes/class-ss-auth.php';
require_once SS_DIR . 'includes/class-ss-router.php';
require_once SS_DIR . 'includes/class-ss-rest.php';
require_once SS_DIR . 'includes/class-ss-rest-plan.php';
require_once SS_DIR . 'includes/class-ss-rest-schedule.php';
require_once SS_DIR . 'includes/class-ss-rest-billing.php';
require_once SS_DIR . 'includes/class-ss-files.php';
require_once SS_DIR . 'includes/class-ss-rest-office.php';
require_once SS_DIR . 'includes/class-ss-rest-kintai.php';
require_once SS_DIR . 'includes/class-ss-rest-keiri.php';
require_once SS_DIR . 'includes/class-ss-cron.php';
require_once SS_DIR . 'includes/class-ss-stats.php';
require_once SS_DIR . 'includes/class-ss-admin.php';

register_activation_hook(__FILE__, array('SS_Installer', 'activate'));
register_deactivation_hook(__FILE__, array('SS_Installer', 'deactivate'));

add_action('plugins_loaded', function () {
    SS_Installer::maybe_upgrade();
    SS_Auth::init();
    SS_Legal::init();
    SS_Router::init();
    SS_Rest::init();
    SS_Rest_Plan::init();
    SS_Rest_Schedule::init();
    SS_Rest_Billing::init();
    SS_Files::init();
    SS_Rest_Office::init();
    SS_Rest_Kintai::init();
    SS_Rest_Keiri::init();
    SS_Cron::init();
    SS_Admin::init();
});
