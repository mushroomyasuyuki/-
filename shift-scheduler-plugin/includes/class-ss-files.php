<?php
/**
 * お客様ごとの非公開ファイル（日報の写真など）。
 *
 * - 保存先：uploads/mush-office-tenants/<お客様の内部ID>/<種類>/<推測できない名前>
 *   フォルダには、外から直接開けないよう .htaccess を置く。
 * - 画面には、短時間だけ有効な「署名つきURL」で渡す。署名には、お客様ID・種類・ファイルIDを含むので、
 *   別のお客様のファイルや、別のファイルのURLに書き換えても、開けない。
 * - ファイルの種類は、送られてきた名前や申告ではなく、中身から判定する。
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Files {
    const URL_TTL = 7200; // 署名つきURLの有効時間（秒）
    const IMAGE_TYPES = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp');
    const MAX_IMAGE_BYTES = 10485760; // 10MB

    /** テスト用：true にすると、アップロード以外のファイル（テストで作ったファイル）も受け付ける */
    public static $allow_local_files = false;

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_serve'), 1);
    }

    public static function base_dir() {
        $u = wp_upload_dir();
        return rtrim($u['basedir'], '/') . '/mush-office-tenants/';
    }

    private static function ensure_base() {
        $base = self::base_dir();
        if (!is_dir($base)) {
            wp_mkdir_p($base);
        }
        if (is_dir($base)) {
            if (!file_exists($base . '.htaccess')) {
                @file_put_contents($base . '.htaccess', "Require all denied\nDeny from all\n");
            }
            if (!file_exists($base . 'index.php')) {
                @file_put_contents($base . 'index.php', "<?php\n// Silence is golden.\n");
            }
        }
        return $base;
    }

    /**
     * アップロードされた画像を、お客様のフォルダに保存する。
     * @return array|WP_Error ['path' => 保存先（お客様フォルダからの相対パス）, 'mime' => , 'name' => 元のファイル名]
     */
    public static function store_image(array $file, $tenant_id, $kind) {
        $tenant_id = (int) $tenant_id;
        if ($tenant_id <= 0 || !preg_match('/^[a-z_]{1,30}$/', $kind)) {
            return new WP_Error('invalid', '保存先が正しくありません。');
        }
        if (!isset($file['tmp_name'], $file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('no_file', '画像ファイルを選択してください。');
        }
        $tmp = (string) $file['tmp_name'];
        if (!self::$allow_local_files && !is_uploaded_file($tmp)) {
            return new WP_Error('no_file', '画像ファイルを選択してください。');
        }
        $size = (int) @filesize($tmp);
        if ($size <= 0 || $size > self::MAX_IMAGE_BYTES) {
            return new WP_Error('size', '画像は10MBまでにしてください。');
        }
        $mime = self::detect_mime($tmp);
        if (!isset(self::IMAGE_TYPES[$mime])) {
            return new WP_Error('type', 'png・jpg・gif・webp形式の画像を選択してください。');
        }
        $dir = self::ensure_base() . $tenant_id . '/' . $kind . '/';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $name = bin2hex(random_bytes(16)) . '.' . self::IMAGE_TYPES[$mime];
        $ok = self::$allow_local_files ? @copy($tmp, $dir . $name) : @move_uploaded_file($tmp, $dir . $name);
        if (!$ok) {
            return new WP_Error('save', '画像の保存に失敗しました。');
        }
        @chmod($dir . $name, 0640);
        $orig = isset($file['name']) ? (string) $file['name'] : '';
        return array('path' => $kind . '/' . $name, 'mime' => $mime, 'name' => mb_substr(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]/u', '', $orig), 0, 200));
    }

    private static function detect_mime($path) {
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            $m = $f ? finfo_file($f, $path) : false;
            if ($f) {
                finfo_close($f);
            }
            if ($m) {
                return (string) $m;
            }
        }
        $info = @getimagesize($path);
        return $info && isset($info['mime']) ? (string) $info['mime'] : '';
    }

    /** お客様フォルダからの相対パスを、実際のパスにする（形が正しくなければ空） */
    public static function abs_path($tenant_id, $rel) {
        if ((int) $tenant_id <= 0 || !preg_match('#^[a-z_]{1,30}/[a-f0-9]{32}\.(png|jpg|gif|webp)$#', (string) $rel)) {
            return '';
        }
        return self::base_dir() . (int) $tenant_id . '/' . $rel;
    }

    public static function delete($tenant_id, $rel) {
        $p = self::abs_path($tenant_id, $rel);
        if ($p !== '' && is_file($p)) {
            @unlink($p);
        }
    }

    /** お客様のファイルを、すべて削除する（解約後の削除で使う） */
    public static function delete_tenant_files($tenant_id) {
        $tenant_id = (int) $tenant_id;
        if ($tenant_id <= 0) {
            return;
        }
        $dir = self::base_dir() . $tenant_id;
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /* ---------- 署名つきURL ---------- */

    public static function sign($tenant_id, $kind, $id, $exp) {
        return hash_hmac('sha256', 'ss_file|' . (int) $tenant_id . '|' . $kind . '|' . (int) $id . '|' . (int) $exp, wp_salt('auth'));
    }

    /** 画面に渡すURL。お客様の内部IDはURLに入れず、署名にだけ含める */
    public static function url($tenant_id, $kind, $id) {
        $exp = (int) (ceil((time() + self::URL_TTL) / 600) * 600); // 10分単位にそろえて、ブラウザのキャッシュが効くようにする
        return add_query_arg(array('ss_file' => $kind, 'id' => (int) $id, 'exp' => $exp, 'sig' => self::sign($tenant_id, $kind, $id, $exp)), home_url('/'));
    }

    /**
     * 署名を確かめて、ファイルの行を返す（送信はしない）。
     * @return array|null ['path' => 実際のパス, 'mime' => , 'name' => ]
     */
    public static function resolve($kind, $id, $exp, $sig) {
        if ($kind !== 'task_image' || (int) $exp < time() || !is_string($sig) || !preg_match('/^[a-f0-9]{64}$/', $sig)) {
            return null;
        }
        $row = SS_System::task_image_by_id((int) $id);
        if (!$row || !hash_equals(self::sign($row['tenant_id'], $kind, $row['id'], $exp), $sig)) {
            return null;
        }
        $tenant = SS_System::tenant_by_id((int) $row['tenant_id']);
        if (!$tenant) {
            return null;
        }
        $path = self::abs_path($row['tenant_id'], $row['file_path']);
        if ($path === '' || !is_file($path)) {
            return null;
        }
        return array('path' => $path, 'mime' => $row['mime'], 'name' => $row['file_name']);
    }

    public static function maybe_serve() {
        if (!isset($_GET['ss_file'])) {
            return;
        }
        $f = self::resolve(
            sanitize_key(wp_unslash($_GET['ss_file'])),
            isset($_GET['id']) ? (int) $_GET['id'] : 0,
            isset($_GET['exp']) ? (int) $_GET['exp'] : 0,
            isset($_GET['sig']) ? sanitize_text_field(wp_unslash($_GET['sig'])) : ''
        );
        if (!$f) {
            status_header(404);
            nocache_headers();
            exit;
        }
        header('Content-Type: ' . (isset(self::IMAGE_TYPES[$f['mime']]) ? $f['mime'] : 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline');
        header('Cache-Control: private, max-age=600');
        header('Content-Length: ' . filesize($f['path']));
        readfile($f['path']);
        exit;
    }
}
