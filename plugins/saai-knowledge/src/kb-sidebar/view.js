import { store, getContext } from '@wordpress/interactivity';

import './style.scss';

store( 'saai-knowledge/kb-sidebar', {
	actions: {
		toggle() {
			const context = getContext();
			context.open = ! context.open;
		},
	},
} );
