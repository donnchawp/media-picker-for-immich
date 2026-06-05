#!/usr/bin/env bash
#
# Prepares a release: creates a release/<version> branch, bumps the version
# in readme.txt and media-picker-for-immich.php (header + constant), drafts a
# changelog entry from the commits since the last release tag, opens readme.txt
# in $EDITOR so the changelog can be tidied by hand, runs `make check`, then
# pushes the branch and opens a PR for review.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

PLUGIN_SLUG="media-picker-for-immich"
PLUGIN_FILE="$PLUGIN_SLUG.php"

VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
	echo "Usage: $0 VERSION (e.g. 0.3.0)" >&2
	exit 1
fi
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[a-z0-9.-]+)?$ ]]; then
	echo "Version must look like x.y.z or x.y.z-suffix, got: $VERSION" >&2
	exit 1
fi

CURRENT_VERSION="$(sed -nE 's/^Stable tag: ([0-9A-Za-z.+-]+).*/\1/p' readme.txt | head -1)"
if [[ -z "$CURRENT_VERSION" ]]; then
	echo "Could not read current Stable tag from readme.txt." >&2
	exit 1
fi
if [[ "$CURRENT_VERSION" == "$VERSION" ]]; then
	echo "Version $VERSION already matches the Stable tag in readme.txt." >&2
	exit 1
fi
highest="$(printf '%s\n%s\n' "$CURRENT_VERSION" "$VERSION" | sort -V | tail -1)"
if [[ "$highest" != "$VERSION" ]]; then
	echo "Version $VERSION is not greater than current Stable tag $CURRENT_VERSION." >&2
	exit 1
fi

if git rev-parse -q --verify "refs/tags/$VERSION" >/dev/null; then
	echo "Tag $VERSION already exists." >&2
	exit 1
fi

# Portable in-place sed across BSD (macOS) and GNU.
inplace_sed() {
	local expr="$1" file="$2" tmp
	tmp="$(mktemp)"
	sed -E "$expr" "$file" > "$tmp" && mv "$tmp" "$file"
}

CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [[ "$CURRENT_BRANCH" != "main" ]]; then
	echo "pre-build must be run from main (currently on: $CURRENT_BRANCH)." >&2
	exit 1
fi

echo "Fetching origin..."
git fetch origin main
if ! git merge-base --is-ancestor origin/main HEAD; then
	echo "Local main is behind origin/main. Pull before running pre-build." >&2
	exit 1
fi

if ! git diff-index --quiet HEAD --; then
	echo "Working tree has uncommitted changes. Commit or stash first." >&2
	exit 1
fi

BRANCH="release/$VERSION"
if git rev-parse --verify "$BRANCH" >/dev/null 2>&1; then
	echo "Branch $BRANCH already exists." >&2
	exit 1
fi

# Collect commit subjects since the last release tag (before we add our own
# bump commit), to seed the changelog draft.
# Highest version tag, not the topologically-nearest one (matches the sort -V
# version comparison used above).
LAST_TAG="$(git tag --list --sort=-v:refname | head -1)"
if [[ -n "$LAST_TAG" ]]; then
	RANGE="$LAST_TAG..HEAD"
	echo "Drafting changelog from commits in $RANGE"
else
	RANGE="HEAD"
	echo "No previous tag found; drafting changelog from full history."
fi

BLOCK_FILE="$(mktemp -t mpi-changelog.XXXXXX)"
trap 'rm -f "$BLOCK_FILE"' EXIT
{
	echo "= $VERSION ="
	git log "$RANGE" --no-merges --reverse --format='* %s'
} > "$BLOCK_FILE"
# Ensure there is at least a placeholder bullet to edit.
if ! grep -q '^\* ' "$BLOCK_FILE"; then
	echo "* " >> "$BLOCK_FILE"
fi

echo "Creating branch $BRANCH"
git checkout -b "$BRANCH"

echo "Bumping version to $VERSION"
inplace_sed "s/^(Stable tag: ).*/\1$VERSION/" readme.txt
inplace_sed "s/^( \* Version: ).*/\1$VERSION/" "$PLUGIN_FILE"
inplace_sed "s/(define\( 'IMMICH_MEDIA_PICKER_VERSION', ')[^']*(' \);)/\1$VERSION\2/" "$PLUGIN_FILE"

# Splice the draft block in at the top of the Changelog section, right after
# the `== Changelog ==` header line.
NEW_README="$(mktemp)"
awk -v blockfile="$BLOCK_FILE" '
	{ print }
	/^== Changelog ==$/ && !done {
		print ""
		while ((getline line < blockfile) > 0) print line
		done = 1
	}
' readme.txt > "$NEW_README" && mv "$NEW_README" readme.txt

cat <<EOF

A changelog draft for $VERSION has been inserted at the top of the
"== Changelog ==" section of readme.txt. Tidy it into release notes.
${EDITOR:-vim} will open readme.txt now.
EOF
read -r -p "Press RETURN to edit readme.txt (Ctrl-C to abort)... " _

"${EDITOR:-vim}" readme.txt

echo
echo "Running make check (build + Plugin Check)..."
if ! make check; then
	cat >&2 <<EOF
make check failed. You are on branch $BRANCH with the version bump and
changelog edits still in place. Either:
  * fix the issues, then commit and push this branch and open the PR by hand; or
  * abandon this release: git checkout main && git branch -D $BRANCH
EOF
	exit 1
fi

echo
echo "----- git diff -----"
git --no-pager diff
echo "--------------------"

read -r -p "Commit, push, and open PR for $VERSION? [y/N] " confirm
if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
	cat <<EOF
Aborted. Branch $BRANCH is left in place with your changes.
Resume by committing/pushing it yourself, or abandon this release:
  git checkout main && git branch -D $BRANCH
EOF
	exit 1
fi

git add readme.txt "$PLUGIN_FILE"
git commit -m "Release $VERSION"
git push -u origin "$BRANCH"

gh pr create \
	--base main \
	--title "Release $VERSION" \
	--body "Prepares $PLUGIN_SLUG $VERSION for release. Bumps the plugin version (header + constant + Stable tag) and updates the changelog."

echo
echo "PR opened. Review and merge it, then run: make publish VERSION=$VERSION"
