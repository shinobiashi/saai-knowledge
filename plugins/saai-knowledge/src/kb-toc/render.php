<?php
/**
 * Server-side render for the saai-knowledge/kb-toc block.
 *
 * @package SAAI\Knowledge
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner block content (unused, block has no children).
 * @var WP_Block             $block      Block instance.
 */

use SAAI\Knowledge\Heading_Anchors;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'saai_render_kb_toc_items' ) ) {
	/**
	 * Renders the table-of-contents list items.
	 *
	 * @param array<int, array<string, mixed>> $headings Heading list, see Heading_Anchors::extract().
	 * @return string
	 */
	function saai_render_kb_toc_items( array $headings ): string {
		$items = '';

		foreach ( $headings as $heading ) {
			if ( ! is_array( $heading ) || empty( $heading['id'] ) || empty( $heading['text'] ) ) {
				// A third-party saai_kb_toc_items callback returned a malformed entry; skip it.
				continue;
			}

			$id          = (string) $heading['id'];
			$level_class = 3 === (int) ( $heading['level'] ?? 2 ) ? ' saai-kb-toc__item--h3' : '';

			$items .= sprintf(
				'<li class="saai-kb-toc__item%1$s" data-wp-context=\'%2$s\' data-wp-class--is-active="state.isActive">' .
					'<a href="#%3$s" data-wp-on--click="actions.scrollToHeading" data-wp-bind--aria-current="state.ariaCurrent">%4$s</a>' .
				'</li>',
				esc_attr( $level_class ),
				esc_attr( wp_json_encode( array( 'id' => $id ) ) ),
				esc_attr( $id ),
				esc_html( (string) $heading['text'] )
			);
		}

		return $items;
	}
}

$saai_current_post_id = is_singular( 'saai_kb' ) ? get_queried_object_id() : null;
$saai_post            = $saai_current_post_id ? get_post( $saai_current_post_id ) : null;

$saai_headings = $saai_post instanceof WP_Post
	? array_values(
		array_filter(
			( new Heading_Anchors() )->extract( $saai_post ),
			static function ( $heading ) {
				return '' !== ( $heading['text'] ?? '' );
			}
		)
	)
	: array();

$saai_context = array( 'post_id' => $saai_current_post_id );

/**
 * Filters the table-of-contents heading list.
 *
 * @since 0.1.0
 *
 * @param array<int, array<string, mixed>> $saai_headings Heading list, see Heading_Anchors::extract().
 * @param array<string, mixed>             $saai_context  Context: [ 'post_id' => int|null ].
 */
$saai_filtered_headings = apply_filters( 'saai_kb_toc_items', $saai_headings, $saai_context );

// @phpstan-ignore ternary.elseUnreachable (PHPStan trusts the docblock @param type above, but a third-party saai_kb_toc_items callback can violate it at runtime.)
$saai_headings = is_array( $saai_filtered_headings ) ? $saai_filtered_headings : $saai_headings;

if ( $saai_headings ) {
	$saai_heading_ids = wp_list_pluck( $saai_headings, 'id' );

	$saai_wrapper_attributes = get_block_wrapper_attributes(
		array(
			'class'               => 'saai-kb-toc',
			'data-wp-interactive' => 'saai-knowledge/kb-toc',
			'data-wp-context'     => wp_json_encode(
				array(
					'activeId'   => null,
					'headingIds' => $saai_heading_ids,
				)
			),
			'data-wp-init'        => 'callbacks.initScrollSpy',
		)
	);

	ob_start();

	/**
	 * Fires before the KB table of contents list.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $saai_context Table of contents context, see docs/DESIGN-HOOKS-API.md section 3.2.
	 */
	do_action( 'saai_kb_toc_before', $saai_context );

	echo saai_render_kb_toc_items( $saai_headings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from already-escaped fragments.

	/**
	 * Fires after the KB table of contents list.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $saai_context Table of contents context, see docs/DESIGN-HOOKS-API.md section 3.2.
	 */
	do_action( 'saai_kb_toc_after', $saai_context );

	$saai_items = ob_get_clean();

	printf(
		'<nav %1$s aria-label="%2$s"><ul class="saai-kb-toc__list">%3$s</ul></nav>',
		$saai_wrapper_attributes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() already escapes.
		esc_attr__( 'Table of contents', 'saai-knowledge' ),
		$saai_items // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from already-escaped fragments.
	);
}
