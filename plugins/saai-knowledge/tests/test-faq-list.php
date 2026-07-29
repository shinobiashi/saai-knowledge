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
	 * Only the first claim per request should win the JSON-LD slot, and a new
	 * main query should release it.
	 */
	public function test_structured_data_slot_is_claimed_once_per_request() {
		$this->assertTrue( Faq_List::claim_structured_data_slot() );
		$this->assertFalse( Faq_List::claim_structured_data_slot() );

		// A new main query resets the slot via the registered pre_get_posts hook.
		$this->go_to( home_url( '/' ) );
		$this->assertTrue( Faq_List::claim_structured_data_slot() );
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
}
