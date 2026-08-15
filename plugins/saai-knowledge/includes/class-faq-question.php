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
	 * Hooks the QAPage JSON-LD output into WordPress.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'output_structured_data' ) );
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
	 * @param \WP_Post $post The FAQ entry.
	 * @return string
	 */
	private function answer_text( \WP_Post $post ): string {
		return html_entity_decode( wp_strip_all_tags( $this->render_answer( $post ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Renders the FAQ entry's answer body the same way the visible page
	 * does: do_blocks()/do_shortcode() applied, mirroring
	 * Faq_List::render_answer()'s content transforms.
	 *
	 * Unlike Faq_List::render_answer(), this doesn't need to temporarily
	 * swap $GLOBALS['post']: that method renders the FAQ's answer while some
	 * other page's content is the one currently rendering, but this runs
	 * from wp_head on the FAQ's own singular view — WP_Query::get_posts()
	 * already sets the global $post to the queried post for is_singular()
	 * results by the time wp_head fires, so shortcodes/dynamic blocks
	 * referencing "the current post" already see the right one.
	 *
	 * @param \WP_Post $post The FAQ entry.
	 * @return string
	 */
	private function render_answer( \WP_Post $post ): string {
		$content = (string) $post->post_content;

		if ( has_blocks( $content ) ) {
			$html = wptexturize( do_blocks( $content ) );
		} else {
			// Same reasoning as Faq_List::render_answer(): WP_Embed's
			// handlers run ahead of the standard transforms (priority 8 vs
			// 10 on the_content) for classic content.
			$wp_embed = $GLOBALS['wp_embed'] ?? null;

			if ( $wp_embed instanceof \WP_Embed ) {
				$content = $wp_embed->run_shortcode( $content );
				$content = $wp_embed->autoembed( $content );
			}

			$html = wpautop( wptexturize( $content ) );
		}

		$html = do_shortcode( shortcode_unautop( $html ) );

		return wp_filter_content_tags( $html, 'the_content' );
	}
}
