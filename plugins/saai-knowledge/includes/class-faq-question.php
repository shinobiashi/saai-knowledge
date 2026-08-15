<?php
/**
 * Single FAQ page support: QAPage JSON-LD.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Emits the schema.org QAPage for a saai_faq singular view.
 *
 * A saai_faq post is already "1 post = 1 Q&A" (title = question, content =
 * answer, docs/DESIGN.md section 3.1), and the block/classic single templates
 * render it directly via core/post-title + core/post-content — there is no
 * dedicated block to emit the JSON-LD from inline the way Faq_List does for
 * the FAQPage on the archive, so this fires from wp_head instead, the same
 * pattern as Glossary_Term::output_structured_data() for DefinedTerm.
 */
final class Faq_Question {

	/**
	 * The globals WP_Query::setup_postdata() mutates besides $post —
	 * snapshotted and restored around the answer render so state from
	 * wp_head's early rendering can't leak into the Loop's later one.
	 * Same list as Faq_List::POSTDATA_GLOBALS.
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
	 * Hooks the QAPage JSON-LD output into WordPress.
	 */
	public function register(): void {
		// Priority 1: output_structured_data() renders the answer (do_blocks()/
		// do_shortcode()), which can enqueue styles as a side effect. Core's
		// wp_print_styles runs at wp_head priority 8, so the default priority 10
		// would enqueue too late for those styles to be printed.
		add_action( 'wp_head', array( $this, 'output_structured_data' ), 1 );
	}

	/**
	 * Outputs the QAPage JSON-LD for a saai_faq singular view.
	 */
	public function output_structured_data(): void {
		if ( ! is_singular( 'saai_faq' ) || ! $this->structured_data_enabled() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// Reading post_content directly below bypasses the
		// post_password_required() gate get_the_content() enforces (it swaps
		// in get_the_password_form() instead) — an unauthenticated visitor
		// must not be able to read the protected answer out of the page
		// source via this JSON-LD. Same guard as Glossary_Term's equivalent.
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
	 * Builds the schema.org QAPage for a single FAQ entry.
	 *
	 * @param \WP_Post $post The FAQ entry.
	 * @return array<string, mixed>
	 */
	public function json_ld( \WP_Post $post ): array {
		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'QAPage',
			'mainEntity' => array(
				'@type'          => 'Question',
				// get_the_title() encodes characters as HTML references (the_title
				// filter, e.g. & -> &#038;); JSON-LD consumers never HTML-decode,
				// so decode to plain text after stripping tags. Same reasoning as
				// Faq_List::json_ld() / Glossary_Term::json_ld().
				'name'           => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ),
				// Required by Google's Q&A structured data guidelines. The data
				// model is always 1 post = 1 answer (docs/DESIGN.md section 3.1),
				// so this is never anything but 1.
				'answerCount'    => 1,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $this->answer_text( $post ),
				),
			),
		);

		/** This filter is documented in includes/class-breadcrumbs.php */
		$filtered_schema = apply_filters( 'saai_structured_data', $schema, 'qa-page', $post );

		// A third-party saai_structured_data callback can return a non-array at runtime.
		return is_array( $filtered_schema ) ? $filtered_schema : $schema;
	}

	/**
	 * Whether structured data output is enabled in settings. Same logic as
	 * Faq_List::structured_data_enabled() / Glossary_Term::structured_data_enabled().
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
	 * The FAQ entry's answer as plain text: the docs/DESIGN.md section 7.1
	 * "1ページで回答が完結する" requirement means this must reflect the whole
	 * answer, not a short summary — unlike Glossary_Term::description()'s
	 * 55-word trim for DefinedTerm, this is not truncated.
	 *
	 * Runs the same do_blocks()/do_shortcode() transforms the visible page
	 * applies before stripping tags: a raw wp_strip_all_tags() of
	 * post_content alone would leave an unexpanded "[shortcode]" as literal
	 * text, or drop a dynamic block's content entirely (its saved form is
	 * just a self-closing HTML comment with no content between the
	 * delimiters) — either way the JSON-LD would no longer match what the
	 * page actually displays.
	 *
	 * wp_strip_all_tags() uses strip_tags() internally, which deletes tags
	 * without inserting a separator — "First<br>Second" or adjacent block
	 * elements like "<p>First</p><p>Second</p>" would otherwise collapse
	 * into the single word "FirstSecond". Line-break points are converted
	 * to a literal newline first so the plain-text answer keeps the same
	 * word boundaries the visible page shows.
	 *
	 * @param \WP_Post $post The FAQ entry.
	 * @return string
	 */
	private function answer_text( \WP_Post $post ): string {
		$html = (string) preg_replace( '#</(?:p|div|li|h[1-6]|blockquote|pre|tr|td|th)>|<br\s*/?>#i', '$0' . "\n", $this->render_answer( $post ) );

		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Renders the FAQ entry's answer body the same way the visible page
	 * does: do_blocks()/do_shortcode() applied, mirroring
	 * Faq_List::render_answer()'s content transforms.
	 *
	 * This runs from wp_head, before WP_Query::the_post() runs for the main
	 * Loop — $GLOBALS['post'] is already the queried FAQ by then, but
	 * setup_postdata()'s other globals ($authordata, pagination) are not,
	 * so a shortcode/block relying on them here would see different state
	 * than when the same content renders later inside the Loop.
	 * POSTDATA_GLOBALS is snapshotted and restored the same way
	 * Faq_List::render_answer() does, so this early render can't leak state
	 * into the Loop's later one either.
	 *
	 * setup_postdata() doesn't touch in_the_loop — only WP_Query::the_post()
	 * does. The classic template's Loop (templates/classic/single-saai_faq.php)
	 * calls the_post(), so its visible the_content() render sees
	 * in_the_loop() === true; a block theme's core/post-content never calls
	 * the_post() at all (see Template_Loader's docblock), so in_the_loop()
	 * stays false there even for the real render. in_the_loop is set to
	 * match only for a classic (non-block) theme, so this render's state
	 * agrees with whichever visible render will actually happen.
	 *
	 * Unlike Faq_List::render_answer(), the result never reaches the
	 * visible page (answer_text() strips it back to plain text for the
	 * JSON-LD), so wp_filter_content_tags() is deliberately skipped: it
	 * only adds responsive/loading HTML attributes that get stripped right
	 * back out, but its wp_get_loading_optimization_attributes() call has
	 * the side effect of advancing the request-wide "how many images seen
	 * so far" counter — running it here would consume the first image's
	 * eager/fetchpriority=high slot before the real the_content() render
	 * gets to it.
	 *
	 * @param \WP_Post $post The FAQ entry.
	 * @return string
	 */
	private function render_answer( \WP_Post $post ): string {
		$previous_post    = $GLOBALS['post'] ?? null;
		$previous_globals = array();

		foreach ( self::POSTDATA_GLOBALS as $var ) {
			$previous_globals[ $var ] = $GLOBALS[ $var ] ?? null;
		}

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberately scoping the FAQ as the current post for its own answer render; restored in the finally block.
		setup_postdata( $post );

		$wp_query             = $GLOBALS['wp_query'] ?? null;
		$previous_in_the_loop = null;

		if ( $wp_query instanceof \WP_Query && ! wp_is_block_theme() ) {
			$previous_in_the_loop  = $wp_query->in_the_loop;
			$wp_query->in_the_loop = true;
		}

		try {
			$content = (string) $post->post_content;

			// Core runs WP_Embed's handlers on the whole content ahead of
			// do_blocks() (the_content priority 8 vs 9) regardless of whether
			// the post also contains blocks: a Classic/freeform block can still
			// hold raw [embed] shortcode syntax or a bare URL, and only
			// WP_Embed's regex-based processor (not do_shortcode()'s standard
			// dispatch) expands those correctly.
			$wp_embed = $GLOBALS['wp_embed'] ?? null;

			if ( $wp_embed instanceof \WP_Embed ) {
				$content = $wp_embed->run_shortcode( $content );
				$content = $wp_embed->autoembed( $content );
			}

			if ( has_blocks( $content ) ) {
				$html = wptexturize( do_blocks( $content ) );
			} else {
				$html = wpautop( wptexturize( $content ) );
			}

			return do_shortcode( shortcode_unautop( $html ) );
		} finally {
			if ( null !== $previous_in_the_loop ) {
				$wp_query->in_the_loop = $previous_in_the_loop;
			}

			$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the exact pre-render value saved above.

			foreach ( $previous_globals as $var => $value ) {
				$GLOBALS[ $var ] = $value; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- restoring the exact pre-render values of WordPress's own postdata globals saved above.
			}
		}
	}
}
