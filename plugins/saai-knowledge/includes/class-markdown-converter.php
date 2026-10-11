<?php
/**
 * Converts rendered block HTML into plain Markdown for the AI-readability
 * features (docs/DESIGN.md section 7.3: ?format=markdown; section 7.4: the
 * planned RAG export's content_markdown field reuses this too).
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * A small, dependency-free HTML-to-Markdown converter scoped to the tags
 * core blocks (and typical hand-written HTML) actually produce — headings,
 * paragraphs, lists, links, emphasis, images, tables, blockquotes, and code.
 * It is not a general-purpose CommonMark serializer: uncommon markup simply
 * falls through to its text content (see the `default` case in
 * convert_node()).
 *
 * DOMDocument is a near-universal PHP extension, but not a guaranteed one;
 * when it's missing (or given HTML it can't parse), convert() degrades to a
 * plain-text stripped result rather than fataling — the same fallback
 * philosophy DESIGN.md documents for Normalizer's mbstring/intl handling.
 */
final class Markdown_Converter {

	/**
	 * Elements whose Markdown starts on a line of its own: the block
	 * elements convert_node() emits as Markdown blocks, and the containers
	 * that hold them. Used by convert_children() to start each one on a new
	 * block and by is_line_start_whitespace(). Elements not listed (inline
	 * ones such as <span>, or unknown ones) are treated as inline.
	 *
	 * @var string[]
	 */
	private const BLOCK_ELEMENTS = array(
		'address',
		'article',
		'aside',
		'blockquote',
		'body',
		'dd',
		'details',
		'div',
		'dl',
		'dt',
		'fieldset',
		'figcaption',
		'figure',
		'footer',
		'form',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'header',
		'hgroup',
		'hr',
		'li',
		'main',
		'nav',
		'ol',
		'p',
		'pre',
		'section',
		'summary',
		'table',
		'ul',
	);

	/**
	 * Converts an HTML fragment (as produced by `the_content`) to Markdown.
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	public static function convert( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		if ( ! class_exists( '\DOMDocument' ) ) {
			return self::fallback_plain_text( $html );
		}

		$dom             = new \DOMDocument();
		$previous_errors = libxml_use_internal_errors( true );

		$loaded = $dom->loadHTML(
			'<?xml encoding="UTF-8"?><!DOCTYPE html><html><body>' . $html . '</body></html>',
			LIBXML_NOERROR | LIBXML_NOWARNING
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );

		if ( ! $loaded ) {
			return self::fallback_plain_text( $html );
		}

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );

		if ( ! $body instanceof \DOMElement ) {
			return self::fallback_plain_text( $html );
		}

		$markdown = self::convert_children( $body, '' );

		// Collapse the blank-line runs that naturally accumulate from
		// block-level elements each emitting their own trailing "\n\n".
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $markdown ) ) . "\n";
	}

	/**
	 * The no-DOMDocument (or unparsable-HTML) fallback: strips tags without
	 * losing block-boundary separation. A bare wp_strip_all_tags() call
	 * concatenates adjacent block elements with nothing between them (e.g.
	 * `<p>First</p><p>Second</p>` becomes "FirstSecond"), so a newline is
	 * inserted after each common block-level closing tag (and for `<br>`)
	 * before stripping.
	 *
	 * HTML character references (`&amp;`, `&#8217;`, ...) are deliberately
	 * left as they are, unlike to_plain_text(): this is convert()'s output,
	 * i.e. Markdown, and CommonMark already reads a character reference in
	 * text as the character it stands for — `AT&amp;T` is correct Markdown
	 * for "AT&T". Decoding here would instead turn an article's literal
	 * `&lt;tag&gt;` text into raw HTML inside the Markdown, which a consumer
	 * rendering that Markdown to HTML would treat as markup.
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	private static function fallback_plain_text( string $html ): string {
		return self::strip_with_block_breaks( $html );
	}

	/**
	 * Converts an HTML fragment (as produced by `the_content`) to
	 * block-separated plain text — no Markdown syntax at all, unlike
	 * convert(). Used for the RAG export's `content_plain` field
	 * (docs/DESIGN.md section 7.4), which is meant for full-text
	 * search/embedding rather than display, so Markdown's own punctuation
	 * would just be noise there.
	 *
	 * Reuses the exact same block-boundary/`<br>` handling as
	 * fallback_plain_text() (the DOMDocument-based convert() path has no
	 * equivalent need for it, since it walks the tree structurally instead
	 * of relying on regexing tag boundaries in a flat string) — both wrap
	 * strip_with_block_breaks().
	 *
	 * Unlike fallback_plain_text(), it also decodes HTML character
	 * references: `the_content` output is full of them (wptexturize alone
	 * turns every `'`/`"` into `&#8217;`/`&#8220;`...), and in text that is
	 * not Markdown nothing downstream would ever decode `AT&amp;T` back to
	 * "AT&T" for search/embedding.
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	public static function to_plain_text( string $html ): string {
		return self::strip_with_block_breaks( $html, true );
	}

	/**
	 * Shared implementation for fallback_plain_text() and to_plain_text():
	 * strips tags without losing block-boundary separation. A bare
	 * wp_strip_all_tags() call concatenates adjacent block elements with
	 * nothing between them (e.g. `<p>First</p><p>Second</p>` becomes
	 * "FirstSecond"), so a newline is inserted after each common block-level
	 * closing tag (and for `<br>`) before stripping. `<td>`/`<th>` get a
	 * space rather than a newline: they are not block-level breaks on their
	 * own (only their containing `<tr>` is), but without *some* separator a
	 * core/table row like `<tr><td>Name</td><td>$9.99</td></tr>` would still
	 * collapse into the single run-on word "Name$9.99" once stripped — two
	 * genuinely distinct cell values corrupted into one for full-text
	 * search/embedding purposes (Codex review).
	 *
	 * Character references are decoded only after the tags are stripped:
	 * decoding first would turn an article's literal `&lt;br&gt;` text into
	 * a real tag, which the break insertion and stripping would then act on.
	 *
	 * @param string $html            Rendered HTML.
	 * @param bool   $decode_entities Whether to decode HTML character references.
	 * @return string
	 */
	private static function strip_with_block_breaks( string $html, bool $decode_entities = false ): string {
		$with_breaks = (string) preg_replace( '#</(?:p|div|h[1-6]|li|blockquote|pre|tr|table|ul|ol)>#i', "$0\n\n", $html );
		$with_breaks = (string) preg_replace( '#</(?:td|th)>#i', '$0 ', $with_breaks );
		$with_breaks = (string) preg_replace( '#<br\s*/?>#i', "\n", $with_breaks );

		$plain = wp_strip_all_tags( $with_breaks );

		if ( $decode_entities ) {
			$plain = html_entity_decode( $plain, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
		}

		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $plain ) );
	}

	/**
	 * Converts every child of a node, in document order.
	 *
	 * @param \DOMNode $node   Parent node.
	 * @param string   $indent Current nested-list indentation prefix (see convert_list()).
	 * @return string
	 */
	private static function convert_children( \DOMNode $node, string $indent ): string {
		$out = '';

		foreach ( $node->childNodes as $child ) {
			if ( self::is_line_start_whitespace( $child, $node, $out ) ) {
				continue;
			}

			if ( self::starts_new_block( $child, $node ) ) {
				$out = self::end_block( $out );
			}

			$markdown = self::convert_node( $child, $indent );

			// Inline content that starts a line can start Markdown syntax
			// there, which only the <p>/<li>/<blockquote> branches escaped:
			// text directly in a <div> after inline content became
			// "prefix\n\n# literal" once end_block() separated them — a
			// heading (Codex review G2-1) — and the same text wrapped in a
			// <span> after a block did too (G3-1).
			if ( self::is_inline_content( $child ) && self::at_line_start( $node, $out ) ) {
				$markdown = self::escape_line_start( ltrim( $markdown, ' ' ) );
			}

			$out .= $markdown;
		}

		return $out;
	}

	/**
	 * Whether a node is inline content whose output escape_line_start()
	 * applies to when it starts a line: text, or an element that isn't a
	 * block. A <br> is left alone — its output is the "  " that marks the
	 * hard break, which ltrim() would remove.
	 *
	 * @param \DOMNode $node Node to check.
	 * @return bool
	 */
	private static function is_inline_content( \DOMNode $node ): bool {
		if ( $node instanceof \DOMText ) {
			return true;
		}

		return $node instanceof \DOMElement && ! self::is_block_element( $node ) && 'br' !== strtolower( $node->tagName );
	}

	/**
	 * Whether the next output of a parent lands at the start of a Markdown
	 * line: its output so far ends with a newline, or it has none yet and
	 * is a block element (so its own output starts a line).
	 *
	 * Judged from the output rather than the previous sibling, so a node
	 * that outputs nothing (a comment, <script>) in between doesn't hide the
	 * line start.
	 *
	 * @param \DOMNode $container The parent.
	 * @param string   $out       Its output so far.
	 * @return bool
	 */
	private static function at_line_start( \DOMNode $container, string $out ): bool {
		if ( '' === $out ) {
			return self::is_block_element( $container );
		}

		return "\n" === substr( $out, -1 );
	}

	/**
	 * Whether a node is one of BLOCK_ELEMENTS.
	 *
	 * @param \DOMNode $node Node to check.
	 * @return bool
	 */
	private static function is_block_element( \DOMNode $node ): bool {
		return $node instanceof \DOMElement && in_array( strtolower( $node->tagName ), self::BLOCK_ELEMENTS, true );
	}

	/**
	 * Whether a child must start a new Markdown block (see end_block()).
	 *
	 * Any element that is or holds a code block does — an inline one too:
	 * otherwise `prefix<ins><div><pre>…` (an inline wrapper between the text
	 * and the block, where the inner convert_children() call has no output
	 * of its own to end) still produced "prefix```" (Codex review G1-2).
	 * The fence must start a line however that leaves a link or emphasis
	 * around it.
	 *
	 * Any other block element does when its parent is a block too, but not
	 * inside an inline element (<a>, <strong>, <span>, ...), where a blank
	 * line would split the Markdown construct around it: a Custom HTML card
	 * `<a href="…"><div>Title</div><div>Desc</div></a>` would become
	 * "[Title", a new paragraph, "Desc](…)" (review R2-1). Those blocks run
	 * on as before.
	 *
	 * @param \DOMNode $child     Child about to be converted.
	 * @param \DOMNode $container Its parent.
	 * @return bool
	 */
	private static function starts_new_block( \DOMNode $child, \DOMNode $container ): bool {
		if ( ! $child instanceof \DOMElement ) {
			return false;
		}

		if ( self::is_block_element( $child ) && self::is_block_element( $container ) ) {
			return true;
		}

		return 'pre' === strtolower( $child->tagName ) || $child->getElementsByTagName( 'pre' )->length > 0;
	}

	/**
	 * Ends the output so far with a blank line, so the block element about
	 * to be appended starts a new Markdown block. Block elements only end
	 * their own output with one; inline content right before one (a stray
	 * <span> or text next to a <pre> in a Custom HTML block, a linked image
	 * before its <figcaption>) would otherwise run into it, e.g. "stray ```"
	 * — a fence that doesn't start its line, so every renderer, CommonMark
	 * included, reads the code sample after it as Markdown and its
	 * `<script>` as a raw element (Issue #81).
	 *
	 * @param string $out Output so far.
	 * @return string
	 */
	private static function end_block( string $out ): string {
		$out = rtrim( $out, ' ' );

		if ( '' === $out || "\n\n" === substr( $out, -2 ) ) {
			return $out;
		}

		return $out . ( "\n" === substr( $out, -1 ) ? "\n" : "\n\n" );
	}

	/**
	 * Whether a child is a whitespace-only text node whose output would land
	 * at the start of a Markdown line — the newlines `the_content` puts
	 * between blocks (e.g. "<p>…</p>\n\n<pre>…</pre>", or the first and
	 * last child of a core/group <div>), which convert_node() would turn
	 * into a ' ' prefixed to the next block's first line.
	 *
	 * CommonMark allows up to three spaces of indentation there, but
	 * Python-Markdown — even with its fenced_code/extra extension — only
	 * recognizes a fence or an ATX heading at column 0: an indented fence
	 * leaves the code to be parsed as Markdown, so a code sample's
	 * `<script>` renders as a raw element there (Issue #81). Such
	 * whitespace isn't displayed in HTML either, so it's dropped. Whitespace
	 * in the middle of a line (`<em>a</em> <em>b</em>`) is kept.
	 *
	 * @param \DOMNode $child     Child about to be converted.
	 * @param \DOMNode $container Its parent.
	 * @param string   $out       The parent's output so far.
	 * @return bool
	 */
	private static function is_line_start_whitespace( \DOMNode $child, \DOMNode $container, string $out ): bool {
		return $child instanceof \DOMText
			&& '' === trim( $child->wholeText, " \t\n\r\f" )
			&& self::at_line_start( $container, $out );
	}

	/**
	 * Converts one node to its Markdown representation.
	 *
	 * @param \DOMNode $node   Node to convert.
	 * @param string   $indent Current nested-list indentation prefix (see convert_list()).
	 * @return string
	 */
	private static function convert_node( \DOMNode $node, string $indent ): string {
		if ( $node instanceof \DOMText ) {
			return self::escape_text( (string) preg_replace( '/\s+/', ' ', $node->wholeText ) );
		}

		if ( ! $node instanceof \DOMElement ) {
			return '';
		}

		$tag = strtolower( $node->tagName );

		switch ( $tag ) {
			case 'script':
			case 'style':
				return '';

			case 'br':
				return "  \n";

			case 'hr':
				return "\n---\n\n";

			case 'p':
			case 'figcaption':
				$text = self::escape_line_start( trim( self::convert_children( $node, $indent ) ) );
				return '' === $text ? '' : $text . "\n\n";

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = (int) substr( $tag, 1 );
				return str_repeat( '#', $level ) . ' ' . trim( self::convert_children( $node, $indent ) ) . "\n\n";

			case 'strong':
			case 'b':
				$text = trim( self::convert_children( $node, $indent ) );
				return '' === $text ? '' : '**' . $text . '**';

			case 'em':
			case 'i':
				$text = trim( self::convert_children( $node, $indent ) );
				return '' === $text ? '' : '_' . $text . '_';

			case 'code':
				// A <code> inside <pre> is handled entirely by the 'pre' case
				// below (as a fenced block); only a standalone inline <code>
				// gets backticks here.
				if ( $node->parentNode instanceof \DOMElement && 'pre' === strtolower( $node->parentNode->tagName ) ) {
					return self::convert_children( $node, $indent );
				}
				return self::render_inline_code( $node->textContent );

			case 'pre':
				return self::render_code_fence( $node->textContent ) . "\n\n";

			case 'summary':
				// core/details' <summary> has no separator of its own before
				// the InnerBlocks content that follows it; without one,
				// "<summary>What?</summary><p>Answer.</p>" concatenates into
				// "What?Answer." with the question/answer boundary lost.
				$text = trim( self::convert_children( $node, $indent ) );
				return '' === $text ? '' : '**' . $text . '**' . "\n\n";

			case 'a':
				$href = trim( (string) $node->getAttribute( 'href' ) );
				$text = trim( self::convert_children( $node, $indent ) );

				if ( '' === $text ) {
					return '';
				}

				return '' === $href ? $text : '[' . $text . '](' . self::markdown_link_destination( $href ) . ')';

			case 'img':
				$src = trim( (string) $node->getAttribute( 'src' ) );

				if ( '' === $src ) {
					return '';
				}

				// alt is an arbitrary editable string, same as any DOMText
				// run — without escaping, an alt like "x](/other) [y" would
				// close this image's label early and start a second,
				// unintended image/link (Codex review).
				$alt = self::escape_text( trim( (string) $node->getAttribute( 'alt' ) ) );
				return '![' . $alt . '](' . self::markdown_link_destination( $src ) . ")\n\n";

			case 'blockquote':
				$inner = trim( self::convert_children( $node, $indent ) );

				if ( '' === $inner ) {
					return '';
				}

				$quoted = implode(
					"\n",
					array_map(
						static function ( $line ) {
							return '> ' . self::escape_line_start( $line );
						},
						explode( "\n", $inner )
					)
				);
				return $quoted . "\n\n";

			case 'ul':
			case 'ol':
				return self::convert_list( $node, $tag, $indent ) . "\n";

			case 'table':
				return self::convert_table( $node ) . "\n";

			default:
				return self::convert_children( $node, $indent );
		}
	}

	/**
	 * Escapes characters in a plain-text run that would otherwise be
	 * (mis)read as Markdown syntax once this text sits next to the syntax
	 * this converter itself emits — e.g. literal text containing
	 * `[literal](not-a-link)` must not become an actual link. Only applied
	 * to DOMText nodes: text that this converter itself wraps in syntax
	 * (inline `<code>`/`<pre>` content, link/image targets) bypasses this
	 * text-node branch entirely and is emitted as-is.
	 *
	 * Public: Markdown_Output and Llms_Index reuse this same escaping for
	 * post titles they place inside their own Markdown (an H1 heading and a
	 * `[title](url)` link label, respectively) — the same class of
	 * "arbitrary editable string next to syntax this plugin emits" problem,
	 * just outside an HTML document this class is parsing.
	 *
	 * The text is plain text, i.e. already decoded (a DOMText value, or a
	 * title/term name passed through html_entity_decode()): a literal `<`,
	 * `>` or `&` here is a character the article actually contains, so it
	 * must not reach the Markdown as raw HTML or a character reference
	 * (Issue #79). Callers must not pass HTML-escaped text — `&amp;` would
	 * come out as the literal text "&amp;".
	 *
	 * `<`/`>` become `&lt;`/`&gt;`, and `&` becomes `&amp;` only where it
	 * starts a character reference (`&name;`, `&#123;`, `&#x1F;`), leaving
	 * the far more common "Q&A"/"AT&T" readable. Character references
	 * rather than backslash escapes: `\<`/`\&` are escapes only in
	 * CommonMark — Markdown.pl, PHP Markdown and Python-Markdown leave
	 * `\<script>` a raw <script> element — while every Markdown dialect
	 * renders a character reference as its character. `<` is escaped
	 * unconditionally because what follows it can't be checked per text
	 * node: `&lt;<em></em>script&gt;` is the two runs "<" and "script>",
	 * which only become `<script>` once concatenated. A reference split
	 * across two text nodes (`&<span>copy;</span>`) is missed, but a
	 * reference only ever renders as a character, never as markup.
	 *
	 * `{`/`}` are backslash-escaped too: Python-Markdown's attr_list (part
	 * of its extra extension) reads a `{: ... }` at the end of a heading or
	 * right after an inline element as that element's attributes, so a
	 * literal `{: onclick=alert(1) }` became an event handler (Issue #82).
	 * Unlike `\<`, `\{` is an escape in every dialect: CommonMark escapes
	 * any ASCII punctuation, and Markdown.pl, PHP Markdown and
	 * Python-Markdown all list `{`/`}` among their escapable characters.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	public static function escape_text( string $text ): string {
		$escaped = str_replace(
			array( '\\', '`', '*', '_', '[', ']', '{', '}' ),
			array( '\\\\', '\\`', '\\*', '\\_', '\\[', '\\]', '\\{', '\\}' ),
			$text
		);

		// Before `<`/`>` are replaced, so the `&` of the `&lt;`/`&gt;` added
		// below isn't itself escaped.
		$escaped = (string) preg_replace( '/&(?=[A-Za-z][A-Za-z0-9]*;|#[0-9]{1,7};|#[xX][0-9a-fA-F]{1,6};)/', '&amp;', $escaped );

		return str_replace( array( '<', '>' ), array( '&lt;', '&gt;' ), $escaped );
	}

	/**
	 * Wraps a link/image destination in CommonMark's `<...>` angle-bracket
	 * form instead of emitting it bare inside `(...)`. A bare destination
	 * containing an unbalanced `)` — a real possibility in an arbitrary
	 * `href`/`src` — closes the Markdown link/image early, corrupting it;
	 * the angle-bracket form tolerates parentheses freely and only needs
	 * its own literal `<`/`>` escaped (Copilot review).
	 *
	 * Public: Llms_Index reuses this for the `url`/`markdown_url` values a
	 * `saai_llms_index_items` callback supplies — a public filter can't be
	 * trusted to hand back a value free of the same characters (Copilot
	 * review).
	 *
	 * @param string $url The href/src attribute value.
	 * @return string
	 */
	public static function markdown_link_destination( string $url ): string {
		return '<' . str_replace( array( '\\', '<', '>' ), array( '\\\\', '\\<', '\\>' ), $url ) . '>';
	}

	/**
	 * Escapes a leading block-syntax marker at the very start of an
	 * assembled line — a heading `#`, blockquote `>`, list `-`/`+`, thematic
	 * break `---` (a `-` followed by another), code fence `~~~`, or
	 * ordered-list `1.`/`1)` — so literal text that happens to start with
	 * one (e.g. a paragraph that is itself just the sentence "- reminder:
	 * ...") isn't misread as that construct once emitted. escape_text()
	 * can't do this itself: it runs per DOMText node, with no notion of
	 * whether its text ends up at the start of the final rendered line —
	 * this instead runs once on each fully assembled paragraph/list-item/
	 * blockquote line, in convert_node()/convert_list(), and on the inline
	 * content convert_children() places at the start of a line (text or an
	 * inline element directly in a <div>, or after a block or a <br>).
	 *
	 * Asterisk/underscore-based markers aren't handled here because
	 * escape_text() already escapes every literal `*`/`_`, unconditionally
	 * and everywhere, which already prevents them from being read as a
	 * list marker or emphasis.
	 *
	 * A tilde fence `~~~` gets its first `~` as a character reference
	 * instead: Python-Markdown doesn't treat `\~` as an escape. Text that
	 * opened a fence would otherwise take the converter's own code block
	 * that follows into it, and a `~~~` line in that code sample would
	 * close it and put the rest of the sample — its `<script>` — outside
	 * any fence (Codex review G3-2). Backtick fences need nothing here:
	 * escape_text() already escapes every literal backtick.
	 *
	 * An ordered-list marker gets its `.`/`)` escaped ("1\. "), not the
	 * digits: a backslash only escapes punctuation, so "\1. " showed the
	 * backslash — now also on the "1. Install" lines after a <br> that
	 * convert_children() escapes (Codex review G2-1).
	 *
	 * @param string $line One fully assembled line of output.
	 * @return string
	 */
	private static function escape_line_start( string $line ): string {
		$line = (string) preg_replace( '/^(#{1,6}(?=\s|$)|>|\+(?=\s|$)|-(?=[\s-]|$))/', '\\\\$1', $line );
		$line = (string) preg_replace( '/^~(?=~~)/', '&#126;', $line );

		return (string) preg_replace( '/^(\d+)([.)])(?=\s|$)/', '$1\\\\$2', $line );
	}

	/**
	 * Builds a fenced code block whose fence is longer than the longest run
	 * of backticks already present in the code — a fixed ` ``` ` fence
	 * would otherwise be closed early by a code sample that itself contains
	 * a triple-backtick sequence (e.g. an article about Markdown syntax
	 * demonstrating a fenced code block), silently turning the remainder of
	 * that code into ordinary Markdown and starting a stray new block at
	 * the original closing fence.
	 *
	 * @param string $code Raw <pre> text content.
	 * @return string
	 */
	private static function render_code_fence( string $code ): string {
		$code = trim( $code, "\n" );

		$fence = str_repeat( '`', max( 3, self::longest_backtick_run( $code ) + 1 ) );

		return $fence . "\n" . $code . "\n" . $fence;
	}

	/**
	 * Wraps inline code in a backtick delimiter longer than the longest run
	 * of backticks already present in it — a fixed single backtick would
	 * otherwise be closed early by code containing its own backtick (e.g.
	 * `<code>a`b</code>`, which a fixed `` `a`b` `` delimiter would split at
	 * the first one), the same class of problem render_code_fence() solves
	 * for fenced blocks. Per CommonMark, code that starts or ends with a
	 * backtick additionally needs a padding space on that side so the
	 * delimiter itself isn't misread as touching the code's own backtick.
	 *
	 * @param string $code Raw inline <code> text content.
	 * @return string
	 */
	private static function render_inline_code( string $code ): string {
		$code = trim( $code );

		$delimiter = str_repeat( '`', max( 1, self::longest_backtick_run( $code ) + 1 ) );
		$padding   = ( '' !== $code && ( '`' === $code[0] || '`' === substr( $code, -1 ) ) ) ? ' ' : '';

		return $delimiter . $padding . $code . $padding . $delimiter;
	}

	/**
	 * The length of the longest run of consecutive backticks in a string.
	 *
	 * @param string $text Text to scan.
	 * @return int
	 */
	private static function longest_backtick_run( string $text ): int {
		$longest_run = 0;

		if ( preg_match_all( '/`+/', $text, $matches ) ) {
			foreach ( $matches[0] as $run ) {
				$longest_run = max( $longest_run, strlen( $run ) );
			}
		}

		return $longest_run;
	}

	/**
	 * Converts a <ul>/<ol>, recursing into nested lists so they render
	 * indented beneath their parent item.
	 *
	 * A nested list is indented to align with the first character *after*
	 * its parent item's own marker and separating space (`$indent` already
	 * carries that from the caller) — not a flat 2 spaces per level. Per
	 * CommonMark, an ordered list's marker width varies ("1. " is 3
	 * columns, "10. " is 4), so a flat indent under-indents a nested list
	 * enough that some parsers read it as a new top-level list instead of
	 * a child of the item above it.
	 *
	 * @param \DOMElement $list_node The <ul> or <ol> element.
	 * @param string      $tag       'ul' or 'ol'.
	 * @param string      $indent    This list's own indentation prefix (empty at the top level).
	 * @return string
	 */
	private static function convert_list( \DOMElement $list_node, string $tag, string $indent ): string {
		$out   = '';
		$index = self::list_start( $list_node, $tag );

		foreach ( $list_node->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement || 'li' !== strtolower( $child->tagName ) ) {
				continue;
			}

			$marker       = 'ol' === $tag ? $index . '.' : '-';
			$child_indent = $indent . str_repeat( ' ', strlen( $marker ) + 1 );
			$nested       = '';
			$text         = '';

			foreach ( $child->childNodes as $li_child ) {
				if ( $li_child instanceof \DOMElement && in_array( strtolower( $li_child->tagName ), array( 'ul', 'ol' ), true ) ) {
					$nested .= self::convert_list( $li_child, strtolower( $li_child->tagName ), $child_indent );
				} else {
					$text .= self::convert_node( $li_child, $child_indent );
				}
			}

			$line = self::escape_line_start( trim( (string) preg_replace( '/\s+/', ' ', $text ) ) );

			if ( '' !== $line || '' !== $nested ) {
				$out .= $indent . $marker . ' ' . $line . "\n";
			}

			$out .= $nested;
			++$index;
		}

		return $out;
	}

	/**
	 * Resolves an <ol>'s starting number from its `start` attribute
	 * (defaulting to 1, same as the browser/HTML default, and for <ul>,
	 * which has no such attribute).
	 *
	 * @param \DOMElement $list_node The <ul> or <ol> element.
	 * @param string      $tag       'ul' or 'ol'.
	 * @return int
	 */
	private static function list_start( \DOMElement $list_node, string $tag ): int {
		if ( 'ol' !== $tag || ! $list_node->hasAttribute( 'start' ) ) {
			return 1;
		}

		$start = filter_var( $list_node->getAttribute( 'start' ), FILTER_VALIDATE_INT );

		return false !== $start ? $start : 1;
	}

	/**
	 * Converts a <table> to a Markdown pipe table using its first row as the
	 * header (Markdown tables have no concept of a bodyless header, so a
	 * <table> without any rows simply produces no output).
	 *
	 * @param \DOMElement $table The <table> element.
	 * @return string
	 */
	private static function convert_table( \DOMElement $table ): string {
		$rows = array();

		foreach ( $table->getElementsByTagName( 'tr' ) as $row ) {
			$cells = array();

			foreach ( $row->childNodes as $cell ) {
				if ( $cell instanceof \DOMElement && in_array( strtolower( $cell->tagName ), array( 'td', 'th' ), true ) ) {
					$cells[] = self::escape_table_cell( trim( self::convert_children( $cell, '' ) ) );
				}
			}

			if ( $cells ) {
				$rows[] = $cells;
			}
		}

		if ( ! $rows ) {
			return '';
		}

		$header = array_shift( $rows );
		$out    = '| ' . implode( ' | ', $header ) . " |\n";
		$out   .= '| ' . implode( ' | ', array_fill( 0, count( $header ), '---' ) ) . " |\n";

		foreach ( $rows as $row ) {
			$out .= '| ' . implode( ' | ', $row ) . " |\n";
		}

		return $out;
	}

	/**
	 * Escapes characters that would otherwise break a Markdown table cell's
	 * `|`-delimited structure.
	 *
	 * @param string $text Cell text.
	 * @return string
	 */
	private static function escape_table_cell( string $text ): string {
		return str_replace( array( "\n", '|' ), array( ' ', '\\|' ), $text );
	}
}
