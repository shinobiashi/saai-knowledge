<?php
/**
 * Glossary tooltips in product descriptions.
 *
 * @package SAAI\KnowledgeWoo
 */

namespace SAAI\KnowledgeWoo;

defined( 'ABSPATH' ) || exit;

/**
 * Connects the free plugin's auto-link engine to WooCommerce product
 * descriptions, restricted to the glossary terms linked to the product
 * (docs/DESIGN-AUTOLINK.md section 6, docs/DESIGN-HOOKS-API.md section 7).
 *
 * Every product description — long and short, on either theme kind — is
 * handed to `$base->autolinker()->process()` by this class itself, never by
 * the free plugin's own `the_content` pass. The reason is the engine's
 * render cache: its key folds in the post, the dictionary generation, and
 * the `$context` array, but not what the `saai_autolink_dictionary` filter
 * returned — and here that filter narrows the dictionary to the product's
 * linked terms, a set that changes without touching the product or any
 * glossary post (the product-side meta box writes the link rows with
 * add_post_meta()/delete_post_meta() alone). Calling process() ourselves
 * lets us put a fingerprint of that set into `$context`, so a changed link
 * set is a cache miss instead of up to an hour of stale HTML on sites with
 * a persistent object cache (Codex review, PR #67).
 *
 * The routes:
 *
 * - `the_content` (priority 50, like the engine's own pass) for the long
 *   description, wherever WordPress renders a product's content (the
 *   Description tab on both theme kinds, a `core/post-content` block in a
 *   Product Collection, search results), each time narrowed to that
 *   product's own linked terms. Skipped in admin, feeds, and REST, as the
 *   engine's pass is. `product` is kept OUT of `saai_autolink_post_types`
 *   while the toggle is on, so the engine's pass never processes product
 *   content with a fingerprint-less key behind this class's back.
 * - `woocommerce_short_description` (classic templates) and the
 *   `render_block_{name}` filters of `core/post-excerpt` /
 *   `woocommerce/product-summary` (block themes) for the short description.
 *   These check Product_Context::current_product_id() and so only act on a
 *   single product page, for the displayed product — not in REST (the
 *   editor's previews), not in admin, and not for the other products a
 *   Product Collection block loops through further down the same page.
 * - `saai_autolink_dictionary` narrows the dictionary to the linked terms
 *   whenever the context is a product. A product with no linked terms
 *   never reaches the engine at all, so the free plugin's Tooltip service
 *   never enqueues its assets for that page.
 */
final class Product_Autolink {

	/**
	 * The `$context` key carrying the fingerprint of the product's linked
	 * glossary term IDs (sorted ints) into the engine, and back out to
	 * filter_dictionary(); see the class docblock.
	 *
	 * @var string
	 */
	public const CONTEXT_TERMS = 'saai_woo_terms';

	/**
	 * The free plugin instance this add-on extends.
	 *
	 * @var mixed SAAI\Knowledge\Plugin, kept untyped per docs/DESIGN-HOOKS-API.md section 2
	 *            (consumed only through its documented public autolinker() method).
	 */
	private $base;

	/**
	 * The product <-> content link resolver.
	 *
	 * @var Link_Resolver
	 */
	private $links;

	/**
	 * The add-on's settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Stores the services this one works from.
	 *
	 * @param mixed         $base     The booted free-plugin instance (SAAI\Knowledge\Plugin).
	 * @param Link_Resolver $links    The product <-> content link resolver.
	 * @param Settings      $settings The add-on's settings.
	 */
	public function __construct( $base, Link_Resolver $links, Settings $settings ) {
		$this->base     = $base;
		$this->links    = $links;
		$this->settings = $settings;
	}

	/**
	 * Hooks into the free plugin's engine and WooCommerce's description output.
	 *
	 * `saai_autolink_post_types` at PHP_INT_MAX: the free plugin's own
	 * Settings service replaces the list with its saved value at the
	 * default priority 10, and anything else adding `product` has to be
	 * seen too — this class wants the last word on it (see
	 * filter_post_types()).
	 */
	public function register(): void {
		add_filter( 'the_content', array( $this, 'filter_long_description' ), 50 );
		add_filter( 'saai_autolink_post_types', array( $this, 'filter_post_types' ), PHP_INT_MAX );
		add_filter( 'saai_autolink_dictionary', array( $this, 'filter_dictionary' ), 10, 2 );
		add_filter( 'woocommerce_short_description', array( $this, 'filter_short_description' ), 20 );
		add_filter( 'render_block_core/post-excerpt', array( $this, 'filter_summary_block' ), 10, 3 );
		add_filter( 'render_block_woocommerce/product-summary', array( $this, 'filter_summary_block' ), 10, 3 );
	}

	/**
	 * Auto-links a product's long description.
	 *
	 * The `the_content` callback, at the same priority 50 as the engine's
	 * own pass and with the same request guards (admin, feeds, REST) and
	 * post-type check — only this one calls process() through this class,
	 * so the linked-term fingerprint reaches the engine's cache key.
	 *
	 * @param mixed $content Rendered post content.
	 * @return mixed
	 */
	public function filter_long_description( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $content;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post || Link_Resolver::PRODUCT_POST_TYPE !== $post->post_type ) {
			return $content;
		}

		return $this->process( $content, (int) $post->ID );
	}

	/**
	 * Keeps `product` out of the engine's own `the_content` pass while
	 * tooltips are on.
	 *
	 * The `saai_autolink_post_types` callback. This class processes product
	 * content itself (see the class docblock); if some other code added
	 * `product` here, the engine's pass would also run on it — doing the
	 * work twice, and caching the result under a key without the
	 * linked-term fingerprint. With the toggle off the list is left exactly
	 * as it arrived: product content is then nobody's business here, and
	 * whoever added `product` gets the engine's normal behaviour.
	 *
	 * @param mixed $post_types Post type slugs.
	 * @return mixed
	 */
	public function filter_post_types( $post_types ) {
		if ( ! is_array( $post_types ) || ! $this->settings->is_enabled( Settings::TOOLTIPS ) ) {
			return $post_types;
		}

		return array_values(
			array_filter(
				$post_types,
				static function ( $post_type ): bool {
					return Link_Resolver::PRODUCT_POST_TYPE !== $post_type;
				}
			)
		);
	}

	/**
	 * Narrows the dictionary to the glossary terms linked to the product
	 * being rendered.
	 *
	 * The `saai_autolink_dictionary` callback. Only a product context is
	 * touched; every other post type keeps the full dictionary. With the
	 * toggle off the dictionary is returned as-is as well — this add-on
	 * then isn't the one rendering product content, and whoever is should
	 * get the engine's normal behaviour.
	 *
	 * The linked term IDs come from the context when process() put them
	 * there (CONTEXT_TERMS — resolved moments earlier for the cache key, no
	 * second query), and are resolved here otherwise, for a third party
	 * calling the engine with a product context of its own.
	 *
	 * @param mixed $entries Dictionary entries (docs/DESIGN-AUTOLINK.md section 2.1).
	 * @param mixed $context [ 'post_id' => int, 'post_type' => string, CONTEXT_TERMS => int[] (optional) ].
	 * @return mixed
	 */
	public function filter_dictionary( $entries, $context ) {
		if ( ! is_array( $entries ) || ! is_array( $context ) ) {
			return $entries;
		}

		if ( Link_Resolver::PRODUCT_POST_TYPE !== ( $context['post_type'] ?? null ) ) {
			return $entries;
		}

		if ( ! $this->settings->is_enabled( Settings::TOOLTIPS ) ) {
			return $entries;
		}

		if ( isset( $context[ self::CONTEXT_TERMS ] ) && is_array( $context[ self::CONTEXT_TERMS ] ) ) {
			$term_ids = $this->normalize_ids( $context[ self::CONTEXT_TERMS ] );
		} else {
			$product_id = isset( $context['post_id'] ) && is_numeric( $context['post_id'] ) ? (int) $context['post_id'] : 0;
			$term_ids   = $product_id > 0 ? $this->linked_term_ids( $product_id ) : array();
		}

		if ( array() === $term_ids ) {
			return array();
		}

		return array_values(
			array_filter(
				$entries,
				static function ( $entry ) use ( $term_ids ): bool {
					return is_array( $entry )
						&& isset( $entry['post_id'] )
						&& is_numeric( $entry['post_id'] )
						&& in_array( (int) $entry['post_id'], $term_ids, true );
				}
			)
		);
	}

	/**
	 * Auto-links the short description on classic-theme product pages.
	 *
	 * The `woocommerce_short_description` callback, at priority 20 so it
	 * sees the HTML after WooCommerce's own do_blocks()/wptexturize()
	 * formatting (priorities 9 and 10).
	 *
	 * WooCommerce reaches this filter from two kinds of places. The
	 * short-description template applies it to the displayed product's own
	 * excerpt — the case this is for. Its generic formatter
	 * wc_format_content() applies it as well, to text that is NOT that:
	 * variation descriptions (embedded as JSON for the variations script
	 * and inserted later by jQuery, where the anchors' Interactivity API
	 * directives are never hydrated, so the tooltip wouldn't open while
	 * has_rendered_links() would still make the free plugin load it), the
	 * Featured Product / Featured Category / Category Description blocks
	 * (another object's text, which would be linked against THIS product's
	 * dictionary), cart item data, and so on. Those are skipped — see
	 * is_inside_wc_format_content().
	 *
	 * @param mixed $html The formatted short description.
	 * @return mixed
	 */
	public function filter_short_description( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		$product_id = Product_Context::current_product_id();

		if ( $product_id <= 0 || $this->is_inside_wc_format_content() ) {
			return $html;
		}

		return $this->process( $html, $product_id );
	}

	/**
	 * Auto-links the short description on block-theme product pages.
	 *
	 * The `render_block_core/post-excerpt` and
	 * `render_block_woocommerce/product-summary` callback. Besides the
	 * single-product-page check, the block's own `postId` context has to
	 * be the displayed product: a Product Collection block lower on the
	 * same page renders these very blocks for other products, and those
	 * must keep their text as-is.
	 *
	 * @param mixed          $content  The rendered block HTML.
	 * @param mixed          $block    The parsed block.
	 * @param \WP_Block|null $instance The block instance, when WordPress passes one.
	 * @return mixed
	 */
	public function filter_summary_block( $content, $block = null, $instance = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $block must precede $instance to match the render_block_{name} filter signature.
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		$product_id = Product_Context::current_product_id();

		if ( $product_id <= 0 ) {
			return $content;
		}

		if ( $instance instanceof \WP_Block && isset( $instance->context['postId'] ) && (int) $instance->context['postId'] !== $product_id ) {
			return $content;
		}

		return $this->process( $content, $product_id );
	}

	/**
	 * Whether WooCommerce's wc_format_content() is on the current call stack.
	 *
	 * `doing_filter( 'woocommerce_format_content' )` cannot tell this: that
	 * function evaluates the inner `woocommerce_short_description` filter
	 * before the outer one starts, so the outer name isn't on the filter
	 * stack yet when this runs. Checking the stack by function name is the
	 * same approach the free plugin's Autolinker takes for
	 * wp_trim_excerpt(). Only reached on single product pages (after the
	 * Product_Context check), a handful of times per page.
	 *
	 * @return bool
	 */
	private function is_inside_wc_format_content(): bool {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Not leftover debug code: used at runtime to detect wc_format_content() on the call stack, see this method's own docblock.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			if ( 'wc_format_content' === $frame['function'] && ! isset( $frame['class'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Runs the free plugin's auto-link engine over product HTML, with the
	 * product's linked-term fingerprint in the context.
	 *
	 * A product with no linked terms returns the HTML untouched without
	 * calling the engine: there is nothing to link against, and not
	 * calling it keeps the engine's has_rendered_links() — and with it the
	 * tooltip assets — off for such a page.
	 *
	 * Type-guarded rather than typed: `$base` is the untyped instance the
	 * `saai_loaded` action hands over (docs/DESIGN-HOOKS-API.md section 2),
	 * and PHP 8 would fatal on a method call against something else.
	 *
	 * @param string $html       HTML to auto-link.
	 * @param int    $product_id The product the HTML belongs to.
	 * @return string
	 */
	private function process( string $html, int $product_id ): string {
		if ( ! $this->settings->is_enabled( Settings::TOOLTIPS ) ) {
			return $html;
		}

		if ( ! is_object( $this->base ) || ! method_exists( $this->base, 'autolinker' ) ) {
			return $html;
		}

		$autolinker = $this->base->autolinker();

		if ( ! is_object( $autolinker ) || ! method_exists( $autolinker, 'process' ) ) {
			return $html;
		}

		$term_ids = $this->linked_term_ids( $product_id );

		if ( array() === $term_ids ) {
			return $html;
		}

		$result = $autolinker->process(
			$html,
			array(
				'post_id'           => $product_id,
				'post_type'         => Link_Resolver::PRODUCT_POST_TYPE,
				self::CONTEXT_TERMS => $term_ids,
			)
		);

		return is_string( $result ) ? $result : $html;
	}

	/**
	 * The published glossary terms linked to a product, as a stable
	 * fingerprint: sorted ascending, so the same set always yields the same
	 * context (and so the same engine cache key) whatever order the resolver
	 * happened to return it in.
	 *
	 * @param int $product_id Product post ID.
	 * @return int[]
	 */
	private function linked_term_ids( int $product_id ): array {
		return $this->normalize_ids(
			$this->links->content_ids_for_product( $product_id, array( 'post_type' => 'saai_glossary' ) )
		);
	}

	/**
	 * Positive, unique, ascending integer IDs out of whatever list came in.
	 *
	 * @param array<mixed> $ids Candidate IDs.
	 * @return int[]
	 */
	private function normalize_ids( array $ids ): array {
		$clean = array();

		foreach ( $ids as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$clean[] = (int) $id;
			}
		}

		$clean = array_values( array_unique( $clean ) );
		sort( $clean );

		return $clean;
	}
}
