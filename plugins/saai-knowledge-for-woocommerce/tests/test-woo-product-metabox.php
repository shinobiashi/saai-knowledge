<?php
/**
 * Tests for the product edit screen's reverse-linking meta box.
 *
 * @package SAAI\KnowledgeWoo
 */

use SAAI\KnowledgeWoo\Link_Resolver;
use SAAI\KnowledgeWoo\Links_Controller;
use SAAI\KnowledgeWoo\Product_Metabox;

/**
 * Class Test_Woo_Product_Metabox.
 */
class Test_Woo_Product_Metabox extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Product_Metabox
	 */
	private $metabox;

	/**
	 * The `$wp_meta_boxes` global before the test, so tear_down() can restore
	 * it wholesale (the core test framework doesn't reset it).
	 *
	 * @var array<string, mixed>|null
	 */
	private $original_wp_meta_boxes;

	/**
	 * Whether this test registered the stand-in product post type.
	 *
	 * @var bool
	 */
	private $registered_post_type = false;

	/**
	 * Sets up the service and the stand-in product post type.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_meta_boxes;

		$this->original_wp_meta_boxes = $wp_meta_boxes;

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

		$this->metabox = new Product_Metabox();
	}

	/**
	 * Restores the globals and registries the test touched.
	 */
	public function tear_down() {
		global $wp_meta_boxes;

		$wp_meta_boxes = $this->original_wp_meta_boxes;

		wp_dequeue_script( Product_Metabox::HANDLE );
		wp_deregister_script( Product_Metabox::HANDLE );
		wp_dequeue_style( Product_Metabox::HANDLE );
		wp_deregister_style( Product_Metabox::HANDLE );
		set_current_screen( 'front' );

		if ( $this->registered_post_type ) {
			unregister_post_type( Link_Resolver::PRODUCT_POST_TYPE );
			$this->registered_post_type = false;
		}

		parent::tear_down();
	}

	/**
	 * Points get_current_screen() at a post edit screen for a post type.
	 *
	 * @param string $post_type Post type slug.
	 */
	private function set_edit_screen( string $post_type ): void {
		set_current_screen( 'post' );

		$screen            = get_current_screen();
		$screen->base      = 'post';
		$screen->post_type = $post_type;
	}

	/**
	 * The box is registered on the product screen in the normal column.
	 */
	public function test_meta_box_is_registered_on_products() {
		global $wp_meta_boxes;

		$wp_meta_boxes = array();

		$this->metabox->add_meta_box();

		$this->assertArrayHasKey(
			Product_Metabox::BOX_ID,
			$wp_meta_boxes[ Link_Resolver::PRODUCT_POST_TYPE ]['normal']['default']
		);
	}

	/**
	 * The box is wired to the product-specific add_meta_boxes hook, so it
	 * never runs for other post types.
	 */
	public function test_register_uses_the_product_specific_hook() {
		$hook = 'add_meta_boxes_' . Link_Resolver::PRODUCT_POST_TYPE;

		$this->assertFalse( has_action( $hook, array( $this->metabox, 'add_meta_box' ) ) );

		$this->metabox->register();

		$this->assertNotFalse( has_action( $hook, array( $this->metabox, 'add_meta_box' ) ) );

		// Registered here rather than by Plugin::boot() (gated on WooCommerce,
		// absent in this process), so this test has to take it back off.
		remove_action( $hook, array( $this->metabox, 'add_meta_box' ) );
		remove_action( 'admin_enqueue_scripts', array( $this->metabox, 'enqueue_assets' ) );
	}

	/**
	 * The container carries the product ID for the script to mount against.
	 */
	public function test_render_outputs_the_product_id() {
		$product_id = self::factory()->post->create( array( 'post_type' => Link_Resolver::PRODUCT_POST_TYPE ) );

		ob_start();
		$this->metabox->render( get_post( $product_id ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-product-id="' . $product_id . '"', $output );
		$this->assertStringContainsString( '<noscript>', $output );
	}

	/**
	 * The no-JS message is escaped.
	 *
	 * The shipped English string contains nothing that needs escaping, so
	 * dropping esc_html_e() would go unnoticed against it. Swapping in a
	 * translation that does makes the escaping observable.
	 */
	public function test_render_escapes_the_noscript_message() {
		$product_id = self::factory()->post->create( array( 'post_type' => Link_Resolver::PRODUCT_POST_TYPE ) );

		$filter = static function ( $translation, $text, $domain ) {
			if ( 'saai-knowledge-for-woocommerce' === $domain && str_starts_with( $text, 'Linking FAQs' ) ) {
				return '<script>alert(1)</script>';
			}

			return $translation;
		};

		add_filter( 'gettext', $filter, 10, 3 );

		ob_start();
		$this->metabox->render( get_post( $product_id ) );
		$output = (string) ob_get_clean();

		remove_filter( 'gettext', $filter, 10 );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $output );
	}

	/**
	 * Assets load on the product edit screen, with the REST namespace inlined
	 * so the script doesn't repeat the literal.
	 */
	public function test_assets_are_enqueued_on_the_product_edit_screen() {
		$this->set_edit_screen( Link_Resolver::PRODUCT_POST_TYPE );

		$this->metabox->enqueue_assets();

		$this->assertTrue( wp_script_is( Product_Metabox::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Product_Metabox::HANDLE, 'enqueued' ) );

		$before = wp_scripts()->get_data( Product_Metabox::HANDLE, 'before' );

		$this->assertIsArray( $before );
		// Compared against the encoded payload rather than the bare namespace:
		// wp_json_encode() escapes the slash, and it is the decoded JSON the
		// script actually reads.
		$this->assertStringContainsString(
			(string) wp_json_encode( array( 'restNamespace' => Links_Controller::NAMESPACE_ROUTE ) ),
			implode( '', $before )
		);
	}

	/**
	 * The script declares the wp-* packages it reads off the global `wp`
	 * object; nothing derives these without a build step.
	 */
	public function test_script_declares_its_wp_package_dependencies() {
		$this->set_edit_screen( Link_Resolver::PRODUCT_POST_TYPE );

		$this->metabox->enqueue_assets();

		$script = wp_scripts()->registered[ Product_Metabox::HANDLE ];

		foreach ( array( 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n', 'wp-url' ) as $dependency ) {
			$this->assertContains( $dependency, $script->deps, $dependency );
		}
	}

	/**
	 * Assets stay off other post types' edit screens and off list tables.
	 */
	public function test_assets_are_not_enqueued_elsewhere() {
		$this->set_edit_screen( 'post' );
		$this->metabox->enqueue_assets();
		$this->assertFalse( wp_script_is( Product_Metabox::HANDLE, 'enqueued' ) );

		set_current_screen( 'edit-post' );
		$screen            = get_current_screen();
		$screen->post_type = Link_Resolver::PRODUCT_POST_TYPE;

		$this->metabox->enqueue_assets();
		$this->assertFalse( wp_script_is( Product_Metabox::HANDLE, 'enqueued' ) );
	}

	/**
	 * With no screen at all nothing is enqueued.
	 */
	public function test_assets_are_not_enqueued_without_a_screen() {
		unset( $GLOBALS['current_screen'] );

		$this->metabox->enqueue_assets();

		$this->assertFalse( wp_script_is( Product_Metabox::HANDLE, 'enqueued' ) );
	}
}
