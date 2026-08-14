import { store, getContext, getElement } from '@wordpress/interactivity';

import './style.scss';

const ARROW_KEYS = {
	ArrowLeft: -1,
	ArrowRight: 1,
};

const { state } = store( 'saai-knowledge/glossary-index', {
	state: {
		get isActiveBucket() {
			const context = getContext();

			return context.activeBucket === context.bucket;
		},
		get tabIndex() {
			return state.isActiveBucket ? 0 : -1;
		},
	},
	actions: {
		selectBucket() {
			const context = getContext();

			context.activeBucket = context.bucket;
		},
		// WAI-ARIA Tabs pattern keyboard support: Left/Right move to the
		// adjacent tab (wrapping), Home/End jump to the first/last. Reads
		// sibling tabs from the DOM (not Interactivity API state — see
		// render.php's data-bucket comment) since that's the only way to
		// reach another tab's bucket key from here.
		onTabKeydown( event ) {
			const delta = ARROW_KEYS[ event.key ];
			const isEdgeKey = 'Home' === event.key || 'End' === event.key;

			if ( ! delta && ! isEdgeKey ) {
				return;
			}

			event.preventDefault();

			const { ref } = getElement();
			const tabs = Array.from(
				ref
					.closest( '[role="tablist"]' )
					.querySelectorAll( '[role="tab"]' )
			);
			const currentIndex = tabs.indexOf( ref );

			let nextIndex = currentIndex;

			if ( delta ) {
				nextIndex =
					( currentIndex + delta + tabs.length ) % tabs.length;
			} else if ( 'Home' === event.key ) {
				nextIndex = 0;
			} else {
				nextIndex = tabs.length - 1;
			}

			const nextTab = tabs[ nextIndex ];

			getContext().activeBucket = nextTab.dataset.bucket;
			nextTab.focus();
		},
	},
} );
