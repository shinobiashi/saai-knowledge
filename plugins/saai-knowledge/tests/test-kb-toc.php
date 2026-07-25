<?php
/**
 * Tests for the kb-toc block's heading-count rendering threshold.
 *
 * @package SAAI\Knowledge
 */

/**
 * Class Test_Kb_Toc.
 */
class Test_Kb_Toc extends WP_UnitTestCase {

	/**
	 * A single heading gives a table of contents nothing to navigate
	 * between, so the block must render nothing — kb-layout.css's
	 * :not(:has(.saai-kb-toc)) rule (which hides the whole panel and
	 * collapses its grid column) relies on this.
	 */
	public function test_renders_nothing_for_a_single_heading() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => '<!-- wp:heading --><h2>Only Section</h2><!-- /wp:heading -->' .
					'<!-- wp:paragraph --><p>Body text.</p><!-- /wp:paragraph -->',
			)
		);
		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$output = do_blocks( '<!-- wp:saai-knowledge/kb-toc /-->' );

		$this->assertSame( '', trim( $output ) );
	}

	/**
	 * Two or more headings give the table of contents something to
	 * navigate between, so the block must render.
	 */
	public function test_renders_for_two_headings() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => '<!-- wp:heading --><h2>First Section</h2><!-- /wp:heading -->' .
					'<!-- wp:paragraph --><p>Body text.</p><!-- /wp:paragraph -->' .
					'<!-- wp:heading --><h2>Second Section</h2><!-- /wp:heading -->' .
					'<!-- wp:paragraph --><p>More text.</p><!-- /wp:paragraph -->',
			)
		);
		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$output = do_blocks( '<!-- wp:saai-knowledge/kb-toc /-->' );

		$this->assertStringContainsString( 'saai-kb-toc', $output );
		$this->assertStringContainsString( 'First Section', $output );
		$this->assertStringContainsString( 'Second Section', $output );
	}
}
