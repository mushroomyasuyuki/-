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
      input.addEventListener('change', () => input.files[0] && this.loadFile(input.files[0]));
      ['dragenter', 'dragover'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
      ['dragleave', 'drop'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
      drop.addEventListener('drop', (e) => e.dataTransfer.files[0] && this.loadFile(e.dataTransfer.files[0]));

      const reload = () => this.lastFile && this.loadFile(this.lastFile, { keepRoom: true, keepManual: true });
      this.$('scale').addEventListener('change', reload);
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
      this.$('sample-btn').addEventListener('click', () => this.loadData(SAMPLE, 'サンプル', { fromFile: false }));
      this.$('reset-btn').addEventListener('click', () => this.renderer && this.renderer.resetCamera());
      this.$('save-btn').addEventListener('click', () => this.saveJSON());
      this.$('floor-tex').addEventListener('change', (e) => this._texture(e, 'floor'));
      this.$('wall-tex').addEventListener('change', (e) => this._texture(e, 'wall'));
    }

    async loadFile(file, opts = {}) {
      this.lastFile = file;
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
        this.loadData(data, file.name, { fromFile: true, keepRoom: !!opts.keepRoom });
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
          if (opts.keepRoom && prevCount === rooms.length && prevIndex < rooms.length) best = prevIndex;
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
      const persp = { 'persp-width': w, 'persp-floor-depth': h, 'persp-wall-height': wallH };
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
        + 'お部屋パース・壁紙のサイズを 幅' + fmtMm(applied['persp-width'] || w)
        + '／奥行' + fmtMm(applied['persp-floor-depth'] || h)
        + '／壁の高さ' + fmtMm(applied['persp-wall-height'] || wallH) + ' mm に設定しました。';
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
      if (hasWall || fromEvent) this.renderer.setWallCanvas(hasWall ? wall : null);
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
