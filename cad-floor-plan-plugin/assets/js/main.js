/**
 * CAD Floor Plan widget: wires the shortcode markup to CADParser and ThreeRoomRenderer.
 * Each widget instance is available as element.cfpWidget (e.g. widget.renderer.setFloorTexture(url)).
 */
(function () {
  'use strict';

  const SAMPLE = {
    rooms: [{
      id: 'sample',
      name: 'サンプル（L字の部屋）',
      vertices: [[0, 0], [6000, 0], [6000, 2500], [3500, 2500], [3500, 5000], [0, 5000]],
    }],
    metadata: { source: 'sample' },
  };

  const SVG_NS = 'http://www.w3.org/2000/svg';
  const fmtM = (mm) => (mm / 1000).toFixed(1);
  const fmtMm = (mm) => Math.round(mm).toLocaleString('ja-JP');
  const TILE = 500; // carpet tile (mm)

  // PSD writer (ag-psd), shared with the simulator page
  function loadAgPsd() {
    if (window.agPsd) return Promise.resolve(window.agPsd);
    if (window.__cocoonAgPsdPromise) return window.__cocoonAgPsdPromise;
    window.__cocoonAgPsdPromise = new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/ag-psd/dist/bundle.js';
      s.onload = () => (window.agPsd ? resolve(window.agPsd) : reject(new Error('ag-psd の読み込みエラー')));
      s.onerror = () => { window.__cocoonAgPsdPromise = null; reject(new Error('PSDライブラリの読み込みに失敗しました')); };
      document.head.appendChild(s);
    });
    return window.__cocoonAgPsdPromise;
  }

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
      if (el.dataset.sample !== 'none') this.loadData(SAMPLE, 'サンプル', { fromFile: false });
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
        reload();
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
      this.$('trace-close').addEventListener('click', () => { this.$('trace').hidden = true; });
      const tool = (name) => {
        if (!this.tracer) return;
        this.tracer.setTool(name);
        ['pan', 'rect', 'poly', 'move', 'edit'].forEach((t) => this.$('tool-' + t).classList.toggle('is-on', t === name));
      };
      ['pan', 'rect', 'poly', 'move', 'edit'].forEach((t) => this.$('tool-' + t).addEventListener('click', () => tool(t)));
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
        ['pan', 'rect', 'move', 'edit'].forEach((t) => this.$('elev-' + t).classList.toggle('is-on', t === name));
      };
      ['pan', 'rect', 'move', 'edit'].forEach((t) => this.$('elev-' + t).addEventListener('click', () => etool(t)));
      this.$('elev-fit').addEventListener('click', () => this.elevTracer && this.elevTracer.fit());
      this.$('elev-cloth-only').addEventListener('change', () => this._renderElevList());
      const setTotal = (fn) => { this._elevAll().forEach((w) => { w.inTotal = fn(w); }); this._renderElevList(); };
      this.$('total-all').addEventListener('click', () => setTotal(() => true));
      this.$('total-cloth').addEventListener('click', () => setTotal((w) => !!w.cloth || !!w.custom));
      this.$('total-none').addEventListener('click', () => setTotal(() => false));
      // carpet tile layout
      const ltool = (name) => {
        if (!this.layoutTracer) return;
        this.layoutTracer.setTool(name);
        ['pan', 'grid', 'design'].forEach((t) => this.$('layout-' + t).classList.toggle('is-on', t === name));
      };
      ['pan', 'grid', 'design'].forEach((t) => this.$('layout-' + t).addEventListener('click', () => ltool(t)));
      this.$('layout-dscale').addEventListener('input', () => {
        this.design.scale = (parseFloat(this.$('layout-dscale').value) || 100) / 100;
        this._renderLayout();
      });
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
        this._layoutCommit();
      });
      this.$('layout-show-img').addEventListener('change', () => this._renderLayout());
      this.$('layout-show-grid').addEventListener('change', () => this._renderLayout());
      this.$('sample-btn').addEventListener('click', () => this.loadData(SAMPLE, 'サンプル', { fromFile: false }));
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
        this.$('layout-dscale').value = Math.round(this.design.scale * 100);
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
        const typed = parseFloat(this.$('scale').value) || 1;
        const scaleN = res.walls.length ? res.scale : (typed > 1 ? typed : (res.scale || 1));
        let bd = st && st.backdrop;
        if (!bd || bd.scaleN !== scaleN) {
          this.status('展開図を表示しています…', 'loading');
          bd = await CADParser.renderPdfBackdrop(d.file, { scale: scaleN });
          bd.scaleN = scaleN;
        }
        this._elevFile = d.file;
        this.elev = { scale: scaleN, walls: res.walls, backdrop: bd, pageW: res.pageW, pageH: res.pageH, custom: st ? st.custom : [] };
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
            custom: (st.custom || []).map((c) => ({ id: c.id, name: c.name, width: c.width, height: c.height, poly: c.poly, inTotal: !!c.inTotal })),
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
            ['trace', 'raster', 'trace-open'].forEach((k) => { this.$(k).hidden = true; });
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
        + (this.drawings.some((d) => d.canSwitch) ? '\n違っていれば、図面名の横の「⇄」で入れ替えられます。' : '');
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
          chip.className = 'cfp-dw-chip' + (d.file === active && (type === 'elev' || this.fromFile) ? ' is-active' : '');
          const b = document.createElement('button');
          b.type = 'button';
          b.textContent = d.file.name;
          b.title = d.file.name + (type === 'elev' ? '（展開図）' : '（平面図）') + (d.reason ? '\n判定: ' + d.reason : '');
          b.addEventListener('click', () => (type === 'elev' ? this.showElevation(d) : this.showPlan(d)));
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
            sw.textContent = '⇄';
            sw.title = type === 'elev' ? '平面図（床）として読み直す' : '展開図（壁）として読み直す';
            sw.setAttribute('aria-label', d.file.name + ' を' + sw.title);
            sw.addEventListener('click', () => this.switchDrawing(d));
            chip.append(b, sw, x);
          } else chip.append(b, x);
          row.appendChild(chip);
        });
        box.appendChild(row);
      });
    }

    _elevAll() {
      return this.elev ? this.elev.walls.concat(this.elev.custom) : [];
    }

    _initElevTracer() {
      const bd = this.elev.backdrop;
      if (!this.elevTracer) {
        this.elevTracer = new RoomTracer(this.$('elev-svg'), { onCommit: (poly) => this._commitElevRect(poly), onMove: (i, poly) => this._moveElevRect(i, poly) });
        this.elevTracer.ortho = true; // corners line up with their neighbours when reshaping
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
    }

    // The walls ticked on every loaded elevation sheet: total width, area and wall paper strips (910 mm rolls).
    _updateWallTotal() {
      // 910mm幅のロールを左右10mmずつ重ねて貼る（1巾が受け持つのは890mm）。1巾の長さ = 壁の高さ + 上下100mm
      const WP = window.cfpWallPrint || { STEP: 890, TRIM_TOP: 100, TRIM_BOTTOM: 100 };
      const ROLL = WP.STEP, TRIM = WP.TRIM_TOP + WP.TRIM_BOTTOM;
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
          const n = Math.ceil((w.width - 5) / ROLL);
          strips += n;
          len += n * (w.height + TRIM) / 1000;
        });
        const files = [...new Set(picked.map((p) => p.file))];
        total = {
          count: picked.length, widthMm: Math.round(width), areaM2: Math.round(area * 100) / 100,
          strips, lengthM: Math.round(len * 10) / 10, files,
        };
        total.text = picked.length + '面（' + files.join('・') + '）幅の合計 ' + fmtMm(total.widthMm) + ' mm／面積の合計 ' + total.areaM2
          + ' ㎡／910mm幅のロール（左右10mmずつ重ね）で ' + strips + ' 巾・長さの合計 約 ' + total.lengthM + ' m（1巾 = 壁の高さ + 上下100mmずつ）';
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
      if (wmm < 100 || hmm < 100) return;
      const n = this.elev.custom.length + 1;
      this.elev.custom.push({ id: 'custom_' + n, name: '指定した壁紙の範囲 ' + n, width: Math.round(wmm), height: Math.round(hmm), custom: true, poly, cloth: null });
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
    }

    // ----------------------------------------------- carpet tile layout (割付)
    // 500 x 500 mm tiles centred on the room (plus the layout's shift). Tiles that touch the room count,
    // the cut ones at the edges as whole tiles. Returns the tiles in mm and the counts.
    _tileLayout(room, offset) {
      const b = CADParser.bounds(room.vertices);
      const off = offset || this.gridOffsets[this.roomIndex] || { x: 0, y: 0 };
      const cx = (b.minX + b.maxX) / 2 + off.x, cy = (b.minY + b.maxY) / 2 + off.y;
      // a tile edge on the centre line (an even layout around the centre)
      const ox = cx - Math.ceil((cx - b.minX) / TILE + 1) * TILE, oy = cy - Math.ceil((cy - b.minY) / TILE + 1) * TILE;
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

    _renderLayout(previewOffset) {
      const panel = this.$('layout');
      const room = this.data && this.data.rooms[this.roomIndex];
      if (!this.fromFile || !room) { panel.hidden = true; return; }
      panel.hidden = false;
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
        if (this.$('layout-clip-box').checked) {
          // show the image only inside the tile layout (the carpet that is ordered)
          const id = 'cfp-lclip-' + (this._clipSeq = (this._clipSeq || 0) + 1);
          const cp = add('clipPath', { id }, add('defs', {}));
          const [bx, by] = view.toV([lay.box.x0, lay.box.y1]);
          add('rect', { x: bx, y: by, width: lay.box.x1 - lay.box.x0, height: lay.box.y1 - lay.box.y0 }, cp);
          parent.setAttribute('clip-path', 'url(#' + id + ')');
        }
        add('image', { href: this._designUrl, x: vx, y: vy, width: w, height: h, preserveAspectRatio: 'none',
          opacity: (parseFloat(this.$('layout-dopacity').value) || 80) / 100 }, parent);
      }
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
        // the centre of the layout
        const [ccx, ccy] = view.toV([lay.cx, lay.cy]);
        add('path', { d: 'M' + (ccx - 250) + ' ' + ccy + 'H' + (ccx + 250) + 'M' + ccx + ' ' + (ccy - 250) + 'V' + (ccy + 250),
          stroke: '#dc2626', 'stroke-width': 2, 'vector-effect': 'non-scaling-stroke', fill: 'none' });
      }
      // while dragging, keep the text as it is (a longer text would push the view down under the pointer)
      if (previewOffset) return;
      const off = this.gridOffsets[this.roomIndex] || { x: 0, y: 0 };
      this.$('layout-note').textContent = lay.box
        ? '割付（50cm角・部屋の中心から）：横 ' + lay.cols + ' 枚 × 縦 ' + lay.rows + ' 枚 ＝ 見積もりサイズ ' + fmtMm(lay.cols * TILE) + ' × ' + fmtMm(lay.rows * TILE) + ' mm'
          + '。部屋に敷く枚数 ' + lay.count + ' 枚（うち端で切る ' + lay.cut + ' 枚、薄い赤）。'
          + (off.x || off.y ? '割付のずらし：横 ' + Math.round(off.x) + ' mm・縦 ' + Math.round(off.y) + ' mm。' : '')
          + (hasDesign ? '' : '①でカーペット用のデザイン画像を処理すると、変換画像を重ねて表示します。')
          + (hasDesign && (this.design.x || this.design.y || this.design.scale !== 1) ? '変換画像：大きさ ' + Math.round(this.design.scale * 100) + '%・ずらし 横 ' + Math.round(this.design.x) + ' mm／縦 ' + Math.round(this.design.y) + ' mm。' : '')
        : '';
    }

    // where the converted carpet image sits (mm, Y up): centred on the tile layout, then shifted / scaled
    _designRect(lay, src, preview) {
      const d = preview || this.design;
      const w = (lay.box.x1 - lay.box.x0) * d.scale, h = w * src.height / src.width;
      const cx = (lay.box.x0 + lay.box.x1) / 2 + d.x, cy = (lay.box.y0 + lay.box.y1) / 2 + d.y;
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
        const agPsd = await loadAgPsd();
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
        const layer = () => {
          const c = document.createElement('canvas');
          c.width = Wpx;
          c.height = Hpx;
          const x = c.getContext('2d');
          x.setTransform(k, 0, 0, k, -rx0 * k, -ry0 * k); // draw in view mm
          return { c, x };
        };
        const px = 1 / k; // one pixel in mm
        const path = (x, poly) => { x.beginPath(); poly.forEach((p, i) => (i ? x.lineTo(p[0], p[1]) : x.moveTo(p[0], p[1]))); x.closePath(); };

        // ① 元図面: the drawing picture, or the outlines of a DXF / JSON drawing
        const L1 = layer();
        if (view.img) {
          const img = await new Promise((res, rej) => { const im = new Image(); im.onload = () => res(im); im.onerror = rej; im.src = view.img; });
          L1.x.drawImage(img, this.imgOffset.x, this.imgOffset.y, view.W, view.H);
        } else {
          L1.x.strokeStyle = '#3f3f46';
          L1.x.lineWidth = 2 * px;
          this.data.rooms.forEach((r) => { path(L1.x, r.vertices.map(view.toV)); L1.x.stroke(); });
        }
        // ② 部屋: every room, the chosen one in blue, with its name
        const L2 = layer();
        this.data.rooms.forEach((r, i) => {
          const sel = i === this.roomIndex;
          path(L2.x, r.vertices.map(view.toV));
          L2.x.fillStyle = sel ? 'rgba(37,99,235,0.22)' : 'rgba(161,161,170,0.18)';
          L2.x.fill();
          L2.x.strokeStyle = sel ? '#2563eb' : '#a1a1aa';
          L2.x.lineWidth = (sel ? 4 : 2) * px;
          L2.x.stroke();
        });
        const rb = CADParser.bounds(room.vertices);
        const [lx, ly] = view.toV([(rb.minX + rb.maxX) / 2, (rb.minY + rb.maxY) / 2]);
        L2.x.fillStyle = '#1e3a8a';
        L2.x.font = 'bold ' + Math.max(120, Math.min(rb.maxX - rb.minX, rb.maxY - rb.minY) / 12) + 'px sans-serif';
        L2.x.textAlign = 'center';
        L2.x.textBaseline = 'middle';
        L2.x.fillText(room.name || '部屋', lx, ly);
        // ③ 画像: the converted carpet image where it was placed
        const L3 = layer();
        if (dr) {
          const [vx, vy] = view.toV([dr.x0, dr.y1]);
          {
            // always only inside the tile layout (the carpet that is ordered)
            const [bx, by] = view.toV([lay.box.x0, lay.box.y1]);
            L3.x.beginPath();
            L3.x.rect(bx, by, lay.box.x1 - lay.box.x0, lay.box.y1 - lay.box.y0);
            L3.x.clip();
          }
          L3.x.imageSmoothingEnabled = false; // keep the reduced colours crisp
          L3.x.drawImage(dsrc, vx, vy, dr.w, dr.h);
        }
        // ④ 割付: the 500 mm tiles in red (cut tiles light red), the outline and the centre
        const L4 = layer();
        if (lay.box) {
          lay.tiles.forEach((t) => {
            const [vx, vy] = view.toV([t.x, t.y + TILE]);
            if (t.cut) { L4.x.fillStyle = 'rgba(239,68,68,0.14)'; L4.x.fillRect(vx, vy, TILE, TILE); }
            L4.x.strokeStyle = '#ef4444';
            L4.x.lineWidth = 2 * px;
            L4.x.strokeRect(vx, vy, TILE, TILE);
          });
          const [bx, by] = view.toV([lay.box.x0, lay.box.y1]);
          L4.x.strokeStyle = '#dc2626';
          L4.x.lineWidth = 5 * px;
          L4.x.strokeRect(bx, by, lay.box.x1 - lay.box.x0, lay.box.y1 - lay.box.y0);
          const [cx, cy] = view.toV([lay.cx, lay.cy]);
          L4.x.lineWidth = 3 * px;
          L4.x.beginPath(); L4.x.moveTo(cx - 250, cy); L4.x.lineTo(cx + 250, cy); L4.x.moveTo(cx, cy - 250); L4.x.lineTo(cx, cy + 250); L4.x.stroke();
        }
        // the image is saved fully opaque (the 濃さ slider is only for looking at the drawing underneath)
        const opacity = 1;
        const comp = document.createElement('canvas');
        comp.width = Wpx;
        comp.height = Hpx;
        const cx2 = comp.getContext('2d');
        cx2.fillStyle = '#ffffff';
        cx2.fillRect(0, 0, Wpx, Hpx);
        cx2.drawImage(L1.c, 0, 0);
        cx2.drawImage(L2.c, 0, 0);
        cx2.globalAlpha = opacity;
        cx2.drawImage(L3.c, 0, 0);
        cx2.globalAlpha = 1;
        cx2.drawImage(L4.c, 0, 0);
        const name = (room.name || '部屋');
        const dpi = 25.4 * k;
        const psd = {
          width: Wpx, height: Hpx, canvas: comp,
          imageResources: { resolutionInfo: { horizontalResolution: dpi, horizontalResolutionUnit: 'PPI', widthUnit: 'Centimeters', verticalResolution: dpi, verticalResolutionUnit: 'PPI', heightUnit: 'Centimeters' } },
          children: [
            { name: '①元図面', canvas: L1.c },
            { name: '②部屋（' + name + '）', canvas: L2.c },
            { name: '③画像（変換画像・大きさ' + Math.round(this.design.scale * 100) + '%・割付の範囲）', canvas: L3.c, hidden: !dr },
            { name: '④割付（50cm角 横' + lay.cols + '×縦' + lay.rows + '枚）', canvas: L4.c },
          ],
        };
        const buf = agPsd.writePsd(psd, { generateThumbnail: true });
        const blob = new Blob([buf], { type: 'image/vnd.adobe.photoshop' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        // ASCII file name (some browsers drop a Japanese one); the room name is in the layer names
        a.download = 'carpet_layout_room' + (this.roomIndex + 1) + '_' + lay.cols + 'x' + lay.rows + '_' + Date.now() + '.psd';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 2000);
        this.status('割付をPSDで保存しました（' + Wpx + ' × ' + Hpx + ' px、1px = ' + (1 / k).toFixed(2) + ' mm。レイヤー：元図面／部屋／画像／割付）。', 'success');
      } catch (err) {
        this.status('エラー: PSDを作成できませんでした（' + err.message + '）', 'error');
        console.error(err);
      } finally {
        btn.disabled = false;
      }
    }

    _layoutDrag(tool, d, phase) {
      if (tool === 'grid') {
        const base = this.gridOffsets[this.roomIndex] || { x: 0, y: 0 };
        const next = { x: base.x + d.dx, y: base.y - d.dy }; // the view's Y points down
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
    syncDesigns(fromEvent) {
      const floor = document.getElementById('cc-reduced');
      const wall = document.getElementById('cw-reduced');
      const note = this.$('design-note');
      if (!this.renderer || (!floor && !wall)) return;
      const hasFloor = !!floor && floor.width > 0 && floor.height > 0 && canvasHasContent(floor);
      const hasWall = !!wall && wall.width > 0 && wall.height > 0 && canvasHasContent(wall);
      if (hasFloor || fromEvent) this.renderer.setFloorCanvas(hasFloor ? floor : null);
      // wallpaper pattern: repeated at its real size, aspect ratio kept (setting of the tool's ② panel)
      const rep = window.cfpGetWallRepeat ? window.cfpGetWallRepeat() : null;
      if (hasWall && rep && rep.on) this.renderer.wallRepeat = { w: rep.mm, h: rep.mm * wall.height / wall.width };
      else this.renderer.wallRepeat = null;
      this.renderer.wallSingle = !!(hasWall && rep && !rep.on);
      // the single picture also covers the print margins (top / bottom trim, side overlaps), as in the saved data
      const wp = window.cfpWallPrint;
      this.renderer.wallTrim = wp ? { side: wp.OVERLAP, top: wp.TRIM_TOP, bottom: wp.TRIM_BOTTOM } : null;
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
