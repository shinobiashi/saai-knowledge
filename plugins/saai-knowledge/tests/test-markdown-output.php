<?php
/**
 * Tests for the Markdown_Output service (docs/DESIGN.md section 7.3).
 *
 * @package SAAI\Knowledge
 */

use SAAI\Knowledge\Markdown_Output;

/**
 * Class Test_Markdown_Output.
 */
class Test_Markdown_Output extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Markdown_Output
	 */
	private $service;

	/**
	 * Sets up the service under test.
	 */
	public function set_up() {
		parent::set_up();

		$this->service = new Markdown_Output();
	}

	/**
	 * The register_query_var() method whitelists 'format' so get_query_var() can see it.
	 */
	public function test_register_query_var_adds_format() {
		$this->assertSame( array( 'format' ), $this->service->register_query_var( array() ) );
	}

	/**
	 * The maybe_serve() method must not hand out a password-protected post's content:
	 * WordPress's own password-protection UI (get_the_password_form())
	 * only replaces the *rendered HTML* body, so without an explicit check
	 * this endpoint would otherwise serve the real content to anyone with
	 * the URL, regardless of the password (R1-1).
	 */
	public function test_maybe_serve_skips_password_protected_post() {
		add_filter( 'query_vars', array( $this->service, 'register_query_var' ) );

		$post_id = self::factory()->post->create(
			array(
				'post_type'     => 'saai_kb',
				'post_content'  => 'Secret body.',
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);

		$this->go_to( add_query_arg( 'format', 'markdown', get_permalink( $post_id ) ) );

		$this->expectOutputString( '' );
		$this->service->maybe_serve();
	}

	/**
	 * The render() method emits an H1 title, a URL meta line, and the converted body.
	 */
	public function test_render_includes_title_url_and_content() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_title'   => 'How to Reset a Password',
				'post_content' => '<!-- wp:paragraph --><p>Click reset.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);
		$post    = get_post( $post_id );

		$markdown = $this->service->render( $post );

		$this->assertStringStartsWith( "# How to Reset a Password\n", $markdown );
		$this->assertStringContainsString( '**URL:** ' . get_permalink( $post ), $markdown );
		$this->assertStringContainsString( 'Click reset.', $markdown );
	}

	/**
	 * The render() method must not leak the rendered post's global postdata (the
	 * global $post, and the rest of setup_postdata()'s globals) into
	 * whatever runs after it — this method is public, and a caller other
	 * than maybe_serve() (which currently always exit()s right after) would
	 * otherwise have the last rendered post silently bleed into its own
	 * global state (Copilot review).
	 */
	public function test_render_restores_previous_global_postdata() {
		$other_post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$other_post    = get_post( $other_post_id );

		$GLOBALS['post'] = $other_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- establishing the "already-current post" state this test asserts render() must restore.
		setup_postdata( $other_post );

		$target_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Body.',
				'post_status'  => 'publish',
			)
		);

		try {
			$this->service->render( get_post( $target_id ) );

			$this->assertSame( $other_post_id, $GLOBALS['post']->ID );
			$this->assertSame( $other_post_id, $GLOBALS['id'] );
		} finally {
			wp_reset_postdata();
		}
	}

	/**
	 * A title containing '[...](...)' would otherwise become an actual
	 * link once placed in the H1 line, since CommonMark headings parse
	 * inline Markdown (Codex review).
	 */
	public function test_render_escapes_markdown_syntax_in_title() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'saai_kb',
				'post_title'  => 'Guide [official](https://example.invalid)',
				'post_status' => 'publish',
			)
		);

		$markdown = $this->service->render( get_post( $post_id ) );

		$this->assertStringStartsWith( '# Guide \\[official\\](https://example.invalid)', $markdown );
	}

	/**
	 * The render() method lists a saai_kb/saai_faq post's saai_category
	 * terms as a Category meta line.
	 */
	public function test_render_includes_category_for_kb_and_faq() {
		$term    = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'saai_category',
				'name'     => 'Billing',
			)
		);
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_faq',
				'post_content' => 'Answer text.',
				'post_status'  => 'publish',
			)
		);
		wp_set_object_terms( $post_id, array( $term->term_id ), 'saai_category' );

		$markdown = $this->service->render( get_post( $post_id ) );

		$this->assertStringContainsString( '**Category:** Billing', $markdown );
	}

	/**
	 * A term name containing Markdown syntax characters is escaped the same
	 * way a post title is, so it can't break or be misread as formatting
	 * once placed in the Category meta line (Copilot review).
	 */
	public function test_render_escapes_markdown_syntax_in_category_name() {
		$term    = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'saai_category',
				'name'     => '[Legacy] *Billing*',
			)
		);
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_faq',
				'post_content' => 'Answer text.',
				'post_status'  => 'publish',
			)
		);
		wp_set_object_terms( $post_id, array( $term->term_id ), 'saai_category' );

		$markdown = $this->service->render( get_post( $post_id ) );

		$this->assertStringContainsString( '**Category:** \\[Legacy\\] \\*Billing\\*', $markdown );
	}

	/**
	 * The render() method does not add a Category line for saai_glossary,
	 * which doesn't use saai_category (docs/DESIGN.md section 3.2).
	 */
	public function test_render_omits_category_for_glossary() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_glossary',
				'post_content' => 'A definition.',
				'post_status'  => 'publish',
			)
		);

		$markdown = $this->service->render( get_post( $post_id ) );

		$this->assertStringNotContainsString( '**Category:**', $markdown );
	}

	/**
	 * The saai_markdown_output filter can rewrite the final document.
	 */
	public function test_saai_markdown_output_filter_can_rewrite_result() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Body.',
				'post_status'  => 'publish',
			)
		);

		$override = static function ( $markdown, $post ) {
			return 'REPLACED:' . $post->ID;
		};

		add_filter( 'saai_markdown_output', $override, 10, 2 );

		try {
			$markdown = $this->service->render( get_post( $post_id ) );
			$this->assertSame( 'REPLACED:' . $post_id, $markdown );
		} finally {
			remove_filter( 'saai_markdown_output', $override );
		}
	}

	/**
	 * The render_cached() method returns identical content across calls
	 * (i.e. the transient round-trip doesn't corrupt the value), and an
	 * edit is reflected once flush_cache() runs — including two edits
	 * within the same second, which a modified-time-keyed cache would have
	 * missed (Codex review).
	 */
	public function test_render_cached_reflects_post_updates_via_flush_cache() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Original body.',
				'post_status'  => 'publish',
			)
		);
		$post    = get_post( $post_id );

		$first = $this->service->render_cached( $post );
		$this->assertStringContainsString( 'Original body.', $first );
		$this->assertSame( $first, $this->service->render_cached( $post ) );

		$this->service->flush_cache( $post_id );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Updated body.',
			)
		);
		$this->service->flush_cache( $post_id );

		$second = $this->service->render_cached( get_post( $post_id ) );
		$this->assertStringContainsString( 'Updated body.', $second );
	}

	/**
	 * The flush_cache() method is wired to save_post_saai_kb, so an ordinary
	 * wp_update_post() call alone (without a manual flush_cache() call)
	 * already invalidates that post's cached Markdown.
	 */
	public function test_save_post_hook_invalidates_cache() {
		$this->service->register();

		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Original body.',
				'post_status'  => 'publish',
			)
		);

		$this->service->render_cached( get_post( $post_id ) );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Updated body.',
			)
		);

		$second = $this->service->render_cached( get_post( $post_id ) );
		$this->assertStringContainsString( 'Updated body.', $second );
	}

	/**
	 * A bump of saai_dict_generation (Autolinker's own dictionary-cache
	 * invalidation counter, bumped e.g. on a glossary term rename/delete)
	 * also invalidates this cache, since a KB/FAQ page's rendered Markdown
	 * includes whatever glossary tooltip links Autolinker inserted into it
	 * (Codex review).
	 */
	public function test_render_cached_is_invalidated_by_dictionary_generation_change() {
		update_option( 'saai_dict_generation', 1 );

		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Original body.',
				'post_status'  => 'publish',
			)
		);

		$first = $this->service->render_cached( get_post( $post_id ) );
		$this->assertSame( $first, $this->service->render_cached( get_post( $post_id ) ) );

		$override = static function ( $markdown, $post ) {
			return 'REBUILT-AFTER-DICTIONARY-CHANGE:' . $post->ID;
		};
		add_filter( 'saai_markdown_output', $override, 10, 2 );
		update_option( 'saai_dict_generation', 2 );

		try {
			$second = $this->service->render_cached( get_post( $post_id ) );
			$this->assertSame( 'REBUILT-AFTER-DICTIONARY-CHANGE:' . $post_id, $second );
		} finally {
			remove_filter( 'saai_markdown_output', $override );
		}
	}

	/**
	 * A request carrying any cookie at all — not just a logged-in
	 * auth cookie — never reads or writes the shared transient, so a
	 * viewer-dependent render (a cart, a language preference, ...) can't
	 * leak into what a cookie-less visitor (this endpoint's actual target
	 * audience: AI/RAG crawlers) sees (Codex review: an earlier version of
	 * this check only looked at is_user_logged_in()).
	 */
	public function test_render_cached_bypasses_shared_cache_for_any_cookie() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Public body.',
				'post_status'  => 'publish',
			)
		);

		$override = static function ( $markdown, $post ) {
			return 'SESSION-SPECIFIC-CONTENT:' . $post->ID;
		};

		add_filter( 'saai_markdown_output', $override, 10, 2 );

		$_COOKIE['saai_test_session'] = '1';

		try {
			$cookied_render = $this->service->render_cached( get_post( $post_id ) );
			$this->assertSame( 'SESSION-SPECIFIC-CONTENT:' . $post_id, $cookied_render );
		} finally {
			remove_filter( 'saai_markdown_output', $override );
			unset( $_COOKIE['saai_test_session'] );
		}

		$anonymous_render = $this->service->render_cached( get_post( $post_id ) );
		$this->assertStringNotContainsString( 'SESSION-SPECIFIC-CONTENT', $anonymous_render );
		$this->assertStringContainsString( 'Public body.', $anonymous_render );
	}
}
