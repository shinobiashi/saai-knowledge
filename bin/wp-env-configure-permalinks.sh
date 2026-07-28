#!/usr/bin/env bash
#
# Switches the wp-env dev and tests sites off the default "Plain" permalink
# structure, and makes sure saai-knowledge is active. The plugin's CPT/
# taxonomy URLs (docs/DESIGN.md section 3.5, e.g. /kb/,
# /knowledge-category/{term}/) only resolve under a pretty permalink
# structure, same as any real site — this mirrors the Settings > Permalinks
# step a site owner would otherwise have to do by hand. Runs as the wp-env
# "afterStart" lifecycle script, and again from package.json's
# `pretest:e2e`: `composer test` reinstalls the tests-cli site's DB via the
# WP core test bootstrap, which resets both of these (CLAUDE.md), so E2E
# needs them restored again if it runs after PHPUnit. Re-activating an
# already-active plugin is a harmless no-op, so running this unconditionally
# from both callers is safe.
set -euo pipefail

for container in cli tests-cli; do
	npx wp-env run "$container" wp plugin activate saai-knowledge
	npx wp-env run "$container" wp rewrite structure '/%postname%/' --hard
done
