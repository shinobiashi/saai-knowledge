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
			if ( ! is_array( $node ) ) {
				// A third-party saai_kb_sidebar_items callback returned a non-array entry; skip it.
				continue;
			}

			$items .= saai_render_kb_sidebar_node( $node );
		}

		return '<ul class="saai-kb-sidebar__list">' . $items . '</ul>';
	}
}

if ( ! function_exists( 'saai_render_kb_sidebar_node' ) ) {
	/**
	 * Renders a single sidebar tree node.
	 *
	 * @param array<string, mixed> $node Node, see Sidebar_Tree::build().
	 * @return string
	 */
	function saai_render_kb_sidebar_node( array $node ): string {
		$type = $node['type'] ?? null;

		if ( ! in_array( $type, array( 'post', 'term' ), true ) ) {
			// A third-party saai_kb_sidebar_items callback returned a node this renderer doesn't understand; skip it.
			return '';
		}

		$title = isset( $node['title'] ) ? (string) $node['title'] : '';
		$url   = isset( $node['url'] ) ? (string) $node['url'] : '';

		if ( 'post' === $type ) {
			return sprintf(
				'<li class="saai-kb-sidebar__item saai-kb-sidebar__item--post"><a href="%1$s">%2$s</a></li>',
				esc_url( $url ),
				esc_html( $title )
			);
		}

		$children = is_array( $node['children'] ?? null ) ? $node['children'] : array();

		if ( ! $children ) {
			return sprintf(
				'<li class="saai-kb-sidebar__item saai-kb-sidebar__item--term"><a href="%1$s">%2$s</a></li>',
				esc_url( $url ),
				esc_html( $title )
			);
		}

		$open        = ! empty( $node['expanded'] );
		$children_id = wp_unique_id( 'saai-kb-sidebar-children-' );

		/* translators: %s: category name. */
		$toggle_label = sprintf( __( 'Toggle %s', 'saai-knowledge' ), $title );

		// data-wp-bind--hidden only reacts to state changes after hydration; it does not
		// apply on initial paint, so the literal `hidden` attribute below must already match
		// $open server-side or a collapsed node would render fully visible until first toggled.
		return sprintf(
			'<li class="saai-kb-sidebar__item saai-kb-sidebar__item--term" data-wp-context=\'%1$s\'>' .
				'<span class="saai-kb-sidebar__term">' .
					'<button type="button" class="saai-kb-sidebar__toggle" data-wp-on--click="actions.toggle" data-wp-bind--aria-expanded="context.open" aria-expanded="%2$s" aria-controls="%3$s" aria-label="%4$s"></button>' .
					'<a href="%5$s">%6$s</a>' .
				'</span>' .
				'<div id="%3$s" class="saai-kb-sidebar__children"%7$s data-wp-bind--hidden="!context.open">%8$s</div>' .
			'</li>',
			esc_attr( wp_json_encode( array( 'open' => $open ) ) ),
			$open ? 'true' : 'false',
			esc_attr( $children_id ),
			esc_attr( $toggle_label ),
			esc_url( $url ),
			esc_html( $title ),
			$open ? '' : ' hidden',
			saai_render_kb_sidebar_nodes( $children )
		);
	}
}

// See breadcrumbs/render.php's docblock for why archive-vs-singular
// conditional tags must be checked before trusting usesContext's postId: on
// a non-empty archive, WordPress's own WP::register_globals() primes the
// global $post — and with it, the top-level render_block()'s default postId
// context — to the FIRST result before any block renders, even though no
// Query Loop has iterated yet; trusting postId first would misidentify the
// hub/term archive itself as that first article's own singular view. queryId
// is only ever provided by a genuine ancestor Query Loop (a site-customized
// template could place this block inside one on an archive view), so its
// presence reliably distinguishes a real per-item render from the archive's
// default seed. The two branches below are mutually exclusive by
// construction, so $saai_current_post_id/$saai_current_term_id — and the
// $saai_context built from them below — can never both end up set at once,
// matching Sidebar_Tree::build()'s own current_post_id-wins contract.
$saai_in_query_loop = isset( $block->context['queryId'] );

$saai_current_post_id = null;
$saai_current_term_id = null;

if ( is_tax( 'saai_category' ) && ! $saai_in_query_loop ) {
	$saai_current_term_id = get_queried_object_id();
} elseif ( ! is_post_type_archive( 'saai_kb' ) || $saai_in_query_loop ) {
	// The editor's ServerSideRender preview provides the edited post via
	// block context (the block-renderer REST endpoint sets up the global
	// post from its post_id parameter, and render_block() derives postId
	// context from it), and the Site Editor canvas for a customized
	// single-saai_kb template does the same — see kb-toc/render.php's
	// docblock for the same reasoning. is_singular() is the front-end
	// fallback.
	$saai_post_id = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : 0;

	if ( ! $saai_post_id && is_singular( 'saai_kb' ) ) {
		$saai_post_id = get_queried_object_id();
	}

	$saai_current_post_id = $saai_post_id ? $saai_post_id : null;
}

$saai_tree = ( new Sidebar_Tree() )->build( $saai_current_post_id, $saai_current_term_id );

$saai_context = array(
	'current_post_id' => $saai_current_post_id,
	'current_term_id' => $saai_current_term_id,
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
