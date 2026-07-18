<?php
/**
 * Tests for the CPT, taxonomy, and post meta registration.
 *
 * @package SAAI\Knowledge
 */

/**
 * Class Test_Data_Model.
 */
class Test_Data_Model extends WP_UnitTestCase {

	/**
	 * Re-registers post meta before each test.
	 *
	 * WP_UnitTestCase_Base::tear_down() unconditionally unregisters all meta
	 * keys after every test, so meta registered once on `init` at bootstrap
	 * does not survive past the first test in the suite.
	 */
	public function set_up(): void {
		parent::set_up();
		( new \SAAI\Knowledge\Post_Meta() )->register_post_meta();
	}

	/**
	 * The three content post types should be registered with REST + archive support.
	 */
	public function test_post_types_are_registered() {
		$this->assertTrue( post_type_exists( 'saai_faq' ) );
		$this->assertTrue( post_type_exists( 'saai_kb' ) );
		$this->assertTrue( post_type_exists( 'saai_glossary' ) );

		foreach ( array( 'saai_faq', 'saai_kb', 'saai_glossary' ) as $post_type ) {
			$object = get_post_type_object( $post_type );

			$this->assertTrue( $object->public, "{$post_type} should be public" );
			$this->assertTrue( $object->has_archive, "{$post_type} should have an archive" );
			$this->assertFalse( $object->hierarchical, "{$post_type} should not be hierarchical" );
			$this->assertTrue( $object->show_in_rest, "{$post_type} should be REST-exposed" );
			$this->assertTrue( post_type_supports( $post_type, 'title' ) );
			$this->assertTrue( post_type_supports( $post_type, 'editor' ) );
			$this->assertTrue( post_type_supports( $post_type, 'excerpt' ) );
			$this->assertTrue( post_type_supports( $post_type, 'revisions' ) );
			$this->assertTrue( post_type_supports( $post_type, 'custom-fields' ) );
		}
	}

	/**
	 * The saai_kb post type should additionally support page-attributes for menu_order sorting.
	 */
	public function test_kb_supports_page_attributes() {
		$this->assertTrue( post_type_supports( 'saai_kb', 'page-attributes' ) );
		$this->assertFalse( post_type_supports( 'saai_faq', 'page-attributes' ) );
		$this->assertFalse( post_type_supports( 'saai_glossary', 'page-attributes' ) );
	}

	/**
	 * The saai_category and saai_tag taxonomies should be registered on saai_faq and saai_kb only.
	 */
	public function test_taxonomies_are_registered() {
		$this->assertTrue( taxonomy_exists( 'saai_category' ) );
		$this->assertTrue( taxonomy_exists( 'saai_tag' ) );

		$category = get_taxonomy( 'saai_category' );
		$tag      = get_taxonomy( 'saai_tag' );

		$this->assertTrue( $category->hierarchical );
		$this->assertTrue( $category->show_in_rest );
		$this->assertTrue( $category->show_admin_column );
		$this->assertFalse( $tag->hierarchical );
		$this->assertTrue( $tag->show_in_rest );

		$this->assertSame( array( 'saai_faq', 'saai_kb' ), $category->object_type );
		$this->assertSame( array( 'saai_faq', 'saai_kb' ), $tag->object_type );

		$this->assertFalse( in_array( 'saai_glossary', $category->object_type, true ) );
	}

	/**
	 * The saai_reading / saai_synonyms meta should be registered on saai_glossary and REST-exposed.
	 */
	public function test_glossary_meta_is_registered() {
		$registered = get_registered_meta_keys( 'post', 'saai_glossary' );

		$this->assertArrayHasKey( 'saai_reading', $registered );
		$this->assertTrue( $registered['saai_reading']['show_in_rest'] );
		$this->assertSame( 'string', $registered['saai_reading']['type'] );

		$this->assertArrayHasKey( 'saai_synonyms', $registered );
		$this->assertTrue( $registered['saai_synonyms']['show_in_rest'] );
		$this->assertSame( 'string', $registered['saai_synonyms']['type'] );
	}

	/**
	 * The saai_no_autolink meta should be registered across all content post types plus post/page.
	 */
	public function test_no_autolink_meta_is_registered_on_all_post_types() {
		foreach ( array( 'saai_faq', 'saai_kb', 'saai_glossary', 'post', 'page' ) as $post_type ) {
			$registered = get_registered_meta_keys( 'post', $post_type );

			$this->assertArrayHasKey( 'saai_no_autolink', $registered, "saai_no_autolink missing on {$post_type}" );
			$this->assertSame( 'boolean', $registered['saai_no_autolink']['type'] );
			$this->assertTrue( $registered['saai_no_autolink']['show_in_rest'] );
			$this->assertFalse( $registered['saai_no_autolink']['default'] );
		}
	}

	/**
	 * The auth callback should gate meta access on edit_post capability,
	 * regardless of the incoming $allowed value (it is intentionally ignored).
	 */
	public function test_meta_auth_callback_requires_edit_capability() {
		$editor_id  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post_id    = self::factory()->post->create( array( 'post_type' => 'saai_glossary' ) );

		$post_meta = new \SAAI\Knowledge\Post_Meta();

		// $allowed=true mirrors the real invocation via auth_{$object_type}_meta_{$meta_key}
		// (is_protected_meta() defaults unprotected keys to true); the method must still
		// enforce current_user_can() rather than trusting the incoming $allowed.
		wp_set_current_user( $editor_id );
		$this->assertTrue( $post_meta->can_edit_post_meta( true, 'saai_reading', $post_id, $editor_id, 'edit_post_meta', array() ) );

		wp_set_current_user( $subscriber );
		$this->assertFalse( $post_meta->can_edit_post_meta( true, 'saai_reading', $post_id, $subscriber, 'edit_post_meta', array() ) );
	}
}
