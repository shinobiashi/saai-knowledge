<?php
/**
 * Tests for the product page's FAQ tab and related-documentation section.
 *
 * @package SAAI\KnowledgeWoo
 */

use SAAI\KnowledgeWoo\Link_Resolver;
use SAAI\KnowledgeWoo\Post_Meta;
use SAAI\KnowledgeWoo\Product_Context;
use SAAI\KnowledgeWoo\Product_Page;
use SAAI\KnowledgeWoo\Settings;

/**
 * Class Test_Woo_Product_Page.
 */
class Test_Woo_Product_Page extends WP_UnitTestCase {

	/**
	 * Service under test. Not register()'d: its methods are called directly,
	 * except in the one test that checks register() itself.
	 *
	 * @var Product_Page
	 */
	private $page;

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
	 * The faq-list block type found before a test swapped it, if any.
	 *
	 * @var \WP_Block_Type|null
	 */
	private $original_faq_block = null;

	/**
	 * Whether this test touched the faq-list block registration.
	 *
	 * @var bool
	 */
	private $swapped_faq_block = false;

	/**
	 * Registers the linking meta and the WooCommerce stand-ins.
	 */
	public function set_up() {
		parent::set_up();

		// The core test framework unregisters every meta key after each test.
		( new Post_Meta() )->register_post_meta();
		delete_option( Settings::OPTION_KEY );

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

		$this->page = new Product_Page( new Link_Resolver(), new Settings() );
	}

	/**
	 * Restores the block registry, the WooCommerce global, and the stand-ins.
	 */
	public function tear_down() {
		$this->restore_faq_list_block();
		unset( $GLOBALS['product'] );

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
	 * Creates a published stand-in product.
	 *
	 * @param string $title Product title.
	 * @return int Post ID.
	 */
	private function create_product( string $title = 'Product' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => Link_Resolver::PRODUCT_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	/**
	 * Creates a published content post of one of the free plugin's types.
	 *
	 * @param string               $post_type saai_faq / saai_kb / saai_glossary.
	 * @param string               $title     Post title.
	 * @param array<string, mixed> $args      Extra wp_insert_post() args.
	 * @return int Post ID.
	 */
	private function create_content( string $post_type, string $title, array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => $post_type,
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => '<!-- wp:paragraph --><p>Body of ' . $title . '</p><!-- /wp:paragraph -->',
				),
				$args
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
	 * Makes the request the single view of a product.
	 *
	 * @param int $product_id Product post ID.
	 */
	private function go_to_product( int $product_id ): void {
		$this->go_to( get_permalink( $product_id ) );

		$this->assertTrue( is_singular( Link_Resolver::PRODUCT_POST_TYPE ), 'Precondition: the request must be the single product view.' );
		$this->assertSame( $product_id, Product_Context::current_product_id() );
	}

	/**
	 * Swaps in a faq-list block registration backed by the free plugin's
	 * render.php straight from its src/.
	 *
	 * The real block type is only registered when the free plugin's JS
	 * build exists, which CI's PHPUnit job doesn't have (same situation as
	 * the free plugin's own test-faq-list.php). tear_down() restores
	 * whatever was registered before.
	 */
	private function register_src_faq_list_block(): void {
		$this->unregister_faq_list_block();

		register_block_type(
			Product_Page::FAQ_BLOCK,
			array(
				'uses_context'    => array( 'postId' ),
				'render_callback' => static function ( $attributes, $content, $block ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- all three are consumed by the required render.php via the closure scope, matching the register_block_type_from_metadata() contract.
					ob_start();
					require dirname( __DIR__, 2 ) . '/saai-knowledge/src/faq-list/render.php';

					return ob_get_clean();
				},
			)
		);
	}

	/**
	 * Leaves the faq-list block unregistered for the rest of the test.
	 */
	private function unregister_faq_list_block(): void {
		$registry = \WP_Block_Type_Registry::get_instance();

		if ( ! $this->swapped_faq_block ) {
			$this->original_faq_block = $registry->get_registered( Product_Page::FAQ_BLOCK );
			$this->swapped_faq_block  = true;
		}

		if ( $registry->is_registered( Product_Page::FAQ_BLOCK ) ) {
			$registry->unregister( Product_Page::FAQ_BLOCK );
		}
	}

	/**
	 * Restores the block registry after register_src_faq_list_block() /
	 * unregister_faq_list_block().
	 */
	private function restore_faq_list_block(): void {
		if ( ! $this->swapped_faq_block ) {
			return;
		}

		$registry = \WP_Block_Type_Registry::get_instance();

		if ( $registry->is_registered( Product_Page::FAQ_BLOCK ) ) {
			$registry->unregister( Product_Page::FAQ_BLOCK );
		}

		if ( $this->original_faq_block ) {
			$registry->register( $this->original_faq_block );
		}

		$this->original_faq_block = null;
		$this->swapped_faq_block  = false;
	}

	/**
	 * How many callbacks a hook currently has, across all priorities.
	 *
	 * @param string $hook Hook name.
	 */
	private function filter_callback_count( string $hook ): int {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return 0;
		}

		$count = 0;

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			$count += count( $callbacks );
		}

		return $count;
	}

	/**
	 * A sample of what WooCommerce hands the tabs filter.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function default_tabs(): array {
		return array(
			'description' => array(
				'title'    => 'Description',
				'priority' => 10,
				'callback' => 'woocommerce_product_description_tab',
			),
		);
	}

	/**
	 * The register() method attaches both insertions at the documented priorities.
	 */
	public function test_register_attaches_the_documented_hooks() {
		$page = new Product_Page( new Link_Resolver(), new Settings() );
		$page->register();

		try {
			$this->assertSame( 20, has_filter( 'woocommerce_product_tabs', array( $page, 'add_faq_tab' ) ) );
			$this->assertSame( Product_Page::KB_LINKS_PRIORITY, has_action( 'woocommerce_after_single_product_summary', array( $page, 'render_kb_links' ) ) );
		} finally {
			remove_filter( 'woocommerce_product_tabs', array( $page, 'add_faq_tab' ), 20 );
			remove_action( 'woocommerce_after_single_product_summary', array( $page, 'render_kb_links' ), Product_Page::KB_LINKS_PRIORITY );
		}
	}

	/**
	 * The shared "which product is this page showing" answer: only the
	 * queried product on its own single view counts.
	 */
	public function test_current_product_id_requires_the_queried_product() {
		$product = $this->create_product( 'Shown' );
		$other   = $this->create_product( 'Looped' );
		$kb      = $this->create_content( 'saai_kb', 'An article' );

		$this->go_to_product( $product );

		// A loop further down the page (related products) has swapped the
		// global post for another product.
		$GLOBALS['post'] = get_post( $other ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulating a product loop iterating on the queried product's page.
		$this->assertSame( 0, Product_Context::current_product_id() );

		$this->go_to( get_permalink( $kb ) );
		$this->assertSame( 0, Product_Context::current_product_id() );

		$this->go_to( add_query_arg( 'post_type', Link_Resolver::PRODUCT_POST_TYPE, home_url( '/' ) ) );
		$this->assertFalse( is_singular() );
		$this->assertSame( 0, Product_Context::current_product_id() );
	}

	/**
	 * The tab appears only on a product that has at least one published
	 * FAQ linked to it; a product with no links shows nothing at all.
	 */
	public function test_faq_tab_is_added_only_for_a_product_with_linked_faqs() {
		$this->register_src_faq_list_block();

		$linked = $this->create_product( 'Linked' );
		$plain  = $this->create_product( 'Plain' );
		$faq    = $this->create_content( 'saai_faq', 'How does it work?' );
		$draft  = $this->create_content( 'saai_faq', 'Unpublished?', array( 'post_status' => 'draft' ) );

		$this->link( $faq, $linked );
		$this->link( $draft, $plain );

		$this->go_to_product( $linked );
		$tabs = $this->page->add_faq_tab( $this->default_tabs() );

		$this->assertArrayHasKey( 'description', $tabs, 'WooCommerce\'s own tabs must be kept.' );
		$this->assertArrayHasKey( Product_Page::FAQ_TAB_KEY, $tabs );
		$this->assertSame( 'FAQ', $tabs[ Product_Page::FAQ_TAB_KEY ]['title'] );
		$this->assertSame( Product_Page::FAQ_TAB_PRIORITY, $tabs[ Product_Page::FAQ_TAB_KEY ]['priority'] );
		$this->assertSame( array( $this->page, 'render_faq_tab' ), $tabs[ Product_Page::FAQ_TAB_KEY ]['callback'] );

		// Only a draft is linked here: that is "no links" as far as the page goes.
		$this->go_to_product( $plain );
		$this->assertSame( $this->default_tabs(), $this->page->add_faq_tab( $this->default_tabs() ) );
	}

	/**
	 * No tab when the toggle is off, when the free plugin's block isn't
	 * registered, off the single product page, or for a non-array input.
	 */
	public function test_faq_tab_is_not_added_when_disabled_or_without_the_block_or_off_the_product_page() {
		$this->register_src_faq_list_block();

		$product = $this->create_product();
		$faq     = $this->create_content( 'saai_faq', 'How does it work?' );
		$this->link( $faq, $product );

		$this->go_to_product( $product );
		$this->assertArrayHasKey( Product_Page::FAQ_TAB_KEY, $this->page->add_faq_tab( $this->default_tabs() ), 'Precondition: the tab is added when everything is in place.' );

		update_option( Settings::OPTION_KEY, array( Settings::FAQ_TAB => false ) );
		$this->assertSame( $this->default_tabs(), $this->page->add_faq_tab( $this->default_tabs() ) );
		$this->assertSame( '', $this->page->faq_list_html( $product ) );
		delete_option( Settings::OPTION_KEY );

		$this->unregister_faq_list_block();
		$this->assertSame( $this->default_tabs(), $this->page->add_faq_tab( $this->default_tabs() ) );
		$this->assertSame( '', $this->page->faq_list_html( $product ) );
		$this->register_src_faq_list_block();

		$this->go_to( get_permalink( $faq ) );
		$this->assertSame( $this->default_tabs(), $this->page->add_faq_tab( $this->default_tabs() ) );

		$this->assertSame( 'broken', $this->page->add_faq_tab( 'broken' ) );
	}

	/**
	 * The tab body is the free plugin's faq-list block narrowed to the
	 * linked FAQs — direct and category-inherited — in the resolver's order,
	 * and the query restriction is detached again afterwards.
	 */
	public function test_faq_tab_body_lists_only_the_linked_faqs_in_resolver_order() {
		$this->register_src_faq_list_block();

		$product  = $this->create_product();
		$category = self::factory()->term->create( array( 'taxonomy' => Link_Resolver::PRODUCT_TAXONOMY ) );
		wp_set_object_terms( $product, array( $category ), Link_Resolver::PRODUCT_TAXONOMY );

		$second    = $this->create_content( 'saai_faq', 'Second question?', array( 'menu_order' => 2 ) );
		$first     = $this->create_content( 'saai_faq', 'First question?', array( 'menu_order' => 1 ) );
		$inherited = $this->create_content( 'saai_faq', 'Inherited question?', array( 'menu_order' => 3 ) );
		$unlinked  = $this->create_content( 'saai_faq', 'Unlinked question?' );
		$kb        = $this->create_content( 'saai_kb', 'A KB article' );

		$this->link( $second, $product );
		$this->link( $first, $product );
		$this->link( $kb, $product );
		add_post_meta( $inherited, Post_Meta::LINKED_PRODUCT_CATS, $category );

		$before = $this->filter_callback_count( 'saai_faq_query_args' );

		$this->go_to_product( $product );
		$html = $this->page->faq_list_html( $product );

		$this->assertSame( $before, $this->filter_callback_count( 'saai_faq_query_args' ), 'The query restriction must be detached after rendering.' );
		$this->assertStringContainsString( 'wp-block-accordion', $html, 'The body is the free plugin\'s accordion markup.' );
		$this->assertStringContainsString( 'First question?', $html );
		$this->assertStringContainsString( 'Second question?', $html );
		$this->assertStringContainsString( 'Inherited question?', $html );
		$this->assertStringNotContainsString( 'Unlinked question?', $html );
		$this->assertStringNotContainsString( 'A KB article', $html );
		$this->assertStringContainsString( 'Body of First question?', $html, 'Answers are rendered, not just questions.' );
		$this->assertLessThan( strpos( $html, 'Second question?' ), strpos( $html, 'First question?' ) );
		$this->assertLessThan( strpos( $html, 'Inherited question?' ), strpos( $html, 'Second question?' ) );

		// The FAQ list on the product page must not leak into the page's own
		// query: another faq-list render afterwards still sees every FAQ.
		$this->assertStringContainsString(
			'Unlinked question?',
			render_block(
				array(
					'blockName'    => Product_Page::FAQ_BLOCK,
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				)
			)
		);

		$this->assertGreaterThan( 0, $unlinked );
	}

	/**
	 * Rendering an FAQ answer fires `the_post` for the FAQ, which WooCommerce
	 * answers by unsetting its $GLOBALS['product']; the tab must put the
	 * product back so the Reviews tab rendered right after still has it.
	 */
	public function test_faq_tab_body_restores_the_woocommerce_product_global() {
		$this->register_src_faq_list_block();

		$product = $this->create_product();
		$faq     = $this->create_content( 'saai_faq', 'Any question?' );
		$this->link( $faq, $product );
		$this->go_to_product( $product );

		// Stand-in for wc_setup_product_data(), which WooCommerce hooks on
		// `the_post` and which unsets the product global for any
		// non-product post.
		$fired = 0;
		$reset = static function ( $post ) use ( &$fired ) {
			if ( $post instanceof WP_Post && 'saai_faq' === $post->post_type ) {
				++$fired;
				unset( $GLOBALS['product'] );
			}
		};
		add_action( 'the_post', $reset );

		$sentinel           = new stdClass();
		$GLOBALS['product'] = $sentinel; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- standing in for WooCommerce's own global.

		try {
			$html = $this->page->faq_list_html( $product );
		} finally {
			remove_action( 'the_post', $reset );
		}

		$this->assertStringContainsString( 'Any question?', $html );
		$this->assertGreaterThan( 0, $fired, 'Precondition: rendering the answer must have fired the_post for the FAQ.' );
		$this->assertSame( $sentinel, $GLOBALS['product'] ?? null );

		// And a global that wasn't there to begin with isn't invented.
		unset( $GLOBALS['product'] );
		$this->page->faq_list_html( $product );
		$this->assertArrayNotHasKey( 'product', $GLOBALS );
	}

	/**
	 * The tab callback WooCommerce invokes echoes the same body.
	 */
	public function test_render_faq_tab_echoes_the_list_body() {
		$this->register_src_faq_list_block();

		$product = $this->create_product();
		$faq     = $this->create_content( 'saai_faq', 'Echoed question?' );
		$this->link( $faq, $product );
		$this->go_to_product( $product );

		ob_start();
		$this->page->render_faq_tab( Product_Page::FAQ_TAB_KEY, array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Echoed question?', $output );

		// core/accordion numbers its items from a per-process counter, so two
		// renders of the same list differ only in those generated IDs.
		$normalize = static function ( string $html ): string {
			return (string) preg_replace( '/accordion-item-\d+/', 'accordion-item-N', $html );
		};
		$this->assertSame( $normalize( $this->page->faq_list_html( $product ) ), $normalize( $output ) );

		$this->go_to( home_url( '/' ) );

		ob_start();
		$this->page->render_faq_tab( Product_Page::FAQ_TAB_KEY, array() );
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The section lists the linked KB articles — only KB, in resolver
	 * order — with every title escaped.
	 */
	public function test_kb_links_lists_linked_articles_and_escapes_titles() {
		$product  = $this->create_product();
		$bravo    = $this->create_content( 'saai_kb', 'Bravo guide', array( 'menu_order' => 2 ) );
		$alpha    = $this->create_content( 'saai_kb', 'Alpha guide', array( 'menu_order' => 1 ) );
		$faq      = $this->create_content( 'saai_faq', 'Not a KB' );
		$term     = $this->create_content( 'saai_glossary', 'Not a KB either' );
		$unlinked = $this->create_content( 'saai_kb', 'Unlinked guide' );

		foreach ( array( $bravo, $alpha, $faq, $term ) as $id ) {
			$this->link( $id, $product );
		}

		// The free plugin's own tests use this trick too: a fixed title
		// without special characters can't tell an escaped output from a
		// raw one, so inject markup through the filter get_the_title() runs.
		$inject = static function ( $title, $post_id ) use ( $alpha ) {
			return (int) $post_id === $alpha ? 'Alpha <script>alert(1)</script> & guide' : $title;
		};
		add_filter( 'the_title', $inject, 10, 2 );

		try {
			$html = $this->page->kb_links_html( $product );
		} finally {
			remove_filter( 'the_title', $inject, 10 );
		}

		$this->assertStringStartsWith( '<section class="saai-woo-related-kb"', $html );
		$this->assertStringContainsString( 'Related documentation', $html );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $alpha ) ) . '"', $html );
		$this->assertStringContainsString( 'Alpha &lt;script&gt;alert(1)&lt;/script&gt; &amp; guide', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'Bravo guide', $html );
		$this->assertStringNotContainsString( 'Not a KB', $html );
		$this->assertStringNotContainsString( 'Unlinked guide', $html );
		$this->assertLessThan( strpos( $html, 'Bravo guide' ), strpos( $html, 'Alpha' ) );
		$this->assertSame( 2, substr_count( $html, '<li class="saai-woo-related-kb__item">' ) );
		$this->assertGreaterThan( 0, $unlinked );
	}

	/**
	 * Nothing is rendered without linked KB articles, with the toggle off,
	 * or for an unusable product ID.
	 */
	public function test_kb_links_render_nothing_without_links_or_when_disabled() {
		$product = $this->create_product();
		$faq     = $this->create_content( 'saai_faq', 'Only a FAQ is linked' );
		$this->link( $faq, $product );

		$this->assertSame( '', $this->page->kb_links_html( $product ) );
		$this->assertSame( '', $this->page->kb_links_html( 0 ) );

		$kb = $this->create_content( 'saai_kb', 'Now a KB is linked' );
		$this->link( $kb, $product );
		$this->assertStringContainsString( 'Now a KB is linked', $this->page->kb_links_html( $product ), 'Precondition: the section renders once a KB is linked.' );

		update_option( Settings::OPTION_KEY, array( Settings::KB_LINKS => false ) );
		$this->assertSame( '', $this->page->kb_links_html( $product ) );
	}

	/**
	 * An article saved without a title still gets link text.
	 */
	public function test_kb_links_fall_back_to_a_placeholder_for_an_empty_title() {
		$product = $this->create_product();
		$kb      = $this->create_content( 'saai_kb', '' );
		$this->link( $kb, $product );

		$html = $this->page->kb_links_html( $product );

		$this->assertStringContainsString( '>(no title)</a>', $html );
	}

	/**
	 * The template-hook callback echoes the section on the single product
	 * page only.
	 */
	public function test_render_kb_links_echoes_only_on_the_single_product_page() {
		$product = $this->create_product();
		$kb      = $this->create_content( 'saai_kb', 'Setup guide' );
		$this->link( $kb, $product );

		$this->go_to( home_url( '/' ) );

		ob_start();
		$this->page->render_kb_links();
		$this->assertSame( '', ob_get_clean() );

		$this->go_to_product( $product );

		ob_start();
		$this->page->render_kb_links();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Setup guide', $output );
		$this->assertSame( $this->page->kb_links_html( $product ), $output );
	}
}
