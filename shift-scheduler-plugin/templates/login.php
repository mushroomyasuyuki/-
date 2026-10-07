<?php if (!defined('ABSPATH')) { exit; } ?>
<section class="ss-card ss-narrow">
  <h1>ログイン</h1>
  <?php if (!empty($err)) : ?><p class="ss-alert ss-alert-error"><?php echo esc_html($err); ?></p><?php endif; ?>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ss-form">
    <input type="hidden" name="action" value="ss_login">
    <input type="hidden" name="_ss_nonce" value="<?php echo esc_attr(wp_create_nonce('ss_login')); ?>">
    <label>メールアドレス<input type="email" name="email" autocomplete="username" required></label>
    <label>パスワード<input type="password" name="password" autocomplete="current-password" required></label>
    <button type="submit" class="ss-btn">ログイン</button>
  </form>
  <p class="ss-sub"><a href="<?php echo esc_url(wp_lostpassword_url(SS_Router::url('login'))); ?>">パスワードを忘れた方</a> ／ <a href="<?php echo esc_url(SS_Router::url('register')); ?>">無料登録</a></p>
</section>
