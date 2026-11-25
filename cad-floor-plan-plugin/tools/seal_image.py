# 社判の画像（白地に朱色）を、PDF に入れやすい形（色と透明度）にする
import sys, struct, zlib
from PIL import Image
src, out, png_out = sys.argv[1], sys.argv[2], sys.argv[3]
src_im = Image.open(src)
if src_im.mode in ('RGBA', 'LA') or 'transparency' in src_im.info:
    # 透明の部分がある画像：白い地に重ねてから扱う
    rgba = src_im.convert('RGBA')
    bg = Image.new('RGBA', rgba.size, (255, 255, 255, 255))
    bg.alpha_composite(rgba)
    im = bg.convert('RGB')
else:
    im = src_im.convert('RGB')
im.thumbnail((360, 360), Image.LANCZOS)  # 印の大きさ 約23mm で 約390dpi
w, h = im.size
px = im.load()
# 朱肉の色：濃いところの平均
ink = [0, 0, 0]; n = 0
for y in range(h):
    for x in range(w):
        r, g, b = px[x, y]
        if g < 80 and r > 150:
            ink[0] += r; ink[1] += g; ink[2] += b; n += 1
ink = [round(v / max(1, n)) for v in ink]
rgb = bytearray(); a = bytearray()
outim = Image.new('RGBA', (w, h))
op = outim.load()
for y in range(h):
    for x in range(w):
        r, g, b = px[x, y]
        al = (255 - min(g, b)) / (255 - min(ink[1], ink[2]))
        al = max(0.0, min(1.0, al))
        al = 0 if al < 0.06 else al
        A = round(al * 255)
        rgb += bytes(ink); a.append(A)
        op[x, y] = (ink[0], ink[1], ink[2], A)
outim.save(png_out)
rz = zlib.compress(bytes(rgb), 9); az = zlib.compress(bytes(a), 9)
with open(out, 'wb') as f:
    f.write(b'CFPS' + struct.pack('>HHII', w, h, len(rz), len(az)) + rz + az)
print(w, h, ink, len(rz), len(az))
