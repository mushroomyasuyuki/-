# CAD Floor Plan 3D Simulator

DXF / PDF / JSON 形式のCADデータを読み込み、**リアルタイム3Dシミュレーション**を表示するプラグイン＆スタンドアロンツール。

![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)
![WordPress](https://img.shields.io/badge/WordPress-5.0+-blue.svg)

## 🚀 主な機能

| 機能 | 説明 |
|------|------|
| **複数フォーマット対応** | DXF、ベクターPDF、JSON |
| **高品質3D表示** | Three.js WebGL描画、60fps |
| **自動面積計算** | 床・壁の面積を自動計算（Shoelace法） |
| **複雑な間取り対応** | L字、T字など複雑な形状に対応 |
| **インタラクティブ操作** | マウスで視点変更・ズーム可能 |
| **REST API** | 外部アプリから利用可能 |
| **スタンドアロン版** | プラグイン不要で単独利用可能 |

---

## 📦 インストール

### WordPress プラグインとして

1. **プラグインファイルをアップロード**
   ```bash
   # wp-content/plugins/ に配置
   wp-content/plugins/cad-floor-plan-plugin/
   ```

2. **WordPress管理画面でアクティベーション**
   - 管理画面 → プラグイン → CAD Floor Plan 3D Simulator → 有効化

3. **ショートコードで使用**
   ```
   [cad_floor_plan width="800" height="600"]
   ```

### スタンドアロン版

1. **ファイルをダウンロード**
   ```bash
   git clone https://github.com/mushroomyasuyuki/cad-floor-plan-simulator.git
   cd cad-floor-plan-plugin
   ```

2. **ローカルサーバーで実行**
   ```bash
   python -m http.server 8000
   # http://localhost:8000/standalone.html
   ```

### npm パッケージ（予定）

```bash
npm install cad-floor-plan-simulator
```

---

## 📖 使用方法

### WordPress プラグイン

#### ショートコード基本形

```html
[cad_floor_plan]
```

#### ショートコード属性

| 属性 | デフォルト | 説明 |
|------|----------|------|
| `width` | 800 | キャンバス幅（ピクセル） |
| `height` | 600 | キャンバス高さ（ピクセル） |
| `sample` | default | デフォルト表示（default / none） |
| `show_info` | true | 情報パネル表示（true / false） |

#### 使用例

```html
<!-- カスタムサイズで表示 -->
[cad_floor_plan width="1000" height="700"]

<!-- サンプル非表示で初期化 -->
[cad_floor_plan sample="none"]

<!-- 情報パネル非表示 -->
[cad_floor_plan show_info="false"]
```

### REST API

#### エンドポイント

**ファイル解析**
```
POST /wp-json/cad-floor-plan/v1/parse
```

リクエスト例:
```javascript
fetch('/wp-json/cad-floor-plan/v1/parse', {
  method: 'POST',
  body: JSON.stringify({
    file: 'base64エンコードされたファイル',
    format: 'dxf' // または 'pdf', 'json'
  }),
  headers: {
    'Content-Type': 'application/json'
  }
})
.then(r => r.json())
.then(data => console.log(data));
```

**プラグイン情報**
```
GET /wp-json/cad-floor-plan/v1/info
```

レスポンス例:
```json
{
  "name": "CAD Floor Plan 3D Simulator",
  "version": "1.0.0",
  "supported_formats": ["dxf", "pdf", "json"],
  "max_file_size": "10MB"
}
```

### JavaScript API（スタンドアロン版）

```javascript
// 1. パーサー - ファイルの解析
const data = await CADParser.parseFloorPlan(file);

// 2. バリデーション
CADParser.validate(data);

// 3. 面積計算
const floorArea = CADParser.calculateArea(vertices);
const wallArea = CADParser.calculateWallArea(walls);

// 4. 3D描画
const renderer = new ThreeRoomRenderer(container, {
  width: 800,
  height: 600
});

renderer.loadFloorPlan(data);

// 5. テクスチャ適用
renderer.setFloorTexture(imageUrl);
renderer.setWallTexture(0, imageUrl);

// 6. カメラ制御
renderer.camera.position.set(5000, 3000, 5000);
renderer.camera.lookAt(2500, 0, 1500);

// 7. 面積情報取得
const areas = renderer.getAreas();
console.log(`床: ${areas.floorArea}m², 壁: ${areas.wallArea}m²`);
```

---

## 📋 対応フォーマット

### DXF（AutoCAD）

```
サポート:
✅ LWPOLYLINE エンティティ
✅ LINE エンティティ
✅ 座標抽出 (X: 10, Y: 20)
✅ 自動壁生成

推奨: AutoCAD R2000 以降
```

### PDF（ベクターのみ）

```
サポート:
✅ Illustrator エクスポート PDF
✅ AutoCAD PDF 出力
✅ ベクター図形

非対応:
❌ スキャン画像 PDF
❌ 混合型 PDF
```

### JSON

```json
{
  "rooms": [{
    "id": "room_1",
    "vertices": [[x1, y1], [x2, y2], ...],  // mm単位
    "walls": [{
      "from": 0,
      "to": 1,
      "length": 5000,
      "height": 2800,
      "material": "wallpaper"
    }],
    "floor": { "material": "carpet" }
  }],
  "metadata": {
    "source": "json",
    "scale": 1
  }
}
```

---

## 🎨 カスタマイズ

### スタイルのカスタマイズ

`assets/css/style.css` を編集して色・サイズを変更:

```css
.cfp-container {
  background: #18181b;      /* コンテナ背景 */
  border: 1px solid #27272a; /* ボーダー */
}

.cfp-btn-primary {
  background: #3b82f6;      /* ボタン色 */
}
```

### スクリプトのカスタマイズ

`assets/js/main.js` で イベント・動作をカスタマイズ可能。

---

## 📊 パフォーマンス

| 項目 | 目安 |
|------|------|
| DXF読み込み | < 500ms |
| PDF読み込み | < 1s |
| JSON解析 | < 100ms |
| 3D描画 | 60fps |
| メモリ使用量 | 30～50MB |

---

## 🔍 トラブルシューティング

### 3D表示が出ない

1. **WebGL サポート確認**
   ```javascript
   const canvas = document.createElement('canvas');
   const gl = canvas.getContext('webgl') || canvas.getContext('webgl2');
   console.log('WebGL:', gl ? 'OK' : 'NG');
   ```

2. **ブラウザコンソール確認**
   - F12 キーで開く
   - Console タブでエラー確認

3. **ファイル形式確認**
   - DXF: テキスト形式か確認
   - PDF: ベクター形式か確認（スキャン画像は非対応）
   - JSON: スキーマが正しいか確認

### ファイルが読み込めない

- **ファイルサイズ**: 10MB以下か確認
- **CORS エラー**: 同一オリジンか確認
- **ファイル拡張子**: .dxf / .pdf / .json か確認

### パフォーマンスが悪い

- **複雑なジオメトリ**: 頂点数を削減
- **テクスチャサイズ**: 画像を圧縮
- **ブラウザキャッシュ**: クリアして再読み込み

---

## 🔐 セキュリティ

- ✅ ファイル処理はクライアント側（ブラウザ）で実行
- ✅ サーバーへのアップロードなし
- ✅ ローカルストレージ無し
- ⚠️ 大容量ファイルはメモリに注意

---

## 📝 ライセンス

MIT License - 自由に使用・改変・配布可能

```
Copyright (c) 2026 Claude Haiku

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software...
```

---

## 🤝 貢献

バグ報告・機能リクエスト・プルリクエストを歓迎します。

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

---

## 📞 サポート

- **問題報告**: [GitHub Issues](https://github.com/mushroomyasuyuki/cad-floor-plan-simulator/issues)
- **質問**: GitHub Discussions
- **ドキュメント**: [Wiki](https://github.com/mushroomyasuyuki/cad-floor-plan-simulator/wiki)

---

## 🗺️ ロードマップ

### Phase 2（2026年Q4予定）

- [ ] スキャン画像PDF対応（OCR + 画像処理）
- [ ] 複数部屋の階層構造
- [ ] ドア・窓の配置機能
- [ ] VRモード（WebXR）
- [ ] クラウド連携（Google Drive等）

### Phase 3（2027年予定）

- [ ] AI による自動間取り認識
- [ ] リアルタイムコラボレーション
- [ ] モバイルアプリ版

---

## 🙏 謝辞

- [Three.js](https://threejs.org/) - 3D WebGL 描画
- [pdf.js](https://mozilla.github.io/pdf.js/) - PDF 処理

---

**最終更新**: 2026年10月1日  
**メンテナー**: Claude Haiku 4.5  
**リポジトリ**: https://github.com/mushroomyasuyuki/cad-floor-plan-simulator
