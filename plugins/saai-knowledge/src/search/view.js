import {
	store,
	getContext,
	getElement,
	getConfig,
} from '@wordpress/interactivity';

import './style.scss';

const DEBOUNCE_MS = 300;

// Debounce timers and in-flight request controllers are per block instance
// (a page can hold more than one search block) and aren't serializable state,
// so they live in module-scope maps keyed by that instance's context object
// rather than in context itself.
const debounceTimers = new WeakMap();
const activeRequests = new WeakMap();

function resultsContainer() {
	const { ref } = getElement();

	return (
		ref
			.closest( '.saai-search' )
			?.querySelector( '.saai-search__results' ) ?? null
	);
}

function clearChildren( element ) {
	while ( element.firstChild ) {
		element.removeChild( element.firstChild );
	}
}

// Results arrive already grouped by relevance, not by type; re-render them
// grouped by type (consecutive same-type runs get one heading) per
// docs/DESIGN.md section 4.4. Built with createElement/textContent, never
// innerHTML, since result titles/excerpts are untrusted third-party content.
function renderResults( results ) {
	const container = resultsContainer();

	if ( ! container ) {
		return;
	}

	clearChildren( container );

	const { typeLabels = {} } = getConfig( 'saai-knowledge/search' );

	let lastType = null;

	results.forEach( ( result ) => {
		if ( result.type !== lastType ) {
			lastType = result.type;

			const heading = document.createElement( 'li' );
			heading.className = 'saai-search__group-title';
			heading.setAttribute( 'role', 'presentation' );
			heading.textContent = typeLabels[ result.type ] ?? result.type;
			container.appendChild( heading );
		}

		const item = document.createElement( 'li' );
		item.className = 'saai-search__item';
		item.setAttribute( 'role', 'option' );

		const link = document.createElement( 'a' );
		link.href = result.url;
		link.textContent = result.title;
		item.appendChild( link );

		if ( result.excerpt ) {
			const excerpt = document.createElement( 'span' );
			excerpt.className = 'saai-search__item-excerpt';
			excerpt.textContent = result.excerpt;
			item.appendChild( excerpt );
		}

		container.appendChild( item );
	} );
}

const { actions } = store( 'saai-knowledge/search', {
	state: {
		get hasResults() {
			const context = getContext();

			return (
				Array.isArray( context.results ) && context.results.length > 0
			);
		},
		get statusText() {
			const context = getContext();
			const { statusText = {} } = getConfig( 'saai-knowledge/search' );

			return statusText[ context.status ] ?? '';
		},
	},
	actions: {
		onInput( event ) {
			const context = getContext();

			context.query = event.target.value;

			const existingTimer = debounceTimers.get( context );

			if ( existingTimer ) {
				clearTimeout( existingTimer );
			}

			if ( '' === context.query.trim() ) {
				activeRequests.get( context )?.abort();
				context.results = [];
				context.status = 'idle';
				renderResults( context.results );
				return;
			}

			debounceTimers.set(
				context,
				setTimeout( () => actions.search(), DEBOUNCE_MS )
			);
		},
		async search() {
			const context = getContext();
			const query = context.query.trim();

			if ( '' === query ) {
				return;
			}

			activeRequests.get( context )?.abort();

			const controller = new AbortController();
			activeRequests.set( context, controller );

			context.status = 'loading';

			const { restUrl = '' } = getConfig( 'saai-knowledge/search' );
			const url = new URL( restUrl, window.location.origin );
			url.searchParams.set( 'query', query );
			url.searchParams.set( 'per_page', String( context.perPage ?? 5 ) );

			try {
				const response = await window.fetch( url.toString(), {
					signal: controller.signal,
					headers: { Accept: 'application/json' },
				} );

				if ( ! response.ok ) {
					throw new Error(
						`saai-knowledge search request failed: ${ response.status }`
					);
				}

				const results = await response.json();

				// A later keystroke may have started a newer request while this
				// one was in flight; only the still-current controller's result
				// should reach state (the newer request already replaced it in
				// activeRequests, so it's no longer === controller here).
				if ( activeRequests.get( context ) !== controller ) {
					return;
				}

				context.results = Array.isArray( results ) ? results : [];
				context.status = context.results.length ? 'idle' : 'empty';
				renderResults( context.results );
			} catch ( error ) {
				if ( 'AbortError' === error?.name ) {
					return;
				}

				context.results = [];
				context.status = 'error';
				renderResults( context.results );
			}
		},
	},
} );
