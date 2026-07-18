<?php
/**
 * Constants defined by the plugin bootstrap files, declared here so
 * PHPStan can resolve them when analysing files that reference them
 * without loading the full plugin entry point.
 *
 * @package SAAI\Knowledge
 */

if ( ! defined( 'SAAI_KNOWLEDGE_VERSION' ) ) {
	define( 'SAAI_KNOWLEDGE_VERSION', '0.1.0' );
}

if ( ! defined( 'SAAI_KNOWLEDGE_DIR' ) ) {
	define( 'SAAI_KNOWLEDGE_DIR', __DIR__ . '/plugins/saai-knowledge/' );
}
