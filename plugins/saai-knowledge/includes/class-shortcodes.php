<?php
/**
 * Registers the classic-theme shortcode wrappers for the plugin's blocks.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Provides thin shortcode wrappers ([saai_kb_sidebar] etc.) that render the
 * corresponding dynamic block, so classic-theme users get identical output.
 */
final class Shortcodes {

	/**
	 * Shortcode tags mapped to the block each one renders.
	 *
	 * @var array<string, string>
	 */
	private const SHORTCODE_BLOCKS = array(
		'saai_kb_sidebar'  => 'saai-knowledge/kb-sidebar',
		'saai_kb_toc'      => 'saai-knowledge/kb-toc',
		'saai_breadcrumbs' => 'saai-knowledge/breadcrumbs',
	);

	/**
	 * Hooks shortcode registration into WordPress.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_shortcodes' ) );
	}

	/**
	 * Registers all shortcode wrappers.
	 */
	public function register_shortcodes(): void {
		foreach ( array_keys( self::SHORTCODE_BLOCKS ) as $tag ) {
			add_shortcode( $tag, array( $this, 'render_shortcode' ) );
		}
	}

	/**
	 * Renders the block backing a shortcode tag.
	 *
	 * The render_block() call supplies the same default context (postId and
	 * postType from the global $post) the block gets in post content, and
	 * enqueues the block's view assets, so the output matches the block.
	 *
	 * @param array<string, string>|string $atts    Shortcode attributes. Unused; the wrapped blocks take none.
	 * @param string|null                  $content Enclosed content. Unused.
	 * @param string                       $tag     The matched shortcode tag.
	 * @return string
	 */
	public function render_shortcode( $atts, $content, string $tag ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- kept to match the add_shortcode() callback signature.
		$block_name = self::SHORTCODE_BLOCKS[ $tag ] ?? '';

		if ( '' === $block_name || ! \WP_Block_Type_Registry::get_instance()->is_registered( $block_name ) ) {
			return '';
		}

		return render_block(
			array(
				'blockName'    => $block_name,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}
}
