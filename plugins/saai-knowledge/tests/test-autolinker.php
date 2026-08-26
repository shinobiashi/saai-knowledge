<?php
/**
 * Tests for the Autolinker engine (docs/DESIGN-AUTOLINK.md).
 *
 * @package SAAI\Knowledge
 */

/**
 * Class Test_Autolinker.
 */
class Test_Autolinker extends WP_UnitTestCase {

	/**
	 * An unregistered instance for tests that call process()/handle_*()
	 * directly. Deliberately not register()'d: the plugin's own bootstrap
	 * (Plugin::boot(), fired once for the whole test process via
	 * plugins_loaded) already registers its own Autolinker instance on
	 * `the_content`/`save_post_saai_glossary`/`deleted_post` — registering
	 * a second instance here would double-process content flowing through
	 * render()'s apply_filters( 'the_content', ... ), chaining the first
	 * instance's output into the second instance's input.
	 *
	 * @var \SAAI\Knowledge\Autolinker
	 */
	private $autolinker;

	/**
	 * Resets dictionary state before each test.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( 'saai_dict_generation' );
		delete_option( 'saai_autolink_dict' );
		delete_option( 'saai_autolink_dict_truncated' );
		wp_cache_flush();

		$this->autolinker = new \SAAI\Knowledge\Autolinker();
	}

	/**
	 * Creates a published glossary term with an optional excerpt/synonyms.
	 *
	 * @param string               $title Term title.
	 * @param array<string, mixed> $args  Extra wp_insert_post()/meta args: 'excerpt', 'synonyms', 'content'.
	 * @return int Post ID.
	 */
	private function create_term( string $title, array $args = array() ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_glossary',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $args['content'] ?? 'A definition long enough to be trimmed if there is no excerpt set for this glossary term.',
				'post_excerpt' => $args['excerpt'] ?? '',
			)
		);

		if ( isset( $args['synonyms'] ) ) {
			update_post_meta( $post_id, \SAAI\Knowledge\Post_Meta::SYNONYMS, $args['synonyms'] );
		}

		return $post_id;
	}

	/**
	 * Renders a post's content through the full the_content filter chain
	 * (which includes the autolinker, hooked at priority 50).
	 *
	 * @param int $post_id Post to render.
	 * @return string
	 */
	private function render( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );

		$rendered = null;

		while ( have_posts() ) {
			the_post();
			$rendered = apply_filters( 'the_content', get_the_content() );
		}

		return (string) $rendered;
	}

	/**
	 * A KB post whose content contains the given raw HTML body.
	 *
	 * @param string $html Raw content.
	 * @return int Post ID.
	 */
	private function create_kb_post( string $html ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_status'  => 'publish',
				'post_content' => $html,
			)
		);
	}

	/**
	 * Latin acronyms should not match inside a longer latin word ("APIs"),
	 * but should match with hiragana/punctuation neighbors.
	 */
	public function test_latin_boundary_rejects_inside_longer_word() {
		$this->create_term( 'API' );
		$post_id = $this->create_kb_post( '<p>WEBAPI and APIs are not the API itself.</p>' );

		$content = $this->render( $post_id );

		$this->assertSame( 1, substr_count( $content, 'class="saai-term"' ) );
		$this->assertStringNotContainsString( 'WEB<a', $content );
	}

	/**
	 * A kanji term should not match as part of a longer compound word, but
	 * should match when followed by a hiragana particle.
	 */
	public function test_kanji_boundary_rejects_compound_but_allows_hiragana_neighbor() {
		$this->create_term( '保証' );
		$post_id = $this->create_kb_post( '<p>この製品は保証書付きです。保証は重要です。</p>' );

		$content = $this->render( $post_id );

		$this->assertSame( 1, substr_count( $content, 'class="saai-term"' ), $content );
		$this->assertStringContainsString( '保証書', $content );
		$this->assertStringContainsString( '>保証</a>', $content );
	}

	/**
	 * A katakana term should not match as part of a longer katakana compound.
	 */
	public function test_katakana_boundary_rejects_compound() {
		$this->create_term( 'クーポン' );
		$post_id = $this->create_kb_post( '<p>クーポンコードを入力してください。</p>' );

		$content = $this->render( $post_id );

		$this->assertSame( 0, substr_count( $content, 'class="saai-term"' ) );
	}

	/**
	 * Hiragana-adjacent matches are never rejected on that edge, even
	 * though the same script class sits on both sides in principle.
	 */
	public function test_hiragana_neighbor_never_rejects() {
		$this->create_term( 'サーバー' );
		$post_id = $this->create_kb_post( '<p>このサーバーは高速です。</p>' );

		$content = $this->render( $post_id );

		$this->assertSame( 1, substr_count( $content, 'class="saai-term"' ) );
	}

	/**
	 * The longer of two overlapping patterns should win at the same
	 * starting position.
	 */
	public function test_longest_pattern_wins_over_shorter_prefix() {
		$this->create_term( 'WooCommerce' );
		$this->create_term( 'WooCommerce Subscriptions' );
		$post_id = $this->create_kb_post( '<p>WooCommerce Subscriptions is an extension.</p>' );

		$content = $this->render( $post_id );

		$this->assertSame( 1, substr_count( $content, 'class="saai-term"' ) );
		$this->assertStringContainsString( 'WooCommerce Subscriptions</a>', $content );
	}

	/**
	 * Only the first occurrence of a term is linked.
	 */
	public function test_only_first_occurrence_is_linked() {
		$this->create_term( 'SSL' );
		$post_id = $this->create_kb_post( '<p>SSL protects data. Always enable SSL.</p>' );

		$content = $this->render( $post_id );

		$this->assertSame( 1, substr_count( $content, 'class="saai-term"' ) );
	}

	/**
	 * No more than the configured maximum number of links are inserted per post.
	 */
	public function test_max_links_per_post_is_enforced() {
		update_option(
			'saai_knowledge_settings',
			array( 'autolink_max_links' => 2 )
		);

		$this->create_term( 'Alpha' );
		$this->create_term( 'Bravo' );
		$this->create_term( 'Charlie' );
		$post_id = $this->create_kb_post( '<p>Alpha, Bravo, and Charlie are all terms.</p>' );

		$content = $this->render( $post_id );

		$this->assertSame( 2, substr_count( $content, 'class="saai-term"' ) );

		delete_option( 'saai_knowledge_settings' );
	}

	/**
	 * Fullwidth Latin content should still match a halfwidth dictionary pattern.
	 */
	public function test_fullwidth_content_matches_halfwidth_pattern() {
		$this->create_term( 'API' );
		$post_id = $this->create_kb_post( '<p>Ａｐｐは API を使う。</p>' ); // phpcs:ignore -- Fullwidth Latin in fixture content is deliberate; testing width-fold matching.

		$content = $this->render( $post_id );

		$this->assertSame( 1, substr_count( $content, 'class="saai-term"' ) );
	}

	/**
	 * Synonyms (one per line) also match, in addition to the title.
	 */
	public function test_synonym_matches() {
		$this->create_term(
			'Secure Sockets Layer',
			array( 'synonyms' => "SSL\nTLS" )
		);
		$post_id = $this->create_kb_post( '<p>This site uses TLS encryption.</p>' );

		$content = $this->render( $post_id );

		$this->assertStringContainsString( 'TLS</a>', $content );
	}

	/**
	 * Case-insensitive matching applies to latin patterns.
	 */
	public function test_latin_matching_is_case_insensitive() {
		$this->create_term( 'API' );
		$post_id = $this->create_kb_post( '<p>Call the api endpoint.</p>' );

		$content = $this->render( $post_id );

		$this->assertStringContainsString( '>api</a>', $content );
	}

	/**
	 * Text inside headings, existing links, and code/pre elements is never
	 * auto-linked.
	 */
	public function test_excluded_elements_are_never_matched() {
		$this->create_term( 'API' );
		$post_id = $this->create_kb_post(
			'<h2>API overview</h2><p><a href="/x">API docs</a></p><pre>API</pre><code>API</code><p>API is fine here.</p>'
		);

		$content = $this->render( $post_id );

		$this->assertSame( 1, substr_count( $content, 'class="saai-term"' ) );
		$this->assertStringContainsString( '<h2>API overview</h2>', $content );
		$this->assertStringContainsString( '<pre>API</pre>', $content );
		$this->assertStringContainsString( '<code>API</code>', $content );
	}

	/**
	 * Attribute values are never scanned as text (only text nodes are).
	 */
	public function test_attribute_values_are_not_matched() {
		$this->create_term( 'API' );
		$post_id = $this->create_kb_post( '<p><img src="API.png" alt="API diagram"> See below.</p>' );

		$content = $this->render( $post_id );

		$this->assertSame( 0, substr_count( $content, 'class="saai-term"' ) );
	}

	/**
	 * A `>` inside a quoted attribute value must not be treated as that
	 * tag's own end: a naive `[^>]*+` tag/text split would spill the rest
	 * of the attribute value out as scannable text and insert a link into
	 * the middle of the attribute, corrupting the markup. Uses process()
	 * directly: wp_insert_post()'s kses pass isn't guaranteed to preserve
	 * this exact byte-for-byte quoting, and the point here is the
	 * tokenizer's own behavior on a specific input.
	 */
	public function test_quoted_attribute_value_containing_gt_does_not_split_the_tag() {
		$this->create_term( 'API' );
		$html = '<p><span title="x > API">text</span></p>';

		$result = $this->autolinker->process( $html );

		$this->assertSame( $html, $result );
	}

	/**
	 * Script/style contents and HTML comments are stashed out and never
	 * scanned. Uses process() directly rather than a saved post: wp_insert_post()
	 * runs content through wp_filter_post_kses() for a user without the
	 * unfiltered_html capability (the default in tests), which would strip
	 * the very <script>/<style> tags this test needs to exercise before the
	 * autolinker ever saw them.
	 */
	public function test_script_style_and_comments_are_protected() {
		$this->create_term( 'API' );
		$html = '<script>var API = 1;</script><style>.API{}</style><!-- API --><p>Nothing to link here.</p>';

		$result = $this->autolinker->process( $html );

		$this->assertSame( 0, substr_count( $result, 'class="saai-term"' ) );
		$this->assertStringContainsString( 'var API = 1;', $result );
		$this->assertStringContainsString( '.API{}', $result );
		$this->assertStringContainsString( '<!-- API -->', $result );
	}

	/**
	 * Malformed HTML input must not throw or warn; the engine degrades gracefully.
	 */
	public function test_malformed_html_does_not_throw() {
		$this->create_term( 'API' );
		$post_id = $this->create_kb_post( '<p>API <strong>bold without close API' );

		$content = $this->render( $post_id );

		$this->assertIsString( $content );
	}

	/**
	 * A post flagged saai_no_autolink is never processed.
	 */
	public function test_no_autolink_meta_disables_processing() {
		$this->create_term( 'API' );
		$post_id = $this->create_kb_post( '<p>API is here.</p>' );
		update_post_meta( $post_id, \SAAI\Knowledge\Post_Meta::NO_AUTOLINK, true );

		$content = $this->render( $post_id );

		$this->assertSame( 0, substr_count( $content, 'class="saai-term"' ) );
	}

	/**
	 * A glossary term's own page is never auto-linked, even to other terms.
	 */
	public function test_glossary_post_type_is_excluded_by_default() {
		add_filter(
			'saai_autolink_post_types',
			static function ( $types ) {
				$types[] = 'saai_glossary';
				return $types;
			}
		);

		$this->create_term( 'API' );
		$term_id = $this->create_term( 'REST', array( 'content' => 'REST often uses an API.' ) );

		$content = $this->render( $term_id );

		$this->assertSame( 0, substr_count( $content, 'class="saai-term"' ) );
	}

	/**
	 * A dictionary with zero entries is a no-op that returns content unchanged.
	 */
	public function test_empty_dictionary_is_a_no_op() {
		$post_id = $this->create_kb_post( '<p>Nothing to link here.</p>' );

		$content = $this->render( $post_id );

		$this->assertSame( '<p>Nothing to link here.</p>' . "\n", $content );
	}

	/**
	 * Saving a glossary term bumps the dictionary generation, forcing a rebuild.
	 */
	public function test_saving_a_glossary_term_bumps_generation() {
		$generation_before = (int) get_option( 'saai_dict_generation', 1 );

		$post_id = $this->create_term( 'API' );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'API updated',
			)
		);

		$generation_after = (int) get_option( 'saai_dict_generation', 1 );

		$this->assertGreaterThan( $generation_before, $generation_after );
	}

	/**
	 * Trashing (deleting) a glossary term also bumps the generation.
	 */
	public function test_deleting_a_glossary_term_bumps_generation() {
		$post_id           = $this->create_term( 'API' );
		$generation_before = (int) get_option( 'saai_dict_generation', 1 );

		wp_delete_post( $post_id, true );

		$generation_after = (int) get_option( 'saai_dict_generation', 1 );

		$this->assertGreaterThan( $generation_before, $generation_after );
	}

	/**
	 * The `saai_autolink_dictionary` filter can inject/override entries.
	 */
	public function test_dictionary_filter_can_inject_entries() {
		add_filter(
			'saai_autolink_dictionary',
			static function ( $entries ) {
				$entries[] = array(
					'post_id'  => 999999,
					'url'      => 'https://example.com/injected/',
					'label'    => 'Injected',
					'patterns' => array( 'Injected' ),
					'excerpt'  => 'An injected entry.',
				);
				return $entries;
			}
		);

		$post_id = $this->create_kb_post( '<p>This term is Injected here.</p>' );

		$content = $this->render( $post_id );

		$this->assertStringContainsString( 'Injected</a>', $content );
	}

	/**
	 * Malformed entries from the `saai_autolink_dictionary` filter (wrong
	 * types, missing keys) are dropped rather than crashing the engine; a
	 * well-formed entry in the same result still links.
	 */
	public function test_dictionary_filter_malformed_entries_are_dropped_not_fatal() {
		add_filter(
			'saai_autolink_dictionary',
			static function () {
				return array(
					'not even an array',
					array(
						'post_id'  => 999998,
						'url'      => 'https://example.com/no-patterns/',
						'patterns' => 'not-an-array',
					),
					array(
						'post_id'  => -1,
						'url'      => 'https://example.com/bad-id/',
						'patterns' => array( 'BadId' ),
					),
					array(
						'post_id'  => 999997,
						'url'      => '',
						'patterns' => array( 'NoUrl' ),
					),
					array(
						'post_id'  => 999995,
						'url'      => 'javascript:alert(1)',
						'patterns' => array( 'BadProtocol' ),
					),
					array(
						'post_id'  => 999996,
						'url'      => 'https://example.com/valid/',
						'label'    => 'ValidInjected',
						'patterns' => array( 'ValidInjected' ),
						'excerpt'  => 'A valid injected entry.',
					),
				);
			}
		);

		$post_id = $this->create_kb_post( '<p>BadId, NoUrl, BadProtocol, and ValidInjected are mentioned.</p>' );

		$content = $this->render( $post_id );

		$this->assertStringContainsString( 'ValidInjected</a>', $content );
		$this->assertStringNotContainsString( 'BadId</a>', $content );
		$this->assertStringNotContainsString( 'NoUrl</a>', $content );
		// A disallowed-protocol URL must be dropped by sanitize_dictionary_entries()
		// (esc_url_raw() collapses it to '') rather than reach build_anchor(),
		// which would otherwise silently emit href="" for it.
		$this->assertStringNotContainsString( 'BadProtocol</a>', $content );
	}

	/**
	 * The compiled-regex cache is keyed on more than just the dictionary's
	 * post_id set: two process() calls in the same request whose
	 * saai_autolink_dictionary filter returns the same post_id with
	 * different patterns must each match their own pattern, not reuse a
	 * regex compiled for the other call's patterns.
	 */
	public function test_compiled_regex_cache_does_not_collide_when_patterns_differ_for_the_same_post_id() {
		$term_id = $this->create_term( 'Original' );

		add_filter(
			'saai_autolink_dictionary',
			static function ( $entries, $context ) use ( $term_id ) {
				$pattern = ( 'context-a' === ( $context['post_type'] ?? '' ) ) ? 'Alpha' : 'Beta';

				return array(
					array(
						'post_id'  => $term_id,
						'url'      => 'https://example.com/term/',
						'label'    => 'Original',
						'patterns' => array( $pattern ),
						'excerpt'  => 'excerpt',
					),
				);
			},
			10,
			2
		);

		$first  = $this->autolinker->process( 'Mentions Alpha here.', array( 'post_type' => 'context-a' ) );
		$second = $this->autolinker->process( 'Mentions Beta here.', array( 'post_type' => 'context-b' ) );

		$this->assertStringContainsString( 'Alpha</a>', $first );
		$this->assertStringContainsString( 'Beta</a>', $second );
	}

	/**
	 * Process() calls tied to the same post_id but with different HTML
	 * (e.g. a WooCommerce product's short vs. full description, both
	 * against the one product post) must not cross-contaminate the object
	 * cache: each call's own content, not another call's cached result.
	 */
	public function test_process_cache_does_not_collide_across_different_html_for_the_same_post() {
		$this->create_term( 'API' );
		$this->create_term( 'SDK' );

		$post_id = $this->create_kb_post( 'placeholder' );
		$context = array( 'post_id' => $post_id );

		$first  = $this->autolinker->process( 'This mentions API only.', $context );
		$second = $this->autolinker->process( 'This mentions SDK only.', $context );

		$this->assertStringContainsString( 'API</a>', $first );
		$this->assertStringNotContainsString( 'SDK</a>', $first );

		$this->assertStringContainsString( 'SDK</a>', $second );
		$this->assertStringNotContainsString( 'API</a>', $second );
	}

	/**
	 * The `saai_autolink_match_rejected` filter can force-reject a match
	 * that would otherwise have been accepted.
	 */
	public function test_match_rejected_filter_can_force_reject() {
		add_filter( 'saai_autolink_match_rejected', '__return_true' );

		$this->create_term( 'API' );
		$post_id = $this->create_kb_post( '<p>API is here.</p>' );

		$content = $this->render( $post_id );

		$this->assertSame( 0, substr_count( $content, 'class="saai-term"' ) );
	}

	/**
	 * The `saai_autolink_match_rejected` filter overrides the script-boundary
	 * heuristic itself, per docs/DESIGN-HOOKS-API.md section 3.1 ("スクリプト
	 * 境界判定の上書き"): it can un-reject a match the boundary heuristic
	 * would otherwise have rejected, not just add further rejections.
	 */
	public function test_match_rejected_filter_can_override_boundary_rejection() {
		add_filter( 'saai_autolink_match_rejected', '__return_false' );

		$this->create_term( 'クーポン' );
		$post_id = $this->create_kb_post( '<p>クーポンコードを入力してください。</p>' );

		$content = $this->render( $post_id );

		$this->assertSame( 1, substr_count( $content, 'class="saai-term"' ) );
	}

	/**
	 * The `saai_autolink_post_types` filter can remove a default target post type.
	 */
	public function test_post_types_filter_can_remove_a_default_type() {
		add_filter(
			'saai_autolink_post_types',
			static function () {
				return array( 'post' );
			}
		);

		$this->create_term( 'API' );
		$post_id = $this->create_kb_post( '<p>API is here.</p>' );

		$content = $this->render( $post_id );

		$this->assertSame( 0, substr_count( $content, 'class="saai-term"' ) );
	}

	/**
	 * The `saai_autolink_enabled` filter can veto processing for a specific post.
	 */
	public function test_enabled_filter_can_veto_a_post() {
		add_filter( 'saai_autolink_enabled', '__return_false' );

		$this->create_term( 'API' );
		$post_id = $this->create_kb_post( '<p>API is here.</p>' );

		$content = $this->render( $post_id );

		$this->assertSame( 0, substr_count( $content, 'class="saai-term"' ) );
	}

	/**
	 * Process() can be called directly (the paid add-on's entry point) with
	 * no post context.
	 */
	public function test_process_can_run_without_a_post_context() {
		$this->create_term( 'API' );

		$result = $this->autolinker->process( 'This mentions API directly.' );

		$this->assertStringContainsString( 'API</a>', $result );
	}

	/**
	 * The anchor markup carries the touch-tap and Escape-to-close directives
	 * the tooltip's Interactivity API store (M3-4) expects, alongside the
	 * hover/focus ones.
	 */
	public function test_anchor_carries_tooltip_interactivity_directives() {
		$this->create_term( 'API' );

		$result = $this->autolinker->process( 'This mentions API directly.' );

		$this->assertStringContainsString( 'data-wp-init="callbacks.initTooltipListeners"', $result );
		$this->assertStringContainsString( 'data-wp-on--touchstart="actions.handleTouchStart"', $result );
		$this->assertStringContainsString( 'data-wp-on--click="actions.handleClick"', $result );
		$this->assertStringContainsString( 'aria-describedby="saai-tooltip"', $result );
	}

	/**
	 * Has_rendered_links() is false until process() actually inserts a term
	 * link, per Tooltip::render()'s guard against printing the singleton
	 * tooltip element on pages with no auto-links.
	 */
	public function test_has_rendered_links_reflects_whether_a_link_was_inserted() {
		$this->assertFalse( $this->autolinker->has_rendered_links() );

		$this->autolinker->process( 'Nothing to link here.' );
		$this->assertFalse( $this->autolinker->has_rendered_links() );

		$this->create_term( 'API' );
		$this->autolinker->process( 'This mentions API directly.' );
		$this->assertTrue( $this->autolinker->has_rendered_links() );
	}

	/**
	 * Has_rendered_links() must also report true on a cache hit: process()
	 * skips replace_in_html() (and therefore build_anchor()) entirely on a
	 * cache hit, so the flag has to come from inspecting the cached result
	 * itself rather than only being set inside build_anchor().
	 */
	public function test_has_rendered_links_is_true_on_a_cache_hit() {
		$post_id = $this->create_kb_post( '<p>This mentions API directly.</p>' );
		$this->create_term( 'API' );

		$html    = get_post( $post_id )->post_content;
		$context = array(
			'post_id'   => $post_id,
			'post_type' => 'saai_kb',
		);

		// First call: cache miss, warms the object cache entry.
		$this->autolinker->process( $html, $context );

		$fresh_autolinker = new \SAAI\Knowledge\Autolinker();
		$this->assertFalse( $fresh_autolinker->has_rendered_links() );

		// Second call with identical html/context: cache hit, so
		// build_anchor() never runs on this instance.
		$fresh_autolinker->process( $html, $context );

		$this->assertTrue( $fresh_autolinker->has_rendered_links() );
	}

	/**
	 * Wp_trim_excerpt() (which get_the_excerpt() calls internally whenever a
	 * post has no manual excerpt) runs the full post content through
	 * `the_content` — same filter process_content() hooks — purely to strip
	 * shortcodes/blocks, then wp_trim_words() strips every tag, including
	 * any term link build_anchor() would have inserted, before the excerpt
	 * ever reaches the page. Without process()'s trim_excerpt_depth guard,
	 * that discarded link would still flip has_rendered_links() true, making
	 * Tooltip::render() enqueue its module/style/singleton element on pages
	 * whose only auto-linkable content is an automatic excerpt.
	 */
	public function test_has_rendered_links_stays_false_for_a_wp_trim_excerpt_pass() {
		$post_id = $this->create_kb_post( 'This mentions API directly.' );
		$this->create_term( 'API' );

		// process_content() reads the current post via get_post() (no
		// args), i.e. the global $post — mirrors how the Loop has it set
		// while rendering an archive listing's excerpts.
		global $post;
		$post = get_post( $post_id );

		// Calling $this->autolinker->register() here (instead of driving
		// this through a real wp_trim_excerpt()/get_the_excerpt() call)
		// would hook a SECOND process_content() onto `the_content` — this
		// plugin's own already-booted Plugin::boot() instance (fired once
		// per test process on `plugins_loaded`, per this file's set_up()
		// docblock) has already hooked its own — so directly invoking
		// process_content() while trim_excerpt_depth is manually held above
		// 0 reproduces wp_trim_excerpt()'s own bracketed window without that
		// double registration. current_filter() also needs to read
		// `the_content` at that point — genuinely true for wp_trim_excerpt()'s
		// own internal pass since it calls apply_filters( 'the_content', ... )
		// itself, but NOT reproduced by calling process_content() as a plain
		// method call — so $wp_current_filter is pushed/popped manually
		// around it too, instead of routing through a real
		// apply_filters( 'the_content', ... ) here, which would also run WP
		// core's OTHER `the_content` callbacks (wpautop, wptexturize, ...)
		// and corrupt this test's exact-string assertion below.
		add_filter(
			'get_the_excerpt',
			function () {
				global $wp_current_filter;

				$this->autolinker->mark_trim_excerpt_entering( '' );
				$wp_current_filter[] = 'the_content';

				// Left decremented/popped in finally, not right after the call:
				// an exception/error out of process_content() would otherwise
				// skip this and leave trim_excerpt_depth/$wp_current_filter
				// stuck for the rest of this test process, silently affecting
				// process() in every later test.
				try {
					$result_content = $this->autolinker->process_content( 'This mentions API directly.' );
				} finally {
					array_pop( $wp_current_filter );
					$this->autolinker->mark_trim_excerpt_leaving( '' );
				}

				return $result_content;
			}
		);

		$result = apply_filters( 'get_the_excerpt', '', get_post( $post_id ) );

		$this->assertSame( 'This mentions API directly.', $result );
		$this->assertFalse( $this->autolinker->has_rendered_links() );
	}

	/**
	 * Process()'s guard must NOT trip merely because get_the_excerpt() is
	 * somewhere on the call stack — only while genuinely nested inside an
	 * active `the_content` pass (wp_trim_excerpt()'s own internal,
	 * discardable one). A manual excerpt never goes through `the_content` at
	 * all (wp_trim_excerpt() returns non-empty $text as-is, word-trimmed),
	 * so a caller of the public process() entry point invoked from a
	 * `get_the_excerpt` callback against real, displayed manual-excerpt HTML
	 * — e.g. a future callback, or the paid add-on's own excerpt-style
	 * rendering — must still get real auto-linking.
	 */
	public function test_process_still_links_when_only_get_the_excerpt_is_active() {
		$post_id = $this->create_kb_post( 'Unused body.' );
		$this->create_term( 'API' );

		add_filter(
			'get_the_excerpt',
			function () use ( $post_id ) {
				return $this->autolinker->process(
					'This mentions API directly.',
					array( 'post_id' => $post_id )
				);
			}
		);

		$result = apply_filters( 'get_the_excerpt', 'manual excerpt placeholder', get_post( $post_id ) );

		$this->assertStringContainsString( '<a ', $result );
		$this->assertTrue( $this->autolinker->has_rendered_links() );
	}

	/**
	 * Process()'s guard must NOT trip merely because `current_filter()`
	 * happens to read `the_content` — trim_excerpt_depth (not
	 * current_filter() alone) is the primary signal, and it's 0 here since
	 * mark_trim_excerpt_entering()/_leaving() were never invoked. This
	 * reproduces a shortcode/dynamic block inside the current post's own
	 * `the_content` rendering calling get_the_excerpt() for a DIFFERENT
	 * post's manual excerpt, whose `get_the_excerpt` callback applies
	 * process() directly — real, displayed HTML that must still get genuine
	 * auto-linking, even with `the_content` spoofed onto $wp_current_filter
	 * around it.
	 */
	public function test_process_still_links_when_get_the_excerpt_is_nested_inside_the_content() {
		$post_id = $this->create_kb_post( 'Unused body.' );
		$this->create_term( 'API' );

		add_filter(
			'get_the_excerpt',
			function () use ( $post_id ) {
				return $this->autolinker->process(
					'This mentions API directly.',
					array( 'post_id' => $post_id )
				);
			}
		);

		global $wp_current_filter;

		$wp_current_filter[] = 'the_content';

		try {
			$result = apply_filters( 'get_the_excerpt', 'manual excerpt placeholder', get_post( $post_id ) );
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->assertStringContainsString( '<a ', $result );
		$this->assertTrue( $this->autolinker->has_rendered_links() );
	}

	/**
	 * Process()'s guard must NOT trip for a LATER `get_the_excerpt` callback
	 * (any priority greater than core's own 10) that independently applies
	 * `the_content` to the real, to-be-displayed excerpt — even though core's
	 * outer `get_the_excerpt` filter application is technically still "in
	 * progress" per doing_filter()/current_filter() alone at that point.
	 * mark_trim_excerpt_leaving() (priority 11) has already run by then,
	 * bringing trim_excerpt_depth back to 0, so this must still get real
	 * auto-linking rather than being mistaken for wp_trim_excerpt()'s own
	 * discardable pass.
	 */
	public function test_process_still_links_for_a_later_get_the_excerpt_callback_after_wp_trim_excerpt() {
		$post_id = $this->create_kb_post( 'Unused body.' );
		$this->create_term( 'API' );

		// Mirrors register()'s own bracket around core's priority-10
		// wp_trim_excerpt() callback, without register()'s `the_content`
		// hook (which would double-process content through this plugin's
		// already-booted singleton instance — see set_up()'s own docblock).
		add_filter( 'get_the_excerpt', array( $this->autolinker, 'mark_trim_excerpt_entering' ), 9 );
		add_filter( 'get_the_excerpt', array( $this->autolinker, 'mark_trim_excerpt_leaving' ), 11 );

		// Stands in for a THIRD-PARTY get_the_excerpt callback — running
		// after both wp_trim_excerpt() and this plugin's own priority-11
		// bracket have already closed — that applies real, displayed
		// manual-excerpt content through process_content() directly.
		add_filter(
			'get_the_excerpt',
			function () use ( $post_id ) {
				global $post;
				$post = get_post( $post_id );

				return $this->autolinker->process_content( 'This mentions API directly.' );
			},
			20
		);

		$result = apply_filters( 'get_the_excerpt', 'manual excerpt placeholder', get_post( $post_id ) );

		$this->assertStringContainsString( '<a ', $result );
		$this->assertTrue( $this->autolinker->has_rendered_links() );
	}

	/**
	 * A get_the_excerpt() callback that applies the public process() API
	 * (see its own docblock — a documented supported usage) with NO post_id
	 * context (process()'s context argument is optional) can be triggered
	 * REENTRANTLY from inside build_dictionary_entries() itself:
	 * entry_excerpt() calls get_the_excerpt() for any term with a manual
	 * excerpt. Without building_dictionary's own guard (see its comment),
	 * that reentrant call would reach get_cached_dictionary_entries() while
	 * the FIRST build_dictionary_entries() call is still running and hasn't
	 * cached anything yet, triggering a second full build — which hits the
	 * same term again and recurses without end until memory is exhausted.
	 */
	public function test_process_does_not_recurse_when_get_the_excerpt_callback_reenters_during_dictionary_build() {
		$this->create_term( 'API', array( 'excerpt' => 'A manual excerpt mentioning API.' ) );

		add_filter(
			'get_the_excerpt',
			function ( $text ) {
				return $this->autolinker->process( $text );
			}
		);

		$result = $this->autolinker->process( 'This mentions API directly.' );

		$this->assertStringContainsString( '<a ', $result );
	}

	/**
	 * Process() with no matching entries returns the original HTML unchanged.
	 */
	public function test_process_returns_original_on_preg_failure_fallback_path() {
		// No dictionary entries at all exercises the same "return $html
		// unchanged" path the preg fail-safe uses, without needing to
		// actually force a PCRE backtrack-limit error.
		$html = '<p>Anything at all.</p>';

		$result = $this->autolinker->process( $html );

		$this->assertSame( $html, $result );
	}

	/**
	 * Performance: 500 terms against ~3,000 characters of content completes
	 * well inside a generous CI-safe threshold (the design budget is 50ms
	 * cache-miss on typical hardware; CI runners are slower and noisier).
	 */
	public function test_performance_with_500_terms_and_3000_characters() {
		for ( $i = 0; $i < 500; $i++ ) {
			$this->create_term( 'Term' . $i . 'X' );
		}

		$paragraph = str_repeat( 'This paragraph mentions Term1X and Term250X and Term499X among other filler words. ', 40 );
		$post_id   = $this->create_kb_post( '<p>' . $paragraph . '</p>' );

		// Warm the dictionary cache first; the assertion is about matching
		// speed, not dictionary-build speed (a separate, one-time cost).
		$this->render( $post_id );
		wp_cache_flush();
		update_option( 'saai_dict_generation', (int) get_option( 'saai_dict_generation', 1 ) );

		$start      = microtime( true );
		$content    = $this->render( $post_id );
		$elapsed_ms = ( microtime( true ) - $start ) * 1000;

		$this->assertLessThan( 1000, $elapsed_ms, "Autolinking took {$elapsed_ms}ms" );
		$this->assertGreaterThan( 0, substr_count( $content, 'class="saai-term"' ) );
	}
}
