/**
 * CAD Floor Plan Plugin - Main Script
 */

(function() {
  'use strict';

  class CADFloorPlanWidget {
    constructor(element) {
      this.element = element;
      this.renderer = null;
      this.currentData = null;
      this.width = parseInt(element.dataset.width) || 800;
      this.height = parseInt(element.dataset.height) || 600;
      this.sample = element.dataset.sample || 'default';
      this.showInfo = element.dataset.showInfo !== 'false';

      this.init();
    }

    init() {
      this.setupEventListeners();

      if (this.sample === 'default') {
        this.loadDefaultRoom();
      }
    }

    setupEventListeners() {
      const fileInput = this.element.querySelector('#cfp-file-input');
      if (fileInput) {
        fileInput.addEventListener('change', (e) => this.handleFileSelect(e));
      }

      const btnLoadSample = this.element.querySelector('#cfp-btn-load-sample');
      if (btnLoadSample) {
        btnLoadSample.addEventListener('click', () => this.loadDefaultRoom());
      }

      const btnResetCamera = this.element.querySelector('#cfp-btn-reset-camera');
      if (btnResetCamera) {
        btnResetCamera.addEventListener('click', () => this.resetCamera());
      }

      const btnDownload = this.element.querySelector('#cfp-btn-download-json');
      if (btnDownload) {
        btnDownload.addEventListener('click', () => this.downloadJSON());
      }

      // ドラッグ&ドロップ
      const container = this.element.querySelector('.cfp-file-input');
      if (container) {
        container.addEventListener('dragover', (e) => {
          e.preventDefault();
          container.style.borderColor = '#60a5fa';
          container.style.background = '#0f172a';
        });

        container.addEventListener('dragleave', () => {
          container.style.borderColor = '#3b82f6';
          container.style.background = '#09090b';
        });

        container.addEventListener('drop', (e) => {
          e.preventDefault();
          container.style.borderColor = '#3b82f6';
          container.style.background = '#09090b';

          const file = e.dataTransfer.files[0];
          if (file) {
            fileInput.files = e.dataTransfer.files;
            this.handleFileSelect({ target: { files: e.dataTransfer.files } });
          }
        });

        container.addEventListener('click', () => {
          fileInput.click();
        });
      }
    }

    async handleFileSelect(event) {
      const file = event.target.files[0];
      if (!file) return;

      try {
        this.showStatus('ファイルを読み込んでいます...', 'loading');

        this.element.querySelector('#cfp-filename').textContent = file.name;

        // Parse file
        this.currentData = await CADParser.parseFloorPlan(file);
        CADParser.validate(this.currentData);

        // Initialize renderer if needed
        if (!this.renderer) {
          const container = this.element.querySelector('#cfp-canvas-container');
          this.renderer = new ThreeRoomRenderer(container, {
            width: this.width,
            height: this.height,
            backgroundColor: 0x09090b
          });
        }

        // Load floor plan
        this.renderer.loadFloorPlan(this.currentData);

        // Update info
        const areas = this.renderer.getAreas();
        this.element.querySelector('#cfp-floor-area').textContent = areas.floorArea + ' m²';
        this.element.querySelector('#cfp-wall-area').textContent = areas.wallArea + ' m²';
        this.element.querySelector('#cfp-room-count').textContent = this.currentData.rooms.length;

        this.showStatus('✅ ファイルを読み込みました！', 'success');
      } catch (error) {
        this.showStatus('❌ エラー: ' + error.message, 'error');
        console.error(error);
      }
    }

    loadDefaultRoom() {
      const defaultData = {
        rooms: [{
          id: "room_1",
          vertices: [[0, 0], [5000, 0], [5000, 3000], [0, 3000]],
          walls: [
            { from: 0, to: 1, length: 5000, height: 2800, material: "wallpaper" },
            { from: 1, to: 2, length: 3000, height: 2800, material: "wallpaper" },
            { from: 2, to: 3, length: 5000, height: 2800, material: "wallpaper" },
            { from: 3, to: 0, length: 3000, height: 2800, material: "wallpaper" }
          ],
          floor: { material: "carpet" }
        }],
        metadata: { source: "default", scale: 1 }
      };

      try {
        this.currentData = defaultData;

        if (!this.renderer) {
          const container = this.element.querySelector('#cfp-canvas-container');
          this.renderer = new ThreeRoomRenderer(container, {
            width: this.width,
            height: this.height,
            backgroundColor: 0x09090b
          });
        }

        this.renderer.loadFloorPlan(defaultData);

        const areas = this.renderer.getAreas();
        this.element.querySelector('#cfp-floor-area').textContent = areas.floorArea + ' m²';
        this.element.querySelector('#cfp-wall-area').textContent = areas.wallArea + ' m²';
        this.element.querySelector('#cfp-room-count').textContent = '1';
        this.element.querySelector('#cfp-filename').textContent = 'サンプル（デフォルト）';

        this.showStatus('✅ サンプル間取りを読み込みました', 'success');
      } catch (error) {
        this.showStatus('❌ エラー: ' + error.message, 'error');
      }
    }

    resetCamera() {
      if (this.renderer) {
        this.renderer.camera.position.set(5000, 3000, 5000);
        this.renderer.camera.lookAt(2500, 0, 1500);
      }
    }

    downloadJSON() {
      if (!this.currentData) {
        alert('先にCADファイルを読み込んでください');
        return;
      }

      const json = JSON.stringify(this.currentData, null, 2);
      const blob = new Blob([json], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'floor-plan-data.json';
      a.click();
      URL.revokeObjectURL(url);
    }

    showStatus(message, type) {
      const statusEl = this.element.querySelector('#cfp-status');
      statusEl.textContent = message;
      statusEl.className = 'cfp-status cfp-status-' + type;
    }
  }

  // Initialize on page load
  document.addEventListener('DOMContentLoaded', function() {
    const widgets = document.querySelectorAll('.cad-floor-plan-widget');
    widgets.forEach(widget => {
      new CADFloorPlanWidget(widget);
    });
  });

  // Handle late-loaded widgets (AJAX, etc.)
  window.initCADFloorPlan = function(element) {
    new CADFloorPlanWidget(element);
  };
})();
