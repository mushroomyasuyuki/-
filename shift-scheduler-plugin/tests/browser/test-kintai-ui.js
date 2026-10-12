// タイムカード（QRから開く打刻ページ・ログインしての打刻）と、QRコード画面を、本物のブラウザで動かして確かめる。
// APIは、このファイルの中の模擬サーバー。実行：NODE_PATH=<playwrightのある場所> node tests/browser/test-kintai-ui.js
const http = require('http');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');
const ROOT = path.join(__dirname, '..', '..');
let failed = 0;
function check(n, c, extra) { console.log((c ? 'PASS ' : 'FAIL ') + n + (!c && extra ? '  ' + extra : '')); if (!c) failed++; }

let card = null, calls = [], outside = [], reissued = 0;
function api(req, p, q, body, json) {
  if (p === 'punch' && req.method === 'GET') {
    return json({ staff: { id: '2', name: '佐藤', dept: '製作' }, company: 'テスト株式会社', today: card, history: card ? [card] : [], date: '2026-10-12', writable: true });
  }
  if (p === 'punch' && req.method === 'POST') {
    if (body.kind === 'in') { if (card) return json({ error: '本日の出勤は、すでに記録されています。' }, 409); card = { id: 1, date: '2026-10-12', staffId: '2', staffName: '佐藤', dept: '製作', clockIn: '08:59', clockOut: '' }; return json(Object.assign({}, card, { time: '08:59', locationSaved: [] })); }
    card.clockOut = '18:01'; return json(Object.assign({}, card, { time: '18:01', locationSaved: [] }));
  }
  if (p === 'punch-keys/reissue' && req.method === 'POST') { reissued++; return json([{ id: String(body.staffId), url: 'https://example.test/s/abcdefghjkmn/punch/?s=' + body.staffId + '&k=' + 'd'.repeat(32) }]); }
  return json({ error: 'not found' }, 404);
}
const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://x');
  if (url.pathname.startsWith('/assets/')) { res.writeHead(200, { 'Content-Type': 'application/javascript' }); return res.end(fs.readFileSync(path.join(ROOT, 'assets', path.basename(url.pathname)))); }
  if (url.pathname.startsWith('/wp-json/mo/v1/')) {
    let b = ''; req.on('data', c => b += c);
    req.on('end', () => {
      const p = url.pathname.replace('/wp-json/mo/v1/', '');
      calls.push({ m: req.method, p, q: Object.fromEntries(url.searchParams), nonce: req.headers['x-wp-nonce'] || '' });
      let body = {}; try { body = b ? JSON.parse(b) : {}; } catch (e) {}
      api(req, p, url.searchParams, body, (o, code) => { res.writeHead(code || 200, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(o)); });
    });
    return;
  }
  let html = '';
  if (url.pathname === '/punch') html = execFileSync('php', [path.join(__dirname, 'render-punch.php')]).toString();
  else if (url.pathname === '/timecard') html = execFileSync('php', [path.join(__dirname, 'render-office.php'), 'timecard.html', '', '']).toString();
  else if (url.pathname === '/qr') html = execFileSync('php', [path.join(__dirname, 'render-office.php'), 'qr-generate.html', '1', '', 'qr']).toString();
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(html || 'top');
});

(async () => {
  await new Promise(r => server.listen(0, r));
  const base = 'http://127.0.0.1:' + server.address().port;
  const browser = await chromium.launch(process.env.PLAYWRIGHT_BROWSERS_PATH ? {} : { executablePath: '/opt/pw-browsers/chromium' });
  const ctx = await browser.newContext({ viewport: { width: 420, height: 860 } });
  ctx.on('request', r => { if (!r.url().startsWith(base) && !r.url().startsWith('data:')) outside.push(r.url()); });

  // 1. QRから開く打刻ページ（ログインなし）
  let page = await ctx.newPage();
  let errors = []; page.on('pageerror', e => errors.push(e.message));
  await page.goto(base + '/punch');
  await page.waitForSelector('.punch-btn.in');
  check('punch page: shows the staff name and the clock-in button', (await page.textContent('#timecard-app')).includes('佐藤さん'));
  check('punch page: API is called with the QR signature (t, s, k) and without a login nonce', calls.length > 0 && calls.every(c => c.q.k === 'c'.repeat(32) && c.q.t === 'abcdefghjkmn' && c.q.s === '2' && c.nonce === ''), JSON.stringify(calls[0]));
  await page.click('.punch-btn.in');
  await page.waitForSelector('.punch-btn.out');
  check('punch page: clock-in recorded with the time decided by the server', (await page.textContent('.msg')).includes('出勤を記録しました（08:59）'));
  const post = calls.find(c => c.m === 'POST');
  check('punch page: only the kind (and location) is sent, not a time', post && post.p === 'punch');
  await page.click('.punch-btn.out');
  await page.waitForSelector('.punch-btn[disabled]');
  check('punch page: clock-out recorded, then the day is complete', (await page.textContent('.msg')).includes('退勤を記録しました') && (await page.textContent('.punch-btn')).includes('完了'));
  check('punch page: no JavaScript errors', errors.length === 0, errors.join(' | '));
  if (process.env.SS_SHOT_DIR) await page.screenshot({ path: path.join(process.env.SS_SHOT_DIR, 'kintai-punch.png'), fullPage: true });
  await page.close();

  // 2. ログインしての打刻（ナンスを付け、署名は付けない）
  card = null; calls = [];
  page = await ctx.newPage(); errors = []; page.on('pageerror', e => errors.push(e.message));
  await page.goto(base + '/timecard');
  await page.waitForSelector('.punch-btn.in');
  check('timecard (logged in): API called with the login nonce, no QR signature', calls.length > 0 && calls.every(c => c.nonce === 'nonce-123' && !c.q.k));
  check('timecard (logged in): top bar and back link to the top page', (await page.textContent('#mo-bar')).includes('テスト株式会社') && (await page.getAttribute('.back-link a', 'href')) === '/s/abcdefghjkmn/');
  check('timecard (logged in): no JavaScript errors', errors.length === 0, errors.join(' | '));
  await page.close();

  // 3. QRコード画面
  calls = [];
  page = await ctx.newPage(); errors = []; page.on('pageerror', e => errors.push(e.message));
  page.on('dialog', d => d.accept());
  await page.goto(base + '/qr');
  await page.waitForSelector('.qr-card img');
  const imgs = await page.$$eval('.qr-card img', els => els.map(e => e.getAttribute('src')));
  check('qr: one QR image per staff member, made in the page (data URL)', imgs.length === 2 && imgs.every(s => s.startsWith('data:image/')));
  check('qr: names are shown as text (no HTML injection)', (await page.$$eval('.staff-name', els => els.map(e => e.innerHTML)))[1] === '&lt;b&gt;佐藤&lt;/b&gt;');
  await page.click('.qr-card:nth-child(2) .reissue');
  await page.waitForFunction(old => document.querySelectorAll('.qr-card img')[1].getAttribute('src') !== old, imgs[1]);
  check('qr: “作り直す” asks the server for a new signature and redraws that QR', reissued === 1 && calls.some(c => c.p === 'punch-keys/reissue' && c.nonce === 'nonce-123'));
  check('qr: no JavaScript errors', errors.length === 0, errors.join(' | '));
  if (process.env.SS_SHOT_DIR) await page.screenshot({ path: path.join(process.env.SS_SHOT_DIR, 'kintai-qr.png'), fullPage: true });
  await page.close();

  check('no request leaves this site (no external QR service)', outside.length === 0, outside.join(', '));
  await browser.close(); server.close();
  console.log(failed ? '\n' + failed + ' FAILED' : '\nAll passed');
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
