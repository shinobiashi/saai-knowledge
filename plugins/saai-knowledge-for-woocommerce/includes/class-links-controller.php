<?php
/**
 * REST routes backing the product edit screen's reverse-linking meta box.
 *
 * @package SAAI\KnowledgeWoo
 */

namespace SAAI\KnowledgeWoo;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the saai-knowledge-woo/v1 routes that list, add, and remove the
 * links between a product and the free plugin's content types.
 *
 * The content-side editor panel needs no route of its own: the linking meta
 * is REST-exposed by Post_Meta, and products and product categories are
 * searched through core's own /wp/v2/product and /wp/v2/product_cat
 * collections (see docs/DESIGN.md section 6.1 for why those are used instead
 * of WooCommerce's /wc/v3/products).
 */
final class Links_Controller {

	/**
	 * REST namespace for the add-on's own routes.
	 *
	 * @var string
	 */
	public const NAMESPACE_ROUTE = 'saai-knowledge-woo/v1';

	/**
	 * Default `per_page` for the content search.
	 *
	 * @var int
	 */
	private const DEFAULT_PER_PAGE = 10;

	/**
	 * Upper bound on `per_page`, regardless of what the request asks for.
	 *
	 * @var int
	 */
	private const MAX_PER_PAGE = 50;

	/**
	 * Link resolver used for every read and write.
	 *
	 * @var Link_Resolver
	 */
	private $resolver;

	/**
	 * Stores the resolver these routes operate through.
	 *
	 * @param Link_Resolver $resolver Link resolver.
	 */
	public function __construct( Link_Resolver $resolver ) {
		$this->resolver = $resolver;
	}

	/**
	 * Hooks route registration into WordPress.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the add-on's routes.
	 *
	 * Each route uses numeric-keyed handler entries with `schema` as a
	 * sibling route option rather than nesting it beside `callback`:
	 * register_rest_route() only hoists `args` out of a bare single-handler
	 * array, so a nested `schema` would end up buried inside the handler
	 * where WP_REST_Server::get_data_for_route() never looks (the same
	 * reasoning as the free plugin's Search controller).
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_ROUTE,
			'/products/(?P<product_id>[\d]+)/linked-content',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_linked_content' ),
					'permission_callback' => array( $this, 'can_read_product_links' ),
					'args'                => array(
						'product_id' => $this->id_arg( __( 'Product post ID.', 'saai-knowledge-for-woocommerce' ) ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_link' ),
					'permission_callback' => array( $this, 'can_edit_product_links' ),
					'args'                => array(
						'product_id' => $this->id_arg( __( 'Product post ID.', 'saai-knowledge-for-woocommerce' ) ),
						'content_id' => $this->id_arg( __( 'ID of the FAQ, knowledge base article, or glossary term to link.', 'saai-knowledge-for-woocommerce' ) ),
					),
				),
				'schema' => array( $this, 'linked_content_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_ROUTE,
			'/products/(?P<product_id>[\d]+)/linked-content/(?P<content_id>[\d]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_link' ),
					'permission_callback' => array( $this, 'can_edit_product_links' ),
					'args'                => array(
						'product_id' => $this->id_arg( __( 'Product post ID.', 'saai-knowledge-for-woocommerce' ) ),
						'content_id' => $this->id_arg( __( 'ID of the linked content to unlink.', 'saai-knowledge-for-woocommerce' ) ),
					),
				),
				'schema' => array( $this, 'linked_content_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_ROUTE,
			'/content-search',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'search_content' ),
					'permission_callback' => array( $this, 'can_search_content' ),
					'args'                => array(
						'search'    => array(
							'description'       => __( 'Text to search for.', 'saai-knowledge-for-woocommerce' ),
							'type'              => 'string',
							'required'          => true,
							'minLength'         => 1,
							// No sanitize_text_field(): it strips percent-encoded
							// octets (%[a-f0-9]{2}), which would quietly mangle a
							// search for something like "50%ab". The value is only
							// ever handed to WP_Query's `s`, which parameterizes it,
							// and the schema already constrains it to a string.
							'validate_callback' => 'rest_validate_request_arg',
						),
						'post_type' => array(
							'description'       => __( 'Limit results to a single content type.', 'saai-knowledge-for-woocommerce' ),
							'type'              => 'string',
							'default'           => '',
							'enum'              => array_merge( array( '' ), Post_Meta::POST_TYPES ),
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'per_page'  => array(
							'description'       => __( 'Maximum number of results to return.', 'saai-knowledge-for-woocommerce' ),
							'type'              => 'integer',
							'default'           => self::DEFAULT_PER_PAGE,
							'minimum'           => 1,
							'maximum'           => self::MAX_PER_PAGE,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				'schema' => array( $this, 'content_item_schema' ),
			)
		);
	}

	/**
	 * Permission check for reading a product's links.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|\WP_Error
	 */
	public function can_read_product_links( \WP_REST_Request $request ) {
		return $this->check_product( (int) $request->get_param( 'product_id' ) );
	}

	/**
	 * Permission check for adding or removing a link.
	 *
	 * Every validation lives here, including the content post's type, because
	 * the authoritative capability is `edit_post` on the *content* post (the
	 * meta row being written belongs to it, not to the product). Deferring
	 * the type check to the callback would mean running
	 * `current_user_can( 'edit_post', $id )` against an ID that might be a
	 * product, whose separate `edit_products` capability would then be
	 * mistaken for permission to edit content.
	 *
	 * The product capability gates reachability of this route, not the link
	 * itself: the same meta is writable through core's own
	 * `/wp/v2/saai_faq/<id>` with `meta.saai_linked_products`, which asks only
	 * for `edit_post` on the content. Anyone who may edit this plugin's
	 * content may therefore link it to any product, by design — see
	 * docs/DESIGN.md section 6.1. Do not treat `edit_products` as an effective
	 * boundary on where content can appear.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|\WP_Error
	 */
	public function can_edit_product_links( \WP_REST_Request $request ) {
		$product_check = $this->check_product( (int) $request->get_param( 'product_id' ) );

		if ( is_wp_error( $product_check ) ) {
			return $product_check;
		}

		$content_id = (int) $request->get_param( 'content_id' );

		if ( null === get_post( $content_id ) ) {
			return new \WP_Error(
				'saai_woo_invalid_content_id',
				__( 'No content was found with that ID.', 'saai-knowledge-for-woocommerce' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->resolver->is_content_post( $content_id ) ) {
			return new \WP_Error(
				'saai_woo_unsupported_content_type',
				__( 'Only FAQs, knowledge base articles, and glossary terms can be linked to products.', 'saai-knowledge-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		if ( ! current_user_can( 'edit_post', $content_id ) ) {
			return new \WP_Error(
				'saai_woo_cannot_edit_content',
				__( 'Sorry, you are not allowed to edit that content.', 'saai-knowledge-for-woocommerce' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Permission check for the content search.
	 *
	 * The three content types use the standard `post` capability type, so
	 * `edit_posts` is exactly "may edit this plugin's content" — which
	 * WooCommerce's shop_manager role has, alongside editors and admins.
	 *
	 * @return true|\WP_Error
	 */
	public function can_search_content() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'saai_woo_cannot_search_content',
				__( 'Sorry, you are not allowed to browse this content.', 'saai-knowledge-for-woocommerce' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * GET handler: the product's direct and inherited links.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_linked_content( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->linked_content_payload( (int) $request->get_param( 'product_id' ) ) );
	}

	/**
	 * POST handler: adds a direct link, then returns the refreshed lists.
	 *
	 * Idempotent — re-linking an existing pair adds no second meta row and
	 * answers 200 instead of 201.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function create_link( \WP_REST_Request $request ): \WP_REST_Response {
		$product_id = (int) $request->get_param( 'product_id' );
		$created    = $this->resolver->link_product( (int) $request->get_param( 'content_id' ), $product_id );

		$response = rest_ensure_response( $this->linked_content_payload( $product_id ) );
		$response->set_status( $created ? 201 : 200 );

		return $response;
	}

	/**
	 * DELETE handler: removes a direct link, then returns the refreshed lists.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function delete_link( \WP_REST_Request $request ): \WP_REST_Response {
		$product_id = (int) $request->get_param( 'product_id' );

		$this->resolver->unlink_product( (int) $request->get_param( 'content_id' ), $product_id );

		return rest_ensure_response( $this->linked_content_payload( $product_id ) );
	}

	/**
	 * GET handler: content matching a search term, for the "add link" field.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function search_content( \WP_REST_Request $request ): \WP_REST_Response {
		$post_types = $this->resolver->content_post_types();
		$requested  = (string) $request->get_param( 'post_type' );

		if ( '' !== $requested ) {
			$post_types = array_values( array_intersect( $post_types, array( $requested ) ) );
		}

		if ( array() === $post_types ) {
			return rest_ensure_response( array() );
		}

		$query = new \WP_Query(
			array(
				'post_type'           => $post_types,
				// Drafts and pending posts are included on purpose: linking a
				// product to content that isn't published yet is a normal
				// step while preparing a launch. visible_items() then drops
				// anything the current user may not read.
				'post_status'         => 'any',
				's'                   => (string) $request->get_param( 'search' ),
				// Titles only. ComboboxControl re-filters the options it is given
				// against the typed text (wp-includes/js/dist/components.js), so a
				// body-only match is invisible in the UI anyway — and worse, it
				// consumes a per_page slot that a real title match needed. Also
				// what docs/DESIGN.md section 6.1 specifies.
				'search_columns'      => array( 'post_title' ),
				'posts_per_page'      => (int) $request->get_param( 'per_page' ),
				'fields'              => 'ids',
				// Deterministic instead of relevance-ranked, so the suggestion
				// list doesn't reshuffle as the shopkeeper types.
				'orderby'             => 'title',
				'order'               => 'ASC',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);

		$ids = array_map( 'intval', $query->posts );

		// `fields => 'ids'` leaves the post cache cold, so prime it once rather
		// than letting visible_items() and content_item() fire a get_post() per
		// result.
		_prime_post_caches( $ids, false, false );

		return rest_ensure_response( array_map( array( $this, 'content_item' ), $this->visible_items( $ids ) ) );
	}

	/**
	 * Builds the response body shared by all three linked-content handlers.
	 *
	 * @param int $product_id Product post ID.
	 * @return array<string, mixed>
	 */
	private function linked_content_payload( int $product_id ): array {
		$category_ids = $this->resolver->category_ids_for_product( $product_id );
		// `has_password => null` drops the resolver's front-end default of
		// excluding protected posts: here the list is the only place a link to
		// one can be removed, so hiding it would strand the meta row.
		$admin_args = array(
			'post_status'  => 'any',
			'has_password' => null,
		);

		$direct_all = $this->resolver->direct_content_ids_for_product( $product_id, $admin_args );
		$every_id   = $this->resolver->content_ids_for_product( $product_id, $admin_args );

		// Meta cache included: the inherited loop below reads
		// saai_linked_product_cats per post to work out which category
		// brought it in.
		_prime_post_caches( $every_id, false, true );

		$direct_ids    = $this->visible_items( $direct_all );
		$all_ids       = $this->visible_items( $every_id );
		$inherited_ids = array_values( array_diff( $all_ids, $direct_ids ) );

		$inherited = array();

		foreach ( $inherited_ids as $post_id ) {
			$item        = $this->content_item( $post_id );
			$item['via'] = $this->term_labels( $this->resolver->linking_category_ids( $post_id, $category_ids ) );
			$inherited[] = $item;
		}

		return array(
			'product_id' => $product_id,
			'direct'     => array_map( array( $this, 'content_item' ), $direct_ids ),
			'inherited'  => $inherited,
		);
	}

	/**
	 * Drops the posts the current user may not read.
	 *
	 * `post_status => 'any'` is what surfaces drafts and private content in
	 * the meta box, so the capability has to be re-checked per post rather
	 * than left to the query.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return int[]
	 */
	private function visible_items( array $post_ids ): array {
		return array_values(
			array_filter(
				$post_ids,
				static function ( $post_id ) {
					return current_user_can( 'read_post', (int) $post_id );
				}
			)
		);
	}

	/**
	 * Shapes one content post for the response.
	 *
	 * The title is decoded to plain text: get_the_title() returns HTML
	 * character references (`&#038;`, `&#8217;`) via the_title, which would
	 * show up literally once the JS renders it as a text node.
	 *
	 * @param int $post_id Content post ID.
	 * @return array<string, mixed>
	 */
	private function content_item( int $post_id ): array {
		$post_id   = (int) $post_id;
		$post_type = (string) get_post_type( $post_id );

		// get_the_title() prefixes "Protected: " / "Private: " outside the
		// admin screens, and a REST request is not is_admin(). The status is
		// already reported in its own field, so the prefix would only produce
		// a second, differently-worded label in the meta box. The filters go
		// back exactly as they were found — the closure is its own identity.
		$plain_title_format = static function () {
			return '%s';
		};

		add_filter( 'protected_title_format', $plain_title_format );
		add_filter( 'private_title_format', $plain_title_format );

		try {
			$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' );
		} finally {
			remove_filter( 'protected_title_format', $plain_title_format );
			remove_filter( 'private_title_format', $plain_title_format );
		}

		$status = (string) get_post_status( $post_id );

		return array(
			'id'              => $post_id,
			'title'           => '' !== trim( $title ) ? $title : __( '(no title)', 'saai-knowledge-for-woocommerce' ),
			'post_type'       => $post_type,
			'post_type_label' => $this->post_type_label( $post_type ),
			'status'          => $status,
			'status_label'    => $this->status_label( $status ),
			'edit_link'       => (string) get_edit_post_link( $post_id, 'raw' ),
		);
	}

	/**
	 * The translated label of a post status, falling back to its slug.
	 *
	 * The raw slug would otherwise reach the meta box untranslated.
	 *
	 * @param string $status Post status slug.
	 */
	private function status_label( string $status ): string {
		$object = get_post_status_object( $status );

		if ( null === $object ) {
			return $status;
		}

		return (string) $object->label;
	}

	/**
	 * The singular label of a post type, falling back to its slug.
	 *
	 * @param string $post_type Post type slug.
	 */
	private function post_type_label( string $post_type ): string {
		$object = get_post_type_object( $post_type );

		if ( null === $object ) {
			return $post_type;
		}

		return (string) $object->labels->singular_name;
	}

	/**
	 * Shapes product category terms for the "linked via" note.
	 *
	 * @param int[] $term_ids Product category term IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private function term_labels( array $term_ids ): array {
		$labels = array();

		foreach ( $term_ids as $term_id ) {
			$term = get_term( (int) $term_id, Link_Resolver::PRODUCT_TAXONOMY );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$labels[] = array(
				'term_id' => $term->term_id,
				'name'    => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
			);
		}

		return $labels;
	}

	/**
	 * Validates that an ID names an existing product the user may edit.
	 *
	 * @param int $product_id Product post ID.
	 * @return true|\WP_Error
	 */
	private function check_product( int $product_id ) {
		if ( Link_Resolver::PRODUCT_POST_TYPE !== get_post_type( $product_id ) ) {
			return new \WP_Error(
				'saai_woo_invalid_product_id',
				__( 'No product was found with that ID.', 'saai-knowledge-for-woocommerce' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			return new \WP_Error(
				'saai_woo_cannot_edit_product',
				__( 'Sorry, you are not allowed to edit that product.', 'saai-knowledge-for-woocommerce' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * A required positive-integer ID argument.
	 *
	 * `validate_callback` is set explicitly: without it, `minimum` is
	 * advertised in the schema but never enforced, so an out-of-range value
	 * would pass straight through.
	 *
	 * @param string $description Argument description.
	 * @return array<string, mixed>
	 */
	private function id_arg( string $description ): array {
		return array(
			'description'       => $description,
			'type'              => 'integer',
			'required'          => true,
			'minimum'           => 1,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		);
	}

	/**
	 * Schema for the linked-content routes.
	 *
	 * @return array<string, mixed>
	 */
	public function linked_content_schema(): array {
		$item = $this->content_item_schema();

		$inherited                      = $item;
		$inherited['properties']['via'] = array(
			'description' => __( 'Product categories that link this content to the product.', 'saai-knowledge-for-woocommerce' ),
			'type'        => 'array',
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'term_id' => array( 'type' => 'integer' ),
					'name'    => array( 'type' => 'string' ),
				),
			),
		);

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'saai-knowledge-woo-linked-content',
			'type'       => 'object',
			'properties' => array(
				'product_id' => array(
					'description' => __( 'Product post ID.', 'saai-knowledge-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
				'direct'     => array(
					'description' => __( 'Content linked to this product directly.', 'saai-knowledge-for-woocommerce' ),
					'type'        => 'array',
					'items'       => $item,
					'context'     => array( 'view', 'edit' ),
				),
				'inherited'  => array(
					'description' => __( 'Content linked through one of the product\'s categories.', 'saai-knowledge-for-woocommerce' ),
					'type'        => 'array',
					'items'       => $inherited,
					'context'     => array( 'view', 'edit' ),
				),
			),
		);
	}

	/**
	 * Schema for one content item.
	 *
	 * @return array<string, mixed>
	 */
	public function content_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'saai-knowledge-woo-content-item',
			'type'       => 'object',
			'properties' => array(
				'id'              => array(
					'description' => __( 'Content post ID.', 'saai-knowledge-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
				'title'           => array(
					'description' => __( 'Content title as plain text.', 'saai-knowledge-for-woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'post_type'       => array(
					'description' => __( 'Content post type slug.', 'saai-knowledge-for-woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'post_type_label' => array(
					'description' => __( 'Human-readable post type name.', 'saai-knowledge-for-woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'status'          => array(
					'description' => __( 'Post status slug.', 'saai-knowledge-for-woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'status_label'    => array(
					'description' => __( 'Human-readable post status name.', 'saai-knowledge-for-woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'edit_link'       => array(
					'description' => __( 'Admin edit URL, or an empty string when unavailable.', 'saai-knowledge-for-woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
			),
		);
	}
}
