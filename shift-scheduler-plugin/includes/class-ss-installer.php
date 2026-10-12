<?php
/** 有効化時のテーブル作成・権限（ロール）登録・初期プラン投入。 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Installer {
    const DB_VERSION = '6';

    public static function activate() {
        self::create_tables();
        self::seed_plans();
        self::add_roles();
        SS_Router::add_rules();
        flush_rewrite_rules();
        if (!wp_next_scheduled('ss_daily')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'ss_daily');
        }
        update_option('ss_db_version', self::DB_VERSION);
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('ss_daily');
        flush_rewrite_rules();
    }

    public static function maybe_upgrade() {
        if (get_option('ss_db_version') !== self::DB_VERSION) {
            self::create_tables();
            $from = (string) get_option('ss_db_version');
            self::seed_plans();
            if ($from !== '' && version_compare($from, '6', '<')) {
                self::migrate_plans_v6();
            }
            self::add_roles();
            update_option('ss_db_version', self::DB_VERSION);
        }
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'shift_';

        $sql = array();

        $sql[] = "CREATE TABLE {$p}tenants (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  public_id varchar(16) NOT NULL,
  name varchar(190) NOT NULL,
  industry varchar(20) NOT NULL DEFAULT 'other',
  status varchar(20) NOT NULL DEFAULT 'unverified',
  trial_start datetime NULL,
  trial_end datetime NULL,
  plan_id bigint(20) unsigned NULL,
  payjp_customer_id varchar(64) NOT NULL DEFAULT '',
  payjp_subscription_id varchar(64) NOT NULL DEFAULT '',
  next_billing_at datetime NULL,
  settings longtext NULL,
  created_at datetime NOT NULL,
  deleted_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY public_id (public_id),
  KEY status (status)
) $c;";

        $sql[] = "CREATE TABLE {$p}users (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id bigint(20) unsigned NOT NULL,
  wp_user_id bigint(20) unsigned NOT NULL,
  email varchar(190) NULL,
  role varchar(20) NOT NULL DEFAULT 'staff',
  staff_id bigint(20) unsigned NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  last_login_at datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY wp_user_id (wp_user_id),
  UNIQUE KEY email (email),
  KEY tenant_id (tenant_id, status),
  KEY staff_id (tenant_id, staff_id)
) $c;";

        $sql[] = "CREATE TABLE {$p}staff (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id bigint(20) unsigned NOT NULL,
  name varchar(100) NOT NULL,
  kana varchar(100) NOT NULL DEFAULT '',
  employee_number varchar(20) NOT NULL DEFAULT '',
  department varchar(50) NOT NULL DEFAULT '',
  employment varchar(10) NOT NULL DEFAULT 'part',
  roles longtext NULL,
  qualifications longtext NULL,
  available_weekdays varchar(20) NOT NULL DEFAULT '0,1,2,3,4,5,6',
  max_hours_week smallint(5) unsigned NULL,
  max_hours_month smallint(5) unsigned NULL,
  max_days_row tinyint(3) unsigned NULL,
  night_ok tinyint(1) NOT NULL DEFAULT 1,
  active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY tenant_active (tenant_id, active, sort_order),
  KEY tenant_empno (tenant_id, employee_number)
) $c;";

        $sql[] = "CREATE TABLE {$p}tokens (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  type varchar(20) NOT NULL,
  token_hash char(64) NOT NULL,
  expires_at datetime NOT NULL,
  used_at datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY user_type (user_id, type)
) $c;";

        $sql[] = "CREATE TABLE {$p}plans (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(60) NOT NULL,
  max_staff int(11) NOT NULL,
  price int(11) NOT NULL,
  payjp_plan_id varchar(64) NOT NULL DEFAULT '',
  payjp_amount int(11) NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id)
) $c;";

        $sql[] = "CREATE TABLE {$p}billing_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id bigint(20) unsigned NULL,
  event_id varchar(64) NOT NULL,
  type varchar(60) NOT NULL,
  payload longtext NULL,
  processed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_id (event_id)
) $c;";

        $sql[] = "CREATE TABLE {$p}patterns (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id bigint(20) unsigned NOT NULL,
  name varchar(60) NOT NULL,
  short_name varchar(10) NOT NULL DEFAULT '',
  start_time varchar(5) NOT NULL,
  end_time varchar(5) NOT NULL,
  break_minutes smallint(5) unsigned NOT NULL DEFAULT 0,
  crosses_midnight tinyint(1) NOT NULL DEFAULT 0,
  counts_as_night tinyint(1) NOT NULL DEFAULT 0,
  color varchar(7) NOT NULL DEFAULT '#6aa84f',
  active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY tenant_active (tenant_id, active, sort_order)
) $c;";

        $sql[] = "CREATE TABLE {$p}rules (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id bigint(20) unsigned NOT NULL,
  type varchar(40) NOT NULL,
  params longtext NULL,
  hard tinyint(1) NOT NULL DEFAULT 1,
  weight smallint(5) unsigned NOT NULL DEFAULT 1,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY tenant_type (tenant_id, type)
) $c;";

        $sql[] = "CREATE TABLE {$p}request_periods (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id bigint(20) unsigned NOT NULL,
  start_date date NOT NULL,
  end_date date NOT NULL,
  deadline date NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'open',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY tenant_status (tenant_id, status, start_date)
) $c;";

        $sql[] = "CREATE TABLE {$p}requests (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id bigint(20) unsigned NOT NULL,
  period_id bigint(20) unsigned NOT NULL,
  staff_id bigint(20) unsigned NOT NULL,
  date date NOT NULL,
  kind varchar(10) NOT NULL,
  note varchar(200) NOT NULL DEFAULT '',
  source varchar(10) NOT NULL DEFAULT 'staff',
  submitted_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_request (tenant_id, period_id, staff_id, date),
  KEY by_period (tenant_id, period_id)
) $c;";

        $sql[] = "CREATE TABLE {$p}request_submissions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id bigint(20) unsigned NOT NULL,
  period_id bigint(20) unsigned NOT NULL,
  staff_id bigint(20) unsigned NOT NULL,
  submitted_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_submission (tenant_id, period_id, staff_id)
) $c;";

        $sql[] = "CREATE TABLE {$p}schedules (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id bigint(20) unsigned NOT NULL,
  start_date date NOT NULL,
  end_date date NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'draft',
  published_at datetime NULL,
  created_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY tenant_status (tenant_id, status, start_date)
) $c;";

        $sql[] = "CREATE TABLE {$p}entries (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id bigint(20) unsigned NOT NULL,
  schedule_id bigint(20) unsigned NOT NULL,
  staff_id bigint(20) unsigned NOT NULL,
  date date NOT NULL,
  pattern_id bigint(20) unsigned NULL,
  start_time varchar(5) NOT NULL DEFAULT '',
  end_time varchar(5) NOT NULL DEFAULT '',
  break_minutes smallint(5) unsigned NOT NULL DEFAULT 0,
  locked tinyint(1) NOT NULL DEFAULT 0,
  note varchar(200) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_entry (tenant_id, schedule_id, staff_id, date),
  KEY by_schedule (tenant_id, schedule_id, date)
) $c;";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }
    }

    /** 初期プラン（金額・上限は後から管理画面で変更できる前提） */
    public static function seed_plans() {
        global $wpdb;
        $t = SS_System::table('plans');
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}") > 0) {
            return;
        }
        foreach (self::default_plans() as $plan) {
            $wpdb->insert($t, $plan);
        }
    }

    /** 初期の料金（月額・税抜）。日報とシフト作成のセット */
    public static function default_plans() {
        return array(
            array('name' => 'ライト', 'max_staff' => 50, 'price' => 3980, 'sort_order' => 1),
            array('name' => 'スタンダード', 'max_staff' => 100, 'price' => 5980, 'sort_order' => 2),
            array('name' => 'プレミアム', 'max_staff' => 200, 'price' => 9800, 'sort_order' => 3),
        );
    }

    /**
     * 0.6.0：料金を、シフト作成だけの料金（ライト500円・スタンダード1,000円）から、セットの料金に切り替える。
     * 管理画面で金額を変えていない場合（初期値のまま）だけ書き換える。変えていれば、何もしない。
     * 書き換えたプランは「PAY.JPに未反映」になるので、管理画面で「プランを保存」を押すと、PAY.JPに新しい金額のプランが作られる。
     */
    public static function migrate_plans_v6() {
        global $wpdb;
        $t = SS_System::table('plans');
        $rows = (array) $wpdb->get_results("SELECT * FROM {$t} ORDER BY sort_order ASC, id ASC", ARRAY_A);
        $old = array('ライト' => array(50, 500), 'スタンダード' => array(100, 1000));
        if (count($rows) !== 2) {
            return false;
        }
        foreach ($rows as $r) {
            if (!isset($old[$r['name']]) || (int) $r['max_staff'] !== $old[$r['name']][0] || (int) $r['price'] !== $old[$r['name']][1]) {
                return false;
            }
        }
        $new = self::default_plans();
        foreach ($rows as $r) {
            foreach ($new as $n) {
                if ($n['name'] === $r['name']) {
                    $wpdb->update($t, array('price' => $n['price'], 'max_staff' => $n['max_staff']), array('id' => (int) $r['id']));
                }
            }
        }
        $wpdb->insert($t, $new[2]);
        return true;
    }

    public static function add_roles() {
        $staff_caps = array('read' => true, 'shift_view' => true, 'shift_submit_requests' => true);
        $manager_caps = array_merge($staff_caps, array(
            'shift_manage_staff'    => true,
            'shift_manage_schedule' => true,
        ));
        $owner_caps = array_merge($manager_caps, array(
            'shift_manage_settings' => true,
            'shift_manage_billing'  => true,
        ));
        remove_role('shift_owner');
        remove_role('shift_manager');
        remove_role('shift_staff');
        add_role('shift_owner', 'マッシュオフィス：お客様の管理者', $owner_caps);
        add_role('shift_manager', 'マッシュオフィス：副管理者', $manager_caps);
        add_role('shift_staff', 'マッシュオフィス：スタッフ', $staff_caps);
    }
}
