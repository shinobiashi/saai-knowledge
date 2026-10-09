<?php
/**
 * Stand-in for WooCommerce's wc_format_content(), for the add-on's PHPUnit tests.
 *
 * WooCommerce is absent from the PHPUnit bootstrap (tests/bootstrap.php loads
 * only the two SAAI plugins). This is a verbatim copy of wc_format_content()
 * from includes/wc-formatting-functions.php (WooCommerce 11.2); the single
 * detail that matters to the tests is that it routes its argument through
 * the `woocommerce_short_description` filter, which the add-on detects on the
 * call stack by this function's name.
 *
 * Kept outside the plugins/ tree (and outside every PHPUnit testsuite
 * directory) so that neither PHPCS nor the test runner picks it up on its
 * own; the test that needs it require_once()s it explicitly.
 *
 * @package SAAI\KnowledgeWoo
 */

if ( ! function_exists( 'wc_format_content' ) ) {
	/**
	 * Formats content like WooCommerce does for descriptions.
	 *
	 * @param string|null $raw_string Raw string.
	 * @return string
	 */
	function wc_format_content( $raw_string ) {
		$raw_string = $raw_string ?? '';
		return apply_filters( 'woocommerce_format_content', apply_filters( 'woocommerce_short_description', $raw_string ), $raw_string );
	}
}
