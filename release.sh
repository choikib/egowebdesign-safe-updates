#!/bin/sh
# Usage: ./release.sh 2.1.1 — sets the version, commits, tags vX.Y.Z and pushes main + tag together.
set -e
v="$1"
[ -n "$v" ] || { echo "Usage: ./release.sh X.Y.Z"; exit 1; }
cd "$(dirname "$0")"
f=egowebdesign-safe-updates.php
sed -i -E "s/^( \* Version: ).*/\1$v/; s/(const VERSION += ')[^']*'/\1$v'/" "$f"
php -l "$f"
git add -A
git commit -m "Version $v"
git tag "v$v"
git push --atomic origin main "v$v"
