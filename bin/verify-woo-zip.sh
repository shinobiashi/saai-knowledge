#!/usr/bin/env bash
#
# Verify the add-on's (saai-knowledge-for-woocommerce) release ZIP, as built
# by `npx wp-scripts plugin-zip` in plugins/saai-knowledge-for-woocommerce/.
#
#   bin/verify-woo-zip.sh [path/to/saai-knowledge-for-woocommerce.zip]
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
ZIP="${1:-plugins/$SLUG/$SLUG.zip}"

fail() {
	echo "::error::$1"
	exit 1
}

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

echo "OK: $ZIP — version ${VERSION}, WooCommerce ${WC_MIN} to ${WC_TESTED}."
