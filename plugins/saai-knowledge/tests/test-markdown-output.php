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
	 * (i.e. the transient round-trip doesn't corrupt the value) and
	 * reflects an edit once the post's modified time changes (its cache
	 * key embeds that timestamp).
	 */
	public function test_render_cached_reflects_post_updates() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => 'Original body.',
				'post_status'  => 'publish',
			)
		);

		$first = $this->service->render_cached( get_post( $post_id ) );
		$this->assertStringContainsString( 'Original body.', $first );
		$this->assertSame( $first, $this->service->render_cached( get_post( $post_id ) ) );

		// Write the new content and a deliberately distinct post_modified
		// straight to the DB (bypassing wp_update_post(), which recomputes
		// post_modified from the current time itself — indistinguishable
		// from the first render_cached() call above at test speed) so the
		// cache key change this asserts on is deterministic rather than a
		// same-second race.
		global $wpdb;
		$new_modified = gmdate( 'Y-m-d H:i:s', time() + 60 );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_content'      => 'Updated body.',
				'post_modified'     => $new_modified,
				'post_modified_gmt' => $new_modified,
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );

		$second = $this->service->render_cached( get_post( $post_id ) );
		$this->assertStringContainsString( 'Updated body.', $second );
	}
}
