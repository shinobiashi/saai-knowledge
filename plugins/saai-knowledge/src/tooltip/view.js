import { store, getElement } from '@wordpress/interactivity';

import './style.scss';

const TOOLTIP_ID = 'saai-tooltip';
const VIEWPORT_MARGIN = 8;

let escapeListenerAttached = false;
let anchorIdCounter = 0;

function getTooltipElement() {
	const tooltip = document.getElementById( TOOLTIP_ID );

	// Tooltip::render() echoes this element wherever the active theme
	// happens to place wp_footer() output. `.saai-tooltip` is
	// `position: absolute`, so if the theme wraps that output in a
	// positioned/transformed ancestor (e.g. a sticky footer wrapper), that
	// ancestor — not the document — becomes its containing block, which
	// breaks positionTooltip()'s document-relative coordinate math.
	// Reparenting it to be a direct child of <body> the first time it's
	// looked up guarantees a predictable containing block regardless of
	// where the theme placed it; cheap on every later call since the
	// parentElement check short-circuits once it's already there.
	if ( tooltip && tooltip.parentElement !== document.body ) {
		document.body.appendChild( tooltip );
	}

	return tooltip;
}

// data-saai-term-id repeats across anchors on pages that loop the same post
// more than once (e.g. an archive linking the same glossary term from two
// different articles' excerpts) — an id derived from it alone could collide,
// so each anchor gets its own id from a page-wide counter the first time
// it's shown. The counter alone doesn't guarantee uniqueness against ids
// that already exist elsewhere in the DOM (authored content, another
// plugin/theme), so each candidate is checked before it's assigned.
function ensureAnchorId( anchor ) {
	if ( ! anchor.id ) {
		let candidate = `saai-term-${ ++anchorIdCounter }`;

		while ( document.getElementById( candidate ) ) {
			candidate = `saai-term-${ ++anchorIdCounter }`;
		}

		anchor.id = candidate;
	}

	return anchor.id;
}

// Must run after the tooltip is unhidden: an element with the `hidden`
// attribute has no layout box, so offsetWidth/offsetHeight would report
// zero and defeat the clamping below. Both axes are clamped from a single
// size read, computed entirely in JS, and written once — reading the
// tooltip's size again after each write (as a naive write→measure→correct
// pass would) would force an extra synchronous layout reflow per show().
function positionTooltip( tooltip, anchor ) {
	const anchorRect = anchor.getBoundingClientRect();
	const scrollX = window.scrollX || document.documentElement.scrollLeft;
	const scrollY = window.scrollY || document.documentElement.scrollTop;
	const viewportWidth = document.documentElement.clientWidth;
	const viewportHeight = window.innerHeight;
	const tooltipWidth = tooltip.offsetWidth;
	const tooltipHeight = tooltip.offsetHeight;

	let left = anchorRect.left + scrollX;
	let top = anchorRect.bottom + scrollY + VIEWPORT_MARGIN;

	const overflowRight = left + tooltipWidth - ( scrollX + viewportWidth );

	if ( overflowRight > 0 ) {
		left -= overflowRight + VIEWPORT_MARGIN;
	}

	if ( left < scrollX ) {
		left = scrollX + VIEWPORT_MARGIN;
	}

	// Flip above the anchor when there's no room below in the viewport,
	// but only when there IS room above — otherwise leave it below (the
	// user can scroll to read it) rather than clamp it somewhere that
	// hides it behind the anchor.
	const overflowBottom = top + tooltipHeight - ( scrollY + viewportHeight );

	if ( overflowBottom > 0 ) {
		const above =
			anchorRect.top + scrollY - tooltipHeight - VIEWPORT_MARGIN;

		if ( above >= scrollY ) {
			top = above;
		}
	}

	tooltip.style.left = `${ left }px`;
	tooltip.style.top = `${ top }px`;
}

// Returns the anchor that WAS shown (before this call hid it), or null if
// the tooltip was already hidden — callers decide what "ending this
// anchor's shown episode" should do to its saaiTapConfirmed flag (see the
// two call sites below; they can't share one rule).
function hideTooltip() {
	const tooltip = getTooltipElement();

	if ( ! tooltip || tooltip.hasAttribute( 'hidden' ) ) {
		return null;
	}

	tooltip.setAttribute( 'hidden', '' );

	const shownFor = tooltip.getAttribute( 'data-saai-shown-for' );
	const anchor = shownFor ? document.getElementById( shownFor ) : null;

	if ( anchor ) {
		anchor.removeAttribute( 'aria-expanded' );
	}

	tooltip.removeAttribute( 'data-saai-shown-for' );

	return anchor;
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
			// hovering/focusing a new term reassigns it. Only clear the
			// PREVIOUS anchor's tap-confirmed flag when it's a genuinely
			// different anchor: handleClick's own show() call re-enters
			// here for the SAME anchor it just marked tap-confirmed (a
			// mobile browser that also synthesizes mouseenter before click
			// already showed it once), and clearing that flag on itself
			// would defeat the second-tap-navigates behavior entirely.
			const previousAnchor = hideTooltip();

			if ( previousAnchor && previousAnchor !== ref ) {
				delete previousAnchor.dataset.saaiTapConfirmed;
			}

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
			// An explicit dismiss (mouseleave/blur) ends this anchor's
			// shown episode outright, unlike show()'s hand-off to a new
			// anchor — so the next tap on it is always a fresh first tap.
			const anchor = hideTooltip();

			if ( anchor ) {
				delete anchor.dataset.saaiTapConfirmed;
			}
		},
		handleTouchStart() {
			const { ref } = getElement();

			if ( ! ref ) {
				return;
			}

			ref.dataset.saaiTouchStarted = 'true';

			// A real tap's synthesized click always follows touchstart on
			// the same anchor within well under a second on every mobile
			// browser. If it never arrives — the touch turned into a
			// scroll/drag, or a multi-touch gesture cancelled it — nothing
			// else would clear this flag, and a later mouse click or
			// keyboard Enter on the same anchor would be misidentified as
			// a touch tap (silently swallowed by preventDefault() instead
			// of navigating). Self-expiring it bounds that window.
			window.setTimeout( () => {
				delete ref.dataset.saaiTouchStarted;
			}, 750 );
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
					const anchor = hideTooltip();

					if ( anchor ) {
						delete anchor.dataset.saaiTapConfirmed;
					}
				}
			} );
		},
	},
} );
