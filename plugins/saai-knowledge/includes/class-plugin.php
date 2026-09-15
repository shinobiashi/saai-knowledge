<?php
/**
 * Core plugin bootstrap and service registration.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the free version and registers its internal services.
 */
final class Plugin {

	/**
	 * Option storing the plugin version as of the last rewrite-rule flush
	 * this class triggered, so maybe_flush_rewrite_rules_on_upgrade() can
	 * detect a version change that happened without activate() running.
	 *
	 * @var string
	 */
	private const VERSION_OPTION = 'saai_knowledge_version';

	/**
	 * Booted singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * The auto-link engine service.
	 *
	 * @var Autolinker|null
	 */
	private $autolinker = null;

	/**
	 * Registers services and signals that the free version is ready.
	 *
	 * Hooked on `plugins_loaded` at priority 20.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}

		self::$instance = new self();
		self::$instance->register_services();

		/**
		 * Fires once all free-version services have been registered.
		 *
		 * Add-ons should wait for this action before initializing;
		 * see docs/DESIGN-HOOKS-API.md section 2.
		 *
		 * @since 0.1.0
		 *
		 * @param Plugin $plugin The booted plugin instance.
		 */
		do_action( 'saai_loaded', self::$instance );
	}

	/**
	 * Registers the plugin's internal services.
	 *
	 * Concrete services (blocks, REST routes, etc.) are added
	 * incrementally in later milestones.
	 */
	private function register_services(): void {
		( new Post_Types() )->register();
		( new Taxonomies() )->register();
		( new Post_Meta() )->register();
		( new Settings() )->register();
		( new Term_Order() )->register();
		( new Sidebar_Tree() )->register();
		( new Template_Loader() )->register();
		( new Heading_Anchors() )->register();
		( new Faq_List() )->register();
		( new Faq_Question() )->register();
		( new Glossary_Term() )->register();
		( new Glossary_Index() )->register();
		( new Search() )->register();
		( new Blocks() )->register();
		( new Shortcodes() )->register();
		( new Markdown_Output() )->register();
		( new Llms_Index() )->register();
		( new Llms_Txt_Adapter() )->register();
		( new Export() )->register();

		$this->autolinker = new Autolinker();
		$this->autolinker->register();
		( new Tooltip( $this->autolinker ) )->register();

		if ( is_admin() ) {
			( new Glossary_Editor() )->register();
		}

		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules_on_upgrade' ), 20 );
	}

	/**
	 * Flushes rewrite rules once after a version change picked up outside
	 * activate() — e.g. a WordPress.org auto-update via
	 * Plugin_Upgrader::upgrade()/bulk_upgrade(), which replaces the plugin
	 * files but never runs the activation hook (Codex review). Without
	 * this, a route a new version adds (Llms_Index's `/{kb slug}/llms.txt`,
	 * say) would 404 on every already-installed site until an unrelated
	 * event (a slug change, a manual Settings > Permalinks re-save)
	 * happened to flush again.
	 *
	 * Priority 20: after Post_Types/Taxonomies/Llms_Index have all
	 * registered their rewrite rules at the default priority 10 on this
	 * same `init`.
	 */
	public function maybe_flush_rewrite_rules_on_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === SAAI_KNOWLEDGE_VERSION ) {
			return;
		}

		flush_rewrite_rules();
		update_option( self::VERSION_OPTION, SAAI_KNOWLEDGE_VERSION, false );
	}

	/**
	 * The auto-link engine service.
	 *
	 * Public per docs/DESIGN-HOOKS-API.md section 5 — the only supported way
	 * for the paid add-on (or any other saai_loaded consumer) to reach it.
	 *
	 * @return Autolinker
	 */
	public function autolinker(): Autolinker {
		if ( null === $this->autolinker ) {
			// register_services() always constructs this before saai_loaded
			// fires; this branch only exists to satisfy static analysis, not
			// any real call path.
			$this->autolinker = new Autolinker();
		}

		return $this->autolinker;
	}

	/**
	 * Runs on plugin activation.
	 *
	 * The post types and taxonomies are normally registered on `init`, but
	 * that hook has already fired earlier in the same request by the time
	 * the activation callback runs (the plugin file is only `include`d
	 * inside `activate_plugin()`, after WordPress's own `init`). Register
	 * them directly here so the first flush includes their rewrite rules.
	 *
	 * Llms_Index::add_rewrite_rule() needs the same direct call for the
	 * same reason — without it, `/{kb slug}/llms.txt` would 404 on a fresh
	 * install until some unrelated later event (a slug change, a manual
	 * permalinks re-save) happens to trigger another flush (Codex review).
	 */
	public static function activate(): void {
		( new Post_Types() )->register_post_types();
		( new Taxonomies() )->register_taxonomies();
		( new Llms_Index() )->add_rewrite_rule();

		flush_rewrite_rules();

		// Records the current version as already flushed, so the next
		// request's maybe_flush_rewrite_rules_on_upgrade() doesn't also
		// flush a second time for the version this activation already
		// covered.
		update_option( self::VERSION_OPTION, SAAI_KNOWLEDGE_VERSION, false );
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
