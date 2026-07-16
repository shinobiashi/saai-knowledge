<?php
/**
 * Smoke test verifying the plugin file loads with a valid header.
 *
 * @package SAAI\Knowledge
 */

/**
 * Class Test_Smoke.
 */
class Test_Smoke extends WP_UnitTestCase {

	/**
	 * The plugin file should declare the expected plugin header data.
	 */
	public function test_plugin_header_is_registered() {
		$plugin_file = dirname( __DIR__ ) . '/saai-knowledge.php';
		$data        = get_plugin_data( $plugin_file, false, false );

		$this->assertSame( 'SAAI Knowledge', $data['Name'] );
		$this->assertSame( 'saai-knowledge', $data['TextDomain'] );
	}
}
