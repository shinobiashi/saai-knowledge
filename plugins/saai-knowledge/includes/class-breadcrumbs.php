<?php
/**
 * Builds the KB breadcrumb trail (hub > category ancestors > article) and
 * its BreadcrumbList JSON-LD representation, shared by the breadcrumbs block.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the KB breadcrumb trail for a saai_kb article or a saai_category
 * term archive, and the schema.org BreadcrumbList for it.
 */
final class Breadcrumbs {

	/**
	 * Builds the breadcrumb trail.
	 *
	 * Pass a saai_kb post for a single-article trail (its saai_category
	 * ancestors are resolved automatically), a saai_category term for a
	 * category archive trail, or neither for the KB hub archive itself.
	 *
	 * @param \WP_Post|null $post Current saai_kb article, if viewing one.
	 * @param \WP_Term|null $term Current saai_category term, if viewing an archive.
	 * @return array<int, array<string, mixed>> Node list, see class docblock for shape.
	 */
	public function build( ?\WP_Post $post = null, ?\WP_Term $term = null ): array {
		$trail = array(
			array(
				'label'   => $this->hub_label(),
				'url'     => $this->hub_url(),
				'current' => ! $post && ! $term,
			),
		);

		if ( $term instanceof \WP_Term && 'saai_category' === $term->taxonomy ) {
			foreach ( $this->ancestor_chain( $term ) as $ancestor ) {
				$trail[] = $this->term_node( $ancestor, false );
			}

			$trail[] = $this->term_node( $term, true );
		} elseif ( $post instanceof \WP_Post && 'saai_kb' === $post->post_type ) {
			$leaf_term = $this->primary_term( $post );

			if ( $leaf_term instanceof \WP_Term ) {
				foreach ( $this->ancestor_chain( $leaf_term ) as $ancestor ) {
					$trail[] = $this->term_node( $ancestor, false );
				}

				$trail[] = $this->term_node( $leaf_term, false );
			}

			$trail[] = array(
				'label'   => get_the_title( $post ),
				'url'     => (string) get_permalink( $post ),
				'current' => true,
			);
		}

		/**
		 * Filters the assembled breadcrumb trail.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int, array<string, mixed>> $trail   The breadcrumb trail.
		 * @param array<string, mixed>             $context Context, see docs/DESIGN-HOOKS-API.md section 3.2.
		 */
		$filtered_trail = apply_filters(
			'saai_breadcrumbs_items',
			$trail,
			array(
				'post_id'  => $post instanceof \WP_Post ? $post->ID : null,
				'term_id'  => $term instanceof \WP_Term ? $term->term_id : null,
				'taxonomy' => 'saai_category',
			)
		);

		// @phpstan-ignore ternary.elseUnreachable (PHPStan trusts the docblock @param type above, but a third-party saai_breadcrumbs_items callback can violate it at runtime.)
		return is_array( $filtered_trail ) ? $filtered_trail : $trail;
	}

	/**
	 * Builds the schema.org BreadcrumbList for an assembled trail.
	 *
	 * @param array<int, array<string, mixed>> $trail Trail from build().
	 * @param \WP_Post|null                    $post  The current post, if any.
	 * @return array<string, mixed>
	 */
	public function json_ld( array $trail, ?\WP_Post $post = null ): array {
		$items    = array();
		$position = 1;

		foreach ( $trail as $node ) {
			if ( ! is_array( $node ) ) {
				// A third-party saai_breadcrumbs_items callback returned a non-array entry; skip it.
				continue;
			}

			$label = $node['label'] ?? '';

			// Not empty(): a crumb legitimately titled "0" must not be dropped.
			if ( ! is_scalar( $label ) || '' === (string) $label ) {
				// A third-party saai_breadcrumbs_items callback returned an entry with no usable label; skip it.
				continue;
			}

			$item = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => (string) $label,
			);

			$url = $node['url'] ?? '';

			if ( is_scalar( $url ) && '' !== (string) $url ) {
				$item['item'] = (string) $url;
			}

			$items[] = $item;
			++$position;
		}

		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		);

		/**
		 * Filters JSON-LD structured data before output.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $schema      The schema.org data.
		 * @param string               $schema_type Schema type identifier: faq-page / defined-term / breadcrumbs.
		 * @param \WP_Post|null        $post        The current post, if any.
		 */
		$filtered_schema = apply_filters( 'saai_structured_data', $schema, 'breadcrumbs', $post );

		// @phpstan-ignore ternary.elseUnreachable (PHPStan trusts the docblock @param type above, but a third-party saai_structured_data callback can violate it at runtime.)
		return is_array( $filtered_schema ) ? $filtered_schema : $schema;
	}

	/**
	 * Whether structured data output is enabled in settings.
	 *
	 * Reads the `structured_data` key of the `saai_knowledge_settings` option
	 * documented in docs/DESIGN.md section 3.4; defaults to enabled since the
	 * settings screen (M4) doesn't exist yet to have written a value.
	 *
	 * @return bool
	 */
	public function structured_data_enabled(): bool {
		$settings = get_option( 'saai_knowledge_settings' );

		if ( ! is_array( $settings ) || ! array_key_exists( 'structured_data', $settings ) ) {
			return true;
		}

		return (bool) $settings['structured_data'];
	}

	/**
	 * The saai_kb archive ("hub") URL.
	 *
	 * @return string
	 */
	private function hub_url(): string {
		$url = get_post_type_archive_link( 'saai_kb' );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * The saai_kb archive ("hub") label.
	 *
	 * @return string
	 */
	private function hub_label(): string {
		$post_type_object = get_post_type_object( 'saai_kb' );

		if ( $post_type_object instanceof \WP_Post_Type && isset( $post_type_object->labels->name ) ) {
			return (string) $post_type_object->labels->name;
		}

		return __( 'Knowledge Base', 'saai-knowledge' );
	}

	/**
	 * The saai_category term an article's breadcrumb trail is built from.
	 *
	 * Articles can carry more than one saai_category term (it's a
	 * non-exclusive taxonomy); the first term in the sidebar's display
	 * order (saai_order term meta, then name — same ordering as
	 * Sidebar_Tree::sort_terms()) is chosen, so the breadcrumb path matches
	 * where the article first appears in the kb-sidebar tree. term_id is a
	 * final tie-break for determinism.
	 *
	 * @param \WP_Post $post Article to resolve the term for.
	 * @return \WP_Term|null
	 */
	private function primary_term( \WP_Post $post ): ?\WP_Term {
		$terms = get_the_terms( $post, 'saai_category' );

		if ( ! is_array( $terms ) || ! $terms ) {
			return null;
		}

		usort(
			$terms,
			static function ( \WP_Term $a, \WP_Term $b ): int {
				$order_a = (int) get_term_meta( $a->term_id, 'saai_order', true );
				$order_b = (int) get_term_meta( $b->term_id, 'saai_order', true );

				if ( $order_a !== $order_b ) {
					return $order_a <=> $order_b;
				}

				$by_name = strcasecmp( $a->name, $b->name );

				if ( 0 !== $by_name ) {
					return $by_name;
				}

				return $a->term_id <=> $b->term_id;
			}
		);

		return $terms[0];
	}

	/**
	 * A term's saai_category ancestors, ordered from root to immediate parent.
	 *
	 * @param \WP_Term $term Term to walk up from.
	 * @return \WP_Term[]
	 */
	private function ancestor_chain( \WP_Term $term ): array {
		$ancestor_ids = array_reverse( get_ancestors( $term->term_id, 'saai_category', 'taxonomy' ) );
		$ancestors    = array();

		foreach ( $ancestor_ids as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, 'saai_category' );

			if ( $ancestor instanceof \WP_Term ) {
				$ancestors[] = $ancestor;
			}
		}

		return $ancestors;
	}

	/**
	 * Builds a single trail node for a saai_category term.
	 *
	 * @param \WP_Term $term      Term to render.
	 * @param bool     $is_current Whether this term is the trail's current (last) item.
	 * @return array<string, mixed>
	 */
	private function term_node( \WP_Term $term, bool $is_current ): array {
		$link = get_term_link( $term );

		return array(
			'label'   => $term->name,
			'url'     => is_wp_error( $link ) ? '' : (string) $link,
			'current' => $is_current,
		);
	}
}
