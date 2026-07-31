import { store, getContext } from '@wordpress/interactivity';

import './style.scss';

store( 'saai-knowledge/glossary-index', {
	state: {
		get isActiveBucket() {
			const context = getContext();

			return context.activeBucket === context.bucket;
		},
	},
	actions: {
		selectBucket() {
			const context = getContext();

			context.activeBucket = context.bucket;
		},
	},
} );
