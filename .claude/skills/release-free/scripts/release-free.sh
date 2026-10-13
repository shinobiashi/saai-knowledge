#!/usr/bin/env bash
#
# Mechanical steps for releasing the free plugin (saai-knowledge) to
# WordPress.org. .claude/skills/release-free/SKILL.md describes the procedure
# that calls them. Only `bump` changes tracked files; `zip` builds into the
# gitignored plugins/saai-knowledge/saai-knowledge.zip and dist/free-check/.
#
#   release-free.sh bump <x.y.z>           set the version in its three places
#                                          and add an empty changelog heading
#   release-free.sh check <x.y.z> [--tag]  before tagging: versions, changelog,
#                                          Upgrade Notice, tag; --tag also checks
#                                          main, the working tree and HEAD's CI
#   release-free.sh latest [--env]         latest WordPress / WooCommerce against
#                                          the headers; --env also asks wp-env
#   release-free.sh strings [<prev-tag>]   translatable strings added since a tag
#   release-free.sh zip                    build the release ZIP, extract it to
#                                          dist/free-check, run Plugin Check
#   release-free.sh verify <x.y.z>         after deploying: GitHub Release, SVN,
#                                          plugins API, download
#
# Checks print OK / FAIL / WARN / INFO lines; the script exits 1 if any FAIL
# was printed. Works with macOS's bash 3.2.
set -uo pipefail

FAILS=0
ok() { printf 'OK    %s\n' "$*"; }
fail() {
	printf 'FAIL  %s\n' "$*"
	FAILS=$((FAILS + 1))
}
warn() { printf 'WARN  %s\n' "$*"; }
info() { printf 'INFO  %s\n' "$*"; }
die() {
	printf 'error: %s\n' "$*" >&2
	exit 2
}
finish() {
	if [ "$FAILS" -gt 0 ]; then
		echo "NG ($FAILS failed)"
		exit 1
	fi
	echo "DONE"
}

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || die "run this inside the repository"
cd "$ROOT" || die "cannot cd to $ROOT"

SLUG=saai-knowledge
DIR="plugins/$SLUG"
MAIN="$DIR/$SLUG.php"
README="$DIR/readme.txt"
WOO_MAIN=plugins/saai-knowledge-for-woocommerce/saai-knowledge-for-woocommerce.php
WOO_README=plugins/saai-knowledge-for-woocommerce/readme.txt
EXTRACT=dist/free-check
WPORG_API='https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug='
SVN="https://plugins.svn.wordpress.org/$SLUG"
PC_CATEGORIES=general,plugin_repo,security,performance,accessibility

# First "Key: value" header in a plugin file or readme.txt, trimmed.
header() { # <file> <key>
	sed -n "s/^[ *]*$2:[[:space:]]*//p" "$1" | head -1 | tr -d '\r' | sed 's/[[:space:]]*$//'
}

# Value of define( '<name>', '<value>' ).
constant() { # <file> <name>
	sed -n "s/^[[:space:]]*define( '$2', '\([^']*\)' );.*/\1/p" "$1" | head -1
}

valid_version() { [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; }

version_gt() { # <a> <b>: a > b
	python3 -I -c 'import sys
t = lambda s: [int(x) for x in s.split(".")]
sys.exit(0 if t(sys.argv[1]) > t(sys.argv[2]) else 1)' "$1" "$2"
}

# "major.minor" of a version.
line_of() { echo "$1" | cut -d. -f1-2; }

# key=value lines about <version>'s entries in readme.txt.
readme_info() { # <readme> <version>
	python3 -I - "$1" "$2" <<'PY'
import re
import sys

path, ver = sys.argv[1], sys.argv[2]
text = open(path, encoding='utf-8').read().replace('\r\n', '\n')


def section(name):
    m = re.search(r'^== ' + re.escape(name) + r' ==[ \t]*$', text, re.M)
    if not m:
        return ''
    rest = text[m.end():]
    n = re.search(r'^== .+ ==[ \t]*$', rest, re.M)
    return rest[:n.start()] if n else rest


def block(sec):
    m = re.search(r'^= ' + re.escape(ver) + r'(?: - [0-9-]+)? =[ \t]*$', sec, re.M)
    if not m:
        return None
    rest = sec[m.end():]
    n = re.search(r'^= .+ =[ \t]*$', rest, re.M)
    return (rest[:n.start()] if n else rest).strip('\n')


changelog = section('Changelog')
first = re.search(r'^= (.+?) =[ \t]*$', changelog, re.M)
entries = block(changelog)
notice = block(section('Upgrade Notice'))
notice_text = ' '.join(l.strip() for l in (notice or '').split('\n') if l.strip())
print('changelog_first=' + (first.group(1) if first else ''))
print('changelog_heading=' + ('no' if entries is None else 'yes'))
print('changelog_entries=' + str(len(re.findall(r'^\* ', entries or '', re.M))))
print('notice=' + ('no' if notice is None else 'yes'))
print('notice_len=' + str(len(notice_text)))
PY
}

field() { # <key=value lines> <key>
	printf '%s\n' "$1" | sed -n "s/^$2=//p"
}

cmd_bump() {
	local ver="${1:-}" cur
	valid_version "$ver" || die "usage: bump <x.y.z>"
	cur="$(header "$MAIN" Version)"
	version_gt "$ver" "$cur" || die "$ver is not greater than the current version $cur"
	python3 -I - "$MAIN" "$README" "$cur" "$ver" <<'PY' || die "the version was not changed (see above)"
import re
import sys

main, readme, cur, new = sys.argv[1:5]


def edit(path, pattern, repl):
    with open(path, encoding='utf-8', newline='') as f:
        s = f.read()
    t, n = re.subn(pattern, repl, s, count=1, flags=re.M)
    if n != 1:
        sys.exit(path + ': no line matches ' + pattern)
    with open(path, 'w', encoding='utf-8', newline='') as f:
        f.write(t)


v = re.escape(cur)
edit(main, r'^([ \t]*\*[ \t]*Version:[ \t]*)' + v + r'[ \t]*$', r'\g<1>' + new)
edit(main, r"^(define\( 'SAAI_KNOWLEDGE_VERSION', ')" + v + r"(' \);)", r'\g<1>' + new + r'\g<2>')
edit(readme, r'^(Stable tag:[ \t]*)' + v + r'[ \t]*$', r'\g<1>' + new)
with open(readme, encoding='utf-8', newline='') as f:
    s = f.read()
if not re.search(r'^= ' + re.escape(new) + r' =', s, re.M):
    edit(readme, r'^(== Changelog ==[ \t]*\r?\n\r?\n)', r'\g<1>= ' + new + ' =\n\n')
PY
	ok "Version: header, SAAI_KNOWLEDGE_VERSION and Stable tag: $cur -> $ver"
	info "added '= $ver =' to the Changelog (write the entries right below it)"
	info "left as they are: phpstan-bootstrap.php's SAAI_KNOWLEDGE_VERSION and the add-on's SAAI_WOO_MIN_BASE_VERSION"
	info "the Upgrade Notice for $ver is written when preparing the release (check fails until then)"
}

cmd_check() {
	local ver="${1:-}" tagmode=0 h c s ri v
	valid_version "$ver" || die "usage: check <x.y.z> [--tag]"
	[ "${2:-}" = "--tag" ] && tagmode=1

	h="$(header "$MAIN" Version)"
	c="$(constant "$MAIN" SAAI_KNOWLEDGE_VERSION)"
	s="$(header "$README" 'Stable tag')"
	if [ "$h" = "$ver" ]; then ok "Version: header $h"; else fail "Version: header is '$h', not $ver ($MAIN)"; fi
	if [ "$c" = "$ver" ]; then ok "SAAI_KNOWLEDGE_VERSION $c"; else fail "SAAI_KNOWLEDGE_VERSION is '$c', not $ver ($MAIN)"; fi
	if [ "$s" = "$ver" ]; then ok "Stable tag $s"; else fail "Stable tag is '$s', not $ver ($README)"; fi

	ri="$(readme_info "$README" "$ver")" || die "could not read $README"
	if [ "$(field "$ri" changelog_heading)" = yes ]; then
		v="$(field "$ri" changelog_entries)"
		if [ "$v" -gt 0 ]; then ok "Changelog '= $ver =' entries: $v"; else fail "Changelog '= $ver =' has no entries"; fi
	else
		fail "Changelog has no '= $ver =' heading"
	fi
	v="$(field "$ri" changelog_first)"
	if [ "$v" = "$ver" ]; then ok "Changelog starts with $ver"; else fail "Changelog starts with '$v', not $ver"; fi
	if [ "$(field "$ri" notice)" = yes ]; then
		v="$(field "$ri" notice_len)"
		if [ "$v" -eq 0 ]; then
			fail "Upgrade Notice '= $ver =' is empty"
		elif [ "$v" -le 300 ]; then
			ok "Upgrade Notice '= $ver =' is $v characters (300 at most)"
		else
			fail "Upgrade Notice '= $ver =' is $v characters (300 at most)"
		fi
	else
		fail "Upgrade Notice has no '= $ver =' entry"
	fi

	for v in 'Requires at least' 'Requires PHP'; do
		h="$(header "$MAIN" "$v")"
		s="$(header "$README" "$v")"
		if [ "$h" = "$s" ]; then ok "$v $h (plugin file and readme.txt)"; else fail "$v differs: plugin file '$h', readme.txt '$s'"; fi
	done
	v="$(header "$README" 'Tested up to')"
	if [[ "$v" =~ ^[0-9]+\.[0-9]+$ ]]; then info "Tested up to $v (compare with \`latest\`)"; else warn "Tested up to '$v' is not major.minor"; fi
	info "left as they are: phpstan-bootstrap.php's SAAI_KNOWLEDGE_VERSION ($(constant phpstan-bootstrap.php SAAI_KNOWLEDGE_VERSION)), SAAI_WOO_MIN_BASE_VERSION ($(constant "$WOO_MAIN" SAAI_WOO_MIN_BASE_VERSION))"

	if git rev-parse -q --verify "refs/tags/v$ver" >/dev/null; then fail "tag v$ver already exists locally"; fi
	if [ -n "$(git ls-remote --tags origin "refs/tags/v$ver" 2>/dev/null)" ]; then
		fail "tag v$ver already exists on origin"
	else
		ok "tag v$ver does not exist on origin yet"
	fi

	if [ "$tagmode" -eq 1 ]; then
		local branch sha runs
		branch="$(git branch --show-current)"
		[ -n "$branch" ] || branch='(detached HEAD)'
		if [ "$branch" = main ]; then ok "on main"; else fail "on '$branch', not main"; fi
		if [ -z "$(git status --porcelain --untracked-files=no)" ]; then ok "no uncommitted changes"; else fail "uncommitted changes in tracked files"; fi
		git fetch -q origin main 2>/dev/null || warn "git fetch origin main failed"
		sha="$(git rev-parse HEAD)"
		if [ "$sha" = "$(git rev-parse origin/main)" ]; then ok "HEAD = origin/main ($(git rev-parse --short HEAD))"; else fail "HEAD is not origin/main (pull or push first)"; fi
		runs="$(gh api "repos/{owner}/{repo}/actions/runs?head_sha=$sha" --jq '.workflow_runs[] | "\(.name)|\(.status)|\(.conclusion // "")"' 2>/dev/null)"
		if [ -z "$runs" ]; then
			fail "no CI run for HEAD yet"
		else
			while IFS='|' read -r name status conclusion; do
				if [ "$status" != completed ]; then
					fail "CI '$name' is $status"
				elif [ "$conclusion" = success ]; then
					ok "CI '$name' success"
				else
					fail "CI '$name' $conclusion"
				fi
			done <<EOF
$runs
EOF
		fi
	fi
	finish
}

cmd_latest() {
	local env=0 wp wc wc_line wc_floor tested json
	[ "${1:-}" = "--env" ] && env=1
	wp="$(curl -fsS https://api.wordpress.org/core/version-check/1.7/ | python3 -I -c 'import json, sys
offers = json.load(sys.stdin)["offers"]
print(max((o["current"] for o in offers), key=lambda s: [int(x) for x in s.split(".")]))')" || die "could not read the WordPress version-check API"
	json="$(curl -fsS "${WPORG_API}woocommerce")" || die "could not read the plugins API"
	wc="$(printf '%s' "$json" | python3 -I -c 'import json, sys; print(json.load(sys.stdin)["version"])')"
	# The release line two before the latest one: the WC requires at least of the L-2 policy.
	wc_floor="$(printf '%s' "$json" | python3 -I -c 'import json, re, sys
d = json.load(sys.stdin)
lines = sorted({tuple(int(x) for x in v.split(".")[:2]) for v in d.get("versions", {}) if re.fullmatch(r"\d+\.\d+\.\d+", v)})
print("%d.%d" % lines[-3] if len(lines) >= 3 else "")')"
	wc_line="$(line_of "$wc")"

	tested="$(header "$README" 'Tested up to')"
	if [ "$tested" = "$(line_of "$wp")" ]; then ok "WordPress $wp: Tested up to $tested"; else warn "WordPress $wp: Tested up to is $tested ($README)"; fi
	tested="$(header "$WOO_README" 'Tested up to')"
	if [ "$tested" = "$(line_of "$wp")" ]; then ok "WordPress $wp: add-on Tested up to $tested"; else warn "WordPress $wp: add-on Tested up to is $tested ($WOO_README)"; fi

	local t1 t2 r1 r2 r3
	t1="$(header "$WOO_MAIN" 'WC tested up to')"
	t2="$(header "$WOO_README" 'WC tested up to')"
	r1="$(header "$WOO_MAIN" 'WC requires at least')"
	r2="$(header "$WOO_README" 'WC requires at least')"
	r3="$(constant "$WOO_MAIN" SAAI_WOO_MIN_WC_VERSION)"
	if [ "$t1" != "$t2" ]; then fail "add-on WC tested up to differs: plugin file '$t1', readme.txt '$t2'"; fi
	if [ "$r1" != "$r2" ] || [ "$r1" != "$r3" ]; then fail "add-on WC requires at least differs: plugin file '$r1', readme.txt '$r2', SAAI_WOO_MIN_WC_VERSION '$r3'"; fi
	if [ "$t1" = "$wc_line" ]; then ok "WooCommerce $wc: add-on WC tested up to $t1"; else warn "WooCommerce $wc: add-on WC tested up to is $t1"; fi
	if [ "$r1" = "$wc_floor" ]; then ok "WooCommerce L-2 is $wc_floor: add-on WC requires at least $r1"; else warn "WooCommerce L-2 is $wc_floor: add-on WC requires at least is $r1"; fi

	json="$(curl -fsS "${WPORG_API}$SLUG")" &&
		info "$SLUG on WordPress.org: $(printf '%s' "$json" | python3 -I -c 'import json, sys; d = json.load(sys.stdin); print(d["version"], "(last updated", d.get("last_updated", "?") + ")")')"

	if [ "$env" -eq 1 ]; then
		local e out core wcv
		for e in cli tests-cli; do
			# shellcheck disable=SC2016 # expanded inside the container
			out="$(npx wp-env run "$e" bash -c 'echo "core=$(wp core version)"; echo "wc=$(wp plugin get woocommerce --field=version)"' 2>/dev/null)"
			core="$(field "$out" core)"
			wcv="$(field "$out" wc)"
			if [ -z "$core" ]; then
				warn "wp-env $e did not answer (npx wp-env start --update)"
			elif [ "$core" = "$wp" ] && [ "$wcv" = "$wc" ]; then
				ok "wp-env $e: WordPress $core, WooCommerce $wcv"
			else
				warn "wp-env $e: WordPress $core, WooCommerce $wcv (npx wp-env start --update)"
			fi
		done
	fi
	finish
}

cmd_strings() {
	local prev="${1:-}" added removed
	if [ -z "$prev" ]; then
		prev="$(git describe --tags --abbrev=0 --match 'v*' 2>/dev/null)" || die "no v* tag found; pass one"
	fi
	git rev-parse -q --verify "$prev^{commit}" >/dev/null || die "unknown tag or commit: $prev"
	local calls='(^|[^A-Za-z0-9_])(__|_e|_x|_ex|_n|_nx|_n_noop|_nx_noop|esc_html__|esc_html_e|esc_html_x|esc_attr__|esc_attr_e|esc_attr_x)\(|"(title|description|keywords)"[[:space:]]*:'
	local diff
	diff="$(git diff -U0 "$prev" -- "$DIR/includes" "$DIR/src" "$DIR/templates" "$DIR/assets" "$MAIN" "$DIR/uninstall.php")"
	# Through ENVIRON, not -v: awk would read the backslashes in -v as escapes.
	added="$(printf '%s\n' "$diff" | RE="$calls" awk '
		/^\+\+\+ / { file = substr($0, 7); next }
		/^\+/ && $0 ~ ENVIRON["RE"] { sub(/^\+[ \t]*/, ""); print file ": " $0 }')"
	removed="$(printf '%s\n' "$diff" | grep -E '^-' | grep -vE '^---' | grep -cE "$calls")"
	if [ -n "$added" ]; then
		info "lines with translation calls added since $prev: $(printf '%s\n' "$added" | wc -l | tr -d ' ') (removed: $removed)"
		printf '%s\n' "$added" | head -30 | sed 's/^/      /'
		info "an added line can be a moved or reworded call; new strings show in English until translate.wordpress.org translates them"
	else
		ok "no lines with translation calls added since $prev (removed: $removed)"
	fi
	finish
}

cmd_zip() {
	local h c s entry missing
	LOG="$(mktemp)"
	trap 'rm -f "$LOG"' EXIT
	local log="$LOG"
	if ! npm run build --workspace="$DIR" >"$log" 2>&1; then
		tail -20 "$log"
		fail "npm run build failed"
		finish
	fi
	if ! (cd "$DIR" && rm -f "$SLUG.zip" && npx wp-scripts plugin-zip) >"$log" 2>&1; then
		tail -20 "$log"
		fail "wp-scripts plugin-zip failed"
		finish
	fi
	rm -rf "$EXTRACT"
	mkdir -p "$EXTRACT"
	if ! unzip -q "$DIR/$SLUG.zip" -d "$EXTRACT"; then
		die "could not extract $DIR/$SLUG.zip"
	fi
	ok "built $DIR/$SLUG.zip and extracted it to $EXTRACT/$SLUG"

	h="$(header "$EXTRACT/$SLUG/$SLUG.php" Version)"
	c="$(constant "$EXTRACT/$SLUG/$SLUG.php" SAAI_KNOWLEDGE_VERSION)"
	s="$(header "$EXTRACT/$SLUG/readme.txt" 'Stable tag')"
	if [ "$h" = "$c" ] && [ "$h" = "$s" ]; then ok "versions in the ZIP: $h"; else fail "versions in the ZIP differ: Version $h, SAAI_KNOWLEDGE_VERSION $c, Stable tag $s"; fi
	missing=""
	for entry in $(python3 -I -c 'import json, sys; print(" ".join(json.load(open(sys.argv[1]))["files"]))' "$DIR/package.json"); do
		[ -e "$EXTRACT/$SLUG/$entry" ] || missing="$missing $entry"
	done
	if [ -z "$missing" ]; then ok "every entry of package.json's files is in the ZIP"; else fail "missing from the ZIP:$missing"; fi
	for entry in src tests node_modules .wordpress-org; do
		[ -e "$EXTRACT/$SLUG/$entry" ] && fail "$entry/ is in the ZIP"
	done

	if ! npx wp-env run cli true >/dev/null 2>&1; then
		warn "wp-env is not running, so Plugin Check was skipped. After npx wp-env start, run:"
		echo "      npx wp-env run cli bash -c \"wp plugin check /var/www/html/saai-monorepo/$EXTRACT/$SLUG --slug=$SLUG --categories=$PC_CATEGORIES --include-experimental\""
		finish
	fi
	# Judge by the CSV's type column: wp plugin check exits 0 even when it
	# reports errors, and its messages follow the site language (ja on dev).
	if npx wp-env run cli bash -c "wp plugin check /var/www/html/saai-monorepo/$EXTRACT/$SLUG --slug=$SLUG --categories=$PC_CATEGORIES --include-experimental --format=csv" >"$log" 2>&1; then
		local errors warnings
		errors="$(grep -cE '^[0-9]+,[0-9]+,ERROR,' "$log")"
		warnings="$(grep -cE '^[0-9]+,[0-9]+,WARNING,' "$log")"
		grep -E '^FILE:|^[0-9]+,[0-9]+,(ERROR|WARNING),' "$log" | head -60 | sed 's/^/      /'
		if [ "$errors" -gt 0 ]; then
			fail "Plugin Check: $errors errors, $warnings warnings (above)"
		elif [ "$warnings" -gt 0 ]; then
			warn "Plugin Check: $warnings warnings (above): judge each one"
		else
			ok "Plugin Check ($PC_CATEGORIES, experimental included): no errors or warnings"
		fi
	else
		grep -vE '^(ℹ|✔)' "$log" | tail -20
		fail "Plugin Check did not run (above)"
	fi
	finish
}

cmd_verify() {
	local ver="${1:-}" code json v
	valid_version "$ver" || die "usage: verify <x.y.z>"
	TMP="$(mktemp -d)"
	trap 'rm -rf "$TMP"' EXIT
	local tmp="$TMP"

	if gh release download "v$ver" --pattern "$SLUG.zip" --dir "$tmp" >/dev/null 2>&1 &&
		unzip -q "$tmp/$SLUG.zip" -d "$tmp/zip" 2>/dev/null; then
		v="$(header "$tmp/zip/$SLUG/$SLUG.php" Version) $(constant "$tmp/zip/$SLUG/$SLUG.php" SAAI_KNOWLEDGE_VERSION) $(header "$tmp/zip/$SLUG/readme.txt" 'Stable tag')"
		if [ "$v" = "$ver $ver $ver" ]; then ok "GitHub Release v$ver has $SLUG.zip at $ver"; else fail "GitHub Release v$ver's $SLUG.zip has Version / constant / Stable tag '$v'"; fi
	else
		fail "GitHub Release v$ver has no $SLUG.zip"
	fi

	v="$(curl -fsS "$SVN/trunk/readme.txt" 2>/dev/null | tr -d '\r' | sed -n 's/^Stable tag:[[:space:]]*//p' | head -1 | sed 's/[[:space:]]*$//')"
	if [ "$v" = "$ver" ]; then ok "SVN trunk: Stable tag $v"; else fail "SVN trunk: Stable tag is '$v'"; fi
	v="$(curl -fsS "$SVN/tags/$ver/$SLUG.php" 2>/dev/null | sed -n 's/^[ *]*Version:[[:space:]]*//p' | head -1 | tr -d '\r' | sed 's/[[:space:]]*$//')"
	if [ "$v" = "$ver" ]; then ok "SVN tags/$ver: Version $v"; else fail "SVN tags/$ver: no $SLUG.php at $ver"; fi

	json="$(curl -fsS "${WPORG_API}$SLUG" 2>/dev/null)"
	v="$(printf '%s' "$json" | python3 -I -c 'import json, sys; d = json.load(sys.stdin); print(d["version"], d.get("last_updated", ""))' 2>/dev/null)"
	if [ "${v%% *}" = "$ver" ]; then ok "plugins API: $v"; else warn "plugins API still says '${v%% *}' (WordPress.org can take up to 24 hours)"; fi
	code="$(curl -sIL -o /dev/null -w '%{http_code}' "https://downloads.wordpress.org/plugin/$SLUG.$ver.zip")"
	if [ "$code" = 200 ]; then ok "download $SLUG.$ver.zip: 200"; else warn "download $SLUG.$ver.zip: $code (can lag like the API)"; fi
	finish
}

cmd="${1:-}"
[ $# -gt 0 ] && shift
case "$cmd" in
bump) cmd_bump "$@" ;;
check) cmd_check "$@" ;;
latest) cmd_latest "$@" ;;
strings) cmd_strings "$@" ;;
zip) cmd_zip "$@" ;;
verify) cmd_verify "$@" ;;
*)
	awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$0"
	exit 2
	;;
esac
