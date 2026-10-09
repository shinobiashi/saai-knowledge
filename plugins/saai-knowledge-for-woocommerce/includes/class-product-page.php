<?php
/**
 * Product page output: the FAQ tab and the related-documentation section.
 *
 * @package SAAI\KnowledgeWoo
 */

namespace SAAI\KnowledgeWoo;

defined( 'ABSPATH' ) || exit;

/**
 * Inserts the content linked to a product into its single product page
 * (docs/DESIGN.md section 6.2).
 *
 * Both insertions ride WooCommerce's own template hooks, which is what makes
 * one implementation cover every theme setup:
 *
 * - `woocommerce_product_tabs` renders as a classic tab (classic themes, and
 *   block themes whose Single Product template was never customized — the
 *   bundled template's Product Details block has no inner blocks and falls
 *   back to the legacy tabs), or as an accordion item once a merchant has
 *   saved that template in the Site Editor (the Product Details block's
 *   compatibility layer converts third-party tabs). WooCommerce's
 *   `woocommerce_product_details_hooked_blocks` is deliberately not used
 *   alongside it: it only applies to the saved-template case, needs a
 *   dynamic block to carry per-product content, and registering the FAQ
 *   there too would show it twice on saved templates.
 * - `woocommerce_after_single_product_summary` fires from the classic
 *   `content-single-product.php` and, on block themes, from the
 *   compatibility layer right after the Product Details block.
 *
 * The FAQ tab's body is the free plugin's own `saai-knowledge/faq-list`
 * block, rendered through render_block() (the route the free plugin's
 * `[saai_faq]` shortcode takes) with the public `saai_faq_query_args` filter
 * narrowing it to the linked FAQs — so the accordion markup, its
 * Interactivity API wiring, and the FAQPage JSON-LD are not reimplemented
 * here (docs/DESIGN-HOOKS-API.md section 7).
 */
final class Product_Page {

	/**
	 * The free plugin's FAQ list block, a public identifier per
	 * docs/DESIGN-HOOKS-API.md section 6.
	 *
	 * @var string
	 */
	public const FAQ_BLOCK = 'saai-knowledge/faq-list';

	/**
	 * The product tab key; becomes WooCommerce's `#tab-saai_faq` panel ID.
	 *
	 * @var string
	 */
	public const FAQ_TAB_KEY = 'saai_faq';

	/**
	 * Tab priority: after WooCommerce's Description (10) and Additional
	 * information (20), before Reviews (30).
	 *
	 * @var int
	 */
	public const FAQ_TAB_PRIORITY = 25;

	/**
	 * `woocommerce_after_single_product_summary` priority: after the classic
	 * tabs (10), before upsells (15) and related products (20).
	 *
	 * @var int
	 */
	public const KB_LINKS_PRIORITY = 12;

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
	 * Stores the services this one renders from.
	 *
	 * @param Link_Resolver $links    The product <-> content link resolver.
	 * @param Settings      $settings The add-on's settings.
	 */
	public function __construct( Link_Resolver $links, Settings $settings ) {
		$this->links    = $links;
		$this->settings = $settings;
	}

	/**
	 * Hooks both insertions into WooCommerce's single product template.
	 *
	 * Priority 20 on the tabs filter: WooCommerce adds its own tabs via
	 * `woocommerce_default_product_tabs` at the default 10, and this runs
	 * after them regardless of load order.
	 */
	public function register(): void {
		add_filter( 'woocommerce_product_tabs', array( $this, 'add_faq_tab' ), 20 );
		add_action( 'woocommerce_after_single_product_summary', array( $this, 'render_kb_links' ), self::KB_LINKS_PRIORITY );
	}

	/**
	 * Adds the FAQ tab when there is something to put in it.
	 *
	 * The `woocommerce_product_tabs` callback. No tab is added — rather
	 * than an empty one — when the setting is off, when the free plugin's
	 * block isn't registered (its JS build is missing), or when no published
	 * FAQ resolves to this product: the acceptance criterion is that a
	 * product with no links shows nothing at all.
	 *
	 * @param mixed $tabs Tab definitions keyed by tab key.
	 * @return mixed
	 */
	public function add_faq_tab( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			return $tabs;
		}

		$product_id = Product_Context::current_product_id();

		if ( $product_id <= 0 || ! $this->faq_tab_enabled() || array() === $this->faq_ids( $product_id ) ) {
			return $tabs;
		}

		$tabs[ self::FAQ_TAB_KEY ] = array(
			'title'    => __( 'FAQ', 'saai-knowledge-for-woocommerce' ),
			'priority' => self::FAQ_TAB_PRIORITY,
			'callback' => array( $this, 'render_faq_tab' ),
		);

		return $tabs;
	}

	/**
	 * Echoes the FAQ tab's body.
	 *
	 * The tab callback WooCommerce invokes as `callback( $key, $tab )`, both
	 * from the classic tabs template and from the Product Details block's
	 * compatibility layer (which captures the output into an accordion
	 * item). The arguments aren't needed: the product comes from the request.
	 */
	public function render_faq_tab(): void {
		$product_id = Product_Context::current_product_id();

		if ( $product_id <= 0 ) {
			return;
		}

		echo $this->faq_list_html( $product_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_block() output of the free plugin's faq-list block, escaped by its own render.php.
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
	 * @param int $product_id Product post ID.
	 * @return string Rendered HTML, or '' when nothing applies.
	 */
	public function faq_list_html( int $product_id ): string {
		if ( $product_id <= 0 || ! $this->faq_tab_enabled() ) {
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

		$restrict = static function ( $args ) use ( $faq_ids ) {
			if ( ! is_array( $args ) ) {
				// An earlier callback handed down something unusable. The
				// free plugin's Faq_List::query_args() would then fall back
				// to its own UNRESTRICTED defaults, which here would list
				// every FAQ on the store in this product's tab — so rebuild
				// a complete query (the block's own constraints) instead of
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
		// restores WordPress's own postdata globals afterwards but cannot
		// know about WooCommerce's. Without this snapshot the Reviews tab,
		// rendered right after this one from the same tabs template, fatals
		// on `$product->get_review_count()` (seen in wp-env).
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
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				)
			);
		} finally {
			remove_filter( 'saai_faq_query_args', $restrict, 20 );

			if ( $had_product ) {
				$GLOBALS['product'] = $previous_product; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- restoring WooCommerce's own global to the exact value saved above.
			} else {
				unset( $GLOBALS['product'] );
			}
		}
	}

	/**
	 * Echoes the related-documentation section.
	 *
	 * The `woocommerce_after_single_product_summary` callback.
	 */
	public function render_kb_links(): void {
		echo $this->kb_links_html( Product_Context::current_product_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url()/esc_html()'d fragments in kb_links_html().
	}

	/**
	 * Renders the list of KB articles linked to a product.
	 *
	 * Plain semantic markup with this add-on's own `saai-woo-` class prefix
	 * and no stylesheet of its own: a heading and a list pick up the
	 * theme's typography, which is what a "related documentation" box
	 * under a product should look like on that store anyway.
	 *
	 * @param int $product_id Product post ID.
	 * @return string Rendered HTML, or '' when nothing applies.
	 */
	public function kb_links_html( int $product_id ): string {
		if ( $product_id <= 0 || ! $this->settings->is_enabled( Settings::KB_LINKS ) ) {
			return '';
		}

		$kb_ids = $this->links->content_ids_for_product( $product_id, array( 'post_type' => 'saai_kb' ) );

		if ( array() === $kb_ids ) {
			return '';
		}

		// The resolver returns IDs only (`fields => 'ids'` primes nothing), so
		// without this every get_permalink()/get_the_title() below would be
		// its own get_post() query — one per linked article. Terms are primed
		// too because the KB permalink structure embeds the article's
		// category.
		_prime_post_caches( $kb_ids, true, false );

		$items = '';

		foreach ( $kb_ids as $kb_id ) {
			$url = get_permalink( $kb_id );

			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$title = get_the_title( $kb_id );

			if ( '' === $title ) {
				$title = __( '(no title)', 'saai-knowledge-for-woocommerce' );
			}

			$items .= sprintf(
				'<li class="saai-woo-related-kb__item"><a href="%1$s">%2$s</a></li>',
				esc_url( $url ),
				esc_html( $title )
			);
		}

		if ( '' === $items ) {
			return '';
		}

		// A unique heading id: a theme firing the summary hook twice, or the
		// manual-placement block landing on the same page later, must not
		// produce duplicate ids for aria-labelledby to point at.
		$title_id = wp_unique_id( 'saai-woo-related-kb-title-' );

		return sprintf(
			'<section class="saai-woo-related-kb" aria-labelledby="%1$s"><h2 id="%1$s" class="saai-woo-related-kb__title">%2$s</h2><ul class="saai-woo-related-kb__list">%3$s</ul></section>',
			esc_attr( $title_id ),
			esc_html__( 'Related documentation', 'saai-knowledge-for-woocommerce' ),
			$items
		);
	}

	/**
	 * Whether the FAQ tab is switched on and the block that renders it exists.
	 *
	 * Deliberately says nothing about a particular product: the per-product
	 * link lookup is the expensive part, and each caller does it once.
	 */
	private function faq_tab_enabled(): bool {
		return $this->settings->is_enabled( Settings::FAQ_TAB )
			&& \WP_Block_Type_Registry::get_instance()->is_registered( self::FAQ_BLOCK );
	}

	/**
	 * The published FAQs linked to a product, in the resolver's order.
	 *
	 * @param int $product_id Product post ID.
	 * @return int[]
	 */
	private function faq_ids( int $product_id ): array {
		return $this->links->content_ids_for_product( $product_id, array( 'post_type' => 'saai_faq' ) );
	}
}
