<?php
/**
 * REST search endpoint spanning FAQ, KB, and glossary content.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Registers GET /saai-knowledge/v1/search and resolves its results.
 *
 * See docs/DESIGN.md section 4.4 and docs/DESIGN-HOOKS-API.md section 3.3.
 */
final class Search {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private const NAMESPACE_ROUTE = 'saai-knowledge/v1';

	/**
	 * REST route.
	 *
	 * @var string
	 */
	private const ROUTE = '/search';

	/**
	 * Default `per_page` when the request omits it.
	 *
	 * @var int
	 */
	private const DEFAULT_PER_PAGE = 10;

	/**
	 * Upper bound on `per_page`, regardless of what the request asks for.
	 *
	 * @var int
	 */
	private const MAX_PER_PAGE = 20;

	/**
	 * Hooks route registration into WordPress.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the search route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_ROUTE,
			self::ROUTE,
			array(
				// A numeric-keyed handler entry, with 'schema' as a sibling
				// route option: register_rest_route() only special-cases a
				// bare `array( 'callback' => ..., 'schema' => ... )` shape by
				// hoisting 'args' out before wrapping it as the single
				// handler — 'schema' has no such hoisting, so nesting it
				// alongside 'callback' would bury it inside the handler
				// entry, where WP_REST_Server::get_data_for_route() never
				// looks (it reads route_options, populated only from
				// non-numeric siblings of the handler array).
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_request' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'query'    => array(
							'description'       => __( 'Search query string.', 'saai-knowledge' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'types'    => array(
							'description'       => __( 'Comma-separated content types to search (e.g. faq,kb,glossary). Defaults to all registered types.', 'saai-knowledge' ),
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'per_page' => array(
							'description'       => __( 'Maximum number of results to return.', 'saai-knowledge' ),
							'type'              => 'integer',
							'default'           => self::DEFAULT_PER_PAGE,
							'minimum'           => 1,
							'maximum'           => self::MAX_PER_PAGE,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				'schema' => array( $this, 'item_schema' ),
			)
		);
	}

	/**
	 * REST callback: resolves the request's params and returns results.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
		$query    = (string) $request->get_param( 'query' );
		$per_page = (int) $request->get_param( 'per_page' );

		try {
			$types = $this->requested_type_keys( (string) $request->get_param( 'types' ) );

			return rest_ensure_response( $this->results( $query, $types, $per_page ) );
		} finally {
			// post_types_cache is scoped to this one dispatch (see its own
			// docblock): this instance is the same long-lived object across
			// every request on a persistent worker (Swoole/FrankenPHP/WP-CLI),
			// since Plugin::register_services() constructs it once at boot and
			// binds handle_request() to it via add_action(). Without clearing
			// this, the first request's saai_search_post_types snapshot (and
			// its translated labels) would silently outlive that request.
			$this->post_types_cache = null;
		}
	}

	/**
	 * Memoized post_types() result, scoped to a single handle_request()
	 * dispatch (cleared there in a finally block) so that one call's
	 * requested_type_keys() and results() agree on the registered set even
	 * if the saai_search_post_types callback behaves inconsistently across
	 * calls (e.g. a stateful callback that unhooks itself after running
	 * once) — without the cache outliving that request.
	 *
	 * @var array<string, array{post_type: string, label: string}>|null
	 */
	private ?array $post_types_cache = null;

	/**
	 * Registered searchable content types, keyed by the value the `types`
	 * request param accepts.
	 *
	 * @return array<string, array{post_type: string, label: string}>
	 */
	public function post_types(): array {
		if ( null !== $this->post_types_cache ) {
			return $this->post_types_cache;
		}

		$default_types = array(
			'faq'      => array(
				'post_type' => 'saai_faq',
				'label'     => __( 'FAQ', 'saai-knowledge' ),
			),
			'kb'       => array(
				'post_type' => 'saai_kb',
				'label'     => __( 'Knowledge Base', 'saai-knowledge' ),
			),
			'glossary' => array(
				'post_type' => 'saai_glossary',
				'label'     => __( 'Glossary', 'saai-knowledge' ),
			),
		);

		/**
		 * Filters the content types the search endpoint can query.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, array{post_type: string, label: string}> $default_types Registered types, keyed by the `types` request param value.
		 */
		$types = apply_filters( 'saai_search_post_types', $default_types );

		// @phpstan-ignore ternary.elseUnreachable (PHPStan trusts the docblock @param type above, but a third-party saai_search_post_types callback can violate it at runtime.)
		$this->post_types_cache = is_array( $types ) ? $types : $default_types;

		return $this->post_types_cache;
	}

	/**
	 * Resolves the `types` request param into known type keys. An empty (or
	 * entirely unrecognized) param means "search everything registered";
	 * an explicit list of unrecognized keys means "search nothing" rather
	 * than silently widening back out to every type.
	 *
	 * @param string $raw Raw comma-separated `types` request param.
	 * @return string[]
	 */
	private function requested_type_keys( string $raw ): array {
		$known = array_keys( $this->post_types() );
		$raw   = trim( $raw );

		if ( '' === $raw ) {
			return $known;
		}

		$requested = array_filter( array_map( 'sanitize_key', explode( ',', $raw ) ) );

		return array_values( array_intersect( $known, $requested ) );
	}

	/**
	 * Runs the search and returns the minimal result set.
	 *
	 * @param string   $query    Search query string.
	 * @param string[] $types    Type keys to search, see post_types(). Empty means no results.
	 * @param int      $per_page Maximum number of results.
	 * @return array<int, array{id: int, type: string, title: string, url: string, excerpt: string}>
	 */
	public function results( string $query, array $types, int $per_page ): array {
		$query = trim( $query );

		if ( '' === $query || ! $types ) {
			return array();
		}

		$type_map      = $this->post_types();
		$post_type_map = array();

		foreach ( $types as $type_key ) {
			$entry = $type_map[ $type_key ] ?? null;

			// @phpstan-ignore nullCoalesce.offset (PHPStan trusts post_types()'s docblock @return type, but a third-party saai_search_post_types callback can violate it at runtime.)
			if ( is_array( $entry ) && is_string( $entry['post_type'] ?? null ) && '' !== $entry['post_type'] ) {
				$post_type_map[ $entry['post_type'] ] = $type_key;
			}
		}

		if ( ! $post_type_map ) {
			return array();
		}

		$query_args = array(
			's'                   => $query,
			'post_type'           => array_keys( $post_type_map ),
			'post_status'         => 'publish',
			'has_password'        => false,
			'posts_per_page'      => max( 1, min( $per_page, self::MAX_PER_PAGE ) ),
			'orderby'             => 'relevance',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		);

		/**
		 * Filters the WP_Query args the search endpoint runs.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $query_args Query args.
		 * @param string               $query      Search query string.
		 * @param string[]             $types      Requested type keys.
		 */
		$query_args = apply_filters( 'saai_search_query_args', $query_args, $query, $types );

		// @phpstan-ignore ternary.elseUnreachable (PHPStan trusts the docblock @param type above, but a third-party saai_search_query_args callback can violate it at runtime.)
		$query_args = is_array( $query_args ) ? $query_args : array();

		// Re-pin after the filter: this is a public, unauthenticated REST
		// endpoint (docs/DESIGN.md section 4.4 fixes post_status to
		// 'publish'), so a saai_search_query_args callback widening these —
		// even unintentionally — would leak draft/private/password-protected
		// titles and excerpts to anonymous requests.
		$query_args['post_status']  = 'publish';
		$query_args['has_password'] = false;

		$wp_query = new \WP_Query( $query_args );

		$results = array();

		foreach ( $wp_query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			// Defensive: a saai_search_query_args filter could have added post
			// types outside the requested set.
			$type_key = $post_type_map[ $post->post_type ] ?? '';

			if ( '' === $type_key ) {
				continue;
			}

			// get_the_title()/excerpt_for() encode characters as HTML
			// references (the_title/get_the_excerpt filters); decode them
			// since this is plain-text JSON consumed via JS textContent, not
			// an HTML sink (see class-glossary-term.php for the same pattern
			// applied to JSON-LD output).
			$results[] = array(
				'id'      => $post->ID,
				'type'    => $type_key,
				'title'   => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ),
				'url'     => (string) get_permalink( $post ),
				'excerpt' => self::excerpt_for( $post ),
			);
		}

		/**
		 * Filters the search endpoint's results.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int, array{id: int, type: string, title: string, url: string, excerpt: string}> $results Results, all values pre-escaping.
		 * @param string                                                                                 $query   Search query string.
		 * @param string[]                                                                               $types   Requested type keys.
		 */
		$results = apply_filters( 'saai_search_results', $results, $query, $types );

		// @phpstan-ignore ternary.elseUnreachable (PHPStan trusts the docblock @param type above, but a third-party saai_search_results callback can violate it at runtime.)
		return is_array( $results ) ? $results : array();
	}

	/**
	 * A plain-text search-result excerpt for a post: its manual excerpt if
	 * set, otherwise the first 55 words of its raw content — the same
	 * has_excerpt()-gated fallback Autolinker::entry_excerpt() uses.
	 *
	 * Deliberately not get_the_excerpt() unconditionally: without a manual
	 * excerpt, that runs the post's content through the full `the_content`
	 * pipeline (do_blocks(), wpautop(), do_shortcode(), every third-party
	 * the_content filter — including this plugin's own Autolinker) merely to
	 * discard everything past the first 55 words. This instant-search
	 * endpoint can be dispatched once per keystroke, up to MAX_PER_PAGE times
	 * per request; wp_trim_words() on the raw content directly (only ever
	 * stripping tags, never rendering them) reaches the same-length result
	 * without that cost (perf review).
	 *
	 * @param \WP_Post $post Result post.
	 * @return string
	 */
	private static function excerpt_for( \WP_Post $post ): string {
		$text = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( $post->post_content, 55 );

		return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * JSON Schema for the response: an array of result items, exposed via
	 * the route's `schema` callback (OPTIONS discovery).
	 *
	 * @return array<string, mixed>
	 */
	public function item_schema(): array {
		return array(
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			'title'   => 'saai-knowledge-search-results',
			'type'    => 'array',
			'items'   => array(
				'type'       => 'object',
				'properties' => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => __( 'Post ID.', 'saai-knowledge' ),
					),
					'type'    => array(
						'type'        => 'string',
						'description' => __( 'Content type key, see the types request param.', 'saai-knowledge' ),
					),
					'title'   => array(
						'type'        => 'string',
						'description' => __( 'Post title.', 'saai-knowledge' ),
					),
					'url'     => array(
						'type'        => 'string',
						'format'      => 'uri',
						'description' => __( 'Permalink.', 'saai-knowledge' ),
					),
					'excerpt' => array(
						'type'        => 'string',
						'description' => __( 'Plain-text excerpt.', 'saai-knowledge' ),
					),
				),
			),
		);
	}
}
