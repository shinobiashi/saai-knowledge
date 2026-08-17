import { store, getElement } from '@wordpress/interactivity';

import './style.scss';

const TOOLTIP_ID = 'saai-tooltip';

let escapeListenerAttached = false;

function getTooltipElement() {
	return document.getElementById( TOOLTIP_ID );
}

function positionTooltip( tooltip, anchor ) {
	const rect = anchor.getBoundingClientRect();
	const scrollX = window.scrollX || document.documentElement.scrollLeft;
	const scrollY = window.scrollY || document.documentElement.scrollTop;

	tooltip.style.left = `${ rect.left + scrollX }px`;
	tooltip.style.top = `${ rect.bottom + scrollY + 8 }px`;
}

function hideTooltip() {
	const tooltip = getTooltipElement();

	if ( ! tooltip || tooltip.hasAttribute( 'hidden' ) ) {
		return;
	}

	tooltip.setAttribute( 'hidden', '' );

	const shownFor = tooltip.getAttribute( 'data-saai-shown-for' );
	const anchor = shownFor ? document.getElementById( shownFor ) : null;

	if ( anchor ) {
		anchor.removeAttribute( 'aria-expanded' );
	}

	tooltip.removeAttribute( 'data-saai-shown-for' );
}

const { actions } = store( 'saai-knowledge/tooltip', {
	actions: {
		show() {
			const { ref } = getElement();
			const tooltip = getTooltipElement();

			if ( ! ref || ! tooltip ) {
				return;
			}

			// A singleton tooltip can only describe one anchor at a time;
			// hovering/focusing a new term reassigns it.
			hideTooltip();

			if ( ! ref.id ) {
				ref.id = `saai-term-${
					ref.getAttribute( 'data-saai-term-id' ) || Date.now()
				}`;
			}

			tooltip.textContent = ref.getAttribute( 'data-saai-tooltip' ) || '';
			tooltip.setAttribute( 'data-saai-shown-for', ref.id );
			positionTooltip( tooltip, ref );
			tooltip.removeAttribute( 'hidden' );
			ref.setAttribute( 'aria-expanded', 'true' );
		},
		hide() {
			hideTooltip();
		},
		handleClick( event ) {
			const tooltip = getTooltipElement();

			// Touch devices don't fire mouseenter/focus before click: the
			// first tap only reveals the tooltip, the second tap (tooltip
			// already visible) is left alone to navigate.
			if ( tooltip && tooltip.hasAttribute( 'hidden' ) ) {
				event.preventDefault();
				actions.show();
			}
		},
	},
	callbacks: {
		initTooltipListeners() {
			if ( escapeListenerAttached ) {
				return;
			}

			escapeListenerAttached = true;

			window.addEventListener( 'keydown', ( event ) => {
				if ( 'Escape' === event.key ) {
					hideTooltip();
				}
			} );
		},
	},
} );
