# 領収書PDFに埋め込む和文フォント（TrueType）を作る：Noto Sans/Serif CJK JP（SIL OFL）から、
# 使う文字だけにして、CFF の輪郭を TrueType（glyf）に変える。
import sys
from fontTools.ttLib import TTFont, TTCollection, newTable
from fontTools import subset
from fontTools.pens.cu2quPen import Cu2QuPen
from fontTools.pens.ttGlyphPen import TTGlyphPen

src_dir, out_dir = sys.argv[1], sys.argv[2]

def charset_body():
    s = set(chr(c) for c in range(0x20, 0x7F))
    s.add('¥')
    for b1 in range(0x81, 0xFD):
        for b2 in range(0x40, 0xFD):
            try:
                s.add(bytes([b1, b2]).decode('cp932'))
            except Exception:
                pass
    s |= set('×÷・〜～－‐―㎡①②③④⑤⑥⑦⑧⑨⑩★☆●○■□▲△▼▽◆◇※〒')
    return ''.join(sorted(c for c in s if len(c) == 1))

def charset_seal():
    s = set(chr(c) for c in range(0x3041, 0x3097)) | set(chr(c) for c in range(0x30A1, 0x30FF)) | set('之印ー')
    return ''.join(sorted(s))

def to_ttf(font):
    gs = font.getGlyphSet()
    glyf = newTable('glyf'); glyf.glyphOrder = font.getGlyphOrder(); glyf.glyphs = {}
    for name in font.getGlyphOrder():
        pen = TTGlyphPen(gs)
        gs[name].draw(Cu2QuPen(pen, 1.0, reverse_direction=True))
        glyf.glyphs[name] = pen.glyph()
    font['glyf'] = glyf
    font['loca'] = newTable('loca')
    del font['CFF ']
    if 'VORG' in font: del font['VORG']
    font['head'].glyphDataFormat = 0
    maxp = font['maxp']; maxp.tableVersion = 0x00010000
    for k in ('maxZones','maxTwilightPoints','maxStorage','maxFunctionDefs','maxInstructionDefs','maxStackElements','maxSizeOfInstructions','maxComponentElements'):
        setattr(maxp, k, 0)
    maxp.maxZones = 1
    font['post'].formatType = 3.0
    font.sfntVersion = '\x00\x01\x00\x00'

def build(ttc, text, out, family):
    coll = TTCollection(ttc)
    jp = [f for f in coll.fonts if 'JP' in f['name'].getDebugName(1)][0]
    opts = subset.Options(); opts.layout_features = ['vert', 'vrt2']; opts.name_IDs = ['*']; opts.notdef_outline = True
    opts.glyph_names = False; opts.hinting = False; opts.drop_tables += ['GSUB', 'GPOS', 'BASE', 'VORG']
    if family == 'seal':
        opts.drop_tables = [t for t in opts.drop_tables if t != 'GSUB']  # 縦書きの字形（vert）を使う
    sub = subset.Subsetter(opts); sub.populate(text=text); sub.subset(jp)
    to_ttf(jp)
    jp.save(out)
    print(out, len(jp.getGlyphOrder()), 'glyphs')

build(src_dir + '/NotoSansCJK-Regular.ttc', charset_body(), out_dir + '/receipt-gothic.ttf', 'body')
build(src_dir + '/NotoSerifCJK-Regular.ttc', charset_seal(), out_dir + '/receipt-seal.ttf', 'seal')
