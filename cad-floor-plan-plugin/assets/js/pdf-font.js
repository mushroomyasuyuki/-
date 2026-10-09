/**
 * PDF（.ai）に埋め込むフォント：Noto Sans JP Regular（assets/fonts/NotoSansJP-Regular.ttf・SIL Open Font License 1.1）
 *
 * TrueType（glyf）のフォントを読み、文字 → グリフ番号・文字の幅を返し、使ったグリフだけを残したフォント（サブセット）を作る。
 * サブセットはグリフの番号を変えずに、使わないグリフを空にする（CIDFontType2・CIDToGIDMap /Identity でそのまま使える）。
 * window.CFPPdfFont.load(url) → Promise<font>
 *   font.name（PostScript 名）・font.upm・font.gid(コードポイント)・font.adv(gid)（1000 あたりの幅）・font.subset(gids) → Uint8Array
 *   font.bbox・font.ascent・font.descent・font.capHeight（1000 あたり）
 */
(function () {
  'use strict';

  function parse(buf) {
    const d = new DataView(buf), u8 = new Uint8Array(buf);
    const u16 = (o) => d.getUint16(o), i16 = (o) => d.getInt16(o), u32 = (o) => d.getUint32(o);
    const tag = (o) => String.fromCharCode(u8[o], u8[o + 1], u8[o + 2], u8[o + 3]);
    const n = u16(4), T = {};
    for (let i = 0; i < n; i++) {
      const r = 12 + i * 16;
      T[tag(r)] = { off: u32(r + 8), len: u32(r + 12) };
    }
    ['head', 'hhea', 'hmtx', 'maxp', 'cmap', 'loca', 'glyf'].forEach((t) => { if (!T[t]) throw new Error('フォントに ' + t + ' がありません（TrueType のフォントが必要です）'); });
    const head = T.head.off, upm = u16(head + 18), longLoca = i16(head + 50) === 1;
    const numGlyphs = u16(T.maxp.off + 4), nhm = u16(T.hhea.off + 34);
    const os2 = T['OS/2'] ? T['OS/2'].off : 0;
    const k = 1000 / upm;
    // 文字 → グリフ番号（cmap：Windows Unicode の format 12 か 4）
    let fmt12 = null, fmt4 = null;
    const cm = T.cmap.off;
    for (let i = 0; i < u16(cm + 2); i++) {
      const pid = u16(cm + 4 + i * 8), eid = u16(cm + 6 + i * 8), so = cm + u32(cm + 8 + i * 8), f = u16(so);
      if (pid === 3 ? (eid === 10 ? f === 12 : false) : false) fmt12 = so;
      if (pid === 3 ? (eid === 1 ? f === 4 : false) : false) fmt4 = so;
      if (pid === 0 ? f === 12 : false) fmt12 = fmt12 || so;
      if (pid === 0 ? f === 4 : false) fmt4 = fmt4 || so;
    }
    const cache = new Map();
    const gid = (cp) => {
      if (cache.has(cp)) return cache.get(cp);
      let g = 0;
      if (fmt12) {
        const ng = u32(fmt12 + 12);
        let lo = 0, hi = ng - 1;
        while (lo <= hi) {
          const mid = (lo + hi) >> 1, r = fmt12 + 16 + mid * 12, s = u32(r), e = u32(r + 4);
          if (cp < s) hi = mid - 1; else if (cp > e) lo = mid + 1; else { g = u32(r + 8) + cp - s; break; }
        }
      } else if (fmt4 ? cp < 65536 : false) {
        const seg = u16(fmt4 + 6) / 2, ends = fmt4 + 14, starts = ends + seg * 2 + 2, deltas = starts + seg * 2, ros = deltas + seg * 2;
        for (let i = 0; i < seg; i++) {
          if (cp > u16(ends + i * 2)) continue;
          const s = u16(starts + i * 2);
          if (cp < s) break;
          const ro = u16(ros + i * 2);
          if (ro === 0) g = (cp + i16(deltas + i * 2)) & 0xffff;
          else { const gg = u16(ros + i * 2 + ro + (cp - s) * 2); g = gg ? (gg + i16(deltas + i * 2)) & 0xffff : 0; }
          break;
        }
      }
      cache.set(cp, g);
      return g;
    };
    const adv = (g) => Math.round(u16(T.hmtx.off + 4 * Math.min(g, nhm - 1)) * k);
    const loca = (g) => (longLoca ? u32(T.loca.off + g * 4) : u16(T.loca.off + g * 2) * 2);
    let name = 'NotoSansJP-Regular';
    if (T.name) {
      const nm = T.name.off, cnt = u16(nm + 2), so = nm + u16(nm + 4);
      for (let i = 0; i < cnt; i++) {
        const r = nm + 6 + i * 12;
        if (u16(r + 6) !== 6) continue;
        const pid = u16(r), len = u16(r + 8), off = so + u16(r + 10);
        let s = '';
        if (pid === 3 ? true : pid === 0) { for (let j = 0; j < len; j += 2) s += String.fromCharCode(u16(off + j)); }
        else { for (let j = 0; j < len; j++) s += String.fromCharCode(u8[off + j]); }
        if (s) { name = s.replace(/[^A-Za-z0-9-]/g, ''); break; }
      }
    }
    const fsType = os2 ? u16(os2 + 8) : 0;
    const font = {
      name, upm, numGlyphs, gid, adv, fsType,
      bbox: [i16(head + 36), i16(head + 38), i16(head + 40), i16(head + 42)].map((v) => Math.round(v * k)),
      ascent: Math.round((os2 ? i16(os2 + 68) : i16(T.hhea.off + 4)) * k),
      descent: Math.round((os2 ? i16(os2 + 70) : i16(T.hhea.off + 6)) * k),
      capHeight: os2 ? (u16(os2) >= 2 ? Math.round(i16(os2 + 88) * k) : 700) : 700,
    };

    // 使ったグリフ（合成グリフの部品も）だけを残したフォント
    font.subset = (gids) => {
      const keep = new Set([0]);
      const stack = Array.from(gids);
      while (stack.length) {
        const g = stack.pop();
        if (keep.has(g) ? g !== 0 : false) continue;
        if (g >= numGlyphs) continue;
        keep.add(g);
        const a = loca(g), b = loca(g + 1);
        if (b <= a) continue;
        let p = T.glyf.off + a;
        if (i16(p) >= 0) continue;
        p += 10;
        for (;;) {
          const fl = u16(p), cg = u16(p + 2);
          if (!keep.has(cg)) stack.push(cg);
          p += 4 + ((fl & 1) ? 4 : 2);
          if (fl & 8) p += 2; else if (fl & 0x40) p += 4; else if (fl & 0x80) p += 8;
          if (!(fl & 0x20)) break;
        }
      }
      // 新しい glyf と loca（長い形式）
      let size = 0;
      const parts = [];
      const newLoca = new Uint32Array(numGlyphs + 1);
      for (let g = 0; g < numGlyphs; g++) {
        newLoca[g] = size;
        if (!keep.has(g)) continue;
        const a = loca(g), b = loca(g + 1);
        if (b <= a) continue;
        const len = b - a, pad = (4 - (len % 4)) % 4;
        parts.push([T.glyf.off + a, len, pad]);
        size += len + pad;
      }
      newLoca[numGlyphs] = size;
      const glyf = new Uint8Array(size);
      let o = 0;
      parts.forEach(([src, len, pad]) => { glyf.set(u8.subarray(src, src + len), o); o += len + pad; });
      const lb = new Uint8Array((numGlyphs + 1) * 4), lv = new DataView(lb.buffer);
      for (let g = 0; g <= numGlyphs; g++) lv.setUint32(g * 4, newLoca[g]);
      // 残す表（並べ替え）
      const tables = {};
      ['head', 'hhea', 'hmtx', 'maxp', 'cvt ', 'fpgm', 'prep', 'OS/2', 'name', 'post', 'cmap'].forEach((t) => { if (T[t]) tables[t] = u8.slice(T[t].off, T[t].off + T[t].len); });
      tables.glyf = glyf;
      tables.loca = lb;
      new DataView(tables.head.buffer).setInt16(50, 1);   // indexToLocFormat = long
      new DataView(tables.head.buffer).setUint32(8, 0);   // checkSumAdjustment（あとで計算）
      if (tables.post ? tables.post.length >= 32 : false) {  // グリフ名は持たない（post format 3）
        const pv = new DataView(tables.post.buffer);
        pv.setUint32(0, 0x00030000);
        tables.post = tables.post.slice(0, 32);
      }
      const tags = Object.keys(tables).sort();
      const nt = tags.length;
      let es = 0; while ((1 << (es + 1)) <= nt) es++;
      const sr = (1 << es) * 16;
      let total = 12 + nt * 16;
      tags.forEach((t) => { total += (tables[t].length + 3) & ~3; });
      const out = new Uint8Array(total), ov = new DataView(out.buffer);
      ov.setUint32(0, 0x00010000); ov.setUint16(4, nt); ov.setUint16(6, sr); ov.setUint16(8, es); ov.setUint16(10, nt * 16 - sr);
      const sum = (bytes) => {
        let s = 0;
        const L = (bytes.length + 3) & ~3;
        for (let i = 0; i < L; i += 4) s = (s + (((bytes[i] || 0) << 24) | ((bytes[i + 1] || 0) << 16) | ((bytes[i + 2] || 0) << 8) | (bytes[i + 3] || 0))) >>> 0;
        return s;
      };
      let off = 12 + nt * 16, headOff = 0;
      tags.forEach((t, i) => {
        const b = tables[t], r = 12 + i * 16;
        for (let j = 0; j < 4; j++) out[r + j] = t.charCodeAt(j);
        ov.setUint32(r + 4, sum(b)); ov.setUint32(r + 8, off); ov.setUint32(r + 12, b.length);
        out.set(b, off);
        if (t === 'head') headOff = off;
        off += (b.length + 3) & ~3;
      });
      ov.setUint32(headOff + 8, (0xB1B0AFBA - sum(out)) >>> 0);
      return out;
    };
    return font;
  }

  let loading = null;
  window.CFPPdfFont = {
    ver: 1,
    parse,
    load(url) {
      if (!loading) {
        loading = fetch(url).then((r) => {
          if (!r.ok) throw new Error('フォント（' + url + '）を読み込めませんでした（HTTP ' + r.status + '）');
          return r.arrayBuffer();
        }).then(parse);
        loading.catch(() => { loading = null; });
      }
      return loading;
    },
  };
})();
