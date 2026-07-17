( function ( wp ) {
	'use strict';

	var registerPlugin = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var useEntityProp = wp.coreData.useEntityProp;
	var createElement = wp.element.createElement;
	var __ = wp.i18n.__;

	if ( ! PluginDocumentSettingPanel ) {
		return;
	}

	function GlossaryFieldsPanel() {
		var meta = useEntityProp( 'postType', 'saai_glossary', 'meta' );
		var metaValue = meta[ 0 ];
		var setMeta = meta[ 1 ];

		function updateField( key ) {
			return function ( value ) {
				var next = {};
				next[ key ] = value;
				setMeta( Object.assign( {}, metaValue, next ) );
			};
		}

		return createElement(
			PluginDocumentSettingPanel,
			{
				name: 'saai-glossary-fields',
				title: __( 'Glossary Details', 'saai-knowledge' ),
			},
			createElement( TextControl, {
				label: __( 'Reading (kana/index sort key)', 'saai-knowledge' ),
				value: metaValue.saai_reading || '',
				onChange: updateField( 'saai_reading' ),
			} ),
			createElement( TextareaControl, {
				label: __( 'Synonyms (one per line)', 'saai-knowledge' ),
				value: metaValue.saai_synonyms || '',
				onChange: updateField( 'saai_synonyms' ),
			} )
		);
	}

	registerPlugin( 'saai-knowledge-glossary-panel', {
		render: GlossaryFieldsPanel,
	} );
} )( window.wp );
