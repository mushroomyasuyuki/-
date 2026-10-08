<?php
/**
 * テナントを特定する前に必要な、システム用のデータ操作（テナント絞り込みなし）。
 * ログイン・登録・トークン・定期処理など、限られた用途でのみ使う。
 * 画面・APIのデータ操作には SS_Repo（テナントで必ず絞り込む）を使うこと。
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_System {
    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'shift_' . preg_replace('/[^a-z_]/', '', $name);
    }

    public static function now() {
        return gmdate('Y-m-d H:i:s');
    }

    public static function tenant_by_id($id) {
        global $wpdb;
        $t = self::table('tenants');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d AND deleted_at IS NULL", (int) $id), ARRAY_A);
    }

    public static function tenant_by_public_id($public_id) {
        global $wpdb;
        $t = self::table('tenants');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE public_id = %s AND deleted_at IS NULL", (string) $public_id), ARRAY_A);
    }

    public static function user_by_wp_id($wp_user_id) {
        global $wpdb;
        $t = self::table('users');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE wp_user_id = %d", (int) $wp_user_id), ARRAY_A);
    }

    public static function user_by_id($id) {
        global $wpdb;
        $t = self::table('users');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $id), ARRAY_A);
    }

    /** URLに使う、推測しにくい12文字のお客様ID */
    public static function new_public_id() {
        $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
        $max = strlen($chars) - 1;
        for ($tries = 0; $tries < 10; $tries++) {
            $id = '';
            for ($i = 0; $i < 12; $i++) {
                $id .= $chars[random_int(0, $max)];
            }
            if (!self::tenant_by_public_id($id)) {
                return $id;
            }
        }
        return '';
    }

    /** 1回限りのトークンを作る。平文は返り値のみ、DBにはハッシュを保存する。 */
    public static function create_token($user_id, $tenant_id, $type, $ttl_seconds) {
        global $wpdb;
        $raw = bin2hex(random_bytes(32)); // 64文字
        $wpdb->insert(self::table('tokens'), array(
            'tenant_id'  => (int) $tenant_id,
            'user_id'    => (int) $user_id,
            'type'       => $type,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + (int) $ttl_seconds),
            'created_at' => self::now(),
        ));
        return $raw;
    }

    /** 有効なトークンの内容を取得（使用済みにはしない）。 */
    public static function peek_token($raw, $type) {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{64}$/', (string) $raw)) {
            return null;
        }
        $t = self::table('tokens');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t} WHERE token_hash = %s AND type = %s AND used_at IS NULL AND expires_at > %s",
            hash('sha256', $raw), $type, self::now()
        ), ARRAY_A);
        return $row ? $row : null;
    }

    /** トークンを使用済みにする。同時に2回使われても1回しか成功しない。 */
    public static function consume_token($raw, $type) {
        global $wpdb;
        $row = self::peek_token($raw, $type);
        if (!$row) {
            return null;
        }
        $t = self::table('tokens');
        $n = $wpdb->query($wpdb->prepare("UPDATE {$t} SET used_at = %s WHERE id = %d AND used_at IS NULL", self::now(), (int) $row['id']));
        return $n === 1 ? $row : null;
    }

    public static function invalidate_tokens($user_id, $type) {
        global $wpdb;
        $t = self::table('tokens');
        $wpdb->query($wpdb->prepare("UPDATE {$t} SET used_at = %s WHERE user_id = %d AND type = %s AND used_at IS NULL", self::now(), (int) $user_id, $type));
    }

    /**
     * お客様のデータを、すべて削除する（元に戻せません）。解約後の保存期間が過ぎたときに使う。
     * スタッフ・勤務区分・ルール・希望・シフト表・ログイン用のアカウント・確認用のトークンを消す。
     * お客様の行は、決済の履歴の照合のため残すが、事業所名や設定は消して「削除済み」にする。
     */
    public static function purge_tenant($tenant_id) {
        global $wpdb;
        $tenant_id = (int) $tenant_id;
        if ($tenant_id <= 0) {
            return false;
        }
        $users = self::table('users');
        $wp_ids = $wpdb->get_col($wpdb->prepare("SELECT wp_user_id FROM {$users} WHERE tenant_id = %d", $tenant_id));
        foreach (array('entries', 'schedules', 'request_submissions', 'requests', 'request_periods', 'rules', 'patterns', 'staff', 'users', 'tokens') as $name) {
            $t = self::table($name);
            $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE tenant_id = %d", $tenant_id));
        }
        if (defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/user.php')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            foreach ((array) $wp_ids as $wp_id) {
                $wp_id = (int) $wp_id;
                $user = $wp_id ? get_userdata($wp_id) : false;
                if ($user && !user_can($user, 'manage_options')) { // 管理権限のあるユーザーは、念のため消さない
                    wp_delete_user($wp_id);
                }
            }
        }
        $wpdb->update(self::table('tenants'), array(
            'name' => '（削除済み）', 'status' => 'suspended', 'settings' => '{}', 'payjp_subscription_id' => '', 'next_billing_at' => null, 'deleted_at' => self::now(),
        ), array('id' => $tenant_id));
        return true;
    }

    public static function mail($to, $subject, $body) {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        return wp_mail($to, '[' . $site . '] ' . $subject, $body . "\n\n--\n" . $site . "\n" . home_url('/'));
    }
}
