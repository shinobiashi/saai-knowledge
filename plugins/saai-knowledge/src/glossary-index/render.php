<?php
/**
 * Server-side render for the saai-knowledge/glossary-index block.
 *
 * @package SAAI\Knowledge
 *
 * @var array<string, mixed> $attributes Block attributes. Unused, block has none.
 * @var string               $content    Inner block content (unused, block has no children).
 * @var WP_Block             $block      Block instance. Unused.
 */

use SAAI\Knowledge\Glossary_Index;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'saai_glossary_index_bucket_label' ) ) {
	/**
	 * The bucket's tab/heading label. Gojūon rows and Latin letters (already
	 * uppercase from Glossary_Index::bucket_for()) are shown as-is; the
	 * catch-all bucket gets a translatable label instead of the bare '#' key.
	 *
	 * @param string $bucket Bucket key, see Glossary_Index::grouped_items().
	 * @return string
	 */
	function saai_glossary_index_bucket_label( string $bucket ): string {
		if ( '#' === $bucket ) {
			return _x( '#', 'Glossary index catch-all bucket label (terms with no kana/Latin reading)', 'saai-knowledge' );
		}

		return $bucket;
	}
}

if ( ! function_exists( 'saai_render_glossary_index_panel_list' ) ) {
	/**
	 * Renders one bucket's term list.
	 *
	 * @param array<int, array<string, mixed>> $items Items, see Glossary_Index::items().
	 * @return string
	 */
	function saai_render_glossary_index_panel_list( array $items ): string {
		$list_items = '';

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$title = $item['title'] ?? '';

			// Not empty(): a term legitimately titled "0" must not be dropped.
			if ( ! is_scalar( $title ) || '' === (string) $title ) {
				continue;
			}

			$url = $item['url'] ?? '';

			$list_items .= sprintf(
				'<li class="saai-glossary-index__item"><a href="%1$s">%2$s</a></li>',
				esc_url( is_scalar( $url ) ? (string) $url : '' ),
				esc_html( (string) $title )
			);
		}

		return '<ul class="saai-glossary-index__list">' . $list_items . '</ul>';
	}
}

$saai_groups = ( new Glossary_Index() )->grouped_items();

$saai_wrapper_attributes = get_block_wrapper_attributes( array( 'class' => 'saai-glossary-index' ) );

if ( ! $saai_groups ) {
	printf(
		'<div %1$s><p class="saai-glossary-index__empty">%2$s</p></div>',
		$saai_wrapper_attributes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() already escapes.
		esc_html__( 'No glossary terms found.', 'saai-knowledge' )
	);

	return;
}

$saai_instance_id    = wp_unique_id( 'saai-glossary-index-' );
$saai_default_bucket = is_scalar( $saai_groups[0]['bucket'] ?? null ) ? (string) $saai_groups[0]['bucket'] : '';
$saai_tabs           = '';
$saai_panels         = '';

foreach ( $saai_groups as $saai_position => $saai_group ) {
	$saai_bucket = is_scalar( $saai_group['bucket'] ?? null ) ? (string) $saai_group['bucket'] : '';

	// A third-party grouped_items() result (Glossary_Index has no public
	// filter today, but this stays defensive like the rest of the block set)
	// with no usable bucket key can't be tabbed to; skip it.
	if ( '' === $saai_bucket ) {
		continue;
	}

	$saai_items     = is_array( $saai_group['items'] ?? null ) ? $saai_group['items'] : array();
	$saai_is_active = ( $saai_bucket === $saai_default_bucket );
	$saai_tab_id    = $saai_instance_id . '-tab-' . $saai_position;
	$saai_panel_id  = $saai_instance_id . '-panel-' . $saai_position;
	$saai_label     = saai_glossary_index_bucket_label( $saai_bucket );
	$saai_context   = wp_json_encode( array( 'bucket' => $saai_bucket ) );

	// Roving tabindex (WAI-ARIA Tabs pattern): only the active tab is a Tab-key
	// stop; actions.onTabKeydown() (view.js) moves both the roving tabindex
	// and focus with the arrow/Home/End keys, using the plain data-bucket
	// attribute to read a SIBLING tab's bucket key — getContext() only
	// exposes the element currently handling the event, not other tabs'.
	$saai_tabs .= sprintf(
		'<button type="button" id="%1$s" role="tab" class="saai-glossary-index__tab%2$s" data-bucket="%3$s" tabindex="%4$s" data-wp-context=\'%5$s\' data-wp-on--click="actions.selectBucket" data-wp-on--keydown="actions.onTabKeydown" data-wp-class--is-active="state.isActiveBucket" data-wp-bind--aria-selected="state.isActiveBucket" data-wp-bind--tabindex="state.tabIndex" aria-selected="%6$s" aria-controls="%7$s">%8$s</button>',
		esc_attr( $saai_tab_id ),
		$saai_is_active ? ' is-active' : '',
		esc_attr( $saai_bucket ),
		$saai_is_active ? '0' : '-1',
		esc_attr( is_string( $saai_context ) ? $saai_context : '' ),
		$saai_is_active ? 'true' : 'false',
		esc_attr( $saai_panel_id ),
		esc_html( $saai_label )
	);

	// data-wp-bind--hidden only reacts to state changes after hydration; the
	// literal `hidden` attribute below must already match $saai_is_active
	// server-side or every panel but the first would render fully visible
	// until its tab is clicked once — same reasoning as kb-sidebar's
	// data-wp-bind--hidden usage.
	$saai_panels .= sprintf(
		'<div id="%1$s" role="tabpanel" tabindex="0" class="saai-glossary-index__panel" aria-labelledby="%2$s" data-wp-context=\'%3$s\' data-wp-bind--hidden="!state.isActiveBucket"%4$s>' .
			'<h2 class="saai-glossary-index__panel-title">%5$s</h2>%6$s' .
		'</div>',
		esc_attr( $saai_panel_id ),
		esc_attr( $saai_tab_id ),
		esc_attr( is_string( $saai_context ) ? $saai_context : '' ),
		$saai_is_active ? '' : ' hidden',
		esc_html( $saai_label ),
		saai_render_glossary_index_panel_list( $saai_items ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from already-escaped fragments.
	);
}

$saai_wrapper_context = wp_json_encode( array( 'activeBucket' => $saai_default_bucket ) );

printf(
	'<div %1$s data-wp-interactive="saai-knowledge/glossary-index" data-wp-context=\'%2$s\'>' .
		'<div class="saai-glossary-index__tabs" role="tablist">%3$s</div>' .
		'<div class="saai-glossary-index__panels">%4$s</div>' .
	'</div>',
	$saai_wrapper_attributes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() already escapes.
	esc_attr( is_string( $saai_wrapper_context ) ? $saai_wrapper_context : '' ),
	$saai_tabs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from already-escaped fragments.
	$saai_panels // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from already-escaped fragments.
);
