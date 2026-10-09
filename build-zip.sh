#!/bin/bash
#
# Build a release zip for Bot Storm Radar
#
#   ./build-zip.sh           GitHub build, with the GitHub release updater
#                            → /tmp/bot-storm-radar-X.Y.Z.zip
#   ./build-zip.sh --wporg   wordpress.org build, without the updater and the early gate
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
    # wordpress.org allows neither a plugin that updates itself from elsewhere
    # nor one that writes PHP files, a must-use plugin or an auto_prepend_file
    # line: drop the updater and the early gate (its installer, early loading,
    # the state file and the channel), and every block marked
    # github-build-only. The plugin then refuses inside WordPress
    # (BotStormRadar_Inline_Gate).
    for f in github-updater gate gate-install gate-early state state-reader channel channel-drain; do
        rm "$STAGED/includes/class-botstormradar-$f.php"
    done
    # readme.txt is the wordpress.org readme; these two describe the GitHub build.
    rm "$STAGED/README.md" "$STAGED/CHANGELOG.md"
    # Directory icons and banners go to SVN assets/, not into the plugin. The
    # GitHub updater loads its copies from the repository, not from here.
    rm -f "$STAGED"/assets/icon-*.png "$STAGED"/assets/icon.svg "$STAGED"/assets/banner-*.png
    # Without the early gate nothing loads these files outside WordPress:
    # the gate-aware guard becomes the standard one.
    while IFS= read -r -d '' f; do
        sed -i -e '/^\/\/ Loadable inside WordPress.*BOTSTORMRADAR_GATE first\.$/d' \
               -e "s/^defined( 'BOTSTORMRADAR_GATE' ) || defined( 'ABSPATH' ) || exit;$/if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}/" "$f"
    done < <(find "$STAGED" -name '*.php' -print0)
    while IFS= read -r -d '' f; do
        STARTS=$(grep -c 'github-build-only:start' "$f" || true)
        ENDS=$(grep -c 'github-build-only:end' "$f" || true)
        if [[ "$STARTS" != "$ENDS" ]]; then
            echo "Error: $f has $STARTS github-build-only start and $ENDS end markers"
            exit 1
        fi
        if [[ "$STARTS" != "0" ]]; then
            sed -i '/github-build-only:start/,/github-build-only:end/d' "$f"
        fi
    done < <(find "$STAGED" -name '*.php' -print0)
    if [[ "$(grep -c 'github-build-only' "$STAGED/includes/class-bot-storm-radar.php" || true)" != "0" ]]; then
        echo "Error: markers left in class-bot-storm-radar.php"
        exit 1
    fi
    if grep -rniE 'github[-_]updater|github-build-only|update_plugins' "$STAGED" --include='*.php'; then
        echo "Error: updater code is still present in the wordpress.org build (lines above)"
        exit 1
    fi
    if grep -rn "defined( 'BOTSTORMRADAR_GATE' )" "$STAGED" --include='*.php'; then
        echo "Error: a gate-aware guard is left in the wordpress.org build (lines above)"
        exit 1
    fi
    if ls "$STAGED"/assets/*.png "$STAGED"/assets/*.svg >/dev/null 2>&1; then
        echo "Error: directory assets are left in assets/"
        exit 1
    fi
    if grep -rnE 'BotStormRadar_(Gate|Gate_Install|Gate_Early|State|State_Reader|Channel|Channel_Drain)\b' "$STAGED" --include='*.php'; then
        echo "Error: early gate code is still referenced in the wordpress.org build (lines above)"
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
