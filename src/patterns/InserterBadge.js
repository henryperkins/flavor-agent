/**
 * Inserter Badge
 *
 * Renders a "!" indicator next to the inserter toggle button
 * when a pattern recommendation scores >= 0.9. Hovering shows
 * a tooltip with the recommendation reason.
 */
import { useSelect } from '@wordpress/data';
import { useEffect, useState, createPortal } from '@wordpress/element';
import { Tooltip } from '@wordpress/components';

import { STORE_NAME } from '../store';

export default function InserterBadge() {
	const badge = useSelect(
		( select ) => select( STORE_NAME ).getPatternBadge(),
		[]
	);
	const [ anchor, setAnchor ] = useState( null );

	useEffect( () => {
		if ( ! badge ) {
			setAnchor( null );
			return;
		}

		// Primary: stable class selector.
		let button = document.querySelector(
			'button.block-editor-inserter__toggle'
		);

		// Fallback: aria-label containing "inserter" (case-insensitive).
		if ( ! button ) {
			const allButtons = document.querySelectorAll(
				'.edit-post-header-toolbar button, .edit-site-header__start button'
			);
			for ( const b of allButtons ) {
				const label = b.getAttribute( 'aria-label' ) || '';
				if ( /inserter/i.test( label ) ) {
					button = b;
					break;
				}
			}
		}

		if ( button?.parentElement ) {
			// Ensure the parent has relative positioning for the badge.
			button.parentElement.style.position = 'relative';
			setAnchor( button.parentElement );
		}
	}, [ badge ] );

	if ( ! badge || ! anchor ) {
		return null;
	}

	return createPortal(
		<Tooltip text={ badge }>
			<span
				aria-label="Pattern recommendations available"
				style={ {
					position: 'absolute',
					top: '2px',
					right: '-4px',
					width: '16px',
					height: '16px',
					borderRadius: '50%',
					background: '#3858e9',
					color: '#fff',
					fontSize: '11px',
					fontWeight: 700,
					lineHeight: '16px',
					textAlign: 'center',
					cursor: 'default',
					zIndex: 100,
					pointerEvents: 'auto',
				} }
			>
				!
			</span>
		</Tooltip>,
		anchor
	);
}
