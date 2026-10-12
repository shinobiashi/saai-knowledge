<?php
/**
 * Tests for Plain_Excerpt (Issue #56): glossary excerpts are trimmed by the
 * script of the text, not the site language's word count type.
 *
 * @package SAAI\Knowledge
 */

use SAAI\Knowledge\Plain_Excerpt;

/**
 * Class Test_Plain_Excerpt.
 */
class Test_Plain_Excerpt extends WP_UnitTestCase {

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
	private function words( int $count ): array {
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
		$long = implode( ' ', $this->words( 60 ) );

		self::with_word_count_type(
			'characters_including_spaces',
			function () use ( $long ) {
				// The setup reproduces the bug: core cuts the sentence.
				$this->assertSame( 'A second verification step, in addition to your passwor&hellip;', wp_trim_words( self::ISSUE_SENTENCE, 55 ) );

				$this->assertSame( self::ISSUE_SENTENCE, Plain_Excerpt::trim_words( self::ISSUE_SENTENCE ) );
				$this->assertSame( implode( ' ', $this->words( 55 ) ) . '…', Plain_Excerpt::trim_words( $long ) );
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

				$this->assertSame( str_repeat( $eleven, 5 ) . '…', Plain_Excerpt::trim_words( $text ) );
			}
		);
	}

	/**
	 * A Latin word inside Japanese text is one unit, kept whole — core's
	 * character count cut it letter by letter.
	 */
	public function test_a_latin_word_inside_cjk_text_counts_as_one_unit() {
		$text = str_repeat( 'あ', 54 ) . 'WordPressのプラグイン';

		$this->assertSame( str_repeat( 'あ', 54 ) . 'WordPress…', Plain_Excerpt::trim_words( $text ) );
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
		$this->assertSame( 'One two three', Plain_Excerpt::trim_words( "<p>One  two</p>\n\n<p>three</p>" ) );
		$this->assertSame( 'あ　い', Plain_Excerpt::trim_words( '　あ　い　' ) );
		$this->assertSame( 'あ…', Plain_Excerpt::trim_words( '　あ　い　', 1 ) );
		$this->assertSame( '', Plain_Excerpt::trim_words( "<p> \n </p>" ) );
	}

	/**
	 * Units are counted in the decoded text: a space written as `&nbsp;`
	 * separates words, and a character reference for a kana counts as that
	 * kana. Counted before decoding, each of these was one unit, never cut
	 * (Codex, PR #91). The result is plain text, so `&amp;` comes back as
	 * "&", and `&amp;lt;` as the text "&lt;", not decoded a second time.
	 */
	public function test_units_are_counted_after_character_references_are_decoded() {
		$this->assertSame( "w1\u{00A0}w2…", Plain_Excerpt::trim_words( 'w1&nbsp;w2&nbsp;w3', 2 ) );
		$this->assertSame( 'ああ…', Plain_Excerpt::trim_words( '&#x3042;&#x3042;&#x3042;', 2 ) );
		$this->assertSame( 'Fish & &lt;chips&gt;', Plain_Excerpt::trim_words( 'Fish &amp; &amp;lt;chips&amp;gt;' ) );
	}

	/**
	 * Invalid UTF-8 fails the unit pattern, so core's trim takes over, as it
	 * did before Plain_Excerpt existed, and its result is made plain text too.
	 */
	public function test_invalid_utf8_falls_back_to_core() {
		$text = "<b>bad</b> \xff &amp; text";

		$this->assertSame( html_entity_decode( wp_strip_all_tags( wp_trim_words( $text, 55 ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ), Plain_Excerpt::trim_words( $text ) );
		$this->assertStringNotContainsString( '<b>', Plain_Excerpt::trim_words( $text ) );
	}

	/**
	 * For_post() prefers the manual excerpt, trims the body otherwise, and
	 * returns plain text either way: tags stripped, then character
	 * references decoded.
	 */
	public function test_for_post_returns_the_plain_excerpt_or_the_trimmed_body() {
		$with_excerpt = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'saai_glossary',
				'post_excerpt' => 'Manual <b>excerpt</b> &amp; more.',
				'post_content' => 'Body that should not be used.',
			)
		);
		$without      = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'saai_glossary',
				'post_excerpt' => '',
				'post_content' => '<p>Fish &amp; chips &lt;br&gt; ' . implode( ' ', $this->words( 60 ) ) . '</p>',
			)
		);

		$this->assertSame( 'Manual excerpt & more.', Plain_Excerpt::for_post( $with_excerpt ) );
		$this->assertSame( 'Fish & chips <br> ' . implode( ' ', $this->words( 51 ) ) . '…', Plain_Excerpt::for_post( $without ) );
	}
}
