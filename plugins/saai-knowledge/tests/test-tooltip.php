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
	 * Assets_are_registered() asks WP_Styles/WP_Script_Modules directly
	 * (see class-tooltip.php), and unlike options/post data those registries
	 * are process-global singletons WP_UnitTestCase does not reset between
	 * tests. Deregistering before each test keeps assets_are_registered()
	 * false-by-default regardless of run order, matching a real request's
	 * first-boot state.
	 */
	public function set_up() {
		parent::set_up();

		wp_deregister_style( 'saai-knowledge-tooltip' );
		wp_deregister_script_module( 'saai-knowledge/tooltip' );
	}

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
	 * Registers the tooltip module/style directly (bypassing register_assets()'s
	 * dependency on the webpack-built build/tooltip/ directory, which CI's
	 * PHP-only PHPUnit job never generates — same concern as
	 * class-blocks.php's file_exists() guard) with a throwaway src, so
	 * render()'s assets_are_registered() check (which asks core's own
	 * registries) and wp_enqueue_*() calls have something real to see.
	 */
	private function fake_assets_registered(): void {
		wp_register_script_module( 'saai-knowledge/tooltip', 'https://example.com/tooltip.js' );
		wp_register_style( 'saai-knowledge-tooltip', 'https://example.com/tooltip.css', array(), '1.0' );
	}

	/**
	 * Register() hooks asset registration onto init and the footer render
	 * onto wp_footer at the default priority — see class-tooltip.php's
	 * register() docblock for why a later priority would silently break
	 * the module/style output entirely.
	 */
	public function test_register_hooks_init_and_wp_footer() {
		list( $tooltip ) = $this->make_tooltip( false );

		$tooltip->register();

		$this->assertNotFalse( has_action( 'init', array( $tooltip, 'register_assets' ) ) );
		$this->assertSame( 10, has_action( 'wp_footer', array( $tooltip, 'render' ) ) );
	}

	/**
	 * Most pages never auto-link a term; render() must not print the
	 * singleton element or enqueue anything on those pages.
	 */
	public function test_render_is_a_no_op_when_no_links_were_rendered() {
		list( $tooltip ) = $this->make_tooltip( false );

		$this->fake_assets_registered();

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

		$this->fake_assets_registered();

		$output = get_echo( array( $tooltip, 'render' ) );

		$this->assertStringContainsString( '<div id="saai-tooltip"', $output );
		$this->assertStringContainsString( 'role="tooltip"', $output );
		$this->assertStringContainsString( 'hidden', $output );

		$this->assertContains( 'saai-knowledge/tooltip', wp_script_modules()->get_queue() );
		$this->assertTrue( wp_style_is( 'saai-knowledge-tooltip', 'enqueued' ) );
	}
}
