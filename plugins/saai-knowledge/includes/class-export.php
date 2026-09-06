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
	 * relying on Markdown_Output::render()'s internal one) because
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
							'description'       => __( 'Only include content modified at or after this ISO 8601 date-time, for incremental sync. Inclusive: pass back the last updated_at you received to avoid missing a same-second update, and de-duplicate by id.', 'saai-knowledge' ),
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
							// The non-empty case returns rest_validate_request_arg()'s
							// result as-is (a WP_Error on failure) rather than
							// collapsing it to a plain bool: WP_REST_Request::has_valid_params()
							// only surfaces a validate_callback's specific
							// WP_Error message/details to the client when it
							// gets the WP_Error itself — a bare `false` return
							// (which `true === $wp_error` would produce here)
							// falls back to a generic "Invalid parameter."
							// (Copilot review).
							'validate_callback' => static function ( $value, $request, $param ) {
								if ( '' === $value ) {
									return true;
								}

								return rest_validate_request_arg( $value, $request, $param );
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

		$data = $result->get_data();

		// A request rejected by arg validation (e.g. an out-of-range
		// per_page, or a bad modified_after) never reaches handle_request():
		// WP_REST_Server::serve_request() converts the resulting WP_Error into
		// a WP_REST_Response of its own — still an instanceof check above
		// passes — shaped like `{ code, message, data }`, with no `records`
		// key at all. Only intercept a response that actually looks like our
		// own success envelope; anything else (including that error shape)
		// falls through to core's normal JSON serving, which is the only
		// place that error's real code/message ever gets sent (Codex review:
		// this used to unconditionally echo an empty NDJSON body over a 400
		// response, discarding the actual error).
		if ( ! is_array( $data ) || ! isset( $data['records'] ) || ! is_array( $data['records'] ) ) {
			return $served;
		}

		// headers_sent() is false for every real request at this point in
		// WP_REST_Server::serve_request() (no body has been echoed yet); the
		// guard only matters for a PHP CLI/test context where some earlier,
		// unrelated output already started the response body.
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/x-ndjson; charset=utf-8' );
		}

		// HEAD must never carry a body (core's own equivalent suppression —
		// `'HEAD' === $request->get_method() ? return null` — lives inside
		// serve_request()'s `if ( ! $served )` branch, which this callback's
		// own `return true` below always skips; a HEAD request to this route
		// would otherwise get the full NDJSON body core would have withheld
		// for `format=json`, Codex review).
		if ( 'HEAD' !== $request->get_method() ) {
			foreach ( $data['records'] as $record ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- newline-delimited JSON body, not HTML.
				echo self::jsonl_line( $record );
			}
		}

		return true;
	}

	/**
	 * Encodes one record as an NDJSON line ("<json>\n"), or '' if
	 * wp_json_encode() fails (e.g. a field containing invalid UTF-8 —
	 * possible via the public saai_export_record filter, not just this
	 * class's own fields). A bare `wp_json_encode( $record ) . "\n"` would
	 * coerce a `false` return into an empty string, silently emitting a
	 * blank line instead of the record and giving a line-oriented NDJSON
	 * consumer no way to tell a record was dropped versus intentionally
	 * absent; skipping it outright at least keeps every emitted line valid
	 * JSON. Same is_string() guard this plugin already uses for JSON-LD
	 * output (Faq_List::structured_data_signature(), Glossary_Term) (Copilot review).
	 *
	 * @param array<string, mixed> $record One export record.
	 * @return string
	 */
	private static function jsonl_line( array $record ): string {
		$encoded = wp_json_encode( $record );

		return is_string( $encoded ) ? $encoded . "\n" : '';
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
			// A marker read by order_by_modified_gmt() below — not a real
			// WP_Query arg — so that filter only ever touches this specific
			// query, not some unrelated WP_Query that happens to run while
			// it's registered.
			'saai_export_query'   => true,
			'no_found_rows'       => false,
			'ignore_sticky_posts' => true,
		);

		if ( '' !== $modified_after ) {
			$modified_after_timestamp = strtotime( $modified_after );

			if ( false !== $modified_after_timestamp ) {
				// Deliberately inclusive ("modified_after" reads as ">=", not
				// the stricter ">" a caller might expect from the name):
				// post_modified_gmt is only second-precision, and the common
				// incremental-sync pattern of "pass back the last updated_at
				// you received as the next modified_after" would otherwise
				// permanently drop any other post saved within that exact
				// same second (a routine bulk-update scenario, not a rare
				// race) — inclusive=false makes that comparison strict '>',
				// which excludes it forever. The tradeoff this accepts is a
				// client occasionally re-receiving a boundary-second record
				// it already has, which any reasonable upsert-by-id sync
				// consumer already handles idempotently (Codex review).
				//
				// 'after' is an array of explicit UTC components — not the
				// raw RFC 8601 string — because WP_Date_Query::build_mysql_datetime()
				// re-localizes ANY string value (even one with an explicit
				// 'Z'/offset) into the *site's configured timezone* before
				// formatting it back to a bare "Y-m-d H:i:s" string with no
				// timezone marker at all; comparing that re-localized value
				// against a true-UTC post_modified_gmt column silently shifts
				// the effective cutoff by the site's UTC offset (e.g. 9 hours
				// for Asia/Tokyo), permanently missing every post modified in
				// that gap. Passing an array instead bypasses that string
				// branch entirely — WP_Date_Query uses the digits as given,
				// with zero timezone reinterpretation (Codex review).
				$query_args['date_query'] = array(
					array(
						'column'    => 'post_modified_gmt',
						'after'     => array(
							'year'   => (int) gmdate( 'Y', $modified_after_timestamp ),
							'month'  => (int) gmdate( 'n', $modified_after_timestamp ),
							'day'    => (int) gmdate( 'j', $modified_after_timestamp ),
							'hour'   => (int) gmdate( 'G', $modified_after_timestamp ),
							'minute' => (int) gmdate( 'i', $modified_after_timestamp ),
							'second' => (int) gmdate( 's', $modified_after_timestamp ),
						),
						'inclusive' => true,
					),
				);
			}
		}

		// WP_Query's own 'orderby' has no built-in option for the
		// post_modified_gmt column (parse_orderby()'s $allowed_keys only
		// recognizes 'modified', i.e. the site-local post_modified) — but
		// modified_after/updated_at both compare against post_modified_gmt,
		// so sorting by anything else risks disagreeing with them. On a
		// site observing DST (e.g. Europe/*), post_modified's UTC offset
		// changes across a transition, so two posts can carry the exact
		// same local post_modified wall-clock value despite being modified
		// an hour apart in true UTC terms — sorting by post_modified alone
		// can't tell them apart in the correct order, which risks page
		// results not being monotonically increasing by updated_at
		// (Copilot review). Priority 20 (rather than the default 10) is a
		// defensive measure in case some other plugin also filters
		// posts_orderby at the default priority for an unrelated reason —
		// this needs to be the final word for this one query.
		add_filter( 'posts_orderby', array( $this, 'order_by_modified_gmt' ), 20, 2 );

		try {
			$wp_query = new \WP_Query( $query_args );
		} finally {
			remove_filter( 'posts_orderby', array( $this, 'order_by_modified_gmt' ), 20 );
		}

		$records = array();

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
	 * The `posts_orderby` override query_records() registers around its own
	 * WP_Query call only (see that method) — sorts by post_modified_gmt (the
	 * column modified_after/updated_at both actually compare against),
	 * ASC, with ID ASC as a secondary tiebreaker for two posts sharing the
	 * exact same modified_gmt second (a real possibility — e.g. both
	 * untouched since a bulk import).
	 *
	 * @param string    $orderby The ORDER BY clause core built from the query's own 'orderby' arg.
	 * @param \WP_Query $query   The query being filtered.
	 * @return string
	 */
	public function order_by_modified_gmt( string $orderby, \WP_Query $query ): string {
		if ( ! $query->get( 'saai_export_query' ) ) {
			return $orderby;
		}

		global $wpdb;

		return "{$wpdb->posts}.post_modified_gmt ASC, {$wpdb->posts}.ID ASC";
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

		// setup_postdata() (really WP_Query::setup_postdata()) only sets
		// $id/$authordata/etc — it never assigns $GLOBALS['post'] itself
		// (that normally only happens inside WP_Query::the_post()'s own
		// `$post = $this->next_post();`, which nothing in this REST/admin
		// context ever runs). Without this assignment, a shortcode or
		// dynamic block inside the post's content that calls the argument-less
		// get_post() would see whatever post happened to be the stale global
		// from a previous request on the same persistent worker (or none at
		// all), not this one — same fix Faq_List::render_answer() already
		// applies for the identical reason (Copilot/Codex review).
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberately scoping this post as "current" for its own the_content render; restored in the finally block.
		setup_postdata( $post );

		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WordPress core's own the_content filter (same as Markdown_Output::render()), not defining a new hook.
			$content_html = apply_filters( 'the_content', $post->post_content );
			$content_html = is_string( $content_html ) ? $content_html : '';

			$record = array(
				'id'               => $post->ID,
				'type'             => $type_key,
				'title'            => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ),
				// Markdown_Output::render() (the *uncached* method), not
				// render_cached(): the cached variant shares one
				// site-wide-per-post transient with the public
				// `?format=markdown` endpoint, but Autolinker::process_content()
				// unconditionally skips while REST_REQUEST is defined (true
				// for every request through this class's own REST route) or
				// is_admin() (true for the admin-post.php download route
				// too) — so whichever of "a real ?format=markdown visitor" or
				// "this export" happens to populate that shared cache first
				// would silently serve its own (autolinked or not) version to
				// the other for up to a day (Codex review). Calling render()
				// directly here still produces exactly the same content this
				// context always would (Autolinker skips either way, so
				// nothing is lost) without ever writing into the cache the
				// other, autolink-eligible context depends on. The
				// self-contained "# Title" heading it includes is a
				// deliberate, harmless duplication of the `title` field
				// above: many embedding pipelines expect each chunk of text
				// to carry its own context rather than relying on a sibling
				// JSON field. $content_html (already computed above) is
				// passed through so render() doesn't apply `the_content` a
				// second time — a stateful shortcode/dynamic block would
				// otherwise run twice per record, and its output could
				// disagree between this field and content_plain/sections
				// (Codex review).
				'content_markdown' => $this->markdown_output->render( $post, $content_html ),
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
	 * one coherent chunk (docs/DESIGN.md section 7.4). Dispatches to
	 * build_sections_via_dom() when DOMDocument is available (the common
	 * case, and the more faithful one — see that method), falling back to
	 * build_sections_via_blocks() otherwise so this field is never simply
	 * empty on an environment lacking that extension (Codex review).
	 *
	 * Known limitation (both implementations): only an `<h2>` that renders as a direct child of the
	 * document body starts a new section — the common case for a flat KB
	 * article built from top-level core blocks. An h2 nested inside a
	 * wrapper block (Group, Columns) is not detected as a section boundary
	 * here, the same class of positional limitation Heading_Anchors
	 * documents for its own tag walk. A section's `anchor` is matched
	 * positionally against Heading_Anchors::extract()'s *top-level* level-2
	 * headings only (in the same document order), so it agrees with the id
	 * add_anchors() would inject into that same heading on the real
	 * front-end page — restricting the candidate list to top_level headings
	 * is what actually keeps this alignment correct (a nested h2 would
	 * otherwise still occupy a slot in extract()'s full list and shift every
	 * later section's position out of sync with it, even one that happens to
	 * share the same heading text as its neighbor); matching_anchor()'s own
	 * text-agreement check is a second, defensive layer on top of that,
	 * falling back to a locally derived slug on any remaining mismatch.
	 *
	 * @param \WP_Post $post         The KB post.
	 * @param string   $content_html Its fully rendered (`the_content`-filtered) HTML.
	 * @return array<int, array{heading: string, anchor: string, content_markdown: string}>
	 */
	private function build_sections( \WP_Post $post, string $content_html ): array {
		if ( '' === trim( $content_html ) ) {
			return array();
		}

		if ( class_exists( '\DOMDocument' ) ) {
			$sections = $this->build_sections_via_dom( $post, $content_html );

			if ( null !== $sections ) {
				return $sections;
			}
		}

		// No DOMDocument (not a guaranteed extension — Markdown_Converter
		// itself explicitly supports its absence), or it failed to parse
		// this content: parse_blocks() has no such dependency, so KB
		// records still get their documented h2-chunked `sections` instead
		// of silently, indistinguishably losing them on every export from
		// an environment lacking it (Codex review).
		return $this->build_sections_via_blocks( $post );
	}

	/**
	 * The build_sections() primary implementation: walks the fully rendered
	 * DOM. Returns null (rather than an empty array) when the DOM path
	 * itself is inapplicable — content that failed to parse — so the caller
	 * can fall back to build_sections_via_blocks() instead of reporting
	 * "no sections" for content that was never actually examined.
	 *
	 * @param \WP_Post $post         The KB post.
	 * @param string   $content_html Its fully rendered (`the_content`-filtered) HTML, already confirmed non-empty.
	 * @return array<int, array{heading: string, anchor: string, content_markdown: string}>|null
	 */
	private function build_sections_via_dom( \WP_Post $post, string $content_html ): ?array {
		$dom             = new \DOMDocument();
		$previous_errors = libxml_use_internal_errors( true );
		$loaded          = $dom->loadHTML(
			'<?xml encoding="UTF-8"?><!DOCTYPE html><html><body>' . $content_html . '</body></html>',
			LIBXML_NOERROR | LIBXML_NOWARNING
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );

		if ( ! $loaded ) {
			return null;
		}

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );

		if ( ! $body instanceof \DOMElement ) {
			return null;
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

		$level_2_headings = $this->top_level_level_2_headings( $post );
		$sections         = array();

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
	 * The build_sections() no-DOMDocument fallback: splits the post's own
	 * *source* blocks (parse_blocks(), never the rendered HTML) into
	 * per-top-level-h2 chunks and renders each chunk's blocks individually
	 * via render_block(). A lower-fidelity path than build_sections_via_dom()
	 * — it renders each block on its own rather than through the full
	 * `the_content` filter chain (no wptexturize()/wpautop()/shortcode_unautop()
	 * pass across the chunk as a whole) — but produces real, non-empty
	 * sections instead of none at all when DOMDocument is unavailable.
	 *
	 * @param \WP_Post $post The KB post.
	 * @return array<int, array{heading: string, anchor: string, content_markdown: string}>
	 */
	private function build_sections_via_blocks( \WP_Post $post ): array {
		$chunks  = array();
		$current = null;

		foreach ( parse_blocks( $post->post_content ) as $block ) {
			$is_top_level_h2 = 'core/heading' === ( $block['blockName'] ?? null )
				&& 2 === (int) ( $block['attrs']['level'] ?? 2 );

			if ( $is_top_level_h2 ) {
				if ( null !== $current ) {
					$chunks[] = $current;
				}

				$current = array(
					'heading' => html_entity_decode( trim( wp_strip_all_tags( $block['innerHTML'] ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ),
					'blocks'  => array(),
				);
				continue;
			}

			if ( null !== $current ) {
				$current['blocks'][] = $block;
			}
		}

		if ( null !== $current ) {
			$chunks[] = $current;
		}

		$level_2_headings = $this->top_level_level_2_headings( $post );
		$sections         = array();

		foreach ( $chunks as $index => $chunk ) {
			if ( '' === $chunk['heading'] ) {
				continue;
			}

			$fragment_html = '';

			foreach ( $chunk['blocks'] as $block ) {
				$fragment_html .= render_block( $block );
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
	 * The post's level-2 headings, restricted to ones Heading_Anchors::extract()
	 * flags as top_level — shared by both build_sections_via_dom() and
	 * build_sections_via_blocks(), which each independently walk only the
	 * post's top-level h2 boundaries and therefore need this same candidate
	 * list to stay positionally aligned with their own chunks. A heading
	 * nested inside a wrapper block (Group/Columns) is invisible to either
	 * walk, so leaving it in this list would shift every later top-level
	 * section's position out of alignment with it — including defeating
	 * matching_anchor()'s own text-agreement check whenever the nested and
	 * top-level headings happen to share identical wording (Codex review).
	 *
	 * @param \WP_Post $post The KB post.
	 * @return array<int, array{id: string, text: string, level: int, top_level: bool}>
	 */
	private function top_level_level_2_headings( \WP_Post $post ): array {
		return array_values(
			array_filter(
				( new Heading_Anchors() )->extract( $post ),
				static function ( array $heading ): bool {
					return 2 === $heading['level'] && ! empty( $heading['top_level'] );
				}
			)
		);
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
			<p>
				<?php
				printf(
					/* translators: %s: MAX_DOWNLOAD_ITEMS, formatted with thousands separators. */
					esc_html__( 'Download every published FAQ, Knowledge Base, and Glossary entry (up to %s per file) for use in a custom support AI / RAG pipeline.', 'saai-knowledge' ),
					esc_html( number_format_i18n( self::MAX_DOWNLOAD_ITEMS ) )
				);
				?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $this->download_url( 'jsonl' ) ); ?>"><?php esc_html_e( 'Download JSONL', 'saai-knowledge' ); ?></a>
				<a class="button" href="<?php echo esc_url( $this->download_url( 'csv' ) ); ?>"><?php esc_html_e( 'Download CSV', 'saai-knowledge' ); ?></a>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: REST endpoint path. */
					esc_html__( 'For incremental sync, or a site with more items than this download covers, use the REST endpoint (%s) with the page/per_page and modified_after parameters instead.', 'saai-knowledge' ),
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
	 * The admin-post.php handler: streams up to MAX_DOWNLOAD_ITEMS published
	 * FAQ/KB/glossary records as a JSONL or CSV file attachment — a site
	 * with more published items than that gets a silently truncated file
	 * unless it notices the `X-SAAI-Export-Truncated` response header (see
	 * below); render_page()'s own copy points such a site at the REST
	 * endpoint's page/per_page pagination instead (Copilot review: the
	 * docblock previously claimed "every" record unconditionally).
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
			set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		$result = $this->query_records( array_keys( $this->post_types() ), '', 1, self::MAX_DOWNLOAD_ITEMS, $format );

		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="saai-knowledge-export-' . gmdate( 'Y-m-d' ) . '.' . $format . '"' );

		// found_posts (query_records()'s 'total_items') reflects the *true*
		// matching count regardless of the MAX_DOWNLOAD_ITEMS cap applied
		// above — comparing the two here is how a caller that only looks at
		// the downloaded file's own content (with no visibility into how
		// many records actually matched) can detect that it was silently
		// truncated, rather than assuming it received everything (Copilot
		// review).
		if ( $result['total_items'] > self::MAX_DOWNLOAD_ITEMS ) {
			header( 'X-SAAI-Export-Truncated: 1' );
			header( 'X-SAAI-Export-Total-Items: ' . $result['total_items'] );
		}

		if ( 'csv' === $format ) {
			$this->stream_csv( $result['records'] );
		} else {
			header( 'Content-Type: application/x-ndjson; charset=utf-8' );

			foreach ( $result['records'] as $record ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- newline-delimited JSON body, not HTML.
				echo self::jsonl_line( $record );
			}
		}

		exit;
	}

	/**
	 * The CSV column names built directly from every record, in a fixed
	 * order — every other key present on any record becomes an extra
	 * trailing column (see extra_csv_columns()).
	 *
	 * @var string[]
	 */
	private const CSV_BASE_COLUMNS = array( 'id', 'type', 'title', 'content_markdown', 'content_plain', 'categories', 'tags', 'url', 'updated_at' );

	/**
	 * Streams records as CSV to the current output buffer. Nested base
	 * fields (categories/tags) are flattened to a "; "-joined string;
	 * `sections` (KB only) is omitted entirely — a flat spreadsheet row has
	 * no natural place for a nested chunk list, which is exactly what the
	 * JSONL/JSON formats are for. Any *other* key a `saai_export_record`
	 * callback added (the paid add-on's product ID/SKU/category metadata,
	 * per docs/DESIGN-HOOKS-API.md section 3.4) becomes its own trailing
	 * column instead of being silently dropped — the admin CSV download is
	 * documented as carrying "the same content" as JSON/JSONL, which would
	 * otherwise not hold for that metadata (Codex review).
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

		$extra_columns = self::extra_csv_columns( $records );

		// PHP 8.4 deprecates omitting $escape (a future version changes its
		// default from "\" to ""); passing "" explicitly here opts in early
		// to that future default, which also happens to be the behavior
		// most other CSV consumers (Excel, Python's csv module) already
		// assume: a field is escaped solely by doubling its enclosure
		// character (RFC 4180), not by a preceding backslash.
		fputcsv( $handle, array_merge( self::CSV_BASE_COLUMNS, $extra_columns ), ',', '"', '' );

		foreach ( $records as $record ) {
			$row = array(
				$record['id'] ?? '',
				$record['type'] ?? '',
				self::escape_csv_formula( $record['title'] ?? '' ),
				self::escape_csv_formula( $record['content_markdown'] ?? '' ),
				self::escape_csv_formula( $record['content_plain'] ?? '' ),
				// csv_cell_value() (not a bare implode()): saai_export_record
				// is a public filter, so categories/tags aren't guaranteed to
				// stay an array of plain strings — an implode() over an
				// array containing a nested array/object emits a PHP
				// "Array to string conversion" warning, which under
				// WP_DEBUG + display_errors gets echoed straight into this
				// CSV response, corrupting the download (Copilot review).
				self::escape_csv_formula( self::csv_cell_value( $record['categories'] ?? array() ) ),
				self::escape_csv_formula( self::csv_cell_value( $record['tags'] ?? array() ) ),
				$record['url'] ?? '',
				$record['updated_at'] ?? '',
			);

			foreach ( $extra_columns as $column ) {
				$row[] = self::escape_csv_formula( self::csv_cell_value( $record[ $column ] ?? '' ) );
			}

			fputcsv( $handle, $row, ',', '"', '' );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output stream opened above, not a filesystem handle.
	}

	/**
	 * Every key present on any record beyond CSV_BASE_COLUMNS and `sections`
	 * (KB's own nested field, always excluded — see stream_csv()'s
	 * docblock), sorted for a stable, deterministic column order regardless
	 * of which record a given extra key first appears on. Not every record
	 * necessarily carries every extra key (e.g. only product-linked FAQs
	 * might get a `product_id`); stream_csv() fills a row's missing extra
	 * columns with an empty cell.
	 *
	 * @param array<int, array<string, mixed>> $records Records, see build_record().
	 * @return string[]
	 */
	private static function extra_csv_columns( array $records ): array {
		$extra = array();

		foreach ( $records as $record ) {
			foreach ( array_keys( $record ) as $key ) {
				if ( ! in_array( $key, self::CSV_BASE_COLUMNS, true ) && 'sections' !== $key ) {
					$extra[ $key ] = true;
				}
			}
		}

		$columns = array_keys( $extra );
		sort( $columns );

		return $columns;
	}

	/**
	 * Renders one extra (filter-added) field's value as a single CSV cell.
	 * A scalar is used as-is; a flat array of scalars is "; "-joined (same
	 * convention as the built-in categories/tags columns); anything else
	 * (a nested/mixed array, an object) falls back to a JSON-encoded string
	 * rather than risking a PHP "Array to string conversion" or losing the
	 * value's shape entirely.
	 *
	 * @param mixed $value Raw field value.
	 * @return string
	 */
	private static function csv_cell_value( $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		if ( is_array( $value ) && array_filter( $value, 'is_scalar' ) === $value ) {
			return implode( '; ', array_map( 'strval', $value ) );
		}

		if ( is_array( $value ) ) {
			$json = wp_json_encode( $value );

			return is_string( $json ) ? $json : '';
		}

		return '';
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

		// A leading LF ("\n") needs the same guard as tab/CR: nothing in
		// core (sanitize_post_field(), title_save_pre, etc.) strips a
		// newline from a title/content field saved via the REST API, and a
		// spreadsheet app can still read a formula starting after a leading
		// LF as a trigger (Codex review).
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r", "\n" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
