<?php
/**
 * Server-side render for the saai-knowledge/kb-sidebar block.
 *
 * @package SAAI\Knowledge
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner block content (unused, block has no children).
 * @var WP_Block             $block      Block instance.
 */

use SAAI\Knowledge\Sidebar_Tree;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'saai_render_kb_sidebar_nodes' ) ) {
	/**
	 * Renders a sidebar tree node list.
	 *
	 * @param array<int, array<string, mixed>> $nodes Node list.
	 * @return string
	 */
	function saai_render_kb_sidebar_nodes( array $nodes ): string {
		if ( ! $nodes ) {
			return '';
		}

		$items = '';

		foreach ( $nodes as $node ) {
			$items .= saai_render_kb_sidebar_node( $node );
		}

		return '<ul class="saai-kb-sidebar__list">' . $items . '</ul>';
	}

	/**
	 * Renders a single sidebar tree node.
	 *
	 * @param array<string, mixed> $node Node, see Sidebar_Tree::build().
	 * @return string
	 */
	function saai_render_kb_sidebar_node( array $node ): string {
		if ( 'post' === $node['type'] ) {
			return sprintf(
				'<li class="saai-kb-sidebar__item saai-kb-sidebar__item--post"><a href="%1$s">%2$s</a></li>',
				esc_url( $node['url'] ),
				esc_html( $node['title'] )
			);
		}

		$children = $node['children'] ?? array();

		if ( ! $children ) {
			return sprintf(
				'<li class="saai-kb-sidebar__item saai-kb-sidebar__item--term"><a href="%1$s">%2$s</a></li>',
				esc_url( $node['url'] ),
				esc_html( $node['title'] )
			);
		}

		$open = ! empty( $node['expanded'] );

		return sprintf(
			'<li class="saai-kb-sidebar__item saai-kb-sidebar__item--term" data-wp-context=\'%1$s\'>' .
				'<button type="button" class="saai-kb-sidebar__toggle" data-wp-on--click="actions.toggle" aria-expanded="%2$s">' .
					'<a href="%3$s">%4$s</a>' .
				'</button>' .
				'<div class="saai-kb-sidebar__children" data-wp-bind--hidden="!context.open">%5$s</div>' .
			'</li>',
			esc_attr( wp_json_encode( array( 'open' => $open ) ) ),
			$open ? 'true' : 'false',
			esc_url( $node['url'] ),
			esc_html( $node['title'] ),
			saai_render_kb_sidebar_nodes( $children )
		);
	}
}

$saai_current_post_id = is_singular() ? get_queried_object_id() : null;
$saai_tree            = ( new Sidebar_Tree() )->build( $saai_current_post_id );

$saai_context = array(
	'current_post_id' => $saai_current_post_id,
	'taxonomy'        => 'saai_category',
);

$saai_wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class'               => 'saai-kb-sidebar',
		'data-wp-interactive' => 'saai-knowledge/kb-sidebar',
	)
);

ob_start();

/**
 * Fires before the KB sidebar tree.
 *
 * @since 0.1.0
 *
 * @param array<string, mixed> $saai_context Sidebar context, see docs/DESIGN-HOOKS-API.md section 3.2.
 */
do_action( 'saai_kb_sidebar_top', $saai_context );

echo saai_render_kb_sidebar_nodes( $saai_tree ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from already-escaped fragments.

/**
 * Fires after the KB sidebar tree.
 *
 * @since 0.1.0
 *
 * @param array<string, mixed> $saai_context Sidebar context, see docs/DESIGN-HOOKS-API.md section 3.2.
 */
do_action( 'saai_kb_sidebar_bottom', $saai_context );

$saai_inner = ob_get_clean();

printf(
	'<nav %1$s>%2$s</nav>',
	$saai_wrapper_attributes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() already escapes.
	$saai_inner // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from already-escaped fragments.
);
