/**
 * シフトの自動作成と、ルール違反のチェック（ブラウザ・Web Worker・Node.js のどれでも動く）。
 *
 * 入力（input）
 *   startDate  'YYYY-MM-DD'（シフトの開始日）  days  日数（62以内）
 *   staff      [{ id, weekdays:[勤務できる曜日の番号 0=日〜6=土], nightOk, roles:[], qualifications:[] }]
 *   patterns   [{ id, start, end, breakMin, night, custom }]
 *              start/end は「その日の0:00からの分」。end が start 以下の勤務は日をまたぐので呼び出し側で +1440 しておく
 *              custom=true は、勤務区分にない時間の勤務（既存の手入力）。自動作成では新たに割り当てない
 *   rules      { maxConsecutive, minRestHours, maxNights, daysOff, afterNightOff }（無いものは null）
 *   required   [{ day, start, end, count, role, qual }]（日ごとに展開済みの必要人数。end<=start は翌日にまたぐ）
 *   hardOff    [[staffIdx, day], ...]  出勤不可（管理者設定の休みを含む）
 *   softOff    [[staffIdx, day], ...]  スタッフの希望休
 *   locked     [{ s, d, p }]  固定する勤務（p=-1 は固定の休み）
 *   initial    [{ s, d, p }]  開始時点の割り当て（固定以外）
 * 割り当て（assign）：長さ staff×days の配列。値は patterns の番号、休みは -1。 index = s*days + d
 */
(function (root) {
  'use strict';

  var W = { short: 10, over: 1, softOff: 30, hard: 200 };
  var SLOT = 30;

  function mulberry32(a) {
    return function () {
      a |= 0; a = a + 0x6D2B79F5 | 0;
      var t = Math.imul(a ^ a >>> 15, 1 | a);
      t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
      return ((t ^ t >>> 14) >>> 0) / 4294967296;
    };
  }

  function parseDate(s) { var p = s.split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])); }

  function prepare(input) {
    var D = input.days, S = input.staff.length;
    var ctx = { input: input, D: D, S: S };
    var start = parseDate(input.startDate);
    ctx.dow = [];
    ctx.monthIdx = [];
    var monthKeys = {}, months = [];
    for (var d = 0; d < D; d++) {
      var dt = new Date(start.getTime() + d * 86400000);
      ctx.dow.push(dt.getUTCDay());
      var key = dt.getUTCFullYear() * 100 + dt.getUTCMonth();
      if (!(key in monthKeys)) {
        monthKeys[key] = months.length;
        months.push({ count: 0, dim: new Date(Date.UTC(dt.getUTCFullYear(), dt.getUTCMonth() + 1, 0)).getUTCDate() });
      }
      ctx.monthIdx.push(monthKeys[key]);
      months[monthKeys[key]].count++;
    }
    ctx.months = months;
    var r = input.rules || {};
    ctx.rules = {
      maxConsecutive: r.maxConsecutive == null ? null : +r.maxConsecutive,
      restMin: r.minRestHours == null ? null : +r.minRestHours * 60,
      maxNights: r.maxNights == null ? null : +r.maxNights,
      afterNightOff: !!r.afterNightOff
    };
    ctx.monthReqOff = months.map(function (m) {
      return r.daysOff == null ? 0 : Math.floor(+r.daysOff * m.count / m.dim);
    });

    ctx.wdOk = input.staff.map(function (st) {
      var ok = new Uint8Array(7);
      (st.weekdays || [0, 1, 2, 3, 4, 5, 6]).forEach(function (w) { if (w >= 0 && w <= 6) { ok[w] = 1; } });
      return ok;
    });
    ctx.pats = input.patterns;
    ctx.free = [];
    ctx.pats.forEach(function (p, i) { if (!p.custom) { ctx.free.push(i); } });

    ctx.hard = new Uint8Array(S * D);
    ctx.soft = new Uint8Array(S * D);
    (input.hardOff || []).forEach(function (x) { ctx.hard[x[0] * D + x[1]] = 1; });
    (input.softOff || []).forEach(function (x) { ctx.soft[x[0] * D + x[1]] = 1; });
    ctx.locked = new Uint8Array(S * D);
    ctx.assign = new Int16Array(S * D).fill(-1);
    (input.initial || []).forEach(function (x) { ctx.assign[x.s * D + x.d] = x.p; });
    (input.locked || []).forEach(function (x) { ctx.locked[x.s * D + x.d] = 1; ctx.assign[x.s * D + x.d] = x.p; });

    // 必要人数
    ctx.req = [];
    ctx.reqByDay = [];
    for (d = 0; d < D; d++) { ctx.reqByDay.push([]); }
    (input.required || []).forEach(function (q) {
      var a = q.day * 1440 + q.start;
      var b = q.day * 1440 + (q.end <= q.start ? q.end + 1440 : q.end);
      var n = Math.max(0, Math.floor((b - a) / SLOT));
      var elig = new Uint8Array(S), list = [];
      input.staff.forEach(function (s, i) {
        var ok = (!q.role || s.roles.indexOf(q.role) >= 0) && (!q.qual || s.qualifications.indexOf(q.qual) >= 0);
        if (ok) { elig[i] = 1; list.push(i); }
      });
      var idx = ctx.req.length;
      ctx.req.push({ day: q.day, a: a, b: b, n: n, count: q.count, role: q.role || '', qual: q.qual || '', elig: elig, list: list, have: new Int16Array(n), shortSum: 0 });
      ctx.reqByDay[q.day].push(idx);
    });

    ctx.rowC = new Float64Array(S);
    ctx.cov = 0;
    ctx.tmpWork = new Int16Array(months.length);
    ctx.tmpNight = new Int16Array(months.length);
    return ctx;
  }

  function slotPen(count, have) {
    return have < count ? W.short * (count - have) : W.over * (have - count);
  }

  /** 1つの勤務（staff si, day d, pattern p）を、必要人数のカウントに足す(sign=+1)／引く(sign=-1)。コストの増減を返す。 */
  function applyShift(ctx, si, d, p, sign) {
    if (p < 0) { return 0; }
    var pat = ctx.pats[p], D = ctx.D, delta = 0;
    var sa = d * 1440 + pat.start, sb = d * 1440 + pat.end;
    for (var dd = Math.max(0, d - 1); dd <= Math.min(D - 1, d + 1); dd++) {
      var list = ctx.reqByDay[dd];
      for (var k = 0; k < list.length; k++) {
        var rq = ctx.req[list[k]];
        if (!rq.elig[si]) { continue; }
        var a = Math.max(sa, rq.a), b = Math.min(sb, rq.b);
        if (b <= a) { continue; }
        for (var t = a; t < b; t += SLOT) {
          var i = (t - rq.a) / SLOT;
          var h = rq.have[i];
          var before = slotPen(rq.count, h);
          var nh = h + sign;
          rq.have[i] = nh;
          delta += slotPen(rq.count, nh) - before;
          rq.shortSum += (nh < rq.count ? rq.count - nh : 0) - (h < rq.count ? rq.count - h : 0);
        }
      }
    }
    return delta;
  }

  /** 1人分の行のペナルティ。out を渡すと、違反の一覧を詰める。 */
  function rowCost(ctx, si, out) {
    var D = ctx.D, base = si * D, cost = 0, run = 0;
    var st = ctx.input.staff[si], pats = ctx.pats, R = ctx.rules;
    var work = ctx.tmpWork, nights = ctx.tmpNight;
    work.fill(0); nights.fill(0);

    function H(d, type, msg, units) {
      cost += W.hard * units;
      if (out) { out.push({ s: si, d: d, type: type, msg: msg, hard: true, units: units }); }
    }

    for (var d = 0; d < D; d++) {
      var c = ctx.assign[base + d];
      if (c < 0) { run = 0; continue; }
      var pat = pats[c];
      if (!ctx.wdOk[si][ctx.dow[d]]) { H(d, 'weekday', 'この曜日は勤務できません', 1); }
      if (ctx.hard[base + d]) { H(d, 'ng', '出勤不可・休みの日です', 1); }
      else if (ctx.soft[base + d]) {
        cost += W.softOff;
        if (out) { out.push({ s: si, d: d, type: 'off', msg: '希望休の日です', hard: false, units: 1 }); }
      }
      if (pat.night && !st.nightOk) { H(d, 'night_ok', '夜勤に入れないスタッフです', 1); }

      if (d >= 1) {
        var pc = ctx.assign[base + d - 1];
        if (pc >= 0) {
          var pp = pats[pc];
          if (R.afterNightOff && pp.night && pp.end > 1440) { H(d, 'after_night', '夜勤明けの日は勤務できません', 1); }
          if (R.restMin != null) {
            var gap = (d * 1440 + pat.start) - ((d - 1) * 1440 + pp.end);
            if (gap < R.restMin) { H(d, 'rest', '前の勤務との間隔が短すぎます（' + Math.max(0, Math.round(gap / 6) / 10) + '時間）', 1); }
          }
        }
      }
      if (d >= 2 && R.afterNightOff) {
        var pc2 = ctx.assign[base + d - 2];
        if (pc2 >= 0 && pats[pc2].night && pats[pc2].end > 1440) { H(d, 'after_night_off', '夜勤明けの翌日は休みです', 1); }
      }

      run++;
      if (R.maxConsecutive != null && run > R.maxConsecutive) { H(d, 'consecutive', '連勤の上限（' + R.maxConsecutive + '日）を超えています', 1); }
      work[ctx.monthIdx[d]]++;
      if (pat.night) { nights[ctx.monthIdx[d]]++; }
    }

    for (var m = 0; m < ctx.months.length; m++) {
      if (R.maxNights != null && nights[m] > R.maxNights) {
        H(-1, 'nights', '夜勤が' + nights[m] + '回で上限（' + R.maxNights + '回）を超えています', nights[m] - R.maxNights);
      }
      var off = ctx.months[m].count - work[m];
      if (ctx.monthReqOff[m] > 0 && off < ctx.monthReqOff[m]) {
        H(-1, 'days_off', '公休が' + off + '日で、必要な' + ctx.monthReqOff[m] + '日に足りません', ctx.monthReqOff[m] - off);
      }
    }
    return cost;
  }

  function initState(ctx) {
    var D = ctx.D, S = ctx.S;
    ctx.cov = 0;
    ctx.req.forEach(function (rq) {
      rq.have.fill(0); rq.shortSum = 0;
      for (var i = 0; i < rq.n; i++) { rq.shortSum += rq.count; }
      ctx.cov += rq.n * slotPen(rq.count, 0);
    });
    for (var s = 0; s < S; s++) {
      for (var d = 0; d < D; d++) { ctx.cov += applyShift(ctx, s, d, ctx.assign[s * D + d], +1); }
      ctx.rowC[s] = rowCost(ctx, s, null);
    }
    var total = ctx.cov;
    for (s = 0; s < S; s++) { total += ctx.rowC[s]; }
    ctx.total = total;
  }

  function summarize(ctx) {
    var hard = 0, soft = 0, s, out = [];
    for (s = 0; s < ctx.S; s++) { rowCost(ctx, s, out); }
    out.forEach(function (v) { if (v.hard) { hard += v.units; } else { soft += v.units; } });
    var shortage = 0;
    ctx.req.forEach(function (rq) { shortage += rq.shortSum; });
    return { hard: hard, soft: soft, shortage: shortage, violations: out };
  }

  /** 割り当てを評価して、違反と不足の一覧を返す（画面の表示用）。 */
  function evaluate(input, assign) {
    var ctx = prepare(input);
    for (var i = 0; i < assign.length && i < ctx.assign.length; i++) { ctx.assign[i] = assign[i]; }
    initState(ctx);
    var sum = summarize(ctx);
    var shortages = [];
    ctx.req.forEach(function (rq) {
      var i = 0;
      while (i < rq.n) {
        if (rq.have[i] < rq.count) {
          var j = i, minHave = rq.have[i];
          while (j + 1 < rq.n && rq.have[j + 1] < rq.count) { j++; minHave = Math.min(minHave, rq.have[j]); }
          shortages.push({ day: rq.day, from: rq.a + i * SLOT - rq.day * 1440, to: rq.a + (j + 1) * SLOT - rq.day * 1440, need: rq.count, have: minHave, role: rq.role, qual: rq.qual });
          i = j + 1;
        } else { i++; }
      }
    });
    return { hard: sum.hard, soft: sum.soft, shortage: sum.shortage, violations: sum.violations, shortages: shortages };
  }

  /** 焼きなまし法で、1つの案を探す。 */
  function anneal(ctx, seed, timeMs, onTick) {
    var rnd = mulberry32(seed), D = ctx.D, S = ctx.S;
    var free = ctx.free;
    initState(ctx);
    var best = Int16Array.from(ctx.assign), bestCost = ctx.total, lastSnap = 0;
    var t0 = Date.now(), iter = 0, T0 = 60, T1 = 0.4, T = T0;
    var movable = [];
    for (var i = 0; i < S * D; i++) { if (!ctx.locked[i]) { movable.push(i); } }
    if (!movable.length) { return { assign: Array.from(ctx.assign), cost: ctx.total }; }

    function change(si, d, np) {
      var idx = si * D + d, op = ctx.assign[idx];
      var dc = applyShift(ctx, si, d, op, -1);
      ctx.assign[idx] = np;
      dc += applyShift(ctx, si, d, np, +1);
      return dc;
    }

    while (true) {
      if ((iter & 255) === 0) {
        var el = Date.now() - t0;
        if (el >= timeMs) { break; }
        T = T0 * Math.pow(T1 / T0, el / timeMs);
        if (onTick && (iter & 8191) === 0) { onTick(el / timeMs, ctx.total); }
      }
      iter++;
      var r = rnd(), si, d, np, old, idx, before, dcov, after, delta;

      if (r < 0.55) { // 目的を絞った変更：人数が足りない時間帯に、入れそうなスタッフを入れる
        var rq = null;
        for (var tries = 0; tries < 12 && !rq; tries++) {
          var cand = ctx.req[(rnd() * ctx.req.length) | 0];
          if (cand && cand.shortSum > 0 && cand.list.length) { rq = cand; }
        }
        if (!rq) { continue; }
        var k = -1;
        for (var t2 = 0; t2 < 8 && k < 0; t2++) {
          var kk = (rnd() * rq.n) | 0;
          if (rq.have[kk] < rq.count) { k = kk; }
        }
        if (k < 0) { continue; }
        var tm = rq.a + k * SLOT;
        si = rq.list[(rnd() * rq.list.length) | 0];
        var day0 = Math.floor(tm / 1440);
        var opts = [];
        for (var dd = Math.max(0, day0 - 1); dd <= Math.min(D - 1, day0); dd++) {
          for (var q = 0; q < free.length; q++) {
            var pt = ctx.pats[free[q]];
            if (dd * 1440 + pt.start <= tm && tm < dd * 1440 + pt.end) { opts.push([dd, free[q]]); }
          }
        }
        if (!opts.length) { continue; }
        var pick = opts[(rnd() * opts.length) | 0];
        d = pick[0]; np = pick[1];
        idx = si * D + d;
        if (ctx.locked[idx] || ctx.hard[idx] || ctx.assign[idx] === np) { continue; }
      } else if (r < 0.85) { // ランダムな変更：休みにする／別の勤務にする
        idx = movable[(rnd() * movable.length) | 0];
        si = (idx / D) | 0; d = idx - si * D;
        var choices = free.length + 1;
        var pi = (rnd() * choices) | 0;
        np = pi === free.length ? -1 : free[pi];
        if (np === ctx.assign[idx] || (np >= 0 && ctx.hard[idx])) { continue; }
      } else { // 同じ日の2人の勤務を入れ替える
        d = (rnd() * D) | 0;
        var s1 = (rnd() * S) | 0, s2 = (rnd() * S) | 0;
        if (s1 === s2) { continue; }
        var i1 = s1 * D + d, i2 = s2 * D + d;
        var p1 = ctx.assign[i1], p2 = ctx.assign[i2];
        if (p1 === p2 || ctx.locked[i1] || ctx.locked[i2]) { continue; }
        if ((p2 >= 0 && ctx.hard[i1]) || (p1 >= 0 && ctx.hard[i2])) { continue; }
        before = ctx.rowC[s1] + ctx.rowC[s2];
        dcov = change(s1, d, p2) + change(s2, d, p1);
        var c1 = rowCost(ctx, s1, null), c2 = rowCost(ctx, s2, null);
        delta = dcov + (c1 + c2) - before;
        if (delta <= 0 || rnd() < Math.exp(-delta / T)) {
          ctx.rowC[s1] = c1; ctx.rowC[s2] = c2; ctx.total += delta;
        } else {
          change(s1, d, p1); change(s2, d, p2);
        }
        if (ctx.total < bestCost && iter - lastSnap > 200) { bestCost = ctx.total; best.set(ctx.assign); lastSnap = iter; }
        continue;
      }

      old = ctx.assign[idx];
      before = ctx.rowC[si];
      dcov = change(si, d, np);
      after = rowCost(ctx, si, null);
      delta = dcov + after - before;
      if (delta <= 0 || rnd() < Math.exp(-delta / T)) {
        ctx.rowC[si] = after; ctx.total += delta;
        if (ctx.total < bestCost && iter - lastSnap > 200) { bestCost = ctx.total; best.set(ctx.assign); lastSnap = iter; }
      } else {
        change(si, d, old);
      }
    }
    if (ctx.total <= bestCost) { best.set(ctx.assign); bestCost = ctx.total; }
    return { assign: Array.from(best), cost: bestCost, iterations: iter };
  }

  /** 複数の案を作る。 */
  function solve(input, opts) {
    opts = opts || {};
    var n = opts.proposals || 3, total = opts.timeMs || 15000, seed0 = opts.seed || 12345;
    var out = [];
    for (var k = 0; k < n; k++) {
      var ctx = prepare(input);
      var res = anneal(ctx, seed0 + k * 7919, total / n, opts.onTick ? function (f, c) { opts.onTick((k + f) / n, c); } : null);
      var ev = evaluate(input, res.assign);
      out.push({ assign: res.assign, cost: res.cost, hard: ev.hard, soft: ev.soft, shortage: ev.shortage, iterations: res.iterations });
    }
    out.sort(function (a, b) { return a.cost - b.cost; });
    return out;
  }

  var api = { solve: solve, evaluate: evaluate, weights: W };
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
  root.SSSolver = api;
})(typeof self !== 'undefined' ? self : (typeof globalThis !== 'undefined' ? globalThis : this));
