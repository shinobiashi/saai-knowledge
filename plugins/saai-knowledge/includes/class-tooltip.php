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
	 * Whether register_assets() found build/tooltip/view.asset.php and
	 * registered the module/style. CI's PHPUnit job runs without a JS build
	 * (build/ is gitignored — see class-blocks.php's same file_exists()
	 * guard), so render() must not try to enqueue a module/style that were
	 * never registered.
	 *
	 * @var bool
	 */
	private $assets_registered = false;

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

		$this->assets_registered = true;
	}

	/**
	 * Prints the singleton tooltip element and enqueues its assets, but only
	 * when the auto-link engine reports it actually linked a term this
	 * request — most pages never do, and shouldn't pay for the module/style.
	 */
	public function render(): void {
		if ( ! $this->assets_registered || ! $this->autolinker->has_rendered_links() ) {
			return;
		}

		wp_enqueue_script_module( self::MODULE_ID );
		wp_enqueue_style( self::STYLE_HANDLE );

		echo '<div id="saai-tooltip" class="saai-tooltip" role="tooltip" hidden></div>';
	}
}
