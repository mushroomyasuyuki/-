<?php
/**
 * Plugin Name: シフト作成（マルチテナント）
 * Description: 飲食・介護向けのシフト作成サービス。お客様ごとにデータを分離し、無料登録→専用ページでシフト作成を行います。
 * Version: 0.5.0
 * Author: mushroomyasuyuki
 * License: MIT
 * Text Domain: shift-scheduler
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SS_VERSION', '0.5.0');
define('SS_DIR', plugin_dir_path(__FILE__));
define('SS_URL', plugin_dir_url(__FILE__));
if (!defined('SS_TRIAL_MONTHS')) {
    define('SS_TRIAL_MONTHS', 2); // 無料期間（月）
}
if (!defined('SS_TRIAL_STAFF_LIMIT')) {
    define('SS_TRIAL_STAFF_LIMIT', 100); // 無料期間中のスタッフ上限
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
    SS_Cron::init();
    SS_Admin::init();
});
