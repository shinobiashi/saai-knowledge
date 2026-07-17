<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package SAAI\Knowledge
 */

$saai_composer_autoload = dirname( __DIR__, 3 ) . '/vendor/autoload.php';

if ( ! file_exists( $saai_composer_autoload ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic output before WordPress (and WP_Filesystem) is loaded.
	fwrite( STDERR, 'Composer dependencies are not installed. Run `composer install` from the repository root.' . PHP_EOL );
	exit( 1 );
}

require $saai_composer_autoload;

$saai_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $saai_tests_dir ) {
	$saai_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}

if ( ! $saai_tests_dir ) {
	$saai_tests_dir = dirname( __DIR__, 3 ) . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( $saai_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic output before WordPress (and WP_Filesystem) is loaded.
	fwrite( STDERR, 'WP test suite not found at "' . $saai_tests_dir . '". Run `composer install` or set the WP_TESTS_DIR / WP_PHPUNIT__DIR environment variable.' . PHP_EOL );
	exit( 1 );
}

require_once $saai_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin under test.
 */
function saai_knowledge_tests_load_plugin() {
	require dirname( __DIR__ ) . '/saai-knowledge.php';
}
tests_add_filter( 'muplugins_loaded', 'saai_knowledge_tests_load_plugin' );

require $saai_tests_dir . '/includes/bootstrap.php';

require_once ABSPATH . 'wp-admin/includes/plugin.php';
