<?php
/**
 * Adds product metadata to the free plugin's RAG export records.
 *
 * @package SAAI\KnowledgeWoo
 */

namespace SAAI\KnowledgeWoo;

defined( 'ABSPATH' ) || exit;

/**
 * Attaches the products and product categories a content post is linked to
 * onto its export record, through the free plugin's public
 * `saai_export_record` filter (docs/DESIGN.md section 7.4,
 * docs/DESIGN-HOOKS-API.md section 3.4). That is what lets a support AI fed
 * from the export answer from "the FAQs for this product" only.
 *
 * The lists are the links as stored on the content, not the resolved
 * product -> content rule of docs/DESIGN.md section 6.1: a category link is
 * reported as that category, not expanded into its products or descendants,
 * so a consumer applies "linked to the product, or to one of its categories
 * or their ancestors" on its own side.
 *
 * Built on core functions only (no wc_get_product()), like Link_Resolver:
 * WooCommerce is absent from the PHPUnit bootstrap, and stand-in `product` /
 * `product_cat` registrations exercise the same code path.
 */
final class Export_Metadata {

	/**
	 * The record key holding the linked products.
	 *
	 * @var string
	 */
	public const PRODUCTS_KEY = 'products';

	/**
	 * The record key holding the linked product categories.
	 *
	 * @var string
	 */
	public const PRODUCT_CATEGORIES_KEY = 'product_categories';

	/**
	 * WooCommerce's product SKU meta key.
	 *
	 * @var string
	 */
	private const SKU_META_KEY = '_sku';

	/**
	 * Reads the link meta.
	 *
	 * @var Link_Resolver
	 */
	private $links;

	/**
	 * Constructs the service.
	 *
	 * @param Link_Resolver $links The product <-> content link resolver.
	 */
	public function __construct( Link_Resolver $links ) {
		$this->links = $links;
	}

	/**
	 * Hooks the export record filter.
	 */
	public function register(): void {
		add_filter( 'saai_export_record', array( $this, 'filter_record' ), 10, 3 );
	}

	/**
	 * Adds `products` / `product_categories` to one export record.
	 *
	 * Each key is added only when at least one of its links resolves, so an
	 * unlinked post's record keeps exactly the free plugin's shape (Issue
	 * #27's acceptance criterion), and a post linked to products only gets
	 * no empty `product_categories`.
	 *
	 * Untyped on purpose: an earlier `saai_export_record` callback can hand
	 * over anything, and a strict signature would turn that into a TypeError
	 * that kills the whole export instead of leaving the record alone.
	 *
	 * @param mixed $record The record, shape per docs/DESIGN.md section 7.4.
	 * @param mixed $post   The post the record was built from.
	 * @param mixed $format 'json', 'jsonl', or 'csv'.
	 * @return mixed
	 */
	public function filter_record( $record, $post, $format ) {
		if ( ! is_array( $record ) || ! $post instanceof \WP_Post || ! $this->links->is_content_post( $post->ID ) ) {
			return $record;
		}

		$products   = $this->products( $this->links->product_ids_for_content( $post->ID ) );
		$categories = $this->product_categories( $this->links->product_category_ids_for_content( $post->ID ) );

		if ( array() !== $products ) {
			$record[ self::PRODUCTS_KEY ] = $this->for_format( $products, $format );
		}

		if ( array() !== $categories ) {
			$record[ self::PRODUCT_CATEGORIES_KEY ] = $this->for_format( $categories, $format );
		}

		return $record;
	}

	/**
	 * Shapes the linked products, dropping every one that isn't public.
	 *
	 * The export endpoint is unauthenticated, so only a published product
	 * without a password may contribute its name and SKU: a draft, private,
	 * scheduled, trashed, or deleted product — or an ID that isn't a product
	 * at all — is silently left out, the same way a dangling link ID is
	 * harmless everywhere else (docs/DESIGN.md section 6.1).
	 *
	 * @param int[] $product_ids Linked product IDs, in stored order.
	 * @return array<int, array{id: int, sku: string, name: string}>
	 */
	private function products( array $product_ids ): array {
		if ( array() === $product_ids ) {
			return array();
		}

		// One query that applies the public-only rule in SQL and skips IDs
		// whose product is gone, plus one for the found products' meta (the
		// SKU). Not _prime_post_caches() + get_post(): a permanently deleted
		// product is not cached as missing, so each dangling ID — and links
		// to auto-emptied trash pile up over time — would cost its own query.
		$found = get_posts(
			array(
				'post_type'              => Link_Resolver::PRODUCT_POST_TYPE,
				'post_status'            => 'publish',
				'has_password'           => false,
				'post__in'               => $product_ids,
				'orderby'                => 'post__in',
				'posts_per_page'         => count( $product_ids ),
				'update_post_term_cache' => false,
			)
		);

		$products = array();

		foreach ( $found as $product ) {
			if ( ! $product instanceof \WP_Post ) {
				continue;
			}

			$sku = get_post_meta( $product->ID, self::SKU_META_KEY, true );

			$products[] = array(
				'id'   => $product->ID,
				'sku'  => is_scalar( $sku ) ? (string) $sku : '',
				// Same decoding as the free plugin's own `title` field: the
				// export is plain text, while get_the_title() returns the
				// HTML character references the_title adds.
				'name' => html_entity_decode( wp_strip_all_tags( get_the_title( $product ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ),
			);
		}

		return $products;
	}

	/**
	 * Shapes the linked product categories, dropping deleted terms and IDs
	 * that belong to another taxonomy.
	 *
	 * @param int[] $term_ids Linked product_cat term IDs, in stored order.
	 * @return array<int, array{id: int, slug: string, name: string}>
	 */
	private function product_categories( array $term_ids ): array {
		if ( array() === $term_ids ) {
			return array();
		}

		_prime_term_caches( $term_ids, false );

		$categories = array();

		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id, Link_Resolver::PRODUCT_TAXONOMY );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$categories[] = array(
				'id'   => $term->term_id,
				// As stored, like core REST's `slug`: a non-ASCII slug stays
				// percent-encoded, the form its URLs use.
				'slug' => $term->slug,
				'name' => html_entity_decode( wp_strip_all_tags( $term->name ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ),
			);
		}

		return $categories;
	}

	/**
	 * The list as it should go into the record for the given format.
	 *
	 * The free plugin's CSV writer puts a scalar into the cell as-is but
	 * falls back to a plain wp_json_encode() for a nested array, which turns
	 * every non-ASCII product name into `\uXXXX` — unreadable in the
	 * spreadsheet the admin CSV download exists for. Encoding it here keeps
	 * the same JSON with the text intact. The cell starts with `[`, so the
	 * free plugin's formula-injection guard has nothing to prefix.
	 *
	 * @param array<int, array<string, mixed>> $items  The shaped list.
	 * @param mixed                            $format 'json', 'jsonl', or 'csv'.
	 * @return array<int, array<string, mixed>>|string
	 */
	private function for_format( array $items, $format ) {
		if ( 'csv' !== $format ) {
			return $items;
		}

		$json = wp_json_encode( $items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return is_string( $json ) ? $json : $items;
	}
}
