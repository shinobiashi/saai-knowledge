<?php
/**
 * PHPUnit bootstrap file for the monorepo (free plugin + WooCommerce add-on).
 */

$saai_composer_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

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
	$saai_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( $saai_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic output before WordPress (and WP_Filesystem) is loaded.
	fwrite( STDERR, 'WP test suite not found at "' . $saai_tests_dir . '". Run `composer install` or set the WP_TESTS_DIR / WP_PHPUNIT__DIR environment variable.' . PHP_EOL );
	exit( 1 );
}

require_once $saai_tests_dir . '/includes/functions.php';

/**
 * Manually loads the free plugin and the WooCommerce add-on under test.
 *
 * The free plugin is required first so its `plugins_loaded` (priority 20)
 * and `saai_loaded` hooks are registered before the add-on's own
 * `plugins_loaded` (priority 21) / `saai_loaded` listeners, matching the
 * load order a real WordPress install would produce.
 */
function saai_knowledge_tests_load_plugins() {
	require dirname( __DIR__ ) . '/plugins/saai-knowledge/saai-knowledge.php';
	require dirname( __DIR__ ) . '/plugins/saai-knowledge-for-woocommerce/saai-knowledge-for-woocommerce.php';
}
tests_add_filter( 'muplugins_loaded', 'saai_knowledge_tests_load_plugins' );

require $saai_tests_dir . '/includes/bootstrap.php';

require_once ABSPATH . 'wp-admin/includes/plugin.php';
