<?php
/**
 * RAG export: a REST endpoint plus an admin download screen for feeding
 * FAQ/KB/glossary content into a customer-support AI pipeline.
 *
 * @package SAAI\Knowledge
 */

namespace SAAI\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Registers GET /saai-knowledge/v1/export and the "RAG Export" admin screen
 * (JSONL/CSV download). See docs/DESIGN.md section 7.4 and
 * docs/DESIGN-HOOKS-API.md section 3.4.
 */
final class Export {

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
	private const ROUTE = '/export';

	/**
	 * Default `per_page` when the request omits it.
	 *
	 * @var int
	 */
	private const DEFAULT_PER_PAGE = 50;

	/**
	 * Upper bound on `per_page` for the paginated REST endpoint.
	 *
	 * @var int
	 */
	private const MAX_PER_PAGE = 200;

	/**
	 * Upper bound on how many records the one-shot admin download pulls in a
	 * single query. A site with more published items than this should use
	 * the paginated REST endpoint (page/per_page) instead — same "cap
	 * instead of posts_per_page => -1" reasoning as Llms_Index::MAX_ITEMS_PER_TYPE.
	 *
	 * @var int
	 */
	private const MAX_DOWNLOAD_ITEMS = 5000;

	/**
	 * The admin_post_{action} hook name for the download screen's links.
	 *
	 * @var string
	 */
	private const DOWNLOAD_ACTION = 'saai_export_download';

	/**
	 * The nonce action name the download links are signed with, checked in
	 * handle_download() via check_admin_referer().
	 *
	 * @var string
	 */
	private const DOWNLOAD_NONCE = 'saai_export_download_nonce';

	/**
	 * The admin submenu page slug, nested under Settings::PAGE_SLUG.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'saai-knowledge-export';

	/**
	 * The globals WP_Query::setup_postdata() mutates besides $post — same
	 * list, same reasoning, as Markdown_Output::POSTDATA_GLOBALS. This class
	 * needs its own copy of the snapshot/restore dance (rather than only
	 * relying on Markdown_Output::render_cached()'s internal one) because
	 * build_record() also runs its own separate `the_content` pass for
	 * content_plain/sections, which the_content's shortcode/embed handlers
	 * can just as easily depend on the postdata globals for.
	 *
	 * @var string[]
	 */
	private const POSTDATA_GLOBALS = array(
		'id',
		'authordata',
		'currentday',
		'currentmonth',
		'page',
		'pages',
		'multipage',
		'more',
		'numpages',
	);

	/**
	 * Reused for the content_markdown field — see build_record().
	 *
	 * @var Markdown_Output
	 */
	private $markdown_output;

	/**
	 * Constructs the service.
	 */
	public function __construct() {
		$this->markdown_output = new Markdown_Output();
	}

	/**
	 * Hooks the REST route, the JSONL raw-body short-circuit, and (admin-side
	 * only) the download screen into WordPress.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'maybe_serve_jsonl' ), 10, 4 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_post_' . self::DOWNLOAD_ACTION, array( $this, 'handle_download' ) );
		}
	}

	/**
	 * Registers the export route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_ROUTE,
			self::ROUTE,
			array(
				// See Search::register_routes()'s docblock for why 'schema'
				// is a sibling of this numeric-keyed handler entry rather
				// than nested inside it.
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_request' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'types'          => array(
							'description'       => __( 'Comma-separated content types to export (faq,kb,glossary). Defaults to all.', 'saai-knowledge' ),
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'format'         => array(
							'description'       => __( 'Response format: json (a paginated envelope) or jsonl (raw newline-delimited records, one JSON object per line).', 'saai-knowledge' ),
							'type'              => 'string',
							'enum'              => array( 'json', 'jsonl' ),
							'default'           => 'json',
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'modified_after' => array(
							'description'       => __( 'Only include content modified after this ISO 8601 date-time, for incremental sync.', 'saai-knowledge' ),
							'type'              => 'string',
							'format'            => 'date-time',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
							// format=>'date-time' would otherwise also apply
							// to the '' default itself once this param is
							// actually present-but-empty in a request (e.g.
							// `?modified_after=`) — rest_validate_request_arg()
							// rejects '' against that format, so an explicit
							// empty value must be allowed through here first.
							'validate_callback' => static function ( $value, $request, $param ) {
								return '' === $value || true === rest_validate_request_arg( $value, $request, $param );
							},
						),
						'page'           => array(
							'description'       => __( 'Page number.', 'saai-knowledge' ),
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'per_page'       => array(
							'description'       => __( 'Records per page.', 'saai-knowledge' ),
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
	 * REST callback: resolves the request's params, queries matching
	 * records, and returns a paginated envelope.
	 *
	 * For `format=jsonl`, this same envelope is what maybe_serve_jsonl()
	 * reads its records back out of; the envelope itself is only actually
	 * sent to the client for `format=json`.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
		$type_keys      = $this->resolve_type_keys( (string) $request->get_param( 'types' ) );
		$modified_after = (string) $request->get_param( 'modified_after' );
		$format         = (string) $request->get_param( 'format' );
		$page           = max( 1, (int) $request->get_param( 'page' ) );
		$per_page       = (int) $request->get_param( 'per_page' );

		$result = $this->query_records( $type_keys, $modified_after, $page, $per_page, $format );

		$response = rest_ensure_response(
			array(
				'page'        => $page,
				'per_page'    => $per_page,
				'total_items' => $result['total_items'],
				'total_pages' => $result['total_pages'],
				'records'     => $result['records'],
			)
		);

		$response->header( 'X-WP-Total', (string) $result['total_items'] );
		$response->header( 'X-WP-TotalPages', (string) $result['total_pages'] );

		return $response;
	}

	/**
	 * Short-circuits the REST response body for `format=jsonl`: emits the
	 * records this route already computed (via handle_request(), reached
	 * through $result's response data) as raw newline-delimited JSON —
	 * "1 line = 1 record", the standard input format for an embedding
	 * pipeline — instead of core's own JSON-encoded envelope.
	 *
	 * By the time `rest_pre_serve_request` fires, WP_REST_Server::serve_request()
	 * has already sent a `Content-Type: application/json` header and this
	 * response's status code, but has not echoed a body yet — so overriding
	 * the Content-Type here (PHP's header() replaces a same-name header by
	 * default) and echoing our own body, then returning true to mark the
	 * request "already served", is the documented extension point for a
	 * non-JSON REST response body.
	 *
	 * @param bool                                $served  Whether the request has already been served.
	 * @param \WP_HTTP_Response|\WP_REST_Response $result  The response about to be served.
	 * @param \WP_REST_Request                    $request Request object.
	 * @param \WP_REST_Server                     $server  Server instance.
	 * @return bool
	 */
	public function maybe_serve_jsonl( $served, $result, \WP_REST_Request $request, \WP_REST_Server $server ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $server kept to match the rest_pre_serve_request filter signature.
		if ( $served ) {
			return $served;
		}

		if ( '/' . self::NAMESPACE_ROUTE . self::ROUTE !== $request->get_route() ) {
			return $served;
		}

		if ( 'jsonl' !== (string) $request->get_param( 'format' ) ) {
			return $served;
		}

		if ( ! $result instanceof \WP_REST_Response ) {
			return $served;
		}

		$data    = $result->get_data();
		$records = is_array( $data ) && isset( $data['records'] ) && is_array( $data['records'] ) ? $data['records'] : array();

		// headers_sent() is false for every real request at this point in
		// WP_REST_Server::serve_request() (no body has been echoed yet); the
		// guard only matters for a PHP CLI/test context where some earlier,
		// unrelated output already started the response body.
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/x-ndjson; charset=utf-8' );
		}

		foreach ( $records as $record ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- newline-delimited JSON body, not HTML.
			echo wp_json_encode( $record ) . "\n";
		}

		return true;
	}

	/**
	 * Resolves the `types` request param into known type keys, same
	 * "empty/unrecognized means everything registered, an explicit
	 * unrecognized list means nothing" semantics as
	 * Search::requested_type_keys() — but against this class's own fixed
	 * three types rather than a filterable set (docs/DESIGN.md section 7.4
	 * scopes RAG export to FAQ/KB/glossary, same as Llms_Index).
	 *
	 * @param string $raw Raw comma-separated `types` request param.
	 * @return string[]
	 */
	private function resolve_type_keys( string $raw ): array {
		$known = array_keys( $this->post_types() );
		$raw   = trim( $raw );

		if ( '' === $raw ) {
			return $known;
		}

		$requested = array_filter( array_map( 'sanitize_key', explode( ',', $raw ) ) );

		return array_values( array_intersect( $known, $requested ) );
	}

	/**
	 * The three content types this endpoint covers, keyed by the value the
	 * `types` request param accepts.
	 *
	 * @return array<string, string> Type key => post type.
	 */
	private function post_types(): array {
		return array(
			'faq'      => 'saai_faq',
			'kb'       => 'saai_kb',
			'glossary' => 'saai_glossary',
		);
	}

	/**
	 * Runs the export query and builds one record per matching post.
	 *
	 * @param string[] $type_keys      Type keys to include, see post_types(). Empty means no results.
	 * @param string   $modified_after ISO 8601 date-time, or '' for no lower bound.
	 * @param int      $page           1-based page number.
	 * @param int      $per_page       Records per page.
	 * @param string   $format         'json'/'jsonl' (REST) or 'csv' (admin download) — passed through to the saai_export_record filter.
	 * @return array{records: array<int, array<string, mixed>>, total_items: int, total_pages: int}
	 */
	private function query_records( array $type_keys, string $modified_after, int $page, int $per_page, string $format ): array {
		$type_map      = $this->post_types();
		$post_type_map = array();

		foreach ( $type_keys as $type_key ) {
			if ( isset( $type_map[ $type_key ] ) ) {
				$post_type_map[ $type_map[ $type_key ] ] = $type_key;
			}
		}

		if ( ! $post_type_map ) {
			return array(
				'records'     => array(),
				'total_items' => 0,
				'total_pages' => 0,
			);
		}

		$query_args = array(
			'post_type'           => array_keys( $post_type_map ),
			'post_status'         => 'publish',
			'has_password'        => false,
			'posts_per_page'      => max( 1, $per_page ),
			'paged'               => max( 1, $page ),
			// A secondary ID tiebreaker keeps pagination stable across pages
			// when two posts share the same post_modified (a real
			// possibility — e.g. both untouched since a bulk import).
			'orderby'             => array(
				'modified' => 'ASC',
				'ID'       => 'ASC',
			),
			'no_found_rows'       => false,
			'ignore_sticky_posts' => true,
		);

		if ( '' !== $modified_after ) {
			$query_args['date_query'] = array(
				array(
					'column'    => 'post_modified_gmt',
					'after'     => $modified_after,
					'inclusive' => false,
				),
			);
		}

		$wp_query = new \WP_Query( $query_args );
		$records  = array();

		foreach ( $wp_query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$type_key = $post_type_map[ $post->post_type ] ?? '';

			if ( '' === $type_key ) {
				continue;
			}

			$records[] = $this->build_record( $post, $type_key, $format );
		}

		return array(
			'records'     => $records,
			'total_items' => (int) $wp_query->found_posts,
			'total_pages' => (int) $wp_query->max_num_pages,
		);
	}

	/**
	 * Builds one export record (docs/DESIGN.md section 7.4).
	 *
	 * @param \WP_Post $post     The post to export.
	 * @param string   $type_key 'faq'/'kb'/'glossary'.
	 * @param string   $format   'json'/'jsonl'/'csv' — passed through to the saai_export_record filter.
	 * @return array<string, mixed>
	 */
	private function build_record( \WP_Post $post, string $type_key, string $format ): array {
		$previous_post    = $GLOBALS['post'] ?? null;
		$previous_globals = array();

		foreach ( self::POSTDATA_GLOBALS as $var ) {
			$previous_globals[ $var ] = $GLOBALS[ $var ] ?? null;
		}

		setup_postdata( $post );

		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WordPress core's own the_content filter (same as Markdown_Output::render()), not defining a new hook.
			$content_html = apply_filters( 'the_content', $post->post_content );
			$content_html = is_string( $content_html ) ? $content_html : '';

			$record = array(
				'id'               => $post->ID,
				'type'             => $type_key,
				'title'            => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ),
				// Reuses Markdown_Output's own (cached) rendering rather than
				// converting $content_html a second time — its class
				// docblock documents this exact reuse. The self-contained
				// "# Title" heading it includes is a deliberate, harmless
				// duplication of the `title` field above: many embedding
				// pipelines expect each chunk of text to carry its own
				// context rather than relying on a sibling JSON field.
				'content_markdown' => $this->markdown_output->render_cached( $post ),
				'content_plain'    => Markdown_Converter::to_plain_text( $content_html ),
				'categories'       => $this->term_names( $post, 'saai_category' ),
				'tags'             => $this->term_names( $post, 'saai_tag' ),
				'url'              => (string) get_permalink( $post ),
				'updated_at'       => mysql_to_rfc3339( $post->post_modified_gmt ),
			);

			if ( 'kb' === $type_key ) {
				$record['sections'] = $this->build_sections( $post, $content_html );
			}
		} finally {
			$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the exact pre-render value saved above.

			foreach ( $previous_globals as $var => $value ) {
				$GLOBALS[ $var ] = $value; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- restoring the exact pre-render values of WordPress's own postdata globals saved above.
			}
		}

		/**
		 * Filters one RAG export record. The paid add-on uses this to attach
		 * product ID/SKU/category metadata (docs/DESIGN-HOOKS-API.md section 3.4).
		 *
		 * @since 0.7.0
		 *
		 * @param array<string, mixed> $record  The record, shape per docs/DESIGN.md section 7.4.
		 * @param \WP_Post              $post   The post the record was built from.
		 * @param string               $format 'json', 'jsonl', or 'csv'.
		 */
		$filtered = apply_filters( 'saai_export_record', $record, $post, $format );

		// @phpstan-ignore ternary.elseUnreachable (PHPStan trusts the docblock @param type above, but a third-party saai_export_record callback can violate it at runtime.)
		return is_array( $filtered ) ? $filtered : $record;
	}

	/**
	 * Splits a KB article's rendered content into per-h2 chunks for the
	 * export record's `sections` field — deliberately not applied to
	 * FAQ/glossary, whose single-answer/single-definition content is already
	 * one coherent chunk (docs/DESIGN.md section 7.4).
	 *
	 * Known limitation: only an `<h2>` that renders as a direct child of the
	 * document body starts a new section — the common case for a flat KB
	 * article built from top-level core blocks. An h2 nested inside a
	 * wrapper block (Group, Columns) is not detected, the same class of
	 * positional limitation Heading_Anchors documents for its own tag walk.
	 * A section's `anchor` is matched positionally against
	 * Heading_Anchors::extract()'s level-2 headings (in the same document
	 * order), so it agrees with the id add_anchors() would inject into that
	 * same heading on the real front-end page — matching_anchor() only trusts
	 * that positional match when the two headings' text actually agree
	 * (guarding against the position drifting once a nested h2, invisible to
	 * this method's own top-level walk, shifts Heading_Anchors' count),
	 * falling back to a locally derived slug otherwise.
	 *
	 * @param \WP_Post $post         The KB post.
	 * @param string   $content_html Its fully rendered (`the_content`-filtered) HTML.
	 * @return array<int, array{heading: string, anchor: string, content_markdown: string}>
	 */
	private function build_sections( \WP_Post $post, string $content_html ): array {
		if ( '' === trim( $content_html ) || ! class_exists( '\DOMDocument' ) ) {
			return array();
		}

		$dom             = new \DOMDocument();
		$previous_errors = libxml_use_internal_errors( true );
		$loaded          = $dom->loadHTML(
			'<?xml encoding="UTF-8"?><!DOCTYPE html><html><body>' . $content_html . '</body></html>',
			LIBXML_NOERROR | LIBXML_NOWARNING
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );

		if ( ! $loaded ) {
			return array();
		}

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );

		if ( ! $body instanceof \DOMElement ) {
			return array();
		}

		$chunks  = array();
		$current = null;

		foreach ( $body->childNodes as $node ) {
			if ( $node instanceof \DOMElement && 'h2' === strtolower( $node->tagName ) ) {
				if ( null !== $current ) {
					$chunks[] = $current;
				}

				$current = array(
					'heading' => html_entity_decode( trim( wp_strip_all_tags( (string) $dom->saveHTML( $node ) ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ),
					'nodes'   => array(),
				);
				continue;
			}

			if ( null !== $current ) {
				$current['nodes'][] = $node;
			}
		}

		if ( null !== $current ) {
			$chunks[] = $current;
		}

		$level_2_headings = array_values(
			array_filter(
				( new Heading_Anchors() )->extract( $post ),
				static function ( array $heading ): bool {
					return 2 === $heading['level'];
				}
			)
		);

		$sections = array();

		foreach ( $chunks as $index => $chunk ) {
			if ( '' === $chunk['heading'] ) {
				continue;
			}

			$fragment_html = '';

			foreach ( $chunk['nodes'] as $node ) {
				$fragment_html .= (string) $dom->saveHTML( $node );
			}

			$body_markdown = Markdown_Converter::convert( $fragment_html );
			$anchor        = self::matching_anchor( $level_2_headings[ $index ] ?? null, $chunk['heading'] );

			$sections[] = array(
				'heading'          => $chunk['heading'],
				'anchor'           => $anchor,
				'content_markdown' => trim( '## ' . Markdown_Converter::escape_text( $chunk['heading'] ) . "\n\n" . $body_markdown ),
			);
		}

		return $sections;
	}

	/**
	 * Resolves one section's anchor from Heading_Anchors::extract()'s
	 * positionally-matched entry, only trusting it when that entry's heading
	 * text actually agrees with this section's own heading text (both
	 * entity-decoded the same way, since extract()'s 'text' is not itself
	 * decoded). build_sections()' own docblock documents why the position
	 * can drift (a wrapper-block-nested h2 shifts Heading_Anchors' recursive
	 * count without shifting this class's top-level-only walk) — without
	 * this check, a drifted position would silently attribute a *different*
	 * heading's anchor to this section instead of just being absent, which
	 * for a citation-generating RAG pipeline is a worse failure than falling
	 * back to a locally derived (if potentially non-matching-the-live-page)
	 * slug.
	 *
	 * @param array<string, mixed>|null $candidate Heading_Anchors::extract() entry at the same position, if any.
	 * @param string                    $heading   This section's own (decoded) heading text.
	 * @return string
	 */
	private static function matching_anchor( ?array $candidate, string $heading ): string {
		if ( null !== $candidate && isset( $candidate['id'], $candidate['text'] ) ) {
			$candidate_text = html_entity_decode( (string) $candidate['text'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );

			if ( $candidate_text === $heading ) {
				return (string) $candidate['id'];
			}
		}

		return sanitize_title( $heading );
	}

	/**
	 * A post's saai_category/saai_tag term names, decoded plain text. Empty
	 * for saai_glossary, which neither taxonomy applies to (Taxonomies::OBJECT_TYPES).
	 *
	 * @param \WP_Post $post     The post.
	 * @param string   $taxonomy 'saai_category' or 'saai_tag'.
	 * @return string[]
	 */
	private function term_names( \WP_Post $post, string $taxonomy ): array {
		if ( ! in_array( $post->post_type, array( 'saai_faq', 'saai_kb' ), true ) ) {
			return array();
		}

		$terms = get_the_terms( $post, $taxonomy );

		if ( ! is_array( $terms ) || ! $terms ) {
			return array();
		}

		return array_values(
			array_map(
				static function ( $term ) {
					return html_entity_decode( wp_strip_all_tags( $term->name ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
				},
				$terms
			)
		);
	}

	/**
	 * JSON Schema for the export envelope, exposed via the route's `schema`
	 * callback (OPTIONS discovery).
	 *
	 * @return array<string, mixed>
	 */
	public function item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'saai-knowledge-export',
			'type'       => 'object',
			'properties' => array(
				'page'        => array( 'type' => 'integer' ),
				'per_page'    => array( 'type' => 'integer' ),
				'total_items' => array( 'type' => 'integer' ),
				'total_pages' => array( 'type' => 'integer' ),
				'records'     => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'               => array( 'type' => 'integer' ),
							'type'             => array( 'type' => 'string' ),
							'title'            => array( 'type' => 'string' ),
							'content_markdown' => array( 'type' => 'string' ),
							'content_plain'    => array( 'type' => 'string' ),
							'categories'       => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'tags'             => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'url'              => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'updated_at'       => array(
								'type'   => 'string',
								'format' => 'date-time',
							),
							'sections'         => array(
								'description' => __( 'KB records only: per-h2 chunks.', 'saai-knowledge' ),
								'type'        => 'array',
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'heading'          => array( 'type' => 'string' ),
										'anchor'           => array( 'type' => 'string' ),
										'content_markdown' => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Adds the "RAG Export" admin screen under the SAAI Knowledge settings menu.
	 */
	public function register_menu(): void {
		add_submenu_page(
			Settings::PAGE_SLUG,
			__( 'RAG Export', 'saai-knowledge' ),
			__( 'RAG Export', 'saai-knowledge' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the download screen: two links to handle_download(), each
	 * carrying a nonce.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RAG Export', 'saai-knowledge' ); ?></h1>
			<p><?php esc_html_e( 'Download every published FAQ, Knowledge Base, and Glossary entry for use in a custom support AI / RAG pipeline.', 'saai-knowledge' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $this->download_url( 'jsonl' ) ); ?>"><?php esc_html_e( 'Download JSONL', 'saai-knowledge' ); ?></a>
				<a class="button" href="<?php echo esc_url( $this->download_url( 'csv' ) ); ?>"><?php esc_html_e( 'Download CSV', 'saai-knowledge' ); ?></a>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: REST endpoint path. */
					esc_html__( 'For incremental sync, use the REST endpoint (%s) with the modified_after parameter instead of re-downloading everything.', 'saai-knowledge' ),
					'<code>/wp-json/' . esc_html( self::NAMESPACE_ROUTE . self::ROUTE ) . '</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Builds one download link's nonced admin-post.php URL.
	 *
	 * @param string $format 'jsonl' or 'csv'.
	 * @return string
	 */
	private function download_url( string $format ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::DOWNLOAD_ACTION,
					'format' => $format,
				),
				admin_url( 'admin-post.php' )
			),
			self::DOWNLOAD_NONCE
		);
	}

	/**
	 * The admin-post.php handler: streams every published FAQ/KB/glossary
	 * record as a JSONL or CSV file attachment.
	 */
	public function handle_download(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saai-knowledge' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::DOWNLOAD_NONCE );

		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'jsonl';

		if ( ! in_array( $format, array( 'jsonl', 'csv' ), true ) ) {
			wp_die( esc_html__( 'Unsupported export format.', 'saai-knowledge' ), '', array( 'response' => 400 ) );
		}

		// A site with a few thousand real published items, each carrying a
		// full content_markdown/content_plain (and, for KB, sections) body,
		// can take longer to build than a typical host's default
		// max_execution_time — same rationale WooCommerce's own CSV
		// exporters use for calling this before their own bulk queries.
		// Best-effort: silently no-ops under a hosting restriction
		// (open_basedir/safe mode-like setups, or when disabled entirely).
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
		}

		$result = $this->query_records( array_keys( $this->post_types() ), '', 1, self::MAX_DOWNLOAD_ITEMS, $format );

		nocache_headers();
		header( 'Content-Disposition: attachment; filename="saai-knowledge-export-' . gmdate( 'Y-m-d' ) . '.' . $format . '"' );

		if ( 'csv' === $format ) {
			$this->stream_csv( $result['records'] );
		} else {
			header( 'Content-Type: application/x-ndjson; charset=utf-8' );

			foreach ( $result['records'] as $record ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- newline-delimited JSON body, not HTML.
				echo wp_json_encode( $record ) . "\n";
			}
		}

		exit;
	}

	/**
	 * Streams records as CSV to the current output buffer. Nested fields
	 * (categories/tags) are flattened to a "; "-joined string; `sections`
	 * (KB only) is omitted entirely — a flat spreadsheet row has no natural
	 * place for a nested chunk list, which is exactly what the JSONL/JSON
	 * formats are for.
	 *
	 * @param array<int, array<string, mixed>> $records Records, see build_record().
	 */
	private function stream_csv( array $records ): void {
		// See maybe_serve_jsonl()'s identical guard: false for every real
		// request at this point, only relevant when this method is called
		// directly (e.g. from a test) after some earlier, unrelated output
		// already started the response body.
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
		}

		// A UTF-8 BOM makes Excel — still the most common CSV consumer, and
		// this plugin's own content is frequently Japanese — detect the
		// encoding instead of mis-rendering multi-byte text as mojibake.
		echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a raw byte-order-mark, not HTML.

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a CSV directly to the browser response body (php://output), not touching the filesystem; WP_Filesystem has no equivalent stream API.
		$handle = fopen( 'php://output', 'w' );

		if ( false === $handle ) {
			return;
		}

		// PHP 8.4 deprecates omitting $escape (a future version changes its
		// default from "\" to ""); passing "" explicitly here opts in early
		// to that future default, which also happens to be the behavior
		// most other CSV consumers (Excel, Python's csv module) already
		// assume: a field is escaped solely by doubling its enclosure
		// character (RFC 4180), not by a preceding backslash.
		fputcsv( $handle, array( 'id', 'type', 'title', 'content_markdown', 'content_plain', 'categories', 'tags', 'url', 'updated_at' ), ',', '"', '' );

		foreach ( $records as $record ) {
			fputcsv(
				$handle,
				array(
					$record['id'] ?? '',
					$record['type'] ?? '',
					self::escape_csv_formula( $record['title'] ?? '' ),
					self::escape_csv_formula( $record['content_markdown'] ?? '' ),
					self::escape_csv_formula( $record['content_plain'] ?? '' ),
					self::escape_csv_formula( implode( '; ', is_array( $record['categories'] ?? null ) ? $record['categories'] : array() ) ),
					self::escape_csv_formula( implode( '; ', is_array( $record['tags'] ?? null ) ? $record['tags'] : array() ) ),
					$record['url'] ?? '',
					$record['updated_at'] ?? '',
				),
				',',
				'"',
				''
			);
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output stream opened above, not a filesystem handle.
	}

	/**
	 * Neutralizes CSV/DDE formula injection (CWE-1236): title/content/term
	 * names are arbitrary editable strings, and a value starting with `=`,
	 * `+`, `-`, or `@` (or a leading tab/CR) is read as a formula by
	 * Excel/Google Sheets/LibreOffice once this file is opened, regardless
	 * of fputcsv()'s own quoting (which only protects the CSV *syntax*, not
	 * how a spreadsheet app interprets a cell's content). Prefixing a single
	 * quote is the standard mitigation (OWASP CSV Injection cheat sheet):
	 * every affected app renders the cell as literal text instead.
	 *
	 * Deliberately untyped: `saai_export_record` is a public filter, so a
	 * third-party callback could replace `title`/`content_markdown`/etc.
	 * with a non-scalar value (an array, say) — a strict `string` parameter
	 * here would throw a TypeError on that input and fatal the entire CSV
	 * download rather than just that one field, same class of risk this
	 * plugin's other public-filter consumers (e.g. Llms_Index::is_valid_index_item())
	 * already guard against.
	 *
	 * @param mixed $value Raw field value.
	 * @return string
	 */
	private static function escape_csv_formula( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';

		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
