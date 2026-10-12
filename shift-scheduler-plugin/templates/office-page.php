<?php
/**
 * マッシュオフィスシステムの画面を、そのまま全幅で表示するページ。
 * 上に、このサービスの小さなメニュー（戻る・ログアウト）だけを付ける。
 * 画面のスタイルとぶつからないよう、このページでは、サービス共通のスタイル（app.css）は読み込まない。
 */
if (!defined('ABSPATH')) { exit; }
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html($title . ' | ' . $tenant_name); ?></title>
<style>
#mo-bar{position:sticky;top:0;z-index:9999;display:flex;flex-wrap:wrap;gap:4px 14px;align-items:center;padding:8px 16px;background:#1e2a4a;color:#fff;font:14px/1.5 system-ui,-apple-system,"Hiragino Sans","Noto Sans JP",sans-serif}
#mo-bar a{color:#fff;text-decoration:none;opacity:.85}
#mo-bar a:hover,#mo-bar a.is-current{opacity:1;text-decoration:underline}
#mo-bar .mo-brand{font-weight:700;margin-right:auto;opacity:1}
#mo-notice{margin:0;padding:10px 16px;background:#fdecea;color:#8a1c12;font:14px/1.5 system-ui,sans-serif}
#mo-notice[hidden]{display:none}
/* まだ移していない機能・このサービスでは使わない操作は、表示しない */
.chat-panel,.backup-panel,button[onclick*="__nippo.logout"]{display:none!important}
</style>
</head>
<body>
<nav id="mo-bar" aria-label="メニュー">
  <a class="mo-brand" href="<?php echo esc_url($home_url); ?>"><?php echo esc_html($tenant_name); ?></a>
  <?php foreach ($menu as $item) : ?>
    <a href="<?php echo esc_url($item['url']); ?>"<?php echo $item['current'] ? ' class="is-current" aria-current="page"' : ''; ?>><?php echo esc_html($item['label']); ?></a>
  <?php endforeach; ?>
  <a href="<?php echo esc_url($logout); ?>">ログアウト</a>
</nav>
<?php
$mo_msg = $readonly ? '現在は閲覧のみの状態のため、変更できません。' : '';
if ($mo_msg === '' && !empty($need_staff_link)) {
    $mo_msg = 'この画面で日報を入力するには、ご自身をスタッフ名簿に登録して、アカウントと紐づけてください（スタッフ管理で、ご自身の行の「メールで招待」に、ご自身のメールアドレスを入れると紐づきます）。';
}
?>
<p id="mo-notice" role="alert"<?php echo $mo_msg !== '' ? '' : ' hidden'; ?>><?php echo esc_html($mo_msg); ?></p>
<script>window.MO_CONFIG = <?php echo wp_json_encode($mo_config); ?>;</script>
<script src="<?php echo esc_url(SS_URL . 'assets/office-shim.js?ver=' . SS_VERSION); ?>"></script>
<?php foreach ((isset($scripts) ? $scripts : array()) as $src) : ?>
<script src="<?php echo esc_url($src); ?>"></script>
<?php endforeach; ?>
<?php echo $office_html; // マッシュオフィスシステムの画面（同梱の固定ファイル） ?>
</body>
</html>
