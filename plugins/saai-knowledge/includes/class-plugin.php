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

		if ( is_admin() ) {
			( new Glossary_Editor() )->register();
		}
	}

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
