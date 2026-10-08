/**
 * Carpet print PSD, made off the page (Web Worker) so that the page does not freeze on a big carpet.
 *
 * In:  { rgba: ArrayBuffer (width x height x 4), width, height, palette: [[r, g, b]...], dither: 0..1, layerName }
 * Out: { type: 'progress', value: 0..1 } ... then { type: 'done', parts: [ArrayBuffer...], used: [palette index...] }
 *      or { type: 'error', message }
 *
 * The colours are reduced to the palette exactly as on the page (nearest colour, Floyd-Steinberg error diffusion
 * scaled by "dither"), but only two rows of error are kept, so a 6.5 x 7.5 m carpet (1 px = 1 mm) needs little memory.
 * The PSD is RGB, 8 bit, 25.4 dpi, RLE (PackBits), 4 layers: ①CAD画像 (the drawing under the carpet) ②部屋 (room outline)
 * ③変換画像 (the reduced picture) ④割付 (50 cm tiles, cut ones light red) + the composite. Over 30000 px: PSB.
 * In (besides the above): info: { cad: { bitmap, sx, sy, sw, sh }, room: [[x, y]...], tiles: [{ x, y, w, h, cut }] } or null.
 */
/* eslint-env worker */
(function () {
  'use strict';

  function nearest(r, g, b, pal) {
    let best = 0, bd = Infinity;
    for (let p = 0; p < pal.length; p++) {
      const q = pal[p];
      const d = (r - q[0]) * (r - q[0]) + (g - q[1]) * (g - q[1]) + (b - q[2]) * (b - q[2]);
      if (d < bd) { bd = d; best = p; }
    }
    return best;
  }

  // reduce to the palette -> three planes (R, G, B), and which palette colours were used.
  // A generator: it pauses every 64 rows (yielding the progress), so that it can also run on the page in small steps.
  // lut: the colour table made on the page (the same assignment as the screen: look, how colours are seen)
  function* quantize(rgba, w, h, pal, dither, lut) {
    const n = w * h;
    const R = new Uint8Array(n), G = new Uint8Array(n), B = new Uint8Array(n), A = new Uint8Array(n).fill(255);
    let clear = false; // some pixels are the margin (transparent): no colour there
    const used = new Uint8Array(pal.length);
    let cur = new Float32Array((w + 2) * 3), next = new Float32Array((w + 2) * 3);
    for (let y = 0; y < h; y++) {
      next.fill(0);
      for (let x = 0; x < w; x++) {
        const i = (y * w + x) * 4, e = (x + 1) * 3;
        if (rgba[i + 3] < 128) { const o = y * w + x; R[o] = G[o] = B[o] = 255; A[o] = 0; clear = true; continue; }
        let r = rgba[i], g = rgba[i + 1], b = rgba[i + 2];
        if (dither > 0) {
          r = Math.min(255, Math.max(0, r + cur[e]));
          g = Math.min(255, Math.max(0, g + cur[e + 1]));
          b = Math.min(255, Math.max(0, b + cur[e + 2]));
        }
        const k = lut ? lut[((r >> 3) << 10) | ((g >> 3) << 5) | (b >> 3)] : nearest(r, g, b, pal), c = pal[k];
        used[k] = 1;
        const o = y * w + x;
        R[o] = c[0]; G[o] = c[1]; B[o] = c[2];
        if (dither > 0) {
          const er = (r - c[0]) * dither, eg = (g - c[1]) * dither, eb = (b - c[2]) * dither;
          // right: 7/16; next row: left 3/16, below 5/16, right 1/16 (the padding columns take what falls outside)
          if (x + 1 < w) { cur[e + 3] += er * 7 / 16; cur[e + 4] += eg * 7 / 16; cur[e + 5] += eb * 7 / 16; }
          if (x > 0) { next[e - 3] += er * 3 / 16; next[e - 2] += eg * 3 / 16; next[e - 1] += eb * 3 / 16; }
          next[e] += er * 5 / 16; next[e + 1] += eg * 5 / 16; next[e + 2] += eb * 5 / 16;
          if (x + 1 < w) { next[e + 3] += er / 16; next[e + 4] += eg / 16; next[e + 5] += eb / 16; }
        }
      }
      const t = cur; cur = next; next = t;
      if (y % 64 === 63) yield 0.8 * y / h;
    }
    const list = [];
    used.forEach((u, i) => { if (u) list.push(i); });
    return { R, G, B, A: clear ? A : null, used: list };
  }

  // a canvas for drawing one band of a layer: OffscreenCanvas (worker / page) or a page canvas
  function makeCanvas(w, h) {
    if (typeof OffscreenCanvas !== 'undefined') return new OffscreenCanvas(w, h);
    const c = document.createElement('canvas');
    c.width = w; c.height = h;
    return c;
  }

  // the drawn layers, in the carpet's picture (mm = px, Y down). info: { cad, room, tiles } (see main.js carpetPsdInfo)
  // bleed: the margin (px = mm) around the carpet; the grid and the carpet outline are inside it
  function layerDraws(w, h, info, bleed) {
    const B = bleed || 0;
    const tiles = info ? info.tiles : null;
    const grid = [];
    if (tiles ? tiles.length : false) tiles.forEach((t) => grid.push(t));
    else { // no drawing: 50 cm tiles from the top left of the carpet
      for (let y = B; y < h - B; y += 500) for (let x = B; x < w - B; x += 500) grid.push({ x, y, w: Math.min(500, w - B - x), h: Math.min(500, h - B - y), cut: false });
    }
    return {
      cad: (ctx) => {
        const c = info ? info.cad : null;
        if (!c) return;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, w, h);
        ctx.imageSmoothingEnabled = true;
        ctx.drawImage(c.bitmap, c.sx, c.sy, c.sw, c.sh, 0, 0, w, h);
      },
      room: (ctx) => {
        const pts = info ? info.room : null;
        if (!pts) return;
        if (pts.length < 3) return;
        ctx.beginPath();
        pts.forEach((q, i) => { if (i === 0) ctx.moveTo(q[0], q[1]); else ctx.lineTo(q[0], q[1]); });
        ctx.closePath();
        ctx.fillStyle = 'rgba(37,99,235,0.10)';
        ctx.fill();
        ctx.strokeStyle = '#2563eb';
        ctx.lineWidth = 8;
        ctx.stroke();
      },
      grid: (ctx) => {
        grid.forEach((t) => { if (t.cut) { ctx.fillStyle = 'rgba(239,68,68,0.14)'; ctx.fillRect(t.x, t.y, t.w, t.h); } });
        ctx.strokeStyle = '#ef4444';
        ctx.lineWidth = 3;
        grid.forEach((t) => ctx.strokeRect(t.x, t.y, t.w, t.h));
        ctx.strokeStyle = '#dc2626';
        ctx.lineWidth = 8;
        ctx.strokeRect(B + 2, B + 2, w - B * 2 - 4, h - B * 2 - 4);
        // センター: the room's centre (no drawing: the carpet's centre), white under red so it shows on any colour
        const c = info ? info.center : null;
        const cx = c ? c[0] : w / 2, cy = c ? c[1] : h / 2;
        const m = Math.min(w, h), arm = Math.min(Math.max(300, m * 0.12), m * 0.15), rr = arm * 0.45;
        const cross = () => { ctx.beginPath(); ctx.moveTo(cx - arm, cy); ctx.lineTo(cx + arm, cy); ctx.moveTo(cx, cy - arm); ctx.lineTo(cx, cy + arm); ctx.stroke(); };
        ctx.strokeStyle = '#ffffff'; ctx.lineWidth = 14; cross();
        ctx.strokeStyle = '#dc2626'; ctx.lineWidth = 7; cross();
        ctx.beginPath(); ctx.arc(cx, cy, rr, 0, Math.PI * 2); ctx.stroke();
        ctx.fillStyle = '#dc2626'; ctx.beginPath(); ctx.arc(cx, cy, rr * 0.3, 0, Math.PI * 2); ctx.fill();
        ctx.font = 'bold ' + Math.round(arm * 0.45) + 'px sans-serif'; ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic';
        ctx.lineWidth = 10; ctx.strokeStyle = '#ffffff'; ctx.strokeText('センター', cx + rr * 1.2, cy - rr * 1.2);
        ctx.fillText('センター', cx + rr * 1.2, cy - rr * 1.2);
      },
    };
  }

  // the PSD: 4 layers (①CAD画像 ②部屋 ③変換画像 ④割付, bottom to top) + the composite (変換画像 + 割付).
  // The drawn layers are made 256 rows at a time, so no picture as big as the carpet is held for them.
  function* writePsd(w, h, planes, info, names, bleed) {
    const big = w > 30000 || h > 30000;
    const parts = [];
    const be = (bytes, v) => { const u = new Uint8Array(bytes); for (let i = bytes - 1; i >= 0; i--) { u[i] = v % 256; v = Math.floor(v / 256); } return u; };
    const i16 = (v) => be(2, v < 0 ? v + 65536 : v);
    const i32 = (v) => be(4, v < 0 ? v + 4294967296 : v);
    const len = (v) => be(big ? 8 : 4, v);
    const RB = big ? 4 : 2;
    const ascii = (t) => Uint8Array.from(t.split('').map((c) => c.charCodeAt(0)));
    const concat = (arr) => { const t = arr.reduce((a, b) => a + b.length, 0); const u = new Uint8Array(t); let o = 0; arr.forEach((b) => { u.set(b, o); o += b.length; }); return u; };
    const rowBuf = new Uint8Array(w * 2 + 4);
    const rleRow = (plane, base) => {
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
    };
    const newChan = () => ({ counts: new Uint8Array(h * RB), chunks: [], size: 0 });
    const addRow = (ch, y, row) => { ch.counts.set(be(RB, row.length), y * RB); ch.chunks.push(row); ch.size += row.length; };
    const chanLen = (c) => 2 + c.counts.length + c.size;

    const draws = layerDraws(w, h, info, bleed);
    const TH = 256;
    const band = makeCanvas(w, TH);
    const bctx = band.getContext('2d', { willReadFrequently: true });
    // a drawn layer -> channels A, R, G, B (and, for the grid, kept bands for the composite)
    const drawn = {};
    const kinds = ['cad', 'room', 'grid'];
    let step = 0;
    const steps = kinds.length * Math.ceil(h / TH);
    for (const kind of kinds) {
      const ch = [newChan(), newChan(), newChan(), newChan()];
      const keep = kind === 'grid' ? [] : null;
      for (let y0 = 0; y0 < h; y0 += TH) {
        const bh = Math.min(TH, h - y0);
        bctx.setTransform(1, 0, 0, 1, 0, 0);
        bctx.clearRect(0, 0, w, TH);
        bctx.setTransform(1, 0, 0, 1, 0, -y0);
        bctx.save(); draws[kind](bctx); bctx.restore();
        const d = bctx.getImageData(0, 0, w, bh).data;
        if (keep) keep.push(d);
        const n = w * bh;
        const pa = new Uint8Array(n), pr = new Uint8Array(n), pg = new Uint8Array(n), pb = new Uint8Array(n);
        for (let i = 0, j = 0; i < n; i++, j += 4) { pr[i] = d[j]; pg[i] = d[j + 1]; pb[i] = d[j + 2]; pa[i] = d[j + 3]; }
        for (let yy = 0; yy < bh; yy++) {
          addRow(ch[0], y0 + yy, rleRow(pa, yy * w));
          addRow(ch[1], y0 + yy, rleRow(pr, yy * w));
          addRow(ch[2], y0 + yy, rleRow(pg, yy * w));
          addRow(ch[3], y0 + yy, rleRow(pb, yy * w));
        }
        step++;
        yield 0.8 + 0.17 * step / steps;
      }
      drawn[kind] = { ch, keep };
    }
    // the reduced picture: R, G, B (opaque), or with transparency where the margin has no colour
    const img = planes.A ? [newChan(), newChan(), newChan(), newChan()] : [newChan(), newChan(), newChan()];
    for (let y = 0; y < h; y++) {
      addRow(img[0], y, rleRow(planes.R, y * w));
      addRow(img[1], y, rleRow(planes.G, y * w));
      addRow(img[2], y, rleRow(planes.B, y * w));
      if (planes.A) addRow(img[3], y, rleRow(planes.A, y * w));
    }
    // the composite: the reduced picture with the grid over it
    const comp = [newChan(), newChan(), newChan()];
    const crow = [new Uint8Array(w), new Uint8Array(w), new Uint8Array(w)];
    const gk = drawn.grid.keep;
    for (let y = 0; y < h; y++) {
      const gd = gk[Math.floor(y / TH)], gy = y % TH;
      for (let x = 0; x < w; x++) {
        const o = y * w + x, j = (gy * w + x) * 4, a = gd[j + 3] / 255;
        crow[0][x] = Math.round(planes.R[o] * (1 - a) + gd[j] * a);
        crow[1][x] = Math.round(planes.G[o] * (1 - a) + gd[j + 1] * a);
        crow[2][x] = Math.round(planes.B[o] * (1 - a) + gd[j + 2] * a);
      }
      for (let c = 0; c < 3; c++) addRow(comp[c], y, rleRow(crow[c], 0));
    }
    yield 0.99;

    parts.push(ascii('8BPS'), i16(big ? 2 : 1), new Uint8Array(6), i16(3), i32(h), i32(w), i16(8), i16(3), i32(0));
    const fx = Math.round(25.4 * 65536);
    const res = concat([ascii('8BIM'), i16(1005), i16(0), i32(16), i32(fx), i16(1), i16(1), i32(fx), i16(1), i16(1)]);
    parts.push(i32(res.length), res);

    const record = (name, chans, ids, flags) => {
      // the old style name is ASCII only (Japanese as "?"); the real name is in 'luni' (Unicode)
      const nameBytes = Uint8Array.from(name.split('').map((c) => (c.charCodeAt(0) > 126 ? 63 : c.charCodeAt(0)))).subarray(0, 255);
      const pad = (4 - ((1 + nameBytes.length) % 4)) % 4;
      const pascal = concat([Uint8Array.of(nameBytes.length), nameBytes, new Uint8Array(pad)]);
      const ub = new Uint8Array(name.length * 2);
      for (let i = 0; i < name.length; i++) { const c = name.charCodeAt(i); ub[i * 2] = c >> 8; ub[i * 2 + 1] = c % 256; }
      let luniData = concat([i32(name.length), ub]);
      if (luniData.length % 4) luniData = concat([luniData, new Uint8Array(4 - (luniData.length % 4))]);
      const luni = concat([ascii('8BIM'), ascii('luni'), i32(luniData.length), luniData]);
      const extra = concat([i32(0), i32(0), pascal, luni]);
      const head = [i32(0), i32(0), i32(h), i32(w), i16(chans.length)];
      ids.forEach((id, k) => { head.push(i16(id)); head.push(len(chanLen(chans[k]))); });
      head.push(ascii('8BIM'), ascii('norm'), Uint8Array.of(255, 0, flags, 0), i32(extra.length), extra);
      return concat(head);
    };
    const L = [
      { name: names.cad, chans: drawn.cad.ch, ids: [-1, 0, 1, 2], flags: 8 },
      { name: names.room, chans: drawn.room.ch, ids: [-1, 0, 1, 2], flags: 8 },
      planes.A ? { name: names.img, chans: [img[3], img[0], img[1], img[2]], ids: [-1, 0, 1, 2], flags: 8 } : { name: names.img, chans: img, ids: [0, 1, 2], flags: 8 },
      { name: names.grid, chans: drawn.grid.ch, ids: [-1, 0, 1, 2], flags: 8 },
    ];
    const recs = L.map((l) => record(l.name, l.chans, l.ids, l.flags));
    const dataLen = L.reduce((t, l) => t + l.chans.reduce((u, c) => u + chanLen(c), 0), 0);
    let infoLen = 2 + recs.reduce((t, r) => t + r.length, 0) + dataLen;
    const odd = infoLen % 2;
    infoLen += odd;
    parts.push(len((big ? 8 : 4) + infoLen + 4), len(infoLen), i16(L.length));
    recs.forEach((r) => parts.push(r));
    L.forEach((l) => l.chans.forEach((c) => { parts.push(i16(1), c.counts); c.chunks.forEach((u) => parts.push(u)); }));
    if (odd) parts.push(new Uint8Array(1));
    parts.push(i32(0));

    parts.push(i16(1));
    comp.forEach((c) => parts.push(c.counts));
    comp.forEach((c) => c.chunks.forEach((u) => parts.push(u)));
    return parts;
  }

  // the whole job (a generator: progress values, then { parts, used })
  function* job(m) {
    const it = quantize(new Uint8ClampedArray(m.rgba), m.width, m.height, m.palette, m.dither, m.lut || null);
    let r = it.next();
    while (!r.done) { yield r.value; r = it.next(); }
    const q = r.value;
    // mode 'reduce': only the reduced picture (RGBA, the same size), written over the input (it is not needed any more)
    if (m.mode === 'reduce') {
      const out = new Uint8ClampedArray(m.rgba);
      for (let i = 0, j = 0; i < q.R.length; i++, j += 4) { out[j] = q.R[i]; out[j + 1] = q.G[i]; out[j + 2] = q.B[i]; out[j + 3] = q.A ? q.A[i] : 255; }
      return { parts: [m.rgba], used: q.used };
    }
    const names = Object.assign({ cad: '①CAD画像', room: '②部屋', img: '③変換画像（減色・実寸）', grid: '④割付（50cm角）' }, m.names || {});
    const wt = writePsd(m.width, m.height, q, m.info || null, names, m.bleed || 0);
    let p = wt.next();
    while (!p.done) { yield p.value; p = wt.next(); }
    return { parts: pack(p.value), used: q.used };
  }

  // join the small pieces into a few big buffers
  function pack(parts) {
    const out = [];
    let cur = [], curLen = 0;
    const flush = () => {
      if (!curLen) return;
      const u = new Uint8Array(curLen); let o = 0;
      cur.forEach((p) => { u.set(p, o); o += p.length; });
      out.push(u.buffer); cur = []; curLen = 0;
    };
    parts.forEach((p) => { cur.push(p); curLen += p.length; if (curLen > 16 * 1024 * 1024) flush(); });
    flush();
    return out;
  }

  // On the page (when a worker cannot be started): the same work, a little at a time
  if (typeof window !== 'undefined') {
    window.CFPCarpetPsd = {
      ver: 9,
      run: async (m, progress) => {
        const it = job(m);
        let r = it.next();
        while (!r.done) { progress(r.value); await new Promise((res) => setTimeout(res, 0)); r = it.next(); }
        return r.value;
      }
    };
    return;
  }

  // ver: 9 = 変換画像だけを実寸で返す（mode: 'reduce'）。ver: 8 = 色の対応表（印象）・余白（透明）に色を付けない・4レイヤー（CAD画像・部屋・変換画像・割付＋センター）・上下左右の余白（bleed）。古い部品がキャッシュから読まれていないかを、画面側で確かめる
  self.postMessage({ type: 'ready', ver: 9, offscreen: typeof OffscreenCanvas !== 'undefined' });
  self.onmessage = (ev) => {
    try {
      const it = job(ev.data);
      let r = it.next();
      while (!r.done) { self.postMessage({ type: 'progress', value: r.value }); r = it.next(); }
      self.postMessage({ type: 'done', parts: r.value.parts, used: r.value.used }, r.value.parts);
    } catch (err) {
      self.postMessage({ type: 'error', message: err && err.message ? err.message : String(err) });
    }
  };
})();
