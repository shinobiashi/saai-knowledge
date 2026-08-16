<?php
/**
 * Tests for the Faq_Question service (QAPage JSON-LD).
 *
 * @package SAAI\Knowledge
 */

use SAAI\Knowledge\Faq_Question;

/**
 * Class Test_Faq_Question.
 */
class Test_Faq_Question extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Faq_Question
	 */
	private $faq_question;

	/**
	 * Sets up the service under test.
	 */
	public function set_up() {
		parent::set_up();

		$this->faq_question = new Faq_Question();
		Faq_Question::reset_state();
	}

	/**
	 * Creates a published FAQ entry.
	 *
	 * @param array<string, mixed> $args Overrides for the post factory.
	 * @return \WP_Post
	 */
	private function create_faq( array $args = array() ): \WP_Post {
		return self::factory()->post->create_and_get(
			array_merge(
				array(
					'post_type'   => 'saai_faq',
					'post_status' => 'publish',
				),
				$args
			)
		);
	}

	/**
	 * Renders the queried FAQ's title and answer through the real Loop
	 * (the_post()/the_title()/the_content()) so capture_answer_content()'s
	 * and capture_title()'s in_the_loop()/queried-post guards run exactly
	 * as they would on the front end, and their captures land in
	 * Faq_Question's static state the same way a real page render would —
	 * every classic single template renders both, not just the content.
	 *
	 * @return string The rendered content.
	 */
	private function render_content_in_the_loop(): string {
		$content = '';

		while ( have_posts() ) {
			the_post();
			// get_the_title() alone is enough to run the_title filters
			// (capture_title()'s hook) the same way the_title() would.
			get_the_title();
			$content .= get_the_content();
			// get_the_content() alone doesn't run the_content filters; apply()
			// them the same way the_content() would, through the real Loop.
			$content = apply_filters( 'the_content', $content );
		}

		return $content;
	}

	/**
	 * The QAPage schema should carry the FAQ's title as the question name and
	 * the visible page's rendered answer as the accepted answer text.
	 */
	public function test_json_ld_builds_qa_page_schema() {
		$post = $this->create_faq(
			array(
				'post_title'   => 'How do I reset my password?',
				'post_content' => 'Open account settings and click Reset password.',
			)
		);

		$this->go_to( get_permalink( $post ) );
		$this->render_content_in_the_loop();

		$schema = $this->faq_question->json_ld( $post );

		$this->assertSame( 'https://schema.org', $schema['@context'] );
		$this->assertSame( 'QAPage', $schema['@type'] );
		$this->assertSame( 'Question', $schema['mainEntity']['@type'] );
		$this->assertSame( 'How do I reset my password?', $schema['mainEntity']['name'] );
		// Google's Q&A structured data guidelines require answerCount; the
		// data model is always 1 post = 1 answer, so this is always 1.
		$this->assertSame( 1, $schema['mainEntity']['answerCount'] );
		$this->assertSame( 'Answer', $schema['mainEntity']['acceptedAnswer']['@type'] );
		$this->assertSame( 'Open account settings and click Reset password.', $schema['mainEntity']['acceptedAnswer']['text'] );
	}

	/**
	 * The answer text must be the same content the visible page shows: a raw
	 * strip-tags of post_content alone would leave an unexpanded shortcode
	 * tag as literal text, no longer matching the rendered page.
	 */
	public function test_json_ld_answer_text_expands_shortcodes() {
		add_shortcode(
			'saai_test_shortcode',
			function () {
				return 'Expanded output';
			}
		);

		$post = $this->create_faq(
			array(
				'post_title'   => 'Shortcode question',
				'post_content' => 'Before [saai_test_shortcode] after.',
			)
		);

		$this->go_to( get_permalink( $post ) );

		try {
			$this->render_content_in_the_loop();
			$schema = $this->faq_question->json_ld( $post );
		} finally {
			remove_shortcode( 'saai_test_shortcode' );
		}

		$answer = $schema['mainEntity']['acceptedAnswer']['text'];

		$this->assertStringContainsString( 'Expanded output', $answer );
		$this->assertStringNotContainsString( '[saai_test_shortcode]', $answer );
	}

	/**
	 * A shortcode with side effects (a view counter, a one-time token) must
	 * run exactly once for a page view: capturing the visible page's own
	 * render for the JSON-LD — rather than independently re-rendering the
	 * answer to build it — must not invoke shortcode callbacks a second
	 * time.
	 */
	public function test_answer_shortcodes_do_not_double_execute() {
		$call_count = 0;

		add_shortcode(
			'saai_test_counter',
			function () use ( &$call_count ) {
				++$call_count;

				return 'Count: ' . $call_count;
			}
		);

		$post = $this->create_faq(
			array(
				'post_title'   => 'Counter question',
				'post_content' => '[saai_test_counter]',
			)
		);

		$this->go_to( get_permalink( $post ) );

		try {
			$visible_answer = $this->render_content_in_the_loop();
			$schema         = $this->faq_question->json_ld( $post );
		} finally {
			remove_shortcode( 'saai_test_counter' );
		}

		$this->assertSame( 1, $call_count );
		$this->assertStringContainsString( 'Count: 1', $visible_answer );
		$this->assertStringContainsString( 'Count: 1', $schema['mainEntity']['acceptedAnswer']['text'] );
	}

	/**
	 * The answer text must reflect the whole content, not a short summary —
	 * docs/DESIGN.md section 7.1's "1ページで回答が完結する" requirement.
	 */
	public function test_json_ld_answer_text_is_not_truncated() {
		$long_answer = implode( ' ', array_fill( 0, 80, 'word' ) );

		$post = $this->create_faq(
			array(
				'post_title'   => 'Long answer',
				'post_content' => $long_answer,
			)
		);

		$this->go_to( get_permalink( $post ) );
		$this->render_content_in_the_loop();

		$schema = $this->faq_question->json_ld( $post );

		$this->assertSame( $long_answer, $schema['mainEntity']['acceptedAnswer']['text'] );
	}

	/**
	 * The captured answer's block/paragraph boundaries must survive as
	 * plain-text word boundaries: wp_strip_all_tags() deletes tags without
	 * inserting a separator, so adjacent paragraphs would otherwise
	 * collapse into one run-together word.
	 */
	public function test_json_ld_answer_text_keeps_word_boundaries_across_tags() {
		$post = $this->create_faq(
			array(
				'post_title'   => 'Line breaks',
				'post_content' => "First\n\nSecond",
			)
		);

		$this->go_to( get_permalink( $post ) );
		$this->render_content_in_the_loop();

		$schema = $this->faq_question->json_ld( $post );

		$this->assertStringNotContainsString( 'FirstSecond', $schema['mainEntity']['acceptedAnswer']['text'] );
	}

	/**
	 * HTML character references produced by the_title filters (& → &#038;)
	 * should be decoded to plain text in the question name — JSON-LD contents
	 * are never HTML-entity-decoded by consumers.
	 */
	public function test_json_ld_decodes_html_entities_in_question_name() {
		$post = $this->create_faq( array( 'post_title' => 'Q & A' ) );

		$schema = $this->faq_question->json_ld( $post );

		$this->assertSame( 'Q & A', $schema['mainEntity']['name'] );
	}

	/**
	 * The saai_structured_data filter should receive the qa-page type and be
	 * able to replace the schema; a non-array return should be ignored.
	 */
	public function test_json_ld_applies_structured_data_filter() {
		$post = $this->create_faq( array( 'post_title' => 'Question' ) );

		$received_type = null;
		$filter        = function ( $schema, $schema_type ) use ( &$received_type ) {
			$received_type     = $schema_type;
			$schema['@custom'] = true;

			return $schema;
		};

		add_filter( 'saai_structured_data', $filter, 10, 2 );

		try {
			$schema = $this->faq_question->json_ld( $post );
		} finally {
			remove_filter( 'saai_structured_data', $filter, 10 );
		}

		$this->assertSame( 'qa-page', $received_type );
		$this->assertTrue( $schema['@custom'] );

		add_filter( 'saai_structured_data', '__return_false' );

		try {
			$schema = $this->faq_question->json_ld( $post );
		} finally {
			remove_filter( 'saai_structured_data', '__return_false' );
		}

		$this->assertSame( 'QAPage', $schema['@type'] );
	}

	/**
	 * Structured data output should default to enabled and honor the
	 * settings option.
	 */
	public function test_structured_data_enabled_reads_settings() {
		$this->assertTrue( $this->faq_question->structured_data_enabled() );

		update_option( 'saai_knowledge_settings', array( 'structured_data' => false ) );
		$this->assertFalse( $this->faq_question->structured_data_enabled() );

		update_option( 'saai_knowledge_settings', array( 'structured_data' => true ) );
		$this->assertTrue( $this->faq_question->structured_data_enabled() );
	}

	/**
	 * An unauthenticated visitor to a password-protected FAQ must not be able
	 * to read its answer out of the page source via JSON-LD.
	 */
	public function test_output_structured_data_skips_password_protected_faqs() {
		$post = $this->create_faq(
			array(
				'post_title'    => 'Secret',
				'post_content'  => 'Secret answer.',
				'post_password' => 'secret',
			)
		);

		$this->go_to( get_permalink( $post ) );
		$this->render_content_in_the_loop();

		ob_start();
		$this->faq_question->output_structured_data();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * The JSON-LD must not be output for singular views of unrelated post
	 * types.
	 */
	public function test_output_structured_data_skips_other_post_types() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->go_to( get_permalink( $page_id ) );

		ob_start();
		$this->faq_question->output_structured_data();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * The JSON-LD must not be output when structured data is disabled in
	 * settings.
	 */
	public function test_output_structured_data_skips_when_disabled_in_settings() {
		$post = $this->create_faq( array( 'post_title' => 'Question' ) );

		$this->go_to( get_permalink( $post ) );

		update_option( 'saai_knowledge_settings', array( 'structured_data' => false ) );

		ob_start();
		$this->faq_question->output_structured_data();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * The data model's title = question means an empty title has no question
	 * text; wp_insert_post_empty_content() only rejects a post whose title,
	 * content, AND excerpt are all empty, so a saai_faq with a blank title
	 * but non-empty content is a valid, admin-savable post that must not
	 * produce a QAPage with an empty required Question.name.
	 */
	public function test_output_structured_data_skips_faqs_with_an_empty_title() {
		$post = $this->create_faq(
			array(
				'post_title'   => '',
				'post_content' => 'Answer without a question title.',
			)
		);

		$this->go_to( get_permalink( $post ) );
		$this->render_content_in_the_loop();

		ob_start();
		$this->faq_question->output_structured_data();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * A title of pure whitespace is not rejected by
	 * wp_insert_post_empty_content() (there is non-empty content), so
	 * get_the_title() would return the whitespace unchanged — a plain ''
	 * comparison would miss it and emit a QAPage with an effectively
	 * empty required Question.name.
	 */
	public function test_output_structured_data_skips_faqs_with_a_whitespace_only_title() {
		$post = $this->create_faq(
			array(
				'post_title'   => '   ',
				'post_content' => 'Answer without a real question title.',
			)
		);

		$this->go_to( get_permalink( $post ) );
		$this->render_content_in_the_loop();

		ob_start();
		$this->faq_question->output_structured_data();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * When the theme never actually renders the answer body (an unusual
	 * template override, or a request that reaches wp_footer without the
	 * Loop having run), there is nothing truthful to report and the QAPage
	 * must be skipped rather than emitted for content nobody saw.
	 */
	public function test_output_structured_data_skips_when_nothing_was_captured() {
		$post = $this->create_faq(
			array(
				'post_title'   => 'Question',
				'post_content' => 'Answer.',
			)
		);

		$this->go_to( get_permalink( $post ) );

		ob_start();
		$this->faq_question->output_structured_data();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Viewing a published FAQ should output a valid QAPage JSON-LD script
	 * tag.
	 */
	public function test_output_structured_data_outputs_script_tag_for_a_published_faq() {
		$post = $this->create_faq(
			array(
				'post_title'   => 'Question',
				'post_content' => 'Answer.',
			)
		);

		$this->go_to( get_permalink( $post ) );
		$this->render_content_in_the_loop();

		ob_start();
		$this->faq_question->output_structured_data();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<script type="application/ld+json">', $output );
		$this->assertStringContainsString( '"QAPage"', $output );
	}

	/**
	 * A block theme's core/post-content render never calls WP_Query::the_post(),
	 * so in_the_loop() stays false throughout and capture_answer_content()
	 * alone never captures there — capture_answer_content_for_block_theme()
	 * must cover it instead.
	 */
	public function test_json_ld_answer_uses_captured_content_from_a_block_theme_render() {
		$post = $this->create_faq(
			array(
				'post_title'   => 'Block theme question',
				'post_content' => 'Block theme answer.',
			)
		);

		$this->go_to( get_permalink( $post ) );
		// render_block()'s postId/postType context comes from the global
		// $post, which real requests only get from the block template
		// canvas's the_post() call before it renders the template content;
		// go_to() alone doesn't set it, so core/post-content would render as
		// the wrong (or no) post without this — same setup as
		// Glossary_Term's equivalent test.
		the_post();

		// Faq_Question::register() is already hooked from the plugin's own
		// normal bootstrap (it's an active plugin for the whole test suite,
		// not something instantiated per-test) — adding a second
		// registration here via a fresh instance would double-capture, same
		// reasoning as Glossary_Term's equivalent test.
		do_blocks( '<!-- wp:post-content /-->' );

		$schema = $this->faq_question->json_ld( $post );

		$this->assertStringContainsString( 'Block theme answer.', $schema['mainEntity']['acceptedAnswer']['text'] );
	}

	/**
	 * A block-caching plugin (or similar) can short-circuit core/post-content
	 * via an earlier-priority pre_render_block callback — render_block()
	 * (wp-includes/blocks.php) returns that value immediately without ever
	 * constructing WP_Block or applying render_block_core/post-content, so
	 * capture_answer_content_for_block_theme() never fires even though the
	 * cached answer is genuinely what the visitor sees.
	 *
	 * The short-circuited text deliberately differs from the post's real
	 * content, and the filter is registered at priority 20 rather than a
	 * lower number: core's own _wp_add_block_level_preset_styles (hooked at
	 * the default priority 10) unconditionally returns null regardless of
	 * the incoming value, which would silently clobber a short-circuit
	 * registered before it and mask this test either passing for the wrong
	 * reason (matching the real content instead) or failing to reproduce the
	 * scenario at all.
	 */
	public function test_json_ld_answer_uses_captured_content_when_post_content_is_short_circuited() {
		$post = $this->create_faq(
			array(
				'post_title'   => 'Cached block theme question',
				'post_content' => 'Real content, never rendered.',
			)
		);

		$this->go_to( get_permalink( $post ) );
		the_post();

		$short_circuit = function ( $pre_render, $parsed_block ) {
			if ( 'core/post-content' === ( $parsed_block['blockName'] ?? null ) ) {
				return '<div class="wp-block-post-content"><p>Short-circuited cached answer.</p></div>';
			}

			return $pre_render;
		};

		add_filter( 'pre_render_block', $short_circuit, 20, 2 );

		try {
			$output = do_blocks( '<!-- wp:post-content /-->' );
			$schema = $this->faq_question->json_ld( $post );
		} finally {
			remove_filter( 'pre_render_block', $short_circuit, 20 );
		}

		$this->assertStringContainsString( 'Short-circuited cached answer.', $output );
		$this->assertStringContainsString( 'Short-circuited cached answer.', $schema['mainEntity']['acceptedAnswer']['text'] );
	}

	/**
	 * A theme or plugin can still modify core/post-content's rendered output
	 * after Faq_Question's own hook runs (translation, access control,
	 * hiding part of the answer) — capturing at a priority later than any
	 * such callback is expected to run at must reflect that later value, not
	 * a stale snapshot taken before it ran.
	 */
	public function test_json_ld_answer_reflects_a_later_render_block_core_post_content_filter() {
		$post = $this->create_faq(
			array(
				'post_title'   => 'Late filter question',
				'post_content' => 'Original answer.',
			)
		);

		$this->go_to( get_permalink( $post ) );
		the_post();

		$late_filter = function ( $block_content ) {
			return str_replace( 'Original answer.', 'Replaced answer.', $block_content );
		};

		add_filter( 'render_block_core/post-content', $late_filter, 11 );

		try {
			do_blocks( '<!-- wp:post-content /-->' );
			$schema = $this->faq_question->json_ld( $post );
		} finally {
			remove_filter( 'render_block_core/post-content', $late_filter, 11 );
		}

		$this->assertStringContainsString( 'Replaced answer.', $schema['mainEntity']['acceptedAnswer']['text'] );
	}

	/**
	 * A the_title filter that behaves differently depending on in_the_loop()
	 * (e.g. a translation callback that only runs during the main Loop)
	 * must not have the JSON-LD question name diverge from what the
	 * visible <h1> actually showed. Before the fix, json_ld() re-derived
	 * the title via a fresh get_the_title( $post ) call — which, called
	 * from output_structured_data()'s wp_footer context, runs after
	 * WP_Query::have_posts() has already reset in_the_loop() back to
	 * false, so a loop-state-dependent filter would produce a different
	 * value there than it did for the real <h1>.
	 */
	public function test_json_ld_question_name_uses_the_in_loop_rendered_title() {
		$post = $this->create_faq( array( 'post_title' => 'Original title' ) );

		$this->go_to( get_permalink( $post ) );

		$loop_dependent_title = function ( $title, $id ) use ( $post ) {
			if ( (int) $id !== $post->ID ) {
				return $title;
			}

			return in_the_loop() ? 'In-loop title' : 'Out-of-loop title';
		};

		add_filter( 'the_title', $loop_dependent_title, 20, 2 );

		try {
			$this->render_content_in_the_loop();
			$schema = $this->faq_question->json_ld( $post );
		} finally {
			remove_filter( 'the_title', $loop_dependent_title, 20 );
		}

		$this->assertSame( 'In-loop title', $schema['mainEntity']['name'] );
	}

	/**
	 * A theme or plugin can still modify core/post-title's rendered output
	 * after Faq_Question's own hook runs (translation, access control) —
	 * capturing at a priority later than any such callback is expected to
	 * run at must reflect that later value, not a stale snapshot taken
	 * before it ran. Mirrors
	 * test_json_ld_answer_reflects_a_later_render_block_core_post_content_filter()
	 * for the title's own block-theme capture path.
	 */
	public function test_json_ld_question_name_reflects_a_later_render_block_core_post_title_filter() {
		$post = $this->create_faq( array( 'post_title' => 'Original title' ) );

		$this->go_to( get_permalink( $post ) );
		the_post();

		$late_filter = function ( $block_content ) {
			return str_replace( 'Original title', 'Replaced title', $block_content );
		};

		add_filter( 'render_block_core/post-title', $late_filter, 11 );

		try {
			do_blocks( '<!-- wp:post-title /-->' );
			$schema = $this->faq_question->json_ld( $post );
		} finally {
			remove_filter( 'render_block_core/post-title', $late_filter, 11 );
		}

		$this->assertStringContainsString( 'Replaced title', $schema['mainEntity']['name'] );
	}

	/**
	 * A single template customized to wrap its main content in an inherited
	 * Query Loop (Inherit query from URL) still shows the viewed FAQ's own
	 * answer — core's render_block_core_query() makes an inherited Query
	 * Loop iterate the main query itself, which on a singular view is
	 * exactly the one viewed post. This must not be rejected the same way a
	 * genuinely unrelated Query Loop (e.g. "related FAQs") is.
	 */
	public function test_json_ld_answer_uses_captured_content_from_an_inherited_query_loop() {
		$post = $this->create_faq(
			array(
				'post_title'   => 'Inherited query loop question',
				'post_content' => 'Inherited query loop answer.',
			)
		);

		$this->go_to( get_permalink( $post ) );
		the_post();

		do_blocks(
			'<!-- wp:query {"query":{"inherit":true}} -->' .
			'<div class="wp-block-query">' .
			'<!-- wp:post-template -->' .
			'<!-- wp:post-content /-->' .
			'<!-- /wp:post-template -->' .
			'</div>' .
			'<!-- /wp:query -->'
		);

		$schema = $this->faq_question->json_ld( $post );

		$this->assertStringContainsString( 'Inherited query loop answer.', $schema['mainEntity']['acceptedAnswer']['text'] );
	}

	/**
	 * A render_block_data callback (a block-variation swap, a translation
	 * proxy block) that renames one core/post-content instance to a
	 * different block name must not desync $post_content_render_depth and
	 * break capture of an unrelated, un-renamed core/post-content that
	 * renders afterward in the same request.
	 *
	 * Before the fix, depth tracking happened on pre_render_block using the
	 * pre-rename block name: the renamed instance would increment the depth
	 * but never decrement it (its dynamic render_block_core/post-content
	 * hook, keyed off the post-rename name, never fires), permanently
	 * leaving $was_outermost false for every real post-content render that
	 * follows in the same request.
	 */
	public function test_json_ld_answer_is_unaffected_by_a_renamed_post_content_block() {
		$post = $this->create_faq(
			array(
				'post_title'   => 'Renamed sibling block question',
				'post_content' => 'Real answer.',
			)
		);

		$this->go_to( get_permalink( $post ) );
		the_post();

		$rename_marked_block = function ( $parsed_block ) {
			if ( 'core/post-content' === ( $parsed_block['blockName'] ?? null ) && ! empty( $parsed_block['attrs']['saaiTestRenamed'] ) ) {
				$parsed_block['blockName'] = 'core/paragraph';
			}

			return $parsed_block;
		};

		add_filter( 'render_block_data', $rename_marked_block );

		try {
			do_blocks( '<!-- wp:post-content {"saaiTestRenamed":true} /--><!-- wp:post-content /-->' );
			$schema = $this->faq_question->json_ld( $post );
		} finally {
			remove_filter( 'render_block_data', $rename_marked_block );
		}

		$this->assertStringContainsString( 'Real answer.', $schema['mainEntity']['acceptedAnswer']['text'] );
	}

	/**
	 * A later-priority render_block_core/post-template callback (a
	 * read-time estimator or analytics plugin reentrantly re-rendering the
	 * block tree for analysis) must not have its discarded core/post-content
	 * render mistaken for the primary answer.
	 *
	 * WP_Block::render() applies render_block_core/post-template via a
	 * single apply_filters() call: every registered callback, regardless of
	 * priority, runs as part of that one call. If this class's own
	 * end-of-post-template tracking ran at a low priority, a later callback
	 * on the same hook doing a reentrant core/post-content render would see
	 * $post_template_render_depth already back at 0 — as if that render
	 * were happening outside any Query Loop — and wrongly capture its
	 * discarded, analysis-only content as the real answer.
	 */
	public function test_json_ld_answer_ignores_a_reentrant_post_content_render_from_a_later_post_template_filter() {
		$post = $this->create_faq(
			array(
				'post_title'   => 'Reentrant filter question',
				'post_content' => 'Real answer.',
			)
		);

		$this->go_to( get_permalink( $post ) );
		the_post();

		$is_reentrant_render = false;

		$tag_reentrant_content = function ( $block_content ) use ( &$is_reentrant_render ) {
			return $is_reentrant_render ? 'Reentrant analysis render.' : $block_content;
		};
		add_filter( 'render_block_core/post-content', $tag_reentrant_content, 5 );

		$reentrant_render = function ( $block_content ) use ( &$is_reentrant_render ) {
			$is_reentrant_render = true;
			render_block(
				array(
					'blockName'    => 'core/post-content',
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				)
			);
			$is_reentrant_render = false;

			return $block_content;
		};
		add_filter( 'render_block_core/post-template', $reentrant_render, 20 );

		try {
			do_blocks(
				'<!-- wp:query {"query":{"inherit":false,"postType":"saai_faq","perPage":10}} -->' .
				'<div class="wp-block-query">' .
				'<!-- wp:post-template -->' .
				'<!-- wp:post-content /-->' .
				'<!-- /wp:post-template -->' .
				'</div>' .
				'<!-- /wp:query -->'
			);
			$schema = $this->faq_question->json_ld( $post );
		} finally {
			remove_filter( 'render_block_core/post-template', $reentrant_render, 20 );
			remove_filter( 'render_block_core/post-content', $tag_reentrant_content, 5 );
		}

		$this->assertStringNotContainsString( 'Reentrant analysis render.', $schema['mainEntity']['acceptedAnswer']['text'] );
	}

	/**
	 * A Group (or custom access-control block) wrapping core/post-content
	 * can still discard the child's already-captured output from its own,
	 * later-running render_block filter — WP_Block::render() renders
	 * children fully, including the capture, before applying the parent's
	 * own filters. The discarded content must not leak into the JSON-LD:
	 * output_structured_data() must treat this exactly like nothing was
	 * ever captured.
	 */
	public function test_json_ld_answer_is_discarded_when_an_ancestor_block_hides_it() {
		$post = $this->create_faq(
			array(
				'post_title'   => 'Restricted question',
				'post_content' => 'Secret answer.',
			)
		);

		$this->go_to( get_permalink( $post ) );
		the_post();

		$discard_ancestor = function ( $block_content, $parsed_block ) {
			if ( 'core/group' === ( $parsed_block['blockName'] ?? null ) ) {
				return '';
			}

			return $block_content;
		};

		add_filter( 'render_block', $discard_ancestor, 20, 2 );

		try {
			$output = do_blocks( '<!-- wp:group --><div class="wp-block-group"><!-- wp:post-content /--></div><!-- /wp:group -->' );

			ob_start();
			$this->faq_question->output_structured_data();
			$footer_output = ob_get_clean();
		} finally {
			remove_filter( 'render_block', $discard_ancestor, 20 );
		}

		$this->assertSame( '', $output );
		$this->assertStringNotContainsString( 'Secret answer.', $footer_output );
		$this->assertSame( '', $footer_output );
	}

	/**
	 * The title counterpart to
	 * test_json_ld_answer_is_discarded_when_an_ancestor_block_hides_it():
	 * an ancestor discarding core/post-title's already-captured output
	 * must not leave a stale question name in the JSON-LD.
	 */
	public function test_json_ld_question_name_is_discarded_when_an_ancestor_block_hides_it() {
		$post = $this->create_faq(
			array(
				'post_title'   => 'Restricted title',
				'post_content' => 'Answer.',
			)
		);

		$this->go_to( get_permalink( $post ) );
		the_post();

		$discard_ancestor = function ( $block_content, $parsed_block ) {
			if ( 'core/group' === ( $parsed_block['blockName'] ?? null ) ) {
				return '';
			}

			return $block_content;
		};

		add_filter( 'render_block', $discard_ancestor, 20, 2 );

		try {
			do_blocks( '<!-- wp:group --><div class="wp-block-group"><!-- wp:post-title /--></div><!-- /wp:group -->' );
			do_blocks( '<!-- wp:post-content /-->' );

			$schema = $this->faq_question->json_ld( $post );
		} finally {
			remove_filter( 'render_block', $discard_ancestor, 20 );
		}

		$this->assertStringNotContainsString( 'Restricted title', $schema['mainEntity']['name'] );
	}

	/**
	 * A Group that merely wraps core/post-content — the overwhelmingly
	 * common case, any layout block (Group, Row, Stack, a column) — must
	 * not have the new ancestor-discard check in
	 * test_json_ld_answer_is_discarded_when_an_ancestor_block_hides_it()
	 * cause a false positive: the captured answer must still survive when
	 * nothing actually discards it.
	 */
	public function test_json_ld_answer_survives_a_non_discarding_ancestor_block() {
		$post = $this->create_faq(
			array(
				'post_title'   => 'Wrapped question',
				'post_content' => 'Wrapped answer.',
			)
		);

		$this->go_to( get_permalink( $post ) );
		the_post();

		do_blocks( '<!-- wp:group --><div class="wp-block-group"><!-- wp:post-content /--></div><!-- /wp:group -->' );

		$schema = $this->faq_question->json_ld( $post );

		$this->assertStringContainsString( 'Wrapped answer.', $schema['mainEntity']['acceptedAnswer']['text'] );
	}

	/**
	 * The data model's title = question means a title-only saai_faq with an
	 * empty (or markup-only) body is a valid, admin-savable post too —
	 * captured_answer_html would then be '' rather than null, which must
	 * still suppress the QAPage rather than emit one with an empty required
	 * acceptedAnswer.text.
	 */
	public function test_output_structured_data_skips_faqs_with_an_empty_answer() {
		$post = $this->create_faq(
			array(
				'post_title'   => 'Question with no answer',
				'post_content' => '',
			)
		);

		$this->go_to( get_permalink( $post ) );
		$this->render_content_in_the_loop();

		ob_start();
		$this->faq_question->output_structured_data();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * A Classic Editor "<p>&nbsp;</p>" answer decodes to a lone U+00A0
	 * (non-breaking space) after tag-stripping/entity-decoding, which
	 * PHP's trim() does not treat as whitespace — a plain trim()-based
	 * empty check would miss it and emit a QAPage with an effectively
	 * empty required acceptedAnswer.text.
	 */
	public function test_output_structured_data_skips_faqs_with_a_non_breaking_space_only_answer() {
		$post = $this->create_faq(
			array(
				'post_title'   => 'Question with a blank answer',
				'post_content' => '<p>&nbsp;</p>',
			)
		);

		$this->go_to( get_permalink( $post ) );
		$this->render_content_in_the_loop();

		ob_start();
		$this->faq_question->output_structured_data();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Excerpt generation applies the_content internally (via
	 * wp_trim_excerpt(), core's default get_the_excerpt callback) when a
	 * post has no manual excerpt, to derive one from the content. A classic
	 * theme calling
	 * the_excerpt() ahead of the_content() (e.g. a "related FAQs" teaser
	 * list) must not have that nested call permanently claim the capture
	 * slot with wp_trim_excerpt()'s intermediate value — shortcodes already
	 * stripped via strip_shortcodes() rather than expanded — pre-empting the
	 * real the_content() capture that follows.
	 */
	public function test_capture_ignores_content_rendered_during_excerpt_generation() {
		add_shortcode(
			'saai_test_shortcode',
			function () {
				return 'Expanded output';
			}
		);

		$post = $this->create_faq(
			array(
				'post_title'   => 'Excerpt then content',
				'post_content' => 'Before [saai_test_shortcode] after.',
				'post_excerpt' => '',
			)
		);

		$this->go_to( get_permalink( $post ) );

		try {
			while ( have_posts() ) {
				the_post();
				get_the_excerpt();
				apply_filters( 'the_content', get_the_content() );
			}

			$schema = $this->faq_question->json_ld( $post );
		} finally {
			remove_shortcode( 'saai_test_shortcode' );
		}

		$answer = $schema['mainEntity']['acceptedAnswer']['text'];

		$this->assertStringContainsString( 'Expanded output', $answer );
		$this->assertStringNotContainsString( '[saai_test_shortcode]', $answer );
	}

	/**
	 * A shortcode inside the answer can itself reentrantly call
	 * apply_filters( 'the_content', ... ) on unrelated content (e.g. a
	 * "related post" teaser shortcode). That nested call must not claim the
	 * capture slot with just its own inner fragment before the outer,
	 * complete answer finishes rendering and reaches the same PHP_INT_MAX
	 * priority.
	 */
	public function test_capture_ignores_a_reentrant_the_content_call() {
		add_shortcode(
			'saai_test_reentrant',
			function () {
				return apply_filters( 'the_content', 'Inner fragment.' );
			}
		);

		$post = $this->create_faq(
			array(
				'post_title'   => 'Reentrant question',
				'post_content' => 'Outer before. [saai_test_reentrant] Outer after.',
			)
		);

		$this->go_to( get_permalink( $post ) );

		try {
			$this->render_content_in_the_loop();
			$schema = $this->faq_question->json_ld( $post );
		} finally {
			remove_shortcode( 'saai_test_reentrant' );
		}

		$answer = $schema['mainEntity']['acceptedAnswer']['text'];

		$this->assertStringContainsString( 'Outer before.', $answer );
		$this->assertStringContainsString( 'Outer after.', $answer );
	}

	/**
	 * A plugin computing something else from the same post's content within
	 * the same Loop pass (a read-time estimate, an SEO description) can call
	 * apply_filters( 'the_content', ... ) on the queried FAQ before the
	 * theme's own real, visible render does. The captured answer must
	 * reflect the latest such call, not freeze on whichever discarded,
	 * non-visible one happened first — the same reclaim reasoning the
	 * block-theme path already applies via claim_block_capture_slot().
	 */
	public function test_json_ld_answer_updates_to_the_latest_same_post_the_content_call() {
		$post = $this->create_faq(
			array(
				'post_title'   => 'Read-time question',
				'post_content' => 'Real answer.',
			)
		);

		$this->go_to( get_permalink( $post ) );

		while ( have_posts() ) {
			the_post();
			// Simulates an early metadata/read-time-estimator plugin calling
			// apply_filters( 'the_content', ... ) on the same post ahead of
			// the theme's own real, visible the_content() call.
			apply_filters( 'the_content', 'Discarded early analysis render.' );
			apply_filters( 'the_content', get_the_content() );
		}

		$schema = $this->faq_question->json_ld( $post );
		$answer = $schema['mainEntity']['acceptedAnswer']['text'];

		$this->assertStringContainsString( 'Real answer.', $answer );
		$this->assertStringNotContainsString( 'Discarded early analysis render.', $answer );
	}
}
