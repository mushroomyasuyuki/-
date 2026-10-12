<?php
/**
 * お客様（会社）のトップページ。マッシュオフィスシステムの「業務システム」トップと同じ見た目のカード。
 * 従業員ページは全員、管理者ページは管理者（副管理者を含む）にだけ出す（ログインで判定するので、パスワードの入力は不要）。
 * まだ移していない機能は「準備中」として、押せないカードで出す。
 */
if (!defined('ABSPATH')) { exit; }
$card = function ($c) {
    $cls = 'nav-card c-' . $c['color'] . (empty($c['url']) ? ' is-soon' : '');
    $inner = '<span class="icon" aria-hidden="true">' . $c['icon'] . '</span><div class="label">' . esc_html($c['label']) . (empty($c['url']) ? '<span class="soon">準備中</span>' : '') . '</div><div class="desc">' . esc_html($c['desc']) . '</div>';
    if (empty($c['url'])) {
        return '<div class="' . esc_attr($cls) . '" aria-disabled="true">' . $inner . '</div>';
    }
    return '<a class="' . esc_attr($cls) . '" href="' . esc_url($c['url']) . '">' . $inner . '</a>';
};
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html($tenant['name'] . ' | ' . SS_PRODUCT_NAME); ?></title>
<style>
  :root{--navy:#1E2A4A;--paper:#F7F6F3;--card:#FFFFFF;--ink:#20242E;--ink-soft:#6B7280;--line:#E4E2DC;--radius:12px}
  *{box-sizing:border-box}
  body{margin:0;font-family:"Hiragino Kaku Gothic ProN","Noto Sans JP","Yu Gothic",sans-serif;background:var(--paper);color:var(--ink)}
  .topbar{background:var(--navy);color:#fff;padding:28px 24px 24px;text-align:center;position:relative}
  .topbar .title{font-size:19px;font-weight:600;margin:0;letter-spacing:.02em}
  .topbar .sub{font-size:12px;color:#B7C0D8;margin-top:6px}
  .topbar .who{position:absolute;top:10px;right:14px;font-size:12px;color:#B7C0D8}
  .topbar .who a{color:#fff;margin-left:10px}
  .container{max-width:640px;margin:0 auto;padding:26px 20px 60px}
  .notice{background:#fff;border:1px solid var(--line);border-left:4px solid #b45309;border-radius:8px;padding:12px 14px;font-size:13px;line-height:1.6;margin:0 0 22px}
  .notice.is-error{border-left-color:#b91c1c;background:#fdf2f2}
  .notice a{color:var(--navy);font-weight:700}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  @media (max-width:480px){.grid{grid-template-columns:1fr}.topbar .who{position:static;margin-bottom:8px}}
  .nav-card{display:block;background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:22px 18px;text-decoration:none;color:var(--ink);border-top:4px solid var(--navy);transition:transform .08s ease,box-shadow .08s ease}
  a.nav-card:hover{box-shadow:0 4px 14px rgba(30,42,74,.10);transform:translateY(-1px)}
  .nav-card .icon{font-size:22px;margin-bottom:10px;display:block}
  .nav-card .label{font-size:15px;font-weight:700;color:var(--navy);margin-bottom:4px}
  .nav-card .desc{font-size:12px;color:var(--ink-soft);line-height:1.5}
  .nav-card.is-soon{opacity:.55;cursor:default}
  .nav-card .soon{display:inline-block;margin-left:8px;padding:1px 7px;border-radius:999px;background:#eef0f4;color:#6B7280;font-size:11px;font-weight:600;vertical-align:2px}
  .c-nippo{border-top-color:#3B5BA5}.c-chorei{border-top-color:#3F8F6B}.c-keiri{border-top-color:#A13D4C}.c-timecard{border-top-color:#b45309}
  .c-myleave{border-top-color:#0d9488}.c-shift{border-top-color:#2563eb}.c-touroku{border-top-color:#C08A1E}.c-shukei{border-top-color:#0f766e}
  .c-kyuyo{border-top-color:#15803d}.c-qr{border-top-color:#6b7280}.c-jisha{border-top-color:#7c3aed}.c-billing{border-top-color:#1E2A4A}.c-settings{border-top-color:#94a3b8}
  .section-title{font-size:13px;font-weight:700;color:var(--ink-soft);margin:0 0 12px;padding-bottom:6px;border-bottom:1px solid var(--line)}
  .section+.section{margin-top:28px}
  .footer-note{text-align:center;font-size:11px;color:#9AA0AC;margin-top:26px}
</style>
</head>
<body>
<div class="topbar">
  <div class="who"><?php echo esc_html($me_name); ?> さん<a href="<?php echo esc_url($logout); ?>">ログアウト</a></div>
  <div class="title"><?php echo esc_html($tenant['name']); ?></div>
  <div class="sub">日報 / 朝礼 / シフト / 勤怠</div>
</div>
<div class="container">
  <?php foreach ($notices as $n) : ?>
    <p class="notice<?php echo $n['error'] ? ' is-error' : ''; ?>"><?php echo esc_html($n['text']); ?><?php if (!empty($n['url'])) : ?> <a href="<?php echo esc_url($n['url']); ?>"><?php echo esc_html($n['link']); ?></a><?php endif; ?></p>
  <?php endforeach; ?>

  <div class="section">
    <div class="section-title">従業員ページ</div>
    <div class="grid"><?php foreach ($staff_cards as $c) { echo $card($c); } // カードの中身は、上の関数でエスケープ済み ?></div>
  </div>

  <?php if ($admin_cards) : ?>
  <div class="section">
    <div class="section-title">管理者ページ</div>
    <div class="grid"><?php foreach ($admin_cards as $c) { echo $card($c); } ?></div>
  </div>
  <?php endif; ?>

  <div class="footer-note"><?php echo esc_html(SS_PRODUCT_NAME); ?></div>
</div>
</body>
</html>
