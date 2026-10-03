/**
 * Carpet print PSD, made off the page (Web Worker) so that the page does not freeze on a big carpet.
 *
 * In:  { rgba: ArrayBuffer (width x height x 4), width, height, palette: [[r, g, b]...], dither: 0..1, layerName }
 * Out: { type: 'progress', value: 0..1 } ... then { type: 'done', parts: [ArrayBuffer...], used: [palette index...] }
 *      or { type: 'error', message }
 *
 * The colours are reduced to the palette exactly as on the page (nearest colour, Floyd-Steinberg error diffusion
 * scaled by "dither"), but only two rows of error are kept, so a 6.5 x 7.5 m carpet (1 px = 1 mm) needs little memory.
 * The PSD is RGB, 8 bit, 25.4 dpi, one layer + the composite, RLE (PackBits). Over 30000 px it is written as PSB.
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
  function* quantize(rgba, w, h, pal, dither) {
    const n = w * h;
    const R = new Uint8Array(n), G = new Uint8Array(n), B = new Uint8Array(n);
    const used = new Uint8Array(pal.length);
    let cur = new Float32Array((w + 2) * 3), next = new Float32Array((w + 2) * 3);
    for (let y = 0; y < h; y++) {
      next.fill(0);
      for (let x = 0; x < w; x++) {
        const i = (y * w + x) * 4, e = (x + 1) * 3;
        let r = rgba[i], g = rgba[i + 1], b = rgba[i + 2];
        if (dither > 0) {
          r = Math.min(255, Math.max(0, r + cur[e]));
          g = Math.min(255, Math.max(0, g + cur[e + 1]));
          b = Math.min(255, Math.max(0, b + cur[e + 2]));
        }
        const k = nearest(r, g, b, pal), c = pal[k];
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
    return { R, G, B, used: list };
  }

  function writePsd(w, h, planes, layerName, progress) {
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
    // one channel: RLE row by row -> { counts, data }
    const rle = (plane) => {
      const counts = new Uint8Array(h * RB);
      const chunks = [];
      let size = 0;
      for (let y = 0; y < h; y++) {
        const base = y * w;
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
        counts.set(be(RB, r), y * RB);
        chunks.push(rowBuf.slice(0, r));
        size += r;
      }
      return { counts, chunks, size };
    };
    const alpha = new Uint8Array(w * h).fill(255);
    const enc = [rle(alpha)];
    progress(0.84);
    enc.push(rle(planes.R)); progress(0.88);
    enc.push(rle(planes.G)); progress(0.92);
    enc.push(rle(planes.B)); progress(0.96);
    const chanLen = (c) => 2 + c.counts.length + c.size;

    parts.push(ascii('8BPS'), i16(big ? 2 : 1), new Uint8Array(6), i16(3), i32(h), i32(w), i16(8), i16(3), i32(0));
    const fx = Math.round(25.4 * 65536);
    const res = concat([ascii('8BIM'), i16(1005), i16(0), i32(16), i32(fx), i16(1), i16(1), i32(fx), i16(1), i16(1)]);
    parts.push(i32(res.length), res);

    // layer record
    // the old style name is ASCII only (Japanese as "?"); the real name is in 'luni' (Unicode)
    const nameBytes = Uint8Array.from(layerName.split('').map((c) => (c.charCodeAt(0) > 126 ? 63 : c.charCodeAt(0)))).subarray(0, 255);
    const pad = (4 - ((1 + nameBytes.length) % 4)) % 4;
    const pascal = concat([Uint8Array.of(nameBytes.length), nameBytes, new Uint8Array(pad)]);
    const ub = new Uint8Array(layerName.length * 2);
    for (let i = 0; i < layerName.length; i++) { const c = layerName.charCodeAt(i); ub[i * 2] = c >> 8; ub[i * 2 + 1] = c % 256; }
    let luniData = concat([i32(layerName.length), ub]);
    if (luniData.length % 4) luniData = concat([luniData, new Uint8Array(4 - (luniData.length % 4))]);
    const luni = concat([ascii('8BIM'), ascii('luni'), i32(luniData.length), luniData]);
    const extra = concat([i32(0), i32(0), pascal, luni]);
    const ids = [-1, 0, 1, 2];
    const head = [i32(0), i32(0), i32(h), i32(w), i16(4)];
    ids.forEach((id, k) => { head.push(i16(id)); head.push(len(chanLen(enc[k]))); });
    head.push(ascii('8BIM'), ascii('norm'), Uint8Array.of(255, 0, 0, 0), i32(extra.length), extra);
    const rec = concat(head);
    const dataLen = enc.reduce((t, c) => t + chanLen(c), 0);
    let infoLen = 2 + rec.length + dataLen;
    const odd = infoLen % 2;
    infoLen += odd;
    parts.push(len((big ? 8 : 4) + infoLen + 4), len(infoLen), i16(1), rec);
    enc.forEach((c) => { parts.push(i16(1), c.counts); c.chunks.forEach((u) => parts.push(u)); });
    if (odd) parts.push(new Uint8Array(1));
    parts.push(i32(0));

    // composite: R, G, B (the same RLE data)
    parts.push(i16(1));
    enc.slice(1).forEach((c) => parts.push(c.counts));
    enc.slice(1).forEach((c) => c.chunks.forEach((u) => parts.push(u)));
    return parts;
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
      run: async (m, progress) => {
        const it = quantize(new Uint8ClampedArray(m.rgba), m.width, m.height, m.palette, m.dither);
        let r = it.next();
        while (!r.done) { progress(r.value); await new Promise((res) => setTimeout(res, 0)); r = it.next(); }
        const q = r.value;
        const parts = writePsd(m.width, m.height, q, m.layerName || 'layer', progress);
        return { parts: pack(parts), used: q.used };
      }
    };
    return;
  }

  self.postMessage({ type: 'ready' });
  self.onmessage = (ev) => {
    try {
      const m = ev.data;
      const progress = (v) => self.postMessage({ type: 'progress', value: v });
      const it = quantize(new Uint8ClampedArray(m.rgba), m.width, m.height, m.palette, m.dither);
      let r = it.next();
      while (!r.done) { progress(r.value); r = it.next(); }
      const q = r.value;
      const parts = writePsd(m.width, m.height, q, m.layerName || 'layer', progress);
      const out = pack(parts);
      self.postMessage({ type: 'done', parts: out, used: q.used }, out);
    } catch (err) {
      self.postMessage({ type: 'error', message: err && err.message ? err.message : String(err) });
    }
  };
})();
