// 「ご契約・お支払い」の画面を、本物のブラウザ（Chromium）で動かして確かめるテスト。
// PAY.JPの入力部品（payjp.js）と、サーバーのAPIは、このファイルの中の模擬で置き換える。
// 実行：NODE_PATH=<playwrightのある場所> node tests/browser/test-billing-ui.js
const http = require('http');
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const ASSETS = path.join(__dirname, '..', '..', 'assets');
let failed = 0;
function check(n, c, extra) { console.log((c ? 'PASS ' : 'FAIL ') + n + (!c && extra ? '  ' + extra : '')); if (!c) failed++; }

const plans = [
  { id: 1, name: 'ライト', price: 550, price_ex: 500, max_staff: 50, fits: true, ready: true },
  { id: 2, name: 'スタンダード', price: 1100, price_ex: 1000, max_staff: 100, fits: true, ready: true },
  { id: 3, name: '準備中', price: 2200, price_ex: 2000, max_staff: 200, fits: true, ready: false },
  { id: 4, name: '小規模', price: 330, price_ex: 300, max_staff: 2, fits: false, ready: true }
];
const TERMS = [
  { title: '料金', body: 'ライト：月額550円（税込。税抜500円）、スタッフ50名まで\nスタンダード：月額1,100円（税込。税抜1,000円）、スタッフ100名まで\n消費税率は10％です（表示の金額は税込です）。' },
  { title: '無料期間', body: '登録の日から2か月間は、すべての機能を無料でご利用いただけます。' },
  { title: '請求の開始', body: '無料期間中にお申し込みいただいた場合は、無料期間の終了日から請求が始まります。' },
  { title: 'お支払い方法', body: 'クレジットカード（決済代行サービス「PAY.JP」を通じて処理します）。' },
  { title: 'お支払いに失敗したとき', body: '7日間は、引き続きご利用いただけます。' },
  { title: 'プランの変更', body: '新しい料金は、次回の請求日から適用されます。' },
  { title: '解約', body: 'お支払い済みの期間の終了日まで、引き続きご利用いただけます。\n日割りによる返金は行いません。' },
  { title: '料金の改定', body: '料金を改定する場合は、事前にお知らせします。' }
];
let S, posts;
function reset(over) {
  S = Object.assign({
    configured: true, public_key: 'pk_test_abc', status: 'trial', trial_end: '2026-12-31 00:00:00', trial_days_left: 40, trial_running: true,
    has_subscription: false, plan_id: null, next_billing_at: null, cancel_at: null, grace_since: null, staff_count: 3, plans: plans, tax_rate: 10, terms: TERMS
  }, over || {});
  posts = [];
}
let nextError = null;
let LEGAL = { tokushoho: { label: '特定商取引法に基づく表記', url: 'https://example.test/tokushoho/' }, terms: { label: '利用規約', url: 'https://example.test/terms/' }, privacy: { label: 'プライバシーポリシー', url: 'https://example.test/privacy/' } };

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://x');
  if (url.pathname.startsWith('/assets/')) {
    const f = path.join(ASSETS, path.basename(url.pathname));
    res.writeHead(200, { 'Content-Type': f.endsWith('.css') ? 'text/css' : 'application/javascript' }); return res.end(fs.readFileSync(f));
  }
  if (url.pathname.startsWith('/api/')) {
    let body = '';
    req.on('data', c => body += c);
    req.on('end', () => {
      const json = (o, code) => { res.writeHead(code || 200, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(o)); };
      const p = url.pathname.replace('/api/', '');
      if (req.method === 'GET' && p === 'billing') return json(S);
      if (req.method === 'POST' && p.startsWith('billing/')) {
        const b = body ? JSON.parse(body) : {};
        posts.push({ path: p, body: b });
        if (nextError) { const e = nextError; nextError = null; return json({ code: 'payjp_card_declined', message: e }, 400); }
        if (p === 'billing/subscribe') Object.assign(S, { has_subscription: true, plan_id: b.plan_id, status: 'trial', next_billing_at: S.trial_end });
        if (p === 'billing/plan') S.plan_id = b.plan_id;
        if (p === 'billing/cancel') S.cancel_at = '2026-12-31 00:00:00';
        if (p === 'billing/cancel/undo') S.cancel_at = null;
        if (p === 'billing/card' && S.status === 'grace') { S.status = 'active'; S.grace_since = null; }
        return json({ ok: true });
      }
      return json({ message: 'not found' }, 404);
    });
    return;
  }
  const port = server.address().port;
  const cfg = { page: 'billing', rest: 'http://127.0.0.1:' + port + '/api/', nonce: 'x', writable: true, today: '2026-10-20', legal: LEGAL };
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
  res.end(`<!doctype html><html lang="ja"><head><meta charset="utf-8"><link rel="stylesheet" href="/assets/app.css"></head><body class="ss"><main class="ss-main"><section class="ss-card"><h1>ご契約・お支払い</h1><div id="ss-notice" class="ss-alert" hidden></div><div id="ss-root"></div></section></main>
<script>window.SS_CONFIG=${JSON.stringify(cfg)};
window.__payjpKeys=[]; window.__mounted=0; window.__created=[]; window.__tokenFrom=[];
window.Payjp=function(key){window.__payjpKeys.push(key);return{elements:function(){return{create:function(type){
  if(window.__noSplit&&type!=='card'){throw new Error('unsupported element type: '+type);}
  if(['card','cardNumber','cardExpiry','cardCvc'].indexOf(type)<0){throw new Error('unknown type '+type);}
  window.__created.push(type);
  return{_type:type,mount:function(sel){if(typeof sel!=='string'){throw new Error('mountにはセレクタ文字列（#id）を指定してください');}var el=document.querySelector(sel);if(!el){throw new Error('mountに指定されたセレクタが現在ページに存在しません。');}window.__mounted++;el.setAttribute('data-mounted','1');}};}}},
  createToken:function(el){window.__tokenFrom.push(el&&el._type);return Promise.resolve({id:window.__tokenResult||'tok_test123'});}};};
</script>
<script src="/assets/ui.js"></script><script src="/assets/pages.js"></script></body></html>`);
});

(async () => {
  await new Promise(r => server.listen(0, '127.0.0.1', r));
  const base = 'http://127.0.0.1:' + server.address().port + '/';
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1000, height: 1100 } });
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));
  // 400は、カードエラーを意図的に起こした場面のブラウザのログ
  page.on('console', m => { if (m.type() === 'error' && !/favicon|status of 400/.test(m.text())) errors.push(m.text()); });
  page.on('dialog', d => d.accept());
  const text = async () => (await page.locator('#ss-root').textContent()).replace(/\s+/g, ' ');

  /* 1. 無料期間中・未契約 */
  reset();
  await page.goto(base);
  await page.waitForSelector('#ss-card-number[data-mounted]', { state: 'attached' });
  let t = await text();
  check('shows status and trial days', t.includes('無料期間中') && t.includes('あと40日'));
  check('explains: no charge until trial end', t.includes('請求は無料期間の終了日') && t.includes('12月31日'));
  check('no error notice right after the page opens (card input mounted without error)', await page.locator('#ss-notice').isHidden());
  const boxes = [];
  for (const id of ['ss-card-number', 'ss-card-expiry', 'ss-card-cvc']) {
    boxes.push(await page.locator('#' + id).evaluate(e => { const c = getComputedStyle(e); return { id: e.id, border: c.borderTopWidth, bg: c.backgroundColor, h: e.getBoundingClientRect().height, w: e.getBoundingClientRect().width }; }));
  }
  check('three separate card boxes, each with a visible border, white background and enough height', boxes.every(b => b.border === '1px' && b.bg === 'rgb(255, 255, 255)' && b.h >= 50), JSON.stringify(boxes));
  check('expiry and CVC boxes are half width, side by side (card number is full width)', boxes[0].w > boxes[1].w * 1.8 && Math.abs(boxes[1].w - boxes[2].w) < 4, JSON.stringify(boxes));
  const noteText = await page.locator('.ss-card-note').first().textContent();
  check('card form states that PAY.JP processes the card data and the site stores none', noteText.includes('決済代行サービス') && noteText.includes('PAY.JP') && noteText.includes('PAY.JPに直接送信') && noteText.includes('保存することもありません'));
  check('PAY.JP link opens safely in a new tab', await page.locator('.ss-card-note a').first().evaluate(a => a.href === 'https://pay.jp/' && a.target === '_blank' && /noopener/.test(a.rel)));
  check('the notice is shown above the card boxes', await page.evaluate(() => { const n = document.querySelector('.ss-card-note').getBoundingClientRect().top, c = document.querySelector('#ss-card-number').getBoundingClientRect().top; return n < c; }));
  check('each box has its own label', ['カード番号', '有効期限（月 / 年）', 'セキュリティコード（CVC）'].every(l => t.includes(l)));
  check('split elements created: number, expiry, cvc — all three mounted', (await page.evaluate(() => window.__created)).join() === 'cardNumber,cardExpiry,cardCvc' && (await page.locator('[data-mounted]').count()) === 3);
  check('card input mounted with the public key', (await page.evaluate(() => window.__payjpKeys)).join() === 'pk_test_abc');
  const radios = page.locator('input[name=plan]');
  check('4 plans listed; not-ready and too-small plans are disabled', (await radios.count()) === 4 && await radios.nth(2).isDisabled() && await radios.nth(3).isDisabled() && !(await radios.nth(0).isDisabled()));
  check('first selectable plan is preselected', await radios.nth(0).isChecked());
  check('plan choices show TAX-INCLUDED price first, with the tax-excluded price', t.includes('ライト 月額550円（税込） ※税抜500円') || t.includes('ライト　月額550円（税込）　※税抜500円'));
  check('standard plan: 1,100円 (税込) / 税抜1,000円', t.includes('1,100円（税込）') && t.includes('税抜1,000円'));
  check('terms block is open on the subscribe screen and lists all seven items (+ price revision)', await page.locator('details.ss-terms').getAttribute('open') !== null && (await page.locator('.ss-terms-h').allTextContents()).slice(0, 7).join('|') === '料金|無料期間|請求の開始|お支払い方法|お支払いに失敗したとき|プランの変更|解約');
  check('terms text shows the tax-included prices and the trial/billing/cancel wording', (await page.locator('details.ss-terms').textContent()).includes('月額550円（税込。税抜500円）') && (await page.locator('details.ss-terms').textContent()).includes('無料期間の終了日から請求が始まります') && (await page.locator('details.ss-terms').textContent()).includes('日割りによる返金は行いません'));
  check('unavailable plans show the reason', t.includes('現在お選びいただけません') && t.includes('スタッフ数が上限を超えています'));

  const lk = await page.locator('.ss-legal-links a').evaluateAll(as => as.map(a => [a.textContent, a.href, a.target, a.rel]));
  check('before subscribing: links to 特商法・利用規約・プライバシーポリシー (in this order, new tab, noopener)', lk.length === 3 && lk[0][0] === '特定商取引法に基づく表記' && lk[1][0] === '利用規約' && lk[2][0] === 'プライバシーポリシー' && lk.every(x => x[2] === '_blank' && /noopener/.test(x[3])) && lk[0][1] === 'https://example.test/tokushoho/');
  check('the links are shown above the card form', await page.evaluate(() => document.querySelector('.ss-legal-links').getBoundingClientRect().top < document.querySelector('#ss-card-number').getBoundingClientRect().top));

  await page.screenshot({ path: path.join(process.env.SS_SHOT_DIR || '/tmp', 'billing-subscribe.png'), fullPage: true });
  /* 2. 契約する */
  await radios.nth(1).check();
  await page.getByRole('button', { name: 'カードを登録して契約する' }).click();
  await page.waitForSelector('text=ご契約を受け付けました');
  check('token is created from the card number element', (await page.evaluate(() => window.__tokenFrom)).join() === 'cardNumber');
  check('subscribe sends chosen plan and token (no card number)', posts.length === 1 && posts[0].path === 'billing/subscribe' && posts[0].body.plan_id === 2 && posts[0].body.card_token === 'tok_test123' && Object.keys(posts[0].body).sort().join() === 'card_token,plan_id');
  t = await text();
  check('after subscribing: management view (plan, card, cancel)', t.includes('スタンダード') && t.includes('1,100円（税込）') && t.includes('カードの変更') && t.includes('解約') && (await page.getByRole('button', { name: 'カードを登録して契約する' }).count()) === 0);

  check('terms block is also on the management screen (collapsed)', (await page.locator('details.ss-terms').count()) === 1 && (await page.locator('details.ss-terms').getAttribute('open')) === null);
  /* 3. プラン変更 */
  await page.locator('input[name=plan]').nth(0).check();
  await page.getByRole('button', { name: 'このプランに変更する' }).click();
  await page.waitForSelector('text=プランを変更しました');
  check('change plan posts new plan id', posts[posts.length - 1].path === 'billing/plan' && posts[posts.length - 1].body.plan_id === 1);
  await page.getByRole('button', { name: 'このプランに変更する' }).click();
  await page.waitForSelector('text=現在と違うプランを選んでください');
  check('choosing the same plan is refused without calling the server', posts[posts.length - 1].path === 'billing/plan' && posts.filter(p => p.path === 'billing/plan').length === 1);

  check('the same notice is also on the card-change form', (await page.locator('.ss-card-note').count()) === 1 && (await page.locator('.ss-card-note').first().textContent()).includes('PAY.JP'));
  /* 4. カード変更 */
  await page.getByRole('button', { name: 'カードを変更する' }).click();
  await page.waitForSelector('text=カードを変更しました');
  check('card change posts token only', posts[posts.length - 1].path === 'billing/card' && Object.keys(posts[posts.length - 1].body).join() === 'card_token');

  /* 5. 解約と取り消し */
  await page.getByRole('button', { name: '解約する' }).click();
  await page.waitForSelector('text=解約を受け付けました');
  t = await text();
  check('cancel reserved: shows the end date, no charge afterwards, undo available', t.includes('12月31日') && t.includes('以後の請求は発生しません') && (await page.getByRole('button', { name: '解約を取り消す' }).isVisible()));
  check('while cancel is reserved, plan/card forms are hidden', (await page.getByRole('button', { name: 'カードを変更する' }).count()) === 0);
  await page.getByRole('button', { name: '解約を取り消す' }).click();
  await page.waitForSelector('text=解約を取り消しました');
  check('undo posts to cancel/undo and returns to management view', posts[posts.length - 1].path === 'billing/cancel/undo' && (await page.getByRole('button', { name: '解約する' }).isVisible()));

  /* 6. カードが通らない */
  reset();
  await page.goto(base);
  await page.waitForSelector('#ss-card-number[data-mounted]', { state: 'attached' });
  nextError = 'カードが承認されませんでした。別のカードをお試しいただくか、カード会社にご確認ください。';
  await page.getByRole('button', { name: 'カードを登録して契約する' }).click();
  await page.waitForSelector('text=カードが承認されませんでした');
  check('card error message is shown; still on the subscribe form; button usable again', (await page.getByRole('button', { name: 'カードを登録して契約する' }).isEnabled()) && (await page.locator('#ss-notice').getAttribute('class')).includes('ss-alert-error'));

  /* リンク先が見つからないページは、リンクを出さない */
  LEGAL = { terms: { label: '利用規約', url: 'https://example.test/terms/' } };
  reset();
  await page.goto(base);
  await page.waitForSelector('#ss-card-number[data-mounted]', { state: 'attached' });
  const lk2 = await page.locator('.ss-legal-links a').evaluateAll(as => as.map(a => a.textContent));
  check('missing pages are simply not linked (no empty or broken links)', lk2.length === 1 && lk2[0] === '利用規約');
  LEGAL = {};
  await page.goto(base);
  await page.waitForSelector('#ss-card-number[data-mounted]', { state: 'attached' });
  check('with no legal pages at all, the sentence is not shown', (await page.locator('.ss-legal-links').count()) === 0);
  LEGAL = { tokushoho: { label: '特定商取引法に基づく表記', url: 'https://example.test/tokushoho/' }, terms: { label: '利用規約', url: 'https://example.test/terms/' }, privacy: { label: 'プライバシーポリシー', url: 'https://example.test/privacy/' } };

  /* 7. 設定前・閲覧のみ・支払い確認中 */
  reset({ configured: false });
  await page.goto(base);
  await page.waitForSelector('#ss-root dl');
  t = await text();
  check('not configured: friendly notice, no forms', t.includes('お支払いの準備中です') && (await page.locator('#ss-card-number').count()) === 0);

  reset({ status: 'readonly', trial_running: false, trial_days_left: null });
  await page.goto(base);
  await page.waitForSelector('#ss-card-number[data-mounted]', { state: 'attached' });
  t = await text();
  check('readonly: explains and offers immediate subscribe; says first charge happens at signup', t.includes('閲覧のみの状態') && t.includes('最初のご請求が行われます'));

  reset({ status: 'grace', has_subscription: true, plan_id: 1, grace_since: '2026-10-15 00:00:00', trial_running: false, trial_days_left: null });
  await page.goto(base);
  await page.waitForSelector('#ss-card-number[data-mounted]', { state: 'attached' });
  t = await text();
  check('grace: asks to update the card, with a deadline 7 days after grace start', t.includes('お支払いを確認できませんでした') && t.includes('10月22日') && t.includes('お支払い確認中'));
  await page.getByRole('button', { name: 'カードを変更する' }).click();
  await page.waitForSelector('text=カードを変更しました');
  check('after card update the status returns to active', (await text()).includes('ご契約中'));

  /* 分割型が使えない場合は、まとまった入力欄に切り替わる */
  const page2 = await browser.newPage({ viewport: { width: 1000, height: 1100 } });
  await page2.addInitScript(() => { window.__noSplit = true; });
  page2.on('pageerror', e => errors.push(String(e)));
  reset();
  await page2.goto(base);
  await page2.waitForSelector('#ss-card-number[data-mounted]', { state: 'attached' });
  check('fallback: combined card element used, expiry/CVC boxes hidden, no error shown', (await page2.evaluate(() => window.__created)).join() === 'card' && await page2.locator('#ss-card-expiry-wrap').isHidden() && await page2.locator('#ss-card-cvc-wrap').isHidden() && await page2.locator('#ss-notice').isHidden());
  await page2.getByRole('button', { name: 'カードを登録して契約する' }).click();
  await page2.waitForSelector('text=ご契約を受け付けました');
  check('fallback: subscribing still works', posts.length === 1 && posts[0].body.card_token === 'tok_test123' && (await page2.evaluate(() => window.__tokenFrom)).join() === 'card');
  await page.screenshot({ path: path.join(process.env.SS_SHOT_DIR || '/tmp', 'billing-page.png') });
  check('no JavaScript errors', errors.length === 0, errors.join(' | '));
  await browser.close(); server.close();
  console.log(failed ? '\n' + failed + ' FAILED' : '\nAll passed');
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
