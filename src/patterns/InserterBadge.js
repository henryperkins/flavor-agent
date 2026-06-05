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

const BADGE_ANCHOR_CLASS = 'flavor-agent-inserter-badge-anchor';

function getOrCreateBadgeAnchor( button ) {
	if ( ! button?.parentElement ) {
		return null;
	}

	const next = button.nextElementSibling;
	if ( next?.classList?.contains( BADGE_ANCHOR_CLASS ) ) {
		return next;
	}

	const anchor = document.createElement( 'span' );
	anchor.className = BADGE_ANCHOR_CLASS;
	button.insertAdjacentElement( 'afterend', anchor );

	return anchor;
}

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
			// Our dedicated anchor only ever holds this component's own portal
			// output, so remove it whenever it is ours. A childNodes-empty
			// guard was unreliable: on unmount React tears the portal output
			// down after this cleanup runs, which would leave the span behind.
			if (
				anchorRef.current?.classList?.contains( BADGE_ANCHOR_CLASS )
			) {
				anchorRef.current.remove();
			}

			anchorRef.current = null;
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
			const nextAnchor = getOrCreateBadgeAnchor( button );

			if ( nextAnchor ) {
				stopRetry();
			} else {
				startRetry();
			}

			if ( anchorRef.current === nextAnchor ) {
				return;
			}

			if (
				anchorRef.current?.classList?.contains( BADGE_ANCHOR_CLASS )
			) {
				anchorRef.current.remove();
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
