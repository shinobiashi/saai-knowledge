<?php
/**
 * Registers the plugin's custom post types.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Registers saai_faq, saai_kb, and saai_glossary post types.
 */
final class Post_Types {

	/**
	 * Hooks post type registration into WordPress.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_types' ) );
	}

	/**
	 * Registers the post types.
	 */
	public function register_post_types(): void {
		register_post_type( 'saai_faq', $this->faq_args() );
		register_post_type( 'saai_kb', $this->kb_args() );
		register_post_type( 'saai_glossary', $this->glossary_args() );
	}

	/**
	 * Arguments shared by all three content post types.
	 *
	 * @return array<string, mixed>
	 */
	private function shared_args(): array {
		return array(
			'public'        => true,
			'has_archive'   => true,
			'hierarchical'  => false,
			'supports'      => array( 'title', 'editor', 'excerpt', 'revisions', 'custom-fields' ),
			'show_in_rest'  => true,
			'show_ui'       => true,
			'show_in_menu'  => true,
			'menu_position' => 25,
		);
	}

	/**
	 * Registration arguments for saai_faq.
	 *
	 * @return array<string, mixed>
	 */
	private function faq_args(): array {
		return array_merge(
			$this->shared_args(),
			array(
				'label'     => __( 'FAQs', 'saai-knowledge' ),
				'labels'    => $this->labels( __( 'FAQ', 'saai-knowledge' ), __( 'FAQs', 'saai-knowledge' ) ),
				'menu_icon' => 'dashicons-editor-help',
				'rewrite'   => array(
					'slug'       => $this->slug( 'slug_faq', 'faq' ),
					'with_front' => false,
				),
			)
		);
	}

	/**
	 * Registration arguments for saai_kb.
	 *
	 * @return array<string, mixed>
	 */
	private function kb_args(): array {
		$args = array_merge(
			$this->shared_args(),
			array(
				'label'     => __( 'Knowledge Base', 'saai-knowledge' ),
				'labels'    => $this->labels( __( 'KB Article', 'saai-knowledge' ), __( 'Knowledge Base', 'saai-knowledge' ) ),
				'menu_icon' => 'dashicons-book',
				'rewrite'   => array(
					'slug'       => $this->slug( 'slug_kb', 'kb' ),
					'with_front' => false,
				),
			)
		);

		$args['supports'][] = 'page-attributes';

		return $args;
	}

	/**
	 * Registration arguments for saai_glossary.
	 *
	 * @return array<string, mixed>
	 */
	private function glossary_args(): array {
		return array_merge(
			$this->shared_args(),
			array(
				'label'     => __( 'Glossary', 'saai-knowledge' ),
				'labels'    => $this->labels( __( 'Term', 'saai-knowledge' ), __( 'Glossary', 'saai-knowledge' ) ),
				'menu_icon' => 'dashicons-open-folder',
				'rewrite'   => array(
					'slug'       => $this->slug( 'slug_glossary', 'glossary' ),
					'with_front' => false,
				),
			)
		);
	}

	/**
	 * Reads a post type's rewrite slug from the `saai_knowledge_settings`
	 * option (docs/DESIGN.md section 3.4); falls back to the built-in slug
	 * when unset. Same defensive read pattern as
	 * Breadcrumbs::structured_data_enabled() et al.
	 *
	 * @param string $key     Settings array key (e.g. `slug_kb`).
	 * @param string $fallback Built-in fallback slug.
	 * @return string
	 */
	private function slug( string $key, string $fallback ): string {
		$settings = get_option( 'saai_knowledge_settings' );

		if ( is_array( $settings ) && isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
			return $settings[ $key ];
		}

		return $fallback;
	}

	/**
	 * Builds a standard label set from a singular/plural pair.
	 *
	 * @param string $singular Singular label.
	 * @param string $plural   Plural label.
	 * @return array<string, string>
	 */
	private function labels( string $singular, string $plural ): array {
		return array(
			'name'               => $plural,
			'singular_name'      => $singular,
			/* translators: %s: singular label. */
			'add_new_item'       => sprintf( __( 'Add New %s', 'saai-knowledge' ), $singular ),
			/* translators: %s: singular label. */
			'edit_item'          => sprintf( __( 'Edit %s', 'saai-knowledge' ), $singular ),
			/* translators: %s: singular label. */
			'new_item'           => sprintf( __( 'New %s', 'saai-knowledge' ), $singular ),
			/* translators: %s: singular label. */
			'view_item'          => sprintf( __( 'View %s', 'saai-knowledge' ), $singular ),
			/* translators: %s: plural label. */
			'search_items'       => sprintf( __( 'Search %s', 'saai-knowledge' ), $plural ),
			/* translators: %s: plural label. */
			'not_found'          => sprintf( __( 'No %s found.', 'saai-knowledge' ), $plural ),
			/* translators: %s: plural label. */
			'not_found_in_trash' => sprintf( __( 'No %s found in Trash.', 'saai-knowledge' ), $plural ),
			/* translators: %s: plural label. */
			'all_items'          => sprintf( __( 'All %s', 'saai-knowledge' ), $plural ),
		);
	}
}
