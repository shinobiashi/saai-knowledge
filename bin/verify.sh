#!/usr/bin/env bash
#
# Run the full local verification suite: PHPCS, PHPStan, and PHPUnit.
# Starts wp-env if it isn't already running, and stops it again afterwards
# only if this script was the one that started it.
set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> composer lint"
composer lint

echo "==> composer analyze"
composer exec phpstan analyse -- --memory-limit=512M

STARTED_WP_ENV=0
if ! npx wp-env run tests-cli true >/dev/null 2>&1; then
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

echo "==> composer test"
npx wp-env run tests-cli --env-cwd=saai-monorepo bash -c "composer test"
