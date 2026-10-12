// 「スタッフ管理」の画面（社員番号・部署・編集・パスワード設定・ログインURL）を、本物のブラウザで確かめる。
// 画面は、実際のテンプレート（templates/app-staff.php）をPHPで描画して使う。APIは模擬。
// 実行：NODE_PATH=<playwrightのある場所> node tests/browser/test-staff-ui.js
const http = require('http');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');
const ASSETS = path.join(__dirname, '..', '..', 'assets');
let failed = 0;
function check(n, c, extra) { console.log((c ? 'PASS ' : 'FAIL ') + n + (!c && extra ? '  ' + extra : '')); if (!c) failed++; }

const body = execFileSync('php', [path.join(__dirname, 'render-staff.php')]).toString();
const PAGE = '<!doctype html><html lang="ja"><head><meta charset="utf-8"><link rel="stylesheet" href="/assets/app.css"></head><body class="ss"><main class="ss-main">' + body + '</main><script src="/assets/staff.js"></script></body></html>';

let staff = [], nextId = 1, calls = [];
function present(s) { return Object.assign({ kana: '', department: '', employee_number: '', employment: 'part', roles: [], qualifications: [], night_ok: true, active: true, account: 'none', email: '', staff_login: false }, s); }
const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://x');
  if (url.pathname.startsWith('/assets/')) {
    const f = path.join(ASSETS, path.basename(url.pathname));
    res.writeHead(200, { 'Content-Type': f.endsWith('.css') ? 'text/css' : 'application/javascript' }); return res.end(fs.readFileSync(f));
  }
  if (url.pathname.startsWith('/api/')) {
    let b = '';
    req.on('data', c => b += c);
    req.on('end', () => {
      const json = (o, code) => { res.writeHead(code || 200, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(o)); };
      const p = url.pathname.replace('/api/', ''); const d = b ? JSON.parse(b) : {};
      calls.push({ m: req.method, p, d });
      if (req.method === 'GET' && p === 'staff') return json({ staff });
      if (req.method === 'POST' && p === 'staff') {
        if (d.employee_number && staff.some(s => s.employee_number === d.employee_number)) return json({ code: 'empno_used', message: 'この社員番号は、すでに別のスタッフに使われています。' }, 409);
        const s = present(Object.assign({ id: nextId++ }, d)); staff.push(s); return json({ staff: s });
      }
      let m = p.match(/^staff\/(\d+)$/);
      if (m && req.method === 'PUT') { const s = staff.find(x => x.id === +m[1]); Object.assign(s, d); return json({ staff: s }); }
      if (req.method === 'GET' && p === 'staff/export') return json({ filename: 'staff-20261012.csv', csv: '"社員番号","名前"\r\n"101","山田太郎"\r\n' });
      if (req.method === 'POST' && p === 'staff/import') {
        if (d.dry_run) return json({ created: 1, updated: 1, errors: [], applied: false });
        staff.push(present({ id: nextId++, name: d.rows[2][1], employee_number: d.rows[2][0] }));
        return json({ created: 1, updated: 1, errors: [], applied: true });
      }
      m = p.match(/^staff\/(\d+)\/password$/);
      if (m && req.method === 'POST') { const s = staff.find(x => x.id === +m[1]); s.account = 'active'; s.staff_login = true; return json({ ok: true }); }
      return json({ message: 'not found' }, 404);
    });
    return;
  }
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(PAGE);
});

(async () => {
  await new Promise(r => server.listen(0, r));
  const base = 'http://127.0.0.1:' + server.address().port + '/';
  const browser = await chromium.launch(process.env.PLAYWRIGHT_BROWSERS_PATH ? {} : { executablePath: '/opt/pw-browsers/chromium' });
  const page = await browser.newPage({ viewport: { width: 1200, height: 900 }, acceptDownloads: true });
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('dialog', async d => { await d.accept(page.__answer || ''); });
  await page.goto(base);
  await page.waitForSelector('#ss-rows');
  check('キャンセル is hidden before editing', await page.isHidden('#ss-cancel'));

  check('login URL for staff is shown', (await page.inputValue('#ss-login-url')) === 'https://mush-office.com/s/abcdefghjkmn/login/');
  check('table has 社員番号 and 部署 columns', (await page.textContent('thead')).includes('社員番号') && (await page.textContent('thead')).includes('部署'));

  await page.fill('input[name=employee_number]', '101');
  await page.fill('input[name=name]', '山田太郎');
  await page.fill('input[name=department]', 'ホール');
  await page.click('#ss-submit');
  await page.waitForFunction(() => document.querySelector('#ss-rows').textContent.includes('山田太郎'));
  const post = calls.find(c => c.m === 'POST' && c.p === 'staff');
  check('add sends employee number and department', post && post.d.employee_number === '101' && post.d.department === 'ホール');
  const row = await page.textContent('#ss-rows tr');
  check('row shows number, name, department; login not set yet', row.includes('101') && row.includes('ホール') && row.includes('未設定'));

  await page.fill('input[name=employee_number]', '101');
  await page.fill('input[name=name]', '重複次郎');
  await page.click('#ss-submit');
  await page.waitForFunction(() => !document.getElementById('ss-notice').hidden);
  check('duplicate number: error is shown', (await page.textContent('#ss-notice')).includes('すでに別のスタッフ'));

  await page.click('#ss-rows tr button:has-text("編集")');
  check('edit: form is filled and the button says 保存', (await page.inputValue('input[name=name]')) === '山田太郎' && (await page.textContent('#ss-submit')) === '保存' && !(await page.isHidden('#ss-cancel')));
  await page.fill('input[name=department]', '厨房');
  await page.click('#ss-submit');
  await page.waitForFunction(() => document.querySelector('#ss-rows').textContent.includes('厨房'));
  const put = calls.find(c => c.m === 'PUT');
  check('edit: saved with PUT to the same staff; form goes back to 追加', put && put.p === 'staff/1' && put.d.department === '厨房' && (await page.textContent('#ss-submit')) === '追加');
  check('edit: キャンセル is hidden again after saving', await page.isHidden('#ss-cancel'));

  page.__answer = 'secret-pass-1';
  await page.click('#ss-rows tr button:has-text("パスワード設定")');
  await page.waitForFunction(() => document.querySelector('#ss-rows').textContent.includes('利用中'));
  const pw = calls.find(c => c.p === 'staff/1/password');
  check('password: sent for that staff; row shows 利用中：社員番号', pw && pw.d.password === 'secret-pass-1' && (await page.textContent('#ss-rows tr')).includes('利用中：社員番号'));
  check('password: button turns into パスワード再設定', (await page.textContent('#ss-rows tr')).includes('パスワード再設定'));

  await page.click('#ss-copy-url');
  await page.waitForFunction(() => document.getElementById('ss-notice').textContent.includes('コピー'));
  check('copy button gives feedback', true);

  // CSV：Excelで保存した Shift_JIS のファイルを取り込む
  const sjis = path.join(require('os').tmpdir(), 'staff-sjis.csv');
  const utf8 = sjis + '.utf8';
  fs.writeFileSync(utf8, '社員番号,名前,部署\r\n101,山田太郎,ホール\r\n102,"鈴木, 花子",キッチン\r\n');
  execFileSync('php', ['-r', 'file_put_contents($argv[2], mb_convert_encoding(file_get_contents($argv[1]), "SJIS-win", "UTF-8"));', utf8, sjis]);
  page.__answer = '';
  await page.setInputFiles('#ss-import', sjis);
  await page.waitForFunction(() => document.getElementById('ss-import-result').textContent.includes('取り込みました'));
  const imp = calls.filter(c => c.p === 'staff/import');
  check('CSV import: Shift_JIS is decoded (names readable, quoted comma kept)', imp.length === 2 && imp[0].d.dry_run === true && imp[0].d.rows[1][1] === '山田太郎' && imp[0].d.rows[2][1] === '鈴木, 花子');
  check('CSV import: checked first (dry run), applied after confirming', imp[1].d.dry_run === undefined && (await page.textContent('#ss-import-result')).includes('追加：1人'));
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('#ss-export')]);
  const buf = fs.readFileSync(await dl.path());
  check('CSV export: saved with BOM (opens in Excel without garbled text) and the given filename', buf[0] === 0xEF && buf[1] === 0xBB && buf[2] === 0xBF && dl.suggestedFilename() === 'staff-20261012.csv' && buf.toString('utf8').includes('山田太郎'));

  await page.setViewportSize({ width: 375, height: 800 });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  check('phone width: the login URL box does not overflow the page', !overflow || (await page.evaluate(() => document.querySelector('.ss-login-url').getBoundingClientRect().right <= window.innerWidth + 1)));
  if (process.env.SS_SHOT_DIR) { await page.screenshot({ path: path.join(process.env.SS_SHOT_DIR, 'staff-ui.png'), fullPage: true }); }

  check('no JavaScript errors', errors.length === 0, errors.join(' | '));
  await browser.close(); server.close();
  console.log(failed ? '\n' + failed + ' FAILED' : '\nAll passed');
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
