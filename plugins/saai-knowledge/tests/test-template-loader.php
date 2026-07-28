<?php
/**
 * Tests for the block-theme / classic-theme template loader.
 *
 * @package SAAI\Knowledge
 */

/**
 * Class Test_Template_Loader.
 */
class Test_Template_Loader extends WP_UnitTestCase {

	/**
	 * The WP core test suite's default theme is classic (not block-based),
	 * which is what exercises the template_include fallback branch here.
	 */
	public function test_default_test_theme_is_classic() {
		$this->assertFalse( wp_is_block_theme() );
	}

	/**
	 * A block template should be registered for each content post type, the KB
	 * archive, and the category taxonomy.
	 *
	 * Template_Loader::register() only conditionally hooks
	 * register_block_templates() on block themes (its other hooks —
	 * template_include, enqueue_block_assets, pre_get_posts — always run
	 * regardless of theme type), and the WP core test suite's default theme
	 * is classic, so bootstrap never triggers it here. Call
	 * register_block_templates() directly instead.
	 *
	 * WP_Block_Templates_Registry isn't reset between tests (unlike registered
	 * meta keys), so calling register_block_templates() more than once across
	 * this test class would trip its "already registered" incorrect-usage
	 * notice; all assertions on its output are consolidated into this one test.
	 */
	public function test_block_templates_are_registered() {
		( new \SAAI\Knowledge\Template_Loader() )->register_block_templates();

		$registry = \WP_Block_Templates_Registry::get_instance();

		foreach ( array( 'saai_kb', 'saai_faq', 'saai_glossary' ) as $post_type ) {
			$template = $registry->get_registered( "saai-knowledge//single-{$post_type}" );

			$this->assertNotNull( $template, "single-{$post_type} block template should be registered" );
			$this->assertStringContainsString( 'wp:post-title', $template->content );
			$this->assertStringContainsString( 'wp:post-content', $template->content );
		}

		$archive = $registry->get_registered( 'saai-knowledge//archive-saai_kb' );
		$this->assertNotNull( $archive, 'archive-saai_kb block template should be registered' );
		$this->assertStringContainsString( 'saai-knowledge/kb-sidebar', $archive->content );
		$this->assertStringContainsString( 'wp:query-no-results', $archive->content );
		$this->assertStringContainsString( esc_html__( 'No knowledge base articles found.', 'saai-knowledge' ), $archive->content );
		$this->assertStringNotContainsString( '{{saai_kb_hub_empty_label}}', $archive->content );

		$taxonomy = $registry->get_registered( 'saai-knowledge//taxonomy-saai_category' );
		$this->assertNotNull( $taxonomy, 'taxonomy-saai_category block template should be registered' );
		$this->assertStringContainsString( 'saai-knowledge/kb-sidebar', $taxonomy->content );
		$this->assertStringContainsString( 'wp:query-no-results', $taxonomy->content );
		$this->assertStringContainsString( esc_html__( 'No knowledge base articles found in this category.', 'saai-knowledge' ), $taxonomy->content );
		$this->assertStringNotContainsString( '{{saai_kb_category_empty_label}}', $taxonomy->content );

		$single_kb = $registry->get_registered( 'saai-knowledge//single-saai_kb' );
		$this->assertNotNull( $single_kb, 'single-saai_kb block template should be registered' );
		$this->assertStringContainsString( '>' . esc_html__( 'Categories', 'saai-knowledge' ) . '</summary>', $single_kb->content );
		$this->assertStringContainsString( '>' . esc_html__( 'Table of contents', 'saai-knowledge' ) . '</summary>', $single_kb->content );
		$this->assertStringNotContainsString( '{{saai_categories_label}}', $single_kb->content );
		$this->assertStringNotContainsString( '{{saai_toc_label}}', $single_kb->content );
	}

	/**
	 * Non-CPT requests (e.g. a plain page) should be left untouched.
	 */
	public function test_template_include_ignores_unrelated_requests() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $page_id ) );

		$resolved = ( new \SAAI\Knowledge\Template_Loader() )->filter_template_include( '/theme/page.php' );

		$this->assertSame( '/theme/page.php', $resolved );
	}

	/**
	 * Each content post type should resolve to its bundled classic-theme template.
	 */
	public function test_template_include_resolves_bundled_template_for_each_post_type() {
		$loader = new \SAAI\Knowledge\Template_Loader();

		foreach ( array( 'saai_kb', 'saai_faq', 'saai_glossary' ) as $post_type ) {
			$post_id = self::factory()->post->create( array( 'post_type' => $post_type ) );
			$this->go_to( get_permalink( $post_id ) );

			$resolved = $loader->filter_template_include( '/theme/fallback.php' );

			$this->assertSame(
				SAAI_KNOWLEDGE_DIR . "templates/classic/single-{$post_type}.php",
				$resolved,
				"single-{$post_type} should resolve to the bundled classic template"
			);
		}
	}

	/**
	 * The KB post type archive should resolve to its bundled classic-theme template.
	 */
	public function test_template_include_resolves_bundled_template_for_kb_archive() {
		self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		$this->go_to( get_post_type_archive_link( 'saai_kb' ) );

		$resolved = ( new \SAAI\Knowledge\Template_Loader() )->filter_template_include( '/theme/fallback.php' );

		$this->assertSame(
			SAAI_KNOWLEDGE_DIR . 'templates/classic/archive-saai_kb.php',
			$resolved
		);
	}

	/**
	 * The saai_category taxonomy archive should resolve to its bundled classic-theme template.
	 */
	public function test_template_include_resolves_bundled_template_for_category_taxonomy() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$this->go_to( get_term_link( $term_id, 'saai_category' ) );

		$resolved = ( new \SAAI\Knowledge\Template_Loader() )->filter_template_include( '/theme/fallback.php' );

		$this->assertSame(
			SAAI_KNOWLEDGE_DIR . 'templates/classic/taxonomy-saai_category.php',
			$resolved
		);
	}

	/**
	 * The saai_template filter should be able to override the final resolution.
	 */
	public function test_saai_template_filter_overrides_resolution() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_faq' ) );
		$this->go_to( get_permalink( $post_id ) );

		$override_path = tempnam( sys_get_temp_dir(), 'saai-template-' );

		$filter = static function () use ( $override_path ) {
			return $override_path;
		};
		add_filter( 'saai_template', $filter );

		$resolved = ( new \SAAI\Knowledge\Template_Loader() )->filter_template_include( '/theme/fallback.php' );

		remove_filter( 'saai_template', $filter );
		wp_delete_file( $override_path );

		$this->assertSame( $override_path, $resolved );
	}

	/**
	 * If the filtered override path doesn't exist, resolution should fall back to
	 * the template WordPress had already resolved rather than a dead path.
	 */
	public function test_saai_template_filter_falls_back_when_override_is_missing() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		$this->go_to( get_permalink( $post_id ) );

		$filter = static function () {
			return '/this/path/does/not/exist.php';
		};
		add_filter( 'saai_template', $filter );

		$resolved = ( new \SAAI\Knowledge\Template_Loader() )->filter_template_include( '/theme/fallback.php' );

		remove_filter( 'saai_template', $filter );

		$this->assertSame( '/theme/fallback.php', $resolved );
	}

	/**
	 * A misbehaving saai_template callback returning a non-string must not fatal
	 * file_exists() with a TypeError; resolution should fall back safely instead.
	 */
	public function test_saai_template_filter_falls_back_when_override_is_not_a_string() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		$this->go_to( get_permalink( $post_id ) );

		$filter = static function () {
			return array( 'not', 'a', 'string' );
		};
		add_filter( 'saai_template', $filter );

		$resolved = ( new \SAAI\Knowledge\Template_Loader() )->filter_template_include( '/theme/fallback.php' );

		remove_filter( 'saai_template', $filter );

		$this->assertSame( '/theme/fallback.php', $resolved );
	}

	/**
	 * Views outside the KB two-column layout (e.g. a plain page) must not enqueue its style/script.
	 */
	public function test_enqueue_layout_style_skips_unrelated_views() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $page_id ) );

		( new \SAAI\Knowledge\Template_Loader() )->enqueue_layout_style();

		$this->assertFalse( wp_style_is( 'saai-knowledge-kb-layout', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'saai-knowledge-kb-layout', 'enqueued' ) );
	}

	/**
	 * The KB singular view, KB archive, and category taxonomy should each enqueue
	 * both the layout style and its companion script (see kb-layout.js: it keeps
	 * the sidebar/TOC <details> open once their container crosses the breakpoint
	 * where kb-layout.css hides the <summary> toggle used to reopen them).
	 */
	public function test_enqueue_layout_style_enqueues_on_kb_layout_views() {
		$loader = new \SAAI\Knowledge\Template_Loader();

		$views = array(
			'singular' => function () {
				$post_id = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
				return get_permalink( $post_id );
			},
			'archive'  => function () {
				self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
				return get_post_type_archive_link( 'saai_kb' );
			},
			'taxonomy' => function () {
				$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
				return get_term_link( $term_id, 'saai_category' );
			},
		);

		foreach ( $views as $view => $get_url ) {
			$this->go_to( $get_url() );
			$loader->enqueue_layout_style();

			$this->assertTrue( wp_style_is( 'saai-knowledge-kb-layout', 'enqueued' ), "style should be enqueued for the {$view} view" );
			$this->assertTrue( wp_script_is( 'saai-knowledge-kb-layout', 'enqueued' ), "script should be enqueued for the {$view} view" );

			wp_dequeue_style( 'saai-knowledge-kb-layout' );
			wp_dequeue_script( 'saai-knowledge-kb-layout' );
		}
	}

	/**
	 * The Site Editor renders one of these registered templates without a
	 * real front-end query (a template is edited in the abstract, not a
	 * specific post/archive), so is_kb_layout_view()'s is_singular()/is_tax()
	 * checks never match there. The layout stylesheet must still load
	 * unconditionally on that screen so a customized template's canvas isn't
	 * an unstyled single column, unlike the front end — but not kb-layout.js,
	 * whose ResizeObserver behavior is meaningless in a static preview
	 * canvas (see enqueue_layout_style()'s docblock).
	 */
	public function test_enqueue_layout_style_loads_stylesheet_only_in_site_editor() {
		require_once ABSPATH . 'wp-admin/includes/screen.php';

		set_current_screen( 'site-editor' );

		( new \SAAI\Knowledge\Template_Loader() )->enqueue_layout_style();

		unset( $GLOBALS['current_screen'] );

		$this->assertTrue( wp_style_is( 'saai-knowledge-kb-layout', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'saai-knowledge-kb-layout', 'enqueued' ) );

		wp_dequeue_style( 'saai-knowledge-kb-layout' );
	}

	/**
	 * The saai_category taxonomy is shared with saai_faq, but the category
	 * archive template is entirely KB-branded; its main query must be
	 * restricted to saai_kb so FAQ posts assigned to the same term don't
	 * appear on it.
	 */
	public function test_restrict_category_archive_to_kb_sets_post_type_for_main_taxonomy_query() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$this->go_to( get_term_link( $term_id, 'saai_category' ) );

		global $wp_query;

		( new \SAAI\Knowledge\Template_Loader() )->restrict_category_archive_to_kb( $wp_query );

		$this->assertSame( 'saai_kb', $wp_query->get( 'post_type' ) );
	}

	/**
	 * Views outside the saai_category taxonomy archive must not have their
	 * post_type query var touched.
	 */
	public function test_restrict_category_archive_to_kb_ignores_unrelated_queries() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $page_id ) );

		global $wp_query;
		$original_post_type = $wp_query->get( 'post_type' );

		( new \SAAI\Knowledge\Template_Loader() )->restrict_category_archive_to_kb( $wp_query );

		$this->assertSame( $original_post_type, $wp_query->get( 'post_type' ) );
	}

	/**
	 * A saai_category feed request must not be restricted to saai_kb — it's
	 * served by WordPress's own feed templates, not the KB-branded archive
	 * template, so saai_faq entries belong in it too.
	 */
	public function test_restrict_category_archive_to_kb_ignores_feed_requests() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$this->go_to( get_term_feed_link( $term_id, 'saai_category' ) );

		global $wp_query;
		$this->assertTrue( $wp_query->is_feed(), 'test setup should have produced a feed request' );
		$original_post_type = $wp_query->get( 'post_type' );

		( new \SAAI\Knowledge\Template_Loader() )->restrict_category_archive_to_kb( $wp_query );

		$this->assertSame( $original_post_type, $wp_query->get( 'post_type' ) );
	}

	/**
	 * A compound search scoped to this taxonomy (e.g.
	 * /?s=setup&saai_category=guides) is still a saai_category taxonomy
	 * query, but WordPress's own template hierarchy renders its search
	 * template for it, not the bundled KB-branded archive template — the
	 * restriction must not hide saai_faq entries from a plain search
	 * results page.
	 */
	public function test_restrict_category_archive_to_kb_ignores_compound_search_requests() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$this->go_to( '/?s=setup&saai_category=' . get_term( $term_id, 'saai_category' )->slug );

		global $wp_query;
		$this->assertTrue( $wp_query->is_search(), 'test setup should have produced a search request' );
		$this->assertTrue( $wp_query->is_tax( 'saai_category' ), 'test setup should have produced a taxonomy request' );

		// Not a before/after comparison: Template_Loader::register() already
		// hooks this on the live, bootstrap-registered instance, so go_to()
		// itself already invoked it once for this same $wp_query — comparing
		// against a "before" value captured after that would just compare
		// the method's output with itself and never catch a regression here.
		( new \SAAI\Knowledge\Template_Loader() )->restrict_category_archive_to_kb( $wp_query );

		$this->assertNotSame( 'saai_kb', $wp_query->get( 'post_type' ) );
	}

	/**
	 * A compound search on this taxonomy also leaves the resolved template
	 * alone on classic themes: WordPress's own template hierarchy has
	 * already resolved $template to its search template (is_search() is
	 * checked ahead of is_tax() in template-loader.php), and forcing the
	 * bundled KB-branded one onto it would contradict
	 * restrict_category_archive_to_kb()'s own exemption for the same
	 * request (unrestricted query, but KB-branded template — showing
	 * saai_faq entries inside a page whose whole premise is "KB only").
	 */
	public function test_filter_template_include_ignores_compound_search_requests() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$this->go_to( '/?s=setup&saai_category=' . get_term( $term_id, 'saai_category' )->slug );

		$this->assertTrue( is_search(), 'test setup should have produced a search request' );
		$this->assertTrue( is_tax( 'saai_category' ), 'test setup should have produced a taxonomy request' );

		$resolved = ( new \SAAI\Knowledge\Template_Loader() )->filter_template_include( '/theme/search.php' );

		$this->assertSame( '/theme/search.php', $resolved );
	}

	/**
	 * A request that already carries an explicit post-type scope (e.g. a
	 * ?post_type=saai_faq query var) before this runs must have that
	 * deliberate choice respected, not silently overwritten.
	 */
	public function test_restrict_category_archive_to_kb_ignores_explicit_post_type_scope() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$this->go_to( add_query_arg( 'post_type', 'saai_faq', get_term_link( $term_id, 'saai_category' ) ) );

		global $wp_query;

		// Not a before/after comparison — see the equivalent comment in
		// test_restrict_category_archive_to_kb_ignores_compound_search_requests().
		( new \SAAI\Knowledge\Template_Loader() )->restrict_category_archive_to_kb( $wp_query );

		$this->assertSame( 'saai_faq', $wp_query->get( 'post_type' ) );
	}

	/**
	 * The bundled taxonomy-saai_category template is entirely KB-branded, so
	 * it must not win for a request that explicitly carries a non-KB
	 * post-type scope (e.g. ?post_type=saai_faq) — restrict_category_archive_to_kb()
	 * already leaves such a request's query alone, and forcing the KB
	 * template onto it anyway would show its results inside KB-only
	 * sidebar, breadcrumbs, and empty-state UI regardless.
	 */
	public function test_filter_template_include_ignores_explicit_post_type_scope() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$this->go_to( add_query_arg( 'post_type', 'saai_faq', get_term_link( $term_id, 'saai_category' ) ) );

		$this->assertSame(
			'saai_faq',
			get_query_var( 'post_type' ),
			'test setup should have produced an explicit post_type scope'
		);

		$resolved = ( new \SAAI\Knowledge\Template_Loader() )->filter_template_include( '/theme/fallback.php' );

		$this->assertSame( '/theme/fallback.php', $resolved );
	}

	/**
	 * WordPress's array form of post_type (e.g. ?post_type[]=saai_kb, or an
	 * earlier pre_get_posts callback calling $query->set('post_type', ['saai_kb']))
	 * scopes a query to KB-only just as validly as the plain string form —
	 * get_query_var('post_type') returning an array here must not be mistaken
	 * for an explicit non-KB scope and fall back to the theme's own template.
	 */
	public function test_filter_template_include_accepts_array_valued_kb_only_scope() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$this->go_to( get_term_link( $term_id, 'saai_category' ) );

		global $wp_query;
		$wp_query->set( 'post_type', array( 'saai_kb' ) );

		$resolved = ( new \SAAI\Knowledge\Template_Loader() )->filter_template_include( '/theme/fallback.php' );

		$this->assertNotSame( '/theme/fallback.php', $resolved );
		$this->assertStringEndsWith( 'templates/classic/taxonomy-saai_category.php', $resolved );
	}

	/**
	 * Unlike the classic-theme path (filter_template_include(), above),
	 * WordPress's own block-theme template resolution (resolve_block_template(),
	 * see wp-includes/block-template.php) selects a candidate purely by slug
	 * hierarchy, with no awareness of query vars at all: nothing else stops
	 * the KB-branded taxonomy-saai_category block template from still
	 * rendering around the saai_faq results restrict_category_archive_to_kb()
	 * deliberately leaves unrestricted. exclude_kb_template_for_non_kb_query()
	 * must strip the plugin's own template from the get_block_templates()
	 * candidates so WordPress's own hierarchy falls through to the theme's
	 * instead.
	 */
	public function test_exclude_kb_template_for_non_kb_query_strips_own_template_for_explicit_scope() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$this->go_to( add_query_arg( 'post_type', 'saai_faq', get_term_link( $term_id, 'saai_category' ) ) );

		global $wp_query;
		$this->assertSame(
			'saai_faq',
			$wp_query->get( 'post_type' ),
			'test setup should have produced an explicit post_type scope'
		);

		$own_template   = $this->make_block_template_stub( 'taxonomy-saai_category', 'saai-knowledge' );
		$theme_template = $this->make_block_template_stub( 'taxonomy-saai_category', null );

		$result = ( new \SAAI\Knowledge\Template_Loader() )->exclude_kb_template_for_non_kb_query(
			array( $own_template, $theme_template ),
			array(),
			'wp_template'
		);

		$this->assertSame( array( $theme_template ), array_values( $result ) );
	}

	/**
	 * The plain, unrestricted default case — restrict_category_archive_to_kb()
	 * has already normalized post_type to saai_kb by the time template
	 * resolution runs, since pre_get_posts fires well before it — must keep
	 * the plugin's own template in the candidate list; only an explicit
	 * non-KB scope should strip it.
	 */
	public function test_exclude_kb_template_for_non_kb_query_keeps_own_template_for_default_scope() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$this->go_to( get_term_link( $term_id, 'saai_category' ) );

		global $wp_query;
		$wp_query->set( 'post_type', 'saai_kb' );

		$own_template = $this->make_block_template_stub( 'taxonomy-saai_category', 'saai-knowledge' );

		$result = ( new \SAAI\Knowledge\Template_Loader() )->exclude_kb_template_for_non_kb_query(
			array( $own_template ),
			array(),
			'wp_template'
		);

		$this->assertSame( array( $own_template ), $result );
	}

	/**
	 * The block-theme equivalent of test_filter_template_include_accepts_array_valued_kb_only_scope():
	 * a query scoped to post_type ['saai_kb'] (WordPress's array form) must
	 * keep the plugin's own candidate template, not be mistaken for an
	 * explicit non-KB scope.
	 */
	public function test_exclude_kb_template_for_non_kb_query_keeps_own_template_for_array_valued_kb_only_scope() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$this->go_to( get_term_link( $term_id, 'saai_category' ) );

		global $wp_query;
		$wp_query->set( 'post_type', array( 'saai_kb' ) );

		$own_template = $this->make_block_template_stub( 'taxonomy-saai_category', 'saai-knowledge' );

		$result = ( new \SAAI\Knowledge\Template_Loader() )->exclude_kb_template_for_non_kb_query(
			array( $own_template ),
			array(),
			'wp_template'
		);

		$this->assertSame( array( $own_template ), $result );
	}

	/**
	 * $template_type is get_block_templates()'s own object-type parameter
	 * ('wp_template' or 'wp_template_part'), not the template hierarchy kind
	 * (e.g. 'taxonomy') — a 'wp_template_part' query (e.g. resolving a
	 * header/footer part) must pass the candidate list through untouched.
	 */
	public function test_exclude_kb_template_for_non_kb_query_ignores_template_parts() {
		$own_template = $this->make_block_template_stub( 'single-saai_kb', 'saai-knowledge' );

		$result = ( new \SAAI\Knowledge\Template_Loader() )->exclude_kb_template_for_non_kb_query(
			array( $own_template ),
			array(),
			'wp_template_part'
		);

		$this->assertSame( array( $own_template ), $result );
	}

	/**
	 * Builds a minimal WP_Block_Template stub for exclude_kb_template_for_non_kb_query()
	 * tests — the class has no required constructor args and is a plain data
	 * object (public properties only), so this only needs to set the two
	 * properties that method reads.
	 *
	 * @param string      $slug   Template slug.
	 * @param string|null $plugin Registering plugin slug, or null for a theme-sourced template.
	 * @return \WP_Block_Template
	 */
	private function make_block_template_stub( string $slug, ?string $plugin ): \WP_Block_Template {
		$template         = new \WP_Block_Template();
		$template->slug   = $slug;
		$template->plugin = $plugin;

		return $template;
	}

	/**
	 * A site's own classic-theme taxonomy-saai_category.php override — already
	 * given priority by filter_template_include() — may deliberately want a
	 * broader post-type scope for this shared taxonomy, so the restriction
	 * must not be forced on it.
	 */
	public function test_restrict_category_archive_to_kb_defers_to_classic_theme_override() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$this->go_to( get_term_link( $term_id, 'saai_category' ) );

		// get_stylesheet_directory() doesn't resolve to a real, writable path
		// in the WP core test suite's bundled theme fixture, so point it at a
		// temp directory of our own via its filter instead of touching that path.
		$theme_dir      = rtrim( sys_get_temp_dir(), '/' ) . '/saai-template-loader-test-' . wp_generate_password( 8, false, false );
		$override_dir   = $theme_dir . '/saai-knowledge';
		$override_path  = $override_dir . '/taxonomy-saai_category.php';
		$stylesheet_dir = static function () use ( $theme_dir ) {
			return $theme_dir;
		};

		wp_mkdir_p( $override_dir );
		file_put_contents( $override_path, '<?php // Test theme override.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test-only fixture file, not a runtime code path.
		add_filter( 'stylesheet_directory', $stylesheet_dir );

		global $wp_query;
		$original_post_type = $wp_query->get( 'post_type' );

		( new \SAAI\Knowledge\Template_Loader() )->restrict_category_archive_to_kb( $wp_query );

		remove_filter( 'stylesheet_directory', $stylesheet_dir );
		wp_delete_file( $override_path );
		rmdir( $override_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test-only cleanup of the fixture directory created above.
		rmdir( $theme_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test-only cleanup of the fixture directory created above.

		$this->assertSame( $original_post_type, $wp_query->get( 'post_type' ) );
	}

	/**
	 * An add-on using the public saai_template filter to override the classic
	 * taxonomy-saai_category template — same priority as a theme file override
	 * (filter_template_include() applies this filter regardless of the
	 * source) — must also defer the post_type restriction.
	 */
	public function test_restrict_category_archive_to_kb_defers_to_saai_template_filter_override() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$this->go_to( get_term_link( $term_id, 'saai_category' ) );

		$override_path = tempnam( sys_get_temp_dir(), 'saai-template-' );

		$filter = static function () use ( $override_path ) {
			return $override_path;
		};
		add_filter( 'saai_template', $filter );

		global $wp_query;
		$original_post_type = $wp_query->get( 'post_type' );

		( new \SAAI\Knowledge\Template_Loader() )->restrict_category_archive_to_kb( $wp_query );

		remove_filter( 'saai_template', $filter );
		wp_delete_file( $override_path );

		$this->assertSame( $original_post_type, $wp_query->get( 'post_type' ) );
	}

	/**
	 * An add-on's saai_template callback that only customizes a completely
	 * unrelated slug (e.g. single-saai_faq), passing everything else
	 * through unchanged, must not be mistaken for a taxonomy override — the
	 * restriction still applies, since the actual, invoked filter leaves
	 * this taxonomy's resolution untouched.
	 */
	public function test_restrict_category_archive_to_kb_ignores_saai_template_filter_for_an_unrelated_slug() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );

		// Registered before go_to(): Template_Loader::register() already
		// hooks this on the live, bootstrap-registered instance, so it must
		// see the same filter state go_to() itself does — otherwise a
		// leftover "already saai_kb" value from that earlier, unfiltered
		// invocation could make this assertion pass by coincidence
		// regardless of what the explicit call below actually decides.
		$filter = static function ( $resolved, $slug ) {
			if ( 'single-saai_faq' === $slug ) {
				return '/some/other/template.php';
			}

			return $resolved;
		};
		add_filter( 'saai_template', $filter, 10, 2 );

		$this->go_to( get_term_link( $term_id, 'saai_category' ) );

		global $wp_query;

		( new \SAAI\Knowledge\Template_Loader() )->restrict_category_archive_to_kb( $wp_query );

		remove_filter( 'saai_template', $filter, 10 );

		$this->assertSame( 'saai_kb', $wp_query->get( 'post_type' ) );
	}

	/**
	 * A classic theme's term-specific taxonomy-saai_category-{term-slug}.php
	 * override — which WordPress's own classic taxonomy template hierarchy
	 * (get_taxonomy_template()) prefers over the generic
	 * taxonomy-saai_category.php — must also defer the post_type
	 * restriction, mirroring the block-theme branch's equivalent handling
	 * (plugin_taxonomy_template_wins()).
	 */
	public function test_restrict_category_archive_to_kb_defers_to_classic_term_specific_theme_override() {
		$term = self::factory()->term->create_and_get( array( 'taxonomy' => 'saai_category' ) );
		$this->go_to( get_term_link( $term, 'saai_category' ) );

		// get_stylesheet_directory() doesn't resolve to a real, writable path
		// in the WP core test suite's bundled theme fixture, so point it at a
		// temp directory of our own via its filter instead of touching that path.
		$theme_dir      = rtrim( sys_get_temp_dir(), '/' ) . '/saai-template-loader-test-' . wp_generate_password( 8, false, false );
		$override_dir   = $theme_dir . '/saai-knowledge';
		$override_path  = $override_dir . "/taxonomy-saai_category-{$term->slug}.php";
		$stylesheet_dir = static function () use ( $theme_dir ) {
			return $theme_dir;
		};

		wp_mkdir_p( $override_dir );
		file_put_contents( $override_path, '<?php // Test term-specific theme override.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test-only fixture file, not a runtime code path.
		add_filter( 'stylesheet_directory', $stylesheet_dir );

		global $wp_query;
		$original_post_type = $wp_query->get( 'post_type' );

		( new \SAAI\Knowledge\Template_Loader() )->restrict_category_archive_to_kb( $wp_query );

		remove_filter( 'stylesheet_directory', $stylesheet_dir );
		wp_delete_file( $override_path );
		rmdir( $override_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test-only cleanup of the fixture directory created above.
		rmdir( $theme_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test-only cleanup of the fixture directory created above.

		$this->assertSame( $original_post_type, $wp_query->get( 'post_type' ) );
	}

	/**
	 * The public saai_template filter must only be invoked once per request
	 * for a given classic taxonomy template, and only from
	 * filter_template_include() (template_include) — its documented,
	 * correctly-timed phase.
	 * restrict_category_archive_to_kb() (pre_get_posts) only checks
	 * has_filter() to decide whether to defer its own restriction; it never
	 * calls the filter itself (see classic_taxonomy_template_overridden()'s
	 * docblock for why: caching an early result across these two phases
	 * would either duplicate this evaluation or pre-empt it for a callback
	 * registered on a later hook — see the next test).
	 */
	public function test_saai_template_filter_only_applies_once_per_request_for_taxonomy() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$this->go_to( get_term_link( $term_id, 'saai_category' ) );

		$call_count = 0;
		$filter     = static function ( $resolved ) use ( &$call_count ) {
			++$call_count;
			return $resolved;
		};
		add_filter( 'saai_template', $filter );

		$loader = new \SAAI\Knowledge\Template_Loader();

		global $wp_query;
		$loader->restrict_category_archive_to_kb( $wp_query );
		$loader->filter_template_include( '' );

		remove_filter( 'saai_template', $filter );

		$this->assertSame( 1, $call_count );
	}

	/**
	 * An add-on registering the documented saai_template override on a hook
	 * later than pre_get_posts (e.g. wp/template_redirect — a normal
	 * pattern for a "final template override" hook) must still have it
	 * honored by filter_template_include(), even though
	 * restrict_category_archive_to_kb() already ran (and, finding no
	 * override registered yet, applied its own restriction).
	 */
	public function test_filter_template_include_honors_a_saai_template_filter_registered_after_the_query_was_scoped() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$this->go_to( get_term_link( $term_id, 'saai_category' ) );

		$loader = new \SAAI\Knowledge\Template_Loader();

		global $wp_query;
		$loader->restrict_category_archive_to_kb( $wp_query );

		$this->assertSame(
			'saai_kb',
			$wp_query->get( 'post_type' ),
			'no override was registered yet, so the restriction should still have applied'
		);

		// Simulates an add-on that only registers the override on a hook
		// later than pre_get_posts.
		$override_path = tempnam( sys_get_temp_dir(), 'saai-template-' );
		$filter        = static function () use ( $override_path ) {
			return $override_path;
		};
		add_filter( 'saai_template', $filter );

		$resolved_template = $loader->filter_template_include( 'fallback.php' );

		remove_filter( 'saai_template', $filter );
		wp_delete_file( $override_path );

		$this->assertSame( $override_path, $resolved_template );
	}

	/**
	 * The documented saai_kb_before_article/saai_kb_after_article insertion
	 * points (docs/DESIGN-HOOKS-API.md section 4) must actually fire around
	 * the block-theme article body, with the viewed KB post passed through.
	 */
	public function test_kb_article_content_hooks_fire_for_current_singular_post() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Hello from the article body.',
			)
		);
		$this->go_to( get_permalink( $post_id ) );
		// render_block()'s postId/postType context comes from the global
		// $post, which real requests only get from the block template
		// canvas's the_post() call before it renders the template content;
		// go_to() alone doesn't set it, so core/post-content would render
		// as the wrong (or no) post without this.
		the_post();

		// Template_Loader::register() is already hooked from the plugin's own
		// normal bootstrap (it's an active plugin for the whole test suite,
		// not something instantiated per-test) — adding a second registration
		// here via a fresh instance would double-fire the hooks below.
		$fired  = array();
		$before = static function ( $post ) use ( &$fired ) {
			$fired[] = array( 'before', $post->ID );
		};
		$after  = static function ( $post ) use ( &$fired ) {
			$fired[] = array( 'after', $post->ID );
		};
		add_action( 'saai_kb_before_article', $before );
		add_action( 'saai_kb_after_article', $after );

		$output = do_blocks( '<!-- wp:post-content /-->' );

		remove_action( 'saai_kb_before_article', $before );
		remove_action( 'saai_kb_after_article', $after );

		$this->assertSame( array( array( 'before', $post_id ), array( 'after', $post_id ) ), $fired );
		$this->assertStringContainsString( 'Hello from the article body.', $output );
	}

	/**
	 * The saai_kb_before_article action must fire early enough that an
	 * add-on registering a the_content filter from within it actually
	 * affects the article body being rendered — not just "before" in output
	 * order. This is exactly the classic template's do_action() ...
	 * the_content() sequencing; pre_render_block is what makes the
	 * block-theme side match it.
	 */
	public function test_saai_kb_before_article_fires_early_enough_to_affect_rendered_content() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Real body text.',
			)
		);
		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$content_filter = static function ( $content ) {
			return '<p id="saai-debug-marker">BEFORE-HOOK-WORKED</p>' . $content;
		};
		$before_action  = static function () use ( $content_filter ) {
			add_filter( 'the_content', $content_filter );
		};
		add_action( 'saai_kb_before_article', $before_action );

		$output = do_blocks( '<!-- wp:post-content /-->' );

		remove_action( 'saai_kb_before_article', $before_action );
		remove_filter( 'the_content', $content_filter );

		$this->assertStringContainsString( 'BEFORE-HOOK-WORKED', $output );
	}

	/**
	 * A saai_kb_before_article callback that echoes markup — the ordinary
	 * WordPress convention for an insertion-point action, as opposed to
	 * registering a filter — must have that output captured into
	 * do_blocks()'s return value directly before the article body, not
	 * written straight to the output stream (where it would land wherever
	 * do_blocks() happens to be assembling the surrounding template instead).
	 */
	public function test_saai_kb_before_article_echoed_output_is_captured_in_place() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Real body text.',
			)
		);
		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$before_action = static function () {
			echo '<div id="saai-echo-marker">ECHOED-BEFORE</div>';
		};
		add_action( 'saai_kb_before_article', $before_action );

		ob_start();
		$returned      = do_blocks( '<!-- wp:post-content /-->' );
		$direct_output = ob_get_clean();

		remove_action( 'saai_kb_before_article', $before_action );

		$this->assertSame( '', $direct_output, 'the echoed markup must not be written directly to the output stream' );
		$this->assertMatchesRegularExpression( '/ECHOED-BEFORE.*Real body text\./s', $returned );
	}

	/**
	 * A customized single-saai_kb.html could nest a Query/Post Template block
	 * (e.g. a "related articles" section) that also renders core/post-content
	 * once per listed post. is_singular( 'saai_kb' ) stays true throughout, so
	 * only comparing against the current global post (which core/post-template
	 * changes via the_post() for each item it renders) can tell those nested
	 * instances apart from the article actually being viewed.
	 */
	public function test_before_article_hook_does_not_fire_for_nested_post_content_in_a_query_loop() {
		$viewed_post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Viewed article body.',
			)
		);
		$other_post_id  = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Other article body.',
			)
		);
		$this->go_to( get_permalink( $viewed_post_id ) );
		the_post();

		$fired  = array();
		$before = static function ( $post ) use ( &$fired ) {
			$fired[] = $post->ID;
		};
		add_action( 'saai_kb_before_article', $before );

		$query_attrs = wp_json_encode(
			array(
				'query' => array(
					'postType' => 'saai_kb',
					'perPage'  => 10,
					'exclude'  => array( $viewed_post_id ),
					'inherit'  => false,
				),
			)
		);

		do_blocks(
			'<!-- wp:post-content /-->' .
			"<!-- wp:query {$query_attrs} -->" .
			'<div class="wp-block-query">' .
			'<!-- wp:post-template -->' .
			'<!-- wp:post-content /-->' .
			'<!-- /wp:post-template -->' .
			'</div>' .
			'<!-- /wp:query -->'
		);

		remove_action( 'saai_kb_before_article', $before );

		$this->assertSame( array( $viewed_post_id ), $fired, 'should fire exactly once, for the viewed post only' );
		$this->assertNotContains( $other_post_id, $fired );
	}

	/**
	 * A Query Loop the article body embeds might revisit the very post
	 * being viewed (an unusual "related articles" configuration, but not an
	 * impossible one) — its nested core/post-content then has the same
	 * global and queried post IDs as the primary article body, and
	 * get_the_ID()-based matching alone can't tell them apart. The action
	 * must still fire exactly once, for the outermost (primary) render only.
	 */
	public function test_before_article_hook_fires_only_once_when_a_nested_query_loop_revisits_the_viewed_post() {
		// The Query Loop must be embedded in the viewed post's own content
		// (not a sibling top-level block) to actually nest within the
		// outer post-content's render: sibling top-level blocks render
		// sequentially — the outer's pre_render_block/render_block_core/post-content
		// pair fully unwinds back to depth 0 before a sibling block ever
		// starts — so a sibling arrangement wouldn't exercise the depth
		// check at all (see test_before_article_hook_output_survives_a_query_loop_embedded_in_the_article_body()
		// for the same reasoning, applied to the before-hook buffer).
		$viewed_post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'placeholder',
			)
		);

		$query_attrs = wp_json_encode(
			array(
				'query' => array(
					'postType' => 'saai_kb',
					'perPage'  => 10,
					'inherit'  => false,
				),
			)
		);

		wp_update_post(
			array(
				'ID'           => $viewed_post_id,
				'post_content' => 'Viewed article body.' .
					"<!-- wp:query {$query_attrs} -->" .
					'<div class="wp-block-query">' .
					'<!-- wp:post-template -->' .
					'<!-- wp:post-content /-->' .
					'<!-- /wp:post-template -->' .
					'</div>' .
					'<!-- /wp:query -->',
			)
		);

		$this->go_to( get_permalink( $viewed_post_id ) );
		the_post();

		$fired  = array();
		$before = static function ( $post ) use ( &$fired ) {
			$fired[] = $post->ID;
		};
		add_action( 'saai_kb_before_article', $before );

		do_blocks( '<!-- wp:post-content /-->' );

		remove_action( 'saai_kb_before_article', $before );

		$this->assertSame(
			array( $viewed_post_id ),
			$fired,
			'should fire exactly once, even though the nested Query Loop revisits the same post'
		);
	}

	/**
	 * A customized single-KB block template could place a "related
	 * articles" Query Loop as a SIBLING of the primary core/post-content
	 * block (e.g. after it, rather than embedded inside the article's own
	 * content) — if that loop's results include the viewed article itself,
	 * $post_content_render_depth alone can't reject it: by the time the
	 * sibling block starts, the primary has already fully unwound the depth
	 * back to 0, so the sibling looks just as "outermost" as the primary
	 * was.
	 */
	public function test_before_article_hook_fires_only_once_when_a_sibling_query_loop_revisits_the_viewed_post() {
		$viewed_post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Viewed article body.',
			)
		);
		$this->go_to( get_permalink( $viewed_post_id ) );
		the_post();

		$fired  = array();
		$before = static function ( $post ) use ( &$fired ) {
			$fired[] = $post->ID;
		};
		add_action( 'saai_kb_before_article', $before );

		$query_attrs = wp_json_encode(
			array(
				'query' => array(
					'postType' => 'saai_kb',
					'perPage'  => 10,
					'inherit'  => false,
				),
			)
		);

		do_blocks(
			'<!-- wp:post-content /-->' .
			"<!-- wp:query {$query_attrs} -->" .
			'<div class="wp-block-query">' .
			'<!-- wp:post-template -->' .
			'<!-- wp:post-content /-->' .
			'<!-- /wp:post-template -->' .
			'</div>' .
			'<!-- /wp:query -->'
		);

		remove_action( 'saai_kb_before_article', $before );

		$this->assertSame(
			array( $viewed_post_id ),
			$fired,
			'should fire exactly once, even though a sibling Query Loop revisits the same post'
		);
	}

	/**
	 * A KB article that itself embeds a Query Loop (e.g. a "related
	 * articles" section written into its own body) renders that loop's
	 * nested core/post-content blocks *during* the outer article's own
	 * core/post-content render — in between fire_before_article_hook()
	 * buffering the before-hook output and wrap_kb_article_content()
	 * consuming it for the real, outer post. The nested, unrelated
	 * invocations must not consume (and thereby discard) that buffer before
	 * the outer one gets to it.
	 */
	public function test_before_article_hook_output_survives_a_query_loop_embedded_in_the_article_body() {
		$viewed_post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'placeholder',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Other article body.',
			)
		);

		// Excludes the viewed post itself: build_query_vars_from_query_block()
		// has no 'include' attribute, only 'exclude' (as post__not_in), so
		// this is the only way to keep the loop from also matching the
		// article whose own body it's embedded in.
		$query_attrs = wp_json_encode(
			array(
				'query' => array(
					'postType' => 'saai_kb',
					'perPage'  => 10,
					'exclude'  => array( $viewed_post_id ),
					'inherit'  => false,
				),
			)
		);

		wp_update_post(
			array(
				'ID'           => $viewed_post_id,
				'post_content' => 'Viewed article body.' .
					"<!-- wp:query {$query_attrs} -->" .
					'<div class="wp-block-query">' .
					'<!-- wp:post-template -->' .
					'<!-- wp:post-content /-->' .
					'<!-- /wp:post-template -->' .
					'</div>' .
					'<!-- /wp:query -->',
			)
		);

		$this->go_to( get_permalink( $viewed_post_id ) );
		the_post();

		$before = static function () {
			echo '<div id="saai-debug-marker">BEFORE-HOOK-OUTPUT</div>';
		};
		add_action( 'saai_kb_before_article', $before );

		$output = do_blocks( '<!-- wp:post-content /-->' );

		remove_action( 'saai_kb_before_article', $before );

		$this->assertMatchesRegularExpression( '/BEFORE-HOOK-OUTPUT.*Viewed article body\./s', $output );
	}

	/**
	 * Views outside a saai_kb singular post (e.g. a plain page) must not fire
	 * the KB article insertion points, even though they may render their own
	 * core/post-content block.
	 */
	public function test_kb_article_content_hooks_do_not_fire_for_unrelated_posts() {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => 'Page body.',
			)
		);
		$this->go_to( get_permalink( $page_id ) );
		the_post();

		$fired  = false;
		$before = static function () use ( &$fired ) {
			$fired = true;
		};
		add_action( 'saai_kb_before_article', $before );

		do_blocks( '<!-- wp:post-content /-->' );

		remove_action( 'saai_kb_before_article', $before );

		$this->assertFalse( $fired );
	}

	/**
	 * A classic theme calling the standard the_content() — whether from the
	 * bundled templates/classic/single-saai_kb.php, a theme's own
	 * saai-knowledge/single-saai_kb.php override, or via the saai_template
	 * filter — must still get the documented saai_kb_before_article/
	 * saai_kb_after_article insertion points (docs/DESIGN-HOOKS-API.md
	 * section 4), since none of those alternatives call the do_action()s
	 * themselves.
	 */
	public function test_kb_article_content_hooks_fire_via_the_content_for_classic_rendering() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Hello from the article body.',
			)
		);
		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$fired  = array();
		$before = static function ( $post ) use ( &$fired ) {
			$fired[] = array( 'before', $post->ID );
		};
		$after  = static function ( $post ) use ( &$fired ) {
			$fired[] = array( 'after', $post->ID );
		};
		add_action( 'saai_kb_before_article', $before );
		add_action( 'saai_kb_after_article', $after );

		$output = apply_filters( 'the_content', get_the_content() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'the_content' is WordPress core's own hook name, not this plugin's.

		remove_action( 'saai_kb_before_article', $before );
		remove_action( 'saai_kb_after_article', $after );

		$this->assertSame( array( array( 'before', $post_id ), array( 'after', $post_id ) ), $fired );
		$this->assertStringContainsString( 'Hello from the article body.', $output );
	}

	/**
	 * An SEO plugin, cache warmer, or similar callback deriving something
	 * (e.g. a meta description) from get_the_content() ahead of the main
	 * template — typically during wp_head, before the main query's Loop has
	 * even called the_post() — is a completely ordinary the_content() call
	 * on the same queried post, just outside the Loop. It must not be
	 * mistaken for the real, visible template render: the hooks must fire
	 * exactly once, attached to the template's own in-Loop call, not
	 * consumed by (and left invisible in) that earlier one.
	 */
	public function test_kb_article_content_hooks_via_the_content_fire_only_once_despite_an_earlier_unrelated_call() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Hello from the article body.',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		$fired  = array();
		$before = static function ( $post ) use ( &$fired ) {
			$fired[] = array( 'before', $post->ID );
		};
		$after  = static function ( $post ) use ( &$fired ) {
			$fired[] = array( 'after', $post->ID );
		};
		add_action( 'saai_kb_before_article', $before );
		add_action( 'saai_kb_after_article', $after );

		// Simulates an SEO plugin (or similar) deriving something from the
		// content before the main query's Loop has started — global $post is
		// already primed for a singular request even this early (see
		// render.php's own docblock on WP::register_globals()), so this call
		// is otherwise indistinguishable from the real one without
		// in_the_loop().
		$this->assertFalse( in_the_loop(), 'test setup should not have entered the Loop yet' );
		apply_filters( 'the_content', get_the_content() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'the_content' is WordPress core's own hook name, not this plugin's.

		// The main template's own call, inside the Loop.
		the_post();
		$this->assertTrue( in_the_loop() );
		apply_filters( 'the_content', get_the_content() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'the_content' is WordPress core's own hook name, not this plugin's.

		remove_action( 'saai_kb_before_article', $before );
		remove_action( 'saai_kb_after_article', $after );

		$this->assertSame( array( array( 'before', $post_id ), array( 'after', $post_id ) ), $fired );
	}

	/**
	 * The saai_kb_before_article action registering its own the_content
	 * filter from within an early, outside-the-Loop the_content() call (the
	 * scenario test_kb_article_content_hooks_via_the_content_fire_only_once_despite_an_earlier_unrelated_call
	 * guards against) must not affect that call's own output — proving the
	 * hook is actually being fired against the visible, in-Loop pass, not
	 * just that the $fired bookkeeping array reports one entry.
	 */
	public function test_saai_kb_before_article_does_not_affect_content_rendered_outside_the_loop() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Real body text.',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		$content_filter = static function ( $content ) {
			return '<p id="saai-debug-marker">BEFORE-HOOK-WORKED</p>' . $content;
		};
		$before_action  = static function () use ( $content_filter ) {
			add_filter( 'the_content', $content_filter );
		};
		add_action( 'saai_kb_before_article', $before_action );

		// Outside the Loop: must not fire, so the filter it would have
		// registered never gets a chance to run against this same content pass.
		$early_output = apply_filters( 'the_content', get_the_content() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'the_content' is WordPress core's own hook name, not this plugin's.

		the_post();
		$output = apply_filters( 'the_content', get_the_content() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'the_content' is WordPress core's own hook name, not this plugin's.

		remove_action( 'saai_kb_before_article', $before_action );
		remove_filter( 'the_content', $content_filter );

		$this->assertStringNotContainsString( 'BEFORE-HOOK-WORKED', $early_output );
		$this->assertStringContainsString( 'BEFORE-HOOK-WORKED', $output );
	}

	/**
	 * The saai_kb_before_article action must fire early enough (before
	 * wpautop, do_blocks, and the rest of the default the_content chain)
	 * that an add-on registering a the_content filter from within it
	 * actually affects the classic-rendered article — mirroring
	 * test_saai_kb_before_article_fires_early_enough_to_affect_rendered_content()
	 * for the block-theme mechanism.
	 */
	public function test_saai_kb_before_article_fires_early_enough_to_affect_classic_rendered_content() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Real body text.',
			)
		);
		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$content_filter = static function ( $content ) {
			return '<p id="saai-debug-marker">BEFORE-HOOK-WORKED</p>' . $content;
		};
		$before_action  = static function () use ( $content_filter ) {
			add_filter( 'the_content', $content_filter );
		};
		add_action( 'saai_kb_before_article', $before_action );

		$output = apply_filters( 'the_content', get_the_content() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'the_content' is WordPress core's own hook name, not this plugin's.

		remove_action( 'saai_kb_before_article', $before_action );
		remove_filter( 'the_content', $content_filter );

		$this->assertStringContainsString( 'BEFORE-HOOK-WORKED', $output );
	}

	/**
	 * Views outside a saai_kb singular post must not fire the KB article
	 * insertion points via the_content either, mirroring
	 * test_kb_article_content_hooks_do_not_fire_for_unrelated_posts() for
	 * the classic-theme mechanism.
	 */
	public function test_kb_article_content_hooks_via_the_content_do_not_fire_for_unrelated_posts() {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => 'Page body.',
			)
		);
		$this->go_to( get_permalink( $page_id ) );
		the_post();

		$fired  = false;
		$before = static function () use ( &$fired ) {
			$fired = true;
		};
		add_action( 'saai_kb_before_article', $before );

		apply_filters( 'the_content', get_the_content() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'the_content' is WordPress core's own hook name, not this plugin's.

		remove_action( 'saai_kb_before_article', $before );

		$this->assertFalse( $fired );
	}

	/**
	 * A block theme's core/post-content block applies the_content too (see
	 * render_block_core_post_content()), so wrap_kb_article_content_classic()
	 * — hooked on the_content unconditionally, not gated to classic themes —
	 * must recognize it's rendering as part of that block (via
	 * $post_content_render_depth) and defer to
	 * fire_before_article_hook()/wrap_kb_article_content() instead of firing
	 * a second time.
	 */
	public function test_kb_article_content_hooks_do_not_double_fire_through_a_post_content_block() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Hello from the article body.',
			)
		);
		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$fired  = array();
		$before = static function ( $post ) use ( &$fired ) {
			$fired[] = array( 'before', $post->ID );
		};
		$after  = static function ( $post ) use ( &$fired ) {
			$fired[] = array( 'after', $post->ID );
		};
		add_action( 'saai_kb_before_article', $before );
		add_action( 'saai_kb_after_article', $after );

		do_blocks( '<!-- wp:post-content /-->' );

		remove_action( 'saai_kb_before_article', $before );
		remove_action( 'saai_kb_after_article', $after );

		$this->assertSame( array( array( 'before', $post_id ), array( 'after', $post_id ) ), $fired );
	}

	/**
	 * A classic-theme KB article can itself embed a Query Loop whose
	 * core/post-content block renders an unrelated post via do_blocks(),
	 * partway through the same the_content chain
	 * buffer_before_article_hook_classic() (priority 1) and
	 * wrap_kb_article_content_classic() (PHP_INT_MAX) bracket. Without
	 * checking $post_content_render_depth, that nested block's own
	 * the_content call would consume the pending buffer and wrap the
	 * unrelated post instead of the primary article — the classic-theme
	 * counterpart of test_before_article_hook_output_survives_a_query_loop_embedded_in_the_article_body()
	 * for the block-theme mechanism.
	 */
	public function test_kb_article_content_hooks_via_the_content_survive_a_query_loop_embedded_in_the_article_body() {
		$viewed_post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'placeholder',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Other article body.',
			)
		);

		// Excludes the viewed post itself: build_query_vars_from_query_block()
		// has no 'include' attribute, only 'exclude' (as post__not_in), so
		// this is the only way to keep the loop from also matching the
		// article whose own body it's embedded in.
		$query_attrs = wp_json_encode(
			array(
				'query' => array(
					'postType' => 'saai_kb',
					'perPage'  => 10,
					'exclude'  => array( $viewed_post_id ),
					'inherit'  => false,
				),
			)
		);

		wp_update_post(
			array(
				'ID'           => $viewed_post_id,
				'post_content' => 'Viewed article body.' .
					"<!-- wp:query {$query_attrs} -->" .
					'<div class="wp-block-query">' .
					'<!-- wp:post-template -->' .
					'<!-- wp:post-content /-->' .
					'<!-- /wp:post-template -->' .
					'</div>' .
					'<!-- /wp:query -->',
			)
		);

		$this->go_to( get_permalink( $viewed_post_id ) );
		the_post();

		$before = static function () {
			echo '<div id="saai-debug-marker">BEFORE-HOOK-OUTPUT</div>';
		};
		add_action( 'saai_kb_before_article', $before );

		$output = apply_filters( 'the_content', get_the_content() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'the_content' is WordPress core's own hook name, not this plugin's.

		remove_action( 'saai_kb_before_article', $before );

		$this->assertMatchesRegularExpression( '/BEFORE-HOOK-OUTPUT.*Viewed article body\./s', $output );
	}

	/**
	 * When another plugin's pre_render_block callback short-circuits
	 * core/post-content (returns non-null), render_block() returns that
	 * value immediately without ever reaching WP_Block::render() — so
	 * render_block_core/post-content (wrap_kb_article_content(), which
	 * decrements $post_content_render_depth) never fires for it. If
	 * fire_before_article_hook() had already incremented the depth for that
	 * same block, the count would leak upward with no matching decrement,
	 * permanently suppressing wrap_kb_article_content_classic() (the
	 * classic-theme mechanism) for the rest of the request.
	 */
	public function test_post_content_render_depth_does_not_leak_when_a_block_is_short_circuited() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Hello from the article body.',
			)
		);
		$this->go_to( get_permalink( $post_id ) );
		the_post();

		// Registered at the default priority — i.e. before
		// fire_before_article_hook(), which register() hooks at PHP_INT_MAX
		// for exactly this reason — to simulate another plugin
		// short-circuiting core/post-content ahead of it.
		$short_circuit = static function ( $pre_render, array $parsed_block ) {
			if ( 'core/post-content' === ( $parsed_block['blockName'] ?? null ) ) {
				return 'SHORT-CIRCUITED';
			}

			return $pre_render;
		};
		add_filter( 'pre_render_block', $short_circuit, 10, 2 );

		$output = do_blocks( '<!-- wp:post-content /-->' );

		remove_filter( 'pre_render_block', $short_circuit, 10 );

		$this->assertSame( 'SHORT-CIRCUITED', $output, 'test setup should have short-circuited the block' );

		// A later, unrelated the_content() call (the classic-theme
		// mechanism) must still fire normally — proving the depth didn't
		// leak from the short-circuited block above.
		$fired  = false;
		$before = static function () use ( &$fired ) {
			$fired = true;
		};
		add_action( 'saai_kb_before_article', $before );

		apply_filters( 'the_content', get_the_content() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'the_content' is WordPress core's own hook name, not this plugin's.

		remove_action( 'saai_kb_before_article', $before );

		$this->assertTrue( $fired, 'post_content_render_depth must not have leaked from the short-circuited block' );
	}

	/**
	 * An interrupted article render (e.g. a saai_kb_before_article callback
	 * that throws, leaving fire_before_article_hook()'s depth increment with
	 * no matching decrement from wrap_kb_article_content()) must not suppress
	 * article hooks for the next article in the same PHP process.
	 * reset_article_content_hooks_state() must clear depth and buffers
	 * alongside the boolean flags on each new main query.
	 */
	public function test_reset_article_content_hooks_state_clears_depth_and_buffers() {
		$loader = new \SAAI\Knowledge\Template_Loader();

		// Corrupt state via reflection to simulate an interrupted render.
		$depth_prop = new \ReflectionProperty( \SAAI\Knowledge\Template_Loader::class, 'post_content_render_depth' );
		$depth_prop->setAccessible( true );
		$depth_prop->setValue( $loader, 1 );

		$before_prop = new \ReflectionProperty( \SAAI\Knowledge\Template_Loader::class, 'before_article_output' );
		$before_prop->setAccessible( true );
		$before_prop->setValue( $loader, '<stale/>' );

		$classic_before_prop = new \ReflectionProperty( \SAAI\Knowledge\Template_Loader::class, 'classic_before_article_output' );
		$classic_before_prop->setAccessible( true );
		$classic_before_prop->setValue( $loader, '<stale/>' );

		// Simulate the new main query arriving (pre_get_posts for the next article).
		global $wp_query;
		$loader->reset_article_content_hooks_state( $wp_query );

		$this->assertSame( 0, $depth_prop->getValue( $loader ), 'post_content_render_depth must be reset for the next article' );
		$this->assertNull( $before_prop->getValue( $loader ), 'before_article_output must be cleared for the next article' );
		$this->assertNull( $classic_before_prop->getValue( $loader ), 'classic_before_article_output must be cleared for the next article' );
	}

	/**
	 * When a theme provides its own block template for taxonomy-saai_category
	 * — an override that must suppress the post_type restriction —
	 * plugin_taxonomy_template_wins() must return false consistently,
	 * regardless of what order get_block_templates() happens to return the
	 * two same-slug templates in. A sort comparator that returns 0 for
	 * equal-priority slugs leaves the winner to PHP's unstable usort, which
	 * depends on input order and can flip between requests; the tiebreaker
	 * added in the comparator ensures the non-plugin (theme) template always
	 * wins when both resolve to the same slug-hierarchy priority.
	 */
	public function test_plugin_taxonomy_template_wins_sort_is_deterministic_with_same_slug_tiebreaker() {
		// Build two WP_Block_Template objects that both have the same
		// taxonomy-saai_category slug: one from the plugin, one from a
		// hypothetical theme that registered the same slug.
		$plugin_tpl        = new \WP_Block_Template();
		$plugin_tpl->slug  = 'taxonomy-saai_category';
		$plugin_tpl->plugin = 'saai-knowledge';

		$theme_tpl        = new \WP_Block_Template();
		$theme_tpl->slug  = 'taxonomy-saai_category';
		$theme_tpl->plugin = '';

		$loader = new \SAAI\Knowledge\Template_Loader();
		$method = new \ReflectionMethod( \SAAI\Knowledge\Template_Loader::class, 'plugin_taxonomy_template_wins' );
		$method->setAccessible( true );

		// Intercept get_block_templates() to return our two fixtures in each
		// possible order — the tiebreaker must produce the same result for both.
		$plugin_first = array( $plugin_tpl, $theme_tpl );
		$theme_first  = array( $theme_tpl, $plugin_tpl );

		// Use the term passed to plugin_taxonomy_template_wins() only as the
		// source of the slug candidates; we override the template list via the
		// get_block_templates filter to bypass the real registry.
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		$term    = get_term( $term_id, 'saai_category' );

		foreach ( array( $plugin_first, $theme_first ) as $order ) {
			$override = static function () use ( $order ) {
				return $order;
			};
			add_filter( 'get_block_templates', $override );

			$result = $method->invoke( $loader, $term );

			remove_filter( 'get_block_templates', $override );

			$this->assertFalse(
				$result,
				sprintf(
					'plugin_taxonomy_template_wins() must return false when a theme template exists for the same slug (tested with templates in order: %s)',
					implode( ', ', array_map( static fn( $t ) => "plugin={$t->plugin}", $order ) )
				)
			);
		}
	}
}
