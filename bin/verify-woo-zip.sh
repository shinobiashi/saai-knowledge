#!/usr/bin/env bash
#
# Verify the add-on's (saai-knowledge-for-woocommerce) release ZIP, as built
# by `npx wp-scripts plugin-zip` in plugins/saai-knowledge-for-woocommerce/.
#
#   bin/verify-woo-zip.sh [--tag woo-v<x.y.z>] [--notes <file>] [path/to/saai-knowledge-for-woocommerce.zip]
#
#   --tag    also require the tag (woo-v<x.y.z>, nothing after the version: a
#            version's ZIP is never replaced, a changed ZIP gets a new patch
#            version) to name the ZIP's version;
#   --notes  once everything passes, write the GitHub Release body for the ZIP
#            (versions, requirements, commit, SHA-256) to <file>.
# .github/workflows/release-woo.yml runs it with both on a woo-v* tag push.
#
# Checks what WooCommerce.com and QIT look at when the ZIP is uploaded:
#   - the runtime files are in it (wp-scripts plugin-zip silently drops any
#     directory missing from package.json's "files"), including readme.txt
#     (QIT Validation fails without it), changelog.txt, and the bundled
#     translations (Marketplace products get no language packs);
#   - development files are not;
#   - the version agrees everywhere the Marketplace compares it — the plugin
#     header, the SAAI_KNOWLEDGE_WOO_VERSION constant, readme.txt's Stable
#     tag, and changelog.txt's latest entry, which must use WooCommerce.com's
#     "YYYY-MM-DD - version x.y.z" form (a changelog in any other form is
#     rejected at upload) — and the WooCommerce version
#     headers agree between the plugin file, readme.txt, and the minimum the
#     add-on enforces at runtime (SAAI_WOO_MIN_WC_VERSION).
set -euo pipefail

SLUG=saai-knowledge-for-woocommerce
ZIP=
TAG=
NOTES=

fail() {
	echo "::error::$1"
	exit 1
}

usage() {
	echo "usage: $0 [--tag woo-v<x.y.z>] [--notes <file>] [path/to/$SLUG.zip]" >&2
	exit 2
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--tag | --notes)
			[ "$#" -ge 2 ] && [ -n "$2" ] || usage
			if [ "$1" = --tag ]; then TAG="$2"; else NOTES="$2"; fi
			shift 2
			;;
		-*) usage ;;
		*)
			[ -z "$ZIP" ] || usage
			ZIP="$1"
			shift
			;;
	esac
done
ZIP="${ZIP:-plugins/$SLUG/$SLUG.zip}"

if [ -n "$TAG" ]; then
	[[ "$TAG" =~ ^woo-v[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "Tag $TAG is not woo-v<x.y.z>. Add-on tags carry the version and nothing else: a version's ZIP is never replaced, so a changed ZIP gets a new patch version instead of a suffix."
fi

[ -f "$ZIP" ] || fail "$ZIP not found — run \`npx wp-scripts plugin-zip\` in plugins/$SLUG first."

CHECK_DIR="$(mktemp -d "${RUNNER_TEMP:-/tmp}/$SLUG-zip-check.XXXXXX")"
# Keep the failing status: without the explicit exit, the trap's own rm
# would become the script's exit status.
on_exit() {
	local status=$?
	rm -rf "$CHECK_DIR"
	exit "$status"
}
trap on_exit EXIT
unzip -q "$ZIP" -d "$CHECK_DIR"
ROOT="$CHECK_DIR/$SLUG"

for dir in assets build includes languages; do
	[ -d "$ROOT/$dir" ] || fail "Release ZIP is missing $dir/ — check the \"files\" field in plugins/$SLUG/package.json."
done

for file in "$SLUG.php" readme.txt changelog.txt; do
	[ -f "$ROOT/$file" ] || fail "Release ZIP is missing $file — check the \"files\" field in plugins/$SLUG/package.json."
done

compgen -G "$ROOT/languages/*.mo" > /dev/null || fail "Release ZIP has no compiled translations (languages/*.mo) — run bin/i18n-build-woo.sh."

for path in src tests node_modules .eslintrc.js; do
	[ ! -e "$ROOT/$path" ] || fail "Release ZIP includes $path — development files must stay out of the \"files\" field in plugins/$SLUG/package.json."
done

# First value of a "Key: value" header line, trailing whitespace and CR removed.
header() {
	sed -n "s/^[ *]*$1:[[:space:]]*//p" "$2" | head -n 1 | tr -d '\r' | sed 's/[[:space:]]*$//'
}

# Value of a define( 'NAME', 'value' ); line.
constant() {
	sed -n "s/^define( '$1', '\([^']*\)' );/\1/p" "$2" | head -n 1
}

MAIN="$ROOT/$SLUG.php"
README="$ROOT/readme.txt"

VERSION="$(header 'Version' "$MAIN")"
VERSION_CONSTANT="$(constant SAAI_KNOWLEDGE_WOO_VERSION "$MAIN")"
STABLE_TAG="$(header 'Stable tag' "$README")"
CHANGELOG_VERSION="$(tr -d '\r' < "$ROOT/changelog.txt" | sed -n 's/^[0-9]\{4\}-[0-9]\{2\}-[0-9]\{2\} - version \([0-9][0-9.]*\)[[:space:]]*$/\1/p' | head -n 1)"

[ -n "$VERSION" ] || fail "No Version header in $SLUG.php."
[ "$VERSION_CONSTANT" = "$VERSION" ] || fail "SAAI_KNOWLEDGE_WOO_VERSION ($VERSION_CONSTANT) does not match the Version header ($VERSION)."
[ "$STABLE_TAG" = "$VERSION" ] || fail "readme.txt Stable tag ($STABLE_TAG) does not match the Version header ($VERSION)."
[ "$CHANGELOG_VERSION" = "$VERSION" ] || fail "changelog.txt's latest entry (${CHANGELOG_VERSION:-none in the \"YYYY-MM-DD - version x.y.z\" form}) does not match the Version header ($VERSION)."

WC_MIN="$(header 'WC requires at least' "$MAIN")"
WC_TESTED="$(header 'WC tested up to' "$MAIN")"
WC_MIN_CONSTANT="$(constant SAAI_WOO_MIN_WC_VERSION "$MAIN")"

[ -n "$WC_MIN" ] && [ -n "$WC_TESTED" ] || fail "$SLUG.php needs both \"WC requires at least\" and \"WC tested up to\" headers."
[ "$WC_MIN_CONSTANT" = "$WC_MIN" ] || fail "SAAI_WOO_MIN_WC_VERSION ($WC_MIN_CONSTANT) does not match \"WC requires at least\" ($WC_MIN)."
[ "$(header 'WC requires at least' "$README")" = "$WC_MIN" ] || fail "readme.txt \"WC requires at least\" does not match $SLUG.php ($WC_MIN)."
[ "$(header 'WC tested up to' "$README")" = "$WC_TESTED" ] || fail "readme.txt \"WC tested up to\" does not match $SLUG.php ($WC_TESTED)."
[ "$(header 'Requires PHP' "$README")" = "$(header 'Requires PHP' "$MAIN")" ] || fail "readme.txt \"Requires PHP\" does not match $SLUG.php."
[ "$(header 'Requires at least' "$README")" = "$(header 'Requires at least' "$MAIN")" ] || fail "readme.txt \"Requires at least\" does not match $SLUG.php."

if [ -n "$TAG" ]; then
	[ "${TAG#woo-v}" = "$VERSION" ] || fail "Tag $TAG does not match the ZIP's version ($VERSION)."
fi

if [ -n "$NOTES" ]; then
	WP_MIN="$(header 'Requires at least' "$MAIN")"
	PHP_MIN="$(header 'Requires PHP' "$MAIN")"
	BASE_MIN="$(constant SAAI_WOO_MIN_BASE_VERSION "$MAIN")"
	[ -n "$WP_MIN" ] && [ -n "$PHP_MIN" ] && [ -n "$BASE_MIN" ] || fail "$SLUG.php needs \"Requires at least\", \"Requires PHP\", and SAAI_WOO_MIN_BASE_VERSION for the release notes."
	if command -v sha256sum > /dev/null; then
		SHA256="$(sha256sum "$ZIP" | cut -d ' ' -f 1)"
	else
		SHA256="$(shasum -a 256 "$ZIP" | cut -d ' ' -f 1)"
	fi
	COMMIT="$(git -C "$(dirname "$0")/.." rev-parse HEAD)"
	{
		echo "The release ZIP of SAAI Knowledge for WooCommerce $VERSION, built from commit $COMMIT and checked by \`bin/verify-woo-zip.sh\`. Upload this file, unchanged, to WooCommerce.com."
		echo
		echo "> [!WARNING]"
		echo "> Keep this release a **draft**. The repository is public, so publishing it would let anyone download the paid add-on."
		echo
		echo "| Item | Value |"
		echo "| --- | --- |"
		echo "| Version | $VERSION |"
		[ -z "$TAG" ] || echo "| Tag | $TAG |"
		echo "| Commit | $COMMIT |"
		echo "| SHA-256 of $SLUG.zip | \`$SHA256\` |"
		echo "| WordPress | $WP_MIN or later |"
		echo "| PHP | $PHP_MIN or later |"
		echo "| WooCommerce | $WC_MIN or later (tested up to $WC_TESTED) |"
		echo "| SAAI Knowledge | $BASE_MIN or later |"
		echo
		echo "WooCommerce.com adds a \`Woo:\` header to the plugin file on upload, so the ZIP customers download does not match this SHA-256."
		echo
		echo "## WooCommerce.com upload"
		echo
		echo "- Uploaded: (YYYY-MM-DD)"
		echo "- Result: (pending)"
	} > "$NOTES"
fi

echo "OK: $ZIP — version ${VERSION}, WooCommerce ${WC_MIN} to ${WC_TESTED}${TAG:+, tag $TAG}."
