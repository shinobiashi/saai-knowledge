<?php
/**
 * Registers the add-on's product-linking post meta.
 *
 * @package SAAI\KnowledgeWoo
 */

namespace SAAI\KnowledgeWoo;

defined( 'ABSPATH' ) || exit;

/**
 * Registers saai_linked_products and saai_linked_product_cats on the free
 * plugin's three content types.
 *
 * Both keys are deliberately multi-value (`single => false`), i.e. one meta
 * row per linked ID, so "every FAQ linked to this product" is a single
 * `meta_query` on an indexed `meta_value` rather than a LIKE against a
 * serialized array (docs/DESIGN.md section 3.3).
 */
final class Post_Meta {

	/**
	 * Meta key holding a linked product ID (one row per product).
	 *
	 * @var string
	 */
	public const LINKED_PRODUCTS = 'saai_linked_products';

	/**
	 * Meta key holding a linked product category term ID (one row per term).
	 *
	 * @var string
	 */
	public const LINKED_PRODUCT_CATS = 'saai_linked_product_cats';

	/**
	 * Content post types that can carry the linking meta.
	 *
	 * @var string[]
	 */
	public const POST_TYPES = array( 'saai_faq', 'saai_kb', 'saai_glossary' );

	/**
	 * Hooks post meta registration into WordPress.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_meta' ) );
	}

	/**
	 * Registers both linking meta keys for every content post type.
	 */
	public function register_post_meta(): void {
		foreach ( self::POST_TYPES as $post_type ) {
			foreach ( array( self::LINKED_PRODUCTS, self::LINKED_PRODUCT_CATS ) as $meta_key ) {
				register_post_meta(
					$post_type,
					$meta_key,
					array(
						'type'              => 'integer',
						// One row per linked ID; see the class docblock. A
						// `default` is deliberately omitted: it is only ever
						// read for the *first* entry of a multi-value key, so
						// it could not express "no links" and would blur the
						// difference between an unset key and a real value
						// (the same trap Term_Order's saai_order documents).
						'single'            => false,
						'show_in_rest'      => true,
						'sanitize_callback' => 'absint',
						'auth_callback'     => array( $this, 'can_edit_post_meta' ),
					)
				);
			}
		}
	}

	/**
	 * Restricts meta read/write access to users who can edit the post.
	 *
	 * Signature matches the `auth_{$object_type}_meta_{$meta_key}` filter
	 * WordPress invokes this callback through (see `map_meta_cap()` in
	 * wp-includes/capabilities.php). The incoming `$allowed` is intentionally
	 * ignored: it only reflects `is_protected_meta()`, not a real permission
	 * decision, so `current_user_can()` is the actual authorization check.
	 *
	 * @param bool     $allowed   Whether the meta key is allowed to be accessed. Unused.
	 * @param string   $meta_key  The meta key. Unused.
	 * @param int      $post_id   Post ID.
	 * @param int      $user_id   User ID. Unused.
	 * @param string   $cap       Capability name. Unused.
	 * @param string[] $caps      Array of the user's capabilities. Unused.
	 * @return bool
	 */
	public function can_edit_post_meta( bool $allowed, string $meta_key, int $post_id, int $user_id = 0, string $cap = '', array $caps = array() ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- kept to match the auth_{$object_type}_meta_{$meta_key} filter signature.
		return current_user_can( 'edit_post', $post_id );
	}
}
