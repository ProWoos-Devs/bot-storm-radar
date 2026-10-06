#!/bin/bash
#
# Build a release zip for Bot Storm Radar
#
#   ./build-zip.sh           GitHub build, with the GitHub release updater
#                            → /tmp/bot-storm-radar-X.Y.Z.zip
#   ./build-zip.sh --wporg   wordpress.org build, without any updater
#                            → /tmp/bot-storm-radar-X.Y.Z-wporg.zip
#
# Both have bot-storm-radar/ as root folder and no development files.
# OUT_DIR=/some/dir changes where the zip is written.
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$SCRIPT_DIR"
PLUGIN_SLUG="bot-storm-radar"
OUT_DIR="${OUT_DIR:-/tmp}"

FLAVOR="github"
case "${1:-}" in
    "")      ;;
    --wporg) FLAVOR="wporg" ;;
    *)       echo "Usage: $0 [--wporg]"; exit 1 ;;
esac

# Read version from plugin file.
VERSION=$(grep -oP "define\( 'BOTSTORMRADAR_VERSION', '\K[0-9]+\.[0-9]+\.[0-9]+" "$PLUGIN_DIR/$PLUGIN_SLUG.php")

if [[ -z "$VERSION" ]]; then
    echo "Error: Could not read version from $PLUGIN_SLUG.php"
    exit 1
fi

if [[ "$FLAVOR" == "wporg" ]]; then
    OUTPUT="$OUT_DIR/$PLUGIN_SLUG-$VERSION-wporg.zip"
else
    OUTPUT="$OUT_DIR/$PLUGIN_SLUG-$VERSION.zip"
fi
STAGING=$(mktemp -d "${TMPDIR:-/tmp}/$PLUGIN_SLUG-build.XXXXXX")
trap 'rm -rf "$STAGING"' EXIT
STAGED="$STAGING/$PLUGIN_SLUG"

echo "Building Bot Storm Radar v$VERSION ($FLAVOR build)..."
echo "Source: $PLUGIN_DIR"
echo "Output: $OUTPUT"

# Copy files, excluding dev/git files.
mkdir -p "$STAGED"
rsync -a --exclude='.git' \
         --exclude='.gitignore' \
         --exclude='.github' \
         --exclude='dev-tools' \
         --exclude='.wordpress-org' \
         --exclude='build-zip.sh' \
         --exclude='CLAUDE.md' \
         --exclude='.claude' \
         --exclude='PHPCS-REPORT.txt' \
         "$PLUGIN_DIR/" "$STAGED/"

if [[ "$FLAVOR" == "wporg" ]]; then
    # wordpress.org does not allow a plugin to update itself from elsewhere:
    # drop the updater class and every block marked github-build-only.
    ORCHESTRATOR="$STAGED/includes/class-bot-storm-radar.php"
    STARTS=$(grep -c 'github-build-only:start' "$ORCHESTRATOR" || true)
    ENDS=$(grep -c 'github-build-only:end' "$ORCHESTRATOR" || true)
    if [[ "$STARTS" != "2" || "$ENDS" != "2" ]]; then
        echo "Error: expected 2 github-build-only blocks in class-bot-storm-radar.php, found $STARTS start and $ENDS end markers"
        exit 1
    fi
    rm "$STAGED/includes/class-botstormradar-github-updater.php"
    sed -i '/github-build-only:start/,/github-build-only:end/d' "$ORCHESTRATOR"
    if grep -rniE 'github[-_]updater|github-build-only|update_plugins' "$STAGED" --include='*.php'; then
        echo "Error: updater code is still present in the wordpress.org build (lines above)"
        exit 1
    fi
fi

# Every staged PHP file must still parse.
while IFS= read -r -d '' f; do
    php -l "$f" > /dev/null || { echo "Error: $f does not parse"; exit 1; }
done < <(find "$STAGED" -name '*.php' -print0)

# Build zip from staging so root folder is bot-storm-radar/
rm -f "$OUTPUT"
(cd "$STAGING" && zip -q -r "$OUTPUT" "$PLUGIN_SLUG/")

echo ""
echo "Built: $OUTPUT"
ls -lh "$OUTPUT"
if [[ "$FLAVOR" == "github" ]]; then
    echo ""
    echo "To create a GitHub release:"
    echo "  gh release create v$VERSION $OUTPUT --title \"v$VERSION\" --notes \"## Changes\""
fi
