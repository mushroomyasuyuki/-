<?php
/**
 * 配布する製品ファイル（マッシュオフィスシステムのZIP）の登録と、期限つきのダウンロードURLの発行。
 * ZIPは、ウェブから直接開けないフォルダに保存し、有効なライセンスの人にだけ、短時間だけ有効なURLを渡す。
 */

if (!defined('ABSPATH')) {
    exit;
}

final class MCO_Products {
    const MAX_BYTES = 104857600; // 100MB
    const URL_TTL = 600;         // ダウンロードURLの有効時間（秒）

    /** テスト用：function ($row, $path) を指定すると、実際には送信せずにこれを呼ぶ */
    public static $sender = null;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'mco_products';
    }

    public static function dir() {
        $u = wp_upload_dir();
        return rtrim($u['basedir'], '/') . '/mush-office-private/';
    }

    public static function ensure_dir() {
        $d = self::dir();
        if (!is_dir($d)) {
            wp_mkdir_p($d);
        }
        if (is_dir($d)) {
            if (!file_exists($d . '.htaccess')) {
                file_put_contents($d . '.htaccess', "Require all denied\nDeny from all\n");
            }
            if (!file_exists($d . 'index.php')) {
                file_put_contents($d . 'index.php', "<?php\n// Silence is golden.\n");
            }
        }
        return $d;
    }

    /**
     * ZIPの中身を調べて、プラグインの版を読み取る。ZIPは展開しない。
     * @return array|WP_Error ['version' => , 'slug' => , 'name' => ]
     */
    public static function inspect_zip($path) {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('no_zip', 'サーバーでZIPを読み取れません（PHPのzip拡張が必要です）。');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return new WP_Error('bad_zip', 'ZIPファイルとして開けませんでした。');
        }
        $found = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!preg_match('#^([^/]+)/([^/]+\.php)$#', $name, $m)) {
                continue;
            }
            $head = $zip->getFromIndex($i, 8192);
            if ($head === false) {
                continue;
            }
            if (preg_match('/^\s*\*?\s*Plugin Name:\s*(.+)$/mi', $head, $pn) && preg_match('/^\s*\*?\s*Version:\s*([0-9][0-9A-Za-z.\-]*)\s*$/mi', $head, $pv)) {
                $found = array('version' => $pv[1], 'slug' => $m[1], 'name' => trim($pn[1]));
                break;
            }
        }
        $zip->close();
        if (!$found) {
            return new WP_Error('not_plugin', 'WordPressのプラグインのZIPではないようです（フォルダ直下に、Plugin Name と Version を持つPHPファイルが必要です）。');
        }
        return $found;
    }

    /**
     * ZIPを登録する。
     * @return array|WP_Error 登録した行
     */
    public static function add_from_path($path, $orig_name = '') {
        global $wpdb;
        if (!is_readable($path)) {
            return new WP_Error('unreadable', 'ファイルを読み取れませんでした。');
        }
        $size = (int) filesize($path);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return new WP_Error('size', 'ファイルのサイズが大きすぎるか、空です（上限100MB）。');
        }
        $info = self::inspect_zip($path);
        if (is_wp_error($info)) {
            return $info;
        }
        $t = self::table();
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE version = %s", $info['version'])) > 0) {
            return new WP_Error('dup', '版 ' . $info['version'] . ' は、すでに登録されています。版を上げたZIPを登録してください。');
        }
        $dir = self::ensure_dir();
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '', $info['slug'] . '-' . $info['version']);
        $file = $safe . '-' . substr(bin2hex(random_bytes(8)), 0, 12) . '.zip';
        if (!@copy($path, $dir . $file)) {
            return new WP_Error('copy', 'ファイルを保存できませんでした（保存先の書き込み権限を確認してください）。');
        }
        $ok = $wpdb->insert($t, array(
            'version'     => $info['version'],
            'file_name'   => $file,
            'size'        => $size,
            'sha256'      => hash_file('sha256', $dir . $file),
            'is_latest'   => 0,
            'uploaded_at' => gmdate('Y-m-d H:i:s'),
        ));
        if (!$ok) {
            @unlink($dir . $file);
            return new WP_Error('db', '登録に失敗しました。');
        }
        $id = (int) $wpdb->insert_id;
        self::recalc_latest();
        return self::get($id);
    }

    /** 一番新しい版を、配布する版にする */
    public static function recalc_latest() {
        global $wpdb;
        $t = self::table();
        $rows = (array) $wpdb->get_results("SELECT id, version FROM {$t}", ARRAY_A);
        $best = null;
        foreach ($rows as $r) {
            if ($best === null || version_compare($r['version'], $best['version'], '>')) {
                $best = $r;
            }
        }
        $wpdb->query("UPDATE {$t} SET is_latest = 0");
        if ($best) {
            $wpdb->update($t, array('is_latest' => 1), array('id' => (int) $best['id']));
        }
    }

    /** 管理者が、配布する版を選び直す（古い版へ戻す場合など） */
    public static function set_latest($id) {
        global $wpdb;
        if (!self::get($id)) {
            return false;
        }
        $t = self::table();
        $wpdb->query("UPDATE {$t} SET is_latest = 0");
        return $wpdb->update($t, array('is_latest' => 1), array('id' => (int) $id)) !== false;
    }

    public static function get($id) {
        global $wpdb;
        $t = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $id), ARRAY_A);
        return $row ? $row : null;
    }

    public static function latest() {
        global $wpdb;
        $t = self::table();
        $row = $wpdb->get_row("SELECT * FROM {$t} WHERE is_latest = 1 ORDER BY id DESC LIMIT 1", ARRAY_A);
        return $row ? $row : null;
    }

    public static function all() {
        global $wpdb;
        $t = self::table();
        return (array) $wpdb->get_results("SELECT * FROM {$t} ORDER BY id DESC", ARRAY_A);
    }

    public static function path_of(array $row) {
        return self::dir() . basename($row['file_name']);
    }

    /* ---------- 期限つきURL ---------- */

    public static function sign($product_id, $license_id, $exp) {
        return hash_hmac('sha256', (int) $product_id . '|' . (int) $license_id . '|' . (int) $exp, wp_salt('auth'));
    }

    public static function verify($product_id, $license_id, $exp, $sig) {
        if ((int) $exp < time()) {
            return false;
        }
        return is_string($sig) && hash_equals(self::sign($product_id, $license_id, $exp), $sig);
    }

    public static function download_url(array $product, array $license) {
        $exp = time() + self::URL_TTL;
        $base = rest_url(MCO_REST_NS . '/download');
        $q = http_build_query(array(
            'key' => $license['license_key'], 'p' => (int) $product['id'], 'exp' => $exp,
            'sig' => self::sign($product['id'], $license['id'], $exp),
        ));
        return $base . (strpos($base, '?') === false ? '?' : '&') . $q;
    }

    /** ファイルを送る（呼び出し後は終了する） */
    public static function send(array $row) {
        $path = self::path_of($row);
        if (self::$sender) {
            return call_user_func(self::$sender, $row, $path);
        }
        if (!is_readable($path)) {
            status_header(404);
            exit;
        }
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="mush-office-system-' . preg_replace('/[^0-9A-Za-z.\-]/', '', $row['version']) . '.zip"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}
