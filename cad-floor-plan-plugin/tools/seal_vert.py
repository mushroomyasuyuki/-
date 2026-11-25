# 社判用のフォントで、縦書きの字形（vert）を文字に直接割り当てる（GSUB を使わずに縦書きの小さい「ッ」などを出すため）
import sys
from fontTools.ttLib import TTFont
f = TTFont(sys.argv[1])
gsub = f['GSUB'].table
vmap = {}
for fr in gsub.FeatureList.FeatureRecord:
    if fr.FeatureTag in ('vert', 'vrt2'):
        for li in fr.Feature.LookupListIndex:
            lk = gsub.LookupList.Lookup[li]
            for st in lk.SubTable:
                m = getattr(st, 'mapping', None)
                if m is None and hasattr(st, 'ExtSubTable'):
                    m = getattr(st.ExtSubTable, 'mapping', None)
                if m: vmap.update(m)
n = 0
for t in f['cmap'].tables:
    for cp, g in list(t.cmap.items()):
        if g in vmap:
            t.cmap[cp] = vmap[g]; n += 1
del f['GSUB']
f.save(sys.argv[1])
print('remapped', n)
