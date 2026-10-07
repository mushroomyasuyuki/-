(function () {
  'use strict';
  var cfg = window.SS_CONFIG || {};
  var rows = document.getElementById('ss-rows');
  var notice = document.getElementById('ss-notice');
  var count = document.getElementById('ss-count');
  var form = document.getElementById('ss-add');
  var EMP = { full: '正社員', part: 'パート', baito: 'アルバイト' };
  var ACCOUNT = { none: '未招待', invited: '招待中', active: '登録済み', disabled: '無効' };

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
      td0.colSpan = 6;
      tr0.appendChild(td0);
      rows.appendChild(tr0);
      return;
    }
    list.forEach(function (s) {
      var tr = document.createElement('tr');
      if (!s.active) { tr.className = 'ss-muted'; }
      tr.appendChild(cell(s.name + (s.active ? '' : '（無効）')));
      tr.appendChild(cell(EMP[s.employment] || s.employment));
      tr.appendChild(cell(s.roles.join(', ')));
      tr.appendChild(cell(s.qualifications.join(', ')));
      tr.appendChild(cell((ACCOUNT[s.account] || '') + (s.email ? '（' + s.email + '）' : '')));
      var ops = document.createElement('td');
      if (cfg.writable && s.active) {
        if (s.account !== 'active') {
          ops.appendChild(button(s.account === 'none' ? '招待' : '再招待', function () { invite(s); }, true));
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
    var email = window.prompt('招待するメールアドレスを入力してください。', s.email || '');
    if (!email) { return; }
    api('POST', 'staff/' + s.id + '/invite', { email: email })
      .then(function () { say('招待メールを送信しました。', true); return load(); })
      .catch(function (e) { say(e.message); });
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
      api('POST', 'staff', {
        name: f.name.value,
        kana: f.kana.value,
        employment: f.employment.value,
        roles: split(f.roles.value),
        qualifications: split(f.qualifications.value)
      }).then(function () {
        form.reset();
        say('追加しました。', true);
        return load();
      }).catch(function (e) { say(e.message); });
    });
  }

  load();
})();
