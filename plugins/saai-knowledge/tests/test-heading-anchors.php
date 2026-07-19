<?php
/**
 * Tests for the Heading_Anchors service (kb-toc heading extraction + content anchors).
 *
 * @package SAAI\Knowledge
 */

/**
 * Class Test_Heading_Anchors.
 */
class Test_Heading_Anchors extends WP_UnitTestCase {

	/**
	 * H2/h3 headings should be extracted with their text and level.
	 */
	public function test_extract_returns_h2_and_h3_headings_in_order() {
		$content = "<!-- wp:heading -->\n<h2>Intro</h2>\n<!-- /wp:heading -->\n\n" .
			"<!-- wp:paragraph -->\n<p>Text</p>\n<!-- /wp:paragraph -->\n\n" .
			"<!-- wp:heading {\"level\":3} -->\n<h3>Details</h3>\n<!-- /wp:heading -->";

		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => $content,
			)
		);

		$headings = ( new \SAAI\Knowledge\Heading_Anchors() )->extract( $post );

		$this->assertSame( array( 'Intro', 'Details' ), wp_list_pluck( $headings, 'text' ) );
		$this->assertSame( array( 2, 3 ), wp_list_pluck( $headings, 'level' ) );
	}

	/**
	 * H1 and h4+ headings are outside the table of contents' scope and must be skipped.
	 */
	public function test_extract_ignores_non_h2_h3_heading_levels() {
		$content = "<!-- wp:heading {\"level\":1} -->\n<h1>Title</h1>\n<!-- /wp:heading -->\n\n" .
			"<!-- wp:heading {\"level\":4} -->\n<h4>Sub</h4>\n<!-- /wp:heading -->";

		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => $content,
			)
		);

		$headings = ( new \SAAI\Knowledge\Heading_Anchors() )->extract( $post );

		$this->assertSame( array(), $headings );
	}

	/**
	 * Headings without an explicit HTML anchor get a slug derived from their text.
	 */
	public function test_extract_derives_id_from_heading_text_when_no_anchor_set() {
		$content = "<!-- wp:heading -->\n<h2>Getting Started</h2>\n<!-- /wp:heading -->";

		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => $content,
			)
		);

		$headings = ( new \SAAI\Knowledge\Heading_Anchors() )->extract( $post );

		$this->assertSame( 'getting-started', $headings[0]['id'] );
	}

	/**
	 * A heading's explicit "anchor" block attribute (the editor's HTML anchor field)
	 * must be used verbatim instead of a derived slug.
	 */
	public function test_extract_prefers_explicit_anchor_attribute() {
		$content = "<!-- wp:heading {\"anchor\":\"custom-slug\"} -->\n<h2 id=\"custom-slug\">Title</h2>\n<!-- /wp:heading -->";

		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => $content,
			)
		);

		$headings = ( new \SAAI\Knowledge\Heading_Anchors() )->extract( $post );

		$this->assertSame( 'custom-slug', $headings[0]['id'] );
	}

	/**
	 * Two headings that resolve to the same slug (identical text, or an explicit
	 * anchor colliding with a derived one) must get de-duplicated ids so the
	 * table of contents doesn't produce two links to the same fragment.
	 */
	public function test_extract_dedupes_colliding_ids() {
		$content = "<!-- wp:heading -->\n<h2>Overview</h2>\n<!-- /wp:heading -->\n\n" .
			"<!-- wp:heading -->\n<h2>Overview</h2>\n<!-- /wp:heading -->";

		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => $content,
			)
		);

		$headings = ( new \SAAI\Knowledge\Heading_Anchors() )->extract( $post );

		$this->assertSame( array( 'overview', 'overview-2' ), wp_list_pluck( $headings, 'id' ) );
	}

	/**
	 * Headings nested inside container blocks (group, columns, ...) must still be found.
	 */
	public function test_extract_recurses_into_inner_blocks() {
		$content = '<!-- wp:group --><div class="wp-block-group">' .
			"<!-- wp:heading {\"level\":3} -->\n<h3>Nested</h3>\n<!-- /wp:heading -->" .
			'</div><!-- /wp:group -->';

		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => $content,
			)
		);

		$headings = ( new \SAAI\Knowledge\Heading_Anchors() )->extract( $post );

		$this->assertSame( array( 'Nested' ), wp_list_pluck( $headings, 'text' ) );
	}

	/**
	 * A heading with no visible text (e.g. icon-only) still gets a heading record
	 * with a fallback id, so add_anchors()'s positional tag-by-tag walk stays
	 * aligned with this list even when such a heading is skipped from display.
	 */
	public function test_extract_includes_empty_headings_for_positional_alignment() {
		$content = "<!-- wp:heading -->\n<h2></h2>\n<!-- /wp:heading -->";

		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => $content,
			)
		);

		$headings = ( new \SAAI\Knowledge\Heading_Anchors() )->extract( $post );

		$this->assertCount( 1, $headings );
		$this->assertSame( '', $headings[0]['text'] );
		$this->assertSame( 'heading', $headings[0]['id'] );
	}

	/**
	 * Memoized extract() results must not go stale when the post object's
	 * content changes within the same request (the memo is keyed by post ID
	 * with a content hash guard).
	 */
	public function test_extract_memo_tracks_content_changes() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => "<!-- wp:heading -->\n<h2>Before</h2>\n<!-- /wp:heading -->",
			)
		);

		$anchors = new \SAAI\Knowledge\Heading_Anchors();

		$this->assertSame( array( 'Before' ), wp_list_pluck( $anchors->extract( $post ), 'text' ) );

		$post->post_content = "<!-- wp:heading -->\n<h2>After</h2>\n<!-- /wp:heading -->";

		$this->assertSame( array( 'After' ), wp_list_pluck( $anchors->extract( $post ), 'text' ) );
	}

	/**
	 * The for_display() method should drop headings with no visible text but keep a
	 * heading legitimately titled "0" (a falsy string, so empty()-style
	 * checks would wrongly discard it).
	 */
	public function test_for_display_excludes_empty_text_but_keeps_zero() {
		$content = "<!-- wp:heading -->\n<h2></h2>\n<!-- /wp:heading -->\n\n" .
			"<!-- wp:heading -->\n<h2>0</h2>\n<!-- /wp:heading -->";

		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => $content,
			)
		);

		$headings = ( new \SAAI\Knowledge\Heading_Anchors() )->for_display( $post );

		$this->assertSame( array( '0' ), wp_list_pluck( $headings, 'text' ) );
	}

	/**
	 * The saai_kb_toc_items filter should receive the heading list and
	 * context, and be able to replace the list.
	 */
	public function test_saai_kb_toc_items_filter_can_replace_headings() {
		$captured_context = null;

		$filter = static function ( $headings, $context ) use ( &$captured_context ) {
			$captured_context = $context;

			return array(
				array(
					'id'    => 'injected',
					'text'  => 'Injected',
					'level' => 2,
				),
			);
		};

		add_filter( 'saai_kb_toc_items', $filter, 10, 2 );

		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => "<!-- wp:heading -->\n<h2>Real</h2>\n<!-- /wp:heading -->",
			)
		);

		$headings = ( new \SAAI\Knowledge\Heading_Anchors() )->for_display( $post );

		remove_filter( 'saai_kb_toc_items', $filter, 10 );

		$this->assertSame( 'injected', $headings[0]['id'] );
		$this->assertSame( $post->ID, $captured_context['post_id'] );
	}

	/**
	 * A misbehaving saai_kb_toc_items callback returning a non-array must not
	 * fatal on the for_display(): array return type; the unfiltered list
	 * should be used instead.
	 */
	public function test_saai_kb_toc_items_filter_falls_back_when_not_an_array() {
		$filter = static function () {
			return 'not an array';
		};

		add_filter( 'saai_kb_toc_items', $filter );

		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => "<!-- wp:heading -->\n<h2>Real</h2>\n<!-- /wp:heading -->",
			)
		);

		$headings = ( new \SAAI\Knowledge\Heading_Anchors() )->for_display( $post );

		remove_filter( 'saai_kb_toc_items', $filter );

		$this->assertSame( array( 'Real' ), wp_list_pluck( $headings, 'text' ) );
	}

	/**
	 * Outside of a saai_kb main-query loop (e.g. called directly, or during
	 * some other post type's rendering) the content must be returned untouched.
	 */
	public function test_add_anchors_leaves_content_untouched_outside_saai_kb_loop() {
		$content = '<h2>Untouched</h2>';

		$this->assertSame( $content, ( new \SAAI\Knowledge\Heading_Anchors() )->add_anchors( $content ) );
	}

	/**
	 * Rendering a saai_kb post's content through the_content should inject ids
	 * into h2/h3 tags that don't already carry one, matching extract()'s slugs.
	 */
	public function test_add_anchors_injects_ids_while_rendering_saai_kb_content() {
		$content = "<!-- wp:heading -->\n<h2>Intro</h2>\n<!-- /wp:heading -->\n\n" .
			"<!-- wp:heading {\"level\":3} -->\n<h3>Details</h3>\n<!-- /wp:heading -->";

		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => $content,
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		$rendered = null;

		while ( have_posts() ) {
			the_post();
			$rendered = apply_filters( 'the_content', get_the_content() );
		}

		$processor = new WP_HTML_Tag_Processor( (string) $rendered );

		$this->assertTrue( $processor->next_tag( 'h2' ) );
		$this->assertSame( 'intro', $processor->get_attribute( 'id' ) );

		$this->assertTrue( $processor->next_tag( 'h3' ) );
		$this->assertSame( 'details', $processor->get_attribute( 'id' ) );
	}

	/**
	 * A heading that already has an id (via the core Heading block's HTML anchor
	 * setting) must not be overwritten.
	 */
	public function test_add_anchors_preserves_existing_heading_id() {
		$content = "<!-- wp:heading {\"anchor\":\"custom-slug\"} -->\n<h2 id=\"custom-slug\">Title</h2>\n<!-- /wp:heading -->";

		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'saai_kb',
				'post_content' => $content,
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		$rendered = null;

		while ( have_posts() ) {
			the_post();
			$rendered = apply_filters( 'the_content', get_the_content() );
		}

		$processor = new WP_HTML_Tag_Processor( (string) $rendered );

		$this->assertTrue( $processor->next_tag( 'h2' ) );
		$this->assertSame( 'custom-slug', $processor->get_attribute( 'id' ) );
	}
}
