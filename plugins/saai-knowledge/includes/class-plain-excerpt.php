<?php
/**
 * Plain-text excerpts for glossary terms, trimmed by the script of the text
 * itself rather than the site language (Issue #56).
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the plain-text excerpt shared by the auto-link tooltip
 * (Autolinker::entry_excerpt()) and the DefinedTerm JSON-LD description
 * (Glossary_Term::description()).
 *
 * Deliberately not wp_trim_words(): core counts by the site language's word
 * count type, which a locale such as `ja` sets to characters. On such a
 * site an English definition was cut after 55 characters, mid-word; on an
 * English site a Japanese definition, having no spaces, was one "word" and
 * never shortened at all. That type can't be overridden for one call
 * either: WP_Locale::init() stores it once, and wp_get_word_count_type()
 * has no filter, so a gettext filter added at call time changes nothing.
 */
final class Plain_Excerpt {

	/**
	 * How many units (see UNIT_PATTERN) a trimmed body keeps — core's
	 * default excerpt length.
	 *
	 * @var int
	 */
	public const WORDS = 55;

	/**
	 * Characters of scripts written without spaces between words — Han,
	 * kana, Thai, Lao, Khmer, Myanmar — plus Japanese/Chinese punctuation,
	 * the prolonged sound mark, and fullwidth/halfwidth forms. Each one
	 * counts as one unit, as core's character count does. Hangul is not
	 * here: Korean puts spaces between words. The ideographic space
	 * (U+3000) is whitespace, not a unit.
	 *
	 * The punctuation ranges are spelled out rather than left to the bare
	 * script properties: PCRE matches those against Script_Extensions in
	 * some versions and Script in others (see Autolinker::char_class()), so
	 * "。" would count as a unit or not depending on the server.
	 *
	 * @var string
	 */
	private const NO_SPACE_CHARACTERS = '\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}\x{3001}-\x{303F}\x{30FC}\x{FF01}-\x{FFEF}';

	/**
	 * One unit: a single NO_SPACE_CHARACTERS character, or a run of any
	 * other non-whitespace characters (a word). "WordPressのプラグイン" is
	 * seven units — "WordPress", then one per kana — so a Latin word inside
	 * Japanese text is counted, and kept, whole.
	 *
	 * @var string
	 */
	private const UNIT_PATTERN = '/[' . self::NO_SPACE_CHARACTERS . ']|[^\s' . self::NO_SPACE_CHARACTERS . ']+/u';

	/**
	 * A term's excerpt as plain text: its manual excerpt if set, otherwise
	 * the first WORDS units of its body.
	 *
	 * Tags are stripped before character references are decoded, never the
	 * other way round: decoding first would turn text such as `&lt;br&gt;`
	 * written in the definition into a tag, which stripping then removes.
	 *
	 * @param \WP_Post $post The glossary term.
	 * @return string
	 */
	public static function for_post( \WP_Post $post ): string {
		$text = has_excerpt( $post ) ? get_the_excerpt( $post ) : self::trim_words( $post->post_content );

		return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Strips tags and keeps the first $num_words units (see UNIT_PATTERN),
	 * appending `&hellip;` when anything was cut.
	 *
	 * Whitespace is handled as wp_trim_words() does: each run of ASCII
	 * whitespace becomes one space, while any other space, such as the
	 * ideographic one, stays as written. Any of them separates units, and
	 * none is kept at either end. The text is cut only between units, so
	 * neither a word nor a character reference such as `&amp;` is ever
	 * split. Unlike wp_trim_words(), the result doesn't go through core's
	 * `wp_trim_words` filter: a site customizing its post excerpts there
	 * doesn't change these.
	 *
	 * @param string $text      Text to trim; may contain HTML.
	 * @param int    $num_words Number of units to keep.
	 * @return string May contain character references, as $text did.
	 */
	public static function trim_words( string $text, int $num_words = self::WORDS ): string {
		$plain = preg_replace( '/[\n\r\t ]+/', ' ', wp_strip_all_tags( $text ) );

		// UNIT_PATTERN fails on invalid UTF-8, which core's own trim still
		// returns something readable for.
		if ( ! is_string( $plain ) || false === preg_match_all( self::UNIT_PATTERN, $plain, $matches, PREG_OFFSET_CAPTURE ) ) {
			return wp_trim_words( $text, $num_words );
		}

		$units = $matches[0];

		if ( ! $units ) {
			return '';
		}

		$kept  = array_slice( $units, 0, max( 0, $num_words ) );
		$start = $units[0][1];
		$last  = end( $kept );
		$end   = false === $last ? $start : $last[1] + strlen( $last[0] );

		return substr( $plain, $start, $end - $start ) . ( count( $units ) > count( $kept ) ? '&hellip;' : '' );
	}
}
