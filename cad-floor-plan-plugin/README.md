# CAD Floor Plan 3D Simulator

DXF / ベクターPDF / JSON の間取りデータをブラウザ上で3D表示し、床・壁の面積を計算する WordPress プラグイン（スタンドアロン版もあります）。ファイルの解析はすべてブラウザ内で行われ、サーバーには送信されません。

現在のバージョン: **1.1.0**（変更履歴は [CHANGELOG.md](CHANGELOG.md)）

## インストール（WordPress）

1. `cad-floor-plan-plugin` フォルダを `wp-content/plugins/` に置く（または zip にして管理画面からアップロード）。
2. 「プラグイン」で有効化する。**有効化すると、固定ページ「CADお部屋シミュレーション」（スラッグ `cad-floor-plan`）が自動で公開されます。**
   - すでに同じスラッグでショートコード入りのページがあれば、それを使います。
   - ページを消してしまった場合は、管理メニュー「CAD Floor Plan」のボタンから作り直せます。
   - プラグインを無効化・削除しても、固定ページは削除されません。
3. 他のページに置きたい場合は `[cad_floor_plan]` を貼ります。

| 属性 | 既定値 | 説明 |
|---|---|---|
| `height` | 600 | 3D表示エリアの高さ（px） |
| `width` | なし | 最大幅（px）。省略で幅いっぱい |
| `sample` | default | `none` でサンプル非表示 |

スクリプトは、ショートコードのあるページだけで読み込まれます。

## スタンドアロン版

`standalone.html` を Web サーバーに置くだけで動きます（`assets/` と同じ階層に置いてください）。

## 対応形式

| 形式 | 読み取る内容 |
|---|---|
| DXF | 閉じた `LWPOLYLINE` / `POLYLINE`、およびつながって閉じる `LINE`。単位は `$INSUNITS` から換算（なければmm） |
| PDF | 1ページ目のベクター図形（CADや Illustrator から書き出したもの）。スキャン画像は不可。縮尺は画面の「PDFの縮尺（1:N）」に N を入力 |
| JSON | 下記の形式 |
| DWG | 直接は読めません。DXFに書き出してください |

- 0.5㎡未満の閉じた図形は、家具や記号などのノイズとして無視します。
- 線が枝分かれしている図面（壁の交差点がある図面など）は、閉じた輪郭として認識できません。外形を閉じたポリラインで描いてください。
- 曲線（円弧・スプライン）は直線近似または未対応です。

```json
{
  "rooms": [
    {
      "id": "room_1",
      "vertices": [[0, 0], [6000, 0], [6000, 2500], [3500, 2500], [3500, 5000], [0, 5000]],
      "walls": [{ "height": 2400 }]
    }
  ]
}
```

座標はmm、Y軸は上向きです。`walls` は省略でき、省略すると各辺を高さ2400mmの壁にします。

## 他のページから使う

各ウィジェットは `element.cfpWidget` から操作できます。

```js
const w = document.querySelector('.cad-floor-plan-widget').cfpWidget;
w.renderer.setFloorTexture(canvas.toDataURL());   // 床の画像
w.renderer.setWallTexture(imageUrl);              // すべての壁
w.renderer.getAreas();                            // { floorArea, wallArea }（㎡）
```

PDF.js の読み込み先は、フィルター `cad_floor_plan_pdfjs_url` と `cad_floor_plan_pdfjs_worker_url` で変更できます。Three.js と PDF.js は cdnjs から読み込みます。

## バージョンについて

プラグインのバージョンは次の3か所に書かれています。変更するときは、まとめて更新してください。

- `cad-floor-plan.php` のヘッダー `Version:`
- 同ファイルの `CFP_VERSION`
- `package.json` の `version`

あわせて `CHANGELOG.md` に変更内容を追記します。バージョンを上げると、次回のアクセス時に保存済みバージョンが更新されます（`maybe_upgrade()`）。

## ライセンス

MIT（`LICENSE.txt`）
