<?php
/**
 * Tests for the Llms_Txt_Adapter service (docs/DESIGN.md section 7.2,
 * layer 1).
 *
 * The actual add_filter() calls in register() are gated on the relevant SEO
 * plugin being loaded (defined( 'WPSEO_VERSION' ), etc.), which isn't true
 * in this test environment. These tests instead call each plugin's
 * would-be filter callback directly — the same thing register() would wire
 * up — so the adapter's actual logic is verified without needing Yoast/Rank
 * Math/AIOSEO installed. See docs/DEVELOPMENT-PLAN.md's M4-6 note for the
 * manual, real-plugin verification this complements.
 *
 * @package SAAI\Knowledge
 */

use SAAI\Knowledge\Llms_Txt_Adapter;

/**
 * Class Test_Llms_Txt_Adapter.
 */
class Test_Llms_Txt_Adapter extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Llms_Txt_Adapter
	 */
	private $adapter;

	/**
	 * Sets up the service under test.
	 */
	public function set_up() {
		parent::set_up();

		$this->adapter = new Llms_Txt_Adapter();
	}

	/**
	 * Creates a published saai_faq post with the given excerpt.
	 *
	 * @param string $excerpt Post excerpt.
	 * @return WP_Post
	 */
	private function create_faq_with_excerpt( string $excerpt ): \WP_Post {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_faq',
				'post_excerpt' => $excerpt,
				'post_status'  => 'publish',
			)
		);

		return get_post( $post_id );
	}

	/**
	 * Yoast's filter: an empty description is filled from our excerpt.
	 */
	public function test_yoast_filter_fills_empty_description() {
		$post = $this->create_faq_with_excerpt( 'Our excerpt.' );

		$result = $this->adapter->filter_yoast_description( '', $post->ID, 'saai_faq' );

		$this->assertSame( 'Our excerpt.', $result );
	}

	/**
	 * Yoast's filter leaves a non-empty description (Yoast's own resolved
	 * value) untouched.
	 */
	public function test_yoast_filter_preserves_existing_description() {
		$post = $this->create_faq_with_excerpt( 'Our excerpt.' );

		$result = $this->adapter->filter_yoast_description( "Yoast's own text", $post->ID, 'saai_faq' );

		$this->assertSame( "Yoast's own text", $result );
	}

	/**
	 * Yoast's filter is a no-op for a post type it doesn't cover.
	 */
	public function test_yoast_filter_ignores_other_post_types() {
		$post_id = self::factory()->post->create( array( 'post_excerpt' => 'Our excerpt.' ) );

		$result = $this->adapter->filter_yoast_description( '', $post_id, 'post' );

		$this->assertSame( '', $result );
	}

	/**
	 * Rank Math's filter: an empty description is filled from our excerpt.
	 */
	public function test_rank_math_filter_fills_empty_description() {
		$post = $this->create_faq_with_excerpt( 'Our excerpt.' );

		$result = $this->adapter->filter_rank_math_description( '', $post );

		$this->assertSame( 'Our excerpt.', $result );
	}

	/**
	 * AIOSEO's filter: an empty description is filled from our excerpt.
	 */
	public function test_aioseo_filter_fills_empty_description() {
		$post = $this->create_faq_with_excerpt( 'Our excerpt.' );

		$result = $this->adapter->filter_aioseo_description( '', $post );

		$this->assertSame( 'Our excerpt.', $result );
	}

	/**
	 * Turning the ai_readability_enabled setting off disables the
	 * description fallback entirely, matching the other AI-readability
	 * features' shared toggle.
	 */
	public function test_disabled_setting_skips_fallback() {
		update_option( 'saai_knowledge_settings', array( 'ai_readability_enabled' => false ) );

		$post = $this->create_faq_with_excerpt( 'Our excerpt.' );

		$result = $this->adapter->filter_rank_math_description( '', $post );

		$this->assertSame( '', $result );
	}
}
