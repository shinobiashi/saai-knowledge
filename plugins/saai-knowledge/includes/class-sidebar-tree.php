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
	 * Transient key the assembled tree skeleton is cached under.
	 *
	 * @var string
	 */
	private const CACHE_KEY = 'saai_kb_sidebar_tree';

	/**
	 * How long the skeleton is cached for. This block renders on nearly every
	 * KB page (single article, category archive, KB hub), so — like
	 * Llms_Index — an unbounded rebuild per request doesn't scale with
	 * category/article count. A TTL (rather than relying solely on the
	 * invalidation hooks below) self-heals any edge a hook doesn't cover,
	 * e.g. a saai_order term-meta-only REST update that doesn't fire
	 * created_saai_category/edited_saai_category (same accepted tradeoff as
	 * docs/review-backlog.md R1-L4 for Markdown_Output's cache).
	 *
	 * @var int
	 */
	private const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Hooks cache invalidation into WordPress. build()/build_skeleton() need
	 * no registration themselves — they're called directly by render.php.
	 */
	public function register(): void {
		add_action( 'save_post_saai_kb', array( $this, 'flush_cache' ) );
		add_action( 'trashed_post', array( $this, 'flush_cache' ) );
		// wp_delete_post( $id, true ) (REST's force=true, `wp post delete
		// --force`) skips wp_trash_post() entirely, so trashed_post never
		// fires — deleted_post is needed too (same reasoning as
		// Llms_Index::register()).
		add_action( 'deleted_post', array( $this, 'flush_cache' ) );
		add_action( 'created_saai_category', array( $this, 'flush_cache' ) );
		add_action( 'edited_saai_category', array( $this, 'flush_cache' ) );
		add_action( 'delete_saai_category', array( $this, 'flush_cache' ) );
		// Re-assigning an existing saai_kb post to a different saai_category
		// term (wp-admin's Quick Edit bulk category change, a REST update
		// that only touches taxonomy terms, a direct wp_set_object_terms()
		// call) goes through wp_set_object_terms() without necessarily
		// calling wp_update_post() — save_post_saai_kb above doesn't fire,
		// so this is needed to catch that case too (Codex review). Not
		// narrowed to saai_kb objects: 'saai_category' is shared with
		// saai_faq (docs/DESIGN.md section 3.2), and flushing on an
		// unrelated saai_faq's term change is a harmless extra rebuild, the
		// same tradeoff trashed_post/deleted_post above already accept.
		add_action( 'set_object_terms', array( $this, 'flush_cache_on_term_relationship_change' ), 10, 4 );
		add_action( 'update_option_saai_knowledge_settings', array( $this, 'maybe_flush_cache_on_slug_change' ), 10, 2 );
		// A brand-new install has no saai_knowledge_settings option row yet;
		// update_option() delegates a first-ever save of it to add_option()
		// internally (WordPress core: default_option_{$option} matching the
		// old value short-circuits to add_option()), which never fires
		// update_option_{$option} — only add_option_{$option} does. Without
		// this, a slug changed on that very first save wouldn't flush this
		// cache at all (Codex review).
		add_action( 'add_option_saai_knowledge_settings', array( $this, 'flush_cache' ) );
	}

	/**
	 * Flushes the cache when an object's saai_category term relationships
	 * change via wp_set_object_terms() — see register()'s docblock.
	 *
	 * @param int    $object_id Unused; kept to match the set_object_terms hook signature.
	 * @param int[]  $terms     Unused.
	 * @param int[]  $tt_ids    Unused.
	 * @param string $taxonomy  The taxonomy terms were set for.
	 */
	public function flush_cache_on_term_relationship_change( int $object_id, array $terms, array $tt_ids, string $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $object_id/$terms/$tt_ids must precede $taxonomy to match the set_object_terms hook signature.
		if ( 'saai_category' === $taxonomy ) {
			$this->flush_cache();
		}
	}

	/**
	 * Builds the full sidebar tree.
	 *
	 * @param int|null $current_post_id The currently viewed post, if any.
	 *                                  Its ancestor terms are marked expanded.
	 * @param int|null $current_term_id The currently viewed saai_category term, if
	 *                                  any (e.g. a taxonomy archive). Ignored when
	 *                                  $current_post_id is given. Its own ancestor
	 *                                  terms are marked expanded.
	 * @return array<int, array<string, mixed>> Node list, see class docblock for shape.
	 */
	public function build( ?int $current_post_id = null, ?int $current_term_id = null ): array {
		if ( null !== $current_post_id ) {
			$ancestor_term_ids = $this->ancestor_term_ids_for_post( $current_post_id );
		} elseif ( null !== $current_term_id ) {
			$ancestor_term_ids = $this->ancestor_term_ids_for_terms( array( $current_term_id ) );
		} else {
			$ancestor_term_ids = array();
		}

		// The cached skeleton has no 'expanded' flags baked in (they depend on
		// the current request's post/term, so can't be shared across
		// requests) — apply_expansion() fills them in on the cached copy,
		// which get_transient() already handed back as a fresh unserialized
		// array, safe to mutate without corrupting the cache.
		$tree = $this->apply_expansion( $this->cached_skeleton(), $ancestor_term_ids );

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
				// Normalized to null whenever $current_post_id wins, matching the
				// expansion priority above — otherwise a saai_kb_sidebar_items
				// consumer would see both set at once and have no way to tell
				// which one actually drove the expanded terms.
				'current_term_id' => null !== $current_post_id ? null : $current_term_id,
				'taxonomy'        => 'saai_category',
			)
		);

		// @phpstan-ignore ternary.elseUnreachable (PHPStan trusts the docblock @param type above, but a third-party saai_kb_sidebar_items callback can violate it at runtime.)
		return is_array( $filtered_tree ) ? $filtered_tree : $tree;
	}

	/**
	 * Returns the cached tree skeleton (no 'expanded' flags), building and
	 * caching it on a miss.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function cached_skeleton(): array {
		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$skeleton = $this->build_skeleton();

		set_transient( self::CACHE_KEY, $skeleton, self::CACHE_TTL );

		return $skeleton;
	}

	/**
	 * Deletes the cached skeleton. Hooked to everything that can change the
	 * tree's shape: saai_kb save/trash/delete and saai_category
	 * create/edit/delete. A stale cache otherwise only self-heals after
	 * CACHE_TTL.
	 */
	public function flush_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Flushes the cache when a saai_knowledge_settings save changes slug_kb —
	 * every post node's 'url' embeds get_permalink(), which changes as soon
	 * as Post_Types re-registers saai_kb with its new slug on the next init
	 * (same reasoning as Llms_Index::maybe_flush_cache_on_slug_change()).
	 *
	 * @param mixed $old_value Previous `saai_knowledge_settings` value.
	 * @param mixed $new_value New `saai_knowledge_settings` value.
	 */
	public function maybe_flush_cache_on_slug_change( $old_value, $new_value ): void {
		$old_value = is_array( $old_value ) ? $old_value : array();
		$new_value = is_array( $new_value ) ? $new_value : array();

		if ( ( $old_value['slug_kb'] ?? null ) !== ( $new_value['slug_kb'] ?? null ) ) {
			$this->flush_cache();
		}
	}

	/**
	 * Builds the tree skeleton: every saai_category term with its child terms
	 * and saai_kb articles nested underneath, in display order, but without
	 * the 'expanded' flag (that's request-specific, applied by
	 * apply_expansion() after this is cached).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function build_skeleton(): array {
		$terms_by_parent = $this->terms_by_parent();
		$posts_by_term   = $this->kb_posts_by_term();

		$tree = array();

		foreach ( $this->sort_terms( $terms_by_parent[0] ?? array() ) as $term ) {
			$tree[] = $this->build_term_node( $term, $terms_by_parent, $posts_by_term );
		}

		return $tree;
	}

	/**
	 * Recursively sets each term node's 'expanded' flag from the given
	 * ancestor term IDs. Post nodes are left untouched.
	 *
	 * @param array<int, array<string, mixed>> $nodes             Node list.
	 * @param int[]                            $ancestor_term_ids Term IDs to auto-expand.
	 * @return array<int, array<string, mixed>>
	 */
	private function apply_expansion( array $nodes, array $ancestor_term_ids ): array {
		foreach ( $nodes as &$node ) {
			if ( 'term' !== ( $node['type'] ?? null ) ) {
				continue;
			}

			$node['expanded'] = in_array( $node['id'], $ancestor_term_ids, true );

			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				$node['children'] = $this->apply_expansion( $node['children'], $ancestor_term_ids );
			}
		}
		unset( $node ); // Break the reference: left dangling, a later reassignment of a loop-like variable in this scope could otherwise silently overwrite the last element (Copilot review).

		return $nodes;
	}

	/**
	 * Builds a single term node, including its child terms and articles.
	 *
	 * @param \WP_Term               $term            The term to render.
	 * @param array<int, \WP_Term[]> $terms_by_parent All saai_category terms, keyed by parent term ID (0 for top level).
	 * @param array<int, \WP_Post[]> $posts_by_term   All saai_kb articles, keyed by their assigned term ID.
	 * @return array<string, mixed>
	 */
	private function build_term_node( \WP_Term $term, array $terms_by_parent, array $posts_by_term ): array {
		$children = array();

		foreach ( $this->sort_terms( $terms_by_parent[ $term->term_id ] ?? array() ) as $child_term ) {
			$children[] = $this->build_term_node( $child_term, $terms_by_parent, $posts_by_term );
		}

		foreach ( $posts_by_term[ $term->term_id ] ?? array() as $post ) {
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
		);
	}

	/**
	 * All saai_category terms in a single query, keyed by parent term ID.
	 *
	 * @return array<int, \WP_Term[]>
	 */
	private function terms_by_parent(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'saai_category',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$by_parent = array();

		foreach ( $terms as $term ) {
			$by_parent[ $term->parent ][] = $term;
		}

		return $by_parent;
	}

	/**
	 * All published saai_kb articles, keyed by their assigned saai_category term ID,
	 * in menu_order.
	 *
	 * The query below leaves `update_post_term_cache` at its default (true), which
	 * primes the term relationship cache for every fetched post in one query; the
	 * per-post wp_get_post_terms() calls below then read from that cache instead of
	 * issuing a query each, keeping this at two queries total regardless of tree size.
	 *
	 * @return array<int, \WP_Post[]>
	 */
	private function kb_posts_by_term(): array {
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
			)
		);

		$posts_by_term = array();

		foreach ( $query->posts as $post ) {
			$term_ids = wp_get_post_terms( $post->ID, 'saai_category', array( 'fields' => 'ids' ) );

			if ( is_wp_error( $term_ids ) ) {
				continue;
			}

			foreach ( $term_ids as $term_id ) {
				$posts_by_term[ $term_id ][] = $post;
			}
		}

		return $posts_by_term;
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

		return $this->ancestor_term_ids_for_terms( wp_list_pluck( $terms, 'term_id' ) );
	}

	/**
	 * The saai_category ancestor term IDs for a set of terms, including the terms themselves.
	 *
	 * @param int[] $term_ids Term IDs.
	 * @return int[]
	 */
	private function ancestor_term_ids_for_terms( array $term_ids ): array {
		$ids = array();

		foreach ( $term_ids as $term_id ) {
			$ids[] = $term_id;

			foreach ( get_ancestors( $term_id, 'saai_category', 'taxonomy' ) as $ancestor_id ) {
				$ids[] = $ancestor_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
