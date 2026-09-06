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

		$this->assertStringContainsString( '[Is this covered?](<' . get_permalink( $faq_id ) . '>)', $markdown );
		$this->assertStringContainsString( '[Getting Started](<' . get_permalink( $kb_id ) . '>)', $markdown );
		$this->assertStringContainsString( '[API](<' . get_permalink( $glossary_id ) . '>)', $markdown );

		$this->assertStringContainsString( '<' . add_query_arg( 'format', 'markdown', get_permalink( $faq_id ) ) . '>', $markdown );
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
	 * A `saai_llms_index_items` callback that honors the required keys but
	 * violates their documented string type (or hands back an empty
	 * string) is dropped rather than reaching the concatenation that builds
	 * each line — an unchecked non-string `url` there would trigger a PHP
	 * "Array to string conversion" notice, or an empty one would produce a
	 * broken `[title]()` link (Copilot review).
	 */
	public function test_saai_llms_index_items_filter_drops_items_with_non_string_or_empty_values() {
		$add_items = static function ( array $items ) {
			$items[] = array(
				'type'         => 'faq',
				'title'        => 'Array URL Item',
				'url'          => array( 'not', 'a', 'string' ),
				'markdown_url' => 'https://example.com/array-url/?format=markdown',
			);
			$items[] = array(
				'type'         => 'faq',
				'title'        => 'Empty URL Item',
				'url'          => '',
				'markdown_url' => 'https://example.com/empty-url/?format=markdown',
			);
			return $items;
		};

		add_filter( 'saai_llms_index_items', $add_items );

		try {
			$markdown = $this->index->build_index();
			$this->assertStringNotContainsString( 'Array URL Item', $markdown );
			$this->assertStringNotContainsString( 'Empty URL Item', $markdown );
		} finally {
			remove_filter( 'saai_llms_index_items', $add_items );
		}
	}

	/**
	 * A `saai_llms_index_items` callback's url/markdown_url are wrapped in
	 * CommonMark's `<...>` angle-bracket form the same way
	 * Markdown_Converter wraps an href/src — an unbalanced ')' in a
	 * third-party-supplied URL would otherwise close the link early
	 * (Copilot review).
	 */
	public function test_saai_llms_index_items_filter_urls_with_parentheses_are_wrapped_in_angle_brackets() {
		$add_items = static function ( array $items ) {
			$items[] = array(
				'type'         => 'faq',
				'title'        => 'Paren URL Item',
				'url'          => 'https://example.com/wiki/Foo_(bar)',
				'markdown_url' => 'https://example.com/wiki/Foo_(bar)?format=markdown',
			);
			return $items;
		};

		add_filter( 'saai_llms_index_items', $add_items );

		try {
			$markdown = $this->index->build_index();
			$this->assertStringContainsString(
				'[Paren URL Item](<https://example.com/wiki/Foo_(bar)>) ([Markdown](<https://example.com/wiki/Foo_(bar)?format=markdown>))',
				$markdown
			);
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
	 * A force-delete (wp_delete_post( $id, true ) — REST's force=true,
	 * `wp post delete --force`) skips wp_trash_post() entirely, so
	 * trashed_post never fires; deleted_post must invalidate the cache too,
	 * or the deleted item keeps appearing in the index for up to CACHE_TTL
	 * (Codex review).
	 */
	public function test_deleted_post_hook_invalidates_cache() {
		$this->index->register();

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'saai_kb',
				'post_title'  => 'Force Deleted Article',
				'post_status' => 'publish',
			)
		);

		$this->index->build_index_cached();

		wp_delete_post( $post_id, true );

		$fresh = $this->index->build_index_cached();
		$this->assertStringNotContainsString( 'Force Deleted Article', $fresh );
	}

	/**
	 * The maybe_flush_cache_on_slug_change() method flushes the cache when slug_kb (or
	 * slug_faq/slug_glossary) actually changes — without it, the cached
	 * index keeps linking to the old, now-404ing URLs for up to CACHE_TTL
	 * after a slug change (Codex review).
	 */
	public function test_maybe_flush_cache_on_slug_change_flushes_only_on_a_real_slug_change() {
		$this->index->build_index_cached();

		$this->index->maybe_flush_cache_on_slug_change(
			array(
				'slug_kb'            => 'kb',
				'autolink_max_links' => 20,
			),
			array(
				'slug_kb'            => 'kb',
				'autolink_max_links' => 5,
			)
		);
		$this->assertIsString( get_transient( 'saai_llms_index' ), 'An unrelated field change must not flush the cache.' );

		$this->index->maybe_flush_cache_on_slug_change(
			array( 'slug_kb' => 'kb' ),
			array( 'slug_kb' => 'articles' )
		);
		$this->assertFalse( get_transient( 'saai_llms_index' ), 'A slug_kb change must flush the cache.' );
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
	 * The site name (get_bloginfo('name')) placed in the index's own H1 is
	 * escaped the same way an item title is — a name containing '[', '*',
	 * '`', etc. would otherwise break or be misread as formatting in that
	 * heading (Copilot review).
	 */
	public function test_build_index_escapes_site_name_in_heading() {
		update_option( 'blogname', '[Legacy] *Docs*' );

		$markdown = $this->index->build_index();

		$this->assertStringStartsWith( '# \\[Legacy\\] \\*Docs\\* — Knowledge Index', $markdown );
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

			// A request whose *query string* merely contains the same
			// characters (not its path) must not match either — only the
			// path component is checked (Copilot review).
			$_SERVER['REQUEST_URI'] = '/search/?q=/kb/llms.txt';
			$this->index->maybe_remove_canonical_redirect();
			$this->assertNotFalse(
				has_filter( 'template_redirect', 'redirect_canonical' ),
				'A request whose query string merely contains the llms.txt path must not have redirect_canonical removed.'
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

	/**
	 * A non-ASCII kb_slug is stored (by Settings::sanitize_slug()) as the
	 * literal percent-encoded string sanitize_title() produces (e.g.
	 * "%e3%81%8b%e3%81%aa" for "かな"), and the browser's REQUEST_URI for a
	 * real request carries the same percent-encoded bytes. Sanitizing that
	 * REQUEST_URI with sanitize_text_field() would strip every %XX octet
	 * (core's _sanitize_text_fields()) before it's compared against the
	 * pattern built from kb_slug(), breaking the match and leaving
	 * redirect_canonical in place — silently 404/redirecting away every
	 * non-ASCII-slugged site's llms.txt route (Codex review; regression
	 * from an earlier unsanitized-input hardening pass).
	 */
	public function test_maybe_remove_canonical_redirect_matches_a_non_ascii_slug() {
		$original_priority = has_filter( 'template_redirect', 'redirect_canonical' );
		$added_canonical   = false;

		if ( false === $original_priority ) {
			add_action( 'template_redirect', 'redirect_canonical' );
			$original_priority = 10;
			$added_canonical   = true;
		}

		$encoded_slug = sanitize_title( 'かな' );

		update_option(
			'saai_knowledge_settings',
			array( 'slug_kb' => $encoded_slug )
		);

		$original_request_uri = $_SERVER['REQUEST_URI'] ?? null;

		try {
			$_SERVER['REQUEST_URI'] = '/' . $encoded_slug . '/llms.txt';
			$this->index->maybe_remove_canonical_redirect();
			$this->assertFalse(
				has_filter( 'template_redirect', 'redirect_canonical' ),
				'A non-ASCII kb slug must still match its own llms.txt route.'
			);
		} finally {
			if ( null === $original_request_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $original_request_uri;
			}

			// Restore the exact state this test found, not just "present":
			// if redirect_canonical was already missing before this test
			// added it (only to have something for maybe_remove_canonical_redirect()
			// to remove), leaving it registered afterward would be a state
			// leak into whichever test runs next (Copilot review).
			if ( $added_canonical ) {
				remove_action( 'template_redirect', 'redirect_canonical', $original_priority );
			} elseif ( false === has_filter( 'template_redirect', 'redirect_canonical' ) ) {
				add_action( 'template_redirect', 'redirect_canonical', $original_priority );
			}
		}
	}

	/**
	 * The remove_filter() call's $priority defaults to 10 when omitted; if
	 * redirect_canonical happens to be registered at a different priority
	 * in a given environment (another plugin/theme re-hooking it), an
	 * unqualified remove_filter() call would silently fail to match it.
	 * maybe_remove_canonical_redirect() must look up the actual registered
	 * priority via has_filter() first (Copilot review).
	 */
	public function test_maybe_remove_canonical_redirect_matches_a_non_default_priority() {
		remove_filter( 'template_redirect', 'redirect_canonical', 10 );
		add_action( 'template_redirect', 'redirect_canonical', 20 );

		try {
			$_SERVER['REQUEST_URI'] = '/kb/llms.txt';
			$this->index->maybe_remove_canonical_redirect();

			$this->assertFalse( has_filter( 'template_redirect', 'redirect_canonical' ) );
		} finally {
			unset( $_SERVER['REQUEST_URI'] );

			if ( false === has_filter( 'template_redirect', 'redirect_canonical' ) ) {
				add_action( 'template_redirect', 'redirect_canonical', 10 );
			}
		}
	}
}
