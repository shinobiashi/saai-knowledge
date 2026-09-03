<?php
/**
 * Tests for the saai_knowledge_settings option, its Settings API screen, and
 * the effects it has on Post_Types and the auto-link engine.
 *
 * @package SAAI\Knowledge
 */

/**
 * Class Test_Settings.
 */
class Test_Settings extends WP_UnitTestCase {

	/**
	 * The service under test.
	 *
	 * @var \SAAI\Knowledge\Settings
	 */
	private $settings;

	/**
	 * Fresh instance for each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->settings = new \SAAI\Knowledge\Settings();
	}

	/**
	 * An empty submission should fall back to the built-in default for every
	 * scalar field, and to an empty (auto-linking disabled) list for the
	 * post types checkboxes — matching how an "all boxes unchecked" form
	 * submission arrives.
	 */
	public function test_sanitize_falls_back_to_defaults_for_empty_submission() {
		$sanitized = $this->settings->sanitize( array() );

		$this->assertSame( 'kb', $sanitized['slug_kb'] );
		$this->assertSame( 'faq', $sanitized['slug_faq'] );
		$this->assertSame( 'glossary', $sanitized['slug_glossary'] );
		$this->assertSame( array(), $sanitized['autolink_post_types'] );
		$this->assertSame( 20, $sanitized['autolink_max_links'] );
		$this->assertFalse( $sanitized['structured_data'] );
		$this->assertFalse( $sanitized['delete_data_on_uninstall'] );
	}

	/**
	 * Unknown post type values must be dropped, in the field's declared
	 * `options` order. `saai_glossary` must be dropped too — it's not one of
	 * the field's offered choices (Autolinker::process() always bails out
	 * for a saai_glossary post itself, so offering it would be a no-op
	 * checkbox).
	 */
	public function test_sanitize_filters_unknown_post_types() {
		$sanitized = $this->settings->sanitize(
			array( 'autolink_post_types' => array( 'saai_faq', 'not-a-real-post-type', 'post', 'saai_glossary' ) )
		);

		$this->assertSame( array( 'post', 'saai_faq' ), $sanitized['autolink_post_types'] );
	}

	/**
	 * A non-numeric or zero max-links value must fall back to the default
	 * rather than disabling auto-linking outright.
	 */
	public function test_sanitize_falls_back_to_default_max_links_for_invalid_input() {
		$sanitized = $this->settings->sanitize( array( 'autolink_max_links' => 'not-a-number' ) );

		$this->assertSame( 20, $sanitized['autolink_max_links'] );

		$sanitized = $this->settings->sanitize( array( 'autolink_max_links' => '5' ) );

		$this->assertSame( 5, $sanitized['autolink_max_links'] );
	}

	/**
	 * Duplicate slugs must be rejected in favor of the previously stored
	 * values, with a settings error recorded for the admin.
	 */
	public function test_sanitize_rejects_duplicate_slugs() {
		$sanitized = $this->settings->sanitize(
			array(
				'slug_kb'  => 'same-slug',
				'slug_faq' => 'same-slug',
			)
		);

		$this->assertSame( 'kb', $sanitized['slug_kb'] );
		$this->assertSame( 'faq', $sanitized['slug_faq'] );

		$errors = get_settings_errors( \SAAI\Knowledge\Settings::OPTION_KEY );
		$this->assertNotEmpty( $errors );
	}

	/**
	 * A real slug change must flag a deferred rewrite flush; an unrelated
	 * field change must not.
	 */
	public function test_sanitize_flags_deferred_flush_only_on_slug_change() {
		$this->settings->sanitize( array( 'structured_data' => '1' ) );
		$this->assertFalse( get_option( 'saai_flush_rewrite_rules' ) );

		$this->settings->sanitize( array( 'slug_kb' => 'articles' ) );
		$this->assertNotFalse( get_option( 'saai_flush_rewrite_rules' ) );
	}

	/**
	 * A slug_glossary change must bump saai_dict_generation, invalidating
	 * Autolinker's cached dictionary (its entries embed get_permalink(),
	 * which changes when the slug does). An unrelated slug change must not.
	 */
	public function test_sanitize_bumps_dictionary_generation_only_on_glossary_slug_change() {
		update_option( 'saai_dict_generation', 5 );

		$this->settings->sanitize( array( 'slug_kb' => 'articles' ) );
		$this->assertSame( 5, (int) get_option( 'saai_dict_generation' ) );

		$this->settings->sanitize( array( 'slug_glossary' => 'dictionary' ) );
		$this->assertSame( 6, (int) get_option( 'saai_dict_generation' ) );
	}

	/**
	 * A field a `saai_settings_sections` consumer (e.g. the paid add-on)
	 * declares must actually be persisted — including its own `sanitize`
	 * callable — and must survive a later save that doesn't include it in
	 * $value, instead of being dropped by a fixed, built-in-only key list.
	 */
	public function test_sanitize_persists_third_party_section_fields() {
		$add_default = static function ( array $defaults ): array {
			$defaults['saai_test_field'] = 'built-in default';
			return $defaults;
		};
		$add_section = static function ( array $sections ): array {
			$sections['test'] = array(
				'title'  => 'Test',
				'fields' => array(
					'saai_test_field' => array(
						'type'     => 'text',
						'label'    => 'Test field',
						'sanitize' => static function ( $raw, $fallback ) {
							return is_string( $raw ) ? 'sanitized:' . $raw : $fallback;
						},
					),
				),
			);
			return $sections;
		};

		add_filter( 'saai_default_settings', $add_default );
		add_filter( 'saai_settings_sections', $add_section );

		try {
			$sanitized = $this->settings->sanitize( array( 'saai_test_field' => 'raw value' ) );
			$this->assertSame( 'sanitized:raw value', $sanitized['saai_test_field'] );

			update_option( 'saai_knowledge_settings', $sanitized );

			// A later save that doesn't include this field at all (e.g. a
			// separate submission of just the built-in fields) must not
			// reset it back to the default.
			$sanitized_again = $this->settings->sanitize( array( 'structured_data' => '1' ) );
			$this->assertSame( 'sanitized:raw value', $sanitized_again['saai_test_field'] );
		} finally {
			remove_filter( 'saai_default_settings', $add_default );
			remove_filter( 'saai_settings_sections', $add_section );
		}
	}

	/**
	 * The maybe_flush_rewrite_rules() flag should be consumed exactly once.
	 */
	public function test_maybe_flush_rewrite_rules_clears_the_flag() {
		update_option( 'saai_flush_rewrite_rules', 1 );

		$this->settings->maybe_flush_rewrite_rules();

		$this->assertFalse( get_option( 'saai_flush_rewrite_rules' ) );
	}

	/**
	 * With no settings ever saved, the saai_autolink_post_types filter must
	 * leave Autolinker's own default untouched.
	 */
	public function test_filter_autolink_post_types_passes_through_when_unset() {
		$result = $this->settings->filter_autolink_post_types( array( 'post', 'page' ) );

		$this->assertSame( array( 'post', 'page' ), $result );
	}

	/**
	 * Once saved, the configured list (including an intentionally empty one
	 * that turns auto-linking off) must override the incoming default.
	 */
	public function test_filter_autolink_post_types_uses_the_saved_value() {
		update_option( 'saai_knowledge_settings', array( 'autolink_post_types' => array( 'saai_kb' ) ) );

		$this->assertSame( array( 'saai_kb' ), $this->settings->filter_autolink_post_types( array( 'post', 'page' ) ) );

		update_option( 'saai_knowledge_settings', array( 'autolink_post_types' => array() ) );

		$this->assertSame( array(), $this->settings->filter_autolink_post_types( array( 'post', 'page' ) ) );
	}

	/**
	 * Post_Types::register_post_types() must read the configured slug for
	 * saai_kb, falling back to "kb" when unset (covered implicitly by every
	 * other test file that registers the post types with no option saved).
	 */
	public function test_post_type_rewrite_slug_reads_the_settings_option() {
		update_option( 'saai_knowledge_settings', array( 'slug_kb' => 'articles' ) );

		try {
			( new \SAAI\Knowledge\Post_Types() )->register_post_types();

			$object = get_post_type_object( 'saai_kb' );
			$this->assertSame( 'articles', $object->rewrite['slug'] );
		} finally {
			// Restore the default registration for the rest of this test run,
			// even on assertion failure: register_post_type()'s effect is a
			// process-global PHP registry, not DB state, so it survives past
			// this test's transaction rollback and would otherwise leak into
			// every later test in the same PHPUnit process.
			delete_option( 'saai_knowledge_settings' );
			( new \SAAI\Knowledge\Post_Types() )->register_post_types();
		}
	}

	/**
	 * The uninstall script must leave all data in place when the setting is off.
	 */
	public function test_uninstall_leaves_data_when_disabled() {
		$faq_id = self::factory()->post->create( array( 'post_type' => 'saai_faq' ) );

		update_option( 'saai_knowledge_settings', array( 'delete_data_on_uninstall' => false ) );

		$this->run_uninstall();

		$this->assertInstanceOf( \WP_Post::class, get_post( $faq_id ) );
	}

	/**
	 * The uninstall script must delete content post types, saai_category/
	 * saai_tag terms, and the plugin's options when the setting is on.
	 */
	public function test_uninstall_deletes_data_when_enabled() {
		$faq_id  = self::factory()->post->create( array( 'post_type' => 'saai_faq' ) );
		$kb_id   = self::factory()->post->create( array( 'post_type' => 'saai_kb' ) );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );

		update_option( 'saai_knowledge_settings', array( 'delete_data_on_uninstall' => true ) );

		$this->run_uninstall();

		$this->assertNull( get_post( $faq_id ) );
		$this->assertNull( get_post( $kb_id ) );
		$this->assertNull( get_term( $term_id, 'saai_category' ) );
		$this->assertFalse( get_option( 'saai_knowledge_settings' ) );
	}

	/**
	 * `post_status => 'any'` excludes 'trash' (WordPress core registers it
	 * exclude_from_search), so a trashed FAQ must still be deleted.
	 */
	public function test_uninstall_deletes_trashed_posts_when_enabled() {
		$faq_id = self::factory()->post->create( array( 'post_type' => 'saai_faq' ) );
		wp_trash_post( $faq_id );
		$this->assertSame( 'trash', get_post_status( $faq_id ) );

		update_option( 'saai_knowledge_settings', array( 'delete_data_on_uninstall' => true ) );

		$this->run_uninstall();

		$this->assertNull( get_post( $faq_id ) );
	}

	/**
	 * Requires uninstall.php the way WordPress's own uninstall handler does.
	 */
	private function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		require dirname( __DIR__ ) . '/uninstall.php';
	}
}
