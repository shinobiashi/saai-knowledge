<?php
/**
 * The llms.txt "layer 1" SEO-plugin adapter (docs/DESIGN.md section 7.2,
 * layer 1).
 *
 * Inclusion itself needs no adapter: saai_faq/saai_kb/saai_glossary are
 * `public: true` post types, so Yoast SEO, Rank Math, and All in One SEO
 * each pick them up on their own — Yoast via its own "indexable post type"
 * determination, Rank Math and AIOSEO via the post-type checkboxes on their
 * respective llms.txt settings screens (their public-post-type list
 * includes ours automatically; the site owner still opts a type in through
 * that plugin's own UI). None of the three exposes a hook to inject a whole
 * new section into their output — this was verified by reading Yoast SEO's
 * and Rank Math's actual implementation (both fully open source; see this
 * class's methods below for the specific hooks that *do* exist and what
 * they're for) rather than assumed from their marketing docs, several of
 * which describe only their title/description filters without saying what
 * drives inclusion. Confirmed live too: installing Yoast SEO in a wp-env
 * instance, publishing a saai_faq post, and enabling Yoast's llms.txt
 * feature produced a "## FAQs" section listing it — with no add_filter()
 * call from this plugin in the loop at all.
 *
 * What this class actually does: when one of those plugins already decided
 * to include one of our posts but has nothing better than an empty
 * description for it, fill that gap with our own excerpt. This is a
 * deliberately small "adapter" — see docs/DEVELOPMENT-PLAN.md's M4-6 note
 * for why the original "inject a section" framing didn't hold up.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Conditionally hooks each supported SEO plugin's per-item description
 * filter, only when that plugin is actually active.
 */
final class Llms_Txt_Adapter {

	/**
	 * Post types this adapter improves descriptions for.
	 *
	 * @var string[]
	 */
	private const POST_TYPES = array( 'saai_faq', 'saai_kb', 'saai_glossary' );

	/**
	 * Hooks each supported plugin's description filter, gated on that
	 * plugin actually being loaded (its version constant being defined) —
	 * a legitimate use of a definition check, unlike the saai_loaded
	 * contract between this plugin and its own paid add-on (see
	 * docs/DESIGN-HOOKS-API.md section 2): there is no equivalent
	 * "wait for a startup action" contract with a third-party plugin we
	 * don't control.
	 */
	public function register(): void {
		if ( defined( 'WPSEO_VERSION' ) ) {
			add_filter( 'wpseo_llmstxt_link_description', array( $this, 'filter_yoast_description' ), 10, 3 );
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			add_filter( 'rank_math/llms_txt/post_description', array( $this, 'filter_rank_math_description' ), 10, 2 );
		}

		if ( defined( 'AIOSEO_VERSION' ) ) {
			add_filter( 'aioseo_llms_post_description', array( $this, 'filter_aioseo_description' ), 10, 2 );
		}
	}

	/**
	 * Yoast SEO: `wpseo_llmstxt_link_description`.
	 *
	 * @param string $description Yoast's default description for this link.
	 * @param int    $post_id     The post's ID.
	 * @param string $post_type   The post's post type.
	 * @return string
	 */
	public function filter_yoast_description( $description, $post_id, $post_type ) {
		if ( ! $this->enabled() || ! in_array( $post_type, self::POST_TYPES, true ) ) {
			return $description;
		}

		return $this->fallback_description( $description, (int) $post_id );
	}

	/**
	 * Rank Math: `rank_math/llms_txt/post_description` (since 1.0.276).
	 *
	 * @param string        $description Rank Math's default description (the post excerpt).
	 * @param \WP_Post|null $post        The post object.
	 * @return string
	 */
	public function filter_rank_math_description( $description, $post ) {
		if ( ! $this->enabled() || ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return $description;
		}

		return $this->fallback_description( $description, $post->ID );
	}

	/**
	 * AIOSEO: `aioseo_llms_post_description`.
	 *
	 * @param string        $description AIOSEO's default description (its meta description, or the post excerpt).
	 * @param \WP_Post|null $post        The post object.
	 * @return string
	 */
	public function filter_aioseo_description( $description, $post ) {
		if ( ! $this->enabled() || ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return $description;
		}

		return $this->fallback_description( $description, $post->ID );
	}

	/**
	 * Fills an empty description with our own plain-text excerpt; leaves a
	 * non-empty one (the host plugin's own resolved value) untouched.
	 *
	 * @param mixed $description The host plugin's description value.
	 * @param int   $post_id     Post ID to fall back to.
	 * @return string
	 */
	private function fallback_description( $description, int $post_id ): string {
		$description = is_string( $description ) ? trim( $description ) : '';

		if ( '' !== $description ) {
			return $description;
		}

		return html_entity_decode( wp_strip_all_tags( get_the_excerpt( $post_id ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Whether the AI-readability feature (Markdown output + llms.txt) is
	 * enabled. Same defensive get_option() read pattern as
	 * Breadcrumbs::structured_data_enabled().
	 *
	 * @return bool
	 */
	private function enabled(): bool {
		$settings = get_option( 'saai_knowledge_settings' );

		if ( ! is_array( $settings ) || ! array_key_exists( 'ai_readability_enabled', $settings ) ) {
			return true;
		}

		return (bool) $settings['ai_readability_enabled'];
	}
}
