import { store, getElement } from '@wordpress/interactivity';

import './style.scss';

const TOOLTIP_ID = 'saai-tooltip';
const VIEWPORT_MARGIN = 8;

let escapeListenerAttached = false;
let anchorIdCounter = 0;

function getTooltipElement() {
	return document.getElementById( TOOLTIP_ID );
}

// data-saai-term-id repeats across anchors on pages that loop the same post
// more than once (e.g. an archive linking the same glossary term from two
// different articles' excerpts) — an id derived from it alone could collide,
// so each anchor gets its own id from a page-wide counter the first time
// it's shown.
function ensureAnchorId( anchor ) {
	if ( ! anchor.id ) {
		anchor.id = `saai-term-${ ++anchorIdCounter }`;
	}

	return anchor.id;
}

// Must run after the tooltip is unhidden: an element with the `hidden`
// attribute has no layout box, so getBoundingClientRect() on it would
// report zero size and defeat the overflow check below.
function positionTooltip( tooltip, anchor ) {
	const rect = anchor.getBoundingClientRect();
	const scrollX = window.scrollX || document.documentElement.scrollLeft;
	const scrollY = window.scrollY || document.documentElement.scrollTop;

	tooltip.style.left = `${ rect.left + scrollX }px`;
	tooltip.style.top = `${ rect.bottom + scrollY + VIEWPORT_MARGIN }px`;

	const tooltipRect = tooltip.getBoundingClientRect();
	const overflowRight =
		tooltipRect.right - document.documentElement.clientWidth;

	if ( overflowRight > 0 ) {
		tooltip.style.left = `${
			rect.left + scrollX - overflowRight - VIEWPORT_MARGIN
		}px`;
	}

	if ( tooltip.getBoundingClientRect().left < 0 ) {
		tooltip.style.left = `${ scrollX + VIEWPORT_MARGIN }px`;
	}
}

function hideTooltip() {
	const tooltip = getTooltipElement();

	if ( ! tooltip || tooltip.hasAttribute( 'hidden' ) ) {
		return;
	}

	tooltip.setAttribute( 'hidden', '' );

	const shownFor = tooltip.getAttribute( 'data-saai-shown-for' );
	const anchor = shownFor ? document.getElementById( shownFor ) : null;

	if ( anchor ) {
		anchor.removeAttribute( 'aria-expanded' );
	}

	tooltip.removeAttribute( 'data-saai-shown-for' );
}

const { actions } = store( 'saai-knowledge/tooltip', {
	actions: {
		show() {
			const { ref } = getElement();
			const tooltip = getTooltipElement();

			if ( ! ref || ! tooltip ) {
				return;
			}

			// A singleton tooltip can only describe one anchor at a time;
			// hovering/focusing a new term reassigns it.
			hideTooltip();

			tooltip.textContent = ref.getAttribute( 'data-saai-tooltip' ) || '';
			tooltip.setAttribute(
				'data-saai-shown-for',
				ensureAnchorId( ref )
			);
			tooltip.removeAttribute( 'hidden' );
			positionTooltip( tooltip, ref );
			ref.setAttribute( 'aria-expanded', 'true' );
		},
		hide() {
			hideTooltip();
		},
		handleTouchStart() {
			const { ref } = getElement();

			if ( ref ) {
				ref.dataset.saaiTouchStarted = 'true';
			}
		},
		handleClick( event ) {
			const { ref } = getElement();

			// Only a touch tap needs interception: mouse/keyboard users
			// already saw the tooltip via hover/focus before the click, so
			// their click should navigate immediately. touchstart fires
			// only for a real touch, always before the synthesized click,
			// so it can't be confused with a mouse click or Enter keypress.
			if ( ! ref || 'true' !== ref.dataset.saaiTouchStarted ) {
				return;
			}

			delete ref.dataset.saaiTouchStarted;

			if ( 'true' === ref.dataset.saaiTapConfirmed ) {
				delete ref.dataset.saaiTapConfirmed;

				return; // Second tap on this anchor: let it navigate.
			}

			ref.dataset.saaiTapConfirmed = 'true';
			event.preventDefault();
			actions.show();
		},
	},
	callbacks: {
		initTooltipListeners() {
			if ( escapeListenerAttached ) {
				return;
			}

			escapeListenerAttached = true;

			window.addEventListener( 'keydown', ( event ) => {
				if ( 'Escape' === event.key ) {
					hideTooltip();
				}
			} );
		},
	},
} );
