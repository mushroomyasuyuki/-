/**
 * ThreeRoomRenderer: draws CADParser room data with Three.js.
 * Input is millimetres (CAD, Y up); the scene is metres with CAD (x, y) -> world (x, 0, -y).
 */
(function (root) {
  'use strict';

  const MM = 0.001;
  const FLOOR_COLOR = 0x8a7b5e;
  const WALL_COLOR = 0xd8d8dc;

  class ThreeRoomRenderer {
    constructor(container, options = {}) {
      if (typeof THREE === 'undefined') throw new Error('Three.js が読み込まれていません。');
      this.container = container;
      this.options = Object.assign({ width: 800, height: 600, backgroundColor: 0x09090b }, options);

      this.floors = [];
      this.walls = [];
      this.rooms = [];
      this.bounds = null;
      this._raf = 0;
      this._textures = { floor: null, wall: null };

      const w = container.clientWidth || this.options.width;
      const h = container.clientHeight || this.options.height;

      this.scene = new THREE.Scene();
      this.scene.background = new THREE.Color(this.options.backgroundColor);
      this.camera = new THREE.PerspectiveCamera(45, w / h, 0.05, 500);

      this.renderer = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true });
      this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
      this.renderer.setSize(w, h);
      if ('outputColorSpace' in this.renderer) this.renderer.outputColorSpace = THREE.SRGBColorSpace;
      else this.renderer.outputEncoding = THREE.sRGBEncoding;
      const el = this.renderer.domElement;
      el.style.display = 'block';
      el.style.width = '100%';
      el.style.height = '100%';
      el.style.touchAction = 'none';
      container.appendChild(el);

      this.scene.add(new THREE.AmbientLight(0xffffff, 0.7));
      const sun = new THREE.DirectionalLight(0xffffff, 0.3);
      sun.position.set(3, 8, 4);
      this.scene.add(sun);

      this.orbit = { target: new THREE.Vector3(0, 0.5, 0), radius: 8, theta: Math.PI / 4, phi: 1.0 };
      this._bindControls();

      if (typeof ResizeObserver !== 'undefined') {
        this._ro = new ResizeObserver(() => this.resize());
        this._ro.observe(container);
      }
      this._updateCamera();
    }

    // ------------------------------------------------------------ public API
    loadFloorPlan(data) {
      root.CADParser.validate(data);
      this._clear();
      this.rooms = data.rooms;

      let minX = Infinity, minZ = Infinity, maxX = -Infinity, maxZ = -Infinity, maxH = 0;
      this.rooms.forEach((room, ri) => {
        const pts = room.vertices.map(([x, y]) => new THREE.Vector2(x * MM, -y * MM)); // (x, z)
        pts.forEach((p) => {
          minX = Math.min(minX, p.x); maxX = Math.max(maxX, p.x);
          minZ = Math.min(minZ, p.y); maxZ = Math.max(maxZ, p.y);
        });
        this._buildFloor(room, pts);
        // position of every wall along the room's perimeter, so that a pattern can run on round the corners
        const lens = room.walls.map((w) => pts[w.from].distanceTo(pts[w.to]) / MM);
        const perimeter = lens.reduce((s, l) => s + l, 0);
        let along = 0;
        room.walls.forEach((wall, wi) => {
          maxH = Math.max(maxH, (wall.height || 2400) * MM);
          this._buildWall(room, pts, wall, ri, wi, { along, perimeter });
          along += lens[wi];
        });
      });
      this.bounds = { minX, minZ, maxX, maxZ, maxH };
      this._applyTextures();
      this.resetCamera();
    }

    setFloorTexture(url) {
      this._loadTexture(url, (t) => this._setTexture('floor', t));
    }

    // index === undefined: apply to every wall that has no texture of its own
    setWallTexture(url, index) {
      this._loadTexture(url, (t) => {
        if (index == null) {
          this._setTexture('wall', t);
        } else if (this.walls[index]) {
          this.walls[index].custom = true;
          this.walls[index].mesh.material.map = t;
          this.walls[index].mesh.material.color.set(0xffffff);
          this.walls[index].mesh.material.needsUpdate = true;
          this._render();
        }
      });
    }

    // Textures straight from a <canvas> (e.g. the reduced-colour design image). null clears.
    setFloorCanvas(canvas) {
      this._setTexture('floor', canvas ? this._canvasTexture(canvas) : null);
    }

    setWallCanvas(canvas) {
      this._setTexture('wall', canvas ? this._canvasTexture(canvas, true) : null);
    }

    // Wallpaper pattern size on the walls: one repeat is w x h mm (keep the picture's aspect ratio).
    // null stretches the picture over each wall.
    // aspect: picture width / height, used when not repeating so that one picture covers each wall
    // without distortion (centred, the overflow cut off).
    // singleScale: size of the single picture relative to the size that covers every wall (1 = 100 %)
    // mode: 'repeat' (pattern of w x h mm), 'center' (one picture centred on every wall) or
    // 'wrap' (one picture running on round the room, unbroken at the corners)
    setWallRepeat(w, h, aspect, singleScale, mode) {
      this.wallMode = mode || (w > 0 ? 'repeat' : 'center');
      this.wallRepeat = w > 0 && h > 0 ? { w, h } : null;
      this.wallAspect = aspect > 0 ? aspect : null;
      this.wallSingleScale = singleScale > 0 ? singleScale : 1;
      this._measureWalls();
      this.walls.forEach((e) => this._wallUV(e));
      if (this._textures.wall) {
        const t = this._textures.wall;
        t.wrapS = t.wrapT = this.wallRepeat ? THREE.RepeatWrapping : THREE.ClampToEdgeWrapping;
        t.needsUpdate = true;
      }
      this._render();
    }

    // The wall(s) of the strip frame view ("壁紙の巾の枠"): one band picture (widths[i] mm wide walls side by
    // side, WH mm high) put on the matching walls of the room, so that they look exactly as in that view.
    // The other walls keep the usual wall paper. band: { widths: [mm...], WH, top } or null; canvas: the picture.
    setWallBand(band, canvas) {
      this.wallBand = band || null;
      if (this._bandTex) { this._bandTex.dispose(); this._bandTex = null; }
      if (band && canvas) {
        const t = new THREE.CanvasTexture(canvas);
        t.minFilter = THREE.LinearFilter;
        t.generateMipmaps = false;
        t.wrapS = t.wrapT = THREE.ClampToEdgeWrapping;
        this._prepareTexture(t);
        this._bandTex = t;
      }
      this._bandKey = null;
      this.walls.forEach((e) => this._wallUV(e));
      this._applyTextures();
    }

    // which room walls get which part of the band: { entry -> { b0, bw } } (by length, in the band's order)
    _bandMatch(entry) {
      const band = this.wallBand;
      const key = this.walls.length + '|' + band.widths.join(',') + '|' + entry.roomIndex;
      if (this._bandKey !== key) {
        this._bandKey = key;
        this._bandMap = new Map();
        const ws = band.widths, m = ws.length;
        const B = [];
        let acc = 0;
        ws.forEach((w) => { B.push(acc); acc += w; });
        // the room's walls left to right seen from inside (growing s0)
        const same = this.walls.filter((e) => e.roomIndex === entry.roomIndex).sort((a, b) => a.s0 - b.s0);
        const n = same.length;
        if (m <= n) {
          // the run of m walls (round the room) whose lengths agree best
          let best = 0, bestErr = Infinity;
          for (let k = 0; k < n; k++) {
            const err = ws.reduce((t, w, i) => t + Math.abs(w - same[(i + k) % n].len / MM), 0);
            if (err < bestErr) { bestErr = err; best = k; }
          }
          ws.forEach((w, i) => this._bandMap.set(same[(i + best) % n], { b0: B[i], bw: w }));
        } else {
          // more band walls than room walls: lay the band along the perimeter at its real size
          same.forEach((e) => this._bandMap.set(e, { b0: e.s0 || 0, bw: e.len / MM, along: true }));
        }
        this._bandW = acc;
      }
      return this._bandMap.get(entry) || null;
    }

    _bandUV(entry, uv, map) {
      const band = this.wallBand;
      const hMm = entry.h / MM;
      for (let j = 0; j < uv.count; j++) {
        const u0 = j % 2, v0 = j < 2 ? 1 : 0;
        // position in the band (mm), at its real size: the band wall centred on the room wall (never stretched)
        const L = entry.len / MM;
        const b = map.along ? map.b0 + u0 * map.bw : map.b0 + map.bw / 2 + (u0 - 0.5) * L;
        const v = band.top ? 1 - (1 - v0) * hMm / band.WH : v0 * hMm / band.WH;
        const pu = band.pu || 0, pv = band.pv || 0; // the picture sits inside a thin white rim of the texture
        uv.setXY(j, pu + (b / this._bandW) * (1 - 2 * pu), pv + v * (1 - 2 * pv));
      }
      uv.needsUpdate = true;
    }

    // the wall paper picture moved by x (right) / y (down) mm (the strip frame view's "画像を移動")
    setWallShift(x, y) {
      this.wallShift = { x: x || 0, y: y || 0 };
      this.walls.forEach((e) => this._wallUV(e));
      this._render();
    }

    _measureWalls() {
      this._maxWall = { len: 1, h: 1 };
      this.walls.forEach((e) => {
        this._maxWall.len = Math.max(this._maxWall.len, e.len / MM);
        this._maxWall.h = Math.max(this._maxWall.h, e.h / MM);
      });
    }

    // UVs in "repeats": u = distance along the wall / repeat width, v = height / repeat height.
    // The pattern starts at the ceiling (top edge) like hung wallpaper.
    _wallUV(entry) {
      const uv = entry.mesh.geometry.attributes.uv;
      entry.band = false;
      if (this.wallBand) {
        const map = this._bandMatch(entry);
        if (map) { entry.band = true; this._bandUV(entry, uv, map); return; }
      }
      const rep = this.wallRepeat;
      const lenMm = entry.len / MM, hMm = entry.h / MM;
      const shx = (this.wallShift || {}).x || 0, shy = (this.wallShift || {}).y || 0;
      for (let i = 0; i < uv.count; i++) {
        const u0 = i % 2, v0 = i < 2 ? 1 : 0; // PlaneGeometry(1x1 segment): (0,1) (1,1) (0,0) (1,0)
        const s = (entry.s0 || 0) + u0 * lenMm; // position along the perimeter
        if (rep) uv.setXY(i, (s - shx) / rep.w, 1 - ((1 - v0) * hMm - shy) / rep.h);
        else if (this.wallAspect && this.wallMode === 'wrap') {
          // one picture round the room: as wide as the perimeter (and the wall height), times the size setting
          const P = Math.max(entry.perimeter || lenMm, 1);
          const tr = this.wallTrim || { side: 0, top: 0, bottom: 0 };
          const imgW = Math.max(P + tr.side * 2, (this._maxWall.h + tr.top + tr.bottom) * this.wallAspect) * (this.wallSingleScale || 1);
          const imgH = imgW / this.wallAspect;
          const b0 = (P - imgW) / 2;
          uv.setXY(i, (s - b0 - shx) / imgW, 0.5 + ((v0 - 0.5) * hMm + shy) / imgH);
        }
        else if (this.wallAspect) {
          // one picture, the same size on every wall: big enough to cover the longest wall and the
          // highest wall, centred on each wall (the overflow is cut off)
          const tr = this.wallTrim || { side: 0, top: 0, bottom: 0 };
          const imgW = Math.max(this._maxWall.len + tr.side * 2, (this._maxWall.h + tr.top + tr.bottom) * this.wallAspect) * (this.wallSingleScale || 1);
          const imgH = imgW / this.wallAspect;
          uv.setXY(i, 0.5 + ((u0 - 0.5) * lenMm - shx) / imgW, 0.5 + ((v0 - 0.5) * hMm + shy) / imgH);
        } else uv.setXY(i, u0, v0);
      }
      uv.needsUpdate = true;
    }

    // remove the room (no rooms to show)
    clear() {
      this._clear();
      this.rooms = [];
      this.bounds = null;
      this._render();
    }

    resetCamera() {
      if (!this.bounds) return;
      const b = this.bounds;
      const sizeX = b.maxX - b.minX, sizeZ = b.maxZ - b.minZ;
      const size = Math.max(sizeX, sizeZ, b.maxH, 1);
      this.orbit.target.set((b.minX + b.maxX) / 2, b.maxH * 0.3, (b.minZ + b.maxZ) / 2);
      this.orbit.radius = size * 1.9;
      this.orbit.theta = Math.PI / 4;
      this.orbit.phi = 0.95;
      this._updateCamera();
    }

    getAreas() {
      let floor = 0, wall = 0;
      this.rooms.forEach((r) => {
        floor += root.CADParser.calculateArea(r.vertices);
        wall += root.CADParser.calculateWallArea(r.walls);
      });
      return { floorArea: Math.round(floor / 1e4) / 100, wallArea: Math.round(wall / 1e4) / 100 };
    }

    resize() {
      const w = this.container.clientWidth, h = this.container.clientHeight;
      if (!w || !h) return;
      this.renderer.setSize(w, h);
      this.camera.aspect = w / h;
      this.camera.updateProjectionMatrix();
      this._render();
    }

    dispose() {
      cancelAnimationFrame(this._raf);
      if (this._ro) this._ro.disconnect();
      this._clear();
      this.renderer.dispose();
      if (this.renderer.domElement.parentNode) this.renderer.domElement.parentNode.removeChild(this.renderer.domElement);
    }

    // -------------------------------------------------------------- geometry
    _buildFloor(room, pts) {
      const geo = new THREE.ShapeGeometry(new THREE.Shape(room.vertices.map(([x, y]) => new THREE.Vector2(x * MM, y * MM))));
      // normalise UVs so one image spans the whole floor (north up)
      const pos = geo.attributes.position;
      let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
      for (let i = 0; i < pos.count; i++) {
        minX = Math.min(minX, pos.getX(i)); maxX = Math.max(maxX, pos.getX(i));
        minY = Math.min(minY, pos.getY(i)); maxY = Math.max(maxY, pos.getY(i));
      }
      const uv = geo.attributes.uv;
      for (let i = 0; i < pos.count; i++) {
        uv.setXY(i, (pos.getX(i) - minX) / (maxX - minX || 1), (pos.getY(i) - minY) / (maxY - minY || 1));
      }
      geo.rotateX(-Math.PI / 2); // (x, y, 0) -> (x, 0, -y)
      const mat = new THREE.MeshStandardMaterial({ color: FLOOR_COLOR, roughness: 0.95, metalness: 0, side: THREE.DoubleSide });
      const mesh = new THREE.Mesh(geo, mat);
      this.scene.add(mesh);
      this.floors.push({ mesh });
    }

    _buildWall(room, pts, wall, ri, wi, run = { along: 0, perimeter: 0 }) {
      const a = pts[wall.from], b = pts[wall.to];
      const dx = b.x - a.x, dz = b.y - a.y;
      const len = Math.hypot(dx, dz);
      if (len < 1e-6) return;
      const h = (wall.height || 2400) * MM;

      let angle = -Math.atan2(dz, dx);
      // plane front face normal is (sin, cos) after rotation.y = angle; make it face the room interior
      const mx = (a.x + b.x) / 2, mz = (a.y + b.y) / 2;
      const probe = new THREE.Vector2(mx + Math.sin(angle) * 0.02, mz + Math.cos(angle) * 0.02);
      const flipped = !this._inside(probe, pts);
      if (flipped) angle += Math.PI;

      const geo = new THREE.PlaneGeometry(len, h);
      const mat = new THREE.MeshStandardMaterial({ color: WALL_COLOR, roughness: 0.9, metalness: 0, side: THREE.FrontSide });
      const mesh = new THREE.Mesh(geo, mat);
      mesh.position.set(mx, h / 2, mz);
      mesh.rotation.y = angle;
      this.scene.add(mesh);

      const edges = new THREE.LineSegments(
        new THREE.EdgesGeometry(geo),
        new THREE.LineBasicMaterial({ color: 0x71717a })
      );
      edges.position.copy(mesh.position);
      edges.rotation.copy(mesh.rotation);
      this.scene.add(edges);

      // s0: where the wall's left edge (seen from inside the room) lies along the perimeter, in mm.
      // A flipped wall runs against the drawing order, so the perimeter is read the other way round.
      const lenMm = len / MM;
      const s0 = flipped ? run.perimeter - (run.along + lenMm) : run.along;
      const entry = { mesh, edges, roomIndex: ri, wallIndex: wi, custom: false, len, h, s0, perimeter: run.perimeter };
      this.walls.push(entry);
      this._measureWalls();
      this.walls.forEach((e) => this._wallUV(e)); // the longest wall may have changed
    }

    _inside(p, pts) {
      let inside = false;
      for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
        const xi = pts[i].x, yi = pts[i].y, xj = pts[j].x, yj = pts[j].y;
        if ((yi > p.y) !== (yj > p.y) && p.x < ((xj - xi) * (p.y - yi)) / (yj - yi) + xi) inside = !inside;
      }
      return inside;
    }

    // -------------------------------------------------------------- textures
    _loadTexture(url, cb) {
      new THREE.TextureLoader().load(url, (t) => {
        this._prepareTexture(t);
        cb(t);
      });
    }

    _prepareTexture(t) {
      if ('colorSpace' in t) t.colorSpace = THREE.SRGBColorSpace;
      else t.encoding = THREE.sRGBEncoding;
      t.anisotropy = this.renderer.capabilities.getMaxAnisotropy();
    }

    _canvasTexture(canvas, repeatable) {
      // copy (max 2048px) so later redraws of the source canvas do not affect it and GPU memory stays bounded
      const max = 2048;
      const k = Math.min(1, max / Math.max(canvas.width, canvas.height));
      const copy = document.createElement('canvas');
      copy.width = Math.max(1, Math.round(canvas.width * k));
      copy.height = Math.max(1, Math.round(canvas.height * k));
      if (repeatable) {
        // a repeating texture must be a power of two (WebGL1); the UVs keep the real aspect ratio
        const pot = (n) => Math.pow(2, Math.round(Math.log2(Math.max(2, n))));
        copy.width = Math.min(2048, pot(copy.width));
        copy.height = Math.min(2048, pot(copy.height));
      }
      const cx = copy.getContext('2d');
      if (repeatable && this.wallSingle) {
        // one picture: a white rim, so that the clamped edge shows white around a smaller picture
        cx.fillStyle = '#ffffff';
        cx.fillRect(0, 0, copy.width, copy.height);
        cx.drawImage(canvas, 2, 2, copy.width - 4, copy.height - 4);
      } else cx.drawImage(canvas, 0, 0, copy.width, copy.height);
      const t = new THREE.CanvasTexture(copy);
      if (repeatable && this.wallRepeat) t.wrapS = t.wrapT = THREE.RepeatWrapping;
      t.minFilter = THREE.LinearFilter;
      t.generateMipmaps = false;
      this._prepareTexture(t);
      return t;
    }

    _setTexture(kind, texture) {
      if (this._textures[kind]) this._textures[kind].dispose();
      this._textures[kind] = texture;
      this._applyTextures();
    }

    _applyTextures() {
      const f = this._textures.floor, w = this._textures.wall;
      const apply = (mat, tex, base) => {
        mat.map = tex || null;
        mat.color.set(tex ? 0xffffff : base);
        mat.needsUpdate = true;
      };
      this.floors.forEach(({ mesh }) => apply(mesh.material, f, FLOOR_COLOR));
      this.walls.forEach((entry) => {
        if (entry.band && this._bandTex) apply(entry.mesh.material, this._bandTex, WALL_COLOR);
        else if (!entry.custom) apply(entry.mesh.material, w, WALL_COLOR);
      });
      this._render();
    }

    // -------------------------------------------------------------- controls
    _bindControls() {
      const el = this.renderer.domElement;
      let last = null;
      el.addEventListener('pointerdown', (e) => { last = [e.clientX, e.clientY]; el.setPointerCapture(e.pointerId); });
      el.addEventListener('pointerup', () => { last = null; });
      el.addEventListener('pointermove', (e) => {
        if (!last) return;
        this.orbit.theta -= (e.clientX - last[0]) * 0.008;
        this.orbit.phi = Math.min(1.5, Math.max(0.1, this.orbit.phi - (e.clientY - last[1]) * 0.008));
        last = [e.clientX, e.clientY];
        this._updateCamera();
      });
      el.addEventListener('wheel', (e) => {
        e.preventDefault();
        this.orbit.radius = Math.min(200, Math.max(0.5, this.orbit.radius * (e.deltaY > 0 ? 1.1 : 0.9)));
        this._updateCamera();
      }, { passive: false });
    }

    _updateCamera() {
      const o = this.orbit;
      this.camera.position.set(
        o.target.x + o.radius * Math.sin(o.phi) * Math.sin(o.theta),
        o.target.y + o.radius * Math.cos(o.phi),
        o.target.z + o.radius * Math.sin(o.phi) * Math.cos(o.theta)
      );
      this.camera.lookAt(o.target);
      this._render();
    }

    _render() {
      if (this._raf) return;
      this._raf = requestAnimationFrame(() => {
        this._raf = 0;
        this.renderer.render(this.scene, this.camera);
      });
    }

    _clear() {
      const drop = (obj) => {
        this.scene.remove(obj);
        obj.geometry && obj.geometry.dispose();
        obj.material && obj.material.dispose();
      };
      this.floors.forEach(({ mesh }) => drop(mesh));
      this.walls.forEach(({ mesh, edges }) => { drop(mesh); drop(edges); });
      this.floors = [];
      this.walls = [];
    }
  }

  root.ThreeRoomRenderer = ThreeRoomRenderer;
  if (typeof module !== 'undefined' && module.exports) module.exports = ThreeRoomRenderer;
})(typeof window !== 'undefined' ? window : globalThis);
