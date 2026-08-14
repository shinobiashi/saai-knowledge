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
	 * The QAPage schema should carry the FAQ's title as the question name and
	 * its content as the accepted answer text.
	 */
	public function test_json_ld_builds_qa_page_schema() {
		$post = $this->create_faq(
			array(
				'post_title'   => 'How do I reset my password?',
				'post_content' => 'Open account settings and click "Reset password".',
			)
		);

		$schema = $this->faq_question->json_ld( $post );

		$this->assertSame( 'https://schema.org', $schema['@context'] );
		$this->assertSame( 'QAPage', $schema['@type'] );
		$this->assertSame( 'Question', $schema['mainEntity']['@type'] );
		$this->assertSame( 'How do I reset my password?', $schema['mainEntity']['name'] );
		$this->assertSame( 'Answer', $schema['mainEntity']['acceptedAnswer']['@type'] );
		$this->assertSame( 'Open account settings and click "Reset password".', $schema['mainEntity']['acceptedAnswer']['text'] );
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

		$schema = $this->faq_question->json_ld( $post );

		$this->assertSame( $long_answer, $schema['mainEntity']['acceptedAnswer']['text'] );
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

		ob_start();
		$this->faq_question->output_structured_data();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<script type="application/ld+json">', $output );
		$this->assertStringContainsString( '"QAPage"', $output );
	}
}
