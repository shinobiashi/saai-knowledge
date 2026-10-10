#!/usr/bin/env bash
#
# Regenerate the add-on's (saai-knowledge-for-woocommerce) i18n files:
#   - languages/saai-knowledge-for-woocommerce.pot (source strings, from PHP + JS + block.json)
#   - languages/saai-knowledge-for-woocommerce-ja.mo / .l10n.php (compiled from the maintained .po)
#   - languages/saai-knowledge-for-woocommerce-ja-<hash>.json (per-script JS translations)
#
# Unlike the free plugin (bin/i18n-build.sh), ALL of these ship in the
# release ZIP (package.json's "files" lists languages/): WooCommerce.com
# products get no translate.wordpress.org language packs, so the add-on keeps
# load_plugin_textdomain() pointed at its own languages/ directory
# (docs/DESIGN.md, i18n section). That call also registers the directory as a
# custom path, which is what lets the block editor scripts — registered with
# wp_set_script_translations( $handle, $domain ) and no path — find the JSON
# files here.
#
# Run `npm run build` first: the JS JSON filenames are hashed from the
# *built* script paths that wp_set_script_translations() resolves at
# runtime (build/<block>/index.js), not the src/ paths that
# `wp i18n make-pot` records as references — hence the --use-map below.
# src/shared/*.js is bundled into every block's index.js, so it maps to all
# three. After running, diff languages/saai-knowledge-for-woocommerce.pot for
# new or changed strings and update languages/saai-knowledge-for-woocommerce-ja.po
# by hand before re-running.
set -euo pipefail

cd "$(dirname "$0")/.."

CONTAINER_DIR=/var/www/html/wp-content/plugins/saai-knowledge-for-woocommerce
DOMAIN=saai-knowledge-for-woocommerce

STARTED_WP_ENV=0
if ! npx wp-env run cli true >/dev/null 2>&1; then
	echo "==> wp-env not running, starting it"
	npx wp-env start
	STARTED_WP_ENV=1
fi

cleanup() {
	if [ "$STARTED_WP_ENV" = "1" ]; then
		echo "==> stopping wp-env (started by this script)"
		npx wp-env stop
	fi
}
trap cleanup EXIT

echo "==> wp i18n make-pot"
npx wp-env run cli -- wp i18n make-pot "$CONTAINER_DIR" "$CONTAINER_DIR/languages/$DOMAIN.pot" \
	--domain="$DOMAIN" --exclude=node_modules,build,vendor,tests,languages \
	--headers='{"Report-Msgid-Bugs-To":"https://github.com/shinobiashi/saai-knowledge/issues"}'

echo "==> wp i18n make-mo / make-php (ja)"
npx wp-env run cli -- bash -c "cd $CONTAINER_DIR && wp i18n make-mo languages/$DOMAIN-ja.po languages && wp i18n make-php languages/$DOMAIN-ja.po languages"

echo "==> wp i18n make-json (ja, mapped to build/ paths)"
# --no-purge: the .po is the hand-maintained source of truth for both the PHP
# .mo and these JS .json files; --purge would delete every entry it converts
# from the .po, dropping strings shared between PHP and JS on the next
# make-mo (see bin/i18n-build.sh).
BLOCKS='["build/product-faq/index.js","build/product-docs/index.js","build/product-glossary/index.js"]'
MAP="{
	\"src/product-faq/index.js\":\"build/product-faq/index.js\",
	\"src/product-docs/index.js\":\"build/product-docs/index.js\",
	\"src/product-glossary/index.js\":\"build/product-glossary/index.js\",
	\"src/shared/edit.js\":$BLOCKS,
	\"src/shared/product-picker.js\":$BLOCKS,
	\"assets/js/linked-products-panel.js\":\"assets/js/linked-products-panel.js\",
	\"assets/js/product-links-metabox.js\":\"assets/js/product-links-metabox.js\"
}"
npx wp-env run cli -- bash -c "cd $CONTAINER_DIR && wp i18n make-json languages/$DOMAIN-ja.po languages --no-purge --pretty-print --use-map='$(echo "$MAP" | tr -d '\n\t')'"

echo "==> done."
