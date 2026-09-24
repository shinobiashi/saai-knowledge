/**
 * The "SAAI Knowledge" meta box on the product edit screen.
 *
 * Shows what FAQ / knowledge base / glossary content applies to this product,
 * split into links made directly to the product (removable here) and links
 * inherited from one of its categories (read-only here, because they live on
 * the content post and apply to every product in that category).
 *
 * Listing, adding, and removing all go through saai-knowledge-woo/v1, which
 * writes the same content-side meta the editor sidebar panel writes — that
 * shared write path is what makes both screens converge (Issue #21).
 *
 * Hand-written for the global `wp` object on purpose; see
 * linked-products-panel.js.
 */
( function ( wp, config ) {
	'use strict';

	var apiFetch = wp.apiFetch;
	var addQueryArgs = wp.url.addQueryArgs;
	var ComboboxControl = wp.components.ComboboxControl;
	var Button = wp.components.Button;
	var Notice = wp.components.Notice;
	var Spinner = wp.components.Spinner;
	var createElement = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useEffect = wp.element.useEffect;
	var useState = wp.element.useState;
	var speak = wp.a11y.speak;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	var REST_NAMESPACE = ( config && config.restNamespace ) || 'saai-knowledge-woo/v1';
	var SEARCH_DEBOUNCE_MS = 300;
	var MAX_SUGGESTIONS = 20;

	/**
	 * Pulls a human-readable message out of an apiFetch rejection.
	 *
	 * @param {*} error Rejection value.
	 * @return {string} Message.
	 */
	function messageFrom( error ) {
		if ( error && 'string' === typeof error.message && '' !== error.message ) {
			return error.message;
		}

		return __( 'Something went wrong. Please try again.', 'saai-knowledge-for-woocommerce' );
	}

	/**
	 * Renders one content item's title, type, and status.
	 *
	 * @param {Object} item Content item from the REST response.
	 * @return {Object} Element.
	 */
	function ItemLabel( item ) {
		var title = item.edit_link
			? createElement( 'a', { href: item.edit_link }, item.title )
			: createElement( 'span', null, item.title );

		return createElement(
			Fragment,
			null,
			title,
			createElement( 'span', { className: 'saai-woo-product-links__type' }, item.post_type_label ),
			'publish' === item.status
				? null
				: createElement(
						'span',
						{ className: 'saai-woo-product-links__status' },
						item.status_label || item.status
				  )
		);
	}

	/**
	 * The meta box contents.
	 *
	 * @param {Object} props Component props.
	 * @return {Object} Element.
	 */
	function ProductLinks( props ) {
		var dataState = useState( null );
		var data = dataState[ 0 ];
		var setData = dataState[ 1 ];

		var errorState = useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];

		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];

		var searchState = useState( '' );
		var search = searchState[ 0 ];
		var setSearch = searchState[ 1 ];

		var suggestionState = useState( [] );
		var suggestions = suggestionState[ 0 ];
		var setSuggestions = suggestionState[ 1 ];

		var basePath = '/' + REST_NAMESPACE + '/products/' + props.productId + '/linked-content';

		useEffect(
			function () {
				var cancelled = false;

				apiFetch( { path: basePath } )
					.then( function ( payload ) {
						if ( ! cancelled ) {
							setData( payload );
						}
					} )
					.catch( function ( reason ) {
						if ( ! cancelled ) {
							setError( messageFrom( reason ) );
						}
					} );

				return function () {
					cancelled = true;
				};
			},
			[ basePath ]
		);

		useEffect(
			function () {
				if ( '' === search ) {
					setSuggestions( [] );
					return undefined;
				}

				var cancelled = false;
				var timer = window.setTimeout( function () {
					apiFetch( {
						path: addQueryArgs( '/' + REST_NAMESPACE + '/content-search', {
							search: search,
							per_page: MAX_SUGGESTIONS,
						} ),
					} )
						.then( function ( items ) {
							if ( ! cancelled ) {
								setSuggestions( Array.isArray( items ) ? items : [] );
							}
						} )
						.catch( function ( reason ) {
							if ( ! cancelled ) {
								setError( messageFrom( reason ) );
							}
						} );
				}, SEARCH_DEBOUNCE_MS );

				return function () {
					cancelled = true;
					window.clearTimeout( timer );
				};
			},
			[ search ]
		);

		/**
		 * Sends a link change and replaces the lists with the response.
		 *
		 * @param {Object} options      apiFetch options.
		 * @param {string} announcement Message to announce once it succeeds.
		 */
		function mutate( options, announcement ) {
			setBusy( true );
			setError( '' );

			apiFetch( options )
				.then( function ( payload ) {
					setData( payload );
					// The row that had focus is gone after an unlink, so the
					// outcome needs announcing explicitly.
					speak( announcement );
				} )
				.catch( function ( reason ) {
					setError( messageFrom( reason ) );
				} )
				.finally( function () {
					setBusy( false );
				} );
		}

		if ( '' !== error && null === data ) {
			return createElement( Notice, { status: 'error', isDismissible: false }, error );
		}

		if ( null === data ) {
			return createElement( Spinner, null );
		}

		var linkedIds = data.direct.map( function ( item ) {
			return item.id;
		} );

		var options = suggestions
			.filter( function ( item ) {
				return -1 === linkedIds.indexOf( item.id );
			} )
			.map( function ( item ) {
				return {
					value: String( item.id ),
					label: item.title + ' — ' + item.post_type_label,
				};
			} );

		return createElement(
			Fragment,
			null,
			'' === error
				? null
				: createElement(
						Notice,
						{
							status: 'error',
							onRemove: function () {
								setError( '' );
							},
						},
						error
				  ),
			createElement(
				'h4',
				{ className: 'saai-woo-product-links__heading' },
				__( 'Linked to this product', 'saai-knowledge-for-woocommerce' )
			),
			0 === data.direct.length
				? createElement(
						'p',
						{ className: 'saai-woo-product-links__empty' },
						__( 'Nothing linked directly to this product yet.', 'saai-knowledge-for-woocommerce' )
				  )
				: createElement(
						'ul',
						{ className: 'saai-woo-product-links__list' },
						data.direct.map( function ( item ) {
							return createElement(
								'li',
								{ key: item.id },
								ItemLabel( item ),
								createElement(
									Button,
									{
										variant: 'tertiary',
										isDestructive: true,
										disabled: busy,
										label: sprintf(
											/* translators: %s: title of the linked content. */
											__( 'Unlink %s', 'saai-knowledge-for-woocommerce' ),
											item.title
										),
										onClick: function () {
											mutate(
												{ path: basePath + '/' + item.id, method: 'DELETE' },
												sprintf(
													/* translators: %s: title of the content that was unlinked. */
													__( '%s is no longer linked to this product.', 'saai-knowledge-for-woocommerce' ),
													item.title
												)
											);
										},
									},
									__( 'Unlink', 'saai-knowledge-for-woocommerce' )
								)
							);
						} )
				  ),
			createElement( ComboboxControl, {
				__next40pxDefaultSize: true,
				__nextHasNoMarginBottom: true,
				label: __( 'Link content to this product', 'saai-knowledge-for-woocommerce' ),
				help: __( 'Search FAQs, knowledge base articles, and glossary terms.', 'saai-knowledge-for-woocommerce' ),
				value: null,
				// ComboboxControl has no `disabled` prop — it destructures a fixed
				// list and drops anything else — so the in-flight guard lives in
				// onChange below, with isLoading showing why. Without it a second
				// request started before the first resolves could roll the list
				// back: every response carries a full snapshot.
				isLoading: busy,
				options: options,
				onFilterValueChange: setSearch,
				onChange: function ( value ) {
					if ( busy ) {
						return;
					}

					var id = parseInt( value, 10 );
					var chosen = suggestions.filter( function ( item ) {
						return item.id === id;
					} )[ 0 ];

					if ( ! isNaN( id ) && id > 0 ) {
						mutate(
							{ path: basePath, method: 'POST', data: { content_id: id } },
							sprintf(
								/* translators: %s: title of the content that was linked. */
								__( '%s is now linked to this product.', 'saai-knowledge-for-woocommerce' ),
								chosen ? chosen.title : String( id )
							)
						);
					}

					setSearch( '' );
				},
			} ),
			0 === data.inherited.length
				? null
				: createElement(
						Fragment,
						null,
						createElement(
							'h4',
							{ className: 'saai-woo-product-links__heading' },
							__( 'Linked through a product category', 'saai-knowledge-for-woocommerce' )
						),
						createElement(
							'p',
							{ className: 'saai-woo-product-links__note' },
							__(
								'These apply to every product in the category. Edit the content itself to change them.',
								'saai-knowledge-for-woocommerce'
							)
						),
						createElement(
							'ul',
							{ className: 'saai-woo-product-links__list' },
							data.inherited.map( function ( item ) {
								var via = ( item.via || [] )
									.map( function ( term ) {
										return term.name;
									} )
									.join( ', ' );

								return createElement(
									'li',
									{ key: item.id },
									ItemLabel( item ),
									'' === via
										? null
										: createElement(
												'span',
												{ className: 'saai-woo-product-links__via' },
												sprintf(
													/* translators: %s: comma-separated product category names. */
													__( 'via %s', 'saai-knowledge-for-woocommerce' ),
													via
												)
										  )
								);
							} )
						)
				  )
		);
	}

	/**
	 * Mounts the component into the meta box container.
	 */
	function mount() {
		var container = document.querySelector( '.saai-woo-product-links[data-product-id]' );

		if ( ! container ) {
			return;
		}

		var productId = parseInt( container.getAttribute( 'data-product-id' ), 10 );

		if ( isNaN( productId ) || productId <= 0 ) {
			return;
		}

		var app = createElement( ProductLinks, { productId: productId } );

		if ( wp.element.createRoot ) {
			wp.element.createRoot( container ).render( app );
		} else {
			wp.element.render( app, container );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
} )( window.wp, window.saaiKnowledgeWooLinks );
