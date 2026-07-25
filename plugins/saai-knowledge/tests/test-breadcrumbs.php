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
	 * An article assigned to more than one saai_category term uses the term
	 * that sorts first by saai_order term meta, matching the sidebar's
	 * display order regardless of creation order or name.
	 */
	public function test_build_for_post_with_multiple_terms_uses_saai_order() {
		$term_a = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'saai_category',
				'name'     => 'Alpha',
			)
		);
		$term_b = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'saai_category',
				'name'     => 'Beta',
			)
		);

		// Beta sorts last by name and term_id, so only its lower saai_order can win.
		update_term_meta( $term_a->term_id, 'saai_order', 2 );
		update_term_meta( $term_b->term_id, 'saai_order', 1 );

		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		wp_set_object_terms( $post_id, array( $term_a->term_id, $term_b->term_id ), 'saai_category' );

		$trail = ( new \SAAI\Knowledge\Breadcrumbs() )->build( get_post( $post_id ) );

		$this->assertSame( 'Beta', $trail[1]['label'] );
	}

	/**
	 * With equal saai_order, terms fall back to name order, matching
	 * Sidebar_Tree::sort_terms().
	 */
	public function test_build_for_post_with_multiple_terms_falls_back_to_name_order() {
		$term_b = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'saai_category',
				'name'     => 'Beta',
			)
		);
		$term_a = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'saai_category',
				'name'     => 'Alpha',
			)
		);

		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		wp_set_object_terms( $post_id, array( $term_a->term_id, $term_b->term_id ), 'saai_category' );

		$trail = ( new \SAAI\Knowledge\Breadcrumbs() )->build( get_post( $post_id ) );

		$this->assertSame( 'Alpha', $trail[1]['label'] );
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
	 * Malformed trail entries from a third-party saai_breadcrumbs_items
	 * callback (non-array nodes, nodes with a missing or non-scalar label)
	 * are skipped in the JSON-LD without breaking the position sequence,
	 * and a non-scalar url is dropped rather than cast to "Array".
	 */
	public function test_json_ld_skips_malformed_nodes_and_keeps_positions_sequential() {
		$trail = array(
			array(
				'label'   => 'Knowledge Base',
				'url'     => 'https://example.com/kb/',
				'current' => false,
			),
			'not an array',
			array(
				'url'     => 'https://example.com/no-label/',
				'current' => false,
			),
			array(
				'label'   => array( 'not a scalar' ),
				'url'     => 'https://example.com/array-label/',
				'current' => false,
			),
			array(
				'label'   => 'Array URL',
				'url'     => array( 'not a scalar' ),
				'current' => false,
			),
			array(
				'label'   => 'Current Article',
				'url'     => '',
				'current' => true,
			),
		);

		$schema = ( new \SAAI\Knowledge\Breadcrumbs() )->json_ld( $trail );

		$this->assertCount( 3, $schema['itemListElement'] );
		$this->assertSame( 1, $schema['itemListElement'][0]['position'] );
		$this->assertSame( 'Knowledge Base', $schema['itemListElement'][0]['name'] );
		$this->assertSame( 2, $schema['itemListElement'][1]['position'] );
		$this->assertSame( 'Array URL', $schema['itemListElement'][1]['name'] );
		$this->assertArrayNotHasKey( 'item', $schema['itemListElement'][1] );
		$this->assertSame( 3, $schema['itemListElement'][2]['position'] );
		$this->assertSame( 'Current Article', $schema['itemListElement'][2]['name'] );
	}

	/**
	 * A URL carrying a disallowed protocol is dropped rather than emitted
	 * into the structured data as-is, since esc_url_raw() reduces it to an
	 * empty string.
	 */
	public function test_json_ld_drops_urls_that_sanitize_to_empty() {
		$trail = array(
			array(
				'label'   => 'Script URL',
				'url'     => 'javascript:alert(1)',
				'current' => false,
			),
			array(
				'label'   => 'Data URL',
				'url'     => 'data:text/html,x',
				'current' => false,
			),
		);

		$schema = ( new \SAAI\Knowledge\Breadcrumbs() )->json_ld( $trail );

		$this->assertCount( 2, $schema['itemListElement'] );
		$this->assertSame( 'Script URL', $schema['itemListElement'][0]['name'] );
		$this->assertArrayNotHasKey( 'item', $schema['itemListElement'][0] );
		$this->assertSame( 'Data URL', $schema['itemListElement'][1]['name'] );
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

	/**
	 * Renders src/breadcrumbs/render.php directly, replicating the variable
	 * contract WordPress core sets up for a block.json "render" callback —
	 * see Test_Kb_Toc::render_kb_toc() for why (same reasoning: avoids
	 * depending on the webpack-built build/breadcrumbs/ directory, which
	 * CI's PHP-only workflow never generates).
	 *
	 * @param int $post_id A postId to simulate in the block's context, or 0 for none.
	 * @return string
	 */
	private function render_kb_breadcrumbs( int $post_id ): string {
		$attributes = array();
		$content    = '';
		$block      = (object) array( 'context' => $post_id ? array( 'postId' => $post_id ) : array() );

		$previous_block_to_render            = \WP_Block_Supports::$block_to_render;
		\WP_Block_Supports::$block_to_render = array(
			'blockName' => 'saai-knowledge/breadcrumbs',
			'attrs'     => $attributes,
		);

		ob_start();
		require SAAI_KNOWLEDGE_DIR . 'src/breadcrumbs/render.php';
		$output = ob_get_clean();

		\WP_Block_Supports::$block_to_render = $previous_block_to_render;

		return $output;
	}

	/**
	 * On a non-empty saai_category term archive, WordPress's own
	 * WP::register_globals() primes the global $post (and with it, this
	 * root-level block's default postId context, since it sits outside the
	 * Query Loop) to the archive's first result — even though no Query Loop
	 * has actually iterated yet. The block must still render the term's own
	 * trail, not that first article's singular one.
	 */
	public function test_render_prioritizes_archive_context_over_first_result_postid() {
		$term_id      = self::factory()->term->create(
			array(
				'taxonomy' => 'saai_category',
				'name'     => 'Archive Context Test Term',
			)
		);
		$first_result = self::factory()->post->create(
			array(
				'post_type'  => 'saai_kb',
				'post_title' => 'First Archive Result',
			)
		);
		wp_set_object_terms( $first_result, array( $term_id ), 'saai_category' );

		$this->go_to( get_term_link( $term_id, 'saai_category' ) );

		$this->assertSame(
			$first_result,
			get_the_ID(),
			'test setup should have primed the global $post to the archive\'s first result'
		);

		// Simulates the postId context a root-level usesContext:['postId']
		// block actually receives on this archive, per render_block()'s own
		// default-context resolution from the primed global $post.
		$output = $this->render_kb_breadcrumbs( get_the_ID() );

		$this->assertStringContainsString( 'Archive Context Test Term', $output );
		$this->assertStringNotContainsString( 'First Archive Result', $output );
	}
}
