<?php
/**
 * Tests for the product metadata added to RAG export records.
 *
 * @package SAAI\KnowledgeWoo
 */

use SAAI\KnowledgeWoo\Export_Metadata;
use SAAI\KnowledgeWoo\Link_Resolver;
use SAAI\KnowledgeWoo\Post_Meta;

/**
 * Class Test_Woo_Export_Metadata.
 */
class Test_Woo_Export_Metadata extends WP_UnitTestCase {

	/**
	 * Service under test. Each test that needs the filter calls register().
	 *
	 * @var Export_Metadata
	 */
	private $service;

	/**
	 * REST server the free plugin's export route is registered on.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * The global `$wp_rest_server` value before set_up() replaced it.
	 *
	 * @var WP_REST_Server|null
	 */
	private $original_wp_rest_server;

	/**
	 * Whether this test registered the stand-in product post type.
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
	 * Registers the linking meta, the WooCommerce stand-ins, and a fresh REST server.
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

		$this->service = new Export_Metadata( new Link_Resolver() );

		global $wp_rest_server;

		$this->original_wp_rest_server = $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		do_action( 'rest_api_init', $this->server );
	}

	/**
	 * Removes the filter and restores the REST server and the stand-ins.
	 */
	public function tear_down() {
		global $wp_rest_server;

		remove_filter( 'saai_export_record', array( $this->service, 'filter_record' ), 10 );

		$wp_rest_server = $this->original_wp_rest_server;

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
	 * Creates a stand-in product.
	 *
	 * @param string               $title Product title.
	 * @param string               $sku   SKU, or '' to store none.
	 * @param array<string, mixed> $args  Extra wp_insert_post() args.
	 * @return int Post ID.
	 */
	private function create_product( string $title, string $sku = '', array $args = array() ): int {
		$product_id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => Link_Resolver::PRODUCT_POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $title,
				),
				$args
			)
		);

		if ( '' !== $sku ) {
			update_post_meta( $product_id, '_sku', $sku );
		}

		return $product_id;
	}

	/**
	 * Creates a product category term.
	 *
	 * @param string $name Term name.
	 * @param string $slug Term slug, or '' to let WordPress derive one.
	 * @return int Term ID.
	 */
	private function create_category( string $name, string $slug = '' ): int {
		$args = array(
			'taxonomy' => Link_Resolver::PRODUCT_TAXONOMY,
			'name'     => $name,
		);

		if ( '' !== $slug ) {
			$args['slug'] = $slug;
		}

		return self::factory()->term->create( $args );
	}

	/**
	 * Creates a published content post of one of the free plugin's types.
	 *
	 * @param string $post_type saai_faq / saai_kb / saai_glossary.
	 * @param string $title     Post title.
	 * @return int Post ID.
	 */
	private function create_content( string $post_type, string $title ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => $post_type,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => '<!-- wp:paragraph --><p>Body of ' . $title . '</p><!-- /wp:paragraph -->',
			)
		);
	}

	/**
	 * Links a content post to products, one meta row each, in the given order.
	 *
	 * @param int   $content_id  Content post ID.
	 * @param int[] $product_ids Product post IDs.
	 */
	private function link_products( int $content_id, array $product_ids ): void {
		foreach ( $product_ids as $product_id ) {
			add_post_meta( $content_id, Post_Meta::LINKED_PRODUCTS, $product_id );
		}
	}

	/**
	 * Links a content post to product categories, one meta row each.
	 *
	 * @param int   $content_id Content post ID.
	 * @param int[] $term_ids   Product category term IDs.
	 */
	private function link_categories( int $content_id, array $term_ids ): void {
		foreach ( $term_ids as $term_id ) {
			add_post_meta( $content_id, Post_Meta::LINKED_PRODUCT_CATS, $term_id );
		}
	}

	/**
	 * Exports through the free plugin's REST route and returns the records by post ID.
	 *
	 * @param string $types Comma-separated type keys.
	 * @return array<int, array<string, mixed>>
	 */
	private function export_records( string $types = 'faq,kb,glossary' ): array {
		$request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$request->set_param( 'types', $types );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$records = array();

		foreach ( $response->get_data()['records'] as $record ) {
			$records[ $record['id'] ] = $record;
		}

		return $records;
	}

	/**
	 * The filter is attached at the default priority with all three arguments,
	 * since the CSV handling depends on the third one.
	 */
	public function test_register_hooks_the_export_record_filter() {
		global $wp_filter;

		$this->service->register();

		$this->assertSame( 10, has_filter( 'saai_export_record', array( $this->service, 'filter_record' ) ) );

		$accepted_args = null;

		foreach ( $wp_filter['saai_export_record']->callbacks[10] as $callback ) {
			if ( array( $this->service, 'filter_record' ) === $callback['function'] ) {
				$accepted_args = $callback['accepted_args'];
			}
		}

		$this->assertSame( 3, $accepted_args );
	}

	/**
	 * A linked FAQ's record carries its products (in stored order, deduplicated)
	 * and product categories in the documented shape.
	 */
	public function test_linked_faq_record_carries_products_and_categories() {
		$shirt    = $this->create_product( 'T-shirt', 'TS-1' );
		$mug      = $this->create_product( 'Mug' );
		$clothing = $this->create_category( 'Clothing', 'clothing' );
		$faq      = $this->create_content( 'saai_faq', 'Sizing' );

		// Stored order, not ID order — and a duplicate row that must collapse.
		$this->link_products( $faq, array( $mug, $shirt, $mug ) );
		$this->link_categories( $faq, array( $clothing ) );

		$this->service->register();

		$record = $this->export_records( 'faq' )[ $faq ];

		$this->assertSame(
			array(
				array(
					'id'   => $mug,
					'sku'  => '',
					'name' => 'Mug',
				),
				array(
					'id'   => $shirt,
					'sku'  => 'TS-1',
					'name' => 'T-shirt',
				),
			),
			$record['products']
		);

		$this->assertSame(
			array(
				array(
					'id'   => $clothing,
					'slug' => 'clothing',
					'name' => 'Clothing',
				),
			),
			$record['product_categories']
		);
	}

	/**
	 * Issue #27's acceptance criterion: an unlinked post's record is exactly
	 * what the free plugin alone exports — no keys, not even empty ones.
	 */
	public function test_unlinked_record_is_identical_to_the_free_plugin_alone() {
		$faq = $this->create_content( 'saai_faq', 'Shipping' );

		$without = $this->export_records( 'faq' )[ $faq ];

		$this->service->register();

		$with = $this->export_records( 'faq' )[ $faq ];

		$this->assertArrayNotHasKey( 'products', $with );
		$this->assertArrayNotHasKey( 'product_categories', $with );
		$this->assertSame( $without, $with );
	}

	/**
	 * Each key is added on its own: products alone don't bring an empty
	 * product_categories, and vice versa.
	 */
	public function test_each_key_is_added_only_when_it_has_entries() {
		$product  = $this->create_product( 'Kettle' );
		$category = $this->create_category( 'Kitchen' );
		$faq_one  = $this->create_content( 'saai_faq', 'Products only' );
		$faq_two  = $this->create_content( 'saai_faq', 'Categories only' );

		$this->link_products( $faq_one, array( $product ) );
		$this->link_categories( $faq_two, array( $category ) );

		$this->service->register();

		$records = $this->export_records( 'faq' );

		$this->assertArrayHasKey( 'products', $records[ $faq_one ] );
		$this->assertArrayNotHasKey( 'product_categories', $records[ $faq_one ] );
		$this->assertArrayNotHasKey( 'products', $records[ $faq_two ] );
		$this->assertArrayHasKey( 'product_categories', $records[ $faq_two ] );
	}

	/**
	 * The export is public, so a product that isn't published and
	 * password-free — or an ID that isn't a live product — never leaks its
	 * name or SKU. When nothing public remains, the key is left out.
	 */
	public function test_only_public_products_are_exported() {
		$public    = $this->create_product( 'Public', 'PUB' );
		$draft     = $this->create_product( 'Draft', 'DRA', array( 'post_status' => 'draft' ) );
		$private   = $this->create_product( 'Private', 'PRI', array( 'post_status' => 'private' ) );
		$scheduled = $this->create_product(
			'Scheduled',
			'SCH',
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', strtotime( '+1 week' ) ),
			)
		);
		$protected = $this->create_product( 'Protected', 'PRO', array( 'post_password' => 'secret' ) );
		$trashed   = $this->create_product( 'Trashed', 'TRA' );
		$deleted   = $this->create_product( 'Deleted', 'DEL' );
		$not_one   = self::factory()->post->create( array( 'post_title' => 'A blog post' ) );

		wp_trash_post( $trashed );
		wp_delete_post( $deleted, true );

		$mixed       = $this->create_content( 'saai_faq', 'Mixed' );
		$hidden_only = $this->create_content( 'saai_faq', 'Hidden only' );

		$this->link_products( $mixed, array( $draft, $public, $private, $scheduled, $protected, $trashed, $deleted, $not_one, 0 ) );
		$this->link_products( $hidden_only, array( $draft, $private, $deleted ) );

		$this->service->register();

		$records = $this->export_records( 'faq' );

		$this->assertSame( array( $public ), wp_list_pluck( $records[ $mixed ]['products'], 'id' ) );
		$this->assertArrayNotHasKey( 'products', $records[ $hidden_only ] );
	}

	/**
	 * Deleted terms and term IDs from another taxonomy are dropped.
	 */
	public function test_only_existing_product_categories_are_exported() {
		$kept     = $this->create_category( 'Kept' );
		$deleted  = $this->create_category( 'Deleted' );
		$post_tag = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Not a product category',
			)
		);

		wp_delete_term( $deleted, Link_Resolver::PRODUCT_TAXONOMY );

		$faq = $this->create_content( 'saai_faq', 'Terms' );
		$this->link_categories( $faq, array( $deleted, $post_tag, $kept ) );

		$this->service->register();

		$record = $this->export_records( 'faq' )[ $faq ];

		$this->assertSame( array( $kept ), wp_list_pluck( $record['product_categories'], 'id' ) );
	}

	/**
	 * Names are plain text like the free plugin's own `title`: the HTML
	 * character references that storage and the_title add are decoded.
	 */
	public function test_names_are_decoded_to_plain_text() {
		$product  = $this->create_product( 'Salt & Pepper' );
		$category = $this->create_category( 'Pots & Pans' );
		$faq      = $this->create_content( 'saai_faq', 'Entities' );

		$this->link_products( $faq, array( $product ) );
		$this->link_categories( $faq, array( $category ) );

		$this->service->register();

		$record = $this->export_records( 'faq' )[ $faq ];

		$this->assertSame( 'Salt & Pepper', $record['products'][0]['name'] );
		$this->assertSame( 'Pots & Pans', $record['product_categories'][0]['name'] );
	}

	/**
	 * KB articles and glossary terms carry the metadata too, not only FAQs.
	 */
	public function test_kb_and_glossary_records_carry_products() {
		$product  = $this->create_product( 'Router', 'RT-9' );
		$kb       = $this->create_content( 'saai_kb', 'Setting up the router' );
		$glossary = $this->create_content( 'saai_glossary', 'WAN' );

		$this->link_products( $kb, array( $product ) );
		$this->link_products( $glossary, array( $product ) );

		$this->service->register();

		$records = $this->export_records( 'kb,glossary' );

		$this->assertSame( array( $product ), wp_list_pluck( $records[ $kb ]['products'], 'id' ) );
		$this->assertSame( array( $product ), wp_list_pluck( $records[ $glossary ]['products'], 'id' ) );
	}

	/**
	 * For the CSV download the lists arrive as JSON strings with the text
	 * left readable (no \uXXXX), and decode back to the JSON/JSONL lists.
	 */
	public function test_csv_format_gets_unescaped_json_strings() {
		$product  = $this->create_product( 'Tシャツ', 'TS/1' );
		$category = $this->create_category( '衣類', 'clothing' );
		$faq      = $this->create_content( 'saai_faq', 'サイズ' );

		$this->link_products( $faq, array( $product ) );
		$this->link_categories( $faq, array( $category ) );

		$post = get_post( $faq );
		$json = $this->service->filter_record( array( 'id' => $faq ), $post, 'json' );
		$csv  = $this->service->filter_record( array( 'id' => $faq ), $post, 'csv' );

		$this->assertIsString( $csv['products'] );
		$this->assertIsString( $csv['product_categories'] );
		$this->assertStringContainsString( '"name":"Tシャツ"', $csv['products'] );
		$this->assertStringContainsString( '"sku":"TS/1"', $csv['products'] );
		$this->assertStringContainsString( '"name":"衣類"', $csv['product_categories'] );
		$this->assertSame( $json['products'], json_decode( $csv['products'], true ) );
		$this->assertSame( $json['product_categories'], json_decode( $csv['product_categories'], true ) );
	}

	/**
	 * Whatever an earlier callback broke, or a post that can't carry links,
	 * passes through untouched instead of fataling the export.
	 */
	public function test_unusable_input_passes_through_unchanged() {
		$product = $this->create_product( 'Lamp' );
		$faq     = $this->create_content( 'saai_faq', 'Lamp FAQ' );
		$blog    = self::factory()->post->create( array( 'post_title' => 'Blog post' ) );

		$this->link_products( $faq, array( $product ) );
		// Not reachable through the UI (the meta is only registered for the
		// content types), but a stray row must not be picked up.
		add_post_meta( $blog, Post_Meta::LINKED_PRODUCTS, $product );

		$this->assertSame( 'not-an-array', $this->service->filter_record( 'not-an-array', get_post( $faq ), 'json' ) );
		$this->assertSame( array( 'id' => $faq ), $this->service->filter_record( array( 'id' => $faq ), $faq, 'json' ) );
		$this->assertSame( array( 'id' => $blog ), $this->service->filter_record( array( 'id' => $blog ), get_post( $blog ), 'json' ) );
	}

	/**
	 * The linked products and terms are fetched in bulk, so a record linked
	 * to three of each costs no more queries than one linked to one — even
	 * with links left behind by permanently deleted products, which the
	 * meta keeps on purpose (docs/DESIGN.md section 6.1) and which the
	 * object cache cannot remember as missing.
	 */
	public function test_query_count_does_not_grow_with_linked_items() {
		$products   = array();
		$categories = array();
		$deleted    = array();

		foreach ( array( 'A', 'B', 'C' ) as $letter ) {
			$products[]   = $this->create_product( 'Product ' . $letter, 'SKU-' . $letter );
			$categories[] = $this->create_category( 'Category ' . $letter );
			$deleted[]    = $this->create_product( 'Deleted ' . $letter );
		}

		foreach ( $deleted as $product_id ) {
			wp_delete_post( $product_id, true );
		}

		$one   = $this->create_content( 'saai_faq', 'One of each' );
		$three = $this->create_content( 'saai_faq', 'Three of each' );

		$this->link_products( $one, array( $products[0] ) );
		$this->link_categories( $one, array( $categories[0] ) );
		$this->link_products( $three, array_merge( $products, $deleted ) );
		$this->link_categories( $three, $categories );

		$queries_for = function ( int $content_id ) use ( $products, $categories ): int {
			foreach ( array_merge( $products, array( $content_id ) ) as $id ) {
				clean_post_cache( $id );
			}

			clean_term_cache( $categories, Link_Resolver::PRODUCT_TAXONOMY );

			$post = get_post( $content_id );

			$before = get_num_queries();
			$record = $this->service->filter_record( array(), $post, 'json' );
			$delta  = get_num_queries() - $before;

			$this->assertNotEmpty( $record['products'], 'Precondition: the live products resolve.' );
			$this->assertNotEmpty( $record['product_categories'], 'Precondition: the categories resolve.' );

			return $delta;
		};

		$delta_one   = $queries_for( $one );
		$delta_three = $queries_for( $three );

		$this->assertLessThanOrEqual( $delta_one, $delta_three, 'Exporting three linked products (plus three deleted ones) and categories must not cost more queries than one of each.' );
	}
}
