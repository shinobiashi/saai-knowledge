<?php
/**
 * Registers the classic-theme shortcode wrappers for the plugin's blocks.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Provides thin shortcode wrappers ([saai_kb_sidebar] etc.) that render the
 * corresponding dynamic block, so classic-theme users get identical output.
 */
final class Shortcodes {

	/**
	 * Shortcode tags mapped to the block each one renders.
	 *
	 * @var array<string, string>
	 */
	private const SHORTCODE_BLOCKS = array(
		'saai_kb_sidebar'  => 'saai-knowledge/kb-sidebar',
		'saai_kb_toc'      => 'saai-knowledge/kb-toc',
		'saai_breadcrumbs' => 'saai-knowledge/breadcrumbs',
		'saai_faq'         => 'saai-knowledge/faq-list',
		'saai_glossary'    => 'saai-knowledge/glossary-index',
		'saai_search'      => 'saai-knowledge/search',
	);

	/**
	 * Per-tag shortcode attributes mapped to the backing block's attributes.
	 *
	 * Shortcode attribute names are lowercase (the shortcode parser lowercases
	 * them); `attr` is the block attribute to map to, `type` how to cast the
	 * shortcode's string value.
	 *
	 * @var array<string, array<string, array{attr: string, type: string}>>
	 */
	private const SHORTCODE_ATTRS = array(
		'saai_faq' => array(
			'category'          => array(
				'attr' => 'category',
				'type' => 'string',
			),
			'count'             => array(
				'attr' => 'count',
				'type' => 'int',
			),
			'orderby'           => array(
				'attr' => 'orderBy',
				'type' => 'string',
			),
			'order'             => array(
				'attr' => 'order',
				'type' => 'string',
			),
			'group_by_category' => array(
				'attr' => 'groupByCategory',
				'type' => 'bool',
			),
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
	 * postType from the global $post) the block gets in post content, and
	 * enqueues the block's view assets, so the output matches the block.
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
				'attrs'        => $this->block_attrs_from_shortcode_atts( $tag, is_array( $atts ) ? $atts : array() ),
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
	 * @param string              $tag  The matched shortcode tag.
	 * @param array<mixed, mixed> $atts Parsed shortcode attributes.
	 * @return array<string, mixed>
	 */
	private function block_attrs_from_shortcode_atts( string $tag, array $atts ): array {
		$block_attrs = array();

		foreach ( self::SHORTCODE_ATTRS[ $tag ] ?? array() as $name => $spec ) {
			if ( ! isset( $atts[ $name ] ) || ! is_scalar( $atts[ $name ] ) ) {
				continue;
			}

			$value = $atts[ $name ];

			switch ( $spec['type'] ) {
				case 'int':
					$value = (int) $value;
					break;
				case 'bool':
					$value = rest_sanitize_boolean( (string) $value );
					break;
				default:
					$value = (string) $value;
			}

			$block_attrs[ $spec['attr'] ] = $value;
		}

		return $block_attrs;
	}
}
