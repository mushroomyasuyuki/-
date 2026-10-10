<?php
/**
 * マッシュオフィスシステム（①）が問い合わせる窓口（名前空間 mush-checkout/v1）。
 *   GET /license-status?key=…  → {"status":"active"|"suspended"|"canceled"|"expired"|"unknown"}
 *   GET /update-check?key=…    → {"version":"1.2.3","package":"（期限つきのダウンロードURL。無効なライセンスは空）"}
 *   GET /download?…            → ZIP本体（update-check が返したURLだけ）
 * ①は、status が active のとき以外を「停止」として扱う。
 */

if (!defined('ABSPATH')) {
    exit;
}

final class MCO_Rest {
    const LIMIT_ALL = 120;  // 1時間あたり、同じ接続元から受け付ける問い合わせの数
    const LIMIT_MISS = 20;  // 1時間あたり、同じ接続元から受け付ける「存在しないキー」の問い合わせの数

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'routes'));
    }

    public static function routes() {
        foreach (array('license-status' => 'license_status', 'update-check' => 'update_check', 'download' => 'download') as $path => $cb) {
            register_rest_route(MCO_REST_NS, '/' . $path, array(
                array('methods' => 'GET', 'callback' => array(__CLASS__, $cb), 'permission_callback' => '__return_true'),
            ));
        }
    }

    private static function ip() {
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    }

    /** 回数制限。上限を超えたら false */
    private static function throttle($bucket, $limit) {
        $k = 'mco_rl_' . md5($bucket . '|' . self::ip());
        $n = (int) get_transient($k) + 1;
        set_transient($k, $n, HOUR_IN_SECONDS);
        return $n <= $limit;
    }

    private static function too_many() {
        return new WP_Error('rate_limited', '問い合わせが多すぎます。しばらくしてからお試しください。', array('status' => 429));
    }

    private static function agent() {
        return isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    }

    public static function license_status(WP_REST_Request $req) {
        if (!self::throttle('all', self::LIMIT_ALL)) {
            return self::too_many();
        }
        $row = MCO_Licenses::find_by_key((string) $req->get_param('key'));
        if (!$row) {
            if (!self::throttle('miss', self::LIMIT_MISS)) {
                return self::too_many();
            }
            return rest_ensure_response(array('status' => 'unknown'));
        }
        MCO_Licenses::record_check($row, self::agent());
        return rest_ensure_response(array('status' => MCO_Licenses::effective_status($row), 'plan' => $row['plan_label']));
    }

    public static function update_check(WP_REST_Request $req) {
        if (!self::throttle('all', self::LIMIT_ALL)) {
            return self::too_many();
        }
        $latest = MCO_Products::latest();
        if (!$latest) {
            return new WP_Error('no_product', '配布できるファイルがありません。', array('status' => 404));
        }
        $row = MCO_Licenses::find_by_key((string) $req->get_param('key'));
        if (!$row && !self::throttle('miss', self::LIMIT_MISS)) {
            return self::too_many();
        }
        $package = $row && MCO_Licenses::is_active($row) ? MCO_Products::download_url($latest, $row) : '';
        return rest_ensure_response(array('version' => $latest['version'], 'package' => $package));
    }

    public static function download(WP_REST_Request $req) {
        if (!self::throttle('all', self::LIMIT_ALL)) {
            return self::too_many();
        }
        $forbidden = new WP_Error('forbidden', 'ダウンロードできません。', array('status' => 403));
        $row = MCO_Licenses::find_by_key((string) $req->get_param('key'));
        $pid = (int) $req->get_param('p');
        if (!$row || !MCO_Licenses::is_active($row)) {
            return $forbidden;
        }
        if (!MCO_Products::verify($pid, $row['id'], (int) $req->get_param('exp'), (string) $req->get_param('sig'))) {
            return $forbidden;
        }
        $product = MCO_Products::get($pid);
        if (!$product) {
            return new WP_Error('not_found', 'ファイルが見つかりません。', array('status' => 404));
        }
        return MCO_Products::send($product);
    }
}
