(function () {
  'use strict';
  var cfg = window.SS_CONFIG || {};
  var root = document.getElementById('ss-root');
  var notice = document.getElementById('ss-notice');
  var WEEK = ['日', '月', '火', '水', '木', '金', '土'];

  /* ---------- 共通 ---------- */

  function api(method, path, body) {
    return fetch(cfg.rest + path, {
      method: method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) {
          if (data && data.code === 'rest_cookie_invalid_nonce') {
            throw new Error('ログイン状態が変わりました。ページを再読み込みするか、ログインし直してください。');
          }
          throw new Error(data && data.message ? data.message : '処理に失敗しました。');
        }
        return data;
      });
    });
  }

  function say(text, ok) {
    notice.hidden = !text;
    notice.textContent = text || '';
    notice.className = 'ss-alert ' + (ok ? 'ss-alert-ok' : 'ss-alert-error');
    if (text) { window.scrollTo({ top: 0, behavior: 'smooth' }); }
  }

  function fail(e) { say(e.message); }

  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (k === 'text') { n.textContent = v; }
      else if (k.indexOf('on') === 0) { n.addEventListener(k.slice(2), v); }
      else if (v === true) { n.setAttribute(k, ''); }
      else if (v !== false && v !== null && v !== undefined) { n.setAttribute(k, v); }
    });
    (children || []).forEach(function (c) {
      if (c === null || c === undefined) { return; }
      n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return n;
  }

  function clear(n) { while (n.firstChild) { n.removeChild(n.firstChild); } }

  function btn(label, fn, sub) {
    return el('button', { type: 'button', class: 'ss-btn' + (sub ? ' ss-btn-sub' : ''), text: label, onclick: fn });
  }

  function field(label, input) { return el('label', {}, [label, input]); }

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  /** 30分刻みの時刻の選択肢。withEnd=true のとき 24:00 を加える。 */
  function timeSelect(name, value, withEnd) {
    var s = el('select', { name: name });
    var list = [];
    for (var h = 0; h < 24; h++) { list.push(pad(h) + ':00'); list.push(pad(h) + ':30'); }
    if (withEnd) { list.push('24:00'); }
    list.forEach(function (t) {
      var o = el('option', { value: t, text: t });
      if (t === value) { o.selected = true; }
      s.appendChild(o);
    });
    return s;
  }

  function parseDate(s) { var p = s.split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])); }
  function fmtDate(d) { return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()); }
  function addDays(s, n) { var d = parseDate(s); d.setUTCDate(d.getUTCDate() + n); return fmtDate(d); }
  function jpDate(s) { var d = parseDate(s); return (d.getUTCMonth() + 1) + '月' + d.getUTCDate() + '日（' + WEEK[d.getUTCDay()] + '）'; }

  function table(headers, rows, emptyText) {
    var thead = el('thead', {}, [el('tr', {}, headers.map(function (h) { return el('th', { text: h }); }))]);
    var tbody = el('tbody');
    if (!rows.length) {
      tbody.appendChild(el('tr', {}, [el('td', { colspan: headers.length, text: emptyText || 'まだありません。', class: 'ss-muted' })]));
    }
    rows.forEach(function (r) { tbody.appendChild(r); });
    return el('div', { class: 'ss-table-wrap' }, [el('table', { class: 'ss-table' }, [thead, tbody])]);
  }

  function td(content) {
    return el('td', {}, Array.isArray(content) ? content : [content]);
  }

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

  var pages = { patterns: patternsPage, rules: rulesPage, requests: requestsPage, 'me-requests': meRequestsPage };
  if (pages[cfg.page] && root) { pages[cfg.page](); }
})();
