<?php
/**
 * Extracts core/heading (h2/h3) blocks from post content and guarantees
 * they carry a stable id, shared by the kb-toc block and the on-page anchors.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves heading anchors for a post's content and injects matching id
 * attributes into the rendered h2/h3 tags that don't already have one
 * (e.g. via the core Heading block's "HTML anchor" advanced setting).
 */
final class Heading_Anchors {

	/**
	 * Hooks the content anchor filter into WordPress.
	 */
	public function register(): void {
		add_filter( 'the_content', array( $this, 'add_anchors' ) );
	}

	/**
	 * Extracts the ordered list of h2/h3 headings from a post's content.
	 *
	 * Every core/heading block of level 2 or 3 is included, even ones with
	 * no visible text (e.g. an icon-only heading) or no matching rendered
	 * tag on the front end, so the list's order stays aligned with
	 * add_anchors()'s tag-by-tag walk of the same content.
	 *
	 * @param \WP_Post $post Post to extract headings from.
	 * @return array<int, array<string, mixed>> List of [ 'id' => string, 'text' => string, 'level' => int ].
	 */
	public function extract( \WP_Post $post ): array {
		$headings = array();
		$used_ids = array();

		$this->collect_headings( parse_blocks( $post->post_content ), $headings, $used_ids );

		return $headings;
	}

	/**
	 * Injects id attributes into rendered h2/h3 tags that don't already have one.
	 *
	 * Matches tags positionally against extract()'s ordered heading list: the
	 * Nth h2/h3 tag in the rendered HTML corresponds to the Nth core/heading
	 * block found while walking the same content's parsed blocks.
	 *
	 * @param string $content Rendered post content (after do_blocks()).
	 * @return string
	 */
	public function add_anchors( string $content ): string {
		if ( ! is_singular( 'saai_kb' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}

		$headings = $this->extract( $post );

		if ( ! $headings ) {
			return $content;
		}

		$processor = new \WP_HTML_Tag_Processor( $content );
		$index     = 0;

		while ( $processor->next_tag() ) {
			$tag = $processor->get_tag();

			if ( 'H2' !== $tag && 'H3' !== $tag ) {
				continue;
			}

			if ( ! isset( $headings[ $index ] ) ) {
				break;
			}

			if ( null === $processor->get_attribute( 'id' ) ) {
				$processor->set_attribute( 'id', $headings[ $index ]['id'] );
			}

			++$index;
		}

		return $processor->get_updated_html();
	}

	/**
	 * Recursively walks a parsed block tree collecting h2/h3 headings in order.
	 *
	 * @param array<int, array<string, mixed>> $blocks   Parsed blocks, see parse_blocks().
	 * @param array<int, array<string, mixed>> $headings Accumulator, passed by reference.
	 * @param string[]                         $used_ids Ids already assigned, passed by reference.
	 */
	private function collect_headings( array $blocks, array &$headings, array &$used_ids ): void {
		foreach ( $blocks as $block ) {
			if ( 'core/heading' === ( $block['blockName'] ?? null ) ) {
				$level = (int) ( $block['attrs']['level'] ?? 2 );

				if ( in_array( $level, array( 2, 3 ), true ) ) {
					$text   = trim( wp_strip_all_tags( $block['innerHTML'] ?? '' ) );
					$anchor = (string) ( $block['attrs']['anchor'] ?? '' );

					$headings[] = array(
						'id'    => $this->resolve_id( $anchor, $text, $used_ids ),
						'text'  => $text,
						'level' => $level,
					);
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->collect_headings( $block['innerBlocks'], $headings, $used_ids );
			}
		}
	}

	/**
	 * Resolves a unique id for a heading, preferring its explicit HTML anchor.
	 *
	 * @param string   $anchor   The block's explicit "anchor" attribute, if any.
	 * @param string   $text     The heading's plain text, used to derive a slug when no anchor is set.
	 * @param string[] $used_ids Ids already assigned, passed by reference; the resolved id is appended.
	 * @return string
	 */
	private function resolve_id( string $anchor, string $text, array &$used_ids ): string {
		$base = '' !== $anchor ? $anchor : sanitize_title( $text );

		if ( '' === $base ) {
			$base = 'heading';
		}

		$id     = $base;
		$suffix = 2;

		while ( in_array( $id, $used_ids, true ) ) {
			$id = $base . '-' . $suffix;
			++$suffix;
		}

		$used_ids[] = $id;

		return $id;
	}
}
