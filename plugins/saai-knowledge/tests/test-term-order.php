<?php
/**
 * Tests for the saai_order term meta and its query ordering.
 *
 * @package SAAI\Knowledge
 */

/**
 * Class Test_Term_Order.
 */
class Test_Term_Order extends WP_UnitTestCase {

	/**
	 * The service under test.
	 *
	 * @var \SAAI\Knowledge\Term_Order
	 */
	private $term_order;

	/**
	 * Re-registers the term meta before each test; the WP test framework
	 * clears all registered meta keys in tear_down().
	 */
	public function set_up(): void {
		parent::set_up();

		$this->term_order = new \SAAI\Knowledge\Term_Order();
		$this->term_order->register_term_meta();
	}

	/**
	 * The saai_order term meta should be registered for saai_category
	 * as a REST-exposed integer.
	 */
	public function test_meta_is_registered() {
		$registered = get_registered_meta_keys( 'term', 'saai_category' );

		$this->assertArrayHasKey( 'saai_order', $registered );
		$this->assertSame( 'integer', $registered['saai_order']['type'] );
		$this->assertTrue( $registered['saai_order']['single'] );
		$this->assertTrue( (bool) $registered['saai_order']['show_in_rest'] );
	}

	/**
	 * Default get_terms() queries for saai_category should order by
	 * saai_order (unset meta sorts as 0), then name.
	 */
	public function test_get_terms_orders_by_saai_order_then_name() {
		$alpha = self::factory()->term->create(
			array(
				'taxonomy' => 'saai_category',
				'name'     => 'Alpha',
			)
		);
		$beta  = self::factory()->term->create(
			array(
				'taxonomy' => 'saai_category',
				'name'     => 'Beta',
			)
		);
		$gamma = self::factory()->term->create(
			array(
				'taxonomy' => 'saai_category',
				'name'     => 'Gamma',
			)
		);

		update_term_meta( $alpha, 'saai_order', 2 );
		update_term_meta( $gamma, 'saai_order', 1 );

		$terms = get_terms(
			array(
				'taxonomy'   => 'saai_category',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		$this->assertSame( array( $beta, $gamma, $alpha ), $terms );
	}

	/**
	 * An explicit orderby => saai_order should apply the meta ordering
	 * even where the caller would otherwise sort differently.
	 */
	public function test_explicit_saai_order_orderby_applies_meta_ordering() {
		$alpha = self::factory()->term->create(
			array(
				'taxonomy' => 'saai_category',
				'name'     => 'Alpha',
			)
		);
		$beta  = self::factory()->term->create(
			array(
				'taxonomy' => 'saai_category',
				'name'     => 'Beta',
			)
		);

		update_term_meta( $alpha, 'saai_order', 2 );
		update_term_meta( $beta, 'saai_order', 1 );

		$terms = get_terms(
			array(
				'taxonomy'   => 'saai_category',
				'hide_empty' => false,
				'orderby'    => 'saai_order',
				'fields'     => 'ids',
			)
		);

		$this->assertSame( array( $beta, $alpha ), $terms );
	}

	/**
	 * Passing order => DESC should reverse the meta ordering.
	 */
	public function test_descending_order_is_respected() {
		$alpha = self::factory()->term->create(
			array(
				'taxonomy' => 'saai_category',
				'name'     => 'Alpha',
			)
		);
		$beta  = self::factory()->term->create(
			array(
				'taxonomy' => 'saai_category',
				'name'     => 'Beta',
			)
		);

		update_term_meta( $alpha, 'saai_order', 1 );
		update_term_meta( $beta, 'saai_order', 2 );

		$terms = get_terms(
			array(
				'taxonomy'   => 'saai_category',
				'hide_empty' => false,
				'order'      => 'DESC',
				'fields'     => 'ids',
			)
		);

		$this->assertSame( array( $beta, $alpha ), $terms );
	}

	/**
	 * An explicit non-name orderby should be left untouched.
	 */
	public function test_explicit_other_orderby_is_untouched() {
		$first  = self::factory()->term->create(
			array(
				'taxonomy' => 'saai_category',
				'name'     => 'First created',
			)
		);
		$second = self::factory()->term->create(
			array(
				'taxonomy' => 'saai_category',
				'name'     => 'Second created',
			)
		);

		update_term_meta( $first, 'saai_order', 9 );
		update_term_meta( $second, 'saai_order', 1 );

		$terms = get_terms(
			array(
				'taxonomy'   => 'saai_category',
				'hide_empty' => false,
				'orderby'    => 'id',
				'fields'     => 'ids',
			)
		);

		$this->assertSame( array( $first, $second ), $terms );
	}

	/**
	 * Other taxonomies should keep their plain name ordering even when
	 * their terms carry a saai_order meta row.
	 */
	public function test_other_taxonomies_are_not_affected() {
		$zeta  = self::factory()->term->create(
			array(
				'taxonomy' => 'saai_tag',
				'name'     => 'Zeta',
			)
		);
		$alpha = self::factory()->term->create(
			array(
				'taxonomy' => 'saai_tag',
				'name'     => 'Alpha',
			)
		);

		update_term_meta( $zeta, 'saai_order', 1 );
		update_term_meta( $alpha, 'saai_order', 2 );

		$terms = get_terms(
			array(
				'taxonomy'   => 'saai_tag',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		$this->assertSame( array( $alpha, $zeta ), $terms );
	}

	/**
	 * Count queries (fields => count) have no orderby clause and must pass
	 * through without SQL errors.
	 */
	public function test_count_query_is_unaffected() {
		self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );
		self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );

		$count = get_terms(
			array(
				'taxonomy'   => 'saai_category',
				'hide_empty' => false,
				'fields'     => 'count',
			)
		);

		$this->assertSame( 2, (int) $count );
	}

	/**
	 * The form save handler should store the submitted order, and an empty
	 * submission should delete the meta row.
	 */
	public function test_save_term_order_saves_and_deletes() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );

		$_POST['saai_order_nonce'] = wp_create_nonce( 'saai_order_save' );
		$_POST['saai_order']       = '5';

		$this->term_order->save_term_order( $term_id );

		$this->assertSame( 5, (int) get_term_meta( $term_id, 'saai_order', true ) );

		$_POST['saai_order'] = '';

		$this->term_order->save_term_order( $term_id );

		$this->assertFalse( metadata_exists( 'term', $term_id, 'saai_order' ) );

		unset( $_POST['saai_order_nonce'], $_POST['saai_order'] );
	}

	/**
	 * Without a valid nonce the save handler must not write anything.
	 */
	public function test_save_term_order_requires_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );

		$_POST['saai_order_nonce'] = 'invalid';
		$_POST['saai_order']       = '5';

		$this->term_order->save_term_order( $term_id );

		$this->assertFalse( metadata_exists( 'term', $term_id, 'saai_order' ) );

		unset( $_POST['saai_order_nonce'], $_POST['saai_order'] );
	}

	/**
	 * Users who cannot edit the term must not be able to write the meta.
	 */
	public function test_save_term_order_requires_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'saai_category' ) );

		$_POST['saai_order_nonce'] = wp_create_nonce( 'saai_order_save' );
		$_POST['saai_order']       = '5';

		$this->term_order->save_term_order( $term_id );

		$this->assertFalse( metadata_exists( 'term', $term_id, 'saai_order' ) );

		unset( $_POST['saai_order_nonce'], $_POST['saai_order'] );
	}
}
