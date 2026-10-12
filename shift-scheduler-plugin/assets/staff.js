(function () {
  'use strict';
  var cfg = window.SS_CONFIG || {};
  var rows = document.getElementById('ss-rows');
  var notice = document.getElementById('ss-notice');
  var count = document.getElementById('ss-count');
  var form = document.getElementById('ss-add');
  var EMP = { full: '正社員', part: 'パート', baito: 'アルバイト' };
  var ACCOUNT = { none: '未設定', invited: '招待中', active: '利用中', disabled: '無効' };
  var editing = null; // 編集中のスタッフ

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
  }

  function cell(text) {
    var td = document.createElement('td');
    td.textContent = text;
    return td;
  }

  function button(label, handler, sub) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'ss-btn' + (sub ? ' ss-btn-sub' : '');
    b.textContent = label;
    b.addEventListener('click', handler);
    return b;
  }

  function render(list) {
    rows.textContent = '';
    var active = list.filter(function (s) { return s.active; });
    count.textContent = active.length;
    if (!list.length) {
      var tr0 = document.createElement('tr');
      var td0 = cell('まだスタッフが登録されていません。');
      td0.colSpan = 8;
      tr0.appendChild(td0);
      rows.appendChild(tr0);
      return;
    }
    list.forEach(function (s) {
      var tr = document.createElement('tr');
      if (!s.active) { tr.className = 'ss-muted'; }
      tr.appendChild(cell(s.employee_number || '-'));
      tr.appendChild(cell(s.name + (s.active ? '' : '（無効）')));
      tr.appendChild(cell(s.department || ''));
      tr.appendChild(cell(EMP[s.employment] || s.employment));
      tr.appendChild(cell(s.roles.join(', ')));
      tr.appendChild(cell(s.qualifications.join(', ')));
      var how = [];
      if (s.staff_login) { how.push('社員番号'); }
      if (s.email) { how.push('メール（' + s.email + '）'); }
      tr.appendChild(cell((ACCOUNT[s.account] || '') + (how.length ? '：' + how.join('・') : '')));
      var ops = document.createElement('td');
      if (cfg.writable && s.active) {
        ops.appendChild(button('編集', function () { startEdit(s); }, true));
        ops.appendChild(button(s.staff_login ? 'パスワード再設定' : 'パスワード設定', function () { setPassword(s); }, true));
        if (s.account !== 'active') {
          ops.appendChild(button(s.account === 'none' ? 'メールで招待' : '再招待', function () { invite(s); }, true));
        }
        ops.appendChild(button('無効にする', function () { deactivate(s); }, true));
      } else if (cfg.writable && !s.active) {
        ops.appendChild(button('有効に戻す', function () { reactivate(s); }, true));
      }
      tr.appendChild(ops);
      rows.appendChild(tr);
    });
  }

  function load() {
    return api('GET', 'staff').then(function (d) { render(d.staff); }).catch(function (e) { say(e.message); });
  }

  function invite(s) {
    var email = window.prompt('招待するメールアドレスを入力してください。\n（ご自身のメールアドレスを入れると、メールを送らずに自分のアカウントをこのスタッフに紐づけます）', s.email || '');
    if (!email) { return; }
    api('POST', 'staff/' + s.id + '/invite', { email: email })
      .then(function (d) { say(d && d.linked ? 'ご自身のアカウントをこのスタッフに紐づけました。' : '招待メールを送信しました。', true); return load(); })
      .catch(function (e) { say(e.message); });
  }

  function setPassword(s) {
    if (!s.employee_number) { say('先に「編集」から、社員番号を登録してください（ログインに使います）。'); return; }
    var pw = window.prompt(s.name + ' さんのパスワードを入力してください（8文字以上）。\nスタッフは、社員番号「' + s.employee_number + '」と、このパスワードでログインします。');
    if (pw === null) { return; }
    api('POST', 'staff/' + s.id + '/password', { password: pw })
      .then(function () { say(s.name + ' さんのパスワードを設定しました。社員番号「' + s.employee_number + '」とパスワードを、ご本人に伝えてください。', true); return load(); })
      .catch(function (e) { say(e.message); });
  }

  function startEdit(s) {
    editing = s;
    var f = form.elements;
    f.employee_number.value = s.employee_number || '';
    f.name.value = s.name;
    f.kana.value = s.kana || '';
    f.department.value = s.department || '';
    f.employment.value = s.employment;
    f.roles.value = s.roles.join(', ');
    f.qualifications.value = s.qualifications.join(', ');
    document.getElementById('ss-submit').textContent = '保存';
    document.getElementById('ss-cancel').hidden = false;
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function endEdit() {
    editing = null;
    form.reset();
    document.getElementById('ss-submit').textContent = '追加';
    document.getElementById('ss-cancel').hidden = true;
  }

  function deactivate(s) {
    if (!window.confirm(s.name + ' さんを無効にしますか？（ログインもできなくなります。過去の記録は残ります）')) { return; }
    api('DELETE', 'staff/' + s.id).then(function () { say('無効にしました。', true); return load(); }).catch(function (e) { say(e.message); });
  }

  function reactivate(s) {
    api('PUT', 'staff/' + s.id, { active: true }).then(function () { say('有効に戻しました。', true); return load(); }).catch(function (e) { say(e.message); });
  }

  function split(v) {
    return v.split(/[,、，]/).map(function (x) { return x.trim(); }).filter(Boolean);
  }

  if (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var f = form.elements;
      var body = {
        employee_number: f.employee_number.value,
        name: f.name.value,
        kana: f.kana.value,
        department: f.department.value,
        employment: f.employment.value,
        roles: split(f.roles.value),
        qualifications: split(f.qualifications.value)
      };
      var req = editing ? api('PUT', 'staff/' + editing.id, body) : api('POST', 'staff', body);
      var wasEditing = !!editing;
      req.then(function () {
        endEdit();
        say(wasEditing ? '保存しました。' : '追加しました。', true);
        return load();
      }).catch(function (e) { say(e.message); });
    });
    document.getElementById('ss-cancel').addEventListener('click', endEdit);
  }

  // ---- 名簿のCSV ----
  function parseCsv(text) {
    var rows = [], row = [], cell = '', q = false;
    text = text.replace(/^\uFEFF/, '');
    for (var i = 0; i < text.length; i++) {
      var c = text[i];
      if (q) {
        if (c === '"') { if (text[i + 1] === '"') { cell += '"'; i++; } else { q = false; } }
        else { cell += c; }
      } else if (c === '"') { q = true; }
      else if (c === ',') { row.push(cell); cell = ''; }
      else if (c === '\n' || c === '\r') {
        if (c === '\r' && text[i + 1] === '\n') { i++; }
        row.push(cell); rows.push(row); row = []; cell = '';
      } else { cell += c; }
    }
    if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
    return rows.filter(function (r) { return r.join('') !== ''; }).map(function (r) {
      return r.map(function (v) { return v.replace(/^'(?=[=+\-@])/, ''); }); // 書き出し時に付けた「'」を外す
    });
  }

  function decode(buf) {
    // Excelの「CSV（コンマ区切り）」は Shift_JIS で保存されるため、UTF-8で読めなければ Shift_JIS として読む
    try { return new TextDecoder('utf-8', { fatal: true }).decode(buf); }
    catch (e) { return new TextDecoder('shift_jis').decode(buf); }
  }

  var resultBox = document.getElementById('ss-import-result');
  function showResult(html, ok) {
    resultBox.hidden = false;
    resultBox.className = 'ss-alert ' + (ok ? 'ss-alert-ok' : 'ss-alert-error');
    resultBox.textContent = '';
    html.forEach(function (line) { var p = document.createElement('div'); p.textContent = line; resultBox.appendChild(p); });
  }

  var exportBtn = document.getElementById('ss-export');
  if (exportBtn) {
    exportBtn.addEventListener('click', function () {
      api('GET', 'staff/export').then(function (d) {
        var blob = new Blob(['\uFEFF' + d.csv], { type: 'text/csv;charset=utf-8' }); // BOM付きにすると、Excelで文字化けしない
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = d.filename;
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
      }).catch(function (e) { say(e.message); });
    });
  }

  var importInput = document.getElementById('ss-import');
  if (importInput) {
    importInput.addEventListener('change', function () {
      var file = importInput.files && importInput.files[0];
      importInput.value = '';
      if (!file) { return; }
      file.arrayBuffer().then(function (buf) {
        var rows = parseCsv(decode(buf));
        return api('POST', 'staff/import', { rows: rows, dry_run: true }).then(function (r) {
          if (r.errors && r.errors.length) {
            showResult(['取り込めませんでした。CSVを直して、もう一度お試しください（何も変更していません）。'].concat(r.errors.map(function (e) {
              return (e.line ? e.line + '行目：' : '') + e.message;
            })), false);
            return;
          }
          if (!window.confirm('「' + file.name + '」を取り込みます。\n追加：' + r.created + '人　更新：' + r.updated + '人\nよろしいですか？')) {
            showResult(['取り込みを中止しました。'], true);
            return;
          }
          return api('POST', 'staff/import', { rows: rows }).then(function (r2) {
            showResult(['取り込みました。追加：' + r2.created + '人　更新：' + r2.updated + '人'], true);
            return load();
          });
        });
      }).catch(function (e) { showResult([e.message], false); });
    });
  }

  var copyBtn = document.getElementById('ss-copy-url');
  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      var input = document.getElementById('ss-login-url');
      input.select();
      var done = function () { say('ログインページのURLをコピーしました。', true); };
      if (navigator.clipboard) { navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); }); }
      else { document.execCommand('copy'); done(); }
    });
  }

  load();
})();
