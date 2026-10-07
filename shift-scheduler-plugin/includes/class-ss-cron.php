<?php
/** 1日1回の定期処理：古い未認証の登録の掃除、期限切れトークンの削除、無料期間終了の反映。 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Cron {
    public static function init() {
        add_action('ss_daily', array(__CLASS__, 'daily'));
    }

    public static function daily() {
        global $wpdb;
        SS_Billing::daily(); // 支払いの同期・解約予約・猶予・無料期間の案内
        $tenants = SS_System::table('tenants');
        $users = SS_System::table('users');
        $tokens = SS_System::table('tokens');
        $now = SS_System::now();

        // 7日以上たっても認証されない登録を削除
        $cutoff = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
        $stale = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$tenants} WHERE status = 'unverified' AND created_at < %s LIMIT 200", $cutoff
        ), ARRAY_A);
        foreach ($stale as $row) {
            $wp_ids = $wpdb->get_col($wpdb->prepare("SELECT wp_user_id FROM {$users} WHERE tenant_id = %d", (int) $row['id']));
            foreach ($wp_ids as $wp_id) {
                SS_Auth::discard_registration((int) $wp_id, 0);
            }
            SS_Auth::discard_registration(0, (int) $row['id']);
        }

        // 期限切れ・使用済みから30日たったトークンを削除
        $old = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare("DELETE FROM {$tokens} WHERE expires_at < %s", $old));

        // 無料期間が終わったお客様を「閲覧のみ」にする（未契約のもの）
        $wpdb->query($wpdb->prepare(
            "UPDATE {$tenants} SET status = 'readonly' WHERE status = 'trial' AND trial_end < %s AND payjp_subscription_id = ''",
            $now
        ));
    }
}
