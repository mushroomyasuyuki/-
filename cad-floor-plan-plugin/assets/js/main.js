/**
 * CAD Floor Plan widget: wires the shortcode markup to CADParser and ThreeRoomRenderer.
 * Each widget instance is available as element.cfpWidget (e.g. widget.renderer.setFloorTexture(url)).
 */
(function () {
  'use strict';

  // the sample room: a rectangle of the carpet size typed in the estimate (1 m x 1 m at first)
  // wh: the wall height (the wallpaper strip frame's wall of a typed size, when that is used)
  const sampleRoom = (w, h, wh) => {
    w = Math.max(500, Math.round(w || 1000)); h = Math.max(500, Math.round(h || 1000));
    const room = { id: 'sample', name: 'サンプル（' + w + '×' + h + 'mm）', vertices: [[0, 0], [w, 0], [w, h], [0, h]] };
    if (wh > 0) room.walls = [0, 1, 2, 3].map((i) => ({ from: i, to: (i + 1) % 4, height: Math.round(wh) }));
    return { rooms: [room], metadata: { source: 'sample' } };
  };
  const ccSize = () => {
    const v = (id) => parseFloat((document.getElementById(id) || {}).value) || 1000;
    return [v('cc-width'), v('cc-height')];
  };

  const SVG_NS = 'http://www.w3.org/2000/svg';
  const fmtM = (mm) => (mm / 1000).toFixed(1);
  const fmtMm = (mm) => Math.round(mm).toLocaleString('ja-JP');
  const TILE = 500; // carpet tile (mm)

  // area of a polygon clipped to the rectangle x0..x1 / y0..y1 (Sutherland-Hodgman)
  function clipArea(poly, x0, y0, x1, y1) {
    let pts = poly;
    const edges = [
      (p) => p[0] >= x0, (p) => p[0] <= x1, (p) => p[1] >= y0, (p) => p[1] <= y1,
    ];
    const cross = [
      (a, b) => [x0, a[1] + (b[1] - a[1]) * (x0 - a[0]) / (b[0] - a[0])],
      (a, b) => [x1, a[1] + (b[1] - a[1]) * (x1 - a[0]) / (b[0] - a[0])],
      (a, b) => [a[0] + (b[0] - a[0]) * (y0 - a[1]) / (b[1] - a[1]), y0],
      (a, b) => [a[0] + (b[0] - a[0]) * (y1 - a[1]) / (b[1] - a[1]), y1],
    ];
    for (let k = 0; k < 4 && pts.length; k++) {
      const out = [];
      for (let i = 0; i < pts.length; i++) {
        const cur = pts[i], prev = pts[(i + pts.length - 1) % pts.length];
        const inC = edges[k](cur), inP = edges[k](prev);
        if (inC) {
          if (!inP) out.push(cross[k](prev, cur));
          out.push(cur);
        } else if (inP) out.push(cross[k](prev, cur));
      }
      pts = out;
    }
    let a = 0;
    for (let i = 0; i < pts.length; i++) {
      const p = pts[i], q = pts[(i + 1) % pts.length];
      a += p[0] * q[1] - q[0] * p[1];
    }
    return Math.abs(a) / 2;
  }
  const fmtArea = (mm2) => (mm2 / 1e6).toFixed(2);

  // A layered RGB PSD (8 bit, RLE) made 256 rows at a time: each layer is a draw function, so no
  // canvas as big as the picture is held (big canvases can come out empty when memory runs short).
  // layers: [{ name, draw(ctx) }] bottom to top; ctx is already moved for the band. Returns Blob parts.
  async function writeBandedPsd(w, h, layers, dpi, onProgress) {
    const parts = [];
    const be = (bytes, v) => { const u = new Uint8Array(bytes); for (let i = bytes - 1; i >= 0; i--) { u[i] = v % 256; v = Math.floor(v / 256); } return u; };
    const i16 = (v) => be(2, v < 0 ? v + 65536 : v);
    const i32 = (v) => be(4, v < 0 ? v + 4294967296 : v);
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
    const newChan = () => ({ counts: new Uint8Array(h * 2), chunks: [], size: 0 });
    const addRow = (ch, y, row) => { ch.counts.set(be(2, row.length), y * 2); ch.chunks.push(row); ch.size += row.length; };
    const chanLen = (c) => 2 + c.counts.length + c.size;
    const TH = 256;
    const band = document.createElement('canvas');
    band.width = w;
    band.height = TH;
    const bx = band.getContext('2d', { willReadFrequently: true });
    if (!bx) throw new Error('画像を用意できませんでした');
    const n = w * TH;
    const pl = [new Uint8Array(n), new Uint8Array(n), new Uint8Array(n), new Uint8Array(n)];
    // one band of one layer (or of the composite on white) -> rows of the channels
    const take = (chans, y0, bh, alpha) => {
      const d = bx.getImageData(0, 0, w, bh).data;
      for (let i = 0, j = 0; i < w * bh; i++, j += 4) { pl[0][i] = d[j + 3]; pl[1][i] = d[j]; pl[2][i] = d[j + 1]; pl[3][i] = d[j + 2]; }
      for (let yy = 0; yy < bh; yy++) chans.forEach((c, k) => addRow(c, y0 + yy, rleRow(pl[alpha ? k : k + 1], yy * w)));
    };
    const L = layers.map((l) => ({ name: l.name, chans: [newChan(), newChan(), newChan(), newChan()] }));
    const comp = [newChan(), newChan(), newChan()];
    const bands = Math.ceil(h / TH);
    let seen = false;
    for (let b = 0; b < bands; b++) {
      const y0 = b * TH, bh = Math.min(TH, h - y0);
      layers.forEach((l, k) => {
        bx.setTransform(1, 0, 0, 1, 0, 0);
        bx.clearRect(0, 0, w, TH);
        bx.save(); bx.translate(0, -y0); l.draw(bx); bx.restore();
        take(L[k].chans, y0, bh, true);
      });
      bx.setTransform(1, 0, 0, 1, 0, 0);
      bx.fillStyle = '#ffffff';
      bx.fillRect(0, 0, w, TH);
      layers.forEach((l) => { bx.save(); bx.translate(0, -y0); l.draw(bx); bx.restore(); });
      // a white band with something drawn on it reads back not all white (or the canvas was lost)
      if (!seen) { const d = bx.getImageData(0, 0, w, bh).data; for (let i = 0; i < d.length; i += 4) { if (d[i] !== 255 || d[i + 1] !== 255 || d[i + 2] !== 255) { seen = true; break; } } }
      take(comp, y0, bh, false);
      if (onProgress) onProgress((b + 1) / bands);
      await new Promise((r) => setTimeout(r, 0));
    }
    band.width = band.height = 1;
    if (!seen) throw new Error('図を描けませんでした（ブラウザのメモリ不足の可能性があります）');

    parts.push(ascii('8BPS'), i16(1), new Uint8Array(6), i16(3), i32(h), i32(w), i16(8), i16(3), i32(0));
    const fx = Math.round(dpi * 65536);
    const res = concat([ascii('8BIM'), i16(1005), i16(0), i32(16), i32(fx), i16(1), i16(1), i32(fx), i16(1), i16(1)]);
    parts.push(i32(res.length), res);
    const record = (l) => {
      // the old style name is ASCII only (Japanese as "?"); the real name is in 'luni' (Unicode)
      const name = l.name;
      const nameBytes = Uint8Array.from(name.split('').map((c) => (c.charCodeAt(0) > 126 ? 63 : c.charCodeAt(0)))).subarray(0, 255);
      const pad = (4 - ((1 + nameBytes.length) % 4)) % 4;
      const pascal = concat([Uint8Array.of(nameBytes.length), nameBytes, new Uint8Array(pad)]);
      const ub = new Uint8Array(name.length * 2);
      for (let i = 0; i < name.length; i++) { const c = name.charCodeAt(i); ub[i * 2] = c >> 8; ub[i * 2 + 1] = c % 256; }
      let luniData = concat([i32(name.length), ub]);
      if (luniData.length % 4) luniData = concat([luniData, new Uint8Array(4 - (luniData.length % 4))]);
      const luni = concat([ascii('8BIM'), ascii('luni'), i32(luniData.length), luniData]);
      const extra = concat([i32(0), i32(0), pascal, luni]);
      const head = [i32(0), i32(0), i32(h), i32(w), i16(4)];
      [-1, 0, 1, 2].forEach((id, k) => { head.push(i16(id)); head.push(i32(chanLen(l.chans[k]))); });
      head.push(ascii('8BIM'), ascii('norm'), Uint8Array.of(255, 0, 8, 0), i32(extra.length), extra);
      return concat(head);
    };
    const recs = L.map(record);
    const dataLen = L.reduce((t, l) => t + l.chans.reduce((u, c) => u + chanLen(c), 0), 0);
    let infoLen = 2 + recs.reduce((t, r) => t + r.length, 0) + dataLen;
    const odd = infoLen % 2;
    infoLen += odd;
    parts.push(i32(4 + infoLen + 4), i32(infoLen), i16(L.length));
    recs.forEach((r) => parts.push(r));
    L.forEach((l) => l.chans.forEach((c) => { parts.push(i16(1), c.counts); c.chunks.forEach((u) => parts.push(u)); }));
    if (odd) parts.push(new Uint8Array(1));
    parts.push(i32(0));
    parts.push(i16(1));
    comp.forEach((c) => parts.push(c.counts));
    comp.forEach((c) => c.chunks.forEach((u) => parts.push(u)));
    return parts;
  }

  // true when something has been drawn on the canvas (checked on a 16x16 downscale)
  function canvasHasContent(c) {
    try {
      const t = document.createElement('canvas');
      t.width = t.height = 16;
      const x = t.getContext('2d');
      x.drawImage(c, 0, 0, 16, 16);
      const d = x.getImageData(0, 0, 16, 16).data;
      for (let i = 3; i < d.length; i += 4) if (d[i] > 0) return true;
    } catch (e) { /* tainted or unreadable canvas: treat as empty */ }
    return false;
  }

  class CADFloorPlanWidget {
    constructor(el) {
      this.el = el;
      this.renderer = null;
      this.data = null;        // every room found in the drawing
      this.roomIndex = 0;      // the room shown in 3D
      this.fromFile = false;   // true when the data came from an uploaded drawing (not the sample)
      this.lastFile = null;
      this.raster = null;      // set while the drawing is read as a picture (PNG / JPEG / scanned PDF)
      this.backdrop = null;    // the drawing picture shown under the plan (raster, or a rendered PDF page)
      this.backdropFile = null;
      this.sourceRooms = [];   // rooms found automatically
      this.sourceMeta = null;
      this.manual = [];        // rooms traced by hand: [{ poly: [[x, y]...] (picture px), name }]
      this.hideAuto = false;
      this.roomKinds = [];     // per room: { manual: bool, mi: index in this.manual }
      this.tracer = null;
      this.layoutTracer = null; // carpet tile layout view (500 x 500 mm from the room's centre)
      this.gridOffsets = {};    // per room (index): shift of the tile layout in mm { x, y }
      this.imgOffset = { x: 0, y: 0 }; // shift of the drawing picture in the layout view (mm, not used by the UI now)
      this.design = { x: 0, y: 0, scale: 1 }; // converted carpet image on the layout: shift (mm) and size
      this.drawings = [];      // every uploaded drawing: { file, type: 'plan' | 'elev', elev }
      this.planFile = null;    // the floor plan shown in 3D (carpet size)
      this.planStates = new Map(); // per floor plan: hand-traced rooms, scale, selection...
      this.elevStates = new Map(); // per elevation sheet: traced areas, selected wall...
      this.elev = null;        // wall elevations read from a PDF sheet: { scale, walls, backdrop }
      this.elevIndex = -1;
      this.elevTracer = null;
      this._elevFile = null;
      this._rasterTimer = null;
      this.$ = (name) => el.querySelector('[data-cfp="' + name + '"]');
      this.height = parseInt(el.dataset.height, 10) || 600;
      this.$('canvas').style.height = this.height + 'px';
      // size reflection only makes sense on the page that has the estimate tool
      this.hasTool = !!document.getElementById('cc-width');
      if (!this.hasTool) this.$('apply-size').closest('label').hidden = true;
      else {
        // on the simulator page the floor / wall pictures come from the reduced-colour images of the tool,
        // so the manual upload fields are not needed
        this.$('design-src').hidden = false;
        this.$('floor-tex').closest('label').hidden = true;
        this.$('wall-tex').closest('label').hidden = true;
      }
      this._bind();
      document.addEventListener('cfp:designs-updated', () => this.syncDesigns(true));
      document.addEventListener('cfp:designs-updated', () => { this._wpKey = null; this._wpBandKey = null; this._wpRender(); });
      if (el.dataset.sample !== 'none') this.loadData(sampleRoom(...ccSize(), this._sampleWallH()), 'サンプル', { fromFile: false });
      this._bindCcSize();
    }

    // The carpet size typed in the estimate (仕様サイズ): without a drawing it shapes the sample room;
    // with a room chosen on a drawing, that room's size has priority and is written back into the fields.
    _bindCcSize() {
      const ids = ['cc-width', 'cc-height'];
      if (!ids.every((id) => document.getElementById(id))) return;
      let timer = null;
      const onSize = (e) => {
        if (!this.renderer) return;
        if (!this.fromFile) {
          if (this.data && this.data.metadata && this.data.metadata.source !== 'sample') return;
          clearTimeout(timer);
          timer = setTimeout(() => this.loadData(sampleRoom(...ccSize(), this._sampleWallH()), 'サンプル', { fromFile: false }), 300);
          return;
        }
        // typed by hand while a room of the drawing is chosen: the drawing's room wins
        const room = this.data && this.data.rooms[this.roomIndex];
        if (e.isTrusted && e.type === 'change' && room && this.$('apply-size').checked) {
          this.reflectSize(room);
          const n = document.getElementById('cc-cad-size-note');
          if (n) n.textContent += ' （入力したサイズより、CADで指定した部屋のサイズを優先しています。手で入力したサイズを使うには、CAD画面の「図面のサイズを見積もり…に反映する」のチェックを外してください。）';
        }
      };
      ids.forEach((id) => { const el = document.getElementById(id); el.addEventListener('input', onSize); el.addEventListener('change', onSize); });
    }

    _bind() {
      const drop = this.$('drop');
      const input = this.$('file');
      drop.addEventListener('click', () => input.click());
      input.addEventListener('change', () => { const fs = [...input.files]; input.value = ''; this.addFiles(fs); });
      ['dragenter', 'dragover'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
      ['dragleave', 'drop'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
      drop.addEventListener('drop', (e) => this.addFiles([...e.dataTransfer.files]));

      const reload = () => this.lastFile && this.loadFile(this.lastFile, { keepRoom: true, keepManual: true });
      const scaleEl = this.$('scale');
      let scaleTimer = null;
      let scaleApplied = scaleEl.value;
      const applyScale = () => {
        clearTimeout(scaleTimer);
        if (scaleEl.value === scaleApplied) return;
        scaleApplied = scaleEl.value;
        // an elevation sheet without walls found automatically takes its scale from this field too
        const ed = this.elev && !this.elev.walls.length ? this.drawings.find((x) => x.file === this._elevFile) : null;
        if (ed) { ed.elevScale = parseFloat(scaleEl.value) || 1; ed.rescale = true; this.showElevation(ed); }
        if (this.viewFocus !== 'elev' || !ed) reload(); // looking at the elevation only: leave the floor plan as it is
      };
      scaleEl.addEventListener('input', () => {
        // 全角数字を半角にし、数字以外は取り除く
        const v = scaleEl.value.replace(/[\uFF10-\uFF19]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0xFEE0)).replace(/[^0-9]/g, '');
        if (v !== scaleEl.value) scaleEl.value = v;
        clearTimeout(scaleTimer);
        scaleTimer = setTimeout(applyScale, 700);
      });
      scaleEl.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); applyScale(); } });
      scaleEl.addEventListener('change', applyScale);
      scaleEl.addEventListener('focus', () => scaleEl.select());
      this.$('unit').addEventListener('change', reload);
      this.$('apply-size').addEventListener('change', () => {
        const room = this.data && this.data.rooms[this.roomIndex];
        if (this.$('apply-size').checked && this.fromFile && room) this.reflectSize(room);
        else this.$('size-note').textContent = '';
      });
      const rerun = () => {
        clearTimeout(this._rasterTimer);
        this._rasterTimer = setTimeout(() => this.lastFile && this.loadFile(this.lastFile, { keepRoom: true, keepScale: true, keepManual: true }), 350);
      };
      this.$('threshold').addEventListener('input', () => { this._rasterLabels(); rerun(); });
      this.$('gap').addEventListener('input', () => { this._rasterLabels(); rerun(); });
      this.$('cal-btn').addEventListener('click', () => this.calibrate());
      this.$('cal-mm').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); this.calibrate(); } });
      this.$('trace-open').addEventListener('click', () => this.openTracer());
      // add a floor (room) of one's own next to the ones found automatically: the tracer opens with the rectangle tool
      this.$('room-add').addEventListener('click', async () => {
        await this.openTracer(true);
        if (this.tracer) {
          this.tracer.setTool('rect');
          ['pan', 'rect', 'poly', 'circle', 'ellipse', 'move', 'edit'].forEach((t) => { const b = this.$('tool-' + t); if (b) b.classList.toggle('is-on', t === 'rect'); });
        }
        this.status('図面の上で、追加する床（部屋）を四角・円・楕円（対角の2点をドラッグ）か多角形（角を順にクリック）で囲んでください。', 'success');
      });
      this.$('trace-close').addEventListener('click', () => { this.$('trace').hidden = true; });
      const tool = (name) => {
        if (!this.tracer) return;
        this.tracer.setTool(name);
        ['pan', 'rect', 'poly', 'circle', 'ellipse', 'move', 'edit'].forEach((t) => this.$('tool-' + t).classList.toggle('is-on', t === name));
      };
      ['pan', 'rect', 'poly', 'circle', 'ellipse', 'move', 'edit'].forEach((t) => this.$('tool-' + t).addEventListener('click', () => tool(t)));
      this.$('zoom-in').addEventListener('click', () => this.tracer && this.tracer.zoom(0.7));
      this.$('zoom-out').addEventListener('click', () => this.tracer && this.tracer.zoom(1.4));
      this.$('zoom-fit').addEventListener('click', () => this.tracer && this.tracer.fit());
      this.$('ortho').addEventListener('change', () => { if (this.tracer) this.tracer.ortho = this.$('ortho').checked; });
      this.$('hide-auto').addEventListener('change', () => {
        this.hideAuto = this.$('hide-auto').checked;
        this._afterRoomsChanged(0);
      });
      const etool = (name) => {
        if (!this.elevTracer) return;
        this.elevTracer.setTool(name);
        ['pan', 'rect', 'poly', 'circle', 'ellipse', 'move', 'edit'].forEach((t) => this.$('elev-' + t).classList.toggle('is-on', t === name));
      };
      ['pan', 'rect', 'poly', 'circle', 'ellipse', 'move', 'edit'].forEach((t) => this.$('elev-' + t).addEventListener('click', () => etool(t)));
      this.$('elev-fit').addEventListener('click', () => this.elevTracer && this.elevTracer.fit());
      this.$('elev-zin').addEventListener('click', () => this.elevTracer && this.elevTracer.zoom(0.7));
      this.$('elev-zout').addEventListener('click', () => this.elevTracer && this.elevTracer.zoom(1.4));
      this.$('elev-ortho').addEventListener('change', () => { if (this.elevTracer) this.elevTracer.ortho = this.$('elev-ortho').checked; });
      this.$('elev-hide-auto').addEventListener('change', () => this._renderElevList());
      this.$('elev-cloth-only').addEventListener('change', () => this._renderElevList());
      const setTotal = (fn) => { this._elevAll().forEach((w) => { w.inTotal = fn(w); }); this._renderElevList(); };
      this.$('total-all').addEventListener('click', () => setTotal(() => true));
      this.$('total-cloth').addEventListener('click', () => setTotal((w) => !!w.cloth || !!w.custom));
      this.$('total-none').addEventListener('click', () => setTotal(() => false));
      this._wpInit();
      // carpet tile layout
      const ltool = (name) => {
        if (!this.layoutTracer) return;
        this.layoutTracer.setTool(name);
        ['pan', 'grid', 'design'].forEach((t) => this.$('layout-' + t).classList.toggle('is-on', t === name));
      };
      ['pan', 'grid', 'design'].forEach((t) => this.$('layout-' + t).addEventListener('click', () => ltool(t)));
      // 画像の大きさ：見積もりツールの「画像の大きさ（拡大・縮小）」と同じ値（1つの設定）。ツールがあるときは
      // そちらで変換画像そのものを拡大・縮小し（PSD・見積もりの画像と同じ）、割付の上では等倍で重ねる
      this.$('layout-dscale').addEventListener('input', () => {
        const cc = document.getElementById('cc-img-scale');
        if (cc) {
          cc.value = this.$('layout-dscale').value;
          cc.dispatchEvent(new Event('input', { bubbles: true }));
          this.design.scale = 1;
        } else this.design.scale = (parseFloat(this.$('layout-dscale').value) || 100) / 100;
        this._renderLayout();
      });
      const ccScale = document.getElementById('cc-img-scale');
      if (ccScale) {
        this.$('layout-dscale').min = ccScale.min;
        this.$('layout-dscale').max = ccScale.max;
        ccScale.addEventListener('input', () => {
          this.$('layout-dscale').value = ccScale.value;
          this.$('layout-dscale-val').textContent = ccScale.value;
        });
      }
      this.$('layout-dopacity').addEventListener('input', () => this._renderLayout());
      this.$('layout-show-design').addEventListener('change', () => this._renderLayout());
      this.$('layout-clip-box').addEventListener('change', () => this._renderLayout());
      this.$('layout-psd').addEventListener('click', () => this._saveLayoutPsd());
      document.addEventListener('cfp:designs-updated', () => { this._designUrl = null; this._renderLayout(); });
      this.$('layout-fit').addEventListener('click', () => this.layoutTracer && this.layoutTracer.fit());
      this.$('layout-zin').addEventListener('click', () => this.layoutTracer && this.layoutTracer.zoom(0.7));
      this.$('layout-zout').addEventListener('click', () => this.layoutTracer && this.layoutTracer.zoom(1.4));
      this.$('layout-center').addEventListener('click', () => {
        delete this.gridOffsets[this.roomIndex];
        this.imgOffset = { x: 0, y: 0 };
        this.design = { x: 0, y: 0, scale: 1 };
        this.$('layout-dscale').value = 100;
        const cc = document.getElementById('cc-img-scale');
        if (cc ? cc.value !== '100' : false) { cc.value = 100; cc.dispatchEvent(new Event('input', { bubbles: true })); }
        this._layoutCommit();
      });
      this.$('layout-show-img').addEventListener('change', () => this._renderLayout());
      this.$('layout-show-grid').addEventListener('change', () => this._renderLayout());
      this.$('sample-btn').addEventListener('click', () => this.loadData(sampleRoom(...ccSize(), this._sampleWallH()), 'サンプル', { fromFile: false }));
      this.$('reset-btn').addEventListener('click', () => this.renderer && this.renderer.resetCamera());
      this.$('save-btn').addEventListener('click', () => this.saveJSON());
      this.$('floor-tex').addEventListener('change', (e) => this._texture(e, 'floor'));
      this.$('wall-tex').addEventListener('change', (e) => this._texture(e, 'wall'));
    }

    async loadFile(file, opts = {}) {
      this.lastFile = file;
      this.planFile = file;
      if (!opts.keepManual) {
        // a new drawing: forget hand-traced rooms and the picture of the previous one
        this.manual = [];
        this.hideAuto = false;
        this.$('hide-auto').checked = false;
        this.backdrop = null;
        this.backdropFile = null;
        this.$('trace').hidden = true;
        this.gridOffsets = {};
        this.imgOffset = { x: 0, y: 0 };
        this.design = { x: 0, y: 0, scale: 1 };
      }
      const isImage = /\.(png|jpe?g|webp)$/i.test(file.name);
      if (isImage) this.$('raster').hidden = false;
      else if (!opts.keepScale) { this.$('raster').hidden = true; this.raster = null; }
      if (isImage && !opts.keepScale) {
        // a new image: forget the previous calibration and start from the default detection settings
        this.raster = null;
        this.$('threshold').value = 0;
        this.$('gap').value = 4;
        this.$('cal-mm').value = '';
      }
      this._rasterLabels();
      this.status('読み込み中…', 'loading');
      try {
        const scale = parseFloat(this.$('scale').value) || 1;
        const unit = this.$('unit').value;
        const options = { scale, unit };
        if (!this.$('raster').hidden) {
          options.threshold = parseFloat(this.$('threshold').value) || 0;
          options.gap = parseFloat(this.$('gap').value);
          if (this.raster && this.raster.calibrated) options.mmPerPx = this.raster.mmPerPx;
        }
        const data = await CADParser.parseFloorPlan(file, options);
        this.loadData(data, file.name, { fromFile: true, keepRoom: !!opts.keepRoom, roomIndex: opts.roomIndex });
      } catch (err) {
        this.status('エラー: ' + err.message, 'error');
        console.error(err);
      }
    }

    loadData(data, label, opts = {}) {
      try {
        const meta = data.metadata || {};
        const normalized = { rooms: data.rooms.map((r, i) => CADParser.normalizeRoom(r, i)), metadata: meta };
        if (meta.source === 'sample') this.gridOffsets = {}; // a new sample room: the layout starts at its edges again
        // a drawing read as a picture may have no room yet: the rooms are then traced by hand
        if (!meta.raster || normalized.rooms.length) CADParser.validate(normalized);
        if (!this.renderer) this.renderer = new ThreeRoomRenderer(this.$('canvas'));
        this.raster = meta.raster || null;
        if (this.raster) this.$('raster').hidden = false; // also for a scanned PDF read as an image
        const prevIndex = this.roomIndex;
        const prevCount = this.data ? this.data.rooms.length : 0;
        this.sourceRooms = normalized.rooms;
        this.sourceMeta = meta;
        this.label = label;
        this.fromFile = !!opts.fromFile;
        if (!this.fromFile) {
          // the sample is not a drawing: no picture, no hand-traced rooms, no picture settings
          this.manual = [];
          this.hideAuto = false;
          this.$('hide-auto').checked = false;
          this.backdrop = null;
          this.$('trace').hidden = true;
          this.$('raster').hidden = true;
        }
        this._updateBackdrop();
        // a cluttered drawing (furniture, equipment...) gives dozens of "rooms": hide them and let the user trace
        let hiddenN = 0;
        if (this.fromFile && !opts.keepManual && (this.raster || /\.pdf$/i.test(label))) {
          const n = this.raster ? this.raster.polys.length : normalized.rooms.length;
          if (n > 20) {
            hiddenN = n;
            this.hideAuto = true;
            this.$('hide-auto').checked = true;
          }
        }
        this.rebuildRooms();
        const rooms = this.data.rooms;

        this.$('trace-open').hidden = !(this.fromFile && (this.raster || /\.pdf$/i.test(label)));
        this.$('room-add').hidden = this.$('trace-open').hidden;

        if (!rooms.length) {
          this._showEmpty();
        } else {
          // with several rooms, start from the largest one; the user picks another on the plan.
          // A reload of the same drawing (scale / unit changed) keeps the room already chosen.
          let best = 0;
          rooms.forEach((r, i) => {
            if (CADParser.calculateArea(r.vertices) > CADParser.calculateArea(rooms[best].vertices)) best = i;
          });
          if (opts.roomIndex != null && opts.roomIndex < rooms.length) best = opts.roomIndex;
          else if (opts.keepRoom && prevCount === rooms.length && prevIndex < rooms.length) best = prevIndex;
          this.renderPicker();
          this.selectRoom(best, this.fromFile);
        }
        this._rasterNote();
        this._refreshTracer();
        if (this.fromFile && !this.$('trace').hidden) this.openTracer(false);

        const n = rooms.length;
        if (hiddenN && !n) {
          this.status('自動で ' + hiddenN + ' 個の図形が見つかりましたが、家具や設備の囲みが多く含まれるため隠しています（「自動で出た部屋を隠す」を外すと表示します）。図面の上で部屋を指定してください。', 'success');
          this.openTracer();
        } else if (meta.pdfFallback) {
          this.status(meta.pdfFallback.reason + (n ? '' : ' 下の「図面の上で部屋を指定」で、部屋を指定してください。'), n ? 'success' : 'error');
          if (!n) this.openTracer();
        } else if (this.raster && !this.raster.calibrated) {
          this.status('画像から部屋を ' + n + ' 室読み取りました。画像には縮尺が無いため、実際の大きさを入力してください。', 'success');
        } else this.status(n > 1
          ? '部屋が ' + n + ' 室見つかりました。3Dで見たい部屋を、平面図または一覧から選んでください。'
          : '読み込みました（部屋 1）', 'success');
      } catch (err) {
        this.status('エラー: ' + err.message, 'error');
        console.error(err);
      }
    }

    // the picture under the plan: the raster itself, or a rendered PDF page kept for hand tracing
    _updateBackdrop() {
      if (this.raster) { this.backdrop = this.raster; return; }
      if (this.backdrop && this.backdrop.kind === 'pdf' && this.backdropFile === this.lastFile) {
        const n = parseFloat(this.$('scale').value) || 1;
        this.backdrop.mmPerPx = (25.4 / 72) * n / this.backdrop.k;
        this.backdrop.calibrated = n > 1;
      } else {
        this.backdrop = null;
      }
    }

    // automatic rooms (unless hidden) + rooms traced by hand, all in mm
    rebuildRooms() {
      const rooms = [];
      const kinds = [];
      if (!this.hideAuto) {
        const auto = this.raster ? CADParser.roomsFromRaster(this.raster, this.raster.mmPerPx) : this.sourceRooms;
        auto.forEach((r) => { rooms.push(r); kinds.push({ manual: false }); });
      }
      if (this.backdrop) {
        const s = this.backdrop.mmPerPx, H = this.backdrop.heightPx;
        this.manual.forEach((m, mi) => {
          rooms.push(CADParser.normalizeRoom({
            id: 'manual_' + (mi + 1), name: m.name, vertices: m.poly.map(([x, y]) => [x * s, (H - y) * s]),
          }, rooms.length));
          kinds.push({ manual: true, mi });
        });
      }
      this.roomKinds = kinds;
      this.data = { rooms, metadata: this.sourceMeta };
    }

    // after rooms were added / removed / hidden: redraw everything and keep a sensible selection
    _afterRoomsChanged(select) {
      this.rebuildRooms();
      const n = this.data.rooms.length;
      if (!n) { this._showEmpty(); this._refreshTracer(); return; }
      this.roomIndex = Math.min(Math.max(select == null ? this.roomIndex : select, 0), n - 1);
      this.renderPicker();
      this.selectRoom(this.roomIndex, this.fromFile);
      this._refreshTracer();
    }

    _showEmpty() {
      if (this.renderer) this.renderer.clear();
      this.$('rooms').hidden = true;
      this.$('layout').hidden = true;
      ['room-name', 'room-size', 'floor-area', 'wall-area'].forEach((k) => { this.$(k).textContent = '-'; });
      this.$('room-count').textContent = '0';
      this.$('filename').textContent = this.label;
      this.$('size-note').textContent = '';
    }

    selectRoom(i, reflect) {
      const room = this.data.rooms[i];
      this.roomIndex = i;
      if (!this.fromFile) this._ccSizeNote(null);
      this.renderer.loadFloorPlan({ rooms: [room], metadata: this.data.metadata });

      const b = CADParser.bounds(room.vertices);
      const a = this.renderer.getAreas();
      this.$('filename').textContent = this.label;
      this.$('room-name').textContent = room.name || ('部屋 ' + (i + 1));
      const kind = this.roomKinds[i] || {};
      const prov = kind.manual ? !!this.backdrop && !this.backdrop.calibrated : !!this.raster && !this.raster.calibrated;
      const tag = prov ? '（仮）' : '';
      this.$('room-size').textContent = fmtMm(b.maxX - b.minX) + ' × ' + fmtMm(b.maxY - b.minY) + ' mm' + tag;
      this.$('floor-area').textContent = a.floorArea + ' ㎡' + tag;
      this.$('wall-area').textContent = a.wallArea + ' ㎡' + tag;
      this.$('room-count').textContent = this.data.rooms.length;
      this.$('unit-info').textContent = this._unitText();
      this._markSelected();
      this.syncDesigns(false);

      // an uncalibrated image has no real size yet: do not push it into the estimate
      if (reflect && !prov && this.$('apply-size').checked) this.reflectSize(room);
      else this.$('size-note').textContent = prov && kind.manual && this.backdrop.kind === 'pdf'
        ? '指定した部屋の大きさは仮です。「PDFの縮尺（1:N の N）」に図面の縮尺（例: 100）を入力してください。' : '';
      this._renderLayout();
      try { this._wpRender(); } catch (err) { console.error(err); } // the joined walls are checked against this room (never in the way of the plan)
    }

    _unitText() {
      const m = this.data.metadata || {};
      if (m.unit) {
        const how = { header: '図面の設定', auto: '自動判定', manual: '手動指定', default: '既定' }[m.unit.source] || '';
        return m.unit.name + (how ? '（' + how + '）' : '');
      }
      if (m.source === 'pdf') return 'PDF 縮尺 1:' + (m.pdfScale || 1);
      if (m.source === 'image') return m.raster.calibrated ? '画像（1px = ' + m.raster.mmPerPx.toFixed(2) + ' mm）' : '画像（縮尺未設定）';
      return '-';
    }

    // ----------------------------------------------------------- room picker
    renderPicker() {
      const wrap = this.$('rooms');
      const rooms = this.data.rooms;
      const bd = this.backdrop;
      wrap.hidden = rooms.length < 2 && !bd;
      const svg = this.$('plan');
      const list = this.$('room-list');
      svg.textContent = '';
      list.textContent = '';
      if (rooms.length < 2 && !bd) return;

      const all = rooms.flatMap((r) => r.vertices);
      const b = bd
        ? { minX: 0, minY: 0, maxX: bd.widthPx * bd.mmPerPx, maxY: bd.heightPx * bd.mmPerPx }
        : CADParser.bounds(all);
      const w = b.maxX - b.minX, h = b.maxY - b.minY;
      const pad = Math.max(w, h) * 0.04;
      // CAD Y is up, SVG Y is down
      svg.setAttribute('viewBox', [b.minX - pad, -b.maxY - pad, w + pad * 2, h + pad * 2].join(' '));
      const fs = Math.max(w, h) / (rooms.length > 12 ? 30 : 16);

      if (bd) {
        // the uploaded drawing, so that the detected rooms can be checked against it
        const img = document.createElementNS(SVG_NS, 'image');
        img.setAttribute('href', bd.dataUrl);
        img.setAttribute('x', 0);
        img.setAttribute('y', -b.maxY);
        img.setAttribute('width', b.maxX);
        img.setAttribute('height', b.maxY);
        img.setAttribute('preserveAspectRatio', 'none');
        img.setAttribute('opacity', '0.55');
        svg.appendChild(img);
      }

      // big rooms first so that small rooms inside them stay clickable
      const order = rooms.map((r, i) => i).sort((p, q) => CADParser.calculateArea(rooms[q].vertices) - CADParser.calculateArea(rooms[p].vertices));
      order.forEach((i) => {
        const r = rooms[i];
        const g = document.createElementNS(SVG_NS, 'g');
        g.setAttribute('class', 'cfp-plan-room');
        g.setAttribute('data-i', i);
        g.setAttribute('tabindex', '0');
        g.setAttribute('role', 'button');
        g.setAttribute('aria-label', (i + 1) + ' ' + r.name);
        const poly = document.createElementNS(SVG_NS, 'polygon');
        poly.setAttribute('points', r.vertices.map(([x, y]) => x + ',' + -y).join(' '));
        const title = document.createElementNS(SVG_NS, 'title');
        const rb = CADParser.bounds(r.vertices);
        title.textContent = (i + 1) + '. ' + r.name + '（' + fmtM(rb.maxX - rb.minX) + '×' + fmtM(rb.maxY - rb.minY) + 'm）';
        const text = document.createElementNS(SVG_NS, 'text');
        text.setAttribute('x', (rb.minX + rb.maxX) / 2);
        text.setAttribute('y', -(rb.minY + rb.maxY) / 2);
        // keep the number inside small rooms
        text.setAttribute('font-size', Math.min(fs, Math.min(rb.maxX - rb.minX, rb.maxY - rb.minY) / 2));
        text.setAttribute('text-anchor', 'middle');
        text.setAttribute('dominant-baseline', 'central');
        text.textContent = i + 1;
        g.append(poly, title, text);
        g.addEventListener('click', () => this._pick(i));
        g.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this._pick(i); } });
        svg.appendChild(g);
      });

      rooms.forEach((r, i) => {
        const rb = CADParser.bounds(r.vertices);
        const item = document.createElement('div');
        item.className = 'cfp-room-item';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cfp-room-btn';
        btn.setAttribute('data-i', i);
        const num = document.createElement('span');
        num.className = 'cfp-room-num';
        num.textContent = i + 1;
        const name = document.createElement('span');
        name.className = 'cfp-room-name';
        name.textContent = r.name;
        const meta = document.createElement('span');
        meta.className = 'cfp-room-meta';
        meta.textContent = fmtM(rb.maxX - rb.minX) + '×' + fmtM(rb.maxY - rb.minY) + 'm / ' + fmtArea(CADParser.calculateArea(r.vertices)) + '㎡';
        btn.append(num, name, meta);
        btn.addEventListener('click', () => this._pick(i));
        item.appendChild(btn);
        const kind = this.roomKinds[i];
        if (kind && kind.manual) {
          const del = document.createElement('button');
          del.type = 'button';
          del.className = 'cfp-room-del';
          del.textContent = '×';
          del.title = 'この部屋を削除';
          del.setAttribute('aria-label', r.name + ' を削除');
          del.addEventListener('click', () => this._removeManual(kind.mi));
          item.appendChild(del);
        }
        list.appendChild(item);
      });
    }

    _pick(i) {
      this.selectRoom(i, this.fromFile);
      this.status('「' + (this.data.rooms[i].name) + '」を表示しています。', 'success');
    }

    _markSelected() {
      this.el.querySelectorAll('.cfp-plan-room, .cfp-room-btn').forEach((n) => {
        const on = Number(n.getAttribute('data-i')) === this.roomIndex;
        n.classList.toggle('is-selected', on);
        if (n.tagName === 'BUTTON') n.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    }


    // ------------------------------------------------ wall elevations (展開図) -> wall paper size
    _hideElev() {
      this.elev = null;
      this.elevIndex = -1;
      this._elevFile = null;
      this.$('elev').hidden = true;
      window.cfpWallTotal = null;
    }

    // ------------------------------------------------------- several drawings
    // Floor plans (rooms -> carpet) and elevation sheets (walls -> wall paper) can be uploaded together;
    // one of each is active at a time and the others are kept to switch to.
    async addFiles(files) {
      files = files.filter((f) => /\.(dxf|pdf|json|png|jpe?g|webp)$/i.test(f.name));
      if (!files.length) return;
      this.status('読み込み中…', 'loading');
      let plan = null, elev = null;
      for (const f of files) {
        // the same drawing again: read it afresh (e.g. after changing the scale)
        const old = this.drawings.find((x) => x.file.name === f.name && x.file.size === f.size && x.file.lastModified === f.lastModified);
        if (old) {
          this.drawings = this.drawings.filter((x) => x !== old);
          this.planStates.delete(old.file);
          this.elevStates.delete(old.file);
        }
        let d = null;
        {
          d = { file: f, type: 'plan', elev: null, reason: '', canSwitch: /\.pdf$/i.test(f.name), warn: false };
          try {
            const c = await CADParser.classifyDrawing(f, {});
            Object.assign(d, { type: c.type, elev: c.elev || null, reason: c.reason, canSwitch: c.canSwitch, warn: !!c.warn });
          } catch (err) {
            console.warn('drawing classification failed', err);
            d.reason = '判別できなかったため、平面図として扱います';
          }
          this.drawings.push(d);
        }
        if (d.type === 'elev') elev = elev || d; else plan = plan || d;
      }
      if (elev) await this.showElevation(elev);
      if (plan) await this.showPlan(plan);
      this._renderDrawings();
      // what each new drawing was taken for, and why
      const judged = files.map((f) => this.drawings.find((x) => x.file === f)).filter(Boolean);
      if (plan && elev) {
        this.status('平面図「' + plan.file.name + '」と展開図「' + elev.file.name + '」を読み込みました。床の部屋はカーペット、壁は壁紙のサイズに反映します。', 'success');
      } else if (judged.some((d) => d.warn)) {
        this.status(judged.find((d) => d.warn).reason + '。', 'error');
      }
    }

    _savePlanState() {
      if (!this.planFile || !this.fromFile) return;
      this.planStates.set(this.planFile, {
        manual: this.manual, hideAuto: this.hideAuto, roomIndex: this.roomIndex,
        scale: this.$('scale').value, backdrop: this.backdrop, backdropFile: this.backdropFile,
        raster: this.raster ? { calibrated: this.raster.calibrated, mmPerPx: this.raster.mmPerPx } : null,
        rasterShown: !this.$('raster').hidden,
        threshold: this.$('threshold').value, gap: this.$('gap').value, calMm: this.$('cal-mm').value,
        gridOffsets: this.gridOffsets, imgOffset: this.imgOffset, design: this.design,
      });
    }

    async showPlan(d) {
      if (this.planFile === d.file && this.fromFile) { this._renderDrawings(); return; }
      this._savePlanState();
      const st = this.planStates.get(d.file);
      if (st) {
        this.manual = st.manual;
        this.hideAuto = st.hideAuto;
        this.$('hide-auto').checked = st.hideAuto;
        this.backdrop = st.backdrop;
        this.backdropFile = st.backdropFile;
        this.$('scale').value = st.scale;
        this.$('threshold').value = st.threshold;
        this.$('gap').value = st.gap;
        this.$('cal-mm').value = st.calMm;
        this.raster = st.raster;
        this.$('raster').hidden = !st.rasterShown;
        this.gridOffsets = st.gridOffsets || {};
        this.imgOffset = st.imgOffset || { x: 0, y: 0 };
        this.design = st.design || { x: 0, y: 0, scale: 1 };
        const cc = document.getElementById('cc-img-scale');
        if (cc ? this.design.scale !== 1 : false) {
          // saved before the two sliders were one: the size moves to the estimate tool's slider
          cc.value = Math.round(this.design.scale * 100);
          cc.dispatchEvent(new Event('input', { bubbles: true }));
          this.design.scale = 1;
        }
        this.$('layout-dscale').value = Math.round(this._imgScale() * 100);
        this.$('trace').hidden = true;
        this._tracerImage = null;
        await this.loadFile(d.file, { keepRoom: true, keepScale: true, keepManual: true, roomIndex: st.roomIndex });
      } else {
        this._tracerImage = null;
        await this.loadFile(d.file);
      }
      this._renderDrawings();
    }

    async showElevation(d) {
      if (this.elev && this._elevFile) {
        this.elevStates.set(this._elevFile, { custom: this.elev.custom, elevIndex: this.elevIndex, clothOnly: this.$('elev-cloth-only').checked, backdrop: this.elev.backdrop });
      }
      let res = d.elev;
      try {
        if (!res) { res = await CADParser.analyzeElevations(d.file, {}); d.elev = res; }
        const st = this.elevStates.get(d.file);
        // no wall found automatically: the scale comes from the "PDFの縮尺" field
        // (the scale typed for this sheet is kept with it: the field changes with the floor plan shown)
        const drawnWith = st ? (st.custom || []).map((c) => c.scaleN || 0).find((v) => v > 1) : 0;
        const typed = d.elevScale > 1 ? d.elevScale : (drawnWith > 1 ? drawnWith : (parseFloat(this.$('scale').value) || 1));
        const scaleN = res.walls.length ? res.scale : (typed > 1 ? typed : (res.scale || 1));
        if (!res.walls.length) d.elevScale = scaleN;
        let bd = st && st.backdrop;
        if (!bd || bd.scaleN !== scaleN) {
          this.status('展開図を表示しています…', 'loading');
          bd = await CADParser.renderPdfBackdrop(d.file, { scale: scaleN });
          bd.scaleN = scaleN;
        }
        this._elevFile = d.file;
        this.elev = { scale: scaleN, walls: res.walls, backdrop: bd, pageW: res.pageW, pageH: res.pageH, custom: st ? st.custom : [] };
        // ranges drawn by hand: their size follows the scale only when the scale was changed on purpose
        // (「PDFの縮尺」を変えたとき)。ほかのとき（図面の切り替え・再開など）は、指定したときの大きさのまま
        const rescale = !!d.rescale;
        d.rescale = false;
        this.elev.custom.forEach((c) => {
          if (!c.poly || !rescale) return;
          c.scaleN = scaleN;
          const xs = c.poly.map((q) => q[0]), ys = c.poly.map((q) => q[1]);
          c.width = Math.round((Math.max(...xs) - Math.min(...xs)) * bd.mmPerPx);
          c.height = Math.round((Math.max(...ys) - Math.min(...ys)) * bd.mmPerPx);
        });
        this.elevIndex = st ? st.elevIndex : -1;
        this.$('elev-cloth-only').checked = st ? st.clothOnly : false;
        this.$('elev-note').textContent = '';
        this.$('elev').hidden = false;
        this._initElevTracer();
        this._renderElevList();
        const nCloth = res.walls.filter((w) => w.cloth).length;
        if (!res.walls.length) {
          this.status('展開図「' + d.file.name + '」から壁を自動で読み取れませんでした。' + (scaleN > 1
            ? '縮尺 1:' + scaleN + ' で表示しています。「四角で壁紙の範囲を指定」で壁を囲んでください。'
            : '「PDFの縮尺（1:N の N）」に図面の縮尺を入れてから、上の図面名をもう一度クリックし、「四角で壁紙の範囲を指定」で壁を囲んでください。'), 'error');
          this.$('elev-note').textContent = scaleN > 1 ? '' : '縮尺が未設定のため、指定した範囲の大きさは正しくありません。';
        } else this.status('展開図「' + d.file.name + '」を読み取りました（縮尺 1:' + res.scale + '、壁 ' + res.walls.length + ' 面'
          + (nCloth ? '、うちクロス貼り ' + nCloth + ' 面' : '') + '）。壁を選ぶと、壁紙のサイズに反映します。', 'success');
        if (this.elevIndex >= 0) this.selectWall(this.elevIndex);
      } catch (err) {
        this.status('エラー: ' + err.message, 'error');
        console.error(err);
      }
      this._renderDrawings();
    }

    // the user says the drawing is the other kind (floor plan <-> elevation sheet)
    async switchDrawing(d) {
      if (!d.canSwitch) return;
      const wasActive = d.type === 'elev' ? this._elevFile === d.file : this.planFile === d.file;
      // a floor plan moved to the elevations keeps the scale it was read with (the field then shows another plan's)
      if (d.type === 'plan' && this.planFile === d.file) d.elevScale = parseFloat(this.$('scale').value) || 1;
      if (wasActive) {
        // take it out of its current slot first, as when it is removed
        const keep = this.drawings;
        this.drawings = keep.filter((x) => x !== d);
        await this.removeDrawing(d, true);
        this.drawings = keep;
      }
      d.type = d.type === 'elev' ? 'plan' : 'elev';
      d.reason = '手動で' + (d.type === 'elev' ? '展開図（壁）' : '平面図（床）') + 'に切り替え';
      if (d.type === 'elev') await this.showElevation(d); else await this.showPlan(d);
      this._renderDrawings();
    }

    // ------------------------------------------------- save / resume (resume.js)
    // Everything needed to continue later, except the drawing files themselves (returned separately).
    exportState() {
      this._savePlanState();
      if (this.elev && this._elevFile) {
        this.elevStates.set(this._elevFile, { custom: this.elev.custom, elevIndex: this.elevIndex, clothOnly: this.$('elev-cloth-only').checked, backdrop: this.elev.backdrop });
      }
      const drawings = this.drawings.map((d, i) => {
        const key = 'cad' + i;
        const entry = { key, name: d.file.name, type: d.type, reason: d.reason };
        if (d.type === 'elev') {
          const st = this.elevStates.get(d.file) || {};
          entry.elev = {
            elevIndex: st.elevIndex == null ? -1 : st.elevIndex,
            clothOnly: !!st.clothOnly,
            custom: (st.custom || []).map((c) => ({ id: c.id, name: c.name, width: c.width, height: c.height, poly: c.poly, inTotal: !!c.inTotal, scaleN: c.scaleN })),
            scale: d.elevScale || null,
            inTotal: d.elev ? d.elev.walls.map((w, wi) => (w.inTotal ? wi : -1)).filter((x) => x >= 0) : [],
          };
        } else {
          const st = this.planStates.get(d.file);
          if (st) {
            entry.plan = {
              manual: st.manual, hideAuto: st.hideAuto, roomIndex: st.roomIndex, scale: st.scale,
              raster: st.raster, rasterShown: st.rasterShown, threshold: st.threshold, gap: st.gap, calMm: st.calMm,
              gridOffsets: st.gridOffsets || {}, imgOffset: st.imgOffset || { x: 0, y: 0 }, design: st.design || { x: 0, y: 0, scale: 1 },
            };
          }
        }
        return entry;
      });
      return {
        drawings,
        activePlan: this.fromFile && this.planFile ? this.planFile.name : null,
        activeElev: this._elevFile ? this._elevFile.name : null,
        applySize: this.$('apply-size').checked,
      };
    }

    files() {
      return this.drawings.map((d, i) => ({ key: 'cad' + i, file: d.file }));
    }

    // filesByKey: { cad0: File, ... }
    async importState(st, filesByKey) {
      if (!st || !Array.isArray(st.drawings)) return;
      this.$('apply-size').checked = st.applySize !== false;
      this.drawings = [];
      this.planStates = new Map();
      this.elevStates = new Map();
      let activePlan = null, activeElev = null;
      for (const e of st.drawings) {
        const file = filesByKey[e.key];
        if (!file) continue;
        const d = { file, type: e.type === 'elev' ? 'elev' : 'plan', elev: null, reason: e.reason || '保存した作業から再開', canSwitch: /\.pdf$/i.test(file.name), warn: false };
        if (d.type === 'elev') {
          try { d.elev = await CADParser.analyzeElevations(file, {}); } catch (err) { console.warn(err); }
          if (e.elev && e.elev.scale > 1) d.elevScale = e.elev.scale;
          if (d.elev && e.elev) {
            (e.elev.inTotal || []).forEach((wi) => { if (d.elev.walls[wi]) d.elev.walls[wi].inTotal = true; });
            this.elevStates.set(file, {
              custom: (e.elev.custom || []).map((c) => Object.assign({ custom: true, cloth: null }, c)),
              elevIndex: e.elev.elevIndex, clothOnly: e.elev.clothOnly, backdrop: null,
            });
          }
          if (e.name === st.activeElev) activeElev = d;
        } else {
          if (e.plan) {
            let backdrop = null;
            // hand-traced rooms of a vector PDF are drawn on its rendered page
            if (e.plan.manual && e.plan.manual.length && /\.pdf$/i.test(file.name) && !e.plan.raster) {
              try {
                backdrop = await CADParser.renderPdfBackdrop(file, { scale: parseFloat(e.plan.scale) || 1 });
              } catch (err) { console.warn(err); }
            }
            this.planStates.set(file, Object.assign({}, e.plan, { backdrop, backdropFile: backdrop ? file : null }));
          }
          if (e.name === st.activePlan) activePlan = d;
        }
        this.drawings.push(d);
      }
      this.planFile = null;
      this.fromFile = false;
      this._elevFile = null;
      this.elev = null;
      if (activeElev) await this.showElevation(activeElev);
      if (activePlan) await this.showPlan(activePlan);
      this._renderDrawings();
      if (this.elev) this._updateWallTotal();
    }

    async removeDrawing(d, quiet) {
      this.drawings = this.drawings.filter((x) => x !== d);
      if (d.type === 'elev') {
        this.elevStates.delete(d.file);
        if (this._elevFile === d.file) {
          this.elev = null;
          this._elevFile = null;
          const next = this.drawings.find((x) => x.type === 'elev');
          if (next) await this.showElevation(next); else this._hideElev();
        }
      } else {
        this.planStates.delete(d.file);
        if (this.planFile === d.file) {
          this.planFile = null;
          this.fromFile = false; // nothing to save for the removed drawing
          const next = this.drawings.find((x) => x.type === 'plan');
          if (next) await this.showPlan(next);
          else {
            this.lastFile = null;
            this.manual = [];
            this.backdrop = null;
            this.raster = null;
            this.data = { rooms: [], metadata: {} };
            this.label = '未選択';
            ['trace', 'raster', 'trace-open', 'room-add'].forEach((k) => { this.$(k).hidden = true; });
            this._showEmpty();
            this.$('size-note').textContent = '';
          }
        }
      }
      this._renderDrawings();
      if (this.elev) this._updateWallTotal();
      if (!quiet) this.status('「' + d.file.name + '」を外しました。', 'success');
    }

    _renderDrawings() {
      const box = this.$('drawings');
      box.textContent = '';
      box.hidden = !this.drawings.length;
      if (!this.drawings.length) { this.$('dw-note').textContent = ''; return; }
      // what each drawing was taken for, and why
      this.$('dw-note').textContent = this.drawings.map((d) => '「' + d.file.name + '」→ ' + (d.type === 'elev' ? '展開図（壁）' : '平面図（床）') + '：' + d.reason).join('\n')
        + (this.drawings.some((d) => d.canSwitch) ? '\n違っていれば、図面名の横の「↑↓」で入れ替えられます。' : '')
        + '\n図面名を押すと、その図面（平面図か展開図）だけを表示します（もう一度押すと両方表示）。';
      [['plan', '平面図（床 → カーペット）', this.planFile], ['elev', '展開図（壁 → 壁紙）', this._elevFile]].forEach(([type, title, active]) => {
        const row = document.createElement('div');
        row.className = 'cfp-dw-row';
        const lb = document.createElement('span');
        lb.className = 'cfp-dw-label is-' + type;
        lb.textContent = title;
        row.appendChild(lb);
        const items = this.drawings.filter((x) => x.type === type);
        if (!items.length) {
          const none = document.createElement('span');
          none.className = 'cfp-dw-none';
          none.textContent = 'なし';
          row.appendChild(none);
        }
        items.forEach((d) => {
          const chip = document.createElement('span');
          chip.className = 'cfp-dw-chip' + (d.file === active && (type === 'elev' || this.fromFile) ? ' is-active' : '') + (d.file === active && this.viewFocus === type ? ' is-focus' : '');
          const b = document.createElement('button');
          b.type = 'button';
          b.textContent = d.file.name;
          b.title = d.file.name + (type === 'elev' ? '（展開図）' : '（平面図）') + (d.reason ? '\n判定: ' + d.reason : '');
          b.addEventListener('click', async () => {
            const wasShown = d.file === active && this.viewFocus === type;
            if (type === 'elev') await this.showElevation(d); else await this.showPlan(d);
            // show only this kind of drawing (the other one's panels are folded away); pressed again: both
            this._setFocus(wasShown ? null : type);
          });
          const x = document.createElement('button');
          x.type = 'button';
          x.className = 'cfp-dw-x';
          x.textContent = '×';
          x.setAttribute('aria-label', d.file.name + ' を外す');
          x.addEventListener('click', () => this.removeDrawing(d));
          if (d.canSwitch) {
            const sw = document.createElement('button');
            sw.type = 'button';
            sw.className = 'cfp-dw-x cfp-dw-sw';
            sw.textContent = '↑↓';
            sw.title = type === 'elev' ? '平面図（床）として読み直す' : '展開図（壁）として読み直す';
            sw.setAttribute('aria-label', d.file.name + ' を' + sw.title);
            sw.addEventListener('click', () => this.switchDrawing(d));
            chip.append(b, sw, x);
          } else chip.append(b, x);
          row.appendChild(chip);
        });
        box.appendChild(row);
      });
      if (this.viewFocus) {
        const both = document.createElement('button');
        both.type = 'button';
        both.className = 'cfp-btn cfp-btn-sm cfp-dw-both';
        both.textContent = (this.viewFocus === 'elev' ? '展開図だけ表示中' : '平面図だけ表示中') + ' → 両方表示に戻す';
        both.addEventListener('click', () => this._setFocus(null));
        box.appendChild(both);
      }
    }

    // which drawing's panels are shown: 'plan', 'elev' or null (both)
    _setFocus(type) {
      this.viewFocus = type;
      this.el.classList.toggle('cfp-focus-plan', type === 'plan');
      this.el.classList.toggle('cfp-focus-elev', type === 'elev');
      this._renderDrawings();
      if (!type) return;
      const target = type === 'elev' ? this.$('elev') : (!this.$('trace').hidden ? this.$('trace') : this.$('rooms'));
      if (target && !target.hidden) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ------------------------------------------------ wall paper strip frames (巾の枠), whole wall without cutting
    _wpInit() {
      if (!this.$('wp')) return;
      const link = (rng, num) => {
        const R = this.$(rng), N = this.$(num);
        const on = (src) => () => {
          if (src === R) N.value = R.value; else if (N.value !== '') R.value = N.value;
          this._wpChanged();
        };
        R.addEventListener('input', on(R));
        N.addEventListener('input', on(N));
        N.addEventListener('change', () => { N.value = R.value = this._wpClamp(N.id || num, N.value); this._wpChanged(); });
      };
      link('wp-roll-r', 'wp-roll');
      // 巾は 910mm / 900mm から選ぶ。合わせ代は 910mm なら 7.5mm、900mm なら 10mm に自動で合わせる（そのあと手で変えることもできる）
      if (this.$('wp-roll-sel')) this.$('wp-roll-sel').addEventListener('change', () => {
        const roll = this.$('wp-roll-sel').value, ov = roll === '900' ? 10 : 7.5;
        this.$('wp-roll-r').value = this.$('wp-roll').value = roll;
        this.$('wp-ov-r').value = this.$('wp-ov').value = ov;
        this._wpChanged();
      });
      link('wp-ov-r', 'wp-ov');
      link('wp-trim-r', 'wp-trim');
      let freeTimer = null;
      ['wp-free-w', 'wp-free-h'].forEach((n) => this.$(n) && this.$(n).addEventListener('input', () => {
        this._wpView = null;
        this._wpRender();
        // the 3D sample room takes the typed wall height (the band of the wall is put on its best matching wall)
        if (n === 'wp-free-h' ? this._isSample() : false) {
          clearTimeout(freeTimer);
          freeTimer = setTimeout(() => this.loadData(sampleRoom(...ccSize(), this._sampleWallH()), 'サンプル', { fromFile: false }), 300);
        }
      }));
      if (this.$('wp-free-use')) this.$('wp-free-use').addEventListener('click', () => {
        // stop using the CAD wall(s): the wall of the typed size
        this.elevIndex = -1;
        if (this.$('wp-join')) this.$('wp-join').checked = false;
        if (this.elev) this._renderElevList();
        this._wpView = null;
        this._wpRender();
      });
      ['wp-show-cad', 'wp-show-img', 'wp-show-frame', 'wp-right'].forEach((n) => this.$(n).addEventListener('change', () => this._wpRender()));
      this.$('wp-save').addEventListener('click', () => this._wpSave());
      if (this.$('wp-save-ai')) this.$('wp-save-ai').addEventListener('click', () => this._wpSave('ai'));
      // joining several walls (clockwise, round the room)
      const jl = this.$('wp-join-list');
      this.$('wp-join').addEventListener('change', () => this._wpRender());
      this.$('wp-align').addEventListener('change', () => this._wpRender());
      this.$('wp-ccw').addEventListener('change', () => this._wpRender());
      jl.addEventListener('input', () => this._wpRender());
      this.$('wp-join-add').addEventListener('click', () => {
        if (this.elevIndex < 0) return;
        const nums = this._wpJoinNums();
        if (nums.indexOf(this.elevIndex + 1) < 0) nums.push(this.elevIndex + 1);
        jl.value = nums.join(',');
        this.$('wp-join').checked = true;
        this._wpRender();
      });
      this.$('wp-join-all').addEventListener('click', () => {
        jl.value = this._elevAll().map((w, i) => i + 1).filter((n, i) => this._elevVisible(this._elevAll()[i], i)).join(',');
        this.$('wp-join').checked = true;
        this._wpRender();
      });
      this.$('wp-join-clear').addEventListener('click', () => { jl.value = ''; this._wpRender(); });
      // zoom / pan of the view (a viewBox in mm)
      const svg = this.$('wp-svg');
      this.$('wp-fit').addEventListener('click', () => { this._wpView = null; this._wpRender(); });
      this.$('wp-zin').addEventListener('click', () => this._wpZoom(1 / 1.4));
      this.$('wp-zout').addEventListener('click', () => this._wpZoom(1.4));
      svg.addEventListener('wheel', (e) => {
        e.preventDefault();
        if (e.deltaX !== 0 && Math.abs(e.deltaX) > Math.abs(e.deltaY)) { // a sideways wheel / trackpad swipe moves the view
          const v = this._wpCurView();
          if (v) { this._wpView = { x: v.x + Math.sign(e.deltaX) * v.w * 0.1, y: v.y, w: v.w, h: v.h }; this._wpApplyView(); }
          return;
        }
        this._wpZoom(e.deltaY < 0 ? 1 / 1.2 : 1.2, e);
      }, { passive: false });
      // what the drag / arrow buttons move: the view, the wall paper picture or the strip frames
      this._wpMode = 'view';
      const setMode = (m) => {
        this._wpMode = m;
        [['view', 'wp-m-view'], ['img', 'wp-m-img'], ['frame', 'wp-m-frame']].forEach(([k, n]) => this.$(n).classList.toggle('is-on', k === m));
        svg.style.cursor = m === 'view' ? 'grab' : 'move';
      };
      this.$('wp-m-view').addEventListener('click', () => setMode('view'));
      this.$('wp-m-img').addEventListener('click', () => setMode('img'));
      this.$('wp-m-frame').addEventListener('click', () => setMode('frame'));
      this.$('wp-reset').addEventListener('click', () => { const o = this._wpO(); o.img = { x: 0, y: 0 }; o.frame = { x: 0, y: 0 }; o.scale = 1; this._wpRender(); });
      // the picture's size (saved too) and how strongly it is shown on screen (the saved picture is always 100 %)
      this.$('wp-dscale').addEventListener('input', () => { this._wpO().scale = (parseFloat(this.$('wp-dscale').value) || 100) / 100; this._wpRender(); });
      this.$('wp-dopacity').addEventListener('input', () => this._wpRender());
      // drag: followed on the window, so it goes on outside the picture and the page's edge
      let drag = null;
      const onMove = (e) => {
        if (!drag) return;
        const r = svg.getBoundingClientRect();
        const k = Math.max(drag.v.w / r.width, drag.v.h / r.height);
        if (drag.mode !== 'view') { // the picture / the frames: shifted in mm; while dragging only the layer moves
          const o = { x: Math.round(drag.o.x + (e.clientX - drag.x) * k), y: Math.round(drag.o.y + (e.clientY - drag.y) * k) };
          this._wpO()[drag.mode] = o;
          const layer = svg.querySelector('[data-wp="' + drag.mode + '"]'), r0 = (this._wpRendered || {})[drag.mode] || { x: 0, y: 0 };
          if (layer) layer.setAttribute('transform', 'translate(' + (o.x - r0.x) + ' ' + (o.y - r0.y) + ')');
          return;
        }
        this._wpView = { x: drag.v.x - (e.clientX - drag.x) * k, y: drag.v.y - (e.clientY - drag.y) * k, w: drag.v.w, h: drag.v.h };
        this._wpApplyView();
      };
      const onEnd = () => {
        const moved = drag && drag.mode !== 'view';
        drag = null;
        this._wpDragging = false;
        if (moved) this._wpRender(); // the cut lines, the note, the picture and the perspective follow now
        svg.style.cursor = this._wpMode === 'view' ? 'grab' : 'move';
        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', onEnd);
        window.removeEventListener('pointercancel', onEnd);
      };
      svg.addEventListener('pointerdown', (e) => {
        const v = this._wpCurView();
        if (!v || e.button > 0) return;
        e.preventDefault();
        svg.focus({ preventScroll: true });
        const mode = this._wpMode || 'view';
        this._wpDragging = mode !== 'view';
        drag = { x: e.clientX, y: e.clientY, v, mode, o: mode === 'view' ? null : Object.assign({}, this._wpO()[mode]) };
        svg.style.cursor = mode === 'view' ? 'grabbing' : 'move';
        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onEnd);
        window.addEventListener('pointercancel', onEnd);
      });
      svg.style.cursor = 'grab';
      // pan buttons and arrow keys (a quarter of the view; Shift = a whole view)
      const pan = (dx, dy, big) => {
        if (this._wpMode !== 'view') { // the picture / the frames: 10 mm (Shift: 100 mm)
          const o = this._wpO(), c = o[this._wpMode], st = big ? 100 : 10;
          o[this._wpMode] = { x: c.x + dx * st, y: c.y + dy * st };
          this._wpRender();
          return;
        }
        const v = this._wpCurView();
        if (!v) return;
        const k = big ? 1 : 0.25;
        this._wpView = { x: v.x + dx * v.w * k, y: v.y + dy * v.h * k, w: v.w, h: v.h };
        this._wpApplyView();
      };
      this.$('wp-pl').addEventListener('click', (e) => pan(-1, 0, e.shiftKey));
      this.$('wp-pr').addEventListener('click', (e) => pan(1, 0, e.shiftKey));
      this.$('wp-pu').addEventListener('click', (e) => pan(0, -1, e.shiftKey));
      this.$('wp-pd').addEventListener('click', (e) => pan(0, 1, e.shiftKey));
      svg.setAttribute('tabindex', '0');
      svg.addEventListener('keydown', (e) => {
        const m = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[e.key];
        if (!m) return;
        e.preventDefault();
        pan(m[0], m[1], e.shiftKey);
      });
    }

    // the picture's shift is shared with the room perspective, the 3D view and the other wall paper saves
    _wpShare(o, sc) {
      const cur = window.cfpWallShift || { x: 0, y: 0, s: 1 };
      if (cur.x === o.x && cur.y === o.y && (cur.s || 1) === sc) return;
      const scaled = (cur.s || 1) !== sc;
      window.cfpWallShift = { x: o.x, y: o.y, s: sc };
      clearTimeout(this._wpShareTimer);
      this._wpShareTimer = setTimeout(() => {
        // the picture's size: the 3D walls and the tool's note follow (the room perspective below)
        if (scaled) {
          if (this.renderer) this.syncDesigns(false);
          if (window.cfpUpdateWallRepeatNote) window.cfpUpdateWallRepeatNote();
        }
        if (window.cfpLayoutPersp) window.cfpLayoutPersp();
        if (this.renderer && this.renderer.setWallShift) this.renderer.setWallShift(o.x, o.y);
      }, 80);
    }

    // how far the picture and the frames were moved on the current wall (mm)
    _wpO() {
      if (!this._wpOffs) this._wpOffs = new Map();
      const id = this._wpWall || '';
      if (!this._wpOffs.has(id)) this._wpOffs.set(id, { img: { x: 0, y: 0 }, frame: { x: 0, y: 0 }, scale: 1 });
      return this._wpOffs.get(id);
    }

    _wpClamp(name, v) {
      v = parseFloat(v);
      if (/ov/.test(name)) return Math.min(15, Math.max(6, Math.round((isNaN(v) ? 10 : v) * 2) / 2));
      if (/trim/.test(name)) return Math.min(100, Math.max(30, Math.round((isNaN(v) ? 100 : v) / 5) * 5));
      return Math.min(930, Math.max(850, Math.round(isNaN(v) ? 910 : v)));
    }

    // roll width / overlap changed: the print size, the total and the 3D margins follow
    _wpChanged() {
      const wp = window.cfpWallPrint;
      if (!wp) return;
      wp.set(this.$('wp-roll-r').value, this.$('wp-ov-r').value, this.$('wp-trim-r') ? this.$('wp-trim-r').value : undefined);
      this._updateWallTotal();
      if (this.hasTool && this.renderer) this.syncDesigns(true);
      this._wpRender();
    }

    _wpCurView() {
      const g = this._wpGeom();
      if (!g) return null;
      return this._wpView || this._wpBase(g);
    }

    // the whole picture with a margin around it, so the outermost frame lines are fully visible
    _wpBase(g) {
      const pad = Math.max(g.FW, g.H) * 0.04;
      return { x: -pad, y: -pad, w: g.FW + pad * 2, h: g.H + pad * 2 };
    }

    _wpApplyView() {
      const v = this._wpCurView();
      if (v) this.$('wp-svg').setAttribute('viewBox', [v.x, v.y, v.w, v.h].map((n) => Math.round(n * 100) / 100).join(' '));
    }

    // zoom by factor f (<1 zooms in), around the pointer (or the centre of the view)
    _wpZoom(f, e) {
      const g = this._wpGeom();
      const v = this._wpCurView();
      if (!g || !v) return;
      const svg = this.$('wp-svg');
      const r = svg.getBoundingClientRect();
      const base = this._wpBase(g);
      const nw = Math.min(base.w, Math.max(60, v.w * f)); // never wider than the whole picture; the position is kept
      const nh = nw * v.h / v.w;
      // the point under the pointer stays put
      const px = e ? (e.clientX - r.left) / r.width : 0.5, py = e ? (e.clientY - r.top) / r.height : 0.5;
      const ax = v.x + v.w * px, ay = v.y + v.h * py;
      this._wpView = { x: ax - nw * px, y: ay - nh * py, w: nw, h: nh };
      this._wpApplyView();
    }

    // For the carpet print PSD of the estimate tool: the chosen room's tile layout in the carpet's own picture
    // (left top = 0, mm, Y down, scaled to W x H): the drawing's part under it, the room outline and the tiles.
    // bleed: the picture is W + 2*bleed by H + 2*bleed (the carpet with a margin on every side)
    async carpetPsdInfo(W, H, bleed) {
      const B = bleed || 0;
      const room = (this.fromFile || this._isSample()) && this.data ? this.data.rooms[this.roomIndex] : null;
      if (!room) return null;
      const lay = this._tileLayout(room);
      if (!lay.box) return null;
      const bw = lay.box.x1 - lay.box.x0, bh = lay.box.y1 - lay.box.y0;
      const kx = W / bw, ky = H / bh;
      const toA = ([x, y]) => [(x - lay.box.x0) * kx + B, (lay.box.y1 - y) * ky + B];
      const info = {
        room: room.vertices.map(toA),
        tiles: lay.tiles.map((t) => { const p = toA([t.x, t.y + TILE]); return { x: p[0], y: p[1], w: TILE * kx, h: TILE * ky, cut: !!t.cut }; }),
        cad: null, name: room.name || '部屋',
      };
      // the room's own centre (the same centre mark as the layout view; it does not move with the layout)
      const rb = CADParser.bounds(room.vertices);
      info.center = toA([(rb.minX + rb.maxX) / 2, (rb.minY + rb.maxY) / 2]);
      const bd = this.backdrop;
      if (bd && bd.dataUrl && bd.mmPerPx) {
        try {
          const img = await new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = rej; i.src = bd.dataUrl; });
          const s = bd.mmPerPx;
          const io = this.imgOffset || { x: 0, y: 0 }; // the drawing moved in the layout view
          const ex = B / kx, ey = B / ky; // the margin in drawing mm
          info.cad = { bitmap: await createImageBitmap(img), sx: (lay.box.x0 - ex - io.x) / s, sy: bd.heightPx - (lay.box.y1 + ey + io.y) / s, sw: (bw + ex * 2) / s, sh: (bh + ey * 2) / s };
        } catch (err) { console.warn(err); }
      }
      return info;
    }

    // the wall(s) of the strip frame view shown on the matching walls of the 3D room (the same picture and position)
    _wp3D(g) {
      const r = this.renderer;
      if (!r || !r.setWallBand) return;
      const room = (this.fromFile || this._isSample()) && this.data ? this.data.rooms[this.roomIndex] : null;
      const src = g ? g.wp.getSource() : null;
      if (!g || !room || !src) {
        if (this._wpBand) { this._wpBand = null; this._wpBandKey = null; r.setWallBand(null); }
        return;
      }
      const off = this._wpO(), top = (this.$('wp-align') || {}).value === 'top';
      const key = [g.walls.map((x) => x.W).join(','), g.WH, top, off.img.x, off.img.y, off.scale || 1, src.width, src.height,
        JSON.stringify(window.cfpGetWallRepeat ? window.cfpGetWallRepeat() : 0)].join('|');
      if (this._wpBandKey === key) return;
      this._wpBandKey = key;
      clearTimeout(this._wpBandTimer);
      this._wpBandTimer = setTimeout(() => {
        const k = Math.min(1, 2048 / Math.max(g.W, g.WH));
        const inner = document.createElement('canvas');
        inner.width = Math.max(1, Math.round(g.W * k)); inner.height = Math.max(1, Math.round(g.WH * k));
        g.wp.renderDesign(inner, off.img.x, off.img.y, g.W, g.WH, k, off.scale || 1); // the walls only (no margins), as placed in the view
        // a white rim: where a room wall is longer than the band, the rest of it shows white (not a smeared edge)
        const c = document.createElement('canvas');
        c.width = inner.width + 4; c.height = inner.height + 4;
        const cx = c.getContext('2d');
        cx.fillStyle = '#ffffff'; cx.fillRect(0, 0, c.width, c.height);
        cx.drawImage(inner, 2, 2);
        this._wpBand = { widths: g.walls.map((x) => x.W), WH: g.WH, top, pu: 2 / c.width, pv: 2 / c.height };
        r.setWallBand(this._wpBand, c); // only the matching walls of the room; the others keep the usual wall paper
      }, 250);
    }

    // joined walls against the room chosen on the floor plan: the total width should equal the room's perimeter
    _wpCheckRoom(g) {
      const el = this.$('wp-warn');
      if (!el) return;
      const room = this.fromFile && this.data ? this.data.rooms[this.roomIndex] : null;
      if (!g || !g.multi || !room) { el.hidden = true; return; }
      const v = room.vertices;
      const sides = v.map((p, i) => { const q = v[(i + 1) % v.length]; return Math.hypot(q[0] - p[0], q[1] - p[1]); });
      const per = sides.reduce((t, x) => t + x, 0);
      const diff = g.W - per, tol = Math.max(50, per * 0.01);
      const f = (x) => Math.round(x).toLocaleString();
      const sideText = '部屋の辺（' + sides.length + '辺）：' + sides.map(f).join(' / ') + ' mm。';
      const countText = sides.length !== g.walls.length ? ' つなげた壁は ' + g.walls.length + ' 面で、部屋の辺の数（' + sides.length + '）と違います。' : '';
      // the same number of walls and sides: line them up (best starting side, in the joining direction) and compare one by one
      let pairText = '', pairBad = false;
      if (!countText) {
        const n = sides.length, ws = g.walls.map((x) => x.W);
        let best = null;
        [false, true].forEach((rev) => {
          const sd = rev ? sides.slice().reverse() : sides;
          for (let k = 0; k < n; k++) {
            const err = ws.reduce((t, wv, i) => t + Math.abs(wv - sd[(i + k) % n]), 0);
            if (!best || err < best.err) best = { err, sd, k };
          }
        });
        const off = ws.map((wv, i) => ({ no: g.walls[i].no, wv, sv: best.sd[(i + best.k) % n] })).filter((x) => Math.abs(x.wv - x.sv) > Math.max(30, x.sv * 0.01));
        if (off.length) {
          pairBad = true;
          pairText = ' 長さの合わない壁：' + off.map((x) => '壁' + x.no + '（' + f(x.wv) + '）↔ 部屋の辺（' + f(x.sv) + '）差 ' + (x.wv > x.sv ? '+' : '') + f(x.wv - x.sv)).join('、') + ' mm。';
        }
      }
      const bad = Math.abs(diff) > tol || !!countText || pairBad;
      el.hidden = false;
      el.classList.toggle('is-bad', bad);
      el.textContent = (bad ? '⚠️ 平面図で選んだ部屋「' + (room.name || '部屋') + '」と合いません。' : '✅ 平面図で選んだ部屋「' + (room.name || '部屋') + '」と合っています。')
        + ' つなげた壁の幅の合計 ' + f(g.W) + ' mm ／ 部屋の周長 ' + f(per) + ' mm → 差 ' + (diff > 0 ? '+' : '') + f(diff) + ' mm（' + (Math.round(diff / per * 1000) / 10) + '%）。'
        + countText + pairText + ' ' + sideText + (bad ? ' 壁の選び方・順番や、展開図・平面図の縮尺を確かめてください。' : '');
    }

    // the corners between joined walls (x in the picture, mm) and whether each is an inside corner (入隅) or an
    // outside corner (出隅): from the room chosen on the floor plan when its sides line up with the walls
    _wpCorners(g, shapes) {
      if (!g.multi) return [];
      const out = shapes.slice(1).map((sh, i) => ({ x: sh.x0, a: shapes[i].no, b: sh.no, type: '' }));
      const room = this.fromFile && this.data ? this.data.rooms[this.roomIndex] : null;
      const v = room ? room.vertices : null;
      if (!v || v.length !== g.walls.length) return out;
      const n = v.length;
      const sides = v.map((p, i) => { const q = v[(i + 1) % n]; return Math.hypot(q[0] - p[0], q[1] - p[1]); });
      let area = 0;
      v.forEach((p, i) => { const q = v[(i + 1) % n]; area += p[0] * q[1] - q[0] * p[1]; });
      // convex seen from inside the room = 入隅, the other way = 出隅
      const convex = (j) => {
        const a = v[(j + n - 1) % n], b = v[j], c = v[(j + 1) % n];
        const cr = (b[0] - a[0]) * (c[1] - b[1]) - (b[1] - a[1]) * (c[0] - b[0]);
        return cr * area > 0;
      };
      const ws = g.walls.map((x) => x.W);
      let best = null;
      [false, true].forEach((rev) => {
        for (let k = 0; k < n; k++) {
          const side = (i) => (rev ? sides[(n - 1 - ((i + k) % n) + n) % n] : sides[(i + k) % n]);
          const err = ws.reduce((t, wv, i) => t + Math.abs(wv - side(i)), 0);
          if (!best || err < best.err) best = { err, rev, k };
        }
      });
      out.forEach((c, i) => {
        // the vertex between wall i and wall i+1 (side m then side m+1 in the walking direction)
        const m = (i + best.k) % n;
        const vert = best.rev ? (n - 1 - m + n) % n : (m + 1) % n;
        c.type = convex(vert) ? '入隅' : '出隅';
      });
      return out;
    }

    // a wallpaper joint (cut line) less than 100 mm from a corner line: shown on the frame and as a warning
    _wpCornerCheck(g, shapes, cuts) {
      const MIN = 100;
      return this._wpCorners(g, shapes).map((c) => {
        let d = Infinity, at = null;
        cuts.forEach((x) => { const e = Math.abs(x - c.x); if (e < d) { d = e; at = x; } });
        return Object.assign(c, { d, at, bad: d < MIN });
      });
    }

    // the wall numbers typed for joining (1-based, in order, no duplicates, only walls that exist)
    _wpJoinNums() {
      const all = this._elevAll(), seen = new Set();
      return ((this.$('wp-join-list') || {}).value || '').split(/[^0-9]+/).map(Number)
        .filter((n) => n >= 1 && n <= all.length && !seen.has(n) && seen.add(n));
    }

    // the walls shown: the joined walls (clockwise order) or the wall chosen in the list. A wall of the CAD elevation
    // has priority; without one, a wall of the size typed in the panel (任意サイズ)
    _wpWalls() {
      const all = this._elevAll();
      if (this.$('wp-join') && this.$('wp-join').checked) {
        let nums = this._wpJoinNums();
        // left-hand (counter-clockwise): from the same first wall, the others the other way round
        if (this.$('wp-ccw').checked && nums.length > 2) nums = [nums[0]].concat(nums.slice(1).reverse());
        if (nums.length) return nums.map((n) => ({ w: all[n - 1], no: n }));
      }
      const w = all[this.elevIndex];
      if (w) return [{ w, no: this.elevIndex + 1 }];
      return [{ w: this._wpFreeWall(), no: 1 }];
    }

    // the sample room's wall height: the wall of a typed size of the strip frame when that is used (3D follows it)
    _sampleWallH() {
      const f = this.$('wp-free-h');
      if (!f || !this.hasTool) return 0;
      const all = this._elevAll();
      const cad = (this.$('wp-join') && this.$('wp-join').checked && this._wpJoinNums().length) || !!all[this.elevIndex];
      return cad ? 0 : this._wpFreeWall().height;
    }

    // the wall of the size typed in the panel (used when no wall of a CAD elevation is chosen)
    _wpFreeWall() {
      const v = (n, d, lo, hi) => Math.min(hi, Math.max(lo, Math.round(parseFloat((this.$(n) || {}).value) || d)));
      return { virtual: true, name: '任意サイズの壁', width: v('wp-free-w', 3000, 300, 200000), height: v('wp-free-h', 2400, 300, 10000) };
    }

    _wpGeom() {
      const wp = window.cfpWallPrint;
      if (!wp || !this.hasTool) return null;
      const walls = this._wpWalls();
      if (!walls.length) return null;
      walls.forEach((x) => { x.W = Math.max(1, Math.round(x.w.width)); x.WH = Math.max(1, Math.round(x.w.height)); });
      const W = walls.reduce((t, x) => t + x.W, 0), WH = Math.max(...walls.map((x) => x.WH));
      const H = WH + wp.TRIM_TOP + wp.TRIM_BOTTOM;
      const N = Math.max(1, Math.ceil((W + wp.OVERLAP - 5) / wp.STEP));
      return { wp, walls, w: walls[0].w, multi: walls.length > 1, W, WH, H, N, FW: Math.round(N * wp.STEP + wp.OVERLAP) };
    }

    // the wall's part of the elevation picture: [x, y, width, height] in picture pixels
    _wpCadBox(w) {
      const poly = w.poly || this._wallPoly(w);
      const xs = poly.map((p) => p[0]), ys = poly.map((p) => p[1]);
      const x0 = Math.min(...xs), y0 = Math.min(...ys);
      return [x0, y0, Math.max(...xs) - x0, Math.max(...ys) - y0];
    }

    // each wall's place and outline in the picture (mm): the walls side by side from ox, their floors (bottom)
    // or ceilings (top) lined up; a wall drawn as a polygon keeps its shape
    _wpShapes(g, ox) {
      const top = (this.$('wp-align') || {}).value === 'top';
      let x = ox;
      return g.walls.map((it) => {
        const x0 = x, y0 = g.wp.TRIM_TOP + (top ? 0 : g.WH - it.WH);
        x += it.W;
        const box = it.w.virtual ? [0, 0, it.W, it.WH] : this._wpCadBox(it.w);
        let pts = [[x0, y0], [x0 + it.W, y0], [x0 + it.W, y0 + it.WH], [x0, y0 + it.WH]];
        if (it.w.poly && it.w.poly.length >= 3 && box[2] > 0 && box[3] > 0) {
          const sx = it.W / box[2], sy = it.WH / box[3];
          pts = it.w.poly.map((q) => [x0 + (q[0] - box[0]) * sx, y0 + (q[1] - box[1]) * sy]);
        }
        return { x0, y0, W: it.W, WH: it.WH, box, pts, no: it.no, w: it.w };
      });
    }

    // the free size inputs: used without a CAD wall; with one, they show that the CAD wall has priority
    _wpFreeNote(g) {
      const note = this.$('wp-free-note');
      if (!note) return;
      const free = !!g.walls[0].w.virtual;
      ['wp-free-w', 'wp-free-h'].forEach((n) => { this.$(n).disabled = !free; });
      if (this.$('wp-free-use')) this.$('wp-free-use').hidden = free;
      note.textContent = free
        ? (this._elevAll().length ? '展開図で壁を選ぶと、その壁のサイズを使います（CADデータが優先）。' : 'CADの展開図を読み込んで壁を選ぶと、その壁のサイズを使います（CADデータが優先）。')
        : '✅ CADの展開図の壁（' + g.walls.map((x) => x.no).join('→') + '）のサイズを使っています（CADデータが優先。任意のサイズにするには「任意サイズに切り替える」）。';
    }

    _wpRender() {
      const panel = this.$('wp');
      if (!panel) return;
      const g = this._wpGeom();
      if (!g) { panel.hidden = true; this._wp3D(null); return; }
      panel.hidden = false;
      const { wp, w, W, WH, H, N, FW } = g;
      const svg = this.$('wp-svg');
      const wid = g.walls.map((x) => x.no).join('-') + '|' + W + 'x' + WH + '|' + (this._elevFile ? this._elevFile.name : '');
      if (this._wpWall !== wid) { this._wpWall = wid; this._wpView = null; } // a different wall: back to the whole view
      this._wpApplyView();
      const off = this._wpO();
      if (!this._wpDragging) this._wpShare(off.img, off.scale || 1);
      this.$('wp-dscale').value = Math.round((off.scale || 1) * 100);
      this.$('wp-dscale-val').textContent = this.$('wp-dscale').value;
      this.$('wp-dopacity-val').textContent = this.$('wp-dopacity').value;
      svg.textContent = '';
      const add = (name, attrs, parent) => {
        const e = document.createElementNS(SVG_NS, name);
        Object.keys(attrs).forEach((k) => e.setAttribute(k, attrs[k]));
        (parent || svg).appendChild(e);
        return e;
      };
      const right = this.$('wp-right').checked;
      const ox = right ? FW - wp.OVERLAP - W : wp.OVERLAP; // the wall's left edge in the picture
      const cuts = [];
      for (let i = 1; i < N; i++) cuts.push((right ? FW - i * wp.STEP - wp.EDGE : i * wp.STEP + wp.EDGE) + off.frame.x);
      add('rect', { x: 0, y: 0, width: FW, height: H, fill: '#ffffff' });
      const showCad = this.$('wp-show-cad').checked, showImg = this.$('wp-show-img').checked, showFrame = this.$('wp-show-frame').checked;
      const bd = this.elev && !g.walls[0].w.virtual ? this.elev.backdrop : null;
      this._wpFreeNote(g);
      const shapes = this._wpShapes(g, ox);
      const corners = this._wpCornerCheck(g, shapes, cuts);
      if (showCad && bd) {
        // one blob URL for the elevation picture (a long data URL set on every element again is slow)
        if (this._wpCadSrc !== bd.dataUrl) {
          this._wpCadSrc = bd.dataUrl;
          if (this._wpCadUrl) URL.revokeObjectURL(this._wpCadUrl);
          try {
            const bin = atob(bd.dataUrl.split(',')[1]), u8 = new Uint8Array(bin.length);
            for (let i = 0; i < bin.length; i++) u8[i] = bin.charCodeAt(i);
            this._wpCadUrl = URL.createObjectURL(new Blob([u8], { type: (bd.dataUrl.match(/^data:([^;,]+)/) || [])[1] || 'image/jpeg' }));
          } catch (err) { this._wpCadUrl = bd.dataUrl; }
        }
        shapes.forEach((sh) => {
          const inner = add('svg', { x: sh.x0, y: sh.y0, width: sh.W, height: sh.WH, viewBox: sh.box.join(' '), preserveAspectRatio: 'none' });
          add('image', { href: this._wpCadUrl, x: 0, y: 0, width: bd.widthPx, height: bd.heightPx }, inner);
        });
      }
      const gImg = add('g', { 'data-wp': 'img' });
      this._wpRendered = { img: { x: off.img.x, y: off.img.y }, frame: { x: off.frame.x, y: off.frame.y } };
      const src = wp.getSource();
      if (showImg && src) {
        const key = [src.width, src.height, W, WH, wp.ROLL, wp.OVERLAP, right ? 'R' : 'L', off.scale || 1, JSON.stringify(window.cfpGetWallRepeat ? window.cfpGetWallRepeat() : 0)].join('|');
        const uo = this._wpUrlOff || { x: 0, y: 0 };
        const same = this._wpKey === key && this._wpUrl;
        if (same) {
          // the picture as it was built, moved by what was moved since (a new one is built a moment later)
          add('image', { href: this._wpUrl, x: off.img.x - uo.x, y: off.img.y - uo.y, width: FW, height: H, preserveAspectRatio: 'none', opacity: (parseFloat(this.$('wp-dopacity').value) || 80) / 100 }, gImg);
        }
        if (!this._wpDragging && (!same || uo.x !== off.img.x || uo.y !== off.img.y)) {
          clearTimeout(this._wpTimer);
          this._wpTimer = setTimeout(() => {
            // drawn straight at the screen size (not the full 1px = 1mm picture): much lighter for long walls
            const k = Math.min(1, 3000 / Math.max(FW, H));
            const small = document.createElement('canvas');
            small.width = Math.max(1, Math.round(FW * k)); small.height = Math.max(1, Math.round(H * k));
            wp.renderDesign(small, ox + off.img.x, wp.TRIM_TOP + off.img.y, W, WH, k, off.scale || 1);
            this._wpUrl = small.toDataURL('image/jpeg', 0.85);
            this._wpUrlOff = { x: off.img.x, y: off.img.y };
            this._wpKey = key;
            this._wpRender();
          }, 200);
        }
      }
      if (showFrame) {
        const gFrame = add('g', { 'data-wp': 'frame' });
        const col = (i) => (i % 2 ? '#ff7a00' : '#ff0000');
        for (let i = 0; i < N; i++) {
          const odd = i % 2;
          const x = right ? FW - wp.ROLL - i * wp.STEP : i * wp.STEP;
          add('rect', { x: x + off.frame.x + 1.5, y: off.frame.y + 1.5 + odd * 4, width: wp.ROLL - 3, height: H - 3 - odd * 8, fill: odd ? 'rgba(255,122,0,0.05)' : 'rgba(255,0,0,0.05)',
            stroke: col(i), 'stroke-width': 3, 'vector-effect': 'non-scaling-stroke' }, gFrame);
        }
        const purple = { stroke: '#d000d0', 'stroke-width': 2, 'vector-effect': 'non-scaling-stroke' };
        shapes.forEach((sh) => {
          add('polygon', Object.assign({ points: sh.pts.map((q) => q.join(',')).join(' '), fill: 'none' }, purple));
          if (g.multi) { // the wall's number at its top left
            const t = add('text', { x: sh.x0 + 40, y: sh.y0 + Math.min(260, sh.WH * 0.12), fill: '#d000d0', 'font-size': Math.min(220, sh.WH * 0.1), 'font-weight': 'bold' });
            t.textContent = String(sh.no);
          }
        });
        // cut lines: in the middle of the overlap of two neighbouring strips, joined to the wall's top and bottom lines
        for (let i = 1; i < N; i++) {
          const cx = (right ? FW - i * wp.STEP - wp.EDGE : i * wp.STEP + wp.EDGE) + off.frame.x; // the middle of the overlap of two strips
          shapes.forEach((sh) => wp.cutSegments(sh.pts, cx).forEach((sg) => add('line', Object.assign({ x1: cx, y1: sg[0], x2: cx, y2: sg[1] }, purple), gFrame)));
        }
        // the corners (入隅・出隅) between the joined walls: blue, red when a joint is less than 100 mm away
        corners.forEach((c) => {
          const y0 = wp.TRIM_TOP, y1 = wp.TRIM_TOP + WH;
          const col = c.bad ? '#ef4444' : '#2563eb';
          add('line', { x1: c.x, y1: y0, x2: c.x, y2: y1, stroke: col, 'stroke-width': c.bad ? 4 : 2, 'stroke-dasharray': '10 6', 'vector-effect': 'non-scaling-stroke' }, gFrame);
          if (c.bad) add('rect', { x: Math.min(c.x, c.at) - 2, y: y0, width: Math.abs(c.at - c.x) + 4, height: WH, fill: 'rgba(239,68,68,0.25)' }, gFrame);
          const fs = Math.min(160, WH * 0.06);
          const t = add('text', { x: c.x + 20, y: y1 - fs * 0.6, fill: col, 'font-size': fs, 'font-weight': 'bold' }, gFrame);
          t.textContent = (c.bad ? '⚠ ' : '') + (c.type || '角') + (c.bad ? ' 継ぎ目まで ' + Math.round(c.d) + 'mm' : '');
        });
      }
      const fmt = (v) => String(Math.round(v * 10) / 10);
      this.$('wp-note').textContent = (g.multi
        ? '壁 ' + g.walls.map((x) => x.no).join(' → ') + '（' + (this.$('wp-ccw').checked ? '左回り' : '右回り') + 'につなげる・' + ((this.$('wp-align') || {}).value === 'top' ? '上（天井）' : '下（床）') + '合わせ） 幅の合計 ' + fmt(W) + ' × 高さ（最大） ' + fmt(WH) + ' mm → '
        : '壁「' + w.name + '」 幅 ' + fmt(W) + ' × 高さ ' + fmt(WH) + ' mm → ') + '画像の大きさ（切り分けなし）：幅 ' + FW + ' × 高さ ' + H + ' mm（壁の実寸＋上下' + wp.TRIM_TOP + 'mmずつ）。'
        + '巾 ' + wp.ROLL + ' mm・合わせ代（巾が重なる幅） ' + fmt(wp.OVERLAP) + ' mm・1巾が受け持つ壁の幅 ' + fmt(wp.STEP) + ' mm → ' + N + ' 巾（' + (right ? '右' : '左') + '寄せスタート）。紫の線＝壁の範囲と、巾が重なる部分（' + fmt(wp.OVERLAP) + ' mm）の真ん中（' + fmt(wp.OVERLAP / 2) + ' mm）のカット線。'
        + (off.img.x || off.img.y ? ' 画像を動かした量：横 ' + off.img.x + ' mm・縦 ' + off.img.y + ' mm。' : '')
        + ((off.scale || 1) !== 1 ? ' 画像の大きさ ' + Math.round(off.scale * 100) + '%。' : '')
        + (off.frame.x || off.frame.y ? ' 枠を動かした量：横 ' + off.frame.x + ' mm・縦 ' + off.frame.y + ' mm。' : '')
        + (g.walls.some((x) => x.W < 300) ? ' ⚠️ 幅が300mmより小さい壁があります。展開図の縮尺が合っていない（1:1 など）可能性があります。展開図だけを表示して「PDFの縮尺」に展開図の縮尺（例: 30、50）を入れると、指定した範囲の大きさも計算し直します。' : '')
        + (src ? '' : '「② 壁紙用デザイン」で画像を選ぶと、壁紙の画像も表示します。');
      this._wpCheckRoom(g);
      this._wpJointWarn(corners);
      if (!this._wpDragging) this._wp3D(g);
    }

    // the warning for joints near a corner (入隅・出隅): less than 100 mm away
    _wpJointWarn(corners) {
      const el = this.$('wp-joint-warn');
      if (!el) return;
      if (!corners.length) { el.hidden = true; return; }
      const bad = corners.filter((c) => c.bad);
      el.hidden = false;
      el.classList.toggle('is-bad', !!bad.length);
      const name = (c) => '壁' + c.a + '→' + c.b + (c.type ? 'の' + c.type : 'の角');
      el.textContent = bad.length
        ? '⚠️ 壁紙の継ぎ目（カット線）が出隅・入隅に近すぎます（100mm以上離してください）：'
          + bad.map((c) => name(c) + ' から ' + Math.round(c.d) + 'mm').join('、')
          + '。「枠を移動」で枠を左右に動かすか、右寄せ／左寄せ・巾・合わせ代を変えてください。'
        : '✅ 出隅・入隅（' + corners.length + 'か所）から壁紙の継ぎ目まで、すべて100mm以上離れています（いちばん近いところ ' + Math.round(Math.min(...corners.map((c) => c.d))) + 'mm）。';
    }

    // format: 'ai' = Illustrator 用（.ai・PDF互換）、省略 = CMYK の PSD
    async _wpSave(format) {
      const g = this._wpGeom();
      const ai = format === 'ai';
      const btn = this.$(ai ? 'wp-save-ai' : 'wp-save');
      const kind = ai ? 'AI' : 'PSD';
      if (!g) return;
      const { wp, w, W, WH } = g;
      const old = btn.textContent;
      btn.disabled = true;
      btn.textContent = '作成中…';
      try {
        await new Promise((r) => setTimeout(r, 30));
        const right = this.$('wp-right').checked;
        const shapes = this._wpShapes(g, right ? g.FW - wp.OVERLAP - W : wp.OVERLAP);
        // the CAD layer is drawn piece by piece (tiles) by the PSD writer: no canvas as big as the whole picture
        let cadDraw = null;
        const bd = this.elev && !g.walls[0].w.virtual ? this.elev.backdrop : null;
        if (bd) {
          const img = await new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => rej(new Error('図面の画像を読めませんでした')); i.src = bd.dataUrl; });
          cadDraw = (cx) => {
            cx.imageSmoothingQuality = 'high';
            shapes.forEach((sh) => cx.drawImage(img, sh.box[0], sh.box[1], sh.box[2], sh.box[3], sh.x0, sh.y0, sh.W, sh.WH));
          };
        }
        const label = g.multi ? '壁 ' + g.walls.map((x) => x.no).join('→') + ' をつなげる・幅の合計 ' + W + 'mm' : '壁の範囲 ' + W + '×' + WH + 'mm';
        const out = await wp.buildWholeAsync(cadDraw, W, WH, right, shapes.map((sh) => sh.pts), this._wpO(), label,
          (f) => { btn.textContent = kind + 'を作成中… ' + Math.round(f * 100) + '%'; }, +this.$('wp-split').value || 0, format);
        const a = document.createElement('a');
        a.href = URL.createObjectURL(out.blob);
        a.download = out.name;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 2000);
        this.status('壁紙データを保存しました（' + (ai ? 'Illustrator用 .ai（PDF互換）・実寸・' : '') + out.width + ' × ' + out.height + ' mm・' + (ai ? '' : '1px = 1mm・') + out.strips + ' 巾分。レイヤー：CAD図面／壁紙の画像／巾の枠' + (ai ? '（線）' : '') + '）。'
          + (out.split ? '幅が' + out.maxw + 'mmを超えるため、巾の区切りで ' + out.split + ' 個の' + kind + '（それぞれ' + out.maxw + 'mm以内）に分け、ZIPにまとめて保存しました。' : '')
          + (ai ? (out.split ? out.maxw : out.width) > 5080 ? ' 幅が約5mを超える .ai は、Illustratorの「大きなカンバス」（倍率付き）として開かれることがあります。普通のカンバスで開くには「PSDを分ける幅」を5m以内にしてください。' : '' : '')
          + (out.psb ? '幅が30000mmを超えるため、PhotoshopのPSB形式（大きなドキュメント形式）で保存しました。Photoshopで開けます。' : ''), 'success');
      } catch (err) {
        this.status('エラー: 壁紙データを作成できませんでした（' + err.message + '）', 'error');
        console.error(err);
      } finally {
        btn.disabled = false;
        btn.textContent = old;
      }
    }

    _elevAll() {
      return this.elev ? this.elev.walls.concat(this.elev.custom) : [];
    }

    _initElevTracer() {
      const bd = this.elev.backdrop;
      if (!this.elevTracer) {
        this.elevTracer = new RoomTracer(this.$('elev-svg'), { onCommit: (poly) => this._commitElevRect(poly), onMove: (i, poly) => this._moveElevRect(i, poly) });
        this.elevTracer.ortho = this.$('elev-ortho').checked; // corners line up with their neighbours when reshaping
      }
      if (this._elevImage !== bd.dataUrl) {
        this.elevTracer.setBackdrop(bd.dataUrl, bd.widthPx, bd.heightPx);
        this._elevImage = bd.dataUrl;
      }
    }

    // wall rectangles in picture pixels
    _wallPoly(w) {
      const { pageH } = this.elev, k = this.elev.backdrop.k;
      const x0 = w.x0 * k, x1 = w.x1 * k, y0 = (pageH - w.y1) * k, y1 = (pageH - w.y0) * k;
      return [[x0, y0], [x1, y0], [x1, y1], [x0, y1]];
    }

    _refreshElevTracer() {
      if (!this.elevTracer || !this.elev) return;
      const all = this._elevAll();
      const items = [];
      all.forEach((w, i) => {
        if (!this._elevVisible(w, i)) return;
        const sel = i === this.elevIndex;
        let poly;
        if (w.poly) poly = w.poly.map((p) => [p[0], p[1]]); // a copy: the tracer edits it in place while dragging
        else poly = this._wallPoly(w);
        items.push({ poly, label: String(i + 1), manual: !!w.custom, movable: !!w.custom, key: i, selected: sel, color: sel ? '#2563eb' : (w.custom ? '#16a34a' : (w.cloth ? '#a855f7' : '#a1a1aa')) });
      });
      this.elevTracer.setRooms(items);
    }

    _elevVisible(w, i) {
      if (this.$('elev-hide-auto').checked && !w.custom) return false; // hide the walls found automatically: only the ones drawn by hand
      return !(this.$('elev-cloth-only').checked && !w.cloth && !w.custom);
    }

    _renderElevList() {
      if (!this.elev) return;
      const list = this.$('elev-list');
      list.textContent = '';
      this._elevAll().forEach((w, i) => {
        if (!this._elevVisible(w, i)) return;
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'cfp-room-btn' + (w.cloth ? ' is-cloth' : '') + (i === this.elevIndex ? ' is-selected' : '');
        b.setAttribute('data-i', i);
        const num = document.createElement('span'); num.className = 'cfp-room-num'; num.textContent = i + 1;
        const nm = document.createElement('span'); nm.className = 'cfp-room-name'; nm.textContent = w.name;
        if (w.cloth) { const t = document.createElement('span'); t.className = 'cfp-badge'; t.textContent = 'クロス'; nm.appendChild(t); nm.title = w.cloth; }
        const meta = document.createElement('span'); meta.className = 'cfp-room-meta'; meta.textContent = fmtMm(w.width) + ' × ' + fmtMm(w.height) + ' mm';
        b.append(num, nm, meta);
        b.addEventListener('click', () => this.selectWall(i));
        const item = document.createElement('div');
        item.className = 'cfp-room-item';
        const chk = document.createElement('label');
        chk.className = 'cfp-elev-chk';
        chk.title = '壁紙の合計に入れる';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.checked = !!w.inTotal;
        cb.setAttribute('aria-label', w.name + ' を壁紙の合計に入れる');
        cb.addEventListener('change', () => { w.inTotal = cb.checked; this._updateWallTotal(); });
        chk.appendChild(cb);
        item.append(chk, b);
        list.appendChild(item);
      });
      this._refreshElevTracer();
      this._updateWallTotal();
      this._wpRender();
    }

    // The walls ticked on every loaded elevation sheet: total width, area and wall paper strips (910 mm rolls).
    _updateWallTotal() {
      // 910mm幅のロールを合わせ代10mmだけ重ねて貼る（1巾が受け持つのは900mm）。1巾の長さ = 壁の高さ + 上下100mm
      const WP = window.cfpWallPrint || { OVERLAP: 10, STEP: 900, TRIM_TOP: 100, TRIM_BOTTOM: 100 };
      const OV = WP.OVERLAP || 10, ROLL = WP.STEP, TRIM = WP.TRIM_TOP + WP.TRIM_BOTTOM;
      const picked = [];
      this.drawings.filter((d) => d.type === 'elev' && d.elev).forEach((d) => {
        const custom = d.file === this._elevFile && this.elev ? this.elev.custom : ((this.elevStates.get(d.file) || {}).custom || []);
        d.elev.walls.concat(custom).forEach((w) => { if (w.inTotal) picked.push({ w, file: d.file.name }); });
      });
      const out = this.$('elev-total');
      let total = null;
      if (picked.length) {
        let width = 0, area = 0, strips = 0, len = 0;
        picked.forEach(({ w }) => {
          width += w.width;
          area += w.width * w.height / 1e6;
          const n = Math.ceil((w.width + OV - 5) / ROLL);
          strips += n;
          len += n * (w.height + TRIM) / 1000;
        });
        const files = [...new Set(picked.map((p) => p.file))];
        total = {
          count: picked.length, widthMm: Math.round(width), areaM2: Math.round(area * 100) / 100,
          strips, lengthM: Math.round(len * 10) / 10, files,
        };
        total.text = picked.length + '面（' + files.join('・') + '）幅の合計 ' + fmtMm(total.widthMm) + ' mm／面積の合計 ' + total.areaM2
          + ' ㎡／' + (window.cfpWallPrint ? window.cfpWallPrint.ROLL : 910) + 'mm幅のロール（合わせ代' + (window.cfpWallPrint ? window.cfpWallPrint.OVERLAP : 10) + 'mm）で ' + strips + ' 巾・長さの合計 約 ' + total.lengthM + ' m（1巾 = 壁の高さ + 上下' + (window.cfpWallPrint ? window.cfpWallPrint.TRIM_TOP : 100) + 'mmずつ）';
        out.textContent = '壁紙の合計: ' + total.text + '。扉・窓などの開口は差し引いていません。';
      } else out.textContent = '壁紙の合計: 一覧の左のチェックで、合計に入れる壁を選んでください。';
      window.cfpWallTotal = total;
      document.dispatchEvent(new CustomEvent('cfp:wall-total', { detail: total }));
    }

    // Sets the wall paper size (wall width / wall height) from the chosen wall.
    selectWall(i) {
      const w = this._elevAll()[i];
      if (!w) return;
      this.elevIndex = i;
      this._renderElevList();
      const area = Math.round(w.width * w.height / 1e4) / 100;
      let msg = '「' + w.name + '」 幅 ' + fmtMm(w.width) + ' × 高さ ' + fmtMm(w.height) + ' mm（約 ' + area + ' ㎡）';
      if (w.cloth) msg += '。図面の記載: ' + w.cloth;
      const pw = document.getElementById('persp-width');
      const ph = document.getElementById('persp-wall-height');
      if (this.hasTool && pw && ph && this.$('apply-size').checked) {
        this._setField(pw, Math.round(w.width));
        this._setField(ph, Math.round(w.height));
        msg += '。壁紙のサイズ（お部屋パースの幅・壁の高さ）に反映しました。';
      } else if (this.hasTool) msg += '。「図面のサイズを…反映する」がオフのため、反映していません。';
      this.$('elev-note').textContent = msg;
      this.status('壁を選びました。', 'success');
    }

    // a wall paper area drawn by hand, dragged to another place (the size stays the same)
    _moveElevRect(i, poly) {
      const w = this._elevAll()[i];
      if (!w || !w.custom) return;
      w.poly = poly;
      // the size follows the new outline (its width x height)
      const s = this.elev.backdrop.mmPerPx;
      const xs = poly.map((p) => p[0]), ys = poly.map((p) => p[1]);
      w.width = Math.round((Math.max(...xs) - Math.min(...xs)) * s);
      w.height = Math.round((Math.max(...ys) - Math.min(...ys)) * s);
      this._renderElevList();
      if (i === this.elevIndex) this.selectWall(i);
      const editing = this.elevTracer && this.elevTracer.tool === 'edit';
      this.status('「' + w.name + '」の' + (editing ? '形を変えました（' + fmtMm(w.width) + ' × ' + fmtMm(w.height) + ' mm）。' : '位置を移動しました。'), 'success');
    }

    // a rectangle drawn on the elevation: the wall paper area of that wall
    _commitElevRect(poly) {
      const bd = this.elev.backdrop, s = bd.mmPerPx;
      const xs = poly.map((p) => p[0]), ys = poly.map((p) => p[1]);
      const wmm = (Math.max(...xs) - Math.min(...xs)) * s;
      const hmm = (Math.max(...ys) - Math.min(...ys)) * s;
      if (wmm < 10 || hmm < 10) {
        this.status('囲んだ範囲が小さすぎます（幅 ' + Math.round(wmm) + ' × 高さ ' + Math.round(hmm) + ' mm）。「PDFの縮尺（1:N の N）」に展開図の縮尺（例: 50）を入れてから囲んでください。', 'error');
        return;
      }
      const n = this.elev.custom.length + 1;
      this.elev.custom.push({ id: 'custom_' + n, name: '指定した壁紙の範囲 ' + n, width: Math.round(wmm), height: Math.round(hmm), custom: true, poly, cloth: null, scaleN: this.elev.scale });
      this._renderElevList();
      this.selectWall(this._elevAll().length - 1);
    }

    // --------------------------------------------------- tracing rooms by hand
    async openTracer(scroll = true) {
      if (!this.lastFile || !this.fromFile) return;
      this.$('trace').hidden = false;
      if (!this.backdrop) {
        // a PDF read as vectors: render its page so that rooms can be traced on it
        try {
          this.status('図面を表示しています…', 'loading');
          const scale = parseFloat(this.$('scale').value) || 1;
          this.backdrop = await CADParser.renderPdfBackdrop(this.lastFile, { scale });
          this.backdropFile = this.lastFile;
          this._afterRoomsChanged();
          this.status('図面の上で部屋を指定してください。', 'success');
        } catch (err) {
          this.status('エラー: ' + err.message, 'error');
          console.error(err);
          return;
        }
      }
      if (!this.tracer) {
        this.tracer = new RoomTracer(this.$('trace-svg'), { onCommit: (poly) => this._commitManual(poly), onMove: (i, poly) => this._moveManual(i, poly) });
        this.$('tool-rect').classList.add('is-on');
      }
      this.tracer.ortho = this.$('ortho').checked;
      if (this._tracerImage !== this.backdrop.dataUrl) {
        // only when the picture changed, so that zoom / pan survive a reload
        this.tracer.setBackdrop(this.backdrop.dataUrl, this.backdrop.widthPx, this.backdrop.heightPx);
        this._tracerImage = this.backdrop.dataUrl;
      }
      this._refreshTracer();
      if (scroll) this.$('trace').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    // rooms shown on the picture: every room of the plan converted back to picture pixels
    _refreshTracer() {
      if (!this.tracer || !this.backdrop || this.$('trace').hidden) return;
      const s = this.backdrop.mmPerPx, H = this.backdrop.heightPx;
      this.tracer.setRooms(this.data.rooms.map((r, i) => ({
        poly: r.vertices.map(([x, y]) => [x / s, H - y / s]),
        label: String(i + 1),
        manual: !!(this.roomKinds[i] && this.roomKinds[i].manual),
        movable: !!(this.roomKinds[i] && this.roomKinds[i].manual),
        key: i,
        selected: i === this.roomIndex,
      })));
    }

    _commitManual(poly) {
      this.manual.push({ poly, name: '指定した部屋 ' + (this.manual.length + 1) });
      this._afterRoomsChanged(this.roomKinds.length); // the new room is last
      const n = this.data.rooms.length;
      this.status('部屋を追加しました（部屋 ' + n + '）。続けて指定するか、一覧から部屋を選んでください。', 'success');
    }

    // a hand-traced room dragged to another place with the move tool (only the outline moves)
    _moveManual(i, poly) {
      const kind = this.roomKinds[i];
      if (!kind || !kind.manual) return;
      this.manual[kind.mi].poly = poly;
      this._afterRoomsChanged(i);
      const editing = this.tracer && this.tracer.tool === 'edit';
      this.status('「' + (this.data.rooms[i].name || '指定した部屋') + '」の' + (editing ? '形を変えました。' : '位置を移動しました。'), 'success');
    }

    _removeManual(mi) {
      this.manual.splice(mi, 1);
      this._afterRoomsChanged();
      this.status('指定した部屋を削除しました。', 'success');
    }

    // ------------------------------------------------------------- image drawings
    _rasterLabels() {
      const t = parseFloat(this.$('threshold').value) || 0;
      this.$('threshold-val').textContent = t ? String(t) : '自動';
      this.$('gap-val').textContent = this.$('gap').value;
    }

    _rasterNote() {
      const note = this.$('cal-note');
      const r = this.raster;
      if (!r) { note.textContent = ''; return; }
      note.textContent = r.calibrated
        ? '縮尺を設定しました（画像 1px ＝ ' + r.mmPerPx.toFixed(2) + ' mm）。別の部屋で確かめるには、その部屋を選んで実際の長さを入力し直してください。'
        : '縮尺が未設定です。図面に書かれた寸法を見て、選んだ部屋の横幅または奥行の実際の長さを入力し、「この大きさにする」を押してください（押すまで、見積もり・壁紙サイズには反映しません）。';
    }

    // Sets the scale from the real length of the selected room's width or depth.
    calibrate() {
      if (!this.raster || !this.data) return;
      const mm = parseFloat(this.$('cal-mm').value);
      if (!(mm > 0)) { this.status('選んだ部屋の実際の長さ（mm）を入力してください。', 'error'); return; }
      const room = this.data.rooms[this.roomIndex];
      if (!room) { this.status('先に部屋を選んでください。', 'error'); return; }
      const b = CADParser.bounds(room.vertices);
      const px = (this.$('cal-axis').value === 'h' ? b.maxY - b.minY : b.maxX - b.minX) / this.raster.mmPerPx;
      if (!(px > 0)) return;
      this.raster.mmPerPx = mm / px;
      this.raster.calibrated = true;
      this._afterRoomsChanged();
      this._rasterNote();
      this.status('縮尺を設定しました。', 'success');
    }

    // ------------------------------------------------- size -> estimate / perspective
    _setField(el, v) {
      el.value = v;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /**
     * Writes the selected room's size into the estimate tool (carpet W x H, rounded up to the
     * 500 mm tile) and the room perspective / wallpaper size fields (width / floor depth / wall height).
     */
    reflectSize(room) {
      const note = this.$('size-note');
      const ccW = document.getElementById('cc-width');
      const ccH = document.getElementById('cc-height');
      if (!ccW || !ccH) { note.textContent = ''; return; }

      const b = CADParser.bounds(room.vertices);
      const w = b.maxX - b.minX, h = b.maxY - b.minY;
      // 500 mm tiles laid from the room's centre: the cut tiles at the edges count as whole tiles
      const lay = this._tileLayout(room);
      const tileW = lay.cols * TILE;
      const tileH = lay.rows * TILE;
      this._setField(ccW, tileW);
      this._setField(ccH, tileH);

      const wallH = Math.max(...room.walls.map((x) => x.height || CADParser.DEFAULT_WALL_HEIGHT));
      let clamped = false;
      // a wall chosen on an elevation sheet owns the wall paper size (width / height)
      const wallLocked = !!(this.elev && this.elevIndex >= 0);
      const persp = wallLocked ? { 'persp-floor-depth': h } : { 'persp-width': w, 'persp-floor-depth': h, 'persp-wall-height': wallH };
      const applied = {};
      Object.keys(persp).forEach((id) => {
        const el = document.getElementById(id);
        if (!el) return;
        const min = parseFloat(el.min) || 0, max = parseFloat(el.max) || Infinity;
        const v = Math.min(max, Math.max(min, Math.round(persp[id] / 50) * 50));
        if (v !== Math.round(persp[id] / 50) * 50) clamped = true;
        applied[id] = v;
        this._setField(el, v);
      });

      const area = CADParser.calculateArea(room.vertices);
      let text = '図面から「' + (room.name || '部屋') + '」のサイズ ' + fmtMm(w) + '×' + fmtMm(h) + ' mm を読み取り、'
        + 'カーペットを ' + fmtMm(tileW) + '×' + fmtMm(tileH) + ' mm（50cm角を部屋の中心から割り付け、端の切れる部分も1枚として計算：横' + lay.cols + '枚×縦' + lay.rows + '枚）、'
        + (wallLocked
          ? 'お部屋パースの奥行を ' + fmtMm(applied['persp-floor-depth'] || h) + ' mm に設定しました（壁紙の幅・高さは、展開図で選んだ壁のサイズのままです）。'
          : 'お部屋パース・壁紙のサイズを 幅' + fmtMm(applied['persp-width'] || w)
            + '／奥行' + fmtMm(applied['persp-floor-depth'] || h)
            + '／壁の高さ' + fmtMm(applied['persp-wall-height'] || wallH) + ' mm に設定しました。');
      if (Math.abs(area - w * h) / (w * h) > 0.01) {
        text += ' ※四角でない部屋のため、見積もりは外接する四角（' + fmtArea(tileW * tileH) + '㎡）で計算されます（実際の床面積は ' + fmtArea(area) + '㎡）。';
      }
      if (lay.count !== lay.cols * lay.rows) text += ' 部屋に実際に敷く枚数は ' + lay.count + ' 枚（うち端で切る ' + lay.cut + ' 枚）です。';
      if (clamped) text += ' ※お部屋パース・壁紙のサイズは入力欄の上限・下限に丸めました。';
      note.textContent = text;
      this._ccSizeNote(room, lay, area, w, h);
    }

    // the note under the estimate's size fields: where the size comes from, and a polygon room
    _ccSizeNote(room, lay, area, w, h) {
      const n = document.getElementById('cc-cad-size-note');
      if (!n) return;
      if (!room) { n.hidden = true; n.textContent = ''; return; }
      const poly = room.vertices.length !== 4 || Math.abs(area - w * h) / (w * h) > 0.01;
      n.hidden = false;
      n.textContent = '📐 CADで指定した部屋「' + (room.name || '部屋') + '」から算出したサイズです（部屋 ' + fmtMm(w) + '×' + fmtMm(h) + ' mm → 50cm角 横' + lay.cols + '枚×縦' + lay.rows + '枚 = ' + fmtMm(lay.cols * TILE) + '×' + fmtMm(lay.rows * TILE) + ' mm）。'
        + (poly ? ' ⚠️ 多角形（四角でない）の部屋です。仕様サイズは部屋に外接する四角の大きさで、実際の床面積は ' + fmtArea(area) + '㎡（部屋に敷く枚数 ' + lay.count + ' 枚）です。' : '');
    }

    // ----------------------------------------------- carpet tile layout (割付)
    // 500 x 500 mm tiles centred on the room (plus the layout's shift). Tiles that touch the room count,
    // the cut ones at the edges as whole tiles. Returns the tiles in mm and the counts.
    // the image size (1 = 100 %): the estimate tool's slider when there is one (one setting for both), else the layout's
    _imgScale() {
      const cc = document.getElementById('cc-img-scale');
      if (cc) return (parseFloat(cc.value) || 100) / 100 * (this.design.scale || 1);
      return this.design.scale || 1;
    }

    // the sample room (from the estimate's carpet size) on a page with the estimate tool
    _isSample() {
      return !this.fromFile && this.hasTool && !!(this.data && this.data.metadata && this.data.metadata.source === 'sample');
    }

    // where the layout sits before it is moved: on a drawing, one tile centred on the room centre; on the sample
    // room (the carpet size itself) the tiles start at the room's edge, so that the count matches the estimate
    _homeOffset(room) {
      if (!room || room.id !== 'sample') return { x: 0, y: 0 };
      const b = CADParser.bounds(room.vertices);
      const one = (lo, hi) => { let o = ((lo - ((lo + hi) / 2 - TILE / 2)) % TILE + TILE) % TILE; if (o > TILE / 2) o -= TILE; return o; };
      return { x: one(b.minX, b.maxX), y: one(b.minY, b.maxY) };
    }

    _tileLayout(room, offset) {
      const b = CADParser.bounds(room.vertices);
      const off = offset || this.gridOffsets[this.roomIndex] || this._homeOffset(room);
      const cx = (b.minX + b.maxX) / 2 + off.x, cy = (b.minY + b.maxY) / 2 + off.y;
      // one tile centred on the room centre (a tile edge is on the centre line after a 250 mm shift)
      const tx = cx - TILE / 2, ty = cy - TILE / 2;
      const ox = tx - Math.ceil((tx - b.minX) / TILE + 1) * TILE, oy = ty - Math.ceil((ty - b.minY) / TILE + 1) * TILE;
      const nx = Math.ceil((b.maxX - ox) / TILE) + 1, ny = Math.ceil((b.maxY - oy) / TILE) + 1;
      const area = CADParser.calculateArea(room.vertices);
      const tiles = [];
      let i0 = Infinity, i1 = -Infinity, j0 = Infinity, j1 = -Infinity, cut = 0;
      for (let i = 0; i < nx; i++) {
        for (let j = 0; j < ny; j++) {
          const x = ox + i * TILE, y = oy + j * TILE;
          const a = clipArea(room.vertices, x, y, x + TILE, y + TILE);
          if (a < 1) continue; // touches the room by less than 1 mm2
          const isCut = a < TILE * TILE - 1;
          tiles.push({ x, y, cut: isCut });
          if (isCut) cut++;
          i0 = Math.min(i0, i); i1 = Math.max(i1, i); j0 = Math.min(j0, j); j1 = Math.max(j1, j);
        }
      }
      if (!tiles.length) return { tiles, cols: 1, rows: 1, count: 0, cut: 0, cx, cy, box: null, area };
      return {
        tiles, cx, cy, area,
        cols: i1 - i0 + 1, rows: j1 - j0 + 1, count: tiles.length, cut,
        box: { x0: ox + i0 * TILE, y0: oy + j0 * TILE, x1: ox + (i1 + 1) * TILE, y1: oy + (j1 + 1) * TILE },
      };
    }

    // drawing coordinates (mm, Y up) -> layout view (mm, Y down), with the picture when there is one
    _layoutView() {
      const bd = this.backdrop;
      if (bd) {
        const s = bd.mmPerPx, W = bd.widthPx * s, H = bd.heightPx * s;
        return { W, H, img: bd.dataUrl, toV: ([x, y]) => [x, H - y] };
      }
      const b = CADParser.bounds(this.data.rooms.flatMap((r) => r.vertices));
      const m = Math.max(b.maxX - b.minX, b.maxY - b.minY) * 0.12 + TILE;
      return { W: b.maxX - b.minX + m * 2, H: b.maxY - b.minY + m * 2, img: '', toV: ([x, y]) => [x - b.minX + m, b.maxY - y + m] };
    }

    // a vector PDF has no picture until one is rendered: render its page so that the drawing shows under the
    // tile layout and rooms can be traced on it (also after resuming saved work or switching drawings)
    async _ensureBackdrop() {
      if (this.backdrop || this.raster || !this.fromFile || !this.lastFile || !/\.pdf$/i.test(this.lastFile.name) || this._bdBusy) return;
      const file = this.lastFile;
      this._bdBusy = true;
      try {
        const bd = await CADParser.renderPdfBackdrop(file, { scale: parseFloat(this.$('scale').value) || 1 });
        if (this.lastFile !== file || this.backdrop) return;
        this.backdrop = bd;
        this.backdropFile = file;
        this._afterRoomsChanged();
      } catch (err) {
        console.warn(err);
      } finally {
        this._bdBusy = false;
      }
    }

    _renderLayout(previewOffset) {
      if (!previewOffset) this._floorSoon(); // the 3D floor follows the carpet's place on the layout
      const panel = this.$('layout');
      const room = this.data && this.data.rooms[this.roomIndex];
      // also the sample room made from the carpet size typed in the estimate (仕様サイズ)
      if (!(this.fromFile || this._isSample()) || !room) { panel.hidden = true; return; }
      panel.hidden = false;
      if (!this.backdrop) this._ensureBackdrop();
      const view = this._layoutView();
      if (!this.layoutTracer) {
        this.layoutTracer = new RoomTracer(this.$('layout-svg'), {
          customTools: ['grid', 'design'],
          onCustomDrag: (tool, d, phase) => this._layoutDrag(tool, d, phase),
        });
        this.layoutTracer.setTool('pan');
      }
      const key = view.img + '|' + Math.round(view.W) + 'x' + Math.round(view.H);
      if (this._layoutKey !== key) {
        this.layoutTracer.setBackdrop(view.img, view.W, view.H);
        this._layoutKey = key;
      }
      const showImg = this.$('layout-show-img').checked;
      this.layoutTracer.setImageVisible(showImg && !!view.img);
      this.layoutTracer.setImageOffset(this.imgOffset.x, this.imgOffset.y);
      this.layoutTracer.setRooms(this.data.rooms.map((r, i) => ({
        poly: r.vertices.map(view.toV), label: i === this.roomIndex ? String(i + 1) : '',
        selected: i === this.roomIndex, color: i === this.roomIndex ? '#2563eb' : '#a1a1aa',
      })));

      const ov = this.layoutTracer.overlay;
      ov.textContent = '';
      const lay = this._tileLayout(room, previewOffset && previewOffset.grid);
      const add = (name, attrs, parent) => {
        const e = document.createElementNS(SVG_NS, name);
        Object.keys(attrs).forEach((k) => e.setAttribute(k, attrs[k]));
        (parent || ov).appendChild(e);
        return e;
      };
      // the converted carpet image (① reduced colours), laid over the tile layout; size / position adjustable
      const dsrc = document.getElementById('cc-reduced');
      const hasDesign = !!(dsrc && dsrc.width && canvasHasContent(dsrc));
      this.$('layout-design').disabled = !hasDesign;
      if (hasDesign && lay.box && this.$('layout-show-design').checked) {
        if (!this._designUrl) this._designUrl = dsrc.toDataURL('image/png');
        const r = this._designRect(lay, dsrc, previewOffset && previewOffset.design);
        const w = r.w, h = r.h;
        const [vx, vy] = view.toV([r.x0, r.y1]);
        let parent = add('g', {});
        // checked: the image fills the whole tile layout (the carpet that is ordered, red frame, cut tiles included);
        // unchecked: only the part inside the room's own shape
        const id = 'cfp-lclip-' + (this._clipSeq = (this._clipSeq || 0) + 1);
        const cp = add('clipPath', { id }, add('defs', {}));
        if (this.$('layout-clip-box').checked) {
          const [bx, by] = view.toV([lay.box.x0, lay.box.y1]);
          add('rect', { x: bx, y: by, width: lay.box.x1 - lay.box.x0, height: lay.box.y1 - lay.box.y0 }, cp);
        } else {
          add('polygon', { points: room.vertices.map((v) => view.toV(v).join(',')).join(' ') }, cp);
        }
        parent.setAttribute('clip-path', 'url(#' + id + ')');
        add('image', { href: this._designUrl, x: vx, y: vy, width: w, height: h, preserveAspectRatio: 'none',
          opacity: (parseFloat(this.$('layout-dopacity').value) || 80) / 100 }, parent);
      }
      this.$('layout-dscale').value = Math.round(this._imgScale() * 100);
      this.$('layout-dscale-val').textContent = this.$('layout-dscale').value;
      this.$('layout-dopacity-val').textContent = this.$('layout-dopacity').value;
      const showGrid = this.$('layout-show-grid').checked;
      if (showGrid && lay.box) {
        lay.tiles.forEach((t) => {
          const [vx, vy] = view.toV([t.x, t.y + TILE]);
          add('rect', { x: vx, y: vy, width: TILE, height: TILE, fill: t.cut ? '#ef4444' : 'none', 'fill-opacity': t.cut ? 0.14 : 0,
            stroke: '#ef4444', 'stroke-width': 1.2, 'vector-effect': 'non-scaling-stroke' });
        });
        const [bx, by] = view.toV([lay.box.x0, lay.box.y1]);
        add('rect', { x: bx, y: by, width: lay.box.x1 - lay.box.x0, height: lay.box.y1 - lay.box.y0, fill: 'none',
          stroke: '#dc2626', 'stroke-width': 3, 'vector-effect': 'non-scaling-stroke' });
      }
      if (lay.box) {
        const rb0 = CADParser.bounds(room.vertices), rcx = (rb0.minX + rb0.maxX) / 2, rcy = (rb0.minY + rb0.maxY) / 2;
        // the room's own centre (fixed: it does not move with the layout, so the layout can be aligned to it): a bold cross, a ring, a dot and a label (drawn in mm, sized to the layout)
        const [ccx, ccy] = view.toV([rcx, rcy]);
        const arm = Math.max(300, Math.min(lay.box.x1 - lay.box.x0, lay.box.y1 - lay.box.y0) * 0.12), rr = arm * 0.45;
        const red = { stroke: '#dc2626', 'vector-effect': 'non-scaling-stroke', fill: 'none' };
        add('path', Object.assign({ d: 'M' + (ccx - arm) + ' ' + ccy + 'H' + (ccx + arm) + 'M' + ccx + ' ' + (ccy - arm) + 'V' + (ccy + arm), 'stroke-width': 4, stroke: '#ffffff' }, { 'vector-effect': 'non-scaling-stroke', fill: 'none' }));
        add('path', Object.assign({ d: 'M' + (ccx - arm) + ' ' + ccy + 'H' + (ccx + arm) + 'M' + ccx + ' ' + (ccy - arm) + 'V' + (ccy + arm), 'stroke-width': 2.5 }, red));
        add('circle', Object.assign({ cx: ccx, cy: ccy, r: rr, 'stroke-width': 2.5 }, red));
        add('circle', { cx: ccx, cy: ccy, r: rr * 0.3, fill: '#dc2626' });
        const lab = add('text', { x: ccx + rr * 1.2, y: ccy - rr * 1.2, fill: '#dc2626', stroke: '#ffffff', 'stroke-width': 3, 'paint-order': 'stroke',
          'font-size': Math.max(160, arm * 0.7), 'font-weight': 'bold', 'font-family': 'sans-serif' });
        lab.textContent = 'センター';
      }
      // while dragging, keep the text as it is (a longer text would push the view down under the pointer)
      if (previewOffset) return;
      const off = this.gridOffsets[this.roomIndex] || { x: 0, y: 0 };
      this.$('layout-note').textContent = lay.box
        ? (this._isSample() ? '仕様サイズのサンプルの部屋（図面なし）の割付（50cm角・部屋の端から）：横 ' : '割付（50cm角・部屋の中心から）：横 ') + lay.cols + ' 枚 × 縦 ' + lay.rows + ' 枚 ＝ 見積もりサイズ ' + fmtMm(lay.cols * TILE) + ' × ' + fmtMm(lay.rows * TILE) + ' mm'
          + '。部屋に敷く枚数 ' + lay.count + ' 枚（うち端で切る ' + lay.cut + ' 枚、薄い赤）。'
          + (off.x || off.y ? '割付のずらし：横 ' + Math.round(off.x) + ' mm・縦 ' + Math.round(off.y) + ' mm。' : '')
          + (hasDesign ? '' : '①でカーペット用のデザイン画像を処理すると、変換画像を重ねて表示します。')
          + (hasDesign && (this.design.x || this.design.y || this._imgScale() !== 1) ? '変換画像：大きさ ' + Math.round(this._imgScale() * 100) + '%・ずらし 横 ' + Math.round(this.design.x) + ' mm／縦 ' + Math.round(this.design.y) + ' mm。' : '')
        : '';
    }

    // where the converted carpet image sits (mm, Y up): centred on the tile layout, then shifted / scaled
    _designRect(lay, src, preview) {
      const d = preview || this.design;
      // sized and placed from the layout at its home position (no shift): moving the layout frame
      // never changes the image's size, aspect ratio or position
      const room = this.data && this.data.rooms[this.roomIndex];
      const home = (room && this._tileLayout(room, this._homeOffset(room)).box) || lay.box;
      // the converted image is the carpet size plus a margin (5 mm) on every side
      const bl = (window.cfpCarpetBleed || 0) * 2;
      const w = (home.x1 - home.x0 + bl) * d.scale, h = (home.y1 - home.y0 + bl) * d.scale;
      const cx = (home.x0 + home.x1) / 2 + d.x, cy = (home.y0 + home.y1) / 2 + d.y;
      return { x0: cx - w / 2, y0: cy - h / 2, x1: cx + w / 2, y1: cy + h / 2, w, h };
    }

    // Saves the layout as a layered PSD: 元図面 / 部屋 / 画像 / 割付 (RGB, the real size in mm).
    async _saveLayoutPsd() {
      const room = this.data && this.data.rooms[this.roomIndex];
      if (!room) return;
      const btn = this.$('layout-psd');
      btn.disabled = true;
      this.status('割付のPSDを作成しています…', 'loading');
      try {
        const view = this._layoutView();
        const lay = this._tileLayout(room);
        const dsrc = document.getElementById('cc-reduced');
        const hasDesign = !!(dsrc && dsrc.width && canvasHasContent(dsrc));
        // the area to save (view mm): the layout, the room and the image, with a margin
        const pts = room.vertices.map(view.toV);
        if (lay.box) [[lay.box.x0, lay.box.y0], [lay.box.x1, lay.box.y1]].forEach((p) => pts.push(view.toV(p)));
        // the image is saved only within the tile layout, so it does not widen the saved area
        const dr = hasDesign && lay.box ? this._designRect(lay, dsrc) : null;
        const pad = 300;
        const rx0 = Math.min(...pts.map((p) => p[0])) - pad, ry0 = Math.min(...pts.map((p) => p[1])) - pad;
        const rw = Math.max(...pts.map((p) => p[0])) + pad - rx0, rh = Math.max(...pts.map((p) => p[1])) + pad - ry0;
        const k = Math.min(1, 8000 / Math.max(rw, rh)); // px per mm (1px = 1mm up to 8,000 px)
        const Wpx = Math.max(1, Math.round(rw * k)), Hpx = Math.max(1, Math.round(rh * k));
        // each layer is a draw function in view mm; the PSD is made 256 rows at a time (little memory)
        const inMm = (x) => x.transform(k, 0, 0, k, -rx0 * k, -ry0 * k);
        const px = 1 / k; // one pixel in mm
        const path = (x, poly) => { x.beginPath(); poly.forEach((p, i) => (i ? x.lineTo(p[0], p[1]) : x.moveTo(p[0], p[1]))); x.closePath(); };

        // ① 元図面: the drawing picture, or the outlines of a DXF / JSON drawing
        const planImg = view.img ? await new Promise((res, rej) => { const im = new Image(); im.onload = () => res(im); im.onerror = rej; im.src = view.img; }) : null;
        const io = this.imgOffset || { x: 0, y: 0 };
        const draw1 = (x) => {
          inMm(x);
          if (planImg) { x.drawImage(planImg, io.x, io.y, view.W, view.H); return; }
          x.strokeStyle = '#3f3f46';
          x.lineWidth = 2 * px;
          this.data.rooms.forEach((r) => { path(x, r.vertices.map(view.toV)); x.stroke(); });
        };
        // ② 部屋: every room, the chosen one in blue, with its name
        const rb = CADParser.bounds(room.vertices);
        const [lx, ly] = view.toV([(rb.minX + rb.maxX) / 2, (rb.minY + rb.maxY) / 2]);
        const draw2 = (x) => {
          inMm(x);
          this.data.rooms.forEach((r, i) => {
            const sel = i === this.roomIndex;
            path(x, r.vertices.map(view.toV));
            x.fillStyle = sel ? 'rgba(37,99,235,0.22)' : 'rgba(161,161,170,0.18)';
            x.fill();
            x.strokeStyle = sel ? '#2563eb' : '#a1a1aa';
            x.lineWidth = (sel ? 4 : 2) * px;
            x.stroke();
          });
          x.fillStyle = '#1e3a8a';
          x.font = 'bold ' + Math.max(120, Math.min(rb.maxX - rb.minX, rb.maxY - rb.minY) / 12) + 'px sans-serif';
          x.textAlign = 'center';
          x.textBaseline = 'middle';
          x.fillText(room.name || '部屋', lx, ly);
        };
        // ③ 画像: the converted carpet image where it was placed, only inside the tile layout (the carpet that is ordered)
        const draw3 = (x) => {
          if (!dr) return;
          inMm(x);
          const [vx, vy] = view.toV([dr.x0, dr.y1]);
          const [bx, by] = view.toV([lay.box.x0, lay.box.y1]);
          x.beginPath();
          x.rect(bx, by, lay.box.x1 - lay.box.x0, lay.box.y1 - lay.box.y0);
          x.clip();
          x.imageSmoothingEnabled = false; // keep the reduced colours crisp
          x.drawImage(dsrc, vx, vy, dr.w, dr.h);
        };
        // ④ 割付: the 500 mm tiles in red (cut tiles light red), the outline
        const draw4 = (x) => {
          if (!lay.box) return;
          inMm(x);
          lay.tiles.forEach((t) => {
            const [vx, vy] = view.toV([t.x, t.y + TILE]);
            if (t.cut) { x.fillStyle = 'rgba(239,68,68,0.14)'; x.fillRect(vx, vy, TILE, TILE); }
            x.strokeStyle = '#ef4444';
            x.lineWidth = 2 * px;
            x.strokeRect(vx, vy, TILE, TILE);
          });
          const [bx, by] = view.toV([lay.box.x0, lay.box.y1]);
          x.strokeStyle = '#dc2626';
          x.lineWidth = 5 * px;
          x.strokeRect(bx, by, lay.box.x1 - lay.box.x0, lay.box.y1 - lay.box.y0);
        };
        // ⑤ センター: the room's own centre, on its own layer (fixed, does not move with the layout)
        const draw5 = (x) => {
          if (!lay.box) return;
          inMm(x);
          const [cx, cy] = view.toV([(rb.minX + rb.maxX) / 2, (rb.minY + rb.maxY) / 2]);
          const arm = Math.max(300, Math.min(lay.box.x1 - lay.box.x0, lay.box.y1 - lay.box.y0) * 0.12), rr = arm * 0.45;
          const cross = () => { x.beginPath(); x.moveTo(cx - arm, cy); x.lineTo(cx + arm, cy); x.moveTo(cx, cy - arm); x.lineTo(cx, cy + arm); x.stroke(); };
          x.strokeStyle = '#ffffff'; x.lineWidth = 9 * px; cross();
          x.strokeStyle = '#dc2626'; x.lineWidth = 5 * px; cross();
          x.beginPath(); x.arc(cx, cy, rr, 0, Math.PI * 2); x.stroke();
          x.fillStyle = '#dc2626'; x.beginPath(); x.arc(cx, cy, rr * 0.3, 0, Math.PI * 2); x.fill();
          x.font = 'bold ' + Math.max(160, arm * 0.7) + 'px sans-serif'; x.textAlign = 'left'; x.textBaseline = 'alphabetic';
          x.lineWidth = 6 * px; x.strokeStyle = '#ffffff'; x.strokeText('センター', cx + rr * 1.2, cy - rr * 1.2);
          x.fillText('センター', cx + rr * 1.2, cy - rr * 1.2);
        };
        const name = (room.name || '部屋');
        const parts = await writeBandedPsd(Wpx, Hpx, [
          { name: '①元図面', draw: draw1 },
          { name: '②部屋（' + name + '）', draw: draw2 },
          { name: '③画像（変換画像・大きさ' + Math.round(this._imgScale() * 100) + '%・割付の範囲）', draw: draw3 },
          { name: '④割付（50cm角 横' + lay.cols + '×縦' + lay.rows + '枚）', draw: draw4 },
          { name: '⑤センター（部屋の中心・固定）', draw: draw5 },
        ], 25.4 * k, (v) => this.status('割付のPSDを作成しています… ' + Math.round(v * 100) + '%', 'loading'));
        const blob = new Blob(parts, { type: 'image/vnd.adobe.photoshop' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        // ASCII file name (some browsers drop a Japanese one); the room name is in the layer names
        a.download = 'carpet_layout_room' + (this.roomIndex + 1) + '_' + lay.cols + 'x' + lay.rows + '_' + Date.now() + '.psd';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 2000);
        this.status('割付をPSDで保存しました（' + Wpx + ' × ' + Hpx + ' px、1px = ' + (1 / k).toFixed(2) + ' mm。レイヤー：元図面／部屋／画像／割付／センター）。', 'success');
      } catch (err) {
        this.status('エラー: PSDを作成できませんでした（' + err.message + '）', 'error');
        console.error(err);
      } finally {
        btn.disabled = false;
      }
    }

    _layoutDrag(tool, d, phase) {
      if (tool === 'grid') {
        // the layout moves in 250 mm steps (half a tile) around the room centre
        const STEP = TILE / 2;
        const base = this.gridOffsets[this.roomIndex] || { x: 0, y: 0 };
        let next;
        if (phase === 'nudge') {
          const k = Math.abs(d.dx || d.dy) >= 100 ? 2 : 1; // Shift: 500 mm
          next = { x: base.x + Math.sign(d.dx) * STEP * k, y: base.y - Math.sign(d.dy) * STEP * k };
        } else {
          next = { x: Math.round((base.x + d.dx) / STEP) * STEP, y: Math.round((base.y - d.dy) / STEP) * STEP }; // the view's Y points down
        }
        if (phase === 'move') { this._renderLayout({ grid: next }); return; }
        this.gridOffsets[this.roomIndex] = next;
        this._layoutCommit();
      } else if (tool === 'design') {
        const next = { x: this.design.x + d.dx, y: this.design.y - d.dy, scale: this.design.scale };
        if (phase === 'move') { this._renderLayout({ design: next }); return; }
        this.design = next;
        this._renderLayout();
      }
    }

    // the layout changed: redraw, and update the estimate with the new tile counts
    _layoutCommit() {
      const room = this.data && this.data.rooms[this.roomIndex];
      if (!room) return;
      const kind = this.roomKinds[this.roomIndex] || {};
      const prov = kind.manual ? !!this.backdrop && !this.backdrop.calibrated : !!this.raster && !this.raster.calibrated;
      if (this.fromFile && !prov && this.$('apply-size').checked) this.reflectSize(room);
      this._renderLayout();
    }

    /**
     * On the wallpaper/carpet simulator page, shows the converted (reduced-colour) floor and
     * wallpaper designs from #cc-reduced / #cw-reduced on the 3D floor and walls.
     * fromEvent: the tool re-processed a design, so an emptied canvas clears the texture too.
     */
    // The 3D floor texture spans the room's bounding box. The carpet image is put there at its real size and
    // place (as on the tile layout view: the layout box + the 5 mm margin, moved / scaled there), so its
    // aspect ratio is kept whatever the room's shape. Where the image does not reach, the floor is white.
    _floorCanvas(c) {
      const room = this.data && this.data.rooms[this.roomIndex];
      if (!room) return this._noBleed(c);
      const lay = this._tileLayout(room);
      if (!lay.box) return this._noBleed(c);
      const b = CADParser.bounds(room.vertices);
      const bw = b.maxX - b.minX, bh = b.maxY - b.minY;
      if (!(bw > 0) || !(bh > 0)) return this._noBleed(c);
      const k = 2048 / Math.max(bw, bh); // px per mm
      const out = document.createElement('canvas');
      out.width = Math.max(1, Math.round(bw * k)); out.height = Math.max(1, Math.round(bh * k));
      const x = out.getContext('2d');
      x.fillStyle = '#ffffff';
      x.fillRect(0, 0, out.width, out.height);
      const dr = this._designRect(lay, c);
      x.imageSmoothingEnabled = false; // keep the reduced colours crisp
      x.drawImage(c, (dr.x0 - b.minX) * k, (b.maxY - dr.y1) * k, dr.w * k, dr.h * k);
      return out;
    }

    // a floor update shortly after the layout changed (the carpet moved, scaled or the layout frame moved)
    _floorSoon() {
      clearTimeout(this._floorTimer);
      this._floorTimer = setTimeout(() => {
        const floor = document.getElementById('cc-reduced');
        if (!this.renderer || !floor || !floor.width || !canvasHasContent(floor)) return;
        this.renderer.setFloorCanvas(this._floorCanvas(floor));
      }, 200);
    }

    // the converted carpet image has a 5 mm margin on every side; the 3D floor shows only the carpet itself
    _noBleed(c) {
      const B = window.cfpCarpetBleed || 0;
      const W = parseFloat((document.getElementById('cc-width') || {}).value) || 0;
      const H = parseFloat((document.getElementById('cc-height') || {}).value) || 0;
      if (!B || !W || !H) return c;
      const kx = c.width / (W + B * 2), ky = c.height / (H + B * 2);
      const out = document.createElement('canvas');
      out.width = Math.max(1, Math.round(W * kx)); out.height = Math.max(1, Math.round(H * ky));
      const ox = out.getContext('2d');
      ox.fillStyle = '#ffffff'; // the margin (no colour) looks white on the 3D floor
      ox.fillRect(0, 0, out.width, out.height);
      ox.drawImage(c, B * kx, B * ky, W * kx, H * ky, 0, 0, out.width, out.height);
      return out;
    }

    syncDesigns(fromEvent) {
      const floor = document.getElementById('cc-reduced');
      const wall = document.getElementById('cw-reduced');
      const note = this.$('design-note');
      if (!this.renderer || (!floor && !wall)) return;
      const hasFloor = !!floor && floor.width > 0 && floor.height > 0 && canvasHasContent(floor);
      const hasWall = !!wall && wall.width > 0 && wall.height > 0 && canvasHasContent(wall);
      if (hasFloor || fromEvent) this.renderer.setFloorCanvas(hasFloor ? this._floorCanvas(floor) : null);
      // wallpaper pattern: repeated at its real size, aspect ratio kept (setting of the tool's ② panel)
      const rep = window.cfpGetWallRepeat ? window.cfpGetWallRepeat() : null;
      if (hasWall && rep && rep.on) this.renderer.wallRepeat = { w: rep.mm * (rep.scale || 1), h: rep.mm * (rep.scale || 1) * wall.height / wall.width };
      else this.renderer.wallRepeat = null;
      this.renderer.wallSingle = !!(hasWall && rep && !rep.on);
      // the single picture also covers the print margins (top / bottom trim, side overlaps), as in the saved data
      const wp = window.cfpWallPrint;
      this.renderer.wallTrim = wp ? { side: wp.OVERLAP, top: wp.TRIM_TOP, bottom: wp.TRIM_BOTTOM } : null;
      this.renderer.wallShift = window.cfpWallShift || { x: 0, y: 0 };
      if (hasWall || fromEvent) this.renderer.setWallCanvas(hasWall ? wall : null);
      this.renderer.setWallRepeat(this.renderer.wallRepeat ? this.renderer.wallRepeat.w : 0, this.renderer.wallRepeat ? this.renderer.wallRepeat.h : 0,
        hasWall && rep && !rep.on ? wall.width / wall.height : 0, rep && rep.scale ? rep.scale : 1, rep && rep.mode ? rep.mode : null);
      this._thumb('src-floor', hasFloor ? floor : null);
      this._thumb('src-wall', hasWall ? wall : null);
      this.$('src-floor-text').textContent = hasFloor ? '適用中' : '未処理（① でデザイン画像を選ぶと適用されます）';
      this.$('src-wall-text').textContent = hasWall ? '適用中' : '未処理（② でデザイン画像を選ぶと適用されます）';
      // 壁紙は既定で減色しない（CMYK印刷）。「壁紙を減色する」のときだけ減色イメージと表示する
      const wallReduced = !!(document.getElementById('cw-reduce-on') || {}).checked;
      const lbl = this.$('src-wall-label');
      if (lbl) lbl.textContent = wallReduced ? '② 壁紙用の減色イメージ' : '② 壁紙用のデザイン画像（元画像の色のまま）';
      if (!note) return;
      note.textContent = hasFloor && hasWall
        ? (wallReduced ? '床・壁とも、減色後のイメージ画像を3Dに適用しています。' : '床は①の減色後のイメージ、壁は②の壁紙用デザイン画像（元画像の色のまま）を3Dに適用しています。') : '';
    }

    // small preview of the picture that is applied (nearest-neighbour, so the 1 px pattern stays crisp)
    _thumb(name, src) {
      const c = this.$(name);
      const x = c.getContext('2d');
      x.clearRect(0, 0, c.width, c.height);
      x.fillStyle = '#27272a';
      x.fillRect(0, 0, c.width, c.height);
      if (!src) return;
      const k = Math.min(c.width / src.width, c.height / src.height);
      const w = Math.max(1, Math.round(src.width * k)), h = Math.max(1, Math.round(src.height * k));
      x.imageSmoothingEnabled = false;
      x.drawImage(src, (c.width - w) / 2, (c.height - h) / 2, w, h);
    }

    _texture(e, kind) {
      const f = e.target.files[0];
      if (!f) return;
      if (!this.renderer) return this.status('先に間取りを読み込んでください。', 'error');
      const url = URL.createObjectURL(f);
      if (kind === 'floor') this.renderer.setFloorTexture(url);
      else this.renderer.setWallTexture(url);
    }

    saveJSON() {
      if (!this.data) return;
      const blob = new Blob([JSON.stringify(this.data, (k, v) => (k === 'dataUrl' ? undefined : v), 2)], { type: 'application/json' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'floor-plan.json';
      a.click();
      URL.revokeObjectURL(a.href);
    }

    status(msg, type) {
      const s = this.$('status');
      s.textContent = msg;
      s.className = 'cfp-status cfp-status-' + type;
    }
  }

  function init() {
    document.querySelectorAll('.cad-floor-plan-widget').forEach((el) => {
      if (!el.cfpWidget) el.cfpWidget = new CADFloorPlanWidget(el);
    });
  }

  window.initCADFloorPlan = init;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
