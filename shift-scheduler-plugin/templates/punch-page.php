<?php
/**
 * QRコードから開くタイムカード（ログイン不要）。会社名と打刻画面だけを出す。
 * 打刻できるのは、URLの署名で確かめた、そのスタッフの分だけ。
 */
if (!defined('ABSPATH')) { exit; }
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<title><?php echo esc_html($title . ' | ' . $tenant_name); ?></title>
<style>
body{margin:0;background:#f0efe9;padding:0 0 24px}
#mo-company{max-width:480px;margin:0 auto;padding:12px 16px;font:13px/1.5 -apple-system,BlinkMacSystemFont,"Hiragino Sans","Yu Gothic",sans-serif;color:#6b7280;text-align:center}
</style>
</head>
<body>
<div id="mo-company"><?php echo esc_html($tenant_name); ?></div>
<script>window.MO_PUNCH = <?php echo wp_json_encode($punch); ?>;</script>
<?php echo $office_html; // 同梱の打刻画面（固定ファイル） ?>
</body>
</html>
