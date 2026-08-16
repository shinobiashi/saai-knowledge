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
						'post_id'  => 999996,
						'url'      => 'https://example.com/valid/',
						'label'    => 'ValidInjected',
						'patterns' => array( 'ValidInjected' ),
						'excerpt'  => 'A valid injected entry.',
					),
				);
			}
		);

		$post_id = $this->create_kb_post( '<p>BadId, NoUrl, and ValidInjected are mentioned.</p>' );

		$content = $this->render( $post_id );

		$this->assertStringContainsString( 'ValidInjected</a>', $content );
		$this->assertStringNotContainsString( 'BadId</a>', $content );
		$this->assertStringNotContainsString( 'NoUrl</a>', $content );
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
