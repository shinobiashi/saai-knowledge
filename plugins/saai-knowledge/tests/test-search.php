<?php
/**
 * Tests for the Search service and its REST endpoint.
 *
 * @package SAAI\Knowledge
 */

use SAAI\Knowledge\Search;

/**
 * Class Test_Search.
 */
class Test_Search extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Search
	 */
	private $search;

	/**
	 * REST server used by the REST-level tests.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * The global `$wp_rest_server` value before set_up() replaced it, so
	 * tear_down() can restore it (rest_get_server() reuses a non-null global
	 * as-is, so leaking ours would hand later tests a server pre-populated
	 * with this class's routes).
	 *
	 * @var WP_REST_Server|null
	 */
	private $original_wp_rest_server;

	/**
	 * Sets up the service and REST server under test.
	 */
	public function set_up() {
		parent::set_up();

		$this->search = new Search();

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
	 * @param string $title     Post title (also used as content so `s` matches it).
	 * @param array  $args      Extra factory args (e.g. post_status/post_password).
	 * @return int Post ID.
	 */
	private function create_post( string $post_type, string $title, array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => $post_type,
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => $title,
					'post_excerpt' => $title,
				),
				$args
			)
		);
	}

	/**
	 * Post_types() should default to faq/kb/glossary.
	 */
	public function test_post_types_returns_default_three_types() {
		$types = $this->search->post_types();

		$this->assertSame( array( 'faq', 'kb', 'glossary' ), array_keys( $types ) );
		$this->assertSame( 'saai_faq', $types['faq']['post_type'] );
		$this->assertSame( 'saai_kb', $types['kb']['post_type'] );
		$this->assertSame( 'saai_glossary', $types['glossary']['post_type'] );
	}

	/**
	 * The saai_search_post_types filter should be able to add a type (paid add-on's
	 * product content, per docs/DESIGN-HOOKS-API.md section 3.3).
	 */
	public function test_post_types_filter_can_extend_registered_types() {
		$add_product_type = static function ( array $types ): array {
			$types['product'] = array(
				'post_type' => 'product',
				'label'     => 'Products',
			);

			return $types;
		};

		add_filter( 'saai_search_post_types', $add_product_type );

		$types = $this->search->post_types();

		remove_filter( 'saai_search_post_types', $add_product_type );

		$this->assertArrayHasKey( 'product', $types );
	}

	/**
	 * An empty query should short-circuit to no results without running a query.
	 */
	public function test_results_returns_empty_array_for_empty_query() {
		$this->create_post( 'saai_faq', 'Widget assembly' );

		$this->assertSame( array(), $this->search->results( '', array( 'faq' ), 10 ) );
		$this->assertSame( array(), $this->search->results( '   ', array( 'faq' ), 10 ) );
	}

	/**
	 * An empty (or entirely unrecognized) types list should return no results.
	 */
	public function test_results_returns_empty_array_when_no_types_selected() {
		$this->create_post( 'saai_faq', 'Widget assembly' );

		$this->assertSame( array(), $this->search->results( 'Widget', array(), 10 ) );
		$this->assertSame( array(), $this->search->results( 'Widget', array( 'not-a-real-type' ), 10 ) );
	}

	/**
	 * Matching content across all three registered types should come back
	 * with the minimal id/type/title/url/excerpt shape (docs/DESIGN.md
	 * section 4.4).
	 */
	public function test_results_matches_across_registered_types() {
		$faq_id      = $this->create_post( 'saai_faq', 'Widget refund policy' );
		$kb_id       = $this->create_post( 'saai_kb', 'Widget setup guide' );
		$glossary_id = $this->create_post( 'saai_glossary', 'Widget' );

		$results = $this->search->results( 'Widget', array( 'faq', 'kb', 'glossary' ), 10 );

		$this->assertCount( 3, $results );

		$by_type = array();
		foreach ( $results as $result ) {
			$by_type[ $result['type'] ] = $result;
		}

		$this->assertSame( $faq_id, $by_type['faq']['id'] );
		$this->assertSame( $kb_id, $by_type['kb']['id'] );
		$this->assertSame( $glossary_id, $by_type['glossary']['id'] );

		foreach ( $results as $result ) {
			$this->assertSame( array( 'id', 'type', 'title', 'url', 'excerpt' ), array_keys( $result ) );
			$this->assertIsString( $result['url'] );
			$this->assertNotSame( '', $result['url'] );
		}
	}

	/**
	 * Titles/excerpts must come back plain-text decoded, not as the raw HTML
	 * entity references get_the_title()/get_the_excerpt() produce — the REST
	 * response is consumed by JS via textContent, which does not decode them.
	 */
	public function test_results_decodes_html_entities_in_title_and_excerpt() {
		$this->create_post(
			'saai_faq',
			'Widgets & Gadgets',
			array( 'post_excerpt' => 'Mentions R&D for excerpt purposes.' )
		);

		$results = $this->search->results( 'Widgets', array( 'faq' ), 10 );

		$this->assertSame( 'Widgets & Gadgets', $results[0]['title'] );
		$this->assertStringContainsString( 'R&D', $results[0]['excerpt'] );
	}

	/**
	 * The `types` filter should actually exclude non-selected types, not
	 * just label results.
	 */
	public function test_results_only_searches_requested_types() {
		$this->create_post( 'saai_faq', 'Widget refund policy' );
		$this->create_post( 'saai_kb', 'Widget setup guide' );

		$results = $this->search->results( 'Widget', array( 'faq' ), 10 );

		$this->assertCount( 1, $results );
		$this->assertSame( 'faq', $results[0]['type'] );
	}

	/**
	 * Draft and password-protected posts must never be searchable, matching
	 * the glossary index's public-only rule.
	 */
	public function test_results_excludes_non_public_posts() {
		$this->create_post( 'saai_faq', 'Widget draft', array( 'post_status' => 'draft' ) );
		$this->create_post( 'saai_faq', 'Widget locked', array( 'post_password' => 'secret' ) );
		$published_id = $this->create_post( 'saai_faq', 'Widget public' );

		$results = $this->search->results( 'Widget', array( 'faq' ), 10 );

		$this->assertCount( 1, $results );
		$this->assertSame( $published_id, $results[0]['id'] );
	}

	/**
	 * A saai_search_query_args callback must not be able to widen this
	 * public, unauthenticated endpoint past publish/non-password-protected
	 * content — docs/DESIGN.md section 4.4 fixes post_status to 'publish'.
	 */
	public function test_results_query_args_filter_cannot_widen_visibility() {
		$this->create_post( 'saai_faq', 'Widget secret draft', array( 'post_status' => 'draft' ) );
		$this->create_post( 'saai_faq', 'Widget secret locked', array( 'post_password' => 'secret' ) );

		$widen_visibility = static function ( array $args ): array {
			$args['post_status']  = 'any';
			$args['has_password'] = null;

			return $args;
		};

		add_filter( 'saai_search_query_args', $widen_visibility );

		$results = $this->search->results( 'Widget', array( 'faq' ), 10 );

		remove_filter( 'saai_search_query_args', $widen_visibility );

		$this->assertSame( array(), $results );
	}

	/**
	 * The per_page param should cap the number of results returned.
	 */
	public function test_results_respects_per_page() {
		$this->create_post( 'saai_faq', 'Widget one' );
		$this->create_post( 'saai_faq', 'Widget two' );
		$this->create_post( 'saai_faq', 'Widget three' );

		$results = $this->search->results( 'Widget', array( 'faq' ), 2 );

		$this->assertCount( 2, $results );
	}

	/**
	 * The saai_search_query_args filter should be able to further constrain the query.
	 */
	public function test_results_query_args_filter_can_constrain_query() {
		$this->create_post( 'saai_faq', 'Widget one' );
		$this->create_post( 'saai_faq', 'Widget two' );

		$force_single_result = static function ( array $args ): array {
			$args['posts_per_page'] = 1;

			return $args;
		};

		add_filter( 'saai_search_query_args', $force_single_result );

		$results = $this->search->results( 'Widget', array( 'faq' ), 10 );

		remove_filter( 'saai_search_query_args', $force_single_result );

		$this->assertCount( 1, $results );
	}

	/**
	 * The saai_search_results filter should be able to post-process the result set
	 * (e.g. the paid add-on annotating product-linked entries).
	 */
	public function test_results_results_filter_can_post_process_results() {
		$this->create_post( 'saai_faq', 'Widget one' );

		$tag_results = static function ( array $results ): array {
			foreach ( $results as $index => $result ) {
				$results[ $index ]['tagged'] = true;
			}

			return $results;
		};

		add_filter( 'saai_search_results', $tag_results );

		$results = $this->search->results( 'Widget', array( 'faq' ), 10 );

		remove_filter( 'saai_search_results', $tag_results );

		$this->assertTrue( $results[0]['tagged'] );
	}

	/**
	 * The memoized post_types() result must not survive past a single
	 * handle_request() call: this Search instance is the same long-lived
	 * object across every
	 * request on a persistent worker (Swoole/FrankenPHP/WP-CLI), so a stale
	 * cache would keep serving the first request's saai_search_post_types
	 * snapshot indefinitely.
	 */
	public function test_handle_request_does_not_leak_post_types_cache_across_dispatches() {
		$first_request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/search' );
		$first_request->set_param( 'query', 'Widget' );

		$this->search->handle_request( $first_request );

		$add_extra_type = static function ( array $types ): array {
			// Reuses the faq post_type under a new key; avoids depending on
			// a post type this test suite doesn't otherwise register.
			$types['extra'] = array(
				'post_type' => 'saai_faq',
				'label'     => 'Extra',
			);

			return $types;
		};

		add_filter( 'saai_search_post_types', $add_extra_type );

		$this->create_post( 'saai_faq', 'Widget extra' );

		$second_request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/search' );
		$second_request->set_param( 'query', 'Widget' );
		$second_request->set_param( 'types', 'extra' );

		$response = $this->search->handle_request( $second_request );

		remove_filter( 'saai_search_post_types', $add_extra_type );

		$this->assertCount( 1, $response->get_data() );
		$this->assertSame( 'extra', $response->get_data()[0]['type'] );
	}

	/**
	 * The REST route should be registered, public, and GET-only.
	 */
	public function test_rest_route_is_registered_and_public() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/saai-knowledge/v1/search', $routes );

		$route = $routes['/saai-knowledge/v1/search'][0];

		$this->assertSame( array( 'GET' => true ), $route['methods'] );
		$this->assertSame( '__return_true', $route['permission_callback'] );
	}

	/**
	 * A REST request with no `types` param should search every registered type.
	 */
	public function test_rest_request_defaults_to_all_types() {
		$this->create_post( 'saai_faq', 'Widget refund policy' );
		$this->create_post( 'saai_kb', 'Widget setup guide' );

		$request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/search' );
		$request->set_param( 'query', 'Widget' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $response->get_data() );
	}

	/**
	 * A REST request naming one type should only search that type.
	 */
	public function test_rest_request_filters_by_types_param() {
		$this->create_post( 'saai_faq', 'Widget refund policy' );
		$this->create_post( 'saai_kb', 'Widget setup guide' );

		$request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/search' );
		$request->set_param( 'query', 'Widget' );
		$request->set_param( 'types', 'faq' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'faq', $data[0]['type'] );
	}

	/**
	 * A request missing the required `query` param should fail validation.
	 */
	public function test_rest_request_requires_query_param() {
		$request  = new WP_REST_Request( 'GET', '/saai-knowledge/v1/search' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * A per_page value above the endpoint's documented maximum should be rejected.
	 */
	public function test_rest_request_rejects_per_page_above_maximum() {
		$request = new WP_REST_Request( 'GET', '/saai-knowledge/v1/search' );
		$request->set_param( 'query', 'Widget' );
		$request->set_param( 'per_page', 500 );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}
}
