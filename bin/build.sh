#!/usr/bin/env bash
# Distributable ZIP build (wp.org submission / self-hosted install).
# Steps: vendor fetch → JS engine build → staging fa (a11yfy/ gyökérrel,
# ez kell a WP-adminos telepítéshez) → zip (dev/rejtett fájlok kizárva).
set -euo pipefail
cd "$(dirname "$0")/.."

./bin/fetch-vendors.sh

echo "Building JS engine…"
( cd js && npm install --no-audit --no-fund && node build.mjs )

VERSION=$(grep -m1 "Version:" a11yfy.php | sed 's/.*Version: *//' | tr -d ' ')
# A zip-beli mappanév a wp.org-on kiosztott sluggal egyezik (a jóváhagyott
# plugin is ebbe a könyvtárba települ).
SLUG="a11yfy-pdf-accessibility-checker-fixer"
OUT="build/${SLUG}-${VERSION}.zip"
STAGE="build/${SLUG}"
mkdir -p build
rm -rf "$STAGE"
rm -f "$OUT"

echo "Staging ${STAGE}…"
mkdir -p "$STAGE"
# languages/ szándékosan nincs a zipben: a wp.org a translate.wordpress.org-ról
# szolgálja ki a fordításokat (plugin-review követelmény).
cp -R a11yfy.php uninstall.php readme.txt includes assets vendor "$STAGE"/
# Vendor-sallang + rejtett fájlok (PCP: hidden files are not permitted).
rm -rf "$STAGE"/vendor/*/tests "$STAGE"/vendor/*/doc "$STAGE"/vendor/*/.github
rm -f "$STAGE"/vendor/pdfparser/Makefile "$STAGE"/vendor/pdfparser/phpunit-windows.xml \
      "$STAGE"/vendor/pdfparser/CONTRIBUTING.md \
      "$STAGE"/vendor/pdfparser/alt_autoload.php-dist \
      "$STAGE"/vendor/pdfparser/README.md \
      "$STAGE"/vendor/pdfparser/composer.json
find "$STAGE" -name '.*' -type f -delete

echo "Zipping ${OUT}…"
( cd build && zip -qr "${SLUG}-${VERSION}.zip" "$SLUG" )

echo "Done: $OUT"
unzip -l "$OUT" | tail -1
