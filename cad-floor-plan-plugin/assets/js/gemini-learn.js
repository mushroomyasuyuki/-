/**
 * カーペットの色変換：人間の変換の癖を Gemini で学ぶ（管理者だけに表示。サーバー側は includes/class-cfp-gemini.php）
 *
 * - 元画像の色を 24 のまとまりに分け（Lab の k-means）、まとまりごとに「人間が選んだ色」「システムが選んだ色」を
 *   同じ位置の画素で数えて、画像と一緒に送る。
 * - 「学習した変換を表示」では、Gemini が選んだ「まとまり → パレットの色」で色の対応表を作り、画面で変換する
 *   （パレットの色だけを使う）。
 */
(function () {
  'use strict';

  const cfg = window.cfpGeminiConfig;
  if (!cfg) return;
  const $ = (id) => document.getElementById(id);
  const K = 24;          // 色のまとまりの数
  const SEND = 768;      // 送る画像の長い辺（px）

  let human = null;      // 人間が変換した画像（元画像と同じ大きさの canvas）
  let last = null;       // 最後に作った学習の変換：{ indices }

  function status(msg, kind) {
    const el = $('cfp-gemini-status');
    if (!el) return;
    el.textContent = msg || '';
    el.className = 'cfp-gemini-status' + (kind ? ' is-' + kind : '');
  }

  function showRules(rules, count) {
    if ($('cfp-gemini-rules-text')) $('cfp-gemini-rules-text').textContent = rules || 'まだ学習していません。';
    if ($('cfp-gemini-count')) $('cfp-gemini-count').textContent = String(count || 0);
  }

  const lab = (r, g, b) => {
    const f = (c) => { c /= 255; return c > 0.04045 ? Math.pow((c + 0.055) / 1.055, 2.4) : c / 12.92; };
    const R = f(r), G = f(g), B = f(b);
    const t = (v) => (v > 0.008856 ? Math.cbrt(v) : 7.787 * v + 16 / 116);
    const x = t((R * 0.4124 + G * 0.3576 + B * 0.1805) / 0.95047), y = t(R * 0.2126 + G * 0.7152 + B * 0.0722), z = t((R * 0.0193 + G * 0.1192 + B * 0.9505) / 1.08883);
    return [116 * y - 16, 500 * (x - y), 200 * (y - z)];
  };
  const hex = (c) => '#' + c.map((v) => Math.round(v).toString(16).padStart(2, '0')).join('');

  // canvas → 長い辺 SEND px までに縮めた data URL
  function dataUrl(cv, type) {
    const s = Math.min(1, SEND / Math.max(cv.width, cv.height));
    const c = document.createElement('canvas');
    c.width = Math.max(1, Math.round(cv.width * s));
    c.height = Math.max(1, Math.round(cv.height * s));
    const x = c.getContext('2d');
    if (type === 'image/jpeg') { x.fillStyle = '#ffffff'; x.fillRect(0, 0, c.width, c.height); }
    x.imageSmoothingEnabled = type === 'image/jpeg';
    x.drawImage(cv, 0, 0, c.width, c.height);
    return c.toDataURL(type || 'image/png', 0.9);
  }

  function imageDataCanvas(im) {
    const c = document.createElement('canvas');
    c.width = im.width; c.height = im.height;
    c.getContext('2d').putImageData(im, 0, 0);
    return c;
  }

  // 元画像の色を K のまとまりに（間引いた画素で k-means）。positions: 数えた画素の位置
  function clusterOriginal(orig) {
    const w = orig.width, h = orig.height;
    const d = orig.getContext('2d').getImageData(0, 0, w, h).data;
    const step = Math.max(1, Math.round(Math.sqrt(w * h / 40000)));
    const pts = [], pos = [];
    for (let y = 0; y < h; y += step) for (let x = 0; x < w; x += step) {
      const i = (y * w + x) * 4;
      if (d[i + 3] < 128) continue;
      pts.push(lab(d[i], d[i + 1], d[i + 2]));
      pos.push(i);
    }
    if (!pts.length) return null;
    // 初期値：k-means++ 風に、離れた色を順に選ぶ
    const cen = [pts[Math.floor(pts.length / 2)].slice()];
    const dist = new Float64Array(pts.length).fill(Infinity);
    while (cen.length < Math.min(K, pts.length)) {
      const c = cen[cen.length - 1];
      let bi = 0, bd = -1;
      for (let i = 0; i < pts.length; i++) {
        const p = pts[i], e = (p[0] - c[0]) ** 2 + (p[1] - c[1]) ** 2 + (p[2] - c[2]) ** 2;
        if (e < dist[i]) dist[i] = e;
        if (dist[i] > bd) { bd = dist[i]; bi = i; }
      }
      if (bd <= 1) break;
      cen.push(pts[bi].slice());
    }
    const asg = new Int32Array(pts.length);
    for (let it = 0; it < 10; it++) {
      const sum = cen.map(() => [0, 0, 0, 0]);
      for (let i = 0; i < pts.length; i++) {
        const p = pts[i];
        let bk = 0, bd = Infinity;
        for (let k = 0; k < cen.length; k++) { const c = cen[k], e = (p[0] - c[0]) ** 2 + (p[1] - c[1]) ** 2 + (p[2] - c[2]) ** 2; if (e < bd) { bd = e; bk = k; } }
        asg[i] = bk;
        const s = sum[bk]; s[0] += p[0]; s[1] += p[1]; s[2] += p[2]; s[3]++;
      }
      sum.forEach((s, k) => { if (s[3]) cen[k] = [s[0] / s[3], s[1] / s[3], s[2] / s[3]]; });
    }
    // まとまりごとの平均の RGB と面積
    const rgb = cen.map(() => [0, 0, 0, 0]);
    for (let i = 0; i < pts.length; i++) { const r = rgb[asg[i]], j = pos[i]; r[0] += d[j]; r[1] += d[j + 1]; r[2] += d[j + 2]; r[3]++; }
    const list = cen.map((c, k) => ({ lab: c, rgb: rgb[k][3] ? [rgb[k][0] / rgb[k][3], rgb[k][1] / rgb[k][3], rgb[k][2] / rgb[k][3]] : [0, 0, 0], n: rgb[k][3] }));
    return { list, asg, pos, total: pts.length };
  }

  // 画像（元画像と同じ大きさ）の、まとまりごとにいちばん多いパレットの色
  function tally(cl, im, colors) {
    const d = im.data, cache = new Map();
    const near = (r, g, b) => {
      const key = (r << 16) | (g << 8) | b;
      let v = cache.get(key);
      if (v === undefined) {
        let bd = Infinity; v = 0;
        colors.forEach((c, i) => { const e = (r - c.rgb[0]) ** 2 + (g - c.rgb[1]) ** 2 + (b - c.rgb[2]) ** 2; if (e < bd) { bd = e; v = i; } });
        cache.set(key, v);
      }
      return v;
    };
    const cnt = cl.list.map(() => new Map());
    for (let i = 0; i < cl.pos.length; i++) {
      const j = cl.pos[i];
      if (d[j + 3] < 128) continue;
      const m = cnt[cl.asg[i]], v = near(d[j], d[j + 1], d[j + 2]);
      m.set(v, (m.get(v) || 0) + 1);
    }
    return cnt.map((m) => {
      let best = -1, bn = 0, tot = 0;
      m.forEach((n, v) => { tot += n; if (n > bn) { bn = n; best = v; } });
      return best < 0 ? null : { idx: best, pct: Math.round(bn / tot * 100) };
    });
  }

  function paletteJson(colors) {
    return JSON.stringify(colors.map((c) => ({ name: c.name, hex: hex(c.rgb), paid: !!(c.isOp ? c.price > 0 : false) })));
  }

  async function post(action, fields) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', cfg.nonce);
    Object.keys(fields).forEach((k) => fd.append(k, fields[k]));
    const r = await fetch(cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' });
    let j = null;
    try { j = await r.json(); } catch (e) { j = null; }
    if (!j) throw new Error('サーバーの応答を読み取れませんでした（HTTP ' + r.status + '）。');
    if (!j.success) throw new Error((j.data ? j.data.message : '') || '失敗しました。');
    return j.data;
  }

  function api() {
    const L = window.cfpCarpetLearn;
    const orig = L ? L.original() : null;
    if (!orig ? true : !orig.width) { status('先に「① カーペット用デザイン」で画像を選んでください。', 'err'); return null; }
    return { L, orig };
  }

  // 人間が変換した画像を、元画像と同じ大きさにして持つ
  function loadHuman(file) {
    const a = api();
    if (!a) return;
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      const cv = $('cfp-gemini-human-cv');
      cv.width = a.orig.width; cv.height = a.orig.height;
      const x = cv.getContext('2d');
      // 元の画像から人間が変換した画像として、元画像と同じ切り抜き方（指定サイズ・画像の大きさ）で重ねる
      x.imageSmoothingEnabled = false;
      a.L.drawCover(x, img, cv.width, cv.height);
      human = cv;
      $('cfp-gemini-human-card').hidden = false;
      $('cfp-gemini-human-name').textContent = file.name + '（' + img.naturalWidth + '×' + img.naturalHeight + 'px → 元画像と同じ切り抜き方で ' + cv.width + '×' + cv.height + 'px にして比べます）';
      $('cfp-gemini-send').disabled = false;
      URL.revokeObjectURL(url);
    };
    img.onerror = () => { status('画像として読み込めませんでした。', 'err'); URL.revokeObjectURL(url); };
    img.src = url;
  }

  async function learn() {
    const a = api();
    if (!a) return;
    if (!human) { status('先に「人間が変換した画像を選ぶ」で画像を選んでください。', 'err'); return; }
    if (!cfg.has_key) { status('Gemini のAPIキーが未設定です。管理画面「壁紙・カーペット」で設定してください。', 'err'); return; }
    const btn = $('cfp-gemini-send');
    btn.disabled = true;
    status('システムの変換を作り、Gemini に送っています…（数十秒かかることがあります）');
    try {
      const { L, orig } = a;
      const sets = L.sets();
      const sys = {};
      ['std', 'op3', 'op6'].forEach((k) => { sys[k] = imageDataCanvas(L.render(sets[k]).imageData); });
      const cl = clusterOriginal(orig);
      if (!cl) throw new Error('元画像に色がありません。');
      const hum = tally(cl, human.getContext('2d').getImageData(0, 0, human.width, human.height), L.colors);
      const st = tally(cl, sys.std.getContext('2d').getImageData(0, 0, orig.width, orig.height), L.colors);
      const clusters = cl.list.map((c, k) => ({
        hex: hex(c.rgb), share: Math.round(c.n / cl.total * 1000) / 10,
        sys: st[k] ? L.colors[st[k].idx].name : '',
        human: hum[k] ? L.colors[hum[k].idx].name : '', human_pct: hum[k] ? hum[k].pct : 0
      })).filter((c) => c.share > 0);
      const res = await post('cfp_gemini_learn', {
        original: dataUrl(orig, 'image/jpeg'), human: dataUrl(human), sys_std: dataUrl(sys.std), sys_op3: dataUrl(sys.op3), sys_op6: dataUrl(sys.op6),
        clusters: JSON.stringify(clusters), palette: paletteJson(L.colors)
      });
      cfg.rules = res.rules; cfg.examples = res.examples;
      showRules(res.rules, res.examples);
      status('✅ 学習しました（例 ' + res.examples + ' 件）。' + (res.observations ? '\n今回わかった癖：\n' + res.observations : ''), 'ok');
    } catch (e) {
      status('❌ ' + e.message, 'err');
    } finally {
      btn.disabled = false;
    }
  }

  async function applyLearned() {
    const a = api();
    if (!a) return;
    if (!cfg.has_key) { status('Gemini のAPIキーが未設定です。管理画面「壁紙・カーペット」で設定してください。', 'err'); return; }
    const btn = $('cfp-gemini-apply');
    btn.disabled = true;
    status('学習した癖で、Gemini に色を選ばせています…（数十秒かかることがあります）');
    try {
      const { L, orig } = a;
      const cl = clusterOriginal(orig);
      if (!cl) throw new Error('元画像に色がありません。');
      const sets = L.sets();
      const st = tally(cl, L.render(sets.std).imageData, L.colors);
      const clusters = cl.list.map((c, k) => ({ hex: hex(c.rgb), share: Math.round(c.n / cl.total * 1000) / 10, sys: st[k] ? L.colors[st[k].idx].name : '' }));
      const res = await post('cfp_gemini_apply', { original: dataUrl(orig, 'image/jpeg'), clusters: JSON.stringify(clusters), palette: paletteJson(L.colors) });
      // まとまり → パレットの色（名前が分からなければ、システムの色）
      const byName = new Map(L.colors.map((c, i) => [c.name, i]));
      const pick = cl.list.map((c, k) => (st[k] ? st[k].idx : 0));
      res.assign.forEach((x) => { if (x.i >= 0 ? x.i < pick.length : false) { const v = byName.get(x.color); if (v !== undefined) pick[x.i] = v; } });
      // 16色まで（多いときは面積の小さいまとまりの色を、残す色のうち近いものに寄せる）
      const area = new Map();
      pick.forEach((v, k) => area.set(v, (area.get(v) || 0) + cl.list[k].n));
      let keep = Array.from(area.keys()).sort((x, y) => area.get(y) - area.get(x));
      if (keep.length > 16) {
        keep = keep.slice(0, 16);
        pick.forEach((v, k) => {
          if (keep.indexOf(v) >= 0) return;
          const c = cl.list[k].lab;
          let bd = Infinity, bv = keep[0];
          keep.forEach((u) => { const q = lab(...L.colors[u].rgb), e = (q[0] - c[0]) ** 2 + (q[1] - c[1]) ** 2 + (q[2] - c[2]) ** 2; if (e < bd) { bd = e; bv = u; } });
          pick[k] = bv;
        });
      }
      const indices = Array.from(new Set(pick)).sort((x, y) => x - y);
      const entries = indices.map((i) => L.colors[i]);
      const slot = pick.map((v) => indices.indexOf(v));
      // 色の対応表：RGB（5bitずつ）→ いちばん近いまとまり → そのまとまりの色
      const lut = new Uint8Array(32768);
      for (let key = 0; key < 32768; key++) {
        const c = lab(((key >> 10) << 3) + 4, (((key >> 5) & 31) << 3) + 4, ((key & 31) << 3) + 4);
        let bk = 0, bd = Infinity;
        cl.list.forEach((q, k) => { const e = (q.lab[0] - c[0]) ** 2 + (q.lab[1] - c[1]) ** 2 + (q.lab[2] - c[2]) ** 2; if (e < bd) { bd = e; bk = k; } });
        lut[key] = slot[bk];
      }
      const out = L.renderLut(entries, lut);
      const cv = $('cfp-gemini-result');
      cv.width = orig.width; cv.height = orig.height;
      cv.getContext('2d').putImageData(out.imageData, 0, 0);
      const chips = $('cfp-gemini-result-chips');
      chips.innerHTML = '';
      out.usedEntries.forEach((col) => {
        const c = document.createElement('div');
        c.className = 'psd-color-chip';
        c.style.backgroundColor = 'rgb(' + col.rgb.join(',') + ')';
        c.title = col.name;
        chips.appendChild(c);
      });
      const paid = out.usedEntries.filter((c) => (c.isOp ? c.price > 0 : false));
      $('cfp-gemini-result-text').textContent = '使用 ' + out.usedEntries.length + '色' + (paid.length ? '（うち有償オプション ' + paid.length + '色：' + paid.map((c) => c.name).join('・') + '）' : '（すべて無償色）') + (res.comment ? '\nGemini：' + res.comment : '');
      $('cfp-gemini-result-card').hidden = false;
      last = { indices: out.usedEntries.map((e) => L.colors.indexOf(e)).filter((i) => i >= 0) };
      status('✅ 学習した癖で変換しました（例 ' + (cfg.examples || 0) + ' 件から学習）。', 'ok');
    } catch (e) {
      status('❌ ' + e.message, 'err');
    } finally {
      btn.disabled = false;
    }
  }

  function init() {
    if (!$('cfp-gemini-box')) return;
    showRules(cfg.rules, cfg.examples);
    if (!cfg.has_key) status('Gemini のAPIキーが未設定です。管理画面「壁紙・カーペット」の「人間の変換の学習（Gemini）」で設定してください。', 'err');
    $('cfp-gemini-human').onchange = (e) => { const f = e.target.files ? e.target.files[0] : null; if (f) loadHuman(f); e.target.value = ''; };
    $('cfp-gemini-send').onclick = learn;
    $('cfp-gemini-apply').onclick = applyLearned;
    $('cfp-gemini-use').onclick = () => {
      if (!last ? true : !window.cfpCarpetLearn) return;
      window.cfpCarpetLearn.select(last.indices);
      status('学習した変換の色の組合せ（' + last.indices.length + '色）をパレットに反映しました。見積もり・保存は、この色の組合せでのシステムの変換になります。', 'ok');
    };
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
