<?php
/**
 * Server-side render for the saai-knowledge/search block.
 *
 * Only the search field and empty result containers are rendered here —
 * results depend on user input, so there is nothing meaningful to render
 * server-side (unlike the SSR requirement for the accordion-style blocks,
 * docs/DESIGN.md section 7.1, which applies to content that exists up front).
 *
 * @package SAAI\Knowledge
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner block content (unused, block has no children).
 * @var WP_Block             $block      Block instance. Unused.
 */

use SAAI\Knowledge\Search;

defined( 'ABSPATH' ) || exit;

$saai_placeholder = is_string( $attributes['placeholder'] ?? null ) ? $attributes['placeholder'] : '';
$saai_per_page    = is_numeric( $attributes['perPage'] ?? null ) ? max( 1, min( 20, (int) $attributes['perPage'] ) ) : 5;

if ( '' === $saai_placeholder ) {
	$saai_placeholder = __( 'Search FAQ, Knowledge Base, and glossary…', 'saai-knowledge' );
}

$saai_instance_id = wp_unique_id( 'saai-search-' );
$saai_input_id    = $saai_instance_id . '-input';
$saai_status_id   = $saai_instance_id . '-status';

$saai_type_labels = array();

foreach ( ( new Search() )->post_types() as $saai_type_key => $saai_type ) {
	// @phpstan-ignore booleanAnd.alwaysTrue, nullCoalesce.offset (PHPStan trusts Search::post_types()'s docblock @return type, but a third-party saai_search_post_types callback can violate it at runtime.)
	if ( is_array( $saai_type ) && is_string( $saai_type['label'] ?? null ) ) {
		$saai_type_labels[ $saai_type_key ] = $saai_type['label'];
	}
}

wp_interactivity_config(
	'saai-knowledge/search',
	array(
		'restUrl'    => rest_url( 'saai-knowledge/v1/search' ),
		'typeLabels' => $saai_type_labels,
		'statusText' => array(
			'loading' => __( 'Searching…', 'saai-knowledge' ),
			'empty'   => __( 'No results found.', 'saai-knowledge' ),
			'error'   => __( 'Something went wrong. Please try again.', 'saai-knowledge' ),
		),
	)
);

$saai_wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class'               => 'saai-search',
		'data-wp-interactive' => 'saai-knowledge/search',
		'data-wp-context'     => wp_json_encode(
			array(
				'query'   => '',
				'perPage' => $saai_per_page,
				'results' => array(),
				'status'  => 'idle',
			)
		),
	)
);

printf(
	'<div %1$s>' .
		'<div class="saai-search__field">' .
			'<label class="screen-reader-text" for="%2$s">%3$s</label>' .
			'<input type="search" id="%2$s" class="saai-search__input" placeholder="%4$s" autocomplete="off" aria-controls="%5$s" data-wp-on--input="actions.onInput">' .
		'</div>' .
		'<p class="saai-search__status" id="%6$s" role="status" aria-live="polite" data-wp-text="state.statusText"></p>' .
		'<ul class="saai-search__results" id="%5$s"></ul>' .
	'</div>',
	$saai_wrapper_attributes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() already escapes.
	esc_attr( $saai_input_id ),
	esc_html__( 'Search', 'saai-knowledge' ),
	esc_attr( $saai_placeholder ),
	esc_attr( $saai_instance_id . '-results' ),
	esc_attr( $saai_status_id )
);
