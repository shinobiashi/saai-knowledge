const {
	expect,
	Admin,
	Editor,
	PageUtils,
} = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * The Single Product template WooCommerce registers for block themes, as
 * the Site Editor and the templates REST route identify it.
 */
const SINGLE_PRODUCT_TEMPLATE_ID = 'woocommerce/woocommerce//single-product';

/**
 * Creates the content the product-page specs assert on: one glossary term,
 * two FAQs, and one KB article, all linked to one WooCommerce product, plus
 * a second product with the same descriptions and no links at all (the
 * acceptance criterion's negative case).
 *
 * Products go through WooCommerce's own REST API so they are real simple
 * products; the content goes through the free plugin's post types with the
 * add-on's `saai_linked_products` meta set in the same request.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @return {Promise<Object>} The created fixtures.
 */
async function createProductPageFixtures( requestUtils ) {
	const unique = Math.random().toString( 36 ).slice( 2, 8 );
	// A made-up word: nothing else on the page can match the dictionary, and
	// the two anchors the specs expect are exactly the two descriptions.
	const termWord = `Zephyrite${ unique }`;

	const productData = ( name ) => ( {
		name,
		type: 'simple',
		regular_price: '10',
		status: 'publish',
		short_description: `<p>Made of ${ termWord }, ships ready to use.</p>`,
		description: `<p>Every ${ termWord } part is tested before shipping.</p>`,
	} );

	const linkedProduct = await requestUtils.rest( {
		method: 'POST',
		path: '/wc/v3/products',
		data: productData( `E2E Linked Product ${ unique }` ),
	} );
	const plainProduct = await requestUtils.rest( {
		method: 'POST',
		path: '/wc/v3/products',
		data: productData( `E2E Plain Product ${ unique }` ),
	} );

	const meta = { saai_linked_products: [ linkedProduct.id ] };

	const term = await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/saai_glossary',
		data: {
			title: termWord,
			status: 'publish',
			excerpt: `${ termWord } is a made-up material used by the E2E suite.`,
			content: `<!-- wp:paragraph --><p>${ termWord } is a made-up material used by the E2E suite, defined here in full.</p><!-- /wp:paragraph -->`,
			meta,
		},
	} );

	const faqs = [];
	// Alphabetical on purpose: `saai_faq` doesn't support page-attributes,
	// so core REST silently drops `menu_order` and both FAQs share 0; the
	// resolver's title tiebreak is what orders them on the page. The
	// menu_order ordering itself is covered by PHPUnit
	// (Test_Woo_Product_Page::test_faq_tab_body_lists_only_the_linked_faqs_in_resolver_order).
	const questions = [ 'How do I install it?', 'Is it waterproof?' ];

	for ( const [ index, question ] of questions.entries() ) {
		faqs.push(
			await requestUtils.rest( {
				method: 'POST',
				path: '/wp/v2/saai_faq',
				data: {
					title: question,
					status: 'publish',
					content: `<!-- wp:paragraph --><p>Answer ${
						index + 1
					} for the E2E suite.</p><!-- /wp:paragraph -->`,
					meta,
				},
			} )
		);
	}

	const kb = await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/saai_kb',
		data: {
			title: `E2E Setup Guide ${ unique }`,
			status: 'publish',
			content:
				'<!-- wp:paragraph --><p>Setup steps for the E2E suite.</p><!-- /wp:paragraph -->',
			meta,
		},
	} );

	return { termWord, questions, term, faqs, kb, linkedProduct, plainProduct };
}

/**
 * Deletes everything createProductPageFixtures() made.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @param {Object}                                                      fixtures
 */
async function deleteProductPageFixtures( requestUtils, fixtures ) {
	if ( ! fixtures ) {
		return;
	}

	for ( const post of [ fixtures.term, ...fixtures.faqs, fixtures.kb ] ) {
		if ( post ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/${ post.type }/${ post.id }`,
				params: { force: true },
			} );
		}
	}

	for ( const product of [ fixtures.linkedProduct, fixtures.plainProduct ] ) {
		if ( product ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wc/v3/products/${ product.id }`,
				params: { force: true },
			} );
		}
	}
}

/**
 * Turns the bundled Single Product template into the accordion variant a
 * merchant gets by re-inserting the Product Details block in the Site
 * Editor, and saves it as a customized template.
 *
 * Opening and saving the template as-is is not enough: WooCommerce's
 * Product Details block only applies its inner-blocks (accordion) template
 * to a block that already has inner blocks or was just inserted; an
 * existing self-closing block keeps rendering the legacy tabs (see
 * docs/DESIGN.md section 6.2). So the existing block is replaced by a
 * freshly created one, which is what deleting it and inserting a new one
 * from the inserter does, and the edited template is then saved through
 * the same entity store the editor's Save button uses.
 *
 * @param {import('@playwright/test').Browser}                           browser
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 */
async function customizeSingleProductTemplate( browser, requestUtils ) {
	const context = await browser.newContext( {
		baseURL: process.env.WP_BASE_URL,
		storageState: process.env.STORAGE_STATE_PATH,
	} );
	const page = await context.newPage();

	try {
		const editor = new Editor( { page } );
		const admin = new Admin( {
			page,
			pageUtils: new PageUtils( { page, browserName: 'chromium' } ),
			editor,
		} );

		await admin.visitSiteEditor( {
			postId: SINGLE_PRODUCT_TEMPLATE_ID,
			postType: 'wp_template',
			canvas: 'edit',
		} );

		// visitSiteEditor() resolves before the template's blocks have
		// settled in the editor: a replaceBlock() dispatched at that point
		// is silently thrown away by the editor's own reset of the block
		// list (seen on CI-like fresh sites). Wait for the canvas to have
		// actually rendered the block before touching it.
		await editor.canvas
			.locator( '[data-type="woocommerce/product-details"]' )
			.first()
			.waitFor( { state: 'visible', timeout: 60000 } );

		const clientId = await page.evaluate( () => {
			const flatten = ( blocks ) =>
				blocks.flatMap( ( block ) => [
					block,
					...flatten( block.innerBlocks ),
				] );
			const select = window.wp.data.select( 'core/block-editor' );
			const legacy = flatten( select.getBlocks() ).find(
				( block ) => block.name === 'woocommerce/product-details'
			);

			if ( ! legacy ) {
				return null;
			}

			const fresh = window.wp.blocks.createBlock(
				'woocommerce/product-details',
				legacy.attributes
			);
			window.wp.data
				.dispatch( 'core/block-editor' )
				.replaceBlock( legacy.clientId, fresh );

			return fresh.clientId;
		} );

		expect(
			clientId,
			'The Single Product template must contain a Product Details block.'
		).not.toBeNull();

		await page.waitForFunction(
			( id ) =>
				window.wp.data
					.select( 'core/block-editor' )
					.getBlocks( id )
					.some( ( block ) => block.name === 'core/accordion' ),
			clientId,
			{ timeout: 60000 }
		);

		await page.evaluate( async () => {
			const { select, dispatch } = window.wp.data;
			const dirty = select( 'core' ).__experimentalGetDirtyEntityRecords();

			for ( const record of dirty ) {
				await dispatch( 'core' ).saveEditedEntityRecord(
					record.kind,
					record.name,
					record.key
				);
			}
		} );
	} finally {
		await context.close();
	}

	const template = await requestUtils.rest( {
		path: `/wp/v2/templates/${ SINGLE_PRODUCT_TEMPLATE_ID }`,
	} );

	expect(
		template.source,
		'The Single Product template must now be the saved (customized) copy.'
	).toBe( 'custom' );
	expect( template.content.raw ).toContain( '<!-- wp:accordion' );
}

/**
 * Deletes the customized Single Product template, if there is one, so the
 * site is back on WooCommerce's bundled file template.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 */
async function restoreSingleProductTemplate( requestUtils ) {
	try {
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/templates/${ SINGLE_PRODUCT_TEMPLATE_ID }`,
			params: { force: true },
		} );
	} catch ( error ) {
		// "Templates based on theme files can't be removed." — nothing was
		// customized, which is exactly the state this function wants.
		if ( error?.code !== 'rest_invalid_template' ) {
			throw error;
		}
	}
}

/**
 * Asserts the related-documentation section lists the linked KB article.
 *
 * @param {import('@playwright/test').Page} page
 * @param {Object}                          fixtures
 */
async function expectRelatedDocumentation( page, fixtures ) {
	const section = page.locator( '.saai-woo-related-kb' );

	await expect( section ).toBeVisible();
	await expect(
		section.getByRole( 'heading', { name: 'Related documentation' } )
	).toBeVisible();
	await expect(
		section.getByRole( 'link', { name: fixtures.kb.title.rendered } )
	).toHaveAttribute( 'href', fixtures.kb.link );
	await expect( section.getByRole( 'link' ) ).toHaveCount( 1 );
}

/**
 * Asserts that hovering an auto-linked term shows the glossary tooltip
 * with the term's excerpt.
 *
 * @param {import('@playwright/test').Page}    page
 * @param {import('@playwright/test').Locator} anchor   The `a.saai-term` to hover.
 * @param {Object}                             fixtures
 */
async function expectTooltipOnHover( page, anchor, fixtures ) {
	await expect( anchor ).toHaveText( fixtures.termWord );
	await expect( anchor ).toHaveAttribute( 'href', fixtures.term.link );

	// `force`: the tooltip opens on the very first pointer move and then
	// sits over the anchor, so Playwright's default actionability retry
	// ("element intercepts pointer events") would loop until timeout even
	// though the hover already did its job.
	await anchor.hover( { force: true } );

	const tooltip = page.locator( '#saai-tooltip[role="tooltip"]' );

	await expect( tooltip ).toBeVisible();
	await expect( tooltip ).toContainText( 'made-up material' );
}

/**
 * Asserts the negative case: a product with no linked content shows no
 * FAQ, no related documentation, no term links, and loads no tooltip.
 *
 * @param {import('@playwright/test').Page} page
 * @param {Object}                          fixtures
 */
async function expectNoInsertions( page, fixtures ) {
	await page.goto( fixtures.plainProduct.permalink );

	// The page itself rendered the product (description present), so the
	// zero counts below mean "not inserted", not "page broken".
	await expect( page.getByText( 'part is tested before shipping' ) ).toBeVisible();

	await expect( page.locator( '#tab-title-saai_faq' ) ).toHaveCount( 0 );
	await expect( page.locator( '.saai-faq-list' ) ).toHaveCount( 0 );
	await expect(
		page.locator( '.wp-block-accordion-heading__toggle-title', {
			hasText: /^FAQ$/,
		} )
	).toHaveCount( 0 );
	await expect( page.locator( '.saai-woo-related-kb' ) ).toHaveCount( 0 );
	await expect( page.locator( 'a.saai-term' ) ).toHaveCount( 0 );
	await expect( page.locator( '#saai-tooltip' ) ).toHaveCount( 0 );
}

/**
 * Collects console errors, ignoring failed resource loads (a 404 for an
 * unrelated asset is not a script error in this plugin's code).
 *
 * @param {import('@playwright/test').Page} page
 * @return {string[]} The array errors are pushed into.
 */
function collectConsoleErrors( page ) {
	const errors = [];

	page.on( 'console', ( message ) => {
		if (
			message.type() === 'error' &&
			! message.text().startsWith( 'Failed to load resource' )
		) {
			errors.push( message.text() );
		}
	} );

	return errors;
}

module.exports = {
	SINGLE_PRODUCT_TEMPLATE_ID,
	createProductPageFixtures,
	deleteProductPageFixtures,
	customizeSingleProductTemplate,
	restoreSingleProductTemplate,
	expectRelatedDocumentation,
	expectTooltipOnHover,
	expectNoInsertions,
	collectConsoleErrors,
};
