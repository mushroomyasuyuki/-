/* 画面の共通部品（pages.js・schedule.js から使う） */
(function () {
  'use strict';
  var WEEK = ['日', '月', '火', '水', '木', '金', '土'];

  /* ---------- 共通 ---------- */

  function api(method, path, body) {
    var cfg = window.SS_CONFIG || {};
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
    var notice = document.getElementById('ss-notice');
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

  window.SSUI = {
    WEEK: WEEK, api: api, say: say, fail: fail, el: el, clear: clear, btn: btn, field: field, pad: pad,
    timeSelect: timeSelect, parseDate: parseDate, fmtDate: fmtDate, addDays: addDays, jpDate: jpDate,
    table: table, td: td
  };
})();
