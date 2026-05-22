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
import {
	createPortal,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';

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
	const anchorRef = useRef( null );

	useEffect( () => {
		const clearAnchor = () => {
			if ( anchorRef.current ) {
				anchorRef.current.classList.remove(
					'flavor-agent-inserter-badge-anchor'
				);
				anchorRef.current = null;
			}

			setAnchor( null );
		};

		if ( badgeState.status === 'hidden' ) {
			clearAnchor();
			return;
		}

		let retryId = null;
		const stopRetry = () => {
			if ( retryId ) {
				clearInterval( retryId );
				retryId = null;
			}
		};
		const startRetry = () => {
			if ( retryId || typeof setInterval !== 'function' ) {
				return;
			}

			retryId = setInterval( refreshAnchor, 250 );
		};
		const refreshAnchor = () => {
			const button = findInserterToggle();
			const nextAnchor = button?.parentElement || null;

			if ( nextAnchor ) {
				stopRetry();
			} else {
				startRetry();
			}

			if ( anchorRef.current === nextAnchor ) {
				return;
			}

			if ( anchorRef.current ) {
				anchorRef.current.classList.remove(
					'flavor-agent-inserter-badge-anchor'
				);
			}

			if ( nextAnchor ) {
				// Use a CSS class instead of mutating inline styles.
				nextAnchor.classList.add(
					'flavor-agent-inserter-badge-anchor'
				);
			}

			anchorRef.current = nextAnchor;
			setAnchor( nextAnchor );
		};

		refreshAnchor();

		const observerTarget = document.body || document.documentElement;
		const MutationObserverConstructor =
			typeof window !== 'undefined' ? window.MutationObserver : null;
		const observer =
			observerTarget && MutationObserverConstructor
				? new MutationObserverConstructor( refreshAnchor )
				: null;

		observer?.observe( observerTarget, {
			attributes: true,
			childList: true,
			subtree: true,
		} );

		return () => {
			observer?.disconnect();
			stopRetry();
			clearAnchor();
		};
	}, [ badgeState.status ] );

	if ( badgeState.status === 'hidden' || ! anchor ) {
		return null;
	}

	return createPortal(
		<output
			className={ badgeState.className }
			aria-label={ badgeState.ariaLabel }
			title={ badgeState.tooltip }
		>
			{ badgeState.content }
		</output>,
		anchor
	);
}
