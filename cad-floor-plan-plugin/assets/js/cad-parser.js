/**
 * CAD Parser Module
 * Handles DXF, PDF (vector), and JSON floor plan data
 */

class CADParser {
  /**
   * Parse floor plan data from various formats
   * @param {File|string} input - File or JSON string
   * @returns {Promise<Object>} Normalized room data
   */
  static async parseFloorPlan(input) {
    if (input instanceof File) {
      const text = await input.text();
      const ext = input.name.split('.').pop().toLowerCase();

      switch (ext) {
        case 'dxf':
          return this.parseDXF(text);
        case 'pdf':
          return this.parsePDF(input);
        case 'json':
          return this.parseJSON(text);
        default:
          throw new Error(`Unsupported format: ${ext}`);
      }
    }

    // Assume JSON string
    return this.parseJSON(input);
  }

  /**
   * Parse DXF format
   * @param {string} dxfText - DXF file content
   * @returns {Object} Normalized room data
   */
  static parseDXF(dxfText) {
    // Simple DXF parser - extracts LWPOLYLINE and LINE entities
    const rooms = [];
    const lines = dxfText.split('\n');

    let currentEntity = null;
    let currentCoords = [];
    let inEntitiesSection = false;
    let currentEntityType = null;

    for (let i = 0; i < lines.length; i++) {
      const line = lines[i].trim();

      if (line === 'ENTITIES') {
        inEntitiesSection = true;
        continue;
      }

      if (line === 'ENDSEC' && inEntitiesSection) {
        if (currentCoords.length >= 3) {
          rooms.push({
            id: `room_${rooms.length}`,
            vertices: currentCoords,
            walls: this._generateWalls(currentCoords),
            floor: { material: 'carpet' }
          });
        }
        currentCoords = [];
        inEntitiesSection = false;
        continue;
      }

      if (!inEntitiesSection) continue;

      // Parse LWPOLYLINE
      if (line === 'LWPOLYLINE') {
        currentEntityType = 'LWPOLYLINE';
        continue;
      }

      // Parse coordinates (X: 10, Y: 20)
      if (currentEntityType === 'LWPOLYLINE') {
        if (line === '10' || line === '20') {
          const nextLine = (i + 1 < lines.length) ? parseFloat(lines[i + 1].trim()) : 0;
          if (line === '10') {
            currentCoords.push([nextLine, 0]);
          } else if (line === '20' && currentCoords.length > 0) {
            currentCoords[currentCoords.length - 1][1] = nextLine;
          }
        }
      }
    }

    return {
      rooms: rooms.length > 0 ? rooms : this._createDefaultRoom(),
      metadata: { source: 'dxf', scale: 1 }
    };
  }

  /**
   * Parse PDF format (vector-based)
   * @param {File} pdfFile - PDF file object
   * @returns {Promise<Object>} Normalized room data
   */
  static async parsePDF(pdfFile) {
    // Vector PDF parsing requires pdf.js library
    // For now, return placeholder - will be enhanced when pdf.js is loaded

    if (typeof pdfjsLib === 'undefined') {
      throw new Error('PDF.js library not loaded. Please load pdfjs-dist');
    }

    const arrayBuffer = await pdfFile.arrayBuffer();
    const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
    const page = await pdf.getPage(1);
    const viewport = page.getViewport({ scale: 1 });

    const operatorList = await page.getOperatorList();
    const vectors = this._extractVectorsFromPDF(operatorList, viewport);

    return {
      rooms: this._vectorsToRooms(vectors),
      metadata: { source: 'pdf', scale: viewport.width / viewport.height }
    };
  }

  /**
   * Parse JSON floor plan
   * @param {string} jsonText - JSON string
   * @returns {Object} Parsed room data
   */
  static parseJSON(jsonText) {
    try {
      const data = JSON.parse(jsonText);
      return {
        rooms: data.rooms || [],
        metadata: data.metadata || { source: 'json', scale: 1 }
      };
    } catch (e) {
      throw new Error(`Invalid JSON: ${e.message}`);
    }
  }

  /**
   * Extract vectors from PDF operator list
   * @private
   */
  static _extractVectorsFromPDF(operatorList, viewport) {
    // Placeholder for PDF vector extraction
    // This would use PDFPageProxy.getOperatorList() and parse drawing commands
    const vectors = [];

    for (let i = 0; i < operatorList.fnArray.length; i++) {
      const fn = operatorList.fnArray[i];
      const args = operatorList.argsArray[i];

      // m = moveTo, l = lineTo, c = curveTo, re = rectangle, h = closePath
      if (fn === 'm' || fn === 'l' || fn === 're') {
        vectors.push({ type: fn, args: args, scale: viewport.width });
      }
    }

    return vectors;
  }

  /**
   * Convert vectors to room objects
   * @private
   */
  static _vectorsToRooms(vectors) {
    // Group vectors into closed paths (rooms)
    const rooms = [];
    let currentPath = [];

    for (const vector of vectors) {
      if (vector.type === 're') {
        // Rectangle
        const [x, y, w, h] = vector.args;
        currentPath = [
          [x, y],
          [x + w, y],
          [x + w, y + h],
          [x, y + h]
        ];

        if (currentPath.length >= 3) {
          rooms.push({
            id: `room_${rooms.length}`,
            vertices: currentPath,
            walls: this._generateWalls(currentPath),
            floor: { material: 'carpet' }
          });
        }
        currentPath = [];
      }
    }

    return rooms.length > 0 ? rooms : this._createDefaultRoom().rooms;
  }

  /**
   * Generate wall definitions from vertices
   * @private
   */
  static _generateWalls(vertices) {
    const walls = [];

    for (let i = 0; i < vertices.length; i++) {
      const nextIdx = (i + 1) % vertices.length;
      const [x1, y1] = vertices[i];
      const [x2, y2] = vertices[nextIdx];

      const length = Math.sqrt((x2 - x1) ** 2 + (y2 - y1) ** 2);

      walls.push({
        from: i,
        to: nextIdx,
        length: length,
        height: 2800, // default height in mm
        material: 'wallpaper'
      });
    }

    return walls;
  }

  /**
   * Create default room (square 5000x3000mm)
   * @private
   */
  static _createDefaultRoom() {
    const defaultVertices = [[0, 0], [5000, 0], [5000, 3000], [0, 3000]];

    return {
      rooms: [{
        id: 'room_1',
        vertices: defaultVertices,
        walls: this._generateWalls(defaultVertices),
        floor: { material: 'carpet' }
      }],
      metadata: { source: 'default', scale: 1 }
    };
  }

  /**
   * Validate floor plan data
   */
  static validate(data) {
    if (!data.rooms || !Array.isArray(data.rooms)) {
      throw new Error('Invalid floor plan: missing rooms array');
    }

    for (const room of data.rooms) {
      if (!room.vertices || room.vertices.length < 3) {
        throw new Error(`Invalid room ${room.id}: need at least 3 vertices`);
      }

      if (!Array.isArray(room.walls)) {
        throw new Error(`Invalid room ${room.id}: missing walls array`);
      }
    }

    return true;
  }

  /**
   * Calculate floor area using Shoelace formula
   */
  static calculateArea(vertices) {
    if (vertices.length < 3) return 0;

    let area = 0;
    for (let i = 0; i < vertices.length; i++) {
      const [x1, y1] = vertices[i];
      const [x2, y2] = vertices[(i + 1) % vertices.length];
      area += (x1 * y2 - x2 * y1);
    }

    return Math.abs(area) / 2;
  }

  /**
   * Calculate total wall area
   */
  static calculateWallArea(walls, height = 2800) {
    return walls.reduce((sum, wall) => sum + (wall.length * height), 0);
  }
}

// Export for use in browser and Node.js
if (typeof module !== 'undefined' && module.exports) {
  module.exports = CADParser;
}
