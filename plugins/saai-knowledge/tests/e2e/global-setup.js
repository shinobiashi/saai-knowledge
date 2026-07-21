const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Logs in once as admin and persists the session so every spec's
 * `requestUtils`/`page` fixtures start already authenticated.
 *
 * @param {import('@playwright/test').FullConfig} config Playwright's resolved config.
 */
module.exports = async function globalSetup( config ) {
	const { storageState, baseURL } = config.projects[ 0 ].use;
	const storageStatePath =
		typeof storageState === 'string' ? storageState : undefined;

	const requestUtils = await RequestUtils.setup( {
		baseURL,
		storageStatePath,
	} );

	await requestUtils.setupRest();
};
