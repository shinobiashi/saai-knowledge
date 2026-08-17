/**
 * WordPress dependencies
 */
const path = require( 'path' );

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

/**
 * The default @wordpress/scripts config auto-discovers entry points from
 * src/*<!---->/block.json (see docs on saai-block-scaffold). The tooltip
 * script module (M3-4) has no block.json of its own — it's a global
 * Interactivity API store the auto-link engine's anchors reference, not a
 * block — so its entry has to be added explicitly here.
 */
const configs = Array.isArray( defaultConfig )
	? defaultConfig
	: [ defaultConfig ];

const moduleConfig = configs.find(
	( config ) => config.output && config.output.module
);

if ( moduleConfig ) {
	const baseEntry = moduleConfig.entry;

	moduleConfig.entry = () => ( {
		...( typeof baseEntry === 'function' ? baseEntry() : baseEntry ),
		'tooltip/view': path.resolve( __dirname, 'src/tooltip/view.js' ),
	} );
} else if ( process.env.WP_EXPERIMENTAL_MODULES ) {
	// This match is against @wordpress/scripts' internal webpack config
	// shape, not a public API — if a future version restructures it, the
	// `.find()` above would silently return undefined and the tooltip
	// entry would just stop being built with no error, no different from
	// the WP_EXPERIMENTAL_MODULES-omitted case CLAUDE.md already warns is
	// easy to miss. Only warn when the env var IS set (module output was
	// actually expected this run) so an intentional non-module build
	// doesn't get a spurious warning.
	// eslint-disable-next-line no-console
	console.warn(
		'[saai-knowledge] webpack.config.js: no @wordpress/scripts config with output.module found; the tooltip/view script module entry was NOT added to the build.'
	);
}

module.exports = configs.length > 1 ? configs : configs[ 0 ];
