/**
 * Three.js Room Renderer Module
 * Handles 3D visualization of floor plans with carpet and wallpaper
 */

class ThreeRoomRenderer {
  constructor(containerElement, options = {}) {
    this.container = containerElement;
    this.scene = null;
    this.camera = null;
    this.renderer = null;
    this.rooms = [];
    this.floorMesh = null;
    this.wallMeshes = [];

    this.options = {
      width: options.width || 800,
      height: options.height || 600,
      backgroundColor: 0x18181b,
      ambientLight: 0xffffff,
      ...options
    };

    this._initThreeJS();
  }

  /**
   * Initialize Three.js scene
   * @private
   */
  _initThreeJS() {
    if (typeof THREE === 'undefined') {
      throw new Error('Three.js not loaded');
    }

    // Scene setup
    this.scene = new THREE.Scene();
    this.scene.background = new THREE.Color(this.options.backgroundColor);
    this.scene.fog = new THREE.Fog(0x09090b, 10000, 20000);

    // Camera setup
    this.camera = new THREE.PerspectiveCamera(
      75,
      this.options.width / this.options.height,
      0.1,
      10000
    );
    this.camera.position.set(5000, 3000, 5000);
    this.camera.lookAt(2500, 0, 1500);

    // Renderer setup
    this.renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    this.renderer.setSize(this.options.width, this.options.height);
    this.renderer.shadowMap.enabled = true;
    this.renderer.shadowMap.type = THREE.PCFShadowShadowMap;
    this.container.appendChild(this.renderer.domElement);

    // Lighting
    const ambientLight = new THREE.AmbientLight(this.options.ambientLight, 0.6);
    this.scene.add(ambientLight);

    const directionalLight = new THREE.DirectionalLight(0xffffff, 0.8);
    directionalLight.position.set(5000, 4000, 5000);
    directionalLight.castShadow = true;
    directionalLight.shadow.mapSize.width = 2048;
    directionalLight.shadow.mapSize.height = 2048;
    directionalLight.shadow.camera.far = 20000;
    directionalLight.shadow.camera.left = -10000;
    directionalLight.shadow.camera.right = 10000;
    directionalLight.shadow.camera.top = 10000;
    directionalLight.shadow.camera.bottom = -10000;
    this.scene.add(directionalLight);

    // Controls (optional - orbit-like movement)
    this._setupControls();

    // Start animation loop
    this.animate();
  }

  /**
   * Setup camera controls
   * @private
   */
  _setupControls() {
    const canvas = this.renderer.domElement;

    canvas.addEventListener('mousedown', (e) => {
      if (e.button === 2) { // Right mouse button
        const startX = e.clientX;
        const startY = e.clientY;
        const startCamX = this.camera.position.x;
        const startCamZ = this.camera.position.z;

        const onMouseMove = (moveEvent) => {
          const deltaX = (moveEvent.clientX - startX) * 2;
          const deltaY = (moveEvent.clientY - startY) * 2;

          this.camera.position.x = startCamX - deltaX;
          this.camera.position.z = startCamZ - deltaY;
          this.camera.lookAt(2500, 0, 1500);
        };

        const onMouseUp = () => {
          window.removeEventListener('mousemove', onMouseMove);
          window.removeEventListener('mouseup', onMouseUp);
        };

        window.addEventListener('mousemove', onMouseMove);
        window.addEventListener('mouseup', onMouseUp);
      }
    });

    // Zoom with mouse wheel
    canvas.addEventListener('wheel', (e) => {
      e.preventDefault();
      const zoomSpeed = 500;
      const direction = e.deltaY > 0 ? 1 : -1;

      const distance = Math.sqrt(
        this.camera.position.x ** 2 +
        this.camera.position.z ** 2
      );
      const newDistance = Math.max(2000, distance + direction * zoomSpeed);
      const ratio = newDistance / distance;

      this.camera.position.x *= ratio;
      this.camera.position.z *= ratio;
    });
  }

  /**
   * Load and render floor plan
   */
  loadFloorPlan(floorPlanData) {
    CADParser.validate(floorPlanData);
    this.rooms = floorPlanData.rooms;

    // Clear previous meshes
    this._clearMeshes();

    // Render each room
    for (const room of this.rooms) {
      this._renderRoom(room);
    }
  }

  /**
   * Render a single room with floor and walls
   * @private
   */
  _renderRoom(room) {
    // Create floor geometry from vertices
    this._createFloor(room.vertices, room.floor);

    // Create walls
    if (room.walls) {
      this._createWalls(room.vertices, room.walls);
    }
  }

  /**
   * Create floor geometry
   * @private
   */
  _createFloor(vertices, floorConfig) {
    // Convert mm to Three.js units (divide by 1000)
    const scaledVertices = vertices.map(([x, y]) => [x / 1000, 0, y / 1000]);

    // Use earcut for polygon triangulation
    const points = scaledVertices.map(([x, , z]) => [x, z]);
    const indices = this._triangulatePolygon(points);

    // Create geometry
    const geometry = new THREE.BufferGeometry();
    const positions = [];

    for (const [x, , z] of scaledVertices) {
      positions.push(x, 0, z);
    }

    geometry.setAttribute('position', new THREE.BufferAttribute(new Float32Array(positions), 3));
    geometry.setIndex(new THREE.BufferAttribute(new Uint32Array(indices), 1));
    geometry.computeVertexNormals();

    // Create material and mesh
    const material = new THREE.MeshStandardMaterial({
      color: 0xd4af37, // Gold carpet color
      roughness: 0.6,
      metalness: 0.1,
      map: null // Texture will be set if provided
    });

    const mesh = new THREE.Mesh(geometry, material);
    mesh.receiveShadow = true;
    mesh.castShadow = true;

    this.scene.add(mesh);
    this.floorMesh = mesh;
  }

  /**
   * Create wall geometry
   * @private
   */
  _createWalls(vertices, walls) {
    for (const wall of walls) {
      const [x1, y1] = vertices[wall.from];
      const [x2, y2] = vertices[wall.to];

      const height = wall.height / 1000; // Convert mm to units
      const length = Math.sqrt((x2 - x1) ** 2 + (y2 - y1) ** 2) / 1000;

      // Create wall geometry
      const geometry = new THREE.BoxGeometry(length, height, 0.05);

      // Position wall
      const centerX = (x1 + x2) / 2 / 1000;
      const centerZ = (y1 + y2) / 2 / 1000;

      // Rotate to face correct direction
      const angle = Math.atan2(y2 - y1, x2 - x1);

      const material = new THREE.MeshStandardMaterial({
        color: 0xe4e4e7,
        roughness: 0.8,
        metalness: 0
      });

      const mesh = new THREE.Mesh(geometry, material);
      mesh.position.set(centerX, height / 2, centerZ);
      mesh.rotation.y = angle;
      mesh.castShadow = true;
      mesh.receiveShadow = true;

      this.scene.add(mesh);
      this.wallMeshes.push(mesh);
    }
  }

  /**
   * Simple polygon triangulation using earcut algorithm
   * @private
   */
  _triangulatePolygon(points) {
    // For simple cases, use a basic fan triangulation
    const indices = [];

    for (let i = 1; i < points.length - 1; i++) {
      indices.push(0, i, i + 1);
    }

    return indices;
  }

  /**
   * Update floor texture
   */
  setFloorTexture(imageUrl) {
    if (!this.floorMesh) return;

    const textureLoader = new THREE.TextureLoader();
    textureLoader.load(imageUrl, (texture) => {
      texture.wrapS = THREE.RepeatWrapping;
      texture.wrapT = THREE.RepeatWrapping;
      texture.repeat.set(4, 4);

      this.floorMesh.material.map = texture;
      this.floorMesh.material.needsUpdate = true;
    });
  }

  /**
   * Update wall texture
   */
  setWallTexture(wallIndex, imageUrl) {
    if (wallIndex >= this.wallMeshes.length) return;

    const textureLoader = new THREE.TextureLoader();
    textureLoader.load(imageUrl, (texture) => {
      texture.wrapS = THREE.RepeatWrapping;
      texture.wrapT = THREE.RepeatWrapping;

      this.wallMeshes[wallIndex].material.map = texture;
      this.wallMeshes[wallIndex].material.needsUpdate = true;
    });
  }

  /**
   * Render animation loop
   */
  animate = () => {
    requestAnimationFrame(this.animate);
    this.renderer.render(this.scene, this.camera);
  };

  /**
   * Clear all meshes from scene
   * @private
   */
  _clearMeshes() {
    this.wallMeshes.forEach(mesh => this.scene.remove(mesh));
    this.wallMeshes = [];

    if (this.floorMesh) {
      this.scene.remove(this.floorMesh);
      this.floorMesh = null;
    }
  }

  /**
   * Dispose of renderer
   */
  dispose() {
    this.renderer.dispose();
    this.container.removeChild(this.renderer.domElement);
  }

  /**
   * Get calculated areas
   */
  getAreas() {
    if (this.rooms.length === 0) {
      return { floorArea: 0, wallArea: 0 };
    }

    const room = this.rooms[0];
    const floorArea = CADParser.calculateArea(room.vertices) / 1000000; // Convert mm² to m²
    const wallArea = CADParser.calculateWallArea(room.walls) / 1000000;

    return {
      floorArea: Math.round(floorArea * 100) / 100,
      wallArea: Math.round(wallArea * 100) / 100
    };
  }
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = ThreeRoomRenderer;
}
