#!/bin/sh
# Usage: ./release.sh 2.1.1 — sets the version, commits, tags vX.Y.Z and pushes main + tag together.
set -e
v="$1"
[ -n "$v" ] || { echo "Usage: ./release.sh X.Y.Z"; exit 1; }
cd "$(dirname "$0")"
git pull --rebase --autostash --quiet origin main # Releases also come from other computers: start from the latest.
git fetch --tags --quiet
! git rev-parse -q --verify "refs/tags/v$v" >/dev/null || { echo "v$v already exists"; exit 1; }
f=egowebdesign-safe-updates.php
sed -i.bak -E "s/^( \* Version: ).*/\1$v/; s/(const VERSION += ')[^']*'/\1$v'/" "$f" && rm "$f.bak"
if command -v php >/dev/null; then php -l "$f"; else ssh granddefi php -l < "$f"; fi
git add -A
git commit -m "Version $v"
git tag "v$v"
git push --atomic origin main "v$v"
