#!/usr/bin/env bash
# Records which commit a plugin build comes from. Run it before packaging db-custom-blocks/
# so the deployed site can show its version and commit on Domain Brothers > Launch checks.
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(grep -m1 "Version:" db-custom-blocks/db-custom-blocks.php | awk '{print $NF}')
commit=$(git rev-parse --short=12 HEAD)
if git diff --quiet && git diff --cached --quiet; then tree=clean; else tree=dirty; fi
built=$(date -u +%Y-%m-%dT%H:%MZ)
printf '{"version":"%s","commit":"%s","tree":"%s","built":"%s"}\n' "$version" "$commit" "$tree" "$built" > db-custom-blocks/release.json
echo "release.json: version $version, commit $commit, $tree tree, built $built"
