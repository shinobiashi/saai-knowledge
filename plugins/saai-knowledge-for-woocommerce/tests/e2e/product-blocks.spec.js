const {
	test,
	expect,
	Admin,
	Editor,
	PageUtils,
	RequestUtils,
} = require( '@wordpress/e2e-test-utils-playwright' );
const {
	SINGLE_PRODUCT_TEMPLATE_ID,
	createProductPageFixtures,
	deleteProductPageFixtures,
	restoreSingleProductTemplate,
	collectConsoleErrors,
} = require( './fixtures' );

/**
 * The three product blocks, with no product chosen: in the Single Product
 * template they resolve the product being viewed.
 */
const CONTEXT_BLOCKS = [
	'<!-- wp:saai-knowledge/product-faq /-->',
	'<!-- wp:saai-knowledge/product-docs /-->',
	'<!-- wp:saai-knowledge/product-glossary /-->',
].join( '\n' );

/**
 * Saves a customized Single Product template with the three product blocks
 * right after the Product Details block — what a merchant does by inserting
 * them in the Site Editor, done through the same templates REST route the
 * editor saves with.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 */
async function addBlocksToSingleProductTemplate( requestUtils ) {
	const template = await requestUtils.rest( {
		path: `/wp/v2/templates/${ SINGLE_PRODUCT_TEMPLATE_ID }`,
		params: { context: 'edit' },
	} );
	const anchor = template.content.raw.match(
		/<!-- wp:woocommerce\/product-details[^>]*\/-->/
	);

	expect(
		anchor,
		'The bundled Single Product template must contain a Product Details block.'
	).not.toBeNull();

	await requestUtils.rest( {
		method: 'POST',
		path: `/wp/v2/templates/${ SINGLE_PRODUCT_TEMPLATE_ID }`,
		data: {
			content: template.content.raw.replace(
				anchor[ 0 ],
				`${ anchor[ 0 ] }\n${ CONTEXT_BLOCKS }`
			),
		},
	} );
}

/**
 * Asserts the three product blocks list the fixtures' linked content.
 *
 * @param {import('@playwright/test').Page} page
 * @param {Object}                          fixtures
 */
async function expectProductBlocks( page, fixtures ) {
	const faq = page.locator( '.wp-block-saai-knowledge-product-faq' );
	await expect( faq ).toHaveCount( 1 );
	await expect( faq.getByRole( 'heading', { name: 'FAQ' } ) ).toBeVisible();
	await expect(
		faq.locator( '.wp-block-accordion-heading__toggle-title' )
	).toHaveText( fixtures.questions );

	const docs = page.locator( '.wp-block-saai-knowledge-product-docs' );
	await expect( docs ).toHaveCount( 1 );
	await expect(
		docs.getByRole( 'heading', { name: 'Related documentation' } )
	).toBeVisible();
	await expect(
		docs.getByRole( 'link', { name: fixtures.kb.title.rendered } )
	).toHaveAttribute( 'href', fixtures.kb.link );

	const glossary = page.locator(
		'.wp-block-saai-knowledge-product-glossary'
	);
	await expect( glossary ).toHaveCount( 1 );
	// Scoped to the term itself: on a page the free plugin's auto-linker
	// also links the term where its own definition mentions it.
	await expect(
		glossary
			.locator( '.saai-woo-product-glossary__term' )
			.getByRole( 'link', { name: fixtures.termWord } )
	).toHaveAttribute( 'href', fixtures.term.link );
	await expect(
		glossary.locator( '.saai-woo-product-glossary__definition' )
	).toHaveText( fixtures.term.excerpt.raw );

	// One FAQPage for the whole page, whatever else lists FAQs on it.
	// Counted from textContent: Playwright's text matching (hasText,
	// getByText) skips <script> contents.
	const faqPages = await page
		.locator( 'script[type="application/ld+json"]' )
		.evaluateAll(
			( scripts ) =>
				scripts.filter( ( script ) =>
					script.textContent.includes( '"FAQPage"' )
				).length
		);
	expect( faqPages ).toBe( 1 );
}

test.describe( 'Product blocks (Twenty Twenty-Five)', () => {
	/** @type {Object} */
	let fixtures;
	/** @type {Object} */
	let blocksPage;
	/** @type {string[]} */
	let consoleErrors;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activateTheme( 'twentytwentyfive' );
		await restoreSingleProductTemplate( requestUtils );
		fixtures = await createProductPageFixtures( requestUtils );

		const productId = fixtures.linkedProduct.id;

		blocksPage = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/pages',
			data: {
				title: 'E2E Product Blocks Page',
				status: 'publish',
				content: [
					`<!-- wp:saai-knowledge/product-faq {"productId":${ productId }} /-->`,
					`<!-- wp:saai-knowledge/product-docs {"productId":${ productId }} /-->`,
					`<!-- wp:shortcode -->[saai_product_glossary product_id="${ productId }"]<!-- /wp:shortcode -->`,
					// No product chosen, on a page that is no product: nothing.
					'<!-- wp:saai-knowledge/product-docs /-->',
				].join( '\n' ),
			},
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await restoreSingleProductTemplate( requestUtils );

		if ( blocksPage ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/pages/${ blocksPage.id }`,
				params: { force: true },
			} );
		}

		await deleteProductPageFixtures( requestUtils, fixtures );
	} );

	test.beforeEach( ( { page } ) => {
		consoleErrors = collectConsoleErrors( page );
	} );

	test( 'lists a chosen product’s content on a regular page', async ( {
		page,
	} ) => {
		await page.goto( blocksPage.link );

		await expectProductBlocks( page, fixtures );

		// The FAQ list is the free plugin's accordion, interactive as ever.
		const faq = page.locator( '.wp-block-saai-knowledge-product-faq' );
		const toggle = faq
			.locator( '.wp-block-accordion-heading__toggle' )
			.first();

		await expect( async () => {
			await toggle.click();
			await expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
		} ).toPass();
		await expect(
			faq.getByText( 'Answer 1 for the E2E suite.' )
		).toBeVisible();

		expect( consoleErrors ).toEqual( [] );
	} );

	test.describe( 'in the Single Product template', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await addBlocksToSingleProductTemplate( requestUtils );
		} );

		test( 'lists the viewed product’s content', async ( { page } ) => {
			await page.goto( fixtures.linkedProduct.permalink );

			await expectProductBlocks( page, fixtures );

			expect( consoleErrors ).toEqual( [] );
		} );

		test( 'shows nothing for a product without links', async ( {
			page,
		} ) => {
			await page.goto( fixtures.plainProduct.permalink );

			// The template rendered the product, so the zero counts below
			// mean "nothing to list", not "page broken".
			await expect(
				page.getByText( 'part is tested before shipping' )
			).toBeVisible();

			for ( const block of [ 'faq', 'docs', 'glossary' ] ) {
				await expect(
					page.locator(
						`.wp-block-saai-knowledge-product-${ block }`
					)
				).toHaveCount( 0 );
			}

			expect( consoleErrors ).toEqual( [] );
		} );
	} );

	test( 'previews a chosen product in the editor and explains an empty preview', async ( {
		admin,
		editor,
	} ) => {
		await admin.createNewPost();

		await editor.insertBlock( {
			name: 'saai-knowledge/product-faq',
			attributes: { productId: fixtures.linkedProduct.id },
		} );
		await expect(
			editor.canvas.locator(
				'.wp-block-saai-knowledge-product-faq .wp-block-accordion-heading__toggle-title'
			)
		).toHaveText( fixtures.questions );

		// A post is no product, so with nothing chosen the server renders
		// nothing and the block explains itself instead.
		await editor.insertBlock( { name: 'saai-knowledge/product-docs' } );
		await expect(
			editor.canvas
				.locator( '.wp-block-saai-knowledge-product-docs' )
				.getByText(
					'Shows the knowledge base articles linked to the product being displayed.',
					{ exact: false }
				)
		).toBeVisible();
	} );

	test( 'lets an Editor without product capabilities pick the product', async ( {
		browser,
		requestUtils,
	} ) => {
		// Log in, open a new post, insert a block, search: a long flow on a
		// slow CI runner.
		test.setTimeout( 120000 );

		// The picker searches core's /wp/v2/product; in the `edit` context
		// core-data uses by default, that route answers an Editor (no
		// `edit_products`) with 403, so this is the case that proves the
		// picker asks for `view`.
		const username = `e2e-editor-${ Date.now() }`;
		const password = `e2e-${ Math.random().toString( 36 ).slice( 2 ) }`;
		let user;
		let context;

		try {
			user = await requestUtils.createUser( {
				username,
				email: `${ username }@example.com`,
				password,
				roles: [ 'editor' ],
			} );

			// Logged in through a request context, as the global setup does
			// for the admin, rather than through the login form: a click on
			// "Log In" right after the page loads was seen on CI to submit
			// nothing at all (no POST to wp-login.php in the trace).
			const editorRequest = await RequestUtils.setup( {
				user: { username, password },
				baseURL: process.env.WP_BASE_URL,
			} );
			await editorRequest.login();
			const storageState = await editorRequest.request.storageState();
			await editorRequest.request.dispose();

			context = await browser.newContext( {
				baseURL: process.env.WP_BASE_URL,
				storageState,
			} );

			const editorPage = await context.newPage();

			const editor = new Editor( { page: editorPage } );
			const admin = new Admin( {
				page: editorPage,
				pageUtils: new PageUtils( {
					page: editorPage,
					browserName: 'chromium',
				} ),
				editor,
			} );

			await admin.createNewPost();
			await editor.insertBlock( { name: 'saai-knowledge/product-docs' } );
			await editor.openDocumentSettingsSidebar();

			const picker = editorPage.getByRole( 'combobox', {
				name: 'Product',
			} );

			await picker.fill( fixtures.linkedProduct.name );
			await editorPage
				.getByRole( 'option', { name: fixtures.linkedProduct.name } )
				.click();

			// Once picked, the search is cleared and the name shown comes from
			// the picker's own lookup of the chosen ID — which also has to ask
			// for the `view` context, or an Editor sees "Product #<ID>".
			await expect( picker ).toHaveValue( fixtures.linkedProduct.name );

			await expect(
				editor.canvas
					.locator( '.wp-block-saai-knowledge-product-docs' )
					.getByRole( 'link', { name: fixtures.kb.title.rendered } )
			).toBeVisible();
		} finally {
			await context?.close();

			if ( user ) {
				// Without `reassign`, the Editor's auto-draft goes with them.
				await requestUtils.rest( {
					method: 'DELETE',
					path: `/wp/v2/users/${ user.id }`,
					params: { force: true, reassign: false },
				} );
			}
		}
	} );
} );
