<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * カーペットの注文（公開の「新ページ」→ 管理者だけの「特許ページ」→ お客様の選択 → PAY.JP 決済）
 *
 * - 新ページ [cfp_carpet_order]：カーペットだけ。画像・仕様・サイズ・お客様情報を入れて「発注」。色変換はお客様に見せない。
 *   発注すると、お客様へ受付メール、管理者へ通知メール（特許ページで開くリンク付き）、工場へ納期確認メール（元画像を添付）。
 * - 特許ページ（[wallpaper_carpet_simulator]・管理者だけ）：?cfp_order=番号 で開くと注文の画像と仕様を読み込んで3通りに変換し、
 *   「お客様へプレビューを送る」でプレビュー画像を添付したメールを送る（assets/js/carpet-order-admin.js）。
 * - お客様はメールのリンク（新ページ ?cfp_co=番号&t=鍵）で案を選ぶ（どれも不可ならコメントを添えて送る → 管理者へ通知）。
 *   案を選ぶと金額が決まり、そのページで PAY.JP（カード・3Dセキュア）で決済する。決済が済むと正式発注として
 *   お客様・管理者・工場へメールを送る。
 * - 金額はサーバーで計算する（画面の値は使わない）。PAY.JP の秘密鍵はサーバーにだけ保存し、ページには出さない。
 */
class CFP_Carpet_Order {

    const POST_TYPE = 'cfp_carpet_order';
    const SHORTCODE = 'cfp_carpet_order';
    const DIR = 'cfp-orders-private';
    const OPT = 'cfp_co_settings';
    const META = '_cfp_co';
    const MAX_UPLOAD = 30 * 1024 * 1024;
    const RATE_PER_HOUR = 10;
    const TAX_RATE = 0.10;
    const PRICE_PER_COLOR = 35000;
    const FREE_OP = ['OP-03', 'OP-05']; // オプション色のうち無償の色
    // HPの初期（旧パレット）の色。特許ページの FREE_PALETTE・OP_PALETTE_RAW と同じ
    /** 納期の目安（プレビューを送るときに変えられる）. */
    const LEAD_DEFAULT = '約2週間';

    const PALETTE_FREE = [
        ['基本-01', '#EFEFEF'],
        ['基本-02', '#E8AB82'],
        ['基本-03', '#DB9514'],
        ['基本-04', '#898989'],
        ['基本-05', '#95B7E1'],
        ['基本-06', '#C8161E'],
        ['基本-07', '#DC5310'],
        ['基本-08', '#FABE00'],
        ['基本-09', '#218F49'],
        ['基本-10', '#005EAD'],
        ['基本-11', '#5B1131'],
        ['基本-12', '#5D2B17'],
        ['基本-13', '#322B29'],
        ['基本-14', '#263F22'],
        ['基本-15', '#082A49'],
        ['基本-16', '#000000']
    ];
    const PALETTE_OPT = [
        ['OP-01', '#FCC3DE'],
        ['OP-02', '#F5A1C7'],
        ['OP-03', '#DD4A96'],
        ['OP-04', '#CF2884'],
        ['OP-05', '#B60069'],
        ['OP-06', '#F3A731'],
        ['OP-07', '#F0810E'],
        ['OP-08', '#762F15'],
        ['OP-09', '#AB1D22'],
        ['OP-10', '#620002'],
        ['OP-11', '#FFFAC2'],
        ['OP-12', '#FFE98C'],
        ['OP-13', '#AAC351'],
        ['OP-14', '#769D41'],
        ['OP-15', '#578B33'],
        ['OP-16', '#BEE9EF'],
        ['OP-17', '#00B0CE'],
        ['OP-18', '#00718C'],
        ['OP-19', '#0B318F'],
        ['OP-20', '#24374E'],
        ['OP-21', '#8B5CA3'],
        ['OP-22', '#63327D'],
        ['OP-23', '#452566'],
        ['OP-24', '#324125'],
        ['OP-25', '#00362D'],
        ['OP-26', '#C08741'],
        ['OP-27', '#995325'],
        ['OP-28', '#552E21'],
        ['OP-29', '#2B0A05'],
        ['OP-30', '#F7F7F7']
    ];

    const REGIONS = [
        'honshu'                  => ['本州', 2000],
        'hokkaido_shikoku_kyushu' => ['北海道・四国・九州', 2500],
        'okinawa'                 => ['沖縄', 5500],
    ];
    const MATERIALS = [
        'cut'  => 'カットパイル',
        'loop' => 'ループパイル',
    ];
    const VARIANTS = [
        'std' => 'A案：標準色のみ',
        'op3' => 'B案：標準色＋オプション3色',
        'max' => 'C案：最大表現',
    ];
    const STATUSES = [
        'received'      => '受付（プレビュー未送信）',
        'previews_sent' => 'プレビュー送信済み（お客様の選択待ち）',
        'rejected'      => 'どれも不可（作り直し）',
        'selected'      => '案を選択済み（決済待ち）',
        'paid'          => '決済完了（正式発注）',
        'cancelled'     => 'キャンセル',
    ];

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_post_type']);
        add_action('template_redirect', [$this, 'no_cache']);
        add_shortcode(self::SHORTCODE, [$this, 'render_page']);
        foreach (['submit', 'select', 'pay', 'pay_finish', 'file'] as $a) {
            add_action('wp_ajax_cfp_co_' . $a, [$this, 'ajax_' . $a]);
            add_action('wp_ajax_nopriv_cfp_co_' . $a, [$this, 'ajax_' . $a]);
        }
        add_action('wp_ajax_cfp_co_admin_get', [$this, 'ajax_admin_get']);
        add_action('wp_ajax_cfp_co_send_previews', [$this, 'ajax_send_previews']);
        add_action('admin_menu', [$this, 'admin_menu'], 20);
        add_action('admin_post_cfp_co_settings', [$this, 'handle_settings']);
        add_action('admin_post_cfp_co_status', [$this, 'handle_status']);
        add_action('admin_post_cfp_co_create_page', [$this, 'handle_create_page']);
    }

    public function register_post_type() {
        register_post_type(self::POST_TYPE, [
            'label'               => 'カーペット注文',
            'public'              => false,
            'show_ui'             => false,
            'show_in_rest'        => false,
            'exclude_from_search' => true,
            'supports'            => ['title'],
        ]);
    }

    /* ---------------------------------------------------------- settings */

    public static function settings() {
        $s = get_option(self::OPT, []);
        return wp_parse_args(is_array($s) ? $s : [], [
            'admin_email'    => '',
            'factory_email'  => '',
            'factory_name'   => '工場ご担当者',
            'payjp_mode'     => 'test',
            'payjp_pk_test'  => '',
            'payjp_sk_test'  => '',
            'payjp_pk_live'  => '',
            'payjp_sk_live'  => '',
            'factory_on_paid' => 1,
            'simulator_public' => 1, // 壁紙・カーペットシミュレーション（特許ページ）をどなたにも表示する
            // 販売者情報（注文ページの「会社情報・特定商取引法に基づく表記」）
            'company_name'    => 'マッシュオフィス',
            'company_rep'     => '佐藤康之',
            'company_invoice' => 'T4810075608301',
            'company_address' => '栃木県宇都宮市下川俣町24-1',
            'company_tel'     => '050-5586-1657',
            'company_email'   => 'yasu@mush-office.com',
            'company_url'     => 'https://mush-office.com/carpet-order/#co-form',
        ]);
    }

    private static function admin_email() {
        $e = self::settings()['admin_email'];
        return is_email($e) ? $e : get_option('admin_email');
    }

    private static function payjp_keys() {
        $s = self::settings();
        $live = $s['payjp_mode'] === 'live';
        return [$live ? $s['payjp_pk_live'] : $s['payjp_pk_test'], $live ? $s['payjp_sk_live'] : $s['payjp_sk_test'], $live];
    }

    /** The URL of the page with [wallpaper_carpet_simulator] (the 特許ページ). */
    public static function patent_page_url() {
        $id = (int) get_option('cad_floor_plan_page_id');
        if ($id && get_post_status($id) && get_post_status($id) !== 'trash') {
            return get_permalink($id);
        }
        return self::find_page_with('[' . CFP_SIM_SHORTCODE);
    }

    /** Whether the simulator page (特許ページ) is shown to everyone (setting). Admins always see it. */
    public static function simulator_public() {
        return !empty(self::settings()['simulator_public']);
    }

    /** The URL of the page with [cfp_carpet_order] (the public 新ページ). */
    public static function order_page_url() {
        $id = self::page_id();
        return $id ? get_permalink($id) : self::find_page_with('[' . self::SHORTCODE);
    }

    /** The order page this plugin manages (0 if none). */
    private static function page_id() {
        $id = (int) get_option('cfp_co_page_id');
        $page = $id ? get_post($id) : null;
        return ($page && $page->post_type === 'page' && $page->post_status !== 'trash') ? $id : 0;
    }

    /**
     * Returns the ID of the public order page (固定ページ「カーペットのご注文」), creating it if needed.
     * A page that already has [cfp_carpet_order] is used as it is (and is not deleted on deactivation).
     */
    public static function ensure_page() {
        $id = self::page_id();
        if ($id) {
            return $id;
        }
        global $wpdb;
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status IN ('publish','private','draft','pending','future') AND post_content LIKE %s ORDER BY ID ASC LIMIT 1",
            '%' . $wpdb->esc_like('[' . self::SHORTCODE) . '%'
        ));
        if ($existing) {
            update_option('cfp_co_page_id', $existing);
            update_option('cfp_co_page_created', 0);
            return $existing;
        }
        $new_id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'カーペットのご注文',
            'post_name'    => 'carpet-order',
            'post_content' => '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->',
            'meta_input'   => CAD_Floor_Plan_Plugin::page_meta(),
        ], true);
        if (is_wp_error($new_id)) {
            return 0;
        }
        update_option('cfp_co_page_id', $new_id);
        update_option('cfp_co_page_created', 1);
        self::apply_share($new_id, CFP_PLUGIN_DIR . 'assets/img/og-carpet-order.jpg', self::SHARE_ORDER);
        return (int) $new_id;
    }

    /**
     * LINE・SNSでリンクを送ったときの表示（OGP）：アイキャッチ画像と説明文を設定する（未設定のときだけ）。
     * Cocoon はアイキャッチを og:image に、メタディスクリプション（なければ抜粋）を og:description に使う。
     * アイキャッチはページには出さない（テンプレートの CSS で隠す）。
     */
    public static function apply_share($page_id, $file, $desc) {
        $page_id = (int) $page_id;
        if (!$page_id || get_post_type($page_id) !== 'page') {
            return;
        }
        if (!has_post_thumbnail($page_id) && is_readable($file)) {
            $up = wp_upload_bits(basename($file), null, file_get_contents($file));
            if (empty($up['error'])) {
                $att = wp_insert_attachment([
                    'post_mime_type' => 'image/jpeg',
                    'post_title'     => get_the_title($page_id),
                    'post_status'    => 'inherit',
                ], $up['file'], $page_id);
                if ($att && !is_wp_error($att)) {
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                    wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $up['file']));
                    set_post_thumbnail($page_id, $att);
                }
            }
        }
        if (get_post_field('post_excerpt', $page_id) === '') {
            wp_update_post(['ID' => $page_id, 'post_excerpt' => $desc]);
        }
        if ((string) get_post_meta($page_id, 'the_page_meta_description', true) === '') {
            update_post_meta($page_id, 'the_page_meta_description', $desc);
        }
    }

    const SHARE_ORDER = 'デザイン画像とサイズを送るだけ。カーペットの色に置き換えたプレビューをメールでお届けし、案を選んでカードでお支払い。50cm角のオリジナル・プリントタイルカーペットのご注文ページです。';
    const SHARE_SIM = '写真・イラスト・ロゴを、カーペット・壁紙の色に置き換えてシミュレーション。お見積もり、お部屋のパース、CAD図面の読み込みまで、ブラウザだけで試せます。';

    /** Both pages' share settings (on update and when a page is created). */
    public static function apply_share_all() {
        $order = self::page_id();
        if ($order) {
            self::apply_share($order, CFP_PLUGIN_DIR . 'assets/img/og-carpet-order.jpg', self::SHARE_ORDER);
        }
        $sim = (int) get_option('cad_floor_plan_page_id');
        if ($sim) {
            self::apply_share($sim, CFP_PLUGIN_DIR . 'assets/img/og-simulator.jpg', self::SHARE_SIM);
        }
    }

    /** Deletes the order page if this plugin created it (orders and settings are kept). */
    public static function remove_page() {
        $id = (int) get_option('cfp_co_page_id');
        if ($id && get_option('cfp_co_page_created') && get_post_type($id) === 'page') {
            wp_delete_post($id, true);
        }
        delete_option('cfp_co_page_id');
        delete_option('cfp_co_page_created');
    }

    public function handle_create_page() {
        if (!current_user_can('manage_options')) {
            wp_die('権限がありません。');
        }
        check_admin_referer('cfp_co_create_page');
        $id = self::ensure_page();
        wp_safe_redirect(admin_url('admin.php?page=cfp-carpet-orders&cfp_co=' . ($id ? 'page' : 'page_error')));
        exit;
    }

    /**
     * The 特許ページ differs for admins and visitors, and the 新ページ holds per-order links: keep page caches
     * (and caching plugins) from storing either page.
     */
    public function no_cache() {
        if (!is_singular()) {
            return;
        }
        $post = get_post();
        if (!$post || (!has_shortcode($post->post_content, self::SHORTCODE) && !has_shortcode($post->post_content, CFP_SIM_SHORTCODE))) {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
    }

    private static function find_page_with($needle) {
        global $wpdb;
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('page','post') AND post_status IN ('publish','private','draft') AND post_content LIKE %s ORDER BY ID DESC LIMIT 1",
            '%' . $wpdb->esc_like($needle) . '%'
        ));
        return $id ? get_permalink($id) : '';
    }

    /* ----------------------------------------------------------- pricing */

    public static function is_paid_color($name) {
        return strpos($name, 'OP-') === 0 && !in_array($name, self::FREE_OP, true);
    }

    /** The estimate, computed on the server: the same rules as the simulator (calculateEstimate). */
    public static function price($material, $w, $h, $region, $colors = null) {
        $area = ($w / 1000) * ($h / 1000);
        if ($material === 'cut') {
            $unit = $area < 1.0 ? 18400 : ($area < 10.0 ? 16300 : 14000);
        } else {
            $unit = $area < 1.0 ? 17500 : ($area < 10.0 ? 15500 : 13300);
        }
        $paid = [];
        if (is_array($colors)) {
            foreach ($colors as $c) {
                if (self::is_paid_color($c)) {
                    $paid[] = $c;
                }
            }
        }
        $free_limit = $area >= 10.0 ? 3 : 0;
        $charge = max(0, count($paid) - $free_limit);
        $op = $charge * self::PRICE_PER_COLOR;
        $tiles_x = max(1, (int) ceil($w / 500));
        $tiles_y = max(1, (int) ceil($h / 500));
        $tiles = $tiles_x * $tiles_y;
        $per_box = $material === 'loop' ? 20 : 16;
        $boxes = max(1, (int) ceil($tiles / $per_box));
        $ship_box = isset(self::REGIONS[$region]) ? self::REGIONS[$region][1] : 2000;
        $base = (int) round($area * $unit);
        $subtotal = $base + $op;
        $shipping = $ship_box * $boxes;
        $tax = (int) round(($subtotal + $shipping) * self::TAX_RATE);
        return [
            'area' => round($area, 2), 'unit' => $unit, 'base' => $base, 'paid_colors' => $paid, 'paid_count' => count($paid),
            'free_applied' => min($free_limit, count($paid)), 'op' => $op, 'subtotal' => $subtotal, 'tiles' => $tiles,
            'tiles_x' => $tiles_x, 'tiles_y' => $tiles_y, 'boxes' => $boxes, 'shipping' => $shipping, 'tax' => $tax,
            'total' => $subtotal + $shipping + $tax,
        ];
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

    private static function order_dir($id) {
        $dir = self::base_dir() . '/order-' . (int) $id;
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        return $dir;
    }

    private static function get($id) {
        $id = (int) $id;
        if (!$id || get_post_type($id) !== self::POST_TYPE) {
            return null;
        }
        $o = get_post_meta($id, self::META, true);
        if (!is_array($o)) {
            return null;
        }
        $o['id'] = $id;
        return $o;
    }

    private static function save($o) {
        $id = (int) $o['id'];
        $data = $o;
        unset($data['id']);
        update_post_meta($id, self::META, $data);
    }

    private static function no($o) {
        return 'C' . str_pad((string) (int) $o['id'], 5, '0', STR_PAD_LEFT);
    }

    private static function log(&$o, $text) {
        $o['log'][] = [current_time('mysql'), $text];
    }

    /** The order with the customer's key (constant-time compare), or null. */
    private static function by_token($id, $t) {
        $o = self::get($id);
        if (!$o || !is_string($t) || $t === '' || !hash_equals((string) $o['token'], $t)) {
            return null;
        }
        return $o;
    }

    private static function select_url($o) {
        $page = $o['page_url'] ?: home_url('/');
        return add_query_arg(['cfp_co' => (int) $o['id'], 't' => $o['token']], $page);
    }

    private static function file_url($o, $f, $with_token = true) {
        $args = ['action' => 'cfp_co_file', 'id' => (int) $o['id'], 'f' => $f];
        if ($with_token) {
            $args['t'] = $o['token'];
        }
        return add_query_arg($args, admin_url('admin-ajax.php'));
    }

    /* -------------------------------------------------------------- page */

    /** The public 新ページ: the order form, or (with ?cfp_co=&t=) the page to choose a conversion and pay. */
    public function render_page() {
        $file = CFP_PLUGIN_DIR . 'templates/carpet-order.html';
        if (!is_readable($file)) {
            return '<!-- carpet-order.html not found -->';
        }
        $id = isset($_GET['cfp_co']) ? absint($_GET['cfp_co']) : 0;
        $t = isset($_GET['t']) ? preg_replace('/[^A-Za-z0-9]/', '', wp_unslash($_GET['t'])) : '';
        $o = $id ? self::by_token($id, $t) : null;
        $cfg = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('cfp_co'),
            'page_url' => get_permalink() ?: home_url(add_query_arg([])),
            'tax_rate' => self::TAX_RATE,
            'price_per_color' => self::PRICE_PER_COLOR,
            'regions'  => self::REGIONS,
            'mode'     => $id ? ($o ? 'select' : 'bad_link') : 'order',
        ];
        if ($o) {
            list($pk, , $live) = self::payjp_keys();
            $cfg['order'] = self::public_view($o);
            $cfg['payjp_pk'] = $pk;
            $cfg['payjp_live'] = $live;
            $cfg['token'] = $o['token'];
            if ($pk !== '') {
                wp_enqueue_script('payjp', 'https://js.pay.jp/v2/pay.js', [], null, true);
            }
        }
        wp_enqueue_script('cfp-carpet-order', CFP_PLUGIN_URL . 'assets/js/carpet-order.js', [], CFP_VERSION, true);
        wp_localize_script('cfp-carpet-order', 'cfpCarpetOrderConfig', $cfg);
        $html = file_get_contents($file);
        // 品質基準の全文は、シミュレーターと同じ文面を使う
        $html = str_replace('{{CFP_QUALITY_DOC}}', self::quality_doc(), $html);
        // 説明の画像（参考例・パイルの写真・制電／防炎マーク）と、無料サンプル請求のメール
        $html = str_replace('{{CFP_ASSET_URL}}', esc_url(CFP_PLUGIN_URL . 'assets/'), $html);
        $html = str_replace('{{CFP_PALETTE}}', self::palette_html(), $html);
        $html = str_replace('{{CFP_COMPANY}}', self::company_html(), $html);
        $html = CAD_Floor_Plan_Plugin::instance()->fill_badges($html);
        $html = CAD_Floor_Plan_Plugin::instance()->fill_photos($html);
        foreach (['CUT' => 'カットパイル', 'LOOP' => 'ループパイル'] as $k => $label) {
            $mail = 'mailto:' . self::admin_email() . '?subject=' . rawurlencode('【無料サンプル請求】' . $label)
                . '&body=' . rawurlencode($label . " の無料サンプルを希望します。\n\nお名前：\nお届け先ご住所：\n電話番号：\n\n※サンプルの発送には数日いただく場合があります。");
            $html = str_replace('{{CFP_SAMPLE_' . $k . '}}', esc_attr($mail), $html);
        }
        return $html;
    }

    /** The colour chips: the 16 standard colours and the option colours (OP-03・OP-05 are free). */
    private static function palette_html() {
        // 無償の印は、オプションカラーのうち無償の色（OP-03・OP-05）にだけ付ける（標準色は見出しに無償と書く）
        $chip = function ($c, $free_mark) {
            return '<li><span class="co-sw" style="background:' . esc_attr($c[1]) . '"></span><b>' . esc_html($c[0]) . '</b>'
                . ($free_mark ? '<i>無償</i>' : '') . '</li>';
        };
        $free = '';
        foreach (self::PALETTE_FREE as $c) {
            $free .= $chip($c, false);
        }
        $opt = '';
        foreach (self::PALETTE_OPT as $c) {
            $opt .= $chip($c, !self::is_paid_color($c[0]));
        }
        return '<h3>標準色（16色・無償）</h3><ul class="co-pal co-pal-free">' . $free . '</ul>'
            . '<h3>オプションカラー（30色）</h3><ul class="co-pal">' . $opt . '</ul>';
    }

    /** 会社情報・特定商取引法に基づく表記（settings → 販売者情報）. */
    private static function company_html() {
        $s = self::settings();
        $row = function ($k, $v, $raw = false) {
            return $v === '' ? '' : '<dt>' . esc_html($k) . '</dt><dd>' . ($raw ? $v : esc_html($v)) . '</dd>';
        };
        // 自分のサイトのページ（注文フォームなど）は同じタブで、ほかのサイトは別のタブで開く
        $own = wp_parse_url($s['company_url'], PHP_URL_HOST) === wp_parse_url(home_url(), PHP_URL_HOST);
        $url = $s['company_url'] !== '' ? '<a href="' . esc_url($s['company_url']) . '"' . ($own ? '' : ' target="_blank" rel="noopener"') . '>' . esc_html($s['company_url']) . '</a>' : '';
        $mail = is_email($s['company_email']) ? '<a href="' . esc_attr('mailto:' . $s['company_email']) . '">' . esc_html($s['company_email']) . '</a>' : '';
        return '<dl>'
            . $row('販売事業者', $s['company_name'])
            . $row('代表', $s['company_rep'])
            . $row('適格請求書発行事業者登録番号', $s['company_invoice'])
            . $row('所在地', $s['company_address'])
            . $row('電話', $s['company_tel'])
            . $row('メール', $mail, true)
            . $row('ホームページ', $url, true)
            . $row('製造', $s['company_name'])
            . $row('販売価格', 'ご注文ページの価格表と、プレビューでお選びいただく案の金額（税込・送料込み）')
            . $row('商品代金以外の費用', '送料（1箱あたり：本州 2,000円／北海道・四国・九州 2,500円／沖縄 5,500円・税別）、有償オプションカラー（1色 35,000円・税別）')
            . $row('お支払い方法', 'クレジットカード（PAY.JP）')
            . $row('お支払い時期', 'プレビューの案をお選びいただいた後、ご注文時にお支払いいただきます')
            . $row('お届け時期', 'お支払い確定後、約2週間で発送します（季節により変動します）。正確な納期は、データサンプル（プレビュー）をお送りするときにお伝えします')
            . $row('キャンセル', 'ご注文（発注）後3日以内は無償でキャンセルを承ります。それ以降はお受けできません')
            . $row('返品・不良品', 'オーダーメイド品のため、お客様のご都合による返品はお受けできません。製品の不良は「品質基準について」に沿って対応します')
            . '</dl>';
    }

    /** The 品質基準 text from the simulator template (one source for both pages). */
    private static function quality_doc() {
        $src = @file_get_contents(CFP_PLUGIN_DIR . 'templates/wallpaper-carpet-tool.php');
        if (!$src) {
            return '';
        }
        $a = strpos($src, '<div class="cfp-qs" id="cc-quality-doc"');
        $b = $a === false ? false : strpos($src, '<div class="qs-end">', $a);
        if ($a === false || $b === false) {
            return '';
        }
        $c = strpos($src, '</div>', $b);
        $inner = substr($src, $a, $c + 6 - $a);
        $inner = str_replace('id="cc-quality-doc"', 'id="co-quality-doc"', $inner);
        return $inner . "\n      </div>";
    }

    /** What the customer may see of an order. */
    private static function public_view($o) {
        $vars = [];
        foreach (self::VARIANTS as $k => $label) {
            if (empty($o['previews'][$k])) {
                continue;
            }
            $p = self::price($o['material'], $o['width'], $o['height'], $o['region'], $o['previews'][$k]['colors']);
            $vars[] = [
                'key' => $k, 'label' => $label, 'img' => self::file_url($o, 'preview_' . $k),
                'colors' => count($o['previews'][$k]['colors']), 'paid' => $p['paid_colors'], 'op' => $p['op'],
                'free_applied' => $p['free_applied'], 'total' => $p['total'], 'subtotal' => $p['subtotal'],
                'shipping' => $p['shipping'], 'tax' => $p['tax'],
            ];
        }
        return [
            'no' => self::no($o), 'status' => $o['status'], 'status_label' => self::STATUSES[$o['status']] ?? $o['status'],
            'name' => $o['name'], 'material' => self::MATERIALS[$o['material']] ?? $o['material'],
            'width' => $o['width'], 'height' => $o['height'], 'region' => self::REGIONS[$o['region']][0] ?? '',
            'variants' => $vars, 'message' => $o['preview_message'] ?? '', 'choice' => $o['choice'] ?? '',
            'amount' => $o['amount'] ?? 0, 'lead_time' => $o['lead_time'] ?? '',
        ];
    }

    /* -------------------------------------------------------------- AJAX */

    private static function fail($msg, $code = 400) {
        wp_send_json_error(['message' => $msg], $code);
    }

    private static function text($k, $multi = false) {
        if (!isset($_POST[$k])) {
            return '';
        }
        $v = wp_unslash($_POST[$k]);
        return $multi ? sanitize_textarea_field($v) : sanitize_text_field($v);
    }

    private static function rate_ok($bucket, $limit) {
        if (current_user_can('manage_options')) {
            return true;
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $key = 'cfp_co_' . $bucket . '_' . md5($ip);
        $n = (int) get_transient($key);
        if ($n >= $limit) {
            return false;
        }
        set_transient($key, $n + 1, HOUR_IN_SECONDS);
        return true;
    }

    /** 発注（新ページ）：注文を作り、お客様・管理者・工場へメール。 */
    public function ajax_submit() {
        check_ajax_referer('cfp_co', 'nonce');
        if (!self::rate_ok('submit', self::RATE_PER_HOUR)) {
            self::fail('短い時間に送信が続いたため、受け付けを止めています。しばらくしてからお試しください。', 429);
        }
        $material = self::text('material');
        $region = self::text('region');
        $w = (int) self::text('width');
        $h = (int) self::text('height');
        $name = self::text('name');
        $company = self::text('company');
        $tel = self::text('tel');
        $email = sanitize_email(self::text('email'));
        $address = self::text('address');
        $memo = self::text('memo', true);
        $receipt = !empty($_POST['receipt']);
        if (!isset(self::MATERIALS[$material]) || !isset(self::REGIONS[$region])) {
            self::fail('仕様またはお届け先地域を選んでください。');
        }
        if ($w < 500 || $h < 500 || $w > 100000 || $h > 100000 || $w % 500 || $h % 500) {
            self::fail('サイズは500mm単位で入力してください。');
        }
        if ($name === '' || $tel === '' || !is_email($email) || $address === '') {
            self::fail('お名前・電話番号・メールアドレス・ご住所を入力してください。');
        }
        if (empty($_POST['copyright_ok']) || empty($_POST['quality_ok'])) {
            self::fail('著作権の確認と品質基準への同意が必要です。');
        }
        if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            self::fail('デザイン画像を選んでください。');
        }
        $f = $_FILES['image'];
        if ($f['size'] > self::MAX_UPLOAD) {
            self::fail('画像が大きすぎます（' . size_format(self::MAX_UPLOAD) . 'まで）。');
        }
        $info = @getimagesize($f['tmp_name']);
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
        if (!$info || !isset($types[$info[2]])) {
            self::fail('JPEG・PNG・GIF・WebP の画像を選んでください。');
        }
        $ext = $types[$info[2]];
        $page_url = esc_url_raw(self::text('page_url'));
        if ($page_url === '' || wp_parse_url($page_url, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST)) {
            $page_url = self::find_page_with('[' . self::SHORTCODE) ?: home_url('/');
        }
        $id = wp_insert_post(['post_type' => self::POST_TYPE, 'post_status' => 'publish', 'post_title' => $name], true);
        if (is_wp_error($id)) {
            self::fail('注文を保存できませんでした。');
        }
        $dir = self::order_dir($id);
        $orig = 'original.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $orig)) {
            wp_delete_post($id, true);
            self::fail('画像を保存できませんでした。');
        }
        $o = [
            'id' => $id, 'status' => 'received', 'token' => wp_generate_password(32, false, false),
            'created' => current_time('mysql'), 'material' => $material, 'width' => $w, 'height' => $h, 'region' => $region,
            'name' => $name, 'company' => $company, 'tel' => $tel, 'email' => $email, 'address' => $address,
            'memo' => $memo, 'receipt' => $receipt, 'page_url' => $page_url,
            'original' => $orig, 'original_name' => sanitize_file_name($f['name']), 'image_px' => [$info[0], $info[1]],
            'previews' => [], 'log' => [],
        ];
        self::log($o, '発注（受付）');
        wp_update_post(['ID' => $id, 'post_title' => self::no($o) . ' ' . $name]);
        self::save($o);
        self::mail_received($o);
        self::mail_admin_new($o);
        self::mail_factory($o, 'inquiry');
        self::save($o);
        wp_send_json_success(['no' => self::no($o)]);
    }

    /** お客様が案を選ぶ（std/op3/max）、または どれも不可（ng＋コメント）。 */
    public function ajax_select() {
        check_ajax_referer('cfp_co', 'nonce');
        $o = self::by_token(absint($_POST['id'] ?? 0), (string) ($_POST['t'] ?? ''));
        if (!$o) {
            self::fail('このリンクは使えません。', 403);
        }
        if (!in_array($o['status'], ['previews_sent', 'selected'], true)) {
            self::fail('いまは案を選べない状態です（' . (self::STATUSES[$o['status']] ?? '') . '）。');
        }
        $choice = self::text('choice');
        $comment = self::text('comment', true);
        if ($choice === 'ng') {
            $o['status'] = 'rejected';
            $o['choice'] = '';
            $o['reject_comment'] = $comment;
            self::log($o, 'お客様：どれも不可' . ($comment !== '' ? '（' . $comment . '）' : ''));
            self::save($o);
            self::mail_admin($o, '【カーペット注文 ' . self::no($o) . '】お客様から「どれも不可」の返信', "お客様が、送ったプレビューのどれも希望と違うと返信しました。\n\nコメント：\n" . ($comment !== '' ? $comment : '（なし）') . "\n\n特許ページで作り直して、もう一度プレビューを送ってください：\n" . self::admin_convert_url($o));
            self::mail_customer($o, '【ご注文 ' . self::no($o) . '】ご返信ありがとうございます', $o['name'] . " 様\n\nご返信ありがとうございます。いただいたご要望をもとに、担当者が色の変換を作り直し、あらためてプレビューをお送りします。\n\nご要望：\n" . ($comment !== '' ? $comment : '（なし）'));
            wp_send_json_success(['order' => self::public_view($o)]);
        }
        if (!isset(self::VARIANTS[$choice]) || empty($o['previews'][$choice])) {
            self::fail('案を選んでください。');
        }
        $p = self::price($o['material'], $o['width'], $o['height'], $o['region'], $o['previews'][$choice]['colors']);
        $o['status'] = 'selected';
        $o['choice'] = $choice;
        $o['amount'] = $p['total'];
        $o['price'] = $p;
        self::log($o, 'お客様：' . self::VARIANTS[$choice] . ' を選択（' . number_format($p['total']) . '円）');
        self::save($o);
        self::mail_admin($o, '【カーペット注文 ' . self::no($o) . '】お客様が ' . self::VARIANTS[$choice] . ' を選びました（決済待ち）', 'お客様が ' . self::VARIANTS[$choice] . ' を選びました。金額：' . number_format($p['total']) . "円（税込）。決済が済むと、あらためてお知らせします。");
        wp_send_json_success(['order' => self::public_view($o)]);
    }

    private static function payjp($method, $path, $body = null) {
        list(, $sk) = self::payjp_keys();
        if ($sk === '') {
            return new WP_Error('cfp_payjp', 'PAY.JP の秘密鍵が設定されていません（管理画面で設定してください）。');
        }
        $args = ['method' => $method, 'timeout' => 30, 'headers' => ['Authorization' => 'Basic ' . base64_encode($sk . ':')]];
        if ($body !== null) {
            $args['body'] = $body;
        }
        $r = wp_remote_request('https://api.pay.jp/v1/' . $path, $args);
        if (is_wp_error($r)) {
            return $r;
        }
        $j = json_decode(wp_remote_retrieve_body($r), true);
        if (!is_array($j)) {
            return new WP_Error('cfp_payjp', 'PAY.JP の応答を読み取れませんでした。');
        }
        if (isset($j['error'])) {
            return new WP_Error('cfp_payjp', 'カード決済できませんでした：' . (string) ($j['error']['message'] ?? ''));
        }
        return $j;
    }

    /** 決済：カードのトークンで支払いを作る（3Dセキュア）。認証が要るときは支払いIDを返し、画面で認証してから pay_finish。 */
    public function ajax_pay() {
        check_ajax_referer('cfp_co', 'nonce');
        $o = self::by_token(absint($_POST['id'] ?? 0), (string) ($_POST['t'] ?? ''));
        if (!$o) {
            self::fail('このリンクは使えません。', 403);
        }
        if ($o['status'] !== 'selected' || empty($o['amount'])) {
            self::fail($o['status'] === 'paid' ? 'お支払いは完了しています。' : '先に案を選んでください。');
        }
        $tok = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($_POST['card_token'] ?? ''));
        if ($tok === '') {
            self::fail('カード情報を確認できませんでした。');
        }
        $lock = 'cfp_co_paylock_' . $o['id'];
        if (get_transient($lock)) {
            self::fail('お支払いの処理中です。しばらくお待ちください。');
        }
        set_transient($lock, 1, 60);
        $ch = self::payjp('POST', 'charges', [
            'card' => $tok, 'amount' => (int) $o['amount'], 'currency' => 'jpy', 'capture' => 'true', 'three_d_secure' => 'true',
            'description' => 'カーペット注文 ' . self::no($o), 'metadata[order]' => self::no($o),
        ]);
        delete_transient($lock);
        if (is_wp_error($ch)) {
            self::fail($ch->get_error_message());
        }
        $o['charge_id'] = (string) $ch['id'];
        self::log($o, '決済を開始（' . $o['charge_id'] . '・3Dセキュア ' . ($ch['three_d_secure_status'] ?? '') . '）');
        self::save($o);
        if (!empty($ch['paid'])) {
            $this->mark_paid($o, $ch);
            wp_send_json_success(['done' => true, 'order' => self::public_view($o)]);
        }
        wp_send_json_success(['done' => false, 'charge_id' => $o['charge_id']]);
    }

    /** 3Dセキュアの認証のあと：支払いを確かめて完了させる。 */
    public function ajax_pay_finish() {
        check_ajax_referer('cfp_co', 'nonce');
        $o = self::by_token(absint($_POST['id'] ?? 0), (string) ($_POST['t'] ?? ''));
        if (!$o || empty($o['charge_id'])) {
            self::fail('このリンクは使えません。', 403);
        }
        if ($o['status'] === 'paid') {
            wp_send_json_success(['done' => true, 'order' => self::public_view($o)]);
        }
        $ch = self::payjp('GET', 'charges/' . rawurlencode($o['charge_id']));
        if (is_wp_error($ch)) {
            self::fail($ch->get_error_message());
        }
        $tds = (string) ($ch['three_d_secure_status'] ?? '');
        if (empty($ch['paid'])) {
            if (!in_array($tds, ['verified', 'attempted'], true)) {
                self::log($o, '3Dセキュアの認証が完了しませんでした（' . $tds . '）');
                self::save($o);
                self::fail('カードの本人認証（3Dセキュア）が完了しませんでした。もう一度お試しください。');
            }
            $ch = self::payjp('POST', 'charges/' . rawurlencode($o['charge_id']) . '/tds_finish');
            if (is_wp_error($ch)) {
                self::fail($ch->get_error_message());
            }
        }
        if (empty($ch['paid'])) {
            self::fail('お支払いを確認できませんでした。');
        }
        if ((int) $ch['amount'] !== (int) $o['amount']) {
            self::fail('金額が一致しません。お手数ですがお問い合わせください。');
        }
        $this->mark_paid($o, $ch);
        wp_send_json_success(['done' => true, 'order' => self::public_view($o)]);
    }

    private function mark_paid(&$o, $ch) {
        $o['status'] = 'paid';
        $o['paid_at'] = current_time('mysql');
        self::log($o, '決済完了（' . number_format((int) $ch['amount']) . '円・' . $ch['id'] . '）');
        self::save($o);
        $p = $o['price'];
        $lines = self::spec_lines($o) . "\n選んだ案：" . self::VARIANTS[$o['choice']] . "\n使う色：" . implode('、', $o['previews'][$o['choice']]['colors'])
            . "\nお支払い金額：" . number_format($p['total']) . "円（税込・うち消費税 " . number_format($p['tax']) . "円）"
            . (self::settings()['company_invoice'] !== '' ? "\n" . self::settings()['company_name'] . "（適格請求書発行事業者登録番号：" . self::settings()['company_invoice'] . "）" : '');
        self::mail_customer($o, '【ご注文 ' . self::no($o) . '】お支払いが完了しました（正式ご注文）', $o['name'] . " 様\n\nお支払いを確認しました。正式にご注文を承りました。製作に入り、発送の準備ができましたらご連絡いたします。" . (!empty($o['lead_time']) ? "\n納期：お支払い確定後、" . $o['lead_time'] . "で発送します。" : '') . "\n\n" . $lines . "\n\n※ご注文（発注）後3日以内は無償でキャンセルを承ります。それ以降のキャンセルはお受けできません。", [self::path($o, 'preview_' . $o['choice'])]);
        self::mail_admin($o, '【カーペット注文 ' . self::no($o) . '】決済完了（正式発注）', "決済が完了しました。\n\n" . $lines . "\nPAY.JP 支払いID：" . $ch['id'] . "\n\n" . self::customer_lines($o));
        if (!empty(self::settings()['factory_on_paid'])) {
            self::mail_factory($o, 'order');
            self::save($o);
        }
    }

    /** 画像：お客様は鍵でプレビューだけ、管理者は元画像も。 */
    public function ajax_file() {
        $id = absint($_GET['id'] ?? 0);
        $f = sanitize_key($_GET['f'] ?? '');
        $admin = current_user_can('manage_options');
        $o = $admin ? self::get($id) : self::by_token($id, (string) ($_GET['t'] ?? ''));
        if (!$o) {
            status_header(403);
            exit;
        }
        $allowed = ['preview_std', 'preview_op3', 'preview_max'];
        if ($admin) {
            $allowed[] = 'original';
        }
        if (!in_array($f, $allowed, true)) {
            status_header(404);
            exit;
        }
        $path = self::path($o, $f);
        if (!$path || !is_readable($path)) {
            status_header(404);
            exit;
        }
        $mime = wp_check_filetype($path)['type'] ?: 'application/octet-stream';
        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    private static function path($o, $f) {
        $dir = self::base_dir() . '/order-' . (int) $o['id'];
        if ($f === 'original') {
            return $dir . '/' . $o['original'];
        }
        if (strpos($f, 'preview_') === 0) {
            return $dir . '/' . $f . '.png';
        }
        return '';
    }

    /* ---------------------------------------------------- 特許ページ（管理者） */

    private static function admin_convert_url($o) {
        $u = self::patent_page_url();
        return $u ? add_query_arg('cfp_order', (int) $o['id'], $u) : admin_url('admin.php?page=cfp-carpet-orders');
    }

    /** The panel config on the 特許ページ (admins only, with ?cfp_order=). */
    public static function admin_panel_config() {
        if (!current_user_can('manage_options') || empty($_GET['cfp_order'])) {
            return null;
        }
        $o = self::get(absint($_GET['cfp_order']));
        if (!$o) {
            return null;
        }
        return [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('cfp_co_admin'),
            'id'       => (int) $o['id'],
            'no'       => self::no($o),
            'name'     => $o['name'],
            'status'   => self::STATUSES[$o['status']] ?? $o['status'],
            'spec'     => ['material' => $o['material'], 'width' => $o['width'], 'height' => $o['height'], 'region' => $o['region']],
            'original' => self::file_url($o, 'original', false),
            'original_name' => $o['original_name'],
            'reject_comment' => $o['reject_comment'] ?? '',
            'memo'     => $o['memo'],
            'list_url' => admin_url('admin.php?page=cfp-carpet-orders'),
            'sent'     => !empty($o['previews']),
        ];
    }

    public function ajax_admin_get() {
        check_ajax_referer('cfp_co_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            self::fail('権限がありません。', 403);
        }
        $o = self::get(absint($_POST['id'] ?? 0));
        if (!$o) {
            self::fail('注文が見つかりません。', 404);
        }
        wp_send_json_success(self::public_view($o));
    }

    /** 特許ページから：3通りのプレビュー（PNG）と使う色を受け取り、お客様へプレビュー付きのメールを送る。 */
    public function ajax_send_previews() {
        check_ajax_referer('cfp_co_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            self::fail('権限がありません。', 403);
        }
        $o = self::get(absint($_POST['id'] ?? 0));
        if (!$o) {
            self::fail('注文が見つかりません。', 404);
        }
        if (in_array($o['status'], ['paid', 'cancelled'], true)) {
            self::fail('この注文は ' . self::STATUSES[$o['status']] . ' のため、送れません。');
        }
        $dir = self::order_dir($o['id']);
        $previews = [];
        foreach (array_keys(self::VARIANTS) as $k) {
            $png = isset($_POST['png_' . $k]) ? (string) wp_unslash($_POST['png_' . $k]) : '';
            if (strpos($png, 'data:image/png;base64,') !== 0) {
                self::fail('プレビュー画像がそろっていません（' . self::VARIANTS[$k] . '）。');
            }
            $bin = base64_decode(substr($png, 22), true);
            if ($bin === false || strlen($bin) > 15 * 1024 * 1024 || substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                self::fail('プレビュー画像を読み取れませんでした。');
            }
            $colors = json_decode((string) wp_unslash($_POST['colors_' . $k] ?? '[]'), true);
            if (!is_array($colors) || !$colors || count($colors) > 16) {
                self::fail('使う色を読み取れませんでした。');
            }
            $colors = array_values(array_map('sanitize_text_field', $colors));
            file_put_contents($dir . '/preview_' . $k . '.png', $bin);
            $previews[$k] = ['colors' => $colors];
        }
        $o['previews'] = $previews;
        $o['preview_message'] = self::text('message', true);
        $lead = mb_substr(self::text('lead'), 0, 60);
        $o['lead_time'] = $lead !== '' ? $lead : self::LEAD_DEFAULT;
        $o['dither'] = max(0, min(100, (int) self::text('dither')));
        $o['status'] = 'previews_sent';
        $o['choice'] = '';
        $o['sent_count'] = (int) ($o['sent_count'] ?? 0) + 1;
        self::log($o, 'プレビューを送信（' . $o['sent_count'] . '回目）');
        self::save($o);
        $lines = [];
        $files = [];
        foreach (self::VARIANTS as $k => $label) {
            $p = self::price($o['material'], $o['width'], $o['height'], $o['region'], $previews[$k]['colors']);
            $lines[] = '■ ' . $label . '：' . count($previews[$k]['colors']) . '色'
                . ($p['paid_count'] ? '（有償オプション色 ' . $p['paid_count'] . '色：' . implode('・', $p['paid_colors']) . ($p['free_applied'] ? '・うち' . $p['free_applied'] . '色は10㎡以上の特典で無料' : '') . '）' : '（すべて無償色）')
                . "\n　合計 " . number_format($p['total']) . '円（税込・送料込み）';
            $files[] = $dir . '/preview_' . $k . '.png';
        }
        $body = $o['name'] . " 様\n\nご注文ありがとうございます。いただいたデザインを、カーペットで使える色に変換したプレビューをお送りします（添付の画像3枚）。\n"
            . ($o['preview_message'] !== '' ? "\n" . $o['preview_message'] . "\n" : '')
            . "\n" . implode("\n\n", $lines)
            . "\n\n■ 納期：お支払い確定後、" . $o['lead_time'] . "で発送します。"
            . "\n\n下のページで、ご希望の案を選んでください。案を選ぶと金額が確定し、そのままカードでお支払いいただけます（お支払いの完了で正式なご注文になります）。\nどの案もご希望と違う場合は、同じページの「どれも希望と違う」からご要望をお送りください。担当者が作り直してお送りします。\n\n"
            . self::select_url($o) . "\n\n" . self::spec_lines($o)
            . "\n\n※プレビューは確認用の画像です。実際の製品の色味・質感・見え方とは異なる場合がございます。";
        $ok = self::mail_customer($o, '【ご注文 ' . self::no($o) . '】色の変換プレビューのご確認', $body, $files);
        wp_send_json_success(['sent' => $ok, 'to' => $o['email'], 'select_url' => self::select_url($o)]);
    }

    /* -------------------------------------------------------------- mail */

    private static function spec_lines($o) {
        $p = self::price($o['material'], $o['width'], $o['height'], $o['region'], []);
        return "ご注文番号：" . self::no($o)
            . "\n仕様：" . (self::MATERIALS[$o['material']] ?? '') . '（500×500mm タイル）'
            . "\nサイズ：" . $o['width'] . '×' . $o['height'] . 'mm（' . $p['area'] . '㎡・' . $p['tiles'] . '枚＝横' . $p['tiles_x'] . '×縦' . $p['tiles_y'] . '・' . $p['boxes'] . '箱）'
            . "\nお届け先地域：" . (self::REGIONS[$o['region']][0] ?? '');
    }

    private static function customer_lines($o) {
        return "お名前：" . $o['name'] . ($o['company'] !== '' ? '（' . $o['company'] . '）' : '')
            . "\n電話：" . $o['tel'] . "\nメール：" . $o['email'] . "\nご住所：" . $o['address']
            . "\n領収書：" . ($o['receipt'] ? '必要' : '不要') . ($o['memo'] !== '' ? "\nご要望：\n" . $o['memo'] : '');
    }

    private static function headers($reply_to = '') {
        $h = ['Content-Type: text/plain; charset=UTF-8'];
        if ($reply_to !== '' && is_email($reply_to)) {
            $h[] = 'Reply-To: ' . $reply_to;
        }
        return $h;
    }

    private static function mail_customer($o, $subject, $body, $files = []) {
        $sign = "\n\n--------------------------------------------------\n" . get_bloginfo('name') . "\n" . home_url('/') . "\nこのメールにご返信いただくと、担当者に届きます。";
        return wp_mail($o['email'], $subject, $body . $sign, self::headers(self::admin_email()), array_filter($files, 'is_readable'));
    }

    private static function mail_admin($o, $subject, $body) {
        return wp_mail(self::admin_email(), $subject, $body . "\n\n注文の一覧：" . admin_url('admin.php?page=cfp-carpet-orders'), self::headers($o['email']));
    }

    private static function mail_received($o) {
        $p = self::price($o['material'], $o['width'], $o['height'], $o['region'], []);
        $body = $o['name'] . " 様\n\nカーペットのご注文を受け付けました。ありがとうございます。\n\n担当者が、いただいたデザインをカーペットで使える色に変換したプレビュー（色の使い方の違う案）を作成し、あらためてメールでお送りします。プレビューをご確認のうえ、ご希望の案を選んでお支払いいただくと、正式なご注文になります。\n\n"
            . self::spec_lines($o)
            . "\n\n概算（オプションカラーを使わない場合）：" . number_format($p['total']) . "円（税込・送料込み）\n※有償のオプションカラーを使う案では、1色につき" . number_format(self::PRICE_PER_COLOR) . "円（税別）が加わります。\n\n" . self::customer_lines($o);
        return self::mail_customer($o, '【ご注文 ' . self::no($o) . '】ご注文を受け付けました', $body);
    }

    private static function mail_admin_new($o) {
        $body = "新ページから、カーペットの発注がありました。\n\n" . self::spec_lines($o) . "\n\n" . self::customer_lines($o)
            . "\n\n▼ 特許ページで色を変換して、お客様へプレビューを送る：\n" . self::admin_convert_url($o)
            . "\n（管理者としてログインした状態で開いてください）";
        return self::mail_admin($o, '【カーペット注文 ' . self::no($o) . '】新しい発注（プレビューを作成してください）', $body);
    }

    /** 工場へ：inquiry＝発注時の納期確認（元画像を添付）、order＝決済後の正式発注（選んだ案の画像を添付）。 */
    private static function mail_factory(&$o, $kind) {
        $s = self::settings();
        if (!is_email($s['factory_email'])) {
            self::log($o, '工場のメールアドレスが未設定のため、工場へのメールを送れませんでした');
            return false;
        }
        $who = $s['factory_name'] !== '' ? $s['factory_name'] . " 様\n\n" : '';
        if ($kind === 'inquiry') {
            $subject = '【納期のご確認】カーペット ' . self::no($o) . '（' . (self::MATERIALS[$o['material']] ?? '') . '・' . $o['width'] . '×' . $o['height'] . 'mm）';
            $body = $who . "いつもお世話になっております。\n下記のカーペットの製作について、納期のご確認をお願いいたします（まだ正式な発注ではありません。色数は確定前です）。\n\n"
                . self::spec_lines($o) . "\nお届け先：" . self::pref($o['address']) . "\n\nデザインの元画像を添付します（" . $o['original_name'] . '・' . $o['image_px'][0] . '×' . $o['image_px'][1] . "px）。\n\nご回答は、このメールへのご返信でお願いいたします。";
            $files = [self::path($o, 'original')];
        } else {
            $subject = '【正式発注】カーペット ' . self::no($o) . '（' . (self::MATERIALS[$o['material']] ?? '') . '・' . $o['width'] . '×' . $o['height'] . 'mm）';
            $body = $who . "いつもお世話になっております。\n下記のカーペットを正式に発注いたします。製作用データは別途お送りします。\n\n"
                . self::spec_lines($o) . "\n使う色（" . count($o['previews'][$o['choice']]['colors']) . '色）：' . implode('、', $o['previews'][$o['choice']]['colors'])
                . "\nお届け先：" . self::pref($o['address']) . "\n\n仕上がりの確認用画像を添付します。";
            $files = [self::path($o, 'preview_' . $o['choice'])];
        }
        $ok = wp_mail($s['factory_email'], $subject, $body . "\n\n" . get_bloginfo('name'), self::headers(self::admin_email()), array_filter($files, 'is_readable'));
        self::log($o, '工場へ' . ($kind === 'inquiry' ? '納期確認' : '正式発注') . 'メール' . ($ok ? 'を送信' : 'の送信に失敗'));
        return $ok;
    }

    /** The prefecture part of an address (the factory does not need the full address before shipping). */
    private static function pref($address) {
        if (preg_match('/^(東京都|北海道|(?:京都|大阪)府|.{2,3}県)/u', $address, $m)) {
            return $m[1];
        }
        return '（ご住所の都道府県を確認できませんでした）';
    }

    /* ------------------------------------------------------------- admin */

    public function admin_menu() {
        add_submenu_page('cad-floor-plan', 'カーペット注文', 'カーペット注文', 'manage_options', 'cfp-carpet-orders', [$this, 'render_admin']);
    }

    public function render_admin() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = self::settings();
        $msg = isset($_GET['cfp_co']) ? sanitize_key($_GET['cfp_co']) : '';
        $orders = get_posts(['post_type' => self::POST_TYPE, 'post_status' => 'any', 'numberposts' => 100, 'orderby' => 'ID', 'order' => 'DESC', 'fields' => 'ids']);
        $order_page = self::order_page_url();
        $order_page_id = self::page_id();
        $patent = self::patent_page_url();
        ?>
        <div class="wrap">
            <h1>カーペット注文</h1>
            <?php if ($msg === 'saved') : ?><div class="notice notice-success"><p>保存しました。</p></div><?php endif; ?>
            <?php if ($msg === 'status') : ?><div class="notice notice-success"><p>状態を変えました。</p></div><?php endif; ?>
            <?php if ($msg === 'page') : ?><div class="notice notice-success"><p>注文ページ（固定ページ）を用意しました。</p></div><?php endif; ?>
            <?php if ($msg === 'page_error') : ?><div class="notice notice-error"><p>注文ページを作成できませんでした。</p></div><?php endif; ?>
            <h2>ページ</h2>
            <p>公開の注文ページ（新ページ）：
                <?php if ($order_page) : ?>
                    <a href="<?php echo esc_url($order_page); ?>" target="_blank" rel="noopener"><?php echo esc_html(urldecode($order_page)); ?></a>
                    <?php if ($order_page_id) : ?> | <a href="<?php echo esc_url(get_edit_post_link($order_page_id)); ?>">固定ページを編集</a><?php endif; ?>
                <?php else : ?>
                    まだありません。
                <?php endif; ?>
            </p>
            <?php if (!$order_page_id) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="cfp_co_create_page">
                <?php wp_nonce_field('cfp_co_create_page'); ?>
                <?php submit_button('注文ページ（固定ページ）を作成', 'primary', 'submit', false); ?>
                <p class="description">固定ページ「カーペットのご注文」（URL：<code><?php echo esc_html(home_url('/carpet-order/')); ?></code>）を作って公開します。<code>[<?php echo esc_html(self::SHORTCODE); ?>]</code> を置いた固定ページがすでにあれば、それを使います。</p>
            </form>
            <?php endif; ?>
            <p>色変換の特許ページ（管理者だけ）：<?php echo $patent ? '<a href="' . esc_url($patent) . '" target="_blank" rel="noopener">' . esc_html(urldecode($patent)) . '</a>' : '未作成'; ?></p>

            <h2>注文</h2>
            <table class="widefat striped">
                <thead><tr><th>番号</th><th>受付</th><th>お客様</th><th>仕様</th><th>状態</th><th>選んだ案・金額</th><th>操作</th></tr></thead>
                <tbody>
                <?php if (!$orders) : ?><tr><td colspan="7">まだ注文はありません。</td></tr><?php endif; ?>
                <?php foreach ($orders as $pid) : $o = self::get($pid); if (!$o) { continue; } ?>
                    <tr>
                        <td><strong><?php echo esc_html(self::no($o)); ?></strong></td>
                        <td><?php echo esc_html($o['created']); ?></td>
                        <td><?php echo esc_html($o['name']); ?><br><small><?php echo esc_html($o['email'] . ' / ' . $o['tel']); ?></small></td>
                        <td><?php echo esc_html((self::MATERIALS[$o['material']] ?? '') . ' ' . $o['width'] . '×' . $o['height'] . 'mm・' . (self::REGIONS[$o['region']][0] ?? '')); ?></td>
                        <td><?php echo esc_html(self::STATUSES[$o['status']] ?? $o['status']); ?>
                            <?php if (!empty($o['reject_comment']) && $o['status'] === 'rejected') : ?><br><small>コメント：<?php echo esc_html($o['reject_comment']); ?></small><?php endif; ?></td>
                        <td><?php echo !empty($o['choice']) ? esc_html(self::VARIANTS[$o['choice']] . '・' . number_format((int) $o['amount']) . '円') : '—'; ?>
                            <?php if (!empty($o['charge_id'])) : ?><br><small><?php echo esc_html($o['charge_id']); ?></small><?php endif; ?></td>
                        <td>
                            <?php if (!in_array($o['status'], ['paid', 'cancelled'], true)) : ?><a class="button button-primary" href="<?php echo esc_url(self::admin_convert_url($o)); ?>" target="_blank" rel="noopener">特許ページで変換・送信</a><?php endif; ?>
                            <a class="button" href="<?php echo esc_url(self::file_url($o, 'original', false)); ?>" target="_blank" rel="noopener">元画像</a>
                            <details><summary>詳細</summary>
                                <pre style="white-space:pre-wrap;max-width:420px"><?php echo esc_html(self::customer_lines($o)); ?></pre>
                                <?php foreach ((array) $o['log'] as $l) : ?><div><small><?php echo esc_html($l[0] . ' ' . $l[1]); ?></small></div><?php endforeach; ?>
                                <?php if ($o['status'] !== 'paid') : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:6px">
                                    <input type="hidden" name="action" value="cfp_co_status"><input type="hidden" name="id" value="<?php echo (int) $o['id']; ?>">
                                    <?php wp_nonce_field('cfp_co_status'); ?>
                                    <button class="button" name="to" value="cancelled" onclick="return confirm('この注文をキャンセル扱いにしますか？');">キャンセル扱いにする</button>
                                </form>
                                <?php endif; ?>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>設定</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="cfp_co_settings">
                <?php wp_nonce_field('cfp_co_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr><th><label for="co-admin">通知を受けるメール（管理者）</label></th><td><input type="email" id="co-admin" name="admin_email" class="regular-text" value="<?php echo esc_attr($s['admin_email']); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>"><p class="description">空のときはサイトの管理者メールアドレス。お客様への返信先にもなります。</p></td></tr>
                    <tr><th><label for="co-factory">工場のメール</label></th><td><input type="email" id="co-factory" name="factory_email" class="regular-text" value="<?php echo esc_attr($s['factory_email']); ?>"><p class="description">発注ボタンが押されたときに、納期確認のメール（元画像を添付）を送ります。</p></td></tr>
                    <tr><th><label for="co-factory-name">工場の宛名</label></th><td><input type="text" id="co-factory-name" name="factory_name" class="regular-text" value="<?php echo esc_attr($s['factory_name']); ?>"></td></tr>
                    <tr><th>シミュレーターのページ</th><td><label><input type="checkbox" name="simulator_public" value="1" <?php checked(!empty($s['simulator_public'])); ?>> 「壁紙・カーペットシミュレーション」（特許ページ）をどなたにも公開する</label><p class="description">外すと管理者だけに表示し、ほかの方には注文ページへの案内を出します。公開中は、色変換のプログラムもページを開いた方が読める状態になります。</p></td></tr>
                    <tr><th>決済完了時</th><td><label><input type="checkbox" name="factory_on_paid" value="1" <?php checked(!empty($s['factory_on_paid'])); ?>> 工場へ正式発注のメールも送る</label></td></tr>
                    <tr><th colspan="2"><h3 style="margin:8px 0 0">販売者情報（注文ページの「会社情報・特定商取引法に基づく表記」）</h3></th></tr>
                    <?php foreach (['company_name' => '販売事業者', 'company_rep' => '代表', 'company_invoice' => '適格請求書発行事業者登録番号', 'company_address' => '所在地', 'company_tel' => '電話', 'company_email' => 'メール', 'company_url' => 'ホームページ'] as $k => $l) : ?>
                    <tr><th><label for="co-<?php echo esc_attr($k); ?>"><?php echo esc_html($l); ?></label></th><td><input type="text" id="co-<?php echo esc_attr($k); ?>" name="<?php echo esc_attr($k); ?>" class="regular-text" value="<?php echo esc_attr($s[$k]); ?>"></td></tr>
                    <?php endforeach; ?>
                    <tr><th>PAY.JP</th><td>
                        <label><input type="radio" name="payjp_mode" value="test" <?php checked($s['payjp_mode'], 'test'); ?>> テスト</label>
                        <label style="margin-left:12px"><input type="radio" name="payjp_mode" value="live" <?php checked($s['payjp_mode'], 'live'); ?>> 本番</label>
                        <p class="description">本番にする前に、テストのカード（4242 4242 4242 4242 など）で一連の流れを確かめてください。</p></td></tr>
                    <?php foreach (['test' => 'テスト', 'live' => '本番'] as $m => $ml) : ?>
                    <tr><th><label for="co-pk-<?php echo esc_attr($m); ?>">公開鍵（<?php echo esc_html($ml); ?>）</label></th><td><input type="text" id="co-pk-<?php echo esc_attr($m); ?>" name="payjp_pk_<?php echo esc_attr($m); ?>" class="regular-text" value="<?php echo esc_attr($s['payjp_pk_' . $m]); ?>" placeholder="pk_<?php echo esc_attr($m); ?>_..."></td></tr>
                    <tr><th><label for="co-sk-<?php echo esc_attr($m); ?>">秘密鍵（<?php echo esc_html($ml); ?>）</label></th><td><input type="password" id="co-sk-<?php echo esc_attr($m); ?>" name="payjp_sk_<?php echo esc_attr($m); ?>" class="regular-text" value="" autocomplete="off" placeholder="<?php echo $s['payjp_sk_' . $m] !== '' ? esc_attr('設定済み（変えるときだけ入力）') : 'sk_' . esc_attr($m) . '_...'; ?>"><p class="description">サーバーにだけ保存し、ページには出しません。</p></td></tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button('保存'); ?>
            </form>
        </div>
        <?php
    }

    public function handle_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('権限がありません。');
        }
        check_admin_referer('cfp_co_settings');
        $s = self::settings();
        $s['admin_email'] = sanitize_email(wp_unslash($_POST['admin_email'] ?? ''));
        $s['factory_email'] = sanitize_email(wp_unslash($_POST['factory_email'] ?? ''));
        $s['factory_name'] = sanitize_text_field(wp_unslash($_POST['factory_name'] ?? ''));
        $s['factory_on_paid'] = empty($_POST['factory_on_paid']) ? 0 : 1;
        $s['simulator_public'] = empty($_POST['simulator_public']) ? 0 : 1;
        $s['payjp_mode'] = ($_POST['payjp_mode'] ?? '') === 'live' ? 'live' : 'test';
        foreach (['company_name', 'company_rep', 'company_invoice', 'company_address', 'company_tel'] as $k) {
            $s[$k] = sanitize_text_field(wp_unslash($_POST[$k] ?? ''));
        }
        $s['company_email'] = sanitize_email(wp_unslash($_POST['company_email'] ?? ''));
        $s['company_url'] = esc_url_raw(wp_unslash($_POST['company_url'] ?? ''));
        foreach (['test', 'live'] as $m) {
            $s['payjp_pk_' . $m] = preg_replace('/[^A-Za-z0-9_]/', '', wp_unslash($_POST['payjp_pk_' . $m] ?? ''));
            $sk = preg_replace('/[^A-Za-z0-9_]/', '', wp_unslash($_POST['payjp_sk_' . $m] ?? ''));
            if ($sk !== '') {
                $s['payjp_sk_' . $m] = $sk;
            }
        }
        update_option(self::OPT, $s, false);
        wp_safe_redirect(admin_url('admin.php?page=cfp-carpet-orders&cfp_co=saved'));
        exit;
    }

    public function handle_status() {
        if (!current_user_can('manage_options')) {
            wp_die('権限がありません。');
        }
        check_admin_referer('cfp_co_status');
        $o = self::get(absint($_POST['id'] ?? 0));
        if ($o && ($_POST['to'] ?? '') === 'cancelled' && $o['status'] !== 'paid') {
            $o['status'] = 'cancelled';
            self::log($o, '管理者がキャンセル扱いに');
            self::save($o);
        }
        wp_safe_redirect(admin_url('admin.php?page=cfp-carpet-orders&cfp_co=status'));
        exit;
    }
}
