/**
 * CADParser: DXF / vector PDF / JSON floor plan -> normalized room data.
 * All coordinates in the output are millimetres, CAD convention (Y up).
 */
(function (root) {
  'use strict';

  const DEFAULT_WALL_HEIGHT = 2400;
  const MIN_ROOM_AREA_MM2 = 0.5 * 1e6; // loops smaller than 0.5 m2 are treated as noise
  const SNAP_MM = 2;

  const PDFJS_URL = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
  const PDFJS_WORKER_URL = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

  // DXF $INSUNITS -> mm
  const INSUNITS_TO_MM = { 1: 25.4, 2: 304.8, 4: 1, 5: 10, 6: 1000 };

  class CADParser {
    static async parseFloorPlan(input, options = {}) {
      if (typeof input === 'string') return this.parseJSON(input);
      const ext = (input.name.split('.').pop() || '').toLowerCase();
      switch (ext) {
        case 'dxf':
          return this.parseDXF(await input.text(), options);
        case 'pdf':
          return this.parsePDF(input, options);
        case 'json':
          return this.parseJSON(await input.text());
        case 'dwg':
          throw new Error('DWGは直接読み込めません。CADソフトでDXF形式に書き出してからアップロードしてください。');
        default:
          throw new Error('未対応の形式です: .' + ext + '（DXF / PDF / JSON に対応）');
      }
    }

    // ---------------------------------------------------------------- DXF
    static parseDXF(text, options = {}) {
      const lines = text.split(/\r\n|\r|\n/);
      const pairs = [];
      for (let i = 0; i + 1 < lines.length; i += 2) {
        pairs.push([parseInt(lines[i].trim(), 10), lines[i + 1].trim()]);
      }

      // header units
      let unitScale = options.unitScale || 0;
      if (!unitScale) {
        for (let i = 0; i < pairs.length; i++) {
          if (pairs[i][0] === 9 && pairs[i][1] === '$INSUNITS' && pairs[i + 1]) {
            unitScale = INSUNITS_TO_MM[parseInt(pairs[i + 1][1], 10)] || 1;
            break;
          }
        }
      }
      unitScale = unitScale || 1;

      // entities section
      let start = -1;
      for (let i = 0; i < pairs.length - 1; i++) {
        if (pairs[i][0] === 0 && pairs[i][1] === 'SECTION' && pairs[i + 1][1] === 'ENTITIES') {
          start = i + 2;
          break;
        }
      }
      if (start < 0) throw new Error('DXFのENTITIESセクションが見つかりません。');

      const closedLoops = [];
      const openPaths = [];
      const segments = [];

      let i = start;
      const readEntity = () => {
        const type = pairs[i][1];
        const data = [];
        i++;
        while (i < pairs.length && pairs[i][0] !== 0) data.push(pairs[i++]);
        return { type, data };
      };

      while (i < pairs.length && !(pairs[i][0] === 0 && pairs[i][1] === 'ENDSEC')) {
        if (pairs[i][0] !== 0) { i++; continue; }
        const ent = readEntity();

        if (ent.type === 'LWPOLYLINE') {
          const pts = [];
          let closed = false;
          for (const [code, val] of ent.data) {
            if (code === 70) closed = (parseInt(val, 10) & 1) === 1;
            else if (code === 10) pts.push([parseFloat(val), 0]);
            else if (code === 20 && pts.length) pts[pts.length - 1][1] = parseFloat(val);
          }
          this._addPath(pts, closed, closedLoops, openPaths);
        } else if (ent.type === 'POLYLINE') {
          let closed = false;
          for (const [code, val] of ent.data) if (code === 70) closed = (parseInt(val, 10) & 1) === 1;
          const pts = [];
          while (i < pairs.length && pairs[i][0] === 0 && pairs[i][1] === 'VERTEX') {
            const v = readEntity();
            let x = 0, y = 0;
            for (const [code, val] of v.data) {
              if (code === 10) x = parseFloat(val);
              else if (code === 20) y = parseFloat(val);
            }
            pts.push([x, y]);
          }
          if (i < pairs.length && pairs[i][1] === 'SEQEND') readEntity();
          this._addPath(pts, closed, closedLoops, openPaths);
        } else if (ent.type === 'LINE') {
          let x1 = 0, y1 = 0, x2 = 0, y2 = 0;
          for (const [code, val] of ent.data) {
            if (code === 10) x1 = parseFloat(val);
            else if (code === 20) y1 = parseFloat(val);
            else if (code === 11) x2 = parseFloat(val);
            else if (code === 21) y2 = parseFloat(val);
          }
          segments.push([[x1, y1], [x2, y2]]);
        }
      }

      const scalePts = (pts) => pts.map(([x, y]) => [x * unitScale, y * unitScale]);
      const loops = closedLoops.map(scalePts);
      const edgeList = segments.map((s) => scalePts(s));
      for (const path of openPaths) {
        const p = scalePts(path);
        for (let k = 0; k + 1 < p.length; k++) edgeList.push([p[k], p[k + 1]]);
      }
      loops.push(...this._loopsFromSegments(edgeList));

      return this._buildResult(loops, { source: 'dxf', unitScale });
    }

    // ---------------------------------------------------------------- PDF
    static async parsePDF(file, options = {}) {
      const pdfjs = await this._ensurePdfJs(options);
      const data = new Uint8Array(await file.arrayBuffer());
      const pdf = await pdfjs.getDocument({ data }).promise;
      const page = await pdf.getPage(options.page || 1);
      const ops = await page.getOperatorList();
      const OPS = pdfjs.OPS;

      // PDF user units are 1/72 inch; options.scale is the drawing scale denominator (1:100 -> 100)
      const mmPerUnit = (25.4 / 72) * (options.scale || 1);

      const loops = [];
      const edges = [];
      let ctm = [1, 0, 0, 1, 0, 0];
      const stack = [];
      const mul = (m, n) => [
        m[0] * n[0] + m[2] * n[1], m[1] * n[0] + m[3] * n[1],
        m[0] * n[2] + m[2] * n[3], m[1] * n[2] + m[3] * n[3],
        m[0] * n[4] + m[2] * n[5] + m[4], m[1] * n[4] + m[3] * n[5] + m[5],
      ];
      const tx = (x, y) => [
        (ctm[0] * x + ctm[2] * y + ctm[4]) * mmPerUnit,
        (ctm[1] * x + ctm[3] * y + ctm[5]) * mmPerUnit,
      ];

      const addSub = (pts, closed) => {
        if (pts.length < 2) return;
        if (closed && pts.length >= 3) loops.push(pts);
        else for (let k = 0; k + 1 < pts.length; k++) edges.push([pts[k], pts[k + 1]]);
      };

      for (let k = 0; k < ops.fnArray.length; k++) {
        const fn = ops.fnArray[k];
        const args = ops.argsArray[k];
        if (fn === OPS.save) stack.push(ctm.slice());
        else if (fn === OPS.restore) ctm = stack.pop() || [1, 0, 0, 1, 0, 0];
        else if (fn === OPS.transform) ctm = mul(ctm, args);
        else if (fn === OPS.constructPath) {
          const pathOps = args[0];
          const c = args[1];
          let ci = 0;
          let cur = [];
          let closed = false;
          const flush = () => { addSub(cur, closed); cur = []; closed = false; };
          for (const op of pathOps) {
            if (op === OPS.moveTo) { flush(); cur.push(tx(c[ci], c[ci + 1])); ci += 2; }
            else if (op === OPS.lineTo) { cur.push(tx(c[ci], c[ci + 1])); ci += 2; }
            else if (op === OPS.curveTo) { cur.push(tx(c[ci + 4], c[ci + 5])); ci += 6; }
            else if (op === OPS.curveTo2 || op === OPS.curveTo3) { cur.push(tx(c[ci + 2], c[ci + 3])); ci += 4; }
            else if (op === OPS.closePath) { closed = true; }
            else if (op === OPS.rectangle) {
              flush();
              const x = c[ci], y = c[ci + 1], w = c[ci + 2], h = c[ci + 3];
              ci += 4;
              addSub([tx(x, y), tx(x + w, y), tx(x + w, y + h), tx(x, y + h)], true);
            }
          }
          flush();
        }
      }

      loops.push(...this._loopsFromSegments(edges));
      return this._buildResult(loops, { source: 'pdf', pdfScale: options.scale || 1 });
    }

    static _ensurePdfJs(options) {
      if (root.pdfjsLib) {
        this._configureWorker(options);
        return Promise.resolve(root.pdfjsLib);
      }
      const url = options.pdfJsUrl || (root.cadFloorPlanData && root.cadFloorPlanData.pdfJsUrl) || PDFJS_URL;
      return new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = url;
        s.onload = () => { this._configureWorker(options); resolve(root.pdfjsLib); };
        s.onerror = () => reject(new Error('PDF.jsの読み込みに失敗しました。ネットワーク接続を確認してください。'));
        document.head.appendChild(s);
      });
    }

    static _configureWorker(options) {
      const w = options.pdfWorkerUrl || (root.cadFloorPlanData && root.cadFloorPlanData.pdfWorkerUrl) || PDFJS_WORKER_URL;
      root.pdfjsLib.GlobalWorkerOptions.workerSrc = w;
    }

    // --------------------------------------------------------------- JSON
    static parseJSON(text) {
      let data;
      try { data = JSON.parse(text); } catch (e) { throw new Error('JSONの形式が正しくありません: ' + e.message); }
      const rooms = Array.isArray(data) ? data : data.rooms;
      if (!Array.isArray(rooms) || !rooms.length) throw new Error('JSONに rooms 配列がありません。');
      return {
        rooms: rooms.map((r, idx) => this.normalizeRoom(r, idx)),
        metadata: Object.assign({ source: 'json' }, data.metadata || {}),
      };
    }

    // ------------------------------------------------------------ helpers
    static _addPath(pts, closed, closedLoops, openPaths) {
      if (pts.length < 2) return;
      const first = pts[0], last = pts[pts.length - 1];
      const selfClosed = pts.length >= 4 && Math.hypot(first[0] - last[0], first[1] - last[1]) < 1e-6;
      if ((closed || selfClosed) && pts.length >= 3) closedLoops.push(pts);
      else openPaths.push(pts);
    }

    // Chain segments into simple closed loops (every node must have exactly two segments).
    static _loopsFromSegments(segments) {
      const key = (p) => Math.round(p[0] / SNAP_MM) + ',' + Math.round(p[1] / SNAP_MM);
      const nodes = new Map();
      const addEdge = (a, b) => {
        const ka = key(a), kb = key(b);
        if (ka === kb) return;
        if (!nodes.has(ka)) nodes.set(ka, { p: a, n: new Set() });
        if (!nodes.has(kb)) nodes.set(kb, { p: b, n: new Set() });
        nodes.get(ka).n.add(kb);
        nodes.get(kb).n.add(ka);
      };
      for (const [a, b] of segments) addEdge(a, b);

      const visited = new Set();
      const loops = [];
      for (const [startKey, node] of nodes) {
        if (visited.has(startKey)) continue;
        // walk the component; accept only if it is a simple cycle
        const comp = [];
        const queue = [startKey];
        const seen = new Set([startKey]);
        while (queue.length) {
          const k = queue.pop();
          comp.push(k);
          for (const nb of nodes.get(k).n) if (!seen.has(nb)) { seen.add(nb); queue.push(nb); }
        }
        comp.forEach((k) => visited.add(k));
        if (comp.length < 3 || !comp.every((k) => nodes.get(k).n.size === 2)) continue;

        const pts = [];
        let prev = null;
        let cur = startKey;
        do {
          pts.push(nodes.get(cur).p);
          const next = [...nodes.get(cur).n].find((k) => k !== prev);
          prev = cur;
          cur = next;
        } while (cur !== startKey && pts.length <= comp.length);
        if (pts.length === comp.length) loops.push(pts);
      }
      return loops;
    }

    static _buildResult(loops, metadata) {
      const rooms = [];
      loops.forEach((pts) => {
        const room = this.normalizeRoom({ vertices: pts }, rooms.length);
        if (this.calculateArea(room.vertices) >= MIN_ROOM_AREA_MM2) rooms.push(room);
      });
      if (!rooms.length) {
        throw new Error(
          '部屋の輪郭（0.5㎡以上の閉じた図形）を検出できませんでした。' +
          '壁の外形を閉じたポリライン、またはつながった線で描いてください。'
        );
      }
      rooms.forEach((r, i) => { r.id = 'room_' + (i + 1); });
      return { rooms, metadata };
    }

    static normalizeRoom(room, idx) {
      let v = (room.vertices || []).map((p) => [Number(p[0]), Number(p[1])]);
      if (v.length > 1) {
        const a = v[0], b = v[v.length - 1];
        if (Math.hypot(a[0] - b[0], a[1] - b[1]) < 1e-6) v.pop();
      }
      if (v.length < 3 || v.some((p) => !isFinite(p[0]) || !isFinite(p[1]))) {
        throw new Error('部屋 ' + (room.id || idx + 1) + ' の頂点が不正です（3点以上の数値が必要）。');
      }
      const walls = Array.isArray(room.walls) && room.walls.length === v.length
        ? room.walls.map((w, i) => ({
          from: w.from != null ? w.from : i,
          to: w.to != null ? w.to : (i + 1) % v.length,
          height: Number(w.height) || DEFAULT_WALL_HEIGHT,
          material: w.material || 'wallpaper',
        }))
        : v.map((_, i) => ({ from: i, to: (i + 1) % v.length, height: DEFAULT_WALL_HEIGHT, material: 'wallpaper' }));
      walls.forEach((w) => {
        const a = v[w.from], b = v[w.to];
        w.length = Math.hypot(b[0] - a[0], b[1] - a[1]);
      });
      return {
        id: room.id || 'room_' + (idx + 1),
        vertices: v,
        walls,
        floor: room.floor || { material: 'carpet' },
      };
    }

    static validate(data) {
      if (!data || !Array.isArray(data.rooms) || !data.rooms.length) throw new Error('部屋データがありません。');
      data.rooms.forEach((r, i) => {
        if (!r.vertices || r.vertices.length < 3) throw new Error('部屋 ' + (r.id || i + 1) + ': 頂点が3点未満です。');
        if (!Array.isArray(r.walls)) throw new Error('部屋 ' + (r.id || i + 1) + ': walls がありません。');
      });
      return true;
    }

    static calculateArea(vertices) {
      let area = 0;
      for (let i = 0; i < vertices.length; i++) {
        const [x1, y1] = vertices[i];
        const [x2, y2] = vertices[(i + 1) % vertices.length];
        area += x1 * y2 - x2 * y1;
      }
      return Math.abs(area) / 2;
    }

    static calculateWallArea(walls) {
      return walls.reduce((sum, w) => sum + w.length * (w.height || DEFAULT_WALL_HEIGHT), 0);
    }
  }

  CADParser.DEFAULT_WALL_HEIGHT = DEFAULT_WALL_HEIGHT;
  root.CADParser = CADParser;
  if (typeof module !== 'undefined' && module.exports) module.exports = CADParser;
})(typeof window !== 'undefined' ? window : globalThis);
