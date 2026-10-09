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

// 人間の変換の学習（Gemini）：APIキー・学習メモ・保存した例
delete_option('cfp_gemini_api_key');
delete_option('cfp_gemini_model');
delete_option('cfp_gemini_rules');
$cfp_up = wp_upload_dir();
$cfp_dir = trailingslashit($cfp_up['basedir']) . 'cfp-gemini-private';
if (is_dir($cfp_dir)) {
    $cfp_it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cfp_dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($cfp_it as $cfp_f) {
        $cfp_f->isDir() ? @rmdir($cfp_f->getPathname()) : @unlink($cfp_f->getPathname());
    }
    @rmdir($cfp_dir);
}
