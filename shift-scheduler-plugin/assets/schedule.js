/* シフト表の編集画面：表の編集・自動作成・ルール違反の表示・保存・公開 */
(function () {
  'use strict';
  var U = window.SSUI, Solver = window.SSSolver;
  var cfg = window.SS_CONFIG || {};
  var root = document.getElementById('ss-root');
  var WEEK = U.WEEK, api = U.api, say = U.say, fail = U.fail, el = U.el, clear = U.clear, btn = U.btn, field = U.field,
      pad = U.pad, timeSelect = U.timeSelect, parseDate = U.parseDate, addDays = U.addDays, jpDate = U.jpDate;

  var data, dates = [], D = 0, staff = [], nActive = 0, staffIdx = {}, dateIdx = {}, pById = {};
  var cells = [], reqMap = {};
  var dirty = false, undoSnap = null, selected = null, worker = null;
  var cellEls = [], statEls = [], markedCells = [];
  var evalResult = null, evalTimer = null;
  var gridBox, panelBox, panelInfo, summaryBox, autoBox, statusBadge, publishBtn, undoBtn;

  /* ---------- 時刻の計算 ---------- */

  function mins(t) { var p = t.split(':'); return (+p[0]) * 60 + (+p[1]); }
  function endMins(start, end) { var s = mins(start), e = mins(end); return e <= s ? e + 1440 : e; }
  function hhmm(m) { return pad(Math.floor(m / 60) % 24) + ':' + pad(m % 60); }
  function span(m) { return (m >= 1440 ? '翌' : '') + hhmm(m); }
  function shortDate(d) { var p = parseDate(dates[d]); return (p.getUTCMonth() + 1) + '/' + p.getUTCDate() + '(' + WEEK[p.getUTCDay()] + ')'; }
  function cellHours(c) { return c && !c.off ? Math.max(0, endMins(c.start, c.end) - mins(c.start) - (c.brk || 0)) / 60 : 0; }

  /* ---------- 読み込み ---------- */

  function load() {
    api('GET', 'schedules/' + cfg.schedule_id).then(function (d) {
      data = d;
      init();
      render();
      scheduleEval();
    }).catch(fail);
  }

  function init() {
    dates = [];
    for (var d = data.schedule.start_date; d <= data.schedule.end_date; d = addDays(d, 1)) { dates.push(d); }
    D = dates.length;
    dateIdx = {};
    dates.forEach(function (x, i) { dateIdx[x] = i; });
    pById = {};
    data.patterns.forEach(function (p) { pById[p.id] = p; });

    var hasEntry = {};
    data.entries.forEach(function (e) { hasEntry[e.staff_id] = true; });
    var active = data.staff.filter(function (s) { return s.active; });
    var inactive = data.staff.filter(function (s) { return !s.active && hasEntry[s.id]; });
    staff = active.concat(inactive);
    nActive = active.length;
    staffIdx = {};
    staff.forEach(function (s, i) { staffIdx[s.id] = i; });

    cells = new Array(staff.length * D);
    data.entries.forEach(function (e) {
      var si = staffIdx[e.staff_id], di = dateIdx[e.date];
      if (si === undefined || di === undefined) { return; }
      cells[si * D + di] = e.start_time === ''
        ? { off: true, locked: true }
        : { pid: e.pattern_id, start: e.start_time, end: e.end_time, brk: e.break_minutes, locked: e.locked, note: e.note || '' };
    });
    reqMap = {};
    data.requests.forEach(function (r) {
      var si = staffIdx[r.staff_id], di = dateIdx[r.date];
      if (si !== undefined && di !== undefined) { reqMap[si * D + di] = r; }
    });
  }

  /* ---------- 計算用の入力を作る ---------- */

  function overlap(a, b) {
    var sa = mins(a.start), ea = endMins(a.start, a.end), sb = mins(b.start), eb = endMins(b.start, b.end);
    return sa < eb && sb < ea;
  }

  /** 必要人数を日ごとに展開する。同じ時間帯に「特定の日」の設定があれば、曜日の設定より優先。 */
  function expandRequired() {
    var out = [];
    var rs = data.rules.required_staff.filter(function (r) { return r.active; }).map(function (r) { return r.params; });
    dates.forEach(function (dt, d) {
      var dow = parseDate(dt).getUTCDay();
      var dated = rs.filter(function (p) { return p.date === dt; });
      var weekly = rs.filter(function (p) { return !p.date && (p.weekday === -1 || p.weekday === dow); }).filter(function (w) {
        return !dated.some(function (x) {
          return (x.role || '') === (w.role || '') && (x.qualification || '') === (w.qualification || '') && overlap(x, w);
        });
      });
      dated.concat(weekly).forEach(function (p) {
        out.push({ day: d, start: mins(p.start), end: mins(p.end), count: p.count, role: p.role || '', qual: p.qualification || '' });
      });
    });
    return out;
  }

  function buildInput() {
    var pats = [], pIdx = {};
    data.patterns.forEach(function (p) {
      pIdx['p' + p.id] = pats.length;
      pats.push({ id: p.id, start: mins(p.start_time), end: endMins(p.start_time, p.end_time), breakMin: p.break_minutes, night: p.counts_as_night, custom: !p.active, src: p });
    });
    function pOf(c) {
      if (!c || c.off) { return -1; }
      var p = c.pid ? pById[c.pid] : null;
      if (p && p.start_time === c.start && p.end_time === c.end && p.break_minutes === (c.brk || 0)) { return pIdx['p' + p.id]; }
      var key = 'c' + c.start + '-' + c.end + '-' + (c.brk || 0);
      if (!(key in pIdx)) {
        pIdx[key] = pats.length;
        pats.push({ id: null, start: mins(c.start), end: endMins(c.start, c.end), breakMin: c.brk || 0, night: false, custom: true, cs: c.start, ce: c.end });
      }
      return pIdx[key];
    }
    var g = data.rules.global || {};
    function gv(k) { return g[k] === undefined || g[k] === null ? null : g[k]; }
    var hardOff = [], softOff = [];
    Object.keys(reqMap).forEach(function (k) {
      var idx = +k, si = Math.floor(idx / D), di = idx - si * D, r = reqMap[k];
      if (si >= nActive) { return; }
      if (r.kind === 'ng' || r.source === 'admin') { hardOff.push([si, di]); } else { softOff.push([si, di]); }
    });
    var assign = new Array(nActive * D);
    for (var i = 0; i < assign.length; i++) { assign[i] = pOf(cells[i]); }
    var input = {
      startDate: dates[0], days: D,
      staff: staff.slice(0, nActive).map(function (s) {
        return { id: s.id, weekdays: s.weekdays, nightOk: s.night_ok, roles: s.roles, qualifications: s.qualifications };
      }),
      patterns: pats,
      rules: { maxConsecutive: gv('max_consecutive_days'), minRestHours: gv('min_rest_hours'), maxNights: gv('max_nights_month'), daysOff: gv('monthly_days_off'), afterNightOff: gv('after_night_off') === 1 },
      required: expandRequired(), hardOff: hardOff, softOff: softOff, locked: [], initial: []
    };
    return { input: input, pats: pats, assign: assign, pOf: pOf };
  }

  function cellFromP(p, pats) {
    if (p < 0) { return null; }
    var pt = pats[p];
    if (pt.src) { return { pid: pt.id, start: pt.src.start_time, end: pt.src.end_time, brk: pt.src.break_minutes, locked: false, note: '' }; }
    return { pid: null, start: pt.cs, end: pt.ce, brk: pt.breakMin, locked: false, note: '' };
  }

  /* ---------- 評価（違反・不足）の表示 ---------- */

  function scheduleEval() {
    clearTimeout(evalTimer);
    evalTimer = setTimeout(runEval, 120);
  }

  function runEval() {
    if (!Solver || !data) { return; }
    var b = buildInput();
    evalResult = Solver.evaluate(b.input, b.assign);
    markCells();
    renderSummary();
    renderStats();
    if (selected !== null) { updatePanelInfo(); }
  }

  function markCells() {
    markedCells.forEach(function (i) { if (cellEls[i]) { cellEls[i].classList.remove('v-hard', 'v-soft'); cellEls[i].removeAttribute('data-v'); } });
    markedCells = [];
    var by = {};
    evalResult.violations.forEach(function (v) {
      if (v.d < 0) { return; }
      var i = v.s * D + v.d;
      (by[i] = by[i] || []).push(v);
    });
    Object.keys(by).forEach(function (k) {
      var b = cellEls[k];
      if (!b) { return; }
      var hard = by[k].some(function (v) { return v.hard; });
      b.classList.add(hard ? 'v-hard' : 'v-soft');
      b.setAttribute('data-v', by[k].map(function (v) { return v.msg; }).join(' / '));
      b.title = cellTitle(+k);
      markedCells.push(+k);
    });
  }

  function cellTitle(idx) {
    var c = cells[idx], si = Math.floor(idx / D), di = idx - si * D;
    var t = staff[si].name + ' ' + shortDate(di);
    if (c && !c.off) { t += '：' + c.start + '〜' + c.end; } else if (c && c.off) { t += '：休み（固定）'; }
    var r = reqMap[idx];
    if (r) { t += '\n' + (r.source === 'admin' ? '管理者が設定した' : '') + (r.kind === 'ng' ? '出勤不可' : '希望休'); }
    var b = cellEls[idx];
    if (b && b.getAttribute('data-v')) { t += '\n⚠ ' + b.getAttribute('data-v'); }
    return t;
  }

  function renderSummary() {
    clear(summaryBox);
    var ev = evalResult;
    var shortSlots = ev.shortage;
    summaryBox.appendChild(el('div', { class: 'ss-stats' }, [
      el('span', { class: 'ss-stat ' + (ev.hard ? 'bad' : 'ok'), text: '必須ルール違反：' + ev.hard + '件' }),
      el('span', { class: 'ss-stat ' + (shortSlots ? 'bad' : 'ok'), text: '人数不足：' + (shortSlots / 2) + '人時間' }),
      el('span', { class: 'ss-stat', text: '希望休が反映されていない：' + ev.soft + '件' })
    ]));
    var vio = ev.violations.filter(function (v) { return v.hard || true; }).sort(function (a, b) { return (b.hard ? 1 : 0) - (a.hard ? 1 : 0); });
    if (vio.length) {
      var ul = el('ul', { class: 'ss-list' });
      vio.slice(0, 200).forEach(function (v) {
        ul.appendChild(el('li', { text: staff[v.s].name + (v.d >= 0 ? ' ' + shortDate(v.d) : '') + '：' + v.msg + (v.hard ? '' : '（希望）') }));
      });
      summaryBox.appendChild(el('details', {}, [el('summary', { text: 'ルール違反・希望休の一覧（' + vio.length + '件）' }), ul]));
    }
    if (ev.shortages.length) {
      var ul2 = el('ul', { class: 'ss-list' });
      ev.shortages.slice(0, 200).forEach(function (s) {
        ul2.appendChild(el('li', { text: shortDate(s.day) + ' ' + span(s.from) + '〜' + span(s.to) + '：' + s.need + '名必要（最少' + s.have + '名）' + (s.role ? ' ／役割：' + s.role : '') + (s.qual ? ' ／資格：' + s.qual : '') }));
      });
      summaryBox.appendChild(el('details', {}, [el('summary', { text: '人数が足りない時間帯（' + ev.shortages.length + '件）' }), ul2]));
    }
  }

  function renderStats() {
    for (var si = 0; si < staff.length; si++) {
      var days = 0, hours = 0;
      for (var d = 0; d < D; d++) {
        var c = cells[si * D + d];
        if (c && !c.off) { days++; hours += cellHours(c); }
      }
      if (statEls[si]) { statEls[si].textContent = days + '日 / ' + (Math.round(hours * 10) / 10) + '時間'; }
    }
  }

  /* ---------- 表 ---------- */

  function contrastBg(color) { return color || '#dde1e8'; }

  function cellText(c) {
    if (!c) { return ''; }
    if (c.off) { return '休'; }
    var p = c.pid ? pById[c.pid] : null;
    if (p && p.start_time === c.start && p.end_time === c.end) { return p.short_name || p.name; }
    return c.start.replace(/^0/, '').replace(':00', '') + '-' + c.end.replace(/^0/, '').replace(':00', '');
  }

  function paintCell(idx) {
    var b = cellEls[idx], c = cells[idx];
    if (!b) { return; }
    b.textContent = cellText(c);
    b.classList.toggle('has-work', !!(c && !c.off));
    b.classList.toggle('is-locked', !!(c && c.locked));
    var p = c && !c.off && c.pid ? pById[c.pid] : null;
    b.style.background = c && !c.off ? contrastBg(p ? p.color : '#cbd5e0') : '';
    var r = reqMap[idx];
    b.classList.toggle('req-off', !!(r && r.kind === 'off' && r.source !== 'admin'));
    b.classList.toggle('req-ng', !!(r && (r.kind === 'ng' || r.source === 'admin')));
    b.classList.toggle('is-selected', selected === idx);
    b.title = cellTitle(idx);
  }

  function renderGrid() {
    clear(gridBox);
    cellEls = new Array(staff.length * D);
    statEls = new Array(staff.length);
    var head = el('tr', {}, [el('th', { text: 'スタッフ' })].concat(dates.map(function (dt, d) {
      var dow = parseDate(dt).getUTCDay();
      var p = parseDate(dt);
      return el('th', { class: dow === 0 ? 'is-sun' : dow === 6 ? 'is-sat' : '', text: (p.getUTCMonth() + 1) + '/' + p.getUTCDate() + WEEK[dow] });
    })));
    var body = el('tbody');
    staff.forEach(function (s, si) {
      var stat = el('small');
      statEls[si] = stat;
      var tr = el('tr', {}, [el('th', { class: 'ss-gname', title: s.name }, [s.name + (s.active ? '' : '（無効）'), stat])]);
      for (var d = 0; d < D; d++) {
        (function (idx) {
          var b = el('button', { type: 'button', class: 'ss-cell', onclick: function () { select(idx); } });
          cellEls[idx] = b;
          tr.appendChild(el('td', { class: 'ss-gc' }, [b]));
        })(si * D + d);
      }
      body.appendChild(tr);
    });
    gridBox.appendChild(el('div', { class: 'ss-table-wrap', style: 'max-height:70vh;overflow:auto' }, [el('table', { class: 'ss-grid' }, [el('thead', {}, [head]), body])]));
    for (var i = 0; i < cellEls.length; i++) { paintCell(i); }
    renderStats();
  }

  function legend() {
    var items = data.patterns.filter(function (p) { return p.active; }).map(function (p) {
      return el('span', {}, [el('span', { class: 'ss-swatch', style: 'background:' + p.color }), p.short_name + '＝' + p.name + '（' + p.start_time + '〜' + p.end_time + '）']);
    });
    items.push(el('span', { text: '／ 赤い斜線＝希望休、灰色の斜線＝出勤不可・管理者が設定した休み、🔒＝固定、赤枠＝必須ルール違反、黄枠＝希望休と重なっています' }));
    return el('div', { class: 'ss-legend' }, items);
  }

  /* ---------- セルの編集 ---------- */

  function select(idx) {
    var prev = selected;
    selected = idx;
    if (prev !== null) { paintCell(prev); }
    paintCell(idx);
    renderPanel();
    panelBox.scrollIntoView({ block: 'nearest' });
  }

  function renderPanel() {
    clear(panelBox);
    if (selected === null) { return; }
    var idx = selected, si = Math.floor(idx / D), di = idx - si * D, c = cells[idx];
    var kindOpts = [el('option', { value: '', text: '休み（勤務なし）' })];
    data.patterns.filter(function (p) { return p.active; }).forEach(function (p) {
      kindOpts.push(el('option', { value: String(p.id), text: p.name + '（' + p.start_time + '〜' + p.end_time + '）' }));
    });
    kindOpts.push(el('option', { value: 'custom', text: '時間を指定する' }));
    var kind = el('select', { name: 'kind' }, kindOpts);
    var cur = '';
    var curP = c && !c.off && c.pid ? pById[c.pid] : null;
    if (c && !c.off) {
      cur = curP && curP.start_time === c.start && curP.end_time === c.end && curP.active ? String(curP.id) : 'custom';
    }
    kind.value = cur;
    var st = timeSelect('start', c && !c.off ? c.start : '09:00', false);
    var en = timeSelect('end', c && !c.off ? c.end : '18:00', true);
    var brk = el('input', { type: 'number', name: 'brk', min: 0, max: 480, step: 5, value: c && !c.off ? (c.brk || 0) : 60 });
    var lock = el('input', { type: 'checkbox', name: 'lock', checked: !!(c && c.locked) });
    var note = el('input', { type: 'text', name: 'note', maxlength: 200, value: c && c.note ? c.note : '', placeholder: 'メモ（任意）' });
    var timeRow = el('div', { class: 'ss-form-row ss-form', style: 'margin:0' }, [field('開始', st), field('終了（開始以前なら翌日）', en), field('休憩（分）', brk)]);

    function sync() {
      var k = kind.value;
      timeRow.hidden = k !== 'custom';
      if (k && k !== 'custom') {
        var p = pById[+k];
        st.value = p.start_time; en.value = p.end_time; brk.value = p.break_minutes;
      }
    }
    kind.addEventListener('change', sync);
    sync();

    panelInfo = el('div');

    panelBox.appendChild(el('div', { class: 'ss-panel' }, [
      el('h2', { class: 'ss-h2', text: staff[si].name + '　' + jpDate(dates[di]) }),
      panelInfo,
      el('div', { class: 'ss-form ss-form-row', style: 'margin:0 0 8px' }, [field('勤務', kind), field('メモ', note), el('label', { class: 'ss-inline' }, [lock, '固定する（自動作成で変更しない）'])]),
      timeRow,
      el('div', { class: 'ss-actions', style: 'margin-top:10px' }, [
        btn('反映', function () {
          var k = kind.value, next = null;
          if (k === '') {
            next = lock.checked ? { off: true, locked: true } : null;
          } else {
            var s2 = st.value, e2 = en.value;
            if (s2 === e2) { say('開始と終了が同じ時刻です。'); return; }
            next = { pid: k === 'custom' ? null : +k, start: s2, end: e2, brk: parseInt(brk.value || '0', 10) || 0, locked: lock.checked, note: note.value };
          }
          setCell(idx, next);
          say('', true);
        }),
        btn('閉じる', function () { var p = selected; selected = null; paintCell(p); clear(panelBox); }, true)
      ])
    ]));
    updatePanelInfo();
  }

  /** 入力中の内容を消さないよう、パネルの「注意書き」の部分だけを更新する。 */
  function updatePanelInfo() {
    if (selected === null || !panelInfo) { return; }
    clear(panelInfo);
    var idx = selected, si = Math.floor(idx / D), di = idx - si * D;
    var r = reqMap[idx];
    if (r) { panelInfo.appendChild(el('p', { class: 'ss-sub', text: (r.source === 'admin' ? '管理者が設定した' : 'スタッフの') + (r.kind === 'ng' ? '出勤不可の日です。' : '希望休の日です。') })); }
    var vs = evalResult ? evalResult.violations.filter(function (v) { return v.s === si && v.d === di; }) : [];
    vs.forEach(function (v) { panelInfo.appendChild(el('p', { class: 'ss-alert ss-alert-error', text: '⚠ ' + v.msg })); });
  }

  function setCell(idx, next) {
    cells[idx] = next;
    markDirty();
    paintCell(idx);
    scheduleEval();
  }

  function markDirty() {
    dirty = true;
    updateStatus();
  }

  function snapshot() { return cells.slice(); }

  function restore(snap) {
    cells = snap;
    for (var i = 0; i < cellEls.length; i++) { paintCell(i); }
    markDirty();
    scheduleEval();
  }

  /* ---------- 自動作成 ---------- */

  function renderAuto() {
    clear(autoBox);
    var timeSel = el('select', { name: 'sec' }, [10, 20, 30, 60].map(function (n) { return el('option', { value: String(n), text: n + '秒' + (n === 20 ? '（おすすめ）' : '') }); }));
    timeSel.value = '20';
    var rebuild = el('input', { type: 'checkbox', name: 'rebuild', checked: true });
    var out = el('div');
    var prog = el('div', { class: 'ss-progress', hidden: true }, [el('div')]);
    var run = btn('自動作成を実行', function () { start(); });
    var cancel = btn('中止', function () { stop(); }, true);
    cancel.hidden = true;

    function stop() {
      if (worker) { worker.terminate(); worker = null; }
      run.disabled = false; cancel.hidden = true; prog.hidden = true;
    }

    function start() {
      if (!cfg.writable) { say('現在は閲覧のみの状態のため、自動作成はできません。'); return; }
      if (!Solver) { say('計算用のスクリプトが読み込まれていません。ページを再読み込みしてください。'); return; }
      clear(out);
      var b = buildInput();
      var keep = !rebuild.checked;
      for (var s = 0; s < nActive; s++) {
        for (var d = 0; d < D; d++) {
          var c = cells[s * D + d];
          if (c && c.locked) { b.input.locked.push({ s: s, d: d, p: b.pOf(c) }); }
          else if (c && keep) { b.input.initial.push({ s: s, d: d, p: b.pOf(c) }); }
        }
      }
      var ms = (+timeSel.value) * 1000;
      run.disabled = true; cancel.hidden = false; prog.hidden = false;
      prog.firstChild.style.width = '2%';

      function finish(proposals) {
        stop();
        showProposals(proposals, b, out);
      }
      try {
        worker = new Worker(cfg.worker_url);
        worker.onmessage = function (ev) {
          var m = ev.data;
          if (m.type === 'tick') { prog.firstChild.style.width = Math.max(2, Math.round(m.fraction * 100)) + '%'; }
          else if (m.type === 'done') { finish(m.proposals); }
          else if (m.type === 'error') { stop(); say('自動作成に失敗しました：' + m.message); }
        };
        worker.onerror = function () { stop(); say('自動作成に失敗しました。'); };
        worker.postMessage({ input: b.input, timeMs: ms, proposals: 3, seed: Date.now() % 100000 });
      } catch (e) { // 別スレッドが使えない環境では、そのまま計算する（画面が一時的に止まります）
        say('しばらくお待ちください…（画面が少し止まります）', true);
        setTimeout(function () { finish(Solver.solve(b.input, { timeMs: ms, proposals: 3 })); }, 50);
      }
    }

    autoBox.appendChild(el('div', { class: 'ss-panel' }, [
      el('h2', { class: 'ss-h2', text: '自動作成' }),
      el('p', { class: 'ss-sub', text: 'ルール（必要人数・連勤・休息・夜勤など）と、スタッフの希望休・出勤不可を満たす案を、3つ作ります。作った案は、「この案を使う」を押すまで反映されません。' }),
      el('div', { class: 'ss-form ss-form-row', style: 'margin:0' }, [
        field('計算にかける時間', timeSel),
        el('label', { class: 'ss-inline' }, [rebuild, 'いまの割り当てを消して作り直す（固定した勤務は残す）'])
      ]),
      el('div', { class: 'ss-actions', style: 'margin-top:10px' }, [run, cancel]),
      prog, out
    ]));
  }

  function showProposals(list, b, out) {
    clear(out);
    out.appendChild(el('h2', { class: 'ss-h2', text: '作成した案' }));
    list.forEach(function (pr, i) {
      out.appendChild(el('div', { class: 'ss-actions', style: 'margin:6px 0' }, [
        el('strong', { text: '案' + (i + 1) }),
        el('span', { class: 'ss-stat ' + (pr.hard ? 'bad' : 'ok'), text: '必須ルール違反 ' + pr.hard + '件' }),
        el('span', { class: 'ss-stat ' + (pr.shortage ? 'bad' : 'ok'), text: '人数不足 ' + (pr.shortage / 2) + '人時間' }),
        el('span', { class: 'ss-stat', text: '希望休が反映されていない ' + pr.soft + '件' }),
        btn('この案を使う', function () { applyProposal(pr, b); }, false)
      ]));
    });
  }

  function applyProposal(pr, b) {
    undoSnap = snapshot();
    for (var s = 0; s < nActive; s++) {
      for (var d = 0; d < D; d++) {
        var idx = s * D + d, c = cells[idx];
        if (c && c.locked) { continue; }
        cells[idx] = cellFromP(pr.assign[idx], b.pats);
      }
    }
    for (var i = 0; i < cellEls.length; i++) { paintCell(i); }
    markDirty();
    updateStatus();
    scheduleEval();
    autoBox.hidden = true;
    say('案を反映しました。内容を確認して、「保存」を押してください。', true);
    gridBox.scrollIntoView({ block: 'start' });
  }

  /* ---------- 保存・公開 ---------- */

  function entriesPayload() {
    var out = [];
    staff.forEach(function (s, si) {
      for (var d = 0; d < D; d++) {
        var c = cells[si * D + d];
        if (!c) { continue; }
        out.push({
          staff_id: s.id, date: dates[d], pattern_id: c.off ? null : c.pid,
          start_time: c.off ? '' : c.start, end_time: c.off ? '' : c.end,
          break_minutes: c.off ? 0 : (c.brk || 0), locked: !!c.locked, note: c.note || ''
        });
      }
    });
    return out;
  }

  function save() {
    return api('POST', 'schedules/' + cfg.schedule_id + '/entries', { entries: entriesPayload() }).then(function (r) {
      dirty = false;
      updateStatus();
      say('保存しました（' + r.saved + '件）。', true);
    });
  }

  function togglePublish() {
    var publishing = data.schedule.status !== 'published';
    var go = function () {
      return api('PUT', 'schedules/' + cfg.schedule_id, { status: publishing ? 'published' : 'draft' }).then(function (r) {
        data.schedule = r.schedule;
        updateStatus();
        say(publishing ? '公開しました。スタッフは「シフトを見る」から確認できます。' : '公開を取り下げました。', true);
      });
    };
    if (publishing && evalResult && evalResult.hard > 0 && !window.confirm('必須ルール違反が' + evalResult.hard + '件あります。このまま公開しますか？')) { return; }
    var p = dirty ? save().then(go) : go();
    p.catch(fail);
  }

  function updateStatus() {
    var pub = data.schedule.status === 'published';
    statusBadge.textContent = (pub ? '公開中' : '下書き') + (dirty ? '（未保存の変更があります）' : '');
    statusBadge.className = 'ss-badge' + (pub ? ' ss-badge-ok' : '');
    publishBtn.textContent = pub ? '公開を取り下げる' : '公開する';
    undoBtn.hidden = !undoSnap;
  }

  /* ---------- 画面 ---------- */

  function render() {
    clear(root);
    statusBadge = el('span', { class: 'ss-badge' });
    publishBtn = btn('公開する', togglePublish, true);
    undoBtn = btn('元に戻す', function () { if (undoSnap) { var s = undoSnap; undoSnap = null; restore(s); updateStatus(); } }, true);
    undoBtn.hidden = true;
    summaryBox = el('div');
    gridBox = el('div');
    panelBox = el('div');
    autoBox = el('div');

    var tools = [el('a', { class: 'ss-btn ss-btn-sub', href: cfg.back_url, text: '一覧へ' })];
    if (cfg.writable) {
      tools.push(btn('保存', function () { save().catch(fail); }));
      tools.push(btn('自動作成', function () { autoBox.hidden = !autoBox.hidden; }, true));
      tools.push(btn('割り当てを消す（固定は残す）', function () {
        if (!window.confirm('固定していない勤務をすべて消します。よろしいですか？')) { return; }
        undoSnap = snapshot();
        cells = cells.map(function (c) { return c && c.locked ? c : undefined; });
        for (var i = 0; i < cellEls.length; i++) { paintCell(i); }
        markDirty(); scheduleEval();
      }, true));
      tools.push(publishBtn);
      tools.push(undoBtn);
    }
    tools.push(statusBadge);

    root.appendChild(el('p', { class: 'ss-sub', text: jpDate(data.schedule.start_date) + ' 〜 ' + jpDate(data.schedule.end_date) + '　／　セルを押すと、勤務を変更できます。' }));
    root.appendChild(el('div', { class: 'ss-toolbar' }, tools));
    root.appendChild(autoBox);
    if (cfg.writable) { renderAuto(); autoBox.hidden = true; }
    root.appendChild(summaryBox);
    root.appendChild(panelBox);
    root.appendChild(legend());
    root.appendChild(gridBox);

    if (!staff.length) {
      gridBox.appendChild(el('p', { class: 'ss-muted', text: '有効なスタッフがいません。先に「スタッフ」画面で登録してください。' }));
    } else {
      renderGrid();
    }
    updateStatus();
  }

  window.addEventListener('beforeunload', function (ev) {
    if (dirty) { ev.preventDefault(); ev.returnValue = ''; }
  });

  load();
})();
