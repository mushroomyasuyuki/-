// 自動作成・違反チェックのテスト。実行：node tests/test-solver.js
const S = require('../assets/solver.js');
let failed = 0;
function check(name, cond, extra) { console.log((cond ? 'PASS ' : 'FAIL ') + name + (extra && !cond ? '  ' + extra : '')); if (!cond) failed++; }
const ALL = [0, 1, 2, 3, 4, 5, 6];
function staff(n, o) { const a = []; for (let i = 0; i < n; i++) a.push(Object.assign({ id: i + 1, weekdays: ALL, nightOk: true, roles: [], qualifications: [] }, o ? o(i) : {})); return a; }
function req(days, f) { const a = []; for (let d = 0; d < days; d++) f(d).forEach(r => a.push(Object.assign({ day: d }, r))); return a; }

/* ---- 1. 違反の検出（手で作った割り当て） ---- */
(function () {
  const input = { startDate: '2026-11-01', days: 10, staff: staff(1),
    patterns: [{ id: 1, start: 540, end: 1080, breakMin: 60, night: false }, { id: 2, start: 960, end: 2340, breakMin: 120, night: true }],
    rules: { maxConsecutive: 3, minRestHours: 11, maxNights: 1, daysOff: null, afterNightOff: true }, required: [], hardOff: [[0, 5]], softOff: [[0, 6]] };
  const a = new Array(10).fill(-1);
  a[0] = a[1] = a[2] = a[3] = 0;          // 4連勤（上限3）
  a[5] = 0;                               // 出勤不可の日
  a[6] = 0;                               // 希望休の日
  let ev = S.evaluate(input, a);
  const types = ev.violations.map(v => v.type);
  check('detects consecutive limit', types.includes('consecutive'));
  check('detects hard off day', types.includes('ng'));
  check('detects soft off day (not hard)', ev.violations.some(v => v.type === 'off' && !v.hard));

  const b = new Array(10).fill(-1);
  b[1] = 1; b[2] = 0;                      // 夜勤の翌日に日勤（夜勤明けに勤務）
  ev = S.evaluate(input, b);
  check('detects work on the day after a night shift', ev.violations.some(v => v.type === 'after_night'));
  const c = new Array(10).fill(-1);
  c[1] = 1; c[3] = 0;                      // 夜勤→明け→翌日に勤務
  ev = S.evaluate(input, c);
  check('detects work on the 2nd day after a night shift', ev.violations.some(v => v.type === 'after_night_off'));
  const d = new Array(10).fill(-1);
  d[1] = 1; d[4] = 1; d[7] = 1;            // 夜勤3回（上限1）
  ev = S.evaluate(input, d);
  check('detects night count over limit', ev.violations.some(v => v.type === 'nights' && v.units === 2));
  const e = new Array(10).fill(-1);
  e[2] = 0; e[3] = 0;                      // 18:00終了→翌9:00開始＝15時間：OK
  check('rest ok with 15h gap', !S.evaluate(input, e).violations.some(v => v.type === 'rest'));
  input.patterns.push({ id: 3, start: 360, end: 900, breakMin: 60, night: false }); // 6:00開始
  const f = new Array(10).fill(-1);
  f[2] = 0; f[3] = 2;                      // 18:00終了→翌6:00開始＝12時間：OK、5:00開始なら不可
  check('rest 12h ok (limit 11h)', !S.evaluate(input, f).violations.some(v => v.type === 'rest'));
  input.rules.minRestHours = 13;
  check('rest 12h violates when limit 13h', S.evaluate(input, f).violations.some(v => v.type === 'rest'));
})();

(function () {
  const input = { startDate: '2026-11-01', days: 30, staff: staff(1, () => ({ nightOk: false })),
    patterns: [{ id: 1, start: 960, end: 2340, breakMin: 120, night: true }], rules: { daysOff: 8 }, required: [] };
  const a = new Array(30).fill(-1);
  for (let d = 0; d < 25; d++) a[d] = 0;
  const ev = S.evaluate(input, a);
  check('days off shortfall detected (25 worked, 8 required)', ev.violations.some(v => v.type === 'days_off' && v.units === 3));
  check('night by non-night staff detected', ev.violations.some(v => v.type === 'night_ok'));
})();

/* ---- 2. 飲食：12名・30日。ランチ2名、ディナー4名（金土は5名） ---- */
(function () {
  const days = 30;
  const input = {
    startDate: '2026-11-01', days,
    staff: staff(12, i => ({ roles: i < 6 ? ['ホール'] : ['キッチン'] })),
    patterns: [
      { id: 1, start: 660, end: 900, breakMin: 0, night: false },     // ランチ 11:00-15:00
      { id: 2, start: 1020, end: 1350, breakMin: 0, night: false },   // ディナー 17:00-22:30
      { id: 3, start: 660, end: 1350, breakMin: 120, night: false }   // 通し
    ],
    rules: { maxConsecutive: 6, minRestHours: 8, maxNights: null, daysOff: 8, afterNightOff: false },
    required: req(days, d => {
      const dow = (0 + d) % 7; // 11/1 は日曜
      return [{ start: 660, end: 900, count: 2, role: '', qual: '' },
              { start: 1020, end: 1350, count: dow === 5 || dow === 6 ? 5 : 4, role: '', qual: '' },
              { start: 1020, end: 1350, count: 1, role: 'キッチン', qual: '' }];
    }),
    hardOff: [], softOff: []
  };
  // 休み希望：スタッフ0は毎週水曜が出勤不可、スタッフ1は11/3が希望休
  for (let d = 0; d < days; d++) if (d % 7 === 3) input.hardOff.push([0, d]);
  input.softOff.push([1, 2]);
  const t = Date.now();
  const res = S.solve(input, { timeMs: 6000, proposals: 3 });
  console.log('  restaurant: best cost=' + res[0].cost + ' hard=' + res[0].hard + ' shortage=' + res[0].shortage + ' soft=' + res[0].soft + ' iter=' + res[0].iterations + ' (' + (Date.now() - t) + 'ms)');
  check('restaurant: 3 proposals sorted by cost', res.length === 3 && res[0].cost <= res[1].cost && res[1].cost <= res[2].cost);
  check('restaurant: no hard violations', res[0].hard === 0);
  check('restaurant: no shortage', res[0].shortage === 0, 'shortage=' + res[0].shortage);
  const ev = S.evaluate(input, res[0].assign);
  check('restaurant: evaluate agrees with solve', ev.hard === res[0].hard && ev.shortage === res[0].shortage);
  check('restaurant: hard off respected', [0, 7, 14, 21, 28].every(d => 3 + d >= 30 || res[0].assign[0 * days + 3 + d] === -1));
})();

/* ---- 3. 介護：16名（看護師4名）・30日。夜勤2名、各時間帯に看護師1名以上 ---- */
(function () {
  const days = 30;
  const input = {
    startDate: '2026-11-01', days,
    staff: staff(16, i => ({ qualifications: i < 4 ? ['看護師', '介護福祉士'] : (i < 10 ? ['介護福祉士'] : []) })),
    patterns: [
      { id: 1, start: 420, end: 960, breakMin: 60, night: false },     // 早番 7:00-16:00
      { id: 2, start: 540, end: 1080, breakMin: 60, night: false },    // 日勤
      { id: 3, start: 660, end: 1200, breakMin: 60, night: false },    // 遅番 11:00-20:00
      { id: 4, start: 960, end: 1980, breakMin: 120, night: true }     // 夜勤 16:00-翌9:00
    ],
    rules: { maxConsecutive: 5, minRestHours: 11, maxNights: 8, daysOff: 8, afterNightOff: true },
    required: req(days, d => [
      { start: 420, end: 960, count: 3, role: '', qual: '' },          // 7-16時 3名
      { start: 960, end: 1440 + 540, count: 2, role: '', qual: '' },   // 16時〜翌9時 2名（夜勤）
      { start: 540, end: 1080, count: 1, role: '', qual: '看護師' }     // 9-18時に看護師1名
    ]),
    hardOff: [], softOff: []
  };
  const t = Date.now();
  const res = S.solve(input, { timeMs: 9000, proposals: 3 });
  console.log('  care: best cost=' + res[0].cost + ' hard=' + res[0].hard + ' shortage=' + res[0].shortage + ' soft=' + res[0].soft + ' iter=' + res[0].iterations + ' (' + (Date.now() - t) + 'ms)');
  check('care: no hard violations', res[0].hard === 0, 'hard=' + res[0].hard);
  check('care: shortage is small (<= 2 slots)', res[0].shortage <= 2, 'shortage=' + res[0].shortage);
  // 夜勤の連続・夜勤明けの勤務がないこと
  const a = res[0].assign; let bad = 0;
  for (let s = 0; s < 16; s++) for (let d = 0; d < days - 2; d++) {
    if (a[s * days + d] === 3 && (a[s * days + d + 1] !== -1 || a[s * days + d + 2] !== -1)) bad++;
  }
  check('care: nothing scheduled on the 2 days after a night shift', bad === 0, 'bad=' + bad);
})();

/* ---- 4. 固定した勤務・希望は守られる ---- */
(function () {
  const days = 14;
  const input = { startDate: '2026-11-01', days, staff: staff(5),
    patterns: [{ id: 1, start: 540, end: 1080, breakMin: 60, night: false }],
    rules: { maxConsecutive: 5, minRestHours: 10, daysOff: 4 },
    required: req(days, () => [{ start: 540, end: 1080, count: 2, role: '', qual: '' }]),
    hardOff: [], softOff: [],
    locked: [{ s: 0, d: 3, p: 0 }, { s: 1, d: 3, p: -1 }, { s: 1, d: 4, p: -1 }] };
  const res = S.solve(input, { timeMs: 2000, proposals: 1 });
  check('locked work kept', res[0].assign[0 * days + 3] === 0);
  check('locked off kept', res[0].assign[1 * days + 3] === -1 && res[0].assign[1 * days + 4] === -1);
  check('simple case fully satisfied', res[0].hard === 0 && res[0].shortage === 0);
})();

console.log(failed ? '\n' + failed + ' FAILED' : '\nAll passed');
process.exit(failed ? 1 : 0);
