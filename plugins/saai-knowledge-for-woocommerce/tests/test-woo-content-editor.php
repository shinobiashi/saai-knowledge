<?php
/**
 * Tests for the editor sidebar panel's asset gating.
 *
 * @package SAAI\KnowledgeWoo
 */

use SAAI\KnowledgeWoo\Content_Editor;
use SAAI\KnowledgeWoo\Post_Meta;

/**
 * Class Test_Woo_Content_Editor.
 */
class Test_Woo_Content_Editor extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Content_Editor
	 */
	private $editor;

	/**
	 * Sets up the service under test.
	 */
	public function set_up() {
		parent::set_up();

		$this->editor = new Content_Editor();
	}

	/**
	 * Leaves the script registry and the current screen as they were found.
	 *
	 * Neither is reset between tests by the core test framework, so an
	 * enqueued handle would otherwise be visible to every test that runs
	 * afterwards in this process.
	 */
	public function tear_down() {
		wp_dequeue_script( Content_Editor::HANDLE );
		wp_deregister_script( Content_Editor::HANDLE );
		wp_dequeue_style( Content_Editor::HANDLE );
		wp_deregister_style( Content_Editor::HANDLE );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Points get_current_screen() at a post edit screen for a post type.
	 *
	 * @param string $post_type Post type slug.
	 */
	private function set_edit_screen( string $post_type ): void {
		set_current_screen( 'post' );

		$screen            = get_current_screen();
		$screen->base      = 'post';
		$screen->post_type = $post_type;
	}

	/**
	 * The panel script loads on each of the three content types.
	 *
	 * @dataProvider data_content_post_types
	 *
	 * @param string $post_type Post type slug.
	 */
	public function test_script_is_enqueued_for_content_types( string $post_type ) {
		$this->set_edit_screen( $post_type );

		$this->editor->enqueue_panel_script();

		$this->assertTrue( wp_script_is( Content_Editor::HANDLE, 'enqueued' ), $post_type );
	}

	/**
	 * Data provider: the post types that carry linking meta.
	 *
	 * @return array<int, string[]>
	 */
	public function data_content_post_types(): array {
		return array_map(
			static function ( $post_type ) {
				return array( $post_type );
			},
			Post_Meta::POST_TYPES
		);
	}

	/**
	 * The panel ships its own stylesheet.
	 *
	 * The markup uses saai-woo-linked* class names, which do nothing without it.
	 */
	public function test_stylesheet_is_enqueued_with_the_script() {
		$this->set_edit_screen( 'saai_kb' );

		$this->editor->enqueue_panel_script();

		$this->assertTrue( wp_style_is( Content_Editor::HANDLE, 'enqueued' ) );
	}

	/**
	 * The panel script stays off unrelated edit screens.
	 */
	public function test_script_is_not_enqueued_for_other_post_types() {
		$this->set_edit_screen( 'post' );

		$this->editor->enqueue_panel_script();

		$this->assertFalse( wp_script_is( Content_Editor::HANDLE, 'enqueued' ) );
	}

	/**
	 * With no screen at all (a REST or front-end request that still reached
	 * the hook) nothing is enqueued.
	 */
	public function test_script_is_not_enqueued_without_a_screen() {
		unset( $GLOBALS['current_screen'] );

		$this->editor->enqueue_panel_script();

		$this->assertFalse( wp_script_is( Content_Editor::HANDLE, 'enqueued' ) );
	}

	/**
	 * The script declares the wp-* packages it reads off the global `wp`
	 * object.
	 *
	 * Without a build step nothing derives these automatically, so a missing
	 * entry would only show up as a runtime error in the editor.
	 */
	public function test_script_declares_its_wp_package_dependencies() {
		$this->set_edit_screen( 'saai_faq' );

		$this->editor->enqueue_panel_script();

		$script = wp_scripts()->registered[ Content_Editor::HANDLE ];

		foreach ( array( 'wp-components', 'wp-compose', 'wp-core-data', 'wp-data', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-html-entities', 'wp-i18n', 'wp-plugins' ) as $dependency ) {
			$this->assertContains( $dependency, $script->deps, $dependency );
		}
	}
}
