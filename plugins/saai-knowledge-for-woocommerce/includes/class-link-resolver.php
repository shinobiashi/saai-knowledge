<?php
/**
 * Resolves the product <-> content links stored in the add-on's post meta.
 *
 * @package SAAI\KnowledgeWoo
 */

namespace SAAI\KnowledgeWoo;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the product-linking meta, and resolves "what content
 * applies to this product" per docs/DESIGN.md section 6.1:
 * content linked to the product ID, union content linked to any of the
 * product's categories (ancestors included), deduplicated, in menu_order.
 *
 * Deliberately built on core APIs only — wp_get_object_terms(),
 * get_ancestors(), WP_Query — and never on wc_get_product() or any other
 * WooCommerce function. WooCommerce is absent from the PHPUnit bootstrap
 * (tests/bootstrap.php loads only the two SAAI plugins), so this keeps the
 * resolution logic exercisable against stand-in `product` / `product_cat`
 * registrations instead of needing a WooCommerce install.
 */
final class Link_Resolver {

	/**
	 * WooCommerce's product post type.
	 *
	 * @var string
	 */
	public const PRODUCT_POST_TYPE = 'product';

	/**
	 * WooCommerce's product category taxonomy.
	 *
	 * @var string
	 */
	public const PRODUCT_TAXONOMY = 'product_cat';

	/**
	 * Returns the content post types that can carry linking meta and are
	 * actually registered this request.
	 *
	 * @return string[]
	 */
	public function content_post_types(): array {
		return array_values( array_filter( Post_Meta::POST_TYPES, 'post_type_exists' ) );
	}

	/**
	 * Returns a product's own product_cat term IDs plus every ancestor of
	 * those terms.
	 *
	 * Ancestors are included so that linking content to a parent category
	 * covers the products filed under its children — the resolution rule
	 * walks *up* from the product, which is why linking never has to be
	 * repeated for each descendant category.
	 *
	 * @param int $product_id Product post ID.
	 * @return int[] Deduplicated term IDs. Empty if the product has no
	 *               categories, or if product_cat isn't registered (i.e.
	 *               WooCommerce is inactive).
	 */
	public function category_ids_for_product( int $product_id ): array {
		if ( $product_id <= 0 || ! taxonomy_exists( self::PRODUCT_TAXONOMY ) ) {
			return array();
		}

		$term_ids = wp_get_object_terms( $product_id, self::PRODUCT_TAXONOMY, array( 'fields' => 'ids' ) );

		if ( is_wp_error( $term_ids ) || ! is_array( $term_ids ) ) {
			return array();
		}

		$resolved = array();

		foreach ( $term_ids as $term_id ) {
			$term_id = (int) $term_id;

			if ( $term_id <= 0 ) {
				continue;
			}

			$resolved[] = $term_id;

			foreach ( get_ancestors( $term_id, self::PRODUCT_TAXONOMY, 'taxonomy' ) as $ancestor_id ) {
				$resolved[] = (int) $ancestor_id;
			}
		}

		return array_values( array_unique( $resolved ) );
	}

	/**
	 * Resolves every content post that applies to a product: linked directly
	 * to it, or linked to one of its categories (ancestors included).
	 *
	 * @param int                  $product_id Product post ID.
	 * @param array<string, mixed> $args       Optional WP_Query overrides. `post_type`
	 *                                         is intersected with the registered content
	 *                                         types; anything else is merged over the
	 *                                         defaults (`post_status` defaults to
	 *                                         'publish').
	 * @return int[] Content post IDs, deduplicated, ordered by menu_order then title.
	 */
	public function content_ids_for_product( int $product_id, array $args = array() ): array {
		if ( $product_id <= 0 ) {
			return array();
		}

		$meta_query = array(
			'relation' => 'OR',
			$this->product_meta_clause( $product_id ),
		);

		$category_ids = $this->category_ids_for_product( $product_id );

		if ( array() !== $category_ids ) {
			$meta_query[] = array(
				'key'     => Post_Meta::LINKED_PRODUCT_CATS,
				'value'   => $category_ids,
				'compare' => 'IN',
			);
		}

		return $this->query_content( $meta_query, $args );
	}

	/**
	 * Resolves only the content linked directly to the product ID, ignoring
	 * category links.
	 *
	 * The product edit screen needs this to tell an editable direct link from
	 * a category link it can only show read-only.
	 *
	 * @param int                  $product_id Product post ID.
	 * @param array<string, mixed> $args       Optional WP_Query overrides; see content_ids_for_product().
	 * @return int[] Content post IDs, ordered by menu_order then title.
	 */
	public function direct_content_ids_for_product( int $product_id, array $args = array() ): array {
		if ( $product_id <= 0 ) {
			return array();
		}

		return $this->query_content( array( $this->product_meta_clause( $product_id ) ), $args );
	}

	/**
	 * The product IDs a content post is linked to.
	 *
	 * @param int $post_id Content post ID.
	 * @return int[] Deduplicated, positive product IDs.
	 */
	public function product_ids_for_content( int $post_id ): array {
		return $this->meta_ids( $post_id, Post_Meta::LINKED_PRODUCTS );
	}

	/**
	 * The product category term IDs a content post is linked to.
	 *
	 * @param int $post_id Content post ID.
	 * @return int[] Deduplicated, positive term IDs.
	 */
	public function product_category_ids_for_content( int $post_id ): array {
		return $this->meta_ids( $post_id, Post_Meta::LINKED_PRODUCT_CATS );
	}

	/**
	 * Of a product's resolved categories, the ones that actually link the
	 * given content post — i.e. why this post showed up as inherited.
	 *
	 * @param int   $post_id               Content post ID.
	 * @param int[] $product_category_ids  A product's resolved category IDs, from category_ids_for_product().
	 * @return int[] Term IDs present in both sets.
	 */
	public function linking_category_ids( int $post_id, array $product_category_ids ): array {
		if ( array() === $product_category_ids ) {
			return array();
		}

		return array_values( array_intersect( $this->product_category_ids_for_content( $post_id ), $product_category_ids ) );
	}

	/**
	 * Links a content post to a product by adding one meta row.
	 *
	 * Together with unlink_product() this is the single write path for
	 * product links, which is what makes the content-side editor panel and
	 * the product-side meta box converge on the same rows no matter which
	 * screen the edit came from (Issue #21's acceptance criterion).
	 *
	 * @param int $post_id    Content post ID.
	 * @param int $product_id Product post ID.
	 * @return bool True when a row was added; false when the arguments were
	 *              unusable or the link already existed.
	 */
	public function link_product( int $post_id, int $product_id ): bool {
		if ( $product_id <= 0 || ! $this->is_content_post( $post_id ) ) {
			return false;
		}

		if ( in_array( $product_id, $this->product_ids_for_content( $post_id ), true ) ) {
			return false;
		}

		return (bool) add_post_meta( $post_id, Post_Meta::LINKED_PRODUCTS, $product_id );
	}

	/**
	 * Removes the meta row linking a content post to a product, leaving any
	 * category links on that post untouched.
	 *
	 * @param int $post_id    Content post ID.
	 * @param int $product_id Product post ID.
	 * @return bool True when a row was deleted.
	 */
	public function unlink_product( int $post_id, int $product_id ): bool {
		if ( $product_id <= 0 || ! $this->is_content_post( $post_id ) ) {
			return false;
		}

		return delete_post_meta( $post_id, Post_Meta::LINKED_PRODUCTS, $product_id );
	}

	/**
	 * Whether a post ID is one of the content types that carries linking meta.
	 *
	 * Guards the write path: the meta keys are only registered (and therefore
	 * only sanitized and REST-exposed) for those types, so writing them
	 * anywhere else would create rows nothing reads back.
	 *
	 * @param int $post_id Post ID.
	 */
	public function is_content_post( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		return in_array( get_post_type( $post_id ), Post_Meta::POST_TYPES, true );
	}

	/**
	 * The meta clause matching content linked directly to a product.
	 *
	 * No `type` is given on purpose, so the comparison stays a plain string
	 * match against the indexed `meta_value` column. Casting to NUMERIC would
	 * defeat that index on what becomes a per-product-page query in M5-3.
	 * Values written through this class and through REST are normalized by
	 * Post_Meta's absint sanitize_callback, so the stored form is always the
	 * canonical decimal string.
	 *
	 * @param int $product_id Product post ID.
	 * @return array<string, mixed>
	 */
	private function product_meta_clause( int $product_id ): array {
		return array(
			'key'     => Post_Meta::LINKED_PRODUCTS,
			'value'   => (string) $product_id,
			'compare' => '=',
		);
	}

	/**
	 * Runs the content query for a prepared meta_query.
	 *
	 * @param array<int|string, mixed> $meta_query WP_Meta_Query clauses.
	 * @param array<string, mixed>     $args       Caller overrides.
	 * @return int[]
	 */
	private function query_content( array $meta_query, array $args ): array {
		$post_types = $this->content_post_types();

		if ( isset( $args['post_type'] ) ) {
			$requested  = is_array( $args['post_type'] ) ? $args['post_type'] : array( $args['post_type'] );
			$post_types = array_values( array_intersect( $post_types, array_map( 'strval', $requested ) ) );
		}

		unset( $args['post_type'] );

		if ( array() === $post_types ) {
			return array();
		}

		$query_args = array_merge(
			array(
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => -1,
				'orderby'             => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			),
			$args,
			array(
				'post_type'  => $post_types,
				'fields'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- resolving product links from content-side meta is this class's documented purpose (docs/DESIGN.md section 3.3).
				'meta_query' => $meta_query,
			)
		);

		// `fields => 'ids'` makes WP_Query::$posts a list of post IDs; the
		// intval() pass only normalizes the string IDs some object caches
		// return.
		return array_map( 'intval', ( new \WP_Query( $query_args ) )->posts );
	}

	/**
	 * Reads a multi-value meta key and normalizes it to positive integers.
	 *
	 * Rows holding 0, a negative number, or a non-scalar are dropped rather
	 * than repaired: they can never match a real product or term, and
	 * silently rewriting another party's rows here would hide the problem.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return int[]
	 */
	private function meta_ids( int $post_id, string $meta_key ): array {
		if ( $post_id <= 0 ) {
			return array();
		}

		$rows = get_post_meta( $post_id, $meta_key, false );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$ids = array();

		foreach ( $rows as $row ) {
			if ( ! is_scalar( $row ) ) {
				continue;
			}

			$id = (int) $row;

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
