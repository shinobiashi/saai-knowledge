<?php
/**
 * Plugin Name:       SAAI Knowledge
 * Plugin URI:        https://github.com/shinobiashi/saai-knowledge
 * Description:       FAQ / Knowledge Base / Glossary content types with a two-column KB layout, term auto-linking, and live search.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Shinobiashi
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       saai-knowledge
 *
 * @package SAAI\Knowledge
 */

defined( 'ABSPATH' ) || exit;

define( 'SAAI_KNOWLEDGE_VERSION', '0.1.0' );
define( 'SAAI_KNOWLEDGE_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	function ( $fqcn ) {
		$prefix = 'SAAI\\Knowledge\\';

		if ( 0 !== strpos( $fqcn, $prefix ) ) {
			return;
		}

		$relative   = substr( $fqcn, strlen( $prefix ) );
		$parts      = explode( '\\', $relative );
		$class_name = array_pop( $parts );
		$file_name  = 'class-' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $class_name ) ) . '.php';
		$directory  = SAAI_KNOWLEDGE_DIR . 'includes/' . ( $parts ? implode( '/', $parts ) . '/' : '' );
		$path       = $directory . $file_name;

		if ( file_exists( $path ) ) {
			require $path;
		}
	}
);

register_activation_hook( __FILE__, array( \SAAI\Knowledge\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \SAAI\Knowledge\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \SAAI\Knowledge\Plugin::class, 'boot' ), 20 );
