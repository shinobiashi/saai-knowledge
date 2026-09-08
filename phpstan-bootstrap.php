<?php
/**
 * Constants defined by the plugin bootstrap files, declared here so
 * PHPStan can resolve them when analysing files that reference them
 * without loading the full plugin entry point.
 */

if ( ! defined( 'SAAI_KNOWLEDGE_VERSION' ) ) {
	define( 'SAAI_KNOWLEDGE_VERSION', '1.0.0' );
}

if ( ! defined( 'SAAI_KNOWLEDGE_DIR' ) ) {
	define( 'SAAI_KNOWLEDGE_DIR', __DIR__ . '/plugins/saai-knowledge/' );
}

if ( ! defined( 'SAAI_KNOWLEDGE_URL' ) ) {
	define( 'SAAI_KNOWLEDGE_URL', 'http://example.com/wp-content/plugins/saai-knowledge/' );
}

if ( ! defined( 'SAAI_KNOWLEDGE_WOO_VERSION' ) ) {
	define( 'SAAI_KNOWLEDGE_WOO_VERSION', '0.1.0' );
}

if ( ! defined( 'SAAI_KNOWLEDGE_WOO_DIR' ) ) {
	define( 'SAAI_KNOWLEDGE_WOO_DIR', __DIR__ . '/plugins/saai-knowledge-for-woocommerce/' );
}

if ( ! defined( 'SAAI_KNOWLEDGE_WOO_URL' ) ) {
	define( 'SAAI_KNOWLEDGE_WOO_URL', 'http://example.com/wp-content/plugins/saai-knowledge-for-woocommerce/' );
}

if ( ! defined( 'SAAI_WOO_MIN_BASE_VERSION' ) ) {
	define( 'SAAI_WOO_MIN_BASE_VERSION', '1.0.0' );
}
