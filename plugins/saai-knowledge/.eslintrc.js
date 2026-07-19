/**
 * Extends @wordpress/scripts's bundled default config (kept as the base so wp-scripts's
 * own babel/parserOptions fallback logic still runs) with a browser env override for
 * view.js entry points, which execute in the browser and use DOM/browser globals
 * (document, window, IntersectionObserver, ...) that the base config deliberately
 * omits since most src/ files (editor scripts, webpack configs) don't run there.
 */
module.exports = {
	root: true,
	extends: [ require.resolve( '@wordpress/scripts/config/.eslintrc.js' ) ],
	overrides: [
		{
			files: [ 'src/**/view.js' ],
			env: {
				browser: true,
			},
		},
	],
};
