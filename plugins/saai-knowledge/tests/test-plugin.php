<?php
/**
 * Tests for Plugin::activate().
 *
 * @package SAAI\Knowledge
 */

use SAAI\Knowledge\Plugin;

/**
 * Class Test_Plugin.
 */
class Test_Plugin extends WP_UnitTestCase {

	/**
	 * Snapshot of $wp_rewrite's extra top-priority rules before the test,
	 * so tear_down() can restore it — activate() registers into the same
	 * global $wp_rewrite every other test in the process shares, and
	 * flush_rewrite_rules()/set_permalink_structure() were found (in
	 * review) to destabilize unrelated Test_Template_Loader tests when
	 * called directly from a unit test, so this test avoids both and
	 * inspects $wp_rewrite's rule registration directly instead.
	 *
	 * @var array<string, string>
	 */
	private $original_extra_rules_top;

	/**
	 * Snapshots $wp_rewrite state before the test.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rewrite;
		$this->original_extra_rules_top = $wp_rewrite->extra_rules_top;
	}

	/**
	 * Restores $wp_rewrite state so this test can't leak into any other
	 * test in the same process.
	 */
	public function tear_down() {
		global $wp_rewrite;
		$wp_rewrite->extra_rules_top = $this->original_extra_rules_top;

		parent::tear_down();
	}

	/**
	 * The activate() method must register Llms_Index's `/{kb slug}/llms.txt`
	 * rewrite rule before it flushes, the same way it already does for the
	 * CPTs/taxonomies — otherwise that route 404s on a fresh install until
	 * some unrelated later event happens to trigger another flush (Codex
	 * review).
	 */
	public function test_activate_registers_llms_index_rewrite_rule() {
		Plugin::activate();

		global $wp_rewrite;

		$this->assertArrayHasKey( '^kb/llms\.txt$', $wp_rewrite->extra_rules_top );
		$this->assertSame( 'index.php?saai_llms_index=1', $wp_rewrite->extra_rules_top['^kb/llms\.txt$'] );
	}
}
