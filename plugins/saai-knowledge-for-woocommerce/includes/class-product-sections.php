<?php
/**
 * The content linked to a product, ready for display.
 *
 * @package SAAI\KnowledgeWoo
 */

namespace SAAI\KnowledgeWoo;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and renders what a product's FAQ, documentation, and glossary
 * sections show, with no opinion on where they go.
 *
 * Shared by the automatic insertions (Product_Page, which adds the settings
 * toggles on top) and the manual-placement blocks and shortcodes
 * (docs/DESIGN.md section 6.2), so both always list the same content for a
 * product: published, non-password-protected posts linked to it directly
 * or through one of its categories, in Link_Resolver's order.
 */
final class Product_Sections {

	/**
	 * The free plugin's FAQ list block, a public identifier per
	 * docs/DESIGN-HOOKS-API.md section 6.
	 *
	 * @var string
	 */
	public const FAQ_BLOCK = 'saai-knowledge/faq-list';

	/**
	 * Prefix of the `category` marker the product-faq block renders the
	 * faq-list block with; the product ID completes it.
	 *
	 * Same purpose as Product_Page::FAQ_TAB_CATEGORY_MARKER: the free
	 * plugin's FAQPage JSON-LD slot is claimed per "normalized attributes +
	 * context post", so the marker is what tells two renders apart. Per
	 * product, because a page listing two products' FAQs would otherwise
	 * give both lists one signature and print two FAQPage objects with
	 * different questions; with it, the first list on the page claims the
	 * slot and the other stays quiet, as does the FAQ tab when the block
	 * sits on a product page that also has one.
	 *
	 * @var string
	 */
	public const FAQ_BLOCK_MARKER_PREFIX = 'saai-woo-product-faq-';

	/**
	 * How many words of a glossary term's body stand in for its definition
	 * when it has no manual excerpt — the free plugin's tooltip rule.
	 *
	 * @var int
	 */
	public const DEFINITION_WORDS = 55;

	/**
	 * The product <-> content link resolver.
	 *
	 * @var Link_Resolver
	 */
	private $links;

	/**
	 * Stores the resolver the sections are built from.
	 *
	 * @param Link_Resolver $links The product <-> content link resolver.
	 */
	public function __construct( Link_Resolver $links ) {
		$this->links = $links;
	}

	/**
	 * The faq-list `category` marker for the product-faq block of a product.
	 *
	 * @param int $product_id Product post ID.
	 * @return string
	 */
	public static function faq_block_marker( int $product_id ): string {
		return self::FAQ_BLOCK_MARKER_PREFIX . $product_id;
	}

	/**
	 * Whether the free plugin's faq-list block is registered (its JS build
	 * exists), which every FAQ rendering here goes through.
	 */
	public function faq_block_available(): bool {
		return \WP_Block_Type_Registry::get_instance()->is_registered( self::FAQ_BLOCK );
	}

	/**
	 * The published FAQs linked to a product, in the resolver's order.
	 *
	 * @param int $product_id Product post ID.
	 * @return int[]
	 */
	public function faq_ids( int $product_id ): array {
		return $this->links->content_ids_for_product( $product_id, array( 'post_type' => 'saai_faq' ) );
	}

	/**
	 * Renders the free plugin's FAQ list block for the FAQs linked to a
	 * product.
	 *
	 * The `saai_faq_query_args` callback is attached only around this one
	 * render_block() call, so any other faq-list block or [saai_faq]
	 * shortcode on the page (a merchant's own, in the description, say)
	 * keeps its own query. `post__in` carries the resolver's order
	 * (menu_order, then title) through `orderby => post__in`, and the
	 * block's own `publish` / `has_password => false` constraints stay in
	 * place, so a linked FAQ that was since unpublished or protected drops
	 * out here too.
	 *
	 * @param int    $product_id Product post ID.
	 * @param string $marker     The faq-list `category` attribute to render with: a
	 *                           marker, not a real category (the restriction drops
	 *                           the `tax_query` it would produce), that keeps this
	 *                           render's FAQPage JSON-LD signature apart from the
	 *                           page's other FAQ lists. See FAQ_BLOCK_MARKER_PREFIX.
	 * @return string Rendered HTML, or '' when nothing applies.
	 */
	public function faq_html( int $product_id, string $marker ): string {
		if ( $product_id <= 0 || ! $this->faq_block_available() ) {
			return '';
		}

		// Resolved exactly once here (not re-checked through a separate
		// availability call): the IDs feed `post__in` below, and WP_Query
		// treats an EMPTY post__in as "no constraint" — so the empty case has
		// to return before the closure can ever see it.
		$faq_ids = $this->faq_ids( $product_id );

		if ( array() === $faq_ids ) {
			return '';
		}

		$restrict = static function ( $args ) use ( $faq_ids, &$restrict ) {
			// Exactly once. The first saai_faq_query_args pass inside the
			// render_block() below is this list's own; a faq-list nested in
			// an answer never gets this far (the free plugin's reentrancy
			// guard renders it empty before querying), but detaching here
			// keeps the restriction from reaching any other consumer that
			// might run before the render returns.
			remove_filter( 'saai_faq_query_args', $restrict, 20 );

			if ( ! is_array( $args ) ) {
				// An earlier callback handed down something unusable. The
				// free plugin's Faq_List::query_args() would then fall back
				// to its own UNRESTRICTED defaults, which here would list
				// every FAQ on the store for this product — so rebuild a
				// complete query (the block's own constraints) instead of
				// passing the junk along.
				$args = array(
					'post_type'           => 'saai_faq',
					'post_status'         => 'publish',
					'has_password'        => false,
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
				);
			}

			$args['post__in']       = $faq_ids;
			$args['orderby']        = 'post__in';
			$args['posts_per_page'] = -1;
			unset( $args['order'], $args['tax_query'] );

			return $args;
		};

		// WooCommerce keeps the displayed product in $GLOBALS['product'] and
		// maintains it from the `the_post` action: wc_setup_product_data()
		// unsets it outright for any non-product post. Rendering an FAQ
		// answer makes that FAQ the current post for the duration (the free
		// plugin's Faq_List calls setup_postdata() on it), which fires
		// `the_post` and so wipes the product global. The free plugin
		// restores WordPress's own postdata globals afterwards and, from
		// 1.0.2, re-fires `the_post` for the previous post — but this add-on
		// still supports earlier versions. Without this snapshot whatever
		// renders next on a product page and reads the global (the Reviews
		// tab, right after the FAQ tab) fatals on
		// `$product->get_review_count()` (seen in wp-env).
		$had_product      = array_key_exists( 'product', $GLOBALS );
		$previous_product = $GLOBALS['product'] ?? null;

		// Priority 20: later than a default-priority third-party callback,
		// so the restriction to the linked FAQs is what the query ends up
		// with.
		add_filter( 'saai_faq_query_args', $restrict, 20 );

		try {
			return render_block(
				array(
					'blockName'    => self::FAQ_BLOCK,
					'attrs'        => array( 'category' => $marker ),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				)
			);
		} finally {
			// Normally already detached by the closure itself; this covers a
			// render that never reached the query (block output short-cut).
			remove_filter( 'saai_faq_query_args', $restrict, 20 );

			if ( $had_product ) {
				$GLOBALS['product'] = $previous_product; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- restoring WooCommerce's own global to the exact value saved above.
			} else {
				unset( $GLOBALS['product'] );
			}
		}
	}

	/**
	 * The KB articles linked to a product, as display-ready links.
	 *
	 * @param int $product_id Product post ID.
	 * @return array<int, array{url: string, title: string}> Unescaped, in the
	 *         resolver's order; escape on output.
	 */
	public function kb_links( int $product_id ): array {
		$links = array();

		foreach ( $this->linked_posts( $product_id, 'saai_kb' ) as $kb ) {
			$url = $this->permalink( $kb );

			if ( '' === $url ) {
				continue;
			}

			$links[] = array(
				'url'   => $url,
				'title' => $this->title( $kb ),
			);
		}

		return $links;
	}

	/**
	 * The glossary terms linked to a product, with their definitions.
	 *
	 * The definition follows the free plugin's tooltip rule — the term's
	 * manual excerpt, or else the first DEFINITION_WORDS words of its body —
	 * as plain text, so the list says exactly what hovering the term in a
	 * description already shows. Like the tooltip it reads the body without
	 * running `the_content`: shortcodes are stripped rather than rendered
	 * and tags removed, which keeps a definition from executing a nested
	 * block or shortcode (another product list, say) inside a product page.
	 * What remains can still contain square brackets (a manual excerpt, or
	 * `[[x]]`, which strip_shortcodes() unescapes to `[x]`), so the blocks
	 * print it through Blocks::text().
	 *
	 * @param int $product_id Product post ID.
	 * @return array<int, array{url: string, title: string, definition: string}>
	 *         Unescaped, in the resolver's order; escape on output.
	 */
	public function glossary_entries( int $product_id ): array {
		$entries = array();

		foreach ( $this->linked_posts( $product_id, 'saai_glossary' ) as $term ) {
			$url = $this->permalink( $term );

			if ( '' === $url ) {
				continue;
			}

			$entries[] = array(
				'url'        => $url,
				'title'      => $this->title( $term ),
				'definition' => $this->definition( $term ),
			);
		}

		return $entries;
	}

	/**
	 * The published posts of one content type linked to a product.
	 *
	 * @param int    $product_id Product post ID.
	 * @param string $post_type  saai_kb or saai_glossary.
	 * @return \WP_Post[] In the resolver's order.
	 */
	private function linked_posts( int $product_id, string $post_type ): array {
		if ( $product_id <= 0 ) {
			return array();
		}

		$ids = $this->links->content_ids_for_product( $product_id, array( 'post_type' => $post_type ) );

		if ( array() === $ids ) {
			return array();
		}

		// The resolver returns IDs only (`fields => 'ids'` primes nothing), so
		// without this every get_post()/get_permalink()/get_the_title() below
		// would be its own query — one per linked post. Posts only: the
		// permalinks are `/{base}/{slug}/` with no taxonomy in them, and
		// nothing here reads meta, so priming either would just be an extra
		// query.
		_prime_post_caches( $ids, false, false );

		$posts = array();

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( $post instanceof \WP_Post ) {
				$posts[] = $post;
			}
		}

		return $posts;
	}

	/**
	 * A post's permalink, or '' when it is unusable as a link.
	 *
	 * Judged AFTER sanitizing: esc_url_raw() reduces a disallowed protocol
	 * (a `post_type_link` filter returning javascript: or data:, say) to '',
	 * and that must drop the item rather than print an <a href=""> around
	 * the title.
	 *
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	private function permalink( \WP_Post $post ): string {
		$url = get_permalink( $post->ID );

		return is_string( $url ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * A post's display title, with a placeholder for an untitled one.
	 *
	 * @param \WP_Post $post The post.
	 * @return string May contain the HTML character references the_title adds;
	 *                esc_html() leaves those intact.
	 */
	private function title( \WP_Post $post ): string {
		$title = get_the_title( $post );

		return '' === $title ? __( '(no title)', 'saai-knowledge-for-woocommerce' ) : $title;
	}

	/**
	 * A glossary term's definition as plain text.
	 *
	 * @param \WP_Post $term The glossary term post.
	 * @return string
	 */
	private function definition( \WP_Post $term ): string {
		$text = has_excerpt( $term )
			? get_the_excerpt( $term )
			: wp_trim_words( strip_shortcodes( $term->post_content ), self::DEFINITION_WORDS );

		return trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ) );
	}
}
