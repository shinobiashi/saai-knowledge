<?php
/**
 * Server-side render for the saai-knowledge/faq-list block.
 *
 * @package SAAI\Knowledge
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner block content (unused, block has no children).
 * @var WP_Block             $block      Block instance.
 */

use SAAI\Knowledge\Faq_List;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'saai_render_faq_list_json_ld' ) ) {
	/**
	 * Renders the FAQPage JSON-LD script tag.
	 *
	 * @param array<string, mixed> $schema Schema, see Faq_List::json_ld().
	 * @return string
	 */
	function saai_render_faq_list_json_ld( array $schema ): string {
		// wp_json_encode() escapes forward slashes by default, which turns
		// any "</script>" appearing inside an answer into "<\/script>" and
		// keeps it from breaking out of this script tag.
		$encoded = wp_json_encode( $schema, JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $encoded ) ) {
			return '';
		}

		return sprintf( '<script type="application/ld+json">%s</script>', $encoded );
	}
}

if ( Faq_List::is_rendering() ) {
	// A faq-list block (or [saai_faq] shortcode) nested inside an FAQ answer
	// or rendered from a saai_faq_before_list / saai_faq_after_list callback
	// would otherwise recurse forever.
	return;
}

$saai_faq_list = new Faq_List();
$saai_attrs    = $saai_faq_list->normalize( $attributes );

// The guard stays up until the very end of this render — through the answer
// rendering AND the insertion hooks below — so nothing a third-party callback
// renders can re-enter this same block.
Faq_List::begin_render();

try {
	if ( $saai_attrs['groupByCategory'] ) {
		$saai_groups = $saai_faq_list->grouped_items( $saai_attrs );
	} else {
		$saai_items  = $saai_faq_list->items( $saai_attrs );
		$saai_groups = $saai_items ? array(
			array(
				'term'  => null,
				'title' => '',
				'items' => $saai_items,
			),
		) : array();
	}

	$saai_sections  = '';
	$saai_all_items = array();

	foreach ( $saai_groups as $saai_group ) {
		$saai_group_items = is_array( $saai_group['items'] ?? null ) ? $saai_group['items'] : array();
		$saai_accordion   = $saai_faq_list->accordion( $saai_group_items );

		if ( '' === $saai_accordion ) {
			continue;
		}

		$saai_all_items = array_merge( $saai_all_items, $saai_group_items );

		$saai_group_title = $saai_group['title'] ?? '';
		$saai_heading     = '';

		// Not empty(): a category legitimately named "0" must keep its heading.
		if ( is_scalar( $saai_group_title ) && '' !== (string) $saai_group_title ) {
			$saai_heading = sprintf(
				'<h2 class="saai-faq-list__group-title">%s</h2>',
				esc_html( (string) $saai_group_title )
			);
		}

		$saai_sections .= sprintf(
			'<section class="saai-faq-list__group">%1$s%2$s</section>',
			$saai_heading,
			$saai_accordion
		);
	}

	$saai_wrapper_attributes = get_block_wrapper_attributes( array( 'class' => 'saai-faq-list' ) );

	if ( '' === $saai_sections ) {
		printf(
			'<div %1$s><p class="saai-faq-list__empty">%2$s</p></div>',
			$saai_wrapper_attributes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() already escapes.
			esc_html__( 'No FAQs found.', 'saai-knowledge' )
		);

		return;
	}

	ob_start();

	/**
	 * Fires before the FAQ list.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $saai_attrs The normalized block attributes.
	 */
	do_action( 'saai_faq_before_list', $saai_attrs );

	echo $saai_sections; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from already-escaped fragments and do_blocks() output.

	/**
	 * Fires after the FAQ list.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $saai_attrs The normalized block attributes.
	 */
	do_action( 'saai_faq_after_list', $saai_attrs );

	$saai_inner = ob_get_clean();

	$saai_json_ld = '';

	// The slot is claimed by attribute signature (not a plain boolean) so a
	// speculative pre-render whose output is discarded — excerpt generation,
	// an SEO plugin's metadata pass — can't permanently consume it; see
	// Faq_List::claim_structured_data_slot().
	if ( $saai_faq_list->structured_data_enabled() && Faq_List::claim_structured_data_slot( $saai_faq_list->structured_data_signature( $saai_attrs ) ) ) {
		// On a non-empty archive (the bundled FAQ archive template in
		// particular), WordPress primes the global $post — and with it the
		// top-level render_block()'s default postId context — to the FIRST
		// main-query result before any block renders, even though the page
		// is not that post's singular view (see breadcrumbs/render.php).
		// Passing that arbitrary first FAQ to the saai_structured_data
		// filter would let an add-on attach the wrong post's metadata to the
		// archive's FAQPage schema, so archive-style views always pass null.
		// No Query Loop exception: WordPress core provides no context key
		// that proves a genuine per-item render (queryId only proves "some
		// descendant of core/query" — a block placed beside the Post
		// Template still inherits it while postId is the archive's seeded
		// first result), and for schema purposes null is the honest value on
		// any archive.
		$saai_current_post = null;

		if ( ! ( is_archive() || is_search() || is_home() ) ) {
			// The block-renderer REST endpoint (editor ServerSideRender
			// preview) supplies postId context from its post_id parameter;
			// the front end's render_block() derives it from the main query
			// (or the Query Loop item, when nested in one). get_post() is
			// the fallback for renders without any context, e.g. the
			// [saai_faq] shortcode on a singular view.
			$saai_post_id   = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : 0;
			$saai_candidate = $saai_post_id ? get_post( $saai_post_id ) : get_post();

			if ( $saai_candidate instanceof WP_Post ) {
				$saai_current_post = $saai_candidate;
			}
		}

		$saai_json_ld = saai_render_faq_list_json_ld(
			$saai_faq_list->json_ld( $saai_all_items, $saai_current_post )
		);
	}

	printf(
		'<div %1$s>%2$s%3$s</div>',
		$saai_wrapper_attributes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() already escapes.
		$saai_inner, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from already-escaped fragments.
		$saai_json_ld // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from wp_json_encode(), see saai_render_faq_list_json_ld().
	);
} finally {
	Faq_List::finish_render();
}
