<?php
/**
 * Tests for the manual-placement product blocks.
 *
 * @package SAAI\KnowledgeWoo
 */

use SAAI\KnowledgeWoo\Blocks;
use SAAI\KnowledgeWoo\Link_Resolver;
use SAAI\KnowledgeWoo\Post_Meta;
use SAAI\KnowledgeWoo\Product_Context;
use SAAI\KnowledgeWoo\Product_Page;
use SAAI\KnowledgeWoo\Product_Sections;
use SAAI\KnowledgeWoo\Settings;
use SAAI\KnowledgeWoo\Shortcodes;

/**
 * Class Test_Woo_Product_Blocks.
 */
class Test_Woo_Product_Blocks extends WP_UnitTestCase {

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
	 * Block types found before this test swapped them, keyed by name (null
	 * when the name wasn't registered).
	 *
	 * @var array<string, \WP_Block_Type|null>
	 */
	private $original_blocks = array();

	/**
	 * Registers the linking meta, the WooCommerce stand-ins, and the blocks
	 * from src/.
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
					'public'      => true,
					'has_archive' => true,
					'label'       => 'Products',
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

		$this->register_src_block(
			Product_Sections::FAQ_BLOCK,
			array( 'uses_context' => array( 'postId' ) ),
			dirname( __DIR__, 2 ) . '/saai-knowledge/src/faq-list/render.php'
		);

		foreach ( Blocks::BLOCKS as $block ) {
			$metadata = wp_json_file_decode( dirname( __DIR__ ) . "/src/{$block}/block.json", array( 'associative' => true ) );

			$this->register_src_block(
				$metadata['name'],
				array(
					'attributes'   => $metadata['attributes'],
					'uses_context' => $metadata['usesContext'],
				),
				dirname( __DIR__ ) . "/src/{$block}/render.php"
			);
		}
	}

	/**
	 * Restores the block registry, the WooCommerce global, and the stand-ins.
	 */
	public function tear_down() {
		$registry = \WP_Block_Type_Registry::get_instance();

		foreach ( $this->original_blocks as $name => $original ) {
			if ( $registry->is_registered( $name ) ) {
				$registry->unregister( $name );
			}

			if ( $original ) {
				$registry->register( $original );
			}
		}

		$this->original_blocks = array();
		unset( $GLOBALS['product'] );

		foreach ( array_keys( Shortcodes::SHORTCODE_BLOCKS ) as $tag ) {
			remove_shortcode( $tag );
		}

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
	 * Registers a block backed by a render.php straight from src/.
	 *
	 * The real block types are only registered when the JS build exists,
	 * which CI's PHPUnit job doesn't have; tear_down() restores whatever was
	 * registered before.
	 *
	 * @param string               $name        Block name.
	 * @param array<string, mixed> $args        Extra register_block_type() args.
	 * @param string               $render_file render.php to require.
	 */
	private function register_src_block( string $name, array $args, string $render_file ): void {
		$registry = \WP_Block_Type_Registry::get_instance();

		if ( ! array_key_exists( $name, $this->original_blocks ) ) {
			$this->original_blocks[ $name ] = $registry->get_registered( $name );
		}

		if ( $registry->is_registered( $name ) ) {
			$registry->unregister( $name );
		}

		register_block_type(
			$name,
			array_merge(
				$args,
				array(
					'render_callback' => static function ( $attributes, $content, $block ) use ( $render_file ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- all three are consumed by the required render.php via the closure scope, matching the register_block_type_from_metadata() contract.
						ob_start();
						require $render_file;

						return ob_get_clean();
					},
				)
			)
		);
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
					'post_excerpt' => '',
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
	 * Creates a product with one linked FAQ, KB article, and glossary term.
	 *
	 * @return int Product post ID.
	 */
	private function create_linked_product(): int {
		$product = $this->create_product( 'Linked product' );

		$this->link( $this->create_content( 'saai_faq', 'Linked question?' ), $product );
		$this->link( $this->create_content( 'saai_kb', 'Linked guide' ), $product );
		$this->link(
			$this->create_content( 'saai_glossary', 'Linked term', array( 'post_excerpt' => 'What the term means.' ) ),
			$product
		);

		return $product;
	}

	/**
	 * Serializes a product block for do_blocks().
	 *
	 * @param string               $block Block directory name (product-faq, ...).
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function block_markup( string $block, array $attrs = array() ): string {
		return get_comment_delimited_block_content( 'saai-knowledge/' . $block, $attrs, '' );
	}

	/**
	 * Every block the registration list names has its source metadata,
	 * under the documented name and the add-on's text domain.
	 */
	public function test_every_registered_block_has_matching_source_metadata() {
		foreach ( Blocks::BLOCKS as $block ) {
			$metadata = wp_json_file_decode( dirname( __DIR__ ) . "/src/{$block}/block.json", array( 'associative' => true ) );

			$this->assertSame( 'saai-knowledge/' . $block, $metadata['name'] );
			$this->assertSame( 'saai-knowledge-for-woocommerce', $metadata['textdomain'] );
			$this->assertSame( array( 'postId', 'queryId' ), $metadata['usesContext'] );
			$this->assertFileExists( dirname( __DIR__ ) . "/src/{$block}/render.php" );
		}
	}

	/**
	 * The register() method hooks block registration into init.
	 */
	public function test_register_hooks_block_registration_into_init() {
		$blocks = new Blocks();
		$blocks->register();

		try {
			$this->assertSame( 10, has_action( 'init', array( $blocks, 'register_blocks' ) ) );
		} finally {
			remove_action( 'init', array( $blocks, 'register_blocks' ) );
		}
	}

	/**
	 * The `productId` attribute names the product outright, even over a
	 * product in the block context; anything that isn't a product resolves
	 * to nothing rather than falling back.
	 */
	public function test_for_block_prefers_the_product_id_attribute() {
		$chosen  = $this->create_product( 'Chosen' );
		$context = $this->create_product( 'From context' );
		$page    = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertSame( $chosen, Product_Context::for_block( $chosen, array( 'postId' => $context ) ) );
		$this->assertSame( $chosen, Product_Context::for_block( (string) $chosen, array() ) );
		$this->assertSame( 0, Product_Context::for_block( $page, array( 'postId' => $context ) ), 'A non-product ID must not fall back to the context.' );
		$this->assertSame( 0, Product_Context::for_block( 999999, array( 'postId' => $context ) ) );
	}

	/**
	 * Without the attribute, the block context's post is used when it is a
	 * product; without either, the single product page being viewed.
	 */
	public function test_for_block_falls_back_to_the_context_then_the_viewed_product() {
		$product = $this->create_product();
		$page    = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->go_to( get_permalink( $page ) );

		$this->assertSame( $product, Product_Context::for_block( 0, array( 'postId' => $product ) ) );
		$this->assertSame( 0, Product_Context::for_block( 0, array( 'postId' => $page ) ) );
		$this->assertSame( 0, Product_Context::for_block( 'not a number', array() ) );

		$this->go_to( get_permalink( $product ) );
		$this->assertSame( $product, Product_Context::for_block( 0, array() ) );
	}

	/**
	 * On an archive, a root-level block's postId context is only the first
	 * result WordPress primed — not the page's product — while a block in a
	 * query loop (queryId context) is rendering that item.
	 */
	public function test_for_block_ignores_the_primed_post_at_the_root_of_an_archive() {
		$product = $this->create_product();

		$this->go_to( get_post_type_archive_link( Link_Resolver::PRODUCT_POST_TYPE ) );
		$this->assertTrue( is_archive(), 'Precondition: the request must be the product archive.' );

		$this->assertSame( 0, Product_Context::for_block( 0, array( 'postId' => $product ) ) );
		$this->assertSame(
			$product,
			Product_Context::for_block(
				0,
				array(
					'postId'  => $product,
					'queryId' => 1,
				)
			)
		);
		$this->assertSame( $product, Product_Context::for_block( $product, array() ), 'An explicit product still applies on an archive.' );
	}

	/**
	 * In the Single Product template the three blocks list the viewed
	 * product's linked content, each under its own heading — whatever the
	 * automatic-insertion toggles say.
	 */
	public function test_blocks_list_the_viewed_products_content_regardless_of_the_toggles() {
		$product = $this->create_linked_product();

		update_option(
			Settings::OPTION_KEY,
			array(
				Settings::FAQ_TAB  => false,
				Settings::KB_LINKS => false,
				Settings::TOOLTIPS => false,
			)
		);

		$this->go_to( get_permalink( $product ) );

		$faq = do_blocks( $this->block_markup( 'product-faq' ) );
		$this->assertStringContainsString( 'wp-block-saai-knowledge-product-faq', $faq );
		$this->assertMatchesRegularExpression( '#<section class="[^"]*saai-woo-product-faq[^"]*" aria-labelledby="(saai-woo-product-faq-title-\d+)"><h2 id="\1" class="saai-woo-product-faq__title">FAQ</h2>#', $faq );
		$this->assertStringContainsString( 'Linked question?', $faq );

		$docs = do_blocks( $this->block_markup( 'product-docs' ) );
		$this->assertStringContainsString( '<h2 id="saai-woo-product-docs-title-', $docs );
		$this->assertStringContainsString( 'Related documentation</h2><ul class="saai-woo-product-docs__list"><li class="saai-woo-product-docs__item"><a href="', $docs );
		$this->assertStringContainsString( '>Linked guide</a>', $docs );

		$glossary = do_blocks( $this->block_markup( 'product-glossary' ) );
		$this->assertStringContainsString( 'Glossary</h2><dl class="saai-woo-product-glossary__list">', $glossary );
		$this->assertStringContainsString( '>Linked term</a></dt><dd class="saai-woo-product-glossary__definition">What the term means.</dd>', $glossary );
	}

	/**
	 * On a regular page, `productId` picks the product; the heading can be
	 * switched off, which also drops the section wrapper.
	 */
	public function test_blocks_show_a_chosen_product_on_a_regular_page() {
		$product = $this->create_linked_product();
		$page    = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->go_to( get_permalink( $page ) );

		$this->assertSame( '', do_blocks( $this->block_markup( 'product-docs' ) ), 'A page is no product; nothing renders without the attribute.' );

		$docs = do_blocks(
			$this->block_markup(
				'product-docs',
				array(
					'productId' => $product,
					'showTitle' => false,
				)
			)
		);

		$this->assertMatchesRegularExpression( '#^<div class="[^"]*\bwp-block-saai-knowledge-product-docs\b[^"]*"><ul class="saai-woo-product-docs__list">#', $docs );
		$this->assertStringNotContainsString( '<h2', $docs );
		$this->assertStringContainsString( '>Linked guide</a>', $docs );
	}

	/**
	 * A product with nothing linked renders nothing at all — no empty
	 * heading in a Single Product template shared by every product.
	 */
	public function test_blocks_render_nothing_without_linked_content() {
		$product = $this->create_product( 'Unlinked' );

		$this->go_to( get_permalink( $product ) );

		foreach ( Blocks::BLOCKS as $block ) {
			$this->assertSame( '', do_blocks( $this->block_markup( $block ) ), $block );
			$this->assertSame( '', do_blocks( $this->block_markup( $block, array( 'productId' => $product ) ) ), $block );
		}
	}

	/**
	 * The block's FAQ list and the FAQ tab on the same product page print
	 * one FAQPage between them: their markers differ, so whichever renders
	 * first keeps the free plugin's single slot.
	 */
	public function test_product_faq_block_and_faq_tab_print_one_faq_page() {
		$product = $this->create_linked_product();

		$this->go_to( get_permalink( $product ) );

		$block = do_blocks( $this->block_markup( 'product-faq' ) );
		$tab   = ( new Product_Page( new Link_Resolver(), new Settings() ) )->faq_list_html( $product );

		$this->assertStringContainsString( '"@type":"FAQPage"', $block );
		$this->assertStringContainsString( 'Linked question?', $tab );
		$this->assertStringNotContainsString( 'FAQPage', $tab );
	}

	/**
	 * Titles and definitions are escaped on output.
	 */
	public function test_glossary_output_is_escaped() {
		$product = $this->create_product();
		$term    = $this->create_content( 'saai_glossary', 'Term', array( 'post_excerpt' => 'Uses &lt;script&gt; tags.' ) );
		$this->link( $term, $product );

		$poison = static function ( $title, $post_id = 0 ) use ( $term ) {
			return (int) $post_id === $term ? '<script>alert(1)</script>' : $title;
		};
		add_filter( 'the_title', $poison, 10, 2 );

		try {
			$html = do_blocks( $this->block_markup( 'product-glossary', array( 'productId' => $product ) ) );
		} finally {
			remove_filter( 'the_title', $poison, 10 );
		}

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;</a></dt>', $html );
		$this->assertStringContainsString( '<dd class="saai-woo-product-glossary__definition">Uses &lt;script&gt; tags.</dd>', $html );
	}

	/**
	 * The shortcodes are registered under their documented tags.
	 */
	public function test_shortcodes_are_registered() {
		( new Shortcodes() )->register_shortcodes();

		foreach ( array( 'saai_product_faq', 'saai_product_docs', 'saai_product_glossary' ) as $tag ) {
			$this->assertTrue( shortcode_exists( $tag ), $tag );
		}
	}

	/**
	 * A shortcode in a product's description resolves that product, like
	 * the block; on a regular page `product_id` picks it and `show_title`
	 * drops the heading.
	 */
	public function test_shortcodes_render_their_blocks() {
		( new Shortcodes() )->register_shortcodes();

		$product = $this->create_linked_product();
		$page    = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->go_to( get_permalink( $product ) );

		$faq = do_shortcode( '[saai_product_faq]' );
		$this->assertStringContainsString( 'wp-block-saai-knowledge-product-faq', $faq );
		$this->assertStringContainsString( 'Linked question?', $faq );

		$this->go_to( get_permalink( $page ) );

		$this->assertSame( '', do_shortcode( '[saai_product_docs]' ), 'A page is no product.' );

		$docs = do_shortcode( '[saai_product_docs product_id="' . $product . '" show_title="false" unknown="1"]' );
		$this->assertStringContainsString( '>Linked guide</a>', $docs );
		$this->assertStringNotContainsString( '<h2', $docs );

		$glossary = do_shortcode( '[saai_product_glossary product_id="' . $product . '" show_title="yes"]' );
		$this->assertStringContainsString( 'Glossary</h2>', $glossary );
		$this->assertStringContainsString( '>Linked term</a>', $glossary );
	}

	/**
	 * `show_title` takes the usual hand-typed spellings of a boolean; a
	 * value that is none keeps the block's default (heading shown).
	 */
	public function test_shortcode_show_title_accepts_common_boolean_spellings() {
		( new Shortcodes() )->register_shortcodes();

		$product = $this->create_linked_product();

		foreach ( array( 'false', '0', 'no', 'off', 'NO' ) as $off ) {
			$this->assertStringNotContainsString( '<h2', do_shortcode( '[saai_product_docs product_id="' . $product . '" show_title="' . $off . '"]' ), $off );
		}

		foreach ( array( 'true', '1', 'yes', 'on', 'maybe' ) as $on ) {
			$this->assertStringContainsString( 'Related documentation</h2>', do_shortcode( '[saai_product_docs product_id="' . $product . '" show_title="' . $on . '"]' ), $on );
		}
	}

	/**
	 * A negative or non-numeric `product_id` counts as no ID at all (the
	 * block then resolves its product from the context, as without the
	 * attribute) — never as a different product, which absint() would make
	 * of -N.
	 */
	public function test_shortcodes_treat_unusable_product_ids_as_absent() {
		( new Shortcodes() )->register_shortcodes();

		$product = $this->create_linked_product();
		$page    = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->go_to( get_permalink( $page ) );
		$this->assertSame( '', do_shortcode( '[saai_product_docs product_id="-' . $product . '"]' ) );

		$this->go_to( get_permalink( $product ) );
		$this->assertStringContainsString( '>Linked guide</a>', do_shortcode( '[saai_product_docs product_id="abc"]' ) );
	}

	/**
	 * Without the block registered (no JS build), a shortcode renders
	 * nothing instead of failing.
	 */
	public function test_shortcodes_render_nothing_without_their_block() {
		( new Shortcodes() )->register_shortcodes();

		$product = $this->create_linked_product();

		\WP_Block_Type_Registry::get_instance()->unregister( 'saai-knowledge/product-docs' );

		$this->assertSame( '', do_shortcode( '[saai_product_docs product_id="' . $product . '"]' ) );
	}
}
