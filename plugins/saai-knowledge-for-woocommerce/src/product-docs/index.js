import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';
import createEdit from '../shared/edit';

registerBlockType( metadata.name, {
	edit: createEdit( {
		name: metadata.name,
		title: __( 'Product Documentation', 'saai-knowledge-for-woocommerce' ),
		icon: metadata.icon,
		noProductText: __(
			'Shows the knowledge base articles linked to the product being displayed. In the Single Product template, leave the product empty so each product page shows its own. To show one specific product, for example on a regular page, choose it in the block settings.',
			'saai-knowledge-for-woocommerce'
		),
		noContentText: __(
			'No published knowledge base articles are linked to this product. Nothing is shown on the site.',
			'saai-knowledge-for-woocommerce'
		),
		automaticNotice: __(
			'Product pages can also show these articles automatically below the product summary. To avoid listing them twice, turn that off under SAAI Knowledge › Settings › WooCommerce.',
			'saai-knowledge-for-woocommerce'
		),
	} ),
	save: () => null,
} );
