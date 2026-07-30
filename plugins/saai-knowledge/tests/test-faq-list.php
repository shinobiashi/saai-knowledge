<?php
/**
 * Tests for the Faq_List service backing the faq-list block.
 *
 * @package SAAI\Knowledge
 */

use SAAI\Knowledge\Faq_List;

/**
 * Class Test_Faq_List.
 */
class Test_Faq_List extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Faq_List
	 */
	private $faq_list;

	/**
	 * Sets up the service and resets its request-scoped static state.
	 */
	public function set_up() {
		parent::set_up();

		$this->faq_list = new Faq_List();
		Faq_List::reset_state();
	}

	/**
	 * Creates a published FAQ entry.
	 *
	 * @param array<string, mixed> $args Overrides for the post factory.
	 * @return int Post ID.
	 */
	private function create_faq( array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => 'saai_faq',
					'post_status' => 'publish',
				),
				$args
			)
		);
	}

	/**
	 * Default attributes should query all published FAQs, newest first.
	 */
	public function test_query_args_defaults() {
		$args = $this->faq_list->query_args( array() );

		$this->assertSame( 'saai_faq', $args['post_type'] );
		$this->assertSame( 'publish', $args['post_status'] );
		$this->assertSame( -1, $args['posts_per_page'] );
		$this->assertSame( 'date', $args['orderby'] );
		$this->assertSame( 'DESC', $args['order'] );
		$this->assertArrayNotHasKey( 'tax_query', $args );
	}

	/**
	 * The category and count attributes should map to tax_query and posts_per_page.
	 */
	public function test_query_args_category_and_count() {
		$args = $this->faq_list->query_args(
			array(
				'category' => 'setup',
				'count'    => 5,
				'orderBy'  => 'title',
				'order'    => 'asc',
			)
		);

		$this->assertSame( 5, $args['posts_per_page'] );
		$this->assertSame( 'title', $args['orderby'] );
		$this->assertSame( 'ASC', $args['order'] );
		$this->assertSame( 'saai_category', $args['tax_query'][0]['taxonomy'] );
		$this->assertSame( 'slug', $args['tax_query'][0]['field'] );
		$this->assertSame( 'setup', $args['tax_query'][0]['terms'] );
	}

	/**
	 * Attribute values outside the block.json enums should fall back to defaults.
	 */
	public function test_query_args_rejects_invalid_enum_values() {
		$args = $this->faq_list->query_args(
			array(
				'orderBy' => 'rand',
				'order'   => 'sideways',
				'count'   => -3,
			)
		);

		$this->assertSame( 'date', $args['orderby'] );
		$this->assertSame( 'DESC', $args['order'] );
		$this->assertSame( -1, $args['posts_per_page'] );
	}

	/**
	 * The saai_faq_query_args filter should receive the args and normalized
	 * attributes, and its return value should be used.
	 */
	public function test_query_args_filter_is_applied() {
		$received = null;
		$filter   = function ( $args, $attrs ) use ( &$received ) {
			$received           = $attrs;
			$args['meta_query'] = array( array( 'key' => 'saai_linked_products' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- exercising the documented add-on injection point.

			return $args;
		};

		add_filter( 'saai_faq_query_args', $filter, 10, 2 );

		try {
			$args = $this->faq_list->query_args( array( 'category' => 'setup' ) );
		} finally {
			remove_filter( 'saai_faq_query_args', $filter, 10 );
		}

		$this->assertSame( 'saai_linked_products', $args['meta_query'][0]['key'] );
		$this->assertSame( 'setup', $received['category'] );
		$this->assertArrayHasKey( 'groupByCategory', $received );
	}

	/**
	 * A filter callback returning a non-array should be ignored in favor of
	 * the unfiltered args.
	 */
	public function test_query_args_filter_non_array_return_falls_back() {
		add_filter( 'saai_faq_query_args', '__return_false' );

		try {
			$args = $this->faq_list->query_args( array() );
		} finally {
			remove_filter( 'saai_faq_query_args', '__return_false' );
		}

		$this->assertSame( 'saai_faq', $args['post_type'] );
	}

	/**
	 * Block-based and classic answers should both render to HTML.
	 */
	public function test_items_renders_block_and_classic_answers() {
		$this->create_faq(
			array(
				'post_title'   => 'Block question',
				'post_content' => "<!-- wp:paragraph -->\n<p>Block answer</p>\n<!-- /wp:paragraph -->",
				'post_date'    => '2026-01-02 00:00:00',
			)
		);
		$this->create_faq(
			array(
				'post_title'   => 'Classic question',
				'post_content' => 'Classic answer',
				'post_date'    => '2026-01-01 00:00:00',
			)
		);

		$items = $this->faq_list->items( array() );

		$this->assertCount( 2, $items );
		$this->assertSame( 'Block question', $items[0]['question'] );
		// Not the full "<p>...": newer WP versions add a block class to the tag.
		$this->assertStringContainsString( '>Block answer</p>', $items[0]['answer'] );
		// Classic content goes through wpautop.
		$this->assertStringContainsString( '<p>Classic answer</p>', $items[1]['answer'] );
	}

	/**
	 * Shortcodes inside an answer should see the FAQ entry — not the page
	 * containing the faq-list block — as the current post, and the containing
	 * page's context should be restored after the render.
	 */
	public function test_items_renders_answers_in_the_faq_post_context() {
		add_shortcode(
			'saai_test_current_id',
			static function () {
				return (string) get_the_ID();
			}
		);

		$faq_id  = $this->create_faq( array( 'post_content' => 'Current ID: [saai_test_current_id]' ) );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->go_to( get_permalink( $page_id ) );

		try {
			$items = $this->faq_list->items( array() );
		} finally {
			remove_shortcode( 'saai_test_current_id' );
		}

		$this->assertStringContainsString( "Current ID: {$faq_id}", $items[0]['answer'] );
		$this->assertSame( $page_id, get_the_ID() );
	}

	/**
	 * The orderBy/order attributes should control the item order.
	 */
	public function test_items_ordering_by_title() {
		$this->create_faq( array( 'post_title' => 'Bravo' ) );
		$this->create_faq( array( 'post_title' => 'Alpha' ) );

		$items = $this->faq_list->items(
			array(
				'orderBy' => 'title',
				'order'   => 'asc',
			)
		);

		$this->assertSame( array( 'Alpha', 'Bravo' ), wp_list_pluck( $items, 'question' ) );
	}

	/**
	 * Draft and password-protected entries should never appear.
	 */
	public function test_items_excludes_non_public_entries() {
		$this->create_faq( array( 'post_title' => 'Public' ) );
		$this->create_faq(
			array(
				'post_title'  => 'Draft',
				'post_status' => 'draft',
			)
		);
		$this->create_faq(
			array(
				'post_title'    => 'Locked',
				'post_password' => 'secret',
			)
		);

		$items = $this->faq_list->items( array() );

		$this->assertSame( array( 'Public' ), wp_list_pluck( $items, 'question' ) );
	}

	/**
	 * Grouping should order groups by saai_order term meta and collect
	 * uncategorized entries in a final fallback group.
	 */
	public function test_grouped_items_orders_groups_and_collects_uncategorized() {
		$second = self::factory()->term->create(
			array(
				'taxonomy' => 'saai_category',
				'name'     => 'Second',
			)
		);
		$first  = self::factory()->term->create(
			array(
				'taxonomy' => 'saai_category',
				'name'     => 'First',
			)
		);

		update_term_meta( $second, 'saai_order', 2 );
		update_term_meta( $first, 'saai_order', 1 );

		$in_second = $this->create_faq( array( 'post_title' => 'In second' ) );
		$in_first  = $this->create_faq( array( 'post_title' => 'In first' ) );
		$this->create_faq( array( 'post_title' => 'Uncategorized' ) );

		wp_set_object_terms( $in_second, array( $second ), 'saai_category' );
		wp_set_object_terms( $in_first, array( $first ), 'saai_category' );

		$groups = $this->faq_list->grouped_items( array() );

		$this->assertCount( 3, $groups );
		$this->assertSame( 'First', $groups[0]['title'] );
		$this->assertSame( 'Second', $groups[1]['title'] );
		$this->assertNull( $groups[2]['term'] );
		$this->assertSame( 'Other', $groups[2]['title'] );
		$this->assertSame( array( 'Uncategorized' ), wp_list_pluck( $groups[2]['items'], 'question' ) );
	}

	/**
	 * When every entry is uncategorized, the lone fallback group should carry
	 * no heading.
	 */
	public function test_grouped_items_lone_fallback_group_has_no_title() {
		$this->create_faq( array( 'post_title' => 'Uncategorized' ) );

		$groups = $this->faq_list->grouped_items( array() );

		$this->assertCount( 1, $groups );
		$this->assertSame( '', $groups[0]['title'] );
	}

	/**
	 * The accordion should render the core Accordion block's markup with the
	 * question escaped and the pre-rendered answer intact.
	 */
	public function test_accordion_renders_core_accordion_markup() {
		$html = $this->faq_list->accordion(
			array(
				array(
					'id'       => 1,
					'question' => 'Question <em>one</em>?',
					'answer'   => '<p>Answer one</p>',
				),
				array(
					'id'       => 2,
					'question' => 'Question two?',
					'answer'   => '<p>Answer two</p>',
				),
			)
		);

		$this->assertStringContainsString( 'wp-block-accordion', $html );
		// Counted via the heading's title span: the item wrapper's class name
		// also appears inside layout-support classes (wp-block-accordion-item-is-layout-flow),
		// so counting the bare class name would double-count.
		$this->assertSame( 2, substr_count( $html, 'wp-block-accordion-heading__toggle-title' ) );
		$this->assertStringContainsString( 'Question &lt;em&gt;one&lt;/em&gt;?', $html );
		$this->assertStringContainsString( '<p>Answer one</p>', $html );
		$this->assertStringContainsString( '<p>Answer two</p>', $html );
		// Core's render callback wires up the Interactivity API.
		$this->assertStringContainsString( 'data-wp-interactive="core/accordion"', $html );
	}

	/**
	 * Malformed items should be skipped rather than rendering broken markup.
	 */
	public function test_accordion_skips_malformed_items() {
		$html = $this->faq_list->accordion(
			array(
				'not-an-array',
				array(
					'id'       => 1,
					'question' => '',
					'answer'   => '<p>No question</p>',
				),
				array(
					'id'       => 2,
					'question' => 'Valid?',
					'answer'   => array( 'not-a-string' ),
				),
			)
		);

		$this->assertSame( 1, substr_count( $html, 'wp-block-accordion-heading__toggle-title' ) );
		$this->assertStringContainsString( 'Valid?', $html );
		$this->assertStringNotContainsString( 'No question', $html );
	}

	/**
	 * An empty item list should render nothing.
	 */
	public function test_accordion_returns_empty_string_for_no_items() {
		$this->assertSame( '', $this->faq_list->accordion( array() ) );
	}

	/**
	 * The FAQPage schema should carry each Q&A pair, with tags stripped from
	 * the question name.
	 */
	public function test_json_ld_builds_faq_page_schema() {
		$schema = $this->faq_list->json_ld(
			array(
				array(
					'id'       => 1,
					'question' => 'Question <em>one</em>?',
					'answer'   => '<p>Answer one</p>',
				),
			)
		);

		$this->assertSame( 'https://schema.org', $schema['@context'] );
		$this->assertSame( 'FAQPage', $schema['@type'] );
		$this->assertCount( 1, $schema['mainEntity'] );
		$this->assertSame( 'Question', $schema['mainEntity'][0]['@type'] );
		$this->assertSame( 'Question one?', $schema['mainEntity'][0]['name'] );
		$this->assertSame( '<p>Answer one</p>', $schema['mainEntity'][0]['acceptedAnswer']['text'] );
	}

	/**
	 * HTML character references produced by the_title filters (& → &#038;,
	 * ' → &#8217;) should be decoded to plain text in the question name —
	 * JSON-LD contents are never HTML-entity-decoded by consumers.
	 */
	public function test_json_ld_decodes_html_entities_in_question_names() {
		$schema = $this->faq_list->json_ld(
			array(
				array(
					'id'       => 1,
					'question' => 'A &#038; B&#8217;s guide?',
					'answer'   => '<p>Answer</p>',
				),
			)
		);

		$this->assertSame( 'A & B’s guide?', $schema['mainEntity'][0]['name'] );
	}

	/**
	 * The saai_structured_data filter should receive the faq-page type and be
	 * able to replace the schema; a non-array return should be ignored.
	 */
	public function test_json_ld_applies_structured_data_filter() {
		$received_type = null;
		$filter        = function ( $schema, $schema_type ) use ( &$received_type ) {
			$received_type     = $schema_type;
			$schema['@custom'] = true;

			return $schema;
		};

		add_filter( 'saai_structured_data', $filter, 10, 2 );

		try {
			$schema = $this->faq_list->json_ld( array() );
		} finally {
			remove_filter( 'saai_structured_data', $filter, 10 );
		}

		$this->assertSame( 'faq-page', $received_type );
		$this->assertTrue( $schema['@custom'] );

		add_filter( 'saai_structured_data', '__return_false' );

		try {
			$schema = $this->faq_list->json_ld( array() );
		} finally {
			remove_filter( 'saai_structured_data', '__return_false' );
		}

		$this->assertSame( 'FAQPage', $schema['@type'] );
	}

	/**
	 * Structured data output should default to enabled and honor the
	 * settings option.
	 */
	public function test_structured_data_enabled_reads_settings() {
		$this->assertTrue( $this->faq_list->structured_data_enabled() );

		update_option( 'saai_knowledge_settings', array( 'structured_data' => false ) );
		$this->assertFalse( $this->faq_list->structured_data_enabled() );

		update_option( 'saai_knowledge_settings', array( 'structured_data' => true ) );
		$this->assertTrue( $this->faq_list->structured_data_enabled() );
	}

	/**
	 * The claim signature should be deterministic for equal attributes,
	 * distinct for different ones, and never empty — including for values
	 * wp_json_encode() cannot represent (invalid UTF-8), where the serialize()
	 * fallback must still keep unrelated blocks apart.
	 */
	public function test_structured_data_signature_is_deterministic_distinct_and_never_empty() {
		$a1 = $this->faq_list->structured_data_signature( array( 'category' => 'setup' ) );
		$a2 = $this->faq_list->structured_data_signature( array( 'category' => 'setup' ) );
		$b  = $this->faq_list->structured_data_signature( array( 'category' => 'other' ) );

		$this->assertNotSame( '', $a1 );
		$this->assertSame( $a1, $a2 );
		$this->assertNotSame( $a1, $b );

		$bad_1 = $this->faq_list->structured_data_signature( array( 'category' => "bad-\xB1\x31" ) );
		$bad_2 = $this->faq_list->structured_data_signature( array( 'category' => "bad-\xB1\x32" ) );

		$this->assertNotSame( '', $bad_1 );
		$this->assertNotSame( $bad_1, $bad_2 );
	}

	/**
	 * The JSON-LD slot should be claimed per attribute signature: the same
	 * block may emit again (a visible render after a speculative one whose
	 * output was discarded), a different block may not, and a new main query
	 * should release the slot entirely.
	 */
	public function test_structured_data_slot_is_claimed_once_per_request() {
		$this->assertTrue( Faq_List::claim_structured_data_slot( 'sig-a' ) );
		$this->assertTrue( Faq_List::claim_structured_data_slot( 'sig-a' ) );
		$this->assertFalse( Faq_List::claim_structured_data_slot( 'sig-b' ) );

		// A new main query resets the slot via the registered pre_get_posts hook.
		$this->go_to( home_url( '/' ) );
		$this->assertTrue( Faq_List::claim_structured_data_slot( 'sig-b' ) );
	}

	/**
	 * The render guard should report and clear the in-progress flag.
	 */
	public function test_render_guard_flags() {
		$this->assertFalse( Faq_List::is_rendering() );

		Faq_List::begin_render();
		$this->assertTrue( Faq_List::is_rendering() );

		Faq_List::finish_render();
		$this->assertFalse( Faq_List::is_rendering() );
	}

	/**
	 * Swaps in a faq-list block registration backed by the same render.php
	 * straight from src/.
	 *
	 * The real block type is only registered when build/ exists (CI runs
	 * PHPUnit without a JS build — see test-shortcodes.php for the same
	 * situation). Callers must pair this with restore_faq_list_block() in a
	 * finally block (the block registry is not reset between tests).
	 *
	 * @return \WP_Block_Type|null The previously registered block type, if any.
	 */
	private function register_src_faq_list_block(): ?\WP_Block_Type {
		$registry = \WP_Block_Type_Registry::get_instance();
		$original = $registry->get_registered( 'saai-knowledge/faq-list' );

		if ( $original ) {
			$registry->unregister( 'saai-knowledge/faq-list' );
		}

		register_block_type(
			'saai-knowledge/faq-list',
			array(
				'uses_context'    => array( 'postId' ),
				'render_callback' => static function ( $attributes, $content, $block ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- all three are consumed by the required render.php via the closure scope, matching the register_block_type_from_metadata() contract.
					ob_start();
					require dirname( __DIR__ ) . '/src/faq-list/render.php';

					return ob_get_clean();
				},
			)
		);

		return $original;
	}

	/**
	 * Restores the block registry after register_src_faq_list_block().
	 *
	 * @param \WP_Block_Type|null $original The previously registered block type, if any.
	 */
	private function restore_faq_list_block( ?\WP_Block_Type $original ): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		$registry->unregister( 'saai-knowledge/faq-list' );

		if ( $original ) {
			$registry->register( $original );
		}
	}

	/**
	 * Renders the faq-list block with a saai_structured_data callback
	 * capturing the post argument the block passes to the filter.
	 *
	 * @param callable|null $render Custom render routine; defaults to a plain
	 *                              do_blocks() of the block comment.
	 * @return \WP_Post|null|string The captured argument, or 'unset' if the
	 *                              filter never ran.
	 */
	private function render_block_capturing_structured_data_post( ?callable $render = null ) {
		$original = $this->register_src_faq_list_block();

		$received = 'unset';
		$filter   = function ( $schema, $schema_type, $post ) use ( &$received ) {
			$received = $post;

			return $schema;
		};

		add_filter( 'saai_structured_data', $filter, 10, 3 );

		try {
			if ( $render ) {
				$render();
			} else {
				do_blocks( '<!-- wp:saai-knowledge/faq-list /-->' );
			}
		} finally {
			remove_filter( 'saai_structured_data', $filter, 10 );
			$this->restore_faq_list_block( $original );
		}

		return $received;
	}

	/**
	 * On the FAQ archive, WordPress primes the global $post with the first
	 * main-query result before any block renders — that arbitrary FAQ must
	 * not reach the saai_structured_data filter as "the current post".
	 */
	public function test_faq_archive_json_ld_passes_no_post_to_the_structured_data_filter() {
		$this->create_faq( array( 'post_title' => 'Archived question' ) );

		$this->go_to( get_post_type_archive_link( 'saai_faq' ) );

		$this->assertNull( $this->render_block_capturing_structured_data_post() );
	}

	/**
	 * On a singular view, the post containing the block is the genuine
	 * current post and should reach the saai_structured_data filter.
	 */
	public function test_singular_json_ld_passes_the_containing_post_to_the_structured_data_filter() {
		$this->create_faq( array( 'post_title' => 'Embedded question' ) );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->go_to( get_permalink( $page_id ) );

		$received = $this->render_block_capturing_structured_data_post();

		$this->assertInstanceOf( \WP_Post::class, $received );
		$this->assertSame( $page_id, $received->ID );
	}

	/**
	 * The queryId context only proves "some descendant of core/query" — a block
	 * placed beside the Post Template inherits it while postId is still the
	 * archive's seeded first result. On an archive, supplied Query Loop
	 * context must not smuggle that arbitrary post into the filter.
	 */
	public function test_faq_archive_json_ld_ignores_inherited_query_loop_context() {
		$faq_id = $this->create_faq( array( 'post_title' => 'Archived question' ) );

		$this->go_to( get_post_type_archive_link( 'saai_faq' ) );

		$received = $this->render_block_capturing_structured_data_post(
			static function () use ( $faq_id ) {
				$parsed = parse_blocks( '<!-- wp:saai-knowledge/faq-list /-->' );

				( new \WP_Block(
					$parsed[0],
					array(
						'postId'  => $faq_id,
						'queryId' => 0,
					)
				) )->render();
			}
		);

		$this->assertNull( $received );
	}

	/**
	 * A saai_faq_before_list / saai_faq_after_list callback rendering another
	 * FAQ list must be rejected by the render guard rather than recursing
	 * forever through the same insertion hook.
	 */
	public function test_insertion_hooks_cannot_recurse_into_a_nested_faq_list() {
		$this->create_faq( array( 'post_title' => 'Recursion question' ) );

		$original = $this->register_src_faq_list_block();

		$nested   = null;
		$callback = static function () use ( &$nested ) {
			$nested = do_blocks( '<!-- wp:saai-knowledge/faq-list /-->' );
		};
		add_action( 'saai_faq_before_list', $callback );

		try {
			$output = do_blocks( '<!-- wp:saai-knowledge/faq-list /-->' );
		} finally {
			remove_action( 'saai_faq_before_list', $callback );
			$this->restore_faq_list_block( $original );
		}

		$this->assertSame( '', $nested );
		$this->assertSame( 1, substr_count( $output, 'wp-block-accordion-heading__toggle-title' ) );
	}

	/**
	 * A bare oEmbed URL in a classic FAQ answer should become an embed, as it
	 * would in normal post content.
	 */
	public function test_items_embeds_bare_oembed_urls_in_classic_answers() {
		$filter = static function () {
			return '<iframe src="https://videos.example.com/embed/123"></iframe>';
		};
		add_filter( 'pre_oembed_result', $filter );

		$this->create_faq(
			array(
				'post_title'   => 'Embed question',
				'post_content' => "Watch this:\n\nhttps://videos.example.com/watch?v=123",
			)
		);

		try {
			$items = $this->faq_list->items( array() );
		} finally {
			remove_filter( 'pre_oembed_result', $filter );
		}

		$this->assertStringContainsString( '<iframe src="https://videos.example.com/embed/123">', $items[0]['answer'] );
	}

	/**
	 * Images in answers should get the_content's own tag optimizations
	 * (responsive/loading/decoding attributes via wp_filter_content_tags()).
	 */
	public function test_items_applies_content_image_optimizations_to_answers() {
		$this->create_faq(
			array(
				'post_title'   => 'Image question',
				'post_content' => '<img src="https://example.com/a.png" width="100" height="100" alt="">',
			)
		);

		$items = $this->faq_list->items( array() );

		$this->assertStringContainsString( 'decoding="async"', $items[0]['answer'] );
	}

	/**
	 * When no global post exists before the render, the complete postdata
	 * state — not just $GLOBALS['post'] — must be back to its prior values
	 * afterward, not left describing the last FAQ.
	 */
	public function test_items_restores_prior_state_when_no_previous_post_exists() {
		$author_id = self::factory()->user->create();
		$this->create_faq(
			array(
				'post_title'  => 'Postless question',
				'post_author' => $author_id,
			)
		);

		$GLOBALS['post']       = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- arranging the postless pre-render state under test.
		$GLOBALS['id']         = 0; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- arranging the postless pre-render state under test.
		$GLOBALS['authordata'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- arranging the postless pre-render state under test.

		$this->faq_list->items( array() );

		$this->assertNull( $GLOBALS['post'] );
		$this->assertSame( 0, $GLOBALS['id'] );
		$this->assertNull( $GLOBALS['authordata'] );
	}
}
