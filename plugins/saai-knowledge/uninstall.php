<?php
/**
 * Fires on plugin deletion via the WordPress admin.
 *
 * Data removal (CPTs, meta, options) is gated behind a settings toggle
 * implemented in a later milestone; this file only guards the entry point.
 *
 * @package SAAI\Knowledge
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
