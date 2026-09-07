<?php
/**
 * Tests for the kb-sidebar block's tree-building logic.
 *
 * @package SAAI\Knowledge
 */

use SAAI\Knowledge\Sidebar_Tree;

/**
 * Class Test_Kb_Sidebar.
 */
class Test_Kb_Sidebar extends WP_UnitTestCase {

	/**
	 * The skeleton is cached: a second build() call after content changes
	 * would otherwise still see the first build's article. Plugin::boot()
	 * already registered its own Sidebar_Tree instance whose
	 * save_post_saai_kb hook calls flush_cache() automatically (both share
	 * the same transient key), so a plain factory create between two build()
	 * calls is enough to exercise the production invalidation path — this is
	 * the same pattern test-llms-index.php uses for Llms_Index.
	 */
	public function test_build_reflects_a_new_article_added_after_the_cache_was_warmed() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );

		$first_post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		wp_set_object_terms( $first_post_id, array( $term_id ), 'saai_category' );

		( new Sidebar_Tree() )->build();

		$second_post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		wp_set_object_terms( $second_post_id, array( $term_id ), 'saai_category' );

		$tree      = ( new Sidebar_Tree() )->build();
		$term_node = $this->find_node( $tree, $term_id );

		$this->assertNotNull( $term_node );
		$this->assertSame(
			array( $first_post_id, $second_post_id ),
			wp_list_pluck(
				array_filter(
					$term_node['children'],
					static fn ( $node ) => 'post' === $node['type']
				),
				'id'
			)
		);
	}

	/**
	 * Build()'s apply_expansion() step mutates the array cached_skeleton()
	 * returns (via `foreach ... as &$node`) rather than building a fresh
	 * copy. Two
	 * consecutive build() calls in the same request, with different
	 * current_post_id — and therefore different ancestor_term_ids — share the
	 * same underlying transient-backed skeleton: each call's expansion must
	 * be independent of the other's, not leak or accumulate across calls
	 * (PHP's array copy-on-write means the second get_transient() read is
	 * safe to mutate even though the first call already mutated its own copy
	 * — this test locks that safety property in against a future refactor
	 * that might, say, cache the built \WP_Term/\WP_Post objects by reference
	 * instead of a plain array).
	 */
	public function test_build_expansion_is_independent_across_consecutive_calls_sharing_the_cached_skeleton() {
		$parent_term = self::factory()->term->create_and_get( array( 'taxonomy' => 'saai_category' ) );
		$other_term  = self::factory()->term->create_and_get( array( 'taxonomy' => 'saai_category' ) );

		$post_under_parent = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		wp_set_object_terms( $post_under_parent, array( $parent_term->term_id ), 'saai_category' );

		$post_under_other = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		wp_set_object_terms( $post_under_other, array( $other_term->term_id ), 'saai_category' );

		$sidebar_tree = new Sidebar_Tree();

		$first_tree        = $sidebar_tree->build( $post_under_parent );
		$first_parent_node = $this->find_node( $first_tree, $parent_term->term_id );
		$first_other_node  = $this->find_node( $first_tree, $other_term->term_id );

		$this->assertTrue( $first_parent_node['expanded'] );
		$this->assertFalse( $first_other_node['expanded'] );

		// Same instance, same cached skeleton (still warm), different
		// current_post_id: the roles must flip, not accumulate both as
		// expanded or leak the first call's flags into the second.
		$second_tree        = $sidebar_tree->build( $post_under_other );
		$second_parent_node = $this->find_node( $second_tree, $parent_term->term_id );
		$second_other_node  = $this->find_node( $second_tree, $other_term->term_id );

		$this->assertFalse( $second_parent_node['expanded'] );
		$this->assertTrue( $second_other_node['expanded'] );

		// The first call's own result must still read as it did — proof the
		// second call's mutation didn't reach back into it.
		$this->assertTrue( $first_parent_node['expanded'] );
		$this->assertFalse( $first_other_node['expanded'] );
	}

	/**
	 * A force-delete (wp_delete_post( $id, true ) — REST's force=true, `wp
	 * post delete --force`) skips wp_trash_post() entirely, so trashed_post
	 * never fires; deleted_post must invalidate the cache too (same
	 * reasoning as Llms_Index's equivalent test).
	 */
	public function test_deleted_post_hook_invalidates_cache() {
		$sidebar_tree = new Sidebar_Tree();
		$sidebar_tree->register();

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		wp_set_object_terms( $post_id, array( $term_id ), 'saai_category' );

		$sidebar_tree->build();

		wp_delete_post( $post_id, true );

		$tree      = $sidebar_tree->build();
		$term_node = $this->find_node( $tree, $term_id );

		$this->assertNotNull( $term_node );
		$this->assertSame( array(), $term_node['children'] );
	}

	/**
	 * Renaming a saai_category term changes a term node's 'title', which the
	 * cached skeleton must pick up rather than keep serving the old name.
	 */
	public function test_edited_saai_category_hook_invalidates_cache() {
		$sidebar_tree = new Sidebar_Tree();
		$sidebar_tree->register();

		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'saai_category',
				'name'     => 'Original Name',
			)
		);

		$sidebar_tree->build();

		wp_update_term( $term_id, 'saai_category', array( 'name' => 'Renamed' ) );

		$tree      = $sidebar_tree->build();
		$term_node = $this->find_node( $tree, $term_id );

		$this->assertNotNull( $term_node );
		$this->assertSame( 'Renamed', $term_node['title'] );
	}

	/**
	 * Re-assigning an existing saai_kb post to a different saai_category term
	 * via wp_set_object_terms() alone (wp-admin's Quick Edit bulk category
	 * change does this without calling wp_update_post()) must invalidate the
	 * cache — save_post_saai_kb never fires for this path, so without the
	 * set_object_terms hook the tree would keep the post under its old
	 * category for up to an hour (Codex review).
	 */
	public function test_set_object_terms_hook_invalidates_cache_on_recategorization() {
		$sidebar_tree = new Sidebar_Tree();
		$sidebar_tree->register();

		$old_term = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$new_term = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );

		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		wp_set_object_terms( $post_id, array( $old_term ), 'saai_category' );

		$sidebar_tree->build();

		wp_set_object_terms( $post_id, array( $new_term ), 'saai_category' );

		$tree          = $sidebar_tree->build();
		$old_term_node = $this->find_node( $tree, $old_term );
		$new_term_node = $this->find_node( $tree, $new_term );

		$this->assertNotNull( $old_term_node );
		$this->assertSame( array(), $old_term_node['children'] );

		$this->assertNotNull( $new_term_node );
		$this->assertSame( array( $post_id ), wp_list_pluck( $new_term_node['children'], 'id' ) );
	}

	/**
	 * A saai_category term change is a shared taxonomy also used by saai_faq
	 * (docs/DESIGN.md section 3.2) — set_object_terms fires for any object
	 * type, and flush_cache_on_term_relationship_change() only checks the
	 * taxonomy, not the object's post type. Confirms this doesn't crash or
	 * misbehave for a non-saai_kb object; the resulting extra flush is an
	 * accepted, harmless tradeoff (see register()'s docblock).
	 */
	public function test_set_object_terms_hook_flushes_for_unrelated_post_types_sharing_the_taxonomy() {
		$sidebar_tree = new Sidebar_Tree();
		$sidebar_tree->register();

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$faq_id  = self::factory()->post->create( array( 'post_type' => 'saai_faq' ) );

		$sidebar_tree->build();

		wp_set_object_terms( $faq_id, array( $term_id ), 'saai_category' );

		$this->assertFalse( get_transient( 'saai_kb_sidebar_tree' ) );
	}

	/**
	 * The maybe_flush_cache_on_slug_change() method flushes the cache only
	 * when slug_kb actually changes — every post node's 'url' embeds
	 * get_permalink(), which changes once Post_Types re-registers saai_kb
	 * with the new slug (same reasoning as Llms_Index's equivalent test).
	 */
	public function test_maybe_flush_cache_on_slug_change_flushes_only_on_a_real_slug_change() {
		$sidebar_tree = new Sidebar_Tree();
		$sidebar_tree->build();

		$sidebar_tree->maybe_flush_cache_on_slug_change(
			array(
				'slug_kb'            => 'kb',
				'autolink_max_links' => 20,
			),
			array(
				'slug_kb'            => 'kb',
				'autolink_max_links' => 5,
			)
		);
		$this->assertIsArray( get_transient( 'saai_kb_sidebar_tree' ), 'An unrelated field change must not flush the cache.' );

		$sidebar_tree->maybe_flush_cache_on_slug_change(
			array( 'slug_kb' => 'kb' ),
			array( 'slug_kb' => 'articles' )
		);
		$this->assertFalse( get_transient( 'saai_kb_sidebar_tree' ), 'A slug_kb change must flush the cache.' );
	}

	/**
	 * A completely fresh install has no saai_knowledge_settings option row
	 * yet; WordPress core's update_option() delegates a first-ever save of
	 * it to add_option() internally, which fires add_option_{$option}
	 * instead of update_option_{$option} — maybe_flush_cache_on_slug_change()
	 * alone would miss a slug changed on that very first save (Codex
	 * review).
	 */
	public function test_add_option_hook_invalidates_cache_on_first_ever_settings_save() {
		$sidebar_tree = new Sidebar_Tree();
		$sidebar_tree->register();

		delete_option( 'saai_knowledge_settings' );

		$sidebar_tree->build();

		add_option( 'saai_knowledge_settings', array( 'slug_kb' => 'articles' ) );

		$this->assertFalse( get_transient( 'saai_kb_sidebar_tree' ) );
	}

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
	 * When both are given, the saai_kb_sidebar_items filter context must report
	 * current_term_id as null, not the raw (ignored) parameter value — otherwise
	 * a consumer would see both current_post_id and current_term_id set at once
	 * with no way to tell which one actually drove the expanded terms.
	 */
	public function test_current_term_id_is_normalized_to_null_in_filter_context_when_current_post_id_wins() {
		$captured_context = null;

		$filter = static function ( $tree, $context ) use ( &$captured_context ) {
			$captured_context = $context;

			return $tree;
		};

		add_filter( 'saai_kb_sidebar_items', $filter, 10, 2 );

		( new \SAAI\Knowledge\Sidebar_Tree() )->build( 42, 99 );

		remove_filter( 'saai_kb_sidebar_items', $filter, 10 );

		$this->assertSame( 42, $captured_context['current_post_id'] );
		$this->assertNull( $captured_context['current_term_id'] );
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
	 * The editor's ServerSideRender preview and the Site Editor canvas render
	 * this block via block context, not a real front-end query — is_singular()
	 * is false there even though a specific KB article is what's being
	 * previewed (see kb-toc/render.php's docblock for the same reasoning).
	 * render.php must prefer $block->context['postId'] so the sidebar expands
	 * the right branch in those views too, not just on the front end.
	 */
	public function test_render_expands_the_current_post_from_block_context_when_not_singular() {
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

		// Deliberately not a saai_kb singular (nor a saai_category archive):
		// is_singular( 'saai_kb' )/is_tax( 'saai_category' ) are both false
		// here, so only $block->context['postId'] can identify the article.
		$this->go_to( home_url( '/' ) );

		$output = $this->render_kb_sidebar( $post_id );

		$this->assertStringContainsString(
			esc_html( $parent_term->name ) . '</a></span><div id="',
			$output,
			'test setup: the ancestor term should be present in the rendered tree'
		);
		$this->assertStringNotContainsString(
			esc_html( $other_term->name ) . '</a></span><div id="',
			$output,
			'test setup: the unrelated term is a distractor and should render collapsed'
		);

		$parent_children_open = strpos( $output, 'data-wp-context=\'{&quot;open&quot;:true}\'' ) !== false
			|| strpos( $output, "data-wp-context='{\"open\":true}'" ) !== false;

		$this->assertTrue(
			$parent_children_open,
			'the current post\'s ancestor term must be expanded from block context alone, even though the view is not singular'
		);
	}

	/**
	 * On a non-empty saai_category term archive, WordPress's own
	 * WP::register_globals() primes the global $post (and with it, this
	 * root-level block's default postId context, since it sits outside the
	 * Query Loop) to the archive's first result — even though no Query Loop
	 * has actually iterated yet. The sidebar must still expand based on the
	 * archived term itself, not mistake that seeded postId for a viewed
	 * article (see breadcrumbs/render.php's equivalent test/docblock).
	 */
	public function test_render_prioritizes_archive_context_over_first_result_postid_on_a_category_archive() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		wp_set_object_terms( $post_id, array( $term_id ), 'saai_category' );

		$this->go_to( get_term_link( $term_id, 'saai_category' ) );

		$this->assertSame(
			$post_id,
			get_the_ID(),
			'test setup should have primed the global $post to the archive\'s first result'
		);

		$captured_context = $this->capture_sidebar_context(
			// Simulates the postId context a root-level usesContext:['postId']
			// block actually receives on this archive, per render_block()'s
			// own default-context resolution from the primed global $post.
			static fn( self $test ) => $test->render_kb_sidebar( get_the_ID() )
		);

		$this->assertNull( $captured_context['current_post_id'], 'the seeded postId must not be mistaken for a viewed article' );
		$this->assertSame( $term_id, $captured_context['current_term_id'] );
	}

	/**
	 * The KB hub archive (archive-saai_kb) equivalent of the test above —
	 * WordPress seeds the same default postId context there too.
	 */
	public function test_render_ignores_archive_seeded_post_id_on_the_kb_hub_archive() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );

		$this->go_to( get_post_type_archive_link( 'saai_kb' ) );

		$this->assertSame(
			$post_id,
			get_the_ID(),
			'test setup should have primed the global $post to the archive\'s first result'
		);

		$captured_context = $this->capture_sidebar_context(
			static fn( self $test ) => $test->render_kb_sidebar( get_the_ID() )
		);

		$this->assertNull( $captured_context['current_post_id'], 'the seeded postId must not be mistaken for a viewed article on the KB hub archive' );
		$this->assertNull( $captured_context['current_term_id'] );
	}

	/**
	 * A site-customized taxonomy-saai_category template can place this block
	 * inside a Query Loop instead of at the root level. There, unlike the
	 * bundled root-level block, postId is the Query Loop's own genuine
	 * per-item context — not the archive's default-seeded first result —
	 * and must win over the archived term even though is_tax( 'saai_category' )
	 * is true.
	 */
	public function test_render_honors_query_loop_postid_on_a_category_archive() {
		$archived_term = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$archive_post  = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		wp_set_object_terms( $archive_post, array( $archived_term ), 'saai_category' );

		// Deliberately not a member of the archived term: stands in for a
		// Query Loop item that could list posts from anywhere, unrelated to
		// the term being archived.
		$looped_term = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$looped_post = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		wp_set_object_terms( $looped_post, array( $looped_term ), 'saai_category' );

		$this->go_to( get_term_link( $archived_term, 'saai_category' ) );

		// queryId marks this as a genuine Query Loop item render, not the
		// archive's default seed.
		$captured_context = $this->capture_sidebar_context(
			static fn( self $test ) => $test->render_kb_sidebar( $looped_post, 1 )
		);

		$this->assertSame( $looped_post, $captured_context['current_post_id'] );
		$this->assertNull( $captured_context['current_term_id'] );
	}

	/**
	 * Renders the given render.php call while capturing the $saai_context
	 * passed to the saai_kb_sidebar_top action — the most direct way to
	 * observe render.php's internal current_post_id/current_term_id
	 * resolution without relying on incidental HTML shape.
	 *
	 * @param callable(self): void $render Invokes render_kb_sidebar() with this test instance.
	 * @return array<string, mixed>
	 */
	private function capture_sidebar_context( callable $render ): array {
		$captured_context = null;
		$capture          = static function ( $context ) use ( &$captured_context ) {
			$captured_context = $context;
		};

		add_action( 'saai_kb_sidebar_top', $capture );
		$render( $this );
		remove_action( 'saai_kb_sidebar_top', $capture );

		return $captured_context;
	}

	/**
	 * Renders src/kb-sidebar/render.php directly with the given postId (and,
	 * optionally, queryId) block context, replicating the variable contract
	 * WordPress core sets up for a block.json "render" callback — see
	 * test-kb-toc.php's render_kb_toc() for the same pattern and its
	 * docblock for why this doesn't need the block actually registered or
	 * built.
	 *
	 * @param int      $post_id  A postId to simulate in the block's context, or 0 for none.
	 * @param int|null $query_id A queryId to simulate (a Query Loop ancestor providing it), or null for none.
	 * @return string
	 */
	private function render_kb_sidebar( int $post_id, ?int $query_id = null ): string {
		$attributes = array();
		$content    = '';
		$context    = $post_id ? array( 'postId' => $post_id ) : array();

		if ( null !== $query_id ) {
			$context['queryId'] = $query_id;
		}

		$block = (object) array( 'context' => $context );

		$previous_block_to_render            = \WP_Block_Supports::$block_to_render;
		\WP_Block_Supports::$block_to_render = array(
			'blockName' => 'saai-knowledge/kb-sidebar',
			'attrs'     => $attributes,
		);

		ob_start();
		require SAAI_KNOWLEDGE_DIR . 'src/kb-sidebar/render.php';
		$output = ob_get_clean();

		\WP_Block_Supports::$block_to_render = $previous_block_to_render;

		return $output;
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
