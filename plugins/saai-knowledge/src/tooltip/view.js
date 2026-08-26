import { store, getElement } from '@wordpress/interactivity';

import './style.scss';

const TOOLTIP_ID = 'saai-tooltip';
const VIEWPORT_MARGIN = 8;
const TOUCH_START_EXPIRY_MS = 1500;
const TAP_CONFIRMED_EXPIRY_MS = 15000;

let escapeListenerAttached = false;
let anchorIdCounter = 0;

// The active mousemove listener from watchHoverExit() below, or null when
// nothing is being watched. Module-scope since at most one anchor can ever
// be the singleton tooltip's shown episode at a time, so at most one watch
// is ever meaningful.
let hoverExitWatch = null;

// The pointer's last known viewport position, kept up to date by a
// permanent listener set up once in initTooltipListeners() below — null
// until the first mousemove (e.g. a keyboard-only user who never moves the
// mouse at all). hide()'s blur branch needs this: a blur event carries no
// coordinates of its own, but still has to tell a pointer already
// travelling toward the tooltip (mid-transit through VIEWPORT_MARGIN's gap,
// not yet over either box) apart from one that's nowhere near it.
let lastPointerX = null;
let lastPointerY = null;

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

// Clears an anchor's self-expiring dataset flag, including the pending
// expiry timer armed for it (if any) — not just the dataset flag. Any
// caller that ends an anchor's flagged state from OUTSIDE the arming call's
// own timeout callback must go through this rather than deleting the
// dataset flag directly: otherwise that still-pending timer outlives this
// clear and later fires on its original schedule, deleting the flag a
// LATER, unrelated event on the same anchor may have re-armed by then.
// Shared by clearTapConfirmed() and handleClick()'s own touchStarted clear
// so this arm/clear discipline (see armExpiringFlag() below) only has to be
// implemented once for both flags.
function clearExpiringFlag( timerMap, anchor, datasetKey ) {
	window.clearTimeout( timerMap.get( anchor ) );
	timerMap.delete( anchor );
	delete anchor.dataset[ datasetKey ];
}

// Ends an anchor's tap-confirmed state. Every caller that ends an anchor's
// tap-confirmed episode from OUTSIDE handleClick's own arming site
// (dismissTooltip(), show()'s previous-anchor hand-off) must go through
// this rather than clearing the dataset flag directly — see
// clearExpiringFlag()'s own comment for why.
function clearTapConfirmed( anchor ) {
	clearExpiringFlag( tapConfirmedTimers, anchor, 'saaiTapConfirmed' );
}

// Arms an anchor's self-expiring dataset flag: cancels any timer already
// pending for this anchor in `timerMap` (see the WeakMaps' own comment
// above — an earlier stale timer must not delete a flag a LATER event just
// (re-)armed), sets `anchor.dataset[ datasetKey ]`, and schedules a timeout
// that clears both the flag and its own WeakMap entry after `ms`. Shared by
// handleTouchStart() (saaiTouchStarted) and handleClick()'s tap-confirm
// arming (saaiTapConfirmed) so this arm/cancel-previous/self-expire
// discipline only has to be implemented once. `onExpire`, when given, runs
// right after the flag/timer are cleared by the timeout itself (not on a
// cancellation from a later re-arm) — used by the tap-confirmed timer to
// also close a tooltip that's still open when its confirmation window lapses
// (see that call site's own comment for why a silent flag clear alone
// strands an open tooltip that then swallows the next tap).
function armExpiringFlag( timerMap, anchor, datasetKey, ms, onExpire ) {
	window.clearTimeout( timerMap.get( anchor ) );

	anchor.dataset[ datasetKey ] = 'true';

	timerMap.set(
		anchor,
		window.setTimeout( () => {
			delete anchor.dataset[ datasetKey ];
			timerMap.delete( anchor );

			if ( onExpire ) {
				onExpire();
			}
		}, ms )
	);
}

function getTooltipElement() {
	// Query by id AND role together, not id alone: page content, a theme, or
	// another plugin can independently use the same "saai-tooltip" id on an
	// unrelated element, and getElementById() only ever returns the FIRST
	// element in the document with that id — if that happens to be the
	// impostor, the real element Tooltip::render() (class-tooltip.php)
	// echoed becomes unreachable and, worse, the impostor would get
	// reparented into <body> and have its content overwritten below.
	// role="tooltip" is part of that same markup and vanishingly unlikely to
	// also collide, so requiring both together reliably finds ours
	// regardless of where either sits in the document.
	const tooltip = document.querySelector(
		`#${ TOOLTIP_ID }[role="tooltip"]`
	);

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
//
// Only a successfully-parsed finite value is cached here — see
// positionTooltip()'s own comment for why an unparseable read (e.g. the
// stylesheet hasn't finished loading/applying yet on this call) is used
// just for this one call instead of being written back to this module-scope
// variable, so a later call — once the stylesheet has actually applied —
// gets a chance to read and cache the real design cap instead of being
// stuck with a bogus one for the rest of the page's lifetime.
let designMaxWidth = null;

// Must run after the tooltip is unhidden: an element with the `hidden`
// attribute has no layout box, so offsetWidth/offsetHeight would report
// zero and defeat the clamping below. Both axes are clamped from a single
// size read, computed entirely in JS, and written once — reading the
// tooltip's size again after each write (as a naive write→measure→correct
// pass would) would force an extra synchronous layout reflow per show().
function positionTooltip( tooltip, anchor ) {
	// Both from documentElement.client*, not window.inner*: the latter
	// includes a horizontal scrollbar's height in innerHeight but
	// clientWidth excludes a vertical scrollbar's width, an inconsistent
	// pair of metrics that would throw off the overflow math below by the
	// scrollbar's size whenever one is present.
	const viewportWidth = document.documentElement.clientWidth;
	const viewportHeight = document.documentElement.clientHeight;

	// If the tooltip stylesheet hasn't finished loading/applying yet at the
	// very first call (the script module can hydrate and this can run
	// before a late-enqueued <link> finishes), getComputedStyle() falls
	// back to the browser default `none`, which parseFloat() can't turn
	// into a finite number. Using Infinity for just THIS call (rather than
	// caching it into designMaxWidth) means the viewport-width half of the
	// cap below still applies now, while a later call — once the
	// stylesheet has actually applied — gets to read and cache the real
	// 20rem design cap instead of being stuck with Infinity for the rest of
	// the page's lifetime.
	let effectiveMaxWidth = designMaxWidth;

	if ( null === effectiveMaxWidth ) {
		// Clear any inline max-width a previous call left behind before
		// reading: getComputedStyle() reports the resolved value, and an
		// inline style always wins the cascade over the external class
		// rule regardless of load order. Without this reset, once any
		// call below writes an inline px value, every later call here
		// (while still uncached) would read that stale inline value back
		// instead of the stylesheet's real 20rem design cap — silently
		// pinning designMaxWidth to a viewport-derived number forever.
		tooltip.style.maxWidth = '';

		const parsed = parseFloat( getComputedStyle( tooltip ).maxWidth );

		effectiveMaxWidth = Number.isFinite( parsed ) ? parsed : Infinity;

		if ( Number.isFinite( effectiveMaxWidth ) ) {
			designMaxWidth = effectiveMaxWidth;
		}
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
		effectiveMaxWidth
	) }px`;

	const tooltipWidth = tooltip.offsetWidth;
	const tooltipHeight = tooltip.offsetHeight;

	// Read after the max-width write above (not at the top of the function)
	// so it shares that write's forced layout with the offsetWidth/offsetHeight
	// reads above instead of forcing a second, separate reflow of its own.
	const anchorRect = anchor.getBoundingClientRect();

	// All clamping below is done in viewport-relative coordinates (no
	// scrollX/scrollY): anchorRect is already relative to the current
	// viewport, and these clamps only care how the tooltip fits within
	// that viewport right now, regardless of how far the page is scrolled.
	let left = anchorRect.left;
	let top = anchorRect.bottom + VIEWPORT_MARGIN;

	const overflowRight = left + tooltipWidth - viewportWidth;

	if ( overflowRight > 0 ) {
		left -= overflowRight + VIEWPORT_MARGIN;
	}

	if ( left < 0 ) {
		left = VIEWPORT_MARGIN;
	}

	// Flip above the anchor when there's no room below in the viewport,
	// but only when there IS room above — otherwise leave it below (the
	// user can scroll to read it) rather than clamp it somewhere that
	// hides it behind the anchor.
	const overflowBottom = top + tooltipHeight - viewportHeight;

	if ( overflowBottom > 0 ) {
		const above = anchorRect.top - tooltipHeight - VIEWPORT_MARGIN;

		if ( above >= 0 ) {
			top = above;
		}
	}

	// Convert from viewport-relative to the tooltip's actual containing
	// block — its offsetParent, which is <body> itself once
	// getTooltipElement() has reparented it there IF the theme happens to
	// give body a `position` other than static (or a transform/filter/etc.
	// ancestor establishes one), otherwise the initial containing block at
	// the document's own (0,0) origin. CSS `left`/`top` on this
	// absolutely-positioned element are measured from that containing
	// block's padding box, not from the viewport — getBoundingClientRect()
	// on it captures exactly that box's current on-page position (and
	// cancels out any scroll offset shared with anchorRect above, since
	// both are read in the same viewport-relative frame at this same
	// instant), so subtracting it works whether or not body carries its
	// own positioning, without this function having to know which case
	// applies.
	const offsetParent = tooltip.offsetParent;
	const parentRect = offsetParent
		? offsetParent.getBoundingClientRect()
		: { left: 0, top: 0 };

	tooltip.style.left = `${ left - parentRect.left }px`;
	tooltip.style.top = `${ top - parentRect.top }px`;
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
	// Whichever caller is actually ending the shown episode here — the
	// singleton itself, unconditionally, since at most one watch is ever
	// relevant (see hoverExitWatch's own comment) — a pending
	// watchHoverExit() from hide()'s hover-exit grace shouldn't outlive it:
	// left running, its next mousemove would re-evaluate a safe zone built
	// from a DIFFERENT anchor's now-stale `data-saai-shown-for` state.
	if ( hoverExitWatch ) {
		hoverExitWatch();
		hoverExitWatch = null;
	}

	if ( ! tooltip || tooltip.hasAttribute( 'hidden' ) ) {
		return null;
	}

	tooltip.setAttribute( 'hidden', '' );

	// Clears the previous anchor's description text along with hiding it:
	// aria-describedby explicitly requests this element's content
	// regardless of its own hidden state (the same mechanism that makes
	// "visually hidden helper text" patterns work at all), so leaving stale
	// text behind here would have assistive tech announce this anchor's
	// excerpt for a later anchor that has none of its own — show() early
	// returns for an anchor with nothing to preview without ever writing
	// new textContent, since hasPreview() is false for it.
	tooltip.textContent = '';

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

// Whether (x, y) — a mousemove event's viewport coordinates — falls inside
// the smallest rectangle containing BOTH the anchor's and the tooltip's
// current bounding boxes. Recomputed fresh on every call (not cached at
// watch-start) so a scroll/reflow mid-transit is still measured correctly.
// This is an approximation of a true "safe polygon" (a pointer resting in
// an unoccupied corner of that bounding rectangle, when the two boxes
// aren't already axis-aligned, would still count as safe) — accepted
// because it only ever errs toward keeping the tooltip open longer than
// strictly necessary, never toward closing it prematurely out from under a
// pointer that's still genuinely travelling toward it.
function isWithinHoverSafeZone( anchor, tooltip, x, y ) {
	const anchorRect = anchor.getBoundingClientRect();
	const tooltipRect = tooltip.getBoundingClientRect();

	const left = Math.min( anchorRect.left, tooltipRect.left );
	const right = Math.max( anchorRect.right, tooltipRect.right );
	const top = Math.min( anchorRect.top, tooltipRect.top );
	const bottom = Math.max( anchorRect.bottom, tooltipRect.bottom );

	return x >= left && x <= right && y >= top && y <= bottom;
}

// Defers dismissing `anchor`'s tooltip past the instant its mouseleave
// fired: mouseleave fires the moment the pointer exits the anchor's own
// box, before it has necessarily crossed VIEWPORT_MARGIN's gap to reach the
// tooltip below/above it, and dismissing right then would make it
// impossible to move the pointer into the tooltip to read more or select
// its text (WCAG 1.4.13 "Content on Hover or Focus" requires hover-
// triggered content stay reachable, with no arbitrary time limit on doing
// so — deliberately NOT a setTimeout-based grace period for that reason).
// Tracks real pointer position instead: as long as it stays within
// isWithinHoverSafeZone()'s bounds — which the pointer must cross to reach
// the tooltip at all — the episode stays open; the instant it doesn't, this
// is the genuine, unambiguous signal to close. Supersedes needing any
// listener on the tooltip element itself: this already re-evaluates on
// every pointer move, including ones over the tooltip's own box (part of
// the safe zone), so a separate tooltip-mouseleave handler would be
// redundant.
function watchHoverExit( tooltip, anchor ) {
	if ( hoverExitWatch ) {
		hoverExitWatch();
	}

	const onMouseMove = ( event ) => {
		if (
			isWithinHoverSafeZone(
				anchor,
				tooltip,
				event.clientX,
				event.clientY
			)
		) {
			return;
		}

		hoverExitWatch();
		hoverExitWatch = null;

		if ( anchor.id === tooltip.getAttribute( 'data-saai-shown-for' ) ) {
			dismissTooltip( tooltip );
		}
	};

	document.addEventListener( 'mousemove', onMouseMove, { passive: true } );

	hoverExitWatch = () =>
		document.removeEventListener( 'mousemove', onMouseMove );
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
		hide( event ) {
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

			// mouseleave and blur both route here, but the anchor can be
			// hovered AND focused at once (e.g. a keyboard user tabs to a
			// link the mouse cursor also happens to be resting on, or vice
			// versa). Only end the episode once BOTH have been released —
			// otherwise losing just one input modality (moving the mouse
			// away while focus remains, or tabbing on while still hovered)
			// would close a tooltip the other modality still wants open. By
			// the time either event fires, the browser has already updated
			// :hover/activeElement to reflect it, so this correctly stays
			// open on the first event and only closes on whichever fires
			// last.
			if (
				ref.matches( ':hover' ) ||
				ref.ownerDocument.activeElement === ref
			) {
				return;
			}

			// Only a mouse-triggered mouseleave gets the hover-exit watch
			// below (see its own comment: mouseleave firing here doesn't by
			// itself mean the pointer is done with this episode, only that
			// it's left the anchor's own box — it may still be travelling
			// toward the tooltip through VIEWPORT_MARGIN's gap). blur
			// (keyboard focus moving away, e.g. Tab) has no such "pointer
			// transiting a gap" concern to defer for, and waiting on mouse
			// movement that may never come — a keyboard-only user's mouse
			// just sitting still — would otherwise leave this open
			// indefinitely instead of closing it right away as expected.
			if ( event && 'mouseleave' === event.type ) {
				watchHoverExit( tooltip, ref );

				return;
			}

			// blur otherwise ends the episode immediately (see above) — except
			// when the pointer is already travelling toward (or resting on)
			// the tooltip as focus leaves the anchor: tab to a term, then move
			// the mouse toward the tooltip before tabbing again. That sequence
			// never starts the watch via the anchor's own mouseleave, because
			// the activeElement check above keeps returning early for as long
			// as focus stays on the anchor — so blur is the only event left to
			// pick it up. Checking the last known pointer position against the
			// same safe zone watchHoverExit() itself watches (rather than only
			// the tooltip's own :hover state) also covers the pointer still
			// being mid-transit through VIEWPORT_MARGIN's gap, not yet over
			// either box, when blur fires. A null lastPointerX (no mousemove
			// has ever fired — a keyboard-only user) keeps the keyboard-only
			// case above working: this is false and blur still dismisses right
			// away instead of waiting on mouse movement that may never come.
			if (
				event &&
				'blur' === event.type &&
				null !== lastPointerX &&
				isWithinHoverSafeZone(
					ref,
					tooltip,
					lastPointerX,
					lastPointerY
				)
			) {
				watchHoverExit( tooltip, ref );

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
			// clearExpiringFlag()/armExpiringFlag() already apply
			// everywhere else in this file.
			clearExpiringFlag( touchStartTimers, ref, 'saaiTouchStarted' );

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
			//
			// onExpire also closes the tooltip if it's still open for THIS
			// anchor when the window lapses: without this, a reader who
			// takes longer than TAP_CONFIRMED_EXPIRY_MS to finish reading
			// (tooltip still visibly open, no mouseleave/blur to close it on
			// touch) has the confirmed-flag cleared out from under an
			// otherwise-unchanged tooltip. Their next tap is then read as a
			// fresh first tap — intercepted (preventDefault/stopPropagation)
			// — but show() is skipped too, since the tooltip already shows
			// this ref, so the tap visibly does nothing and a THIRD tap is
			// needed to navigate. Proactively closing it here instead makes
			// the next tap unambiguous: nothing shown -> show() runs again.
			armExpiringFlag(
				tapConfirmedTimers,
				ref,
				'saaiTapConfirmed',
				TAP_CONFIRMED_EXPIRY_MS,
				() => {
					const tooltip = getTooltipElement();

					if (
						tooltip &&
						ref.id === tooltip.getAttribute( 'data-saai-shown-for' )
					) {
						dismissTooltip( tooltip );
					}
				}
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

			// Kept up to date for the lifetime of the page so hide()'s blur
			// branch (see its own comment) always has a recent pointer
			// position to test against the hover safe zone, even though blur
			// itself carries no coordinates — a listener started only once a
			// tooltip is already open would miss exactly the movement that
			// happens right before the blur that needs it.
			document.addEventListener(
				'mousemove',
				( event ) => {
					lastPointerX = event.clientX;
					lastPointerY = event.clientY;
				},
				{ passive: true }
			);

			// A tooltip can stay open long enough (up to
			// TAP_CONFIRMED_EXPIRY_MS on touch, or indefinitely on
			// hover/focus) for the viewport to be resized/rotated, for a
			// late-loading image/web font to shift layout, or for a
			// scrollable ancestor (e.g. a modal/side panel with
			// `overflow: auto`) to scroll — moving the anchor without
			// positionTooltip() ever re-running, since it only runs once
			// when the tooltip is shown. Shared by the ResizeObserver and
			// scroll listener below so this "reposition whichever anchor is
			// currently shown, if any" lookup isn't duplicated between them.
			const repositionIfShown = () => {
				const tooltip = getTooltipElement();
				const shownFor =
					tooltip && tooltip.getAttribute( 'data-saai-shown-for' );
				const anchor = shownFor && document.getElementById( shownFor );

				if ( tooltip && anchor && ! tooltip.hasAttribute( 'hidden' ) ) {
					positionTooltip( tooltip, anchor );
				}
			};

			// Observing documentElement's own box, rather than window's
			// `resize` event (which only fires for viewport-size changes),
			// also catches reflow-driven layout shifts that grow/shrink the
			// document itself.
			new ResizeObserver( repositionIfShown ).observe(
				document.documentElement
			);

			// `scroll` doesn't bubble, so a listener on window in the
			// bubbling phase would only ever see window's own scroll — not
			// one fired on a scrollable ancestor element somewhere inside
			// the page. The capturing phase, unlike bubbling, always runs
			// top-down from window through every ancestor before reaching
			// the actual scrolled element, so a capture listener here still
			// sees scroll events target at any such ancestor.
			window.addEventListener( 'scroll', repositionIfShown, {
				capture: true,
				passive: true,
			} );
		},
	},
} );
