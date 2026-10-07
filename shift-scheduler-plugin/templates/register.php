<?php if (!defined('ABSPATH')) { exit; }
$terms_url = get_option('ss_terms_url', ''); ?>
<section class="ss-card ss-narrow">
  <h1>無料登録</h1>
  <p class="ss-lead">登録から<?php echo (int) SS_TRIAL_MONTHS; ?>か月は無料です。カードの登録は不要です。</p>
  <?php if (!empty($err)) : ?><p class="ss-alert ss-alert-error"><?php echo esc_html($err); ?></p><?php endif; ?>
  <?php if (!empty($msg)) : ?><p class="ss-alert ss-alert-ok"><?php echo esc_html($msg); ?></p><?php endif; ?>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ss-form">
    <input type="hidden" name="action" value="ss_register">
    <input type="hidden" name="_ss_nonce" value="<?php echo esc_attr(wp_create_nonce('ss_register')); ?>">
    <label>事業所名・店舗名<input type="text" name="tenant_name" maxlength="190" required></label>
    <fieldset>
      <legend>業種</legend>
      <label class="ss-inline"><input type="radio" name="industry" value="restaurant" required> 飲食</label>
      <label class="ss-inline"><input type="radio" name="industry" value="care"> 介護</label>
      <label class="ss-inline"><input type="radio" name="industry" value="other"> その他</label>
    </fieldset>
    <label>ご担当者のお名前<input type="text" name="person_name" maxlength="100" required></label>
    <label>メールアドレス<input type="email" name="email" maxlength="190" autocomplete="email" required></label>
    <label>パスワード（8文字以上）<input type="password" name="password" minlength="8" autocomplete="new-password" required></label>
    <div class="ss-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    <label class="ss-inline"><input type="checkbox" name="agree" value="1" required>
      <?php if ($terms_url) : ?><a href="<?php echo esc_url($terms_url); ?>" target="_blank" rel="noopener">利用規約</a><?php else : ?>利用規約<?php endif; ?>に同意します</label>
    <button type="submit" class="ss-btn">無料で登録する</button>
  </form>
  <p class="ss-sub">すでに登録済みの方は <a href="<?php echo esc_url(SS_Router::url('login')); ?>">ログイン</a></p>
</section>
