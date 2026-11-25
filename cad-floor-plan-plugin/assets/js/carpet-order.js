/**
 * 公開の注文ページ（新ページ・[cfp_carpet_order]）
 * - 注文：仕様・サイズ・画像・お客様情報を入れて発注（色変換はお客様に見せない）。
 * - 選択（メールのリンク ?cfp_co=&t=）：プレビューの案を選んで PAY.JP でお支払い、またはどれも不可で作り直しを依頼。
 * 金額はサーバーで決める（ここでの計算は表示用の目安）。
 */
(function () {
  'use strict';
  const cfg = window.cfpCarpetOrderConfig;
  if (!cfg) return;
  const $ = (id) => document.getElementById(id);
  const yen = (n) => '¥' + Math.round(n).toLocaleString();

  function post(action, fields, onProgress) {
    return new Promise((resolve, reject) => {
      const fd = new FormData();
      fd.append('action', action);
      fd.append('nonce', cfg.nonce);
      Object.keys(fields).forEach((k) => fd.append(k, fields[k]));
      const x = new XMLHttpRequest();
      x.open('POST', cfg.ajax_url);
      if (onProgress) x.upload.onprogress = (e) => { if (e.lengthComputable) onProgress(e.loaded / e.total); };
      x.onload = () => {
        let j = null;
        try { j = JSON.parse(x.responseText); } catch (e) { j = null; }
        if (!j) { reject(new Error('サーバーの応答を読み取れませんでした（HTTP ' + x.status + '）。')); return; }
        if (!j.success) { reject(new Error((j.data ? j.data.message : '') || '失敗しました。')); return; }
        resolve(j.data);
      };
      x.onerror = () => reject(new Error('通信できませんでした。電波の良いところで、もう一度お試しください。'));
      x.send(fd);
    });
  }

  function status(el, type, text) {
    el.className = 'co-status ' + (type || '');
    el.textContent = text || '';
  }

  /* ------------------------------------------------------------ 注文 */

  function price(material, w, h, region) {
    const area = (w / 1000) * (h / 1000);
    let unit;
    if (material === 'cut') unit = area < 1 ? 18400 : (area < 10 ? 16300 : 14000);
    else unit = area < 1 ? 17500 : (area < 10 ? 15500 : 13300);
    const tx = Math.max(1, Math.ceil(w / 500)), ty = Math.max(1, Math.ceil(h / 500));
    const tiles = tx * ty;
    const boxes = Math.max(1, Math.ceil(tiles / (material === 'loop' ? 20 : 16)));
    const r = cfg.regions[region];
    const shipping = (r ? r[1] : 2000) * boxes;
    const base = Math.round(area * unit);
    const tax = Math.round((base + shipping) * cfg.tax_rate);
    return { area, unit, tx, ty, tiles, boxes, shipping, base, tax, total: base + shipping + tax };
  }

  function snap(el) {
    let v = parseFloat(el.value) || 500;
    v = Math.max(500, Math.round(v / 500) * 500);
    el.value = v;
  }

  // 1回のご注文の上限（面積・1辺）。こえたら案内を出し、発注ボタンを押せなくする
  function tooBig(w, h) {
    const maxArea = Number(cfg.max_area) || 50, maxSide = Number(cfg.max_side) || 20000;
    return w > maxSide || h > maxSide || (w / 1000) * (h / 1000) > maxArea;
  }
  function sizeCheck() {
    const w = parseFloat($('co-width').value) || 0, h = parseFloat($('co-height').value) || 0;
    const big = tooBig(w, h);
    const warn = $('co-size-warn');
    if (warn) {
      warn.hidden = !big;
      warn.textContent = big ? (Number(cfg.max_area) || 50) + '㎡（1辺 ' + ((Number(cfg.max_side) || 20000) / 1000) + 'm）をこえるご注文は、このフォームではお受けできません。' + (cfg.contact || '') : '';
    }
    $('co-submit').disabled = big;
    return !big;
  }

  let img = null;
  const calm = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)').matches : false;
  let revealTimer = 0;

  // 合計金額：前の金額から新しい金額まで数字が進む
  function countTo(el, to) {
    const from = parseInt((el.textContent || '').replace(/[^0-9]/g, ''), 10);
    if (calm || !isFinite(from) || from === to) { el.textContent = yen(to); return; }
    const t0 = performance.now(), dur = 450;
    cancelAnimationFrame(el._co);
    const step = (t) => {
      const k = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - k, 3);
      el.textContent = yen(from + (to - from) * e);
      if (k < 1) el._co = requestAnimationFrame(step);
    };
    el._co = requestAnimationFrame(step);
  }

  // reveal：画像を選んだとき、タイル（50cm）を斜めに1枚ずつ見せる
  function drawPreview(reveal) {
    const cv = $('co-canvas');
    const w = parseFloat($('co-width').value) || 500, h = parseFloat($('co-height').value) || 500;
    const max = 640, k = Math.min(max / w, max / h);
    const W = Math.max(1, Math.round(w * k)), H = Math.max(1, Math.round(h * k));
    cv.width = W; cv.height = H;
    const ctx = cv.getContext('2d');
    cancelAnimationFrame(revealTimer);
    // タイル（500mm）の区切り：どんな絵柄の上でも見えるよう、白と紺の2本
    function grid() {
      for (let x = 500; x < w; x += 500) { const X = Math.round(x * k) + 0.5; line(X, 0, X, H); }
      for (let y = 500; y < h; y += 500) { const Y = Math.round(y * k) + 0.5; line(0, Y, W, Y); }
      ctx.strokeStyle = '#082A49'; ctx.lineWidth = 2; ctx.strokeRect(1, 1, W - 2, H - 2);
    }
    const line = (x1, y1, x2, y2) => {
      ctx.strokeStyle = 'rgba(255,255,255,.9)'; ctx.lineWidth = 3; ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke();
      ctx.strokeStyle = 'rgba(8,42,73,.55)'; ctx.lineWidth = 1; ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke();
    };
    const tx = Math.max(1, Math.ceil(w / 500)), ty = Math.max(1, Math.ceil(h / 500));
    if (img ? (reveal ? !calm && tx * ty <= 900 : false) : false) {
      // 絵柄を別の画面に描いておき、タイルの順に写す
      const off = document.createElement('canvas');
      off.width = W; off.height = H;
      const oc = off.getContext('2d');
      const s = Math.max(W / img.width, H / img.height);
      oc.drawImage(img, (W - img.width * s) / 2, (H - img.height * s) / 2, img.width * s, img.height * s);
      const tiles = [];
      for (let j = 0; j < ty; j++) for (let i = 0; i < tx; i++) tiles.push([i, j]);
      tiles.sort((a, b) => (a[0] + a[1]) - (b[0] + b[1]));
      const t0 = performance.now(), dur = Math.min(900, 250 + tiles.length * 40);
      let shown = 0;
      ctx.fillStyle = '#FFFFFF'; ctx.fillRect(0, 0, W, H);
      const frame = (t) => {
        const n = Math.min(tiles.length, Math.ceil(tiles.length * Math.min(1, (t - t0) / dur)));
        for (; shown < n; shown++) {
          const x0 = Math.round(tiles[shown][0] * 500 * k), y0 = Math.round(tiles[shown][1] * 500 * k);
          const x1 = Math.min(W, Math.round((tiles[shown][0] + 1) * 500 * k)), y1 = Math.min(H, Math.round((tiles[shown][1] + 1) * 500 * k));
          ctx.drawImage(off, x0, y0, x1 - x0, y1 - y0, x0, y0, x1 - x0, y1 - y0);
        }
        grid();
        if (shown < tiles.length) revealTimer = requestAnimationFrame(frame);
      };
      revealTimer = requestAnimationFrame(frame);
      return;
    }
    if (img) {
      // 指定サイズいっぱいに（縦横比を変えず、はみ出す部分は切り取る）
      const s = Math.max(W / img.width, H / img.height);
      const dw = img.width * s, dh = img.height * s;
      ctx.drawImage(img, (W - dw) / 2, (H - dh) / 2, dw, dh);
    } else {
      // 画像を選ぶ前：タイルの並びだけを見せる
      ctx.fillStyle = '#FFFFFF';
      ctx.fillRect(0, 0, W, H);
    }
    grid();
    if (!img) {
      ctx.fillStyle = '#3E5670';
      ctx.font = '500 ' + Math.round(Math.max(18, W / 22)) + 'px "Zen Kaku Gothic New", sans-serif';
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText('画像を選ぶと、ここに配置が出ます', W / 2, H / 2);
    }
  }

  function estimate(reveal) {
    const m = $('co-material').value, w = parseFloat($('co-width').value) || 0, h = parseFloat($('co-height').value) || 0;
    const p = price(m, w, h, $('co-region').value);
    $('co-tiles').textContent = '合計 ' + p.tiles + '枚（横' + p.tx + '枚 × 縦' + p.ty + '枚）・' + p.boxes + '箱';
    $('co-e-material').textContent = (m === 'cut' ? 'カットパイル' : 'ループパイル') + ' ¥' + p.unit.toLocaleString() + '/㎡';
    $('co-e-area').textContent = p.area.toFixed(2) + '㎡・' + p.tiles + '枚';
    $('co-e-base').textContent = yen(p.base);
    $('co-e-ship').textContent = yen(p.shipping) + '（' + p.boxes + '箱）';
    $('co-e-tax').textContent = yen(p.tax);
    countTo($('co-e-total'), p.total);
    $('co-e-ppc').textContent = Number(cfg.price_per_color).toLocaleString();
    drawPreview(reveal === true);
    sizeCheck();
  }

  // 住所の都道府県から送料の地域を選ぶ
  function regionFromAddress() {
    const a = $('co-address').value.trim();
    if (/^沖縄/.test(a)) $('co-region').value = 'okinawa';
    else if (/^(北海道|徳島|香川|愛媛|高知|福岡|佐賀|長崎|熊本|大分|宮崎|鹿児島)/.test(a)) $('co-region').value = 'hokkaido_shikoku_kyushu';
    else if (/^(東京|神奈川|千葉|埼玉|茨城|栃木|群馬|山梨|長野|新潟|富山|石川|福井|静岡|愛知|岐阜|三重|滋賀|京都|大阪|兵庫|奈良|和歌山|鳥取|島根|岡山|広島|山口|青森|岩手|宮城|秋田|山形|福島)/.test(a)) $('co-region').value = 'honshu';
    else return;
    estimate();
  }

  function initQuality() {
    const doc = $('co-quality-doc'), chk = $('co-quality'), btn = $('co-quality-open'), st = $('co-quality-status');
    if (!doc) { chk.disabled = false; return; }
    const atEnd = () => doc.scrollTop + doc.clientHeight >= doc.scrollHeight - 6;
    const read = () => { chk.disabled = false; st.textContent = '最後まで読みました。同意のチェックができます。'; st.className = 'co-qs-status ok'; };
    btn.onclick = () => {
      doc.hidden = !doc.hidden;
      btn.textContent = doc.hidden ? '品質基準を読む（最後まで読むとチェックできます）' : '品質基準を閉じる';
      if (!doc.hidden) { doc.focus({ preventScroll: true }); if (atEnd()) read(); }
    };
    doc.addEventListener('scroll', () => { if (atEnd()) read(); });
  }

  function initOrder() {
    $('co-order-view').hidden = false;
    ['co-material', 'co-region'].forEach((id) => { $(id).onchange = estimate; });
    ['co-width', 'co-height'].forEach((id) => { $(id).onchange = () => { snap($(id)); estimate(); }; });
    $('co-address').addEventListener('change', regionFromAddress);
    $('co-file').onchange = () => {
      const f = $('co-file').files[0];
      img = null;
      const drop = document.querySelector('#cfp-co .co-drop');
      if (drop) {
        drop.classList.toggle('has-file', !!f);
        drop.querySelector('strong').textContent = f ? f.name : '画像を選ぶ';
      }
      if (!f) { estimate(); return; }
      const url = URL.createObjectURL(f);
      const im = new Image();
      im.onload = () => { img = im; estimate(true); };
      im.onerror = () => { status($('co-status'), 'err', '画像として読み込めませんでした。JPEG・PNG・GIF・WebP の画像を選んでください。'); };
      im.src = url;
    };
    initQuality();
    // 入力している手順の番号を光らせる
    document.addEventListener('focusin', (e) => {
      const step = e.target.closest ? e.target.closest('#cfp-co .co-step') : null;
      if (!step) return;
      document.querySelectorAll('#cfp-co .co-step.is-active').forEach((x) => { if (x !== step) x.classList.remove('is-active'); });
      step.classList.add('is-active');
    });
    estimate();
    $('co-submit').onclick = submit;
    initMotion();
  }

  // スクロールで説明が現れる動きと、画面の下に出る「ご注文フォームへ」
  function initMotion() {
    if (!('IntersectionObserver' in window)) return;
    const root = $('cfp-co');
    const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!reduce) {
      root.classList.add('js-reveal');
      const io = new IntersectionObserver((es) => {
        es.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); } });
      }, { rootMargin: '0px 0px -10% 0px' });
      root.querySelectorAll('.co-reveal').forEach((el) => io.observe(el));
    }
    const fab = $('co-fab');
    const hero = root.querySelector('.co-hero');
    const form = root.querySelector('.co-order');
    if (!fab || !hero || !form) return;
    let pastHero = false, atForm = false;
    const sync = () => { fab.classList.toggle('is-on', pastHero && !atForm); fab.tabIndex = pastHero && !atForm ? 0 : -1; };
    new IntersectionObserver((es) => { pastHero = !es[0].isIntersecting && es[0].boundingClientRect.top < 0; sync(); }).observe(hero);
    new IntersectionObserver((es) => { atForm = es[0].isIntersecting; sync(); }, { rootMargin: '0px 0px -30% 0px' }).observe(form);
  }

  async function submit() {
    const st = $('co-status');
    const f = $('co-file').files[0];
    const need = [['co-name', 'お名前'], ['co-tel', '電話番号'], ['co-email', 'メールアドレス'], ['co-address', 'ご住所']];
    if (!f) { status(st, 'err', 'デザイン画像を選んでください。'); return; }
    if (f.size > 30 * 1024 * 1024) { status(st, 'err', '画像が大きすぎます（30MBまで）。'); return; }
    for (const [id, label] of need) {
      if (!$(id).value.trim()) { status(st, 'err', label + 'を入力してください。'); $(id).focus(); return; }
    }
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test($('co-email').value.trim())) { status(st, 'err', 'メールアドレスの形式を確認してください。'); $('co-email').focus(); return; }
    if (!$('co-copyright').checked) { status(st, 'err', '著作権などの確認にチェックしてください。'); return; }
    if (!$('co-quality').checked) { status(st, 'err', '品質基準を最後まで読んで、同意にチェックしてください。'); return; }
    snap($('co-width')); snap($('co-height'));
    if (!sizeCheck()) { status(st, 'err', $('co-size-warn').textContent); return; }
    const btn = $('co-submit');
    btn.disabled = true;
    status(st, 'info', '送信しています…');
    try {
      const res = await post('cfp_co_submit', {
        material: $('co-material').value, width: $('co-width').value, height: $('co-height').value, region: $('co-region').value,
        name: $('co-name').value, company: $('co-company').value, tel: $('co-tel').value, email: $('co-email').value.trim(),
        address: $('co-address').value, memo: $('co-memo').value, receipt: $('co-receipt').checked ? '1' : '',
        copyright_ok: '1', quality_ok: '1', page_url: cfg.page_url, image: f
      }, (r) => status(st, 'info', '送信しています… ' + Math.round(r * 100) + '%'));
      status(st, 'ok', 'ご注文を受け付けました（ご注文番号：' + res.no + '）。\n確認のメールをお送りしました。担当者が色の変換プレビューを作成し、あらためてメールでお送りします。');
      btn.textContent = '受け付けました';
    } catch (e) {
      status(st, 'err', ''+ e.message);
      btn.disabled = false;
    }
  }

  /* ------------------------------------------------------------ 案の選択・お支払い */

  let order = cfg.order || null;
  let chosen = '';
  let card = null, payjp = null;

  function renderSelect() {
    const o = order;
    $('co-sel-lead').textContent = 'ご注文番号 ' + o.no + '（' + o.material + '・' + o.width + '×' + o.height + 'mm・' + o.region + '）'
      + (o.lead_time ? '\n納期（出荷日）：お支払い確定後、' + o.lead_time + 'で発送します。' : '');
    const msg = $('co-sel-msg');
    msg.hidden = !o.message;
    msg.textContent = o.message || '';
    const box = $('co-vars');
    box.innerHTML = '';
    if (o.status === 'paid') {
      $('co-sel-actions').hidden = true;
      closePay(true);
      const sh = o.shipped;
      status($('co-sel-status'), 'ok', sh
        ? 'ご注文の商品を発送しました（発送日：' + sh.date + '・ヤマト運輸・送り状番号：' + sh.tracking + '）。\nお荷物の状況は、ヤマト運輸のサイトで確認できます。'
        : 'お支払いが完了し、正式にご注文を承りました。ありがとうございます。\n製作に入り、発送の準備ができましたらご連絡いたします。');
      if (sh) {
        const a = document.createElement('a');
        a.href = sh.url; a.target = '_blank'; a.rel = 'noopener'; a.className = 'co-link'; a.textContent = 'お荷物の状況を確認する（ヤマト運輸）';
        a.style.display = 'inline-block'; a.style.marginTop = '8px';
        $('co-sel-status').appendChild(document.createElement('br'));
        $('co-sel-status').appendChild(a);
      }
      return;
    }
    if (o.status === 'rejected') {
      $('co-sel-actions').hidden = true;
      status($('co-sel-status'), 'info', '作り直しのご依頼を承りました。担当者が新しいプレビューを作成し、あらためてメールでお送りします。');
      return;
    }
    if (o.status === 'cancelled') {
      $('co-sel-actions').hidden = true;
      status($('co-sel-status'), 'info', 'このご注文はキャンセルされています。');
      return;
    }
    if (!o.variants.length) {
      $('co-sel-actions').hidden = true;
      status($('co-sel-status'), 'info', 'プレビューを準備中です。準備ができましたらメールでお知らせします。');
      return;
    }
    o.variants.forEach((v) => {
      const d = document.createElement('div');
      d.className = 'co-var' + (chosen === v.key ? ' on' : '');
      const paid = v.paid.length ? '有償オプション色 ' + v.paid.length + '色（' + v.paid.join('・') + '）' + (v.free_applied ? '・うち' + v.free_applied + '色は無料特典' : '') : 'すべて無償色';
      d.innerHTML = '<h3></h3><img alt=""><div class="co-note"></div><div class="co-var-price"></div><div class="co-note">税込・送料込み</div>';
      d.querySelector('h3').textContent = v.label;
      d.querySelector('img').src = v.img;
      d.querySelector('img').alt = v.label + 'のプレビュー';
      d.querySelector('.co-note').textContent = v.colors + '色・' + paid;
      d.querySelector('.co-var-price').textContent = yen(v.total);
      d.tabIndex = 0;
      d.setAttribute('role', 'button');
      d.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); d.click(); } });
      d.onclick = () => {
        chosen = v.key;
        box.querySelectorAll('.co-var').forEach((x) => x.classList.remove('on'));
        d.classList.add('on');
        $('co-choose').disabled = false;
        $('co-choose').textContent = v.label.split('：')[0] + 'で注文する（' + yen(v.total) + '）';
      };
      box.appendChild(d);
    });
    if (o.status === 'selected' ? o.choice : false) {
      chosen = o.choice;
      const i = o.variants.findIndex((v) => v.key === chosen);
      if (i >= 0) {
        box.children[i].classList.add('on');
        $('co-choose').disabled = false;
        $('co-choose').textContent = o.variants[i].label.split('：')[0] + 'で注文する（' + yen(o.variants[i].total) + '）';
      }
    }
  }

  async function choose() {
    if (!chosen) return;
    const st = $('co-sel-status');
    $('co-choose').disabled = true;
    status(st, 'info', '送信しています…');
    try {
      const res = await post('cfp_co_select', { id: String(cfg.order_id || ''), t: cfg.token, choice: chosen });
      order = res.order;
      status(st, '', '');
      showPay();
    } catch (e) {
      status(st, 'err', ''+ e.message);
    }
    $('co-choose').disabled = false;
  }

  async function reject() {
    if (!confirm('どの案も希望と違うとして、作り直しを依頼します。よろしいですか？')) return;
    const st = $('co-sel-status');
    try {
      const res = await post('cfp_co_select', { id: String(cfg.order_id || ''), t: cfg.token, choice: 'ng', comment: $('co-ng-comment').value });
      order = res.order;
      renderSelect();
    } catch (e) {
      status(st, 'err', ''+ e.message);
    }
  }

  // お支払いの画面（ページの上に重ねて開く小さなウィンドウ）
  let paying = false, lastFocus = null;
  function openPay() {
    const m = $('co-pay-modal');
    lastFocus = document.activeElement;
    m.hidden = false;
    document.documentElement.classList.add('cfp-co-modal-open');
    setTimeout(() => { const x = m.querySelector('.co-modal-x'); if (x) x.focus(); }, 30);
  }
  function closePay(force) {
    if (paying && !force) return;
    const m = $('co-pay-modal');
    if (!m || m.hidden) return;
    m.hidden = true;
    document.documentElement.classList.remove('cfp-co-modal-open');
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  function showPay() {
    const v = order.variants.find((x) => x.key === order.choice);
    if (!v) return;
    status($('co-pay-status'), '', '');
    openPay();
    $('co-pay-summary').textContent = '選んだ案：' + v.label + '\nお支払い金額：' + yen(order.amount) + '（税込・送料込み）';
    $('co-pay-btn').textContent = yen(order.amount) + ' を支払う（正式に注文する）';
    if (!cfg.payjp_pk) {
      status($('co-pay-status'), 'info', '現在カード決済を準備中です。お手数ですが、メールにご返信ください。');
      $('co-pay-btn').disabled = true;
      return;
    }
    if (typeof window.Payjp !== 'function') {
      status($('co-pay-status'), 'err', '決済の画面を読み込めませんでした。ページを再読み込みしてください。');
      return;
    }
    if (!payjp) {
      payjp = window.Payjp(cfg.payjp_pk);
      card = payjp.elements().create('card');
      card.mount('#co-card');
    }
  }

  async function pay() {
    const st = $('co-pay-status');
    const btn = $('co-pay-btn');
    btn.disabled = true;
    paying = true;
    status(st, 'info', 'カードを確認しています…');
    try {
      const name = $('co-card-name').value.trim();
      const r = await payjp.createToken(card, name ? { card: { name } } : undefined);
      if (r.error) throw new Error(r.error.message || 'カード情報を確認してください。');
      status(st, 'info', 'お支払いを処理しています…');
      let res = await post('cfp_co_pay', { id: String(cfg.order_id || ''), t: cfg.token, card_token: r.id });
      if (!res.done) {
        status(st, 'info', 'カード会社の本人認証（3Dセキュア）を行ってください…');
        await payjp.openThreeDSecureDialog(res.charge_id);
        status(st, 'info', 'お支払いを確認しています…');
        res = await post('cfp_co_pay_finish', { id: String(cfg.order_id || ''), t: cfg.token });
      }
      order = res.order;
      paying = false;
      renderSelect();
      window.scrollTo({ top: $('cfp-co').offsetTop, behavior: 'smooth' });
    } catch (e) {
      status(st, 'err', ''+ (e && e.message ? e.message : 'お支払いできませんでした。'));
      btn.disabled = false;
      paying = false;
    }
  }

  function initSelect() {
    $('co-select-view').hidden = false;
    const q = new URLSearchParams(location.search);
    cfg.order_id = q.get('cfp_co');
    $('co-choose').onclick = choose;
    $('co-ng').onclick = reject;
    $('co-pay-btn').onclick = pay;
    // 閉じる：×・背景・Esc（お支払いの処理中は閉じない）
    $('co-pay-modal').querySelectorAll('[data-close]').forEach((x) => { x.onclick = () => closePay(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closePay(); });
    renderSelect();
  }

  function start() {
    if (cfg.mode === 'select') initSelect();
    else if (cfg.mode === 'bad_link') $('co-bad-view').hidden = false;
    else initOrder();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
