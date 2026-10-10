#!/usr/bin/env bash
#
# Smoke-test the add-on's release ZIP on clean WordPress + WooCommerce
# installs, one QIT local environment (`qit env:up`) per version pair —
# the local stand-in for QIT's Activation test and the Marketplace's
# "two latest major releases of WooCommerce and WordPress" requirement,
# usable before a WooCommerce.com Partner account can run managed tests
# (docs/MARKETPLACE-PREFLIGHT.md).
#
#   bin/qit-matrix.sh [WP:WC ...]      e.g. bin/qit-matrix.sh 7.1.3:11.2.1 7.0.7:11.0.1
#
# With no arguments, runs DEFAULT_MATRIX below. Each environment gets the
# add-on from plugins/saai-knowledge-for-woocommerce/saai-knowledge-for-woocommerce.zip
# (build it first: `npx wp-scripts plugin-zip` in that directory); QIT
# installs the free plugin from WordPress.org itself, by resolving the
# add-on's "Requires Plugins" header. Environments run --online: offline
# (QIT's default), core's plugins_api() cannot reach WordPress.org to look
# up those dependencies and logs a PHP warning on every Plugins screen,
# which would bury anything the add-on itself logs. Then:
#   1. the add-on's Playwright specs, pointed at the environment;
#   2. an admin + storefront crawl as admin (dashboard, plugins, WooCommerce
#      settings, product list/new/edit, FAQ list/new, SAAI settings, shop,
#      a product with a linked FAQ, cart, checkout), asserting HTTP 200 (a
#      redirect for checkout, which sends an empty cart back to the cart) and
#      that the product page shows the FAQ tab;
#   3. deactivate and reactivate the add-on, and deactivate the free plugin
#      under it (the add-on must show a notice, not fatal), crawling between;
#   4. wp-content/debug.log (WP_DEBUG is on in QIT environments) must be empty.
# The environment is stopped afterwards whatever happened.
#
# Requires: qit CLI (composer global require woocommerce/qit-cli), Docker,
# Playwright browsers (npx playwright install chromium), python3.
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$PWD"

DEFAULT_MATRIX=( 7.1.3:11.2.1 7.1.3:11.1.2 7.1.3:11.0.1 7.0.7:11.2.1 7.0.7:11.1.2 7.0.7:11.0.1 )
MATRIX=( "$@" )
[ "${#MATRIX[@]}" -gt 0 ] || MATRIX=( "${DEFAULT_MATRIX[@]}" )

SLUG=saai-knowledge-for-woocommerce
ZIP="$ROOT/plugins/$SLUG/$SLUG.zip"
[ -f "$ZIP" ] || { echo "Missing $ZIP — run \`npx wp-scripts plugin-zip\` in plugins/$SLUG first." >&2; exit 2; }

WORK="$(mktemp -d "${TMPDIR:-/tmp}/qit-matrix.XXXXXX")"
# qit resolves a relative --plugin path against its own working directory, so
# hand it a stable absolute copy of the ZIP.
cp "$ZIP" "$WORK/$SLUG.zip"

ENV_ID=""
stop_env() {
	if [ -n "$ENV_ID" ]; then
		qit env:down "$ENV_ID" > /dev/null 2>&1 || true
		ENV_ID=""
	fi
}
# shellcheck disable=SC2329 # invoked by the trap below
on_exit() {
	local status=$?
	stop_env
	exit "$status"
}
trap on_exit EXIT INT TERM

# Runs a shell command in the environment's PHP container.
in_env() {
	qit env:exec --env_id="$ENV_ID" "$1"
}

# IDs of the QIT environments running now, one per line.
env_ids() {
	qit env:list --json 2> /dev/null | python3 -c '
import json, sys
try:
    envs = json.load(sys.stdin)
except ValueError:
    envs = []
for env in envs if isinstance(envs, list) else []:
    print(env.get("env_id", ""))
' || true
}

# Runs a WP-CLI command that prints a new object's ID (--porcelain) and
# echoes the ID; fails when the last line of output is not a number, so
# stray output can never be glued into a wrong ID.
porcelain_id() {
	local id
	id="$(in_env "$1" 2> /dev/null | tr -d '\r' | tail -n 1)" || true
	case "$id" in
		'' | *[!0-9]*) return 1 ;;
	esac
	echo "$id"
}

# GETs a path as the logged-in admin; prints "<code> <path>" and fails unless
# the status is 200 (or the second argument, e.g. 302).
fetch() {
	local code expected="${2:-200}"
	code="$(curl -s -o "$WORK/page.html" -w '%{http_code}' -b "$WORK/cookies" -c "$WORK/cookies" "$SITE$1")"
	echo "    $code $1"
	[ "$code" = "$expected" ] || { echo "    !! expected $expected"; return 1; }
}

# Fails if any page fails, after visiting them all. Called as `crawl || ...`,
# where set -e does not apply, so each status is collected explicitly.
crawl() {
	local product_url="$1" rc=0
	fetch /wp-admin/ || rc=1
	fetch /wp-admin/plugins.php || rc=1
	fetch '/wp-admin/admin.php?page=wc-settings' || rc=1
	fetch '/wp-admin/edit.php?post_type=product' || rc=1
	fetch '/wp-admin/post-new.php?post_type=product' || rc=1
	fetch "/wp-admin/post.php?post=$PRODUCT_ID&action=edit" || rc=1
	fetch '/wp-admin/edit.php?post_type=saai_faq' || rc=1
	fetch '/wp-admin/post-new.php?post_type=saai_faq' || rc=1
	fetch '/wp-admin/admin.php?page=saai-knowledge-settings' || rc=1
	fetch /shop/ || rc=1
	fetch "${product_url#"$SITE"}" || rc=1
	fetch /cart/ || rc=1
	fetch /checkout/ 302 || rc=1
	return "$rc"
}

RESULTS=()
FAILED=0

for pair in "${MATRIX[@]}"; do
	WP="${pair%%:*}"
	WC="${pair##*:}"
	echo "==> WordPress $WP / WooCommerce $WC"
	LOG="$WORK/$WP-$WC"
	mkdir -p "$LOG"

	BEFORE="$(env_ids | sort)"
	if ! ( cd "$WORK" && qit env:up --wp="$WP" --woo="$WC" --plugin="$WORK/$SLUG.zip" --online --json > "$LOG/env.json" 2> "$LOG/env.err" ); then
		echo "    !! qit env:up failed (see $LOG/env.json and env.err)"
		RESULTS+=( "$WP / $WC: env:up failed" )
		FAILED=1
		continue
	fi

	if ! ENV_INFO="$(python3 -c '
import json, sys
env = json.load(open(sys.argv[1]))
print(env["env_id"])
print(env["site_url"])
print(len(env.get("plugin_activation_failures") or []))
' "$LOG/env.json")"; then
		echo "    !! could not read $LOG/env.json"
		# Stop whatever this run started, and only that: other QIT
		# environments on this machine are none of this script's business.
		comm -13 <(echo "$BEFORE") <(env_ids | sort) | while read -r id; do
			[ -n "$id" ] && qit env:down "$id" > /dev/null 2>&1 || true
		done
		RESULTS+=( "$WP / $WC: env.json unreadable" )
		FAILED=1
		continue
	fi
	{ read -r ENV_ID; read -r SITE; read -r FAILURES; } <<< "$ENV_INFO"
	echo "    $SITE ($ENV_ID), activation failures: $FAILURES"
	STEP_FAILED=0
	[ "$FAILURES" = "0" ] || STEP_FAILED=1
	for plugin in woocommerce saai-knowledge "$SLUG"; do
		in_env "wp plugin is-active $plugin" > /dev/null 2>&1 || { echo "    !! $plugin is not active"; STEP_FAILED=1; }
	done

	# The block-theme specs switch to Twenty Twenty-Five; QIT ships Storefront only.
	in_env "wp theme install twentytwentyfive" > "$LOG/theme.log" 2>&1 || STEP_FAILED=1

	echo "    E2E"
	if WP_BASE_URL="$SITE" STORAGE_STATE_PATH="$LOG/storage/admin.json" npx playwright test "plugins/$SLUG" > "$LOG/e2e.log" 2>&1; then
		echo "    $(grep -E '[0-9]+ passed' "$LOG/e2e.log" | tail -n 1 | sed 's/^ *//')"
	else
		echo "    !! E2E failed (see $LOG/e2e.log)"
		STEP_FAILED=1
	fi
	in_env "wp theme activate storefront" > /dev/null 2>&1 || true

	# Content for the crawl: a product with a linked FAQ (one meta row per ID,
	# as the add-on stores links).
	PRODUCT_ID="$(porcelain_id "wp wc product create --name='QIT smoke product' --regular_price=10 --user=admin --porcelain")" || { echo "    !! could not create the product"; STEP_FAILED=1; }
	FAQ_ID="$(porcelain_id "wp post create --post_type=saai_faq --post_status=publish --post_title='QIT smoke question' --post_content='QIT smoke answer.' --porcelain")" || { echo "    !! could not create the FAQ"; STEP_FAILED=1; }
	in_env "wp post meta add ${FAQ_ID:-0} saai_linked_products ${PRODUCT_ID:-0}" > /dev/null 2>&1 || STEP_FAILED=1
	PRODUCT_URL="$(in_env "wp post url ${PRODUCT_ID:-0}" 2> /dev/null | tr -d '\r' | tail -n 1)" || true
	case "$PRODUCT_URL" in
		"$SITE"/*) ;;
		*) echo "    !! no product URL"; PRODUCT_URL="$SITE/"; STEP_FAILED=1 ;;
	esac
	echo "    product ${PRODUCT_ID:-?}, FAQ ${FAQ_ID:-?}, $PRODUCT_URL"
	in_env "wp rewrite flush" > /dev/null 2>&1 || true
	# A new store can be in WooCommerce's "coming soon" mode, which shows
	# logged-out visitors a placeholder instead of the shop (the E2E specs
	# browse logged in, so they don't notice). Launch it for the visitor check.
	in_env "wp option update woocommerce_coming_soon no" > /dev/null 2>&1 || true

	echo "    crawl (admin)"
	rm -f "$WORK/cookies"
	curl -s -o /dev/null -c "$WORK/cookies" "$SITE/wp-login.php" || STEP_FAILED=1
	curl -s -o /dev/null -b "$WORK/cookies" -c "$WORK/cookies" \
		--data-urlencode 'log=admin' --data-urlencode 'pwd=password' \
		--data-urlencode 'testcookie=1' --data-urlencode "redirect_to=$SITE/wp-admin/" \
		"$SITE/wp-login.php" || STEP_FAILED=1
	crawl "$PRODUCT_URL" || STEP_FAILED=1
	# As a visitor: what a shopper sees.
	curl -s -o "$LOG/product.html" "$PRODUCT_URL" || STEP_FAILED=1
	if grep -q 'tab-title-saai_faq' "$LOG/product.html"; then
		echo "    product page shows the FAQ tab"
	else
		echo "    !! product page has no FAQ tab (saved to $LOG/product.html)"
		in_env "wp post meta list $FAQ_ID --keys=saai_linked_products" > "$LOG/faq-meta.txt" 2>&1 || true
		in_env "wp theme list --status=active --field=name" >> "$LOG/faq-meta.txt" 2>&1 || true
		STEP_FAILED=1
	fi

	echo "    deactivate / reactivate"
	in_env "wp plugin deactivate $SLUG" > "$LOG/cycle.log" 2>&1 || STEP_FAILED=1
	fetch /wp-admin/plugins.php || STEP_FAILED=1
	fetch "${PRODUCT_URL#"$SITE"}" || STEP_FAILED=1
	in_env "wp plugin activate $SLUG" >> "$LOG/cycle.log" 2>&1 || STEP_FAILED=1
	in_env "wp plugin deactivate saai-knowledge" >> "$LOG/cycle.log" 2>&1 || STEP_FAILED=1
	fetch /wp-admin/plugins.php || STEP_FAILED=1
	if grep -q 'requires the free SAAI Knowledge plugin' "$WORK/page.html"; then
		echo "    missing free plugin: notice shown"
	else
		echo "    !! missing free plugin: no notice on plugins.php"
		STEP_FAILED=1
	fi
	fetch "${PRODUCT_URL#"$SITE"}" || STEP_FAILED=1
	in_env "wp plugin activate saai-knowledge" >> "$LOG/cycle.log" 2>&1 || STEP_FAILED=1

	echo "    debug.log"
	# shellcheck disable=SC2016 # expanded by the container's shell, not here
	in_env 'f="$(wp eval "echo WP_CONTENT_DIR;")/debug.log"; if [ -f "$f" ]; then cat "$f"; fi' > "$LOG/debug.log" 2>&1 || true
	# env:exec may print its own status lines; keep only PHP log entries.
	if grep -E '^\[[0-9]{2}-[A-Za-z]{3}-[0-9]{4} ' "$LOG/debug.log" > "$LOG/debug-entries.log"; then
		echo "    !! debug.log has $(wc -l < "$LOG/debug-entries.log" | tr -d ' ') entries:"
		# head first: piping sed into head would SIGPIPE sed on a long log,
		# and pipefail + set -e would then abort the whole matrix.
		head -n 20 "$LOG/debug-entries.log" | sed 's/^/       /'
		STEP_FAILED=1
	else
		echo "    debug.log empty"
	fi

	stop_env
	if [ "$STEP_FAILED" = "0" ]; then
		RESULTS+=( "$WP / $WC: pass" )
	else
		RESULTS+=( "$WP / $WC: FAIL (logs in $LOG)" )
		FAILED=1
	fi
done

echo
echo "==> Summary (logs: $WORK)"
printf '    %s\n' "${RESULTS[@]}"
exit "$FAILED"
