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
	 * Reads raw post_content rather than get_the_content(): the singular
	 * template hasn't necessarily run the_post()/setup_postdata() yet at
	 * wp_head time (global $post isn't reliably the queried FAQ there), and
	 * wp_trim_words()'s internal wp_strip_all_tags() already discards block
	 * comment delimiters and markup, same precedent as Glossary_Term's
	 * fallback description.
	 *
	 * @param \WP_Post $post The FAQ entry.
	 * @return string
	 */
	private function answer_text( \WP_Post $post ): string {
		return html_entity_decode( wp_strip_all_tags( $post->post_content ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
	}
}
