/**
 * CAD Floor Plan widget: wires the shortcode markup to CADParser and ThreeRoomRenderer.
 * Each widget instance is available as element.cfpWidget (e.g. widget.renderer.setFloorTexture(url)).
 */
(function () {
  'use strict';

  const SAMPLE = {
    rooms: [{
      id: 'sample',
      vertices: [[0, 0], [6000, 0], [6000, 2500], [3500, 2500], [3500, 5000], [0, 5000]],
    }],
    metadata: { source: 'sample' },
  };

  class CADFloorPlanWidget {
    constructor(el) {
      this.el = el;
      this.renderer = null;
      this.data = null;
      this.lastFile = null;
      this.$ = (name) => el.querySelector('[data-cfp="' + name + '"]');
      this.height = parseInt(el.dataset.height, 10) || 600;
      this.$('canvas').style.height = this.height + 'px';
      this._bind();
      if (el.dataset.sample !== 'none') this.loadData(SAMPLE, 'サンプル（L字の部屋）');
    }

    _bind() {
      const drop = this.$('drop');
      const input = this.$('file');
      drop.addEventListener('click', () => input.click());
      input.addEventListener('change', () => input.files[0] && this.loadFile(input.files[0]));
      ['dragenter', 'dragover'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
      ['dragleave', 'drop'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
      drop.addEventListener('drop', (e) => e.dataTransfer.files[0] && this.loadFile(e.dataTransfer.files[0]));

      this.$('scale').addEventListener('change', () => this.lastFile && this.loadFile(this.lastFile));
      this.$('sample-btn').addEventListener('click', () => this.loadData(SAMPLE, 'サンプル（L字の部屋）'));
      this.$('reset-btn').addEventListener('click', () => this.renderer && this.renderer.resetCamera());
      this.$('save-btn').addEventListener('click', () => this.saveJSON());
      this.$('floor-tex').addEventListener('change', (e) => this._texture(e, 'floor'));
      this.$('wall-tex').addEventListener('change', (e) => this._texture(e, 'wall'));
    }

    async loadFile(file) {
      this.lastFile = file;
      this.status('読み込み中…', 'loading');
      try {
        const scale = parseFloat(this.$('scale').value) || 1;
        const data = await CADParser.parseFloorPlan(file, { scale });
        this.loadData(data, file.name);
      } catch (err) {
        this.status('エラー: ' + err.message, 'error');
        console.error(err);
      }
    }

    loadData(data, label) {
      try {
        const normalized = { rooms: data.rooms.map((r, i) => CADParser.normalizeRoom(r, i)), metadata: data.metadata };
        CADParser.validate(normalized);
        if (!this.renderer) this.renderer = new ThreeRoomRenderer(this.$('canvas'));
        this.renderer.loadFloorPlan(normalized);
        this.data = normalized;
        const a = this.renderer.getAreas();
        this.$('filename').textContent = label;
        this.$('floor-area').textContent = a.floorArea + ' ㎡';
        this.$('wall-area').textContent = a.wallArea + ' ㎡';
        this.$('room-count').textContent = normalized.rooms.length;
        this.status('読み込みました（部屋 ' + normalized.rooms.length + '）', 'success');
      } catch (err) {
        this.status('エラー: ' + err.message, 'error');
        console.error(err);
      }
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
      const blob = new Blob([JSON.stringify(this.data, null, 2)], { type: 'application/json' });
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
