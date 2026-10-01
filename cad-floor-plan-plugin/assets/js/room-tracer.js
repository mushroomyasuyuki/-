/**
 * RoomTracer: draw rooms by hand on top of a drawing picture (SVG, coordinates = picture pixels).
 * Tools: pan (drag), rect (drag two corners), poly (click corners; click the first point or
 * double-click to finish). Mouse wheel zooms around the pointer.
 */
(function (root) {
  'use strict';

  const NS = 'http://www.w3.org/2000/svg';
  const el = (name, attrs = {}) => {
    const e = document.createElementNS(NS, name);
    Object.keys(attrs).forEach((k) => e.setAttribute(k, attrs[k]));
    return e;
  };

  class RoomTracer {
    constructor(svg, opts = {}) {
      this.svg = svg;
      this.onCommit = opts.onCommit || (() => {});
      this.tool = 'rect';
      this.ortho = true;
      this.W = 1;
      this.H = 1;
      this.vb = { x: 0, y: 0, w: 1, h: 1 };
      this.pts = [];
      this.cursor = null;
      this.drag = null;
      this.pan = null;
      this.labels = [];

      this.image = el('image', { preserveAspectRatio: 'none' });
      this.roomsLayer = el('g', { 'pointer-events': 'none' });
      this.draft = el('g', { 'pointer-events': 'none' });
      svg.append(this.image, this.roomsLayer, this.draft);
      svg.setAttribute('tabindex', '0');
      this._bind();
    }

    setBackdrop(dataUrl, W, H) {
      this.W = W;
      this.H = H;
      this.image.setAttribute('href', dataUrl);
      this.image.setAttribute('width', W);
      this.image.setAttribute('height', H);
      this.cancel();
      this.fit();
    }

    // items: [{ poly: [[x, y]...], label, manual, selected }]
    setRooms(items) {
      this.roomsLayer.textContent = '';
      this.labels = [];
      items.forEach((it) => {
        const g = el('g');
        const color = it.selected ? '#2563eb' : (it.manual ? '#16a34a' : '#a1a1aa');
        g.appendChild(el('polygon', {
          points: it.poly.map((p) => p[0] + ',' + p[1]).join(' '),
          fill: color, 'fill-opacity': it.selected ? 0.35 : 0.22,
          stroke: color, 'stroke-width': it.selected ? 3 : 2, 'vector-effect': 'non-scaling-stroke',
        }));
        const xs = it.poly.map((p) => p[0]), ys = it.poly.map((p) => p[1]);
        const t = el('text', {
          x: (Math.min(...xs) + Math.max(...xs)) / 2, y: (Math.min(...ys) + Math.max(...ys)) / 2,
          'text-anchor': 'middle', 'dominant-baseline': 'central', fill: '#111', 'font-weight': 700,
          stroke: '#fff', 'stroke-width': 3, 'paint-order': 'stroke',
        });
        t.textContent = it.label;
        g.appendChild(t);
        this.labels.push(t);
        this.roomsLayer.appendChild(g);
      });
      this._labelSize();
    }

    setTool(tool) {
      this.tool = tool;
      this.cancel();
      this.svg.style.cursor = tool === 'pan' ? 'grab' : 'crosshair';
    }

    cancel() {
      this.pts = [];
      this.cursor = null;
      this.drag = null;
      this._drawDraft();
    }

    undoPoint() {
      this.pts.pop();
      this._drawDraft();
    }

    fit() {
      this.vb = { x: 0, y: 0, w: this.W, h: this.H };
      this._applyView();
    }

    zoom(factor, cx, cy) {
      const v = this.vb;
      const px = cx == null ? v.x + v.w / 2 : cx;
      const py = cy == null ? v.y + v.h / 2 : cy;
      const w = Math.min(this.W * 1.5, Math.max(this.W / 60, v.w * factor));
      const f = w / v.w;
      this.vb = { x: px - (px - v.x) * f, y: py - (py - v.y) * f, w, h: v.h * f };
      this._applyView();
    }

    // ------------------------------------------------------------------ internals
    _applyView() {
      const v = this.vb;
      this.svg.setAttribute('viewBox', [v.x, v.y, v.w, v.h].join(' '));
      this._labelSize();
      this._drawDraft();
    }

    _labelSize() {
      const size = this.vb.w / 32;
      this.labels.forEach((t) => t.setAttribute('font-size', size));
    }

    // client -> picture pixels
    _pt(e) {
      const m = this.svg.getScreenCTM().inverse();
      return [m.a * e.clientX + m.c * e.clientY + m.e, m.b * e.clientX + m.d * e.clientY + m.f];
    }

    _scale() { return this.svg.getScreenCTM().a; } // screen px per picture px

    _clamp(p) { return [Math.min(this.W, Math.max(0, p[0])), Math.min(this.H, Math.max(0, p[1]))]; }

    _snap(p) {
      if (!this.ortho || !this.pts.length) return p;
      const last = this.pts[this.pts.length - 1];
      const tol = 10 / this._scale();
      const q = [p[0], p[1]];
      if (Math.abs(p[0] - last[0]) <= tol) q[0] = last[0];
      if (Math.abs(p[1] - last[1]) <= tol) q[1] = last[1];
      return q;
    }

    _drawDraft() {
      this.draft.textContent = '';
      const sw = { 'stroke-width': 2, 'vector-effect': 'non-scaling-stroke', fill: 'none' };
      if (this.drag && this.drag.cur) {
        const [x0, y0] = this.drag.start, [x1, y1] = this.drag.cur;
        this.draft.appendChild(el('rect', Object.assign({
          x: Math.min(x0, x1), y: Math.min(y0, y1), width: Math.abs(x1 - x0), height: Math.abs(y1 - y0),
          stroke: '#f59e0b', 'stroke-dasharray': '6 4', fill: '#f59e0b', 'fill-opacity': 0.15,
        }, { 'stroke-width': 2, 'vector-effect': 'non-scaling-stroke' })));
      }
      if (this.pts.length) {
        const all = this.pts.concat(this.cursor ? [this.cursor] : []);
        this.draft.appendChild(el('polyline', Object.assign({ points: all.map((p) => p[0] + ',' + p[1]).join(' '), stroke: '#f59e0b' }, sw)));
        const r = 5 / this._scale();
        this.pts.forEach((p, i) => this.draft.appendChild(el('circle', {
          cx: p[0], cy: p[1], r: i === 0 ? r * 1.4 : r, fill: i === 0 ? '#ef4444' : '#f59e0b', stroke: '#fff', 'stroke-width': 1, 'vector-effect': 'non-scaling-stroke',
        })));
      }
    }

    _finishPoly() {
      if (this.pts.length >= 3) {
        const poly = this.pts.map((p) => [p[0], p[1]]);
        this.cancel();
        this.onCommit(poly);
      }
    }

    _bind() {
      const svg = this.svg;
      svg.addEventListener('contextmenu', (e) => e.preventDefault());

      svg.addEventListener('wheel', (e) => {
        e.preventDefault();
        const [x, y] = this._pt(e);
        this.zoom(e.deltaY < 0 ? 0.8 : 1.25, x, y);
      }, { passive: false });

      svg.addEventListener('pointerdown', (e) => {
        svg.focus({ preventScroll: true });
        if (e.button === 1 || (e.button === 0 && this.tool === 'pan')) {
          this.pan = { x: e.clientX, y: e.clientY };
          svg.setPointerCapture(e.pointerId);
          svg.style.cursor = 'grabbing';
          return;
        }
        if (e.button !== 0) return;
        const p = this._clamp(this._pt(e));
        if (this.tool === 'rect') {
          this.drag = { start: p, cur: null, sx: e.clientX, sy: e.clientY };
          svg.setPointerCapture(e.pointerId);
        } else if (this.tool === 'poly') {
          this.down = { sx: e.clientX, sy: e.clientY };
        }
      });

      svg.addEventListener('pointermove', (e) => {
        if (this.pan) {
          const s = this._scale();
          this.vb.x -= (e.clientX - this.pan.x) / s;
          this.vb.y -= (e.clientY - this.pan.y) / s;
          this.pan = { x: e.clientX, y: e.clientY };
          this._applyView();
          return;
        }
        if (this.drag) {
          this.drag.cur = this._clamp(this._pt(e));
          this._drawDraft();
        } else if (this.tool === 'poly' && this.pts.length) {
          this.cursor = this._snap(this._clamp(this._pt(e)));
          this._drawDraft();
        }
      });

      svg.addEventListener('pointerup', (e) => {
        if (this.pan) {
          this.pan = null;
          svg.style.cursor = this.tool === 'pan' ? 'grab' : 'crosshair';
          return;
        }
        if (this.drag) {
          const d = this.drag;
          this.drag = null;
          const cur = this._clamp(this._pt(e));
          const moved = Math.hypot(e.clientX - d.sx, e.clientY - d.sy);
          this._drawDraft();
          if (moved >= 8 && Math.abs(cur[0] - d.start[0]) > 1 && Math.abs(cur[1] - d.start[1]) > 1) {
            const x0 = Math.min(d.start[0], cur[0]), x1 = Math.max(d.start[0], cur[0]);
            const y0 = Math.min(d.start[1], cur[1]), y1 = Math.max(d.start[1], cur[1]);
            this.onCommit([[x0, y0], [x1, y0], [x1, y1], [x0, y1]]);
          }
          return;
        }
        if (this.tool === 'poly' && this.down) {
          const moved = Math.hypot(e.clientX - this.down.sx, e.clientY - this.down.sy);
          this.down = null;
          if (moved > 5) return;
          const raw = this._clamp(this._pt(e));
          if (this.pts.length >= 3 && Math.hypot(raw[0] - this.pts[0][0], raw[1] - this.pts[0][1]) * this._scale() <= 12) {
            this._finishPoly();
            return;
          }
          this.pts.push(this._snap(raw));
          this._drawDraft();
        }
      });

      svg.addEventListener('dblclick', (e) => {
        if (this.tool !== 'poly') return;
        e.preventDefault();
        // the two clicks of a double click each added a point; drop the duplicate
        if (this.pts.length >= 2) {
          const a = this.pts[this.pts.length - 1], b = this.pts[this.pts.length - 2];
          if (Math.hypot(a[0] - b[0], a[1] - b[1]) * this._scale() < 6) this.pts.pop();
        }
        this._finishPoly();
      });

      svg.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') this.cancel();
        else if (e.key === 'Backspace') { e.preventDefault(); this.undoPoint(); }
        else if (e.key === 'Enter') this._finishPoly();
      });
    }
  }

  root.RoomTracer = RoomTracer;
})(typeof window !== 'undefined' ? window : globalThis);
