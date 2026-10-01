<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// The simulator page is deleted on deactivation; only the stored options remain here.
delete_option('cad_floor_plan_version');
delete_option('cad_floor_plan_page_id');
delete_option('cad_floor_plan_page_created');

require_once __DIR__ . '/includes/order-mailer/uninstall-data.php';

// 途中保存した作業（画像・図面を含む）をすべて削除
if (!defined('CFP_RESUME_DAYS')) {
    define('CFP_RESUME_DAYS', 30);
}
require_once __DIR__ . '/includes/class-cfp-resume.php';
CFP_Resume::remove_all();
wp_clear_scheduled_hook('cfp_resume_cleanup');
