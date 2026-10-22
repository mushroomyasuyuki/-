// cfp-layers.jsx — 壁紙・カーペットシミュレーションで保存した「確認用シート」（.ai／.svg）を Illustrator で開いたあと、
// レイヤー（グラフィック／分割／壁面ライン／寸法／仕様）に分けるスクリプト。
// 使い方：Illustrator でファイルを開く → メニュー「ファイル」→「スクリプト」→「その他のスクリプト…」→ このファイルを選ぶ。
// Illustrator は PDF・SVG のレイヤーを読み込まず、1つのレイヤーにグループとして入れるため、そのグループをレイヤーに分ける。
#target illustrator
(function () {
  var NAMES = ['\u30b0\u30e9\u30d5\u30a3\u30c3\u30af', '\u5206\u5272', '\u58c1\u9762\u30e9\u30a4\u30f3', '\u5bf8\u6cd5', '\u4ed5\u69d8'];
  if (app.documents.length === 0) { alert('\u30d5\u30a1\u30a4\u30eb\u3092\u958b\u3044\u3066\u304b\u3089\u5b9f\u884c\u3057\u3066\u304f\u3060\u3055\u3044\u3002'); return; }
  var doc = app.activeDocument;
  // 最上位のグループ（レイヤーの直下）を集める（上から順）
  var tops = [], i, j, L, it;
  for (i = 0; i < doc.layers.length; i++) {
    L = doc.layers[i];
    for (j = 0; j < L.pageItems.length; j++) {
      it = L.pageItems[j];
      if (it.parent === L && it.typename === 'GroupItem') tops.push(it);
    }
  }
  // SVG：グループの名前がレイヤー名。.ai（PDF）：上から 仕様・寸法・壁面ライン・分割・グラフィック の順
  var found = [], k, nm;
  for (k = 0; k < NAMES.length; k++) found[k] = null;
  for (i = 0; i < tops.length; i++) {
    nm = tops[i].name;
    for (k = 0; k < NAMES.length; k++) if (nm === NAMES[k] || nm.indexOf(NAMES[k]) === 0) found[k] = tops[i];
  }
  var byName = 0;
  for (k = 0; k < NAMES.length; k++) if (found[k]) byName++;
  if (byName < NAMES.length) {
    if (tops.length < NAMES.length) { alert('\u30ec\u30a4\u30e4\u30fc\u306b\u5206\u3051\u3089\u308c\u307e\u305b\u3093\u3067\u3057\u305f\uff08\u3053\u306e\u30b7\u30df\u30e5\u30ec\u30fc\u30bf\u30fc\u3067\u4fdd\u5b58\u3057\u305f\u78ba\u8a8d\u7528\u30b7\u30fc\u30c8\u3092\u958b\u3044\u3066\u304b\u3089\u5b9f\u884c\u3057\u3066\u304f\u3060\u3055\u3044\uff09\u3002'); return; }
    for (k = 0; k < NAMES.length; k++) found[k] = tops[NAMES.length - 1 - k];
  }
  var first = found[0].layer;
  for (k = 0; k < NAMES.length; k++) {
    var g = found[k];
    var layer = doc.layers.add();
    layer.name = NAMES[k];
    g.move(layer, ElementPlacement.PLACEATBEGINNING);
    // ページ全体の切り抜き（.ai のまとまり）を外す
    if (g.clipped) {
      g.clipped = false;
      for (j = g.pageItems.length - 1; j >= 0; j--) {
        it = g.pageItems[j];
        if (it.typename === 'PathItem' && it.clipping) { it.remove(); break; }
        if (it.typename === 'PathItem' && !it.filled && !it.stroked) { it.remove(); break; }
      }
    }
    // グループをほどいて、中身をレイヤーの直下に（重なり順はそのまま）
    while (g.pageItems.length > 0) g.pageItems[0].move(g, ElementPlacement.PLACEBEFORE);
    g.remove();
  }
  // 残ったもとのレイヤー（背景の白など）：空なら消す
  try { if (first.pageItems.length === 0) first.remove(); else first.name = '\u80cc\u666f'; } catch (e) {}
  alert('\u30ec\u30a4\u30e4\u30fc\u306b\u5206\u3051\u307e\u3057\u305f\uff1a\u30b0\u30e9\u30d5\u30a3\u30c3\u30af\uff0f\u5206\u5272\uff0f\u58c1\u9762\u30e9\u30a4\u30f3\uff0f\u5bf8\u6cd5\uff0f\u4ed5\u69d8');
})();
