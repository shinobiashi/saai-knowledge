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
	 * Signature matches the `auth_{$object_type}_meta_{$meta_key}` filter
	 * WordPress invokes this callback through (see `map_meta_cap()` in
	 * wp-includes/capabilities.php). The incoming `$allowed` is intentionally
	 * ignored: it only reflects `is_protected_meta()`, not a real permission
	 * decision, so `current_user_can()` is the actual authorization check.
	 *
	 * @param bool     $allowed   Whether the meta key is allowed to be accessed. Unused.
	 * @param string   $meta_key  The meta key. Unused.
	 * @param int      $post_id   Post ID.
	 * @param int      $user_id   User ID. Unused.
	 * @param string   $cap       Capability name. Unused.
	 * @param string[] $caps      Array of the user's capabilities. Unused.
	 * @return bool
	 */
	public function can_edit_post_meta( bool $allowed, string $meta_key, int $post_id, int $user_id = 0, string $cap = '', array $caps = array() ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- kept to match the auth_{$object_type}_meta_{$meta_key} filter signature.
		return current_user_can( 'edit_post', $post_id );
	}
}
