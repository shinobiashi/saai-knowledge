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
			return trim( wp_strip_all_tags( $html ) );
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
			return trim( wp_strip_all_tags( $html ) );
		}

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );

		if ( ! $body instanceof \DOMElement ) {
			return trim( wp_strip_all_tags( $html ) );
		}

		$markdown = self::convert_children( $body, 0 );

		// Collapse the blank-line runs that naturally accumulate from
		// block-level elements each emitting their own trailing "\n\n".
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $markdown ) ) . "\n";
	}

	/**
	 * Converts every child of a node, in document order.
	 *
	 * @param \DOMNode $node       Parent node.
	 * @param int      $list_depth Current nested-list indentation depth.
	 * @return string
	 */
	private static function convert_children( \DOMNode $node, int $list_depth ): string {
		$out = '';

		foreach ( $node->childNodes as $child ) {
			$out .= self::convert_node( $child, $list_depth );
		}

		return $out;
	}

	/**
	 * Converts one node to its Markdown representation.
	 *
	 * @param \DOMNode $node       Node to convert.
	 * @param int      $list_depth Current nested-list indentation depth.
	 * @return string
	 */
	private static function convert_node( \DOMNode $node, int $list_depth ): string {
		if ( $node instanceof \DOMText ) {
			return (string) preg_replace( '/\s+/', ' ', $node->wholeText );
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
				$text = trim( self::convert_children( $node, $list_depth ) );
				return '' === $text ? '' : $text . "\n\n";

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = (int) substr( $tag, 1 );
				return str_repeat( '#', $level ) . ' ' . trim( self::convert_children( $node, $list_depth ) ) . "\n\n";

			case 'strong':
			case 'b':
				$text = trim( self::convert_children( $node, $list_depth ) );
				return '' === $text ? '' : '**' . $text . '**';

			case 'em':
			case 'i':
				$text = trim( self::convert_children( $node, $list_depth ) );
				return '' === $text ? '' : '_' . $text . '_';

			case 'code':
				// A <code> inside <pre> is handled entirely by the 'pre' case
				// below (as a fenced block); only a standalone inline <code>
				// gets backticks here.
				if ( $node->parentNode instanceof \DOMElement && 'pre' === strtolower( $node->parentNode->tagName ) ) {
					return self::convert_children( $node, $list_depth );
				}
				return '`' . trim( $node->textContent ) . '`';

			case 'pre':
				return "```\n" . trim( $node->textContent ) . "\n```\n\n";

			case 'a':
				$href = trim( (string) $node->getAttribute( 'href' ) );
				$text = trim( self::convert_children( $node, $list_depth ) );

				if ( '' === $text ) {
					return '';
				}

				return '' === $href ? $text : '[' . $text . '](' . $href . ')';

			case 'img':
				$src = trim( (string) $node->getAttribute( 'src' ) );

				if ( '' === $src ) {
					return '';
				}

				$alt = trim( (string) $node->getAttribute( 'alt' ) );
				return '![' . $alt . '](' . $src . ")\n\n";

			case 'blockquote':
				$inner = trim( self::convert_children( $node, $list_depth ) );

				if ( '' === $inner ) {
					return '';
				}

				$quoted = implode(
					"\n",
					array_map(
						static function ( $line ) {
							return '> ' . $line;
						},
						explode( "\n", $inner )
					)
				);
				return $quoted . "\n\n";

			case 'ul':
			case 'ol':
				return self::convert_list( $node, $tag, $list_depth ) . "\n";

			case 'table':
				return self::convert_table( $node ) . "\n";

			default:
				return self::convert_children( $node, $list_depth );
		}
	}

	/**
	 * Converts a <ul>/<ol>, recursing into nested lists so they render
	 * indented beneath their parent item.
	 *
	 * @param \DOMElement $list_node The <ul> or <ol> element.
	 * @param string      $tag       'ul' or 'ol'.
	 * @param int         $depth     Current indentation depth.
	 * @return string
	 */
	private static function convert_list( \DOMElement $list_node, string $tag, int $depth ): string {
		$out    = '';
		$index  = 1;
		$indent = str_repeat( '  ', $depth );

		foreach ( $list_node->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement || 'li' !== strtolower( $child->tagName ) ) {
				continue;
			}

			$marker = 'ol' === $tag ? $index . '.' : '-';
			$nested = '';
			$text   = '';

			foreach ( $child->childNodes as $li_child ) {
				if ( $li_child instanceof \DOMElement && in_array( strtolower( $li_child->tagName ), array( 'ul', 'ol' ), true ) ) {
					$nested .= self::convert_list( $li_child, strtolower( $li_child->tagName ), $depth + 1 );
				} else {
					$text .= self::convert_node( $li_child, $depth );
				}
			}

			$line = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

			if ( '' !== $line || '' !== $nested ) {
				$out .= $indent . $marker . ' ' . $line . "\n";
			}

			$out .= $nested;
			++$index;
		}

		return $out;
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
					$cells[] = self::escape_table_cell( trim( self::convert_children( $cell, 0 ) ) );
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
