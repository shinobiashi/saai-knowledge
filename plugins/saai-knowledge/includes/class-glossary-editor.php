<?php
/**
 * Adds the glossary "reading"/"synonyms" panel to the block editor.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the DocumentSettingPanel script for saai_glossary.
 */
final class Glossary_Editor {

	/**
	 * Hooks the editor asset registration into WordPress.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_panel_script' ) );
	}

	/**
	 * Enqueues the glossary panel script only when editing a saai_glossary post.
	 */
	public function enqueue_panel_script(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'saai_glossary' !== $screen->post_type ) {
			return;
		}

		$asset_file   = SAAI_KNOWLEDGE_DIR . 'assets/js/glossary-panel.asset.php';
		$dependencies = array( 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-components', 'wp-core-data', 'wp-element', 'wp-i18n' );
		$version      = SAAI_KNOWLEDGE_VERSION;

		if ( file_exists( $asset_file ) ) {
			$asset        = require $asset_file;
			$dependencies = $asset['dependencies'];
			$version      = $asset['version'];
		}

		wp_enqueue_script(
			'saai-knowledge-glossary-panel',
			plugins_url( 'assets/js/glossary-panel.js', SAAI_KNOWLEDGE_DIR . 'saai-knowledge.php' ),
			$dependencies,
			$version,
			true
		);

		wp_set_script_translations( 'saai-knowledge-glossary-panel', 'saai-knowledge' );
	}
}
