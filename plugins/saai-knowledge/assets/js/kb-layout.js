/**
 * Keeps the KB layout's sidebar/TOC <details> panels open once their
 * container crosses the breakpoint where kb-layout.css switches from a
 * foldable accordion to a fixed grid column (and hides the <summary>
 * toggle that would otherwise let a visitor reopen a collapsed panel).
 *
 * <details> visibility is governed solely by its `open` attribute — CSS
 * cannot force collapsed content to display. Without this script, a panel
 * a visitor closed at a narrow container width stays inaccessible after
 * the container grows past the breakpoint (e.g. rotating a device or
 * resizing the window), because the toggle used to reopen it is hidden
 * at that width.
 *
 * Plain script rather than an Interactivity API store: this fixes template
 * layout chrome (registered by Template_Loader, alongside the equally plain
 * kb-layout.css), not a block's own view.js, so it sits outside the blocks'
 * src/ build and doesn't need a store/context to track — it only ever
 * pushes `open` from false to true.
 */
( function () {
	// Keep in sync with the `@container (min-width: ...)` breakpoints in
	// kb-layout.css: the article layout reserves two 220px side columns
	// (sidebar + TOC) instead of archive's one, so it switches to columns at
	// a higher container width. Not read from CSS custom properties at
	// runtime — with two breakpoints used in exactly these two places, that
	// indirection would outweigh the value of removing the duplicated literals.
	var BREAKPOINTS = {
		'saai-kb-layout--article': 900,
		'saai-kb-layout--archive': 600,
	};

	if ( typeof ResizeObserver === 'undefined' ) {
		return;
	}

	function breakpointFor( layout ) {
		for ( var modifierClass in BREAKPOINTS ) {
			if ( layout.classList.contains( modifierClass ) ) {
				return BREAKPOINTS[ modifierClass ];
			}
		}

		return 600;
	}

	var observer = new ResizeObserver( function ( entries ) {
		entries.forEach( function ( entry ) {
			var boxSize = entry.contentBoxSize && entry.contentBoxSize[ 0 ];
			var width = boxSize
				? boxSize.inlineSize
				: entry.contentRect.width;

			if ( width < breakpointFor( entry.target ) ) {
				return;
			}

			entry.target
				.querySelectorAll( '.saai-kb-layout__sidebar, .saai-kb-layout__toc' )
				.forEach( function ( details ) {
					details.open = true;
				} );
		} );
	} );

	document.querySelectorAll( '.saai-kb-layout' ).forEach( function ( layout ) {
		observer.observe( layout );
	} );
} )();
