<?php
/**
 * Plugin Name: CAD Floor Plan 3D Simulator
 * Plugin URI: https://github.com/mushroomyasuyuki/cad-floor-plan-simulator
 * Description: DXF/PDF/JSON形式のCADデータを読み込み、リアルタイム3Dシミュレーションを表示するプラグイン
 * Version: 1.0.0
 * Author: Claude Haiku 4.5
 * Author URI: https://claude.ai/code
 * License: MIT
 * Text Domain: cad-floor-plan
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// プラグイン定数
define('CFP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CFP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CFP_PLUGIN_VERSION', '1.0.0');

class CADFloorPlanPlugin {
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScripts']);
        add_shortcode('cad_floor_plan', [$this, 'renderShortcode']);
        add_action('rest_api_init', [$this, 'registerRestApi']);

        // メニュー
        add_action('admin_menu', [$this, 'addAdminMenu']);
    }

    /**
     * フロントエンドスクリプト・スタイルをエンキュー
     */
    public function enqueueScripts() {
        // Three.js CDN
        wp_enqueue_script(
            'three-js',
            'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js',
            [],
            '128',
            true
        );

        // CADパーサー
        wp_enqueue_script(
            'cad-parser',
            CFP_PLUGIN_URL . 'assets/js/cad-parser.js',
            [],
            CFP_PLUGIN_VERSION,
            true
        );

        // 3Dレンダラー
        wp_enqueue_script(
            'three-room-renderer',
            CFP_PLUGIN_URL . 'assets/js/three-room-renderer.js',
            ['three-js', 'cad-parser'],
            CFP_PLUGIN_VERSION,
            true
        );

        // メインスクリプト
        wp_enqueue_script(
            'cad-floor-plan',
            CFP_PLUGIN_URL . 'assets/js/main.js',
            ['three-room-renderer'],
            CFP_PLUGIN_VERSION,
            true
        );

        // スタイル
        wp_enqueue_style(
            'cad-floor-plan',
            CFP_PLUGIN_URL . 'assets/css/style.css',
            [],
            CFP_PLUGIN_VERSION
        );

        // ローカライズ
        wp_localize_script('cad-floor-plan', 'cadFloorPlanData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cad_floor_plan'),
            'pluginUrl' => CFP_PLUGIN_URL,
            'restUrl' => rest_url('cad-floor-plan/v1'),
        ]);
    }

    /**
     * 管理画面スクリプト・スタイル
     */
    public function enqueueAdminScripts($hook) {
        if (strpos($hook, 'cad-floor-plan') === false) {
            return;
        }

        wp_enqueue_script(
            'cad-admin',
            CFP_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            CFP_PLUGIN_VERSION,
            true
        );

        wp_enqueue_style(
            'cad-admin',
            CFP_PLUGIN_URL . 'assets/css/admin.css',
            [],
            CFP_PLUGIN_VERSION
        );
    }

    /**
     * Shortcode: [cad_floor_plan]
     */
    public function renderShortcode($atts) {
        $atts = shortcode_atts([
            'width' => '800',
            'height' => '600',
            'sample' => 'default',
            'show_info' => 'true',
        ], $atts);

        ob_start();
        ?>
        <div class="cad-floor-plan-widget" data-width="<?php echo esc_attr($atts['width']); ?>" data-height="<?php echo esc_attr($atts['height']); ?>" data-sample="<?php echo esc_attr($atts['sample']); ?>" data-show-info="<?php echo esc_attr($atts['show_info']); ?>">
            <div class="cfp-container">
                <div class="cfp-header">
                    <h2>🏗️ CAD Floor Plan 3D Simulator</h2>
                    <p>DXF / PDF / JSON形式のCADデータをアップロードしてリアルタイム3D表示</p>
                </div>

                <div class="cfp-input-area">
                    <div class="cfp-file-input">
                        <label for="cfp-file-input">📋 DXF / PDF / JSON ファイルを選択</label>
                        <input type="file" id="cfp-file-input" accept=".dxf,.pdf,.json">
                        <div class="cfp-file-info">
                            対応形式: DXF / PDF（ベクター） / JSON<br>
                            最大サイズ: 10MB
                        </div>
                    </div>

                    <div class="cfp-info-panel" id="cfp-info-panel">
                        <div class="cfp-info-row">
                            <span class="cfp-label">ファイル名</span>
                            <span class="cfp-value" id="cfp-filename">未選択</span>
                        </div>
                        <div class="cfp-info-row">
                            <span class="cfp-label">床面積</span>
                            <span class="cfp-value" id="cfp-floor-area">-</span>
                        </div>
                        <div class="cfp-info-row">
                            <span class="cfp-label">壁面積</span>
                            <span class="cfp-value" id="cfp-wall-area">-</span>
                        </div>
                        <div class="cfp-info-row">
                            <span class="cfp-label">部屋数</span>
                            <span class="cfp-value" id="cfp-room-count">0</span>
                        </div>
                    </div>
                </div>

                <div class="cfp-status" id="cfp-status"></div>

                <div class="cfp-canvas-container" id="cfp-canvas-container"></div>

                <div class="cfp-controls">
                    <button class="cfp-btn cfp-btn-primary" id="cfp-btn-load-sample">
                        🎯 サンプルを読み込み
                    </button>
                    <button class="cfp-btn" id="cfp-btn-reset-camera">
                        🎥 カメラをリセット
                    </button>
                    <button class="cfp-btn" id="cfp-btn-download-json">
                        💾 データを保存
                    </button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * REST API エンドポイント登録
     */
    public function registerRestApi() {
        // CADファイル解析エンドポイント
        register_rest_route('cad-floor-plan/v1', '/parse', [
            'methods' => 'POST',
            'callback' => [$this, 'restParseFile'],
            'permission_callback' => '__return_true',
            'args' => [
                'file' => [
                    'type' => 'string',
                    'required' => true,
                ],
                'format' => [
                    'type' => 'string',
                    'enum' => ['dxf', 'pdf', 'json'],
                    'required' => true,
                ],
            ],
        ]);

        // メタデータ取得
        register_rest_route('cad-floor-plan/v1', '/info', [
            'methods' => 'GET',
            'callback' => [$this, 'restGetInfo'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * REST API: ファイル解析
     */
    public function restParseFile($request) {
        $file = $request->get_param('file');
        $format = $request->get_param('format');

        if (empty($file) || empty($format)) {
            return new WP_Error(
                'invalid_params',
                'ファイルと形式は必須です',
                ['status' => 400]
            );
        }

        // 実際の解析はJavaScriptで行うため、ここでは簡易的なバリデーション
        return rest_ensure_response([
            'success' => true,
            'message' => 'ファイルを受け取りました。ブラウザ内で処理中...',
            'format' => $format,
        ]);
    }

    /**
     * REST API: プラグイン情報
     */
    public function restGetInfo($request) {
        return rest_ensure_response([
            'name' => 'CAD Floor Plan 3D Simulator',
            'version' => CFP_PLUGIN_VERSION,
            'supported_formats' => ['dxf', 'pdf', 'json'],
            'max_file_size' => '10MB',
        ]);
    }

    /**
     * 管理メニュー追加
     */
    public function addAdminMenu() {
        add_menu_page(
            'CAD Floor Plan',
            'CAD Floor Plan',
            'manage_options',
            'cad-floor-plan',
            [$this, 'renderAdminPage'],
            'dashicons-layout',
            30
        );

        add_submenu_page(
            'cad-floor-plan',
            'Settings',
            'Settings',
            'manage_options',
            'cad-floor-plan-settings',
            [$this, 'renderSettingsPage']
        );
    }

    /**
     * 管理ページ
     */
    public function renderAdminPage() {
        ?>
        <div class="wrap">
            <h1>CAD Floor Plan 3D Simulator</h1>
            <p>このプラグインは DXF / PDF / JSON 形式のCADデータを読み込み、リアルタイム3D表示します。</p>

            <h2>使用方法</h2>
            <ol>
                <li>ページ・投稿のエディタで以下のショートコードを追加:</li>
                <li><code>[cad_floor_plan width="800" height="600"]</code></li>
                <li>フロントエンドで表示されたウィジェットにCADファイルをアップロード</li>
            </ol>

            <h2>ショートコード属性</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>属性</th>
                        <th>デフォルト</th>
                        <th>説明</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>width</code></td>
                        <td>800</td>
                        <td>キャンバス幅（ピクセル）</td>
                    </tr>
                    <tr>
                        <td><code>height</code></td>
                        <td>600</td>
                        <td>キャンバス高さ（ピクセル）</td>
                    </tr>
                    <tr>
                        <td><code>sample</code></td>
                        <td>default</td>
                        <td>デフォルト表示 (default / none)</td>
                    </tr>
                    <tr>
                        <td><code>show_info</code></td>
                        <td>true</td>
                        <td>情報パネル表示 (true / false)</td>
                    </tr>
                </tbody>
            </table>

            <h2>REST API</h2>
            <p>外部アプリケーションから以下のエンドポイントを利用可能:</p>
            <ul>
                <li><code>POST /wp-json/cad-floor-plan/v1/parse</code> - ファイル解析</li>
                <li><code>GET /wp-json/cad-floor-plan/v1/info</code> - プラグイン情報</li>
            </ul>

            <h2>トラブルシューティング</h2>
            <p>3D表示が出ない場合:</p>
            <ul>
                <li>ブラウザがWebGLをサポートしているか確認</li>
                <li>JavaScript コンソールでエラーを確認</li>
                <li>ファイル形式が正しいか確認（DXF、PDF、JSON）</li>
            </ul>
        </div>
        <?php
    }

    /**
     * 設定ページ
     */
    public function renderSettingsPage() {
        ?>
        <div class="wrap">
            <h1>CAD Floor Plan - Settings</h1>
            <p>今後、キャッシュ設定などをここに追加予定です。</p>
        </div>
        <?php
    }
}

// プラグイン初期化
add_action('plugins_loaded', function() {
    CADFloorPlanPlugin::getInstance();
});

// アクティベーション
register_activation_hook(__FILE__, function() {
    // 必要に応じてデータベース初期化など
    add_option('cad_floor_plan_version', CFP_PLUGIN_VERSION);
});

// デアクティベーション
register_deactivation_hook(__FILE__, function() {
    // クリーンアップ処理
});
