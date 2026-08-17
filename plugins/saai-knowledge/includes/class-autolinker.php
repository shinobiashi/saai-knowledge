<?php
/**
 * Auto-links glossary terms found in post content to their glossary page,
 * per docs/DESIGN-AUTOLINK.md.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Detects glossary term mentions in rendered post content and replaces the
 * first occurrence of each with a tooltip-carrying link to the term's
 * glossary page.
 *
 * The public entry point is process(): it is the service docs/DESIGN-HOOKS-API.md
 * section 5 exposes as `$plugin->autolinker()->process()` for the paid add-on
 * to run against non-post content (e.g. a WooCommerce product description).
 * process_content() is the `the_content` callback used for the free version's
 * own post types.
 */
final class Autolinker {

	/**
	 * Option holding the built dictionary, keyed by the generation it was
	 * built for: [ 'generation' => int, 'entries' => array ]. autoload: no.
	 *
	 * @var string
	 */
	private const DICTIONARY_OPTION = 'saai_autolink_dict';

	/**
	 * Option holding the dictionary generation number. Bumped whenever a
	 * glossary term is saved, trashed, untrashed, or permanently deleted.
	 *
	 * @var string
	 */
	private const GENERATION_OPTION = 'saai_dict_generation';

	/**
	 * Option flagging that the last dictionary build had to truncate
	 * patterns past the MAX_PATTERNS cap, so an admin notice can be shown.
	 *
	 * @var string
	 */
	private const TRUNCATED_OPTION = 'saai_autolink_dict_truncated';

	/**
	 * Object cache group for the built dictionary and processed content.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'saai_autolink';

	/**
	 * Bumped whenever the shape of the value cache_key() is used for
	 * (currently `[ 'html' => string, 'has_links' => bool ]`) changes.
	 * Folded into the key itself rather than just handled by the is_array()
	 * check in process() so a rolling deploy/rollback behind a shared
	 * persistent object cache can't have old- and new-code requests
	 * fighting over the same key with two different value shapes — each
	 * version simply reads/writes its own key namespace and self-heals
	 * once the deploy finishes, instead of every request in the mixed
	 * window missing the cache.
	 *
	 * @var string
	 */
	private const CACHE_SCHEMA_VERSION = '2';

	/**
	 * Maximum number of match patterns (title + synonyms, across all terms)
	 * the dictionary keeps. Longest patterns win when the cap is exceeded.
	 *
	 * @var int
	 */
	private const MAX_PATTERNS = 2000;

	/**
	 * Default per-post cap on the number of auto-links inserted, used until
	 * the M4 settings screen exists to override it.
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_LINKS = 20;

	/**
	 * Default post types eligible for auto-linking; see docs/DESIGN.md section 4.3.
	 *
	 * @var string[]
	 */
	private const DEFAULT_POST_TYPES = array( 'post', 'page', 'saai_kb', 'saai_faq' );

	/**
	 * The data-wp-interactive attribute build_anchor() stamps onto every
	 * term link, naming the saai-knowledge/tooltip Interactivity API store
	 * (docs/DESIGN-AUTOLINK.md section 3.3). Named as a constant purely for
	 * readability at its one use site in build_anchor() — it is NOT a
	 * cross-file source of truth: the store id also appears as independent
	 * literals in Tooltip::MODULE_ID (class-tooltip.php) and view.js's own
	 * store() call, and this constant can't keep those in sync if one of
	 * the three ever changes without the others. has_rendered_links() does
	 * not derive its answer from this string (see process()'s `$link_count`
	 * tracking below) precisely because grepping rendered HTML for it is
	 * unreliable — ordinary content that quotes this plugin's own anchor
	 * markup as a documentation/code example would false-positive.
	 *
	 * @var string
	 */
	private const TOOLTIP_INTERACTIVE_MARKER = 'data-wp-interactive="saai-knowledge/tooltip"';

	/**
	 * Tag names whose rendered text content is never auto-linked: headings
	 * (a term shouldn't link inside its own section title), existing links
	 * (no links inside links), code-ish elements, and interactive controls.
	 * script/style/comment bodies are handled separately, by stashing them
	 * out before the tag/text walk even starts (see stash_raw_blocks()).
	 *
	 * @var string[]
	 */
	private const EXCLUDED_ELEMENTS = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'code', 'pre', 'kbd', 'samp', 'button' );

	/**
	 * Per-instance memo of normalize_char() results, keyed by the raw
	 * (un-normalized) character. Built lazily; a single request's content
	 * repeats common hiragana/kanji heavily, so this cuts normalizer calls
	 * substantially without needing to persist across requests.
	 *
	 * @var array<string, string>
	 */
	private $char_normalization_cache = array();

	/**
	 * Per-instance memo of compile_groups() results, keyed by a fingerprint
	 * of the dictionary's entry post IDs (see compiled_groups_for()).
	 * Different contexts (e.g. a paid add-on's per-product dictionary) can
	 * be compiled more than once per request, but the same dictionary is
	 * compiled only once.
	 *
	 * @var array<string, array<string, array{regex: string, entry_map: array<string, int>}>>
	 */
	private $compiled_cache = array();

	/**
	 * Whether process() has returned HTML containing at least one term link
	 * during the current request, checked by Tooltip::render() to decide
	 * whether the singleton tooltip element and its script module are
	 * needed. Set from the real link count replace_in_html() computes on a
	 * fresh build, or from the `has_links` flag stored alongside the cached
	 * HTML on a cache hit — never derived by inspecting rendered HTML text,
	 * so it can't be fooled by content that merely quotes the anchor
	 * markup (e.g. a documentation example).
	 *
	 * @var bool
	 */
	private $has_rendered_links = false;

	/**
	 * Hooks the auto-link engine into WordPress.
	 */
	public function register(): void {
		add_filter( 'the_content', array( $this, 'process_content' ), 50 );
		add_action( 'save_post_saai_glossary', array( $this, 'handle_glossary_saved' ) );
		add_action( 'deleted_post', array( $this, 'handle_post_deleted' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_dictionary_truncated_notice' ) );
	}

	/**
	 * The `the_content` callback: auto-links the current post's own content.
	 *
	 * @param string $content Rendered post content.
	 * @return string
	 */
	public function process_content( string $content ): string {
		if ( is_admin() || is_feed() || $this->is_rest_request() ) {
			return $content;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}

		if ( ! in_array( $post->post_type, $this->target_post_types(), true ) ) {
			return $content;
		}

		return $this->process(
			$content,
			array(
				'post_id'   => $post->ID,
				'post_type' => $post->post_type,
			)
		);
	}

	/**
	 * Runs the auto-link engine against arbitrary HTML.
	 *
	 * Public per docs/DESIGN-HOOKS-API.md section 5 — the paid add-on's only
	 * supported way to apply auto-linking outside the free version's own
	 * post types (e.g. a WooCommerce product description filter).
	 *
	 * @param string               $html    HTML to auto-link.
	 * @param array<string, mixed> $context Context: `post_id` (int, optional) and
	 *                                      `post_type` (string, optional). Passed through
	 *                                      to the saai_autolink_dictionary filter.
	 * @return string
	 */
	public function process( string $html, array $context = array() ): string {
		if ( '' === $html ) {
			return $html;
		}

		$post_id = isset( $context['post_id'] ) ? (int) $context['post_id'] : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( $post instanceof \WP_Post ) {
			/**
			 * Filters whether auto-linking runs for a given post.
			 *
			 * @since 0.1.0
			 *
			 * @param bool     $enabled Whether auto-linking should run. Default true.
			 * @param \WP_Post $post    The post being rendered.
			 */
			$enabled = apply_filters( 'saai_autolink_enabled', true, $post );

			if ( ! $enabled ) {
				return $html;
			}

			// A term's own glossary page never auto-links to other terms;
			// keeping definitions readable takes priority.
			if ( 'saai_glossary' === $post->post_type ) {
				return $html;
			}

			if ( (bool) get_post_meta( $post->ID, Post_Meta::NO_AUTOLINK, true ) ) {
				return $html;
			}
		}

		$entries = $this->dictionary_for_context( $context );

		if ( $post instanceof \WP_Post ) {
			$current_post_id = $post->ID;
			$entries         = array_values(
				array_filter(
					$entries,
					static function ( array $entry ) use ( $current_post_id ): bool {
						return $entry['post_id'] !== $current_post_id;
					}
				)
			);
		}

		if ( ! $entries ) {
			return $html;
		}

		$cache_key = $this->cache_key( $html, $post );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) && isset( $cached['html'] ) && is_string( $cached['html'] ) ) {
			if ( ! empty( $cached['has_links'] ) ) {
				$this->has_rendered_links = true;
			}

			return $cached['html'];
		}

		$link_count = 0;
		$result     = $this->replace_in_html( $html, $entries, $link_count );

		wp_cache_set(
			$cache_key,
			array(
				'html'      => $result,
				'has_links' => $link_count > 0,
			),
			self::CACHE_GROUP,
			HOUR_IN_SECONDS
		);

		if ( $link_count > 0 ) {
			$this->has_rendered_links = true;
		}

		return $result;
	}

	/**
	 * Whether process() has produced at least one term link so far during
	 * the current request. Read by Tooltip::render() to skip the singleton
	 * tooltip element and script module on pages with no auto-links.
	 *
	 * @return bool
	 */
	public function has_rendered_links(): bool {
		return $this->has_rendered_links;
	}

	/**
	 * Bumps the dictionary generation when a glossary term is saved
	 * (created, updated, published, trashed, or untrashed).
	 *
	 * @param int $post_id The saved post's ID.
	 */
	public function handle_glossary_saved( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$this->bump_generation();
	}

	/**
	 * Bumps the dictionary generation when a glossary term is permanently deleted.
	 *
	 * Save_post_saai_glossary (handle_glossary_saved()) covers trash/untrash,
	 * since both route through wp_insert_post(); a hard delete via
	 * wp_delete_post() does not, so it needs this separate hook.
	 *
	 * @param int      $post_id Unused; kept to match the deleted_post hook signature.
	 * @param \WP_Post $post    The deleted post.
	 */
	public function handle_post_deleted( int $post_id, \WP_Post $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $post_id must precede $post to match the deleted_post hook signature.
		if ( 'saai_glossary' === $post->post_type ) {
			$this->bump_generation();
		}
	}

	/**
	 * Shows an admin notice on the glossary list screen when the dictionary
	 * had to drop patterns past the MAX_PATTERNS cap.
	 */
	public function render_dictionary_truncated_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! get_option( self::TRUNCATED_OPTION ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof \WP_Screen || 'edit-saai_glossary' !== $screen->id ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: maximum auto-link dictionary pattern count. */
					__( 'The auto-link dictionary has more than %d term/synonym patterns; the shortest patterns were dropped from auto-linking. Consider trimming synonyms or splitting terms.', 'saai-knowledge' ),
					self::MAX_PATTERNS
				)
			)
		);
	}

	/**
	 * The post types eligible for auto-linking.
	 *
	 * @return string[]
	 */
	private function target_post_types(): array {
		/**
		 * Filters the post types eligible for auto-linking.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $post_types Post type slugs. Default: post, page, saai_kb, saai_faq.
		 */
		$post_types = apply_filters( 'saai_autolink_post_types', self::DEFAULT_POST_TYPES );

		if ( ! is_array( $post_types ) ) {
			return self::DEFAULT_POST_TYPES;
		}

		return array_values( array_filter( $post_types, 'is_string' ) );
	}

	/**
	 * Resolves the dictionary for a given context: the cached built
	 * dictionary, run through the public saai_autolink_dictionary filter.
	 *
	 * @param array<string, mixed> $context Context passed through to the filter.
	 * @return array<int, array<string, mixed>>
	 */
	private function dictionary_for_context( array $context ): array {
		$entries = $this->get_cached_dictionary_entries();

		/**
		 * Filters the auto-link dictionary.
		 *
		 * Entry shape: [ 'post_id', 'url', 'label', 'patterns' => string[], 'excerpt' ].
		 * See docs/DESIGN-AUTOLINK.md section 2.1.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int, array<string, mixed>> $entries Dictionary entries.
		 * @param array<string, mixed>             $context [ 'post_id' => int, 'post_type' => string ].
		 */
		$filtered = apply_filters( 'saai_autolink_dictionary', $entries, $context );

		// @phpstan-ignore ternary.elseUnreachable (PHPStan trusts the docblock @param type above, but a third-party saai_autolink_dictionary callback can violate it at runtime.)
		return $this->sanitize_dictionary_entries( is_array( $filtered ) ? $filtered : $entries );
	}

	/**
	 * Validates/normalizes dictionary entries after the public
	 * saai_autolink_dictionary filter has run.
	 *
	 * A third-party callback can return entries with a missing/wrong-typed
	 * key (e.g. `patterns` not an array), which would otherwise reach
	 * compile_groups()/build_anchor() and trigger a warning or TypeError —
	 * undermining the preg-failure fail-safe with a different kind of
	 * failure. Malformed entries are dropped entirely rather than partially
	 * repaired, so a bad entry never silently links to the wrong place.
	 *
	 * @param array<int, mixed> $entries Filter output, of unknown-if-conforming shape.
	 * @return array<int, array<string, mixed>>
	 */
	private function sanitize_dictionary_entries( array $entries ): array {
		$sanitized = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$post_id  = $entry['post_id'] ?? null;
			$url      = $entry['url'] ?? null;
			$patterns = $entry['patterns'] ?? null;

			if ( ! is_int( $post_id ) || $post_id <= 0 || ! is_string( $url ) || '' === $url || ! is_array( $patterns ) ) {
				continue;
			}

			$patterns = array_values(
				array_filter(
					$patterns,
					static function ( $pattern ): bool {
						return is_string( $pattern ) && '' !== $pattern;
					}
				)
			);

			if ( ! $patterns ) {
				continue;
			}

			$sanitized[] = array(
				'post_id'  => $post_id,
				'url'      => $url,
				'label'    => is_string( $entry['label'] ?? null ) ? $entry['label'] : '',
				'patterns' => $patterns,
				'excerpt'  => is_string( $entry['excerpt'] ?? null ) ? $entry['excerpt'] : '',
			);
		}

		return $sanitized;
	}

	/**
	 * Reads the dictionary for the current generation, rebuilding it if the
	 * cached/persisted copy is stale or missing.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_cached_dictionary_entries(): array {
		$generation = (int) get_option( self::GENERATION_OPTION, 1 );
		$cache_key  = 'saai_autolink_dict_v' . $generation;
		$cached     = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$stored = get_option( self::DICTIONARY_OPTION );

		if ( is_array( $stored ) && ( $stored['generation'] ?? null ) === $generation && isset( $stored['entries'] ) && is_array( $stored['entries'] ) ) {
			wp_cache_set( $cache_key, $stored['entries'], self::CACHE_GROUP );

			return $stored['entries'];
		}

		$entries = $this->build_dictionary_entries();

		update_option(
			self::DICTIONARY_OPTION,
			array(
				'generation' => $generation,
				'entries'    => $entries,
			),
			false
		);
		wp_cache_set( $cache_key, $entries, self::CACHE_GROUP );

		return $entries;
	}

	/**
	 * Builds the dictionary from every published glossary term.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function build_dictionary_entries(): array {
		$query = new \WP_Query(
			array(
				'post_type'      => 'saai_glossary',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$entries = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$label    = get_the_title( $post );
			$patterns = $this->entry_patterns( $post, $label );

			if ( ! $patterns ) {
				continue;
			}

			$url = get_permalink( $post );

			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$entries[] = array(
				'post_id'  => $post->ID,
				'url'      => $url,
				'label'    => html_entity_decode( wp_strip_all_tags( $label ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ),
				'patterns' => $patterns,
				'excerpt'  => $this->entry_excerpt( $post ),
			);
		}

		return $this->cap_dictionary_patterns( $entries );
	}

	/**
	 * The match patterns for one glossary term: its title plus each
	 * newline-separated synonym, trimmed and de-duplicated. Entity-encoded
	 * as WordPress itself renders titles/content (the_title runs
	 * wptexturize()), so patterns compare like-for-like against rendered
	 * HTML text nodes.
	 *
	 * @param \WP_Post $post  The glossary term.
	 * @param string   $label The term's rendered title (already computed by the caller).
	 * @return string[]
	 */
	private function entry_patterns( \WP_Post $post, string $label ): array {
		$raw = array( $label );

		$synonyms_raw = (string) get_post_meta( $post->ID, Post_Meta::SYNONYMS, true );

		if ( '' !== $synonyms_raw ) {
			$lines = preg_split( '/\r\n|\r|\n/', $synonyms_raw );
			$raw   = array_merge( $raw, false === $lines ? array() : $lines );
		}

		$patterns = array();
		$seen     = array();

		foreach ( $raw as $pattern ) {
			$pattern = trim( $pattern );

			if ( '' === $pattern || isset( $seen[ $pattern ] ) ) {
				continue;
			}

			$seen[ $pattern ] = true;
			$patterns[]       = $pattern;
		}

		return $patterns;
	}

	/**
	 * A plain-text tooltip excerpt for a term: its excerpt if set, otherwise
	 * the first words of its definition body. Same logic as
	 * Glossary_Term::description().
	 *
	 * @param \WP_Post $post The glossary term.
	 * @return string
	 */
	private function entry_excerpt( \WP_Post $post ): string {
		$text = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( $post->post_content, 55 );

		return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Caps the dictionary at MAX_PATTERNS total patterns, dropping the
	 * shortest patterns first (across the whole dictionary, not per entry).
	 * An entry that loses all of its patterns is dropped entirely.
	 *
	 * @param array<int, array<string, mixed>> $entries Dictionary entries.
	 * @return array<int, array<string, mixed>>
	 */
	private function cap_dictionary_patterns( array $entries ): array {
		$total = 0;

		foreach ( $entries as $entry ) {
			$total += count( $entry['patterns'] );
		}

		if ( $total <= self::MAX_PATTERNS ) {
			if ( get_option( self::TRUNCATED_OPTION ) ) {
				delete_option( self::TRUNCATED_OPTION );
			}

			return $entries;
		}

		$flat = array();

		foreach ( $entries as $entry_index => $entry ) {
			foreach ( $entry['patterns'] as $pattern ) {
				$flat[] = array(
					'entry_index' => $entry_index,
					'pattern'     => $pattern,
				);
			}
		}

		usort(
			$flat,
			function ( array $a, array $b ): int {
				return $this->char_length( $b['pattern'] ) <=> $this->char_length( $a['pattern'] );
			}
		);

		$kept = array_slice( $flat, 0, self::MAX_PATTERNS );

		$surviving_patterns = array();

		foreach ( $kept as $item ) {
			$surviving_patterns[ $item['entry_index'] ][] = $item['pattern'];
		}

		$capped = array();

		foreach ( $entries as $entry_index => $entry ) {
			if ( empty( $surviving_patterns[ $entry_index ] ) ) {
				continue;
			}

			$entry['patterns'] = $surviving_patterns[ $entry_index ];
			$capped[]          = $entry;
		}

		update_option( self::TRUNCATED_OPTION, true, false );

		return $capped;
	}

	/**
	 * Increments the dictionary generation number, invalidating the built
	 * dictionary. The dictionary itself is rebuilt lazily, on next read.
	 */
	private function bump_generation(): void {
		$generation = (int) get_option( self::GENERATION_OPTION, 1 );

		update_option( self::GENERATION_OPTION, $generation + 1, false );
	}

	/**
	 * The maximum number of auto-links to insert per post.
	 *
	 * Reads the `autolink_max_links` key of the `saai_knowledge_settings`
	 * option (docs/DESIGN.md section 3.4); defaults since the settings
	 * screen (M4) doesn't exist yet to have written a value. Same read
	 * pattern as Faq_List::structured_data_enabled().
	 *
	 * @return int
	 */
	private function max_links(): int {
		$settings = get_option( 'saai_knowledge_settings' );

		if ( is_array( $settings ) && array_key_exists( 'autolink_max_links', $settings ) ) {
			$value = (int) $settings['autolink_max_links'];

			if ( $value > 0 ) {
				return $value;
			}
		}

		return self::DEFAULT_MAX_LINKS;
	}

	/**
	 * Builds the object-cache key for a processed HTML string.
	 *
	 * Per docs/DESIGN-AUTOLINK.md section 2.3: keyed off the dictionary
	 * generation, the settings that affect output, the post's modified time
	 * (when a post is known), and always a content hash. The content hash is
	 * not optional even when a post is known: process() is a public service
	 * (docs/DESIGN-HOOKS-API.md section 5) an add-on can call more than once
	 * for the *same* post_id with different HTML in one request — e.g. a
	 * WooCommerce product's short description and full description both tied
	 * to one product post — and post_id + post_modified_gmt alone can't tell
	 * those two calls apart.
	 *
	 * @param string        $html Input HTML, hashed into the key.
	 * @param \WP_Post|null $post The post being processed, if any.
	 * @return string
	 */
	private function cache_key( string $html, ?\WP_Post $post ): string {
		$generation = (int) get_option( self::GENERATION_OPTION, 1 );
		$identity   = $post instanceof \WP_Post
			? $post->ID . '|' . $post->post_modified_gmt . '|' . md5( $html )
			: 'raw|' . md5( $html );

		return 'saai_al_' . self::CACHE_SCHEMA_VERSION . '_' . md5( $generation . '|' . $this->max_links() . '|' . $identity );
	}

	/**
	 * Whether the current request is a REST API request.
	 *
	 * @return bool
	 */
	private function is_rest_request(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Runs the matching engine against HTML and replaces accepted matches
	 * with term links. Fails safe: any preg error at any stage discards the
	 * partial result and returns the original, untouched HTML.
	 *
	 * @param string                           $html       HTML to auto-link.
	 * @param array<int, array<string, mixed>> $entries    Dictionary entries.
	 * @param int                              $link_count Out param: set to the number of
	 *                                                      term links actually inserted (0 on
	 *                                                      any early/fail-safe return, since
	 *                                                      those return $html untouched). Lets
	 *                                                      process() learn whether a link was
	 *                                                      produced without re-scanning the
	 *                                                      returned HTML for a marker string.
	 * @return string
	 */
	private function replace_in_html( string $html, array $entries, int &$link_count = 0 ): string {
		$link_count = 0;
		$compiled   = $this->compiled_groups_for( $entries );

		if ( ! $compiled ) {
			return $html;
		}

		$stashed = array();
		$working = $this->stash_raw_blocks( $html, $stashed );

		if ( PREG_NO_ERROR !== preg_last_error() ) {
			$this->log_preg_error();

			return $html;
		}

		// Quote-aware: a bare `[^>]*+` would treat a `>` inside a quoted
		// attribute value (e.g. `<span title="x > API">`) as the tag's own
		// end, splitting the rest of that attribute value out as a "text"
		// segment — matching a term inside it and inserting a link into the
		// middle of an attribute, corrupting the markup. Each alternative
		// only consumes what it recognizes (plain non-quote/non-`>` chars,
		// or a fully-quoted string), so there's no ambiguity between them
		// for backtracking to explore — safe against catastrophic
		// backtracking despite not being a single flat character class.
		$segments = preg_split( '/(<(?:[^"\'>]++|"[^"]*+"|\'[^\']*+\')*+>)/u', $working, -1, PREG_SPLIT_DELIM_CAPTURE );

		// @phpstan-ignore notIdentical.alwaysFalse (PHPStan's preg_last_error() stub always returns literal 0 here; the check is a real fail-safe against pathological input at runtime — docs/DESIGN-AUTOLINK.md section 5.)
		if ( false === $segments || PREG_NO_ERROR !== preg_last_error() ) {
			$this->log_preg_error();

			return $html;
		}

		$depth_stack = array();
		$link_state  = array(
			'used'  => array(),
			'count' => 0,
		);
		$max_links   = $this->max_links();

		$output = '';

		foreach ( $segments as $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			if ( '<' === $segment[0] ) {
				$output .= $segment;
				$this->update_depth_stack( $segment, $depth_stack );
				continue;
			}

			if ( $depth_stack || $link_state['count'] >= $max_links ) {
				$output .= $segment;
				continue;
			}

			$output .= $this->process_text_segment( $segment, $compiled, $entries, $link_state, $max_links );
		}

		// @phpstan-ignore notIdentical.alwaysFalse (see the identical suppression above; a preg_match_all() failure inside process_text_segment() during the loop above is what this actually guards against at runtime.)
		if ( PREG_NO_ERROR !== preg_last_error() ) {
			$this->log_preg_error();

			return $html;
		}

		$link_count = $link_state['count'];

		return $this->unstash( $output, $stashed );
	}

	/**
	 * Resolves compiled match-group regexes for a dictionary, memoized per
	 * request so a repeatedly-used dictionary is only compiled once.
	 *
	 * The fingerprint includes each entry's patterns, not just its post_id:
	 * saai_autolink_dictionary can return the same post_id set with a
	 * different patterns list per context (e.g. a paid add-on narrowing
	 * synonyms per product) in the same request, and post_id alone would
	 * wrongly reuse a stale compiled regex built from the other context's
	 * patterns.
	 *
	 * @param array<int, array<string, mixed>> $entries Dictionary entries.
	 * @return array<string, array{regex: string, entry_map: array<string, int>}>
	 */
	private function compiled_groups_for( array $entries ): array {
		$fingerprint = md5(
			implode(
				';',
				array_map(
					static function ( array $entry ): string {
						return $entry['post_id'] . ':' . implode( ',', $entry['patterns'] );
					},
					$entries
				)
			)
		);

		if ( isset( $this->compiled_cache[ $fingerprint ] ) ) {
			return $this->compiled_cache[ $fingerprint ];
		}

		$compiled                             = $this->compile_groups( $entries );
		$this->compiled_cache[ $fingerprint ] = $compiled;

		return $compiled;
	}

	/**
	 * Compiles one alternation regex per script-class group (see
	 * char_class()), grouped by each pattern's normalized leading
	 * character. Patterns within a group are ordered longest-first so
	 * "WooCommerce Subscriptions" wins over "WooCommerce" at the same
	 * starting position. Only the latin group is case-insensitive.
	 *
	 * @param array<int, array<string, mixed>> $entries Dictionary entries.
	 * @return array<string, array{regex: string, entry_map: array<string, int>}>
	 */
	private function compile_groups( array $entries ): array {
		$groups = array(
			'latin'    => array(),
			'kanji'    => array(),
			'katakana' => array(),
			'hiragana' => array(),
			'other'    => array(),
		);

		foreach ( $entries as $entry_index => $entry ) {
			foreach ( $entry['patterns'] as $pattern ) {
				$normalized = Normalizer::normalize( $pattern );

				if ( '' === $normalized ) {
					continue;
				}

				$group = $this->pattern_group( $normalized );

				foreach ( $this->pattern_variants( $normalized ) as $variant ) {
					if ( '' === $variant ) {
						continue;
					}

					// The latin group matches case-insensitively, but the actual
					// matched substring from content (e.g. "api") can differ in
					// case from the stored pattern (e.g. "API"); fold both to the
					// same key here and in process_text_segment()'s entry_map
					// lookup so a case-variant match still resolves to its entry.
					$key = 'latin' === $group ? $this->latin_fold( $variant ) : $variant;

					if ( isset( $groups[ $group ][ $key ] ) ) {
						continue;
					}

					$groups[ $group ][ $key ] = $entry_index;
				}
			}
		}

		$compiled = array();

		foreach ( $groups as $group => $pattern_map ) {
			if ( ! $pattern_map ) {
				continue;
			}

			$patterns = array_keys( $pattern_map );

			usort(
				$patterns,
				function ( string $a, string $b ): int {
					return $this->char_length( $b ) <=> $this->char_length( $a );
				}
			);

			$quoted             = array_map(
				static function ( string $pattern ): string {
					return preg_quote( $pattern, '/' );
				},
				$patterns
			);
			$flags              = 'u' . ( 'latin' === $group ? 'i' : '' );
			$compiled[ $group ] = array(
				'regex'     => '/(?:' . implode( '|', $quoted ) . ')/' . $flags,
				'entry_map' => $pattern_map,
			);
		}

		return $compiled;
	}

	/**
	 * Case-folds a latin-group pattern/match for entry_map key comparison.
	 * The regex itself already matches case-insensitively via PCRE's Unicode
	 * case folding (the 'i' + 'u' flags together); this only needs to be
	 * consistent between compile_groups() and process_text_segment(), not a
	 * full Unicode-correct fold, so a missing mbstring extension degrades to
	 * ASCII-only folding rather than failing.
	 *
	 * @param string $text Text to fold.
	 * @return string
	 */
	private function latin_fold( string $text ): string {
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $text, 'UTF-8' ) : strtoupper( $text );
	}

	/**
	 * The script-class group a normalized pattern belongs to, based on its
	 * first character (see char_class()).
	 *
	 * @param string $normalized_pattern A Normalizer::normalize()'d pattern.
	 * @return string One of 'latin', 'kanji', 'katakana', 'hiragana', 'other'.
	 */
	private function pattern_group( string $normalized_pattern ): string {
		$chars = preg_split( '//u', $normalized_pattern, -1, PREG_SPLIT_NO_EMPTY );
		$first = false === $chars || ! $chars ? '' : $chars[0];

		return $this->char_class( $first ) ?? 'other';
	}

	/**
	 * Additional literal variants of a pattern to also match against raw
	 * HTML text nodes, which carry HTML entities as-is (e.g. a WordPress
	 * text node spells an ampersand "&amp;", not "&"). Only the ampersand is
	 * handled: the tag/text tokenizer already isolates plain text nodes, so
	 * "<" / ">" can't appear literally inside one, and quotes only need
	 * escaping inside attribute values, which text nodes aren't.
	 *
	 * @param string $normalized_pattern A Normalizer::normalize()'d pattern.
	 * @return string[]
	 */
	private function pattern_variants( string $normalized_pattern ): array {
		$variants = array( $normalized_pattern );

		if ( str_contains( $normalized_pattern, '&' ) ) {
			$variants[] = str_replace( '&', '&amp;', $normalized_pattern );
		}

		return array_unique( $variants );
	}

	/**
	 * Classifies a single character's script, for the \b-substitute
	 * boundary heuristic in docs/DESIGN-AUTOLINK.md section 3.1.
	 *
	 * @param string $char A single character (not necessarily 1 byte).
	 * @return string|null One of 'latin', 'kanji', 'katakana', 'hiragana', or null (no known class).
	 */
	private function char_class( string $char ): ?string {
		if ( '' === $char ) {
			return null;
		}

		if ( 1 === preg_match( '/[\p{Latin}\p{N}_]/u', $char ) ) {
			return 'latin';
		}

		// Script=Han/Hiragana/Katakana (rather than the bare \p{Han} etc.
		// aliases) deliberately: PCRE resolves the bare alias against each
		// character's Script_Extensions, not its primary Script, and CJK
		// punctuation shared across scripts (e.g. "。" U+3002) carries Han,
		// Hiragana, AND Katakana in its Script_Extensions — so the bare form
		// misclassifies punctuation as a letter, corrupting the boundary
		// heuristic in is_rejected_by_boundary() (verified empirically: bare
		// \p{Han} matches "。").
		if ( 1 === preg_match( '/\p{Script=Han}/u', $char ) ) {
			return 'kanji';
		}

		if ( 1 === preg_match( '/[\p{Script=Katakana}ー]/u', $char ) ) {
			return 'katakana';
		}

		if ( 1 === preg_match( '/\p{Script=Hiragana}/u', $char ) ) {
			return 'hiragana';
		}

		return null;
	}

	/**
	 * Updates the excluded-element depth stack for one tag segment.
	 *
	 * Pops leniently: a closing tag removes the most recently opened tag of
	 * the same name, tolerating mismatched/malformed nesting rather than
	 * corrupting the whole stack over one bad tag.
	 *
	 * @param string   $tag   A single `<...>` tag segment.
	 * @param string[] $stack Depth stack of open excluded element names, passed by reference.
	 */
	private function update_depth_stack( string $tag, array &$stack ): void {
		if ( 1 !== preg_match( '/^<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9-]*)/', $tag, $matches ) ) {
			return;
		}

		$name = strtolower( $matches[2] );

		if ( ! in_array( $name, self::EXCLUDED_ELEMENTS, true ) ) {
			return;
		}

		if ( '/' === $matches[1] ) {
			for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
				if ( $stack[ $i ] === $name ) {
					array_splice( $stack, $i, 1 );
					break;
				}
			}

			return;
		}

		if ( '/' === substr( rtrim( $tag, '>' ), -1 ) ) {
			return; // Self-closing (e.g. malformed "<code/>"): never opens.
		}

		$stack[] = $name;
	}

	/**
	 * Finds and replaces accepted term matches within one text segment
	 * (guaranteed by the caller to be outside any excluded element).
	 *
	 * @param string                                                             $segment    Plain-text segment.
	 * @param array<string, array{regex: string, entry_map: array<string, int>}> $compiled   Compiled match groups.
	 * @param array<int, array<string, mixed>>                                   $entries    Dictionary entries.
	 * @param array{used: array<int, bool>, count: int}                          $link_state Cross-segment first-occurrence/max-link state, passed by reference.
	 * @param int                                                                $max_links  Maximum links per post.
	 * @return string
	 */
	private function process_text_segment( string $segment, array $compiled, array $entries, array &$link_state, int $max_links ): string {
		$original_chars = preg_split( '//u', $segment, -1, PREG_SPLIT_NO_EMPTY );

		if ( false === $original_chars || ! $original_chars ) {
			return $segment;
		}

		$normalization        = $this->build_normalized_text( $original_chars );
		$normalized_text      = $normalization['text'];
		$origin_index         = $normalization['origin_index'];
		$norm_index_at_offset = $this->offset_index_map( $normalization['chars'] );
		$orig_offset_at_index = $this->char_byte_offsets( $original_chars );

		$candidates = array();

		foreach ( $compiled as $group_name => $group ) {
			$match_count = preg_match_all( $group['regex'], $normalized_text, $matches, PREG_OFFSET_CAPTURE );

			if ( false === $match_count || 0 === $match_count ) {
				continue;
			}

			foreach ( $matches[0] as $match ) {
				$matched_text = $match[0];
				$byte_offset  = (int) $match[1];
				$byte_end     = $byte_offset + strlen( $matched_text );

				if ( ! isset( $norm_index_at_offset[ $byte_offset ], $norm_index_at_offset[ $byte_end ] ) ) {
					continue;
				}

				$start_norm_index = $norm_index_at_offset[ $byte_offset ];
				$end_norm_index   = $norm_index_at_offset[ $byte_end ];

				if ( $end_norm_index <= $start_norm_index ) {
					continue;
				}

				$orig_start_index = $origin_index[ $start_norm_index ] ?? null;
				$orig_end_index   = $origin_index[ $end_norm_index - 1 ] ?? null;
				$lookup_key       = 'latin' === $group_name ? $this->latin_fold( $matched_text ) : $matched_text;
				$entry_index      = $group['entry_map'][ $lookup_key ] ?? null;

				if ( null === $orig_start_index || null === $orig_end_index || null === $entry_index || ! isset( $entries[ $entry_index ] ) ) {
					continue;
				}

				$candidates[] = array(
					'start'   => $orig_start_index,
					'end'     => $orig_end_index + 1,
					'entry'   => $entry_index,
					'pattern' => $matched_text,
				);
			}
		}

		if ( ! $candidates ) {
			return $segment;
		}

		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				$length_a = $a['end'] - $a['start'];
				$length_b = $b['end'] - $b['start'];

				return $length_a === $length_b ? $a['start'] <=> $b['start'] : $length_b <=> $length_a;
			}
		);

		$claimed  = array();
		$accepted = array();

		foreach ( $candidates as $candidate ) {
			if ( $this->overlaps_claimed( $candidate['start'], $candidate['end'], $claimed ) ) {
				continue;
			}

			$entry             = $entries[ $candidate['entry'] ];
			$boundary_rejected = $this->is_rejected_by_boundary( $original_chars, $candidate['start'], $candidate['end'] );

			/**
			 * Filters the script-boundary heuristic's accept/reject decision for one match.
			 *
			 * @since 0.1.0
			 *
			 * @param bool                 $rejected Whether the boundary heuristic rejected this match.
			 * @param array<string, mixed> $match    [ 'pattern', 'text', 'offset', 'before_char', 'after_char' ].
			 */
			$rejected = apply_filters(
				'saai_autolink_match_rejected',
				$boundary_rejected,
				array(
					'pattern'     => $candidate['pattern'],
					'text'        => implode( '', array_slice( $original_chars, $candidate['start'], $candidate['end'] - $candidate['start'] ) ),
					'offset'      => $orig_offset_at_index[ $candidate['start'] ] ?? 0,
					'before_char' => $candidate['start'] > 0 ? ( $original_chars[ $candidate['start'] - 1 ] ?? '' ) : '',
					'after_char'  => $original_chars[ $candidate['end'] ] ?? '',
				)
			);

			if ( $rejected ) {
				continue;
			}

			if ( isset( $link_state['used'][ $entry['post_id'] ] ) ) {
				continue;
			}

			if ( $link_state['count'] >= $max_links ) {
				break;
			}

			$claimed[]  = array(
				'start' => $candidate['start'],
				'end'   => $candidate['end'],
			);
			$accepted[] = array(
				'start' => $candidate['start'],
				'end'   => $candidate['end'],
				'entry' => $entry,
			);

			$link_state['used'][ $entry['post_id'] ] = true;
			++$link_state['count'];
		}

		if ( ! $accepted ) {
			return $segment;
		}

		usort(
			$accepted,
			static function ( array $a, array $b ): int {
				return $b['start'] <=> $a['start'];
			}
		);

		$result = $segment;

		foreach ( $accepted as $match ) {
			$byte_start   = $orig_offset_at_index[ $match['start'] ];
			$byte_end     = $orig_offset_at_index[ $match['end'] ] ?? strlen( $segment );
			$matched_text = implode( '', array_slice( $original_chars, $match['start'], $match['end'] - $match['start'] ) );
			$anchor       = $this->build_anchor( $match['entry'], $matched_text );
			$result       = substr_replace( $result, $anchor, $byte_start, $byte_end - $byte_start );
		}

		return $result;
	}

	/**
	 * Whether a candidate match's character range overlaps one already
	 * claimed by an accepted match in the same segment.
	 *
	 * @param int                                     $start   Start char index (inclusive).
	 * @param int                                     $end     End char index (exclusive).
	 * @param array<int, array{start: int, end: int}> $claimed Already-claimed ranges.
	 * @return bool
	 */
	private function overlaps_claimed( int $start, int $end, array $claimed ): bool {
		foreach ( $claimed as $range ) {
			if ( $start < $range['end'] && $end > $range['start'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Applies the script-boundary heuristic (docs/DESIGN-AUTOLINK.md section 3.1)
	 * to both edges of a candidate match.
	 *
	 * @param string[] $chars       The segment's characters.
	 * @param int      $start_index Match start char index (inclusive).
	 * @param int      $end_index   Match end char index (exclusive).
	 * @return bool
	 */
	private function is_rejected_by_boundary( array $chars, int $start_index, int $end_index ): bool {
		$first  = $chars[ $start_index ] ?? null;
		$last   = $chars[ $end_index - 1 ] ?? null;
		$before = $start_index > 0 ? ( $chars[ $start_index - 1 ] ?? null ) : null;
		$after  = $chars[ $end_index ] ?? null;

		return $this->edge_rejected( $first, $before ) || $this->edge_rejected( $last, $after );
	}

	/**
	 * Whether one edge of a match is rejected: its own character and the
	 * character just outside the match share the same script class, and
	 * that class isn't hiragana (hiragana edges are never rejected — see
	 * docs/DESIGN-AUTOLINK.md section 3.1, rule 4).
	 *
	 * @param string|null $edge_char The match's own edge character.
	 * @param string|null $neighbor  The character immediately outside the match, if any.
	 * @return bool
	 */
	private function edge_rejected( ?string $edge_char, ?string $neighbor ): bool {
		if ( null === $edge_char || null === $neighbor ) {
			return false;
		}

		$edge_class = $this->char_class( $edge_char );

		if ( null === $edge_class || 'hiragana' === $edge_class ) {
			return false;
		}

		return $this->char_class( $neighbor ) === $edge_class;
	}

	/**
	 * Builds the auto-link anchor markup for one accepted match.
	 *
	 * The tooltip is rendered by the saai-knowledge/tooltip Interactivity API
	 * store (M3-4): the excerpt travels in data-saai-tooltip so the
	 * singleton tooltip element can be populated without a JSON script tag.
	 * data-wp-on--touchstart marks the anchor as mid-tap before the
	 * synthesized click arrives (some mobile browsers fire mouseenter/focus
	 * for the same tap, which would otherwise make the tooltip look already
	 * shown by the time data-wp-on--click runs); data-wp-on--click uses that
	 * mark to intercept only a touch device's first tap (revealing the
	 * tooltip instead of navigating) and lets a second tap navigate
	 * normally, while mouse/keyboard clicks (no preceding touchstart) always
	 * navigate immediately. data-wp-init attaches the store's single
	 * document-level Escape-to-close listener the first time any term link
	 * on the page hydrates.
	 *
	 * TOOLTIP_INTERACTIVE_MARKER names the store this markup's directives
	 * target; see its own docblock for why that constant is only a
	 * readability aid here, not something the other two independent copies
	 * of the same store id (Tooltip::MODULE_ID, view.js's store() call)
	 * actually stay in sync with. has_rendered_links() is tracked
	 * separately from real replace_in_html() link counts — see process().
	 *
	 * @param array<string, mixed> $entry        The matched dictionary entry.
	 * @param string               $matched_text The original text to keep as the link's visible text.
	 * @return string
	 */
	private function build_anchor( array $entry, string $matched_text ): string {
		return sprintf(
			'<a href="%1$s" class="saai-term" %5$s data-wp-init="callbacks.initTooltipListeners" data-wp-on--mouseenter="actions.show" data-wp-on--focus="actions.show" data-wp-on--mouseleave="actions.hide" data-wp-on--blur="actions.hide" data-wp-on--touchstart="actions.handleTouchStart" data-wp-on--click="actions.handleClick" data-saai-term-id="%2$d" data-saai-tooltip="%3$s" aria-describedby="saai-tooltip">%4$s</a>',
			esc_url( $entry['url'] ),
			(int) $entry['post_id'],
			esc_attr( $entry['excerpt'] ),
			$matched_text,
			self::TOOLTIP_INTERACTIVE_MARKER
		);
	}

	/**
	 * Replaces `<script>`, `<style>`, and comment blocks with single-token
	 * placeholders before the tag/text walk, so their raw contents are never
	 * treated as scannable text or split by the tag-boundary regex.
	 *
	 * @param string                $html    HTML to stash.
	 * @param array<string, string> $stashed Placeholder token => original text, passed by reference.
	 * @return string
	 */
	private function stash_raw_blocks( string $html, array &$stashed ): string {
		$result = preg_replace_callback(
			'/<script\b[^>]*>.*?<\/script\s*>|<style\b[^>]*>.*?<\/style\s*>|<!--.*?-->/uis',
			static function ( array $matches ) use ( &$stashed ): string {
				$token = "\x01SAAI" . count( $stashed ) . "\x02";

				$stashed[ $token ] = $matches[0];

				return $token;
			},
			$html
		);

		return null === $result ? $html : $result;
	}

	/**
	 * Restores stash_raw_blocks() placeholders.
	 *
	 * @param string                $html    HTML containing placeholder tokens.
	 * @param array<string, string> $stashed Placeholder token => original text.
	 * @return string
	 */
	private function unstash( string $html, array $stashed ): string {
		return $stashed ? strtr( $html, $stashed ) : $html;
	}

	/**
	 * Builds a normalized copy of a segment's characters for matching,
	 * along with a map back to the original character each normalized
	 * character came from.
	 *
	 * Normalizing character-by-character (rather than the whole segment at
	 * once) guarantees this origin map stays correct even when
	 * Normalizer::normalize() expands one input character into more than
	 * one output character (e.g. some compatibility ideographs decompose
	 * under NFKC) — every output character it produces for input character
	 * N is recorded as coming from N, however many there are.
	 *
	 * @param string[] $original_chars The segment's original characters.
	 * @return array{text: string, chars: string[], origin_index: array<int, int>}
	 */
	private function build_normalized_text( array $original_chars ): array {
		$normalized_chars = array();
		$origin_index     = array();

		foreach ( $original_chars as $i => $char ) {
			$normalized = $this->normalize_char( $char );
			$pieces     = '' === $normalized ? array( $char ) : preg_split( '//u', $normalized, -1, PREG_SPLIT_NO_EMPTY );

			if ( false === $pieces || ! $pieces ) {
				$pieces = array( $char );
			}

			foreach ( $pieces as $piece ) {
				$normalized_chars[] = $piece;
				$origin_index[]     = $i;
			}
		}

		return array(
			'text'         => implode( '', $normalized_chars ),
			'chars'        => $normalized_chars,
			'origin_index' => $origin_index,
		);
	}

	/**
	 * Normalizer::normalize() for a single character, memoized per instance.
	 *
	 * @param string $char A single character.
	 * @return string
	 */
	private function normalize_char( string $char ): string {
		if ( isset( $this->char_normalization_cache[ $char ] ) ) {
			return $this->char_normalization_cache[ $char ];
		}

		$normalized = Normalizer::normalize( $char );

		$this->char_normalization_cache[ $char ] = $normalized;

		return $normalized;
	}

	/**
	 * Byte offset of each character in an ordered character list, plus a
	 * sentinel entry (at the array's character count) for the offset just
	 * past the last character.
	 *
	 * @param string[] $chars Ordered characters.
	 * @return array<int, int>
	 */
	private function char_byte_offsets( array $chars ): array {
		$offsets = array();
		$offset  = 0;

		foreach ( $chars as $i => $char ) {
			$offsets[ $i ] = $offset;
			$offset       += strlen( $char );
		}

		$offsets[ count( $chars ) ] = $offset;

		return $offsets;
	}

	/**
	 * Reverse of char_byte_offsets(): character index at each byte offset,
	 * plus a sentinel for the offset just past the last character.
	 *
	 * @param string[] $chars Ordered characters.
	 * @return array<int, int>
	 */
	private function offset_index_map( array $chars ): array {
		$map    = array();
		$offset = 0;

		foreach ( $chars as $i => $char ) {
			$map[ $offset ] = $i;
			$offset        += strlen( $char );
		}

		$map[ $offset ] = count( $chars );

		return $map;
	}

	/**
	 * Character count of a string (not byte length).
	 *
	 * @param string $text Text to measure.
	 * @return int
	 */
	private function char_length( string $text ): int {
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		return false === $chars ? strlen( $text ) : count( $chars );
	}

	/**
	 * Logs a preg failure that triggered the fail-safe original-content
	 * return (docs/DESIGN-AUTOLINK.md section 5).
	 */
	private function log_preg_error(): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate diagnostic for the documented preg fail-safe path; content is never lost, only unlinked.
		error_log( sprintf( '[saai-knowledge] Autolinker preg failure (code %d); original content returned unchanged.', preg_last_error() ) );
	}
}
