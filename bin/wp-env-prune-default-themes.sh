#!/usr/bin/env bash
#
# Keep only the latest 2 block-theme releases plus Twenty Twenty-One (the
# classic theme used by the KB layout's classic-theme E2E coverage) installed
# in the wp-env dev and tests sites. Runs as the wp-env "afterStart" lifecycle
# script.
set -euo pipefail

KEEP_THEMES='twentytwentyfive|twentytwentyfour|twentytwentyone'

for container in cli tests-cli; do
	# twentytwentyone isn't bundled with core by default; install (not activate)
	# so it's available for the classic-theme E2E specs to switch to. Skip the
	# install when it's already there — `--force` unconditionally re-downloads
	# and reinstalls on every wp-env start otherwise.
	npx wp-env run "$container" bash -c \
		"wp theme is-installed twentytwentyone || wp theme install twentytwentyone"
	npx wp-env run "$container" bash -c \
		"wp theme list --field=name | grep -vE '^(${KEEP_THEMES})\$' | xargs -r wp theme delete"
done
