<?php
/** お客様（テナント）の契約状態・プラン上限などの判定。 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Tenants {
    /** trial（無料中）／active（有料）／grace（支払い猶予）は書き込み可。 */
    const WRITABLE = array('trial', 'active', 'grace');

    /** 保存されている状態に、無料期間の期限切れを加味した状態 */
    public static function effective_status(array $tenant) {
        $status = $tenant['status'];
        if ($status === 'trial' && !empty($tenant['trial_end']) && strtotime($tenant['trial_end'] . ' UTC') < time()) {
            return 'readonly';
        }
        return $status;
    }

    public static function is_writable(array $tenant) {
        return in_array(self::effective_status($tenant), self::WRITABLE, true);
    }

    public static function status_label($status) {
        $labels = array(
            'unverified' => 'メール認証待ち',
            'trial'      => '無料期間中',
            'active'     => 'ご契約中',
            'grace'      => 'お支払い確認中',
            'readonly'   => '閲覧のみ',
            'suspended'  => '停止中',
        );
        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    public static function trial_days_left(array $tenant) {
        if ($tenant['status'] !== 'trial' || empty($tenant['trial_end'])) {
            return null;
        }
        $left = strtotime($tenant['trial_end'] . ' UTC') - time();
        return $left > 0 ? (int) ceil($left / DAY_IN_SECONDS) : 0;
    }

    /** スタッフ数の上限。無料期間中は定数、契約中はプランの上限。 */
    public static function staff_limit(array $tenant) {
        global $wpdb;
        if (!empty($tenant['plan_id'])) {
            $t = SS_System::table('plans');
            $max = $wpdb->get_var($wpdb->prepare("SELECT max_staff FROM {$t} WHERE id = %d", (int) $tenant['plan_id']));
            if ($max) {
                return (int) $max;
            }
        }
        return (int) SS_TRIAL_STAFF_LIMIT;
    }

    public static function plans() {
        global $wpdb;
        $t = SS_System::table('plans');
        return $wpdb->get_results("SELECT * FROM {$t} WHERE active = 1 ORDER BY sort_order ASC, id ASC", ARRAY_A);
    }

    public static function settings(array $tenant) {
        $v = !empty($tenant['settings']) ? json_decode($tenant['settings'], true) : array();
        return is_array($v) ? $v : array();
    }

    /** スタッフが見られる範囲：all=全員のシフト／self=自分のシフトのみ */
    public static function staff_view(array $tenant) {
        $s = self::settings($tenant);
        return isset($s['staff_view']) && $s['staff_view'] === 'self' ? 'self' : 'all';
    }

    public static function save_settings($tenant_id, array $changes) {
        global $wpdb;
        $tenant = SS_System::tenant_by_id($tenant_id);
        if (!$tenant) {
            return false;
        }
        $merged = array_merge(self::settings($tenant), $changes);
        return $wpdb->update(SS_System::table('tenants'), array('settings' => wp_json_encode($merged)), array('id' => (int) $tenant_id)) !== false;
    }
}
