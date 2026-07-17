<?php
/**
 * Registers the plugin's REST-exposed post meta.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Registers saai_reading, saai_synonyms, and saai_no_autolink post meta.
 */
final class Post_Meta {

	/**
	 * Meta key holding the glossary term's reading (kana/index sort key).
	 *
	 * @var string
	 */
	public const READING = 'saai_reading';

	/**
	 * Meta key holding a glossary term's newline-separated synonyms.
	 *
	 * @var string
	 */
	public const SYNONYMS = 'saai_synonyms';

	/**
	 * Meta key flagging a post as excluded from auto-linking.
	 *
	 * @var string
	 */
	public const NO_AUTOLINK = 'saai_no_autolink';

	/**
	 * Post types that can carry the saai_no_autolink flag.
	 *
	 * @var string[]
	 */
	private const NO_AUTOLINK_POST_TYPES = array( 'saai_faq', 'saai_kb', 'saai_glossary', 'post', 'page' );

	/**
	 * Hooks post meta registration into WordPress.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_meta' ) );
	}

	/**
	 * Registers the post meta fields.
	 */
	public function register_post_meta(): void {
		register_post_meta(
			'saai_glossary',
			self::READING,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( $this, 'can_edit_post_meta' ),
			)
		);

		register_post_meta(
			'saai_glossary',
			self::SYNONYMS,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_textarea_field',
				'auth_callback'     => array( $this, 'can_edit_post_meta' ),
			)
		);

		foreach ( self::NO_AUTOLINK_POST_TYPES as $post_type ) {
			register_post_meta(
				$post_type,
				self::NO_AUTOLINK,
				array(
					'type'              => 'boolean',
					'single'            => true,
					'default'           => false,
					'show_in_rest'      => true,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'auth_callback'     => array( $this, 'can_edit_post_meta' ),
				)
			);
		}
	}

	/**
	 * Restricts meta read/write access to users who can edit the post.
	 *
	 * @param bool   $allowed  Whether the meta key is allowed to be accessed.
	 * @param string $meta_key The meta key.
	 * @param int    $post_id  Post ID.
	 * @return bool
	 */
	public function can_edit_post_meta( bool $allowed, string $meta_key, int $post_id ): bool {
		return current_user_can( 'edit_post', $post_id );
	}
}
