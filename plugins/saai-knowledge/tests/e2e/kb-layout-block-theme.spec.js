const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { createKbFixtures, deleteKbFixtures } = require( './fixtures' );

test.describe( 'KB two-column layout — block theme (Twenty Twenty-Five)', () => {
	/** @type {{term: Object, post: Object}} */
	let fixtures;
	/** @type {string[]} */
	let consoleErrors;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activateTheme( 'twentytwentyfive' );
		fixtures = await createKbFixtures( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await deleteKbFixtures( requestUtils, fixtures );
	} );

	test.beforeEach( ( { page } ) => {
		consoleErrors = [];
		page.on( 'console', ( message ) => {
			if ( message.type() === 'error' ) {
				consoleErrors.push( message.text() );
			}
		} );
	} );

	test( 'renders the article as a three-column layout with sidebar, breadcrumbs, and TOC', async ( {
		page,
	} ) => {
		await page.goto( fixtures.post.link );

		await expect( page.locator( '.saai-kb-layout--article' ) ).toBeVisible();
		await expect( page.locator( '.saai-kb-sidebar' ) ).toBeVisible();
		await expect( page.locator( '.saai-kb-toc' ) ).toBeVisible();
		await expect( page.locator( '.saai-breadcrumbs' ) ).toBeVisible();
		await expect(
			page.locator( '.saai-kb-layout__content h1' )
		).toContainText( fixtures.post.title.rendered );

		expect( consoleErrors ).toEqual( [] );
	} );

	test( 'the table of contents navigates to the clicked heading', async ( {
		page,
	} ) => {
		await page.goto( fixtures.post.link );

		await page
			.locator( '.saai-kb-toc a', { hasText: 'Second Section' } )
			.click();

		await expect( page.locator( 'h2', { hasText: 'Second Section' } ) ).toBeInViewport();
		expect( consoleErrors ).toEqual( [] );
	} );

	test( 'renders the KB hub archive with the sidebar and the article', async ( {
		page,
	} ) => {
		await page.goto( '/kb/' );

		await expect( page.locator( '.saai-kb-layout--archive' ) ).toBeVisible();
		await expect( page.locator( '.saai-kb-sidebar' ) ).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: fixtures.post.title.rendered } )
		).toBeVisible();

		expect( consoleErrors ).toEqual( [] );
	} );

	test( 'renders the category archive with the sidebar and the article', async ( {
		page,
	} ) => {
		await page.goto( fixtures.term.link );

		await expect( page.locator( '.saai-kb-layout--archive' ) ).toBeVisible();
		await expect( page.locator( '.saai-kb-sidebar' ) ).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: fixtures.post.title.rendered } )
		).toBeVisible();

		expect( consoleErrors ).toEqual( [] );
	} );

	// The layout's <summary> toggle is CSS-hidden once the container is wide
	// enough to lay the panels out as grid columns, and a native <details>'s
	// visibility can't be forced open by CSS — only kb-layout.js reopening it
	// on resize prevents a panel manually collapsed at a narrow width from
	// staying stuck hidden after the window grows past the breakpoint.
	test( 'reopens a manually-collapsed sidebar once the layout grows past the breakpoint', async ( {
		page,
	} ) => {
		await page.setViewportSize( { width: 375, height: 800 } );
		await page.goto( fixtures.post.link );

		const sidebar = page.locator( '.saai-kb-layout__sidebar' );
		await sidebar.locator( 'summary' ).click();
		await expect( sidebar ).not.toHaveJSProperty( 'open', true );

		await page.setViewportSize( { width: 1400, height: 1000 } );
		await expect( sidebar ).toHaveJSProperty( 'open', true );

		expect( consoleErrors ).toEqual( [] );
	} );

	// kb-toc renders no markup for an article with fewer than two headings,
	// so its static wrapper (and 220px grid column) must be hidden rather
	// than left as an empty "Table of contents" panel.
	test( 'hides the TOC panel for an article with no headings', async ( {
		page,
		requestUtils,
	} ) => {
		const noHeadingsPost = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/saai_kb',
			data: {
				title: `E2E KB No Headings ${ Math.random()
					.toString( 36 )
					.slice( 2, 8 ) }`,
				status: 'publish',
				content:
					'<!-- wp:paragraph --><p>No headings here.</p><!-- /wp:paragraph -->',
			},
		} );

		try {
			await page.goto( noHeadingsPost.link );

			await expect( page.locator( '.saai-kb-layout--article' ) ).toBeVisible();
			await expect( page.locator( '.saai-kb-layout__toc' ) ).toBeHidden();

			expect( consoleErrors ).toEqual( [] );
		} finally {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/saai_kb/${ noHeadingsPost.id }`,
				params: { force: true },
			} );
		}
	} );
} );
