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
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	private static function fallback_plain_text( string $html ): string {
		$with_breaks = (string) preg_replace( '#</(?:p|div|h[1-6]|li|blockquote|pre|tr|table|ul|ol)>#i', "$0\n\n", $html );
		$with_breaks = (string) preg_replace( '#<br\s*/?>#i', "\n", $with_breaks );

		$plain = wp_strip_all_tags( $with_breaks );

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
			$out .= self::convert_node( $child, $indent );
		}

		return $out;
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

				return '' === $href ? $text : '[' . $text . '](' . $href . ')';

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
				return '![' . $alt . '](' . $src . ")\n\n";

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
	 * @param string $text Plain text.
	 * @return string
	 */
	public static function escape_text( string $text ): string {
		return str_replace(
			array( '\\', '`', '*', '_', '[', ']' ),
			array( '\\\\', '\\`', '\\*', '\\_', '\\[', '\\]' ),
			$text
		);
	}

	/**
	 * Escapes a leading block-syntax marker at the very start of an
	 * assembled line — a heading `#`, blockquote `>`, list `-`/`+`, or
	 * ordered-list `1.`/`1)` — so literal text that happens to start with
	 * one (e.g. a paragraph that is itself just the sentence "- reminder:
	 * ...") isn't misread as that construct once emitted. escape_text()
	 * can't do this itself: it runs per DOMText node, with no notion of
	 * whether its text ends up at the start of the final rendered line —
	 * this instead runs once on each fully assembled paragraph/list-item/
	 * blockquote line, in convert_node()/convert_list().
	 *
	 * Asterisk/underscore-based markers aren't handled here because
	 * escape_text() already escapes every literal `*`/`_`, unconditionally
	 * and everywhere, which already prevents them from being read as a
	 * list marker or emphasis.
	 *
	 * @param string $line One fully assembled line of output.
	 * @return string
	 */
	private static function escape_line_start( string $line ): string {
		return (string) preg_replace( '/^(#{1,6}(?=\s|$)|>|[-+](?=\s|$)|\d+[.)](?=\s|$))/', '\\\\$1', $line );
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
