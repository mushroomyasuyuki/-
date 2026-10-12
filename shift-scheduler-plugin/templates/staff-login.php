<?php if (!defined('ABSPATH')) { exit; } ?>
<section class="ss-card ss-narrow">
  <h1>スタッフのログイン</h1>
  <?php if ($tenant_name !== '') : ?><p class="ss-lead"><?php echo esc_html($tenant_name); ?></p><?php endif; ?>
  <?php if (!empty($err)) : ?><p class="ss-alert ss-alert-error"><?php echo esc_html($err); ?></p><?php endif; ?>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ss-form">
    <input type="hidden" name="action" value="ss_staff_login">
    <input type="hidden" name="_ss_nonce" value="<?php echo esc_attr(wp_create_nonce('ss_staff_login')); ?>">
    <input type="hidden" name="tenant" value="<?php echo esc_attr($public_id); ?>">
    <label>社員番号<input type="text" name="employee_number" autocomplete="username" inputmode="text" maxlength="20" required></label>
    <label>パスワード<input type="password" name="password" autocomplete="current-password" required></label>
    <button type="submit" class="ss-btn">ログイン</button>
  </form>
  <p class="ss-sub">パスワードを忘れた場合は、職場の管理者に再設定を依頼してください。<br>管理者の方は、<a href="<?php echo esc_url(SS_Router::url('login')); ?>">メールアドレスでログイン</a>してください。</p>
</section>
