<?php
/**
 * Server-side render for the saai-knowledge/product-glossary block.
 *
 * @package SAAI\KnowledgeWoo
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner block content (unused, block has no children).
 * @var WP_Block             $block      Block instance.
 */

use SAAI\KnowledgeWoo\Blocks;
use SAAI\KnowledgeWoo\Link_Resolver;
use SAAI\KnowledgeWoo\Product_Context;
use SAAI\KnowledgeWoo\Product_Sections;

defined( 'ABSPATH' ) || exit;

$saai_woo_product_id = Product_Context::for_block( $attributes['productId'] ?? 0, $block->context );

if ( $saai_woo_product_id <= 0 ) {
	return;
}

$saai_woo_items = '';

foreach ( ( new Product_Sections( new Link_Resolver() ) )->glossary_entries( $saai_woo_product_id ) as $saai_woo_entry ) {
	// One <div> per term/definition pair, as HTML allows inside <dl>. The
	// <dd> is kept even when empty (a term with no body or excerpt): a <dt>
	// without one isn't a valid group.
	$saai_woo_items .= sprintf(
		'<div class="saai-woo-product-glossary__item"><dt class="saai-woo-product-glossary__term"><a href="%1$s">%2$s</a></dt><dd class="saai-woo-product-glossary__definition">%3$s</dd></div>',
		esc_url( $saai_woo_entry['url'] ),
		esc_html( $saai_woo_entry['title'] ),
		esc_html( $saai_woo_entry['definition'] )
	);
}

// Nothing linked: no heading either (see product-faq/render.php).
if ( '' === $saai_woo_items ) {
	return;
}

$saai_woo_html = Blocks::section_html(
	'saai-woo-product-glossary',
	__( 'Glossary', 'saai-knowledge-for-woocommerce' ),
	'<dl class="saai-woo-product-glossary__list">' . $saai_woo_items . '</dl>',
	Blocks::show_title( $attributes )
);

echo $saai_woo_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wrapper attributes from get_block_wrapper_attributes(), the heading escaped in section_html(), the list built from esc_url()/esc_html()'d fragments above.
