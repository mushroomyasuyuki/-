import sys
from PIL import Image, ImageDraw, ImageFont, ImageFilter
from fontTools.ttLib import TTCollection
nd, img, out = sys.argv[1], sys.argv[2], sys.argv[3]
def jp_index(ttc):
    for i, f in enumerate(TTCollection(ttc).fonts):
        if 'JP' in f['name'].getDebugName(1): return i
serif_p = nd + '/NotoSerifCJK-Regular.ttc'; sans_p = nd + '/NotoSansCJK-Regular.ttc'; sansb_p = nd + '/NotoSansCJK-Bold.ttc'
si, ni, bi = jp_index(serif_p), jp_index(sans_p), jp_index(sansb_p)
F = lambda p, i, s: ImageFont.truetype(p, s, index=i)
W, H = 1200, 630
bg = Image.new('RGB', (W, H), (8, 42, 73))
# 右上の青いにじみ
glow = Image.new('L', (W, H), 0); g = ImageDraw.Draw(glow); g.ellipse((640, -260, 1500, 520), fill=150)
glow = glow.filter(ImageFilter.GaussianBlur(120))
bg = Image.composite(Image.new('RGB', (W, H), (0, 94, 173)), bg, glow)
d = ImageDraw.Draw(bg)
for x in range(-H, W, 7):
    d.line((x, H, x + H, 0), fill=(14, 49, 82), width=1)
# 右：店舗の写真を重ねる
def card(path, w, angle, dim=1.0):
    im = Image.open(path).convert('RGB'); h = round(w * 3 / 4)
    im = im.resize((w, h), Image.LANCZOS)
    if dim < 1: im = Image.eval(im, lambda v: int(v * dim))
    b = 9; c = Image.new('RGBA', (w + b * 2, h + b * 2), (255, 255, 255, 255)); c.paste(im, (b, b))
    m = Image.new('L', c.size, 0); ImageDraw.Draw(m).rounded_rectangle((0, 0, c.size[0] - 1, c.size[1] - 1), 16, fill=255); c.putalpha(m)
    return c.rotate(angle, resample=Image.BICUBIC, expand=True)
def shadow(c, off=(0, 26), blur=24, alpha=150):
    s = Image.new('RGBA', c.size, (0, 0, 0, 0)); a = c.split()[3].point(lambda v: alpha if v else 0)
    s.putalpha(a); return s.filter(ImageFilter.GaussianBlur(blur)), off
canvas = bg.convert('RGBA')
for path, w, ang, dim, pos in [(img + '/use-apparel.webp', 380, 9, .7, (720, 40)), (img + '/use-gym.webp', 400, -7, .82, (610, 70)), (img + '/use-nail.webp', 470, 3, 1.0, (640, 150))]:
    c = card(path, w, ang, dim); s, off = shadow(c)
    canvas.alpha_composite(s, (pos[0] + off[0], pos[1] + off[1])); canvas.alpha_composite(c, pos)
d = ImageDraw.Draw(canvas)
d.text((64, 70), 'ORIGINAL PRINT TILE CARPET', font=F(sansb_p, bi, 20), fill=(250, 190, 0), spacing=0)
hf = F(serif_p, si, 58)
lines = ['お手持ちの絵柄を、', '50cm角の', 'タイルカーペットに。']
y = 120
for i, l in enumerate(lines):
    if i == 2:
        tw = d.textlength('タイルカーペット', font=hf)
        d.rectangle((64, y + 58, 64 + tw, y + 72), fill=(160, 128, 20))
    d.text((64, y), l, font=hf, fill=(255, 255, 255))
    y += 84
# 特長
cf = F(sansb_p, bi, 21); x = 64; y = 410
for t in ['土足OK', '防炎認定', '1枚ずつ交換', 'プレビューを見てから支払い']:
    tw = d.textlength(t, font=cf)
    if x + tw + 36 > 600: x = 64; y += 50
    d.rounded_rectangle((x, y, x + tw + 32, y + 40), 20, fill=(22, 62, 98), outline=(250, 190, 0), width=2)
    d.text((x + 16, y + 6), t, font=cf, fill=(255, 255, 255)); x += tw + 44
d.line((64, 548, 560, 548), fill=(60, 90, 120), width=1)
d.text((64, 562), 'マッシュオフィス', font=F(sansb_p, bi, 26), fill=(255, 255, 255))
d.text((300, 568), 'mush-office.com', font=F(sans_p, ni, 22), fill=(200, 215, 230))
canvas.convert('RGB').save(out, 'JPEG', quality=86, optimize=True, progressive=True)
print('ok')
