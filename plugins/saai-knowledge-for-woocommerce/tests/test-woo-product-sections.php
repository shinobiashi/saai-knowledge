<?php
/**
 * Tests for the display-ready content linked to a product.
 *
 * @package SAAI\KnowledgeWoo
 */

use SAAI\KnowledgeWoo\Link_Resolver;
use SAAI\KnowledgeWoo\Post_Meta;
use SAAI\KnowledgeWoo\Product_Sections;

/**
 * Class Test_Woo_Product_Sections.
 */
class Test_Woo_Product_Sections extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Product_Sections
	 */
	private $sections;

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

		$this->sections = new Product_Sections( new Link_Resolver() );
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
					// The factory would otherwise generate one, and a manual
					// excerpt is what a glossary definition prefers.
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
	 * Swaps in a faq-list block registration backed by the free plugin's
	 * render.php straight from its src/ (CI's PHPUnit job has no JS build).
	 */
	private function register_src_faq_list_block(): void {
		$this->unregister_faq_list_block();

		register_block_type(
			Product_Sections::FAQ_BLOCK,
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
			$this->original_faq_block = $registry->get_registered( Product_Sections::FAQ_BLOCK );
			$this->swapped_faq_block  = true;
		}

		if ( $registry->is_registered( Product_Sections::FAQ_BLOCK ) ) {
			$registry->unregister( Product_Sections::FAQ_BLOCK );
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

		if ( $registry->is_registered( Product_Sections::FAQ_BLOCK ) ) {
			$registry->unregister( Product_Sections::FAQ_BLOCK );
		}

		if ( $this->original_faq_block ) {
			$registry->register( $this->original_faq_block );
		}

		$this->original_faq_block = null;
		$this->swapped_faq_block  = false;
	}

	/**
	 * The product-faq marker is per product.
	 */
	public function test_faq_block_marker_is_per_product() {
		$this->assertSame( 'saai-woo-product-faq-12', Product_Sections::faq_block_marker( 12 ) );
		$this->assertNotSame( Product_Sections::faq_block_marker( 12 ), Product_Sections::faq_block_marker( 13 ) );
	}

	/**
	 * The FAQ list holds exactly the linked FAQs, in the resolver's order,
	 * and nothing is rendered for a product without links, for an unusable
	 * product ID, or while the free plugin's block isn't registered.
	 */
	public function test_faq_html_lists_only_the_linked_faqs() {
		$this->register_src_faq_list_block();

		$product  = $this->create_product();
		$unlinked = $this->create_product( 'Unlinked' );
		$second   = $this->create_content( 'saai_faq', 'Second question', array( 'menu_order' => 2 ) );
		$first    = $this->create_content( 'saai_faq', 'First question', array( 'menu_order' => 1 ) );

		$this->create_content( 'saai_faq', 'Unrelated question' );
		$this->link( $second, $product );
		$this->link( $first, $product );

		$html = $this->sections->faq_html( $product, Product_Sections::faq_block_marker( $product ) );

		$this->assertStringContainsString( 'saai-faq-list', $html );
		$this->assertStringNotContainsString( 'Unrelated question', $html );
		$this->assertLessThan( strpos( $html, 'Second question' ), strpos( $html, 'First question' ), 'The resolver order (menu_order) must carry through.' );

		$this->assertSame( '', $this->sections->faq_html( $unlinked, Product_Sections::faq_block_marker( $unlinked ) ) );
		$this->assertSame( '', $this->sections->faq_html( 0, Product_Sections::faq_block_marker( 0 ) ) );

		$this->unregister_faq_list_block();
		$this->assertFalse( $this->sections->faq_block_available() );
		$this->assertSame( '', $this->sections->faq_html( $product, Product_Sections::faq_block_marker( $product ) ) );
	}

	/**
	 * Two products' FAQ lists on one page print one FAQPage between them:
	 * the per-product markers give them different signatures, so only the
	 * first claims the free plugin's single JSON-LD slot.
	 */
	public function test_faq_html_for_two_products_yields_one_faq_page() {
		$this->register_src_faq_list_block();

		$a = $this->create_product( 'A' );
		$b = $this->create_product( 'B' );

		$this->link( $this->create_content( 'saai_faq', 'Question for A' ), $a );
		$this->link( $this->create_content( 'saai_faq', 'Question for B' ), $b );

		// A fresh request (go_to() runs a new main query, which resets the
		// free plugin's slot): a comparison page listing both products.
		$this->go_to( get_permalink( self::factory()->post->create( array( 'post_type' => 'page' ) ) ) );

		$html_a = $this->sections->faq_html( $a, Product_Sections::faq_block_marker( $a ) );
		$html_b = $this->sections->faq_html( $b, Product_Sections::faq_block_marker( $b ) );

		$this->assertStringContainsString( 'Question for A', $html_a );
		$this->assertStringContainsString( 'Question for B', $html_b );
		$this->assertStringContainsString( 'application/ld+json', $html_a );
		$this->assertStringNotContainsString( 'application/ld+json', $html_b );

		// The same product again is the same signature, so a speculative
		// pre-render followed by the real one still prints.
		$this->assertStringContainsString( 'application/ld+json', $this->sections->faq_html( $a, Product_Sections::faq_block_marker( $a ) ) );
	}

	/**
	 * Rendering an FAQ answer fires `the_post` for the FAQ, which
	 * WooCommerce uses to drop its product global; the render puts it back.
	 */
	public function test_faq_html_restores_the_woocommerce_product_global() {
		$this->register_src_faq_list_block();

		$product = $this->create_product();
		$this->link( $this->create_content( 'saai_faq', 'A question' ), $product );

		$unset_on_the_post = static function ( $post ) {
			if ( $post instanceof WP_Post && 'saai_faq' === $post->post_type ) {
				unset( $GLOBALS['product'] );
			}
		};
		add_action( 'the_post', $unset_on_the_post );

		$sentinel           = new stdClass();
		$GLOBALS['product'] = $sentinel; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- simulating WooCommerce's own global.

		try {
			$this->sections->faq_html( $product, Product_Sections::faq_block_marker( $product ) );
		} finally {
			remove_action( 'the_post', $unset_on_the_post );
		}

		$this->assertSame( $sentinel, $GLOBALS['product'] ?? null );
	}

	/**
	 * KB links come back in the resolver's order with their permalinks and
	 * titles, skipping unusable URLs, with a placeholder for an empty title.
	 */
	public function test_kb_links_are_ordered_and_skip_unusable_permalinks() {
		$product  = $this->create_product();
		$second   = $this->create_content( 'saai_kb', 'Second guide', array( 'menu_order' => 2 ) );
		$first    = $this->create_content( 'saai_kb', 'First guide', array( 'menu_order' => 1 ) );
		$untitled = $this->create_content(
			'saai_kb',
			'',
			array(
				'menu_order' => 3,
				'post_name'  => 'untitled-guide',
			)
		);
		$bad      = $this->create_content( 'saai_kb', 'Bad guide', array( 'menu_order' => 4 ) );
		$draft    = $this->create_content( 'saai_kb', 'Draft guide', array( 'post_status' => 'draft' ) );

		foreach ( array( $second, $first, $untitled, $bad, $draft ) as $id ) {
			$this->link( $id, $product );
		}

		$poison = static function ( $url, $post ) use ( $bad ) {
			return $post instanceof WP_Post && $post->ID === $bad ? 'javascript:alert(1)' : $url;
		};
		add_filter( 'post_type_link', $poison, 10, 2 );

		try {
			$links = $this->sections->kb_links( $product );
		} finally {
			remove_filter( 'post_type_link', $poison, 10 );
		}

		$this->assertSame(
			array(
				array(
					'url'   => get_permalink( $first ),
					'title' => 'First guide',
				),
				array(
					'url'   => get_permalink( $second ),
					'title' => 'Second guide',
				),
				array(
					'url'   => get_permalink( $untitled ),
					'title' => '(no title)',
				),
			),
			$links
		);

		$this->assertSame( array(), $this->sections->kb_links( $this->create_product( 'Unlinked' ) ) );
		$this->assertSame( array(), $this->sections->kb_links( 0 ) );
	}

	/**
	 * A glossary entry's definition is the manual excerpt, or else the
	 * opening words of the body — plain text either way, shortcodes and
	 * tags removed, entities decoded.
	 */
	public function test_glossary_entries_carry_a_plain_text_definition() {
		$product  = $this->create_product();
		$excerpt  = $this->create_content(
			'saai_glossary',
			'SSL',
			array(
				'menu_order'   => 1,
				'post_excerpt' => 'Encrypts <strong>traffic</strong> &amp; more.',
			)
		);
		$body     = $this->create_content(
			'saai_glossary',
			'CDN',
			array(
				'menu_order'   => 2,
				'post_content' => '<!-- wp:paragraph --><p>Serves [gallery ids="1"]files from <em>edge</em> servers.</p><!-- /wp:paragraph -->',
			)
		);
		$long     = $this->create_content(
			'saai_glossary',
			'Long',
			array(
				'menu_order'   => 3,
				'post_content' => '<p>' . implode( ' ', array_fill( 0, Product_Sections::DEFINITION_WORDS + 10, 'word' ) ) . '</p>',
			)
		);
		$excluded = $this->create_content( 'saai_glossary', 'Protected', array( 'post_password' => 'secret' ) );

		foreach ( array( $excerpt, $body, $long, $excluded ) as $id ) {
			$this->link( $id, $product );
		}

		$entries = $this->sections->glossary_entries( $product );

		$this->assertSame( array( 'SSL', 'CDN', 'Long' ), wp_list_pluck( $entries, 'title' ), 'Published, unprotected terms only, in the resolver order.' );
		$this->assertSame( get_permalink( $excerpt ), $entries[0]['url'] );
		$this->assertSame( 'Encrypts traffic & more.', $entries[0]['definition'] );
		$this->assertSame( 'Serves files from edge servers.', $entries[1]['definition'] );
		$this->assertSame( Product_Sections::DEFINITION_WORDS, count( explode( ' ', rtrim( $entries[2]['definition'], '…' ) ) ) );

		$this->assertSame( array(), $this->sections->glossary_entries( $this->create_product( 'Unlinked' ) ) );
	}

	/**
	 * The linked posts are primed in one go: listing three costs no more
	 * queries than listing one.
	 */
	public function test_linked_posts_are_primed_in_one_go() {
		$one   = $this->create_product( 'One' );
		$three = $this->create_product( 'Three' );
		$ids   = array();

		foreach ( array( 'A', 'B', 'C', 'D' ) as $letter ) {
			$ids[] = $this->create_content( 'saai_glossary', 'Term ' . $letter );
		}

		$this->link( $ids[0], $one );

		foreach ( array_slice( $ids, 1 ) as $id ) {
			$this->link( $id, $three );
		}

		$this->assertCount( 3, $this->sections->glossary_entries( $three ), 'Precondition: all three terms resolve.' );

		$queries_for = function ( int $product_id ) use ( $ids ): int {
			foreach ( $ids as $id ) {
				clean_post_cache( $id );
			}

			$before = get_num_queries();
			$this->sections->glossary_entries( $product_id );

			return get_num_queries() - $before;
		};

		$delta_one   = $queries_for( $one );
		$delta_three = $queries_for( $three );

		$this->assertLessThanOrEqual( $delta_one, $delta_three, 'Listing three linked terms must not cost more queries than listing one.' );
	}
}
