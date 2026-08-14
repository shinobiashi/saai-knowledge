<?php
/**
 * Single glossary term page support: DefinedTerm JSON-LD and the
 * saai_glossary_after_definition insertion point.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Emits the schema.org DefinedTerm for a saai_glossary singular view and
 * fires saai_glossary_after_definition (docs/DESIGN-HOOKS-API.md section 4)
 * after its content.
 */
final class Glossary_Term {

	/**
	 * Whether append_after_definition_hook() has already fired for the
	 * current main query's Loop pass — see that method's docblock.
	 *
	 * Static, not per-instance: Plugin::register_services() constructs one
	 * Glossary_Term and registers its hooks once for the process's lifetime,
	 * but a test (or any other code building its own instance to call
	 * register() again) would otherwise add a second, independent the_content
	 * filter callback — same double-registration hazard
	 * Faq_List::$rendering avoids by being static rather than per-instance.
	 *
	 * @var bool
	 */
	private static $hook_fired = false;

	/**
	 * Whether fire_after_definition_hook_for_block_theme() has already fired
	 * for the current main query's pass — separate from $hook_fired because
	 * the two run on different hooks (the_content vs. render_block_core/post-content)
	 * and, per append_after_definition_hook()'s $post_content_render_depth
	 * guard, only one of them ever actually fires the action for a given
	 * request.
	 *
	 * @var bool
	 */
	private static $block_hook_fired = false;

	/**
	 * Tracks nested core/post-content block renders — same purpose and
	 * reasoning as Template_Loader::$post_content_render_depth: it lets
	 * fire_after_definition_hook_for_block_theme() (on render_block_core/post-content)
	 * tell whether the render it's looking at is the outermost core/post-content
	 * currently unwinding, and lets append_after_definition_hook() (on
	 * the_content) tell whether it's firing as a side effect of that same
	 * block's render callback rather than a classic theme's Loop.
	 *
	 * @var int
	 */
	private $post_content_render_depth = 0;

	/**
	 * Tracks whether a core/post-template (Query Loop) block is currently
	 * mid-render — same purpose as Template_Loader::$post_template_render_depth:
	 * rejects a core/post-content encountered while iterating a Query Loop
	 * (e.g. a "related terms" section embedding the very term being viewed),
	 * which post_content_render_depth's outermost-render check alone can't
	 * distinguish from the primary term body.
	 *
	 * @var int
	 */
	private $post_template_render_depth = 0;

	/**
	 * Hooks term-page rendering into WordPress.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'output_structured_data' ) );
		add_filter( 'the_content', array( $this, 'append_after_definition_hook' ), PHP_INT_MAX );
		// A real HTTP request is a fresh PHP process, so $hook_fired starts
		// false naturally, but a single long-running script rendering more
		// than one glossary term in the same process (a WP-CLI export tool,
		// or this test suite itself) reuses this instance across each one —
		// same reasoning as Faq_List::reset_render_state().
		add_action( 'pre_get_posts', array( $this, 'reset_hook_state' ) );
		// Block themes render the term body via core/post-content, whose own
		// render callback applies the_content internally but without ever
		// calling WP_Query::the_post() — in_the_loop() (append_after_definition_hook()'s
		// guard) stays false throughout, so that guard alone never fires
		// there. These mirror Template_Loader::fire_before_article_hook()/
		// wrap_kb_article_content()'s pre_render_block / render_block_core/post-content
		// pairing to detect that render directly instead. Not gated on
		// wp_is_block_theme(): a classic theme can still render this core
		// block via do_blocks() (e.g. inside a widget or a block-built page).
		add_filter( 'pre_render_block', array( $this, 'track_post_template_render_start' ), PHP_INT_MAX, 2 );
		add_filter( 'render_block_core/post-template', array( $this, 'track_post_template_render_end' ) );
		add_filter( 'pre_render_block', array( $this, 'track_post_content_render_start' ), PHP_INT_MAX, 2 );
		add_filter( 'render_block_core/post-content', array( $this, 'fire_after_definition_hook_for_block_theme' ), 10, 3 );
	}

	/**
	 * Resets $hook_fired for each new main query — exposed as a static
	 * method too (reset_state()) for tests that need to reset it without
	 * running a main query.
	 *
	 * @param \WP_Query $query The query WordPress is about to run.
	 */
	public function reset_hook_state( \WP_Query $query ): void {
		if ( $query->is_main_query() ) {
			self::$hook_fired                 = false;
			self::$block_hook_fired           = false;
			$this->post_content_render_depth  = 0;
			$this->post_template_render_depth = 0;
		}
	}

	/**
	 * Resets $hook_fired directly — see reset_hook_state().
	 */
	public static function reset_state(): void {
		self::$hook_fired       = false;
		self::$block_hook_fired = false;
	}

	/**
	 * Fires saai_glossary_after_definition once, right after the queried
	 * term's own definition body — the classic-theme Loop path.
	 *
	 * The core/post-content render callback applies the_content internally
	 * too, but without ever calling WP_Query::the_post(), so in_the_loop()
	 * stays false throughout a block theme's render of it —
	 * fire_after_definition_hook_for_block_theme() covers that case instead,
	 * via pre_render_block/render_block_core/post-content, the same as
	 * Template_Loader's KB article hooks. $post_content_render_depth being
	 * nonzero here means this the_content call is firing as a side effect of
	 * that same core/post-content render (the block theme case, already
	 * covered there), so it defers rather than firing a second time.
	 *
	 * The remaining guards mirror Template_Loader::queried_kb_article_for_classic_content_hooks():
	 * in_the_loop() rejects a the_content() call made ahead of the main
	 * query's Loop (e.g. an SEO plugin deriving a meta description during
	 * wp_head), get_queried_object_id() === get_the_ID() scopes this to the
	 * viewed term itself (not some other saai_glossary post whose content
	 * happens to render through the same filter, e.g. a "related terms" Query
	 * Loop the template embeds), and $hook_fired rejects a second the_content()
	 * call for the same post within that same Loop pass. Known accepted gap,
	 * same as that method's: a secondary query embedding the viewed term
	 * itself, run before the primary the_content() call within the same Loop
	 * iteration, can still consume the guard first — requires a deliberately
	 * unusual template override, not anything this plugin ships.
	 *
	 * @param string $content The post content, already run through the_content.
	 * @return string
	 */
	public function append_after_definition_hook( string $content ): string {
		if ( $this->post_content_render_depth > 0 ) {
			return $content;
		}

		if ( self::$hook_fired || ! in_the_loop() || ! is_singular( 'saai_glossary' ) ) {
			return $content;
		}

		if ( get_queried_object_id() !== get_the_ID() ) {
			return $content;
		}

		$post = get_post( get_the_ID() );

		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}

		// get_the_content() already swapped $content for
		// get_the_password_form() when the term is protected; an add-on's
		// saai_glossary_after_definition callback (e.g. echoing linked
		// products) must not still run and print right after that form for
		// an unauthenticated visitor.
		if ( post_password_required( $post ) ) {
			return $content;
		}

		self::$hook_fired = true;

		ob_start();

		/**
		 * Fires after a glossary term's definition body.
		 *
		 * @since 0.1.0
		 *
		 * @param \WP_Post $post The glossary term being viewed.
		 */
		do_action( 'saai_glossary_after_definition', $post );

		return $content . ob_get_clean();
	}

	/**
	 * Marks the start of a core/post-template (Query Loop) render — see
	 * $post_template_render_depth's docblock. Identical to
	 * Template_Loader::track_post_template_render_start(): only increments if
	 * $pre_render is still null, since a lower-priority pre_render_block
	 * callback may have already short-circuited this same block, in which
	 * case render_block_core_post_template() (and with it,
	 * track_post_template_render_end(), the filter that decrements this)
	 * never runs.
	 *
	 * @param string|null          $pre_render   Pass-through; never short-circuits.
	 * @param array<string, mixed> $parsed_block The block about to render.
	 * @return string|null
	 */
	public function track_post_template_render_start( $pre_render, array $parsed_block ) {
		if ( null === $pre_render && 'core/post-template' === ( $parsed_block['blockName'] ?? null ) ) {
			++$this->post_template_render_depth;
		}

		return $pre_render;
	}

	/**
	 * Marks the end of a core/post-template render — the
	 * render_block_core/post-template counterpart to
	 * track_post_template_render_start(). This filter only ever fires for
	 * core/post-template (its dynamic hook name), so every call here is one
	 * such block finishing its render.
	 *
	 * @param string $block_content The rendered post-template block.
	 * @return string
	 */
	public function track_post_template_render_end( string $block_content ): string {
		--$this->post_template_render_depth;

		return $block_content;
	}

	/**
	 * Marks the start of a core/post-content block render — see
	 * $post_content_render_depth's docblock. Identical reasoning to
	 * track_post_template_render_start(): only increments if $pre_render is
	 * still null.
	 *
	 * @param string|null          $pre_render   Pass-through; never short-circuits.
	 * @param array<string, mixed> $parsed_block The block about to render.
	 * @return string|null
	 */
	public function track_post_content_render_start( $pre_render, array $parsed_block ) {
		if ( null === $pre_render && 'core/post-content' === ( $parsed_block['blockName'] ?? null ) ) {
			++$this->post_content_render_depth;
		}

		return $pre_render;
	}

	/**
	 * Fires saai_glossary_after_definition once, right after a block theme's
	 * rendered term body — the block-theme counterpart to
	 * append_after_definition_hook(); see that method's docblock for why
	 * in_the_loop() alone can't detect this case.
	 *
	 * Mirrors Template_Loader::wrap_kb_article_content(): $was_outermost
	 * (captured from $post_content_render_depth before decrementing) rejects
	 * a core/post-content nested inside the one actually being appended to —
	 * a Query Loop the term body itself embeds, rendering some other,
	 * unrelated term through the same filter. $post_template_render_depth
	 * separately rejects a core/post-content encountered while iterating a
	 * Query Loop that includes the viewed term (e.g. a "related terms"
	 * section) — a case $was_outermost can't catch on its own, since each
	 * iteration's core/post-content sits at the same, non-nested depth.
	 * $block_hook_fired then rejects a second, non-nested core/post-content
	 * for the same post elsewhere in the template. get_queried_object_id() ===
	 * get_the_ID() scopes this to the viewed term itself, relying on
	 * core/post-template's the_post() call (or, for the primary render, the
	 * block template canvas's own) having set the global $post to it.
	 *
	 * @param string               $block_content The rendered post-content block.
	 * @param array<string, mixed> $parsed_block Parsed block data (unused).
	 * @param \WP_Block            $block Unused.
	 * @return string
	 */
	public function fire_after_definition_hook_for_block_theme( string $block_content, array $parsed_block, \WP_Block $block ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- kept to match the render_block_core/post-content filter signature.
		$was_outermost = 1 === $this->post_content_render_depth;
		--$this->post_content_render_depth;

		if ( ! $was_outermost || 0 !== $this->post_template_render_depth || self::$block_hook_fired ) {
			return $block_content;
		}

		if ( ! is_singular( 'saai_glossary' ) || get_queried_object_id() !== get_the_ID() ) {
			return $block_content;
		}

		$post = get_post( get_the_ID() );

		if ( ! $post instanceof \WP_Post ) {
			return $block_content;
		}

		// Same reasoning as append_after_definition_hook()'s equivalent
		// guard: an add-on's saai_glossary_after_definition callback must not
		// print right after a protected term's rendered password form.
		if ( post_password_required( $post ) ) {
			return $block_content;
		}

		self::$block_hook_fired = true;

		ob_start();

		/** This action is documented in append_after_definition_hook(). */
		do_action( 'saai_glossary_after_definition', $post );

		return $block_content . ob_get_clean();
	}

	/**
	 * Outputs the DefinedTerm JSON-LD for a saai_glossary singular view.
	 */
	public function output_structured_data(): void {
		if ( ! is_singular( 'saai_glossary' ) || ! $this->structured_data_enabled() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// json_ld()'s description() reads the excerpt/content directly,
		// bypassing the post_password_required() gate get_the_content()
		// enforces (it swaps in get_the_password_form() instead) — an
		// unauthenticated visitor must not be able to read the protected
		// definition out of the page source via this JSON-LD.
		if ( post_password_required( $post ) ) {
			return;
		}

		$encoded = wp_json_encode( $this->json_ld( $post ), JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $encoded ) ) {
			return;
		}

		printf( '<script type="application/ld+json">%s</script>' . "\n", $encoded ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() output is safe JSON, not HTML; see Faq_List's identical convention.
	}

	/**
	 * Builds the schema.org DefinedTerm for a glossary term.
	 *
	 * @param \WP_Post $post The glossary term.
	 * @return array<string, mixed>
	 */
	public function json_ld( \WP_Post $post ): array {
		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'DefinedTerm',
			// get_the_title() encodes characters as HTML references (the_title
			// filter, e.g. & -> &#038;); JSON-LD consumers never HTML-decode,
			// so decode to plain text after stripping tags. Same reasoning as
			// Faq_List::json_ld().
			'name'        => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ),
			'description' => $this->description( $post ),
			'url'         => (string) get_permalink( $post ),
		);

		$archive_url = get_post_type_archive_link( 'saai_glossary' );

		if ( is_string( $archive_url ) && '' !== $archive_url ) {
			$schema['inDefinedTermSet'] = $archive_url;
		}

		/** This filter is documented in includes/class-breadcrumbs.php */
		$filtered_schema = apply_filters( 'saai_structured_data', $schema, 'defined-term', $post );

		return is_array( $filtered_schema ) ? $filtered_schema : $schema;
	}

	/**
	 * Whether structured data output is enabled in settings. Same logic as
	 * Faq_List::structured_data_enabled() / Breadcrumbs::structured_data_enabled().
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
	 * A plain-text description for a term's DefinedTerm: its excerpt if set,
	 * otherwise the first words of its definition body.
	 *
	 * @param \WP_Post $post The glossary term.
	 * @return string
	 */
	private function description( \WP_Post $post ): string {
		$text = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( $post->post_content, 55 );

		return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
	}
}
