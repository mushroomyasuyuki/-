<?php
/** ライセンス（契約ごとのキーと状態）。 */

if (!defined('ABSPATH')) {
    exit;
}

final class MCO_Licenses {
    const STATUSES = array('active' => '有効', 'suspended' => '停止中', 'canceled' => '解約');

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'mco_licenses';
    }

    public static function now() {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * ライセンスを新しく発行する。
     * @return array|WP_Error 作成した行
     */
    public static function create(array $d) {
        global $wpdb;
        $name = isset($d['customer_name']) ? trim((string) $d['customer_name']) : '';
        if ($name === '') {
            return new WP_Error('invalid', 'お客様の名前を入力してください。');
        }
        $email = isset($d['email']) ? trim((string) $d['email']) : '';
        $expires = isset($d['expires_at']) && $d['expires_at'] !== '' ? (string) $d['expires_at'] : null;
        if ($expires !== null && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $expires)) {
            return new WP_Error('invalid', '有効期限の形式が正しくありません（例：2027-03-31）。');
        }
        for ($i = 0; $i < 5; $i++) {
            $key = MCO_Keys::generate();
            $ok = $wpdb->insert(self::table(), array(
                'license_key'   => $key,
                'customer_name' => mb_substr($name, 0, 190),
                'email'         => mb_substr($email, 0, 190),
                'plan_label'    => mb_substr(isset($d['plan_label']) ? trim((string) $d['plan_label']) : '', 0, 100),
                'status'        => 'active',
                'expires_at'    => $expires,
                'note'          => isset($d['note']) ? (string) $d['note'] : '',
                'created_at'    => self::now(),
            ));
            if ($ok) {
                return self::get((int) $wpdb->insert_id);
            }
        }
        return new WP_Error('failed', 'キーを発行できませんでした。もう一度お試しください。');
    }

    public static function get($id) {
        global $wpdb;
        $t = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $id), ARRAY_A);
        return $row ? $row : null;
    }

    public static function find_by_key($key) {
        global $wpdb;
        $key = MCO_Keys::normalize($key);
        if (!MCO_Keys::looks_valid($key)) {
            return null;
        }
        $t = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE license_key = %s", $key), ARRAY_A);
        return $row ? $row : null;
    }

    public static function all($limit = 500) {
        global $wpdb;
        $t = self::table();
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} ORDER BY id DESC LIMIT %d", (int) $limit), ARRAY_A);
    }

    public static function set_status($id, $status) {
        global $wpdb;
        if (!isset(self::STATUSES[$status])) {
            return false;
        }
        return $wpdb->update(self::table(), array('status' => $status), array('id' => (int) $id)) !== false;
    }

    /** ①に返す状態。有効期限が過ぎていれば expired。①は、active 以外は停止として扱う。 */
    public static function effective_status(array $row) {
        if ($row['status'] !== 'active') {
            return $row['status'];
        }
        if (!empty($row['expires_at']) && strtotime($row['expires_at'] . ' UTC') < time()) {
            return 'expired';
        }
        return 'active';
    }

    public static function is_active(array $row) {
        return self::effective_status($row) === 'active';
    }

    /** ①からの確認を記録する（いつ・どのサイトから確認されたかを、運営が見られるように） */
    public static function record_check(array $row, $agent) {
        global $wpdb;
        $wpdb->update(self::table(), array(
            'last_checked_at'  => self::now(),
            'last_check_agent' => mb_substr((string) $agent, 0, 255),
            'check_count'      => (int) $row['check_count'] + 1,
        ), array('id' => (int) $row['id']));
    }
}
