<?php
/**
 * Tests for Bootstrap's requirement gating, hook wiring, and admin notices.
 *
 * @package SAAI\KnowledgeWoo
 */

use SAAI\KnowledgeWoo\Bootstrap;
use SAAI\KnowledgeWoo\Plugin;

/**
 * Class Test_Bootstrap.
 */
class Test_Bootstrap extends WP_UnitTestCase {

	/**
	 * Full snapshot of `all_admin_notices`' registered callbacks before the
	 * test, so tear_down() can restore it wholesale.
	 *
	 * A blind `remove_all_actions( 'all_admin_notices' )` would also strip
	 * the free plugin's own Autolinker::render_dictionary_truncated_notice()
	 * (registered on the same hook at bootstrap), leaking a side effect into
	 * every test that runs afterward in this process (Codex review) — the
	 * same failure mode Test_Plugin's `$wp_rewrite` clone/restore guards
	 * against for flush_rewrite_rules().
	 *
	 * @var \WP_Hook|null
	 */
	private $original_all_admin_notices;

	/**
	 * The real `Plugin::$instance` value before the test, so tear_down() can
	 * restore it.
	 *
	 * `Plugin::boot()` is a process-wide singleton guard with no public
	 * reset, and `phpunit.xml.dist` runs every testsuite (including the free
	 * plugin's) in one process. Leaving a test double installed here would
	 * make `Plugin::boot()` a permanent no-op — and `Plugin::instance()`
	 * keep returning a `stdClass` `base()` — for the rest of the run
	 * (Codex review).
	 *
	 * @var \SAAI\KnowledgeWoo\Plugin|null
	 */
	private $original_plugin_instance;

	/**
	 * Snapshots `all_admin_notices` and `Plugin::$instance` before the test.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_filter;
		$this->original_all_admin_notices = isset( $wp_filter['all_admin_notices'] ) ? clone $wp_filter['all_admin_notices'] : null;
		$this->original_plugin_instance   = self::plugin_instance_property()->getValue();
	}

	/**
	 * Restores `all_admin_notices` and `Plugin::$instance` to their pre-test
	 * snapshots, undoing only what this test itself added.
	 */
	public function tear_down() {
		global $wp_filter;

		if ( null !== $this->original_all_admin_notices ) {
			$wp_filter['all_admin_notices'] = $this->original_all_admin_notices; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the shared global to its pre-test snapshot, not introducing a new one.
		} else {
			unset( $wp_filter['all_admin_notices'] );
		}

		self::plugin_instance_property()->setValue( null, $this->original_plugin_instance );

		parent::tear_down();
	}

	/**
	 * Reflection accessor for the private static `Plugin::$instance`, which
	 * has no public reset — see $original_plugin_instance.
	 */
	private static function plugin_instance_property(): \ReflectionProperty {
		$property = new \ReflectionProperty( Plugin::class, 'instance' );
		$property->setAccessible( true );

		return $property;
	}

	/**
	 * Bootstrap::requirements_status() is a pure function; every outcome is exercised
	 * directly here rather than by installing/removing the free plugin or
	 * WooCommerce (not practical within a single PHPUnit process — see the
	 * plan's wp-env verification step for the real three-combination check).
	 */
	public function test_requirements_status_covers_every_outcome() {
		$this->assertSame( 'missing_base', Bootstrap::requirements_status( null, SAAI_WOO_MIN_WC_VERSION ) );
		$this->assertSame( 'outdated_base', Bootstrap::requirements_status( '0.9.0', SAAI_WOO_MIN_WC_VERSION ) );
		$this->assertSame( 'missing_woocommerce', Bootstrap::requirements_status( SAAI_WOO_MIN_BASE_VERSION, null ) );
		$this->assertSame( 'outdated_woocommerce', Bootstrap::requirements_status( SAAI_WOO_MIN_BASE_VERSION, '9.9' ) );
		$this->assertSame( 'ok', Bootstrap::requirements_status( SAAI_WOO_MIN_BASE_VERSION, SAAI_WOO_MIN_WC_VERSION ) );
	}

	/**
	 * The bootstrap file must wire on_saai_loaded() to `saai_loaded` and
	 * check_base_plugin_loaded() to `plugins_loaded` at priority 21 — one
	 * after the free plugin's own `boot()` at priority 20 — so
	 * did_action( 'saai_loaded' ) reliably reflects whether it ran.
	 */
	public function test_hooks_are_registered_with_expected_priorities() {
		$this->assertSame( 10, has_action( 'saai_loaded', array( Bootstrap::class, 'on_saai_loaded' ) ) );
		$this->assertSame( 21, has_action( 'plugins_loaded', array( Bootstrap::class, 'check_base_plugin_loaded' ) ) );
		$this->assertSame( 5, has_action( 'init', array( Bootstrap::class, 'load_textdomain' ) ) );
	}

	/**
	 * Plugin::boot() stores whatever base-plugin instance it's given and is a no-op
	 * on a second call, matching the free plugin's own Plugin::boot() guard.
	 *
	 * The real saai_loaded fired during test bootstrap without WooCommerce
	 * present (requirements_status() there resolves to missing_woocommerce),
	 * so Plugin::boot() has not run yet by the time this test executes.
	 */
	public function test_boot_is_idempotent_and_stores_base_plugin() {
		$this->assertNull( Plugin::instance(), 'Precondition: boot() must not already have run in this process.' );

		$first_base = new stdClass();
		Plugin::boot( $first_base );

		$this->assertNotNull( Plugin::instance() );
		$this->assertSame( $first_base, Plugin::instance()->base() );

		$second_base = new stdClass();
		Plugin::boot( $second_base );

		$this->assertSame( $first_base, Plugin::instance()->base(), 'A second boot() call must not replace the stored base plugin.' );
	}

	/**
	 * The missing-WooCommerce notice only renders for users who can activate
	 * plugins, and its output is escaped.
	 */
	public function test_missing_woocommerce_notice_is_capability_gated_and_escaped() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );

		Bootstrap::on_saai_loaded( new stdClass() );

		wp_set_current_user( $subscriber );
		ob_start();
		do_action( 'all_admin_notices' );
		$this->assertSame( '', ob_get_clean() );

		wp_set_current_user( $admin );
		ob_start();
		do_action( 'all_admin_notices' );
		$output = ob_get_clean();

		$this->assertStringContainsString( esc_html( Bootstrap::notice_message( 'missing_woocommerce' ) ), $output );
		$this->assertStringNotContainsString( '<script', $output );
	}
}
