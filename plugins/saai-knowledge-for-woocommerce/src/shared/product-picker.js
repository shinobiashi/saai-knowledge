import { ComboboxControl } from '@wordpress/components';
import { useDebounce } from '@wordpress/compose';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';

const SEARCH_DEBOUNCE_MS = 300;
const MAX_SUGGESTIONS = 20;

// Returned while there is nothing to search: a fresh [] on every call would
// make useSelect() see a changed value each time and re-render for nothing.
const NO_MATCHES = [];

/**
 * Reads a product record's display name.
 *
 * @param {Object} record Product entity record.
 * @return {string} Name, or a numbered fallback for an untitled product.
 */
function productName( record ) {
	const name = decodeEntities( record.title?.rendered || '' );

	if ( name ) {
		return name;
	}

	return sprintf(
		/* translators: %d: product ID. */
		__( 'Product #%d', 'saai-knowledge-for-woocommerce' ),
		record.id
	);
}

/**
 * Searchable product selector for the product blocks' `productId` attribute.
 *
 * Products are searched through core's own /wp/v2/product route
 * (docs/DESIGN.md section 6.1): WooCommerce's /wc/v3/products needs product
 * capabilities an Editor doesn't have. Both queries ask for the `view`
 * context explicitly — core-data requests post type records in the `edit`
 * context by default, which that route also refuses to anyone without
 * `edit_products` (403 rest_forbidden_context for an Editor). The search is
 * limited to titles because ComboboxControl re-filters the options it is
 * given against the typed text, so a product matched on its description
 * alone would be invisible while still taking a result slot.
 *
 * @param {Object}   props           Component props.
 * @param {number}   props.productId The selected product ID; 0 for none.
 * @param {Function} props.onChange  Receives the new product ID; 0 when cleared.
 * @return {Element} Element.
 */
export default function ProductPicker( { productId, onChange } ) {
	const [ search, setSearch ] = useState( '' );
	const debouncedSetSearch = useDebounce( setSearch, SEARCH_DEBOUNCE_MS );

	const { matches, selected, isSearching } = useSelect(
		( select ) => {
			const core = select( coreStore );
			const searchQuery = {
				context: 'view',
				search,
				search_columns: [ 'post_title' ],
				per_page: MAX_SUGGESTIONS,
				orderby: 'title',
				order: 'asc',
			};
			const selectedRecords =
				productId > 0
					? core.getEntityRecords( 'postType', 'product', {
							context: 'view',
							include: [ productId ],
							per_page: 1,
					  } )
					: null;

			return {
				matches:
					'' === search
						? NO_MATCHES
						: core.getEntityRecords(
								'postType',
								'product',
								searchQuery
						  ) ?? NO_MATCHES,
				selected: selectedRecords?.[ 0 ] ?? null,
				isSearching:
					'' !== search &&
					core.isResolving( 'getEntityRecords', [
						'postType',
						'product',
						searchQuery,
					] ),
			};
		},
		[ search, productId ]
	);

	const options = [];

	// The selected product has to be among the options for the control to
	// show its name, whatever the current search returned.
	if ( productId > 0 ) {
		options.push( {
			value: String( productId ),
			label: selected
				? productName( selected )
				: sprintf(
						/* translators: %d: product ID. */
						__( 'Product #%d', 'saai-knowledge-for-woocommerce' ),
						productId
				  ),
		} );
	}

	matches
		.filter( ( record ) => record.id !== productId )
		.forEach( ( record ) =>
			options.push( {
				value: String( record.id ),
				label: productName( record ),
			} )
		);

	return (
		<ComboboxControl
			label={ __( 'Product', 'saai-knowledge-for-woocommerce' ) }
			help={
				productId > 0
					? __(
							'Clear to use the product being displayed instead.',
							'saai-knowledge-for-woocommerce'
					  )
					: __(
							'Empty: the product being displayed (Single Product template, product loops). Search to show a specific product, e.g. on a regular page.',
							'saai-knowledge-for-woocommerce'
					  )
			}
			value={ productId > 0 ? String( productId ) : null }
			options={ options }
			isLoading={ isSearching }
			onChange={ ( value ) => {
				const id = value ? parseInt( value, 10 ) : 0;
				onChange( Number.isNaN( id ) || id < 0 ? 0 : id );
			} }
			onFilterValueChange={ ( value ) => {
				// ComboboxControl reports '' synchronously on focus and after
				// a selection. Routing that through the debounce would keep
				// the old results on screen for the delay, and a keystroke
				// still pending when an item is picked would resurrect the old
				// search and fire the request the debounce exists to avoid.
				if ( '' === value ) {
					debouncedSetSearch.cancel?.();
					setSearch( '' );
					return;
				}

				debouncedSetSearch( value );
			} }
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
	);
}
