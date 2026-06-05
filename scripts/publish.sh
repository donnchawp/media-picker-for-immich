#!/usr/bin/env bash
#
# Publishes a release after the release PR has been merged into main:
# builds the zip (make release), creates an annotated git tag and a GitHub
# release using the matching changelog section as the notes, then optionally
# publishes the plugin to the WordPress.org SVN repository.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

PLUGIN_SLUG="media-picker-for-immich"
DIST_DIR="dist"
SVN_DIR="$DIST_DIR/svn"
SVN_URL="https://plugins.svn.wordpress.org/$PLUGIN_SLUG/"
LISTING_ASSETS_DIR="screenshots"

CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [[ "$CURRENT_BRANCH" != "main" ]]; then
	echo "publish must be run from main (currently on: $CURRENT_BRANCH)." >&2
	exit 1
fi

echo "Fetching origin..."
git fetch origin main
if [[ "$(git rev-parse HEAD)" != "$(git rev-parse origin/main)" ]]; then
	echo "Local main is not in sync with origin/main. Pull the merged release PR first." >&2
	exit 1
fi
if ! git diff-index --quiet HEAD --; then
	echo "Working tree has uncommitted changes. Commit or stash first." >&2
	exit 1
fi

VERSION="$(sed -nE 's/^Stable tag: ([0-9A-Za-z.+-]+).*/\1/p' readme.txt | head -1)"
if [[ -z "$VERSION" ]]; then
	echo "Could not read Stable tag from readme.txt." >&2
	exit 1
fi

# An optional VERSION argument is treated as an assertion against readme.txt.
EXPECTED="${1:-}"
if [[ -n "$EXPECTED" && "$EXPECTED" != "$VERSION" ]]; then
	echo "Requested version $EXPECTED does not match readme.txt Stable tag $VERSION." >&2
	exit 1
fi

if gh release view "$VERSION" >/dev/null 2>&1; then
	echo "GitHub release $VERSION already exists." >&2
	exit 1
fi

ZIP_FILE="$DIST_DIR/$PLUGIN_SLUG-$VERSION.zip"

echo "Building $ZIP_FILE..."
make release

if [[ ! -f "$ZIP_FILE" ]]; then
	echo "Expected $ZIP_FILE was not produced by make release." >&2
	exit 1
fi

ZIP_VERSION="$(unzip -p "$ZIP_FILE" "$PLUGIN_SLUG/readme.txt" 2>/dev/null | sed -nE 's/^Stable tag: ([0-9A-Za-z.+-]+).*/\1/p' | head -1)"
if [[ "$ZIP_VERSION" != "$VERSION" ]]; then
	echo "Stable tag in $ZIP_FILE ($ZIP_VERSION) does not match readme.txt ($VERSION)." >&2
	exit 1
fi

# Extract the changelog body for this version: everything between the
# `= <version> =` heading and the next `= ` heading (the heading itself is
# omitted because the release title already shows the version), trimming any
# leading/trailing blank lines.
NOTES="$(awk -v ver="$VERSION" '
	BEGIN { gsub(/\./, "\\.", ver) }
	$0 ~ "^= " ver " =" { inblock = 1; next }
	inblock && /^= / { exit }
	inblock {
		lines[NR] = $0
		if ($0 ~ /[^[:space:]]/) { if (!first) first = NR; last = NR }
	}
	END { for (i = first; i <= last; i++) print lines[i] }
' readme.txt)"

if [[ -z "$NOTES" ]]; then
	echo "No changelog section found for $VERSION in readme.txt." >&2
	exit 1
fi

cat <<EOF

Tag:     $VERSION
Zip:     $ZIP_FILE
Notes:
----------------------------------------
$NOTES
----------------------------------------
EOF

read -r -p "Create git tag and GitHub release $VERSION? [y/N] " confirm
if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
	echo "Aborted."
	exit 1
fi

if ! git rev-parse -q --verify "refs/tags/$VERSION" >/dev/null; then
	git tag -a "$VERSION" -m "Release $VERSION"
fi
git push origin "$VERSION"

gh release create "$VERSION" "$ZIP_FILE" \
	--title "$VERSION" \
	--notes "$NOTES" \
	--verify-tag

echo "GitHub release $VERSION created."

# ---- SVN publish to WordPress.org ----

read -r -p "Also publish $VERSION to WordPress.org SVN? [y/N] " svn_confirm
if [[ "$svn_confirm" != "y" && "$svn_confirm" != "Y" ]]; then
	echo "Skipped SVN publish."
	exit 0
fi

if ! command -v svn >/dev/null 2>&1; then
	echo "svn is not installed." >&2
	exit 1
fi

rm -rf "$SVN_DIR"
mkdir -p "$SVN_DIR"

echo "Shallow-checking out $SVN_URL"
svn checkout "$SVN_URL" "$SVN_DIR" --depth=empty
( cd "$SVN_DIR" && svn up trunk && svn up tags --depth=immediates && { svn up assets || true; } )

if [[ -e "$SVN_DIR/tags/$VERSION" ]]; then
	echo "Tag $VERSION already exists in SVN. Aborting." >&2
	exit 1
fi

# Unzip the released artefact and sync its contents into trunk, so trunk is a
# byte-for-byte match of the published zip.
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
unzip -q "$ZIP_FILE" -d "$TMP"
if [[ ! -d "$TMP/$PLUGIN_SLUG" ]] || [[ -z "$(ls -A "$TMP/$PLUGIN_SLUG")" ]]; then
	echo "Unzipped build is empty. Refusing to rsync --delete into SVN trunk." >&2
	exit 1
fi
echo "Syncing build into $SVN_DIR/trunk/"
rsync -a --delete --exclude='.svn/' "$TMP/$PLUGIN_SLUG/" "$SVN_DIR/trunk/"

# Sync WordPress.org listing assets (screenshots/banner/icon) if present.
# No --delete: this directory only holds screenshots, so a delete would wipe
# any banner/icon uploaded directly to wp.org.
if [[ -d "$LISTING_ASSETS_DIR" ]]; then
	echo "Syncing $LISTING_ASSETS_DIR/ into $SVN_DIR/assets/"
	mkdir -p "$SVN_DIR/assets"
	rsync -a --exclude='.svn/' "$LISTING_ASSETS_DIR/" "$SVN_DIR/assets/"
fi

# Stage SVN adds/removes based on `svn status`.
(
	cd "$SVN_DIR"
	while IFS= read -r line; do
		flag="${line:0:1}"
		# svn status prints flags in cols 1-7 and a space, then the path at col 9.
		path="${line:8}"
		# Append @ to avoid svn interpreting @ in filenames as peg revisions.
		case "$flag" in
			'?') svn add "${path}@" ;;
			'!') svn rm "${path}@" ;;
		esac
	done < <(svn status)
)

echo
echo "----- svn status -----"
( cd "$SVN_DIR" && svn status )
echo "----------------------"
read -r -p "Commit trunk and create tags/$VERSION on WordPress.org SVN? [y/N] " commit_confirm
if [[ "$commit_confirm" != "y" && "$commit_confirm" != "Y" ]]; then
	echo "Aborted before SVN commit. $SVN_DIR is left in place for inspection."
	exit 1
fi

(
	cd "$SVN_DIR"
	svn commit -m "Update to version $VERSION"
	svn cp "^/$PLUGIN_SLUG/trunk" "^/$PLUGIN_SLUG/tags/$VERSION" -m "Tagging version $VERSION"
)

echo "Published $PLUGIN_SLUG $VERSION to WordPress.org SVN."
