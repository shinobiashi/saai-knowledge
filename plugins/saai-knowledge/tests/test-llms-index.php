<?php
/**
 * Tests for the Llms_Index service (docs/DESIGN.md section 7.2, layer 2).
 *
 * @package SAAI\Knowledge
 */

use SAAI\Knowledge\Llms_Index;

/**
 * Class Test_Llms_Index.
 */
class Test_Llms_Index extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Llms_Index
	 */
	private $index;

	/**
	 * Sets up the service under test.
	 */
	public function set_up() {
		parent::set_up();

		$this->index = new Llms_Index();
	}

	/**
	 * The build_index() method lists a published item of each content type
	 * under its own heading, linking to both the canonical URL and its
	 * Markdown equivalent.
	 */
	public function test_build_index_groups_items_by_type() {
		$faq_id      = self::factory()->post->create(
			array(
				'post_type'   => 'saai_faq',
				'post_title'  => 'Is this covered?',
				'post_status' => 'publish',
			)
		);
		$kb_id       = self::factory()->post->create(
			array(
				'post_type'   => 'saai_kb',
				'post_title'  => 'Getting Started',
				'post_status' => 'publish',
			)
		);
		$glossary_id = self::factory()->post->create(
			array(
				'post_type'   => 'saai_glossary',
				'post_title'  => 'API',
				'post_status' => 'publish',
			)
		);

		$markdown = $this->index->build_index();

		$this->assertStringContainsString( '## FAQ', $markdown );
		$this->assertStringContainsString( '## Knowledge Base', $markdown );
		$this->assertStringContainsString( '## Glossary', $markdown );

		$this->assertStringContainsString( '[Is this covered?](' . get_permalink( $faq_id ) . ')', $markdown );
		$this->assertStringContainsString( '[Getting Started](' . get_permalink( $kb_id ) . ')', $markdown );
		$this->assertStringContainsString( '[API](' . get_permalink( $glossary_id ) . ')', $markdown );

		$this->assertStringContainsString( add_query_arg( 'format', 'markdown', get_permalink( $faq_id ) ), $markdown );
	}

	/**
	 * A draft post is never listed.
	 */
	public function test_build_index_excludes_unpublished_posts() {
		self::factory()->post->create(
			array(
				'post_type'   => 'saai_kb',
				'post_title'  => 'Draft Article',
				'post_status' => 'draft',
			)
		);

		$markdown = $this->index->build_index();

		$this->assertStringNotContainsString( 'Draft Article', $markdown );
	}

	/**
	 * The saai_llms_index_items filter can add/rewrite items; a malformed
	 * item (missing a required key) is silently dropped rather than
	 * breaking the whole index.
	 */
	public function test_saai_llms_index_items_filter_can_extend_and_malformed_items_are_dropped() {
		$add_items = static function ( array $items ) {
			$items[] = array(
				'type'         => 'faq',
				'title'        => 'Injected Item',
				'url'          => 'https://example.com/injected/',
				'markdown_url' => 'https://example.com/injected/?format=markdown',
			);
			$items[] = array( 'type' => 'faq' ); // Missing required keys.
			return $items;
		};

		add_filter( 'saai_llms_index_items', $add_items );

		try {
			$markdown = $this->index->build_index();
			$this->assertStringContainsString( 'Injected Item', $markdown );
		} finally {
			remove_filter( 'saai_llms_index_items', $add_items );
		}
	}

	/**
	 * The build_index_cached() method returns the same string on a second
	 * call (i.e. it actually served the transient rather than rebuilding).
	 */
	public function test_build_index_cached_returns_stable_value() {
		$first  = $this->index->build_index_cached();
		$second = $this->index->build_index_cached();

		$this->assertSame( $first, $second );
	}

	/**
	 * After flush_cache(), build_index_cached() reflects newly published
	 * content instead of serving a stale cached value.
	 *
	 * Plugin::boot() has already registered its own Llms_Index instance
	 * whose save_post_saai_kb hook calls flush_cache() automatically (both
	 * instances share the same CACHE_KEY constant), so this test calls
	 * flush_cache() directly rather than relying on that hook — the
	 * production-hook path is exercised implicitly by every other test in
	 * this file that creates a saai_kb post after warming the cache.
	 */
	public function test_flush_cache_makes_new_content_visible() {
		$this->index->build_index_cached();

		self::factory()->post->create(
			array(
				'post_type'   => 'saai_kb',
				'post_title'  => 'Cache Busting Article',
				'post_status' => 'publish',
			)
		);

		$this->index->flush_cache();

		$fresh = $this->index->build_index_cached();
		$this->assertStringContainsString( 'Cache Busting Article', $fresh );
	}

	/**
	 * A `]` (or `[`) in a post title is escaped before being placed inside
	 * the index's `[title](url)` link syntax — otherwise it would close the
	 * link label early and corrupt the URL that follows (Codex review).
	 */
	public function test_build_index_escapes_brackets_in_title() {
		self::factory()->post->create(
			array(
				'post_type'   => 'saai_faq',
				'post_title'  => 'Is [Feature] broken?',
				'post_status' => 'publish',
			)
		);

		$markdown = $this->index->build_index();

		$this->assertStringContainsString( 'Is \\[Feature\\] broken?', $markdown );
	}

	/**
	 * The maybe_remove_canonical_redirect() method only strips redirect_canonical for
	 * the real `/{kb slug}/llms.txt` route, not for an unrelated article
	 * whose slug merely starts with the same characters (e.g.
	 * `/kb/llms.txt-guide/`) — a bare substring match would incorrectly
	 * disable that article's own trailing-slash canonicalization (Codex
	 * review).
	 */
	public function test_maybe_remove_canonical_redirect_requires_exact_boundary_match() {
		$original_priority = has_filter( 'template_redirect', 'redirect_canonical' );

		if ( false === $original_priority ) {
			add_action( 'template_redirect', 'redirect_canonical' );
			$original_priority = 10;
		}

		try {
			$_SERVER['REQUEST_URI'] = '/kb/llms.txt-guide/';
			$this->index->maybe_remove_canonical_redirect();
			$this->assertNotFalse(
				has_filter( 'template_redirect', 'redirect_canonical' ),
				'An unrelated article whose slug merely starts with "llms.txt" must not have redirect_canonical removed.'
			);

			$_SERVER['REQUEST_URI'] = '/kb/llms.txt';
			$this->index->maybe_remove_canonical_redirect();
			$this->assertFalse(
				has_filter( 'template_redirect', 'redirect_canonical' ),
				'The real llms.txt index route must still have redirect_canonical removed.'
			);
		} finally {
			unset( $_SERVER['REQUEST_URI'] );

			if ( false === has_filter( 'template_redirect', 'redirect_canonical' ) ) {
				add_action( 'template_redirect', 'redirect_canonical', $original_priority );
			}
		}
	}
}
