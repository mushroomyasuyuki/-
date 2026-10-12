// マッシュオフィスシステムの実際の画面（日報・予定進捗・朝礼・管理者集計）を、本物のブラウザで動かし、
// 下準備（office-shim.js）によって、このサービスのAPI（mo/v1）を正しく呼ぶことを確かめる。
// APIは、このファイルの中の模擬サーバー。実行：NODE_PATH=<playwrightのある場所> node tests/browser/test-office-ui.js
const http = require('http');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');
const ROOT = path.join(__dirname, '..', '..');
let failed = 0;
function check(n, c, extra) { console.log((c ? 'PASS ' : 'FAIL ') + n + (!c && extra ? '  ' + extra : '')); if (!c) failed++; }

const today = new Date(); const pad = n => String(n).padStart(2, '0');
const TODAY = today.getFullYear() + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate());
const employees = [{ id: '1', employee_number: 101, name: '山田', department: '営業', position: '' }, { id: '2', employee_number: 102, name: '佐藤', department: '製作', position: '' }];
let tasks = [{ id: 1, date: TODAY, staffId: '1', staffName: '山田', dept: '営業', category: '営業', client: '顧客X', project: '案件1', start: '09:00', end: '12:00', status: '予定', isOutside: false, area: '', prefecture: '', travelType: '', note: '', bulkMode: false, bulkCount: '', progress: 0, currentDay: 0, totalDays: 0, dueDate: '', holidayWork: false, earlyWork: false, nightWork: false, images: [], imagesCleared: false, createdAt: '', updatedAt: '' }];
let sched = [{ id: 1, date: TODAY, staffId: '2', staffName: '佐藤', dept: '製作', name: '図面', start: '10:00', end: '11:00', dueDate: '', memo: '', progress: 30, createdAt: '', updatedAt: '' }];
let calls = [], bad = [], missing = [];
function api(method, p, body, res, json) {
  if (method === 'GET' && p === 'employees') return json(employees);
  if (method === 'GET' && p === 'departments') return json([{ name: '営業', band: 100, fg: '#0369a1', bg: '#e0f2fe', builtin: false, employeeCount: 1 }, { name: '製作', band: 200, fg: '#047857', bg: '#d1fae5', builtin: false, employeeCount: 1 }]);
  if (method === 'GET' && p === 'company-info') return json({ name: 'テスト株式会社', weeklyHolidays: [0], holidayDates: [], workStartTime: '09:00', workEndTime: '18:00', breakMinutes: 60 });
  if (method === 'GET' && p === 'company-name') return json({ name: 'テスト株式会社' });
  if (method === 'GET' && p === 'task-records') return json(tasks);
  if (method === 'POST' && p === 'task-records/upsert') { const t = Object.assign({}, tasks[0], body, { id: tasks.length + 1, staffName: '佐藤' }); tasks.push(t); return json(t); }
  if (method === 'POST' && /^task-records\/\d+\/admin-update$/.test(p)) { const t = tasks.find(x => x.id === +p.split('/')[1]); Object.assign(t, body); return json(t); }
  if (method === 'GET' && p === 'schedule-tasks') return json(sched);
  if (method === 'POST' && p === 'schedule-tasks/upsert') { const s = Object.assign({ id: sched.length + 1 }, body); sched.push(s); return json(s); }
  if (method === 'GET' && p === 'activity-log') return json([]);
  if (method === 'POST' && p === 'activity-log') return json({ logged: true });
  if (method === 'GET' && ['dm/threads', 'dm/messages', 'chat-messages/unread-staff-ids', 'time-cards'].includes(p)) return json([]);
  if (method === 'GET' && p === 'backup/status') return json({ lastDownloadAt: '2026-10-12 09:00:00', managed: true });
  missing.push(method + ' ' + p);
  return json({ error: 'not found' }, 404);
}
const PAGES = { nippo: 'nippo1-fixed.html', yotei: 'yotei-jisseki-input.html', chorei: 'chorei-fixed.html', shukei: 'kanri-shukei.html' };
const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://x');
  if (url.pathname.startsWith('/assets/')) {
    res.writeHead(200, { 'Content-Type': 'application/javascript' }); return res.end(fs.readFileSync(path.join(ROOT, 'assets', path.basename(url.pathname))));
  }
  if (url.pathname.includes('mush-office/v1')) { bad.push('old API called: ' + url.pathname); res.writeHead(500); return res.end('{}'); }
  if (url.pathname.startsWith('/wp-json/mo/v1/')) {
    let b = '';
    req.on('data', c => b += c);
    req.on('end', () => {
      const p = url.pathname.replace('/wp-json/mo/v1/', '');
      calls.push({ m: req.method, p, q: url.search });
      if (req.headers['x-wp-nonce'] !== 'nonce-123') bad.push('no nonce: ' + p);
      if (req.headers['authorization']) bad.push('Authorization header sent: ' + p);
      let body = {}; try { body = b ? JSON.parse(b) : {}; } catch (e) {}
      api(req.method, p, body, res, (o, code) => { res.writeHead(code || 200, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(o)); });
    });
    return;
  }
  const m = url.pathname.match(/^\/s\/abcdefghjkmn\/(\w+)\/?$/);
  const key = m ? m[1] : '';
  if (PAGES[key]) {
    const html = execFileSync('php', [path.join(__dirname, 'render-office.php'), PAGES[key], key === 'shukei' ? '1' : '', url.searchParams.get('ro') ? '1' : '']).toString();
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); return res.end(html);
  }
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end('<!doctype html><title>dashboard</title><p>DASHBOARD ' + url.pathname + '</p>');
});

(async () => {
  await new Promise(r => server.listen(0, r));
  const base = 'http://127.0.0.1:' + server.address().port;
  const browser = await chromium.launch(process.env.PLAYWRIGHT_BROWSERS_PATH ? {} : { executablePath: '/opt/pw-browsers/chromium' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.route(/cdnjs\.cloudflare\.com/, r => r.fulfill({ status: 200, contentType: 'application/javascript', body: 'window.XLSX = {};' })); // 外部のExcel部品は、テストでは空にする
  // 同じブラウザに、従来のマッシュオフィスシステムのログイン情報が残っている場合を再現する
  await ctx.addInitScript(() => { if (!sessionStorage.getItem('seeded')) { localStorage.setItem('mush_nippo_auth', JSON.stringify({ token: 'OLD-SYSTEM', staffId: '99', staffName: '旧', dept: '', expiresAt: Date.now() + 1e8 })); sessionStorage.setItem('seeded', '1'); } });

  for (const key of Object.keys(PAGES)) {
    calls = []; bad = []; missing = [];
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(base + '/s/abcdefghjkmn/' + key + '/');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);
    check(key + ': no JavaScript errors', errors.length === 0, errors.join(' | '));
    check(key + ': calls go to the company API (mo/v1) with the nonce, never the old API, no token header', calls.length > 0 && bad.length === 0, bad.join(', '));
    check(key + ': no call to an endpoint the new API lacks', missing.length === 0, missing.join(', '));
    const loginShown = await page.evaluate(() => Array.from(document.querySelectorAll('input[type=password]')).some(el => el.offsetParent !== null));
    check(key + ': the old login form is not shown (signed in by this service)', !loginShown);
    const ls = await page.evaluate(() => { const o = {}; for (let i = 0; i < localStorage.length; i++) { const k = localStorage.key(i); o[k] = localStorage[k]; } return o; });
    check(key + ': saved login info is kept per company; the old system\'s entry is left untouched', ls['mo:abcdefghjkmn:mush_nippo_auth'] && JSON.parse(ls['mo:abcdefghjkmn:mush_nippo_auth']).staffId === '2' && JSON.parse(ls['mush_nippo_auth']).token === 'OLD-SYSTEM');
    if (key === 'shukei') { check('shukei: admin login info is prepared for managers', !!ls['mo:abcdefghjkmn:mush_admin_auth']); }
    check(key + ': top bar with the company name is shown', (await page.textContent('#mo-bar')).includes('テスト株式会社'));
    if (process.env.SS_SHOT_DIR) { await page.screenshot({ path: path.join(process.env.SS_SHOT_DIR, 'office-' + key + '.png'), fullPage: false }); }
    const visibleOld = await page.evaluate(() => {
      const vis = el => el && el.offsetParent !== null;
      return {
        chat: vis(document.querySelector('.chat-panel')), backup: vis(document.querySelector('.backup-panel')),
        logout: Array.from(document.querySelectorAll('button')).some(b => vis(b) && /ログアウト/.test(b.textContent)),
      };
    });
    check(key + ': chat (not moved yet), backup and the screen\'s own logout buttons are hidden', !visibleOld.chat && !visibleOld.backup && !visibleOld.logout, JSON.stringify(visibleOld));
    if (key === 'nippo') {
      check('nippo: today\'s records and the roster are loaded', calls.some(c => c.p === 'task-records') && calls.some(c => c.p === 'employees'));
      await page.evaluate(() => { const a = document.createElement('a'); a.href = '/トップ/'; a.id = 'x-top'; a.textContent = 'top'; document.body.appendChild(a); });
      await page.click('#x-top');
      await page.waitForURL(/\/s\/abcdefghjkmn\/$/);
      check('a link to the old “/トップ/” goes to this service\'s dashboard', page.url().endsWith('/s/abcdefghjkmn/'));
    }
    await page.close();
  }

  // 閲覧のみ：上にお知らせが出る
  const p2 = await ctx.newPage();
  await p2.goto(base + '/s/abcdefghjkmn/nippo/?ro=1');
  check('read-only: a notice is shown at the top', !(await p2.isHidden('#mo-notice')) && (await p2.textContent('#mo-notice')).includes('閲覧のみ'));
  await p2.close();

  await browser.close(); server.close();
  console.log(failed ? '\n' + failed + ' FAILED' : '\nAll passed');
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
