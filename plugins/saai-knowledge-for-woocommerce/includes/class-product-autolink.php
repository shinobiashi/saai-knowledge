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
 * Three public touch points of the free plugin do all the work:
 *
 * - `saai_autolink_post_types` adds `product`, which makes the engine's own
 *   `the_content` pass cover the long description on both theme kinds
 *   (WooCommerce's Description tab calls the_content()).
 * - `saai_autolink_dictionary` narrows the dictionary to the linked terms
 *   whenever the context is a product. A product with no linked terms gets
 *   an empty dictionary, so the engine returns the HTML untouched and the
 *   free plugin's Tooltip service never enqueues its assets for that page.
 * - `$base->autolinker()->process()` is called directly for the short
 *   description, which the_content never sees. Its route differs by theme:
 *   classic templates apply `woocommerce_short_description`, while the
 *   bundled block template renders it with `core/post-excerpt` (WooCommerce
 *   also ships its own `woocommerce/product-summary` block), so those two
 *   are caught through their `render_block_{name}` filters.
 *
 * Every entry point checks Product_Context::current_product_id() so nothing
 * runs outside a single product page — not in REST (the editor's previews),
 * not in admin, and not for the other products a Product Collection block
 * loops through further down the same page.
 */
final class Product_Autolink {

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
	 * Priority 20 on `saai_autolink_post_types`: the free plugin's own
	 * Settings service replaces the list with its saved "auto-link post
	 * types" value at the default priority 10, so `product` has to be
	 * appended after that or it would be overwritten.
	 */
	public function register(): void {
		add_filter( 'saai_autolink_post_types', array( $this, 'filter_post_types' ), 20 );
		add_filter( 'saai_autolink_dictionary', array( $this, 'filter_dictionary' ), 10, 2 );
		add_filter( 'woocommerce_short_description', array( $this, 'filter_short_description' ), 20 );
		add_filter( 'render_block_core/post-excerpt', array( $this, 'filter_summary_block' ), 10, 3 );
		add_filter( 'render_block_woocommerce/product-summary', array( $this, 'filter_summary_block' ), 10, 3 );
	}

	/**
	 * Adds `product` to the auto-link post types while tooltips are on.
	 *
	 * The `saai_autolink_post_types` callback. Purely additive: when the
	 * toggle is off this leaves the list exactly as it arrived, so a
	 * `product` entry some other code put there is neither duplicated nor
	 * removed.
	 *
	 * @param mixed $post_types Post type slugs.
	 * @return mixed
	 */
	public function filter_post_types( $post_types ) {
		if ( ! is_array( $post_types ) || ! $this->settings->is_enabled( Settings::TOOLTIPS ) ) {
			return $post_types;
		}

		if ( ! in_array( Link_Resolver::PRODUCT_POST_TYPE, $post_types, true ) ) {
			$post_types[] = Link_Resolver::PRODUCT_POST_TYPE;
		}

		return $post_types;
	}

	/**
	 * Narrows the dictionary to the glossary terms linked to the product
	 * being rendered.
	 *
	 * The `saai_autolink_dictionary` callback. Only a product context is
	 * touched; every other post type keeps the full dictionary. With the
	 * toggle off the dictionary is returned as-is as well — this add-on
	 * then isn't the one that made `product` eligible, and whoever did
	 * should get the engine's normal behaviour.
	 *
	 * @param mixed $entries Dictionary entries (docs/DESIGN-AUTOLINK.md section 2.1).
	 * @param mixed $context [ 'post_id' => int, 'post_type' => string ].
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

		$product_id = isset( $context['post_id'] ) && is_numeric( $context['post_id'] ) ? (int) $context['post_id'] : 0;

		if ( $product_id <= 0 ) {
			return array();
		}

		$term_ids = $this->links->content_ids_for_product( $product_id, array( 'post_type' => 'saai_glossary' ) );

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
	 * formatting (priorities 9 and 10). WooCommerce applies this same
	 * filter to variation descriptions it embeds for the variations
	 * script; those are confined to the single product page by the
	 * context check as well.
	 *
	 * @param mixed $html The formatted short description.
	 * @return mixed
	 */
	public function filter_short_description( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		$product_id = Product_Context::current_product_id();

		if ( $product_id <= 0 ) {
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
	 * Runs the free plugin's auto-link engine over product HTML.
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

		$result = $autolinker->process(
			$html,
			array(
				'post_id'   => $product_id,
				'post_type' => Link_Resolver::PRODUCT_POST_TYPE,
			)
		);

		return is_string( $result ) ? $result : $html;
	}
}
