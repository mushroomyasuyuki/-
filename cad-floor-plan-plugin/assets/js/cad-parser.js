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
        case 'png':
        case 'jpg':
        case 'jpeg':
        case 'webp':
          return this.parseImage(input, options);
        case 'dwg':
          throw new Error('DWGは直接読み込めません。CADソフトでDXF形式に書き出してからアップロードしてください。');
        default:
          throw new Error('未対応の形式です: .' + ext + '（DXF / PDF / JSON / PNG / JPEG に対応）');
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
      const vectorShapes = loops.length + edges.length;
      const imageOps = [OPS.paintImageXObject, OPS.paintInlineImageXObject, OPS.paintImageMaskXObject, OPS.paintJpegXObject].filter((o) => o != null);
      const hasImage = ops.fnArray.some((f) => imageOps.includes(f));

      // text on the page (room names) in the same coordinate space as the paths
      let labels = [];
      try {
        const tc = await page.getTextContent();
        labels = tc.items
          .filter((it) => it.str && it.str.trim())
          .map((it) => ({ text: it.str.trim(), x: it.transform[4] * mmPerUnit, y: it.transform[5] * mmPerUnit }));
      } catch (e) { /* no text layer: rooms are simply numbered */ }

      try {
        return this._buildResult(loops, { source: 'pdf', pdfScale: options.scale || 1 }, labels);
      } catch (err) {
        // No closed rooms in the vector data (a scanned PDF, or a CAD plan drawn with loose lines):
        // show the rendered page so that the rooms can be read from the picture or traced by hand.
        let reason;
        if (hasImage && vectorShapes < 20) {
          reason = 'このPDFは画像（スキャン）です。画像として読み取りました。';
        } else if (!loops.length) {
          reason = 'このPDFでは、壁の線が閉じた部屋の輪郭になっていません（途切れている、または交差で枝分かれしています）。';
        } else {
          let w = 0, h = 0, best = 0;
          loops.forEach((l) => {
            const b = this.bounds(l), a = this.calculateArea(l);
            if (a > best) { best = a; w = b.maxX - b.minX; h = b.maxY - b.minY; }
          });
          reason = '0.5㎡以上の閉じた部屋が見つかりませんでした（最も大きい閉じた図形は ' +
            Math.round(w).toLocaleString('ja-JP') + '×' + Math.round(h).toLocaleString('ja-JP') + ' mm。' +
            '縮尺が合っていなければ「PDFの縮尺」を直してください。現在は 1:' + (options.scale || 1) + '）。';
        }
        const bd = await this._renderPdfPage(page, options);
        const opts = Object.assign({}, options, { allowEmpty: true, minAreaRatio: 0.004, mmPerPx: (options.scale || 1) > 1 ? bd.mmPerPx : undefined });
        const res = this._detectRaster(bd.canvas, opts);
        res.metadata.pdfFallback = { reason };
        res.metadata.raster.pdfMmPerPx = bd.mmPerPx;
        return res;
      }
    }

    // Renders a PDF page to a canvas (long side 2000 px). mmPerPx follows the drawing scale 1:N.
    static async _renderPdfPage(page, options = {}) {
      const base = page.getViewport({ scale: 1 });
      const k = 2000 / Math.max(base.width, base.height); // px per PDF point
      const viewport = page.getViewport({ scale: k });
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(viewport.width);
      canvas.height = Math.round(viewport.height);
      const ctx = canvas.getContext('2d', { willReadFrequently: true });
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      await page.render({ canvasContext: ctx, viewport }).promise;
      return { canvas, k, mmPerPx: (25.4 / 72) * (options.scale || 1) / k };
    }

    // The page picture for tracing rooms by hand on a PDF that was read as vectors.
    static async renderPdfBackdrop(file, options = {}) {
      const pdfjs = await this._ensurePdfJs(options);
      const pdf = await pdfjs.getDocument({ data: new Uint8Array(await file.arrayBuffer()) }).promise;
      const page = await pdf.getPage(options.page || 1);
      const bd = await this._renderPdfPage(page, options);
      return {
        dataUrl: bd.canvas.toDataURL('image/jpeg', 0.85),
        widthPx: bd.canvas.width,
        heightPx: bd.canvas.height,
        k: bd.k,
        mmPerPx: bd.mmPerPx,
        calibrated: (options.scale || 1) > 1,
        kind: 'pdf',
      };
    }

    // ---------------------------------------------------- elevation drawings (展開図)
    // A sheet of wall elevations: every drawing is one wall. Its height is the ceiling height written at
    // the page edge (e.g. 2,700 beside "▼FL±0"), its width is the dimension string above the drawing.
    // Callouts that mention クロス (wall paper) are matched to the wall they sit on.
    static async analyzeElevations(file, options = {}) {
      const pdfjs = await this._ensurePdfJs(options);
      const base = (options.pdfJsUrl || (root.cadFloorPlanData && root.cadFloorPlanData.pdfJsUrl) || PDFJS_URL).replace(/\/build\/[^/]*$/, '/').replace(/[^/]*$/, '');
      const cMapUrl = options.cMapUrl || (root.cadFloorPlanData && root.cadFloorPlanData.cMapUrl) || (base + 'cmaps/');
      const pdf = await pdfjs.getDocument({ data: new Uint8Array(await file.arrayBuffer()), cMapUrl, cMapPacked: true }).promise;
      const page = await pdf.getPage(options.page || 1);
      const vp = page.getViewport({ scale: 1 });
      const tc = await page.getTextContent();
      const res = this.elevationsFromText(tc.items, vp.width, vp.height, options.scale || 0);
      res.keywords = this.drawingKeywords(tc.items.map((i) => i.str));
      res.pageW = vp.width;
      res.pageH = vp.height;
      return res;
    }

    // Words that tell an elevation sheet (walls) from a floor plan, found in the drawing's text.
    static drawingKeywords(strings) {
      // titles are decisive; level marks (▼FL, 天井高) also appear on floor plans and only count as hints
      const ELEV = /展開図|立面図|姿図|ELEVATION/i;
      const HINT = /▼\s*FL|FL\s*[±＋+]|天井高|C\.?H\s*[=＝]/i;
      const PLAN = /平面図|間取|PLAN|配置図|床伏/i;
      const elev = [], plan = [], hint = [];
      strings.forEach((raw) => {
        const s = String(raw || '').trim();
        if (!s) return;
        const e = s.match(ELEV), h = s.match(HINT), p = s.match(PLAN);
        if (e) elev.push(e[0]);
        if (h) hint.push(h[0]);
        if (p && !/天井/.test(s)) plan.push(p[0]);
      });
      return { elev, plan, hint };
    }

    // Floor plan or elevation sheet? Returns { type: 'plan' | 'elev', reason, elev, canSwitch }.
    // PDFs are judged from their text (wall heights "▼FL" with dimensions, titles such as 展開図 / 平面図);
    // DXF from its texts; pictures and JSON cannot be read and are taken as floor plans.
    static async classifyDrawing(file, options = {}) {
      const name = file.name || '';
      if (/\.pdf$/i.test(name)) {
        const r = await this.analyzeElevations(file, options);
        const k = r.keywords || { elev: [], plan: [], hint: [] };
        const word = (a) => '「' + a[0] + '」';
        if (r.walls.length) {
          return { type: 'elev', elev: r, canSwitch: true,
            reason: '壁の高さの表記（▼FL と天井高）と寸法から、壁を ' + r.walls.length + ' 面読み取れたため' + (k.elev.length ? '（図面の文字 ' + word(k.elev) + '）' : '') };
        }
        if (k.elev.length > k.plan.length) {
          return { type: 'elev', elev: r, canSwitch: true, reason: '図面に ' + word(k.elev) + ' とあるため（壁の自動検出はできませんでした）' };
        }
        return { type: 'plan', elev: r, canSwitch: true,
          reason: k.plan.length ? '図面に ' + word(k.plan) + ' とあるため'
            : (k.hint.length ? '高さの表記（' + k.hint[0] + '）はありますが、壁の寸法として読み取れず、「展開図」などの表記もないため'
              : '壁の高さの表記（▼FL と天井高）が見つからないため') };
      }
      if (/\.dxf$/i.test(name)) {
        const text = this._decodeDXF(await file.arrayBuffer());
        const strs = [];
        const re = /\n\s*(?:1|3)\r?\n([^\r\n]*)/g;
        let mm;
        while ((mm = re.exec(text))) strs.push(this._cleanDxfText(mm[1]));
        const k = this.drawingKeywords(strs);
        if (k.elev.length > k.plan.length) {
          return { type: 'plan', canSwitch: false, warn: true,
            reason: '図面に「' + k.elev[0] + '」とあり、展開図の可能性があります。DXFの展開図は読み取れないため、PDFで書き出して入れてください' };
        }
        return { type: 'plan', canSwitch: false, reason: k.plan.length ? '図面に「' + k.plan[0] + '」とあるため' : 'DXFは平面図として読み取るため' };
      }
      if (/\.json$/i.test(name)) return { type: 'plan', canSwitch: false, reason: '間取りデータ（JSON）のため' };
      return { type: 'plan', canSwitch: false, reason: '画像は中身の文字を読めないため、平面図として扱います' };
    }

    static elevationsFromText(items, pageW, pageH, scaleIn) {
      const PT = 72 / 25.4; // points per mm at 1:1
      const num = (s) => { const t = s.replace(/,/g, '').trim(); return /^\d+(\.\d+)?$/.test(t) ? parseFloat(t) : NaN; };
      const T = items.filter((i) => i.str && i.str.trim()).map((i) => ({ s: i.str.trim(), x: i.transform[4], y: i.transform[5], w: i.width, v: num(i.str) }));
      const fl = T.filter((t) => /FL[±+\-]/.test(t.s));
      const dims = T.filter((t) => !isNaN(t.v) && t.v >= 20);
      let N = scaleIn > 1 ? scaleIn : 0;
      if (!N) {
        // the scale that best explains the spacing of neighbouring chain dimensions
        const rows = {};
        dims.forEach((d) => { const k = Math.round(d.y / 2); (rows[k] = rows[k] || []).push(d); });
        const std = [10, 20, 25, 30, 40, 50, 60, 75, 100, 150, 200];
        const score = std.map(() => 0);
        Object.values(rows).forEach((r) => {
          r.sort((a, b) => a.x - b.x);
          for (let i = 1; i < r.length; i++) {
            const a = r[i - 1], b = r[i];
            const dpt = (b.x + b.w / 2) - (a.x + a.w / 2);
            if (dpt < 8) continue;
            std.forEach((n, k) => { const exp = ((a.v + b.v) / 2) * PT / n; if (Math.abs(exp - dpt) < 0.08 * exp + 1) score[k]++; });
          }
        });
        const best = Math.max(...score);
        if (best >= 2) N = std[score.indexOf(best)];
      }
      if (!N) return { scale: 0, walls: [] };

      // ceiling heights at the page edges, matched with the "▼FL±0" mark below them
      const hl = T.filter((t) => !isNaN(t.v) && t.v >= 1800 && t.v <= 4500 && (t.x < 60 || t.x > pageW - 90));
      const strips = [];
      hl.forEach((h) => {
        const below = fl.filter((f) => f.y < h.y + 2 && h.y - f.y < 150 && Math.abs(f.x - h.x) < 80).sort((a, b) => b.y - a.y)[0];
        if (!below || strips.some((s) => Math.abs(s.floor - below.y) < 4)) return;
        strips.push({ floor: below.y, H: h.v });
      });
      strips.sort((a, b) => b.floor - a.floor); // from the top row of the sheet

      const walls = [];
      strips.forEach((st, si) => {
        const top = st.floor + st.H / N * PT;
        const above = strips[si - 1] ? strips[si - 1].floor - 4 : pageH;
        const lim = Math.min(top + 100, above);
        const band = dims.filter((d) => d.y > top - 2 && d.y < lim).map((d) => ({ ...d, c: d.x + d.w / 2, sp: d.v / N * PT }));
        const rowsB = {};
        band.forEach((d) => { const k = Math.round(d.y / 3); (rowsB[k] = rowsB[k] || []).push(d); });
        const runs = [];
        Object.values(rowsB).forEach((r) => {
          r.sort((a, b) => a.c - b.c);
          let cur = null;
          r.forEach((d) => {
            const a = d.c - d.sp / 2, b = d.c + d.sp / 2;
            if (cur && a - cur.b < 8) { cur.b = Math.max(cur.b, b); cur.v += d.v; } else { cur = { a, b, v: d.v }; runs.push(cur); }
          });
        });
        // dimension rows of one drawing overlap: keep the longest chain per drawing
        runs.sort((a, b) => b.v - a.v);
        const groups = [];
        runs.forEach((r) => {
          const g = groups.find((q) => r.a < q.b - 4 && r.b > q.a + 4);
          if (g) { g.a = Math.min(g.a, r.a); g.b = Math.max(g.b, r.b); } else groups.push({ ...r });
        });
        groups.sort((a, b) => a.a - b.a);
        let k = 0;
        groups.forEach((g) => {
          if (g.v < 500) return;
          k++;
          walls.push({
            id: 'wall_' + (si + 1) + '_' + k, name: (si + 1) + '段目 壁' + k, strip: si + 1,
            width: Math.round(g.v * 10) / 10, height: st.H, x0: g.a, x1: g.b, y0: st.floor, y1: top, cloth: null,
          });
        });
      });

      // wall paper callouts (クロス): the nearest wall in the same row
      const cloth = T.filter((t) => /ｸﾛｽ|クロス|壁紙/.test(t.s));
      cloth.forEach((c) => {
        let best = null, bd = 1e9;
        walls.forEach((w) => {
          if (c.y < w.y0 - 40 || c.y > w.y1 + 40) return;
          const dx = c.x < w.x0 ? w.x0 - c.x : (c.x > w.x1 ? c.x - w.x1 : 0);
          if (dx < 60 && dx < bd) { bd = dx; best = w; }
        });
        if (best) best.cloth = best.cloth ? (best.cloth.indexOf(c.s) < 0 ? best.cloth + ' / ' + c.s : best.cloth) : c.s;
      });
      return { scale: N, walls };
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

    // ------------------------------------------------------- image (PNG / JPEG)
    // A raster plan has no geometry and no scale: rooms are the enclosed light areas between dark
    // lines, measured in pixels. metadata.raster keeps the pixel polygons so that the scale can be
    // set afterwards (roomsFromRaster).
    static async parseImage(file, options = {}) {
      const img = await this._loadImage(file);
      const MAX = options.maxSide || 1200;
      const k = Math.min(1, MAX / Math.max(img.width, img.height));
      const W = Math.max(1, Math.round(img.width * k));
      const H = Math.max(1, Math.round(img.height * k));
      const cv = document.createElement('canvas');
      cv.width = W;
      cv.height = H;
      const ctx = cv.getContext('2d', { willReadFrequently: true });
      ctx.fillStyle = '#fff'; // transparent PNG background counts as white
      ctx.fillRect(0, 0, W, H);
      ctx.drawImage(img, 0, 0, W, H);
      return this._detectRaster(cv, options);
    }

    // Finds the rooms in a drawing that is already on a canvas (also used for scanned PDF pages).
    static _detectRaster(cv, options = {}) {
      const W = cv.width, H = cv.height;
      const px = cv.getContext('2d', { willReadFrequently: true }).getImageData(0, 0, W, H).data;

      const gray = new Uint8Array(W * H);
      const hist = new Array(256).fill(0);
      for (let i = 0, p = 0; i < gray.length; i++, p += 4) {
        const g = ((px[p] * 299 + px[p + 1] * 587 + px[p + 2] * 114) / 1000) | 0;
        gray[i] = g;
        hist[g]++;
      }
      const auto = options.threshold == null || options.threshold === 'auto' || Number(options.threshold) <= 0;
      const threshold = auto ? Math.min(this._otsu(hist, gray.length), 160) : Number(options.threshold);
      // the gap setting is in pixels of a 1200 px wide picture; scale it for bigger renders (PDF pages)
      const sc = Math.max(1, Math.max(W, H) / 1200);
      const gap = Math.round((options.gap == null ? 4 : Math.max(0, Math.min(20, Number(options.gap)))) * sc);

      const wall = new Uint8Array(W * H);
      for (let i = 0; i < wall.length; i++) wall[i] = gray[i] < threshold ? 1 : 0;

      // close small gaps (door openings, broken lines) so that rooms do not leak into each other
      const blocked = this._dilate(wall, W, H, gap);
      const { labels, areas, border } = this._label(blocked, W, H);

      // the exterior is the biggest light area that touches the image border
      let exterior = 0;
      areas.forEach((a, id) => { if (id && border[id] && (!exterior || a > areas[exterior])) exterior = id; });

      // give the pixels of the closed gap band back to the nearest room (rooms keep their true size)
      this._grow(labels, wall, W, H, gap);

      const minArea = Math.max(150, (options.minAreaRatio || 0.002) * W * H);
      const eps = 2 + 0.002 * Math.max(W, H);
      const loops = this._outlines(labels, W, H, exterior);
      let polys = [];
      loops.forEach((pts, id) => {
        let poly = this._simplify(pts, eps);
        poly = this._despike(poly, 2 * gap + 8 * sc);
        poly = this._rectify(poly, 2 * gap + 8 * sc);
        // an almost rectangular room (door arcs, small notches) becomes an exact rectangle;
        // rooms with a real step, like an L shape, keep their outline
        const bb = this.bounds(poly);
        const boxArea = (bb.maxX - bb.minX) * (bb.maxY - bb.minY);
        if (boxArea > 0 && this.calculateArea(poly) / boxArea >= 0.95) {
          poly = [[bb.minX, bb.minY], [bb.maxX, bb.minY], [bb.maxX, bb.maxY], [bb.minX, bb.maxY]];
        }
        if (poly.length >= 3 && this.calculateArea(poly) >= minArea) polys.push(poly);
      });
      // a closed shape inside another room is furniture or a pillar, not a room
      polys = polys.filter((p, i) => {
        const c = [(this.bounds(p).minX + this.bounds(p).maxX) / 2, (this.bounds(p).minY + this.bounds(p).maxY) / 2];
        const area = this.calculateArea(p);
        return !polys.some((q, j) => j !== i && this.calculateArea(q) > area && this.pointInPolygon(c, q));
      });
      if (!polys.length && !options.allowEmpty) {
        throw new Error(
          '部屋を検出できませんでした。線が薄い・細いときは「線を検出する濃さ」を上げ、' +
          '部屋がつながって見えるときは「すき間を閉じる」を大きくしてみてください。'
        );
      }

      // reading order: top to bottom (rows within 6% of the height), then left to right
      const cen = (poly) => {
        const b = this.bounds(poly);
        return [(b.minX + b.maxX) / 2, (b.minY + b.maxY) / 2];
      };
      polys.sort((a, b) => cen(a)[1] - cen(b)[1]);
      const ordered = [];
      const tol = 0.06 * H;
      while (polys.length) {
        const rowY = cen(polys[0])[1];
        const row = polys.filter((q) => cen(q)[1] - rowY <= tol);
        polys = polys.filter((q) => !row.includes(q));
        row.sort((a, b) => cen(a)[0] - cen(b)[0]);
        ordered.push(...row);
      }

      const provisional = !(Number(options.mmPerPx) > 0);
      const raster = {
        dataUrl: cv.toDataURL('image/jpeg', 0.7),
        widthPx: W,
        heightPx: H,
        mmPerPx: provisional ? 12000 / Math.max(W, H) : Number(options.mmPerPx),
        calibrated: !provisional,
        threshold,
        thresholdAuto: auto,
        gap: options.gap == null ? 4 : Number(options.gap),
        polys: ordered,
      };
      return { rooms: this.roomsFromRaster(raster, raster.mmPerPx), metadata: { source: 'image', raster } };
    }

    // pixel polygons -> rooms in mm (image Y points down, CAD Y points up)
    static roomsFromRaster(raster, mmPerPx) {
      return raster.polys.map((poly, i) => this.normalizeRoom({
        id: 'room_' + (i + 1),
        name: '部屋 ' + (i + 1),
        vertices: poly.map(([x, y]) => [x * mmPerPx, (raster.heightPx - y) * mmPerPx]),
      }, i));
    }

    static _loadImage(file) {
      return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
        img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('画像を読み込めませんでした。')); };
        img.src = url;
      });
    }

    static _otsu(hist, total) {
      let sum = 0;
      for (let i = 0; i < 256; i++) sum += i * hist[i];
      let sumB = 0, wB = 0, best = 0, thr = 128;
      for (let t = 0; t < 256; t++) {
        wB += hist[t];
        if (!wB) continue;
        const wF = total - wB;
        if (!wF) break;
        sumB += t * hist[t];
        const mB = sumB / wB, mF = (sum - sumB) / wF;
        const between = wB * wF * (mB - mF) * (mB - mF);
        if (between > best) { best = between; thr = t; }
      }
      return thr;
    }

    // square dilation by r pixels (two separable passes with running sums)
    static _dilate(mask, W, H, r) {
      if (!r) return mask.slice();
      const tmp = new Uint8Array(W * H);
      const out = new Uint8Array(W * H);
      const pass = (src, dst, len, lines, stride, step) => {
        const pre = new Int32Array(len + 1);
        for (let l = 0; l < lines; l++) {
          const base = l * stride;
          for (let i = 0; i < len; i++) pre[i + 1] = pre[i] + src[base + i * step];
          for (let i = 0; i < len; i++) {
            const a = Math.max(0, i - r), b = Math.min(len, i + r + 1);
            dst[base + i * step] = pre[b] - pre[a] > 0 ? 1 : 0;
          }
        }
      };
      pass(mask, tmp, W, H, W, 1);
      pass(tmp, out, H, W, 1, W);
      return out;
    }

    // 4-connected components of the zero pixels; id 0 = blocked
    static _label(blocked, W, H) {
      const labels = new Int32Array(W * H);
      const areas = [0];
      const border = [false];
      const stack = new Int32Array(W * H);
      let id = 0;
      for (let s0 = 0; s0 < labels.length; s0++) {
        if (blocked[s0] || labels[s0]) continue;
        id++;
        let sp = 0, area = 0, touches = false;
        stack[sp++] = s0;
        labels[s0] = id;
        while (sp) {
          const p = stack[--sp];
          area++;
          const x = p % W, y = (p / W) | 0;
          if (x === 0 || y === 0 || x === W - 1 || y === H - 1) touches = true;
          if (x > 0 && !blocked[p - 1] && !labels[p - 1]) { labels[p - 1] = id; stack[sp++] = p - 1; }
          if (x < W - 1 && !blocked[p + 1] && !labels[p + 1]) { labels[p + 1] = id; stack[sp++] = p + 1; }
          if (y > 0 && !blocked[p - W] && !labels[p - W]) { labels[p - W] = id; stack[sp++] = p - W; }
          if (y < H - 1 && !blocked[p + W] && !labels[p + W]) { labels[p + W] = id; stack[sp++] = p + W; }
        }
        areas.push(area);
        border.push(touches);
      }
      return { labels, areas, border };
    }

    // expand every region by up to r pixels over light pixels that the dilation had blocked
    static _grow(labels, wall, W, H, r) {
      if (!r) return;
      const D = [-W - 1, -W, -W + 1, -1, 1, W - 1, W, W + 1];
      const DX = [-1, 0, 1, -1, 1, -1, 0, 1];
      const DY = [-1, -1, -1, 0, 0, 1, 1, 1];
      const open = (p, x, y, k) => {
        const nx = x + DX[k], ny = y + DY[k];
        return nx >= 0 && nx < W && ny >= 0 && ny < H && !labels[p + D[k]] && !wall[p + D[k]];
      };
      let frontier = [];
      for (let p = 0; p < labels.length; p++) {
        if (!labels[p]) continue;
        const x = p % W, y = (p / W) | 0;
        for (let k = 0; k < 8; k++) if (open(p, x, y, k)) { frontier.push(p); break; }
      }
      // 8-connected steps reach the same square distance as the dilation, so corners come back exactly
      for (let step = 0; step < r && frontier.length; step++) {
        const next = [];
        for (const p of frontier) {
          const x = p % W, y = (p / W) | 0;
          for (let k = 0; k < 8; k++) {
            if (open(p, x, y, k)) { labels[p + D[k]] = labels[p]; next.push(p + D[k]); }
          }
        }
        frontier = next;
      }
    }

    // outer boundary (pixel corners, clockwise) of every region except `skip`
    static _outlines(labels, W, H, skip) {
      const edges = new Map(); // label -> Map(startKey -> [endKey...])
      const key = (x, y) => y * (W + 1) + x;
      const add = (id, a, b) => {
        let m = edges.get(id);
        if (!m) { m = new Map(); edges.set(id, m); }
        const arr = m.get(a);
        if (arr) arr.push(b); else m.set(a, [b]);
      };
      for (let y = 0; y < H; y++) {
        for (let x = 0; x < W; x++) {
          const id = labels[y * W + x];
          if (!id || id === skip) continue;
          if (y === 0 || labels[(y - 1) * W + x] !== id) add(id, key(x, y), key(x + 1, y));
          if (x === W - 1 || labels[y * W + x + 1] !== id) add(id, key(x + 1, y), key(x + 1, y + 1));
          if (y === H - 1 || labels[(y + 1) * W + x] !== id) add(id, key(x + 1, y + 1), key(x, y + 1));
          if (x === 0 || labels[y * W + x - 1] !== id) add(id, key(x, y + 1), key(x, y));
        }
      }
      const out = new Map();
      edges.forEach((m, id) => {
        let best = null, bestArea = 0;
        for (const [start] of m) {
          if (!m.get(start) || !m.get(start).length) continue;
          const pts = [];
          let cur = start;
          let guard = 0;
          while (guard++ < 5e6) {
            const nexts = m.get(cur);
            if (!nexts || !nexts.length) break;
            pts.push([cur % (W + 1), (cur / (W + 1)) | 0]);
            cur = nexts.pop();
            if (cur === start) break;
          }
          if (pts.length >= 3) {
            const a = this.calculateArea(pts);
            if (a > bestArea) { bestArea = a; best = pts; }
          }
        }
        if (best) out.set(id, best);
      });
      return out;
    }

    // Douglas-Peucker on a closed loop
    static _simplify(pts, eps) {
      if (pts.length < 4) return pts;
      // split at the two points that are farthest apart
      let a = 0, b = 0, far = -1;
      for (let i = 1; i < pts.length; i++) {
        const d = (pts[i][0] - pts[0][0]) ** 2 + (pts[i][1] - pts[0][1]) ** 2;
        if (d > far) { far = d; b = i; }
      }
      const dp = (list) => {
        if (list.length < 3) return list;
        const [x1, y1] = list[0], [x2, y2] = list[list.length - 1];
        const len = Math.hypot(x2 - x1, y2 - y1) || 1;
        let idx = 0, dmax = 0;
        for (let i = 1; i < list.length - 1; i++) {
          const d = Math.abs((y2 - y1) * list[i][0] - (x2 - x1) * list[i][1] + x2 * y1 - y2 * x1) / len;
          if (d > dmax) { dmax = d; idx = i; }
        }
        if (dmax <= eps) return [list[0], list[list.length - 1]];
        return dp(list.slice(0, idx + 1)).slice(0, -1).concat(dp(list.slice(idx)));
      };
      const first = pts.slice(a, b + 1);
      const second = pts.slice(b).concat(pts.slice(0, a + 1));
      return dp(first).slice(0, -1).concat(dp(second).slice(0, -1));
    }

    // drop thin spikes (a vertex whose two neighbours are close together, e.g. a door-swing arc)
    static _despike(poly, width) {
      let pts = poly;
      let changed = true;
      while (changed && pts.length > 3) {
        changed = false;
        for (let i = 0; i < pts.length && !changed; i++) {
          const A = pts[(i + pts.length - 1) % pts.length], C = pts[(i + 1) % pts.length];
          if (Math.hypot(A[0] - C[0], A[1] - C[1]) <= width) {
            pts = pts.filter((_, k) => k !== i);
            changed = true;
          }
        }
      }
      return pts;
    }

    // make nearly horizontal / vertical edges exact, then drop duplicate and collinear points
    static _rectify(poly, bump = 0) {
      const n = poly.length;
      if (n < 3) return poly;
      const p = poly.map((q) => [q[0], q[1]]);
      for (let i = 0; i < n; i++) {
        const a = p[i], b = p[(i + 1) % n];
        const dx = b[0] - a[0], dy = b[1] - a[1];
        const len = Math.hypot(dx, dy);
        if (!len) continue;
        if (Math.abs(dy) / len < 0.1) { const y = (a[1] + b[1]) / 2; a[1] = y; b[1] = y; }
        else if (Math.abs(dx) / len < 0.1) { const x = (a[0] + b[0]) / 2; a[0] = x; b[0] = x; }
      }
      // flatten small protrusions / notches (door openings, wall thickness): A->B and C->D are short
      // and opposite, so B and C are dropped and the outline runs straight from A to D
      let pts = p;
      const len2 = (a, b) => Math.hypot(b[0] - a[0], b[1] - a[1]);
      let changed = bump > 0;
      while (changed && pts.length > 4) {
        changed = false;
        const m = pts.length;
        for (let i = 0; i < m && !changed; i++) {
          const A = pts[i], B = pts[(i + 1) % m], C = pts[(i + 2) % m], D = pts[(i + 3) % m];
          const e1 = [B[0] - A[0], B[1] - A[1]], e3 = [D[0] - C[0], D[1] - C[1]];
          const opposite = e1[0] * e3[0] + e1[1] * e3[1] < 0;
          const sameAxis = Math.abs(e1[0] * e3[1] - e1[1] * e3[0]) < 1e-6;
          if (len2(A, B) <= bump && len2(C, D) <= bump && opposite && sameAxis) {
            const drop = new Set([(i + 1) % m, (i + 2) % m]);
            pts = pts.filter((_, k) => !drop.has(k));
            changed = true;
          }
        }
      }
      const out = [];
      for (let i = 0; i < pts.length; i++) {
        const prev = out[out.length - 1];
        if (!prev || Math.hypot(pts[i][0] - prev[0], pts[i][1] - prev[1]) > 0.5) out.push(pts[i]);
      }
      if (out.length > 1 && Math.hypot(out[0][0] - out[out.length - 1][0], out[0][1] - out[out.length - 1][1]) <= 0.5) out.pop();
      // collinear
      const res = [];
      for (let i = 0; i < out.length; i++) {
        const a = out[(i + out.length - 1) % out.length], b = out[i], c = out[(i + 1) % out.length];
        const cross = (b[0] - a[0]) * (c[1] - b[1]) - (b[1] - a[1]) * (c[0] - b[0]);
        if (Math.abs(cross) > 1) res.push(b);
      }
      return res.length >= 3 ? res : out;
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
