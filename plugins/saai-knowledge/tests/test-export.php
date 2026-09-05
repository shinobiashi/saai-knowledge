<?php
/**
 * Tests for the Export service and its RAG export REST endpoint.
 *
 * @package SAAI\Knowledge
 */

use SAAI\Knowledge\Export;

/**
 * Class Test_Export.
 */
class Test_Export extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Export
	 */
	private $export;

	/**
	 * REST server used by the REST-level tests.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * The global `$wp_rest_server` value before set_up() replaced it, so
	 * tear_down() can restore it — same reasoning as Test_Search's own copy
	 * of this pattern.
	 *
	 * @var WP_REST_Server|null
	 */
	private $original_wp_rest_server;

	/**
	 * Sets up the service and REST server under test.
	 */
	public function set_up() {
		parent::set_up();

		$this->export = new Export();

		global $wp_rest_server;

		$this->original_wp_rest_server = $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		do_action( 'rest_api_init', $this->server );
	}

	/**
	 * Restores the global REST server replaced in set_up().
	 */
	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = $this->original_wp_rest_server;

		parent::tear_down();
	}

	/**
	 * Creates a published post of the given type.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $title     Post title.
	 * @param array  $args      Extra factory args.
	 * @return int Post ID.
	 */
	private function create_post( string $post_type, string $title, array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => $post_type,
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => '<!-- wp:paragraph --><p>' . $title . ' body.</p><!-- /wp:paragraph -->',
				),
				$args
			)
		);
	}

	/**
	 * Backdates a post's last-modified timestamp for modified_after tests.
	 *
	 * Wp_update_post() always forces post_modified(_gmt) to the current time
	 * on an update (see wp_insert_post()'s `$update` branch) — there is no
	 * `$postarr` value that overrides this — so backdating requires writing
	 * the posts table directly and clearing the post cache afterward.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $when    A strtotime()-parseable relative time, e.g. '-1 day'.
	 */
	private function set_modified( int $post_id, string $when ): void {
		global $wpdb;

		$mysql_datetime = gmdate( 'Y-m-d H:i:s', strtotime( $when ) );

		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $mysql_datetime,
				'post_modified_gmt' => $mysql_datetime,
			),
			array( 'ID' => $post_id )
		);

		clean_post_cache( $post_id );
	}

	/**
	 * The REST route should be registered, public, GET-only, and schema-discoverable.
	 */
	public function test_rest_route_is_registered_and_public() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/saai-knowledge/v1/export', $routes );

		$route = $routes['/saai-knowledge/v1/export'][0];

		$this->assertSame( array( 'GET' => true ), $route['methods'] );
		$this->assertSame( '__return_true', $route['permission_callback'] );
		$this->assertIsCallable( $route['callback'] );
	}

	/**
	 * A bare request with no params should export every registered type,
	 * page 1, and report pagination totals via both the JSON envelope and
	 * the X-WP-Total/X-WP-TotalPages headers (same convention WP core's own
	 * collection endpoints use).
	 */
	public function test_rest_request_defaults_to_all_types_with_pagination_envelope() {
		$this->create_post( 'saai_faq', 'Refund policy' );
		$this->create_post( 'saai_kb', 'Setup guide' );
		$this->create_post( 'saai_glossary', 'Widget' );

		$request  = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $data['page'] );
		$this->assertSame( 3, $data['total_items'] );
		$this->assertSame( 1, $data['total_pages'] );
		$this->assertCount( 3, $data['records'] );
		$this->assertSame( '3', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '1', $response->get_headers()['X-WP-TotalPages'] );
	}

	/**
	 * The `types` param should restrict the export to the requested types,
	 * same semantics as Search::requested_type_keys().
	 */
	public function test_rest_request_filters_by_types_param() {
		$this->create_post( 'saai_faq', 'Refund policy' );
		$this->create_post( 'saai_kb', 'Setup guide' );

		$request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$request->set_param( 'types', 'faq' );

		$response = $this->server->dispatch( $request );
		$records  = $response->get_data()['records'];

		$this->assertCount( 1, $records );
		$this->assertSame( 'faq', $records[0]['type'] );
	}

	/**
	 * Draft and password-protected posts must never be exported — same
	 * public-only rule as Search and Llms_Index.
	 */
	public function test_rest_request_excludes_non_public_posts() {
		$this->create_post( 'saai_faq', 'Draft FAQ', array( 'post_status' => 'draft' ) );
		$this->create_post( 'saai_faq', 'Locked FAQ', array( 'post_password' => 'secret' ) );
		$published_id = $this->create_post( 'saai_faq', 'Public FAQ' );

		$request  = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$response = $this->server->dispatch( $request );
		$records  = $response->get_data()['records'];

		$this->assertCount( 1, $records );
		$this->assertSame( $published_id, $records[0]['id'] );
	}

	/**
	 * `modified_after` should scope the export to content modified after
	 * that instant, for incremental sync.
	 */
	public function test_rest_request_modified_after_filters_by_date() {
		$old_id = $this->create_post( 'saai_faq', 'Old FAQ' );
		$this->set_modified( $old_id, '-3 days' );

		$new_id = $this->create_post( 'saai_faq', 'New FAQ' );
		$this->set_modified( $new_id, '-1 hour' );

		$request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$request->set_param( 'modified_after', gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '-1 day' ) ) );

		$response = $this->server->dispatch( $request );
		$records  = $response->get_data()['records'];

		$this->assertCount( 1, $records );
		$this->assertSame( $new_id, $records[0]['id'] );
	}

	/**
	 * An explicit but empty `modified_after` must not be rejected by the
	 * date-time format validation — see register_routes()'s validate_callback.
	 */
	public function test_rest_request_allows_empty_modified_after() {
		$this->create_post( 'saai_faq', 'Refund policy' );

		$request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$request->set_param( 'modified_after', '' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * An invalid `modified_after` value should fail validation.
	 */
	public function test_rest_request_rejects_invalid_modified_after() {
		$request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$request->set_param( 'modified_after', 'not-a-date' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * `per_page` above the documented maximum should be rejected, same as Search.
	 */
	public function test_rest_request_rejects_per_page_above_maximum() {
		$request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$request->set_param( 'per_page', 1000 );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * An unsupported `format` value should be rejected by the `enum` schema constraint.
	 */
	public function test_rest_request_rejects_unsupported_format() {
		$request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$request->set_param( 'format', 'xml' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * `per_page`/`page` should paginate a combined multi-type result set.
	 */
	public function test_rest_request_paginates_with_per_page() {
		$this->create_post( 'saai_faq', 'FAQ one' );
		$this->create_post( 'saai_faq', 'FAQ two' );
		$this->create_post( 'saai_faq', 'FAQ three' );

		$request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$request->set_param( 'per_page', 2 );
		$request->set_param( 'page', 2 );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertCount( 1, $data['records'] );
		$this->assertSame( 3, $data['total_items'] );
		$this->assertSame( 2, $data['total_pages'] );
	}

	/**
	 * A record's shape must match docs/DESIGN.md section 7.4, with
	 * content_markdown reusing Markdown_Output's own rendering (recognizable
	 * by its leading "# Title" heading) and content_plain free of Markdown syntax.
	 */
	public function test_record_shape_and_content_fields() {
		$post_id = $this->create_post( 'saai_faq', 'Refund policy' );

		$request  = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$response = $this->server->dispatch( $request );
		$record   = $response->get_data()['records'][0];

		$this->assertSame(
			array( 'id', 'type', 'title', 'content_markdown', 'content_plain', 'categories', 'tags', 'url', 'updated_at' ),
			array_keys( $record )
		);
		$this->assertSame( $post_id, $record['id'] );
		$this->assertSame( 'faq', $record['type'] );
		$this->assertSame( 'Refund policy', $record['title'] );
		$this->assertStringStartsWith( '# Refund policy', $record['content_markdown'] );
		$this->assertStringContainsString( 'Refund policy body.', $record['content_plain'] );
		$this->assertStringNotContainsString( '#', $record['content_plain'] );
		$this->assertSame( get_permalink( $post_id ), $record['url'] );
		$this->assertNotFalse( strtotime( $record['updated_at'] ) );
	}

	/**
	 * A shortcode inside the exported post's own content that calls the
	 * argument-less get_post() must see *that* post, not whatever post
	 * happened to already be the global $post (a stale value from another
	 * post on a persistent worker, say) — setup_postdata() alone never
	 * assigns $GLOBALS['post'] itself (only WP_Query::the_post(), which
	 * nothing in this REST context ever runs, does that).
	 */
	public function test_build_record_sets_global_post_for_the_content() {
		add_shortcode(
			'saai_test_current_post_id',
			static function () {
				$post = get_post();

				return $post instanceof \WP_Post ? (string) $post->ID : 'none';
			}
		);

		$post_id  = $this->create_post( 'saai_faq', 'Refund policy', array( 'post_content' => '[saai_test_current_post_id]' ) );
		$other_id = $this->create_post( 'saai_faq', 'Unrelated post' );

		// Simulates the exact failure condition: some other post is already
		// "current" when this record starts rendering.
		$GLOBALS['post'] = get_post( $other_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberately seeding the bug scenario under test.

		try {
			$request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
			$request->set_param( 'types', 'faq' );
			$response = $this->server->dispatch( $request );
		} finally {
			remove_shortcode( 'saai_test_current_post_id' );
		}

		$by_id = array();
		foreach ( $response->get_data()['records'] as $record ) {
			$by_id[ $record['id'] ] = $record;
		}

		$this->assertStringContainsString( (string) $post_id, $by_id[ $post_id ]['content_plain'] );
		$this->assertStringNotContainsString( (string) $other_id, $by_id[ $post_id ]['content_plain'] );
	}

	/**
	 * The content_markdown field must not be produced via Markdown_Output::render_cached():
	 * that method shares one site-wide transient with the public
	 * `?format=markdown` endpoint, but Autolinker::process_content() always
	 * skips while REST_REQUEST is defined — so writing this REST context's
	 * (never-autolinked) rendering into that cache would silently serve it
	 * to a real ?format=markdown visitor (who should get autolinked content)
	 * for up to a day, purely because the export happened to run first.
	 */
	public function test_build_record_does_not_populate_the_shared_markdown_cache() {
		$post_id = $this->create_post( 'saai_faq', 'Refund policy' );

		$request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$this->server->dispatch( $request );

		$generation = (int) get_option( 'saai_dict_generation', 1 );

		$this->assertFalse( get_transient( 'saai_markdown_' . $post_id . '_' . $generation ) );
	}

	/**
	 * Category/tag names should be included for FAQ/KB but always
	 * empty for glossary, which neither taxonomy applies to.
	 */
	public function test_categories_and_tags_present_for_faq_and_kb_but_not_glossary() {
		$faq_id = $this->create_post( 'saai_faq', 'Refund policy' );
		wp_set_object_terms( $faq_id, array( 'Billing' ), 'saai_category' );
		wp_set_object_terms( $faq_id, array( 'refunds' ), 'saai_tag' );

		$this->create_post( 'saai_glossary', 'Widget' );

		$request  = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$response = $this->server->dispatch( $request );

		$by_type = array();
		foreach ( $response->get_data()['records'] as $record ) {
			$by_type[ $record['type'] ] = $record;
		}

		$this->assertSame( array( 'Billing' ), $by_type['faq']['categories'] );
		$this->assertSame( array( 'refunds' ), $by_type['faq']['tags'] );
		$this->assertSame( array(), $by_type['glossary']['categories'] );
		$this->assertSame( array(), $by_type['glossary']['tags'] );
	}

	/**
	 * `sections` should only appear on KB records, one entry per h2, each
	 * carrying the heading text, the matching Heading_Anchors anchor id, and
	 * a self-contained Markdown chunk.
	 */
	public function test_sections_present_only_for_kb_and_split_by_h2() {
		$content  = '<!-- wp:heading --><h2>Getting started</h2><!-- /wp:heading -->';
		$content .= '<!-- wp:paragraph --><p>Install the plugin first.</p><!-- /wp:paragraph -->';
		$content .= '<!-- wp:heading --><h2>Troubleshooting</h2><!-- /wp:heading -->';
		$content .= '<!-- wp:paragraph --><p>Check the logs.</p><!-- /wp:paragraph -->';

		$kb_id  = $this->create_post( 'saai_kb', 'Setup guide', array( 'post_content' => $content ) );
		$faq_id = $this->create_post( 'saai_faq', 'Refund policy' );

		$request  = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$response = $this->server->dispatch( $request );

		$by_id = array();
		foreach ( $response->get_data()['records'] as $record ) {
			$by_id[ $record['id'] ] = $record;
		}

		$this->assertArrayNotHasKey( 'sections', $by_id[ $faq_id ] );

		$sections = $by_id[ $kb_id ]['sections'];
		$this->assertCount( 2, $sections );

		$this->assertSame( 'Getting started', $sections[0]['heading'] );
		$this->assertSame( 'getting-started', $sections[0]['anchor'] );
		$this->assertStringContainsString( '## Getting started', $sections[0]['content_markdown'] );
		$this->assertStringContainsString( 'Install the plugin first.', $sections[0]['content_markdown'] );

		$this->assertSame( 'Troubleshooting', $sections[1]['heading'] );
		$this->assertSame( 'troubleshooting', $sections[1]['anchor'] );
		$this->assertStringContainsString( 'Check the logs.', $sections[1]['content_markdown'] );
		$this->assertStringNotContainsString( 'Install the plugin first.', $sections[1]['content_markdown'] );
	}

	/**
	 * A KB article with no h2 headings at all should simply have no sections,
	 * rather than erroring.
	 */
	public function test_sections_is_empty_array_when_no_h2_headings() {
		$kb_id = $this->create_post( 'saai_kb', 'Setup guide' );

		$request  = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$response = $this->server->dispatch( $request );

		$record = $response->get_data()['records'][0];

		$this->assertSame( $kb_id, $record['id'] );
		$this->assertSame( array(), $record['sections'] );
	}

	/**
	 * A top-level h2 must never be attributed the anchor of a *different*
	 * heading. Heading_Anchors::extract() also counts an h2 nested inside a
	 * wrapper block (Group), which build_sections()'s own top-level-only
	 * walk does not see as a section boundary — without matching_anchor()'s
	 * text-agreement check, this shifts every later top-level section's
	 * position-matched anchor by one, silently pointing it at the wrong
	 * heading's id instead of its own.
	 */
	public function test_sections_anchor_does_not_misattribute_when_a_nested_h2_shifts_position() {
		$content  = '<!-- wp:group --><div class="wp-block-group">';
		$content .= '<!-- wp:heading --><h2>Nested heading</h2><!-- /wp:heading -->';
		$content .= '<!-- wp:paragraph --><p>Nested paragraph.</p><!-- /wp:paragraph -->';
		$content .= '</div><!-- /wp:group -->';
		$content .= '<!-- wp:heading --><h2>Real section one</h2><!-- /wp:heading -->';
		$content .= '<!-- wp:paragraph --><p>Section one body.</p><!-- /wp:paragraph -->';
		$content .= '<!-- wp:heading --><h2>Real section two</h2><!-- /wp:heading -->';
		$content .= '<!-- wp:paragraph --><p>Section two body.</p><!-- /wp:paragraph -->';

		$this->create_post( 'saai_kb', 'Setup guide', array( 'post_content' => $content ) );

		$request  = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$response = $this->server->dispatch( $request );
		$sections = $response->get_data()['records'][0]['sections'];

		$this->assertCount( 2, $sections );
		$this->assertSame( 'Real section one', $sections[0]['heading'] );
		$this->assertSame( 'Real section two', $sections[1]['heading'] );

		// Neither anchor may be "nested-heading" (the misattributed id a
		// naive positional match would produce) or each other's slug.
		$this->assertSame( 'real-section-one', $sections[0]['anchor'] );
		$this->assertSame( 'real-section-two', $sections[1]['anchor'] );
	}

	/**
	 * A text-agreement check alone cannot catch a nested heading and its
	 * immediately following top-level heading sharing the exact same
	 * wording (e.g. both "Overview") — build_sections() must exclude the
	 * nested candidate from consideration entirely (via
	 * Heading_Anchors::extract()'s `top_level` flag), not just compare text,
	 * or it would still accept the nested heading's id.
	 */
	public function test_sections_anchor_correct_when_nested_and_top_level_headings_share_text() {
		$content  = '<!-- wp:group --><div class="wp-block-group">';
		$content .= '<!-- wp:heading --><h2>Overview</h2><!-- /wp:heading -->';
		$content .= '<!-- wp:paragraph --><p>Nested overview text.</p><!-- /wp:paragraph -->';
		$content .= '</div><!-- /wp:group -->';
		$content .= '<!-- wp:heading --><h2>Overview</h2><!-- /wp:heading -->';
		$content .= '<!-- wp:paragraph --><p>Real overview text.</p><!-- /wp:paragraph -->';

		$this->create_post( 'saai_kb', 'Setup guide', array( 'post_content' => $content ) );

		$request  = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$response = $this->server->dispatch( $request );
		$sections = $response->get_data()['records'][0]['sections'];

		$this->assertCount( 1, $sections );
		$this->assertSame( 'Overview', $sections[0]['heading'] );
		// Heading_Anchors::extract() assigns 'overview' to the nested
		// occurrence and 'overview-2' to the top-level one (document order,
		// deduped) — the top-level-only section must resolve to its own id.
		$this->assertSame( 'overview-2', $sections[0]['anchor'] );
		$this->assertStringContainsString( 'Real overview text.', $sections[0]['content_markdown'] );
	}

	/**
	 * The saai_export_record filter should be able to annotate a record
	 * (the paid add-on's product ID/SKU use case) and should receive the
	 * requested format.
	 */
	public function test_saai_export_record_filter_can_annotate_records() {
		$this->create_post( 'saai_faq', 'Refund policy' );

		$seen_formats = array();
		$annotate     = function ( array $record, \WP_Post $post, string $format ) use ( &$seen_formats ): array {
			$record['product_id'] = 123;
			$seen_formats[]       = $format;

			return $record;
		};

		add_filter( 'saai_export_record', $annotate, 10, 3 );

		$request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$request->set_param( 'format', 'jsonl' );

		$response = $this->server->dispatch( $request );

		remove_filter( 'saai_export_record', $annotate, 10 );

		$this->assertSame( 123, $response->get_data()['records'][0]['product_id'] );
		$this->assertSame( array( 'jsonl' ), $seen_formats );
	}

	/**
	 * A saai_export_record callback returning a non-array must not corrupt
	 * the record — same defensive fallback as every other public filter in
	 * this plugin (e.g. Search::results()).
	 */
	public function test_saai_export_record_filter_ignores_non_array_return() {
		$this->create_post( 'saai_faq', 'Refund policy' );

		$break = static function () {
			return 'not-an-array';
		};

		add_filter( 'saai_export_record', $break );

		$request  = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$response = $this->server->dispatch( $request );

		remove_filter( 'saai_export_record', $break );

		$this->assertSame( 'Refund policy', $response->get_data()['records'][0]['title'] );
	}

	/**
	 * The maybe_serve_jsonl() filter should emit one JSON object per line (no wrapping
	 * array/envelope) for the export route when format=jsonl, and report the
	 * request as served.
	 */
	public function test_maybe_serve_jsonl_outputs_newline_delimited_records() {
		$this->create_post( 'saai_faq', 'Refund policy' );
		$this->create_post( 'saai_kb', 'Setup guide' );

		$request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$request->set_param( 'format', 'jsonl' );

		// Dispatched (rather than calling handle_request() directly) so the
		// route's registered arg defaults — per_page in particular — are
		// actually applied to $request; a bare handle_request() call would
		// see a null per_page and fall back to querying just 1 post.
		$response = $this->server->dispatch( $request );

		ob_start();
		$served = $this->export->maybe_serve_jsonl( false, $response, $request, $this->server );
		$body   = ob_get_clean();

		$this->assertTrue( $served );

		$lines = array_values( array_filter( explode( "\n", $body ) ) );
		$this->assertCount( 2, $lines );

		foreach ( $lines as $line ) {
			$decoded = json_decode( $line, true );
			$this->assertIsArray( $decoded );
			$this->assertArrayHasKey( 'id', $decoded );
		}
	}

	/**
	 * The maybe_serve_jsonl() filter must be a no-op for any other route or for
	 * format=json on this same route — it only ever intercepts its own
	 * route's jsonl responses.
	 */
	public function test_maybe_serve_jsonl_ignores_other_routes_and_formats() {
		$export_request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$other_request  = new WP_REST_Request( 'GET', '/saai-knowledge/v1/search' );
		$response       = new WP_REST_Response( array( 'records' => array() ) );

		$this->assertFalse( $this->export->maybe_serve_jsonl( false, $response, $other_request, $this->server ) );
		$this->assertFalse( $this->export->maybe_serve_jsonl( false, $response, $export_request, $this->server ) );
		$this->assertTrue( $this->export->maybe_serve_jsonl( true, $response, $export_request, $this->server ) );
	}

	/**
	 * A request rejected by arg validation (e.g. an out-of-range per_page)
	 * never reaches handle_request() — WP_REST_Server converts the resulting
	 * WP_Error into a `{ code, message, data }`-shaped WP_REST_Response
	 * instead, which has no `records` key. maybe_serve_jsonl() must leave
	 * that alone (returning $served unchanged) so core's normal JSON
	 * serving still delivers the real error, rather than swallowing it into
	 * an empty NDJSON body.
	 */
	public function test_maybe_serve_jsonl_does_not_intercept_error_responses() {
		$request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$request->set_param( 'format', 'jsonl' );
		$request->set_param( 'per_page', 1000 );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );

		ob_start();
		$served = $this->export->maybe_serve_jsonl( false, $response, $request, $this->server );
		$body   = ob_get_clean();

		$this->assertFalse( $served );
		$this->assertSame( '', $body );
	}

	/**
	 * A HEAD request must never carry a body. Core's own HEAD-body
	 * suppression in WP_REST_Server::serve_request() only runs inside the
	 * `if ( ! $served )` branch, which this callback's own `return true`
	 * always skips — so it must suppress the body itself instead of relying
	 * on that.
	 */
	public function test_maybe_serve_jsonl_omits_body_for_head_requests() {
		$this->create_post( 'saai_faq', 'Refund policy' );

		$request = new WP_REST_Request( 'HEAD', '/saai-knowledge/v1/export' );
		$request->set_param( 'format', 'jsonl' );

		$response = $this->server->dispatch( $request );

		ob_start();
		$served = $this->export->maybe_serve_jsonl( false, $response, $request, $this->server );
		$body   = ob_get_clean();

		$this->assertTrue( $served );
		$this->assertSame( '', $body );
	}

	/**
	 * The admin screen should be nested under Settings::PAGE_SLUG, matching
	 * every other admin screen this plugin registers.
	 */
	public function test_register_menu_adds_export_submenu() {
		global $submenu;

		$previous_user = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$slug         = \SAAI\Knowledge\Settings::PAGE_SLUG;
		$previous_sub = $submenu[ $slug ] ?? null;

		try {
			$this->export->register_menu();

			$slugs = wp_list_pluck( $submenu[ $slug ], 2 );
			// Mirrors Export::PAGE_SLUG (private) — the submenu's own page slug.
			$this->assertContains( 'saai-knowledge-export', $slugs );
		} finally {
			if ( null === $previous_sub ) {
				unset( $submenu[ $slug ] );
			} else {
				$submenu[ $slug ] = $previous_sub;
			}

			wp_set_current_user( $previous_user );
		}
	}

	/**
	 * The render_page() method must not output anything for a user lacking manage_options.
	 */
	public function test_render_page_requires_manage_options_capability() {
		$previous_user = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		$this->export->render_page();
		$output = ob_get_clean();

		wp_set_current_user( $previous_user );

		$this->assertSame( '', $output );
	}

	/**
	 * The render_page() method should render nonced download links for an administrator.
	 */
	public function test_render_page_renders_download_links_for_administrator() {
		$previous_user = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		$this->export->render_page();
		$output = ob_get_clean();

		wp_set_current_user( $previous_user );

		$this->assertStringContainsString( 'action=saai_export_download', $output );
		$this->assertStringContainsString( 'format=jsonl', $output );
		$this->assertStringContainsString( 'format=csv', $output );
		$this->assertStringContainsString( '_wpnonce=', $output );
	}

	/**
	 * A record field beginning with a formula-trigger character (`=`, `+`,
	 * `-`, `@`, tab, or CR) must be prefixed with a literal single quote in
	 * the CSV output — otherwise Excel/Sheets reads it as a formula once an
	 * administrator opens the downloaded file (CWE-1236: CSV/DDE formula
	 * injection). title/content_markdown/content_plain/categories/tags are
	 * all arbitrary editor-supplied strings, so this cannot be assumed away.
	 */
	public function test_stream_csv_escapes_formula_injection_characters() {
		$records = array(
			array(
				'id'               => 1,
				'type'             => 'faq',
				'title'            => '=cmd|\'/c calc\'!A1',
				'content_markdown' => '+SUM(A1:A9)',
				'content_plain'    => '-2+3',
				'categories'       => array( '@mention' ),
				'tags'             => array( 'safe-tag' ),
				'url'              => 'http://example.test/faq/x/',
				'updated_at'       => '2026-01-01T00:00:00',
			),
		);

		$method = new ReflectionMethod( $this->export, 'stream_csv' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $this->export, $records );
		$csv = ob_get_clean();

		// Strip the leading UTF-8 BOM before parsing so str_getcsv() sees a
		// clean first field. $escape is passed explicitly (matching
		// stream_csv()'s own fputcsv() calls) to silence PHP 8.4's
		// deprecation for omitting it and to parse using the same
		// RFC 4180-only quoting rules the writer side now uses.
		$rows = array_map(
			static function ( string $line ): array {
				return str_getcsv( $line, ',', '"', '' );
			},
			explode( "\n", trim( substr( $csv, 3 ) ) )
		);
		$row  = $rows[1];

		$this->assertSame( "'=cmd|'/c calc'!A1", $row[2] );
		$this->assertSame( "'+SUM(A1:A9)", $row[3] );
		$this->assertSame( "'-2+3", $row[4] );
		$this->assertSame( "'@mention", $row[5] );
		// A value that doesn't start with a trigger character is untouched.
		$this->assertSame( 'safe-tag', $row[6] );
	}

	/**
	 * A saai_export_record callback can replace any field with a non-scalar
	 * value (an array, say); stream_csv() must not fatal on that (a strict
	 * `string`-typed escaping helper would throw a TypeError here and break
	 * the entire download over one bad row) — same defensive posture this
	 * plugin already takes toward every other public filter's output.
	 */
	public function test_stream_csv_does_not_fatal_on_non_scalar_record_fields() {
		$records = array(
			array(
				'id'               => 1,
				'type'             => 'faq',
				'title'            => array( 'unexpected' => 'array' ),
				'content_markdown' => null,
				'content_plain'    => 'fine',
				'categories'       => array(),
				'tags'             => array(),
				'url'              => 'http://example.test/faq/x/',
				'updated_at'       => '2026-01-01T00:00:00',
			),
		);

		$method = new ReflectionMethod( $this->export, 'stream_csv' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $this->export, $records );
		$csv = ob_get_clean();

		$this->assertStringContainsString( 'fine', $csv );
	}

	/**
	 * A field a saai_export_record callback adds beyond the base columns
	 * (the paid add-on's product ID/category metadata, per
	 * docs/DESIGN-HOOKS-API.md section 3.4) must appear as its own CSV
	 * column — the admin CSV download is documented as carrying the same
	 * content as JSON/JSONL, which wouldn't hold if this metadata were
	 * silently dropped. A record the filter never touched gets an empty
	 * cell for that column, not a missing/misaligned row.
	 */
	public function test_stream_csv_includes_extra_filter_added_columns() {
		$annotate = static function ( array $record ): array {
			if ( 'faq' === $record['type'] ) {
				$record['product_id']         = 42;
				$record['product_categories'] = array( 'Widgets', 'Gadgets' );
			}

			return $record;
		};

		add_filter( 'saai_export_record', $annotate );

		$this->create_post( 'saai_faq', 'Refund policy' );
		$this->create_post( 'saai_kb', 'Setup guide' );

		$request  = new WP_REST_Request( 'GET', '/saai-knowledge/v1/export' );
		$response = $this->server->dispatch( $request );

		remove_filter( 'saai_export_record', $annotate );

		$method = new ReflectionMethod( $this->export, 'stream_csv' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $this->export, $response->get_data()['records'] );
		$csv = ob_get_clean();

		// Unlike the hand-crafted single-line mock records in the other
		// stream_csv() tests above, these are real export records — their
		// content_markdown legitimately contains embedded newlines (a
		// title heading, blank lines, body text), which fputcsv() quotes
		// rather than splits. A naive explode( "\n", $csv ) would fragment
		// that one quoted field's own newlines into extra, column-count-
		// mismatched "rows" instead of parsing it as CSV; fgetcsv() on an
		// in-memory stream respects the quoting the same way stream_csv()'s
		// own writer produced it.
		$rows   = array();
		$stream = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- an in-memory stream for CSV-parsing this test's captured output, not the filesystem.

		fwrite( $stream, substr( $csv, 3 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing to the in-memory stream above; strips the leading UTF-8 BOM.
		rewind( $stream );

		while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- the standard fgetcsv() read-until-EOF idiom.
			$rows[] = $row;
		}

		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the in-memory stream opened above, not a filesystem handle.

		$header = $rows[0];

		$this->assertContains( 'product_id', $header );
		$this->assertContains( 'product_categories', $header );

		$product_id_index         = array_search( 'product_id', $header, true );
		$product_categories_index = array_search( 'product_categories', $header, true );

		$faq_row = null;
		$kb_row  = null;

		foreach ( array_slice( $rows, 1 ) as $row ) {
			if ( 'faq' === $row[1] ) {
				$faq_row = $row;
			} elseif ( 'kb' === $row[1] ) {
				$kb_row = $row;
			}
		}

		$this->assertNotNull( $faq_row );
		$this->assertNotNull( $kb_row );
		$this->assertSame( '42', $faq_row[ $product_id_index ] );
		$this->assertSame( 'Widgets; Gadgets', $faq_row[ $product_categories_index ] );
		// The KB record was never annotated by the filter — its extra
		// columns must still exist, just empty.
		$this->assertSame( '', $kb_row[ $product_id_index ] );
		$this->assertSame( '', $kb_row[ $product_categories_index ] );
	}
}
