<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// The simulator page is deleted on deactivation; only the stored options remain here.
delete_option('cad_floor_plan_version');
delete_option('cad_floor_plan_page_id');
delete_option('cad_floor_plan_page_created');
