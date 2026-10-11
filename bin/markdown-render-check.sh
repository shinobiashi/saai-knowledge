#!/usr/bin/env bash
#
# Render Markdown with several Markdown libraries and report, for each one,
# whether the page it produces contains HTML elements Markdown itself never
# generates — i.e. whether text in the Markdown reached the page as markup.
#
#   bin/markdown-render-check.sh [options] <file.md | -> ...
#   bin/markdown-render-check.sh --from-html [options] <file.html | -> ...
#
# Meant for this plugin's Markdown output (Markdown_Converter: ?format=markdown,
# the llms.txt index, the RAG export's content_markdown). Rendering with one
# CommonMark library isn't enough: Python-Markdown reads ``` blocks only with
# the fenced_code extension, and even then only a fence (or an ATX heading)
# that starts at column 0, so Markdown that CommonMark renders safely can
# still put a code sample's <script> on the page there (Issue #81).
#
# Renderers, each with raw HTML allowed, as a consumer rendering this Markdown
# with HTML enabled would:
#   commonmark   commonmark.js, the CommonMark reference implementation
#   markdown-it  markdown-it (CommonMark + GFM tables), common in chat UIs
#   pymd         Python-Markdown without extensions
#   pymd-fenced  Python-Markdown with fenced_code
#   pymd-extra   Python-Markdown with extra
# The JavaScript renderers run through `npx -y` at pinned versions and
# Python-Markdown from a venv cached outside the repository
# (${XDG_CACHE_HOME:-~/.cache}/saai-knowledge/); none of them is a project
# dependency. The first run needs network access.
#
# Options:
#   --from-html          The inputs are HTML (as the_content outputs it):
#                        convert each one with Markdown_Converter::convert()
#                        in the wp-env development environment (which must be
#                        running) before rendering.
#   --renderers LIST     Comma-separated subset of the renderers above.
#   --expect-text TEXT   The rendered page must show TEXT as text (compared
#                        with whitespace runs collapsed), e.g. '<script>' or
#                        '&copy;'. Repeatable.
#   --expect-html REGEX  The rendered HTML must match REGEX (a Python regular
#                        expression), e.g. '<h2>'. Repeatable.
#   --show               Also print the Markdown and each renderer's HTML.
#
# Exit status: 0 every renderer passed for every input; 1 at least one did
# not (raw HTML on the page, or an unmet expectation); 2 usage or setup error.
set -euo pipefail

COMMONMARK_VERSION=0.31.2
MARKDOWN_IT_VERSION=14.1.0
PYTHON_MARKDOWN_VERSION=3.11
ALL_RENDERERS=commonmark,markdown-it,pymd,pymd-fenced,pymd-extra

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

usage() {
	awk 'NR > 2 && /^set -euo pipefail/ { exit } NR > 2 { sub(/^# ?/, ""); print }' "$0"
}

die() {
	echo "markdown-render-check: $1" >&2
	exit 2
}

from_html=0
renderers=$ALL_RENDERERS
show=0
# Pairs of (kind, value), kept in command-line order. Bash 3.2 (macOS) treats
# "${empty[@]}" as unbound under `set -u`, hence the ${a[@]+"${a[@]}"} form.
expectations=()
inputs=()

while [ $# -gt 0 ]; do
	case "$1" in
		--from-html) from_html=1 ;;
		--renderers | --expect-text | --expect-html)
			[ $# -ge 2 ] || die "$1 needs a value"
			case "$1" in
				--renderers) renderers=$2 ;;
				--expect-text) expectations+=(text "$2") ;;
				--expect-html) expectations+=(html "$2") ;;
			esac
			shift
			;;
		--show) show=1 ;;
		-h | --help)
			usage
			exit 0
			;;
		-) inputs+=(-) ;;
		-*) die "unknown option: $1 (see --help)" ;;
		*) inputs+=("$1") ;;
	esac
	shift
done

[ ${#inputs[@]} -gt 0 ] || die "no input given (see --help)"

selected=()
needs_python_markdown=0
IFS=, read -r -a requested <<<"$renderers"
for renderer in ${requested[@]+"${requested[@]}"}; do
	case ",$ALL_RENDERERS," in
		*",$renderer,"*) ;;
		*) die "unknown renderer: $renderer (one of $ALL_RENDERERS)" ;;
	esac
	selected+=("$renderer")
	case "$renderer" in
		pymd*) needs_python_markdown=1 ;;
	esac
done
[ ${#selected[@]} -gt 0 ] || die "--renderers is empty"

command -v python3 >/dev/null || die "python3 not found"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/markdown-render-check.XXXXXX")"
# Keep the failing status: without the explicit exit, the trap's own rm
# would become the script's exit status.
# shellcheck disable=SC2329 # invoked by the trap below
on_exit() {
	local status=$?
	rm -rf "$WORK"
	exit "$status"
}
trap on_exit EXIT

# Copy every input first: the renderers run from inside $WORK (so npx doesn't
# resolve against the monorepo's own node_modules), where relative paths and
# stdin no longer point at the caller's files.
names=()
index=0
for input in "${inputs[@]}"; do
	index=$((index + 1))
	if [ "$input" = - ]; then
		cat >"$WORK/$index.src"
		names+=(stdin)
	else
		[ -f "$input" ] || die "$input: no such file"
		cp "$input" "$WORK/$index.src"
		names+=("$input")
	fi
done

PYTHON=python3
if [ "$needs_python_markdown" = 1 ]; then
	venv="${XDG_CACHE_HOME:-$HOME/.cache}/saai-knowledge/markdown-render-check/python-markdown-$PYTHON_MARKDOWN_VERSION"
	if ! "$venv/bin/python" -I -c 'import markdown' >/dev/null 2>&1; then
		echo "==> installing Python-Markdown $PYTHON_MARKDOWN_VERSION into $venv" >&2
		rm -rf "$venv"
		python3 -m venv "$venv" >&2 || die "could not create $venv"
		"$venv/bin/python" -I -m pip install --quiet --disable-pip-version-check "Markdown==$PYTHON_MARKDOWN_VERSION" >&2 ||
			die "could not install Python-Markdown $PYTHON_MARKDOWN_VERSION"
	fi
	PYTHON="$venv/bin/python"
fi

# Prints Markdown_Converter::convert() of the HTML file $1 into $2. The HTML
# travels base64-encoded inside the eval'd PHP, so no quoting can break out.
to_markdown() {
	local encoded
	encoded="$(base64 <"$1" | tr -d '\n')"
	if ! (cd "$ROOT" && npx wp-env run cli wp eval "echo \\SAAI\\Knowledge\\Markdown_Converter::convert( base64_decode( '$encoded' ) );") >"$2" 2>"$WORK/wp-env.err"; then
		tail -5 "$WORK/wp-env.err" >&2
		die "Markdown_Converter::convert() failed in wp-env — is it running (npx wp-env start)?"
	fi
}

# Renders the Markdown file $2 with renderer $1 into the HTML file $3.
render() {
	if ! case "$1" in
		commonmark) (cd "$WORK" && npx -y "commonmark@$COMMONMARK_VERSION" "$2") ;;
		markdown-it) (cd "$WORK" && npx -y "markdown-it@$MARKDOWN_IT_VERSION" "$2") ;;
		pymd) "$PYTHON" -I -X utf8 -m markdown "$2" ;;
		pymd-fenced) "$PYTHON" -I -X utf8 -m markdown -x fenced_code "$2" ;;
		pymd-extra) "$PYTHON" -I -X utf8 -m markdown -x extra "$2" ;;
	esac >"$3" 2>"$WORK/render.err"; then
		tail -5 "$WORK/render.err" >&2
		die "$1 failed to render"
	fi
}

# Prints "ok", or what is wrong with the HTML file argv[1], and exits 1 in
# that case. The remaining arguments are the (kind, value) expectations.
# An allowed tag is one some renderer above generates from Markdown syntax;
# anything else (<script>, <b>, <div>, ...) came from raw HTML in the input.
ANALYZE='
import html.parser, re, sys

ALLOWED = {
    "a", "blockquote", "br", "code", "del", "em", "h1", "h2", "h3", "h4",
    "h5", "h6", "hr", "img", "li", "ol", "p", "pre", "s", "strong",
    "table", "tbody", "td", "th", "thead", "tr", "ul",
}

class Page(html.parser.HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.tags = set()
        self.text = []

    def handle_starttag(self, tag, attrs):
        self.tags.add(tag)

    def handle_data(self, data):
        self.text.append(data)

def squash(text):
    return re.sub(r"\s+", " ", text).strip()

source = open(sys.argv[1], encoding="utf-8").read()
page = Page()
page.feed(source)
page.close()
shown = squash("".join(page.text))

problems = []
raw = sorted(page.tags - ALLOWED)
if raw:
    problems.append("raw HTML " + " ".join("<%s>" % tag for tag in raw))
args = sys.argv[2:]
for kind, value in zip(args[0::2], args[1::2]):
    if kind == "text" and squash(value) not in shown:
        problems.append("not shown as text: %r" % value)
    if kind == "html" and not re.search(value, source):
        problems.append("HTML does not match %r" % value)

print("FAIL  " + "; ".join(problems) if problems else "ok")
sys.exit(1 if problems else 0)
'

# Prints a file, ending it with a newline if it lacks one (Python-Markdown's
# output does), so the next line doesn't run on.
print_file() {
	cat "$1"
	[ -z "$(tail -c 1 "$1")" ] || echo
}

failed=0
for index in $(seq 1 ${#inputs[@]}); do
	name="${names[$((index - 1))]}"
	markdown="$WORK/$index.md"

	if [ "$from_html" = 1 ]; then
		to_markdown "$WORK/$index.src" "$markdown"
		echo "== $name (converted by Markdown_Converter)"
	else
		cp "$WORK/$index.src" "$markdown"
		echo "== $name"
	fi

	if [ "$show" = 1 ]; then
		echo "-- Markdown"
		print_file "$markdown"
	fi

	for renderer in "${selected[@]}"; do
		out="$WORK/$index.$renderer.html"
		render "$renderer" "$markdown" "$out"

		if [ "$show" = 1 ]; then
			echo "-- $renderer"
			print_file "$out"
		fi

		if ! result="$(python3 -I -X utf8 -c "$ANALYZE" "$out" ${expectations[@]+"${expectations[@]}"})"; then
			# A crash prints nothing on stdout, unlike a reported failure.
			[ -n "$result" ] || die "could not analyze the $renderer output"
			failed=1
		fi
		printf '  %-12s %s\n' "$renderer" "$result"
	done
done

exit "$failed"
