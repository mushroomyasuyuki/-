#!/bin/sh
# Builds dist/cad-floor-plan-embed.html: one paste-able block (CSS + markup + scripts) for a Custom HTML block.
set -e
cd "$(dirname "$0")"
VERSION=$(sed -n 's/.*"version": "\(.*\)".*/\1/p' package.json)
OUT=dist/cad-floor-plan-embed.html
strip() { grep -v '^[[:space:]]*$' "$1"; }
{
  echo "<!-- CAD Floor Plan 3D Simulator v$VERSION (embed) -->"
  echo "<style>"; strip assets/css/style.css; echo "</style>"
  # widget markup: the <div class="cad-floor-plan-widget"> block from standalone.html
  sed -n '/<div class="cad-floor-plan-widget"/,/^<script/p' standalone.html | sed '$d' | grep -v '^[[:space:]]*$'
  echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>'
  for f in cad-parser three-room-renderer room-tracer main; do echo "<script>"; strip assets/js/$f.js; echo "</script>"; done
} > "$OUT"
echo "built $OUT ($(wc -c < "$OUT") bytes)"
