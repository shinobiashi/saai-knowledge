<?php
/**
 * Builds the saai_category + saai_kb tree consumed by the kb-sidebar block.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles the hierarchical sidebar tree: saai_category terms with their
 * child terms and saai_kb posts nested underneath, in display order.
 */
final class Sidebar_Tree {

	/**
	 * Builds the full sidebar tree.
	 *
	 * @param int|null $current_post_id The currently viewed post, if any.
	 *                                  Its ancestor terms are marked expanded.
	 * @return array<int, array<string, mixed>> Node list, see class docblock for shape.
	 */
	public function build( ?int $current_post_id = null ): array {
		$ancestor_term_ids = null !== $current_post_id
			? $this->ancestor_term_ids_for_post( $current_post_id )
			: array();

		$top_level_terms = get_terms(
			array(
				'taxonomy'   => 'saai_category',
				'parent'     => 0,
				'hide_empty' => false,
			)
		);

		$tree = array();

		if ( ! is_wp_error( $top_level_terms ) ) {
			foreach ( $this->sort_terms( $top_level_terms ) as $term ) {
				$tree[] = $this->build_term_node( $term, $ancestor_term_ids );
			}
		}

		/**
		 * Filters the assembled sidebar tree.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int, array<string, mixed>> $tree    The sidebar tree.
		 * @param array<string, mixed>              $context Context, see docs/DESIGN-HOOKS-API.md section 3.2.
		 */
		$filtered_tree = apply_filters(
			'saai_kb_sidebar_items',
			$tree,
			array(
				'current_post_id' => $current_post_id,
				'taxonomy'        => 'saai_category',
			)
		);

		// @phpstan-ignore ternary.elseUnreachable (PHPStan trusts the docblock @param type above, but a third-party saai_kb_sidebar_items callback can violate it at runtime.)
		return is_array( $filtered_tree ) ? $filtered_tree : $tree;
	}

	/**
	 * Builds a single term node, including its child terms and articles.
	 *
	 * @param \WP_Term $term              The term to render.
	 * @param int[]    $ancestor_term_ids Term IDs to auto-expand.
	 * @return array<string, mixed>
	 */
	private function build_term_node( \WP_Term $term, array $ancestor_term_ids ): array {
		$children = array();

		$child_terms = get_terms(
			array(
				'taxonomy'   => 'saai_category',
				'parent'     => $term->term_id,
				'hide_empty' => false,
			)
		);

		if ( ! is_wp_error( $child_terms ) ) {
			foreach ( $this->sort_terms( $child_terms ) as $child_term ) {
				$children[] = $this->build_term_node( $child_term, $ancestor_term_ids );
			}
		}

		foreach ( $this->term_articles( $term->term_id ) as $post ) {
			$children[] = array(
				'type'     => 'post',
				'id'       => $post->ID,
				'title'    => get_the_title( $post ),
				'url'      => (string) get_permalink( $post ),
				'order'    => (int) $post->menu_order,
				'children' => array(),
			);
		}

		$term_link = get_term_link( $term );

		return array(
			'type'     => 'term',
			'id'       => $term->term_id,
			'title'    => $term->name,
			'url'      => is_wp_error( $term_link ) ? '' : $term_link,
			'order'    => (int) get_term_meta( $term->term_id, 'saai_order', true ),
			'children' => $children,
			'expanded' => in_array( $term->term_id, $ancestor_term_ids, true ),
		);
	}

	/**
	 * The saai_kb articles directly assigned to a term, in menu_order.
	 *
	 * @param int $term_id Term ID.
	 * @return \WP_Post[]
	 */
	private function term_articles( int $term_id ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => 'saai_kb',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => array(
					array(
						'taxonomy'         => 'saai_category',
						'field'            => 'term_id',
						'terms'            => $term_id,
						'include_children' => false,
					),
				),
			)
		);

		return $query->posts;
	}

	/**
	 * Sorts terms by their saai_order term meta, then by name.
	 *
	 * @param \WP_Term[] $terms Terms to sort.
	 * @return \WP_Term[]
	 */
	private function sort_terms( array $terms ): array {
		usort(
			$terms,
			static function ( \WP_Term $a, \WP_Term $b ): int {
				$order_a = (int) get_term_meta( $a->term_id, 'saai_order', true );
				$order_b = (int) get_term_meta( $b->term_id, 'saai_order', true );

				if ( $order_a !== $order_b ) {
					return $order_a <=> $order_b;
				}

				return strcasecmp( $a->name, $b->name );
			}
		);

		return $terms;
	}

	/**
	 * The saai_category ancestor term IDs for a post, including its own assigned terms.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	private function ancestor_term_ids_for_post( int $post_id ): array {
		$terms = get_the_terms( $post_id, 'saai_category' );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$ids = array();

		foreach ( $terms as $term ) {
			$ids[] = $term->term_id;

			foreach ( get_ancestors( $term->term_id, 'saai_category', 'taxonomy' ) as $ancestor_id ) {
				$ids[] = $ancestor_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
