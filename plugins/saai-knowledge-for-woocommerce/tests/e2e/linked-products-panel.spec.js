const {
	test,
	expect,
	Admin,
	Editor,
	PageUtils,
	RequestUtils,
} = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * The "Linked Products" panel on a FAQ, operated by each role that can edit
 * one. An Editor is the case that matters: the panel reads core's
 * /wp/v2/product and /wp/v2/product_cat, which answer the `edit` context
 * core-data asks for by default with 403 to anyone without product
 * capabilities — and an Editor has none. The administrator run guards the
 * behavior that already worked.
 */
test.describe( 'Linked Products panel', () => {
	/** @type {Object} */
	let fixtures;

	test.beforeAll( async ( { requestUtils } ) => {
		const unique = Math.random().toString( 36 ).slice( 2, 8 );

		const product = ( name ) =>
			requestUtils.rest( {
				method: 'POST',
				path: '/wc/v3/products',
				data: {
					name: `${ name } ${ unique }`,
					type: 'simple',
					regular_price: '10',
					status: 'publish',
				},
			} );
		const category = ( name ) =>
			requestUtils.rest( {
				method: 'POST',
				path: '/wc/v3/products/categories',
				data: { name: `${ name } ${ unique }` },
			} );

		fixtures = {
			linkedProduct: await product( 'E2E Panel Linked Product' ),
			addedProduct: await product( 'E2E Panel Added Product' ),
			linkedCategory: await category( 'E2E Panel Linked Category' ),
			addedCategory: await category( 'E2E Panel Added Category' ),
		};
	} );

	test.afterAll( async ( { requestUtils } ) => {
		if ( ! fixtures ) {
			return;
		}

		for ( const product of [
			fixtures.linkedProduct,
			fixtures.addedProduct,
		] ) {
			if ( product ) {
				await requestUtils.rest( {
					method: 'DELETE',
					path: `/wc/v3/products/${ product.id }`,
					params: { force: true },
				} );
			}
		}

		for ( const category of [
			fixtures.linkedCategory,
			fixtures.addedCategory,
		] ) {
			if ( category ) {
				await requestUtils.rest( {
					method: 'DELETE',
					path: `/wc/v3/products/categories/${ category.id }`,
					params: { force: true },
				} );
			}
		}
	} );

	for ( const role of [ 'administrator', 'editor' ] ) {
		test( `lets an ${ role } see, search, and link products and categories`, async ( {
			browser,
			requestUtils,
		} ) => {
			// Log in, open the FAQ, two searches, a save: a long flow on a
			// slow CI runner.
			test.setTimeout( 120000 );

			const username = `e2e-${ role }-${ Date.now() }`;
			const password = `e2e-${ Math.random().toString( 36 ).slice( 2 ) }`;
			let user;
			let faq;
			let context;

			try {
				user = await requestUtils.createUser( {
					username,
					email: `${ username }@example.com`,
					password,
					roles: [ role ],
				} );

				// Already linked to one product and one category, so the names
				// the panel shows for them come from its own lookup of the
				// stored IDs, not from a search it just ran.
				faq = await requestUtils.rest( {
					method: 'POST',
					path: '/wp/v2/saai_faq',
					data: {
						title: `E2E Panel FAQ (${ role })`,
						status: 'publish',
						content:
							'<!-- wp:paragraph --><p>Answer for the panel E2E.</p><!-- /wp:paragraph -->',
						meta: {
							saai_linked_products: [ fixtures.linkedProduct.id ],
							saai_linked_product_cats: [
								fixtures.linkedCategory.id,
							],
						},
					},
				} );

				// Logged in through a request context rather than the login
				// form, as in product-blocks.spec.js: a click on "Log In" was
				// seen on CI to submit nothing at all.
				const userRequest = await RequestUtils.setup( {
					user: { username, password },
					baseURL: process.env.WP_BASE_URL,
				} );
				await userRequest.login();
				// Stored on the server before the editor loads: for a user with
				// no saved preferences, the editor fetches them after the page
				// is up and would overwrite the welcome guide setting that
				// Admin#editPost() applies in the browser.
				await userRequest.setPreferences( 'core/edit-post', {
					welcomeGuide: false,
					fullscreenMode: false,
				} );
				const storageState = await userRequest.request.storageState();
				await userRequest.request.dispose();

				context = await browser.newContext( {
					baseURL: process.env.WP_BASE_URL,
					storageState,
				} );

				const page = await context.newPage();
				const editor = new Editor( { page } );
				const admin = new Admin( {
					page,
					pageUtils: new PageUtils( {
						page,
						browserName: 'chromium',
					} ),
					editor,
				} );

				await admin.editPost( faq.id );
				await editor.openDocumentSettingsSidebar();

				// A plugin's document panel starts closed for a new user.
				const toggle = page.getByRole( 'button', {
					name: 'Linked Products',
				} );
				if (
					'false' === ( await toggle.getAttribute( 'aria-expanded' ) )
				) {
					await toggle.click();
				}

				const panel = page.locator( '.saai-woo-linked' );

				// The stored links resolve to their names.
				await expect(
					panel.getByText( fixtures.linkedProduct.name, {
						exact: true,
					} )
				).toBeVisible();
				await expect(
					panel.getByText( fixtures.linkedCategory.name, {
						exact: true,
					} )
				).toBeVisible();
				await expect(
					panel.getByText( 'Cannot be shown', { exact: false } )
				).toHaveCount( 0 );

				// Search and link a product, then a category.
				const productSearch = panel.getByRole( 'combobox', {
					name: 'Add a product',
					exact: true,
				} );
				await productSearch.fill( fixtures.addedProduct.name );
				const productOption = page.getByRole( 'option', {
					name: fixtures.addedProduct.name,
				} );
				// Asserted before the click so that a search coming back empty
				// fails here, with this locator, instead of as a click waiting
				// out the test timeout (which also skips the cleanup below).
				await expect( productOption ).toBeVisible();
				await productOption.click();
				await expect(
					panel.getByText( fixtures.addedProduct.name, {
						exact: true,
					} )
				).toBeVisible();

				const categorySearch = panel.getByRole( 'combobox', {
					name: 'Add a product category',
					exact: true,
				} );
				await categorySearch.fill( fixtures.addedCategory.name );
				const categoryOption = page.getByRole( 'option', {
					name: fixtures.addedCategory.name,
				} );
				await expect( categoryOption ).toBeVisible();
				await categoryOption.click();
				await expect(
					panel.getByText( fixtures.addedCategory.name, {
						exact: true,
					} )
				).toBeVisible();

				await expect(
					panel.getByText( 'Cannot be shown', { exact: false } )
				).toHaveCount( 0 );

				// Saved through the editor store rather than the Save button,
				// whose label depends on the post status and UI language.
				const saved = await page.evaluate( async () => {
					const { dispatch, select } = window.wp.data;
					await dispatch( 'core/editor' ).savePost();
					return select( 'core/editor' ).didPostSaveRequestSucceed();
				} );
				expect( saved ).toBe( true );

				const stored = await requestUtils.rest( {
					path: `/wp/v2/saai_faq/${ faq.id }`,
					params: { context: 'edit' },
				} );
				expect( stored.meta.saai_linked_products ).toEqual( [
					fixtures.linkedProduct.id,
					fixtures.addedProduct.id,
				] );
				expect( stored.meta.saai_linked_product_cats ).toEqual( [
					fixtures.linkedCategory.id,
					fixtures.addedCategory.id,
				] );
			} finally {
				await context?.close();

				if ( faq ) {
					await requestUtils.rest( {
						method: 'DELETE',
						path: `/wp/v2/saai_faq/${ faq.id }`,
						params: { force: true },
					} );
				}

				if ( user ) {
					await requestUtils.rest( {
						method: 'DELETE',
						path: `/wp/v2/users/${ user.id }`,
						params: { force: true, reassign: false },
					} );
				}
			}
		} );
	}
} );
