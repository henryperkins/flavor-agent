/**
 * Inserter Badge
 *
 * Renders the unified pattern recommendation status badge next to
 * the inserter toggle button.
 *
 * Toggle button discovery goes through `inserter-dom.js` so caller
 * code treats missing toolbar markup as a clean degraded path.
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import { useEffect, useMemo, useState, createPortal } from '@wordpress/element';
import { Tooltip } from '@wordpress/components';

import { findInserterToggle } from './inserter-dom';
import { STORE_NAME } from '../store';
import { getInserterBadgeState } from './inserter-badge-state';
import { getAllowedPatterns } from './pattern-settings';
import { filterInsertableRecommendedPatterns } from './pattern-insertability';
import {
	buildRecommendedPatterns,
	getPatternBadgeReason,
} from './recommendation-utils';

export default function InserterBadge() {
	const patternState = useSelect( ( select ) => {
		const store = select( STORE_NAME );

		return {
			status: store.getPatternStatus(),
			recommendations: store.getPatternRecommendations(),
			error: store.getPatternError(),
		};
	}, [] );
	const renderableRecommendations = useSelect(
		( select ) => {
			const editor = select( blockEditorStore );
			const insertionPoint = editor.getBlockInsertionPoint?.() || null;
			const rootClientId = insertionPoint?.rootClientId ?? null;
			const allowedPatterns = getAllowedPatterns( rootClientId, editor );

			return filterInsertableRecommendedPatterns(
				buildRecommendedPatterns(
					patternState.recommendations,
					allowedPatterns
				),
				rootClientId,
				editor
			).map( ( { recommendation } ) => recommendation );
		},
		[ patternState.recommendations ]
	);
	const badgeState = useMemo(
		() =>
			getInserterBadgeState( {
				status: patternState.status,
				recommendations: renderableRecommendations,
				badge: getPatternBadgeReason( renderableRecommendations ),
				error: patternState.error,
			} ),
		[ patternState.error, patternState.status, renderableRecommendations ]
	);
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
