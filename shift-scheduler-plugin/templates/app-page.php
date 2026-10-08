<?php if (!defined('ABSPATH')) { exit; } ?>
<section class="ss-card">
  <h1><?php echo esc_html($title); ?></h1>
  <div id="ss-notice" class="ss-alert" hidden></div>
  <?php if (empty($config['writable']) && (!isset($config['page']) || $config['page'] !== 'billing')) : ?>
    <p class="ss-alert ss-alert-error">現在は閲覧のみの状態のため、変更できません。</p>
  <?php endif; ?>
  <div id="ss-root"><p class="ss-muted">読み込み中…</p></div>
</section>
<script>window.SS_CONFIG = <?php echo wp_json_encode($config); ?>;</script>
