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
      this.raster = null;      // set while the drawing is a PNG / JPEG
      this._rasterTimer = null;
      this.$ = (name) => el.querySelector('[data-cfp="' + name + '"]');
      this.height = parseInt(el.dataset.height, 10) || 600;
      this.$('canvas').style.height = this.height + 'px';
      // size reflection only makes sense on the page that has the estimate tool
      this.hasTool = !!document.getElementById('cc-width');
      if (!this.hasTool) this.$('apply-size').closest('label').hidden = true;
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

      const reload = () => this.lastFile && this.loadFile(this.lastFile, { keepRoom: true });
      this.$('scale').addEventListener('change', reload);
      this.$('unit').addEventListener('change', reload);
      this.$('apply-size').addEventListener('change', () => {
        const room = this.data && this.data.rooms[this.roomIndex];
        if (this.$('apply-size').checked && this.fromFile && room) this.reflectSize(room);
        else this.$('size-note').textContent = '';
      });
      const rerun = () => {
        clearTimeout(this._rasterTimer);
        this._rasterTimer = setTimeout(() => this.lastFile && this.loadFile(this.lastFile, { keepRoom: true, keepScale: true }), 350);
      };
      this.$('threshold').addEventListener('input', () => { this._rasterLabels(); rerun(); });
      this.$('gap').addEventListener('input', () => { this._rasterLabels(); rerun(); });
      this.$('cal-btn').addEventListener('click', () => this.calibrate());
      this.$('cal-mm').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); this.calibrate(); } });
      this.$('sample-btn').addEventListener('click', () => this.loadData(SAMPLE, 'サンプル', { fromFile: false }));
      this.$('reset-btn').addEventListener('click', () => this.renderer && this.renderer.resetCamera());
      this.$('save-btn').addEventListener('click', () => this.saveJSON());
      this.$('floor-tex').addEventListener('change', (e) => this._texture(e, 'floor'));
      this.$('wall-tex').addEventListener('change', (e) => this._texture(e, 'wall'));
    }

    async loadFile(file, opts = {}) {
      this.lastFile = file;
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
        const normalized = { rooms: data.rooms.map((r, i) => CADParser.normalizeRoom(r, i)), metadata: data.metadata };
        CADParser.validate(normalized);
        if (!this.renderer) this.renderer = new ThreeRoomRenderer(this.$('canvas'));
        this.raster = (normalized.metadata && normalized.metadata.raster) || null;
        if (this.raster) this.$('raster').hidden = false; // also for a scanned PDF read as an image
        const prevIndex = this.roomIndex;
        const prevCount = this.data ? this.data.rooms.length : 0;
        this.data = normalized;
        this.label = label;
        this.fromFile = !!opts.fromFile;

        // with several rooms, start from the largest one; the user picks another on the plan.
        // A reload of the same drawing (scale / unit changed) keeps the room already chosen.
        let best = 0;
        normalized.rooms.forEach((r, i) => {
          if (CADParser.calculateArea(r.vertices) > CADParser.calculateArea(normalized.rooms[best].vertices)) best = i;
        });
        if (opts.keepRoom && prevCount === normalized.rooms.length && prevIndex < normalized.rooms.length) best = prevIndex;
        this.renderPicker();
        this.selectRoom(best, this.fromFile);

        const n = normalized.rooms.length;
        this._rasterNote();
        if (this.raster && !this.raster.calibrated) {
          this.status('画像から部屋を ' + n + ' 室読み取りました。画像には縮尺が無いため、実際の大きさを入力してください。', 'success');
        } else this.status(n > 1
          ? '部屋が ' + n + ' 室見つかりました。3Dで見たい部屋を、平面図または一覧から選んでください。'
          : '読み込みました（部屋 1）', 'success');
      } catch (err) {
        this.status('エラー: ' + err.message, 'error');
        console.error(err);
      }
    }

    selectRoom(i, reflect) {
      const room = this.data.rooms[i];
      this.roomIndex = i;
      this.renderer.loadFloorPlan({ rooms: [room], metadata: this.data.metadata });

      const b = CADParser.bounds(room.vertices);
      const a = this.renderer.getAreas();
      this.$('filename').textContent = this.label;
      this.$('room-name').textContent = room.name || ('部屋 ' + (i + 1));
      const prov = !!this.raster && !this.raster.calibrated;
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
      else this.$('size-note').textContent = '';
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
      const raster = this.raster;
      wrap.hidden = rooms.length < 2 && !raster;
      const svg = this.$('plan');
      const list = this.$('room-list');
      svg.textContent = '';
      list.textContent = '';
      if (rooms.length < 2 && !raster) return;

      const all = rooms.flatMap((r) => r.vertices);
      const b = raster
        ? { minX: 0, minY: 0, maxX: raster.widthPx * raster.mmPerPx, maxY: raster.heightPx * raster.mmPerPx }
        : CADParser.bounds(all);
      const w = b.maxX - b.minX, h = b.maxY - b.minY;
      const pad = Math.max(w, h) * 0.04;
      // CAD Y is up, SVG Y is down
      svg.setAttribute('viewBox', [b.minX - pad, -b.maxY - pad, w + pad * 2, h + pad * 2].join(' '));
      const fs = Math.max(w, h) / (rooms.length > 12 ? 30 : 16);

      if (raster) {
        // the uploaded drawing, so that the detected rooms can be checked against it
        const img = document.createElementNS(SVG_NS, 'image');
        img.setAttribute('href', raster.dataUrl);
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
        list.appendChild(btn);
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
      const b = CADParser.bounds(this.raster.polys[this.roomIndex]);
      const px = this.$('cal-axis').value === 'h' ? b.maxY - b.minY : b.maxX - b.minX;
      if (!(px > 0)) return;
      this.raster.mmPerPx = mm / px;
      this.raster.calibrated = true;
      this.data.rooms = CADParser.roomsFromRaster(this.raster, this.raster.mmPerPx);
      this.renderPicker();
      this.selectRoom(this.roomIndex, true);
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
     * 500 mm tile) and the wallpaper size fields (wall width / wall height).
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
      const persp = { 'persp-width': w, 'persp-wall-height': wallH };
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
        + '壁紙のサイズを 幅' + fmtMm(applied['persp-width'] || w)
        + '／高さ' + fmtMm(applied['persp-wall-height'] || wallH) + ' mm に設定しました。';
      if (Math.abs(area - w * h) / (w * h) > 0.01) {
        text += ' ※四角でない部屋のため、見積もりは外接する四角（' + fmtArea(tileW * tileH) + '㎡）で計算されます（実際の床面積は ' + fmtArea(area) + '㎡）。';
      }
      if (clamped) text += ' ※壁紙のサイズは入力欄の上限・下限に丸めました。';
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
      if (!note) return;
      if (hasFloor && hasWall) note.textContent = '床・壁のデザイン（変換後の画像）を反映しています。';
      else if (hasFloor) note.textContent = '床のデザインを反映しています。壁は「② 壁紙用デザイン」を処理すると反映されます。';
      else if (hasWall) note.textContent = '壁のデザインを反映しています。床は「① カーペット用デザイン」を処理すると反映されます。';
      else note.textContent = '①カーペット用・②壁紙用のデザイン画像を処理すると、床・壁に自動で反映されます。';
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
