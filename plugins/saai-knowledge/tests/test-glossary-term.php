<?php
/**
 * Tests for the Glossary_Term service (DefinedTerm JSON-LD and the
 * saai_glossary_after_definition insertion point).
 *
 * @package SAAI\Knowledge
 */

use SAAI\Knowledge\Glossary_Term;

/**
 * Class Test_Glossary_Term.
 */
class Test_Glossary_Term extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Glossary_Term
	 */
	private $glossary_term;

	/**
	 * Sets up the service under test.
	 */
	public function set_up() {
		parent::set_up();

		$this->glossary_term = new Glossary_Term();
		Glossary_Term::reset_state();
	}

	/**
	 * Creates a published glossary entry.
	 *
	 * @param array<string, mixed> $args Overrides for the post factory.
	 * @return \WP_Post
	 */
	private function create_term( array $args = array() ): \WP_Post {
		return self::factory()->post->create_and_get(
			array_merge(
				array(
					'post_type'   => 'saai_glossary',
					'post_status' => 'publish',
				),
				$args
			)
		);
	}

	/**
	 * The DefinedTerm schema should carry the term's name, description, URL,
	 * and the glossary archive as inDefinedTermSet.
	 */
	public function test_json_ld_builds_defined_term_schema() {
		$post = $this->create_term(
			array(
				'post_title'   => 'API',
				'post_excerpt' => 'Application Programming Interface.',
			)
		);

		$schema = $this->glossary_term->json_ld( $post );

		$this->assertSame( 'https://schema.org', $schema['@context'] );
		$this->assertSame( 'DefinedTerm', $schema['@type'] );
		$this->assertSame( 'API', $schema['name'] );
		$this->assertSame( 'Application Programming Interface.', $schema['description'] );
		$this->assertSame( get_permalink( $post ), $schema['url'] );
		$this->assertSame( get_post_type_archive_link( 'saai_glossary' ), $schema['inDefinedTermSet'] );
	}

	/**
	 * Without an excerpt, the description should fall back to the trimmed,
	 * tag-stripped definition body.
	 */
	public function test_json_ld_description_falls_back_to_trimmed_content() {
		$post = $this->create_term(
			array(
				'post_title'   => 'Widget',
				// The post factory fills in a default excerpt unless told
				// otherwise; this test exercises the no-excerpt fallback.
				'post_excerpt' => '',
				'post_content' => '<p>A small reusable UI component.</p>',
			)
		);

		$schema = $this->glossary_term->json_ld( $post );

		$this->assertSame( 'A small reusable UI component.', $schema['description'] );
	}

	/**
	 * HTML character references produced by the_title filters (& → &#038;)
	 * should be decoded to plain text in the name — JSON-LD contents are
	 * never HTML-entity-decoded by consumers.
	 */
	public function test_json_ld_decodes_html_entities_in_name() {
		$post = $this->create_term( array( 'post_title' => 'Q & A' ) );

		$schema = $this->glossary_term->json_ld( $post );

		$this->assertSame( 'Q & A', $schema['name'] );
	}

	/**
	 * The saai_structured_data filter should receive the defined-term type
	 * and be able to replace the schema; a non-array return should be
	 * ignored.
	 */
	public function test_json_ld_applies_structured_data_filter() {
		$post = $this->create_term( array( 'post_title' => 'Term' ) );

		$received_type = null;
		$filter        = function ( $schema, $schema_type ) use ( &$received_type ) {
			$received_type     = $schema_type;
			$schema['@custom'] = true;

			return $schema;
		};

		add_filter( 'saai_structured_data', $filter, 10, 2 );

		try {
			$schema = $this->glossary_term->json_ld( $post );
		} finally {
			remove_filter( 'saai_structured_data', $filter, 10 );
		}

		$this->assertSame( 'defined-term', $received_type );
		$this->assertTrue( $schema['@custom'] );

		add_filter( 'saai_structured_data', '__return_false' );

		try {
			$schema = $this->glossary_term->json_ld( $post );
		} finally {
			remove_filter( 'saai_structured_data', '__return_false' );
		}

		$this->assertSame( 'DefinedTerm', $schema['@type'] );
	}

	/**
	 * Structured data output should default to enabled and honor the
	 * settings option.
	 */
	public function test_structured_data_enabled_reads_settings() {
		$this->assertTrue( $this->glossary_term->structured_data_enabled() );

		update_option( 'saai_knowledge_settings', array( 'structured_data' => false ) );
		$this->assertFalse( $this->glossary_term->structured_data_enabled() );

		update_option( 'saai_knowledge_settings', array( 'structured_data' => true ) );
		$this->assertTrue( $this->glossary_term->structured_data_enabled() );
	}

	/**
	 * Renders a term's content through the real Loop (the_post()/the_content())
	 * so append_after_definition_hook()'s in_the_loop()/queried-post guards
	 * run exactly as they would on the front end.
	 *
	 * @return string The rendered content.
	 */
	private function render_content_in_the_loop(): string {
		$content = '';

		while ( have_posts() ) {
			the_post();
			$content .= get_the_content();
			// get_the_content() alone doesn't run the_content filters; apply()
			// them the same way the_content() would, through the real Loop.
			$content = apply_filters( 'the_content', $content );
		}

		return $content;
	}

	/**
	 * Viewing a glossary term should fire saai_glossary_after_definition
	 * exactly once, after the definition body, with the term post.
	 */
	public function test_append_after_definition_hook_fires_once_on_singular_view() {
		$post = $this->create_term(
			array(
				'post_title'   => 'Term',
				'post_content' => 'Definition body.',
			)
		);

		$this->go_to( get_permalink( $post ) );

		$received = array();
		$callback = function ( $hooked_post ) use ( &$received ) {
			$received[] = $hooked_post;
		};
		add_action( 'saai_glossary_after_definition', $callback );

		try {
			$content = $this->render_content_in_the_loop();
		} finally {
			remove_action( 'saai_glossary_after_definition', $callback );
		}

		$this->assertCount( 1, $received );
		$this->assertSame( $post->ID, $received[0]->ID );
		$this->assertStringContainsString( 'Definition body.', $content );
	}

	/**
	 * An unauthenticated visitor to a password-protected term must not be
	 * able to read its definition out of the page source via JSON-LD.
	 */
	public function test_output_structured_data_skips_password_protected_terms() {
		$post = $this->create_term(
			array(
				'post_title'    => 'Secret',
				'post_excerpt'  => 'Secret definition.',
				'post_password' => 'secret',
			)
		);

		$this->go_to( get_permalink( $post ) );

		ob_start();
		$this->glossary_term->output_structured_data();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * The hook must not fire for a password-protected term either — an
	 * add-on's callback (e.g. echoing linked products) must not print right
	 * after the password form for an unauthenticated visitor.
	 */
	public function test_append_after_definition_hook_does_not_fire_for_password_protected_terms() {
		$post = $this->create_term(
			array(
				'post_title'    => 'Secret',
				'post_content'  => 'Definition body.',
				'post_password' => 'secret',
			)
		);

		$this->go_to( get_permalink( $post ) );

		$fired    = false;
		$callback = function () use ( &$fired ) {
			$fired = true;
		};
		add_action( 'saai_glossary_after_definition', $callback );

		try {
			$content = $this->render_content_in_the_loop();
		} finally {
			remove_action( 'saai_glossary_after_definition', $callback );
		}

		$this->assertFalse( $fired );
		$this->assertStringNotContainsString( 'Definition body.', $content );
	}

	/**
	 * The hook must not fire for singular views of unrelated post types.
	 */
	public function test_append_after_definition_hook_does_not_fire_for_other_post_types() {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => 'Just a page.',
			)
		);

		$this->go_to( get_permalink( $page_id ) );

		$fired    = false;
		$callback = function () use ( &$fired ) {
			$fired = true;
		};
		add_action( 'saai_glossary_after_definition', $callback );

		try {
			$this->render_content_in_the_loop();
		} finally {
			remove_action( 'saai_glossary_after_definition', $callback );
		}

		$this->assertFalse( $fired );
	}

	/**
	 * A second, unrelated the_content() call outside the main query's Loop
	 * (e.g. an SEO plugin deriving a meta description ahead of the template)
	 * must not consume the one-shot hook before the real render.
	 */
	public function test_append_after_definition_hook_ignores_calls_outside_the_loop() {
		$post = $this->create_term(
			array(
				'post_title'   => 'Term',
				'post_content' => 'Definition body.',
			)
		);

		$this->go_to( get_permalink( $post ) );

		$received = array();
		$callback = function ( $hooked_post ) use ( &$received ) {
			$received[] = $hooked_post;
		};
		add_action( 'saai_glossary_after_definition', $callback );

		try {
			// Simulates a speculative the_content() call before the Loop starts.
			apply_filters( 'the_content', get_post_field( 'post_content', $post ) );

			$this->render_content_in_the_loop();
		} finally {
			remove_action( 'saai_glossary_after_definition', $callback );
		}

		$this->assertCount( 1, $received );
	}

	/**
	 * A block theme's core/post-content render never calls WP_Query::the_post(),
	 * so in_the_loop() stays false throughout and append_after_definition_hook()
	 * alone never fires there — fire_after_definition_hook_for_block_theme()
	 * must cover it instead.
	 */
	public function test_after_definition_hook_fires_for_a_block_theme_rendering_post_content() {
		$post = $this->create_term(
			array(
				'post_title'   => 'Term',
				'post_content' => 'Definition body.',
			)
		);

		$this->go_to( get_permalink( $post ) );
		// render_block()'s postId/postType context comes from the global
		// $post, which real requests only get from the block template
		// canvas's the_post() call before it renders the template content;
		// go_to() alone doesn't set it, so core/post-content would render as
		// the wrong (or no) post without this — same setup as
		// Template_Loader's equivalent KB article test.
		the_post();

		// Glossary_Term::register() is already hooked from the plugin's own
		// normal bootstrap (it's an active plugin for the whole test suite,
		// not something instantiated per-test) — adding a second
		// registration here via a fresh instance would double-fire the
		// hooks, same reasoning as Template_Loader's equivalent KB test.
		$received = array();
		$callback = function ( $hooked_post ) use ( &$received ) {
			$received[] = $hooked_post;
		};
		add_action( 'saai_glossary_after_definition', $callback );

		try {
			$output = do_blocks( '<!-- wp:post-content /-->' );
		} finally {
			remove_action( 'saai_glossary_after_definition', $callback );
		}

		$this->assertCount( 1, $received );
		$this->assertSame( $post->ID, $received[0]->ID );
		$this->assertStringContainsString( 'Definition body.', $output );
	}

	/**
	 * The block-theme path must not print an add-on's output right after a
	 * protected term's rendered password form either — same reasoning as
	 * test_append_after_definition_hook_does_not_fire_for_password_protected_terms().
	 */
	public function test_after_definition_hook_does_not_fire_for_password_protected_terms_in_a_block_theme() {
		$post = $this->create_term(
			array(
				'post_title'    => 'Secret',
				'post_content'  => 'Definition body.',
				'post_password' => 'secret',
			)
		);

		$this->go_to( get_permalink( $post ) );
		the_post();

		$fired    = false;
		$callback = function () use ( &$fired ) {
			$fired = true;
		};
		add_action( 'saai_glossary_after_definition', $callback );

		try {
			$output = do_blocks( '<!-- wp:post-content /-->' );
		} finally {
			remove_action( 'saai_glossary_after_definition', $callback );
		}

		$this->assertFalse( $fired );
		$this->assertStringNotContainsString( 'Definition body.', $output );
	}
}
