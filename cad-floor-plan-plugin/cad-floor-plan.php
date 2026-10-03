<?php
/**
 * Plugin Name: 壁紙・カーペットシミュレーション（CAD対応）
 * Plugin URI: https://github.com/mushroomyasuyuki/-
 * Description: 壁紙・カーペットのデザイン減色・見積もり・お部屋パースのシミュレーションに、DXF / ベクターPDF / JSON / PNG・JPEG の間取り読み込み（CAD 3D表示）と、注文メール送信（Design Order Mailer 同梱）を組み合わせたプラグイン。有効化すると「壁紙・カーペットシミュレーション」固定ページを自動作成し、無効化すると削除します。
 * Version: 2.68.0
 * Author: mushroomyasuyuki
 * License: MIT
 * Text Domain: cad-floor-plan
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CFP_VERSION', '2.68.0');
define('CFP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CFP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CFP_PAGE_TITLE', '壁紙・カーペットシミュレーション');
define('CFP_PAGE_SLUG', '壁紙・カーペットシミュレーション');
define('CFP_SHORTCODE', 'cad_floor_plan');
define('CFP_SIM_SHORTCODE', 'wallpaper_carpet_simulator');
if (!defined('CFP_RESUME_DAYS')) {
    define('CFP_RESUME_DAYS', 30); // 途中保存した作業の保存日数
}
if (!defined('CFP_OM_EXPIRY_DAYS')) {
    define('CFP_OM_EXPIRY_DAYS', 60); // 注文デザインファイルの保存日数
}

final class CAD_Floor_Plan_Plugin {
    private static $instance = null;
    private $assets_enqueued = false;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'maybe_upgrade']);
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_assets']);
        add_shortcode(CFP_SHORTCODE, [$this, 'render_shortcode']);
        add_shortcode(CFP_SIM_SHORTCODE, [$this, 'render_simulator']);
        $this->load_order_mailer();
        require_once CFP_PLUGIN_DIR . 'includes/class-cfp-resume.php';
        CFP_Resume::instance();
        add_action('rest_api_init', [$this, 'register_rest']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_cfp_create_page', [$this, 'handle_create_page']);
    }

    /* --------------------------------------------------------- order mailer */

    /**
     * Loads the bundled Design Order Mailer unless the standalone plugin is active
     * (both register the same AJAX actions, so only one may run).
     */
    private function load_order_mailer() {
        if (defined('DOMAILER_VERSION')) {
            return;
        }
        require_once CFP_PLUGIN_DIR . 'includes/order-mailer/class-domailer.php';
        CFP_Domailer::instance();
    }

    /* ---------------------------------------------------------- versioning */

    public function maybe_upgrade() {
        $stored = get_option('cad_floor_plan_version');
        if ($stored === CFP_VERSION) {
            return;
        }
        // 1.x managed a CAD-only page; turn it into the combined simulator page.
        if ($stored && version_compare($stored, '2.0.0', '<')) {
            $id = (int) get_option('cad_floor_plan_page_id');
            if ($id && get_option('cad_floor_plan_page_created') && get_post_type($id) === 'page') {
                wp_update_post([
                    'ID'           => $id,
                    'post_title'   => CFP_PAGE_TITLE,
                    'post_name'    => CFP_PAGE_SLUG,
                    'post_content' => self::page_content(),
                ]);
            }
        }
        // 2.1.0: apply the Cocoon page settings to the page this plugin created.
        if ($stored && version_compare($stored, '2.1.0', '<')) {
            $id = (int) get_option('cad_floor_plan_page_id');
            if ($id && get_option('cad_floor_plan_page_created') && get_post_type($id) === 'page') {
                self::apply_page_meta($id);
            }
        }
        update_option('cad_floor_plan_version', CFP_VERSION);
    }

    /* ------------------------------------------------------------- the page */

    /**
     * Returns the ID of the simulator page, creating it if it does not exist.
     */
    public static function ensure_page() {
        $id = (int) get_option('cad_floor_plan_page_id');
        if ($id) {
            $page = get_post($id);
            if ($page && $page->post_type === 'page' && $page->post_status !== 'trash') {
                return $id;
            }
        }

        $existing = self::find_page_by_slug();
        if ($existing && strpos($existing->post_content, '[' . CFP_SIM_SHORTCODE) !== false) {
            update_option('cad_floor_plan_page_id', $existing->ID);
            update_option('cad_floor_plan_page_created', 0);
            return (int) $existing->ID;
        }

        $new_id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => CFP_PAGE_TITLE,
            'post_name'    => CFP_PAGE_SLUG,
            'post_content' => self::page_content(),
            'meta_input'   => self::page_meta(),
        ], true);

        if (is_wp_error($new_id)) {
            return 0;
        }
        update_option('cad_floor_plan_page_id', $new_id);
        update_option('cad_floor_plan_page_created', 1);
        return (int) $new_id;
    }

    /**
     * Cocoon page settings: "本文のみ（フルワイド）", hide title, reading time and table of contents.
     * Other themes ignore these custom fields.
     */
    private static function page_meta() {
        return [
            'page_type'                   => 'content_only_full_wide',
            'the_page_title_novisible'    => 1,
            'the_page_read_time_novisible' => 1,
            'the_page_toc_novisible'      => 1,
        ];
    }

    private static function apply_page_meta($id) {
        foreach (self::page_meta() as $key => $value) {
            update_post_meta($id, $key, $value);
        }
    }

    private static function page_content() {
        return '<!-- wp:shortcode -->[' . CFP_SIM_SHORTCODE . ']<!-- /wp:shortcode -->';
    }

    private static function find_page_by_slug() {
        $found = get_posts([
            'post_type'   => 'page',
            'name'        => sanitize_title(CFP_PAGE_SLUG),
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'numberposts' => 1,
        ]);
        return $found ? $found[0] : null;
    }

    /**
     * Deletes the simulator page if this plugin created it. A pre-existing page that was only
     * adopted is left alone.
     */
    public static function remove_page() {
        $id = (int) get_option('cad_floor_plan_page_id');
        if ($id && get_option('cad_floor_plan_page_created') && get_post_type($id) === 'page') {
            wp_delete_post($id, true);
        }
        delete_option('cad_floor_plan_page_id');
        delete_option('cad_floor_plan_page_created');
    }

    public function handle_create_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('権限がありません。', 'cad-floor-plan'), 403);
        }
        check_admin_referer('cfp_create_page');
        $id = self::ensure_page();
        wp_safe_redirect(add_query_arg(
            ['page' => 'cad-floor-plan', 'cfp_page' => $id ? 'ok' : 'error'],
            admin_url('admin.php')
        ));
        exit;
    }

    /* --------------------------------------------------------------- assets */

    public function maybe_enqueue_assets() {
        $post = get_post();
        if ($post && (has_shortcode($post->post_content, CFP_SHORTCODE) || has_shortcode($post->post_content, CFP_SIM_SHORTCODE))) {
            $this->enqueue_assets();
        }
    }

    private function enqueue_assets() {
        if ($this->assets_enqueued) {
            return;
        }
        $this->assets_enqueued = true;

        wp_enqueue_style('cfp-style', CFP_PLUGIN_URL . 'assets/css/style.css', [], CFP_VERSION);

        wp_register_script('cfp-three', 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js', [], '128', true);
        wp_register_script('cfp-parser', CFP_PLUGIN_URL . 'assets/js/cad-parser.js', [], CFP_VERSION, true);
        wp_register_script('cfp-renderer', CFP_PLUGIN_URL . 'assets/js/three-room-renderer.js', ['cfp-three', 'cfp-parser'], CFP_VERSION, true);
        wp_register_script('cfp-tracer', CFP_PLUGIN_URL . 'assets/js/room-tracer.js', [], CFP_VERSION, true);
        wp_register_script('cfp-main', CFP_PLUGIN_URL . 'assets/js/main.js', ['cfp-renderer', 'cfp-tracer'], CFP_VERSION, true);

        wp_localize_script('cfp-parser', 'cadFloorPlanData', [
            'pdfJsUrl'     => apply_filters('cad_floor_plan_pdfjs_url', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js'),
            'pdfWorkerUrl' => apply_filters('cad_floor_plan_pdfjs_worker_url', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js'),
            'version'      => CFP_VERSION,
        ]);

        wp_enqueue_script('cfp-main');
    }

    /* ------------------------------------------------------------ shortcode */

    /**
     * [wallpaper_carpet_simulator]: the original wallpaper/carpet tool, with the CAD widget
     * placed where templates/wallpaper-carpet-tool.html has the [cad_floor_plan] placeholder.
     */
    public function render_simulator() {
        $file = CFP_PLUGIN_DIR . 'templates/wallpaper-carpet-tool.html';
        if (!is_readable($file)) {
            return '<!-- wallpaper-carpet-tool.html not found -->';
        }
        $this->enqueue_assets();
        // Block themes render the content before wp_enqueue_scripts, so the handle may not be registered yet.
        wp_enqueue_script('domailer-frontend');
        // 作業の途中保存・再開
        wp_enqueue_script('cfp-resume', CFP_PLUGIN_URL . 'assets/js/resume.js', ['cfp-main'], CFP_VERSION, true);
        wp_localize_script('cfp-resume', 'cfpResumeConfig', CFP_Resume::client_config());
        $html = file_get_contents($file);
        $html = str_replace('{{CFP_ASSET_URL}}', esc_url(CFP_PLUGIN_URL . 'assets/'), $html);
        $html = $this->fill_badges($html);
        $html = $this->fill_photos($html);
        return str_replace('[' . CFP_SHORTCODE . ']', $this->render_shortcode(['height' => '600']), $html);
    }

    /**
     * Fills the 制電マーク / 防炎マーク images. Order: assets/badges/{seiden,bouen}.*, then an image in
     * the media library whose title contains the mark name (e.g. "seiden_mark_制電マーク.jpg").
     * A missing image is removed so that no broken-image icon is shown.
     */
    private function fill_badges($html) {
        $marks = [
            'SEIDEN' => ['seiden', ['制電マーク', 'seiden']],
            'BOUEN'  => ['bouen', ['防炎マーク', 'bouen', 'boen']],
        ];
        foreach ($marks as $marker => $def) {
            $token = '{{CFP_BADGE_' . $marker . '}}';
            $url = apply_filters('cfp_badge_url', $this->find_badge_url($def[0], $def[1]), strtolower($marker));
            if ($url) {
                $html = str_replace($token, esc_url($url), $html);
            } else {
                $html = preg_replace('#<img[^>]*' . preg_quote($token, '#') . '[^>]*>#', '', $html);
            }
        }
        return $html;
    }

    /**
     * Fills the carpet photos of the product spec cards ({{CFP_PHOTO_CUT}} / {{CFP_PHOTO_LOOP}}).
     * Order: assets/img/<name>.(jpg|jpeg|png|webp), then a media library file named <name>.* (e.g. uploads/2026/10/carpet-cut-pile.jpg).
     * With no image the whole <figure> is removed, so that no broken image shows.
     */
    private function fill_photos($html) {
        $photos = ['CUT' => 'carpet-cut-pile', 'LOOP' => 'carpet-loop-pile'];
        foreach ($photos as $marker => $name) {
            $token = '{{CFP_PHOTO_' . $marker . '}}';
            $url = apply_filters('cfp_photo_url', $this->find_photo_url($name), strtolower($marker));
            if ($url) {
                $html = str_replace($token, esc_url($url), $html);
            } else {
                $html = preg_replace('#<figure[^>]*>(?:(?!</figure>).)*' . preg_quote($token, '#') . '.*?</figure>#s', '', $html);
            }
        }
        return $html;
    }

    private function find_photo_url($name) {
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            if (is_readable(CFP_PLUGIN_DIR . 'assets/img/' . $name . '.' . $ext)) {
                return CFP_PLUGIN_URL . 'assets/img/' . $name . '.' . $ext;
            }
        }
        $cache_key = 'cfp_photo_' . $name;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        $url = '';
        // the file name in the media library (the title may have been renamed)
        $found = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'numberposts'    => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [[
                'key'     => '_wp_attached_file',
                'value'   => '/' . $name . '.',
                'compare' => 'LIKE',
            ]],
        ]);
        if ($found) {
            $url = (string) wp_get_attachment_url($found[0]->ID);
        }
        set_transient($cache_key, $url, 10 * MINUTE_IN_SECONDS);
        return $url;
    }

    private function find_badge_url($name, array $terms) {
        foreach (['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'] as $ext) {
            if (is_readable(CFP_PLUGIN_DIR . 'assets/badges/' . $name . '.' . $ext)) {
                return CFP_PLUGIN_URL . 'assets/badges/' . $name . '.' . $ext;
            }
        }
        $cache_key = 'cfp_badge_' . $name;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        $url = '';
        foreach ($terms as $term) {
            $found = get_posts([
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                's'              => $term,
                'numberposts'    => 1,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ]);
            if ($found) {
                $url = (string) wp_get_attachment_url($found[0]->ID);
                break;
            }
        }
        set_transient($cache_key, $url, 10 * MINUTE_IN_SECONDS);
        return $url;
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts([
            'width'  => '',
            'height' => '600',
            'sample' => 'default',
        ], $atts, CFP_SHORTCODE);

        $this->enqueue_assets();

        $style = $atts['width'] !== '' ? 'max-width:' . absint($atts['width']) . 'px;' : '';

        ob_start();
        ?>
        <div class="cad-floor-plan-widget" data-height="<?php echo esc_attr(absint($atts['height'])); ?>" data-sample="<?php echo esc_attr($atts['sample']); ?>"<?php echo $style ? ' style="' . esc_attr($style) . '"' : ''; ?>>
            <div class="cfp-container">
                <div class="cfp-header">
                    <h2>CADお部屋シミュレーション</h2>
                    <p>DXF / PDF（ベクター）/ JSON の間取りを3D表示し、床・壁の面積を計算します</p>
                </div>

                <div class="cfp-input-area">
                    <div class="cfp-drop" data-cfp="drop">
                        <span class="cfp-drop-title">ファイルをドロップ、またはクリックして選択（複数可）</span>
                        <input type="file" data-cfp="file" accept=".dxf,.pdf,.json,.png,.jpg,.jpeg,.webp" multiple>
                        <div class="cfp-drop-note">対応形式: DXF / PDF（ベクター）/ JSON / PNG・JPEG（画像）<br>平面図（床）と展開図（壁）を一緒に入れられます<br>DWGはDXFに書き出してください</div>
                    </div>
                    <div class="cfp-info-panel">
                        <div class="cfp-info-row"><span class="cfp-label">ファイル</span><span class="cfp-value" data-cfp="filename">未選択</span></div>
                        <div class="cfp-info-row"><span class="cfp-label">選択中の部屋</span><span class="cfp-value" data-cfp="room-name">-</span></div>
                        <div class="cfp-info-row"><span class="cfp-label">サイズ（幅×奥行）</span><span class="cfp-value" data-cfp="room-size">-</span></div>
                        <div class="cfp-info-row"><span class="cfp-label">図面の単位</span><span class="cfp-value" data-cfp="unit-info">-</span></div>
                        <div class="cfp-info-row"><span class="cfp-label">床面積</span><span class="cfp-value" data-cfp="floor-area">-</span></div>
                        <div class="cfp-info-row"><span class="cfp-label">壁面積</span><span class="cfp-value" data-cfp="wall-area">-</span></div>
                        <div class="cfp-info-row"><span class="cfp-label">部屋数</span><span class="cfp-value" data-cfp="room-count">0</span></div>
                    </div>
                </div>

                <div class="cfp-options">
                    <label>PDFの縮尺（1:N の N）
                        <input type="text" inputmode="numeric" autocomplete="off" data-cfp="scale" value="1" placeholder="例: 100" aria-label="PDFの縮尺 N">
                    </label>
                    <label>DXFの単位<select data-cfp="unit"><option value="auto">自動判定</option><option value="mm">mm</option><option value="cm">cm</option><option value="m">m</option></select></label>
                    <label class="cfp-check"><input type="checkbox" data-cfp="apply-size" checked> 図面のサイズを見積もり・お部屋パース・壁紙サイズに反映する</label>
                    <label>床デザイン画像
                        <input type="file" data-cfp="floor-tex" accept="image/*">
                    </label>
                    <label>壁紙デザイン画像
                        <input type="file" data-cfp="wall-tex" accept="image/*">
                    </label>
                </div>

                <div class="cfp-design-src" data-cfp="design-src" hidden>
                  <div class="cfp-design-item"><canvas data-cfp="src-floor" width="72" height="72"></canvas><div><b>床</b> ← ① カーペット用の減色イメージ<br><span data-cfp="src-floor-text"></span></div></div>
                  <div class="cfp-design-item"><canvas data-cfp="src-wall" width="72" height="72"></canvas><div><b>壁</b> ← <span data-cfp="src-wall-label">② 壁紙用のデザイン画像（元画像の色のまま）</span><br><span data-cfp="src-wall-text"></span></div></div>
                </div>
                <div class="cfp-raster" data-cfp="raster" hidden>
                  <div class="cfp-raster-title">画像（PNG / JPEG）の図面の読み取り設定</div>
                  <p class="cfp-hint">線で囲まれた部分を部屋として読み取ります。画像には縮尺が無いため、実際の大きさを入力してください。</p>
                  <div class="cfp-raster-row">
                    <label>線を検出する濃さ <input type="range" data-cfp="threshold" min="0" max="220" step="5" value="0"> <span data-cfp="threshold-val">自動</span></label>
                    <label>すき間を閉じる（ドアの開口など） <input type="range" data-cfp="gap" min="0" max="16" step="1" value="4"> <span data-cfp="gap-val">4</span> px</label>
                  </div>
                  <div class="cfp-raster-row">
                    <label>選んだ部屋の <select data-cfp="cal-axis"><option value="w">横幅</option><option value="h">奥行（縦）</option></select> の実際の長さ <input type="number" data-cfp="cal-mm" min="100" step="10" placeholder="例 3600"> mm</label>
                    <button type="button" class="cfp-btn" data-cfp="cal-btn">この大きさにする</button>
                  </div>
                  <p class="cfp-hint" data-cfp="cal-note"></p>
                </div>
                <div class="cfp-trace" data-cfp="trace" hidden>
                  <div class="cfp-trace-head">
                    <span class="cfp-trace-title">図面の上で部屋を指定</span>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="trace-close">閉じる</button>
                  </div>
                  <p class="cfp-hint">自動で部屋を読み取れない図面は、ここで部屋を自分で囲んで指定します。「四角」は対角の2点をドラッグ、「多角形」は角を順にクリックし、最初の赤い点をクリック（またはダブルクリック）で確定します。「部屋を移動」で枠だけを動かし、「形を変える」で角（白い丸）をドラッグして形を直せます（辺の中央の四角で角を追加、角のダブルクリックで削除）。ホイールで拡大・縮小、「移動」でドラッグして動かせます。</p>
                  <div class="cfp-trace-bar">
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="tool-pan">移動</button>
                    <button type="button" class="cfp-btn cfp-btn-sm is-on" data-cfp="tool-rect">四角</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="tool-poly">多角形</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="tool-move" title="手で指定した部屋（緑の枠）をドラッグして、図面はそのままに枠だけ動かします">部屋を移動</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="tool-edit" title="手で指定した部屋の角（白い丸）をドラッグして形を変えます。辺の中央の四角をドラッグすると角を追加、角をダブルクリックすると削除">形を変える</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="zoom-fit">全体</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="zoom-in" aria-label="拡大" title="図面を拡大">＋ 拡大</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="zoom-out" aria-label="縮小" title="図面を縮小">− 縮小</button>
                    <label class="cfp-check"><input type="checkbox" data-cfp="ortho" checked> 角を直角にそろえる</label>
                    <label class="cfp-check"><input type="checkbox" data-cfp="hide-auto"> 自動で出た部屋を隠す</label>
                  </div>
                  <details class="cfp-edit-help" open>
                    <summary>「形を変える」の使い方</summary>
                    <table class="cfp-edit-table">
                      <tr><th>白い丸をドラッグ</th><td>その角だけが動き、形が変わる</td></tr>
                      <tr><th>緑の四角をドラッグ</th><td>そこに角が増え、そのまま動かせる（L字にするときなど）</td></tr>
                      <tr><th>白い丸をダブルクリック</th><td>その角を消す（最低3つは残る）</td></tr>
                      <tr><th>ドラッグ中に Esc</th><td>元の形に戻る</td></tr>
                    </table>
                  </details>
                  <svg class="cfp-trace-view" data-cfp="trace-svg" role="img" aria-label="図面"></svg>
                </div>
                <div class="cfp-drawings" data-cfp="drawings" hidden></div>
                <p class="cfp-hint cfp-dw-note" data-cfp="dw-note"></p>
                <div class="cfp-elev" data-cfp="elev" hidden>
                  <div class="cfp-trace-head">
                    <span class="cfp-elev-title">展開図から壁を読み取りました：壁を選ぶと、壁紙のサイズに反映します</span>
                  </div>
                  <p class="cfp-hint" data-cfp="elev-note"></p>
                  <p class="cfp-elev-total" data-cfp="elev-total"></p>
                  <div class="cfp-trace-bar">
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="elev-pan">移動</button>
                    <button type="button" class="cfp-btn cfp-btn-sm is-on" data-cfp="elev-rect">四角</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="elev-poly">多角形</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="elev-move" title="手で指定した壁紙の範囲（緑の枠）をドラッグして、図面はそのままに枠だけ動かします">壁を移動</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="elev-edit" title="手で指定した範囲の角（白い丸）をドラッグして形を変えます。辺の中央の四角をドラッグすると角を追加、角をダブルクリックすると削除">形を変える</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="elev-fit">全体</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="elev-zin" aria-label="拡大" title="図面を拡大">＋ 拡大</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="elev-zout" aria-label="縮小" title="図面を縮小">− 縮小</button>
                    <label class="cfp-check"><input type="checkbox" data-cfp="elev-ortho" checked> 角を直角にそろえる</label>
                    <label class="cfp-check"><input type="checkbox" data-cfp="elev-hide-auto"> 自動で出た壁を隠す</label>
                    <label class="cfp-check"><input type="checkbox" data-cfp="elev-cloth-only"> クロス貼りの壁だけ表示</label>
                    <span class="cfp-elev-sep">この図面の壁を合計に：</span>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="total-all">全部</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="total-cloth">クロス貼りだけ</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="total-none">解除</button>
                  </div>
                  <details class="cfp-edit-help">
                    <summary>「範囲の形を変える」の使い方</summary>
                    <table class="cfp-edit-table">
                      <tr><th>白い丸をドラッグ</th><td>その角だけが動き、形が変わる</td></tr>
                      <tr><th>緑の四角をドラッグ</th><td>そこに角が増え、そのまま動かせる（L字にするときなど）</td></tr>
                      <tr><th>白い丸をダブルクリック</th><td>その角を消す（最低3つは残る）</td></tr>
                      <tr><th>ドラッグ中に Esc</th><td>元の形に戻る</td></tr>
                    </table>
                  </details>
                  <div class="cfp-rooms-body">
                    <svg class="cfp-elev-view" data-cfp="elev-svg" role="img" aria-label="展開図"></svg>
                    <div class="cfp-room-list cfp-elev-list" data-cfp="elev-list"></div>
                  </div>
                  <div class="cfp-wp" data-cfp="wp" hidden>
                    <div class="cfp-layout-title">壁紙の巾の枠（切り分けなし・実寸）</div>
                    <p class="cfp-hint">選んだ壁に、壁紙のロール（巾）の枠を赤で重ねて表示します。画像は巾ごとに切らず、1枚のまま表示します。高さは「壁の実寸＋上下100mmずつ」です。「壁をつなげる（部屋一周）」にチェックを入れて壁の番号を順に入れると（右回り／左回りを選べます）、その壁を横に並べ、柄が途切れずに一周つながるように貼ります（高さが違う壁は、下（床）合わせか上（天井）合わせを選べます）。「CAD図面」「画像」「枠」を、それぞれ表示／非表示にできます。マウスホイールか「＋ 拡大／− 縮小」で拡大縮小、「移動」「枠を移動」「画像を移動」を選ぶと、ドラッグか「← 左／右 →／↑ 上／↓ 下」ボタン（矢印キーも可。画像・枠は10mm、Shiftで100mm）で、表示・壁紙の画像・巾の枠をそれぞれ動かせます。「画像の大きさ」で壁紙の画像を拡大縮小、「画像の濃さ」で画面の画像の濃さを変えられます（保存する画像は濃さ100%）。</p>
                    <div class="cfp-trace-bar">
                      <button type="button" class="cfp-btn cfp-btn-sm is-on" data-cfp="wp-m-view" title="ドラッグで表示を動かす">移動</button>
                      <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="wp-m-frame" title="ドラッグで巾の枠を動かす（← → ↑ ↓ は10mm、Shiftで100mm）">枠を移動</button>
                      <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="wp-m-img" title="ドラッグで壁紙の画像を動かす（← → ↑ ↓ は10mm、Shiftで100mm）">画像を移動</button>
                      <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="wp-reset" title="画像の位置・大きさと枠の位置を元に戻す">位置を戻す</button>
                      <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="wp-fit" title="全体を表示">全体</button>
                      <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="wp-zin" title="拡大">＋ 拡大</button>
                      <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="wp-zout" title="縮小">− 縮小</button>
                      <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="wp-pl" title="左へ">← 左</button>
                      <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="wp-pr" title="右へ">右 →</button>
                      <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="wp-pu" title="上へ">↑ 上</button>
                      <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="wp-pd" title="下へ">↓ 下</button>
                      <button type="button" class="cfp-btn cfp-btn-sm cfp-btn-save" data-cfp="wp-save" title="CAD図面・壁紙の画像・巾の枠をレイヤーに分けたPSDで保存します">壁紙データを保存（PSD）</button>
                      <label class="cfp-check"><input type="checkbox" data-cfp="wp-show-cad" checked> CAD図面を表示</label>
                      <label class="cfp-check"><input type="checkbox" data-cfp="wp-show-frame" checked> 枠を表示</label>
                      <label class="cfp-check"><input type="checkbox" data-cfp="wp-show-img" checked> 画像を表示</label>
                      <label class="cfp-check"><input type="checkbox" data-cfp="wp-right"> 枠を右寄せスタート（外すと左寄せ）</label>
                      <label class="cfp-check"><input type="checkbox" data-cfp="wp-split" checked> 幅が30mを超えるときは30m以内のPSDに分けて保存（ZIP）（外すと1つのPSB）</label>
                    </div>
                    <div class="cfp-raster-row cfp-layout-sliders cfp-wp-sliders">
                      <label>画像の大きさ <input type="range" data-cfp="wp-dscale" min="20" max="300" step="1" value="100"> <span data-cfp="wp-dscale-val">100</span>%</label>
                      <label>画像の濃さ <input type="range" data-cfp="wp-dopacity" min="10" max="100" step="5" value="80"> <span data-cfp="wp-dopacity-val">80</span>%</label>
                    </div>
                    <div class="cfp-raster-row cfp-layout-sliders cfp-wp-ctrl">
                      <label>巾（横幅）<input type="range" data-cfp="wp-roll-r" min="850" max="930" step="1" value="910"> <input type="number" data-cfp="wp-roll" min="850" max="930" step="1" value="910"> mm</label>
                      <label>合わせ代（巾が重なる幅）<input type="range" data-cfp="wp-ov-r" min="6" max="15" step="0.5" value="10"> <input type="number" data-cfp="wp-ov" min="6" max="15" step="0.5" value="10"> mm</label>
                    </div>
                    <div class="cfp-wp-join">
                      <label class="cfp-check"><input type="checkbox" data-cfp="wp-join"> 壁をつなげる（部屋一周）</label>
                      <label>壁の番号（時計回りの順）<input type="text" data-cfp="wp-join-list" placeholder="例: 1,2,3,4" inputmode="numeric"></label>
                      <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="wp-join-add" title="展開図の一覧で選んでいる壁の番号を、最後に追加します">＋ 選んでいる壁を追加</button>
                      <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="wp-join-all" title="一覧のすべての壁を番号順に入れます">全部（番号順）</button>
                      <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="wp-join-clear">クリア</button>
                      <label class="cfp-check"><input type="checkbox" data-cfp="wp-ccw"> 左回り（反時計回り）でつなげる（外すと右回り）</label>
                      <label>高さが違うとき <select data-cfp="wp-align"><option value="bottom">下（床）合わせ</option><option value="top">上（天井）合わせ</option></select></label>
                    </div>
                    <p class="cfp-layout-note" data-cfp="wp-note"></p>
                    <p class="cfp-wp-warn" data-cfp="wp-warn" hidden></p>
                    <svg class="cfp-wp-view" data-cfp="wp-svg" role="img" aria-label="壁紙の巾の枠"></svg>
                  </div>
                </div>
                <div class="cfp-status" data-cfp="status"></div>
                <div class="cfp-rooms" data-cfp="rooms" hidden>
                  <div class="cfp-rooms-head"><div class="cfp-rooms-title">部屋を選択（平面図をクリック、または一覧から選ぶと、その部屋を3Dで表示します）</div><button type="button" class="cfp-btn cfp-btn-sm cfp-room-add" data-cfp="room-add" hidden title="自動で出た部屋とは別に、図面の上で四角・多角形で囲んで床（部屋）を追加します">＋ 床（部屋）を追加（図面の上で囲む）</button></div>
                  <div class="cfp-rooms-body">
                    <svg class="cfp-plan" data-cfp="plan" role="group" aria-label="間取りの平面図"></svg>
                    <div class="cfp-room-list" data-cfp="room-list"></div>
                  </div>
                </div>
                <div class="cfp-layout" data-cfp="layout" hidden>
                  <div class="cfp-layout-title">カーペット割付（50cm × 50cm・部屋の中心から／赤線）</div>
                  <p class="cfp-hint">選んだ部屋に、50cm角のタイルカーペットを部屋の中心（赤い「センター」印）にタイル1枚の中心を合わせて割り付けた線を、図面の上に赤線で表示します。端で切れるタイルも1枚として数え、見積もり（横幅・縦幅）に反映します（薄い赤＝端で切るタイル）。「割付を移動」でドラッグすると割付全体をずらせます（250mm＝タイル半分ずつ動きます。矢印キーで250mm、Shift+矢印で500mm）。変換画像（①カーペット用の減色イメージ）を割付の上に重ねて表示し、「画像を移動」のドラッグ（矢印キーで10mm）と「画像の大きさ」で位置・大きさを微調整できます。</p>
                  <div class="cfp-trace-bar">
                    <button type="button" class="cfp-btn cfp-btn-sm is-on" data-cfp="layout-pan">移動</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="layout-grid">割付を移動</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="layout-design" title="変換画像（①カーペット用の減色イメージ）をドラッグして動かします">画像を移動</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="layout-center">中心に戻す</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="layout-fit">全体</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="layout-zin" title="図面を拡大">＋ 拡大</button>
                    <button type="button" class="cfp-btn cfp-btn-sm" data-cfp="layout-zout" title="図面を縮小">− 縮小</button>
                    <button type="button" class="cfp-btn cfp-btn-sm cfp-btn-save" data-cfp="layout-psd" title="元図面・部屋・画像・割付をレイヤーに分けたPSDで保存します">割付をPSDで保存</button>
                    <label class="cfp-check"><input type="checkbox" data-cfp="layout-show-img" checked> 図面を表示</label>
                    <label class="cfp-check"><input type="checkbox" data-cfp="layout-show-grid" checked> 割付を表示</label>
                    <label class="cfp-check"><input type="checkbox" data-cfp="layout-show-design" checked> 変換画像を表示</label>
                    <label class="cfp-check"><input type="checkbox" data-cfp="layout-clip-box" checked> 画像をカーペット割付の範囲まで表示（外すと部屋の形まで）</label>
                  </div>
                  <div class="cfp-raster-row cfp-layout-sliders">
                    <label>画像の大きさ <input type="range" data-cfp="layout-dscale" min="20" max="300" step="1" value="100"> <span data-cfp="layout-dscale-val">100</span>%</label>
                    <label>画像の濃さ <input type="range" data-cfp="layout-dopacity" min="10" max="100" step="5" value="80"> <span data-cfp="layout-dopacity-val">80</span>%</label>
                  </div>
                  <p class="cfp-layout-note" data-cfp="layout-note"></p>
                  <svg class="cfp-elev-view cfp-layout-view" data-cfp="layout-svg" role="img" aria-label="カーペット割付"></svg>
                </div>
                <div class="cfp-canvas" data-cfp="canvas"></div>
                <p class="cfp-hint">ドラッグで回転、ホイールで拡大縮小</p>
                <p class="cfp-hint" data-cfp="design-note"></p>
                <p class="cfp-hint" data-cfp="size-note"></p>

                <div class="cfp-controls">
                    <button type="button" class="cfp-btn" data-cfp="trace-open" hidden>図面の上で部屋を指定</button>
                    <button type="button" class="cfp-btn" data-cfp="sample-btn">サンプル</button>
                    <button type="button" class="cfp-btn" data-cfp="reset-btn">視点をリセット</button>
                    <button type="button" class="cfp-btn" data-cfp="save-btn">解析結果をJSON保存</button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ------------------------------------------------------------- REST API */

    public function register_rest() {
        register_rest_route('cad-floor-plan/v1', '/info', [
            'methods'             => 'GET',
            'callback'            => function () {
                return rest_ensure_response([
                    'name'              => 'CAD Floor Plan 3D Simulator',
                    'version'           => CFP_VERSION,
                    'supported_formats' => ['dxf', 'pdf', 'json'],
                ]);
            },
            'permission_callback' => '__return_true',
        ]);
    }

    /* ---------------------------------------------------------------- admin */

    public function add_admin_menu() {
        add_menu_page('壁紙・カーペットシミュレーション', '壁紙・カーペット', 'manage_options', 'cad-floor-plan', [$this, 'render_admin_page'], 'dashicons-layout', 30);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $page_id = (int) get_option('cad_floor_plan_page_id');
        $page    = $page_id ? get_post($page_id) : null;
        $exists  = $page && $page->post_status !== 'trash';
        $result  = isset($_GET['cfp_page']) ? sanitize_key($_GET['cfp_page']) : '';
        ?>
        <div class="wrap">
            <h1>壁紙・カーペットシミュレーション（CAD対応） <small>v<?php echo esc_html(CFP_VERSION); ?></small></h1>

            <?php if ($result === 'ok') : ?>
                <div class="notice notice-success"><p>固定ページを用意しました。</p></div>
            <?php elseif ($result === 'error') : ?>
                <div class="notice notice-error"><p>固定ページを作成できませんでした。</p></div>
            <?php endif; ?>

            <h2>固定ページ</h2>
            <?php if ($exists && urldecode($page->post_name) !== urldecode(sanitize_title(CFP_PAGE_SLUG))) : ?>
                <div class="notice notice-warning inline"><p>同じURLの既存ページがあったため、このページのURLは「<?php echo esc_html(urldecode($page->post_name)); ?>」になっています。既存ページを削除またはURL変更したあと、このページの「URL（スラッグ）」を「<?php echo esc_html(CFP_PAGE_SLUG); ?>」に変更してください。</p></div>
            <?php endif; ?>
            <?php if ($exists) : ?>
                <p>
                    <a href="<?php echo esc_url(get_permalink($page_id)); ?>" target="_blank" rel="noopener">表示</a> |
                    <a href="<?php echo esc_url(get_edit_post_link($page_id)); ?>">編集</a>
                    （<?php echo esc_html(get_the_title($page_id)); ?>）
                </p>
            <?php else : ?>
                <p>固定ページがありません。下のボタンで作成できます。プラグインを無効化すると、このページは削除されます。</p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="cfp_create_page">
                <?php wp_nonce_field('cfp_create_page'); ?>
                <?php submit_button($exists ? '固定ページは作成済みです' : '固定ページを作成', 'primary', 'submit', false, $exists ? ['disabled' => 'disabled'] : null); ?>
            </form>

            <h2>注文メール（Design Order Mailer）</h2>
            <p>
                <?php if (defined('DOMAILER_VERSION')) : ?>
                    元の「Design Order Mailer」プラグインが有効なため、そちらを使用しています。このプラグインに同梱の機能は停止中です（元のプラグインを無効化すると切り替わります）。
                <?php else : ?>
                    このプラグインに同梱の機能を使用しています。受信先メールアドレスは「設定」→「Design Order Mailer」で変更できます。
                <?php endif; ?>
            </p>

            <h2>ショートコード</h2>
            <p><code>[wallpaper_carpet_simulator]</code>: 壁紙・カーペットのシミュレーション全体（CAD表示を含む）。固定ページに使われています。<br>
            <code>[cad_floor_plan]</code>: CADの3D表示だけを任意のページ・投稿に置きます。</p>
            <table class="widefat" style="max-width:640px">
                <thead><tr><th>属性</th><th>既定値</th><th>説明</th></tr></thead>
                <tbody>
                    <tr><td><code>height</code></td><td>600</td><td>3D表示エリアの高さ（px）</td></tr>
                    <tr><td><code>width</code></td><td>（なし）</td><td>最大幅（px）。省略すると幅いっぱい</td></tr>
                    <tr><td><code>sample</code></td><td>default</td><td><code>none</code> でサンプル非表示</td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}

add_action('plugins_loaded', ['CAD_Floor_Plan_Plugin', 'instance']);

register_activation_hook(__FILE__, function () {
    update_option('cad_floor_plan_version', CFP_VERSION);
    if (!defined('DOMAILER_VERSION')) {
        require_once CFP_PLUGIN_DIR . 'includes/order-mailer/class-domailer.php';
        CFP_Domailer::activate();
    }
    CAD_Floor_Plan_Plugin::ensure_page();
});

register_deactivation_hook(__FILE__, function () {
    CAD_Floor_Plan_Plugin::remove_page();
    require_once CFP_PLUGIN_DIR . 'includes/class-cfp-resume.php';
    CFP_Resume::unschedule();
    if (!defined('DOMAILER_VERSION')) {
        require_once CFP_PLUGIN_DIR . 'includes/order-mailer/class-domailer.php';
        CFP_Domailer::deactivate();
    }
});
