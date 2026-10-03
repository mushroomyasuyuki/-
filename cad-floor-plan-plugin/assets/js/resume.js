/**
 * 作業の途中保存と再開（壁紙・カーペットシミュレーションのページ）
 *
 * 保存：入力欄・パレットの選択・CAD表示の状態（JSON）と、床・壁紙の元画像、読み込んだ図面を
 *       サーバーに送り、再開用リンクをメールで受け取る（includes/class-cfp-resume.php）。
 * 再開：?cfp_resume=トークン 付きでページを開くと、保存した内容を読み込んで続きから始める。
 */
(function () {
  'use strict';

  const cfg = window.cfpResumeConfig;
  if (!cfg) return;

  const $ = (id) => document.getElementById(id);
  const TOOL = '.cocoon-carpet-tool';
  // 保存しない入力欄（ファイル選択、著作権の確認、CAD表示の中、途中保存欄そのもの）
  const SKIP = (el) => el.type === 'file' || el.id === 'cc-copyright-ok' || el.id === 'cc-quality-ok' || el.id === 'cfp-resume-email'
    || !!el.closest('.cad-floor-plan-widget') || !el.id;
  let resumeToken = '';

  function setStatus(msg, kind) {
    const s = $('cfp-resume-status');
    if (!s) return;
    s.textContent = msg;
    s.className = 'cfp-resume-status' + (kind ? ' is-' + kind : '');
  }

  function widget() {
    const el = document.querySelector('.cad-floor-plan-widget');
    return el ? el.cfpWidget : null;
  }

  function root() {
    return document.querySelector(TOOL) || document;
  }

  // ------------------------------------------------------------------ collect
  function collectFields() {
    const out = {};
    root().querySelectorAll('input, select, textarea').forEach((el) => {
      if (SKIP(el)) return;
      out[el.id] = (el.type === 'checkbox' || el.type === 'radio') ? { c: el.checked } : { v: el.value };
    });
    return out;
  }

  function applyFields(fields, only) {
    Object.keys(fields || {}).forEach((id) => {
      if (only && !only(id)) return;
      const el = $(id);
      if (!el || SKIP(el)) return;
      const f = fields[id];
      if ('c' in f) {
        if (el.checked === !!f.c) return;
        el.checked = !!f.c;
      } else {
        if (el.value === f.v) return;
        el.value = f.v;
      }
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }

  // 保存する内容（JSON）と、ファイル（床・壁紙の元画像、図面）
  function collectAll() {
    const tool = window.cfpToolApi;
    const w = widget();
    const state = {
      v: 1,
      savedAt: new Date().toISOString(),
      fields: collectFields(),
      palettes: tool ? tool.palettes() : null,
      cad: w ? w.exportState() : null,
    };
    const files = [];
    if (tool) {
      const f = tool.files();
      if (f.floor) files.push({ key: 'floor', file: f.floor });
      if (f.wall) files.push({ key: 'wall', file: f.wall });
    }
    if (w) w.files().forEach((x) => files.push(x));
    return { state, files };
  }

  async function save() {
    const email = ($('cfp-resume-email').value || '').trim();
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
      setStatus('再開用リンクを受け取るメールアドレスを入力してください。', 'err');
      $('cfp-resume-email').focus();
      return;
    }
    const btn = $('cfp-resume-save');
    btn.disabled = true;
    setStatus('保存しています…（画像・図面が大きいと時間がかかります）');
    try {
      const { state, files } = collectAll();
      const fd = new FormData();
      fd.append('action', 'cfp_resume_save');
      fd.append('nonce', cfg.nonce);
      fd.append('email', email);
      fd.append('token', resumeToken);
      fd.append('page_url', location.href.split('#')[0]);
      fd.append('state', JSON.stringify(state));
      let total = 0;
      files.forEach((x) => {
        total += x.file.size;
        fd.append('files[]', x.file, x.key + '::' + x.file.name);
      });
      if (total > cfg.max_mb * 1048576) {
        throw new Error('画像・図面の合計が大きすぎます（' + cfg.max_mb + 'MBまで）。');
      }
      const res = await fetch(cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' });
      const json = await res.json().catch(() => null);
      if (!json || !json.success) throw new Error((json && json.data && json.data.message) || '保存に失敗しました。');
      resumeToken = json.data.token;
      setStatus('保存しました（' + json.data.expires + 'まで）。'
        + (json.data.mailed ? '\n再開用リンクを ' + email + ' にお送りしました。' : '\nメールの送信に失敗しました。下のリンクを控えてください。')
        + '\n再開用リンク：' + json.data.url, 'ok');
    } catch (err) {
      setStatus('エラー：' + err.message, 'err');
    } finally {
      btn.disabled = false;
    }
  }

  // ------------------------------------------------------------------- resume
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));

  async function waitFor(fn, ms) {
    const end = Date.now() + ms;
    while (Date.now() < end) {
      if (fn()) return true;
      await wait(150);
    }
    return !!fn();
  }

  // 保存した内容を画面に戻す（サーバーからでも、このブラウザの自動保存からでも同じ）
  async function restore(st, byKey) {
    restoring = true;
    try {
      await waitFor(() => window.cfpToolApi, 8000);
      await waitFor(() => widget(), 8000);
      const tool = window.cfpToolApi;
      const w = widget();
      // 1) 選択肢・数値（画像の処理より先に、パレット・減色・貼り方を合わせる）
      applyFields(st.fields);
      if (tool && st.palettes) tool.setPalettes(st.palettes);
      // 2) 画像
      if (tool && byKey.floor) await tool.loadFloor(byKey.floor);
      if (tool && byKey.wall) await tool.loadWall(byKey.wall);
      // 3) 図面（部屋・壁の選択でサイズが書き換わる）
      if (w && st.cad) await w.importState(st.cad, byKey);
      // 4) 最後に、保存した数値で上書き（図面からの反映より保存時の入力を優先）
      applyFields(st.fields);
      if (tool) tool.refresh();
    } finally {
      restoring = false;
    }
  }

  async function resume(token) {
    setStatus('保存した作業を読み込んでいます…');
    const box = $('cfp-resume-box');
    if (box) box.scrollIntoView({ block: 'start' });
    try {
      const res = await fetch(cfg.ajax_url + '?action=cfp_resume_load&token=' + encodeURIComponent(token), { credentials: 'same-origin' });
      const json = await res.json().catch(() => null);
      if (!json || !json.success) throw new Error((json && json.data && json.data.message) || '保存した作業を読み込めませんでした。');
      const data = json.data;
      const st = data.state || {};

      const byKey = {};
      for (const f of data.files) {
        const r = await fetch(f.url, { credentials: 'same-origin' });
        if (!r.ok) continue;
        const blob = await r.blob();
        byKey[f.key] = new File([blob], f.name, { type: blob.type || '' });
      }
      await restore(st, byKey);

      resumeToken = token;
      if ($('cfp-resume-email') && data.email) $('cfp-resume-email').value = data.email;
      setStatus('保存した作業を読み込みました（保存期限：' + data.expires + '）。続きから作業できます。\nもう一度「途中保存」すると、同じリンクのまま内容と期限が更新されます。', 'ok');
    } catch (err) {
      setStatus('エラー：' + err.message, 'err');
    }
  }

  // --------------------------------------------- このブラウザへの自動保存（IndexedDB）
  // 同じ端末・同じブラウザなら、次に開いたとき「続きから再開」できる。CFP_RESUME_DAYS 日で削除。
  let restoring = false;
  let dirty = false;
  let lastSig = '';
  const DB = 'cfp-resume', STORE = 'autosave', KEY = 'last';

  function idb() {
    return new Promise((resolve, reject) => {
      if (!window.indexedDB) { reject(new Error('no indexedDB')); return; }
      const req = indexedDB.open(DB, 1);
      req.onupgradeneeded = () => req.result.createObjectStore(STORE);
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  }
  async function idbDo(mode, fn) {
    const db = await idb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, mode);
      const req = fn(tx.objectStore(STORE));
      tx.oncomplete = () => { db.close(); resolve(req ? req.result : undefined); };
      tx.onerror = () => { db.close(); reject(tx.error); };
    });
  }
  const localGet = () => idbDo('readonly', (s) => s.get(KEY));
  const localPut = (v) => idbDo('readwrite', (s) => s.put(v, KEY));
  const localDel = () => idbDo('readwrite', (s) => s.delete(KEY));

  async function autosave() {
    if (restoring || !dirty) return;
    try {
      const { state, files } = collectAll();
      // 変わったときだけ保存（ファイルは名前・大きさで比べる）
      const sig = JSON.stringify([state.fields, state.palettes, state.cad, files.map((x) => x.key + x.file.name + x.file.size)]);
      if (sig === lastSig) return;
      lastSig = sig;
      await localPut({ savedAt: Date.now(), token: resumeToken, state, files: files.map((x) => ({ key: x.key, name: x.file.name, type: x.file.type, blob: x.file })) });
    } catch (err) {
      console.warn('autosave failed', err);
    }
  }

  async function offerLocal() {
    let rec;
    try { rec = await localGet(); } catch (err) { return; }
    if (!rec || !rec.state) return;
    if (Date.now() - rec.savedAt > cfg.days * 86400000) { localDel().catch(() => {}); return; }
    const box = $('cfp-resume-local');
    if (!box) return;
    const d = new Date(rec.savedAt);
    const pad = (n) => String(n).padStart(2, '0');
    const names = (rec.files || []).map((f) => f.name).join('、');
    $('cfp-resume-local-text').textContent = '前回の作業（' + d.getFullYear() + '/' + pad(d.getMonth() + 1) + '/' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes())
      + ' 時点）が、この端末のブラウザに残っています。' + (names ? '（' + names + '）' : '');
    box.hidden = false;
    $('cfp-resume-local-load').onclick = async () => {
      box.hidden = true;
      setStatus('前回の作業を読み込んでいます…');
      try {
        const byKey = {};
        (rec.files || []).forEach((f) => { byKey[f.key] = new File([f.blob], f.name, { type: f.type || '' }); });
        await restore(rec.state, byKey);
        if (rec.token) resumeToken = rec.token;
        setStatus('前回の作業を読み込みました。続きから作業できます。', 'ok');
      } catch (err) {
        setStatus('エラー：' + err.message, 'err');
      }
    };
    $('cfp-resume-local-discard').onclick = () => {
      box.hidden = true;
      localDel().catch(() => {});
      setStatus('前回の作業を破棄しました。');
    };
  }

  function startAutosave() {
    // 操作があってから保存を始める（何もしていない最初の状態では、前回の作業を上書きしない）
    const mark = (e) => {
      if (restoring || !e.target || !e.target.closest) return;
      if (e.target.closest('#cfp-resume-box')) return;
      if (!e.target.closest(TOOL)) return;
      if (e.type === 'click' && !e.target.closest('.cad-floor-plan-widget, .palette-select-grid')) return; // 折りたたみ等のクリックは作業に数えない
      dirty = true;
      const box = $('cfp-resume-local');
      if (box) box.hidden = true; // 新しく作業を始めたら、前回の案内は消す
    };
    ['input', 'change', 'click', 'drop'].forEach((t) => document.addEventListener(t, mark, true));
    setInterval(autosave, 5000);
    window.addEventListener('pagehide', autosave);
  }

  function init() {
    document.querySelectorAll('.cfp-resume-days').forEach((el) => { el.textContent = String(cfg.days); });
    const btn = $('cfp-resume-save');
    if (!btn) return;
    btn.addEventListener('click', save);
    const ccEmail = $('cc-email');
    if (ccEmail) {
      ccEmail.addEventListener('change', () => { if (!$('cfp-resume-email').value) $('cfp-resume-email').value = ccEmail.value; });
    }
    const token = new URLSearchParams(location.search).get('cfp_resume');
    if (token && /^[a-f0-9]{40}$/i.test(token)) resume(token.toLowerCase()).then(() => { dirty = true; });
    else offerLocal();
    startAutosave();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
