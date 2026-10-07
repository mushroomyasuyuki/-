<?php if (!defined('ABSPATH')) { exit; } ?>
<section class="ss-card">
  <h1>スタッフ管理</h1>
  <div id="ss-notice" class="ss-alert" hidden></div>
  <?php if (!$config['writable']) : ?>
    <p class="ss-alert ss-alert-error">現在は閲覧のみの状態のため、変更できません。</p>
  <?php endif; ?>
  <p class="ss-sub">登録できるスタッフは <span id="ss-count">-</span> / <?php echo (int) $config['limit']; ?> 名です。</p>

  <form id="ss-add" class="ss-form ss-form-row"<?php echo $config['writable'] ? '' : ' hidden'; ?>>
    <label>名前<input type="text" name="name" maxlength="100" required></label>
    <label>よみがな<input type="text" name="kana" maxlength="100"></label>
    <label>雇用区分
      <select name="employment">
        <option value="full">正社員</option>
        <option value="part" selected>パート</option>
        <option value="baito">アルバイト</option>
      </select>
    </label>
    <label>役割（カンマ区切り）<input type="text" name="roles" placeholder="ホール, キッチン"></label>
    <label>資格（カンマ区切り）<input type="text" name="qualifications" placeholder="看護師, 介護福祉士"></label>
    <button type="submit" class="ss-btn">追加</button>
  </form>

  <div class="ss-table-wrap">
    <table class="ss-table">
      <thead><tr><th>名前</th><th>雇用区分</th><th>役割</th><th>資格</th><th>ログイン</th><th></th></tr></thead>
      <tbody id="ss-rows"><tr><td colspan="6">読み込み中…</td></tr></tbody>
    </table>
  </div>
</section>
<script>window.SS_CONFIG = <?php echo wp_json_encode($config); ?>;</script>
