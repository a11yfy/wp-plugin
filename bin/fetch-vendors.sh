#!/usr/bin/env bash
# a11yfy WP plugin — vendor libek letöltése (build-time fetch, a vendor/ nincs gitben).
# A wp.org-ra beadott ZIP a build.sh-val készül, ami ezt előfeltételként futtatja.
# Supply-chain védelem: mindkét tarball sha256-pinnel; verzió-bump esetén a
# párban lévő *_SHA256 értéket is frissíteni kell (env-ből felülírható).
set -euo pipefail
cd "$(dirname "$0")/.."

AS_VERSION="${AS_VERSION:-3.9.2}"
AS_SHA256="${AS_SHA256:-462e09db1d579cc9b3971e14febf62ebf1dff5b82d8e1ed53e5ee10064eca292}"
SMALOT_VERSION="${SMALOT_VERSION:-v2.12.0}"
SMALOT_SHA256="${SMALOT_SHA256:-9f477c0b96f6210cdef1817c650473426c7c32eb38ae2cf0fb1ec464f5119bd7}"

mkdir -p vendor

# sha256 <file> — prints the digest (Linux: sha256sum, macOS: shasum).
sha256() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | awk '{print $1}'
  else
    shasum -a 256 "$1" | awk '{print $1}'
  fi
}

# verify <file> <expected-sha256> <name>
verify() {
  local actual
  actual="$(sha256 "$1")"
  if [ "$actual" != "$2" ]; then
    echo "ERROR: $3 checksum mismatch!" >&2
    echo "  expected: $2" >&2
    echo "  actual:   $actual" >&2
    rm -f "$1"
    exit 1
  fi
}

# ── Action Scheduler (GPL-3.0 — WooCommerce háttér-queue) ──────────────────
if [ ! -f vendor/action-scheduler/action-scheduler.php ]; then
  echo "Fetching Action Scheduler ${AS_VERSION}…"
  curl -fsSL "https://github.com/woocommerce/action-scheduler/archive/refs/tags/${AS_VERSION}.tar.gz" -o /tmp/as.tar.gz
  verify /tmp/as.tar.gz "${AS_SHA256}" "action-scheduler ${AS_VERSION}"
  rm -rf vendor/action-scheduler
  mkdir -p vendor/action-scheduler
  tar -xzf /tmp/as.tar.gz -C vendor/action-scheduler --strip-components=1
  rm /tmp/as.tar.gz
fi

# ── smalot/pdfparser (LGPL-3.0 — PHP-triázs) ───────────────────────────────
if [ ! -f vendor/pdfparser/src/Smalot/PdfParser/Parser.php ]; then
  echo "Fetching smalot/pdfparser ${SMALOT_VERSION}…"
  curl -fsSL "https://github.com/smalot/pdfparser/archive/refs/tags/${SMALOT_VERSION}.tar.gz" -o /tmp/pdfparser.tar.gz
  verify /tmp/pdfparser.tar.gz "${SMALOT_SHA256}" "smalot/pdfparser ${SMALOT_VERSION}"
  rm -rf vendor/pdfparser
  mkdir -p vendor/pdfparser
  tar -xzf /tmp/pdfparser.tar.gz -C vendor/pdfparser --strip-components=1
  rm /tmp/pdfparser.tar.gz
fi

# ── GPL-compliance kapu: a wp.org review LICENSE-fájlt vár a vendorokban ────
for lic in vendor/action-scheduler/license.txt vendor/pdfparser/LICENSE.txt; do
  if [ ! -f "$lic" ]; then
    echo "ERROR: missing vendor license file: $lic (wp.org GPL-compliance)" >&2
    exit 1
  fi
done

echo "Vendors OK."
