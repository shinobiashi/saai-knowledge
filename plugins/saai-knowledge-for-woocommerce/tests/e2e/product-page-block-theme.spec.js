const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	createProductPageFixtures,
	deleteProductPageFixtures,
	customizeSingleProductTemplate,
	restoreSingleProductTemplate,
	expectRelatedDocumentation,
	expectTooltipOnHover,
	expectNoInsertions,
	collectConsoleErrors,
} = require( './fixtures' );

test.describe( 'Product page insertions — block theme (Twenty Twenty-Five)', () => {
	/** @type {Object} */
	let fixtures;
	/** @type {string[]} */
	let consoleErrors;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activateTheme( 'twentytwentyfive' );
		// Start from WooCommerce's bundled template whatever an earlier,
		// interrupted run may have left behind.
		await restoreSingleProductTemplate( requestUtils );
		fixtures = await createProductPageFixtures( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await restoreSingleProductTemplate( requestUtils );
		await deleteProductPageFixtures( requestUtils, fixtures );
	} );

	test.beforeEach( ( { page } ) => {
		consoleErrors = collectConsoleErrors( page );
	} );

	test.describe( 'with the bundled Single Product template (legacy tabs)', () => {
		test( 'shows the FAQ tab, the related documentation, and the glossary tooltips for a linked product', async ( {
			page,
		} ) => {
			await page.goto( fixtures.linkedProduct.permalink );

			// The bundled template's Product Details block has no inner
			// blocks, so WooCommerce renders the classic tabs inside it.
			const details = page.locator(
				'.wp-block-woocommerce-product-details'
			);
			const tabs = details.locator( '.woocommerce-tabs' );
			await expect( tabs ).toBeVisible();

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

			// One anchor in the summary's excerpt block, one in the Description tab.
			await expect( page.locator( 'a.saai-term' ) ).toHaveCount( 2 );
			await expectTooltipOnHover(
				page,
				page.locator( '.wp-block-post-excerpt a.saai-term' ),
				fixtures
			);

			expect( consoleErrors ).toEqual( [] );
		} );

		test( 'shows none of them for a product without links', async ( {
			page,
		} ) => {
			await expectNoInsertions( page, fixtures );
			await expect(
				page.locator(
					'.wp-block-woocommerce-product-details .woocommerce-tabs'
				)
			).toBeVisible();

			expect( consoleErrors ).toEqual( [] );
		} );
	} );

	test.describe( 'with a Single Product template saved from the Site Editor (accordion)', () => {
		test.beforeAll( async ( { browser, requestUtils } ) => {
			// Loading the Site Editor, expanding the block, and saving the
			// template takes well over the default 30s hook timeout on CI.
			test.setTimeout( 180000 );
			await customizeSingleProductTemplate( browser, requestUtils );
		} );

		test.afterAll( async ( { requestUtils } ) => {
			await restoreSingleProductTemplate( requestUtils );
		} );

		test( 'shows the FAQ as exactly one accordion item, the related documentation, and the glossary tooltips', async ( {
			page,
		} ) => {
			await page.goto( fixtures.linkedProduct.permalink );

			const details = page.locator(
				'.wp-block-woocommerce-product-details'
			);
			await expect( details ).toBeVisible();
			// The saved template renders the accordion, not the legacy tabs.
			await expect( details.locator( '.woocommerce-tabs' ) ).toHaveCount(
				0
			);

			// WooCommerce's compatibility layer turns the FAQ tab into one
			// accordion item — once, not twice (no second insertion path).
			const faqHeading = details.locator(
				'.wp-block-accordion-heading__toggle-title',
				{ hasText: /^FAQ$/ }
			);
			await expect( faqHeading ).toHaveCount( 1 );

			// `has` is matched relative to each candidate item, so the inner
			// locator must be page-rooted rather than chained from `details`.
			const faqItem = details.locator( '.wp-block-accordion-item' ).filter( {
				has: page.locator( '.wp-block-accordion-heading__toggle-title', {
					hasText: /^FAQ$/,
				} ),
			} );
			await expect( faqItem ).toHaveCount( 1 );

			// Open the item. The toggle only works once the Interactivity API
			// has hydrated the accordion, so retry the click until it took.
			const faqToggle = faqItem
				.locator( '.wp-block-accordion-heading__toggle' )
				.first();
			await expect( async () => {
				if (
					( await faqToggle.getAttribute( 'aria-expanded' ) ) !== 'true'
				) {
					await faqToggle.click();
				}
				await expect( faqToggle ).toHaveAttribute( 'aria-expanded', 'true', {
					timeout: 1000,
				} );
			} ).toPass( { timeout: 15000 } );

			const questions = faqItem.locator(
				'.saai-faq-list .wp-block-accordion-heading__toggle-title'
			);
			await expect( questions ).toHaveText( fixtures.questions );
			await expect( questions.first() ).toBeVisible();

			await expectRelatedDocumentation( page, fixtures );

			await expect( page.locator( 'a.saai-term' ) ).toHaveCount( 2 );
			await expectTooltipOnHover(
				page,
				page.locator( '.wp-block-post-excerpt a.saai-term' ),
				fixtures
			);

			expect( consoleErrors ).toEqual( [] );
		} );

		test( 'shows none of them for a product without links', async ( {
			page,
		} ) => {
			await expectNoInsertions( page, fixtures );
			await expect(
				page.locator(
					'.wp-block-woocommerce-product-details .wp-block-accordion'
				)
			).toBeVisible();

			expect( consoleErrors ).toEqual( [] );
		} );
	} );
} );
