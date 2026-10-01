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
          return this.parseDXF(this._decodeDXF(await input.arrayBuffer()), options);
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
    // DXF before R2007 stores text in the drawing's code page (Japanese drawings: Shift_JIS)
    static _decodeDXF(buffer) {
      const head = new TextDecoder('latin1').decode(new Uint8Array(buffer, 0, Math.min(buffer.byteLength, 20000)));
      const ver = (head.match(/\$ACADVER\s+1\s+(AC\d+)/) || [])[1];
      let enc = 'utf-8';
      if (!ver || ver < 'AC1021') {
        const cp = (head.match(/\$DWGCODEPAGE\s+3\s+(\S+)/) || [])[1] || '';
        enc = { ANSI_932: 'shift_jis', ANSI_936: 'gbk', ANSI_949: 'euc-kr', ANSI_950: 'big5', ANSI_1252: 'windows-1252' }[cp.toUpperCase()] || (cp ? 'windows-1252' : 'utf-8');
      }
      try { return new TextDecoder(enc).decode(buffer); } catch (e) { return new TextDecoder('utf-8').decode(buffer); }
    }

    static _cleanDxfText(t) {
      return t
        .replace(/\\U\+([0-9A-Fa-f]{4})/g, (m, h) => String.fromCharCode(parseInt(h, 16)))
        .replace(/\\P/g, ' ')
        .replace(/\\[A-Za-z][^;\\]*;/g, '')
        .replace(/[{}]/g, '')
        .replace(/%%[cCdDpP]/g, '')
        .trim();
    }

    static parseDXF(text, options = {}) {
      const lines = text.split(/\r\n|\r|\n/);
      const pairs = [];
      for (let i = 0; i + 1 < lines.length; i += 2) {
        pairs.push([parseInt(lines[i].trim(), 10), lines[i + 1].trim()]);
      }

      // drawing units from the header ($INSUNITS; 0 = unspecified)
      let headerScale = 0;
      for (let i = 0; i < pairs.length; i++) {
        if (pairs[i][0] === 9 && pairs[i][1] === '$INSUNITS' && pairs[i + 1]) {
          headerScale = INSUNITS_TO_MM[parseInt(pairs[i + 1][1], 10)] || 0;
          break;
        }
      }

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
      const texts = [];

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
        } else if (ent.type === 'TEXT' || ent.type === 'MTEXT') {
          let x = 0, y = 0, body = '';
          for (const [code, val] of ent.data) {
            if (code === 10) x = parseFloat(val);
            else if (code === 20) y = parseFloat(val);
            else if (code === 3) body += val; // MTEXT continuation chunks
            else if (code === 1) body += val;
          }
          const t = this._cleanDxfText(body);
          if (t) texts.push({ text: t, x, y });
        }
      }

      // unit: manual option > header > guess from the drawing's extent (a room is 1.5 m or larger)
      const allPts = [].concat(...closedLoops, ...openPaths, ...segments);
      let unit = { name: 'mm', source: 'default' };
      const byName = { mm: 1, cm: 10, m: 1000 };
      let unitScale = 1;
      if (options.unit && byName[options.unit]) {
        unitScale = byName[options.unit];
        unit = { name: options.unit, source: 'manual' };
      } else if (headerScale) {
        unitScale = headerScale;
        unit = { name: { 1: 'mm', 10: 'cm', 1000: 'm' }[headerScale] || (headerScale === 25.4 ? 'inch' : headerScale === 304.8 ? 'feet' : 'mm'), source: 'header' };
      } else if (allPts.length) {
        const xs = allPts.map((p) => p[0]), ys = allPts.map((p) => p[1]);
        const extent = Math.max(Math.max(...xs) - Math.min(...xs), Math.max(...ys) - Math.min(...ys));
        if (extent < 100) { unitScale = 1000; unit = { name: 'm', source: 'auto' }; }
        else if (extent < 1500) { unitScale = 10; unit = { name: 'cm', source: 'auto' }; }
        else { unit = { name: 'mm', source: 'auto' }; }
      }

      const scalePts = (pts) => pts.map(([x, y]) => [x * unitScale, y * unitScale]);
      const loops = closedLoops.map(scalePts);
      const edgeList = segments.map((sg) => scalePts(sg));
      for (const path of openPaths) {
        const p = scalePts(path);
        for (let k = 0; k + 1 < p.length; k++) edgeList.push([p[k], p[k + 1]]);
      }
      loops.push(...this._loopsFromSegments(edgeList));
      const labels = texts.map((t) => ({ text: t.text, x: t.x * unitScale, y: t.y * unitScale }));

      return this._buildResult(loops, { source: 'dxf', unitScale, unit }, labels);
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

      // text on the page (room names) in the same coordinate space as the paths
      let labels = [];
      try {
        const tc = await page.getTextContent();
        labels = tc.items
          .filter((it) => it.str && it.str.trim())
          .map((it) => ({ text: it.str.trim(), x: it.transform[4] * mmPerUnit, y: it.transform[5] * mmPerUnit }));
      } catch (e) { /* no text layer: rooms are simply numbered */ }

      return this._buildResult(loops, { source: 'pdf', pdfScale: options.scale || 1 }, labels);
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

    static _buildResult(loops, metadata, labels = []) {
      let rooms = [];
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

      // reading order: top to bottom, then left to right (rows are grouped within 1.5 m)
      const cen = (r) => {
        const b = this.bounds(r.vertices);
        return [(b.minX + b.maxX) / 2, (b.minY + b.maxY) / 2];
      };
      rooms.sort((a, b) => cen(b)[1] - cen(a)[1]);
      const ordered = [];
      while (rooms.length) {
        const rowY = cen(rooms[0])[1];
        const row = rooms.filter((r) => rowY - cen(r)[1] <= 1500);
        rooms = rooms.filter((r) => !row.includes(r));
        row.sort((a, b) => cen(a)[0] - cen(b)[0]);
        ordered.push(...row);
      }
      rooms = ordered;

      // name each room from text inside it (skip pure numbers / dimensions)
      const skip = /^[\d\s.,+\-×xX*\/mMcC㎜㎡²㎝()（）:：]*$/;
      const named = labels.filter((l) => !skip.test(l.text) && l.text.length <= 14);
      named.sort((a, b) => b.y - a.y || a.x - b.x);
      rooms.forEach((r) => {
        const inside = named.filter((l) => this.pointInPolygon([l.x, l.y], r.vertices));
        r.name = inside.length ? inside[0].text : '';
      });
      // a name used by several rooms (e.g. a note repeated) is not helpful: drop duplicates
      const count = {};
      rooms.forEach((r) => { if (r.name) count[r.name] = (count[r.name] || 0) + 1; });
      rooms.forEach((r, i) => {
        r.id = 'room_' + (i + 1);
        if (r.name && count[r.name] > 1) r.name = r.name + ' ' + (rooms.slice(0, i).filter((q) => q.name === r.name).length + 1);
        if (!r.name) r.name = '部屋 ' + (i + 1);
      });
      return { rooms, metadata };
    }

    static bounds(vertices) {
      const xs = vertices.map((p) => p[0]), ys = vertices.map((p) => p[1]);
      return { minX: Math.min(...xs), maxX: Math.max(...xs), minY: Math.min(...ys), maxY: Math.max(...ys) };
    }

    static pointInPolygon(p, vertices) {
      let inside = false;
      for (let i = 0, j = vertices.length - 1; i < vertices.length; j = i++) {
        const [xi, yi] = vertices[i], [xj, yj] = vertices[j];
        if ((yi > p[1]) !== (yj > p[1]) && p[0] < ((xj - xi) * (p[1] - yi)) / (yj - yi) + xi) inside = !inside;
      }
      return inside;
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
        name: room.name || '',
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
