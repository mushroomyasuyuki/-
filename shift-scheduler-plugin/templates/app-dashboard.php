<?php if (!defined('ABSPATH')) { exit; } ?>
<section class="ss-card">
  <h1><?php echo esc_html($tenant['name']); ?></h1>
  <dl class="ss-facts">
    <dt>ご契約の状態</dt>
    <dd><?php echo esc_html(SS_Tenants::status_label($tenant_status)); ?>
      <?php if ($days_left !== null && $tenant_status === 'trial') : ?>（無料期間はあと<?php echo (int) $days_left; ?>日）<?php endif; ?></dd>
    <dt>スタッフ数</dt>
    <dd><?php echo (int) $staff_count; ?> / <?php echo (int) $staff_limit; ?> 名</dd>
  </dl>
  <?php if ($tenant_status === 'readonly') : ?>
    <p class="ss-alert ss-alert-error">無料期間が終了したため、閲覧のみの状態です。ご契約いただくと、編集を再開できます。</p>
  <?php endif; ?>
  <?php if (current_user_can('shift_manage_schedule')) : ?>
    <h2 class="ss-h2">はじめに</h2>
    <ol class="ss-steps">
      <li><a href="<?php echo esc_url(SS_View::app_url($tenant['public_id'], 'staff')); ?>">スタッフを登録する</a></li>
      <li><a href="<?php echo esc_url(SS_View::app_url($tenant['public_id'], 'patterns')); ?>">勤務区分を確認する</a></li>
      <li><a href="<?php echo esc_url(SS_View::app_url($tenant['public_id'], 'rules')); ?>">ルール（必要人数など）を設定する</a></li>
      <li><a href="<?php echo esc_url(SS_View::app_url($tenant['public_id'], 'requests')); ?>">スタッフから希望休を集める</a></li>
    </ol>
  <?php endif; ?>
  <?php if (!empty($me['staff_id']) && current_user_can('shift_submit_requests')) : ?>
    <p><a class="ss-btn" href="<?php echo esc_url(SS_View::app_url($tenant['public_id'], 'me/requests')); ?>">希望休を提出する</a></p>
  <?php endif; ?>
  <p class="ss-sub">シフト表の作成機能は順次追加されます。</p>
</section>
