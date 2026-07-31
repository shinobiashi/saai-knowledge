<?php
/**
 * Tests for the Normalizer service (width folding + kana folding).
 *
 * @package SAAI\Knowledge
 */

/**
 * Class Test_Normalizer.
 */
class Test_Normalizer extends WP_UnitTestCase {

	/**
	 * Fullwidth Latin letters, digits, and the fullwidth space should fold
	 * to their halfwidth ASCII form.
	 */
	public function test_normalize_folds_fullwidth_ascii_to_halfwidth() {
		$this->assertSame( 'Apple', \SAAI\Knowledge\Normalizer::normalize( 'Ａｐｐｌｅ' ) );
		$this->assertSame( '0123456789', \SAAI\Knowledge\Normalizer::normalize( '０１２３４５６７８９' ) );
		$this->assertSame( 'A B', \SAAI\Knowledge\Normalizer::normalize( 'Ａ　Ｂ' ) );
	}

	/**
	 * Halfwidth katakana (including a dakuten/handakuten sequence) should
	 * fold to fullwidth katakana.
	 */
	public function test_normalize_folds_halfwidth_katakana_to_fullwidth() {
		$this->assertSame( 'カ', \SAAI\Knowledge\Normalizer::normalize( 'ｶ' ) );
		$this->assertSame( 'ガ', \SAAI\Knowledge\Normalizer::normalize( 'ｶﾞ' ) );
		$this->assertSame( 'パ', \SAAI\Knowledge\Normalizer::normalize( 'ﾊﾟ' ) );
	}

	/**
	 * Normalize() should be idempotent and leave kanji/plain ASCII untouched.
	 */
	public function test_normalize_is_idempotent_and_leaves_unrelated_text_alone() {
		$normalized = \SAAI\Knowledge\Normalizer::normalize( 'クーポン' );

		$this->assertSame( $normalized, \SAAI\Knowledge\Normalizer::normalize( $normalized ) );
		$this->assertSame( '決済', \SAAI\Knowledge\Normalizer::normalize( '決済' ) );
		$this->assertSame( 'apple', \SAAI\Knowledge\Normalizer::normalize( 'apple' ) );
	}

	/**
	 * Normalize_fallback() is directly testable since the extensions it
	 * substitutes for can't be unloaded at test run time.
	 */
	public function test_normalize_fallback_folds_fullwidth_ascii_only() {
		$this->assertSame( 'Apple', \SAAI\Knowledge\Normalizer::normalize_fallback( 'Ａｐｐｌｅ' ) );
		$this->assertSame( '0123456789', \SAAI\Knowledge\Normalizer::normalize_fallback( '０１２３４５６７８９' ) );
		$this->assertSame( 'A B', \SAAI\Knowledge\Normalizer::normalize_fallback( 'Ａ　Ｂ' ) );
		// Halfwidth katakana is out of scope for the fallback; left as-is.
		$this->assertSame( 'ｶﾞｲﾄﾞ', \SAAI\Knowledge\Normalizer::normalize_fallback( 'ｶﾞｲﾄﾞ' ) );
	}

	/**
	 * Fold_kana() should map every katakana variant (plain, voiced,
	 * semi-voiced, small, archaic) and small/archaic hiragana to base
	 * hiragana, while leaving ん/を/ー untouched.
	 */
	public function test_fold_kana_maps_katakana_variants_to_base_hiragana() {
		$this->assertSame( 'か', \SAAI\Knowledge\Normalizer::fold_kana( 'カ' ) );
		$this->assertSame( 'か', \SAAI\Knowledge\Normalizer::fold_kana( 'ガ' ) );
		$this->assertSame( 'は', \SAAI\Knowledge\Normalizer::fold_kana( 'パ' ) );
		$this->assertSame( 'あ', \SAAI\Knowledge\Normalizer::fold_kana( 'ァ' ) );
		$this->assertSame( 'つ', \SAAI\Knowledge\Normalizer::fold_kana( 'ッ' ) );
		$this->assertSame( 'や', \SAAI\Knowledge\Normalizer::fold_kana( 'ャ' ) );
		$this->assertSame( 'う', \SAAI\Knowledge\Normalizer::fold_kana( 'ヴ' ) );
		$this->assertSame( 'い', \SAAI\Knowledge\Normalizer::fold_kana( 'ヰ' ) );
		$this->assertSame( 'か', \SAAI\Knowledge\Normalizer::fold_kana( 'ヵ' ) );
		$this->assertSame( 'け', \SAAI\Knowledge\Normalizer::fold_kana( 'ヶ' ) );
		$this->assertSame( 'を', \SAAI\Knowledge\Normalizer::fold_kana( 'ヲ' ) );
		$this->assertSame( 'ん', \SAAI\Knowledge\Normalizer::fold_kana( 'ン' ) );
		$this->assertSame( 'ー', \SAAI\Knowledge\Normalizer::fold_kana( 'ー' ) );
	}

	/**
	 * Fold_kana() should also fold small/voiced hiragana variants.
	 */
	public function test_fold_kana_maps_hiragana_variants_to_base_row() {
		$this->assertSame( 'あ', \SAAI\Knowledge\Normalizer::fold_kana( 'ぁ' ) );
		$this->assertSame( 'か', \SAAI\Knowledge\Normalizer::fold_kana( 'が' ) );
		$this->assertSame( 'は', \SAAI\Knowledge\Normalizer::fold_kana( 'ぱ' ) );
		$this->assertSame( 'い', \SAAI\Knowledge\Normalizer::fold_kana( 'ゐ' ) );
	}

	/**
	 * Combined pipeline: normalize() then fold_kana() should treat a
	 * halfwidth voiced katakana sequence identically to its fullwidth
	 * hiragana equivalent.
	 */
	public function test_normalize_then_fold_kana_unifies_halfwidth_and_fullwidth_forms() {
		$halfwidth = \SAAI\Knowledge\Normalizer::fold_kana( \SAAI\Knowledge\Normalizer::normalize( 'ｶﾞｲﾄﾞ' ) );
		$fullwidth = \SAAI\Knowledge\Normalizer::fold_kana( \SAAI\Knowledge\Normalizer::normalize( 'ガイド' ) );

		$this->assertSame( $fullwidth, $halfwidth );
		$this->assertSame( 'かいと', $fullwidth );
	}
}
