import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, Placeholder, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

import ProductPicker from './product-picker';

/**
 * Builds the editor component shared by the product blocks.
 *
 * The preview is the block's own render.php through ServerSideRender. That
 * renders nothing when there is nothing to list (no product to resolve, or
 * no published content linked to it), so the empty response is replaced
 * with an explanation instead of the generic "Block rendered as empty."
 *
 * @param {Object} settings                 Block-specific settings.
 * @param {string} settings.name            Block name.
 * @param {string} settings.title           Block title, for the placeholder.
 * @param {string} settings.icon            Dashicon name, for the placeholder.
 * @param {string} settings.noProductText   Shown when no product resolves for the preview.
 * @param {string} settings.noContentText   Shown when the chosen product has nothing linked.
 * @param {string} settings.automaticNotice Where the matching automatic insertion is switched off.
 * @return {Function} The block's edit component.
 */
export default function createEdit( {
	name,
	title,
	icon,
	noProductText,
	noContentText,
	automaticNotice,
} ) {
	function EmptyPreview( { attributes } ) {
		return (
			<Placeholder
				icon={ icon }
				label={ title }
				instructions={
					attributes.productId > 0 ? noContentText : noProductText
				}
			/>
		);
	}

	function Edit( { attributes, setAttributes } ) {
		const { productId, showTitle } = attributes;
		const blockProps = useBlockProps();

		return (
			<>
				<InspectorControls>
					<PanelBody
						title={ __(
							'Settings',
							'saai-knowledge-for-woocommerce'
						) }
					>
						<ProductPicker
							productId={ productId }
							onChange={ ( id ) =>
								setAttributes( { productId: id } )
							}
						/>
						<ToggleControl
							label={ __(
								'Show heading',
								'saai-knowledge-for-woocommerce'
							) }
							checked={ showTitle }
							onChange={ ( value ) =>
								setAttributes( { showTitle: value } )
							}
							__nextHasNoMarginBottom
						/>
						<p>{ automaticNotice }</p>
					</PanelBody>
				</InspectorControls>
				<div { ...blockProps }>
					<ServerSideRender
						block={ name }
						attributes={ attributes }
						EmptyResponsePlaceholder={ EmptyPreview }
					/>
				</div>
			</>
		);
	}

	return Edit;
}
