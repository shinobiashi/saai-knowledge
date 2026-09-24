<?php
/**
 * Adds the "Linked Products" panel to the block editor sidebar.
 *
 * @package SAAI\KnowledgeWoo
 */

namespace SAAI\KnowledgeWoo;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the PluginDocumentSettingPanel script on FAQ, knowledge base, and
 * glossary edit screens (docs/DESIGN.md section 6.1, content side).
 */
final class Content_Editor {

	/**
	 * Script handle.
	 *
	 * @var string
	 */
	public const HANDLE = 'saai-knowledge-woo-linked-products-panel';

	/**
	 * Hooks the editor asset registration into WordPress.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_panel_script' ) );
	}

	/**
	 * Enqueues the panel script only while editing linkable content.
	 */
	public function enqueue_panel_script(): void {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->post_type, Post_Meta::POST_TYPES, true ) ) {
			return;
		}

		$asset_file   = SAAI_KNOWLEDGE_WOO_DIR . 'assets/js/linked-products-panel.asset.php';
		$dependencies = array( 'wp-components', 'wp-compose', 'wp-core-data', 'wp-data', 'wp-editor', 'wp-element', 'wp-html-entities', 'wp-i18n', 'wp-plugins' );
		$version      = SAAI_KNOWLEDGE_WOO_VERSION;

		// The script is hand-written rather than bundled today (the add-on has
		// no build step until the product blocks land in M5-3). Reading an
		// asset file when one exists keeps this call site unchanged once
		// wp-scripts starts generating one, matching the free plugin's
		// Glossary_Editor.
		if ( file_exists( $asset_file ) ) {
			$asset        = require $asset_file;
			$dependencies = $asset['dependencies'];
			$version      = $asset['version'];
		}

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/js/linked-products-panel.js', SAAI_KNOWLEDGE_WOO_DIR . 'saai-knowledge-for-woocommerce.php' ),
			$dependencies,
			$version,
			true
		);

		wp_set_script_translations( self::HANDLE, 'saai-knowledge-for-woocommerce' );

		// Deliberately not $version: once a build generates the asset file,
		// that variable becomes the JS bundle's hash, which would not change
		// when only this stylesheet is edited.
		wp_enqueue_style(
			self::HANDLE,
			plugins_url( 'assets/css/linked-products-panel.css', SAAI_KNOWLEDGE_WOO_DIR . 'saai-knowledge-for-woocommerce.php' ),
			array( 'wp-components' ),
			SAAI_KNOWLEDGE_WOO_VERSION
		);
	}
}
