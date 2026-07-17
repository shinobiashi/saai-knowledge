#!/usr/bin/env bash
#
# Keep only the latest 2 official WordPress default themes installed in the
# wp-env dev and tests sites. Runs as the wp-env "afterStart" lifecycle script.
set -euo pipefail

KEEP_THEMES='twentytwentyfive|twentytwentyfour'

for container in cli tests-cli; do
	npx wp-env run "$container" bash -c \
		"wp theme list --field=name | grep -vE '^(${KEEP_THEMES})\$' | xargs -r wp theme delete"
done
