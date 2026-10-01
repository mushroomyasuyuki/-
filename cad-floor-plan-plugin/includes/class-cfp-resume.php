<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 作業の途中保存と再開（壁紙・カーペットシミュレーション）
 *
 * - 「途中保存」で、入力内容（JSON）と画像・図面ファイルを非公開ディレクトリに保存し、
 *   再開用リンク（ページURL + ?cfp_resume=トークン）をお客様のメールに送る。
 * - リンクを開くと、保存した内容を読み込んで続きから再開できる（どの端末からでも可）。
 * - 保存から CFP_RESUME_DAYS 日（30日）で、cron により自動削除する。再開後に保存し直すと、同じリンクのまま期限が延びる。
 */
class CFP_Resume {

    const DIR = 'cfp-resume-private';
    const CRON = 'cfp_resume_cleanup';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_cfp_resume_save', [$this, 'handle_save']);
        add_action('wp_ajax_nopriv_cfp_resume_save', [$this, 'handle_save']);
        add_action('wp_ajax_cfp_resume_load', [$this, 'handle_load']);
        add_action('wp_ajax_nopriv_cfp_resume_load', [$this, 'handle_load']);
        add_action('wp_ajax_cfp_resume_file', [$this, 'handle_file']);
        add_action('wp_ajax_nopriv_cfp_resume_file', [$this, 'handle_file']);
        add_action(self::CRON, [$this, 'cleanup']);
        add_action('init', [__CLASS__, 'schedule']);
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON);
        }
    }

    public static function unschedule() {
        $ts = wp_next_scheduled(self::CRON);
        if ($ts) {
            wp_unschedule_event($ts, self::CRON);
        }
    }

    /** Settings for the page script. */
    public static function client_config() {
        return [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('cfp_resume'),
            'days'     => CFP_RESUME_DAYS,
            'max_mb'   => (int) floor(self::max_total() / 1048576),
        ];
    }

    private static function max_total() {
        return (int) apply_filters('cfp_resume_max_total_bytes', 150 * 1048576);
    }

    /* ----------------------------------------------------------- storage */

    public static function base_dir() {
        $up = wp_upload_dir();
        $dir = trailingslashit($up['basedir']) . self::DIR;
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        if (!file_exists($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n");
        }
        if (!file_exists($dir . '/index.php')) {
            file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        return $dir;
    }

    private static function clean_token($t) {
        $t = preg_replace('/[^a-f0-9]/', '', strtolower((string) $t));
        return strlen($t) === 40 ? $t : '';
    }

    private static function token_dir($token) {
        return trailingslashit(self::base_dir()) . $token;
    }

    private static function read_meta($token) {
        $file = self::token_dir($token) . '/meta.json';
        if (!$token || !file_exists($file)) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($file), true);
        return is_array($meta) ? $meta : null;
    }

    private static function remove_dir($dir) {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) glob(trailingslashit($dir) . '*') as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    /* -------------------------------------------------------------- save */

    public function handle_save() {
        if (!check_ajax_referer('cfp_resume', 'nonce', false)) {
            wp_send_json_error(['message' => 'ページの有効期限が切れました。ページを再読み込みしてから、もう一度保存してください。'], 403);
        }
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if (!is_email($email)) {
            wp_send_json_error(['message' => '再開用リンクを送るメールアドレスを、正しく入力してください。'], 400);
        }
        $state = isset($_POST['state']) ? wp_unslash($_POST['state']) : '';
        if (strlen($state) > 5 * 1048576 || !is_array(json_decode($state, true))) {
            wp_send_json_error(['message' => '保存する内容が正しくありません。'], 400);
        }

        // files[] (name = "key::original name")
        $allowed = apply_filters('cfp_resume_allowed_extensions', ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'dxf', 'json']);
        $incoming = [];
        $total = strlen($state);
        if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
            $n = count($_FILES['files']['name']);
            if ($n > 30) {
                wp_send_json_error(['message' => 'ファイルが多すぎます（30個まで）。'], 400);
            }
            for ($i = 0; $i < $n; $i++) {
                if ((int) $_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
                    wp_send_json_error(['message' => 'ファイルの送信に失敗しました。'], 400);
                }
                $raw = (string) $_FILES['files']['name'][$i];
                $parts = explode('::', $raw, 2);
                $key = preg_replace('/[^a-z0-9_\-]/', '', strtolower($parts[0]));
                $name = sanitize_file_name(isset($parts[1]) ? $parts[1] : $parts[0]);
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if ($key === '' || !in_array($ext, $allowed, true)) {
                    wp_send_json_error(['message' => '保存できない形式のファイルが含まれています。（' . esc_html($ext) . '）'], 400);
                }
                $total += (int) $_FILES['files']['size'][$i];
                $incoming[] = ['key' => $key, 'name' => $name, 'ext' => $ext, 'tmp' => $_FILES['files']['tmp_name'][$i], 'size' => (int) $_FILES['files']['size'][$i]];
            }
        }
        if ($total > self::max_total()) {
            wp_send_json_error(['message' => '保存する画像・図面の合計が大きすぎます（' . size_format(self::max_total()) . 'まで）。'], 400);
        }

        // overwrite the same save when continuing from a resume link (the link stays the same)
        $token = self::clean_token(isset($_POST['token']) ? wp_unslash($_POST['token']) : '');
        $old = $token ? self::read_meta($token) : null;
        if (!$old) {
            $token = bin2hex(random_bytes(20));
        }
        $dir = self::token_dir($token);
        self::remove_dir($dir);
        wp_mkdir_p($dir);

        $files = [];
        foreach ($incoming as $i => $f) {
            $stored = 'f' . $i . '.' . $f['ext'];
            if (!move_uploaded_file($f['tmp'], $dir . '/' . $stored)) {
                self::remove_dir($dir);
                wp_send_json_error(['message' => 'ファイルの保存に失敗しました。'], 500);
            }
            $files[] = ['key' => $f['key'], 'name' => $f['name'], 'stored' => $stored, 'size' => $f['size']];
        }
        file_put_contents($dir . '/state.json', $state);

        $now = time();
        $expires = $now + CFP_RESUME_DAYS * DAY_IN_SECONDS;
        $page = isset($_POST['page_url']) ? esc_url_raw(wp_unslash($_POST['page_url'])) : '';
        if (!$page || wp_parse_url($page, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST)) {
            $page = home_url('/');
        }
        $page = remove_query_arg('cfp_resume', $page);
        $url = add_query_arg('cfp_resume', $token, $page);
        $meta = [
            'email'   => $email,
            'created' => $old && !empty($old['created']) ? $old['created'] : $now,
            'saved'   => $now,
            'expires' => $expires,
            'url'     => $url,
            'files'   => $files,
        ];
        file_put_contents($dir . '/meta.json', wp_json_encode($meta));

        $date = wp_date('Y年n月j日', $expires);
        $site = get_bloginfo('name');
        $subject = '【作業の途中保存】' . $site . ' 壁紙・カーペットシミュレーション';
        $body  = "壁紙・カーペットシミュレーションの作業内容を保存しました。\n\n";
        $body .= "下のリンクを開くと、保存した時点の続きから作業を再開できます（パソコン・スマートフォンのどちらからでも開けます）。\n\n";
        $body .= "■ 再開用リンク\n{$url}\n\n";
        $body .= "■ 保存期限\n{$date}まで（保存から" . CFP_RESUME_DAYS . "日間）\n";
        $body .= "期限を過ぎると、保存した内容（画像・図面を含む）は自動的に削除されます。再開後にもう一度保存すると、同じリンクのまま期限が延びます。\n\n";
        $body .= "※このリンクを知っている人は、保存した内容を開くことができます。他の方には共有しないでください。\n";
        $body .= "※このメールにお心当たりがない場合は、破棄してください。\n\n";
        $body .= $site . "\n" . home_url('/') . "\n";
        $sent = wp_mail($email, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);

        wp_send_json_success([
            'token'   => $token,
            'url'     => $url,
            'expires' => $date,
            'mailed'  => (bool) $sent,
        ]);
    }

    /* -------------------------------------------------------------- load */

    public function handle_load() {
        $token = self::clean_token(isset($_GET['token']) ? wp_unslash($_GET['token']) : '');
        $meta = self::read_meta($token);
        if (!$meta) {
            wp_send_json_error(['message' => '保存した作業が見つかりません。リンクが間違っているか、保存期限（' . CFP_RESUME_DAYS . '日間）を過ぎて削除された可能性があります。'], 404);
        }
        if ((int) $meta['expires'] < time()) {
            self::remove_dir(self::token_dir($token));
            wp_send_json_error(['message' => '保存期限（' . CFP_RESUME_DAYS . '日間）を過ぎたため、保存した作業は削除されました。'], 410);
        }
        $state = json_decode((string) file_get_contents(self::token_dir($token) . '/state.json'), true);
        $files = [];
        foreach ((array) $meta['files'] as $f) {
            $files[] = [
                'key'  => $f['key'],
                'name' => $f['name'],
                'url'  => add_query_arg(['action' => 'cfp_resume_file', 'token' => $token, 'f' => $f['stored']], admin_url('admin-ajax.php')),
            ];
        }
        nocache_headers();
        wp_send_json_success([
            'state'   => $state,
            'files'   => $files,
            'expires' => wp_date('Y年n月j日', (int) $meta['expires']),
            'email'   => $meta['email'],
        ]);
    }

    public function handle_file() {
        $token = self::clean_token(isset($_GET['token']) ? wp_unslash($_GET['token']) : '');
        $stored = isset($_GET['f']) ? preg_replace('/[^a-z0-9_.]/', '', strtolower(wp_unslash($_GET['f']))) : '';
        $meta = self::read_meta($token);
        $hit = null;
        if ($meta && (int) $meta['expires'] >= time()) {
            foreach ((array) $meta['files'] as $f) {
                if ($f['stored'] === $stored) {
                    $hit = $f;
                }
            }
        }
        $path = $hit ? self::token_dir($token) . '/' . $hit['stored'] : '';
        if (!$hit || !file_exists($path)) {
            status_header(404);
            wp_die('ファイルが見つかりません。', '', ['response' => 404]);
        }
        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode($hit['name']) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /* ----------------------------------------------------------- cleanup */

    public function cleanup() {
        foreach ((array) glob(trailingslashit(self::base_dir()) . '*', GLOB_ONLYDIR) as $dir) {
            $meta = self::read_meta(basename($dir));
            if (!$meta || (int) $meta['expires'] < time()) {
                self::remove_dir($dir);
            }
        }
    }

    /** Removes every saved work (uninstall). */
    public static function remove_all() {
        $base = self::base_dir();
        foreach ((array) glob(trailingslashit($base) . '*', GLOB_ONLYDIR) as $dir) {
            self::remove_dir($dir);
        }
        @unlink($base . '/.htaccess');
        @unlink($base . '/index.php');
        @rmdir($base);
    }
}
