/**
 * RoomTracer: draw rooms by hand on top of a drawing picture (SVG, coordinates = picture pixels).
 * Tools: pan (drag), rect (drag two corners), poly (click corners; click the first point or
 * double-click to finish), move (drag a room drawn by hand to another place; the picture stays),
 * edit (reshape a room drawn by hand: drag a corner, drag an edge's middle handle to add a corner,
 * double-click a corner to remove it).
 * Mouse wheel zooms around the pointer.
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
      this.onMove = opts.onMove || (() => {}); // (key, poly) after a room was dragged with the move tool
      this.items = [];
      this.move = null;
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
      this.handles = el('g', { 'pointer-events': 'none' });
      this.edit = null;
      svg.append(this.image, this.roomsLayer, this.handles, this.draft);
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
    // movable: true for rooms that the move tool may drag; key is passed back to onMove
    setRooms(items) {
      this.roomsLayer.textContent = '';
      this.labels = [];
      this.items = items;
      items.forEach((it) => {
        const g = el('g');
        const color = it.color || (it.selected ? '#2563eb' : (it.manual ? '#16a34a' : '#a1a1aa'));
        it._g = g;
        it._poly = g.appendChild(el('polygon', {
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
        it._label = t;
        g.appendChild(t);
        this.labels.push(t);
        this.roomsLayer.appendChild(g);
      });
      this._labelSize();
      this._drawHandles();
    }

    setTool(tool) {
      this.tool = tool;
      this.cancel();
      this.svg.style.cursor = this._cursor();
      this._drawHandles();
    }

    _cursor() {
      return this.tool === 'pan' ? 'grab' : (this.tool === 'move' ? 'move' : (this.tool === 'edit' ? 'default' : 'crosshair'));
    }

    // ---------------------------------------------------------- reshaping (edit tool)
    _drawHandles() {
      this.handles.textContent = '';
      if (this.tool !== 'edit') return;
      const r = 6 / this._scale();
      this.items.forEach((it) => {
        if (!it.movable) return;
        const v = it.poly;
        v.forEach((p, i) => {
          const q = v[(i + 1) % v.length];
          // middle of each edge: drag it to add a corner
          this.handles.appendChild(el('rect', {
            x: (p[0] + q[0]) / 2 - r * 0.7, y: (p[1] + q[1]) / 2 - r * 0.7, width: r * 1.4, height: r * 1.4,
            fill: '#16a34a', 'fill-opacity': 0.75, stroke: '#fff', 'stroke-width': 1, 'vector-effect': 'non-scaling-stroke',
          }));
        });
        v.forEach((p) => this.handles.appendChild(el('circle', {
          cx: p[0], cy: p[1], r, fill: '#fff', stroke: '#16a34a', 'stroke-width': 2, 'vector-effect': 'non-scaling-stroke',
        })));
      });
    }

    // nearest corner (or, failing that, edge middle) of a movable room within 10 screen px
    _handleAt(p) {
      const tol = 10 / this._scale();
      let best = null, bd = tol;
      this.items.forEach((it) => {
        if (!it.movable) return;
        it.poly.forEach((q, i) => {
          const d = Math.hypot(q[0] - p[0], q[1] - p[1]);
          if (d <= bd) { bd = d; best = { it, vi: i, mid: false }; }
        });
      });
      if (best) return best;
      bd = tol;
      this.items.forEach((it) => {
        if (!it.movable) return;
        it.poly.forEach((q, i) => {
          const n = it.poly[(i + 1) % it.poly.length];
          const d = Math.hypot((q[0] + n[0]) / 2 - p[0], (q[1] + n[1]) / 2 - p[1]);
          if (d <= bd) { bd = d; best = { it, vi: i, mid: true }; }
        });
      });
      return best;
    }

    _redrawItem(it) {
      it._poly.setAttribute('points', it.poly.map((p) => p[0] + ',' + p[1]).join(' '));
      const xs = it.poly.map((p) => p[0]), ys = it.poly.map((p) => p[1]);
      it._label.setAttribute('x', (Math.min(...xs) + Math.max(...xs)) / 2);
      it._label.setAttribute('y', (Math.min(...ys) + Math.max(...ys)) / 2);
      this._drawHandles();
    }

    // with "角を直角にそろえる", a dragged corner lines up with its neighbours
    _snapCorner(poly, vi, p) {
      if (!this.ortho) return p;
      const tol = 10 / this._scale();
      const q = [p[0], p[1]];
      [poly[(vi + poly.length - 1) % poly.length], poly[(vi + 1) % poly.length]].forEach((n) => {
        if (Math.abs(q[0] - n[0]) <= tol) q[0] = n[0];
        if (Math.abs(q[1] - n[1]) <= tol) q[1] = n[1];
      });
      return q;
    }

    // the topmost movable room under a picture point
    _hit(p) {
      for (let i = this.items.length - 1; i >= 0; i--) {
        const it = this.items[i];
        if (!it.movable) continue;
        let inside = false;
        const v = it.poly;
        for (let a = 0, b = v.length - 1; a < v.length; b = a++) {
          if ((v[a][1] > p[1]) !== (v[b][1] > p[1]) && p[0] < ((v[b][0] - v[a][0]) * (p[1] - v[a][1])) / (v[b][1] - v[a][1]) + v[a][0]) inside = !inside;
        }
        if (inside) return it;
      }
      return null;
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
      this._drawHandles();
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
        if (this.tool === 'edit') {
          const h = this._handleAt(p);
          if (!h) return;
          const orig = h.it.poly.map((q) => [q[0], q[1]]);
          let vi = h.vi;
          if (h.mid) {
            // a new corner in the middle of the edge
            const a = h.it.poly[vi], b = h.it.poly[(vi + 1) % h.it.poly.length];
            h.it.poly.splice(vi + 1, 0, [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2]);
            vi += 1;
            this._redrawItem(h.it);
          }
          this.edit = { it: h.it, vi, orig, changed: h.mid };
          svg.setPointerCapture(e.pointerId);
          return;
        }
        if (this.tool === 'move') {
          const it = this._hit(p);
          if (!it) return;
          const xs = it.poly.map((q) => q[0]), ys = it.poly.map((q) => q[1]);
          this.move = { it, start: p, orig: it.poly.map((q) => [q[0], q[1]]), dx: 0, dy: 0,
            minX: Math.min(...xs), maxX: Math.max(...xs), minY: Math.min(...ys), maxY: Math.max(...ys) };
          svg.setPointerCapture(e.pointerId);
          return;
        }
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
        if (this.edit) {
          const ed = this.edit;
          ed.it.poly[ed.vi] = this._snapCorner(ed.it.poly, ed.vi, this._clamp(this._pt(e)));
          ed.changed = true;
          this._redrawItem(ed.it);
          return;
        }
        if (this.tool === 'edit') {
          this.svg.style.cursor = this._handleAt(this._clamp(this._pt(e))) ? 'pointer' : 'default';
        }
        if (this.move) {
          const m = this.move, p = this._pt(e);
          // keep the room inside the picture
          m.dx = Math.min(this.W - m.maxX, Math.max(-m.minX, p[0] - m.start[0]));
          m.dy = Math.min(this.H - m.maxY, Math.max(-m.minY, p[1] - m.start[1]));
          m.it._g.setAttribute('transform', 'translate(' + m.dx + ',' + m.dy + ')');
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
          svg.style.cursor = this._cursor();
          return;
        }
        if (this.edit) {
          const ed = this.edit;
          this.edit = null;
          if (ed.changed) this.onMove(ed.it.key, ed.it.poly.map((q) => [q[0], q[1]]));
          return;
        }
        if (this.move) {
          const m = this.move;
          this.move = null;
          if (Math.abs(m.dx) < 0.5 && Math.abs(m.dy) < 0.5) { m.it._g.removeAttribute('transform'); return; }
          this.onMove(m.it.key, m.orig.map((q) => [q[0] + m.dx, q[1] + m.dy]));
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
        if (this.tool === 'edit') {
          // remove a corner (a room keeps at least 3)
          e.preventDefault();
          const h = this._handleAt(this._clamp(this._pt(e)));
          if (!h || h.mid || h.it.poly.length <= 3) return;
          h.it.poly.splice(h.vi, 1);
          this._redrawItem(h.it);
          this.onMove(h.it.key, h.it.poly.map((q) => [q[0], q[1]]));
          return;
        }
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
        if (e.key === 'Escape') {
          if (this.move) { this.move.it._g.removeAttribute('transform'); this.move = null; }
          if (this.edit) { this.edit.it.poly = this.edit.orig; this._redrawItem(this.edit.it); this.edit = null; }
          this.cancel();
        }
        else if (e.key === 'Backspace') { e.preventDefault(); this.undoPoint(); }
        else if (e.key === 'Enter') this._finishPoly();
      });
    }
  }

  root.RoomTracer = RoomTracer;
})(typeof window !== 'undefined' ? window : globalThis);
