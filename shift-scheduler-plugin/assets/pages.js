(function () {
  'use strict';
  var U = window.SSUI;
  var cfg = window.SS_CONFIG || {};
  var root = document.getElementById('ss-root');
  var WEEK = U.WEEK, api = U.api, say = U.say, fail = U.fail, el = U.el, clear = U.clear, btn = U.btn, field = U.field,
      pad = U.pad, timeSelect = U.timeSelect, parseDate = U.parseDate, fmtDate = U.fmtDate, addDays = U.addDays,
      jpDate = U.jpDate, table = U.table, td = U.td;

  /* ---------- 勤務区分 ---------- */

  function patternsPage() {
    var editing = null;

    function load() {
      return api('GET', 'patterns').then(render).catch(fail);
    }

    function render(d) {
      var list = d.patterns;
      clear(root);

      if (!list.length && cfg.writable) {
        root.appendChild(el('p', {}, ['勤務区分がまだありません。']));
        root.appendChild(btn('業種のひな形を入れる', function () {
          api('POST', 'patterns/preset').then(function (r) { say(r.added + '件追加しました。', true); return load(); }).catch(fail);
        }));
      }

      var rows = list.map(function (p) {
        var time = p.start_time + '〜' + (p.crosses_midnight ? '翌' : '') + p.end_time;
        var ops = [];
        if (cfg.writable) {
          if (p.active) {
            ops.push(btn('編集', function () { editing = p; render(d); window.scrollTo({ top: 0 }); }, true));
            ops.push(btn('無効にする', function () {
              if (!window.confirm('「' + p.name + '」を無効にしますか？（過去のシフトの記録は残ります）')) { return; }
              api('DELETE', 'patterns/' + p.id).then(function () { say('無効にしました。', true); return load(); }).catch(fail);
            }, true));
          } else {
            ops.push(btn('有効に戻す', function () {
              api('PUT', 'patterns/' + p.id, { active: true }).then(function () { say('有効に戻しました。', true); return load(); }).catch(fail);
            }, true));
          }
        }
        var tr = el('tr', { class: p.active ? '' : 'ss-muted' }, [
          td([el('span', { class: 'ss-swatch', style: 'background:' + p.color }), p.name + (p.active ? '' : '（無効）')]),
          td(p.short_name),
          td(time),
          td(p.break_minutes + '分'),
          td(p.counts_as_night ? '夜勤' : ''),
          td(ops)
        ]);
        return tr;
      });
      root.appendChild(table(['名前', '略称', '時間', '休憩', '夜勤', ''], rows, 'まだありません。'));

      if (cfg.writable) { root.appendChild(form()); }
    }

    function form() {
      var p = editing || { name: '', short_name: '', start_time: '09:00', end_time: '18:00', break_minutes: 60, counts_as_night: false, color: '#6aa84f' };
      var f = el('form', { class: 'ss-form ss-form-row ss-section' }, [
        el('h2', { class: 'ss-h2', text: editing ? '勤務区分を編集' : '勤務区分を追加', style: 'grid-column:1/-1' }),
        field('名前', el('input', { type: 'text', name: 'name', maxlength: 60, required: true, value: p.name })),
        field('略称（1〜2文字）', el('input', { type: 'text', name: 'short_name', maxlength: 10, value: p.short_name })),
        field('開始', timeSelect('start_time', p.start_time, false)),
        field('終了（開始以前なら翌日）', timeSelect('end_time', p.end_time, true)),
        field('休憩（分）', el('input', { type: 'number', name: 'break_minutes', min: 0, max: 480, step: 5, value: p.break_minutes })),
        field('色', el('input', { type: 'color', name: 'color', value: p.color })),
        el('label', { class: 'ss-inline' }, [el('input', { type: 'checkbox', name: 'counts_as_night', checked: p.counts_as_night }), '夜勤として数える']),
        el('div', { class: 'ss-actions' }, [
          el('button', { type: 'submit', class: 'ss-btn', text: editing ? '保存' : '追加' }),
          editing ? btn('キャンセル', function () { editing = null; load(); }, true) : null
        ])
      ]);
      f.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var e = f.elements;
        var body = {
          name: e.name.value, short_name: e.short_name.value,
          start_time: e.start_time.value, end_time: e.end_time.value,
          break_minutes: parseInt(e.break_minutes.value || '0', 10),
          color: e.color.value, counts_as_night: e.counts_as_night.checked
        };
        var req = editing ? api('PUT', 'patterns/' + editing.id, body) : api('POST', 'patterns', body);
        req.then(function () { say(editing ? '保存しました。' : '追加しました。', true); editing = null; return load(); }).catch(fail);
      });
      return f;
    }

    load();
  }

  /* ---------- ルール ---------- */

  function rulesPage() {
    function load() {
      return api('GET', 'rules').then(render).catch(fail);
    }

    function weekdayLabel(r) {
      if (r.params.date) { return jpDate(r.params.date); }
      return r.params.weekday === -1 ? '毎日' : WEEK[r.params.weekday] + '曜日';
    }

    function render(d) {
      clear(root);

      var hasGlobal = Object.keys(d.global).length > 0;
      if (!hasGlobal && cfg.writable) {
        root.appendChild(el('p', {}, ['全体ルールがまだ設定されていません。']));
        root.appendChild(btn('業種のひな形を入れる', function () {
          api('POST', 'rules/preset').then(function (r) { say(r.added + '件追加しました。', true); return load(); }).catch(fail);
        }));
      }

      /* 全体ルール */
      var gf = el('form', { class: 'ss-form ss-form-row ss-section' }, [
        el('h2', { class: 'ss-h2', text: '全体のルール（空欄にするとルールを外します）', style: 'grid-column:1/-1' })
      ]);
      d.defs.forEach(function (def) {
        var v = d.global[def.type];
        gf.appendChild(field(def.label + '（' + def.unit + '）', el('input', {
          type: 'number', name: def.type, min: def.min, max: def.max,
          value: v === null || v === undefined ? '' : v, disabled: !cfg.writable
        })));
      });
      if (cfg.writable) {
        gf.appendChild(el('div', { class: 'ss-actions' }, [el('button', { type: 'submit', class: 'ss-btn', text: '保存' })]));
      }
      gf.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var body = {};
        d.defs.forEach(function (def) { body[def.type] = gf.elements[def.type].value; });
        api('PUT', 'rules/global', body).then(function () { say('保存しました。', true); return load(); }).catch(fail);
      });
      root.appendChild(gf);

      /* 必要人数 */
      root.appendChild(el('h2', { class: 'ss-h2 ss-section', text: '必要人数（時間帯ごと）' }));
      root.appendChild(el('p', { class: 'ss-sub', text: '曜日ごとの基本の人数を登録し、祝日やイベント日だけ「特定の日」で追加します。同じ時間帯に特定の日の設定があるときは、そちらが優先されます。' }));
      var req = d.required_staff.slice().sort(function (a, b) {
        var ka = (a.params.date || '~') + a.params.weekday + a.params.start;
        var kb = (b.params.date || '~') + b.params.weekday + b.params.start;
        return ka < kb ? -1 : 1;
      });
      var rows = req.map(function (r) {
        var p = r.params;
        var ops = cfg.writable ? [btn('削除', function () {
          if (!window.confirm('この設定を削除しますか？')) { return; }
          api('DELETE', 'rules/' + r.id).then(function () { say('削除しました。', true); return load(); }).catch(fail);
        }, true)] : [];
        return el('tr', {}, [
          td(weekdayLabel(r)),
          td(p.start + '〜' + p.end),
          td(p.count + '名'),
          td(p.role || ''),
          td(p.qualification || ''),
          td(ops)
        ]);
      });
      root.appendChild(table(['曜日・日付', '時間帯', '人数', '役割', '資格', ''], rows, 'まだ設定がありません。'));

      if (cfg.writable) { root.appendChild(requiredForm()); }
    }

    function requiredForm() {
      var wd = el('select', { name: 'weekday' }, [el('option', { value: '-1', text: '毎日' })].concat(
        WEEK.map(function (w, i) { return el('option', { value: String(i), text: w + '曜日' }); })));
      var f = el('form', { class: 'ss-form ss-form-row ss-section' }, [
        el('h2', { class: 'ss-h2', text: '必要人数を追加', style: 'grid-column:1/-1' }),
        field('曜日', wd),
        field('特定の日（任意・入れると曜日より優先）', el('input', { type: 'date', name: 'date' })),
        field('開始', timeSelect('start', '09:00', false)),
        field('終了（開始以前なら翌日）', timeSelect('end', '18:00', true)),
        field('人数', el('input', { type: 'number', name: 'count', min: 0, max: 200, value: 2, required: true })),
        field('役割（任意）', el('input', { type: 'text', name: 'role', maxlength: 40, placeholder: 'ホール' })),
        field('資格（任意）', el('input', { type: 'text', name: 'qualification', maxlength: 40, placeholder: '看護師' })),
        el('div', { class: 'ss-actions' }, [el('button', { type: 'submit', class: 'ss-btn', text: '追加' })])
      ]);
      f.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var e = f.elements;
        api('POST', 'rules', {
          weekday: parseInt(e.weekday.value, 10), date: e.date.value,
          start: e.start.value, end: e.end.value, count: parseInt(e.count.value, 10),
          role: e.role.value, qualification: e.qualification.value
        }).then(function () { say('追加しました。', true); return load(); }).catch(fail);
      });
      return f;
    }

    load();
  }

  /* ---------- 希望の収集（管理者） ---------- */

  function requestsPage() {
    var detail = null; // 提出状況を開いている期間

    function load() {
      return api('GET', 'periods').then(render).catch(fail);
    }

    function render(d) {
      clear(root);
      var rows = d.periods.map(function (p) {
        var ops = [btn('提出状況', function () { openDetail(p); }, true)];
        if (cfg.writable) {
          ops.push(btn(p.status === 'open' ? '受付を終了' : '受付を再開', function () {
            api('PUT', 'periods/' + p.id, { status: p.status === 'open' ? 'closed' : 'open' })
              .then(function () { return load(); }).catch(fail);
          }, true));
          ops.push(btn('削除', function () {
            if (!window.confirm('この期間と、提出された希望をすべて削除しますか？')) { return; }
            api('DELETE', 'periods/' + p.id).then(function () { detail = null; say('削除しました。', true); return load(); }).catch(fail);
          }, true));
        }
        return el('tr', {}, [
          td(jpDate(p.start_date) + ' 〜 ' + jpDate(p.end_date)),
          td(jpDate(p.deadline)),
          td(el('span', { class: 'ss-badge' + (p.status === 'open' ? ' ss-badge-ok' : ''), text: p.status === 'open' ? '受付中' : '終了' })),
          td(p.submitted + ' / ' + p.staff_total + '名'),
          td(ops)
        ]);
      });
      root.appendChild(table(['期間', '締切', '状態', '提出', ''], rows, 'まだ期間がありません。'));
      if (detail) { root.appendChild(detailBox()); }
      if (cfg.writable) { root.appendChild(form()); }
    }

    function openDetail(p) {
      Promise.all([api('GET', 'periods/' + p.id + '/status'), api('GET', 'periods/' + p.id + '/requests')]).then(function (rs) {
        detail = { period: p, staff: rs[0].staff, days: rs[1].days };
        load();
      }).catch(fail);
    }

    function detailBox() {
      var rows = detail.staff.map(function (s) {
        return el('tr', {}, [
          td(s.name),
          td(el('span', { class: 'ss-badge' + (s.submitted ? ' ss-badge-ok' : ''), text: s.submitted ? '提出済み' : '未提出' })),
          td(s.submitted ? s.count + '日' : '')
        ]);
      });
      function names(list) {
        return list.map(function (x) { return x.name + (x.admin ? '（管理者設定）' : ''); }).join('、');
      }
      var dayRows = detail.days.map(function (d) {
        return el('tr', {}, [td(jpDate(d.date)), td(names(d.off)), td(names(d.ng))]);
      });
      var box = el('div', { class: 'ss-section' }, [
        el('h2', { class: 'ss-h2', text: jpDate(detail.period.start_date) + '〜 の提出状況' }),
        table(['名前', '状態', '休み・不可の日数'], rows, 'スタッフがいません。'),
        el('h2', { class: 'ss-h2', text: '日ごとの希望休・出勤不可' }),
        table(['日付', '希望休', '出勤不可'], dayRows, 'まだ入力がありません。')
      ]);
      if (cfg.writable) { box.appendChild(bulkForm()); }
      return box;
    }

    /** 管理者が、全員（または選んだスタッフ）の休みをまとめて設定・解除する */
    function bulkForm() {
      var per = detail.period;
      var wdBoxes = WEEK.map(function (w, i) {
        return el('label', { class: 'ss-inline' }, [el('input', { type: 'checkbox', name: 'wd', value: String(i) }), w]);
      });
      var staffBoxes = detail.staff.map(function (s) {
        return el('label', { class: 'ss-inline' }, [el('input', { type: 'checkbox', name: 'sid', value: String(s.staff_id) }), s.name]);
      });
      var staffBox = el('div', { hidden: true }, staffBoxes);
      var f = el('form', { class: 'ss-form ss-form-row' }, [
        el('h2', { class: 'ss-h2', text: '休みをまとめて設定（管理者）', style: 'grid-column:1/-1' }),
        el('p', { class: 'ss-sub', style: 'grid-column:1/-1', text: '定休日や年末年始など、全員（または選んだ人）の休みをまとめて入れます。同じ日にスタッフが入力済みの分は、上書きされます。管理者が設定した日は、スタッフは変更できません。' }),
        field('開始日', el('input', { type: 'date', name: 'start_date', value: per.start_date, min: per.start_date, max: per.end_date })),
        field('終了日', el('input', { type: 'date', name: 'end_date', value: per.end_date, min: per.start_date, max: per.end_date })),
        el('div', { style: 'grid-column:1/-1' }, [el('span', { class: 'ss-sub', text: '曜日で絞る（選ばなければ毎日）：' })].concat(wdBoxes)),
        field('種類', el('select', { name: 'kind' }, [el('option', { value: 'off', text: '希望休（休み）' }), el('option', { value: 'ng', text: '出勤不可' })])),
        field('メモ（任意）', el('input', { type: 'text', name: 'note', maxlength: 200, placeholder: '定休日、年末年始 など' })),
        el('div', { style: 'grid-column:1/-1' }, [
          el('label', { class: 'ss-inline' }, [el('input', { type: 'radio', name: 'target', value: 'all', checked: true, onchange: function () { staffBox.hidden = true; } }), 'スタッフ全員']),
          el('label', { class: 'ss-inline' }, [el('input', { type: 'radio', name: 'target', value: 'some', onchange: function () { staffBox.hidden = false; } }), '選んだスタッフ']),
          staffBox
        ]),
        el('div', { class: 'ss-actions' }, [
          el('button', { type: 'submit', class: 'ss-btn', text: '休みを設定' }),
          btn('管理者が設定した休みを解除', function () { submit('clear'); }, true)
        ])
      ]);

      function submit(action) {
        var e = f.elements;
        var weekdays = [].slice.call(f.querySelectorAll('input[name=wd]:checked')).map(function (x) { return parseInt(x.value, 10); });
        var some = e.target.value === 'some';
        var ids = [].slice.call(f.querySelectorAll('input[name=sid]:checked')).map(function (x) { return parseInt(x.value, 10); });
        if (some && !ids.length) { say('スタッフを選んでください。'); return; }
        var label = action === 'set' ? '設定' : '解除';
        if (!window.confirm((some ? ids.length + '名' : '全員') + 'の休みを' + label + 'します。よろしいですか？')) { return; }
        api('POST', 'periods/' + per.id + '/bulk', {
          action: action, start_date: e.start_date.value, end_date: e.end_date.value,
          weekdays: weekdays, kind: e.kind.value, note: e.note.value, staff_ids: some ? ids : 'all'
        }).then(function (r) {
          say(r.dates + '日分を' + label + 'しました。', true);
          return api('GET', 'periods/' + per.id + '/status').then(function (st) {
            return api('GET', 'periods/' + per.id + '/requests').then(function (rq) {
              detail = { period: per, staff: st.staff, days: rq.days };
              return load();
            });
          });
        }).catch(fail);
      }
      f.addEventListener('submit', function (ev) { ev.preventDefault(); submit('set'); });
      return f;
    }

    function form() {
      var today = cfg.today;
      var first = today.slice(0, 8) + '01';
      var d = parseDate(first);
      d.setUTCMonth(d.getUTCMonth() + 1);
      var start = fmtDate(d);
      var endD = parseDate(start);
      endD.setUTCMonth(endD.getUTCMonth() + 1);
      endD.setUTCDate(0);
      var end = fmtDate(endD);
      var deadline = addDays(start, -10);
      if (deadline < today) { deadline = addDays(today, 3); }
      var f = el('form', { class: 'ss-form ss-form-row ss-section' }, [
        el('h2', { class: 'ss-h2', text: '希望の収集を始める', style: 'grid-column:1/-1' }),
        field('シフトの開始日', el('input', { type: 'date', name: 'start_date', value: start, required: true })),
        field('シフトの終了日（62日以内）', el('input', { type: 'date', name: 'end_date', value: end, required: true })),
        field('希望の提出の締切日', el('input', { type: 'date', name: 'deadline', value: deadline, required: true })),
        el('div', { class: 'ss-actions' }, [el('button', { type: 'submit', class: 'ss-btn', text: '受付を開始' })])
      ]);
      f.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var e = f.elements;
        api('POST', 'periods', { start_date: e.start_date.value, end_date: e.end_date.value, deadline: e.deadline.value })
          .then(function () { say('受付を開始しました。スタッフは「希望休の提出」から入力できます。', true); return load(); }).catch(fail);
      });
      return f;
    }

    load();
  }

  /* ---------- 希望休の提出（スタッフ） ---------- */

  function meRequestsPage() {
    function load() {
      return api('GET', 'me/periods').then(render).catch(fail);
    }

    function render(d) {
      clear(root);
      if (!d.periods.length) {
        root.appendChild(el('p', { class: 'ss-muted', text: '現在、受付中の希望の収集はありません。' }));
        return;
      }
      root.appendChild(el('p', { text: '提出する期間を選んでください。' }));
      var list = el('div', { class: 'ss-actions' });
      d.periods.forEach(function (p) {
        list.appendChild(btn(jpDate(p.start_date) + '〜' + jpDate(p.end_date) + (p.submitted ? '（提出済み）' : ''), function () { openPeriod(p); }, true));
      });
      root.appendChild(list);
      root.appendChild(el('div', { id: 'ss-detail' }));
    }

    function openPeriod(p) {
      api('GET', 'me/requests?period_id=' + p.id).then(function (r) { showDays(r); }).catch(fail);
    }

    function showDays(r) {
      var box = document.getElementById('ss-detail');
      clear(box);
      var chosen = {};
      r.items.forEach(function (it) { chosen[it.date] = it; });
      var editable = r.editable && cfg.writable;
      box.appendChild(el('h2', { class: 'ss-h2 ss-section', text: jpDate(r.period.start_date) + '〜' + jpDate(r.period.end_date) }));
      box.appendChild(el('p', { class: 'ss-sub', text: '締切：' + jpDate(r.period.deadline) + (r.submitted ? '　（提出済み。締切まで修正できます）' : '') }));
      box.appendChild(el('p', { class: 'ss-sub', text: '「希望休」は休みたい日、「出勤不可」はどうしても出られない日です。選ばない日は出勤できる日として扱います。' }));

      var rows = [];
      var d = r.period.start_date;
      while (d <= r.period.end_date) {
        (function (date) {
          var dow = parseDate(date).getUTCDay();
          var cur = chosen[date] || { kind: '', note: '', source: 'staff' };
          var locked = cur.source === 'admin';
          var sel = el('select', { 'data-date': date, disabled: !editable || locked }, [
            el('option', { value: '', text: '—' }),
            el('option', { value: 'off', text: '希望休' }),
            el('option', { value: 'ng', text: '出勤不可' })
          ]);
          sel.value = cur.kind;
          var note = el('input', { type: 'text', maxlength: 200, placeholder: locked ? '管理者が設定した日です' : 'メモ（任意）', value: cur.note, disabled: !editable || locked });
          rows.push({ date: date, sel: sel, note: note, locked: locked });
          box.appendChild(el('div', { class: 'ss-day' + (dow === 0 ? ' is-sun' : dow === 6 ? ' is-sat' : '') }, [
            el('span', { class: 'ss-day-label', text: jpDate(date) + (locked ? '（管理者設定）' : '') }), sel, note
          ]));
        })(d);
        d = addDays(d, 1);
      }

      if (editable) {
        box.appendChild(el('div', { class: 'ss-actions ss-section' }, [btn(r.submitted ? '修正して提出' : '提出する', function () {
          var items = [];
          rows.forEach(function (x) {
            if (x.sel.value && !x.locked) { items.push({ date: x.date, kind: x.sel.value, note: x.note.value }); }
          });
          api('PUT', 'me/requests', { period_id: r.period.id, items: items })
            .then(function () { say('提出しました（' + items.length + '日）。', true); return load(); }).catch(fail);
        })]));
      } else {
        box.appendChild(el('p', { class: 'ss-alert ss-alert-error', text: 'この期間は受付が終了しているため、変更できません。' }));
      }
    }

    load();
  }

  /* ---------- シフト表の一覧（管理者） ---------- */

  function schedulesPage() {
    function load() { return api('GET', 'schedules').then(render).catch(fail); }

    function openUrl(id) { return cfg.schedule_url.replace('__ID__', id); }

    function render(d) {
      clear(root);
      var rows = d.schedules.map(function (sc) {
        var ops = [el('a', { class: 'ss-btn', href: openUrl(sc.id), text: '開く' })];
        if (cfg.writable) {
          ops.push(btn('削除', function () {
            if (!window.confirm('このシフト表と、入力した勤務をすべて削除しますか？（元に戻せません）')) { return; }
            api('DELETE', 'schedules/' + sc.id).then(function () { say('削除しました。', true); return load(); }).catch(fail);
          }, true));
        }
        return el('tr', {}, [
          td(jpDate(sc.start_date) + ' 〜 ' + jpDate(sc.end_date)),
          td(el('span', { class: 'ss-badge' + (sc.status === 'published' ? ' ss-badge-ok' : ''), text: sc.status === 'published' ? '公開中' : '下書き' })),
          td(sc.entries + '件'),
          td(el('div', { class: 'ss-actions' }, ops))
        ]);
      });
      root.appendChild(table(['期間', '状態', '入力済みの勤務', ''], rows, 'まだシフト表がありません。'));
      if (cfg.writable) { root.appendChild(form()); }
    }

    function form() {
      var first = cfg.today.slice(0, 8) + '01';
      var d = parseDate(first); d.setUTCMonth(d.getUTCMonth() + 1);
      var start = fmtDate(d);
      var e = parseDate(start); e.setUTCMonth(e.getUTCMonth() + 1); e.setUTCDate(0);
      var f = el('form', { class: 'ss-form ss-form-row ss-section' }, [
        el('h2', { class: 'ss-h2', text: '新しいシフト表を作る', style: 'grid-column:1/-1' }),
        field('開始日', el('input', { type: 'date', name: 'start_date', value: start, required: true })),
        field('終了日（62日以内）', el('input', { type: 'date', name: 'end_date', value: fmtDate(e), required: true })),
        el('div', { class: 'ss-actions' }, [el('button', { type: 'submit', class: 'ss-btn', text: '作成して開く' })])
      ]);
      f.addEventListener('submit', function (ev) {
        ev.preventDefault();
        api('POST', 'schedules', { start_date: f.elements.start_date.value, end_date: f.elements.end_date.value })
          .then(function (r) { window.location.href = openUrl(r.schedule.id); }).catch(fail);
      });
      return f;
    }

    load();
  }

  /* ---------- 公開されたシフトを見る ---------- */

  function meSchedulePage() {
    function load() { return api('GET', 'me/schedules').then(render).catch(fail); }

    function render(d) {
      clear(root);
      if (!d.schedules.length) {
        root.appendChild(el('p', { class: 'ss-muted', text: '公開されているシフトはまだありません。' }));
        return;
      }
      var list = el('div', { class: 'ss-actions' });
      d.schedules.forEach(function (sc) {
        list.appendChild(btn(jpDate(sc.start_date) + '〜' + jpDate(sc.end_date), function () { open(sc); }, true));
      });
      root.appendChild(list);
      root.appendChild(el('div', { id: 'ss-detail' }));
      if (d.schedules.length) { open(d.schedules[0]); }
    }

    function open(sc) {
      api('GET', 'me/schedules/' + sc.id).then(show).catch(fail);
    }

    function label(e, pats) {
      if (!e.start_time) { return '休'; }
      var p = null;
      pats.forEach(function (x) { if (x.id === e.pattern_id) { p = x; } });
      return p ? p.short_name || p.name : e.start_time.replace(/^0/, '') + '-' + e.end_time.replace(/^0/, '');
    }

    function show(r) {
      var box = document.getElementById('ss-detail');
      clear(box);
      var dates = [];
      for (var d = r.schedule.start_date; d <= r.schedule.end_date; d = addDays(d, 1)) { dates.push(d); }
      var byKey = {};
      r.entries.forEach(function (e) { byKey[e.staff_id + '|' + e.date] = e; });
      var colorOf = {};
      r.patterns.forEach(function (p) { colorOf[p.id] = p.color; });

      box.appendChild(el('h2', { class: 'ss-h2 ss-section', text: jpDate(r.schedule.start_date) + '〜' + jpDate(r.schedule.end_date) }));
      if (r.scope === 'self') { box.appendChild(el('p', { class: 'ss-sub', text: '自分のシフトのみ表示されます。' })); }

      var head = el('tr', {}, [el('th', { text: '名前' })].concat(dates.map(function (dt) {
        var dow = parseDate(dt).getUTCDay();
        return el('th', { class: 'ss-gh' + (dow === 0 ? ' is-sun' : dow === 6 ? ' is-sat' : ''), text: (parseDate(dt).getUTCMonth() + 1) + '/' + parseDate(dt).getUTCDate() + WEEK[dow] });
      })));
      var rows = r.staff.map(function (s) {
        return el('tr', { class: s.id === r.my_staff_id ? 'is-me' : '' }, [el('th', { class: 'ss-gname', text: s.name })].concat(dates.map(function (dt) {
          var e = byKey[s.id + '|' + dt];
          var c = el('td', { class: 'ss-gc', text: e ? label(e, r.patterns) : '' });
          if (e && e.start_time) { c.style.background = colorOf[e.pattern_id] || '#dde1e8'; c.style.color = '#111'; c.title = e.start_time + '〜' + e.end_time; }
          return c;
        })));
      });
      box.appendChild(el('div', { class: 'ss-table-wrap' }, [el('table', { class: 'ss-grid' }, [el('thead', {}, [head]), el('tbody', {}, rows)])]));
    }

    load();
  }

  /* ---------- 設定 ---------- */

  function settingsPage() {
    api('GET', 'settings').then(function (d) {
      clear(root);
      var f = el('form', { class: 'ss-form' }, [
        el('h2', { class: 'ss-h2', text: 'スタッフが見られるシフトの範囲' }),
        el('label', { class: 'ss-inline' }, [el('input', { type: 'radio', name: 'staff_view', value: 'all', checked: d.staff_view === 'all' }), '全員のシフトを見られる（交代の相談がしやすい）']),
        el('label', { class: 'ss-inline' }, [el('input', { type: 'radio', name: 'staff_view', value: 'self', checked: d.staff_view === 'self' }), '自分のシフトだけ見られる（他のスタッフのシフトは表示されません）']),
        cfg.writable ? el('div', { class: 'ss-actions' }, [el('button', { type: 'submit', class: 'ss-btn', text: '保存' })]) : null
      ]);
      f.addEventListener('submit', function (ev) {
        ev.preventDefault();
        api('PUT', 'settings', { staff_view: f.elements.staff_view.value }).then(function () { say('保存しました。', true); }).catch(fail);
      });
      root.appendChild(f);
    }).catch(fail);
  }

  /* ---------- ご契約・お支払い ---------- */

  function billingPage() {
    var STATUS = { trial: '無料期間中', active: 'ご契約中', grace: 'お支払い確認中', readonly: '閲覧のみ', suspended: '停止中' };
    var payjp = null, cardEl = null, wantCard = null;

    function load() { return api('GET', 'billing').then(render).catch(fail); }
    function yen(n) { return Number(n).toLocaleString('ja-JP') + '円'; }
    function day(s) { return s ? jpDate(s.slice(0, 10)) : '-'; }

    /**
     * カード入力欄（PAY.JPの入力部品）を用意する。カード番号は、このサイトのサーバーには送られない。
     * カード番号・有効期限・CVCを別々の枠にする。分割型が使えない場合は、1つにまとまった入力欄に切り替える。
     * PAY.JPの部品は、置き場所を「#id」の文字列で受け取る。画面に置いたあとで呼ぶこと。
     */
    function mountCard(publicKey) {
      return new Promise(function (resolve, reject) {
        function ready() {
          try {
            payjp = payjp || window.Payjp(publicKey);
            var els = payjp.elements();
            try {
              var number = els.create('cardNumber'), expiry = els.create('cardExpiry'), cvc = els.create('cardCvc');
              number.mount('#ss-card-number');
              expiry.mount('#ss-card-expiry');
              cvc.mount('#ss-card-cvc');
              cardEl = number; // トークンは、カード番号の部品から作る（有効期限・CVCも一緒に扱われる）
            } catch (splitError) {
              ['ss-card-expiry-wrap', 'ss-card-cvc-wrap'].forEach(function (id) { var w = document.getElementById(id); if (w) { w.hidden = true; } });
              var box = document.getElementById('ss-card-number');
              if (box) { box.textContent = ''; }
              cardEl = els.create('card');
              cardEl.mount('#ss-card-number');
            }
            resolve();
          } catch (e) { reject(e); }
        }
        if (window.Payjp) { ready(); return; }
        var sc = document.createElement('script');
        sc.src = 'https://js.pay.jp/v2/pay.js';
        sc.onload = ready;
        sc.onerror = function () { reject(new Error('カード入力部品を読み込めませんでした。通信状況をご確認のうえ、ページを再読み込みしてください。')); };
        document.head.appendChild(sc);
      });
    }

    function getToken() {
      return payjp.createToken(cardEl).then(function (r) {
        if (!r || r.error) { throw new Error(r && r.error && r.error.message ? r.error.message : 'カード情報を確認してください。'); }
        return r.id;
      });
    }

    function done(msg) { say(msg, true); return load(); }

    function render(d) {
      clear(root);
      cardEl = null;
      var facts = [
        el('dt', { text: 'ご契約の状態' }), el('dd', { text: (STATUS[d.status] || d.status) + (d.status === 'trial' && d.trial_days_left !== null ? '（無料期間はあと' + d.trial_days_left + '日）' : '') })
      ];
      if (d.trial_end && d.trial_running) { facts.push(el('dt', { text: '無料期間の終了日' }), el('dd', { text: day(d.trial_end) })); }
      var cur = null;
      d.plans.forEach(function (p) { if (p.id === d.plan_id) { cur = p; } });
      if (d.has_subscription && cur) { facts.push(el('dt', { text: 'プラン' }), el('dd', { text: cur.name + '（' + yen(cur.price) + '／月、スタッフ' + cur.max_staff + '名まで）' })); }
      if (d.has_subscription && d.next_billing_at && !d.cancel_at) { facts.push(el('dt', { text: '次回の請求日' }), el('dd', { text: day(d.next_billing_at) })); }
      facts.push(el('dt', { text: 'いまのスタッフ数' }), el('dd', { text: d.staff_count + '名' }));
      root.appendChild(el('dl', { class: 'ss-facts' }, facts));

      if (!d.configured) {
        root.appendChild(el('p', { class: 'ss-alert ss-alert-error', text: 'お支払いの準備中です。しばらくしてから、もう一度お試しください。' }));
        return;
      }
      if (d.status === 'readonly') {
        root.appendChild(el('p', { class: 'ss-alert ss-alert-error', text: '無料期間が終了したため、閲覧のみの状態です。プランとお支払い方法を登録すると、すぐに編集できるようになります。' }));
      }
      if (d.status === 'grace') {
        root.appendChild(el('p', { class: 'ss-alert ss-alert-error', text: '直近のお支払いを確認できませんでした。' + (d.grace_since ? day(addDays(d.grace_since.slice(0, 10), 7)) + 'までに、' : '') + '下の「カードを変更する」から、有効なカードを登録してください。期限を過ぎると、閲覧のみになります。' }));
      }
      wantCard = null;
      if (!d.has_subscription) { subscribeForm(d); } else { manageForm(d); }
      if (wantCard && document.getElementById('ss-card-number')) {
        mountCard(wantCard).catch(function (e) { fail(e); });
      }
    }

    function planChoices(d, name, selected) {
      var box = el('div');
      d.plans.forEach(function (p) {
        var ok = p.ready && p.fits;
        var note = !p.ready ? '（現在お選びいただけません）' : (!p.fits ? '（スタッフ数が上限を超えています）' : '');
        var input = el('input', { type: 'radio', name: name, value: String(p.id), disabled: !ok, checked: p.id === selected });
        box.appendChild(el('div', {}, [el('label', { class: 'ss-inline', style: 'margin:6px 0' }, [input, p.name + '　' + yen(p.price) + '／月（スタッフ' + p.max_staff + '名まで）' + note])]));
      });
      return box;
    }

    function chosen(box) {
      var r = box.querySelector('input[type=radio]:checked');
      return r ? parseInt(r.value, 10) : 0;
    }

    function cardBox(d, label, onSubmit, buttonText) {
      var b = btn(buttonText, function () {
        if (!cardEl) { say('カード入力欄の準備ができていません。'); return; }
        b.disabled = true;
        getToken().then(onSubmit).catch(function (e) { fail(e); }).then(function () { b.disabled = false; });
      });
      function field3(id, title, extra) {
        return el('div', Object.assign({ id: id + '-wrap' }, extra || {}), [
          el('div', { class: 'ss-card-label', text: title }),
          el('div', { id: id, class: 'ss-card-input' })
        ]);
      }
      var note = el('p', { class: 'ss-card-note' }, [
        el('strong', { text: 'カード情報のお取り扱いについて' }), el('br'),
        'お支払いは、決済代行サービス「',
        el('a', { href: 'https://pay.jp/', target: '_blank', rel: 'noopener noreferrer', text: 'PAY.JP' }),
        '」を通じて行われます。入力されたカード情報は、PAY.JPに直接送信され、PAY.JPが安全に処理・保管します。当サービス（このサイト）では、カード番号・有効期限・セキュリティコードを受け取ることも、保存することもありません。'
      ]);
      var wrap = el('div', {}, [
        el('p', { class: 'ss-sub', text: label }),
        note,
        el('div', { class: 'ss-card-grid' }, [
          field3('ss-card-number', 'カード番号', { style: 'grid-column:1/-1' }),
          field3('ss-card-expiry', '有効期限（月 / 年）'),
          field3('ss-card-cvc', 'セキュリティコード（CVC）')
        ]),
        el('div', { class: 'ss-actions', style: 'margin-top:10px' }, [b])
      ]);
      wantCard = d.public_key; // render() が、画面に置いたあとで入力欄を用意する
      return wrap;
    }

    function subscribeForm(d) {
      var rec = d.plans.filter(function (p) { return p.ready && p.fits; })[0];
      var choices = planChoices(d, 'plan', rec ? rec.id : 0);
      var first = d.trial_running ? 'お申し込みいただいても、請求は無料期間の終了日（' + day(d.trial_end) + '）から始まります。それまでは、お支払いは発生しません。' : 'お申し込みの時点で、最初のご請求が行われます。';
      root.appendChild(el('h2', { class: 'ss-h2 ss-section', text: 'プランを選んで契約する' }));
      root.appendChild(choices);
      root.appendChild(el('p', { class: 'ss-sub', text: first }));
      root.appendChild(cardBox(d, 'クレジットカード（カード番号は、このサイトには保存されません）', function (token) {
        var plan = chosen(choices);
        if (!plan) { throw new Error('プランを選んでください。'); }
        return api('POST', 'billing/subscribe', { plan_id: plan, card_token: token }).then(function () { return done('ご契約を受け付けました。ありがとうございます。'); });
      }, 'カードを登録して契約する'));
      root.appendChild(el('p', { class: 'ss-sub', text: '解約は、いつでもこの画面からできます。解約後も、お支払い済みの期間の終わりまでご利用いただけます。' }));
    }

    function manageForm(d) {
      if (d.cancel_at) {
        root.appendChild(el('p', { class: 'ss-alert ss-alert-error', text: '解約を受け付けています。' + day(d.cancel_at) + 'まで、ご利用いただけます（以後の請求は発生しません）。' }));
        root.appendChild(btn('解約を取り消す', function () {
          api('POST', 'billing/cancel/undo').then(function () { return done('解約を取り消しました。請求は、' + day(d.cancel_at) + 'から再開されます。'); }).catch(fail);
        }));
        return;
      }
      root.appendChild(el('h2', { class: 'ss-h2 ss-section', text: 'プランの変更' }));
      root.appendChild(el('p', { class: 'ss-sub', text: '新しい料金は、次回の請求日' + (d.next_billing_at ? '（' + day(d.next_billing_at) + '）' : '') + 'から適用されます。変更の時点で、請求が発生することはありません。' }));
      var choices = planChoices(d, 'plan', d.plan_id);
      root.appendChild(choices);
      root.appendChild(el('div', { class: 'ss-actions' }, [btn('このプランに変更する', function () {
        var plan = chosen(choices);
        if (!plan || plan === d.plan_id) { say('現在と違うプランを選んでください。'); return; }
        api('POST', 'billing/plan', { plan_id: plan }).then(function () { return done('プランを変更しました。新しい料金は、次回の請求日から適用されます。'); }).catch(fail);
      })]));

      root.appendChild(el('h2', { class: 'ss-h2 ss-section', text: 'カードの変更' }));
      root.appendChild(cardBox(d, '新しいクレジットカードを入力してください。', function (token) {
        return api('POST', 'billing/card', { card_token: token }).then(function () { return done('カードを変更しました。'); });
      }, 'カードを変更する'));

      root.appendChild(el('h2', { class: 'ss-h2 ss-section', text: '解約' }));
      root.appendChild(el('p', { class: 'ss-sub', text: '解約すると、以後の請求が止まります。お支払い済みの期間の終わりまでは、これまでどおりご利用いただけます。その後は閲覧のみとなり、データは削除されません（再契約すると、すぐに編集できます）。' }));
      root.appendChild(btn('解約する', function () {
        if (!window.confirm('解約します。よろしいですか？（お支払い済みの期間の終わりまでは、ご利用いただけます）')) { return; }
        api('POST', 'billing/cancel').then(function () { return done('解約を受け付けました。'); }).catch(fail);
      }, true));
    }

    load();
  }

  var pages = { patterns: patternsPage, rules: rulesPage, requests: requestsPage, 'me-requests': meRequestsPage, schedules: schedulesPage, 'me-schedule': meSchedulePage, settings: settingsPage, billing: billingPage };
  if (pages[cfg.page] && root) { pages[cfg.page](); }
})();
