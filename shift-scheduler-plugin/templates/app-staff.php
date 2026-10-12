<?php if (!defined('ABSPATH')) { exit; } ?>
<section class="ss-card">
  <h1>スタッフ管理</h1>
  <div id="ss-notice" class="ss-alert" hidden></div>
  <?php if (!$config['writable']) : ?>
    <p class="ss-alert ss-alert-error">現在は閲覧のみの状態のため、変更できません。</p>
  <?php endif; ?>
  <p class="ss-sub">登録できるスタッフは <span id="ss-count">-</span> / <?php echo (int) $config['limit']; ?> 名です。</p>

  <div class="ss-login-url">
    <strong>スタッフ用のログインページ</strong>（社員番号＋パスワード）：
    <input type="text" id="ss-login-url" readonly value="<?php echo esc_attr($config['staff_login_url']); ?>">
    <button type="button" class="ss-btn ss-btn-sub" id="ss-copy-url">コピー</button>
    <span class="ss-sub">このURLを、スタッフに伝えてください。会社ごとに専用のURLです。</span>
  </div>

  <form id="ss-add" class="ss-form ss-form-row"<?php echo $config['writable'] ? '' : ' hidden'; ?>>
    <label>社員番号<input type="text" name="employee_number" maxlength="20" placeholder="101"></label>
    <label>名前<input type="text" name="name" maxlength="100" required></label>
    <label>よみがな<input type="text" name="kana" maxlength="100"></label>
    <label>部署<input type="text" name="department" maxlength="50" placeholder="ホール"></label>
    <label>雇用区分
      <select name="employment">
        <option value="full">正社員</option>
        <option value="part" selected>パート</option>
        <option value="baito">アルバイト</option>
      </select>
    </label>
    <label>役割（カンマ区切り）<input type="text" name="roles" placeholder="ホール, キッチン"></label>
    <label>資格（カンマ区切り）<input type="text" name="qualifications" placeholder="看護師, 介護福祉士"></label>
    <button type="submit" class="ss-btn" id="ss-submit">追加</button>
    <button type="button" class="ss-btn ss-btn-sub" id="ss-cancel" hidden>キャンセル</button>
  </form>

  <div class="ss-table-wrap">
    <table class="ss-table">
      <thead><tr><th>社員番号</th><th>名前</th><th>部署</th><th>雇用区分</th><th>役割</th><th>資格</th><th>ログイン</th><th></th></tr></thead>
      <tbody id="ss-rows"><tr><td colspan="8">読み込み中…</td></tr></tbody>
    </table>
  </div>
</section>
<script>window.SS_CONFIG = <?php echo wp_json_encode($config); ?>;</script>
