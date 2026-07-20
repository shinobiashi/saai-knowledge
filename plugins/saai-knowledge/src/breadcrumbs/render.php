<?php
/**
 * Server-side render for the saai-knowledge/breadcrumbs block.
 *
 * @package SAAI\Knowledge
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner block content (unused, block has no children).
 * @var WP_Block             $block      Block instance.
 */

use SAAI\Knowledge\Breadcrumbs;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'saai_render_breadcrumbs_items' ) ) {
	/**
	 * Renders the breadcrumb trail's list items.
	 *
	 * @param array<int, array<string, mixed>> $trail Trail, see Breadcrumbs::build().
	 * @return string
	 */
	function saai_render_breadcrumbs_items( array $trail ): string {
		$items = '';

		foreach ( $trail as $node ) {
			if ( ! is_array( $node ) ) {
				// A third-party saai_breadcrumbs_items callback returned a non-array entry; skip it.
				continue;
			}

			$label = $node['label'] ?? '';
			$url   = $node['url'] ?? '';

			// Not empty(): a crumb legitimately titled "0" must not be dropped.
			if ( ! is_scalar( $label ) || '' === (string) $label ) {
				// A third-party saai_breadcrumbs_items callback returned a malformed entry; skip it.
				continue;
			}

			$is_current = ! empty( $node['current'] );
			$label      = esc_html( (string) $label );

			if ( $is_current ) {
				$items .= sprintf(
					'<li class="saai-breadcrumbs__item saai-breadcrumbs__item--current" aria-current="page">%s</li>',
					$label
				);
				continue;
			}

			// Sanitize before testing for a usable URL: esc_url() reduces a value
			// carrying a disallowed protocol (javascript:, data:, ...) to an
			// empty string, which would otherwise render as href="".
			$url = is_scalar( $url ) ? esc_url( (string) $url ) : '';

			// A non-current crumb with no usable URL (hub link resolution failed,
			// or a filter injected a linkless node) renders as plain text; only
			// the explicitly current crumb may carry aria-current.
			if ( '' === $url ) {
				$items .= sprintf(
					'<li class="saai-breadcrumbs__item">%s</li>',
					$label
				);
				continue;
			}

			$items .= sprintf(
				'<li class="saai-breadcrumbs__item"><a href="%1$s">%2$s</a></li>',
				$url,
				$label
			);
		}

		return $items;
	}
}

if ( ! function_exists( 'saai_render_breadcrumbs_json_ld' ) ) {
	/**
	 * Renders the BreadcrumbList JSON-LD script tag.
	 *
	 * @param array<string, mixed> $schema Schema, see Breadcrumbs::json_ld().
	 * @return string
	 */
	function saai_render_breadcrumbs_json_ld( array $schema ): string {
		// wp_json_encode() escapes forward slashes by default, which turns
		// any "</script>" appearing inside a crumb label into "<\/script>"
		// and keeps it from breaking out of this script tag.
		$encoded = wp_json_encode( $schema, JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $encoded ) ) {
			return '';
		}

		return sprintf( '<script type="application/ld+json">%s</script>', $encoded );
	}
}

// See docs/DESIGN-HOOKS-API.md section 3.2: the block-renderer REST endpoint
// (editor ServerSideRender preview) supplies postId context from its post_id
// parameter; the front end's render_block() derives it from the main query.
// Because postId wins over the is_tax() check below, a Query Loop supplies
// each looped article's postId — on a term archive template this block must
// sit outside the loop to render the term trail, not a per-article trail.
$saai_post_id = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : 0;

if ( ! $saai_post_id && is_singular( 'saai_kb' ) ) {
	$saai_post_id = get_queried_object_id();
}

$saai_post = null;

if ( $saai_post_id ) {
	$saai_candidate = get_post( $saai_post_id );

	if ( $saai_candidate instanceof WP_Post && 'saai_kb' === $saai_candidate->post_type ) {
		$saai_post = $saai_candidate;
	}
}

$saai_term = null;

if ( ! $saai_post instanceof WP_Post && is_tax( 'saai_category' ) ) {
	$saai_queried_object = get_queried_object();

	if ( $saai_queried_object instanceof WP_Term ) {
		$saai_term = $saai_queried_object;
	}
}

if ( ! $saai_post instanceof WP_Post && ! $saai_term instanceof WP_Term && ! is_post_type_archive( 'saai_kb' ) ) {
	return;
}

$saai_breadcrumbs = new Breadcrumbs();
$saai_trail       = $saai_breadcrumbs->build( $saai_post, $saai_term );

if ( ! $saai_trail ) {
	return;
}

$saai_context = array(
	'post_id'  => $saai_post instanceof WP_Post ? $saai_post->ID : null,
	'term_id'  => $saai_term instanceof WP_Term ? $saai_term->term_id : null,
	'taxonomy' => 'saai_category',
);

$saai_wrapper_attributes = get_block_wrapper_attributes( array( 'class' => 'saai-breadcrumbs' ) );

ob_start();

/**
 * Fires before the breadcrumb list.
 *
 * @since 0.1.0
 *
 * @param array<string, mixed> $saai_context Breadcrumb context, see docs/DESIGN-HOOKS-API.md section 3.2.
 */
do_action( 'saai_breadcrumbs_before', $saai_context );

echo saai_render_breadcrumbs_items( $saai_trail ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from already-escaped fragments.

/**
 * Fires after the breadcrumb list.
 *
 * @since 0.1.0
 *
 * @param array<string, mixed> $saai_context Breadcrumb context, see docs/DESIGN-HOOKS-API.md section 3.2.
 */
do_action( 'saai_breadcrumbs_after', $saai_context );

$saai_items = ob_get_clean();

$saai_json_ld = '';

if ( $saai_breadcrumbs->structured_data_enabled() ) {
	$saai_json_ld = saai_render_breadcrumbs_json_ld( $saai_breadcrumbs->json_ld( $saai_trail, $saai_post ) );
}

printf(
	'<nav %1$s aria-label="%2$s"><ol class="saai-breadcrumbs__list">%3$s</ol></nav>%4$s',
	$saai_wrapper_attributes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() already escapes.
	esc_attr__( 'Breadcrumb', 'saai-knowledge' ),
	$saai_items, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from already-escaped fragments.
	$saai_json_ld // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from wp_json_encode(), see saai_render_breadcrumbs_json_ld().
);
