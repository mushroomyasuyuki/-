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
        ['pan', 'rect', 'poly'].forEach((t) => this.$('tool-' + t).classList.toggle('is-on', t === name));
      };
      ['pan', 'rect', 'poly'].forEach((t) => this.$('tool-' + t).addEventListener('click', () => tool(t)));
      this.$('zoom-in').addEventListener('click', () => this.tracer && this.tracer.zoom(0.7));
      this.$('zoom-out').addEventListener('click', () => this.tracer && this.tracer.zoom(1.4));
      this.$('zoom-fit').addEventListener('click', () => this.tracer && this.tracer.fit());
      this.$('undo-pt').addEventListener('click', () => this.tracer && this.tracer.undoPoint());
      this.$('ortho').addEventListener('change', () => { if (this.tracer) this.tracer.ortho = this.$('ortho').checked; });
      this.$('hide-auto').addEventListener('change', () => {
        this.hideAuto = this.$('hide-auto').checked;
        this._afterRoomsChanged(0);
      });
      const etool = (name) => {
        if (!this.elevTracer) return;
        this.elevTracer.setTool(name);
        ['pan', 'rect'].forEach((t) => this.$('elev-' + t).classList.toggle('is-on', t === name));
      };
      ['pan', 'rect'].forEach((t) => this.$('elev-' + t).addEventListener('click', () => etool(t)));
      this.$('elev-fit').addEventListener('click', () => this.elevTracer && this.elevTracer.fit());
      this.$('elev-cloth-only').addEventListener('change', () => this._renderElevList());
      const setTotal = (fn) => { this._elevAll().forEach((w) => { w.inTotal = fn(w); }); this._renderElevList(); };
      this.$('total-all').addEventListener('click', () => setTotal(() => true));
      this.$('total-cloth').addEventListener('click', () => setTotal((w) => !!w.cloth || !!w.custom));
      this.$('total-none').addEventListener('click', () => setTotal(() => false));
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
        this.elevTracer = new RoomTracer(this.$('elev-svg'), { onCommit: (poly) => this._commitElevRect(poly) });
        this.elevTracer.ortho = false;
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
        if (w.poly) poly = w.poly;
        else poly = this._wallPoly(w);
        items.push({ poly, label: String(i + 1), manual: !!w.custom, selected: sel, color: sel ? '#2563eb' : (w.custom ? '#16a34a' : (w.cloth ? '#a855f7' : '#a1a1aa')) });
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
      const ROLL = 910, TRIM = 200;
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
          + ' ㎡／910mm幅のロールで ' + strips + ' 巾・長さの合計 約 ' + total.lengthM + ' m（1巾 = 壁の高さ + 裁断代200mm）';
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
        this.tracer = new RoomTracer(this.$('trace-svg'), { onCommit: (poly) => this._commitManual(poly) });
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
        selected: i === this.roomIndex,
      })));
    }

    _commitManual(poly) {
      this.manual.push({ poly, name: '指定した部屋 ' + (this.manual.length + 1) });
      this._afterRoomsChanged(this.roomKinds.length); // the new room is last
      const n = this.data.rooms.length;
      this.status('部屋を追加しました（部屋 ' + n + '）。続けて指定するか、一覧から部屋を選んでください。', 'success');
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
      // round up to the 500 mm tile, but ignore drawing noise of up to 10 mm
      const tiles = (mm) => Math.max(500, Math.ceil((mm - 10) / 500) * 500);
      const tileW = tiles(w);
      const tileH = tiles(h);
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
        + 'カーペットを ' + fmtMm(tileW) + '×' + fmtMm(tileH) + ' mm（500mm単位に切り上げ。10mm以下の端数は切り捨て）、'
        + (wallLocked
          ? 'お部屋パースの奥行を ' + fmtMm(applied['persp-floor-depth'] || h) + ' mm に設定しました（壁紙の幅・高さは、展開図で選んだ壁のサイズのままです）。'
          : 'お部屋パース・壁紙のサイズを 幅' + fmtMm(applied['persp-width'] || w)
            + '／奥行' + fmtMm(applied['persp-floor-depth'] || h)
            + '／壁の高さ' + fmtMm(applied['persp-wall-height'] || wallH) + ' mm に設定しました。');
      if (Math.abs(area - w * h) / (w * h) > 0.01) {
        text += ' ※四角でない部屋のため、見積もりは外接する四角（' + fmtArea(tileW * tileH) + '㎡）で計算されます（実際の床面積は ' + fmtArea(area) + '㎡）。';
      }
      if (clamped) text += ' ※お部屋パース・壁紙のサイズは入力欄の上限・下限に丸めました。';
      note.textContent = text;
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
      if (hasWall || fromEvent) this.renderer.setWallCanvas(hasWall ? wall : null);
      this.renderer.setWallRepeat(this.renderer.wallRepeat ? this.renderer.wallRepeat.w : 0, this.renderer.wallRepeat ? this.renderer.wallRepeat.h : 0,
        hasWall && rep && !rep.on ? wall.width / wall.height : 0, rep && rep.scale ? rep.scale : 1, rep && rep.mode ? rep.mode : null);
      this._thumb('src-floor', hasFloor ? floor : null);
      this._thumb('src-wall', hasWall ? wall : null);
      this.$('src-floor-text').textContent = hasFloor ? '適用中' : '未処理（① でデザイン画像を選ぶと適用されます）';
      this.$('src-wall-text').textContent = hasWall ? '適用中' : '未処理（② でデザイン画像を選ぶと適用されます）';
      if (!note) return;
      note.textContent = hasFloor && hasWall ? '床・壁とも、減色後のイメージ画像を3Dに適用しています。' : '';
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
