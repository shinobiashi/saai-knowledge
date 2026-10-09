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

		// WooCommerce's wc_format_content(), which one test needs on the call
		// stack; see the stub's own docblock for why it lives there.
		require_once dirname( __DIR__, 3 ) . '/tests/stubs/wc-format-content.php';

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
		remove_filter( 'the_content', array( $service, 'filter_long_description' ), 50 );
		remove_filter( 'saai_autolink_post_types', array( $service, 'filter_post_types' ), PHP_INT_MAX );
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
		$this->assertSame( 50, has_filter( 'the_content', array( $this->service, 'filter_long_description' ) ), 'Same priority as the free engine\'s own pass.' );
		$this->assertSame( PHP_INT_MAX, has_filter( 'saai_autolink_post_types', array( $this->service, 'filter_post_types' ) ), 'The last word on whether the free engine sees product.' );
		$this->assertSame( 10, has_filter( 'saai_autolink_dictionary', array( $this->service, 'filter_dictionary' ) ) );
		$this->assertSame( 20, has_filter( 'woocommerce_short_description', array( $this->service, 'filter_short_description' ) ), 'After WooCommerce\'s own formatting callbacks at 9 and 10.' );
		$this->assertSame( 10, has_filter( 'render_block_core/post-excerpt', array( $this->service, 'filter_summary_block' ) ) );
		$this->assertSame( 10, has_filter( 'render_block_woocommerce/product-summary', array( $this->service, 'filter_summary_block' ) ) );
	}

	/**
	 * While tooltips are on, product content is this add-on's business:
	 * `product` is kept out of the free engine's own post types even when
	 * someone else added it; with the toggle off the list is left alone.
	 */
	public function test_product_is_kept_out_of_the_free_engines_post_types_while_tooltips_are_on() {
		$this->assertSame( array( 'post', 'page' ), $this->service->filter_post_types( array( 'post', Link_Resolver::PRODUCT_POST_TYPE, 'page' ) ) );

		// Through the real filter chain, with someone else adding `product`
		// after the free plugin's own priority-10 settings callback (which
		// replaces the incoming list with its saved value): the add-on still
		// has the last word.
		$add = static function ( $types ) {
			$types[] = Link_Resolver::PRODUCT_POST_TYPE;
			return $types;
		};
		add_filter( 'saai_autolink_post_types', $add, 20 );

		try {
			$types = apply_filters( 'saai_autolink_post_types', array( 'post' ) );
		} finally {
			remove_filter( 'saai_autolink_post_types', $add, 20 );
		}

		$this->assertNotContains( Link_Resolver::PRODUCT_POST_TYPE, $types );
		$this->assertContains( 'post', $types );

		update_option( Settings::OPTION_KEY, array( Settings::TOOLTIPS => false ) );
		$this->assertContains( Link_Resolver::PRODUCT_POST_TYPE, $this->service->filter_post_types( array( 'post', Link_Resolver::PRODUCT_POST_TYPE ) ), 'Off: someone else\'s product entry is not removed.' );

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
	 * A fingerprint process() put into the context is what the dictionary
	 * is narrowed to — resolved once for the cache key, not again here.
	 */
	public function test_dictionary_trusts_the_term_fingerprint_in_the_context() {
		$alpha   = $this->create_term( 'Alpha' );
		$bravo   = $this->create_term( 'Bravo' );
		$product = $this->create_product( 'Linked' );
		$this->link( $alpha, $product );

		$entries = array( $this->entry( $alpha, 'Alpha' ), $this->entry( $bravo, 'Bravo' ) );

		// The meta says Alpha only; the context says Bravo only — the context wins.
		$narrowed = apply_filters(
			'saai_autolink_dictionary',
			$entries,
			array(
				'post_id'                       => $product,
				'post_type'                     => Link_Resolver::PRODUCT_POST_TYPE,
				Product_Autolink::CONTEXT_TERMS => array( (string) $bravo, 'junk', -1 ),
			)
		);
		$this->assertSame( array( $entries[1] ), $narrowed );

		$this->assertSame(
			array(),
			apply_filters(
				'saai_autolink_dictionary',
				$entries,
				array(
					'post_id'                       => $product,
					'post_type'                     => Link_Resolver::PRODUCT_POST_TYPE,
					Product_Autolink::CONTEXT_TERMS => array(),
				)
			),
			'An empty fingerprint is an empty dictionary, not a fallback to the meta.'
		);
	}

	/**
	 * End to end through the `the_content` filter chain — where the add-on's
	 * own filter_long_description() does the work, the free engine's pass
	 * having been told to leave `product` alone: on a product page only the
	 * linked term is linked, a product with no links gets nothing, and the
	 * toggle switches it all off.
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
	 * The free engine caches a render under a key that folds in the
	 * $context but not what the dictionary filter returned — so the linked
	 * term set has to be IN the context, or a link added/removed through
	 * the product meta box (meta rows only, no post save, no dictionary
	 * generation bump) would keep serving the old HTML for up to an hour.
	 */
	public function test_changing_the_links_changes_the_engine_context_and_defeats_the_stale_cache() {
		$alpha   = $this->create_term( 'Alpha' );
		$bravo   = $this->create_term( 'Bravo' );
		$product = $this->create_product( 'Linked' );
		$this->link( $alpha, $product );

		$content = '<p>Alpha meets Bravo.</p>';

		// One request: go_to() flushes the object cache, so the renders after
		// the first must NOT go through it again — the point is to hit the
		// engine's cache with the same post, html, and generation, exactly as
		// a persistent object cache would across requests.
		$this->go_to( get_permalink( $product ) );
		$this->assertTrue( is_singular( Link_Resolver::PRODUCT_POST_TYPE ) );
		the_post();

		$first = apply_filters( 'the_content', $content );
		$this->assertStringContainsString( 'data-saai-term-id="' . $alpha . '"', $first );
		$this->assertStringNotContainsString( 'data-saai-term-id="' . $bravo . '"', $first, 'Precondition: only Alpha is linked at first.' );

		// Exactly what the product-side meta box does: one meta row, nothing else.
		$this->link( $bravo, $product );

		$second = apply_filters( 'the_content', $content );
		$this->assertStringContainsString( 'data-saai-term-id="' . $bravo . '"', $second, 'The new link must show up although the product and the dictionary generation are unchanged.' );

		delete_post_meta( $alpha, Post_Meta::LINKED_PRODUCTS, $product );

		$third = apply_filters( 'the_content', $content );
		$this->assertStringNotContainsString( 'data-saai-term-id="' . $alpha . '"', $third, 'A removed link must disappear as well.' );
		$this->assertStringContainsString( 'data-saai-term-id="' . $bravo . '"', $third );
	}

	/**
	 * The long-description route carries the free engine's own request
	 * guards: nothing happens in admin or in a feed.
	 */
	public function test_long_description_is_left_alone_in_admin_and_feeds() {
		$alpha   = $this->create_term( 'Alpha' );
		$product = $this->create_product( 'Linked' );
		$this->link( $alpha, $product );

		$content = '<p>Alpha here.</p>';

		$this->assertStringContainsString( 'saai-term', $this->render_content_on( $product, $content ), 'Precondition: linked on the front end.' );

		$this->go_to( add_query_arg( 'feed', 'rss2', get_permalink( $product ) ) );
		$this->assertTrue( is_feed() );
		$GLOBALS['post'] = get_post( $product ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the feed loop's current item.
		$this->assertStringNotContainsString( 'saai-term', $this->service->filter_long_description( $content ) );

		$this->go_to( get_permalink( $product ) );
		set_current_screen( 'edit-post' );

		try {
			$this->assertTrue( is_admin() );
			$this->assertStringNotContainsString( 'saai-term', $this->service->filter_long_description( $content ) );
		} finally {
			set_current_screen( 'front' );
		}

		$this->assertSame( '', $this->service->filter_long_description( '' ) );
		$this->assertNull( $this->service->filter_long_description( null ) );
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
	 * Text WooCommerce formats through wc_format_content() — variation
	 * descriptions, Featured Product / Category blocks, cart item data — is
	 * not the displayed short description and stays untouched even on the
	 * product page, while the template's direct filter call is linked.
	 */
	public function test_short_description_is_left_alone_inside_wc_format_content() {
		$alpha   = $this->create_term( 'Alpha' );
		$product = $this->create_product( 'Linked' );
		$this->link( $alpha, $product );
		$this->go_to( get_permalink( $product ) );

		$input = '<p>Alpha inside.</p>';

		$this->assertStringContainsString( 'data-saai-term-id="' . $alpha . '"', apply_filters( 'woocommerce_short_description', $input ), 'Precondition: the direct filter call is linked.' );
		$this->assertSame( $input, wc_format_content( $input ) );
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
