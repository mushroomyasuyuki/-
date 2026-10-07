<?php if (!defined('ABSPATH')) { exit; } ?>
<section class="ss-card">
  <h1><?php echo esc_html($tenant['name']); ?></h1>
  <dl class="ss-facts">
    <dt>ご契約の状態</dt>
    <dd><?php echo esc_html(SS_Tenants::status_label($status)); ?>
      <?php if ($days_left !== null && $status === 'trial') : ?>（無料期間はあと<?php echo (int) $days_left; ?>日）<?php endif; ?></dd>
    <dt>スタッフ数</dt>
    <dd><?php echo (int) $staff_count; ?> / <?php echo (int) $staff_limit; ?> 名</dd>
  </dl>
  <?php if ($status === 'readonly') : ?>
    <p class="ss-alert ss-alert-error">無料期間が終了したため、閲覧のみの状態です。ご契約いただくと、編集を再開できます。</p>
  <?php endif; ?>
  <?php if (current_user_can('shift_manage_staff')) : ?>
    <p><a class="ss-btn" href="<?php echo esc_url(SS_View::app_url($tenant['public_id'], 'staff')); ?>">スタッフを登録する</a></p>
  <?php endif; ?>
  <p class="ss-sub">シフト表の作成機能は順次追加されます。</p>
</section>
