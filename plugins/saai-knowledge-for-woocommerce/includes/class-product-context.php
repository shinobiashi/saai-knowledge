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

	/**
	 * The product a manual-placement block or shortcode is about.
	 *
	 * In order: the block's own `productId` attribute (a normal page naming
	 * its product), the `postId` block context (the Single Product template,
	 * a Product Collection / Query Loop item, the editor's preview of a
	 * product), and finally the single product page being viewed. Whatever
	 * wins has to be a product; anything else resolves to nothing.
	 *
	 * The `postId` context is ignored for a block at the root of an archive,
	 * search, or posts-page view: WordPress primes the global post — and
	 * with it render_block()'s default context — to the FIRST result before
	 * any block renders, so the shop page would otherwise present its first
	 * product's FAQ as if it were the page's. A `queryId` context marks a
	 * block inside a Query Loop / Product Collection, whose `postId` is the
	 * item being rendered. (`queryId` only proves "some descendant of a
	 * query block", not "inside its item template" — the free plugin's
	 * known limitation, accepted here too.)
	 *
	 * Only the product's ID matters downstream: what gets listed is the
	 * published, unprotected content linked to it, so the product's own
	 * status isn't checked.
	 *
	 * @param mixed               $product_id_attr The block's `productId` attribute (0 or absent: resolve from context).
	 * @param array<string,mixed> $context         The block's context (`postId`, `queryId`).
	 * @return int The product post ID, or 0.
	 */
	public static function for_block( $product_id_attr, array $context ): int {
		$product_id = is_numeric( $product_id_attr ) ? (int) $product_id_attr : 0;

		if ( $product_id <= 0 ) {
			if ( isset( $context['postId'] ) && is_numeric( $context['postId'] ) ) {
				if ( ( is_archive() || is_search() || is_home() ) && ! isset( $context['queryId'] ) ) {
					return 0;
				}

				$product_id = (int) $context['postId'];
			} else {
				return self::current_product_id();
			}
		}

		if ( $product_id <= 0 || Link_Resolver::PRODUCT_POST_TYPE !== get_post_type( $product_id ) ) {
			return 0;
		}

		return $product_id;
	}
}
