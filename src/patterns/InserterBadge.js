/**
 * Inserter Badge
 *
 * Renders the unified pattern recommendation status badge next to
 * the inserter toggle button.
 *
 * Toggle button discovery goes through `inserter-dom.js` so caller
 * code treats missing toolbar markup as a clean degraded path.
 */
import { useSelect } from '@wordpress/data';
import { useEffect, useState, createPortal } from '@wordpress/element';
import { Tooltip } from '@wordpress/components';

import { findInserterToggle } from './inserter-dom';
import { STORE_NAME } from '../store';
import { getInserterBadgeState } from './inserter-badge-state';

export default function InserterBadge() {
	const badgeState = useSelect( ( select ) => {
		const store = select( STORE_NAME );

		return getInserterBadgeState( {
			status: store.getPatternStatus(),
			recommendations: store.getPatternRecommendations(),
			badge: store.getPatternBadge(),
			error: store.getPatternError(),
		} );
	}, [] );
	const [ anchor, setAnchor ] = useState( null );

	useEffect( () => {
		if ( badgeState.status === 'hidden' ) {
			setAnchor( null );
			return;
		}

		const button = findInserterToggle();
		const parent = button?.parentElement;
		if ( ! parent ) {
			setAnchor( null );
			return;
		}

		// Use a CSS class instead of mutating inline styles.
		parent.classList.add( 'flavor-agent-inserter-badge-anchor' );
		setAnchor( parent );

		return () => {
			parent.classList.remove( 'flavor-agent-inserter-badge-anchor' );
		};
	}, [ badgeState.status ] );

	if ( badgeState.status === 'hidden' || ! anchor ) {
		return null;
	}

	return createPortal(
		<Tooltip text={ badgeState.tooltip }>
			<span
				className={ badgeState.className }
				aria-label={ badgeState.ariaLabel }
			>
				{ badgeState.content }
			</span>
		</Tooltip>,
		anchor
	);
}
