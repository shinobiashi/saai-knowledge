<?php
/**
 * Tests for the add-on's Plain_Excerpt (Issue #89): glossary definitions are
 * trimmed by the script of the text, not the site language's word count
 * type, exactly as the free plugin trims its tooltips.
 *
 * @package SAAI\KnowledgeWoo
 */

use SAAI\KnowledgeWoo\Plain_Excerpt;
use SAAI\KnowledgeWoo\Product_Sections;

/**
 * Class Test_Woo_Plain_Excerpt.
 */
class Test_Woo_Plain_Excerpt extends WP_UnitTestCase {

	/**
	 * The sentence Issue #56 reported cut to "…your passwor…" on a `ja`
	 * site: 17 words, 101 characters.
	 *
	 * @var string
	 */
	private const ISSUE_SENTENCE = 'A second verification step, in addition to your password, that confirms it is really you signing in.';

	/**
	 * Runs $callback with the site language's word count type set to $type,
	 * as a locale such as `ja` (characters_including_spaces) sets it.
	 * WP_Locale::init() stores the type once, so the property is the only
	 * place a test can change it — the tests site has no `ja` language pack.
	 *
	 * @param string   $type     'words', 'characters_including_spaces' or 'characters_excluding_spaces'.
	 * @param callable $callback Code to run.
	 * @return mixed What $callback returns.
	 */
	private static function with_word_count_type( string $type, callable $callback ) {
		global $wp_locale;

		$previous                   = $wp_locale->word_count_type;
		$wp_locale->word_count_type = $type;

		try {
			return $callback();
		} finally {
			$wp_locale->word_count_type = $previous;
		}
	}

	/**
	 * Numbered words, "w1 w2 … w$count".
	 *
	 * @param int $count How many words.
	 * @return string[]
	 */
	private static function words( int $count ): array {
		return array_map(
			static function ( int $i ): string {
				return 'w' . $i;
			},
			range( 1, $count )
		);
	}

	/**
	 * Where the site language counts characters, core cuts English after 55
	 * characters, mid-word; Plain_Excerpt keeps 55 whole words.
	 */
	public function test_latin_text_keeps_whole_words_when_the_locale_counts_characters() {
		$long = implode( ' ', self::words( 60 ) );

		self::with_word_count_type(
			'characters_including_spaces',
			function () use ( $long ) {
				// The setup reproduces the bug: core cuts the sentence.
				$this->assertSame( 'A second verification step, in addition to your passwor&hellip;', wp_trim_words( self::ISSUE_SENTENCE, 55 ) );

				$this->assertSame( self::ISSUE_SENTENCE, Plain_Excerpt::trim_words( self::ISSUE_SENTENCE, 55 ) );
				$this->assertSame( implode( ' ', self::words( 55 ) ) . '…', Plain_Excerpt::trim_words( $long, 55 ) );
			}
		);
	}

	/**
	 * Where the site language counts words, core never shortens Japanese
	 * (no spaces, so one "word"); Plain_Excerpt keeps 55 characters.
	 */
	public function test_cjk_text_is_cut_at_characters_when_the_locale_counts_words() {
		$eleven = 'あいうえお漢字カタカナ';
		$text   = str_repeat( $eleven, 6 );

		self::with_word_count_type(
			'words',
			function () use ( $eleven, $text ) {
				$this->assertSame( $text, wp_trim_words( $text, 55 ) );

				$this->assertSame( str_repeat( $eleven, 5 ) . '…', Plain_Excerpt::trim_words( $text, 55 ) );
			}
		);
	}

	/**
	 * A Latin word inside Japanese text is one unit, kept whole — core's
	 * character count cut it letter by letter.
	 */
	public function test_a_latin_word_inside_cjk_text_counts_as_one_unit() {
		$text = str_repeat( 'あ', 54 ) . 'WordPressのプラグイン';

		$this->assertSame( str_repeat( 'あ', 54 ) . 'WordPress…', Plain_Excerpt::trim_words( $text, 55 ) );
	}

	/**
	 * Japanese punctuation and fullwidth forms count one character each,
	 * whether or not the server's PCRE reads them as part of a script.
	 */
	public function test_japanese_punctuation_and_fullwidth_forms_count_one_character_each() {
		$this->assertSame( 'これは、ＡＢ…', Plain_Excerpt::trim_words( 'これは、ＡＢＣ。', 6 ) );
		$this->assertSame( 'サーバー（TLS）…', Plain_Excerpt::trim_words( 'サーバー（TLS）で暗号化', 7 ) );
	}

	/**
	 * Tags are stripped and ASCII whitespace collapses as in core; spaces
	 * separate units but never count, and none is kept at either end. The
	 * ideographic space stays as written.
	 */
	public function test_whitespace_separates_units_without_counting() {
		$this->assertSame( 'One two three', Plain_Excerpt::trim_words( "<p>One  two</p>\n\n<p>three</p>", 55 ) );
		$this->assertSame( 'あ　い', Plain_Excerpt::trim_words( '　あ　い　', 55 ) );
		$this->assertSame( 'あ…', Plain_Excerpt::trim_words( '　あ　い　', 1 ) );
		$this->assertSame( '', Plain_Excerpt::trim_words( "<p> \n </p>", 55 ) );
	}

	/**
	 * Units are counted in the decoded text: a space written as `&nbsp;`
	 * separates words, and a character reference for a kana counts as that
	 * kana. The result is plain text, so `&amp;` comes back as "&", and
	 * `&amp;lt;` as the text "&lt;", not decoded a second time.
	 */
	public function test_units_are_counted_after_character_references_are_decoded() {
		$this->assertSame( "w1\u{00A0}w2…", Plain_Excerpt::trim_words( 'w1&nbsp;w2&nbsp;w3', 2 ) );
		$this->assertSame( 'ああ…', Plain_Excerpt::trim_words( '&#x3042;&#x3042;&#x3042;', 2 ) );
		$this->assertSame( 'Fish & &lt;chips&gt;', Plain_Excerpt::trim_words( 'Fish &amp; &amp;lt;chips&amp;gt;', 55 ) );
	}

	/**
	 * Invalid UTF-8 fails the unit pattern, so core's trim takes over, as in
	 * the free plugin, and its result is made plain text too.
	 */
	public function test_invalid_utf8_falls_back_to_core() {
		$text = "<b>bad</b> \xff &amp; text";

		$this->assertSame( html_entity_decode( wp_strip_all_tags( wp_trim_words( $text, 55 ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ), Plain_Excerpt::trim_words( $text, 55 ) );
		$this->assertStringNotContainsString( '<b>', Plain_Excerpt::trim_words( $text, 55 ) );
	}

	/**
	 * The tokens of a method's body, without whitespace and comments, so
	 * two copies compare equal however they are indented or commented.
	 *
	 * @param string $class_name Class name.
	 * @param string $method     Method name.
	 * @return string[]
	 */
	private static function body_tokens( string $class_name, string $method ): array {
		$reflection = new ReflectionMethod( $class_name, $method );
		$lines      = file( (string) $reflection->getFileName() );
		$source     = implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
		$tokens     = array();

		foreach ( token_get_all( '<?php ' . $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$text = is_array( $token ) ? $token[1] : $token;

			// The body starts at the first brace; the signatures differ (the
			// free plugin's $num_words has a default).
			if ( array() !== $tokens || '{' === $text ) {
				$tokens[] = $text;
			}
		}

		return $tokens;
	}

	/**
	 * The add-on's copy is the free plugin's Plain_Excerpt, which the
	 * tooltip uses: the same constants, and the same code in trim_words()
	 * and plain_text(), and a product page's definition keeps as many units
	 * as the tooltip does. The add-on may not call that class (it depends only
	 * on the free plugin's public hooks), so this is what keeps the two from
	 * drifting apart: change one, and this fails until the other matches.
	 * Comparing the code catches what comparing results can't, such as a
	 * script added to the pattern that this server's PCRE already matches
	 * through another script property.
	 */
	public function test_copies_the_free_plugin_code() {
		$this->assertTrue( class_exists( \SAAI\Knowledge\Plain_Excerpt::class ), 'The tests load the free plugin.' );

		// The tooltip takes the free plugin's default length; the product
		// page passes its own.
		$this->assertSame( \SAAI\Knowledge\Plain_Excerpt::WORDS, Product_Sections::DEFINITION_WORDS, 'Length' );

		foreach ( array( 'NO_SPACE_CHARACTERS', 'UNIT_PATTERN' ) as $constant ) {
			$this->assertSame(
				( new ReflectionClassConstant( \SAAI\Knowledge\Plain_Excerpt::class, $constant ) )->getValue(),
				( new ReflectionClassConstant( Plain_Excerpt::class, $constant ) )->getValue(),
				$constant
			);
		}

		foreach ( array( 'trim_words', 'plain_text' ) as $method ) {
			$tokens = self::body_tokens( Plain_Excerpt::class, $method );

			$this->assertNotEmpty( $tokens, $method );
			$this->assertSame( self::body_tokens( \SAAI\Knowledge\Plain_Excerpt::class, $method ), $tokens, $method );
		}
	}

	/**
	 * The add-on's copy trims exactly as the free plugin's Plain_Excerpt
	 * under either word count type, on this server's PCRE.
	 */
	public function test_trims_exactly_as_the_free_plugin_does() {
		$this->assertTrue( class_exists( \SAAI\Knowledge\Plain_Excerpt::class ), 'The tests load the free plugin.' );

		$texts = array(
			self::ISSUE_SENTENCE,
			implode( ' ', self::words( 60 ) ),
			str_repeat( 'あいうえお漢字カタカナ', 6 ),
			str_repeat( 'あ', 54 ) . 'WordPressのプラグイン',
			'これは、ＡＢＣ。',
			'サーバー（TLS）で暗号化',
			"<p>One  two</p>\n\n<p>three</p>",
			'　あ　い　',
			"<p> \n </p>",
			'',
			'w1&nbsp;w2&nbsp;w3',
			'&#x3042;&#x3042;&#x3042;',
			'Fish &amp; &amp;lt;chips&amp;gt; &lt;br&gt;',
			'<!-- wp:paragraph --><p>WordPress の<strong>プラグイン</strong>を有効化します。</p><!-- /wp:paragraph -->',
			'สวัสดีครับ ภาษาไทย 한국어 문장 Ελληνικά',
			'한국어 문장 그리고 English words',
			'ສະບາຍດີ ພາສາລາວ',
			'សួស្តី ភាសាខ្មែរ',
			'မင်္ဂလာပါ မြန်မာစာ',
			"It&apos;s a\fb\fc",
			"<b>bad</b> \xff &amp; text",
		);

		foreach ( array( 'words', 'characters_including_spaces' ) as $type ) {
			self::with_word_count_type(
				$type,
				function () use ( $texts, $type ) {
					foreach ( $texts as $text ) {
						foreach ( array( 0, 1, 2, 6, 7, 55 ) as $num_words ) {
							$this->assertSame(
								\SAAI\Knowledge\Plain_Excerpt::trim_words( $text, $num_words ),
								Plain_Excerpt::trim_words( $text, $num_words ),
								sprintf( '%s, %d units, %s', $type, $num_words, wp_json_encode( $text, JSON_INVALID_UTF8_SUBSTITUTE ) )
							);
						}
					}
				}
			);
		}
	}
}
