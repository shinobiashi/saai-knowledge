<?php
/**
 * Add-on bootstrap: base-plugin/WooCommerce dependency gating.
 *
 * @package SAAI\KnowledgeWoo
 */

namespace SAAI\KnowledgeWoo;

defined( 'ABSPATH' ) || exit;

/**
 * Gates add-on startup on the free plugin and WooCommerce being present,
 * and boots Plugin once every requirement is satisfied.
 */
final class Bootstrap {

	/**
	 * Loads the bundled translation.
	 *
	 * Hooked on `init` at priority 5, independent of whether requirements
	 * are met, so the admin notices below are always translated.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'saai-knowledge-for-woocommerce', false, basename( SAAI_KNOWLEDGE_WOO_DIR ) . '/languages' );
	}

	/**
	 * Evaluates whether the add-on may boot.
	 *
	 * Kept as a pure function (no WordPress state) so every outcome can be
	 * tested directly, without installing or removing the free plugin or
	 * WooCommerce.
	 *
	 * @param string|null $base_version The free plugin's SAAI_KNOWLEDGE_VERSION, or null if it never booted.
	 * @param string|null $woo_version  WooCommerce's WC_VERSION, or null if the WooCommerce class doesn't exist.
	 * @return string One of 'ok', 'missing_base', 'outdated_base', 'missing_woocommerce', 'outdated_woocommerce'.
	 */
	public static function requirements_status( ?string $base_version, ?string $woo_version ): string {
		if ( null === $base_version ) {
			return 'missing_base';
		}

		if ( version_compare( $base_version, SAAI_WOO_MIN_BASE_VERSION, '<' ) ) {
			return 'outdated_base';
		}

		if ( null === $woo_version ) {
			return 'missing_woocommerce';
		}

		if ( version_compare( $woo_version, SAAI_WOO_MIN_WC_VERSION, '<' ) ) {
			return 'outdated_woocommerce';
		}

		return 'ok';
	}

	/**
	 * Fires once the free plugin has finished registering its own services.
	 *
	 * Per docs/DESIGN-HOOKS-API.md section 2, add-ons wait for this action
	 * rather than probing for the free plugin with class_exists()/function_exists().
	 *
	 * @param mixed $base The booted free-plugin instance (SAAI\Knowledge\Plugin).
	 */
	public static function on_saai_loaded( $base ): void {
		$status = self::requirements_status(
			defined( 'SAAI_KNOWLEDGE_VERSION' ) ? SAAI_KNOWLEDGE_VERSION : null,
			defined( 'WC_VERSION' ) ? WC_VERSION : null
		);

		if ( 'ok' !== $status ) {
			self::register_notice( $status );
			return;
		}

		Plugin::boot( $base );
	}

	/**
	 * Fallback for the free plugin never booting at all this request.
	 *
	 * Hooked on `plugins_loaded` at priority 21 — one after the free
	 * plugin's own `boot()` at priority 20 — so `did_action( 'saai_loaded' )`
	 * reliably reflects whether it ran.
	 */
	public static function check_base_plugin_loaded(): void {
		if ( did_action( 'saai_loaded' ) ) {
			return;
		}

		self::register_notice( 'missing_base' );
	}

	/**
	 * Registers an admin notice for the given requirement failure.
	 *
	 * Hooked on `all_admin_notices` rather than `admin_notices` — a network
	 * activation fires `network_admin_notices` on Network Admin screens
	 * instead, so `admin_notices` alone would leave a super admin looking at
	 * the network plugins list with no indication why this add-on is
	 * inactive. `all_admin_notices` fires on both (Codex review).
	 *
	 * @param string $status One of the non-'ok' requirements_status() outcomes.
	 */
	private static function register_notice( string $status ): void {
		add_action(
			'all_admin_notices',
			function () use ( $status ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				echo '<div class="notice notice-warning"><p>' . esc_html( self::notice_message( $status ) ) . '</p></div>';
			}
		);
	}

	/**
	 * Builds the human-readable message for a requirement failure.
	 *
	 * @param string $status One of the non-'ok' requirements_status() outcomes.
	 */
	public static function notice_message( string $status ): string {
		switch ( $status ) {
			case 'outdated_base':
				return sprintf(
					/* translators: %s: minimum required SAAI Knowledge version number */
					__( 'SAAI Knowledge for WooCommerce requires SAAI Knowledge version %s or later. Please update the SAAI Knowledge plugin.', 'saai-knowledge-for-woocommerce' ),
					SAAI_WOO_MIN_BASE_VERSION
				);

			case 'outdated_woocommerce':
				return sprintf(
					/* translators: %s: minimum required WooCommerce version number */
					__( 'SAAI Knowledge for WooCommerce requires WooCommerce version %s or later. Please update WooCommerce.', 'saai-knowledge-for-woocommerce' ),
					SAAI_WOO_MIN_WC_VERSION
				);

			case 'missing_woocommerce':
				return __( 'SAAI Knowledge for WooCommerce requires WooCommerce to be installed and active.', 'saai-knowledge-for-woocommerce' );

			case 'missing_base':
			default:
				return __( 'SAAI Knowledge for WooCommerce requires the free SAAI Knowledge plugin to be installed and active.', 'saai-knowledge-for-woocommerce' );
		}
	}
}
