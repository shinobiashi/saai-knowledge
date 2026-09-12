#!/usr/bin/env bash
#
# Regenerate the free plugin's i18n build artifacts:
#   - languages/saai-knowledge.pot (source strings, from PHP + JS + block.json)
#   - languages/saai-knowledge-ja.mo / .l10n.php (compiled from the maintained .po)
#   - languages/saai-knowledge-ja-<hash>.json (per-script JS translations)
#
# NONE of these ship in the release ZIP (see package.json's "files", and the
# guard in ci-js.yml): translations for the WordPress.org build come from
# translate.wordpress.org, which generates and delivers its own .mo/.l10n.php
# /.json language packs into WP_LANG_DIR/plugins. languages/saai-knowledge-ja.po
# is kept here as the hand-maintained source to import into GlotPress.
#
# Because the plugin no longer calls load_plugin_textdomain() (WordPress.org
# review: it has been unnecessary since WP 4.6), WordPress does NOT look inside
# this languages/ directory at runtime — WP_Textdomain_Registry only searches
# WP_LANG_DIR/plugins, WP_LANG_DIR/themes, and paths registered by
# load_plugin_textdomain(). To try a translation locally, copy the compiled
# files into wp-content/languages/plugins/ instead:
#
#   npx wp-env run cli -- bash -c "mkdir -p /var/www/html/wp-content/languages/plugins \
#     && cp /var/www/html/wp-content/plugins/saai-knowledge/languages/saai-knowledge-ja.* \
#        /var/www/html/wp-content/languages/plugins/"
#
# Run `npm run build` first: the JS JSON filenames are hashed from the
# *built* script paths that wp_set_script_translations() resolves at
# runtime (build/<block>/index.js), not the src/ paths that
# `wp i18n make-pot` scans for human-readable references — hence the
# --use-map below. After running, diff languages/saai-knowledge.pot for
# new/changed strings and update languages/saai-knowledge-ja.po by hand
# before re-running.
set -euo pipefail

cd "$(dirname "$0")/.."

CONTAINER_DIR=/var/www/html/wp-content/plugins/saai-knowledge

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
npx wp-env run cli -- wp i18n make-pot "$CONTAINER_DIR" "$CONTAINER_DIR/languages/saai-knowledge.pot" \
	--domain=saai-knowledge --exclude=node_modules,build,vendor,tests

echo "==> wp i18n make-mo / make-php (ja)"
npx wp-env run cli -- bash -c "cd $CONTAINER_DIR && wp i18n make-mo languages/saai-knowledge-ja.po languages && wp i18n make-php languages/saai-knowledge-ja.po languages"

echo "==> wp i18n make-json (ja, mapped to build/ paths)"
# --no-purge: languages/saai-knowledge-ja.po is the hand-maintained source of
# truth for both the PHP .mo and these JS .json files. --purge deletes any
# entry it converts to JSON from the .po itself, which silently drops
# translations for strings shared between PHP and JS (e.g. "Category",
# "Order" — used in both a taxonomy/meta label and a block's index.js
# control) the next time make-mo/make-php run from this file.
npx wp-env run cli -- bash -c "cd $CONTAINER_DIR && wp i18n make-json languages/saai-knowledge-ja.po languages --no-purge --pretty-print --use-map='{\"src/faq-list/index.js\":\"build/faq-list/index.js\",\"src/search/index.js\":\"build/search/index.js\",\"assets/js/glossary-panel.js\":\"assets/js/glossary-panel.js\"}'"

echo "==> done."
