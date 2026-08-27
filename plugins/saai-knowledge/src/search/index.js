import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, RangeControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

import metadata from './block.json';
import './editor.scss';

function Edit( { attributes, setAttributes } ) {
	const { placeholder, perPage } = attributes;
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Search settings', 'saai-knowledge' ) }>
					<TextControl
						label={ __( 'Placeholder text', 'saai-knowledge' ) }
						value={ placeholder }
						onChange={ ( value ) =>
							setAttributes( { placeholder: value } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<RangeControl
						label={ __( 'Maximum results', 'saai-knowledge' ) }
						value={ perPage }
						onChange={ ( value ) =>
							setAttributes( { perPage: value ?? 5 } )
						}
						min={ 1 }
						max={ 20 }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<ServerSideRender
					block={ metadata.name }
					attributes={ attributes }
				/>
			</div>
		</>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
