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

if ( ! function_exists( 'saai_valid_kb_toc_headings' ) ) {
	/**
	 * Filters a heading list down to entries the renderer can actually display.
	 *
	 * Applied before the minimum-heading-count check in the main render body,
	 * so a saai_kb_toc_items callback that returns entries this rejects can't
	 * inflate the count past the threshold with headings that then render as
	 * nothing (see that check's docblock for why the count must match what
	 * actually renders).
	 *
	 * @param array<int, array<string, mixed>> $headings Heading list, see Heading_Anchors::extract().
	 * @return array<int, array<string, mixed>>
	 */
	function saai_valid_kb_toc_headings( array $headings ): array {
		return array_values(
			array_filter(
				$headings,
				static function ( $heading ) {
					if ( ! is_array( $heading ) ) {
						// A third-party saai_kb_toc_items callback returned a non-array entry; skip it.
						return false;
					}

					$id   = $heading['id'] ?? '';
					$text = $heading['text'] ?? '';

					// Not empty(): a heading legitimately titled "0" must not be dropped.
					return is_scalar( $id ) && '' !== (string) $id && is_scalar( $text ) && '' !== (string) $text;
				}
			)
		);
	}
}

if ( ! function_exists( 'saai_render_kb_toc_items' ) ) {
	/**
	 * Renders the table-of-contents list items.
	 *
	 * @param array<int, array<string, mixed>> $headings Heading list, already filtered by saai_valid_kb_toc_headings().
	 * @return string
	 */
	function saai_render_kb_toc_items( array $headings ): string {
		$items = '';

		foreach ( $headings as $heading ) {
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

// The editor's ServerSideRender preview provides the edited post via block
// context (the block-renderer REST endpoint sets up the global post from its
// post_id parameter, and render_block() derives postId context from it); on
// the front end render_block() does the same from the main query's post, with
// is_singular() as a fallback for renders outside a post context.
$saai_post_id = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : 0;

if ( ! $saai_post_id && is_singular( 'saai_kb' ) ) {
	$saai_post_id = get_queried_object_id();
}

$saai_post = $saai_post_id ? get_post( $saai_post_id ) : null;

if ( ! $saai_post instanceof WP_Post || 'saai_kb' !== $saai_post->post_type ) {
	return;
}

// Heading_Anchors::extract() reads $post->post_content directly, bypassing
// the the_content filter chain that normally swaps in WordPress's password
// form for a protected post; without this, the TOC would expose section
// headings before the visitor supplies the password.
if ( post_password_required( $saai_post ) ) {
	return;
}

$saai_headings = saai_valid_kb_toc_headings( ( new Heading_Anchors() )->for_display( $saai_post ) );

// A single heading gives a table of contents nothing to navigate between,
// so kb-layout.css's :not(:has(.saai-kb-toc)) rule (which hides the whole
// panel and collapses its grid column) relies on this block rendering
// nothing below that count. Counted after saai_valid_kb_toc_headings()
// filters out malformed entries, so a saai_kb_toc_items callback that
// returns e.g. two entries where only one validates can't pass this check
// and then render just that one heading (or none) inside a still-visible
// .saai-kb-toc wrapper.
if ( count( $saai_headings ) > 1 ) {
	$saai_context     = array( 'post_id' => $saai_post->ID );
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
