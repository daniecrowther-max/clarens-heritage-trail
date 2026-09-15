#!/usr/bin/env bash
#
# The ONLY supported way to build the plugin release zip. Do not hand-roll
# `git archive` or `zip` elsewhere.
#
# The repo directory is `wordpress-plugin/`, but the plugin is installed on
# the WordPress host as `wp-content/plugins/cha-heritage-trail/`. A zip whose
# single top-level folder is anything else — `wordpress-plugin/`, a
# version-stamped `cha-heritage-trail-vX.Y.Z/`, or no folder at all — installs
# as a second, duplicate plugin (or lands loose in wp-content/plugins/) instead
# of updating the live one. The prefix below is therefore a literal, never
# derived from the version, and the resulting archive is checked byte-for-byte
# afterwards: a green test suite cannot see a broken zip, only the zip can.
#
# Usage:  tools/build-zip.sh
# Output: dist/cha-heritage-trail.zip, built via `git archive` from HEAD —
#         never from the working tree — so the zip always matches a commit.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

readonly PREFIX="cha-heritage-trail/"
readonly OUT="dist/cha-heritage-trail.zip"

mkdir -p dist
rm -f "$OUT"

git archive --format=zip --prefix="$PREFIX" -o "$OUT" HEAD:wordpress-plugin/

# ── The guard: exactly one top-level entry, and it must be the bare,
# un-stamped "cha-heritage-trail/" directory. Every entry in the archive
# must live under it — nothing loose at the zip root.
entries="$(unzip -Z1 "$OUT")"
top_levels="$(echo "$entries" | cut -d/ -f1 | sort -u)"
top_level_count="$(echo "$top_levels" | wc -l)"

fail() {
	echo "BUILD FAILED: $1" >&2
	rm -f "$OUT"
	exit 1
}

if [ -z "$entries" ]; then
	fail "the archive is empty."
fi

if [ "$top_level_count" -ne 1 ]; then
	fail "expected exactly one top-level entry, found $top_level_count: $(echo "$top_levels" | tr '\n' ' ')"
fi

if [ "$top_levels" != "cha-heritage-trail" ]; then
	fail "the one top-level entry must be exactly 'cha-heritage-trail', got '$top_levels' — any other prefix installs a duplicate plugin instead of updating the live one."
fi

first_entry="$(echo "$entries" | head -1)"
if [ "$first_entry" != "cha-heritage-trail/" ]; then
	fail "the first zip entry must be the bare directory 'cha-heritage-trail/', got '$first_entry'."
fi

count="$(echo "$entries" | wc -l)"
sha="$(sha256sum "$OUT" | cut -d' ' -f1)"

echo "Built $OUT"
echo "  entries: $count"
echo "  sha256:  $sha"
