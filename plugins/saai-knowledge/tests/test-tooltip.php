<?php
/**
 * Tests for the tooltip singleton element service (M3-4).
 *
 * @package SAAI\Knowledge
 */

/**
 * Class Test_Tooltip.
 */
class Test_Tooltip extends WP_UnitTestCase {

	/**
	 * Builds a Tooltip whose Autolinker reports has_rendered_links() as
	 * given, without needing a real glossary term/post to produce one (the
	 * flag is a private property on Autolinker, set via reflection).
	 *
	 * @param bool $has_rendered_links Value has_rendered_links() should return.
	 * @return array{0: \SAAI\Knowledge\Tooltip, 1: \SAAI\Knowledge\Autolinker}
	 */
	private function make_tooltip( bool $has_rendered_links ): array {
		$autolinker = new \SAAI\Knowledge\Autolinker();

		$prop = new \ReflectionProperty( \SAAI\Knowledge\Autolinker::class, 'has_rendered_links' );
		$prop->setAccessible( true );
		$prop->setValue( $autolinker, $has_rendered_links );

		return array( new \SAAI\Knowledge\Tooltip( $autolinker ), $autolinker );
	}

	/**
	 * Marks a Tooltip's assets as registered without depending on the
	 * webpack-built build/tooltip/ directory, which CI's PHP-only PHPUnit
	 * job never generates (same concern as class-blocks.php's file_exists()
	 * guard). Registers the module/style with a throwaway src so render()'s
	 * wp_enqueue_*() calls have something real to queue.
	 *
	 * @param \SAAI\Knowledge\Tooltip $tooltip Tooltip instance to mark as ready.
	 */
	private function fake_assets_registered( \SAAI\Knowledge\Tooltip $tooltip ): void {
		wp_register_script_module( 'saai-knowledge/tooltip', 'https://example.com/tooltip.js' );
		wp_register_style( 'saai-knowledge-tooltip', 'https://example.com/tooltip.css', array(), '1.0' );

		$prop = new \ReflectionProperty( \SAAI\Knowledge\Tooltip::class, 'assets_registered' );
		$prop->setAccessible( true );
		$prop->setValue( $tooltip, true );
	}

	/**
	 * Register() hooks asset registration onto init and the footer render
	 * onto wp_footer.
	 */
	public function test_register_hooks_init_and_wp_footer() {
		list( $tooltip ) = $this->make_tooltip( false );

		$tooltip->register();

		$this->assertNotFalse( has_action( 'init', array( $tooltip, 'register_assets' ) ) );
		$this->assertNotFalse( has_action( 'wp_footer', array( $tooltip, 'render' ) ) );
	}

	/**
	 * Most pages never auto-link a term; render() must not print the
	 * singleton element or enqueue anything on those pages.
	 */
	public function test_render_is_a_no_op_when_no_links_were_rendered() {
		list( $tooltip ) = $this->make_tooltip( false );

		$this->fake_assets_registered( $tooltip );

		$this->assertSame( '', get_echo( array( $tooltip, 'render' ) ) );
		$this->assertFalse( wp_style_is( 'saai-knowledge-tooltip', 'enqueued' ) );
	}

	/**
	 * Render() must not enqueue/print anything either when the auto-link
	 * engine did link a term but the build/tooltip/ assets were never
	 * registered (e.g. CI's PHP-only PHPUnit job) — enqueuing an
	 * unregistered module/style would be dead weight on the page.
	 */
	public function test_render_is_a_no_op_when_assets_were_never_registered() {
		list( $tooltip ) = $this->make_tooltip( true );

		$this->assertSame( '', get_echo( array( $tooltip, 'render' ) ) );
	}

	/**
	 * The one case render() should actually do something: a term was
	 * linked and the assets are registered. The singleton element carries
	 * the id every term anchor's aria-describedby points at (see
	 * class-autolinker.php's build_anchor()), and the module/style get
	 * enqueued.
	 */
	public function test_render_prints_tooltip_element_and_enqueues_assets_when_a_link_was_rendered() {
		list( $tooltip ) = $this->make_tooltip( true );

		$this->fake_assets_registered( $tooltip );

		$output = get_echo( array( $tooltip, 'render' ) );

		$this->assertStringContainsString( '<div id="saai-tooltip"', $output );
		$this->assertStringContainsString( 'role="tooltip"', $output );
		$this->assertStringContainsString( 'hidden', $output );

		$this->assertContains( 'saai-knowledge/tooltip', wp_script_modules()->get_queue() );
		$this->assertTrue( wp_style_is( 'saai-knowledge-tooltip', 'enqueued' ) );
	}
}
