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
	 * Template_Loader::register() only hooks registration on block themes, and
	 * the WP core test suite's default theme is classic, so bootstrap never
	 * triggers it here. Call register_block_templates() directly instead.
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

		$taxonomy = $registry->get_registered( 'saai-knowledge//taxonomy-saai_category' );
		$this->assertNotNull( $taxonomy, 'taxonomy-saai_category block template should be registered' );
		$this->assertStringContainsString( 'saai-knowledge/kb-sidebar', $taxonomy->content );
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
}
