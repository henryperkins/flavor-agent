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

		const parent = button?.parentElement;
		if ( ! parent ) {
			return;
		}

		// Use a CSS class instead of mutating inline styles.
		parent.classList.add( 'flavor-agent-inserter-badge-anchor' );
		setAnchor( parent );

		return () => {
			parent.classList.remove( 'flavor-agent-inserter-badge-anchor' );
		};
	}, [ badge ] );

	if ( ! badge || ! anchor ) {
		return null;
	}

	return createPortal(
		<Tooltip text={ badge }>
			<span
				className="flavor-agent-inserter-badge"
				aria-label="Pattern recommendations available"
			>
				!
			</span>
		</Tooltip>,
		anchor
	);
}
