import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';
import createEdit from '../shared/edit';

registerBlockType( metadata.name, {
	edit: createEdit( {
		name: metadata.name,
		title: __( 'Product Glossary', 'saai-knowledge-for-woocommerce' ),
		icon: metadata.icon,
		noProductText: __(
			'Shows the glossary terms linked to the product being displayed. Choose a product in the block settings to preview it here, or to show a specific product on a regular page.',
			'saai-knowledge-for-woocommerce'
		),
		noContentText: __(
			'No published glossary terms are linked to this product. Nothing is shown on the site.',
			'saai-knowledge-for-woocommerce'
		),
		automaticNotice: __(
			'This list is independent of the automatic glossary tooltips in product descriptions, which stay as they are.',
			'saai-knowledge-for-woocommerce'
		),
	} ),
	save: () => null,
} );
