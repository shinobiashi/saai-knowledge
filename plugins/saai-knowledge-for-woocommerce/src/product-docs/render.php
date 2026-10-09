<?php
/**
 * Server-side render for the saai-knowledge/product-docs block.
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

foreach ( ( new Product_Sections( new Link_Resolver() ) )->kb_links( $saai_woo_product_id ) as $saai_woo_link ) {
	$saai_woo_items .= sprintf(
		'<li class="saai-woo-product-docs__item"><a href="%1$s">%2$s</a></li>',
		esc_url( $saai_woo_link['url'] ),
		esc_html( $saai_woo_link['title'] )
	);
}

// Nothing linked: no heading either (see product-faq/render.php).
if ( '' === $saai_woo_items ) {
	return;
}

$saai_woo_html = Blocks::section_html(
	'saai-woo-product-docs',
	__( 'Related documentation', 'saai-knowledge-for-woocommerce' ),
	'<ul class="saai-woo-product-docs__list">' . $saai_woo_items . '</ul>',
	Blocks::show_title( $attributes )
);

echo $saai_woo_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wrapper attributes from get_block_wrapper_attributes(), the heading escaped in section_html(), the list built from esc_url()/esc_html()'d fragments above.
