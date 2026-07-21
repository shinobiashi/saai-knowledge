const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { createKbFixtures, deleteKbFixtures } = require( './fixtures' );

test.describe( 'KB two-column layout — classic theme (Twenty Twenty-One)', () => {
	/** @type {{term: Object, post: Object}} */
	let fixtures;
	/** @type {string[]} */
	let consoleErrors;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activateTheme( 'twentytwentyone' );
		fixtures = await createKbFixtures( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await deleteKbFixtures( requestUtils, fixtures );
		// Leave the site on the default block theme for later manual checks.
		await requestUtils.activateTheme( 'twentytwentyfive' );
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
} );
