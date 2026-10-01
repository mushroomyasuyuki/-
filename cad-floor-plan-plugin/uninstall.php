<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// The simulator page itself is left in place: it may have been edited by the site owner.
delete_option('cad_floor_plan_version');
delete_option('cad_floor_plan_page_id');
