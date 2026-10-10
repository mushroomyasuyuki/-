<?php
/** 管理画面：ライセンスの発行・状態の変更、製品ファイルの登録、①の設定方法の案内。 */

if (!defined('ABSPATH')) {
    exit;
}

final class MCO_Admin {
    const PAGE = 'mco-licenses';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_mco_add_license', array(__CLASS__, 'add_license'));
        add_action('admin_post_mco_set_status', array(__CLASS__, 'set_status'));
        add_action('admin_post_mco_upload_product', array(__CLASS__, 'upload_product'));
        add_action('admin_post_mco_set_latest', array(__CLASS__, 'set_latest'));
        add_action('admin_post_mco_save_options', array(__CLASS__, 'save_options'));
    }

    public static function menu() {
        add_menu_page('ライセンス管理', 'ライセンス管理', 'manage_options', self::PAGE, array(__CLASS__, 'page'), 'dashicons-admin-network', 58);
    }

    private static function guard($action) {
        if (!current_user_can('manage_options')) {
            wp_die('権限がありません。', '', array('response' => 403));
        }
        check_admin_referer($action);
    }

    private static function flash($type, $message) {
        set_transient('mco_flash_' . get_current_user_id(), array($type, $message), 120);
    }

    private static function back() {
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE));
        exit;
    }

    private static function post($key, $max = 190) {
        return isset($_POST[$key]) ? mb_substr(sanitize_text_field(wp_unslash($_POST[$key])), 0, $max) : '';
    }

    /* ---------- 画面 ---------- */

    public static function page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap"><h1>ライセンス管理</h1>';
        $fl = get_transient('mco_flash_' . get_current_user_id());
        if ($fl) {
            delete_transient('mco_flash_' . get_current_user_id());
            printf('<div class="notice notice-%s"><p>%s</p></div>', $fl[0] === 'ok' ? 'success' : 'error', esc_html($fl[1]));
        }
        self::section_products();
        self::section_issue();
        self::section_licenses();
        self::section_howto();
        self::section_options();
        echo '</div>';
    }

    private static function form_open($action) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field($action);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
    }

    private static function section_products() {
        echo '<h2>1. 配布するファイル（マッシュオフィスシステム）</h2>';
        $list = MCO_Products::all();
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>版</th><th>サイズ</th><th>SHA-256（先頭）</th><th>登録日時（UTC）</th><th>配布</th></tr></thead><tbody>';
        if (!$list) {
            echo '<tr><td colspan="5">まだ登録されていません。下のフォームから、ZIPを登録してください。</td></tr>';
        }
        foreach ($list as $p) {
            echo '<tr><td><strong>' . esc_html($p['version']) . '</strong></td><td>' . esc_html(number_format($p['size'] / 1048576, 2)) . ' MB</td><td><code>' . esc_html(substr($p['sha256'], 0, 12)) . '</code></td><td>' . esc_html($p['uploaded_at']) . '</td><td>';
            if ($p['is_latest']) {
                echo '<strong style="color:#1a7f37">配布中の最新版</strong>';
            } else {
                self::form_open('mco_set_latest');
                echo '<input type="hidden" name="id" value="' . (int) $p['id'] . '"><button class="button button-small">この版を配布する</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px">';
        wp_nonce_field('mco_upload_product');
        echo '<input type="hidden" name="action" value="mco_upload_product">';
        echo '<p><input type="file" name="file" accept=".zip,application/zip" required> <button class="button button-primary">ZIPを登録</button></p>';
        echo '<p class="description">マッシュオフィスシステムのZIP（フォルダ名つき）を登録します。版は、ZIPの中のプラグイン情報（Version）から読み取ります。いちばん新しい版が、自動で配布されます。ZIPは、外から直接開けないフォルダに保存されます。</p></form>';
    }

    private static function section_issue() {
        echo '<h2>2. ライセンスを発行する</h2>';
        self::form_open('mco_add_license');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>お客様の名前<span style="color:#b32d2e"> *</span></th><td><input type="text" name="customer_name" class="regular-text" maxlength="190" required></td></tr>';
        echo '<tr><th>メールアドレス</th><td><input type="email" name="email" class="regular-text" maxlength="190"></td></tr>';
        echo '<tr><th>プランの表示名</th><td><input type="text" name="plan_label" class="regular-text" maxlength="100" placeholder="例：月額プラン"></td></tr>';
        echo '<tr><th>有効期限（任意）</th><td><input type="text" name="expires_at" placeholder="2027-03-31"> <span class="description">空欄なら、期限なし。過ぎると停止として扱います。</span></td></tr>';
        echo '<tr><th>メモ</th><td><textarea name="note" rows="2" class="large-text"></textarea></td></tr>';
        echo '</tbody></table><p><button class="button button-primary">ライセンスキーを発行</button></p></form>';
    }

    private static function section_licenses() {
        echo '<h2>3. ライセンス一覧</h2>';
        $rows = MCO_Licenses::all();
        echo '<table class="widefat striped"><thead><tr><th>発行日</th><th>お客様</th><th>プラン</th><th>ライセンスキー</th><th>状態</th><th>有効期限</th><th>最終確認（UTC）</th><th>回数</th><th>確認元</th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="9">まだ発行されていません。</td></tr>';
        }
        foreach ($rows as $r) {
            $eff = MCO_Licenses::effective_status($r);
            echo '<tr><td>' . esc_html(substr($r['created_at'], 0, 10)) . '</td><td>' . esc_html($r['customer_name']) . '<br><small>' . esc_html($r['email']) . '</small>' . ($r['note'] !== '' && $r['note'] !== null ? '<br><small style="color:#666">' . esc_html($r['note']) . '</small>' : '') . '</td>';
            echo '<td>' . esc_html($r['plan_label']) . '</td><td><input type="text" readonly value="' . esc_attr($r['license_key']) . '" onclick="this.select()" style="font-family:monospace;width:21em"></td><td>';
            self::form_open('mco_set_status');
            echo '<input type="hidden" name="id" value="' . (int) $r['id'] . '"><select name="status">';
            foreach (MCO_Licenses::STATUSES as $k => $label) {
                printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($r['status'], $k, false), esc_html($label));
            }
            echo '</select> <button class="button button-small">変更</button></form>';
            if ($eff === 'expired') {
                echo '<small style="color:#b32d2e">期限切れ</small>';
            }
            echo '</td><td>' . esc_html($r['expires_at'] ? substr($r['expires_at'], 0, 10) : '-') . '</td><td>' . esc_html($r['last_checked_at'] ? $r['last_checked_at'] : '-') . '</td><td>' . (int) $r['check_count'] . '</td><td><small>' . esc_html($r['last_check_agent']) . '</small></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function section_howto() {
        echo '<h2>4. お客様（マッシュオフィスシステム）側の設定</h2>';
        echo '<ol><li>お客様のWordPressに、マッシュオフィスシステムを入れて有効化します。</li><li>「設定 → マッシュオフィスシステム」の<strong>ライセンスキー</strong>に、上の表のキーを入れて保存します。</li><li>1日1回、次の窓口に確認します（「今すぐ確認」でも確認できます）。</li></ol>';
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        echo '<tr><th>ライセンスの確認</th><td><code>' . esc_html(rest_url(MCO_REST_NS . '/license-status')) . '</code></td></tr>';
        echo '<tr><th>更新の確認</th><td><code>' . esc_html(rest_url(MCO_REST_NS . '/update-check')) . '</code></td></tr>';
        echo '</tbody></table>';
        echo '<p class="description">この2つの窓口は、マッシュオフィスシステムの側に、すでに書かれています。「mush-office.com/wp-json/…」の形でつながるよう、WordPressのパーマリンクを「基本」以外にしておいてください。<strong>古い「決済・ライセンス管理」プラグインが有効なままだと、同じ窓口が重なります。先に無効化してください。</strong></p>';
    }

    private static function section_options() {
        echo '<h2>5. その他</h2>';
        self::form_open('mco_save_options');
        echo '<p><label><input type="checkbox" name="delete_on_uninstall" value="1"' . checked(get_option('mco_delete_on_uninstall') === '1', true, false) . '> このプラグインを<strong>削除したとき</strong>、ライセンスと配布ファイルも、すべて削除する</label><br><span class="description">チェックを外したままなら、プラグインを削除しても、ライセンスの記録は残ります（初期値）。</span></p>';
        echo '<p><button class="button">保存</button></p></form>';
    }

    /* ---------- 処理 ---------- */

    public static function add_license() {
        self::guard('mco_add_license');
        $r = MCO_Licenses::create(array(
            'customer_name' => self::post('customer_name'), 'email' => sanitize_email(self::post('email')),
            'plan_label' => self::post('plan_label', 100), 'expires_at' => self::post('expires_at', 19), 'note' => sanitize_textarea_field(isset($_POST['note']) ? wp_unslash($_POST['note']) : ''),
        ));
        if (is_wp_error($r)) {
            self::flash('error', $r->get_error_message());
        } else {
            self::flash('ok', 'ライセンスキーを発行しました：' . $r['license_key']);
        }
        self::back();
    }

    public static function set_status() {
        self::guard('mco_set_status');
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $ok = $id && MCO_Licenses::set_status($id, self::post('status', 20));
        self::flash($ok ? 'ok' : 'error', $ok ? '状態を変更しました。' : '状態を変更できませんでした。');
        self::back();
    }

    public static function upload_product() {
        self::guard('mco_upload_product');
        $f = isset($_FILES['file']) ? $_FILES['file'] : null;
        if (!$f || !isset($f['error']) || (int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            self::flash('error', 'ファイルをアップロードできませんでした（サイズの上限を超えている可能性があります）。');
            self::back();
        }
        $r = MCO_Products::add_from_path($f['tmp_name'], isset($f['name']) ? $f['name'] : '');
        self::flash(is_wp_error($r) ? 'error' : 'ok', is_wp_error($r) ? $r->get_error_message() : '版 ' . $r['version'] . ' を登録しました。');
        self::back();
    }

    public static function set_latest() {
        self::guard('mco_set_latest');
        $ok = MCO_Products::set_latest(isset($_POST['id']) ? (int) $_POST['id'] : 0);
        self::flash($ok ? 'ok' : 'error', $ok ? '配布する版を変更しました。' : '変更できませんでした。');
        self::back();
    }

    public static function save_options() {
        self::guard('mco_save_options');
        update_option('mco_delete_on_uninstall', !empty($_POST['delete_on_uninstall']) ? '1' : '0', false);
        self::flash('ok', '保存しました。');
        self::back();
    }
}
