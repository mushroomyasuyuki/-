<?php if (!defined('ABSPATH')) { exit; } ?>
<section class="ss-card ss-narrow">
  <h1>スタッフ登録</h1>
  <p>「<?php echo esc_html($tenant_name); ?>」に招待されています。<br>ログインに使うメールアドレス：<strong><?php echo esc_html($email); ?></strong></p>
  <?php if (!empty($err)) : ?><p class="ss-alert ss-alert-error"><?php echo esc_html($err); ?></p><?php endif; ?>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ss-form">
    <input type="hidden" name="action" value="ss_accept_invite">
    <input type="hidden" name="_ss_nonce" value="<?php echo esc_attr(wp_create_nonce('ss_accept_invite')); ?>">
    <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
    <label>パスワードを決めてください（8文字以上）<input type="password" name="password" minlength="8" autocomplete="new-password" required></label>
    <button type="submit" class="ss-btn">登録してはじめる</button>
  </form>
</section>
