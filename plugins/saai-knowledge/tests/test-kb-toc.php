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
	 * Renders src/kb-toc/render.php directly, replicating the variable
	 * contract WordPress core sets up for a block.json "render" callback
	 * (see register_block_type_from_metadata() in wp-includes/blocks.php:
	 * ob_start(); require $template_path; return ob_get_clean();) — rather
	 * than registering the block and rendering it via do_blocks(), which
	 * would depend on the webpack-built build/kb-toc/ directory that CI's
	 * PHP-only workflow (composer test) never generates (see CLAUDE.md on
	 * build/ vs src/).
	 *
	 * $block is a plain stdClass, not a real WP_Block: render.php only ever
	 * reads $block->context['postId'], which doesn't need the real class.
	 *
	 * @param int $post_id Post to render the table of contents for.
	 * @return string
	 */
	private function render_kb_toc( int $post_id ): string {
		$attributes = array();
		$content    = '';
		$block      = (object) array( 'context' => array( 'postId' => $post_id ) );

		// get_block_wrapper_attributes() (called by render.php) reads this
		// static to look up the block's registered supports; real rendering
		// sets it via WP_Block::render() before invoking the render
		// callback. An unregistered blockName is handled gracefully
		// (WP_Block_Supports::apply_block_supports() just returns no extra
		// attributes), so this doesn't need the block actually registered.
		$previous_block_to_render            = \WP_Block_Supports::$block_to_render;
		\WP_Block_Supports::$block_to_render = array(
			'blockName' => 'saai-knowledge/kb-toc',
			'attrs'     => $attributes,
		);

		ob_start();
		require SAAI_KNOWLEDGE_DIR . 'src/kb-toc/render.php';
		$output = ob_get_clean();

		\WP_Block_Supports::$block_to_render = $previous_block_to_render;

		return $output;
	}

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

		$output = $this->render_kb_toc( $post_id );

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

		$output = $this->render_kb_toc( $post_id );

		$this->assertStringContainsString( 'saai-kb-toc', $output );
		$this->assertStringContainsString( 'First Section', $output );
		$this->assertStringContainsString( 'Second Section', $output );
	}
}
