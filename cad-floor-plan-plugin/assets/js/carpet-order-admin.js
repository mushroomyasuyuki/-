/**
 * 特許ページ（管理者だけ）：新ページからの注文（?cfp_order=番号）を読み込み、3通りに変換して、お客様へプレビューを送る。
 * 案ごとに、自動の変換（① 標準色のみ／② 標準色＋オプション3色／③ 最大表現）か、パレットで手動で選んだ色かを選べる。
 * 階調表現（誤差拡散）も案ごとに決められる（各案のスライダーを動かすまでは、画面の階調表現と同じ値）。
 */
(function () {
  'use strict';
  const cfg = window.cfpCarpetOrderAdmin;
  if (!cfg) return;
  const KEYS = ['std', 'op3', 'max'];
  const LABELS = { std: 'A案：標準色のみ', op3: 'B案：標準色＋オプション3色', max: 'C案：最大表現' };
  const HEADS = { std: 'A案', op3: 'B案', max: 'C案' };
  // 案ごとの設定：manual = 手動で選んだ色（indices）を使う、dither = 階調表現、touched = 案のスライダーを動かした
  const cases = {};
  KEYS.forEach((k) => { cases[k] = { manual: false, indices: null, dither: 0, touched: false }; });
  let rendered = null;

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
  const leadWrap = el('label', { style: 'display:block;margin:8px 0 4px;color:#e4e4e7' }, '納期・出荷日（お支払い確定後）：');
  const lead = el('input', { type: 'text', value: '約2週間', maxlength: '60', placeholder: '例：約2週間（12月10日ごろ発送）', style: 'width:100%;max-width:420px;background:#09090b;color:#fff;border:1px solid #52525b;border-radius:6px;padding:6px 8px;margin-top:4px' });
  leadWrap.appendChild(lead);
  const send = el('button', { type: 'button', class: 'btn-submit', disabled: '' }, '変換が終わるまでお待ちください…');
  const st = el('div', { style: 'margin-top:8px;white-space:pre-line;color:#fde68a' });
  const links = el('div', { style: 'margin-top:6px;font-size:12px' });
  const back = el('a', { href: cfg.list_url, target: '_blank', rel: 'noopener', style: 'color:#93c5fd' }, '注文の一覧（管理画面）');
  links.appendChild(back);
  [title, info, thumbs, msg, leadWrap, send, st, links].forEach((c) => box.appendChild(c));

  // 工場の納期回答：枠に表示し、納期の欄に入れる
  const fr = cfg.factory_reply;
  if (fr ? fr.lead : false) lead.value = fr.lead;
  const lines = ['仕様：' + (cfg.spec.material === 'cut' ? 'カットパイル' : 'ループパイル') + '・' + cfg.spec.width + '×' + cfg.spec.height + 'mm', '元画像：' + cfg.original_name];
  if (cfg.memo) lines.push('お客様のご要望：' + cfg.memo);
  if (fr) {
    lines.push('🏭 工場の納期回答（' + fr.at + (fr.name ? '・' + fr.name : '') + '）：' + (fr.days ? '製作 ' + fr.days + '日' : '') + (fr.days && fr.date ? '・' : '') + (fr.date ? '出荷できる日 ' + fr.date : '') + (fr.comment ? '\n　コメント：' + fr.comment : '') + '\n　→ 下の「納期・出荷日」に入れました（変えられます）。');
  } else {
    lines.push('🏭 工場の納期回答：まだありません（回答があると、ここと「納期・出荷日」に入ります）。');
  }
  if (cfg.reject_comment) lines.push('⚠ 前回のプレビューへのお客様のコメント（作り直し）：' + cfg.reject_comment);
  lines.push('3案を確認してから「お客様へプレビューを送る」を押してください。');
  lines.push('・色を自分で選ぶ：各案の下のカラーパレットで色を押して入れる・外す（その案は手動で選んだ色になる。「自動に戻す」で元に戻る）。');
  lines.push('・階調表現：案ごとのスライダーで決める（動かすまでは画面の階調表現と同じ）。「仕上がりの印象」「守る色」は自動の案に反映されます。');
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

  const btnStyle = 'margin:6px 4px 0 0;padding:4px 10px;font-size:12px;border-radius:5px;border:1px solid #71717a;background:#27272a;color:#fff;cursor:pointer';

  // 手動で選んだ色の案の名前（お客様に見える）：色に合わせて「標準色のみ／標準色＋オプションn色」
  function caseLabel(k, used) {
    if (!cases[k].manual) return LABELS[k];
    const op = used.filter((n) => /^OP-/.test(n)).length;
    return HEADS[k] + '：' + (op ? '標準色＋オプション' + op + '色' : '標準色のみ');
  }

  // 3案を描き直す（自動の案は、いまの自動変換の色。手動の案は、入れた色）
  function refreshThumbs() {
    const api = window.cfpCarpetOrder;
    const vs = api ? api.variants() : null;
    const auto = api && api.autoSets ? api.autoSets() : null;
    if (!vs || !auto) { thumbs.innerHTML = ''; rendered = null; return null; }
    const g = api.current().dither;
    const out = {};
    thumbs.innerHTML = '';
    KEYS.forEach((k) => {
      const cs = cases[k];
      if (!cs.touched) cs.dither = g;
      const idx = cs.manual && cs.indices ? cs.indices : auto[k];
      const r = api.render(idx, cs.dither);
      if (!r) return;
      const label = caseLabel(k, r.used);
      out[k] = { canvas: r.canvas, colors: r.used, label: label, dither: cs.dither, manual: cs.manual, indices: idx.slice() };
      const c = el('div', { style: 'background:#09090b;border:1px solid ' + (cs.manual ? '#f59e0b' : '#3f3f46') + ';border-radius:6px;padding:8px;text-align:center;font-size:12px' });
      c.appendChild(el('div', { style: 'font-weight:700;color:#fff' }, label));
      c.appendChild(el('div', { style: 'color:' + (cs.manual ? '#fbbf24' : '#a1a1aa') + ';margin-bottom:4px' }, cs.manual ? '手動で選んだ色' : '自動の変換'));
      const im = el('img', { alt: label, style: 'max-width:100%;height:auto;background:#fff;image-rendering:pixelated' });
      im.src = r.canvas.toDataURL('image/png');
      c.appendChild(im);
      const paid = r.used.filter((n) => /^OP-/.test(n) && n !== 'OP-03' && n !== 'OP-05');
      c.appendChild(el('div', { style: 'text-align:left;margin-top:4px' }, r.used.length + '色' + (paid.length ? '（有償 ' + paid.length + '色）' : '（すべて無償色）') + '：' + r.used.join('・')));
      // 階調表現（案ごと）
      const row = el('label', { style: 'display:block;text-align:left;margin-top:6px;color:#e4e4e7' }, '階調表現：' + cs.dither + '%');
      const sl = el('input', { type: 'range', min: '0', max: '100', value: String(cs.dither), style: 'width:100%' });
      sl.setAttribute('data-cfp-case', k);
      sl.oninput = () => { row.firstChild.textContent = '階調表現：' + sl.value + '%'; };
      sl.onchange = (ev) => { ev.stopPropagation(); cs.dither = parseInt(sl.value, 10) || 0; cs.touched = true; refreshThumbs(); };
      row.appendChild(sl);
      c.appendChild(row);
      // この案の色：パレットを押して入れる・外す（押すと、この案は手動で選んだ色になる）
      const pal = api.palette ? api.palette() : [];
      if (pal.length) {
        const sel = new Set(idx);
        const used = new Set(r.used);
        const wrap = el('div', { style: 'text-align:left;margin-top:8px' });
        wrap.appendChild(el('div', { style: 'color:#e4e4e7;margin-bottom:3px' }, 'この案の色（押して入れる・外す：' + sel.size + '／16色）'));
        [['標準色', pal.filter((c) => !/^OP-/.test(c.name))], ['オプションカラー', pal.filter((c) => /^OP-/.test(c.name))]].forEach(([name, list]) => {
          wrap.appendChild(el('div', { style: 'color:#a1a1aa;font-size:11px;margin-top:4px' }, name));
          const grid = el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(22px,1fr));gap:3px' });
          list.forEach((col) => {
            const on = sel.has(col.i);
            const chip = el('button', { type: 'button', title: col.name + (col.paid ? '（有償）' : '（無償）') + (on ? (used.has(col.name) ? '：使用中' : '：選択中（画像には出ていない）') : ''), 'aria-pressed': on ? 'true' : 'false',
              style: 'position:relative;aspect-ratio:1;min-height:22px;padding:0;border-radius:4px;cursor:pointer;background:rgb(' + col.rgb.join(',') + ');'
                + (on ? 'outline:2px solid #fbbf24;outline-offset:1px;border:2px solid #09090b;' : 'border:1px solid #52525b;opacity:.45;') });
            if (on) {
              const mark = el('span', { style: 'position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;text-shadow:0 0 2px #000,0 0 2px #000' }, used.has(col.name) ? '✓' : '·');
              chip.appendChild(mark);
            }
            if (col.paid) chip.appendChild(el('span', { style: 'position:absolute;right:1px;top:-1px;font-size:9px;color:#fbbf24;text-shadow:0 0 2px #000' }, '¥'));
            chip.onclick = (ev) => {
              ev.stopPropagation();
              const next = new Set(idx);
              if (next.has(col.i)) {
                if (next.size <= 1) { st.textContent = '❌ 色は1色以上にしてください。'; return; }
                next.delete(col.i);
              } else {
                if (next.size >= 16) { st.textContent = '❌ 色は16色までです。ほかの色を外してから入れてください。'; return; }
                next.add(col.i);
              }
              st.textContent = '';
              cs.manual = true;
              cs.indices = Array.from(next).sort((x, y) => x - y);
              refreshThumbs();
            };
            grid.appendChild(chip);
          });
          wrap.appendChild(grid);
        });
        wrap.appendChild(el('div', { style: 'color:#a1a1aa;font-size:11px;margin-top:3px' }, '✓＝画像に使われている色　·＝選んだが画像に出ていない色（金額に入らない）　¥＝有償'));
        c.appendChild(wrap);
      }
      // 画面のパレットで選んでいる色を、そのまま入れる
      const take = el('button', { type: 'button', style: btnStyle }, '画面のパレットの色を入れる');
      take.onclick = (ev) => {
        ev.stopPropagation();
        const cur = api.current().indices;
        if (!cur.length) { st.textContent = '❌ パレットで色を選んでください。'; return; }
        if (cur.length > 16) { st.textContent = '❌ 色は16色までです。'; return; }
        cs.manual = true; cs.indices = cur;
        refreshThumbs();
      };
      c.appendChild(take);
      if (cs.manual) {
        const back2 = el('button', { type: 'button', style: btnStyle }, '自動に戻す');
        back2.onclick = (ev) => { ev.stopPropagation(); cs.manual = false; cs.indices = null; refreshThumbs(); };
        c.appendChild(back2);
      }
      if (cs.touched) {
        const reset = el('button', { type: 'button', style: btnStyle }, '階調表現を画面に合わせる');
        reset.onclick = (ev) => { ev.stopPropagation(); cs.touched = false; refreshThumbs(); };
        c.appendChild(reset);
      }
      thumbs.appendChild(c);
    });
    rendered = Object.keys(out).length === KEYS.length ? out : null;
    return rendered;
  }

  // 画面の設定を変えたら、少し待ってから描き直す（この枠の中の操作は除く）
  let timer = null;
  function schedule(ev) {
    if (ev && ev.target && box.contains(ev.target)) return;
    clearTimeout(timer);
    timer = setTimeout(refreshThumbs, 600);
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
      document.addEventListener('change', schedule);
      document.addEventListener('click', schedule);
      document.addEventListener('input', (ev) => { if (ev.target && ev.target.id === 'cc-dither2') schedule(ev); });
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
    KEYS.forEach((k) => {
      fd.append('png_' + k, vs[k].canvas.toDataURL('image/png'));
      fd.append('colors_' + k, JSON.stringify(vs[k].colors));
      fd.append('label_' + k, vs[k].label);
      fd.append('dither_' + k, String(vs[k].dither));
      fd.append('manual_' + k, vs[k].manual ? '1' : '0');
    });
    try {
      const r = await fetch(cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await r.json();
      if (!j.success) throw new Error((j.data ? j.data.message : '') || '送れませんでした。');
      const head = (j.data.sent ? '✅ ' + j.data.to + ' へプレビューを送りました。' : '⚠ メールの送信に失敗した可能性があります（サーバーのメール設定を確認してください）。') + '\nお客様の選択ページ：' + j.data.select_url;
      st.textContent = head;
      send.textContent = '送り直す';
      // 案ごとの実寸PSD（変換画像＋割付）を作ってサーバーに保存する（決済後のメールのリンクで渡す）
      const psdLines = await savePsds(vs, (line) => { st.textContent = head + '\n' + line; });
      st.textContent = head + '\n' + psdLines.join('\n');
    } catch (e) {
      st.textContent = '❌ ' + e.message;
    }
    send.disabled = false;
  };

  async function savePsds(vs, show) {
    const api = window.cfpCarpetOrder;
    const lines = [];
    if (!api || !api.psd) return ['⚠ PSDを作る機能が見つかりませんでした。'];
    for (const k of KEYS) {
      const name = vs[k].label.split('：')[0];
      try {
        show('⏳ ' + name + 'のPSD（実寸・割付つき・仮）を作っています…ページを閉じないでください。');
        const p = await api.psd(vs[k].indices, vs[k].dither, (v) => show('⏳ ' + name + 'のPSDを作っています… ' + Math.round(v * 100) + '%（ページを閉じないでください）'));
        // サーバーのアップロードの上限より小さく分けて送る
        const limit = Math.max(256 * 1024, Math.min(8 * 1048576, Math.floor((cfg.max_upload || 2 * 1048576) * 0.8)));
        const chunks = Math.max(1, Math.ceil(p.blob.size / limit));
        const uid = Math.random().toString(36).slice(2, 10) + Date.now().toString(36);
        for (let i = 0; i < chunks; i++) {
          show('⏳ ' + name + 'のPSD（' + mb(p.blob.size) + '）をサーバーに保存しています… ' + Math.round((i / chunks) * 100) + '%');
          const fd = new FormData();
          fd.append('action', 'cfp_co_upload_psd');
          fd.append('nonce', cfg.nonce);
          fd.append('id', String(cfg.id));
          fd.append('case', k);
          fd.append('w', String(p.width));
          fd.append('h', String(p.height));
          fd.append('chunk', String(i));
          fd.append('chunks', String(chunks));
          fd.append('uid', uid);
          if (i === chunks - 1) {
            // PSDを作ったときの色（実際に使われた色）と階調表現：決済完了メールに書く
            fd.append('psd_colors', JSON.stringify(p.used.map((c) => ({ name: c.name, rgb: c.rgb }))));
            fd.append('psd_dither', String(p.dither));
          }
          fd.append('psd', p.blob.slice(i * limit, Math.min(p.blob.size, (i + 1) * limit)), 'psd_' + k + '.part');
          const r = await fetch(cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' });
          let j = null;
          try { j = await r.json(); } catch (e) { j = null; }
          if (!j ? true : !j.success) throw new Error((j ? (j.data ? j.data.message : '') : '') || 'サーバーに保存できませんでした（HTTP ' + r.status + '）。');
        }
        lines.push('✅ ' + name + 'のPSDを仮に保存しました（' + p.width + '×' + p.height + 'px・' + mb(p.blob.size) + '・' + p.used.length + '色・階調表現 ' + p.dither + '%）。');
      } catch (e) {
        lines.push('❌ ' + name + 'のPSD：' + (e && e.message ? e.message : '作れませんでした。'));
      }
    }
    lines.push('※PSDは仮の保存です。お客様が案を選んで決済が完了した時点で、選ばれた案のPSDだけが残り、ほかの2つは削除されます。');
    return lines;
  }

  function mb(n) { return (n / 1048576).toFixed(1) + 'MB'; }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
