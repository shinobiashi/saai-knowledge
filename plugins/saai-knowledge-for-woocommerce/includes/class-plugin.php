<?php
/**
 * Add-on plugin instance, booted once requirements are satisfied.
 *
 * @package SAAI\KnowledgeWoo
 */

namespace SAAI\KnowledgeWoo;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the add-on and registers its internal services.
 */
final class Plugin {

	/**
	 * Booted singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * The free plugin instance this add-on extends.
	 *
	 * @var mixed SAAI\Knowledge\Plugin, kept untyped per docs/DESIGN-HOOKS-API.md section 2
	 *            (add-ons consume it only through its documented public methods).
	 */
	private $base;

	/**
	 * Registers services once Bootstrap has confirmed every requirement is met.
	 *
	 * @param mixed $base The booted free-plugin instance (SAAI\Knowledge\Plugin).
	 */
	public static function boot( $base ): void {
		if ( null !== self::$instance ) {
			return;
		}

		self::$instance = new self( $base );
		self::$instance->register_services();
	}

	/**
	 * Returns the booted singleton instance, or null if boot() hasn't run yet.
	 *
	 * @return self|null
	 */
	public static function instance(): ?self {
		return self::$instance;
	}

	/**
	 * Stores the free plugin instance passed to boot().
	 *
	 * @param mixed $base The booted free-plugin instance (SAAI\Knowledge\Plugin).
	 */
	private function __construct( $base ) {
		$this->base = $base;
	}

	/**
	 * Registers the add-on's internal services.
	 *
	 * No concrete services exist yet; they are added incrementally starting
	 * with the product-linking meta in M5-2 (Issue #21).
	 */
	private function register_services(): void {
	}

	/**
	 * The free plugin instance this add-on extends.
	 *
	 * The only supported way for add-on services to reach the free plugin's
	 * public API (docs/DESIGN-HOOKS-API.md section 5).
	 *
	 * @return mixed SAAI\Knowledge\Plugin
	 */
	public function base() {
		return $this->base;
	}
}
