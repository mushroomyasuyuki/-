// シフト表の編集画面を、本物のブラウザ（Chromium）で動かして確かめるテスト。
// 実行：NODE_PATH=<playwrightのある場所> node tests/browser/test-schedule-ui.js
// （WordPressは不要。APIは、このファイルの中の簡易サーバーが代わりに答える）
const http = require('http');
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const ASSETS = path.join(__dirname, '..', '..', 'assets');
let failed = 0;
function check(n, c, extra) { console.log((c ? 'PASS ' : 'FAIL ') + n + (!c && extra ? '  ' + extra : '')); if (!c) failed++; }

/* ---- 用意するデータ：飲食店・8名・14日 ---- */
const days = 14;
const staff = [];
for (let i = 0; i < 8; i++) staff.push({ id: 100 + i, name: 'スタッフ' + (i + 1), employment: 'part', roles: [], qualifications: [], night_ok: true, weekdays: [0, 1, 2, 3, 4, 5, 6], active: true });
const patterns = [
  { id: 1, name: 'ランチ', short_name: 'ラ', start_time: '11:00', end_time: '15:00', break_minutes: 0, crosses_midnight: false, counts_as_night: false, color: '#f2b84b', active: true },
  { id: 2, name: 'ディナー', short_name: 'デ', start_time: '17:00', end_time: '22:30', break_minutes: 0, crosses_midnight: false, counts_as_night: false, color: '#4a7fd1', active: true },
  { id: 3, name: '通し', short_name: '通', start_time: '11:00', end_time: '22:30', break_minutes: 120, crosses_midnight: false, counts_as_night: false, color: '#6aa84f', active: true }
];
const state = {
  schedule: { id: 7, start_date: '2026-11-02', end_date: '2026-11-15', status: 'draft', published_at: null },
  entries: [], saved: null, statusPut: null
};
const model = () => ({
  schedule: state.schedule, staff, patterns,
  rules: {
    global: { max_consecutive_days: 5, min_rest_hours: 8, monthly_days_off: 4 }, defs: [],
    required_staff: [
      { id: 1, active: true, hard: true, params: { weekday: -1, date: '', start: '11:00', end: '15:00', count: 2, role: '', qualification: '' } },
      { id: 2, active: true, hard: true, params: { weekday: -1, date: '', start: '17:00', end: '22:30', count: 3, role: '', qualification: '' } }
    ]
  },
  // スタッフ1は 11/4 が出勤不可、スタッフ2は 11/5 が希望休
  requests: [{ staff_id: 100, date: '2026-11-04', kind: 'ng', source: 'staff' }, { staff_id: 101, date: '2026-11-05', kind: 'off', source: 'staff' }],
  entries: state.entries
});

const config = {
  page: 'schedule-edit', rest: 'http://127.0.0.1:__PORT__/api/', nonce: 'x', writable: true, schedule_id: 7,
  worker_url: 'http://127.0.0.1:__PORT__/assets/solver-worker.js', back_url: '/back', today: '2026-10-20'
};

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://x');
  if (url.pathname.startsWith('/assets/')) {
    const f = path.join(ASSETS, path.basename(url.pathname));
    if (fs.existsSync(f)) { res.writeHead(200, { 'Content-Type': f.endsWith('.css') ? 'text/css' : 'application/javascript' }); return res.end(fs.readFileSync(f)); }
    res.writeHead(404); return res.end();
  }
  if (url.pathname.startsWith('/api/')) {
    let body = '';
    req.on('data', c => body += c);
    req.on('end', () => {
      const json = (o, code) => { res.writeHead(code || 200, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' }); res.end(JSON.stringify(o)); };
      const p = url.pathname.replace('/api/', '');
      if (req.method === 'GET' && p === 'schedules/7') return json(model());
      if (req.method === 'POST' && p === 'schedules/7/entries') { state.saved = JSON.parse(body).entries; state.entries = state.saved; return json({ ok: true, saved: state.saved.length }); }
      if (req.method === 'PUT' && p === 'schedules/7') { state.statusPut = JSON.parse(body).status; state.schedule = Object.assign({}, state.schedule, { status: state.statusPut }); return json({ schedule: state.schedule }); }
      return json({ message: 'not found: ' + req.method + ' ' + p }, 404);
    });
    return;
  }
  const port = server.address().port;
  const html = `<!doctype html><html lang="ja"><head><meta charset="utf-8"><link rel="stylesheet" href="/assets/app.css"></head><body class="ss"><main class="ss-main"><section class="ss-card"><h1>シフト表の編集</h1><div id="ss-notice" class="ss-alert" hidden></div><div id="ss-root"></div></section></main>
<script>window.SS_CONFIG=${JSON.stringify(config).replace(/__PORT__/g, port)};</script>
<script src="/assets/ui.js"></script><script src="/assets/solver.js"></script><script src="/assets/schedule.js"></script></body></html>`;
  if (url.pathname === '/assets/app.css') { res.writeHead(200, { 'Content-Type': 'text/css' }); return res.end(fs.readFileSync(path.join(ASSETS, 'app.css'))); }
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(html);
});

(async () => {
  await new Promise(r => server.listen(0, '127.0.0.1', r));
  const port = server.address().port;
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1300, height: 900 } });
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('dialog', d => d.accept());
  const cell = (si, di) => page.locator('tbody tr').nth(si).locator('button.ss-cell').nth(di);
  const txt = async (loc) => (await loc.textContent()).trim();

  await page.goto('http://127.0.0.1:' + port + '/');
  await page.waitForSelector('table.ss-grid');
  check('grid has 8 staff rows and 14 date columns', (await page.locator('tbody tr').count()) === 8 && (await page.locator('thead th').count()) === 15);
  check('hard-off request shown (gray stripe)', await cell(0, 2).evaluate(e => e.classList.contains('req-ng')));
  check('soft-off request shown (red stripe)', await cell(1, 3).evaluate(e => e.classList.contains('req-off')));

  // セルを編集：スタッフ1の11/4（出勤不可の日）にランチを入れる → 必須違反の赤枠
  await cell(0, 2).click();
  await page.waitForSelector('.ss-panel select[name=kind]');
  await page.selectOption('select[name=kind]', '1');
  await page.getByRole('button', { name: '反映', exact: true }).click();
  check('cell shows pattern short name', (await txt(cell(0, 2))) === 'ラ');
  await page.waitForTimeout(400);
  check('work on unavailable day is highlighted as hard violation', await cell(0, 2).evaluate(e => e.classList.contains('v-hard')));
  check('summary shows hard violation count', (await page.locator('.ss-stats').textContent()).includes('必須ルール違反：1件'));

  // 休みに戻す
  await cell(0, 2).click();
  await page.selectOption('select[name=kind]', '');
  await page.getByRole('button', { name: '反映', exact: true }).click();
  check('cell cleared', (await txt(cell(0, 2))) === '');

  // カスタム時間
  await cell(2, 0).click();
  await page.selectOption('select[name=kind]', 'custom');
  await page.selectOption('select[name=start]', '10:30');
  await page.selectOption('select[name=end]', '14:00');
  await page.getByRole('button', { name: '反映', exact: true }).click();
  check('custom time shown as range', (await txt(cell(2, 0))).includes('10:30-14'));

  // 固定した休み
  await cell(3, 0).click();
  await page.selectOption('select[name=kind]', '');
  await page.check('input[name=lock]');
  await page.getByRole('button', { name: '反映', exact: true }).click();
  check('locked off shown as 休 with lock mark', (await txt(cell(3, 0))) === '休' && await cell(3, 0).evaluate(e => e.classList.contains('is-locked')));

  // 自動作成
  await page.getByRole('button', { name: '自動作成', exact: true }).first().click();
  await page.selectOption('select[name=sec]', '10');
  await page.getByRole('button', { name: '自動作成を実行' }).click();
  await page.waitForSelector('text=作成した案', { timeout: 30000 });
  const props = await page.locator('button', { hasText: 'この案を使う' }).count();
  check('3 proposals offered', props === 3);
  await page.locator('button', { hasText: 'この案を使う' }).first().click();
  await page.waitForTimeout(800);
  const stats = await page.locator('.ss-stats').textContent();
  console.log('  after auto-create:', stats.replace(/\s+/g, ' '));
  check('auto-create result has no hard violations', stats.includes('必須ルール違反：0件'));
  check('auto-create result has no shortage', stats.includes('人数不足：0人時間'));
  check('locked off cell survived auto-create', (await txt(cell(3, 0))) === '休');
  check('unavailable day (staff1 11/4) stays empty', (await txt(cell(0, 2))) === '');
  const worked = await page.locator('button.ss-cell.has-work').count();
  check('many cells now have work', worked > 25, 'worked=' + worked);

  // 元に戻す
  check('undo button visible', await page.getByRole('button', { name: '元に戻す' }).isVisible());

  // 保存
  await page.getByRole('button', { name: '保存', exact: true }).click();
  await page.waitForSelector('text=保存しました');
  check('saved to server', state.saved && state.saved.length > 25);
  check('saved entries use valid shape', state.saved.every(e => e.staff_id >= 100 && /^\d{4}-\d{2}-\d{2}$/.test(e.date) && (e.start_time === '' ? e.locked : /^\d{2}:\d{2}$/.test(e.start_time))));
  check('locked off saved with empty times', state.saved.some(e => e.locked && e.start_time === '' && e.staff_id === 103 && e.date === '2026-11-02'));
  check('no unsaved-changes label after save', !(await page.locator('.ss-badge').last().textContent()).includes('未保存'));

  // 公開
  await page.getByRole('button', { name: '公開する' }).click();
  await page.waitForSelector('text=公開しました');
  check('published', state.statusPut === 'published' && (await page.locator('.ss-badge').last().textContent()).includes('公開中'));

  await page.locator('.ss-table-wrap').first().screenshot({ path: path.join(process.env.SS_SHOT_DIR || '/tmp', 'schedule-grid.png') });
  await page.screenshot({ path: path.join(process.env.SS_SHOT_DIR || '/tmp', 'schedule-editor.png'), fullPage: false });
  check('no JavaScript errors in the page', errors.length === 0, errors.join(' | '));

  await browser.close();
  server.close();
  console.log(failed ? '\n' + failed + ' FAILED' : '\nAll passed');
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
