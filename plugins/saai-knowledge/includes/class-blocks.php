<?php
/**
 * Registers the plugin's blocks.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Registers all saai-knowledge/* dynamic blocks from their build/ metadata.
 */
final class Blocks {

	/**
	 * Block directory names under build/, relative to the plugin root.
	 *
	 * @var string[]
	 */
	private const BLOCKS = array(
		'kb-sidebar',
		'kb-toc',
		'breadcrumbs',
	);

	/**
	 * Hooks block registration into WordPress.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Registers each block from its build/ directory.
	 */
	public function register_blocks(): void {
		foreach ( self::BLOCKS as $block ) {
			$path = SAAI_KNOWLEDGE_DIR . "build/{$block}";

			if ( file_exists( $path . '/block.json' ) ) {
				register_block_type( $path );
			}
		}
	}
}
