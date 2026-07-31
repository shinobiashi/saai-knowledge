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
			self::$hook_fired = false;
		}
	}

	/**
	 * Resets $hook_fired directly — see reset_hook_state().
	 */
	public static function reset_state(): void {
		self::$hook_fired = false;
	}

	/**
	 * Fires saai_glossary_after_definition once, right after the queried
	 * term's own definition body.
	 *
	 * The core/post-content render callback applies the_content the same as a
	 * classic theme's Loop does, so a single filter covers both theme types —
	 * unlike the KB article's before/after wrap (Template_Loader), this hook
	 * only appends, so it needs none of that class's pre_render_block
	 * depth-tracking to intercept the block's render ahead of the_content.
	 *
	 * Guards mirror Template_Loader::queried_kb_article_for_classic_content_hooks():
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
