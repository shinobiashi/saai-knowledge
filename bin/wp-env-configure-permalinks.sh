#!/usr/bin/env bash
#
# Switches the wp-env dev and tests sites off the default "Plain" permalink
# structure. The plugin's CPT/taxonomy URLs (docs/DESIGN.md section 3.5, e.g.
# /kb/, /knowledge-category/{term}/) only resolve under a pretty permalink
# structure, same as any real site — this mirrors the Settings > Permalinks
# step a site owner would otherwise have to do by hand. Runs as the wp-env
# "afterStart" lifecycle script.
set -euo pipefail

for container in cli tests-cli; do
	npx wp-env run "$container" wp rewrite structure '/%postname%/' --hard
done
