/*
 * マッシュオフィスシステムの画面（日報・予定進捗・朝礼・管理者集計）を、このサービスの中で動かすための下準備。
 * 画面より先に読み込む。画面のコードは、書き換えずにそのまま使う。
 *
 *  1. ブラウザ保存（localStorage）の「mush_…」を、お客様ごとに分ける
 *     （同じブラウザで、別のお客様や、従来のマッシュオフィスシステムを開いても混ざらない）
 *  2. 画面のログイン情報を、このサービスのログイン（WordPress）から用意する（画面のログイン入力を省く）
 *  3. 画面が呼ぶ mush-office/v1 のAPIを、このサービスの mo/v1（お客様ごとに分離したAPI）へ向け直す
 *  4. 画面内の「トップに戻る」などのリンクを、このサービスの画面へ向け直す
 */
(function () {
  'use strict';
  var C = window.MO_CONFIG || {};
  var PREFIX = 'mo:' + C.tenant + ':';

  // 1. ブラウザ保存をお客様ごとに分ける
  var S = Storage.prototype, get = S.getItem, set = S.setItem, del = S.removeItem;
  function key(k) { return (typeof k === 'string' && k.indexOf('mush_') === 0) ? PREFIX + k : k; }
  S.getItem = function (k) { return get.call(this, key(k)); };
  S.setItem = function (k, v) { return set.call(this, key(k), v); };
  S.removeItem = function (k) { return del.call(this, key(k)); };

  // 2. ログイン情報
  try {
    var exp = Date.now() + 12 * 3600 * 1000;
    if (C.auth && C.auth.staff) {
      localStorage.setItem('mush_nippo_auth', JSON.stringify({ token: 'wp-session', staffId: String(C.auth.staff.staffId), staffName: C.auth.staff.staffName, dept: C.auth.staff.dept, expiresAt: exp }));
    } else {
      localStorage.removeItem('mush_nippo_auth');
    }
    if (C.auth && C.auth.admin) {
      localStorage.setItem('mush_admin_auth', JSON.stringify({ token: 'wp-session', expiresAt: exp }));
    } else {
      localStorage.removeItem('mush_admin_auth');
    }
  } catch (e) { /* 保存できない環境では、画面のログイン入力が出る（APIは、このサービスのログインで確かめる） */ }

  // 画面ごとの「ログアウト」ボタンは出さない（上のメニューのログアウトが、このサービスのログアウト）
  window.__mushLogoutInstalled = true;

  // 3. APIの向け直し
  var MARK = 'mush-office/v1';
  function rewrite(url) {
    var i = url.indexOf(MARK);
    var rest = url.slice(i + MARK.length);
    if (C.base.indexOf('?') !== -1) { rest = rest.replace('?', '&'); }
    return C.base + rest;
  }
  function sameOrigin(url) {
    try { return new URL(url, location.href).origin === location.origin; } catch (e) { return false; }
  }
  var origFetch = window.fetch;
  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    if (url.indexOf(MARK) === -1 || !sameOrigin(url)) {
      return origFetch.apply(this, arguments);
    }
    init = Object.assign({}, init || {});
    if (typeof input !== 'string' && input) {
      init.method = init.method || input.method;
      init.headers = init.headers || input.headers;
    }
    var h = new Headers(init.headers || {});
    h.delete('Authorization');
    h.set('X-WP-Nonce', C.nonce);
    init.headers = h;
    init.credentials = 'same-origin';
    return origFetch.call(this, rewrite(url), init).then(function (res) {
      if (res.status === 401 || res.status === 403) {
        res.clone().json().then(function (d) {
          if (d && (d.code === 'rest_cookie_invalid_nonce' || d.code === 'rest_not_logged_in')) { showNotice('ログインの有効期限が切れました。ページを再読み込みしてください。'); }
          else if (d && d.code === 'rest_forbidden' && C.readonly) { showNotice('現在は閲覧のみの状態のため、変更できません。'); }
        }).catch(function () {});
      }
      return res;
    });
  };

  // 4. リンクの向け直し
  document.addEventListener('click', function (ev) {
    var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
    if (!a || a.target === '_blank') { return; }
    var path;
    try {
      var u = new URL(a.getAttribute('href'), location.href);
      if (u.origin !== location.origin) { return; }
      path = decodeURIComponent(u.pathname);
    } catch (e) { return; }
    var m = path.match(/\/(トップ|日報|予定進捗|朝礼|管理者集計|従業員登録|タイムカード|QRコード|経理|給与計算|自社情報|有給照会|管理者チャット)\/?$/);
    if (!m) { return; }
    ev.preventDefault();
    location.href = (C.links && C.links[m[1]]) || C.links['トップ'];
  }, true);

  function showNotice(text) {
    var el = document.getElementById('mo-notice');
    if (!el) { return; }
    el.textContent = text;
    el.hidden = false;
  }
})();
