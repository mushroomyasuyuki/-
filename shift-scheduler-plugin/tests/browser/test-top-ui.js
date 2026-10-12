// お客様のトップページ（templates/app-top.php）を、本物のブラウザで確かめる。
// 実行：NODE_PATH=<playwrightのある場所> node tests/browser/test-top-ui.js
const fs = require('fs'); const os = require('os'); const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');
let failed = 0;
function check(n, c, extra) { console.log((c ? 'PASS ' : 'FAIL ') + n + (!c && extra ? '  ' + extra : '')); if (!c) failed++; }
(async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'top-'));
  const files = {};
  for (const [k, arg] of [['admin', '1'], ['staff', '']]) {
    files[k] = path.join(dir, k + '.html');
    fs.writeFileSync(files[k], execFileSync('php', [path.join(__dirname, 'render-top.php'), arg]));
  }
  const browser = await chromium.launch(process.env.PLAYWRIGHT_BROWSERS_PATH ? {} : { executablePath: '/opt/pw-browsers/chromium' });
  const page = await browser.newPage({ viewport: { width: 1100, height: 900 } });
  await page.goto('file://' + files.admin);
  const labels = sel => page.$$eval(sel, els => els.map(e => e.querySelector('.label').childNodes[0].textContent.trim()));
  const sections = await page.$$eval('.section-title', els => els.map(e => e.textContent));
  check('manager: 従業員ページ and 管理者ページ are shown', JSON.stringify(sections) === JSON.stringify(['従業員ページ', '管理者ページ']));
  const staffLabels = await page.$$eval('.section:nth-of-type(1) .nav-card .label', els => els.map(e => e.childNodes[0].textContent.trim()));
  check('従業員ページ: 日報・朝礼・経理・タイムカード・給与照会・シフト', JSON.stringify(staffLabels) === JSON.stringify(['日報', '朝礼', '経理', 'タイムカード', '給与照会', 'シフト']), JSON.stringify(staffLabels));
  const adminLabels = await page.$$eval('.section:nth-of-type(2) .nav-card .label', els => els.map(e => e.childNodes[0].textContent.trim()));
  check('管理者ページ: 従業員登録・管理者集計・シフト作成・給与計算・QRコード生成・自社情報', JSON.stringify(adminLabels) === JSON.stringify(['従業員登録', '管理者集計', 'シフト作成', '給与計算', 'QRコード生成', '自社情報']), JSON.stringify(adminLabels));
  const labelsList = await labels('a.nav-card');
  check('all features are links; a card the person may not use shows why (not 準備中) and is not a link', ['経理', 'タイムカード', '給与照会', 'QRコード生成', '自社情報'].every(l => labelsList.includes(l)) && !labelsList.includes('給与計算')
    && (await page.$$('.is-soon')).length === 1 && (await page.textContent('.is-soon .soon')) === '契約者の許可が必要');
  check('シフト作成 links to the shift tables', (await page.getAttribute('a.c-shift >> text=シフト作成', 'href')) === null || (await page.$eval('.section:nth-of-type(2) a.c-shift', a => a.getAttribute('href'))).endsWith('/schedules/'));
  await page.setViewportSize({ width: 375, height: 800 });
  check('phone width: one column, no sideways scrolling', !(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)));
  await page.goto('file://' + files.staff);
  check('staff: only 従業員ページ is shown', JSON.stringify(await page.$$eval('.section-title', els => els.map(e => e.textContent))) === JSON.stringify(['従業員ページ']));
  await browser.close();
  console.log(failed ? '\n' + failed + ' FAILED' : '\nAll passed');
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
