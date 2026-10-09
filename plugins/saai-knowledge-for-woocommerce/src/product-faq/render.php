<?php
/**
 * Server-side render for the saai-knowledge/product-faq block.
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

// The free plugin's faq-list block, restricted to this product's FAQs, with
// a per-product marker keeping its FAQPage JSON-LD signature apart from the
// FAQ tab's and from other products' lists on the same page.
$saai_woo_list = ( new Product_Sections( new Link_Resolver() ) )->faq_html(
	$saai_woo_product_id,
	Product_Sections::faq_block_marker( $saai_woo_product_id )
);

// Nothing linked: no heading either. The Single Product template is shared
// by every product, and most products may have nothing to show.
if ( '' === $saai_woo_list ) {
	return;
}

$saai_woo_html = Blocks::section_html(
	'saai-woo-product-faq',
	__( 'FAQ', 'saai-knowledge-for-woocommerce' ),
	$saai_woo_list,
	Blocks::show_title( $attributes )
);

echo $saai_woo_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wrapper attributes from get_block_wrapper_attributes(), the heading escaped in section_html(), the body is the faq-list block's own escaped render.
