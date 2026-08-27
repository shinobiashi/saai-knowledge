<?php
/**
 * Tests for the classic-theme shortcode wrappers.
 *
 * @package SAAI\Knowledge
 */

/**
 * Class Test_Shortcodes.
 */
class Test_Shortcodes extends WP_UnitTestCase {

	/**
	 * All wrapper shortcodes should be registered on init.
	 */
	public function test_shortcodes_are_registered() {
		$this->assertTrue( shortcode_exists( 'saai_kb_sidebar' ) );
		$this->assertTrue( shortcode_exists( 'saai_kb_toc' ) );
		$this->assertTrue( shortcode_exists( 'saai_breadcrumbs' ) );
		$this->assertTrue( shortcode_exists( 'saai_faq' ) );
		$this->assertTrue( shortcode_exists( 'saai_glossary' ) );
		$this->assertTrue( shortcode_exists( 'saai_search' ) );
	}

	/**
	 * [saai_faq] attributes should be mapped and cast onto the faq-list
	 * block's attributes; unknown ones should be dropped.
	 */
	public function test_faq_shortcode_maps_attributes_to_block() {
		$registry = WP_Block_Type_Registry::get_instance();
		$original = $registry->get_registered( 'saai-knowledge/faq-list' );

		if ( $original ) {
			$registry->unregister( 'saai-knowledge/faq-list' );
		}

		$received = null;

		register_block_type(
			'saai-knowledge/faq-list',
			array(
				'render_callback' => static function ( $attributes ) use ( &$received ) {
					$received = $attributes;

					return '';
				},
			)
		);

		try {
			do_shortcode( '[saai_faq category="setup" count="3" orderby="title" order="asc" group_by_category="true" bogus="x"]' );
		} finally {
			$registry->unregister( 'saai-knowledge/faq-list' );

			if ( $original ) {
				$registry->register( $original );
			}
		}

		$this->assertSame( 'setup', $received['category'] );
		$this->assertSame( 3, $received['count'] );
		$this->assertSame( 'title', $received['orderBy'] );
		$this->assertSame( 'asc', $received['order'] );
		$this->assertTrue( $received['groupByCategory'] );
		$this->assertArrayNotHasKey( 'bogus', $received );
	}

	/**
	 * A shortcode should return exactly what render_block() produces for
	 * its backing block.
	 *
	 * The real block types are only registered when build/ exists, so the
	 * test swaps in a stub render callback under the real block name and
	 * restores the registry afterwards (the block registry is not reset
	 * between tests).
	 */
	public function test_shortcode_renders_backing_block() {
		$registry = WP_Block_Type_Registry::get_instance();
		$original = $registry->get_registered( 'saai-knowledge/kb-sidebar' );

		if ( $original ) {
			$registry->unregister( 'saai-knowledge/kb-sidebar' );
		}

		register_block_type(
			'saai-knowledge/kb-sidebar',
			array(
				'render_callback' => static function () {
					return '<nav class="stub-sidebar"></nav>';
				},
			)
		);

		try {
			$output = do_shortcode( '[saai_kb_sidebar]' );
		} finally {
			$registry->unregister( 'saai-knowledge/kb-sidebar' );

			if ( $original ) {
				$registry->register( $original );
			}
		}

		$this->assertSame( '<nav class="stub-sidebar"></nav>', $output );
	}

	/**
	 * When the backing block is not registered, the shortcode should
	 * render to an empty string instead of erroring.
	 */
	public function test_shortcode_returns_empty_string_for_unregistered_block() {
		$registry = WP_Block_Type_Registry::get_instance();
		$original = $registry->get_registered( 'saai-knowledge/kb-toc' );

		if ( $original ) {
			$registry->unregister( 'saai-knowledge/kb-toc' );
		}

		try {
			$output = do_shortcode( '[saai_kb_toc]' );
		} finally {
			if ( $original ) {
				$registry->register( $original );
			}
		}

		$this->assertSame( '', $output );
	}
}
