<?php
/**
 * 「いま誰のデータを扱っているか」を決める。
 * お客様ID（tenant_id）は必ずログイン中のユーザーの所属から決める。
 * リクエストのパラメータ（URL・フォーム・JSON）の値は一切使わない。
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Context {
    private static $cache = array();

    /** 所属が確定している（有効な）ユーザー行。未ログイン・未認証・無効は null。 */
    public static function user() {
        if (!is_user_logged_in()) {
            return null;
        }
        $wp_id = get_current_user_id();
        if (!array_key_exists($wp_id, self::$cache)) {
            $row = SS_System::user_by_wp_id($wp_id);
            self::$cache[$wp_id] = ($row && $row['status'] === 'active') ? $row : null;
        }
        return self::$cache[$wp_id];
    }

    public static function tenant_id() {
        $u = self::user();
        return $u ? (int) $u['tenant_id'] : 0;
    }

    public static function tenant() {
        $id = self::tenant_id();
        return $id ? SS_System::tenant_by_id($id) : null;
    }

    public static function reset() {
        self::$cache = array();
    }
}
