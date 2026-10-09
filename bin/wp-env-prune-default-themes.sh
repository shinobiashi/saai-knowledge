#!/usr/bin/env bash
#
# Keep only the latest 2 block-theme releases plus Twenty Twenty-One (the
# classic theme used by the KB layout's classic-theme E2E coverage) and
# Storefront (WooCommerce's classic theme, used by the add-on's product-page
# E2E coverage) installed in the wp-env dev and tests sites. Runs as the
# wp-env "afterStart" lifecycle script.
set -euo pipefail

KEEP_THEMES='twentytwentyfive|twentytwentyfour|twentytwentyone|storefront'

for container in cli tests-cli; do
	# Neither twentytwentyone nor storefront is bundled with core; install (not
	# activate) so they're available for the classic-theme E2E specs to switch
	# to. Skip the install when already there — `--force` unconditionally
	# re-downloads and reinstalls on every wp-env start otherwise.
	for theme in twentytwentyone storefront; do
		npx wp-env run "$container" bash -c \
			"wp theme is-installed $theme || wp theme install $theme"
	done
	npx wp-env run "$container" bash -c \
		"wp theme list --field=name | grep -vE '^(${KEEP_THEMES})\$' | xargs -r wp theme delete"
done
