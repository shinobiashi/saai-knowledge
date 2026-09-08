<?php
/**
 * Plugin Name:          SAAI Knowledge for WooCommerce
 * Plugin URI:           https://woocommerce.com/products/saai-knowledge-for-woocommerce/
 * Description:          Links FAQ / Knowledge Base / Glossary content from SAAI Knowledge to WooCommerce products and product categories, and displays it on product pages.
 * Version:              0.1.0
 * Requires at least:    6.9
 * Requires PHP:         8.2
 * Requires Plugins:     woocommerce, saai-knowledge
 * WC requires at least: 10.9
 * WC tested up to:      11.1
 * Author:               Shinobiashi
 * License:              GPL v2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          saai-knowledge-for-woocommerce
 * Domain Path:          /languages
 *
 * @package SAAI\KnowledgeWoo
 */

defined( 'ABSPATH' ) || exit;

define( 'SAAI_KNOWLEDGE_WOO_VERSION', '0.1.0' );
define( 'SAAI_KNOWLEDGE_WOO_DIR', plugin_dir_path( __FILE__ ) );
define( 'SAAI_KNOWLEDGE_WOO_URL', plugin_dir_url( __FILE__ ) );
define( 'SAAI_WOO_MIN_BASE_VERSION', '1.0.0' );

spl_autoload_register(
	function ( $fqcn ) {
		$prefix = 'SAAI\\KnowledgeWoo\\';

		if ( 0 !== strpos( $fqcn, $prefix ) ) {
			return;
		}

		$relative   = substr( $fqcn, strlen( $prefix ) );
		$parts      = explode( '\\', $relative );
		$class_name = array_pop( $parts );
		$file_name  = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
		$directory  = SAAI_KNOWLEDGE_WOO_DIR . 'includes/' . ( $parts ? implode( '/', $parts ) . '/' : '' );
		$path       = $directory . $file_name;

		if ( file_exists( $path ) ) {
			require $path;
		}
	}
);

add_action(
	'before_woocommerce_init',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
);

add_action( 'init', array( \SAAI\KnowledgeWoo\Bootstrap::class, 'load_textdomain' ), 5 );
add_action( 'saai_loaded', array( \SAAI\KnowledgeWoo\Bootstrap::class, 'on_saai_loaded' ) );
add_action( 'plugins_loaded', array( \SAAI\KnowledgeWoo\Bootstrap::class, 'check_base_plugin_loaded' ), 21 );
