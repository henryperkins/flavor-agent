/**
 * Inserter Badge
 *
 * Renders the unified pattern recommendation status badge next to
 * the inserter toggle button.
 */
import { useSelect } from '@wordpress/data';
import { useEffect, useState, createPortal } from '@wordpress/element';
import { Tooltip } from '@wordpress/components';

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
