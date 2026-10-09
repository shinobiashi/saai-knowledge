<?php
/**
 * Resolves which product, if any, the current request is displaying.
 *
 * @package SAAI\KnowledgeWoo
 */

namespace SAAI\KnowledgeWoo;

defined( 'ABSPATH' ) || exit;

/**
 * The one answer to "is this a single product page, and which product?"
 * shared by every product-page service, so the FAQ tab, the related-KB
 * section, and the description auto-linking all agree on when to act.
 *
 * Built on core functions only (no is_product() / wc_get_product()), like
 * Link_Resolver: WooCommerce is absent from the PHPUnit bootstrap, and a
 * stand-in `product` post type exercises the same code path.
 */
final class Product_Context {

	/**
	 * The product whose single page the current request is rendering.
	 *
	 * Requires three things to line up: the main query is a single `product`
	 * view, the global post is a product, and it is THE queried product.
	 * The last check is what keeps the related-products / upsell loops that
	 * WooCommerce renders further down the same page from being mistaken
	 * for the displayed product while they iterate the global post, and it
	 * also rules out the editor's REST block-renderer previews and admin
	 * screens, where is_singular() is false.
	 *
	 * @return int The product post ID, or 0 when this isn't a single product page.
	 */
	public static function current_product_id(): int {
		if ( ! is_singular( Link_Resolver::PRODUCT_POST_TYPE ) ) {
			return 0;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post || Link_Resolver::PRODUCT_POST_TYPE !== $post->post_type ) {
			return 0;
		}

		if ( (int) get_queried_object_id() !== $post->ID ) {
			return 0;
		}

		return (int) $post->ID;
	}
}
