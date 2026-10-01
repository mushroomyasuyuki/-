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
        room.walls.forEach((wall, wi) => {
          maxH = Math.max(maxH, (wall.height || 2400) * MM);
          this._buildWall(room, pts, wall, ri, wi);
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
      this._setTexture('wall', canvas ? this._canvasTexture(canvas) : null);
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

    _buildWall(room, pts, wall, ri, wi) {
      const a = pts[wall.from], b = pts[wall.to];
      const dx = b.x - a.x, dz = b.y - a.y;
      const len = Math.hypot(dx, dz);
      if (len < 1e-6) return;
      const h = (wall.height || 2400) * MM;

      let angle = -Math.atan2(dz, dx);
      // plane front face normal is (sin, cos) after rotation.y = angle; make it face the room interior
      const mx = (a.x + b.x) / 2, mz = (a.y + b.y) / 2;
      const probe = new THREE.Vector2(mx + Math.sin(angle) * 0.02, mz + Math.cos(angle) * 0.02);
      if (!this._inside(probe, pts)) angle += Math.PI;

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

      this.walls.push({ mesh, edges, roomIndex: ri, wallIndex: wi, custom: false });
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

    _canvasTexture(canvas) {
      // copy (max 2048px) so later redraws of the source canvas do not affect it and GPU memory stays bounded
      const max = 2048;
      const k = Math.min(1, max / Math.max(canvas.width, canvas.height));
      const copy = document.createElement('canvas');
      copy.width = Math.max(1, Math.round(canvas.width * k));
      copy.height = Math.max(1, Math.round(canvas.height * k));
      copy.getContext('2d').drawImage(canvas, 0, 0, copy.width, copy.height);
      const t = new THREE.CanvasTexture(copy);
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
      this.walls.forEach((entry) => { if (!entry.custom) apply(entry.mesh.material, w, WALL_COLOR); });
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
