<?php
/**
 * Registers the saai_order term meta and applies it to term queries.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the saai_category display order: registers the saai_order term
 * meta, renders the admin form fields, and orders get_terms() results by
 * the meta value (ties fall back to name).
 */
final class Term_Order {

	/**
	 * Meta key holding a term's display order.
	 *
	 * @var string
	 */
	public const META_KEY = 'saai_order';

	/**
	 * Taxonomy the display order applies to.
	 *
	 * @var string
	 */
	public const TAXONOMY = 'saai_category';

	/**
	 * Nonce action for the term form fields.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'saai_order_save';

	/**
	 * Nonce field name for the term form fields.
	 *
	 * @var string
	 */
	private const NONCE_NAME = 'saai_order_nonce';

	/**
	 * Hooks term meta registration, query ordering, and the admin UI.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_term_meta' ) );
		add_filter( 'terms_clauses', array( $this, 'filter_terms_clauses' ), 10, 3 );

		if ( is_admin() ) {
			add_action( self::TAXONOMY . '_add_form_fields', array( $this, 'render_add_form_field' ) );
			add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'render_edit_form_field' ) );
			add_action( 'created_' . self::TAXONOMY, array( $this, 'save_term_order' ) );
			add_action( 'edited_' . self::TAXONOMY, array( $this, 'save_term_order' ) );
			add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', array( $this, 'add_order_column' ) );
			add_filter( 'manage_' . self::TAXONOMY . '_custom_column', array( $this, 'render_order_column' ), 10, 3 );
		}
	}

	/**
	 * Registers the saai_order term meta.
	 */
	public function register_term_meta(): void {
		register_term_meta(
			self::TAXONOMY,
			self::META_KEY,
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( $this, 'can_edit_term_meta' ),
			)
		);
	}

	/**
	 * Orders saai_category term queries by saai_order, then name.
	 *
	 * Applies when the query is for saai_category only and the caller either
	 * asked for `orderby => saai_order` explicitly or left the default
	 * (`name`). Terms without the meta sort as 0, so an untouched taxonomy
	 * keeps its plain alphabetical order. Any other explicit orderby
	 * (count, id, include, ...) is left alone.
	 *
	 * @param array<string, string> $clauses    Term query SQL clauses.
	 * @param string[]              $taxonomies Taxonomies queried.
	 * @param array<string, mixed>  $args       Term query arguments.
	 * @return array<string, string>
	 */
	public function filter_terms_clauses( array $clauses, array $taxonomies, array $args ): array {
		global $wpdb;

		if ( array( self::TAXONOMY ) !== $taxonomies ) {
			return $clauses;
		}

		$orderby = isset( $args['orderby'] ) && is_string( $args['orderby'] ) ? $args['orderby'] : 'name';

		if ( ! in_array( $orderby, array( 'name', self::META_KEY ), true ) ) {
			return $clauses;
		}

		if ( empty( $clauses['orderby'] ) ) {
			return $clauses;
		}

		$order = isset( $args['order'] ) && is_string( $args['order'] ) && 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

		$clauses['join']   .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->termmeta} AS saai_order_meta ON ( t.term_id = saai_order_meta.term_id AND saai_order_meta.meta_key = %s )",
			self::META_KEY
		);
		$clauses['orderby'] = "ORDER BY COALESCE( CAST( saai_order_meta.meta_value AS SIGNED ), 0 ) {$order}, t.name";
		$clauses['order']   = $order;
		// A term with duplicate saai_order rows (add_term_meta bypassing the
		// single registration) would otherwise appear once per meta row.
		$clauses['distinct'] = 'DISTINCT';

		return $clauses;
	}

	/**
	 * Renders the order field on the "Add New Category" form.
	 */
	public function render_add_form_field(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div class="form-field">
			<label for="saai-order"><?php esc_html_e( 'Order', 'saai-knowledge' ); ?></label>
			<input name="<?php echo esc_attr( self::META_KEY ); ?>" id="saai-order" type="number" min="0" step="1" value="" />
			<p><?php esc_html_e( 'Position among sibling categories. Lower numbers appear first; ties are ordered by name.', 'saai-knowledge' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the order field on the "Edit Category" form.
	 *
	 * @param \WP_Term $term The term being edited.
	 */
	public function render_edit_form_field( \WP_Term $term ): void {
		$value = (int) get_term_meta( $term->term_id, self::META_KEY, true );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<tr class="form-field">
			<th scope="row"><label for="saai-order"><?php esc_html_e( 'Order', 'saai-knowledge' ); ?></label></th>
			<td>
				<input name="<?php echo esc_attr( self::META_KEY ); ?>" id="saai-order" type="number" min="0" step="1" value="<?php echo esc_attr( (string) $value ); ?>" />
				<p class="description"><?php esc_html_e( 'Position among sibling categories. Lower numbers appear first; ties are ordered by name.', 'saai-knowledge' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Saves the submitted order when a term is created or edited.
	 *
	 * Runs on `created_saai_category` / `edited_saai_category`, which also
	 * fire for programmatic wp_insert_term() calls; the nonce check makes
	 * this a no-op outside the term form submissions that rendered it.
	 *
	 * @param int $term_id The saved term's ID.
	 */
	public function save_term_order( int $term_id ): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ], $_POST[ self::META_KEY ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		$raw = sanitize_text_field( wp_unslash( $_POST[ self::META_KEY ] ) );

		if ( '' === $raw ) {
			delete_term_meta( $term_id, self::META_KEY );
			return;
		}

		update_term_meta( $term_id, self::META_KEY, absint( $raw ) );
	}

	/**
	 * Adds the Order column to the saai_category list table.
	 *
	 * @param array<string, string> $columns Column headers.
	 * @return array<string, string>
	 */
	public function add_order_column( array $columns ): array {
		$columns[ self::META_KEY ] = __( 'Order', 'saai-knowledge' );

		return $columns;
	}

	/**
	 * Renders the Order column value in the saai_category list table.
	 *
	 * @param string $content     Current column content.
	 * @param string $column_name Column name.
	 * @param int    $term_id     Term ID.
	 * @return string
	 */
	public function render_order_column( string $content, string $column_name, int $term_id ): string {
		if ( self::META_KEY !== $column_name ) {
			return $content;
		}

		return esc_html( (string) (int) get_term_meta( $term_id, self::META_KEY, true ) );
	}

	/**
	 * Restricts meta write access to users who can edit the term.
	 *
	 * Signature matches the `auth_{$object_type}_meta_{$meta_key}` filter
	 * WordPress invokes this callback through (see `map_meta_cap()` in
	 * wp-includes/capabilities.php). The incoming `$allowed` is intentionally
	 * ignored: it only reflects `is_protected_meta()`, not a real permission
	 * decision, so `current_user_can()` is the actual authorization check.
	 *
	 * @param bool     $allowed  Whether the meta key is allowed to be accessed. Unused.
	 * @param string   $meta_key The meta key. Unused.
	 * @param int      $term_id  Term ID.
	 * @param int      $user_id  User ID. Unused.
	 * @param string   $cap      Capability name. Unused.
	 * @param string[] $caps     Array of the user's capabilities. Unused.
	 * @return bool
	 */
	public function can_edit_term_meta( bool $allowed, string $meta_key, int $term_id, int $user_id = 0, string $cap = '', array $caps = array() ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- kept to match the auth_{$object_type}_meta_{$meta_key} filter signature.
		return current_user_can( 'edit_term', $term_id );
	}
}
