/**
 * "Linked Products" document setting panel for FAQ / knowledge base /
 * glossary posts.
 *
 * Reads and writes saai_linked_products and saai_linked_product_cats through
 * core-data, so a save goes out with the post like any other meta. Products
 * and product categories are searched through core's own /wp/v2/product and
 * /wp/v2/product_cat collections rather than WooCommerce's /wc/v3/products,
 * whose read permission (read_private_products) is limited to administrators
 * and shop managers — see docs/DESIGN.md section 6.1.
 *
 * Hand-written for the global `wp` object on purpose: the add-on has no build
 * step until the product blocks land, mirroring the free plugin's
 * assets/js/glossary-panel.js.
 */
( function ( wp ) {
	'use strict';

	var registerPlugin = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var ComboboxControl = wp.components.ComboboxControl;
	var Button = wp.components.Button;
	var useEntityProp = wp.coreData.useEntityProp;
	var useSelect = wp.data.useSelect;
	var useDebounce = wp.compose.useDebounce;
	var createElement = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var decodeEntities = wp.htmlEntities.decodeEntities;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	var PRODUCT_META = 'saai_linked_products';
	var CATEGORY_META = 'saai_linked_product_cats';
	var CONTENT_TYPES = [ 'saai_faq', 'saai_kb', 'saai_glossary' ];
	var MAX_SUGGESTIONS = 20;
	var SEARCH_DEBOUNCE_MS = 300;

	if ( ! PluginDocumentSettingPanel ) {
		return;
	}

	/**
	 * Normalizes a meta value into a deduplicated list of positive integers.
	 *
	 * Meta rows come back as strings, and a row holding 0 or a stale value
	 * can never name a real product or term.
	 *
	 * @param {*} value Raw meta value.
	 * @return {number[]} Normalized IDs.
	 */
	function toIds( value ) {
		if ( ! Array.isArray( value ) ) {
			return [];
		}

		return value
			.map( function ( id ) {
				return parseInt( id, 10 );
			} )
			.filter( function ( id ) {
				return ! isNaN( id ) && id > 0;
			} )
			.filter( function ( id, index, all ) {
				return all.indexOf( id ) === index;
			} );
	}

	/**
	 * Builds ComboboxControl options, appending a hint only where two results
	 * in the same list share a name (two "Accessories" categories under
	 * different parents, say). The option value is always the ID, so a
	 * duplicate label can never make the selection ambiguous.
	 *
	 * @param {Object[]} records  Entity records.
	 * @param {Function} getName  Reads the display name off a record.
	 * @param {Function} getHint  Reads a disambiguating hint off a record.
	 * @param {number[]} excluded IDs already linked.
	 * @return {Object[]} Combobox options.
	 */
	function buildOptions( records, getName, getHint, excluded ) {
		var counts = {};

		records.forEach( function ( record ) {
			var name = getName( record );
			counts[ name ] = ( counts[ name ] || 0 ) + 1;
		} );

		return records
			.filter( function ( record ) {
				return -1 === excluded.indexOf( record.id );
			} )
			.map( function ( record ) {
				var name = getName( record );
				var hint = getHint( record );

				return {
					value: String( record.id ),
					label: counts[ name ] > 1 && hint ? name + ' (' + hint + ')' : name,
				};
			} );
	}

	/**
	 * Wraps a debounced setter so that clearing the field takes effect at once.
	 *
	 * ComboboxControl calls onFilterValueChange( '' ) synchronously on focus
	 * and after a selection. Routing that through the debounce would leave the
	 * previous results on screen for the delay, and — worse — a keystroke
	 * still pending when an item is picked would resurrect the old term and
	 * fire exactly the request the debounce exists to avoid.
	 *
	 * @param {Function} setSearch Raw state setter.
	 * @param {Function} debounced Debounced state setter, carrying .cancel().
	 * @return {Function} Handler for onFilterValueChange.
	 */
	function searchHandler( setSearch, debounced ) {
		return function ( value ) {
			if ( '' === value ) {
				if ( debounced.cancel ) {
					debounced.cancel();
				}

				setSearch( '' );
				return;
			}

			debounced( value );
		};
	}

	/**
	 * Fetches the records naming a set of already-linked IDs.
	 *
	 * @param {string}   kind Entity kind ('postType' or 'taxonomy').
	 * @param {string}   name Entity name.
	 * @param {number[]} ids  IDs to resolve.
	 * @return {Object[]} Records, possibly fewer than requested.
	 */
	function useRecordsByIds( kind, name, ids ) {
		var key = ids.join( ',' );

		return useSelect(
			function ( select ) {
				if ( '' === key ) {
					return [];
				}

				return (
					select( 'core' ).getEntityRecords( kind, name, {
						include: ids,
						per_page: -1,
					} ) || []
				);
			},
			[ kind, name, key ]
		);
	}

	/**
	 * Renders the linked IDs with a remove button each.
	 *
	 * The list is driven by the meta IDs, not by the fetched records: an ID
	 * whose record is missing (still loading, or a deleted product) is shown
	 * as "not found" instead of being dropped, so neither the loading window
	 * nor a deleted product can quietly erase a link on the next save.
	 *
	 * @param {Object} props Component props.
	 * @return {Object} Element.
	 */
	function LinkedList( props ) {
		var byId = {};

		props.records.forEach( function ( record ) {
			byId[ record.id ] = record;
		} );

		if ( 0 === props.ids.length ) {
			return createElement( 'p', { className: 'saai-woo-linked__empty' }, props.emptyLabel );
		}

		return createElement(
			'ul',
			{ className: 'saai-woo-linked__list' },
			props.ids.map( function ( id ) {
				var record = byId[ id ];
				var label = record
					? props.getName( record )
					: sprintf(
							/* translators: %d: ID of a linked product or product category that could not be loaded. */
							__( 'Cannot be shown — unpublished or deleted (#%d)', 'saai-knowledge-for-woocommerce' ),
							id
					  );

				return createElement(
					'li',
					{ key: id },
					createElement( 'span', null, label ),
					createElement(
						Button,
						{
							variant: 'tertiary',
							isDestructive: true,
							label: sprintf(
								/* translators: %s: name of the linked product or product category. */
								__( 'Remove %s', 'saai-knowledge-for-woocommerce' ),
								label
							),
							onClick: function () {
								props.onRemove( id );
							},
						},
						__( 'Remove', 'saai-knowledge-for-woocommerce' )
					)
				);
			} )
		);
	}

	/**
	 * Panel body. Split out from LinkedProductsPanel so the meta hooks only
	 * ever run for a post type that actually carries the meta, without
	 * calling hooks conditionally.
	 *
	 * @param {Object} props Component props.
	 * @return {Object} Element.
	 */
	function PanelContents( props ) {
		var metaProp = useEntityProp( 'postType', props.postType, 'meta' );
		var meta = metaProp[ 0 ] || {};
		var setMeta = metaProp[ 1 ];

		var productSearchState = useState( '' );
		var productSearch = productSearchState[ 0 ];
		var setProductSearch = productSearchState[ 1 ];

		var categorySearchState = useState( '' );
		var categorySearch = categorySearchState[ 0 ];
		var setCategorySearch = categorySearchState[ 1 ];

		// The controls keep their own input state, so only the query is
		// delayed — without this every keystroke starts a REST request, unlike
		// the product meta box which has always debounced.
		var debouncedProductSearch = useDebounce( setProductSearch, SEARCH_DEBOUNCE_MS );
		var debouncedCategorySearch = useDebounce( setCategorySearch, SEARCH_DEBOUNCE_MS );

		var changeProductSearch = searchHandler( setProductSearch, debouncedProductSearch );
		var changeCategorySearch = searchHandler( setCategorySearch, debouncedCategorySearch );

		var productIds = toIds( meta[ PRODUCT_META ] );
		var categoryIds = toIds( meta[ CATEGORY_META ] );

		var productMatches = useSelect(
			function ( select ) {
				if ( '' === productSearch ) {
					return [];
				}

				return (
					select( 'core' ).getEntityRecords( 'postType', 'product', {
						search: productSearch,
						// Titles only: ComboboxControl re-filters the options it
						// is given against the typed text, so a product matched
						// on its description alone would be invisible while
						// still consuming a per_page slot.
						search_columns: [ 'post_title' ],
						per_page: MAX_SUGGESTIONS,
						orderby: 'title',
						order: 'asc',
					} ) || []
				);
			},
			[ productSearch ]
		);

		var categoryMatches = useSelect(
			function ( select ) {
				if ( '' === categorySearch ) {
					return [];
				}

				return (
					select( 'core' ).getEntityRecords( 'taxonomy', 'product_cat', {
						search: categorySearch,
						per_page: MAX_SUGGESTIONS,
						orderby: 'name',
						order: 'asc',
					} ) || []
				);
			},
			[ categorySearch ]
		);

		var productRecords = useRecordsByIds( 'postType', 'product', productIds );
		var categoryRecords = useRecordsByIds( 'taxonomy', 'product_cat', categoryIds );

		function productName( record ) {
			return decodeEntities( ( record.title && record.title.rendered ) || '' ) || String( record.id );
		}

		function productHint( record ) {
			return record.slug || '';
		}

		function categoryName( record ) {
			return decodeEntities( record.name || '' ) || String( record.id );
		}

		function categoryHint( record ) {
			return record.slug || '';
		}

		function setIds( key, ids ) {
			var next = {};
			next[ key ] = ids;
			setMeta( Object.assign( {}, meta, next ) );
		}

		function addId( key, ids, value, clearSearch ) {
			var id = parseInt( value, 10 );

			if ( ! isNaN( id ) && id > 0 && -1 === ids.indexOf( id ) ) {
				setIds( key, ids.concat( [ id ] ) );
			}

			clearSearch( '' );
		}

		function removeId( key, ids, id ) {
			setIds(
				key,
				ids.filter( function ( current ) {
					return current !== id;
				} )
			);
		}

		return createElement(
			Fragment,
			null,
			createElement( 'h3', { className: 'saai-woo-linked__heading' }, __( 'Products', 'saai-knowledge-for-woocommerce' ) ),
			createElement( LinkedList, {
				ids: productIds,
				records: productRecords,
				getName: productName,
				emptyLabel: __( 'No products linked yet.', 'saai-knowledge-for-woocommerce' ),
				onRemove: function ( id ) {
					removeId( PRODUCT_META, productIds, id );
				},
			} ),
			createElement( ComboboxControl, {
				__next40pxDefaultSize: true,
				__nextHasNoMarginBottom: true,
				label: __( 'Add a product', 'saai-knowledge-for-woocommerce' ),
				value: null,
				options: buildOptions( productMatches, productName, productHint, productIds ),
				onFilterValueChange: changeProductSearch,
				onChange: function ( value ) {
					addId( PRODUCT_META, productIds, value, changeProductSearch );
				},
			} ),
			createElement(
				'h3',
				{ className: 'saai-woo-linked__heading' },
				__( 'Product categories', 'saai-knowledge-for-woocommerce' )
			),
			createElement( LinkedList, {
				ids: categoryIds,
				records: categoryRecords,
				getName: categoryName,
				emptyLabel: __( 'No product categories linked yet.', 'saai-knowledge-for-woocommerce' ),
				onRemove: function ( id ) {
					removeId( CATEGORY_META, categoryIds, id );
				},
			} ),
			createElement( ComboboxControl, {
				__next40pxDefaultSize: true,
				__nextHasNoMarginBottom: true,
				label: __( 'Add a product category', 'saai-knowledge-for-woocommerce' ),
				help: __(
					'A category also covers the products in its subcategories.',
					'saai-knowledge-for-woocommerce'
				),
				value: null,
				options: buildOptions( categoryMatches, categoryName, categoryHint, categoryIds ),
				onFilterValueChange: changeCategorySearch,
				onChange: function ( value ) {
					addId( CATEGORY_META, categoryIds, value, changeCategorySearch );
				},
			} )
		);
	}

	/**
	 * The registered plugin: resolves the post type, then defers to
	 * PanelContents.
	 *
	 * @return {Object|null} Element.
	 */
	function LinkedProductsPanel() {
		var postType = useSelect( function ( select ) {
			var editor = select( 'core/editor' );

			return editor ? editor.getCurrentPostType() : null;
		}, [] );

		if ( ! postType || -1 === CONTENT_TYPES.indexOf( postType ) ) {
			return null;
		}

		return createElement(
			PluginDocumentSettingPanel,
			{
				name: 'saai-woo-linked-products',
				title: __( 'Linked Products', 'saai-knowledge-for-woocommerce' ),
				className: 'saai-woo-linked',
			},
			createElement( PanelContents, { postType: postType } )
		);
	}

	registerPlugin( 'saai-knowledge-woo-linked-products', {
		render: LinkedProductsPanel,
	} );
} )( window.wp );
