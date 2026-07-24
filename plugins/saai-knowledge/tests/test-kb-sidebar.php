<?php
/**
 * Tests for the kb-sidebar block's tree-building logic.
 *
 * @package SAAI\Knowledge
 */

/**
 * Class Test_Kb_Sidebar.
 */
class Test_Kb_Sidebar extends WP_UnitTestCase {

	/**
	 * The tree should mirror the saai_category hierarchy with articles
	 * nested under their term, ordered by menu_order then title.
	 */
	public function test_build_nests_terms_and_articles_in_order() {
		$parent_term = self::factory()->term->create_and_get( array( 'taxonomy' => 'saai_category' ) );
		$child_term  = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'saai_category',
				'parent'   => $parent_term->term_id,
			)
		);

		$second_post = self::factory()->post->create(
			array(
				'post_type'  => 'saai_kb',
				'post_title' => 'B Article',
				'menu_order' => 1,
			)
		);
		$first_post  = self::factory()->post->create(
			array(
				'post_type'  => 'saai_kb',
				'post_title' => 'A Article',
				'menu_order' => 0,
			)
		);

		wp_set_object_terms( $first_post, array( $parent_term->term_id ), 'saai_category' );
		wp_set_object_terms( $second_post, array( $parent_term->term_id ), 'saai_category' );

		$tree = ( new \SAAI\Knowledge\Sidebar_Tree() )->build();

		$parent_node = $this->find_node( $tree, $parent_term->term_id );

		$this->assertNotNull( $parent_node );
		$this->assertSame( 'term', $parent_node['type'] );

		$child_types = wp_list_pluck( $parent_node['children'], 'type' );
		$this->assertContains( 'term', $child_types );
		$this->assertContains( 'post', $child_types );

		$post_nodes = array_values(
			array_filter(
				$parent_node['children'],
				static function ( $node ) {
					return 'post' === $node['type'];
				}
			)
		);

		$this->assertSame( array( $first_post, $second_post ), wp_list_pluck( $post_nodes, 'id' ) );

		$child_term_node = $this->find_node( $parent_node['children'], $child_term->term_id );
		$this->assertNotNull( $child_term_node );
		$this->assertSame( 'term', $child_term_node['type'] );
	}

	/**
	 * An article assigned to a child term must appear only once, under that
	 * child, and not be duplicated under the parent term (WP_Query's
	 * tax_query defaults to include_children=true, which would otherwise
	 * match the parent term too since the child is hierarchically beneath it).
	 */
	public function test_article_on_child_term_is_not_duplicated_under_parent() {
		$parent_term = self::factory()->term->create_and_get( array( 'taxonomy' => 'saai_category' ) );
		$child_term  = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'saai_category',
				'parent'   => $parent_term->term_id,
			)
		);

		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		wp_set_object_terms( $post_id, array( $child_term->term_id ), 'saai_category' );

		$tree = ( new \SAAI\Knowledge\Sidebar_Tree() )->build();

		$parent_node = $this->find_node( $tree, $parent_term->term_id );
		$this->assertNotNull( $parent_node );

		$child_node = $this->find_node( $parent_node['children'], $child_term->term_id );
		$this->assertNotNull( $child_node );

		$this->assertSame( array( 'post' ), wp_list_pluck( $child_node['children'], 'type' ) );
		$this->assertSame( array( $child_node ), array_values( array_filter( $parent_node['children'], static fn ( $node ) => 'term' === $node['type'] ) ) );

		$parent_post_types = wp_list_pluck( $parent_node['children'], 'type' );
		$this->assertNotContains( 'post', $parent_post_types );
	}

	/**
	 * The saai_category ancestors of the current post should be marked expanded.
	 */
	public function test_ancestor_terms_of_current_post_are_expanded() {
		$parent_term = self::factory()->term->create_and_get( array( 'taxonomy' => 'saai_category' ) );
		$child_term  = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'saai_category',
				'parent'   => $parent_term->term_id,
			)
		);
		$other_term  = self::factory()->term->create_and_get( array( 'taxonomy' => 'saai_category' ) );

		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		wp_set_object_terms( $post_id, array( $child_term->term_id ), 'saai_category' );

		$tree = ( new \SAAI\Knowledge\Sidebar_Tree() )->build( $post_id );

		$parent_node = $this->find_node( $tree, $parent_term->term_id );
		$other_node  = $this->find_node( $tree, $other_term->term_id );

		$this->assertNotNull( $parent_node );
		$this->assertNotNull( $other_node );
		$this->assertTrue( $parent_node['expanded'] );
		$this->assertFalse( $other_node['expanded'] );

		$child_node = $this->find_node( $parent_node['children'], $child_term->term_id );
		$this->assertNotNull( $child_node );
		$this->assertTrue( $child_node['expanded'] );
	}

	/**
	 * The saai_category ancestors of the current viewed term (e.g. a taxonomy
	 * archive, where there is no current post) should be marked expanded.
	 */
	public function test_ancestor_terms_of_current_term_are_expanded() {
		$parent_term = self::factory()->term->create_and_get( array( 'taxonomy' => 'saai_category' ) );
		$child_term  = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'saai_category',
				'parent'   => $parent_term->term_id,
			)
		);
		$other_term  = self::factory()->term->create_and_get( array( 'taxonomy' => 'saai_category' ) );

		$tree = ( new \SAAI\Knowledge\Sidebar_Tree() )->build( null, $child_term->term_id );

		$parent_node = $this->find_node( $tree, $parent_term->term_id );
		$other_node  = $this->find_node( $tree, $other_term->term_id );

		$this->assertNotNull( $parent_node );
		$this->assertNotNull( $other_node );
		$this->assertTrue( $parent_node['expanded'] );
		$this->assertFalse( $other_node['expanded'] );

		$child_node = $this->find_node( $parent_node['children'], $child_term->term_id );
		$this->assertNotNull( $child_node );
		$this->assertTrue( $child_node['expanded'] );
	}

	/**
	 * If both are somehow given, the current post's ancestor terms should win
	 * over the current term parameter (render.php never passes both, since a
	 * request is either a singular post view or a taxonomy archive, but
	 * build()'s own priority should still be well-defined and tested directly).
	 */
	public function test_current_post_id_takes_priority_over_current_term_id() {
		$post_term  = self::factory()->term->create_and_get( array( 'taxonomy' => 'saai_category' ) );
		$other_term = self::factory()->term->create_and_get( array( 'taxonomy' => 'saai_category' ) );

		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		wp_set_object_terms( $post_id, array( $post_term->term_id ), 'saai_category' );

		$tree = ( new \SAAI\Knowledge\Sidebar_Tree() )->build( $post_id, $other_term->term_id );

		$post_term_node  = $this->find_node( $tree, $post_term->term_id );
		$other_term_node = $this->find_node( $tree, $other_term->term_id );

		$this->assertTrue( $post_term_node['expanded'] );
		$this->assertFalse( $other_term_node['expanded'] );
	}

	/**
	 * The saai_kb_sidebar_items filter should receive and be able to replace the tree.
	 */
	public function test_saai_kb_sidebar_items_filter_can_replace_tree() {
		$captured_context = null;

		$filter = static function ( $tree, $context ) use ( &$captured_context ) {
			$captured_context = $context;

			return array(
				array(
					'type'     => 'post',
					'id'       => 999,
					'title'    => 'Injected',
					'url'      => '#',
					'order'    => 0,
					'children' => array(),
				),
			);
		};

		add_filter( 'saai_kb_sidebar_items', $filter, 10, 2 );

		$tree = ( new \SAAI\Knowledge\Sidebar_Tree() )->build( 42 );

		remove_filter( 'saai_kb_sidebar_items', $filter, 10 );

		$this->assertSame( 999, $tree[0]['id'] );
		$this->assertSame( 42, $captured_context['current_post_id'] );
		$this->assertNull( $captured_context['current_term_id'] );
		$this->assertSame( 'saai_category', $captured_context['taxonomy'] );
	}

	/**
	 * A misbehaving saai_kb_sidebar_items callback returning a non-array must not
	 * fatal on the build(): array return type; the unfiltered tree should be used instead.
	 */
	public function test_saai_kb_sidebar_items_filter_falls_back_when_not_an_array() {
		$parent_term = self::factory()->term->create_and_get( array( 'taxonomy' => 'saai_category' ) );

		$filter = static function () {
			return 'not an array';
		};

		add_filter( 'saai_kb_sidebar_items', $filter );

		$tree = ( new \SAAI\Knowledge\Sidebar_Tree() )->build();

		remove_filter( 'saai_kb_sidebar_items', $filter );

		$this->assertNotNull( $this->find_node( $tree, $parent_term->term_id ) );
	}

	/**
	 * Finds a node with the given term/post id at the top level of a node list.
	 *
	 * @param array<int, array<string, mixed>> $nodes Node list.
	 * @param int                              $id    ID to find.
	 * @return array<string, mixed>|null
	 */
	private function find_node( array $nodes, int $id ): ?array {
		foreach ( $nodes as $node ) {
			if ( $node['id'] === $id ) {
				return $node;
			}
		}

		return null;
	}
}
