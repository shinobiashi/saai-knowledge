const { defineConfig, devices } = require( '@playwright/test' );
const path = require( 'path' );

const STORAGE_STATE_PATH =
	process.env.STORAGE_STATE_PATH ||
	path.join( __dirname, 'test-results/storage-states/admin.json' );

// @wordpress/e2e-test-utils-playwright's built-in `requestUtils` fixture reads
// this env var directly (it doesn't consult `use.storageState` below), so it
// must be set before that package is imported by any spec/global-setup file.
process.env.STORAGE_STATE_PATH = STORAGE_STATE_PATH;

// The same package also resolves WP_BASE_URL once, when it is first required,
// and `requestUtils.setupRest()` looks the REST root up there rather than at
// the `baseURL` handed to `RequestUtils.setup()`. Its fallback is wp-env's
// default tests port 8889, which this repo no longer uses (see .wp-env.json),
// so point it at the tests environment here, before any spec imports it.
process.env.WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:10031';

module.exports = defineConfig( {
	testDir: './plugins/saai-knowledge/tests/e2e',
	globalSetup: require.resolve(
		'./plugins/saai-knowledge/tests/e2e/global-setup.js'
	),
	// The specs activate a theme for the whole site before asserting on it;
	// running them concurrently would race that shared, site-wide state.
	fullyParallel: false,
	workers: 1,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 2 : 0,
	reporter: process.env.CI ? 'github' : 'list',
	use: {
		baseURL: process.env.WP_BASE_URL,
		storageState: STORAGE_STATE_PATH,
		viewport: { width: 1400, height: 1000 },
		trace: 'retain-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
