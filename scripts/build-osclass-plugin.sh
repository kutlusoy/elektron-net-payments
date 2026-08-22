#!/usr/bin/env bash
# Builds an installable oc-content/plugins/osclass-escrow zip: a real copy
# (never a symlink) of the shared core baked into vendor/, dev-only files
# stripped, ready to extract straight into an Osclass install.
#
# Usage: scripts/build-osclass-plugin.sh [version]
#   version defaults to the git tag/describe output if omitted.
#
# Requires: composer, zip, php (for languages/po2mo.php as a msgfmt fallback).

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-$(git -C "$REPO_ROOT" describe --tags --always 2>/dev/null || echo "0.0.0-dev")}"
VERSION="${VERSION#osclass-v}"
VERSION="${VERSION#v}"

BUILD_DIR="$(mktemp -d)"
trap 'rm -rf "$BUILD_DIR"' EXIT

echo "Building osclass-escrow ${VERSION} ..."

# Copy both osclass-escrow/ and shared/ preserving their relative layout,
# since osclass-escrow's composer.json depends on shared/ via a
# "../shared" path repository.
cp -a "$REPO_ROOT/osclass-escrow" "$BUILD_DIR/osclass-escrow"
cp -a "$REPO_ROOT/shared" "$BUILD_DIR/shared"

cd "$BUILD_DIR/osclass-escrow"

composer install --no-dev --optimize-autoloader --no-interaction

# Make sure every .mo is current, in case a .po was edited without
# recompiling (msgfmt preferred; languages/po2mo.php is the fallback this
# repo already ships for machines without gettext installed).
for po in languages/*/messages.po; do
  locale_dir="$(dirname "$po")"
  if command -v msgfmt >/dev/null 2>&1; then
    msgfmt -o "$locale_dir/messages.mo" "$po"
  else
    php languages/po2mo.php "$po" "$locale_dir/messages.mo"
  fi
done

# Stamp the release version into the plugin header so the deployed copy's
# "Version:" matches the tag/zip it came from.
sed -i.bak -E "s/^Version: .*/Version: ${VERSION}/" index.php && rm -f index.php.bak

# Composer metadata and the po->mo fallback compiler are dev-time tooling,
# not needed by a deployed copy; vendor/ already carries everything the
# plugin needs at runtime.
rm -f composer.json composer.lock languages/po2mo.php

cd "$BUILD_DIR"
DIST_DIR="$REPO_ROOT/dist"
mkdir -p "$DIST_DIR"
ZIP_PATH="$DIST_DIR/osclass-escrow-${VERSION}.zip"
rm -f "$ZIP_PATH"
zip -rq "$ZIP_PATH" osclass-escrow

echo "Built $ZIP_PATH"
