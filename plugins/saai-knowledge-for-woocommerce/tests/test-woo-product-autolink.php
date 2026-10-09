<?php
/**
 * Tests for the glossary tooltips in product descriptions.
 *
 * @package SAAI\KnowledgeWoo
 */

use SAAI\KnowledgeWoo\Link_Resolver;
use SAAI\KnowledgeWoo\Post_Meta;
use SAAI\KnowledgeWoo\Product_Autolink;
use SAAI\KnowledgeWoo\Settings;

/**
 * Class Test_Woo_Product_Autolink.
 */
class Test_Woo_Product_Autolink extends WP_UnitTestCase {

	/**
	 * Service under test, register()'d for the duration of each test so the
	 * free plugin's own booted Autolinker (on `the_content`) sees its
	 * filters; tear_down() detaches them again.
	 *
	 * @var Product_Autolink
	 */
	private $service;

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
	 * Registers the stand-ins, resets the dictionary, and hooks the service.
	 */
	public function set_up() {
		parent::set_up();

		// The core test framework unregisters every meta key after each test.
		( new Post_Meta() )->register_post_meta();
		delete_option( Settings::OPTION_KEY );

		// Same reset as the free plugin's test-autolinker.php: the built
		// dictionary is persisted in options and the object cache.
		delete_option( 'saai_dict_generation' );
		delete_option( 'saai_autolink_dict' );
		delete_option( 'saai_autolink_dict_truncated' );
		wp_cache_flush();

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

		$this->service = new Product_Autolink( $this->base_stub(), new Link_Resolver(), new Settings() );
		$this->service->register();
	}

	/**
	 * Detaches the service and unregisters only the stand-ins this test created.
	 */
	public function tear_down() {
		$this->unregister( $this->service );

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
	 * A stand-in for the free plugin instance `saai_loaded` hands over: the
	 * only thing the service reads from it is autolinker().
	 *
	 * The engine instance it returns is deliberately not register()'d — the
	 * free plugin's bootstrap already has its own on `the_content`, and a
	 * second one there would double-process (see test-autolinker.php).
	 *
	 * @return object
	 */
	private function base_stub(): object {
		return new class() {
			/**
			 * The auto-link engine.
			 *
			 * @return \SAAI\Knowledge\Autolinker
			 */
			public function autolinker() {
				return new \SAAI\Knowledge\Autolinker();
			}
		};
	}

	/**
	 * Removes every filter register() added.
	 *
	 * @param Product_Autolink $service The registered service.
	 */
	private function unregister( Product_Autolink $service ): void {
		remove_filter( 'saai_autolink_post_types', array( $service, 'filter_post_types' ), 20 );
		remove_filter( 'saai_autolink_dictionary', array( $service, 'filter_dictionary' ), 10 );
		remove_filter( 'woocommerce_short_description', array( $service, 'filter_short_description' ), 20 );
		remove_filter( 'render_block_core/post-excerpt', array( $service, 'filter_summary_block' ), 10 );
		remove_filter( 'render_block_woocommerce/product-summary', array( $service, 'filter_summary_block' ), 10 );
	}

	/**
	 * Creates a published glossary term.
	 *
	 * @param string $title Term title (also its only pattern).
	 * @return int Post ID.
	 */
	private function create_term( string $title ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => 'saai_glossary',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => '<p>A definition of ' . $title . ' long enough to be trimmed for the tooltip excerpt.</p>',
			)
		);
	}

	/**
	 * Creates a published stand-in product.
	 *
	 * @param string $title   Product title.
	 * @param string $excerpt Short description.
	 * @return int Post ID.
	 */
	private function create_product( string $title, string $excerpt = '' ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => Link_Resolver::PRODUCT_POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_excerpt' => $excerpt,
			)
		);
	}

	/**
	 * Links a content post directly to a product.
	 *
	 * @param int $content_id Content post ID.
	 * @param int $product_id Product post ID.
	 */
	private function link( int $content_id, int $product_id ): void {
		add_post_meta( $content_id, Post_Meta::LINKED_PRODUCTS, $product_id );
	}

	/**
	 * A dictionary entry in the public shape (docs/DESIGN-AUTOLINK.md 2.1).
	 *
	 * @param int    $post_id Glossary term ID.
	 * @param string $label   Term label and only pattern.
	 * @return array<string, mixed>
	 */
	private function entry( int $post_id, string $label ): array {
		return array(
			'post_id'  => $post_id,
			'url'      => get_permalink( $post_id ),
			'label'    => $label,
			'patterns' => array( $label ),
			'excerpt'  => '',
		);
	}

	/**
	 * Runs content through the full `the_content` chain on a product's
	 * single view — the route WooCommerce's Description tab takes.
	 *
	 * @param int    $product_id Product post ID.
	 * @param string $content    Raw content.
	 * @return string
	 */
	private function render_content_on( int $product_id, string $content ): string {
		$this->go_to( get_permalink( $product_id ) );
		$this->assertTrue( is_singular( Link_Resolver::PRODUCT_POST_TYPE ), 'Precondition: the request must be the single product view.' );

		$rendered = '';

		while ( have_posts() ) {
			the_post();
			$rendered = apply_filters( 'the_content', $content );
		}

		return (string) $rendered;
	}

	/**
	 * The register() method attaches every hook at its documented priority.
	 */
	public function test_register_attaches_the_documented_hooks() {
		$this->assertSame( 20, has_filter( 'saai_autolink_post_types', array( $this->service, 'filter_post_types' ) ), 'After the free plugin\'s own priority-10 settings callback.' );
		$this->assertSame( 10, has_filter( 'saai_autolink_dictionary', array( $this->service, 'filter_dictionary' ) ) );
		$this->assertSame( 20, has_filter( 'woocommerce_short_description', array( $this->service, 'filter_short_description' ) ), 'After WooCommerce\'s own formatting callbacks at 9 and 10.' );
		$this->assertSame( 10, has_filter( 'render_block_core/post-excerpt', array( $this->service, 'filter_summary_block' ) ) );
		$this->assertSame( 10, has_filter( 'render_block_woocommerce/product-summary', array( $this->service, 'filter_summary_block' ) ) );
	}

	/**
	 * The `product` type becomes an auto-link post type while the toggle is on —
	 * once, even when it's already there — and the list is left alone
	 * otherwise.
	 */
	public function test_product_joins_the_autolink_post_types_only_while_tooltips_are_on() {
		$types = apply_filters( 'saai_autolink_post_types', array( 'post', 'page' ) );
		$this->assertContains( Link_Resolver::PRODUCT_POST_TYPE, $types );

		$with_product = apply_filters( 'saai_autolink_post_types', array( 'post', Link_Resolver::PRODUCT_POST_TYPE ) );
		$this->assertSame( 1, count( array_keys( $with_product, Link_Resolver::PRODUCT_POST_TYPE, true ) ) );

		update_option( Settings::OPTION_KEY, array( Settings::TOOLTIPS => false ) );
		$this->assertNotContains( Link_Resolver::PRODUCT_POST_TYPE, apply_filters( 'saai_autolink_post_types', array( 'post', 'page' ) ) );
		$this->assertContains( Link_Resolver::PRODUCT_POST_TYPE, $this->service->filter_post_types( array( Link_Resolver::PRODUCT_POST_TYPE ) ), 'Someone else\'s product entry is not removed.' );

		$this->assertSame( 'broken', $this->service->filter_post_types( 'broken' ) );
	}

	/**
	 * In a product context the dictionary shrinks to the linked terms; any
	 * other context, and a product with the toggle off, keep it whole.
	 */
	public function test_dictionary_is_narrowed_to_the_linked_terms_for_a_product_context() {
		$linked  = $this->create_term( 'Alpha' );
		$other   = $this->create_term( 'Bravo' );
		$product = $this->create_product( 'Linked' );
		$plain   = $this->create_product( 'Plain' );
		$this->link( $linked, $product );

		$entries = array( $this->entry( $linked, 'Alpha' ), $this->entry( $other, 'Bravo' ) );
		$context = array(
			'post_id'   => $product,
			'post_type' => Link_Resolver::PRODUCT_POST_TYPE,
		);

		$this->assertSame( array( $entries[0] ), apply_filters( 'saai_autolink_dictionary', $entries, $context ) );
		$this->assertSame(
			array(),
			apply_filters(
				'saai_autolink_dictionary',
				$entries,
				array(
					'post_id'   => $plain,
					'post_type' => Link_Resolver::PRODUCT_POST_TYPE,
				)
			),
			'A product with no linked terms gets an empty dictionary.'
		);
		$this->assertSame( array(), apply_filters( 'saai_autolink_dictionary', $entries, array( 'post_type' => Link_Resolver::PRODUCT_POST_TYPE ) ), 'No post ID, no way to resolve links.' );
		$this->assertSame(
			$entries,
			apply_filters(
				'saai_autolink_dictionary',
				$entries,
				array(
					'post_id'   => $product,
					'post_type' => 'saai_kb',
				)
			),
			'Other post types keep the full dictionary.'
		);
		$this->assertSame( $entries, apply_filters( 'saai_autolink_dictionary', $entries, array() ) );

		update_option( Settings::OPTION_KEY, array( Settings::TOOLTIPS => false ) );
		$this->assertSame( $entries, apply_filters( 'saai_autolink_dictionary', $entries, $context ), 'Off: not this add-on\'s product context to narrow.' );

		$this->assertSame( 'broken', $this->service->filter_dictionary( 'broken', $context ) );
		$this->assertSame( $entries, $this->service->filter_dictionary( $entries, 'broken' ) );
	}

	/**
	 * End to end through the free plugin's own `the_content` pass: on a
	 * product page only the linked term is linked, a product with no links
	 * gets nothing, and the toggle switches it all off.
	 */
	public function test_the_content_links_only_the_linked_terms_on_a_product_page() {
		$alpha   = $this->create_term( 'Alpha' );
		$bravo   = $this->create_term( 'Bravo' );
		$product = $this->create_product( 'Linked' );
		$plain   = $this->create_product( 'Plain' );
		$this->link( $alpha, $product );

		$html = $this->render_content_on( $product, '<p>Alpha meets Bravo.</p>' );

		$this->assertStringContainsString( 'data-saai-term-id="' . $alpha . '"', $html );
		$this->assertStringNotContainsString( 'data-saai-term-id="' . $bravo . '"', $html );

		$this->assertStringNotContainsString( 'saai-term', $this->render_content_on( $plain, '<p>Alpha meets Bravo.</p>' ) );

		update_option( Settings::OPTION_KEY, array( Settings::TOOLTIPS => false ) );
		$this->assertStringNotContainsString( 'saai-term', $this->render_content_on( $product, '<p>Alpha meets Bravo.</p>' ) );
	}

	/**
	 * The classic-theme short description is linked for the displayed
	 * product only: not while a loop has another product as the global
	 * post, not off the product page, and not with the toggle off.
	 */
	public function test_short_description_is_linked_only_for_the_displayed_product() {
		$alpha   = $this->create_term( 'Alpha' );
		$product = $this->create_product( 'Linked' );
		$other   = $this->create_product( 'Other linked' );
		$kb      = self::factory()->post->create(
			array(
				'post_type'   => 'saai_kb',
				'post_status' => 'publish',
				'post_title'  => 'An article',
			)
		);
		$this->link( $alpha, $product );
		$this->link( $alpha, $other );

		$input = '<p>Alpha inside.</p>';

		$this->go_to( get_permalink( $product ) );
		$this->assertStringContainsString( 'data-saai-term-id="' . $alpha . '"', apply_filters( 'woocommerce_short_description', $input ) );

		// Both products link Alpha, so only the context check can explain
		// the untouched output here.
		$GLOBALS['post'] = get_post( $other ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulating a product loop iterating on the queried product's page.
		$this->assertSame( $input, apply_filters( 'woocommerce_short_description', $input ) );

		$this->go_to( get_permalink( $kb ) );
		$this->assertSame( $input, apply_filters( 'woocommerce_short_description', $input ) );

		$this->go_to( get_permalink( $product ) );
		update_option( Settings::OPTION_KEY, array( Settings::TOOLTIPS => false ) );
		$this->assertSame( $input, apply_filters( 'woocommerce_short_description', $input ) );

		$this->assertSame( '', $this->service->filter_short_description( '' ) );
		$this->assertNull( $this->service->filter_short_description( null ) );
	}

	/**
	 * The block-theme short description is linked when the excerpt block
	 * renders the displayed product, and left alone when its post context
	 * is another product (a Product Collection item on the same page).
	 */
	public function test_summary_block_is_linked_only_when_its_post_context_is_the_displayed_product() {
		$alpha   = $this->create_term( 'Alpha' );
		$product = $this->create_product( 'Linked', 'Alpha in the summary.' );
		$other   = $this->create_product( 'Other linked', 'Alpha elsewhere.' );
		$this->link( $alpha, $product );
		$this->link( $alpha, $other );

		$parsed = array(
			'blockName'    => 'core/post-excerpt',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

		$this->go_to( get_permalink( $product ) );

		// render_block() supplies the queried product as the default postId context.
		$own = render_block( $parsed );
		$this->assertStringContainsString( 'in the summary', $own );
		$this->assertStringContainsString( 'data-saai-term-id="' . $alpha . '"', $own );

		$foreign = ( new WP_Block(
			$parsed,
			array(
				'postId'   => $other,
				'postType' => Link_Resolver::PRODUCT_POST_TYPE,
			)
		) )->render();
		$this->assertStringContainsString( 'Alpha elsewhere', $foreign );
		$this->assertStringNotContainsString( 'saai-term', $foreign );

		$this->assertSame( '', $this->service->filter_summary_block( '' ) );
		$this->assertNull( $this->service->filter_summary_block( null ) );
	}

	/**
	 * A base instance without the documented autolinker() method leaves the
	 * HTML untouched instead of fataling.
	 */
	public function test_an_unusable_base_plugin_leaves_html_untouched() {
		$this->unregister( $this->service );

		$alpha   = $this->create_term( 'Alpha' );
		$product = $this->create_product( 'Linked' );
		$this->link( $alpha, $product );
		$this->go_to( get_permalink( $product ) );

		$service = new Product_Autolink( null, new Link_Resolver(), new Settings() );
		$this->assertSame( '<p>Alpha</p>', $service->filter_short_description( '<p>Alpha</p>' ) );

		$service = new Product_Autolink( new stdClass(), new Link_Resolver(), new Settings() );
		$this->assertSame( '<p>Alpha</p>', $service->filter_short_description( '<p>Alpha</p>' ) );
	}
}
