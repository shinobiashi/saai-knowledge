<?php
/**
 * Renders the singleton tooltip element the auto-link engine's term
 * anchors describe themselves with, per docs/DESIGN-AUTOLINK.md section 3.3.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the saai-knowledge/tooltip script module and, only on requests
 * where the auto-link engine actually produced a term link, prints the
 * single `#saai-tooltip` element every term anchor's aria-describedby
 * points at and enqueues the module/style that drive it.
 */
final class Tooltip {

	/**
	 * The saai-knowledge/tooltip script module id.
	 *
	 * @var string
	 */
	private const MODULE_ID = 'saai-knowledge/tooltip';

	/**
	 * The tooltip stylesheet's registered handle.
	 *
	 * @var string
	 */
	private const STYLE_HANDLE = 'saai-knowledge-tooltip';

	/**
	 * The auto-link engine, consulted for whether the tooltip is needed.
	 *
	 * @var Autolinker
	 */
	private $autolinker;

	/**
	 * Constructor.
	 *
	 * @param Autolinker $autolinker The auto-link engine service, consulted by render().
	 */
	public function __construct( Autolinker $autolinker ) {
		$this->autolinker = $autolinker;
	}

	/**
	 * Hooks the tooltip service into WordPress.
	 *
	 * Render() deliberately stays at wp_footer's default priority (10),
	 * not a later one: WordPress core's own printers for what render()
	 * enqueues — WP_Script_Modules::print_enqueued_script_modules()
	 * (default priority) and script-loader.php's late-style capture
	 * (priority 20) — are both hooked on wp_footer too, at fixed
	 * priorities. Enqueuing from a later priority than those would queue
	 * the module/style only after WordPress already printed everything
	 * queued at that point, so they'd never reach the page (verified: this
	 * broke real output when tried at PHP_INT_MAX). At the same default
	 * priority, this plugin's own add_action() call — fired from
	 * plugins_loaded — is registered before core's (fired from
	 * after_setup_theme, later in the request), so render() still runs
	 * first within that bucket and its enqueue calls are seen in time.
	 * This does mean a link an unusually late (later-priority) wp_footer
	 * callback produces after render() already ran won't get a tooltip;
	 * that's an accepted trade-off against actually breaking the common case.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'wp_footer', array( $this, 'render' ) );
	}

	/**
	 * Registers the tooltip script module and stylesheet from their build/
	 * metadata. Registration doesn't enqueue: render() only enqueues once it
	 * knows the current page actually contains a term link.
	 */
	public function register_assets(): void {
		$asset_file = SAAI_KNOWLEDGE_DIR . 'build/tooltip/view.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset        = include $asset_file;
		$dependencies = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$version      = isset( $asset['version'] ) && is_string( $asset['version'] ) ? $asset['version'] : SAAI_KNOWLEDGE_VERSION;

		wp_register_script_module(
			self::MODULE_ID,
			SAAI_KNOWLEDGE_URL . 'build/tooltip/view.js',
			$dependencies,
			$version
		);

		wp_register_style(
			self::STYLE_HANDLE,
			SAAI_KNOWLEDGE_URL . 'build/tooltip/style-view.css',
			array(),
			$version
		);
	}

	/**
	 * Prints the singleton tooltip element and enqueues its assets, but only
	 * when the auto-link engine reports it actually linked a term this
	 * request — most pages never do, and shouldn't pay for the module/style.
	 */
	public function render(): void {
		if ( ! $this->assets_are_registered() || ! $this->autolinker->has_rendered_links() ) {
			return;
		}

		wp_enqueue_script_module( self::MODULE_ID );
		wp_enqueue_style( self::STYLE_HANDLE );

		echo '<div id="saai-tooltip" class="saai-tooltip" role="tooltip" hidden></div>';
	}

	/**
	 * Whether register_assets() found build/tooltip/view.asset.php and
	 * registered the module/style, asked of core's own registries rather
	 * than tracked in a separate flag here (which could drift from what's
	 * actually registered if register_assets()'s early-return condition
	 * ever changes without a matching update to the flag). CI's PHPUnit job
	 * runs without a JS build (build/ is gitignored — see class-blocks.php's
	 * same file_exists() guard), so render() must not try to enqueue a
	 * module/style that were never registered.
	 *
	 * @return bool
	 */
	private function assets_are_registered(): bool {
		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			return false;
		}

		// @phpstan-ignore method.notFound (WP_Script_Modules::get_registered() shipped in WordPress core 6.9.0 but is missing from the bundled php-stubs/wordpress-stubs 6.9.4; verified against the real method in wp-includes/class-wp-script-modules.php.)
		return null !== wp_script_modules()->get_registered( self::MODULE_ID );
	}
}
