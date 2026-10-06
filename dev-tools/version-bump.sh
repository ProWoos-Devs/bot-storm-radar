#!/bin/bash

# Version Bump Script for Bot Storm Radar
# Usage: ./dev-tools/version-bump.sh [major|minor|patch] "description"
# Run from the plugin root directory (bot-storm-radar/)

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
cd "$PLUGIN_DIR"

# Check if version type is provided
if [ $# -lt 1 ]; then
    echo "Usage: $0 [major|minor|patch] [optional description]"
    echo "Example: $0 patch 'Tighten the storm threshold'"
    exit 1
fi

VERSION_TYPE=$1
DESCRIPTION=${2:-"Version bump"}

# Get current version from plugin file
CURRENT_VERSION=$(grep "Version:" bot-storm-radar.php | head -1 | sed 's/.*Version: *//' | tr -d ' \r')
echo "Current version: $CURRENT_VERSION"

# Parse version numbers
IFS='.' read -ra VERSION_PARTS <<< "$CURRENT_VERSION"
MAJOR=${VERSION_PARTS[0]}
MINOR=${VERSION_PARTS[1]}
PATCH=${VERSION_PARTS[2]}

# Bump version based on type
case $VERSION_TYPE in
    major)
        MAJOR=$((MAJOR + 1))
        MINOR=0
        PATCH=0
        ;;
    minor)
        MINOR=$((MINOR + 1))
        PATCH=0
        ;;
    patch)
        PATCH=$((PATCH + 1))
        ;;
    *)
        echo "Invalid version type. Use: major, minor, or patch"
        exit 1
        ;;
esac

NEW_VERSION="$MAJOR.$MINOR.$PATCH"
DATE=$(date +%Y-%m-%d)
MONTH_YEAR=$(date +"%B %-d, %Y")
echo "New version: $NEW_VERSION"

# 1. Update main plugin file: header Version + BOTSTORMRADAR_VERSION constant
sed -i "s/Version: *$CURRENT_VERSION/Version:     $NEW_VERSION/" bot-storm-radar.php
sed -i "s/BOTSTORMRADAR_VERSION', '$CURRENT_VERSION'/BOTSTORMRADAR_VERSION', '$NEW_VERSION'/" bot-storm-radar.php

# 2. Update README.md: version badge + "Current Version" line
sed -i "s/Version-$CURRENT_VERSION-red/Version-$NEW_VERSION-red/" README.md
sed -i "s/Current Version: $CURRENT_VERSION/Current Version: $NEW_VERSION/" README.md
sed -i "s/Released: [^*]*/Released: $MONTH_YEAR/" README.md

# 3. Update readme.txt: stable tag (its changelog section is written by hand)
sed -i "s/^Stable tag: $CURRENT_VERSION/Stable tag: $NEW_VERSION/" readme.txt

# 4. Update CHANGELOG.md: add new version entry
# awk + ENVIRON so the description can contain any character (a sed s/// here
# broke when the description held the delimiter '/', and would also mangle '&').
DESC="$DESCRIPTION" awk -v ver="$NEW_VERSION" -v date="$DATE" '
    { print }
    !ins && /^## \[Unreleased\]/ {
        print ""
        print "## [" ver "] - " date
        print ""
        print "### Changed"
        print "- " ENVIRON["DESC"]
        ins = 1
    }
' CHANGELOG.md > CHANGELOG.md.tmp && mv CHANGELOG.md.tmp CHANGELOG.md

echo ""
echo "Version bumped to $NEW_VERSION"
echo "Updated files:"
echo "  - bot-storm-radar.php (header + BOTSTORMRADAR_VERSION)"
echo "  - README.md (badge + Current Version)"
echo "  - readme.txt (Stable tag)"
echo "  - CHANGELOG.md (new version entry)"
echo ""
echo "Next steps:"
echo "1. Add the release to the Changelog and Upgrade Notice sections of readme.txt"
echo "2. Review the changes: git diff"
echo "3. Commit: git add . && git commit -m 'Release v$NEW_VERSION: $DESCRIPTION'"
echo "4. Tag: git tag v$NEW_VERSION"
echo "5. Push: git push && git push --tags"
