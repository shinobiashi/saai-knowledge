<?php
/**
 * Builds the 五十音/A–Z glossary index (query, bucketing, grouping) shared by
 * the glossary-index block and the [saai_glossary] shortcode.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves published saai_glossary entries and groups them into 五十音 row /
 * A–Z / catch-all index buckets, keyed off Post_Meta::READING (falling back
 * to remove_accents(title) per docs/DESIGN.md section 3.3).
 */
final class Glossary_Index {

	/**
	 * Bucket labels in display order: the ten gojūon rows, then A–Z, then the
	 * catch-all bucket for readings that fold to neither (digits, symbols,
	 * unreadable CJK left as-is).
	 *
	 * @var string[]
	 */
	private const BUCKET_ORDER = array(
		'あ',
		'か',
		'さ',
		'た',
		'な',
		'は',
		'ま',
		'や',
		'ら',
		'わ',
		'A',
		'B',
		'C',
		'D',
		'E',
		'F',
		'G',
		'H',
		'I',
		'J',
		'K',
		'L',
		'M',
		'N',
		'O',
		'P',
		'Q',
		'R',
		'S',
		'T',
		'U',
		'V',
		'W',
		'X',
		'Y',
		'Z',
		'#',
	);

	/**
	 * The catch-all bucket label for a reading whose first character is
	 * neither a gojūon kana nor a Latin letter (digits, symbols, kanji left
	 * unconverted by Normalizer::fold_kana()).
	 *
	 * @var string
	 */
	private const OTHER_BUCKET = '#';

	/**
	 * Maps every character Normalizer::fold_kana() can produce (the 46
	 * gojūon hiragana, ん, を, plus the small-tsu/small-ya-yu-yo/ー that fold
	 * to a base row) to its gojūon row bucket label.
	 *
	 * Built once, not as a class constant: it's derived from KANA_ROWS below
	 * so the two can't drift apart, and PHP constants can't be computed from
	 * an expression.
	 *
	 * @var array<string, string>|null
	 */
	private static $row_lookup = null;

	/**
	 * Each gojūon row's member hiragana, in reading order — the source
	 * Normalizer::fold_kana() output is checked against to resolve a bucket
	 * label. ん and を close out the わ row rather than getting their own,
	 * matching how Normalizer::KANA_FOLD treats them as わ-row members.
	 *
	 * @var array<string, string[]>
	 */
	private const KANA_ROWS = array(
		'あ' => array( 'あ', 'い', 'う', 'え', 'お' ),
		'か' => array( 'か', 'き', 'く', 'け', 'こ' ),
		'さ' => array( 'さ', 'し', 'す', 'せ', 'そ' ),
		'た' => array( 'た', 'ち', 'つ', 'て', 'と' ),
		'な' => array( 'な', 'に', 'ぬ', 'ね', 'の' ),
		'は' => array( 'は', 'ひ', 'ふ', 'へ', 'ほ' ),
		'ま' => array( 'ま', 'み', 'む', 'め', 'も' ),
		'や' => array( 'や', 'ゆ', 'よ' ),
		'ら' => array( 'ら', 'り', 'る', 'れ', 'ろ' ),
		'わ' => array( 'わ', 'を', 'ん' ),
	);

	/**
	 * The published saai_glossary entries, each shaped
	 * [ 'id', 'title', 'reading', 'url' ].
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function items(): array {
		$query = new \WP_Query(
			array(
				'post_type'           => 'saai_glossary',
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => -1,
				'orderby'             => 'title',
				'order'               => 'ASC',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);

		$items = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$items[] = array(
				'id'      => $post->ID,
				'title'   => get_the_title( $post ),
				'reading' => $this->reading( $post ),
				'url'     => (string) get_permalink( $post ),
			);
		}

		return $items;
	}

	/**
	 * A glossary entry's reading: Post_Meta::READING if set, otherwise
	 * remove_accents(title) (docs/DESIGN.md section 3.3).
	 *
	 * @param \WP_Post $post Glossary entry.
	 * @return string
	 */
	public function reading( \WP_Post $post ): string {
		$reading = get_post_meta( $post->ID, Post_Meta::READING, true );

		if ( is_string( $reading ) && '' !== trim( $reading ) ) {
			return $reading;
		}

		return remove_accents( get_the_title( $post ) );
	}

	/**
	 * The index bucket a reading sorts under — a gojūon row label, an
	 * uppercase Latin letter, or the catch-all OTHER_BUCKET.
	 *
	 * @param string $reading Reading (or fallback title), see reading().
	 * @return string
	 */
	public function bucket_for( string $reading ): string {
		$folded = Normalizer::fold_kana( Normalizer::normalize( $reading ) );
		$first  = self::first_char( $folded );

		if ( '' === $first ) {
			return self::OTHER_BUCKET;
		}

		$row = self::row_lookup()[ $first ] ?? null;

		if ( null !== $row ) {
			return $row;
		}

		$upper = strtoupper( $first );

		if ( 1 === strlen( $first ) && ctype_alpha( $upper ) ) {
			return $upper;
		}

		return self::OTHER_BUCKET;
	}

	/**
	 * A reading's sort key within its bucket: normalized + kana-folded, then
	 * case-folded so Latin readings sort case-insensitively.
	 *
	 * @param string $reading Reading (or fallback title), see reading().
	 * @return string
	 */
	public function sort_key( string $reading ): string {
		return strtolower( Normalizer::fold_kana( Normalizer::normalize( $reading ) ) );
	}

	/**
	 * All glossary entries grouped into index buckets, in BUCKET_ORDER —
	 * buckets with no entries are omitted. Entries within a bucket are
	 * sorted by sort_key(), then title as a final deterministic tie-break.
	 *
	 * @return array<int, array<string, mixed>> Groups shaped [ 'bucket' => string, 'items' => item[] ].
	 */
	public function grouped_items(): array {
		$buckets = array();

		foreach ( $this->items() as $item ) {
			$reading = is_string( $item['reading'] ?? null ) ? $item['reading'] : '';
			$bucket  = $this->bucket_for( $reading );

			$item['sort_key']     = $this->sort_key( $reading );
			$buckets[ $bucket ][] = $item;
		}

		$groups = array();

		foreach ( self::BUCKET_ORDER as $bucket ) {
			if ( empty( $buckets[ $bucket ] ) ) {
				continue;
			}

			$bucket_items = $buckets[ $bucket ];

			usort(
				$bucket_items,
				static function ( array $a, array $b ): int {
					$by_sort_key = strcmp( (string) $a['sort_key'], (string) $b['sort_key'] );

					if ( 0 !== $by_sort_key ) {
						return $by_sort_key;
					}

					return strcasecmp( (string) $a['title'], (string) $b['title'] );
				}
			);

			foreach ( $bucket_items as &$bucket_item ) {
				unset( $bucket_item['sort_key'] );
			}
			unset( $bucket_item );

			$groups[] = array(
				'bucket' => $bucket,
				'items'  => $bucket_items,
			);
		}

		return $groups;
	}

	/**
	 * The first character of a UTF-8 string, multibyte-safe.
	 *
	 * @param string $text Text to read from.
	 * @return string
	 */
	private static function first_char( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, 1, 'UTF-8' );
		}

		return self::first_char_without_mbstring( $text );
	}

	/**
	 * The mbstring-independent fallback for first_char(), split out so tests
	 * can exercise it directly regardless of whether mbstring happens to be
	 * loaded in the environment running them.
	 *
	 * Mbstring is a recommended, not required, PHP extension (same stance as
	 * WP_Site_Health::get_test_php_extensions()) — without it,
	 * substr( $text, 0, 1 ) would slice off a single invalid byte from any
	 * multibyte UTF-8 character. PCRE's /u modifier decodes UTF-8
	 * independently of mbstring, so it stays multibyte-safe here.
	 *
	 * @param string $text Text to read from; never empty (first_char() short-circuits that case).
	 * @return string
	 */
	private static function first_char_without_mbstring( string $text ): string {
		return preg_match( '/^./us', $text, $matches ) ? $matches[0] : '';
	}

	/**
	 * Builds (and memoizes) the fold_kana()-output -> gojūon row lookup
	 * table derived from KANA_ROWS.
	 *
	 * @return array<string, string>
	 */
	private static function row_lookup(): array {
		if ( null !== self::$row_lookup ) {
			return self::$row_lookup;
		}

		$lookup = array();

		foreach ( self::KANA_ROWS as $row => $members ) {
			foreach ( $members as $member ) {
				$lookup[ $member ] = $row;
			}
		}

		self::$row_lookup = $lookup;

		return $lookup;
	}
}
