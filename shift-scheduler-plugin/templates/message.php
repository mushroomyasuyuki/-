<?php if (!defined('ABSPATH')) { exit; } ?>
<section class="ss-card ss-narrow">
  <h1><?php echo esc_html($title); ?></h1>
  <p><?php echo esc_html($text); ?></p>
  <?php if (!empty($link_url)) : ?>
    <p><a class="ss-btn" href="<?php echo esc_url($link_url); ?>"><?php echo esc_html($link_label); ?></a></p>
  <?php endif; ?>
</section>
