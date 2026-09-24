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
	 * The product <-> content link resolver.
	 *
	 * @var Link_Resolver|null
	 */
	private $link_resolver = null;

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
	 * Services are added incrementally per milestone; product page output
	 * follows in M5-3 (Issue #22).
	 */
	private function register_services(): void {
		( new Post_Meta() )->register();

		$this->link_resolver = new Link_Resolver();

		// Not inside the is_admin() branch: the routes are registered on
		// `rest_api_init`, which a REST request reaches without is_admin()
		// being true.
		( new Links_Controller( $this->link_resolver ) )->register();

		if ( is_admin() ) {
			( new Content_Editor() )->register();
			( new Product_Metabox() )->register();
		}
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

	/**
	 * The product <-> content link resolver.
	 *
	 * The supported entry point for the add-on's own later milestones (the
	 * product page output in M5-3, the export metadata in M5-5) so the
	 * resolution rule in docs/DESIGN.md section 6.1 has one implementation.
	 *
	 * @return Link_Resolver
	 */
	public function link_resolver(): Link_Resolver {
		if ( null === $this->link_resolver ) {
			// register_services() always constructs this before boot()
			// returns; this branch only exists to satisfy static analysis,
			// not any real call path.
			$this->link_resolver = new Link_Resolver();
		}

		return $this->link_resolver;
	}
}
