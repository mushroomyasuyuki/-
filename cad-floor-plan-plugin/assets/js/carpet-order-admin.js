/**
 * 特許ページ（管理者だけ）：新ページからの注文（?cfp_order=番号）を読み込み、3通りに変換して、お客様へプレビューを送る。
 * 変換は画面の「① 標準色のみ／② 標準色＋オプション3色／③ 最大表現」をそのまま使う（印象・守る色・階調表現を変えてから送れる）。
 */
(function () {
  'use strict';
  const cfg = window.cfpCarpetOrderAdmin;
  if (!cfg) return;
  const KEYS = ['std', 'op3', 'max'];
  const LABELS = { std: 'A案：標準色のみ', op3: 'B案：標準色＋オプション3色', max: 'C案：最大表現' };

  function el(tag, attrs, text) {
    const e = document.createElement(tag);
    Object.keys(attrs || {}).forEach((k) => e.setAttribute(k, attrs[k]));
    if (text) e.textContent = text;
    return e;
  }

  const box = el('div', { id: 'cfp-co-admin', style: 'background:#1c1917;border:2px solid #f39c12;border-radius:10px;padding:16px;margin:0 0 20px;color:#f4f4f5;font-size:14px;line-height:1.7' });
  const title = el('div', { style: 'font-size:17px;font-weight:700;color:#fbbf24;margin-bottom:6px' }, '📦 注文 ' + cfg.no + '（' + cfg.name + ' 様）・' + cfg.status);
  const info = el('div', { style: 'white-space:pre-line;color:#e4e4e7' });
  const thumbs = el('div', { style: 'display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:10px 0' });
  const msg = el('textarea', { rows: '3', placeholder: 'お客様へのひとこと（任意。メールの先頭に入ります）', style: 'width:100%;background:#09090b;color:#fff;border:1px solid #52525b;border-radius:6px;padding:8px' });
  const leadWrap = el('label', { style: 'display:block;margin:8px 0 4px;color:#e4e4e7' }, '納期（お支払い確定後）：');
  const lead = el('input', { type: 'text', value: '約1週間', maxlength: '60', placeholder: '例：約1週間（12月10日ごろ発送）', style: 'width:100%;max-width:420px;background:#09090b;color:#fff;border:1px solid #52525b;border-radius:6px;padding:6px 8px;margin-top:4px' });
  leadWrap.appendChild(lead);
  const send = el('button', { type: 'button', class: 'btn-submit', disabled: '' }, '変換が終わるまでお待ちください…');
  const st = el('div', { style: 'margin-top:8px;white-space:pre-line;color:#fde68a' });
  const links = el('div', { style: 'margin-top:6px;font-size:12px' });
  const back = el('a', { href: cfg.list_url, target: '_blank', rel: 'noopener', style: 'color:#93c5fd' }, '注文の一覧（管理画面）');
  links.appendChild(back);
  [title, info, thumbs, msg, leadWrap, send, st, links].forEach((c) => box.appendChild(c));

  const lines = ['仕様：' + (cfg.spec.material === 'cut' ? 'カットパイル' : 'ループパイル') + '・' + cfg.spec.width + '×' + cfg.spec.height + 'mm', '元画像：' + cfg.original_name];
  if (cfg.memo) lines.push('お客様のご要望：' + cfg.memo);
  if (cfg.reject_comment) lines.push('⚠ 前回のプレビューへのお客様のコメント（作り直し）：' + cfg.reject_comment);
  lines.push('3通りの変換を確認し、必要なら下の「仕上がりの印象」「守る色」「階調表現」を変えてから、「お客様へプレビューを送る」を押してください。');
  info.textContent = lines.join('\n');

  function place() {
    const anchor = document.getElementById('tool-section') || document.getElementById('cocoonCarpetTool');
    if (!anchor) return false;
    anchor.parentNode.insertBefore(box, anchor.nextSibling);
    return true;
  }

  function wait(test, ms) {
    return new Promise((resolve, reject) => {
      const t0 = Date.now();
      (function tick() {
        const v = test();
        if (v) { resolve(v); return; }
        if (Date.now() - t0 > ms) { reject(new Error('時間内に準備できませんでした。ページを再読み込みしてください。')); return; }
        setTimeout(tick, 250);
      })();
    });
  }

  function refreshThumbs() {
    const api = window.cfpCarpetOrder;
    const vs = api ? api.variants() : null;
    thumbs.innerHTML = '';
    if (!vs) return null;
    KEYS.forEach((k) => {
      const c = el('div', { style: 'background:#09090b;border:1px solid #3f3f46;border-radius:6px;padding:6px;text-align:center;font-size:12px' });
      const im = el('img', { alt: LABELS[k], style: 'max-width:100%;height:auto;background:#fff;image-rendering:pixelated' });
      im.src = vs[k].canvas.toDataURL('image/png');
      c.appendChild(el('div', { style: 'font-weight:700;color:#fff' }, LABELS[k]));
      c.appendChild(im);
      c.appendChild(el('div', {}, vs[k].colors.length + '色：' + vs[k].colors.join('・')));
      thumbs.appendChild(c);
    });
    return vs;
  }

  async function load() {
    if (!place()) return;
    try {
      const api = await wait(() => window.cfpCarpetOrder, 15000);
      st.textContent = '注文の画像を読み込んでいます…';
      const r = await fetch(cfg.original, { credentials: 'same-origin' });
      if (!r.ok) throw new Error('元画像を読み込めませんでした（HTTP ' + r.status + '）。');
      const blob = await r.blob();
      const file = new File([blob], cfg.original_name || 'original', { type: blob.type });
      api.setSpec(cfg.spec);
      await new Promise((resolve) => api.loadFile(file, resolve));
      st.textContent = '変換しています…';
      await wait(() => api.variants(), 60000);
      await new Promise((r2) => setTimeout(r2, 400));
      refreshThumbs();
      st.textContent = cfg.sent ? '※この注文には、以前にプレビューを送っています。送ると、新しいプレビューで送り直します。' : '';
      send.disabled = false;
      send.textContent = 'お客様へプレビューを送る（3案・メールに画像を添付）';
      // 変換の設定を変えたら、送る前の確認画像も新しくする
      document.addEventListener('change', () => setTimeout(refreshThumbs, 600));
      document.addEventListener('click', () => setTimeout(refreshThumbs, 600));
    } catch (e) {
      st.textContent = '❌ ' + e.message;
    }
  }

  send.onclick = async () => {
    const vs = refreshThumbs();
    if (!vs) { st.textContent = '❌ 変換がまだできていません。'; return; }
    if (!confirm(cfg.name + ' 様へ、3案のプレビューをメールで送ります。よろしいですか？')) return;
    send.disabled = true;
    st.textContent = '送信しています…';
    const fd = new FormData();
    fd.append('action', 'cfp_co_send_previews');
    fd.append('nonce', cfg.nonce);
    fd.append('id', String(cfg.id));
    fd.append('message', msg.value);
    fd.append('lead', lead.value.trim());
    const d = document.getElementById('cc-dither');
    fd.append('dither', d ? d.value : '0');
    KEYS.forEach((k) => {
      fd.append('png_' + k, vs[k].canvas.toDataURL('image/png'));
      fd.append('colors_' + k, JSON.stringify(vs[k].colors));
    });
    try {
      const r = await fetch(cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await r.json();
      if (!j.success) throw new Error((j.data ? j.data.message : '') || '送れませんでした。');
      st.textContent = (j.data.sent ? '✅ ' + j.data.to + ' へプレビューを送りました。' : '⚠ メールの送信に失敗した可能性があります（サーバーのメール設定を確認してください）。') + '\nお客様の選択ページ：' + j.data.select_url;
      send.textContent = '送り直す';
    } catch (e) {
      st.textContent = '❌ ' + e.message;
    }
    send.disabled = false;
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
