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
 * On requests where the auto-link engine actually produced a term link,
 * prints the single `#saai-tooltip` element every term anchor's
 * aria-describedby points at and enqueues the saai-knowledge/tooltip
 * script module + its style that drive it.
 */
final class Tooltip {

	/**
	 * The saai-knowledge/tooltip script module id.
	 *
	 * By WordPress Script Modules convention this doubles as the module's
	 * Interactivity API store namespace, so it's public: Autolinker::build_anchor()
	 * (class-autolinker.php) reuses it as the anchor's data-wp-interactive
	 * value instead of duplicating the string as its own literal, and
	 * view.js's own store() call must keep matching it by hand (a JS build
	 * can't reference a PHP const).
	 *
	 * @var string
	 */
	public const MODULE_ID = 'saai-knowledge/tooltip';

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
	 * Whether render() has already printed the singleton element this
	 * request. Some themes/plugins call wp_footer() (or get_footer())
	 * more than once per request; without this guard a second call would
	 * print a second `#saai-tooltip` element, and duplicate ids break the
	 * uniqueness `aria-describedby` relies on.
	 *
	 * @var bool
	 */
	private $rendered = false;

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
	 * This is not a coin-flip on registration order: WP_Hook's own
	 * contract (wp-includes/class-wp-hook.php) guarantees "functions with
	 * the same priority are executed in the order in which they were added
	 * to the filter," and PHP's array insertion order backs that guarantee
	 * deterministically — verified against core source, not assumed.
	 * This does mean a link an unusually late (later-priority) wp_footer
	 * callback produces after render() already ran won't get a tooltip;
	 * that's an accepted trade-off against actually breaking the common case.
	 */
	public function register(): void {
		add_action( 'wp_footer', array( $this, 'render' ) );
	}

	/**
	 * Prints the singleton tooltip element and enqueues its module/style,
	 * but only when the auto-link engine reports it actually linked a term
	 * this request — most pages never do, and shouldn't pay for either.
	 */
	public function render(): void {
		if ( $this->rendered || ! $this->autolinker->has_rendered_links() || ! $this->ensure_assets_registered() ) {
			return;
		}

		$this->rendered = true;

		wp_enqueue_script_module( self::MODULE_ID );
		wp_enqueue_style( self::STYLE_HANDLE );

		echo '<div id="saai-tooltip" class="saai-tooltip" role="tooltip" hidden></div>';
	}

	/**
	 * Registers the tooltip module/style from their build/ metadata, unless
	 * they're registered already. Called from render() instead of eagerly
	 * on every request's `init` (a prior version did that): the
	 * file_exists()/include/two registry writes this does only need to run
	 * once per process, and only for requests that reach this — has
	 * has_rendered_links() true — while every other front-end request
	 * (the overwhelming majority; admin, cron, and REST requests never
	 * reach wp_footer at all) skips it entirely.
	 *
	 * The "already registered" check only asks wp_style_is() — not also
	 * WP_Script_Modules::get_registered() for the module, which a prior
	 * version of this method did — because get_registered() doesn't exist
	 * before WordPress core 7.0.0 (`@since 7.0.0` in
	 * wp-includes/class-wp-script-modules.php), a fatal error on this
	 * plugin's declared 6.9+ minimum. The style and module are always
	 * registered together below, so the style's registration state alone
	 * is a reliable proxy for "this method already ran successfully."
	 *
	 * @return bool Whether both are registered (freshly, or already were).
	 */
	private function ensure_assets_registered(): bool {
		if ( wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			return true;
		}

		$asset_file = SAAI_KNOWLEDGE_DIR . 'build/tooltip/view.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return false;
		}

		$style_file = SAAI_KNOWLEDGE_DIR . 'build/tooltip/style-view.css';

		// Checked up front, alongside $asset_file above, so a build that has
		// landed view.asset.php/view.js but not yet style-view.css (e.g. an
		// atomic deploy swap mid-transfer) fails this method entirely rather
		// than registering wp_enqueue_style() against a file that 404s —
		// render() then retries on the next request instead of leaving a
		// broken <link> cached for the rest of this one.
		if ( ! file_exists( $style_file ) ) {
			return false;
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

		// The JS asset's version hash is computed from the JS bundle only
		// (the CSS is a separately-extracted file); reusing it for the
		// stylesheet would mean a CSS-only change doesn't bust the
		// browser/CDN cache for style-view.css, since that hash wouldn't
		// change (verified: rebuilding after a style.scss-only edit leaves
		// view.asset.php's version identical). The CSS file's own mtime
		// gives it an independent, correctly-changing version instead.
		//
		// filemtime() alone (no separate file_exists() first) avoids a
		// TOCTOU window where the file is removed/replaced between the two
		// calls (e.g. an atomic deploy swap mid-request): filemtime()
		// returns false in that case, which is checked explicitly so the
		// version falls back to $version instead of silently becoming the
		// empty string `(string) false` would produce — an empty $ver
		// tells wp_register_style() "no version", disabling cache-busting
		// for style-view.css until the next successful registration. A
		// literal epoch-0 mtime (e.g. a reproducible-build pipeline that
		// normalizes timestamps to `SOURCE_DATE_EPOCH=0`) is treated the
		// same as a missing file rather than becoming a permanently frozen
		// version string of '0' that could never cache-bust again.
		$style_mtime   = @filemtime( $style_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- avoids the file_exists()+filemtime() TOCTOU window described above; filemtime()'s own false return (checked below) already covers a missing file.
		$style_version = $style_mtime > 0 ? (string) $style_mtime : $version;

		wp_register_style(
			self::STYLE_HANDLE,
			SAAI_KNOWLEDGE_URL . 'build/tooltip/style-view.css',
			array(),
			$style_version
		);

		return true;
	}
}
