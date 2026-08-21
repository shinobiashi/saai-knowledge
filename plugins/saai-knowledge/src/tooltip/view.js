import { store, getElement } from '@wordpress/interactivity';

import './style.scss';

const TOOLTIP_ID = 'saai-tooltip';
const VIEWPORT_MARGIN = 8;
const TOUCH_START_EXPIRY_MS = 1500;
const TAP_CONFIRMED_EXPIRY_MS = 15000;

let escapeListenerAttached = false;
let anchorIdCounter = 0;

// Each anchor's touchstart/tap-confirmed expiry is a fresh setTimeout per
// event, uncoalesced with any timer already pending for that same anchor.
// Without cancelling the previous one, an earlier touchstart's (or tap's)
// stale expiry timer still fires on schedule and deletes the flag a LATER,
// unrelated touchstart/tap on the same anchor just (re-)armed — e.g. a
// touch that turns into a drag/scroll (no click, flag left armed) followed
// more than TOUCH_START_EXPIRY_MS later by a genuine tap on the same
// anchor: the first timer deletes the flag the second touchstart just set,
// so the second tap's click is misread as a plain (non-touch) click and
// navigates immediately without ever showing the tooltip. Tracked outside
// the anchor's own dataset (which only holds strings) so the previous
// timer can be cancelled before arming a new one.
const touchStartTimers = new WeakMap();
const tapConfirmedTimers = new WeakMap();

// Ends an anchor's tap-confirmed state, including the pending expiry timer
// armed for it (if any) — not just the dataset flag. Every caller that ends
// an anchor's tap-confirmed episode from OUTSIDE handleClick's own arming
// site (dismissTooltip(), show()'s previous-anchor hand-off) must go
// through this rather than deleting the dataset flag directly: otherwise
// that still-pending timer outlives this clear and later fires on its
// original schedule, deleting the flag a LATER, unrelated tap on the same
// anchor may have re-armed by then — the same stale-timer race
// handleTouchStart/handleClick's own arming sites already guard against for
// themselves (see the WeakMaps' comment above), just triggered from a
// different call site this time.
function clearTapConfirmed( anchor ) {
	window.clearTimeout( tapConfirmedTimers.get( anchor ) );
	tapConfirmedTimers.delete( anchor );
	delete anchor.dataset.saaiTapConfirmed;
}

// Arms an anchor's self-expiring dataset flag: cancels any timer already
// pending for this anchor in `timerMap` (see the WeakMaps' own comment
// above — an earlier stale timer must not delete a flag a LATER event just
// (re-)armed), sets `anchor.dataset[ datasetKey ]`, and schedules a timeout
// that clears both the flag and its own WeakMap entry after `ms`. Shared by
// handleTouchStart() (saaiTouchStarted) and handleClick()'s tap-confirm
// arming (saaiTapConfirmed) so this arm/cancel-previous/self-expire
// discipline only has to be implemented once.
function armExpiringFlag( timerMap, anchor, datasetKey, ms ) {
	window.clearTimeout( timerMap.get( anchor ) );

	anchor.dataset[ datasetKey ] = 'true';

	timerMap.set(
		anchor,
		window.setTimeout( () => {
			delete anchor.dataset[ datasetKey ];
			timerMap.delete( anchor );
		}, ms )
	);
}

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

// The tooltip's design-intended cap (style.scss's `max-width: 20rem`),
// read once from the stylesheet the first time positionTooltip() runs —
// i.e. before it ever writes its own inline max-width below, which would
// otherwise shadow the CSS value for every getComputedStyle() call after
// the first. Cached at module scope so later calls compare against the
// ORIGINAL design cap, not a previous call's own inline override.
let designMaxWidth = null;

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
	// Both from documentElement.client*, not window.inner*: the latter
	// includes a horizontal scrollbar's height in innerHeight but
	// clientWidth excludes a vertical scrollbar's width, an inconsistent
	// pair of metrics that would throw off the overflow math below by the
	// scrollbar's size whenever one is present.
	const viewportWidth = document.documentElement.clientWidth;
	const viewportHeight = document.documentElement.clientHeight;

	if ( null === designMaxWidth ) {
		designMaxWidth =
			parseFloat( getComputedStyle( tooltip ).maxWidth ) || Infinity;
	}

	// Caps the tooltip at the SMALLER of the design's own max-width and the
	// viewport's available width — not the viewport width alone, which on
	// an ordinary desktop viewport is far wider than the compact bubble the
	// design intends and would let a long excerpt stretch the tooltip
	// across most of the screen instead of wrapping within ~20rem. The
	// viewport half of this cap still has to be written as a `px` inline
	// style computed from `clientWidth` rather than left to a CSS `vw`
	// unit: `100vw` includes a reserved-space vertical scrollbar's width
	// while `clientWidth` excludes it, so the two would disagree (and let
	// the tooltip render wider than the horizontal clamp below assumes) on
	// any desktop browser with a classic (non-overlay) scrollbar. Written
	// before reading offsetWidth so the measurement below reflects it.
	tooltip.style.maxWidth = `${ Math.min(
		viewportWidth - VIEWPORT_MARGIN * 2,
		designMaxWidth
	) }px`;

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
// the tooltip was already hidden. Doesn't touch saaiTapConfirmed itself:
// show() needs to keep it when re-entering for the SAME anchor (see its own
// comment), so only dismissTooltip() — used by every OTHER caller, which
// all want the same "this anchor's episode is over" behavior — clears it.
// Accepts an already-resolved tooltip element so callers that fetched one
// themselves (show(), hide()) don't force a second getElementById() lookup
// and reparent check for the same element within the same event handler.
function hideTooltip( tooltip = getTooltipElement() ) {
	if ( ! tooltip || tooltip.hasAttribute( 'hidden' ) ) {
		return null;
	}

	tooltip.setAttribute( 'hidden', '' );

	const shownFor = tooltip.getAttribute( 'data-saai-shown-for' );
	const anchor = shownFor ? document.getElementById( shownFor ) : null;

	// Set back to "false" rather than removed: build_anchor() (class-autolinker.php)
	// now renders aria-expanded="false" as the anchor's baseline specifically so an
	// anchor tabbed to before ever being hovered/tapped still has an expanded/collapsed
	// state to announce — removing the attribute here would return the anchor to that
	// same state-less condition after its first show/hide cycle, undoing that baseline.
	if ( anchor ) {
		anchor.setAttribute( 'aria-expanded', 'false' );
	}

	tooltip.removeAttribute( 'data-saai-shown-for' );

	return anchor;
}

// An explicit dismiss (mouseleave/blur/Escape) ends the shown anchor's
// episode outright, unlike show()'s hand-off to a new anchor — so the next
// tap on it is always treated as a fresh first tap.
function dismissTooltip( tooltip = getTooltipElement() ) {
	const anchor = hideTooltip( tooltip );

	if ( anchor ) {
		clearTapConfirmed( anchor );
	}
}

// Whether an anchor has anything to preview. Shared by show() (skip
// displaying an empty bubble) and handleClick() (skip intercepting a tap
// that would otherwise navigate nowhere) so the "empty" definition can't
// drift between the two call sites.
function hasPreview( anchor ) {
	return '' !== ( anchor.getAttribute( 'data-saai-tooltip' ) || '' ).trim();
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
			// hovering/focusing ANY new anchor ends the previous one's
			// episode, even when this new anchor turns out to have nothing
			// to preview (checked below) — otherwise a still-visible
			// tooltip left over from a different anchor would keep
			// describing that anchor while this one's aria-describedby now
			// also points at it (e.g. tabbing from a term with an excerpt
			// straight to a stub term with none). Only clear the PREVIOUS
			// anchor's tap-confirmed flag when it's a genuinely different
			// anchor: handleClick's own show() call re-enters here for the
			// SAME anchor it just marked tap-confirmed (a mobile browser
			// that also synthesizes mouseenter before click already showed
			// it once), and clearing that flag on itself would defeat the
			// second-tap-navigates behavior entirely.
			const previousAnchor = hideTooltip( tooltip );

			if ( previousAnchor && previousAnchor !== ref ) {
				clearTapConfirmed( previousAnchor );
			}

			// A term with no excerpt and no body to fall back on (e.g. a
			// stub glossary entry) has nothing to preview — showing an
			// empty bubble would be confusing, so leave the tooltip hidden
			// (handleClick separately avoids treating this as an
			// interceptable tap in the first place, so this mainly guards
			// the hover/focus path).
			if ( ! hasPreview( ref ) ) {
				return;
			}

			tooltip.textContent = ref.getAttribute( 'data-saai-tooltip' );
			tooltip.setAttribute(
				'data-saai-shown-for',
				ensureAnchorId( ref )
			);
			tooltip.removeAttribute( 'hidden' );
			positionTooltip( tooltip, ref );
			ref.setAttribute( 'aria-expanded', 'true' );
		},
		hide() {
			const { ref } = getElement();
			const tooltip = getTooltipElement();

			// mouseleave/blur can arrive for an anchor that ISN'T the one the
			// singleton tooltip is currently describing (e.g. pointer-order
			// races between adjacent term links, or an anchor that never
			// actually triggered show() in the first place). Hiding
			// unconditionally would then dismiss a DIFFERENT anchor's
			// just-shown tooltip out from under it; only end the episode when
			// this anchor is actually the one described.
			if (
				! ref ||
				! tooltip ||
				ref.id !== tooltip.getAttribute( 'data-saai-shown-for' )
			) {
				return;
			}

			dismissTooltip( tooltip );
		},
		handleTouchStart() {
			const { ref } = getElement();

			if ( ! ref ) {
				return;
			}

			// A real tap's synthesized click follows touchstart on the same
			// anchor once the finger lifts — which can be over a second
			// after touchstart for a deliberate, unhurried tap that never
			// moves (not a drag), so the window has to be generous enough
			// to still cover that click when it arrives. If click never
			// arrives at all — the touch turned into a scroll/drag, or a
			// multi-touch gesture cancelled it — nothing else would clear
			// this flag, and a later mouse click or keyboard Enter on the
			// same anchor would be misidentified as a touch tap (silently
			// swallowed by preventDefault() instead of navigating).
			// Self-expiring it bounds that window.
			armExpiringFlag(
				touchStartTimers,
				ref,
				'saaiTouchStarted',
				TOUCH_START_EXPIRY_MS
			);
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

			// Also cancels the still-pending expiry timer armed for this
			// flag in handleTouchStart(), not just the flag itself —
			// otherwise it outlives this click and fires later on its
			// original schedule against whatever this WeakMap entry has
			// been reused for by then, the same stale-timer discipline
			// armExpiringFlag()/clearTapConfirmed() already apply
			// everywhere else in this file.
			window.clearTimeout( touchStartTimers.get( ref ) );
			touchStartTimers.delete( ref );
			delete ref.dataset.saaiTouchStarted;

			if ( 'true' === ref.dataset.saaiTapConfirmed ) {
				clearTapConfirmed( ref );

				return; // Second tap on this anchor: let it navigate.
			}

			// Nothing to preview (see show()'s same check via hasPreview())
			// — don't intercept the tap at all, or it'd navigate nowhere:
			// no tooltip appears (show() no-ops) and the link never gets a
			// second tap to complete the navigation it just swallowed.
			if ( ! hasPreview( ref ) ) {
				return;
			}

			// Bounds how long a shown-but-forgotten tooltip keeps this
			// anchor's next tap classified as "second tap, navigate" —
			// mouseleave/blur/Escape/a different anchor's show() already
			// clear it on their own triggers, but touch has no reliable
			// equivalent of "the user looked away" (mouseleave never fires,
			// and blur only if something else takes focus), so a tap
			// returning much later would otherwise still read as stale
			// confirmation and navigate without ever re-showing the tooltip.
			// Long enough to cover actually reading the excerpt (up to the
			// ~55-word fallback Autolinker::entry_excerpt() can produce)
			// before a deliberate second tap — a short window here would
			// make a normal "read it, then tap again to go" interaction
			// misfire as a fresh first tap instead of navigating.
			armExpiringFlag(
				tapConfirmedTimers,
				ref,
				'saaiTapConfirmed',
				TAP_CONFIRMED_EXPIRY_MS
			);

			// stopPropagation(), not just preventDefault(): preventDefault()
			// only cancels the anchor's OWN navigation, but a theme/page
			// builder sometimes wraps prose in a click-to-navigate container
			// (e.g. a card div with its own click listener) — without this,
			// intercepting the anchor's first tap would still let that
			// wrapper's click handler fire and navigate away underneath the
			// tooltip it just opened.
			event.preventDefault();
			event.stopPropagation();

			// On a browser that also synthesized mouseenter/focus for this
			// same tap (the reason handleTouchStart/handleClick exist at
			// all — see their own comments), show() already ran for this
			// exact ref before this click arrived: re-running it would
			// hide-then-reshow the same content, forcing a redundant
			// style write + offsetWidth/offsetHeight layout read
			// (positionTooltip()) for a tooltip that's already correctly
			// displayed. ref.id is only ever set by a PRIOR show() call
			// (ensureAnchorId()), so an unset id here correctly falls
			// through to actions.show() for this anchor's actual first
			// display.
			const tooltip = getTooltipElement();

			if (
				! tooltip ||
				ref.id !== tooltip.getAttribute( 'data-saai-shown-for' )
			) {
				actions.show();
			}
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
					dismissTooltip();
				}
			} );
		},
	},
} );
