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
	 * The singleton tooltip element's `id`, reused as-is for the value every
	 * term anchor's `aria-describedby` points at.
	 *
	 * Public for the same reason as MODULE_ID above: Autolinker::build_anchor()
	 * reuses it instead of duplicating the string as its own literal.
	 * view.js's own TOOLTIP_ID constant still has to match it by hand (a JS
	 * build can't reference a PHP const).
	 *
	 * @var string
	 */
	public const ELEMENT_ID = 'saai-tooltip';

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
	 * @param Autolinker $autolinker The auto-link engine service, consulted by maybe_enqueue_assets().
	 */
	public function __construct( Autolinker $autolinker ) {
		$this->autolinker = $autolinker;
	}

	/**
	 * Hooks the tooltip service into WordPress.
	 *
	 * Maybe_enqueue_assets() cannot always wait for wp_footer the way an
	 * earlier version of this method did. As of WordPress 6.9.0 (this
	 * plugin's declared minimum — verified against
	 * wp-includes/class-wp-script-modules.php's `@since 6.9.0` tags, not
	 * assumed), WP_Script_Modules::add_hooks() prints a SINGLE import map
	 * once per request — at `wp_head` for a block theme, `wp_footer` for a
	 * classic one — built from get_import_map(), which only pulls in the
	 * DEPENDENCIES (e.g. `@wordpress/interactivity`, our module's static
	 * import) of modules already sitting in the internal enqueue queue at
	 * that exact moment; a module enqueued later doesn't retroactively
	 * contribute to it, and only one importmap `<script>` is ever printed
	 * (a second, later one wouldn't apply per the HTML spec anyway). Core's
	 * `print_enqueued_script_modules()` still unconditionally prints our
	 * module's own `<script type="module">` tag at `wp_footer` regardless
	 * of theme (so the tag itself isn't silently dropped), but on a block
	 * theme that's too late for the import map: if our module was enqueued
	 * only at wp_footer, as before, and no OTHER script module already
	 * needed `@wordpress/interactivity` by wp_head time, the browser has
	 * no import-map entry to resolve view.js's own `import { store,
	 * getElement } from '@wordpress/interactivity'` bare specifier against,
	 * so the module throws and the store never registers — silently
	 * breaking every tooltip on the page. A block theme's entire template
	 * (including any term links our anchors carry) is rendered to a string
	 * BEFORE wp_head() runs — wp-includes/template-canvas.php calls
	 * get_the_block_template_html() first specifically so blocks can add
	 * head output, per its own comment — so has_rendered_links() is already
	 * known by wp_head time and there's no reason to wait for wp_footer. A
	 * classic theme doesn't have this problem (its import map itself is
	 * printed at wp_footer, by which point we've already enqueued) but has
	 * the opposite one: at wp_head time its main content loop hasn't run
	 * yet, so has_rendered_links() isn't known and it still has to enqueue
	 * at wp_footer. wp_is_block_theme() is reliable this early: the active
	 * theme is already loaded by the time plugins_loaded fires
	 * Plugin::register_services() (which constructs and registers this
	 * class), the same reasoning already established elsewhere in this
	 * codebase (see Template_Loader).
	 *
	 * Both actions stay at their hook's default priority (10), not a later
	 * one: at the same default priority, this plugin's own add_action()
	 * calls — fired from plugins_loaded — are registered before core's own
	 * wp_head/wp_footer printers (fired from after_setup_theme, later in
	 * the request), so maybe_enqueue_assets()/render() still run first
	 * within that bucket and their calls are seen in time (verified:
	 * enqueuing from a later priority, e.g. PHP_INT_MAX, broke real
	 * output). This is not a coin-flip on registration order: WP_Hook's
	 * own contract (wp-includes/class-wp-hook.php) guarantees "functions
	 * with the same priority are executed in the order in which they were
	 * added to the filter," and PHP's array insertion order backs that
	 * guarantee deterministically. This does mean a link an unusually late
	 * (later-priority) wp_head/wp_footer callback produces after
	 * maybe_enqueue_assets() already ran won't get a tooltip; that's an
	 * accepted trade-off against actually breaking the common case.
	 */
	public function register(): void {
		add_action( wp_is_block_theme() ? 'wp_head' : 'wp_footer', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render' ) );
	}

	/**
	 * Enqueues the tooltip module/style, but only when the auto-link engine
	 * reports it actually linked a term this request — most pages never
	 * do, and shouldn't pay for either. Idempotent and safe to call more
	 * than once per request: register() hooks it directly for the
	 * wp_head/wp_footer split described in its own docblock, and render()
	 * also calls it so a classic theme (where nothing else calls this
	 * before render() runs) still gets the assets enqueued before the
	 * singleton element that depends on them is printed.
	 *
	 * No separate "already enqueued" instance flag: the style and module are
	 * always enqueued together below, so — same precedent as
	 * ensure_assets_registered() using the style's `registered` state as a
	 * proxy for the module's — `wp_style_is( ..., 'enqueued' )` is a reliable,
	 * already-available proxy for "this method already ran successfully",
	 * without duplicating that state in a property that could drift from it.
	 */
	public function maybe_enqueue_assets(): void {
		if ( wp_style_is( self::STYLE_HANDLE, 'enqueued' ) || ! $this->autolinker->has_rendered_links() || ! $this->ensure_assets_registered() ) {
			return;
		}

		wp_enqueue_script_module( self::MODULE_ID );
		wp_enqueue_style( self::STYLE_HANDLE );
	}

	/**
	 * Prints the singleton tooltip element, once per request, once
	 * maybe_enqueue_assets() confirms there's something for it to drive
	 * (a term was actually linked, and the built assets are available).
	 */
	public function render(): void {
		if ( $this->rendered ) {
			return;
		}

		$this->maybe_enqueue_assets();

		if ( ! wp_style_is( self::STYLE_HANDLE, 'enqueued' ) ) {
			return;
		}

		$this->rendered = true;

		printf(
			'<div id="%s" class="saai-tooltip" role="tooltip" hidden></div>',
			esc_attr( self::ELEMENT_ID )
		);
	}

	/**
	 * Registers the tooltip module/style from their build/ metadata, unless
	 * they're registered already. Called from maybe_enqueue_assets() instead
	 * of eagerly on every request's `init` (a prior version did that): the
	 * file_exists()/include/two registry writes this does only need to run
	 * once per process, and only for requests that reach this — has
	 * has_rendered_links() true — while every other front-end request
	 * (the overwhelming majority; admin, cron, and REST requests never
	 * reach wp_head/wp_footer at all) skips it entirely.
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
		// maybe_enqueue_assets() then retries on the next request instead of
		// leaving a broken <link> cached for the rest of this one.
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
