<?php
/**
 * Tests for the product <-> content link resolution rule.
 *
 * @package SAAI\KnowledgeWoo
 */

use SAAI\KnowledgeWoo\Link_Resolver;
use SAAI\KnowledgeWoo\Post_Meta;

/**
 * Class Test_Woo_Link_Resolver.
 *
 * WooCommerce is absent from the PHPUnit bootstrap, so `product` and
 * `product_cat` are registered here as stand-ins. Link_Resolver only ever
 * touches them through core APIs (wp_get_object_terms(), get_ancestors(),
 * WP_Query), so these exercise the same code path a real WooCommerce install
 * does. The one difference is that the stand-in product type uses the default
 * `post` capability mapping instead of WooCommerce's `product` one, which
 * doesn't matter here: the resolver makes no capability checks.
 */
class Test_Woo_Link_Resolver extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Link_Resolver
	 */
	private $resolver;

	/**
	 * Whether this test registered the stand-in product post type, so
	 * tear_down() only unregisters what it created.
	 *
	 * @var bool
	 */
	private $registered_post_type = false;

	/**
	 * Whether this test registered the stand-in product_cat taxonomy.
	 *
	 * @var bool
	 */
	private $registered_taxonomy = false;

	/**
	 * Registers the linking meta and the WooCommerce stand-ins.
	 */
	public function set_up() {
		parent::set_up();

		// The core test framework unregisters every meta key after each test.
		( new Post_Meta() )->register_post_meta();

		if ( ! post_type_exists( Link_Resolver::PRODUCT_POST_TYPE ) ) {
			register_post_type(
				Link_Resolver::PRODUCT_POST_TYPE,
				array(
					'public' => true,
					'label'  => 'Products',
				)
			);
			$this->registered_post_type = true;
		}

		if ( ! taxonomy_exists( Link_Resolver::PRODUCT_TAXONOMY ) ) {
			register_taxonomy(
				Link_Resolver::PRODUCT_TAXONOMY,
				array( Link_Resolver::PRODUCT_POST_TYPE ),
				array(
					'public'       => true,
					'hierarchical' => true,
					'label'        => 'Product categories',
				)
			);
			$this->registered_taxonomy = true;
		}

		$this->resolver = new Link_Resolver();
	}

	/**
	 * Unregisters only the stand-ins this test created.
	 */
	public function tear_down() {
		if ( $this->registered_taxonomy ) {
			unregister_taxonomy( Link_Resolver::PRODUCT_TAXONOMY );
			$this->registered_taxonomy = false;
		}

		if ( $this->registered_post_type ) {
			unregister_post_type( Link_Resolver::PRODUCT_POST_TYPE );
			$this->registered_post_type = false;
		}

		parent::tear_down();
	}

	/**
	 * Creates a product, optionally filed under the given categories.
	 *
	 * @param int[] $term_ids Product category term IDs.
	 * @return int Product post ID.
	 */
	private function create_product( array $term_ids = array() ): int {
		$product_id = self::factory()->post->create( array( 'post_type' => Link_Resolver::PRODUCT_POST_TYPE ) );

		if ( array() !== $term_ids ) {
			wp_set_object_terms( $product_id, $term_ids, Link_Resolver::PRODUCT_TAXONOMY );
		}

		return $product_id;
	}

	/**
	 * Creates a product category term.
	 *
	 * @param string $name      Term name.
	 * @param int    $parent_id Parent term ID.
	 * @return int Term ID.
	 */
	private function create_category( string $name, int $parent_id = 0 ): int {
		$term = wp_insert_term(
			$name,
			Link_Resolver::PRODUCT_TAXONOMY,
			array( 'parent' => $parent_id )
		);

		$this->assertIsArray( $term, 'term creation failed for ' . $name );

		return (int) $term['term_id'];
	}

	/**
	 * Creates a content post with the given links already stored.
	 *
	 * @param string               $post_type Content post type.
	 * @param array<string, mixed> $args      Extra factory args.
	 * @param int[]                $products  Product IDs to link.
	 * @param int[]                $cats      Product category term IDs to link.
	 * @return int Content post ID.
	 */
	private function create_content( string $post_type, array $args = array(), array $products = array(), array $cats = array() ): int {
		$post_id = self::factory()->post->create( array_merge( array( 'post_type' => $post_type ), $args ) );

		foreach ( $products as $product_id ) {
			add_post_meta( $post_id, Post_Meta::LINKED_PRODUCTS, $product_id );
		}

		foreach ( $cats as $term_id ) {
			add_post_meta( $post_id, Post_Meta::LINKED_PRODUCT_CATS, $term_id );
		}

		return $post_id;
	}

	/**
	 * A product's categories resolve to themselves plus every ancestor, once.
	 */
	public function test_category_ids_include_ancestors_and_are_deduplicated() {
		$parent     = $this->create_category( 'Apparel' );
		$child      = $this->create_category( 'Hoodies', $parent );
		$grandchild = $this->create_category( 'Zip hoodies', $child );

		// Filed under both the grandchild and the child, so the child appears
		// once as an own term and once as an ancestor.
		$product_id = $this->create_product( array( $grandchild, $child ) );

		$resolved = $this->resolver->category_ids_for_product( $product_id );

		sort( $resolved );
		$expected = array( $parent, $child, $grandchild );
		sort( $expected );

		$this->assertSame( $expected, $resolved );
	}

	/**
	 * A product with no categories, and an invalid ID, resolve to nothing.
	 */
	public function test_category_ids_are_empty_without_categories() {
		$this->assertSame( array(), $this->resolver->category_ids_for_product( $this->create_product() ) );
		$this->assertSame( array(), $this->resolver->category_ids_for_product( 0 ) );
		$this->assertSame( array(), $this->resolver->category_ids_for_product( -5 ) );
	}

	/**
	 * Content linked straight to the product resolves.
	 */
	public function test_direct_link_resolves() {
		$product_id = $this->create_product();
		$faq_id     = $this->create_content( 'saai_faq', array(), array( $product_id ) );
		$other_id   = $this->create_content( 'saai_faq', array(), array( $product_id + 1000 ) );

		$this->assertSame( array( $faq_id ), $this->resolver->content_ids_for_product( $product_id ) );
		$this->assertNotContains( $other_id, $this->resolver->content_ids_for_product( $product_id ) );
	}

	/**
	 * A product in a descendant category inherits the ancestor's links.
	 *
	 * This is the whole point of walking ancestors: linking content to
	 * "Apparel" must cover a product filed only under "Apparel > Hoodies >
	 * Zip hoodies".
	 */
	public function test_ancestor_category_link_resolves_for_a_descendant_product() {
		$parent     = $this->create_category( 'Apparel' );
		$child      = $this->create_category( 'Hoodies', $parent );
		$grandchild = $this->create_category( 'Zip hoodies', $child );

		$product_id = $this->create_product( array( $grandchild ) );
		$kb_id      = $this->create_content( 'saai_kb', array(), array(), array( $parent ) );

		$this->assertSame( array( $kb_id ), $this->resolver->content_ids_for_product( $product_id ) );
	}

	/**
	 * A sibling category's links do not leak onto the product.
	 */
	public function test_sibling_category_link_does_not_resolve() {
		$parent  = $this->create_category( 'Apparel' );
		$hoodies = $this->create_category( 'Hoodies', $parent );
		$caps    = $this->create_category( 'Caps', $parent );

		$product_id = $this->create_product( array( $hoodies ) );
		$this->create_content( 'saai_kb', array(), array(), array( $caps ) );

		$this->assertSame( array(), $this->resolver->content_ids_for_product( $product_id ) );
	}

	/**
	 * Content matching both clauses is returned once.
	 */
	public function test_content_linked_both_ways_appears_once() {
		$category   = $this->create_category( 'Apparel' );
		$product_id = $this->create_product( array( $category ) );
		$faq_id     = $this->create_content( 'saai_faq', array(), array( $product_id ), array( $category ) );

		$this->assertSame( array( $faq_id ), $this->resolver->content_ids_for_product( $product_id ) );
	}

	/**
	 * Results come back in menu_order, then title.
	 */
	public function test_results_are_ordered_by_menu_order_then_title() {
		$product_id = $this->create_product();

		$third  = $this->create_content(
			'saai_kb',
			array(
				'post_title' => 'Beta',
				'menu_order' => 5,
			),
			array( $product_id )
		);
		$first  = $this->create_content(
			'saai_kb',
			array(
				'post_title' => 'Zulu',
				'menu_order' => 1,
			),
			array( $product_id )
		);
		$second = $this->create_content(
			'saai_kb',
			array(
				'post_title' => 'Alpha',
				'menu_order' => 5,
			),
			array( $product_id )
		);

		$this->assertSame(
			array( $first, $second, $third ),
			$this->resolver->content_ids_for_product( $product_id )
		);
	}

	/**
	 * Only published content resolves by default; `post_status` opts in.
	 */
	public function test_unpublished_content_is_excluded_by_default() {
		$product_id = $this->create_product();
		$published  = $this->create_content( 'saai_faq', array( 'post_title' => 'Live' ), array( $product_id ) );
		$draft      = $this->create_content(
			'saai_faq',
			array(
				'post_title'  => 'Draft',
				'post_status' => 'draft',
			),
			array( $product_id )
		);

		$this->assertSame( array( $published ), $this->resolver->content_ids_for_product( $product_id ) );

		$with_drafts = $this->resolver->content_ids_for_product( $product_id, array( 'post_status' => 'any' ) );

		$this->assertContains( $published, $with_drafts );
		$this->assertContains( $draft, $with_drafts );
	}

	/**
	 * Password-protected content is excluded.
	 */
	public function test_password_protected_content_is_excluded() {
		$product_id = $this->create_product();
		$this->create_content(
			'saai_faq',
			array( 'post_password' => 'secret' ),
			array( $product_id )
		);

		$this->assertSame( array(), $this->resolver->content_ids_for_product( $product_id ) );
	}

	/**
	 * `post_type` narrows the result and is intersected with the known types.
	 */
	public function test_post_type_argument_narrows_and_is_constrained() {
		$product_id = $this->create_product();
		$faq_id     = $this->create_content( 'saai_faq', array(), array( $product_id ) );
		$kb_id      = $this->create_content( 'saai_kb', array(), array( $product_id ) );

		$this->assertSame( array( $faq_id ), $this->resolver->content_ids_for_product( $product_id, array( 'post_type' => 'saai_faq' ) ) );
		$this->assertSame( array( $kb_id ), $this->resolver->content_ids_for_product( $product_id, array( 'post_type' => array( 'saai_kb' ) ) ) );
		$this->assertSame( array(), $this->resolver->content_ids_for_product( $product_id, array( 'post_type' => 'page' ) ) );
	}

	/**
	 * An unrelated or invalid product resolves to nothing.
	 */
	public function test_unrelated_and_invalid_products_resolve_to_nothing() {
		$product_id = $this->create_product();
		$other_id   = $this->create_product();

		$this->create_content( 'saai_faq', array(), array( $product_id ) );

		$this->assertSame( array(), $this->resolver->content_ids_for_product( $other_id ) );
		$this->assertSame( array(), $this->resolver->content_ids_for_product( 0 ) );
		$this->assertSame( array(), $this->resolver->direct_content_ids_for_product( 0 ) );
	}

	/**
	 * The direct-only lookup ignores category links.
	 */
	public function test_direct_lookup_excludes_category_links() {
		$category   = $this->create_category( 'Apparel' );
		$product_id = $this->create_product( array( $category ) );

		$direct_id    = $this->create_content( 'saai_faq', array(), array( $product_id ) );
		$inherited_id = $this->create_content( 'saai_faq', array(), array(), array( $category ) );

		$direct = $this->resolver->direct_content_ids_for_product( $product_id );

		$this->assertSame( array( $direct_id ), $direct );
		$this->assertContains( $inherited_id, $this->resolver->content_ids_for_product( $product_id ) );
	}

	/**
	 * Meta reads drop unusable rows and duplicates.
	 */
	public function test_meta_reads_are_normalized() {
		$post_id = $this->create_content( 'saai_glossary' );

		add_post_meta( $post_id, Post_Meta::LINKED_PRODUCTS, 7 );
		add_post_meta( $post_id, Post_Meta::LINKED_PRODUCTS, 7 );
		add_post_meta( $post_id, Post_Meta::LINKED_PRODUCTS, 0 );
		add_post_meta( $post_id, Post_Meta::LINKED_PRODUCT_CATS, 3 );

		$this->assertSame( array( 7 ), $this->resolver->product_ids_for_content( $post_id ) );
		$this->assertSame( array( 3 ), $this->resolver->product_category_ids_for_content( $post_id ) );
		$this->assertSame( array(), $this->resolver->product_ids_for_content( 0 ) );
	}

	/**
	 * Linking adds exactly one row and is idempotent.
	 */
	public function test_link_product_is_idempotent() {
		$product_id = $this->create_product();
		$faq_id     = $this->create_content( 'saai_faq' );

		$this->assertTrue( $this->resolver->link_product( $faq_id, $product_id ) );
		$this->assertFalse( $this->resolver->link_product( $faq_id, $product_id ) );
		$this->assertSame( array( (string) $product_id ), get_post_meta( $faq_id, Post_Meta::LINKED_PRODUCTS, false ) );
	}

	/**
	 * Unlinking removes that one product row and nothing else.
	 */
	public function test_unlink_product_removes_only_that_row() {
		$category   = $this->create_category( 'Apparel' );
		$product_id = $this->create_product( array( $category ) );
		$other_id   = $this->create_product();
		$faq_id     = $this->create_content( 'saai_faq', array(), array( $product_id, $other_id ), array( $category ) );

		$this->assertTrue( $this->resolver->unlink_product( $faq_id, $product_id ) );
		$this->assertSame( array( $other_id ), $this->resolver->product_ids_for_content( $faq_id ) );
		$this->assertSame( array( $category ), $this->resolver->product_category_ids_for_content( $faq_id ) );
		$this->assertFalse( $this->resolver->unlink_product( $faq_id, $product_id ) );
	}

	/**
	 * Writes are refused for post types that don't carry the meta.
	 */
	public function test_writes_are_refused_outside_the_content_types() {
		$product_id = $this->create_product();
		$page_id    = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertFalse( $this->resolver->link_product( $page_id, $product_id ) );
		$this->assertFalse( $this->resolver->unlink_product( $page_id, $product_id ) );
		$this->assertFalse( $this->resolver->link_product( 0, $product_id ) );
		$this->assertSame( array(), get_post_meta( $page_id, Post_Meta::LINKED_PRODUCTS, false ) );
	}

	/**
	 * A non-positive product ID is refused.
	 */
	public function test_writes_are_refused_for_an_invalid_product_id() {
		$faq_id = $this->create_content( 'saai_faq' );

		$this->assertFalse( $this->resolver->link_product( $faq_id, 0 ) );
		$this->assertFalse( $this->resolver->unlink_product( $faq_id, -1 ) );
	}

	/**
	 * The "via" lookup reports only the categories both sides share.
	 */
	public function test_linking_category_ids_intersects_both_sides() {
		$parent     = $this->create_category( 'Apparel' );
		$child      = $this->create_category( 'Hoodies', $parent );
		$unrelated  = $this->create_category( 'Mugs' );
		$product_id = $this->create_product( array( $child ) );

		$kb_id = $this->create_content( 'saai_kb', array(), array(), array( $parent, $unrelated ) );

		$this->assertSame(
			array( $parent ),
			$this->resolver->linking_category_ids( $kb_id, $this->resolver->category_ids_for_product( $product_id ) )
		);
		$this->assertSame( array(), $this->resolver->linking_category_ids( $kb_id, array() ) );
	}

	/**
	 * With product_cat unregistered — WooCommerce inactive — category
	 * resolution degrades to "no categories" instead of erroring.
	 */
	public function test_category_resolution_degrades_without_the_taxonomy() {
		if ( ! $this->registered_taxonomy ) {
			$this->markTestSkipped( 'product_cat is registered by a real WooCommerce install here.' );
		}

		$category   = $this->create_category( 'Apparel' );
		$product_id = $this->create_product( array( $category ) );
		$kb_id      = $this->create_content( 'saai_kb', array(), array(), array( $category ) );

		$this->assertSame( array( $kb_id ), $this->resolver->content_ids_for_product( $product_id ) );

		unregister_taxonomy( Link_Resolver::PRODUCT_TAXONOMY );
		$this->registered_taxonomy = false;

		$this->assertSame( array(), $this->resolver->category_ids_for_product( $product_id ) );
		$this->assertSame( array(), $this->resolver->content_ids_for_product( $product_id ) );
	}

	/**
	 * Only the three content types count as linkable content.
	 */
	public function test_is_content_post_recognizes_the_content_types() {
		foreach ( Post_Meta::POST_TYPES as $post_type ) {
			$post_id = self::factory()->post->create( array( 'post_type' => $post_type ) );
			$this->assertTrue( $this->resolver->is_content_post( $post_id ), $post_type );
		}

		$this->assertFalse( $this->resolver->is_content_post( self::factory()->post->create() ) );
		$this->assertFalse( $this->resolver->is_content_post( 0 ) );
	}
}
