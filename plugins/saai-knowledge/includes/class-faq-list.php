<?php
/**
 * Builds the FAQ list (query, grouping, accordion markup, FAQPage JSON-LD)
 * shared by the faq-list block and the [saai_faq] shortcode.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves published saai_faq entries for the faq-list block and renders them
 * as a core Accordion block (WP 6.9) composition, plus the schema.org FAQPage
 * representation.
 */
final class Faq_List {

	/**
	 * Default block attributes, mirroring the faq-list block.json.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(
		'category'        => '',
		'count'           => 0,
		'orderBy'         => 'date',
		'order'           => 'desc',
		'groupByCategory' => false,
	);

	/**
	 * Whether a faq-list render is currently in progress — checked by the
	 * block's render.php so a faq-list block (or [saai_faq] shortcode) nested
	 * inside an FAQ answer, or rendered from a saai_faq_before_list /
	 * saai_faq_after_list callback, can't recurse forever through the
	 * answer-rendering do_blocks()/do_shortcode() calls or the insertion
	 * hooks themselves.
	 *
	 * @var bool
	 */
	private static $rendering = false;

	/**
	 * The render signature (normalized attributes + context post, see
	 * structured_data_signature()) of the faq-list block that has claimed
	 * this request's single FAQPage JSON-LD slot, or null while unclaimed.
	 * Google's guidance is one FAQPage per page, so when several distinct
	 * faq-list blocks render on the same page only the first one emits the
	 * structured data.
	 *
	 * A signature rather than a boolean: a theme or SEO plugin can render
	 * post content speculatively (excerpt generation, metadata analysis)
	 * before the visible template pass, and that discarded render must not
	 * permanently consume the slot — the later, visible render of the same
	 * block re-presents the same signature and is allowed to emit again.
	 * WordPress offers no way to tell a speculative render from a visible
	 * one, so the accepted residual trade-off is that the same block placed
	 * twice on one page — same attributes AND same context post — emits its
	 * identical schema twice, which is harmless next to the alternative of a
	 * page losing its FAQPage data entirely.
	 *
	 * @var string|null
	 */
	private static $structured_data_signature = null;

	/**
	 * The globals WP_Query::setup_postdata() mutates besides $post —
	 * snapshotted and restored around each answer render so state from the
	 * last FAQ can't leak into whatever renders afterward, even when there
	 * was no prior global post to re-establish via setup_postdata().
	 *
	 * @var string[]
	 */
	private const POSTDATA_GLOBALS = array(
		'id',
		'authordata',
		'currentday',
		'currentmonth',
		'page',
		'pages',
		'multipage',
		'more',
		'numpages',
	);

	/**
	 * Transient key prefix for a single FAQ's cached rendered answer — see
	 * answer_cache_key(). Unlike Markdown_Output's equivalent, this pipeline
	 * never applies the_content (see render_answer()'s docblock), so the key
	 * doesn't need to fold in the autolink dictionary generation.
	 *
	 * @var string
	 */
	private const ANSWER_CACHE_PREFIX = 'saai_faq_answer_';

	/**
	 * Hooks per-request state resets and answer-cache invalidation into
	 * WordPress.
	 */
	public function register(): void {
		// A real HTTP request is a fresh PHP process, but a single
		// long-running script rendering more than one page in the same
		// process (a WP-CLI export tool, or this test suite itself) reuses
		// these statics across each one — without resetting per main query,
		// only the first page rendered would ever emit FAQPage JSON-LD. Same
		// reasoning as Template_Loader::reset_article_content_hooks_state().
		add_action( 'pre_get_posts', array( $this, 'reset_render_state' ) );

		add_action( 'save_post_saai_faq', array( $this, 'flush_answer_cache' ) );
		add_action( 'trashed_post', array( $this, 'flush_answer_cache' ) );
		// wp_delete_post( $id, true ) (REST's force=true, `wp post delete
		// --force`) skips wp_trash_post() entirely, so trashed_post never
		// fires — deleted_post is needed too (same reasoning as
		// Markdown_Output::register()).
		add_action( 'deleted_post', array( $this, 'flush_answer_cache' ) );
	}

	/**
	 * Deletes one FAQ's cached rendered answer. Hooked to save/trash/delete
	 * of saai_faq (see register()); a stale cache otherwise only self-heals
	 * after ANSWER_CACHE_TTL.
	 *
	 * @param int $post_id The FAQ whose cache entry to clear.
	 */
	public function flush_answer_cache( int $post_id ): void {
		delete_transient( self::answer_cache_key( $post_id ) );
	}

	/**
	 * The transient key for one FAQ's cached rendered answer — shared by
	 * render_answer() and flush_answer_cache() so they can never drift apart.
	 *
	 * @param int $post_id FAQ post ID.
	 * @return string
	 */
	private static function answer_cache_key( int $post_id ): string {
		return self::ANSWER_CACHE_PREFIX . $post_id;
	}

	/**
	 * Resets the per-request render state for each new main query.
	 *
	 * @param \WP_Query $query The query WordPress is about to run.
	 */
	public function reset_render_state( \WP_Query $query ): void {
		if ( ! $query->is_main_query() ) {
			return;
		}

		self::reset_state();
	}

	/**
	 * Resets all static render state directly — the reset_render_state()
	 * internals, exposed for tests that exercise the request-scoped guards
	 * without running a main query.
	 */
	public static function reset_state(): void {
		self::$rendering                 = false;
		self::$structured_data_signature = null;
	}

	/**
	 * Whether a faq-list render is currently in progress — see $rendering.
	 *
	 * @return bool
	 */
	public static function is_rendering(): bool {
		return self::$rendering;
	}

	/**
	 * Marks a faq-list render as in progress. Callers must pair this with
	 * finish_render() (in a finally block) around any code that can render
	 * nested blocks or shortcodes.
	 */
	public static function begin_render(): void {
		self::$rendering = true;
	}

	/**
	 * Marks the current faq-list render as finished.
	 */
	public static function finish_render(): void {
		self::$rendering = false;
	}

	/**
	 * A deterministic signature for a block render — the claim key for the
	 * FAQPage JSON-LD slot (see claim_structured_data_slot()).
	 *
	 * Built from the normalized attributes plus the render's context post:
	 * a saai_faq_query_args callback can produce context-dependent results
	 * for otherwise identical attributes (e.g. the same block rendered for
	 * different Query Loop items), and folding the context post into the
	 * signature keeps such renders from reclaiming each other's slot and
	 * emitting conflicting schema — only the first one wins.
	 *
	 * Even after its own invalid-UTF-8 sanitization retry, wp_json_encode()
	 * can still return false; a bare (string) cast of that would collapse
	 * every failing block to the same empty signature, letting unrelated
	 * blocks share the slot and all emit JSON-LD. The serialize() fallback is
	 * binary-safe and deterministic for this fixed scalar attribute set.
	 *
	 * @param array<string, mixed> $attrs        Block attributes (raw or normalized).
	 * @param \WP_Post|null        $context_post The render's structured-data context post, if any.
	 * @return string
	 */
	public function structured_data_signature( array $attrs, ?\WP_Post $context_post = null ): string {
		$attrs   = $this->normalize( $attrs );
		$suffix  = '|' . ( $context_post instanceof \WP_Post ? $context_post->ID : 0 );
		$encoded = wp_json_encode( $attrs );

		if ( is_string( $encoded ) ) {
			return $encoded . $suffix;
		}

		return serialize( $attrs ) . $suffix; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- deterministic, binary-safe fallback claim key; never unserialized or output.
	}

	/**
	 * Claims the request's single FAQPage JSON-LD slot — see
	 * $structured_data_signature for why a matching signature may reclaim it.
	 *
	 * @param string $signature The claiming block's normalized-attribute signature.
	 * @return bool Whether the caller may emit the structured data.
	 */
	public static function claim_structured_data_slot( string $signature ): bool {
		if ( null === self::$structured_data_signature ) {
			self::$structured_data_signature = $signature;

			return true;
		}

		return self::$structured_data_signature === $signature;
	}

	/**
	 * Normalizes raw block attributes against the block's defaults.
	 *
	 * @param array<string, mixed> $attrs Raw block attributes.
	 * @return array<string, mixed> Normalized attributes, all DEFAULTS keys present.
	 */
	public function normalize( array $attrs ): array {
		$attrs = array_merge( self::DEFAULTS, array_intersect_key( $attrs, self::DEFAULTS ) );

		$attrs['category'] = is_scalar( $attrs['category'] ) ? (string) $attrs['category'] : '';
		$attrs['count']    = max( 0, (int) $attrs['count'] );

		if ( ! in_array( $attrs['orderBy'], array( 'date', 'title' ), true ) ) {
			$attrs['orderBy'] = self::DEFAULTS['orderBy'];
		}

		$attrs['order'] = is_scalar( $attrs['order'] ) ? strtolower( (string) $attrs['order'] ) : '';

		if ( ! in_array( $attrs['order'], array( 'asc', 'desc' ), true ) ) {
			$attrs['order'] = self::DEFAULTS['order'];
		}

		$attrs['groupByCategory'] = (bool) $attrs['groupByCategory'];

		return $attrs;
	}

	/**
	 * Builds the WP_Query arguments for a set of block attributes.
	 *
	 * @param array<string, mixed> $attrs Block attributes (raw or normalized).
	 * @return array<string, mixed>
	 */
	public function query_args( array $attrs ): array {
		$attrs = $this->normalize( $attrs );

		$args = array(
			'post_type'           => 'saai_faq',
			'post_status'         => 'publish',
			'has_password'        => false,
			'posts_per_page'      => $attrs['count'] > 0 ? $attrs['count'] : -1,
			'orderby'             => $attrs['orderBy'],
			'order'               => 'asc' === $attrs['order'] ? 'ASC' : 'DESC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		);

		if ( '' !== $attrs['category'] ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- filtering FAQs by category is the block's documented purpose.
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'saai_category',
					'field'    => 'slug',
					'terms'    => $attrs['category'],
				),
			);
		}

		/**
		 * Filters the faq-list block's WP_Query arguments.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $args  The WP_Query arguments.
		 * @param array<string, mixed> $attrs The normalized block attributes.
		 */
		$filtered_args = apply_filters( 'saai_faq_query_args', $args, $attrs );

		// @phpstan-ignore ternary.elseUnreachable (PHPStan trusts the docblock @param type above, but a third-party saai_faq_query_args callback can violate it at runtime.)
		return is_array( $filtered_args ) ? $filtered_args : $args;
	}

	/**
	 * The FAQ entries matching a set of block attributes.
	 *
	 * @param array<string, mixed> $attrs Block attributes (raw or normalized).
	 * @return array<int, array<string, mixed>> Items shaped [ 'id' => int, 'question' => string, 'answer' => string (rendered HTML) ].
	 */
	public function items( array $attrs ): array {
		$query = new \WP_Query( $this->query_args( $attrs ) );
		$items = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				// A saai_faq_query_args callback set a 'fields' argument, so
				// there's no post object to build an item from; skip it.
				continue;
			}

			$items[] = array(
				'id'       => $post->ID,
				'question' => get_the_title( $post ),
				'answer'   => $this->render_answer( $post ),
			);
		}

		return $items;
	}

	/**
	 * The FAQ entries grouped by their primary saai_category term.
	 *
	 * Groups are ordered like the kb-sidebar tree (saai_order term meta, then
	 * name, then term_id); entries without a category collect in a final
	 * fallback group. When that fallback is the only group, its title is left
	 * empty — a lone "Other" heading over the whole list would be noise.
	 *
	 * @param array<string, mixed> $attrs Block attributes (raw or normalized).
	 * @return array<int, array<string, mixed>> Groups shaped [ 'term' => \WP_Term|null, 'title' => string, 'items' => item[] ].
	 */
	public function grouped_items( array $attrs ): array {
		$groups = array();

		foreach ( $this->items( $attrs ) as $item ) {
			$term = $this->primary_term( $item['id'] );
			$key  = $term instanceof \WP_Term ? $term->term_id : 0;

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'term'  => $term,
					'title' => $term instanceof \WP_Term ? $term->name : __( 'Other', 'saai-knowledge' ),
					'items' => array(),
				);
			}

			$groups[ $key ]['items'][] = $item;
		}

		if ( 1 === count( $groups ) && isset( $groups[0] ) ) {
			$groups[0]['title'] = '';
		}

		$groups = array_values( $groups );

		usort(
			$groups,
			function ( array $a, array $b ): int {
				$term_a = $a['term'];
				$term_b = $b['term'];

				// The uncategorized fallback group always sorts last.
				if ( ! $term_a instanceof \WP_Term || ! $term_b instanceof \WP_Term ) {
					return ( $term_a instanceof \WP_Term ? 0 : 1 ) <=> ( $term_b instanceof \WP_Term ? 0 : 1 );
				}

				return $this->compare_terms( $term_a, $term_b );
			}
		);

		return $groups;
	}

	/**
	 * Renders a list of FAQ items as a core Accordion block composition.
	 *
	 * The issue's design decision (docs/DESIGN.md section 4.2) is to reuse
	 * the core Accordion block's markup, styles, and Interactivity API view
	 * module rather than building a custom accordion: this assembles the same
	 * serialized markup the editor would save for core/accordion and runs it
	 * through do_blocks(), so core's own render callbacks attach the
	 * interactivity directives and enqueue the assets. The panel content is
	 * pre-rendered HTML (see render_answer()), which the block parser passes
	 * through untouched. All panels render closed but fully server-side — the
	 * answers are complete in the initial HTML and JS only handles toggling.
	 *
	 * @param array<int, array<string, mixed>> $items Items, see items().
	 * @return string
	 */
	public function accordion( array $items ): string {
		$inner = '';

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$question = $item['question'] ?? '';

			// Not empty(): an FAQ legitimately titled "0" must not be dropped.
			if ( ! is_scalar( $question ) || '' === (string) $question ) {
				continue;
			}

			$answer = $item['answer'] ?? '';
			$answer = is_scalar( $answer ) ? (string) $answer : '';

			$inner .= sprintf(
				'<!-- wp:accordion-item --><div class="wp-block-accordion-item">' .
					'<!-- wp:accordion-heading --><h3 class="wp-block-accordion-heading has-icon has-icon-right">' .
						'<button type="button" class="wp-block-accordion-heading__toggle">' .
							'<span class="wp-block-accordion-heading__toggle-title">%1$s</span>' .
							'<span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">+</span>' .
						'</button>' .
					'</h3><!-- /wp:accordion-heading -->' .
					'<!-- wp:accordion-panel --><div class="wp-block-accordion-panel" role="region">%2$s</div><!-- /wp:accordion-panel -->' .
				'</div><!-- /wp:accordion-item -->',
				esc_html( (string) $question ),
				$answer
			);
		}

		if ( '' === $inner ) {
			return '';
		}

		return do_blocks(
			'<!-- wp:accordion --><div class="wp-block-accordion" role="group">' . $inner . '</div><!-- /wp:accordion -->'
		);
	}

	/**
	 * Builds the schema.org FAQPage for a list of FAQ items.
	 *
	 * @param array<int, array<string, mixed>> $items Items, see items().
	 * @param \WP_Post|null                    $post  The current post, if any.
	 * @return array<string, mixed>
	 */
	public function json_ld( array $items, ?\WP_Post $post = null ): array {
		$questions = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$question = $item['question'] ?? '';

			// Not empty(): an FAQ legitimately titled "0" must not be dropped.
			if ( ! is_scalar( $question ) || '' === (string) $question ) {
				continue;
			}

			$answer = $item['answer'] ?? '';
			$answer = is_scalar( $answer ) ? (string) $answer : '';

			$questions[] = array(
				'@type'          => 'Question',
				// The question text arrives via get_the_title(), whose the_title
				// filters encode characters as HTML references (& → &#038;,
				// ' → &#8217;). JSON-LD script contents are never HTML-entity-
				// decoded by consumers, so decode to plain text after stripping
				// tags.
				'name'           => html_entity_decode( wp_strip_all_tags( (string) $question ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ),
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					// Google's FAQPage guidelines allow a limited HTML subset
					// in the answer text, so the rendered markup is kept.
					'text'  => $answer,
				),
			);
		}

		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $questions,
		);

		/** This filter is documented in includes/class-breadcrumbs.php */
		$filtered_schema = apply_filters( 'saai_structured_data', $schema, 'faq-page', $post );

		// A third-party saai_structured_data callback can return a non-array at runtime.
		return is_array( $filtered_schema ) ? $filtered_schema : $schema;
	}

	/**
	 * Whether structured data output is enabled in settings.
	 *
	 * Reads the `structured_data` key of the `saai_knowledge_settings` option
	 * documented in docs/DESIGN.md section 3.4; defaults to enabled since the
	 * settings screen (M4) doesn't exist yet to have written a value. Same
	 * logic as Breadcrumbs::structured_data_enabled() — M4's settings service
	 * will centralize this.
	 *
	 * @return bool
	 */
	public function structured_data_enabled(): bool {
		$settings = get_option( 'saai_knowledge_settings' );

		if ( ! is_array( $settings ) || ! array_key_exists( 'structured_data', $settings ) ) {
			return true;
		}

		return (bool) $settings['structured_data'];
	}

	/**
	 * Returns an FAQ answer's cached rendered body, rendering and caching it
	 * on a miss. The answer only changes when the FAQ post itself is
	 * saved/trashed/deleted (register() hooks flush_answer_cache() to all
	 * three), so — unlike a page-scoped cache — this is safe to share across
	 * every faq-list block/[saai_faq] shortcode render on the site, and turns
	 * an O(FAQ count) content-pipeline cost per page view into a one-time
	 * cost per FAQ (perf review).
	 *
	 * That sharing is only safe for a render that doesn't itself vary by
	 * viewer: render_answer_uncached() runs the answer's post_content through
	 * do_blocks()/do_shortcode(), core's own the_content machinery, which can
	 * legitimately contain a shortcode/block whose output varies by viewer —
	 * not just by login state, but by anything a visitor's own cookies drive
	 * (a cart, a language switcher, a geo/currency preference). Caching and
	 * replaying one such visitor's render to every other visitor for up to a
	 * day would leak whatever that render exposed, and a cache hit skips
	 * whatever asset-enqueue side effects that shortcode/block would
	 * otherwise perform on every render. A request carrying *any* cookie at
	 * all — logged in or not — therefore bypasses this cache entirely, both
	 * reading and writing it: the same heuristic Markdown_Output::render_cached()
	 * already uses for the identical risk, and the one full-page-cache
	 * plugins (WP Super Cache et al.) use to decide a request is safe to
	 * serve from a shared cache (Codex review).
	 *
	 * @param \WP_Post $post The FAQ entry.
	 * @return string
	 */
	private function render_answer( \WP_Post $post ): string {
		if ( ! empty( $_COOKIE ) ) {
			return $this->render_answer_uncached( $post );
		}

		$key    = self::answer_cache_key( $post->ID );
		$cached = get_transient( $key );

		if ( is_string( $cached ) ) {
			return $cached;
		}

		$html = $this->render_answer_uncached( $post );

		set_transient( $key, $html, DAY_IN_SECONDS );

		return $html;
	}

	/**
	 * Renders an FAQ answer body.
	 *
	 * Applies the standard content transforms directly instead of the full
	 * the_content filter chain: this runs while some other content is already
	 * mid-render (the page containing the block), and re-entering the_content
	 * would re-fire every third-party filter attached to it for a different
	 * post than they expect.
	 *
	 * The FAQ entry is made the current post for the duration of the render:
	 * shortcodes and dynamic blocks inside the answer can read the global
	 * $post directly (or, for blocks, receive it as render_block()'s default
	 * postId context), and at this point it still belongs to whatever page
	 * contains the faq-list block — not this FAQ. The complete previous
	 * postdata state (POSTDATA_GLOBALS, not just $post) is snapshotted and
	 * restored afterward so the containing page's own render continues
	 * unaffected — including when there was no prior global post at all.
	 *
	 * @param \WP_Post $post The FAQ entry.
	 * @return string
	 */
	private function render_answer_uncached( \WP_Post $post ): string {
		$previous_post    = $GLOBALS['post'] ?? null;
		$previous_globals = array();

		foreach ( self::POSTDATA_GLOBALS as $var ) {
			$previous_globals[ $var ] = $GLOBALS[ $var ] ?? null;
		}

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberately scoping the FAQ as the current post for its own answer render; restored in the finally block.
		setup_postdata( $post );

		try {
			$content = (string) $post->post_content;

			if ( has_blocks( $content ) ) {
				$html = wptexturize( do_blocks( $content ) );
			} else {
				// Core runs WP_Embed's handlers on classic content ahead of
				// the standard transforms (priority 8 vs 10 on the_content):
				// [embed] shortcodes and bare oEmbed URLs on their own line
				// must become embeds before wpautop wraps them in paragraphs.
				// Block content needs neither — embeds there are core-embed
				// blocks, already handled by do_blocks(). The current post is
				// already this FAQ (see above), so WP_Embed's oEmbed response
				// caching lands on the FAQ's own meta.
				$wp_embed = $GLOBALS['wp_embed'] ?? null;

				if ( $wp_embed instanceof \WP_Embed ) {
					$content = $wp_embed->run_shortcode( $content );
					$content = $wp_embed->autoembed( $content );
				}

				$html = wpautop( wptexturize( $content ) );
			}

			$html = do_shortcode( shortcode_unautop( $html ) );

			// the_content's own tail end (priority 12, after do_shortcode at
			// 11): responsive srcset/sizes and loading/decoding attributes
			// for images in both block and classic answers.
			return wp_filter_content_tags( $html, 'the_content' );
		} finally {
			$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the exact pre-render value saved above.

			foreach ( $previous_globals as $var => $value ) {
				$GLOBALS[ $var ] = $value; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- restoring the exact pre-render values of WordPress's own postdata globals saved above.
			}
		}
	}

	/**
	 * The saai_category term an FAQ entry is grouped under.
	 *
	 * Entries can carry more than one saai_category term; the first in
	 * display order (saai_order term meta, then name, then term_id — same
	 * ordering as Breadcrumbs::primary_term()) is chosen.
	 *
	 * @param int $post_id FAQ entry ID.
	 * @return \WP_Term|null
	 */
	private function primary_term( int $post_id ): ?\WP_Term {
		$terms = get_the_terms( $post_id, 'saai_category' );

		if ( ! is_array( $terms ) || ! $terms ) {
			return null;
		}

		usort( $terms, array( $this, 'compare_terms' ) );

		return $terms[0];
	}

	/**
	 * Compares two saai_category terms by display order: saai_order term
	 * meta, then name, then term_id as a final deterministic tie-break.
	 *
	 * @param \WP_Term $a First term.
	 * @param \WP_Term $b Second term.
	 * @return int
	 */
	private function compare_terms( \WP_Term $a, \WP_Term $b ): int {
		$order_a = (int) get_term_meta( $a->term_id, 'saai_order', true );
		$order_b = (int) get_term_meta( $b->term_id, 'saai_order', true );

		if ( $order_a !== $order_b ) {
			return $order_a <=> $order_b;
		}

		$by_name = strcasecmp( $a->name, $b->name );

		if ( 0 !== $by_name ) {
			return $by_name;
		}

		return $a->term_id <=> $b->term_id;
	}
}
