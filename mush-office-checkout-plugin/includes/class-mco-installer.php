<?php
/** テーブルの作成。 */

if (!defined('ABSPATH')) {
    exit;
}

final class MCO_Installer {
    const DB_VERSION = '1';

    public static function activate() {
        self::install();
    }

    public static function maybe_upgrade() {
        if (get_option('mco_db_version') !== self::DB_VERSION) {
            self::install();
        }
    }

    private static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}mco_licenses (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  license_key varchar(40) NOT NULL,
  customer_name varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  plan_label varchar(100) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'active',
  expires_at datetime NULL,
  payjp_customer_id varchar(64) NOT NULL DEFAULT '',
  payjp_subscription_id varchar(64) NOT NULL DEFAULT '',
  note text NULL,
  created_at datetime NOT NULL,
  last_checked_at datetime NULL,
  last_check_agent varchar(255) NOT NULL DEFAULT '',
  check_count int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY license_key (license_key),
  KEY status (status)
) $c;");

        dbDelta("CREATE TABLE {$p}mco_products (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  version varchar(40) NOT NULL,
  file_name varchar(190) NOT NULL,
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  sha256 char(64) NOT NULL DEFAULT '',
  is_latest tinyint(1) NOT NULL DEFAULT 0,
  uploaded_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY is_latest (is_latest)
) $c;");

        update_option('mco_db_version', self::DB_VERSION);
    }
}
