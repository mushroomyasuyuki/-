<?php if (!defined('ABSPATH')) { exit; } ?>
<section class="ss-card ss-narrow">
  <h1>登録の確認</h1>
  <p>下のボタンを押すと、登録が完了します。</p>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ss-form">
    <input type="hidden" name="action" value="ss_verify">
    <input type="hidden" name="_ss_nonce" value="<?php echo esc_attr(wp_create_nonce('ss_verify')); ?>">
    <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
    <button type="submit" class="ss-btn">登録を完了する</button>
  </form>
</section>
