/*
 * 壁紙データ（巾の枠）の CMYK PSD を作る Web Worker。
 * 画面（メインスレッド）は絵を描いて画素を読むだけにして、重い処理（RGB→CMYK の変換、行ごとの圧縮、
 * 統合イメージの合成、ZIP 用の CRC32）をここで行う。こうすると保存中も画面が固まらない。
 *
 * 受け取るメッセージ：
 *   { type: 'begin', width, height, names: [..], dpi }
 *   { type: 'band', y0, bh, bufs: [ArrayBuffer(RGBA, width*bh*4) をレイヤーの数だけ（下から順）] } → { type: 'ack' }
 *   { type: 'end' } → { type: 'done', parts: [ArrayBuffer..], size, crc }
 * 仕上がりは画面で作る writeCmykPsdTiled と同じ形（レイヤー：A,C,M,Y,K・RLE。統合イメージ：白地に全レイヤー）。
 */
(function () {
  const VER = 1;
  let S = null;

  const be = (bytes, v) => { const u = new Uint8Array(bytes); for (let i = bytes - 1; i >= 0; i--) { u[i] = v % 256; v = Math.floor(v / 256); } return u; };
  const i16 = (v) => be(2, v < 0 ? v + 65536 : v);
  const i32 = (v) => be(4, v < 0 ? v + 4294967296 : v);
  const ascii = (t) => Uint8Array.from(t.split('').map((c) => c.charCodeAt(0)));
  const concat = (arr) => { const t = arr.reduce((x, y) => x + y.length, 0); const u = new Uint8Array(t); let o = 0; arr.forEach((y) => { u.set(y, o); o += y.length; }); return u; };

  const CRC_TABLE = (() => {
    const tb = new Uint32Array(256);
    for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; tb[n] = c >>> 0; }
    return tb;
  })();

  function begin(m) {
    const w = m.width, h = m.height, n = m.names.length;
    const newChan = () => ({ counts: new Uint32Array(h), chunks: [], size: 0 });
    S = {
      w, h, names: m.names, dpi: m.dpi, big: w > 30000 || h > 30000,
      layers: m.names.map(() => [newChan(), newChan(), newChan(), newChan(), newChan()]), // C,M,Y,K,A
      comp: [newChan(), newChan(), newChan(), newChan()],
      rowBuf: new Uint8Array(w * 2 + 4),
      n,
    };
  }

  function rleRow(plane, base) {
    const w = S.w, rowBuf = S.rowBuf;
    let i = 0, r = 0;
    while (i < w) {
      const v = plane[base + i];
      let run = 1;
      while (i + run < w && run < 128 && plane[base + i + run] === v) run++;
      if (run >= 2) { rowBuf[r++] = 257 - run; rowBuf[r++] = v; i += run; continue; }
      const start = i; i++;
      while (i < w && i - start < 128 && !(i + 1 < w && plane[base + i] === plane[base + i + 1])) i++;
      rowBuf[r++] = i - start - 1;
      for (let q = start; q < i; q++) rowBuf[r++] = plane[base + q];
    }
    return rowBuf.slice(0, r);
  }

  // RGBA → C,M,Y,K（255 = インクなし）と A。透明な画素はインクなし
  function toCmyk(d, n, pl, withAlpha) {
    let last = -1, lc = 255, lm = 255, ly = 255, lk = 255;
    for (let i = 0, j = 0; i < n; i++, j += 4) {
      const al = d[j + 3];
      if (withAlpha) pl[4][i] = al;
      if (withAlpha && al === 0) { pl[0][i] = pl[1][i] = pl[2][i] = pl[3][i] = 255; continue; }
      const rr = d[j], gg = d[j + 1], bb = d[j + 2];
      const key = rr * 65536 + gg * 256 + bb;
      if (key !== last) {
        const mx = Math.max(rr, gg, bb);
        let c = 0, m = 0, yv = 0;
        if (mx > 0) { c = Math.round((mx - rr) * 255 / mx); m = Math.round((mx - gg) * 255 / mx); yv = Math.round((mx - bb) * 255 / mx); }
        last = key; lc = 255 - c; lm = 255 - m; ly = 255 - yv; lk = mx;
      }
      pl[0][i] = lc; pl[1][i] = lm; pl[2][i] = ly; pl[3][i] = lk;
    }
  }

  function band(m) {
    const w = S.w, bh = m.bh, n = w * bh;
    const pl = [new Uint8Array(n), new Uint8Array(n), new Uint8Array(n), new Uint8Array(n), new Uint8Array(n)];
    const add = (chans, count) => {
      for (let c = 0; c < count; c++) {
        for (let yy = 0; yy < bh; yy++) {
          const row = rleRow(pl[c], yy * w);
          chans[c].counts[m.y0 + yy] = row.length; chans[c].chunks.push(row); chans[c].size += row.length;
        }
      }
    };
    // 統合イメージ：白地の上にレイヤーを下から重ねる（canvas の source-over と同じ）
    const comp = new Uint8ClampedArray(n * 4);
    const cr = new Float32Array(n).fill(255), cg = new Float32Array(n).fill(255), cb = new Float32Array(n).fill(255);
    m.bufs.forEach((buf, k) => {
      const d = new Uint8ClampedArray(buf);
      toCmyk(d, n, pl, true);
      add(S.layers[k], 5);
      for (let i = 0, j = 0; i < n; i++, j += 4) {
        const al = d[j + 3];
        if (al === 0) continue;
        if (al === 255) { cr[i] = d[j]; cg[i] = d[j + 1]; cb[i] = d[j + 2]; continue; }
        const a = al / 255, b = 1 - a;
        cr[i] = cr[i] * b + d[j] * a; cg[i] = cg[i] * b + d[j + 1] * a; cb[i] = cb[i] * b + d[j + 2] * a;
      }
    });
    for (let i = 0, j = 0; i < n; i++, j += 4) { comp[j] = cr[i]; comp[j + 1] = cg[i]; comp[j + 2] = cb[i]; comp[j + 3] = 255; }
    toCmyk(comp, n, pl, false);
    add(S.comp, 4);
  }

  function end() {
    const { w, h, big } = S;
    const len = (v) => be(big ? 8 : 4, v);
    const RB = big ? 4 : 2;
    const rowCounts = (counts) => { const u = new Uint8Array(h * RB); counts.forEach((v, i) => u.set(be(RB, v), i * RB)); return u; };
    const chanLen = (c) => 2 + h * RB + c.size;
    const parts = [];
    parts.push(ascii('8BPS'), i16(big ? 2 : 1), new Uint8Array(6), i16(4), i32(h), i32(w), i16(8), i16(4), i32(0));
    const fx = Math.round(S.dpi * 65536);
    const resBody = concat([ascii('8BIM'), i16(1005), i16(0), i32(16), i32(fx), i16(1), i16(1), i32(fx), i16(1), i16(1)]);
    parts.push(i32(resBody.length), resBody);
    const recs = [], data = [];
    let dataLen = 0;
    S.names.forEach((name, k) => {
      const ch = S.layers[k];
      const order = [[-1, ch[4]], [0, ch[0]], [1, ch[1]], [2, ch[2]], [3, ch[3]]];
      // 旧形式のレイヤー名は ASCII だけ（日本語は「?」）。日本語の名前は luni（Unicode）で持つ
      const nameBytes = Uint8Array.from(name.split('').map((c) => (c.charCodeAt(0) > 126 ? 63 : c.charCodeAt(0)))).subarray(0, 255);
      const pad = (4 - ((1 + nameBytes.length) % 4)) % 4;
      const pascal = concat([Uint8Array.of(nameBytes.length), nameBytes, new Uint8Array(pad)]);
      const ub = new Uint8Array(name.length * 2);
      for (let i = 0; i < name.length; i++) { const c = name.charCodeAt(i); ub[i * 2] = c >> 8; ub[i * 2 + 1] = c % 256; }
      let luniData = concat([i32(name.length), ub]);
      if (luniData.length % 4) luniData = concat([luniData, new Uint8Array(4 - (luniData.length % 4))]);
      const luni = concat([ascii('8BIM'), ascii('luni'), i32(luniData.length), luniData]);
      const extra = concat([i32(0), i32(0), pascal, luni]);
      const head = [i32(0), i32(0), i32(h), i32(w), i16(5)];
      order.forEach((o) => { head.push(i16(o[0])); head.push(len(chanLen(o[1]))); });
      head.push(ascii('8BIM'), ascii('norm'), Uint8Array.of(255, 0, 0, 0), i32(extra.length), extra);
      recs.push(concat(head));
      order.forEach((o) => { [i16(1), rowCounts(o[1].counts)].concat(o[1].chunks).forEach((u) => { data.push(u); dataLen += u.length; }); });
    });
    const recLen = recs.reduce((t, u) => t + u.length, 0);
    let layerInfoLen = 2 + recLen + dataLen;
    const odd = layerInfoLen % 2;
    layerInfoLen += odd;
    parts.push(len((big ? 8 : 4) + layerInfoLen + 4), len(layerInfoLen), i16(S.n));
    recs.forEach((u) => parts.push(u));
    data.forEach((u) => parts.push(u));
    if (odd) parts.push(new Uint8Array(1));
    parts.push(i32(0)); // グローバルレイヤーマスク情報（空）
    parts.push(i16(1));
    [0, 1, 2, 3].forEach((c) => parts.push(rowCounts(S.comp[c].counts)));
    [0, 1, 2, 3].forEach((c) => S.comp[c].chunks.forEach((u) => parts.push(u)));
    S = null;

    // 小さな部品をまとめ（16MBずつ）、ZIP 用の CRC32 も計算する
    const out = [];
    let cur = [], curLen = 0, size = 0, crc = 0xFFFFFFFF;
    const flush = () => {
      if (!curLen) return;
      const u = new Uint8Array(curLen);
      let o = 0;
      cur.forEach((p) => { u.set(p, o); o += p.length; });
      for (let i = 0; i < u.length; i++) crc = CRC_TABLE[(crc ^ u[i]) & 255] ^ (crc >>> 8);
      out.push(u.buffer);
      size += curLen; cur = []; curLen = 0;
    };
    parts.forEach((p) => { cur.push(p); curLen += p.length; if (curLen > 16 * 1024 * 1024) flush(); });
    flush();
    return { parts: out, size, crc: (crc ^ 0xFFFFFFFF) >>> 0 };
  }

  self.onmessage = (ev) => {
    const m = ev.data;
    try {
      if (m.type === 'begin') { begin(m); self.postMessage({ type: 'ack' }); }
      else if (m.type === 'band') { band(m); self.postMessage({ type: 'ack' }); }
      else if (m.type === 'end') { const r = end(); self.postMessage({ type: 'done', parts: r.parts, size: r.size, crc: r.crc }, r.parts); }
    } catch (err) {
      S = null;
      self.postMessage({ type: 'error', message: err && err.message ? err.message : String(err) });
    }
  };
  self.postMessage({ type: 'ready', ver: VER });
})();
