<?php
/**
 * Tests for the Glossary_Index service backing the glossary-index block.
 *
 * @package SAAI\Knowledge
 */

use SAAI\Knowledge\Glossary_Index;
use SAAI\Knowledge\Post_Meta;

/**
 * Class Test_Glossary_Index.
 */
class Test_Glossary_Index extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Glossary_Index
	 */
	private $index;

	/**
	 * Sets up the service under test.
	 */
	public function set_up() {
		parent::set_up();

		$this->index = new Glossary_Index();
	}

	/**
	 * Creates a published glossary entry.
	 *
	 * @param string $title   Term title.
	 * @param string $reading Optional saai_reading meta value.
	 * @return int Post ID.
	 */
	private function create_term( string $title, string $reading = '' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'saai_glossary',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		if ( '' !== $reading ) {
			update_post_meta( $post_id, Post_Meta::READING, $reading );
		}

		return $post_id;
	}

	/**
	 * Reading() should use the saai_reading meta when set.
	 */
	public function test_reading_uses_meta_when_set() {
		$post_id = $this->create_term( 'クーポン', 'くーぽん' );

		$this->assertSame( 'くーぽん', $this->index->reading( get_post( $post_id ) ) );
	}

	/**
	 * Reading() should fall back to remove_accents(title) when the meta is
	 * unset or blank (docs/DESIGN.md section 3.3).
	 */
	public function test_reading_falls_back_to_remove_accents_of_title() {
		$post_id = $this->create_term( 'Café' );

		$this->assertSame( remove_accents( 'Café' ), $this->index->reading( get_post( $post_id ) ) );

		update_post_meta( $post_id, Post_Meta::READING, '   ' );
		$this->assertSame( remove_accents( 'Café' ), $this->index->reading( get_post( $post_id ) ) );
	}

	/**
	 * Kana readings (plain, voiced, halfwidth) should bucket into their
	 * gojūon row regardless of surface form.
	 */
	public function test_bucket_for_groups_kana_variants_by_row() {
		$this->assertSame( 'か', $this->index->bucket_for( 'かいと' ) );
		$this->assertSame( 'か', $this->index->bucket_for( 'ガイド' ) );
		$this->assertSame( 'か', $this->index->bucket_for( 'ｶﾞｲﾄﾞ' ) );
		$this->assertSame( 'わ', $this->index->bucket_for( 'を' ) );
		$this->assertSame( 'わ', $this->index->bucket_for( 'ん' ) );
	}

	/**
	 * Latin readings should bucket by uppercase first letter, case-insensitively.
	 */
	public function test_bucket_for_groups_latin_by_uppercase_letter() {
		$this->assertSame( 'A', $this->index->bucket_for( 'Apple' ) );
		$this->assertSame( 'A', $this->index->bucket_for( 'apple' ) );
		$this->assertSame( 'A', $this->index->bucket_for( 'Ａｐｐｌｅ' ) );
	}

	/**
	 * Readings starting with a digit, symbol, or unconverted kanji should
	 * fall into the catch-all bucket.
	 */
	public function test_bucket_for_falls_back_to_catch_all_bucket() {
		$this->assertSame( '#', $this->index->bucket_for( '3Dプリンター' ) );
		$this->assertSame( '#', $this->index->bucket_for( '税' ) );
	}

	/**
	 * Acceptance criteria (Issue #12): Japanese and Latin (English) terms
	 * mixed together should group correctly — kana rows first, then Latin
	 * letters, each internally sorted, with no cross-contamination.
	 */
	public function test_grouped_items_groups_mixed_japanese_and_english_terms() {
		$this->create_term( 'かいと', 'かいと' );
		$this->create_term( '会員', 'かいいん' );
		$this->create_term( 'Banana' );
		$this->create_term( 'Apple' );
		$this->create_term( 'クーポン', 'くーぽん' );

		$groups    = $this->index->grouped_items();
		$by_bucket = array();

		foreach ( $groups as $group ) {
			$by_bucket[ $group['bucket'] ] = wp_list_pluck( $group['items'], 'title' );
		}

		$this->assertSame( array( 'か', 'A', 'B' ), array_keys( $by_bucket ) );
		// かいいん (会員) < かいと < くーぽん (クーポン) by folded reading.
		$this->assertSame( array( '会員', 'かいと', 'クーポン' ), $by_bucket['か'] );
		$this->assertSame( array( 'Apple' ), $by_bucket['A'] );
		$this->assertSame( array( 'Banana' ), $by_bucket['B'] );
	}

	/**
	 * Buckets should appear in gojūon-row-then-A–Z-then-catch-all order, and
	 * entries within a bucket should sort by reading, not raw title.
	 */
	public function test_grouped_items_bucket_order_and_within_bucket_sort() {
		$this->create_term( 'Zebra' );
		$this->create_term( 'Apple' );
		$this->create_term( 'わさび', 'わさび' );
		$this->create_term( 'あんこ', 'あんこ' );
		$this->create_term( '$100 plan' );

		$groups  = $this->index->grouped_items();
		$buckets = wp_list_pluck( $groups, 'bucket' );

		$this->assertSame( array( 'あ', 'わ', 'A', 'Z', '#' ), $buckets );

		$a_group = $groups[0];
		$this->assertSame( 'あ', $a_group['bucket'] );
		$this->assertSame( array( 'あんこ' ), wp_list_pluck( $a_group['items'], 'title' ) );
	}

	/**
	 * Entries within the same bucket should sort by their kana-folded
	 * reading, independent of surface form (hiragana/katakana/halfwidth).
	 */
	public function test_grouped_items_sorts_within_bucket_by_folded_reading() {
		$this->create_term( 'キャンセル', 'きゃんせる' );
		$this->create_term( 'カード', 'かーど' );

		$groups   = $this->index->grouped_items();
		$ka_group = null;

		foreach ( $groups as $group ) {
			if ( 'か' === $group['bucket'] ) {
				$ka_group = $group;
			}
		}

		$this->assertNotNull( $ka_group );
		$this->assertSame( array( 'カード', 'キャンセル' ), wp_list_pluck( $ka_group['items'], 'title' ) );
	}

	/**
	 * Buckets with no entries should be omitted entirely rather than
	 * appearing empty.
	 */
	public function test_grouped_items_omits_empty_buckets() {
		$this->create_term( 'Apple' );

		$groups = $this->index->grouped_items();

		$this->assertCount( 1, $groups );
		$this->assertSame( 'A', $groups[0]['bucket'] );
	}

	/**
	 * Draft and password-protected entries should never appear in the index.
	 */
	public function test_items_excludes_non_public_entries() {
		$this->create_term( 'Public' );
		self::factory()->post->create(
			array(
				'post_type'   => 'saai_glossary',
				'post_status' => 'draft',
				'post_title'  => 'Draft',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'     => 'saai_glossary',
				'post_status'   => 'publish',
				'post_title'    => 'Locked',
				'post_password' => 'secret',
			)
		);

		$items = $this->index->items();

		$this->assertSame( array( 'Public' ), wp_list_pluck( $items, 'title' ) );
	}

	/**
	 * The mbstring-independent fallback first_char() uses when mb_substr()
	 * is unavailable must still return a single, whole multibyte character —
	 * not the single invalid byte substr() would slice off — so a reading
	 * beginning with hiragana/katakana still lands in its gojūon bucket
	 * rather than the catch-all one. Exercised directly via reflection since
	 * mbstring is normally loaded in the test environment, which would
	 * otherwise make first_char() never reach this branch.
	 */
	public function test_first_char_without_mbstring_reads_a_whole_utf8_character() {
		$method = new \ReflectionMethod( Glossary_Index::class, 'first_char_without_mbstring' );
		$method->setAccessible( true );

		$this->assertSame( 'か', $method->invoke( null, 'かいと' ) );
		$this->assertSame( 'A', $method->invoke( null, 'Apple' ) );
		$this->assertSame( '', $method->invoke( null, '' ) );
	}
}
