const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	createProductPageFixtures,
	deleteProductPageFixtures,
	expectRelatedDocumentation,
	expectTooltipOnHover,
	expectNoInsertions,
	collectConsoleErrors,
} = require( './fixtures' );

test.describe( 'Product page insertions — classic theme (Storefront)', () => {
	/** @type {Object} */
	let fixtures;
	/** @type {string[]} */
	let consoleErrors;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activateTheme( 'storefront' );
		fixtures = await createProductPageFixtures( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await deleteProductPageFixtures( requestUtils, fixtures );
		// Leave the site on the default block theme for later manual checks.
		await requestUtils.activateTheme( 'twentytwentyfive' );
	} );

	test.beforeEach( ( { page } ) => {
		consoleErrors = collectConsoleErrors( page );
	} );

	test( 'shows the FAQ tab, the related documentation, and the glossary tooltips for a linked product', async ( {
		page,
	} ) => {
		await page.goto( fixtures.linkedProduct.permalink );

		const tabs = page.locator( '.woocommerce-tabs' );
		await expect( tabs ).toBeVisible();

		// The FAQ tab sits between WooCommerce's own tabs and is a real tab:
		// clicking it reveals the panel holding the free plugin's accordion.
		const faqTab = tabs.locator( '#tab-title-saai_faq a' );
		await expect( faqTab ).toHaveText( 'FAQ' );
		await faqTab.click();

		const panel = tabs.locator( '#tab-saai_faq' );
		await expect( panel ).toBeVisible();
		await expect(
			panel.locator(
				'.saai-faq-list .wp-block-accordion-heading__toggle-title'
			)
		).toHaveText( fixtures.questions );

		await expectRelatedDocumentation( page, fixtures );

		// One anchor in the short description, one in the Description tab.
		await expect( page.locator( 'a.saai-term' ) ).toHaveCount( 2 );
		await expectTooltipOnHover(
			page,
			page.locator(
				'.woocommerce-product-details__short-description a.saai-term'
			),
			fixtures
		);

		expect( consoleErrors ).toEqual( [] );
	} );

	test( 'shows none of them for a product without links', async ( {
		page,
	} ) => {
		await expectNoInsertions( page, fixtures );
		await expect( page.locator( '.woocommerce-tabs' ) ).toBeVisible();

		expect( consoleErrors ).toEqual( [] );
	} );
} );
