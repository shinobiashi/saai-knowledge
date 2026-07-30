import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

import metadata from './block.json';
import './editor.scss';

function Edit( { attributes, setAttributes } ) {
	const { category, count, orderBy, order, groupByCategory } = attributes;
	const blockProps = useBlockProps();

	const categories = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', 'saai_category', {
				per_page: -1,
			} ),
		[]
	);

	const categoryOptions = [
		{ label: __( 'All categories', 'saai-knowledge' ), value: '' },
		...( categories ?? [] ).map( ( term ) => ( {
			label: term.name,
			value: term.slug,
		} ) ),
	];

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'FAQ settings', 'saai-knowledge' ) }>
					<SelectControl
						label={ __( 'Category', 'saai-knowledge' ) }
						value={ category }
						options={ categoryOptions }
						onChange={ ( value ) =>
							setAttributes( { category: value } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<RangeControl
						label={ __( 'Number of FAQs', 'saai-knowledge' ) }
						help={ __( '0 shows all FAQs.', 'saai-knowledge' ) }
						value={ count }
						onChange={ ( value ) =>
							setAttributes( { count: value ?? 0 } )
						}
						min={ 0 }
						max={ 50 }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<SelectControl
						label={ __( 'Order by', 'saai-knowledge' ) }
						value={ orderBy }
						options={ [
							{
								label: __( 'Date', 'saai-knowledge' ),
								value: 'date',
							},
							{
								label: __( 'Title', 'saai-knowledge' ),
								value: 'title',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { orderBy: value } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<SelectControl
						label={ __( 'Order', 'saai-knowledge' ) }
						value={ order }
						options={ [
							{
								label: __( 'Descending', 'saai-knowledge' ),
								value: 'desc',
							},
							{
								label: __( 'Ascending', 'saai-knowledge' ),
								value: 'asc',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { order: value } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<ToggleControl
						label={ __( 'Group by category', 'saai-knowledge' ) }
						help={ __(
							'Show a heading per category. Each FAQ appears under its first category in display order.',
							'saai-knowledge'
						) }
						checked={ groupByCategory }
						onChange={ ( value ) =>
							setAttributes( { groupByCategory: value } )
						}
						__nextHasNoMarginBottom
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
