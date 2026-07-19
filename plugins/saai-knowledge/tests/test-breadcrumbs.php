<?php
/**
 * Tests for the breadcrumbs block's trail-building and JSON-LD logic.
 *
 * @package SAAI\Knowledge
 */

/**
 * Class Test_Breadcrumbs.
 */
class Test_Breadcrumbs extends WP_UnitTestCase {

	/**
	 * With no post or term, the trail is just the hub, marked current.
	 */
	public function test_build_with_no_context_returns_hub_only() {
		$trail = ( new \SAAI\Knowledge\Breadcrumbs() )->build();

		$this->assertCount( 1, $trail );
		$this->assertTrue( $trail[0]['current'] );
		$this->assertSame( get_post_type_archive_link( 'saai_kb' ), $trail[0]['url'] );
	}

	/**
	 * A term archive trail walks from the hub down through the term's
	 * ancestors (root first) to the term itself, marked current.
	 */
	public function test_build_for_term_includes_ancestor_chain() {
		$parent_term = self::factory()->term->create_and_get( array( 'taxonomy' => 'saai_category' ) );
		$child_term  = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'saai_category',
				'parent'   => $parent_term->term_id,
			)
		);

		$trail = ( new \SAAI\Knowledge\Breadcrumbs() )->build( null, $child_term );

		$this->assertCount( 3, $trail );
		$this->assertFalse( $trail[0]['current'] );
		$this->assertSame( $parent_term->name, $trail[1]['label'] );
		$this->assertFalse( $trail[1]['current'] );
		$this->assertSame( $child_term->name, $trail[2]['label'] );
		$this->assertTrue( $trail[2]['current'] );
	}

	/**
	 * A single-article trail resolves the article's saai_category term (and
	 * its ancestors), appending the article itself as the current crumb.
	 */
	public function test_build_for_post_includes_term_and_article() {
		$parent_term = self::factory()->term->create_and_get( array( 'taxonomy' => 'saai_category' ) );
		$child_term  = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'saai_category',
				'parent'   => $parent_term->term_id,
			)
		);

		$post_id = self::factory()->post->create(
			array(
				'post_type'  => 'saai_kb',
				'post_title' => 'My Article',
			)
		);
		wp_set_object_terms( $post_id, array( $child_term->term_id ), 'saai_category' );

		$trail = ( new \SAAI\Knowledge\Breadcrumbs() )->build( get_post( $post_id ) );

		$this->assertCount( 4, $trail );
		$this->assertSame( $parent_term->name, $trail[1]['label'] );
		$this->assertSame( $child_term->name, $trail[2]['label'] );
		$this->assertSame( 'My Article', $trail[3]['label'] );
		$this->assertTrue( $trail[3]['current'] );
		$this->assertNotSame( '', $trail[2]['url'] );
	}

	/**
	 * An article with no saai_category term still produces a trail: hub + article.
	 */
	public function test_build_for_post_with_no_term_skips_category_crumbs() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'  => 'saai_kb',
				'post_title' => 'Orphan Article',
			)
		);

		$trail = ( new \SAAI\Knowledge\Breadcrumbs() )->build( get_post( $post_id ) );

		$this->assertCount( 2, $trail );
		$this->assertSame( 'Orphan Article', $trail[1]['label'] );
	}

	/**
	 * An article assigned to more than one saai_category term uses the
	 * lowest term_id for a deterministic single-path trail.
	 */
	public function test_build_for_post_with_multiple_terms_picks_lowest_term_id() {
		$term_a = self::factory()->term->create_and_get( array( 'taxonomy' => 'saai_category' ) );
		$term_b = self::factory()->term->create_and_get( array( 'taxonomy' => 'saai_category' ) );

		$ordered_terms = array( $term_a, $term_b );
		usort(
			$ordered_terms,
			static function ( $a, $b ) {
				return $a->term_id <=> $b->term_id;
			}
		);
		$expected_term = $ordered_terms[0];

		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		wp_set_object_terms( $post_id, array( $term_a->term_id, $term_b->term_id ), 'saai_category' );

		$trail = ( new \SAAI\Knowledge\Breadcrumbs() )->build( get_post( $post_id ) );

		$this->assertSame( $expected_term->name, $trail[1]['label'] );
	}

	/**
	 * The saai_breadcrumbs_items filter should receive and be able to replace the trail.
	 */
	public function test_saai_breadcrumbs_items_filter_can_replace_trail() {
		$captured_context = null;

		$filter = static function ( $trail, $context ) use ( &$captured_context ) {
			$captured_context = $context;

			return array(
				array(
					'label'   => 'Injected',
					'url'     => '#',
					'current' => true,
				),
			);
		};

		add_filter( 'saai_breadcrumbs_items', $filter, 10, 2 );

		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		$trail   = ( new \SAAI\Knowledge\Breadcrumbs() )->build( get_post( $post_id ) );

		remove_filter( 'saai_breadcrumbs_items', $filter, 10 );

		$this->assertSame( 'Injected', $trail[0]['label'] );
		$this->assertSame( $post_id, $captured_context['post_id'] );
		$this->assertSame( 'saai_category', $captured_context['taxonomy'] );
	}

	/**
	 * A misbehaving saai_breadcrumbs_items callback returning a non-array must not
	 * fatal on the build(): array return type; the unfiltered trail should be used instead.
	 */
	public function test_saai_breadcrumbs_items_filter_falls_back_when_not_an_array() {
		$filter = static function () {
			return 'not an array';
		};

		add_filter( 'saai_breadcrumbs_items', $filter );

		$trail = ( new \SAAI\Knowledge\Breadcrumbs() )->build();

		remove_filter( 'saai_breadcrumbs_items', $filter );

		$this->assertCount( 1, $trail );
	}

	/**
	 * The JSON-LD schema has one ListItem per crumb with a sequential
	 * position, omitting the "item" key for crumbs with no URL.
	 */
	public function test_json_ld_builds_breadcrumb_list_schema() {
		$trail = array(
			array(
				'label'   => 'Knowledge Base',
				'url'     => 'https://example.com/kb/',
				'current' => false,
			),
			array(
				'label'   => 'Current Article',
				'url'     => '',
				'current' => true,
			),
		);

		$schema = ( new \SAAI\Knowledge\Breadcrumbs() )->json_ld( $trail );

		$this->assertSame( 'https://schema.org', $schema['@context'] );
		$this->assertSame( 'BreadcrumbList', $schema['@type'] );
		$this->assertCount( 2, $schema['itemListElement'] );
		$this->assertSame( 1, $schema['itemListElement'][0]['position'] );
		$this->assertSame( 'https://example.com/kb/', $schema['itemListElement'][0]['item'] );
		$this->assertSame( 2, $schema['itemListElement'][1]['position'] );
		$this->assertArrayNotHasKey( 'item', $schema['itemListElement'][1] );
	}

	/**
	 * The saai_structured_data filter should receive the schema-type identifier and post.
	 */
	public function test_saai_structured_data_filter_receives_schema_type_and_post() {
		$captured_type = null;
		$captured_post = null;

		$filter = static function ( $schema, $schema_type, $post ) use ( &$captured_type, &$captured_post ) {
			$captured_type = $schema_type;
			$captured_post = $post;

			return $schema;
		};

		add_filter( 'saai_structured_data', $filter, 10, 3 );

		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		( new \SAAI\Knowledge\Breadcrumbs() )->json_ld( array(), get_post( $post_id ) );

		remove_filter( 'saai_structured_data', $filter, 10 );

		$this->assertSame( 'breadcrumbs', $captured_type );
		$this->assertSame( $post_id, $captured_post->ID );
	}

	/**
	 * Structured data defaults to enabled when no settings option exists yet
	 * (the M4 settings screen hasn't been built).
	 */
	public function test_structured_data_enabled_defaults_true_without_settings_option() {
		delete_option( 'saai_knowledge_settings' );

		$this->assertTrue( ( new \SAAI\Knowledge\Breadcrumbs() )->structured_data_enabled() );
	}

	/**
	 * Structured data honors an explicit false in the settings option.
	 */
	public function test_structured_data_enabled_honors_settings_option() {
		update_option( 'saai_knowledge_settings', array( 'structured_data' => false ) );

		$this->assertFalse( ( new \SAAI\Knowledge\Breadcrumbs() )->structured_data_enabled() );

		delete_option( 'saai_knowledge_settings' );
	}
}
