<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * カーペットの色変換：人間の変換の癖を Gemini で学ぶ（管理者だけ）
 *
 * - 「Gemini に送る（学習）」：元画像・システムの変換（標準色のみ／オプション3色／オプション6色）・人間が変換した画像（正解）と、
 *   画面で数えた「元の色のまとまり → 人間が選んだ色」の表を Gemini に送り、人間の変換の癖（学習メモ）を更新する。
 *   送った例は非公開ディレクトリに保存する（新しい順に最大 MAX_EXAMPLES 件）。
 * - 「学習した変換を表示」：学習メモと最近の例（元画像・人間の変換）を手本にして、いまの画像の色のまとまりごとに
 *   パレットの色を Gemini に選ばせる。画像は画面でその対応表を使って作る（パレットの色だけを使う）。
 * - APIキーは管理画面で入力し、サーバーにだけ保存する（ページには出さない）。
 */
class CFP_Gemini {

    const DIR = 'cfp-gemini-private';
    const OPT_KEY = 'cfp_gemini_api_key';
    const OPT_MODEL = 'cfp_gemini_model';
    const OPT_RULES = 'cfp_gemini_rules';
    const DEFAULT_MODEL = 'gemini-2.5-flash';
    const MAX_EXAMPLES = 12;
    const FEW_SHOT = 3;

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_cfp_gemini_learn', [$this, 'handle_learn']);
        add_action('wp_ajax_cfp_gemini_apply', [$this, 'handle_apply']);
        add_action('admin_post_cfp_gemini_settings', [$this, 'handle_settings']);
    }

    public static function can_use() {
        return is_user_logged_in() && current_user_can('manage_options');
    }

    private static function model() {
        $m = trim((string) get_option(self::OPT_MODEL, ''));
        return $m !== '' ? $m : self::DEFAULT_MODEL;
    }

    /* -------------------------------------------------------------- page */

    /** The panel under the carpet conversions (admins only; '' for everyone else). */
    public static function panel_html() {
        if (!self::can_use()) {
            return '';
        }
        wp_enqueue_script('cfp-gemini', CFP_PLUGIN_URL . 'assets/js/gemini-learn.js', [], CFP_VERSION, true);
        wp_localize_script('cfp-gemini', 'cfpGeminiConfig', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('cfp_gemini'),
            'has_key'  => get_option(self::OPT_KEY, '') !== '',
            'examples' => count(self::examples()),
            'rules'    => (string) get_option(self::OPT_RULES, ''),
            'settings' => admin_url('admin.php?page=cad-floor-plan#cfp-gemini'),
        ]);
        ob_start();
        ?>
<style>.cfp-gemini-box{background:#0f172a;border:1px solid #0ea5e9;border-radius:8px;padding:14px 16px;margin:0 0 20px}.cfp-gemini-title{font-size:15px;font-weight:700;color:#7dd3fc;margin-bottom:6px}.cfp-gemini-admin{font-size:11px;font-weight:400;color:#94a3b8;margin-left:8px}.cfp-gemini-text{font-size:13px;color:#e4e4e7;line-height:1.7}.cfp-gemini-row{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;align-items:center}.cfp-gemini-row .btn-chip-action{padding:9px 14px;font-size:13px}.cfp-gemini-send{background:#0369a1!important;border-color:#0ea5e9!important}.cfp-gemini-apply{background:#6d28d9!important;border-color:#8b5cf6!important}.cfp-gemini-file{cursor:pointer}.cfp-gemini-small{font-size:12px;color:#cbd5e1;white-space:pre-line;line-height:1.6}.cfp-gemini-status{font-size:13px;margin-top:8px;color:#fde68a;white-space:pre-line;line-height:1.7}.cfp-gemini-status.is-ok{color:#86efac}.cfp-gemini-status.is-err{color:#fca5a5}.cfp-gemini-grid{margin-top:12px}.cfp-gemini-grid canvas{max-width:100%;height:auto!important;background:#fff}.cfp-gemini-rules{margin-top:10px;color:#e4e4e7;font-size:13px}.cfp-gemini-rules summary{cursor:pointer;color:#7dd3fc}#cfp-gemini-result-text{white-space:pre-line}</style>
<div class="cfp-gemini-box" id="cfp-gemini-box">
  <div class="cfp-gemini-title">🧠 人間の変換を学習（Gemini）<span class="cfp-gemini-admin">管理者だけに表示</span></div>
  <div class="cfp-gemini-text">人間が変換した画像（正解）を選んで「Gemini に送る」を押すと、元画像・システムの変換（標準色のみ／オプション3色／オプション6色）と一緒に Gemini に送り、人間の変換の癖を学びます。「学習した変換を表示」で、学んだ癖でいまの画像を変換します。</div>
  <div class="cfp-gemini-row">
    <label class="btn-chip-action cfp-gemini-file">人間が変換した画像を選ぶ<input type="file" id="cfp-gemini-human" accept="image/png,image/jpeg,image/webp,image/gif,image/bmp" style="display:none!important"></label>
    <span id="cfp-gemini-human-name" class="cfp-gemini-small">未選択</span>
  </div>
  <div class="cfp-gemini-row">
    <button type="button" class="btn-chip-action cfp-gemini-send" id="cfp-gemini-send" disabled>Gemini に送る（学習）</button>
    <button type="button" class="btn-chip-action cfp-gemini-apply" id="cfp-gemini-apply">学習した変換を表示</button>
  </div>
  <div id="cfp-gemini-status" class="cfp-gemini-status"></div>
  <div class="preview-grid cfp-gemini-grid">
    <div class="preview-card" id="cfp-gemini-human-card" hidden><h3>人間が変換した画像（正解）</h3><canvas id="cfp-gemini-human-cv"></canvas></div>
    <div class="preview-card" id="cfp-gemini-result-card" hidden><h3>人間の変換を学んだ変換（Gemini）</h3><canvas id="cfp-gemini-result"></canvas><div class="psd-palette-grid" id="cfp-gemini-result-chips"></div><div class="used-colors-text" id="cfp-gemini-result-text"></div><button type="button" class="btn-chip-action cc-var-btn" id="cfp-gemini-use">この色の組合せをパレットに反映</button></div>
  </div>
  <details class="cfp-gemini-rules"><summary>学習メモ（例 <span id="cfp-gemini-count">0</span> 件）</summary><div id="cfp-gemini-rules-text" class="cfp-gemini-small"></div></details>
</div>
        <?php
        return ob_get_clean();
    }

    /* ----------------------------------------------------------- admin */

    public static function render_settings() {
        $key = (string) get_option(self::OPT_KEY, '');
        $n = count(self::examples());
        $saved = isset($_GET['cfp_gemini']) ? sanitize_key($_GET['cfp_gemini']) : '';
        ?>
        <h2 id="cfp-gemini">人間の変換の学習（Gemini）</h2>
        <?php if ($saved === 'ok') : ?><div class="notice notice-success inline"><p>保存しました。</p></div><?php endif; ?>
        <?php if ($saved === 'reset') : ?><div class="notice notice-success inline"><p>学習内容を消しました。</p></div><?php endif; ?>
        <p>カーペットの色変換で、人間が変換した画像を Gemini に送り、変換の癖を学ばせます（管理者でログインしているときだけ、変換画面に表示されます）。APIキーはこのサーバーにだけ保存し、ページには出しません。</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="cfp_gemini_settings">
            <?php wp_nonce_field('cfp_gemini_settings'); ?>
            <table class="form-table" role="presentation">
                <tr><th scope="row"><label for="cfp-gemini-key">Gemini APIキー</label></th>
                    <td><input type="password" id="cfp-gemini-key" name="api_key" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $key !== '' ? esc_attr('設定済み（変えるときだけ入力）') : ''; ?>">
                    <p class="description">Google AI Studio で発行したキー。<?php echo $key !== '' ? '設定済みです。空のまま保存すると、いまのキーのままです。' : '未設定です。'; ?></p></td></tr>
                <tr><th scope="row"><label for="cfp-gemini-model">モデル</label></th>
                    <td><input type="text" id="cfp-gemini-model" name="model" value="<?php echo esc_attr(self::model()); ?>" class="regular-text">
                    <p class="description">画像を読めるモデル（既定：<?php echo esc_html(self::DEFAULT_MODEL); ?>）。</p></td></tr>
            </table>
            <p>学習した例：<?php echo (int) $n; ?> 件（新しい順に最大 <?php echo (int) self::MAX_EXAMPLES; ?> 件を保存）</p>
            <?php submit_button('保存', 'primary', 'save', false); ?>
            <?php if ($key !== '') : ?> <?php submit_button('APIキーを削除', 'secondary', 'delete_key', false); ?><?php endif; ?>
            <?php submit_button('学習内容を消す', 'delete', 'reset', false, ['onclick' => "return confirm('学習した例と学習メモをすべて消します。よろしいですか？');"]); ?>
        </form>
        <?php
    }

    public function handle_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('権限がありません。');
        }
        check_admin_referer('cfp_gemini_settings');
        $result = 'ok';
        if (isset($_POST['reset'])) {
            delete_option(self::OPT_RULES);
            foreach (glob(self::base_dir() . '/ex-*') ?: [] as $d) {
                self::rmdir($d);
            }
            $result = 'reset';
        } elseif (isset($_POST['delete_key'])) {
            delete_option(self::OPT_KEY);
        } else {
            $key = isset($_POST['api_key']) ? trim(sanitize_text_field(wp_unslash($_POST['api_key']))) : '';
            if ($key !== '') {
                update_option(self::OPT_KEY, $key, false);
            }
            $model = isset($_POST['model']) ? preg_replace('/[^A-Za-z0-9._-]/', '', wp_unslash($_POST['model'])) : '';
            update_option(self::OPT_MODEL, $model !== '' ? $model : self::DEFAULT_MODEL, false);
        }
        wp_safe_redirect(admin_url('admin.php?page=cad-floor-plan&cfp_gemini=' . $result . '#cfp-gemini'));
        exit;
    }

    /* ----------------------------------------------------------- storage */

    private static function base_dir() {
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

    private static function rmdir($dir) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? self::rmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    /** Saved examples, newest first: [ ['dir' => .., 'meta' => [..]] ]. */
    private static function examples() {
        $dirs = glob(self::base_dir() . '/ex-*', GLOB_ONLYDIR) ?: [];
        rsort($dirs);
        $out = [];
        foreach ($dirs as $d) {
            $meta = json_decode((string) @file_get_contents($d . '/meta.json'), true);
            if (is_array($meta)) {
                $out[] = ['dir' => $d, 'meta' => $meta];
            }
        }
        return $out;
    }

    /* -------------------------------------------------------------- AJAX */

    private function check() {
        if (!self::can_use()) {
            wp_send_json_error(['message' => '管理者でログインしてください。'], 403);
        }
        check_ajax_referer('cfp_gemini', 'nonce');
        if (get_option(self::OPT_KEY, '') === '') {
            wp_send_json_error(['message' => 'Gemini のAPIキーが未設定です。管理画面「壁紙・カーペット」で設定してください。']);
        }
    }

    /** A PNG/JPEG data URL from the page -> [mime, base64], or null. */
    private static function image($field) {
        $v = isset($_POST[$field]) ? (string) wp_unslash($_POST[$field]) : '';
        if (!preg_match('#^data:(image/(?:png|jpeg));base64,([A-Za-z0-9+/=]+)$#', $v, $m)) {
            return null;
        }
        if (strlen($m[2]) > 6 * 1048576) {
            return null;
        }
        return [$m[1], $m[2]];
    }

    private static function json_field($field) {
        $v = isset($_POST[$field]) ? json_decode((string) wp_unslash($_POST[$field]), true) : null;
        return is_array($v) ? $v : [];
    }

    private static function palette_text($palette) {
        $lines = [];
        foreach ($palette as $c) {
            if (!isset($c['name'], $c['hex'])) {
                continue;
            }
            $lines[] = sprintf('%s %s%s', sanitize_text_field($c['name']), sanitize_text_field($c['hex']), !empty($c['paid']) ? '（有償）' : '');
        }
        return implode("\n", $lines);
    }

    private static function clusters_text($clusters, $with_human) {
        $lines = [];
        foreach ($clusters as $i => $c) {
            $line = sprintf('#%d 元の色 %s・面積 %s%%', $i, sanitize_text_field($c['hex'] ?? ''), (float) ($c['share'] ?? 0));
            if (!empty($c['sys'])) {
                $line .= '・システム（標準色のみ）→ ' . sanitize_text_field($c['sys']);
            }
            if ($with_human && !empty($c['human'])) {
                $line .= '・人間 → ' . sanitize_text_field($c['human']);
                if (isset($c['human_pct'])) {
                    $line .= '（' . (float) $c['human_pct'] . '%）';
                }
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    public function handle_learn() {
        $this->check();
        $orig = self::image('original');
        $human = self::image('human');
        if (!$orig || !$human) {
            wp_send_json_error(['message' => '元画像と人間が変換した画像を送れませんでした。']);
        }
        $sys = ['std' => self::image('sys_std'), 'op3' => self::image('sys_op3'), 'op6' => self::image('sys_op6')];
        $clusters = self::json_field('clusters');
        $palette = self::json_field('palette');
        $rules = (string) get_option(self::OPT_RULES, '');

        $parts = [];
        $parts[] = ['text' => "あなたはカーペット（タフテッド）の色変換の職人の癖を学ぶアシスタントです。元の画像を、決められたパレットの色（最大16色）だけで塗り直す変換を、人間の職人が行いました。人間の変換が正解です。システムの自動変換と比べて、人間がどう色を選び・まとめ・残しているか（色の置き換え方、細部や輪郭の扱い、似た色のまとめ方、強調する色、避ける色、全体の印象など）を学んでください。\n\nパレット（名前 色）：\n" . self::palette_text($palette)];
        $parts[] = ['text' => '画像1：元画像'];
        $parts[] = ['inline_data' => ['mime_type' => $orig[0], 'data' => $orig[1]]];
        $labels = ['std' => 'システムの変換（標準色のみ）', 'op3' => 'システムの変換（標準色＋オプション3色）', 'op6' => 'システムの変換（標準色＋オプション6色）'];
        $n = 2;
        foreach ($sys as $k => $img) {
            if ($img) {
                $parts[] = ['text' => '画像' . $n++ . '：' . $labels[$k]];
                $parts[] = ['inline_data' => ['mime_type' => $img[0], 'data' => $img[1]]];
            }
        }
        $parts[] = ['text' => '画像' . $n . '：人間が変換した画像（正解）'];
        $parts[] = ['inline_data' => ['mime_type' => $human[0], 'data' => $human[1]]];
        $parts[] = ['text' => "画面で数えた、元の色のまとまりごとの対応（同じ位置の画素で比べた結果）：\n" . self::clusters_text($clusters, true)
            . "\n\nこれまでの学習メモ：\n" . ($rules !== '' ? $rules : '（まだありません）')
            . "\n\n次の JSON だけを返してください：{\"observations\": \"この例から分かった人間の癖（日本語・箇条書き・400字以内）\", \"rules\": \"これまでの学習メモにこの例の学びを統合した、次の変換で使う具体的なルール（日本語・箇条書き・1500字以内。色の名前はパレットの名前で）\"}"];

        $res = self::call($parts);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }
        $new_rules = isset($res['rules']) ? sanitize_textarea_field((string) $res['rules']) : '';
        $obs = isset($res['observations']) ? sanitize_textarea_field((string) $res['observations']) : '';
        if ($new_rules !== '') {
            update_option(self::OPT_RULES, $new_rules, false);
        }

        // 例として保存（元画像・人間の変換・対応表）。古いものから消す
        $dir = self::base_dir() . '/ex-' . gmdate('YmdHis') . '-' . wp_generate_password(6, false, false);
        wp_mkdir_p($dir);
        file_put_contents($dir . '/original.' . ($orig[0] === 'image/png' ? 'png' : 'jpg'), base64_decode($orig[1]));
        file_put_contents($dir . '/human.' . ($human[0] === 'image/png' ? 'png' : 'jpg'), base64_decode($human[1]));
        file_put_contents($dir . '/meta.json', wp_json_encode([
            'time'         => time(),
            'orig_mime'    => $orig[0],
            'human_mime'   => $human[0],
            'clusters'     => $clusters,
            'observations' => $obs,
        ]));
        $all = self::examples();
        foreach (array_slice($all, self::MAX_EXAMPLES) as $old) {
            self::rmdir($old['dir']);
        }
        wp_send_json_success([
            'observations' => $obs,
            'rules'        => $new_rules !== '' ? $new_rules : $rules,
            'examples'     => min(count($all), self::MAX_EXAMPLES),
        ]);
    }

    public function handle_apply() {
        $this->check();
        $orig = self::image('original');
        if (!$orig) {
            wp_send_json_error(['message' => '元画像を送れませんでした。']);
        }
        $clusters = self::json_field('clusters');
        $palette = self::json_field('palette');
        $rules = (string) get_option(self::OPT_RULES, '');
        $examples = array_slice(self::examples(), 0, self::FEW_SHOT);
        if ($rules === '' && !$examples) {
            wp_send_json_error(['message' => 'まだ学習していません。先に「Gemini に送る（学習）」で人間の変換を送ってください。']);
        }

        $parts = [];
        $parts[] = ['text' => "あなたはカーペット（タフテッド）の色変換の職人です。人間の職人の変換の癖を学んだ内容を使って、新しい画像をパレットの色（最大16色）だけで塗り直すための色の割り当てを決めます。\n\n学習メモ：\n" . ($rules !== '' ? $rules : '（なし）')];
        $k = 1;
        foreach ($examples as $ex) {
            $m = $ex['meta'];
            $o = $ex['dir'] . '/original.' . (($m['orig_mime'] ?? '') === 'image/png' ? 'png' : 'jpg');
            $h = $ex['dir'] . '/human.' . (($m['human_mime'] ?? '') === 'image/png' ? 'png' : 'jpg');
            if (!is_readable($o) || !is_readable($h)) {
                continue;
            }
            $parts[] = ['text' => '手本' . $k . '：元画像'];
            $parts[] = ['inline_data' => ['mime_type' => $m['orig_mime'], 'data' => base64_encode(file_get_contents($o))]];
            $parts[] = ['text' => '手本' . $k . '：人間の変換（正解）。色の対応：' . "\n" . self::clusters_text(is_array($m['clusters'] ?? null) ? $m['clusters'] : [], true)];
            $parts[] = ['inline_data' => ['mime_type' => $m['human_mime'], 'data' => base64_encode(file_get_contents($h))]];
            $k++;
        }
        $parts[] = ['text' => "今回変換する画像："];
        $parts[] = ['inline_data' => ['mime_type' => $orig[0], 'data' => $orig[1]]];
        $parts[] = ['text' => "パレット（名前 色）：\n" . self::palette_text($palette)
            . "\n\n今回の画像の色のまとまり：\n" . self::clusters_text($clusters, false)
            . "\n\nそれぞれのまとまり（#番号）に、人間の職人ならどのパレットの色を使うかを決めてください。使う色は全部で16色以内、パレットの名前をそのまま使います。次の JSON だけを返してください：{\"assign\": [{\"i\": 0, \"color\": \"パレットの名前\"}, ...（すべてのまとまり）], \"comment\": \"どう考えたか（日本語・200字以内）\"}"];

        $res = self::call($parts);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }
        $assign = [];
        foreach ((array) ($res['assign'] ?? []) as $a) {
            if (is_array($a) && isset($a['i'], $a['color'])) {
                $assign[] = ['i' => (int) $a['i'], 'color' => sanitize_text_field((string) $a['color'])];
            }
        }
        if (!$assign) {
            wp_send_json_error(['message' => 'Gemini の答えから色の割り当てを読み取れませんでした。もう一度お試しください。']);
        }
        wp_send_json_success([
            'assign'  => $assign,
            'comment' => isset($res['comment']) ? sanitize_textarea_field((string) $res['comment']) : '',
        ]);
    }

    /** Calls Gemini (generateContent, JSON answer). Returns the decoded JSON or WP_Error. */
    private static function call($parts) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(self::model()) . ':generateContent';
        $resp = wp_remote_post($url, [
            'timeout' => 120,
            'headers' => [
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => (string) get_option(self::OPT_KEY, ''),
            ],
            'body'    => wp_json_encode([
                'contents'         => [['role' => 'user', 'parts' => $parts]],
                'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.2],
            ]),
        ]);
        if (is_wp_error($resp)) {
            return new WP_Error('cfp_gemini', 'Gemini に接続できませんでした：' . $resp->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = json_decode((string) wp_remote_retrieve_body($resp), true);
        if ($code !== 200) {
            $msg = is_array($body) && isset($body['error']['message']) ? (string) $body['error']['message'] : 'HTTP ' . $code;
            return new WP_Error('cfp_gemini', 'Gemini からエラーが返りました：' . $msg);
        }
        $text = '';
        foreach ((array) ($body['candidates'][0]['content']['parts'] ?? []) as $p) {
            if (isset($p['text'])) {
                $text .= $p['text'];
            }
        }
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));
        $json = json_decode($text, true);
        if (!is_array($json)) {
            return new WP_Error('cfp_gemini', 'Gemini の答えを読み取れませんでした。');
        }
        return $json;
    }
}
