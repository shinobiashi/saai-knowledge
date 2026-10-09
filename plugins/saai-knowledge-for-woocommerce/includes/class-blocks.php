<?php
/**
 * Registers the add-on's product blocks.
 *
 * @package SAAI\KnowledgeWoo
 */

namespace SAAI\KnowledgeWoo;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the manual-placement product blocks (docs/DESIGN.md section 6.2)
 * from their build/ metadata, and holds the markup they share.
 */
final class Blocks {

	/**
	 * Block directory names under build/, relative to the add-on's root.
	 *
	 * @var string[]
	 */
	public const BLOCKS = array(
		'product-faq',
		'product-docs',
		'product-glossary',
	);

	/**
	 * Hooks block registration into WordPress.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Registers each block from its build/ directory.
	 *
	 * A block whose build is missing (a source checkout without
	 * `npm run build`) is skipped rather than registered half-way, as the
	 * free plugin does.
	 */
	public function register_blocks(): void {
		foreach ( self::BLOCKS as $block ) {
			$path = SAAI_KNOWLEDGE_WOO_DIR . "build/{$block}";

			if ( file_exists( $path . '/block.json' ) ) {
				register_block_type( $path );
			}
		}
	}

	/**
	 * Wraps a product block's body in its block wrapper, with an optional
	 * heading.
	 *
	 * Called from the blocks' render.php only, while the block is being
	 * rendered (get_block_wrapper_attributes() reads the block in progress).
	 * With the heading on, the wrapper is a `<section>` labelled by it; the
	 * id is unique per render, so the block placed twice — or next to the
	 * automatic related-documentation section — never duplicates it.
	 *
	 * @param string $class_name Base class of the block's markup (`saai-woo-product-faq`, ...).
	 * @param string $title      Heading text (unescaped; see text()).
	 * @param string $body       The block's already-escaped body HTML.
	 * @param bool   $show_title Whether to print the heading.
	 * @return string
	 */
	public static function section_html( string $class_name, string $title, string $body, bool $show_title ): string {
		$wrapper_attributes = get_block_wrapper_attributes( array( 'class' => $class_name ) );

		if ( ! $show_title ) {
			return sprintf( '<div %1$s>%2$s</div>', $wrapper_attributes, $body );
		}

		$title_id = wp_unique_id( $class_name . '-title-' );

		return sprintf(
			'<section %1$s aria-labelledby="%2$s"><h2 id="%2$s" class="%3$s__title">%4$s</h2>%5$s</section>',
			$wrapper_attributes,
			esc_attr( $title_id ),
			esc_attr( $class_name ),
			self::text( $title ),
			$body
		);
	}

	/**
	 * Escapes text for a product block's HTML, square brackets included.
	 *
	 * A block placed in post content (a product description, a page) is
	 * rendered by do_blocks() at `the_content` priority 9, and do_shortcode()
	 * runs over that output at priority 11. A title or glossary definition
	 * that mentions `[some_shortcode]` — or `[[some_shortcode]]`, which
	 * strip_shortcodes() turns back into the runnable form — would otherwise
	 * be executed inside the list. Character references keep the text
	 * exactly as written while giving the shortcode parser nothing to match.
	 *
	 * @param string $text Unescaped text.
	 * @return string
	 */
	public static function text( string $text ): string {
		return str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), esc_html( $text ) );
	}

	/**
	 * Reads a block's `showTitle` attribute; on unless explicitly off.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function show_title( array $attributes ): bool {
		return ! array_key_exists( 'showTitle', $attributes ) || (bool) $attributes['showTitle'];
	}
}
