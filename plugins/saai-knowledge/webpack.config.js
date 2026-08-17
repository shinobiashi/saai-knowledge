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
}

module.exports = configs.length > 1 ? configs : configs[ 0 ];
