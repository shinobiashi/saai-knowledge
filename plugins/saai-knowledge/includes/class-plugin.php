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
		( new Term_Order() )->register();
		( new Template_Loader() )->register();
		( new Heading_Anchors() )->register();
		( new Faq_List() )->register();
		( new Faq_Question() )->register();
		( new Glossary_Term() )->register();
		( new Blocks() )->register();
		( new Shortcodes() )->register();

		$this->autolinker = new Autolinker();
		$this->autolinker->register();

		if ( is_admin() ) {
			( new Glossary_Editor() )->register();
		}
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
	 */
	public static function activate(): void {
		( new Post_Types() )->register_post_types();
		( new Taxonomies() )->register_taxonomies();

		flush_rewrite_rules();
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
