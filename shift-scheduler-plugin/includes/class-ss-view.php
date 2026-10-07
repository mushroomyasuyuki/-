<?php
/** 画面の描画（テーマに依存しない、独立したHTMLレイアウト）。 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_View {
    public static function render($ss_template, array $vars = array(), $ss_http_status = 200) {
        status_header($ss_http_status);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        header('Content-Type: text/html; charset=utf-8');
        $vars['site_name'] = get_bloginfo('name');
        $ss_file = SS_DIR . 'templates/' . $ss_template . '.php';
        extract($vars, EXTR_SKIP);
        ob_start();
        include $ss_file;
        $content = ob_get_clean();
        $title = isset($vars['title']) ? $vars['title'] : 'シフト作成';
        $scripts = isset($vars['scripts']) ? $vars['scripts'] : '';
        include SS_DIR . 'templates/layout.php';
        exit;
    }

    public static function message($title, $text, $http_status = 200, $link_url = '', $link_label = '') {
        self::render('message', compact('title', 'text', 'link_url', 'link_label'), $http_status);
    }

    public static function error_text($code) {
        $map = array(
            'invalid'      => '入力内容を確認してください。',
            'terms'        => '利用規約への同意が必要です。',
            'password'     => 'パスワードは8文字以上で入力してください。',
            'email'        => 'メールアドレスの形式が正しくありません。',
            'email_used'   => 'このメールアドレスでは登録できません。すでに登録済みの場合はログインしてください。',
            'rate'         => '短時間に操作が集中しました。しばらくしてからお試しください。',
            'failed'       => '処理に失敗しました。時間をおいて再度お試しください。',
            'login'        => 'メールアドレスまたはパスワードが正しくありません。',
            'pending'      => 'メール認証が完了していません。届いたメールのリンクから認証してください。',
            'disabled'     => 'このアカウントは利用できません。',
            'verify_sent'  => '確認メールを送信しました。メールのリンクから登録を完了してください。',
        );
        return isset($map[$code]) ? $map[$code] : '';
    }

    public static function app_url($public_id, $path = '') {
        return SS_Router::url('app', $public_id, $path);
    }
}
