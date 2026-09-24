<?php
/**
 * Tests for the add-on's product-linking post meta registration.
 *
 * @package SAAI\KnowledgeWoo
 */

use SAAI\KnowledgeWoo\Post_Meta;

/**
 * Class Test_Woo_Post_Meta.
 */
class Test_Woo_Post_Meta extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Post_Meta
	 */
	private $meta;

	/**
	 * The global `$wp_rest_server` value before set_up() replaced it, so
	 * tear_down() can restore it.
	 *
	 * @var WP_REST_Server|null
	 */
	private $original_wp_rest_server;

	/**
	 * Registers the meta keys under test.
	 *
	 * The core test framework unregisters every meta key after each test, and
	 * the add-on's own `init` registration never runs in this process (its
	 * Plugin::boot() is gated on WooCommerce, which the PHPUnit bootstrap
	 * doesn't load), so the registration has to happen here explicitly.
	 */
	public function set_up() {
		parent::set_up();

		$this->meta = new Post_Meta();
		$this->meta->register_post_meta();

		global $wp_rest_server;

		$this->original_wp_rest_server = $wp_rest_server;
	}

	/**
	 * Restores the global REST server if a test replaced it.
	 */
	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = $this->original_wp_rest_server;

		parent::tear_down();
	}

	/**
	 * Both keys exist on all three content types, as multi-value integers.
	 */
	public function test_both_keys_are_registered_as_multi_value_on_every_content_type() {
		foreach ( Post_Meta::POST_TYPES as $post_type ) {
			$registered = get_registered_meta_keys( 'post', $post_type );

			foreach ( array( Post_Meta::LINKED_PRODUCTS, Post_Meta::LINKED_PRODUCT_CATS ) as $meta_key ) {
				$this->assertArrayHasKey( $meta_key, $registered, $post_type . ' / ' . $meta_key );
				$this->assertSame( 'integer', $registered[ $meta_key ]['type'] );
				$this->assertFalse( $registered[ $meta_key ]['single'], 'one meta row per linked ID' );
				$this->assertTrue( $registered[ $meta_key ]['show_in_rest'] );
			}
		}
	}

	/**
	 * An unlinked post reads back as an empty list.
	 *
	 * Asserts the observable consequence of registering no `default`: with one
	 * set, get_post_meta() would answer with the default for the first entry
	 * and make "no links" indistinguishable from a real value.
	 */
	public function test_unlinked_post_has_no_values() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_faq' ) );

		$this->assertSame( array(), get_post_meta( $post_id, Post_Meta::LINKED_PRODUCTS, false ) );
		$this->assertSame( array(), get_post_meta( $post_id, Post_Meta::LINKED_PRODUCT_CATS, false ) );
	}

	/**
	 * The sanitize callback coerces each stored row to a non-negative integer.
	 */
	public function test_values_are_sanitized_per_row() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );

		add_post_meta( $post_id, Post_Meta::LINKED_PRODUCTS, '12abc' );
		add_post_meta( $post_id, Post_Meta::LINKED_PRODUCTS, -3 );

		$this->assertSame( array( '12', '3' ), get_post_meta( $post_id, Post_Meta::LINKED_PRODUCTS, false ) );
	}

	/**
	 * REST writes replace every row, keeping one row per value.
	 */
	public function test_rest_write_keeps_one_row_per_value_and_replaces_on_update() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );

		$first = new WP_REST_Request( 'POST', '/wp/v2/saai_kb/' . $post_id );
		$first->set_body_params( array( 'meta' => array( Post_Meta::LINKED_PRODUCTS => array( 3, 5 ) ) ) );

		$response = $wp_rest_server->dispatch( $first );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 3, 5 ), array_map( 'intval', get_post_meta( $post_id, Post_Meta::LINKED_PRODUCTS, false ) ) );

		$second = new WP_REST_Request( 'POST', '/wp/v2/saai_kb/' . $post_id );
		$second->set_body_params( array( 'meta' => array( Post_Meta::LINKED_PRODUCTS => array( 7 ) ) ) );

		$this->assertSame( 200, $wp_rest_server->dispatch( $second )->get_status() );
		$this->assertSame( array( 7 ), array_map( 'intval', get_post_meta( $post_id, Post_Meta::LINKED_PRODUCTS, false ) ) );
	}

	/**
	 * REST exposes the values as an array.
	 */
	public function test_rest_reads_the_values_as_an_array() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_glossary' ) );
		add_post_meta( $post_id, Post_Meta::LINKED_PRODUCT_CATS, 11 );
		add_post_meta( $post_id, Post_Meta::LINKED_PRODUCT_CATS, 22 );

		$request = new WP_REST_Request( 'GET', '/wp/v2/saai_glossary/' . $post_id );
		$request->set_param( 'context', 'edit' );

		$data = $wp_rest_server->dispatch( $request )->get_data();

		$this->assertSame( array( 11, 22 ), $data['meta'][ Post_Meta::LINKED_PRODUCT_CATS ] );
	}

	/**
	 * The auth callback follows `edit_post` on the post being edited.
	 */
	public function test_auth_callback_follows_edit_post_capability() {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = self::factory()->post->create(
			array(
				'post_type'   => 'saai_kb',
				'post_author' => $author_id,
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $this->meta->can_edit_post_meta( true, Post_Meta::LINKED_PRODUCTS, $post_id ) );

		wp_set_current_user( $author_id );
		$this->assertTrue( $this->meta->can_edit_post_meta( false, Post_Meta::LINKED_PRODUCTS, $post_id ) );
	}
}
