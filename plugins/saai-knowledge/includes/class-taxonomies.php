<?php
/**
 * Registers the plugin's taxonomies.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Registers saai_category and saai_tag, shared by saai_faq and saai_kb.
 */
final class Taxonomies {

	/**
	 * Post types the taxonomies are attached to.
	 *
	 * @var string[]
	 */
	private const OBJECT_TYPES = array( 'saai_faq', 'saai_kb' );

	/**
	 * Hooks taxonomy registration into WordPress.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_taxonomies' ) );
	}

	/**
	 * Registers the taxonomies.
	 */
	public function register_taxonomies(): void {
		register_taxonomy(
			'saai_category',
			self::OBJECT_TYPES,
			array(
				'hierarchical'      => true,
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'labels'            => array(
					'name'          => __( 'Categories', 'saai-knowledge' ),
					'singular_name' => __( 'Category', 'saai-knowledge' ),
					'search_items'  => __( 'Search Categories', 'saai-knowledge' ),
					'all_items'     => __( 'All Categories', 'saai-knowledge' ),
					'edit_item'     => __( 'Edit Category', 'saai-knowledge' ),
					'update_item'   => __( 'Update Category', 'saai-knowledge' ),
					'add_new_item'  => __( 'Add New Category', 'saai-knowledge' ),
					'new_item_name' => __( 'New Category Name', 'saai-knowledge' ),
				),
				'rewrite'           => array(
					'slug'       => 'knowledge-category',
					'with_front' => false,
				),
			)
		);

		register_taxonomy(
			'saai_tag',
			self::OBJECT_TYPES,
			array(
				'hierarchical'      => false,
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'labels'            => array(
					'name'          => __( 'Tags', 'saai-knowledge' ),
					'singular_name' => __( 'Tag', 'saai-knowledge' ),
					'search_items'  => __( 'Search Tags', 'saai-knowledge' ),
					'all_items'     => __( 'All Tags', 'saai-knowledge' ),
					'edit_item'     => __( 'Edit Tag', 'saai-knowledge' ),
					'update_item'   => __( 'Update Tag', 'saai-knowledge' ),
					'add_new_item'  => __( 'Add New Tag', 'saai-knowledge' ),
					'new_item_name' => __( 'New Tag Name', 'saai-knowledge' ),
				),
			)
		);
	}
}
