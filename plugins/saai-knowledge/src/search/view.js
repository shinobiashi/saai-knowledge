import {
	store,
	getContext,
	getElement,
	getConfig,
	withScope,
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

// The saai_search_results filter (and, in principle, a 'post_link'/
// 'post_type_link' filter behind get_permalink()) is documented as raw,
// pre-escaping output — a misbehaving callback could return a `javascript:`
// URL, which would execute on click if assigned to `.href` unchecked.
function isHttpUrl( url ) {
	try {
		return [ 'http:', 'https:' ].includes(
			new URL( url, window.location.origin ).protocol
		);
	} catch {
		return false;
	}
}

// Results arrive ordered by relevance, not by type; partition into one group
// per type (in order of each type's first, most-relevant appearance) per
// docs/DESIGN.md section 4.4 — a plain consecutive-run merge would split a
// type into multiple groups whenever relevance interleaves types. Built with
// createElement/textContent, never innerHTML, since result titles/excerpts
// are untrusted third-party content.
function renderResults( results ) {
	const container = resultsContainer();

	if ( ! container ) {
		return;
	}

	clearChildren( container );

	const { typeLabels = {} } = getConfig( 'saai-knowledge/search' );

	const groups = new Map();

	results.forEach( ( result ) => {
		if ( ! groups.has( result.type ) ) {
			groups.set( result.type, [] );
		}

		groups.get( result.type ).push( result );
	} );

	groups.forEach( ( items, type ) => {
		const heading = document.createElement( 'li' );
		heading.className = 'saai-search__group-title';
		heading.textContent = typeLabels[ type ] ?? type;
		container.appendChild( heading );

		items.forEach( ( result ) => {
			const item = document.createElement( 'li' );
			item.className = 'saai-search__item';

			const link = document.createElement( 'a' );
			if ( isHttpUrl( result.url ) ) {
				link.href = result.url;
			}
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
	} );
}

const { actions } = store( 'saai-knowledge/search', {
	state: {
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

			// Abort immediately, on every keystroke, so a stale in-flight
			// response can never land after newer input — waiting for the
			// next debounce to fire (search() aborts too) would leave a
			// window where an older response is still the "current" one.
			activeRequests.get( context )?.abort();

			if ( '' === context.query.trim() ) {
				context.results = [];
				context.status = 'idle';
				renderResults( context.results );
				return;
			}

			// setTimeout runs outside any Interactivity scope, so getContext()/
			// getElement() would throw once the timer fires — withScope()
			// re-establishes the scope active right now (see @wordpress/
			// interactivity's own guidance for setTimeout-deferred actions).
			debounceTimers.set(
				context,
				setTimeout(
					withScope( () => actions.search() ),
					DEBOUNCE_MS
				)
			);
		},
		// A generator, not an async function: withScope() only restores scope
		// around each step up to the next yield, so getElement() inside
		// renderResults() (called after the fetch resolves) still has a valid
		// scope. A plain `await` would lose scope the moment it suspends.
		*search() {
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
				const response = yield window.fetch( url.toString(), {
					signal: controller.signal,
					headers: { Accept: 'application/json' },
				} );

				if ( ! response.ok ) {
					throw new Error(
						`saai-knowledge search request failed: ${ response.status }`
					);
				}

				const results = yield response.json();

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
