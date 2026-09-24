<?php
/**
 * Tests for the saai-knowledge-woo/v1 linking routes.
 *
 * @package SAAI\KnowledgeWoo
 */

use SAAI\KnowledgeWoo\Link_Resolver;
use SAAI\KnowledgeWoo\Links_Controller;
use SAAI\KnowledgeWoo\Post_Meta;

/**
 * Class Test_Woo_Links_Controller.
 *
 * See Test_Woo_Link_Resolver for why `product` / `product_cat` are registered
 * here as stand-ins.
 */
class Test_Woo_Links_Controller extends WP_UnitTestCase {

	/**
	 * REST server used by these tests.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * The global `$wp_rest_server` value before set_up() replaced it, so
	 * tear_down() can restore it (rest_get_server() reuses a non-null global
	 * as-is, so leaking ours would hand later tests a server pre-populated
	 * with this class's routes).
	 *
	 * @var WP_REST_Server|null
	 */
	private $original_wp_rest_server;

	/**
	 * Resolver shared with the controller under test.
	 *
	 * @var Link_Resolver
	 */
	private $resolver;

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
	 * Registers the meta, the stand-ins, and the routes under test.
	 */
	public function set_up() {
		parent::set_up();

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

		global $wp_rest_server;

		$this->original_wp_rest_server = $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		do_action( 'rest_api_init', $this->server );

		// Called directly rather than left to `rest_api_init`: the add-on's
		// Plugin::boot() is gated on WooCommerce, which the PHPUnit bootstrap
		// doesn't load, so nothing has hooked the controller in this process.
		$this->resolver = new Link_Resolver();
		( new Links_Controller( $this->resolver ) )->register_routes();
	}

	/**
	 * Restores the REST server and unregisters only what set_up() created.
	 */
	public function tear_down() {
		global $wp_rest_server;

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
	 * Logs in as an administrator.
	 *
	 * @return int User ID.
	 */
	private function login_as_admin(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		return $user_id;
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
	 * The base path for one product's linked content.
	 *
	 * @param int $product_id Product post ID.
	 */
	private function base_path( int $product_id ): string {
		return '/' . Links_Controller::NAMESPACE_ROUTE . '/products/' . $product_id . '/linked-content';
	}

	/**
	 * The GET route splits direct links from category-inherited ones and
	 * reports which category each inherited item came through.
	 */
	public function test_get_separates_direct_and_inherited_links() {
		$this->login_as_admin();

		$parent   = (int) wp_insert_term( 'Apparel', Link_Resolver::PRODUCT_TAXONOMY )['term_id'];
		$child    = (int) wp_insert_term( 'Hoodies', Link_Resolver::PRODUCT_TAXONOMY, array( 'parent' => $parent ) )['term_id'];
		$product  = $this->create_product( array( $child ) );
		$direct   = self::factory()->post->create(
			array(
				'post_type'  => 'saai_faq',
				'post_title' => 'Direct FAQ',
			)
		);
		$indirect = self::factory()->post->create(
			array(
				'post_type'  => 'saai_kb',
				'post_title' => 'Inherited article',
			)
		);

		add_post_meta( $direct, Post_Meta::LINKED_PRODUCTS, $product );
		add_post_meta( $indirect, Post_Meta::LINKED_PRODUCT_CATS, $parent );

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', $this->base_path( $product ) ) )->get_data();

		$this->assertSame( $product, $data['product_id'] );
		$this->assertSame( array( $direct ), wp_list_pluck( $data['direct'], 'id' ) );
		$this->assertSame( array( $indirect ), wp_list_pluck( $data['inherited'], 'id' ) );
		$this->assertSame( 'Direct FAQ', $data['direct'][0]['title'] );
		$this->assertSame( 'saai_kb', $data['inherited'][0]['post_type'] );
		$this->assertSame( array( $parent ), wp_list_pluck( $data['inherited'][0]['via'], 'term_id' ) );
		$this->assertSame( 'Apparel', $data['inherited'][0]['via'][0]['name'] );
	}

	/**
	 * Draft content shows up for an admin, since linking before launch is a
	 * normal step.
	 */
	public function test_get_includes_unpublished_content() {
		$this->login_as_admin();

		$product = $this->create_product();
		$draft   = self::factory()->post->create(
			array(
				'post_type'   => 'saai_faq',
				'post_status' => 'draft',
			)
		);

		add_post_meta( $draft, Post_Meta::LINKED_PRODUCTS, $product );

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', $this->base_path( $product ) ) )->get_data();

		$this->assertSame( array( $draft ), wp_list_pluck( $data['direct'], 'id' ) );
		$this->assertSame( 'draft', $data['direct'][0]['status'] );
	}

	/**
	 * An untitled post still gets a readable label.
	 */
	public function test_get_labels_untitled_content() {
		$this->login_as_admin();

		$product = $this->create_product();
		$faq     = self::factory()->post->create(
			array(
				'post_type'  => 'saai_faq',
				'post_title' => '',
			)
		);

		add_post_meta( $faq, Post_Meta::LINKED_PRODUCTS, $product );

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', $this->base_path( $product ) ) )->get_data();

		$this->assertNotSame( '', $data['direct'][0]['title'] );
	}

	/**
	 * Titles come back as plain text, not HTML character references.
	 */
	public function test_titles_are_decoded_to_plain_text() {
		$this->login_as_admin();

		$product = $this->create_product();
		$faq     = self::factory()->post->create(
			array(
				'post_type'  => 'saai_faq',
				'post_title' => 'Care & cleaning',
			)
		);

		add_post_meta( $faq, Post_Meta::LINKED_PRODUCTS, $product );

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', $this->base_path( $product ) ) )->get_data();

		$this->assertSame( 'Care & cleaning', $data['direct'][0]['title'] );
	}

	/**
	 * Reading a product's links needs `edit_post` on the product.
	 */
	public function test_get_requires_permission_to_edit_the_product() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$product  = $this->create_product();
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', $this->base_path( $product ) ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'saai_woo_cannot_edit_product', $response->as_error()->get_error_code() );
	}

	/**
	 * An ID that isn't a product is a 404, not a 403.
	 */
	public function test_get_rejects_an_id_that_is_not_a_product() {
		$this->login_as_admin();

		$page     = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', $this->base_path( $page ) ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'saai_woo_invalid_product_id', $response->as_error()->get_error_code() );
	}

	/**
	 * POST adds one row, answers 201, and is idempotent afterwards.
	 */
	public function test_post_links_content_and_is_idempotent() {
		$this->login_as_admin();

		$product = $this->create_product();
		$faq     = self::factory()->post->create( array( 'post_type' => 'saai_faq' ) );

		$request = new WP_REST_Request( 'POST', $this->base_path( $product ) );
		$request->set_body_params( array( 'content_id' => $faq ) );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( array( $faq ), wp_list_pluck( $response->get_data()['direct'], 'id' ) );
		$this->assertSame( array( (string) $product ), get_post_meta( $faq, Post_Meta::LINKED_PRODUCTS, false ) );

		$repeat = new WP_REST_Request( 'POST', $this->base_path( $product ) );
		$repeat->set_body_params( array( 'content_id' => $faq ) );

		$this->assertSame( 200, $this->server->dispatch( $repeat )->get_status() );
		$this->assertSame( array( (string) $product ), get_post_meta( $faq, Post_Meta::LINKED_PRODUCTS, false ) );
	}

	/**
	 * A post type that can't carry the meta is rejected as a bad request.
	 */
	public function test_post_rejects_an_unsupported_content_type() {
		$this->login_as_admin();

		$product = $this->create_product();
		$page    = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$request = new WP_REST_Request( 'POST', $this->base_path( $product ) );
		$request->set_body_params( array( 'content_id' => $page ) );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'saai_woo_unsupported_content_type', $response->as_error()->get_error_code() );
		$this->assertSame( array(), get_post_meta( $page, Post_Meta::LINKED_PRODUCTS, false ) );
	}

	/**
	 * A content ID that doesn't exist is a 404.
	 */
	public function test_post_rejects_a_missing_content_id() {
		$this->login_as_admin();

		$product = $this->create_product();

		$request = new WP_REST_Request( 'POST', $this->base_path( $product ) );
		$request->set_body_params( array( 'content_id' => 9999999 ) );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'saai_woo_invalid_content_id', $response->as_error()->get_error_code() );
	}

	/**
	 * Writing requires `edit_post` on the *content*, which is where the meta
	 * row lives — not just on the product.
	 */
	public function test_post_requires_permission_to_edit_the_content() {
		$editor_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $editor_id );

		$product = self::factory()->post->create(
			array(
				'post_type'   => Link_Resolver::PRODUCT_POST_TYPE,
				'post_author' => $editor_id,
			)
		);
		$faq     = self::factory()->post->create(
			array(
				'post_type'   => 'saai_faq',
				'post_author' => self::factory()->user->create( array( 'role' => 'author' ) ),
			)
		);

		$request = new WP_REST_Request( 'POST', $this->base_path( $product ) );
		$request->set_body_params( array( 'content_id' => $faq ) );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'saai_woo_cannot_edit_content', $response->as_error()->get_error_code() );
		$this->assertSame( array(), get_post_meta( $faq, Post_Meta::LINKED_PRODUCTS, false ) );
	}

	/**
	 * A non-positive content ID never reaches the handler.
	 */
	public function test_post_rejects_a_zero_content_id() {
		$this->login_as_admin();

		$product = $this->create_product();

		$request = new WP_REST_Request( 'POST', $this->base_path( $product ) );
		$request->set_body_params( array( 'content_id' => 0 ) );

		$this->assertSame( 400, $this->server->dispatch( $request )->get_status() );
	}

	/**
	 * A negative content ID is rejected, not folded into a positive one.
	 *
	 * The `absint` sanitize_callback would turn -3 into 3 — a different, real
	 * post — but WP_REST_Server runs has_valid_params() before
	 * sanitize_params(), so `minimum => 1` rejects it first. Pinned here
	 * because the safety depends on that ordering.
	 */
	public function test_post_rejects_a_negative_content_id() {
		$this->login_as_admin();

		$product = $this->create_product();
		$faq     = self::factory()->post->create( array( 'post_type' => 'saai_faq' ) );

		$request = new WP_REST_Request( 'POST', $this->base_path( $product ) );
		$request->set_body_params( array( 'content_id' => -$faq ) );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array(), get_post_meta( $faq, Post_Meta::LINKED_PRODUCTS, false ) );
	}

	/**
	 * DELETE removes the product row and leaves category links alone.
	 */
	public function test_delete_removes_only_the_direct_link() {
		$this->login_as_admin();

		$category = (int) wp_insert_term( 'Apparel', Link_Resolver::PRODUCT_TAXONOMY )['term_id'];
		$product  = $this->create_product( array( $category ) );
		$faq      = self::factory()->post->create( array( 'post_type' => 'saai_faq' ) );

		add_post_meta( $faq, Post_Meta::LINKED_PRODUCTS, $product );
		add_post_meta( $faq, Post_Meta::LINKED_PRODUCT_CATS, $category );

		$response = $this->server->dispatch( new WP_REST_Request( 'DELETE', $this->base_path( $product ) . '/' . $faq ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $data['direct'] );
		$this->assertSame( array( $faq ), wp_list_pluck( $data['inherited'], 'id' ) );
		$this->assertSame( array(), get_post_meta( $faq, Post_Meta::LINKED_PRODUCTS, false ) );
		$this->assertSame( array( (string) $category ), get_post_meta( $faq, Post_Meta::LINKED_PRODUCT_CATS, false ) );
	}

	/**
	 * The content search finds drafts as well as published content.
	 */
	public function test_content_search_finds_drafts() {
		$this->login_as_admin();

		$published = self::factory()->post->create(
			array(
				'post_type'  => 'saai_faq',
				'post_title' => 'Waterproofing guide',
			)
		);
		$draft     = self::factory()->post->create(
			array(
				'post_type'   => 'saai_kb',
				'post_title'  => 'Waterproofing deep dive',
				'post_status' => 'draft',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'  => 'saai_faq',
				'post_title' => 'Something else entirely',
			)
		);

		$request = new WP_REST_Request( 'GET', '/' . Links_Controller::NAMESPACE_ROUTE . '/content-search' );
		$request->set_param( 'search', 'Waterproofing' );

		$ids = wp_list_pluck( $this->server->dispatch( $request )->get_data(), 'id' );

		$this->assertContains( $published, $ids );
		$this->assertContains( $draft, $ids );
		$this->assertCount( 2, $ids );
	}

	/**
	 * `post_type` narrows the search, and `per_page` caps it.
	 */
	public function test_content_search_respects_post_type_and_per_page() {
		$this->login_as_admin();

		$faq = self::factory()->post->create(
			array(
				'post_type'  => 'saai_faq',
				'post_title' => 'Sizing alpha',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'  => 'saai_kb',
				'post_title' => 'Sizing beta',
			)
		);

		$narrowed = new WP_REST_Request( 'GET', '/' . Links_Controller::NAMESPACE_ROUTE . '/content-search' );
		$narrowed->set_param( 'search', 'Sizing' );
		$narrowed->set_param( 'post_type', 'saai_faq' );

		$this->assertSame( array( $faq ), wp_list_pluck( $this->server->dispatch( $narrowed )->get_data(), 'id' ) );

		$capped = new WP_REST_Request( 'GET', '/' . Links_Controller::NAMESPACE_ROUTE . '/content-search' );
		$capped->set_param( 'search', 'Sizing' );
		$capped->set_param( 'per_page', 1 );

		$this->assertCount( 1, $this->server->dispatch( $capped )->get_data() );

		$too_many = new WP_REST_Request( 'GET', '/' . Links_Controller::NAMESPACE_ROUTE . '/content-search' );
		$too_many->set_param( 'search', 'Sizing' );
		$too_many->set_param( 'per_page', 500 );

		$this->assertSame( 400, $this->server->dispatch( $too_many )->get_status() );
	}

	/**
	 * The content search needs `edit_posts`.
	 */
	public function test_content_search_requires_edit_posts() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$request = new WP_REST_Request( 'GET', '/' . Links_Controller::NAMESPACE_ROUTE . '/content-search' );
		$request->set_param( 'search', 'anything' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'saai_woo_cannot_search_content', $response->as_error()->get_error_code() );
	}

	/**
	 * Content the current user may not read is dropped from the listing.
	 *
	 * The query asks for `post_status => any` without a `perm` argument, so
	 * WP_Query returns every draft regardless of author — visible_items() is
	 * the only thing standing between another author's unpublished content and
	 * whoever can edit this product.
	 */
	public function test_listing_hides_content_the_user_cannot_read() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$stranger_id  = self::factory()->user->create( array( 'role' => 'author' ) );
		$product      = self::factory()->post->create(
			array(
				'post_type'   => Link_Resolver::PRODUCT_POST_TYPE,
				'post_author' => $owner_id,
			)
		);
		$others_draft = self::factory()->post->create(
			array(
				'post_type'   => 'saai_faq',
				'post_title'  => 'Unreleased secret',
				'post_status' => 'draft',
				'post_author' => $stranger_id,
			)
		);
		$own_draft    = self::factory()->post->create(
			array(
				'post_type'   => 'saai_faq',
				'post_title'  => 'My own draft',
				'post_status' => 'draft',
				'post_author' => $owner_id,
			)
		);

		add_post_meta( $others_draft, Post_Meta::LINKED_PRODUCTS, $product );
		add_post_meta( $own_draft, Post_Meta::LINKED_PRODUCTS, $product );

		wp_set_current_user( $owner_id );

		$ids = wp_list_pluck(
			$this->server->dispatch( new WP_REST_Request( 'GET', $this->base_path( $product ) ) )->get_data()['direct'],
			'id'
		);

		$this->assertSame( array( $own_draft ), $ids );
	}

	/**
	 * The same guard applies to the search used by the "add" field.
	 */
	public function test_content_search_hides_content_the_user_cannot_read() {
		$owner_id    = self::factory()->user->create( array( 'role' => 'author' ) );
		$stranger_id = self::factory()->user->create( array( 'role' => 'author' ) );

		self::factory()->post->create(
			array(
				'post_type'   => 'saai_faq',
				'post_title'  => 'Zephyr secret draft',
				'post_status' => 'draft',
				'post_author' => $stranger_id,
			)
		);
		$own = self::factory()->post->create(
			array(
				'post_type'   => 'saai_faq',
				'post_title'  => 'Zephyr own draft',
				'post_status' => 'draft',
				'post_author' => $owner_id,
			)
		);

		wp_set_current_user( $owner_id );

		$request = new WP_REST_Request( 'GET', '/' . Links_Controller::NAMESPACE_ROUTE . '/content-search' );
		$request->set_param( 'search', 'Zephyr' );

		$this->assertSame( array( $own ), wp_list_pluck( $this->server->dispatch( $request )->get_data(), 'id' ) );
	}

	/**
	 * A readable but uneditable item comes back without an edit link.
	 */
	public function test_edit_link_is_empty_without_edit_permission() {
		$owner_id    = self::factory()->user->create( array( 'role' => 'author' ) );
		$stranger_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$product     = self::factory()->post->create(
			array(
				'post_type'   => Link_Resolver::PRODUCT_POST_TYPE,
				'post_author' => $owner_id,
			)
		);
		$published   = self::factory()->post->create(
			array(
				'post_type'   => 'saai_faq',
				'post_author' => $stranger_id,
			)
		);

		add_post_meta( $published, Post_Meta::LINKED_PRODUCTS, $product );

		wp_set_current_user( $owner_id );

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', $this->base_path( $product ) ) )->get_data();

		$this->assertSame( array( $published ), wp_list_pluck( $data['direct'], 'id' ) );
		$this->assertSame( '', $data['direct'][0]['edit_link'] );
	}

	/**
	 * Unlinking needs edit_post on the content, not just on the product.
	 */
	public function test_delete_requires_permission_to_edit_the_content() {
		$owner_id    = self::factory()->user->create( array( 'role' => 'author' ) );
		$stranger_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$product     = self::factory()->post->create(
			array(
				'post_type'   => Link_Resolver::PRODUCT_POST_TYPE,
				'post_author' => $owner_id,
			)
		);
		$faq         = self::factory()->post->create(
			array(
				'post_type'   => 'saai_faq',
				'post_author' => $stranger_id,
			)
		);

		add_post_meta( $faq, Post_Meta::LINKED_PRODUCTS, $product );

		wp_set_current_user( $owner_id );

		$response = $this->server->dispatch( new WP_REST_Request( 'DELETE', $this->base_path( $product ) . '/' . $faq ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'saai_woo_cannot_edit_content', $response->as_error()->get_error_code() );
		$this->assertSame( array( (string) $product ), get_post_meta( $faq, Post_Meta::LINKED_PRODUCTS, false ) );
	}

	/**
	 * Password-protected content stays listed.
	 *
	 * The resolver excludes it by default for front-end output, but this list
	 * is the only place its link can be removed from the product side.
	 */
	public function test_password_protected_content_is_listed() {
		$this->login_as_admin();

		$product = $this->create_product();
		$faq     = self::factory()->post->create(
			array(
				'post_type'     => 'saai_faq',
				'post_title'    => 'Members only',
				'post_password' => 'secret',
			)
		);

		add_post_meta( $faq, Post_Meta::LINKED_PRODUCTS, $product );

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', $this->base_path( $product ) ) )->get_data();

		$this->assertSame( array( $faq ), wp_list_pluck( $data['direct'], 'id' ) );
		// No "Protected: " prefix: get_the_title() adds one outside the admin
		// screens, and a REST request is not is_admin().
		$this->assertSame( 'Members only', $data['direct'][0]['title'] );
		// ...so the protection has to be reported on its own. A protected post
		// is still `publish`, so the status field cannot stand in for it
		// (Codex review).
		$this->assertSame( 'publish', $data['direct'][0]['status'] );
		$this->assertTrue( $data['direct'][0]['protected'] );
	}

	/**
	 * Unprotected content is not flagged as protected.
	 */
	public function test_unprotected_content_is_not_flagged() {
		$this->login_as_admin();

		$product = $this->create_product();
		$faq     = self::factory()->post->create( array( 'post_type' => 'saai_faq' ) );

		add_post_meta( $faq, Post_Meta::LINKED_PRODUCTS, $product );

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', $this->base_path( $product ) ) )->get_data();

		$this->assertFalse( $data['direct'][0]['protected'] );
	}

	/**
	 * A private post's title comes back unprefixed too.
	 */
	public function test_private_content_title_is_not_prefixed() {
		$this->login_as_admin();

		$product = $this->create_product();
		$faq     = self::factory()->post->create(
			array(
				'post_type'   => 'saai_faq',
				'post_title'  => 'Internal note',
				'post_status' => 'private',
			)
		);

		add_post_meta( $faq, Post_Meta::LINKED_PRODUCTS, $product );

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', $this->base_path( $product ) ) )->get_data();

		$this->assertSame( 'Internal note', $data['direct'][0]['title'] );
	}

	/**
	 * Temporarily filtering the title formats leaves no trace behind.
	 */
	public function test_title_format_filters_are_restored() {
		$this->login_as_admin();

		$product = $this->create_product();
		$faq     = self::factory()->post->create(
			array(
				'post_type'     => 'saai_faq',
				'post_title'    => 'Members only',
				'post_password' => 'secret',
			)
		);

		add_post_meta( $faq, Post_Meta::LINKED_PRODUCTS, $product );

		$before = array( has_filter( 'protected_title_format' ), has_filter( 'private_title_format' ) );

		$this->server->dispatch( new WP_REST_Request( 'GET', $this->base_path( $product ) ) );

		$this->assertSame( $before, array( has_filter( 'protected_title_format' ), has_filter( 'private_title_format' ) ) );
	}

	/**
	 * The search matches titles only.
	 *
	 * ComboboxControl re-filters the options it is handed against the typed
	 * text, so a body-only match can never be shown — it would only crowd a
	 * real title match out of the per_page window.
	 */
	public function test_content_search_matches_titles_only() {
		$this->login_as_admin();

		self::factory()->post->create(
			array(
				'post_type'    => 'saai_faq',
				'post_title'   => 'Unrelated heading',
				'post_content' => 'Mentions zqxprobe in the body only.',
			)
		);
		$titled = self::factory()->post->create(
			array(
				'post_type'  => 'saai_faq',
				'post_title' => 'Zqxprobe care guide',
			)
		);

		$request = new WP_REST_Request( 'GET', '/' . Links_Controller::NAMESPACE_ROUTE . '/content-search' );
		$request->set_param( 'search', 'zqxprobe' );

		$this->assertSame( array( $titled ), wp_list_pluck( $this->server->dispatch( $request )->get_data(), 'id' ) );
	}

	/**
	 * An empty search term is rejected rather than listing everything.
	 */
	public function test_content_search_rejects_an_empty_term() {
		$this->login_as_admin();

		$request = new WP_REST_Request( 'GET', '/' . Links_Controller::NAMESPACE_ROUTE . '/content-search' );
		$request->set_param( 'search', '' );

		$this->assertSame( 400, $this->server->dispatch( $request )->get_status() );
	}

	/**
	 * Items carry a translated status label, not just the raw slug.
	 */
	public function test_items_carry_a_translated_status_label() {
		$this->login_as_admin();

		$product = $this->create_product();
		$draft   = self::factory()->post->create(
			array(
				'post_type'   => 'saai_faq',
				'post_status' => 'draft',
			)
		);

		add_post_meta( $draft, Post_Meta::LINKED_PRODUCTS, $product );

		$item = $this->server->dispatch( new WP_REST_Request( 'GET', $this->base_path( $product ) ) )->get_data()['direct'][0];

		$this->assertSame( 'draft', $item['status'] );
		$this->assertSame( get_post_status_object( 'draft' )->label, $item['status_label'] );
	}

	/**
	 * The routes advertise a schema, so OPTIONS discovery works.
	 */
	public function test_routes_expose_their_schema() {
		$this->login_as_admin();

		$product = $this->create_product();

		$options = $this->server->dispatch( new WP_REST_Request( 'OPTIONS', $this->base_path( $product ) ) )->get_data();

		$this->assertArrayHasKey( 'schema', $options );
		$this->assertArrayHasKey( 'direct', $options['schema']['properties'] );
		$this->assertArrayHasKey( 'inherited', $options['schema']['properties'] );
	}
}
