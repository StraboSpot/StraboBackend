#!/bin/bash
# File: pdfservice/sync-renderer.sh
# Description: Copy the desktop app's PDF renderer into vendor/, byte for byte.
#              The service runs these files unchanged (shims/ stands in for
#              their Electron-only requires), so server PDFs match the app's.
#              Run on a dev machine after the app's renderer changes, then
#              commit vendor/ and rebuild strabo-node.
#
#              Usage: ./sync-renderer.sh [path to StraboMicro2 checkout]
#              Default: ~/Desktop/Work/StraboMicro2

set -euo pipefail

APP="${1:-$HOME/Desktop/Work/StraboMicro2}"
HERE="$(cd "$(dirname "$0")" && pwd)"
FILES="projectSerializer.js pdfReactExport.js imageExport.js"

for f in $FILES; do
  [ -f "$APP/electron/$f" ] || { echo "missing $APP/electron/$f" >&2; exit 1; }
done

mkdir -p "$HERE/vendor"
for f in $FILES; do
  cp "$APP/electron/$f" "$HERE/vendor/$f"
done

{
  echo "Copied from StraboMicro2 electron/ by sync-renderer.sh. Do not edit here."
  echo "App commit: $(git -C "$APP" log -1 --format='%h %s' 2>/dev/null || echo unknown)"
  echo "App branch: $(git -C "$APP" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
  if [ -n "$(git -C "$APP" status --porcelain -- $(for f in $FILES; do printf "electron/%s " "$f"; done) 2>/dev/null)" ]; then
    echo "WARNING: the app had uncommitted changes to these files when copied"
  fi
  echo
  (cd "$HERE/vendor" && shasum -a 256 $FILES)
} > "$HERE/vendor/SOURCE.txt"

cat "$HERE/vendor/SOURCE.txt"
