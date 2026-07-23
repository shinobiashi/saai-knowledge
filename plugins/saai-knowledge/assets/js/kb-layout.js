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
 */
( function () {
	// Keep in sync with the `@container (min-width: ...)` breakpoint in kb-layout.css.
	var BREAKPOINT = 600;

	if ( typeof ResizeObserver === 'undefined' ) {
		return;
	}

	var observer = new ResizeObserver( function ( entries ) {
		entries.forEach( function ( entry ) {
			var boxSize = entry.contentBoxSize && entry.contentBoxSize[ 0 ];
			var width = boxSize
				? boxSize.inlineSize
				: entry.contentRect.width;

			if ( width < BREAKPOINT ) {
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
