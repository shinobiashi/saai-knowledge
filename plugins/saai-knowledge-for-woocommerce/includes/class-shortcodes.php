<?php
/**
 * Registers the classic-theme shortcode wrappers for the product blocks.
 *
 * @package SAAI\KnowledgeWoo
 */

namespace SAAI\KnowledgeWoo;

defined( 'ABSPATH' ) || exit;

/**
 * Provides thin shortcode wrappers ([saai_product_faq] etc.) that render the
 * corresponding product block, so a classic theme — a product description,
 * a widget, a page — gets the blocks' exact output (docs/DESIGN.md section
 * 6.2). The same approach as the free plugin's own shortcodes, which this
 * add-on can't reuse directly (it only depends on the free plugin's public
 * hooks).
 */
final class Shortcodes {

	/**
	 * Shortcode tags mapped to the block each one renders.
	 *
	 * @var array<string, string>
	 */
	public const SHORTCODE_BLOCKS = array(
		'saai_product_faq'      => 'saai-knowledge/product-faq',
		'saai_product_docs'     => 'saai-knowledge/product-docs',
		'saai_product_glossary' => 'saai-knowledge/product-glossary',
	);

	/**
	 * Shortcode attributes mapped to the blocks' attributes; the same for
	 * every tag.
	 *
	 * Shortcode attribute names are lowercase (the shortcode parser lowercases
	 * them); `attr` is the block attribute to map to, `type` how to cast the
	 * shortcode's string value.
	 *
	 * @var array<string, array{attr: string, type: string}>
	 */
	private const SHORTCODE_ATTRS = array(
		'product_id' => array(
			'attr' => 'productId',
			'type' => 'int',
		),
		'show_title' => array(
			'attr' => 'showTitle',
			'type' => 'bool',
		),
	);

	/**
	 * Hooks shortcode registration into WordPress.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_shortcodes' ) );
	}

	/**
	 * Registers all shortcode wrappers.
	 */
	public function register_shortcodes(): void {
		foreach ( array_keys( self::SHORTCODE_BLOCKS ) as $tag ) {
			add_shortcode( $tag, array( $this, 'render_shortcode' ) );
		}
	}

	/**
	 * Renders the block backing a shortcode tag.
	 *
	 * The render_block() call supplies the same default context (postId and
	 * postType from the global $post) the block gets in post content, so a
	 * shortcode in a product's description resolves that product, as the
	 * block would.
	 *
	 * @param array<string, string>|string $atts    Shortcode attributes, mapped to block attributes via SHORTCODE_ATTRS.
	 * @param string|null                  $content Enclosed content. Unused.
	 * @param string                       $tag     The matched shortcode tag.
	 * @return string
	 */
	public function render_shortcode( $atts, $content, string $tag ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- kept to match the add_shortcode() callback signature.
		$block_name = self::SHORTCODE_BLOCKS[ $tag ] ?? '';

		if ( '' === $block_name || ! \WP_Block_Type_Registry::get_instance()->is_registered( $block_name ) ) {
			return '';
		}

		return render_block(
			array(
				'blockName'    => $block_name,
				'attrs'        => $this->block_attrs_from_shortcode_atts( is_array( $atts ) ? $atts : array() ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	/**
	 * Maps a shortcode's attributes onto its backing block's attributes.
	 *
	 * Unknown shortcode attributes are dropped; missing ones are left to the
	 * block's own defaults.
	 *
	 * @param array<mixed, mixed> $atts Parsed shortcode attributes.
	 * @return array<string, mixed>
	 */
	private function block_attrs_from_shortcode_atts( array $atts ): array {
		$block_attrs = array();

		foreach ( self::SHORTCODE_ATTRS as $name => $spec ) {
			if ( ! isset( $atts[ $name ] ) || ! is_scalar( $atts[ $name ] ) ) {
				continue;
			}

			$value = $atts[ $name ];

			if ( 'int' === $spec['type'] ) {
				// Not absint(): it would turn "-3" into 3, a different and
				// possibly real product; a negative ID resolves to nothing.
				$value = max( 0, (int) $value );
			} else {
				$value = rest_sanitize_boolean( (string) $value );
			}

			$block_attrs[ $spec['attr'] ] = $value;
		}

		return $block_attrs;
	}
}
