#!/usr/bin/env bash
# Build a distributable .alfredworkflow for spnuke.
#
# Output: dist/spnuke.alfredworkflow
#
# Requires composer on PATH. Run from repo root.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$REPO_ROOT"

DIST_DIR="dist"
WORKFLOW_NAME="spnuke.alfredworkflow"
WORKFLOW_PATH="$DIST_DIR/$WORKFLOW_NAME"

echo "==> Cleaning previous build"
rm -rf "$DIST_DIR" vendor
mkdir -p "$DIST_DIR"

echo "==> Installing production dependencies"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Verifying entry point parses"
php -l src/nuke.php >/dev/null

echo "==> Building $WORKFLOW_PATH"
# Build into a temp staging dir so we don't accidentally include the dist
# folder or other host clutter inside the zip.
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

cp info.plist "$STAGE/"
cp -R src "$STAGE/"
cp -R vendor "$STAGE/"
if [ -f icon.png ]; then
    cp icon.png "$STAGE/"
fi
# Include the LICENSE and a slim README inside the package for transparency.
cp LICENSE "$STAGE/" 2>/dev/null || true
cp README.md "$STAGE/" 2>/dev/null || true

(
    cd "$STAGE"
    # -X strips macOS extended attrs; -q quiet
    zip -X -q -r "$REPO_ROOT/$WORKFLOW_PATH" .
)

echo "==> Done: $WORKFLOW_PATH"
ls -lh "$WORKFLOW_PATH"
