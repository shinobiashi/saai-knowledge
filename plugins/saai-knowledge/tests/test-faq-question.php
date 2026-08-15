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
	 * Renders the queried FAQ's answer through the real Loop
	 * (the_post()/the_content()) so capture_answer_content()'s
	 * in_the_loop()/queried-post guards run exactly as they would on the
	 * front end, and its capture lands in Faq_Question's static state the
	 * same way a real page render would.
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
}
