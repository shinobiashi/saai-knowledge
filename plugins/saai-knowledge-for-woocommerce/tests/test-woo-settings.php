<?php
/**
 * Tests for the add-on's settings section and its toggles.
 *
 * @package SAAI\KnowledgeWoo
 */

use SAAI\KnowledgeWoo\Settings;

/**
 * Class Test_Woo_Settings.
 */
class Test_Woo_Settings extends WP_UnitTestCase {

	/**
	 * Service under test, register()'d for the duration of each test.
	 *
	 * The add-on's Plugin::boot() never runs in this process (WooCommerce
	 * is absent from the PHPUnit bootstrap, so Bootstrap reports
	 * `missing_woocommerce`), which means no production instance sits on
	 * these filters; this one does, and tear_down() takes it off again.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Starts every test without a saved option row.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION_KEY );

		$this->settings = new Settings();
		$this->settings->register();
	}

	/**
	 * Detaches the filters register() added.
	 */
	public function tear_down() {
		remove_filter( 'saai_settings_sections', array( $this->settings, 'add_section' ) );
		remove_filter( 'saai_default_settings', array( $this->settings, 'add_defaults' ) );

		parent::tear_down();
	}

	/**
	 * The section arrives through the public filter in the documented
	 * list-with-`id` field shape, without disturbing other sections.
	 */
	public function test_section_is_added_through_the_public_filter() {
		$sections = apply_filters(
			'saai_settings_sections',
			array(
				'general' => array(
					'title'  => 'General',
					'fields' => array(),
				),
			)
		);

		$this->assertArrayHasKey( 'general', $sections, 'Existing sections must be kept.' );
		$this->assertArrayHasKey( Settings::SECTION_ID, $sections );
		$this->assertSame( 'WooCommerce', $sections[ Settings::SECTION_ID ]['title'] );

		$fields = $sections[ Settings::SECTION_ID ]['fields'];

		$this->assertSame(
			array( Settings::FAQ_TAB, Settings::KB_LINKS, Settings::TOOLTIPS ),
			array_column( $fields, 'id' ),
			'Fields use the documented list-with-id shape, in display order.'
		);

		foreach ( $fields as $field ) {
			$this->assertSame( 'checkbox', $field['type'] );
			$this->assertTrue( $field['default'] );
			$this->assertNotSame( '', $field['label'] );
			$this->assertNotSame( '', $field['checkbox_label'] );
		}
	}

	/**
	 * The defaults reach both the filter's consumers and a bare get_option()
	 * on a site that never saved the settings page (the free plugin's
	 * `default_option_*` filter serves its filtered defaults then).
	 */
	public function test_defaults_are_added_and_reach_a_bare_get_option() {
		$defaults = apply_filters( 'saai_default_settings', array( 'slug_kb' => 'kb' ) );

		$this->assertSame( 'kb', $defaults['slug_kb'], 'Existing defaults must be kept.' );
		$this->assertTrue( $defaults[ Settings::FAQ_TAB ] );
		$this->assertTrue( $defaults[ Settings::KB_LINKS ] );
		$this->assertTrue( $defaults[ Settings::TOOLTIPS ] );

		$option = get_option( Settings::OPTION_KEY );

		$this->assertIsArray( $option );
		$this->assertTrue( $option[ Settings::TOOLTIPS ] );
		$this->assertTrue( $this->settings->is_enabled( Settings::TOOLTIPS ) );
	}

	/**
	 * A saved option row from before the add-on was activated has none of
	 * these keys; they must read as on, not off.
	 */
	public function test_is_enabled_falls_back_to_on_when_the_key_is_missing() {
		update_option( Settings::OPTION_KEY, array( 'slug_kb' => 'kb' ) );

		$this->assertTrue( $this->settings->is_enabled( Settings::FAQ_TAB ) );
		$this->assertTrue( $this->settings->is_enabled( Settings::KB_LINKS ) );
		$this->assertTrue( $this->settings->is_enabled( Settings::TOOLTIPS ) );
	}

	/**
	 * A stored value wins over the default, whatever scalar shape the
	 * sanitizer stored it in.
	 */
	public function test_is_enabled_prefers_the_stored_value() {
		update_option(
			Settings::OPTION_KEY,
			array(
				Settings::FAQ_TAB  => false,
				Settings::KB_LINKS => '1',
				Settings::TOOLTIPS => 0,
			)
		);

		$this->assertFalse( $this->settings->is_enabled( Settings::FAQ_TAB ) );
		$this->assertTrue( $this->settings->is_enabled( Settings::KB_LINKS ) );
		$this->assertFalse( $this->settings->is_enabled( Settings::TOOLTIPS ) );
	}

	/**
	 * Only this class's own fields are answered; a stored free-plugin key is
	 * not re-interpreted as one of this add-on's toggles.
	 */
	public function test_is_enabled_is_false_for_fields_this_plugin_does_not_own() {
		update_option( Settings::OPTION_KEY, array( 'structured_data' => true ) );

		$this->assertFalse( $this->settings->is_enabled( 'structured_data' ) );
		$this->assertFalse( $this->settings->is_enabled( '' ) );
	}

	/**
	 * A third-party callback can hand the filters something other than an
	 * array; that must pass through rather than be replaced or fatal.
	 */
	public function test_non_array_filter_values_pass_through_untouched() {
		$this->assertSame( 'broken', $this->settings->add_section( 'broken' ) );
		$this->assertNull( $this->settings->add_defaults( null ) );
	}

	/**
	 * Saving the free plugin's settings page with a toggle unchecked stores
	 * it as off (an unchecked checkbox is absent from the submission), and
	 * is_enabled() then reads it back as off rather than as its default.
	 */
	public function test_an_unchecked_toggle_round_trips_as_off_through_the_free_plugin_sanitizer() {
		$free      = new \SAAI\Knowledge\Settings();
		$sanitized = $free->sanitize(
			array(
				Settings::FAQ_TAB => '1',
				'slug_kb'         => 'kb',
			)
		);

		$this->assertTrue( $sanitized[ Settings::FAQ_TAB ] );
		$this->assertFalse( $sanitized[ Settings::KB_LINKS ], 'A checkbox absent from the submission is stored as off, not left at its default.' );
		$this->assertFalse( $sanitized[ Settings::TOOLTIPS ] );

		update_option( Settings::OPTION_KEY, $sanitized );

		$this->assertTrue( $this->settings->is_enabled( Settings::FAQ_TAB ) );
		$this->assertFalse( $this->settings->is_enabled( Settings::KB_LINKS ) );
		$this->assertFalse( $this->settings->is_enabled( Settings::TOOLTIPS ) );
	}
}
