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
	 * Transient key the grouped index is cached under.
	 *
	 * @var string
	 */
	private const CACHE_KEY = 'saai_glossary_index';

	/**
	 * How long the grouped index is cached for. Like Sidebar_Tree, this
	 * rebuilds (query + per-item kana folding/sorting) on every render of the
	 * glossary-index block/shortcode; a TTL self-heals anything the
	 * invalidation hooks in register() don't cover.
	 *
	 * @var int
	 */
	private const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Hooks cache invalidation into WordPress.
	 */
	public function register(): void {
		add_action( 'save_post_saai_glossary', array( $this, 'flush_cache' ) );
		add_action( 'trashed_post', array( $this, 'flush_cache' ) );
		// wp_delete_post( $id, true ) (REST's force=true, `wp post delete
		// --force`) skips wp_trash_post() entirely, so trashed_post never
		// fires — deleted_post is needed too (same reasoning as
		// Llms_Index::register()).
		add_action( 'deleted_post', array( $this, 'flush_cache' ) );
		// A direct update_post_meta( $id, Post_Meta::READING, ... ) call (an
		// import script, a migration, another plugin) changes a term's
		// bucket/sort key without going through wp_update_post(), so
		// save_post_saai_glossary above never fires for it — added/updated/
		// deleted_post_meta are needed too (Codex review). Not narrowed to
		// saai_glossary objects, matching the same accepted-tradeoff
		// reasoning as Sidebar_Tree::flush_cache_on_term_relationship_change().
		add_action( 'added_post_meta', array( $this, 'flush_cache_on_reading_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'flush_cache_on_reading_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'flush_cache_on_reading_meta_change' ), 10, 3 );
		add_action( 'update_option_saai_knowledge_settings', array( $this, 'maybe_flush_cache_on_slug_change' ), 10, 2 );
		// A brand-new install has no saai_knowledge_settings option row yet;
		// update_option() delegates a first-ever save of it to add_option()
		// internally (WordPress core: default_option_{$option} matching the
		// old value short-circuits to add_option()), which never fires
		// update_option_{$option} — only add_option_{$option} does. Without
		// this, a slug changed on that very first save wouldn't flush this
		// cache at all (Codex review).
		add_action( 'add_option_saai_knowledge_settings', array( $this, 'flush_cache' ) );
	}

	/**
	 * Flushes the cache when a post's saai_reading meta is added, updated,
	 * or deleted directly — see register()'s docblock.
	 *
	 * $meta_id is int for added_post_meta/updated_post_meta but an array of
	 * IDs for deleted_post_meta (WordPress core: delete_metadata() passes
	 * $meta_ids, plural) — untyped/mixed since this shared callback handles
	 * all three and never uses the value.
	 *
	 * @param mixed  $meta_id   Unused; kept to match the *_post_meta hook signature.
	 * @param int    $object_id Unused.
	 * @param string $meta_key  The meta key that changed.
	 */
	public function flush_cache_on_reading_meta_change( $meta_id, int $object_id, string $meta_key ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $meta_id/$object_id must precede $meta_key to match the *_post_meta hook signature.
		if ( Post_Meta::READING === $meta_key ) {
			$this->flush_cache();
		}
	}

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
	 * Deliberately posts_per_page => -1, not a fixed cap: the bundled archive
	 * template renders this as one complete page by design
	 * (paged_glossary_archive_redirect_url()'s docblock, DESIGN.md section
	 * 3.5) and redirects any /glossary/page/2/ request back to the root — a
	 * hard cap here would silently and permanently drop every term past it
	 * from the index, with no path to reach the missing entries (Codex
	 * review: an earlier revision capped this at 5000 as a perf-audit
	 * safety net, without accounting for that redirect).
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
		return self::bucket_from_folded( Normalizer::fold_kana( Normalizer::normalize( $reading ) ) );
	}

	/**
	 * The actual bucket-resolution logic bucket_for() wraps, taking an
	 * already-normalized-and-kana-folded string so grouped_items() can share
	 * one fold_kana()/normalize() pass with sort_key() instead of each
	 * running it independently on the same reading (perf review — folding is
	 * a per-character table walk, so this halves that cost for every entry).
	 * Case-insensitive on the Latin-letter branch (it uppercases $first
	 * itself), so it's safe to call with either sort_key()'s lowercased
	 * output or bucket_for()'s own unmodified one.
	 *
	 * @param string $folded Normalize()+fold_kana() output.
	 * @return string
	 */
	private static function bucket_from_folded( string $folded ): string {
		$first = self::first_char( $folded );

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
	 * The cached result of grouped_items_uncached(), building and caching it
	 * on a miss.
	 *
	 * @return array<int, array<string, mixed>> Groups shaped [ 'bucket' => string, 'items' => item[] ].
	 */
	public function grouped_items(): array {
		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$groups = $this->grouped_items_uncached();

		set_transient( self::CACHE_KEY, $groups, self::CACHE_TTL );

		return $groups;
	}

	/**
	 * Deletes the cached grouped index. Hooked to save/trash/delete of
	 * saai_glossary posts. A stale cache otherwise only self-heals after
	 * CACHE_TTL.
	 */
	public function flush_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Flushes the cache when a saai_knowledge_settings save changes
	 * slug_glossary — every item's 'url' embeds get_permalink(), which
	 * changes as soon as Post_Types re-registers saai_glossary with its new
	 * slug on the next init (same reasoning as Llms_Index's equivalent).
	 *
	 * @param mixed $old_value Previous `saai_knowledge_settings` value.
	 * @param mixed $new_value New `saai_knowledge_settings` value.
	 */
	public function maybe_flush_cache_on_slug_change( $old_value, $new_value ): void {
		$old_value = is_array( $old_value ) ? $old_value : array();
		$new_value = is_array( $new_value ) ? $new_value : array();

		if ( ( $old_value['slug_glossary'] ?? null ) !== ( $new_value['slug_glossary'] ?? null ) ) {
			$this->flush_cache();
		}
	}

	/**
	 * All glossary entries grouped into index buckets, in BUCKET_ORDER —
	 * buckets with no entries are omitted. Entries within a bucket are
	 * sorted by sort_key(), then title as a final deterministic tie-break.
	 *
	 * @return array<int, array<string, mixed>> Groups shaped [ 'bucket' => string, 'items' => item[] ].
	 */
	private function grouped_items_uncached(): array {
		$buckets = array();

		foreach ( $this->items() as $item ) {
			$reading = is_string( $item['reading'] ?? null ) ? $item['reading'] : '';
			// normalize()+fold_kana() run once here and are shared between the
			// bucket lookup and the sort key (see bucket_from_folded()'s
			// docblock) instead of each independently reprocessing $reading.
			$folded = Normalizer::fold_kana( Normalizer::normalize( $reading ) );
			$bucket = self::bucket_from_folded( $folded );

			$item['sort_key']     = strtolower( $folded );
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
