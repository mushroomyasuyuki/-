<?php if (!defined('ABSPATH')) { exit; } ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html($title . ' | ' . $site_name); ?></title>
<link rel="stylesheet" href="<?php echo esc_url(SS_URL . 'assets/app.css?ver=' . SS_VERSION); ?>">
</head>
<body class="ss">
<header class="ss-header">
  <a class="ss-brand" href="<?php echo esc_url(home_url('/')); ?>"><?php echo esc_html($site_name); ?></a>
  <?php if (!empty($nav)) : ?>
    <nav class="ss-nav">
      <?php foreach ($nav as $item) : ?>
        <a href="<?php echo esc_url($item['url']); ?>"<?php echo $item['current'] ? ' class="is-current"' : ''; ?>><?php echo esc_html($item['label']); ?></a>
      <?php endforeach; ?>
      <a href="<?php echo esc_url($logout); ?>">ログアウト</a>
    </nav>
  <?php endif; ?>
</header>
<main class="ss-main"><?php echo $content; // テンプレート内で個別にエスケープ済み ?></main>
<?php foreach ($scripts as $script_url) : ?>
<script src="<?php echo esc_url($script_url); ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
