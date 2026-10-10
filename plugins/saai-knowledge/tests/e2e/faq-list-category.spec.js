const {
	test,
	expect,
	Admin,
	Editor,
	PageUtils,
	RequestUtils,
} = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * The FAQ List block's "Category" setting, used by each role that can insert
 * the block. Authors and Contributors are the cases that matter: the choices
 * come from /wp/v2/saai_category, which answers the `edit` context core-data
 * asks for by default with 403 to anyone without `manage_categories` — and
 * neither role has it. The administrator and editor runs guard the behavior
 * that already worked.
 */
test.describe( 'FAQ List block category setting', () => {
	/** @type {Object} */
	let term;

	test.beforeAll( async ( { requestUtils } ) => {
		const unique = Math.random().toString( 36 ).slice( 2, 8 );

		term = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/saai_category',
			data: { name: `E2E FAQ List Category ${ unique }` },
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		if ( term ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/saai_category/${ term.id }`,
				params: { force: true },
			} );
		}
	} );

	for ( const role of [
		'administrator',
		'editor',
		'author',
		'contributor',
	] ) {
		test( `${ role } can choose a category`, async ( {
			browser,
			requestUtils,
		} ) => {
			// Log in, open a new post, insert a block: a long flow on a slow
			// CI runner.
			test.setTimeout( 120000 );

			const username = `e2e-${ role }-${ Date.now() }`;
			const password = `e2e-${ Math.random().toString( 36 ).slice( 2 ) }`;
			let user;
			let context;

			try {
				user = await requestUtils.createUser( {
					username,
					email: `${ username }@example.com`,
					password,
					roles: [ role ],
				} );

				// Logged in through a request context rather than the login
				// form: a click on "Log In" was seen on CI to submit nothing at
				// all.
				const userRequest = await RequestUtils.setup( {
					user: { username, password },
					baseURL: process.env.WP_BASE_URL,
				} );
				await userRequest.login();
				// Stored on the server before the editor loads: for a user with
				// no saved preferences, the editor fetches them after the page
				// is up and would overwrite the welcome guide setting that
				// Admin#createNewPost() applies in the browser.
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

				await admin.createNewPost();
				await editor.insertBlock( { name: 'saai-knowledge/faq-list' } );
				await editor.openDocumentSettingsSidebar();

				const categorySelect = page
					.locator( '.block-editor-block-inspector' )
					.getByRole( 'combobox', { name: 'Category', exact: true } );

				// The choices load asynchronously; without them only "All
				// categories" is there.
				await expect(
					categorySelect.getByRole( 'option', {
						name: term.name,
						exact: true,
					} )
				).toHaveCount( 1 );

				await categorySelect.selectOption( { label: term.name } );
				await expect
					.poll( async () => ( await editor.getBlocks() )[ 0 ] )
					.toMatchObject( {
						name: 'saai-knowledge/faq-list',
						attributes: { category: term.slug },
					} );
			} finally {
				await context?.close();

				if ( user ) {
					// Without `reassign`, the user's auto-draft goes with them.
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
