<?php
/**
 * Tests for Plugin::activate() and the version-upgrade rewrite flush.
 *
 * @package SAAI\Knowledge
 */

use SAAI\Knowledge\Plugin;

/**
 * Class Test_Plugin.
 */
class Test_Plugin extends WP_UnitTestCase {

	/**
	 * Full snapshot of the global $wp_rewrite object before the test, so
	 * tear_down() can restore it wholesale.
	 *
	 * The activate() method (and maybe_flush_rewrite_rules_on_upgrade()) call the real
	 * flush_rewrite_rules(), which mutates far more of $wp_rewrite than just
	 * the rule this test asserts on (its cached `rules` array, etc.) — every
	 * other test in the same PHPUnit process shares this one global object,
	 * so restoring only the property this test happens to touch isn't
	 * enough to guarantee no leak (Codex review: an earlier version of this
	 * test only restored `extra_rules_top`). Replacing the whole object
	 * reference with a clone taken before the test is the only way to
	 * guarantee every property flush_rewrite_rules() might touch is
	 * restored, without having to enumerate them.
	 *
	 * @var \WP_Rewrite
	 */
	private $original_wp_rewrite;

	/**
	 * Snapshots $wp_rewrite before the test.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rewrite;
		$this->original_wp_rewrite = clone $wp_rewrite;
	}

	/**
	 * Restores $wp_rewrite wholesale so this test can't leak into any other
	 * test in the same process. The `rewrite_rules` and
	 * `saai_knowledge_version` DB options flush_rewrite_rules()/activate()
	 * write don't need the same treatment: WP_UnitTestCase rolls back every
	 * DB change (including plain update_option() calls) between tests on
	 * its own.
	 */
	public function tear_down() {
		global $wp_rewrite;
		$wp_rewrite = $this->original_wp_rewrite; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the shared global to its pre-test snapshot, not introducing a new one.

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

	/**
	 * The maybe_flush_rewrite_rules_on_upgrade() method flushes (and records the
	 * current version) when the stored version doesn't match — the path
	 * that covers a WordPress.org auto-update, which never runs activate()
	 * (Codex review) — and is a no-op once the version is already current.
	 */
	public function test_maybe_flush_rewrite_rules_on_upgrade_flushes_once_per_version() {
		$plugin = new Plugin();

		delete_option( 'saai_knowledge_version' );
		$plugin->maybe_flush_rewrite_rules_on_upgrade();
		$this->assertSame( SAAI_KNOWLEDGE_VERSION, get_option( 'saai_knowledge_version' ) );

		update_option( 'saai_knowledge_version', '0.0.0' );
		$plugin->maybe_flush_rewrite_rules_on_upgrade();
		$this->assertSame( SAAI_KNOWLEDGE_VERSION, get_option( 'saai_knowledge_version' ) );
	}
}
