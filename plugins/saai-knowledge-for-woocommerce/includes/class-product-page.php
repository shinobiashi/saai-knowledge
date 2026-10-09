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
	public const FAQ_BLOCK = Product_Sections::FAQ_BLOCK;

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
	 * The `category` attribute the tab renders the faq-list block with.
	 *
	 * Not a real category — the query restriction drops the `tax_query` it
	 * would produce. It exists for the free plugin's FAQPage JSON-LD slot,
	 * which is claimed per "normalized attributes + context post" and
	 * re-admits a matching signature (so a speculative pre-render can't use
	 * it up). A default-attribute faq-list block or [saai_faq] shortcode in
	 * the product's own description would therefore share the tab's
	 * signature, and both would print a FAQPage with different question
	 * sets. With this marker the signatures differ and the slot works as
	 * designed: whichever renders first (the description precedes the tabs)
	 * prints the page's one FAQPage (Codex review, PR #67). `category` is
	 * the one public attribute whose value leaves the rendered list alone
	 * once its tax_query is removed.
	 *
	 * @var string
	 */
	public const FAQ_TAB_CATEGORY_MARKER = 'saai-woo-product-tab';

	/**
	 * What the sections show, shared with the manual-placement blocks.
	 *
	 * @var Product_Sections
	 */
	private $sections;

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
		$this->sections = new Product_Sections( $links );
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

		if ( $product_id <= 0 || ! $this->faq_tab_enabled() || array() === $this->sections->faq_ids( $product_id ) ) {
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
	 * Renders the FAQ tab's list: the free plugin's FAQ list block for the
	 * FAQs linked to a product.
	 *
	 * The rendering itself (the query restriction, the WooCommerce product
	 * global's snapshot) lives in Product_Sections, shared with the
	 * product-faq block; this adds the tab's settings toggle and its own
	 * FAQPage JSON-LD marker.
	 *
	 * @param int $product_id Product post ID.
	 * @return string Rendered HTML, or '' when nothing applies.
	 */
	public function faq_list_html( int $product_id ): string {
		if ( $product_id <= 0 || ! $this->faq_tab_enabled() ) {
			return '';
		}

		return $this->sections->faq_html( $product_id, self::FAQ_TAB_CATEGORY_MARKER );
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

		$items = '';

		foreach ( $this->sections->kb_links( $product_id ) as $link ) {
			$items .= sprintf(
				'<li class="saai-woo-related-kb__item"><a href="%1$s">%2$s</a></li>',
				esc_url( $link['url'] ),
				esc_html( $link['title'] )
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
		return $this->settings->is_enabled( Settings::FAQ_TAB ) && $this->sections->faq_block_available();
	}
}
