#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$ROOT/build"
SLUG="lightweb2-publish-with-buffer"

rm -rf "$BUILD"
mkdir -p "$BUILD/$SLUG"

rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/" "$BUILD/$SLUG/"

(cd "$BUILD" && zip -qr "$SLUG.zip" "$SLUG")

echo "Built $BUILD/$SLUG.zip"
