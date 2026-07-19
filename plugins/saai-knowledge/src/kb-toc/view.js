import { store, getContext, getElement } from '@wordpress/interactivity';

import './style.scss';

const { state } = store( 'saai-knowledge/kb-toc', {
	state: {
		get isActive() {
			const { id, activeId } = getContext();
			return !! id && id === activeId;
		},
		get ariaCurrent() {
			return state.isActive ? 'location' : null;
		},
	},
	actions: {
		scrollToHeading( event ) {
			const { id } = getContext();
			const heading = document.getElementById( id );

			if ( ! heading ) {
				return;
			}

			event.preventDefault();

			const prefersReducedMotion = window.matchMedia(
				'(prefers-reduced-motion: reduce)'
			).matches;

			heading.scrollIntoView( {
				behavior: prefersReducedMotion ? 'auto' : 'smooth',
				block: 'start',
			} );

			// Keep keyboard / screen-reader position in sync with the visual scroll.
			heading.setAttribute( 'tabindex', '-1' );
			heading.focus( { preventScroll: true } );

			window.history.pushState( null, '', `#${ id }` );
		},
	},
	callbacks: {
		initScrollSpy() {
			const { ref } = getElement();
			const context = getContext();
			const headingIds = Array.isArray( context.headingIds )
				? context.headingIds
				: [];

			const headings = headingIds
				.map( ( id ) => document.getElementById( id ) )
				.filter( ( el ) => el !== null );

			if ( ! ref || ! headings.length ) {
				return;
			}

			const intersecting = new Set();

			const observer = new IntersectionObserver(
				( entries ) => {
					entries.forEach( ( entry ) => {
						if ( entry.isIntersecting ) {
							intersecting.add( entry.target.id );
						} else {
							intersecting.delete( entry.target.id );
						}
					} );

					const activeId = headingIds.find( ( id ) =>
						intersecting.has( id )
					);

					if ( activeId ) {
						context.activeId = activeId;
					}
				},
				{ rootMargin: '0px 0px -70% 0px', threshold: 0 }
			);

			headings.forEach( ( heading ) => observer.observe( heading ) );

			return () => observer.disconnect();
		},
	},
} );
