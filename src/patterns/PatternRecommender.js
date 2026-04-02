/**
 * Pattern Recommender
 *
 * Fetches AI-ranked pattern recommendations and patches the native
 * block inserter's pattern data so recommended patterns appear in
 * a "Recommended" category with contextual descriptions.
 *
 * Two modes:
 * - Passive: fetches on editor load using postType
 * - Active: fetches on inserter search input with prompt
 * - Unavailable: keeps the inserter surface visible with a shared
 *   capability notice when ranking backends are missing
 *
 * Pattern settings reads and DOM discovery are split so selector
 * degradation stays isolated from the settings compatibility path.
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import {
	useCallback,
	useEffect,
	useRef,
	createPortal,
} from '@wordpress/element';

import CapabilityNotice from '../components/CapabilityNotice';
import { getBlockPatterns, setBlockPatterns } from './pattern-settings';
import { findInserterContainer, findInserterSearchInput } from './inserter-dom';
import { patchPatternMetadata } from './recommendation-utils';
import { STORE_NAME } from '../store';
import { getSurfaceCapability } from '../utils/capability-flags';
import { normalizeTemplateType } from '../utils/template-types';
import { getVisiblePatternNames } from '../utils/visible-patterns';

const SEARCH_DEBOUNCE_MS = 400;
const OBSERVER_TIMEOUT_MS = 3000;
const NOTICE_SLOT_CLASS = 'flavor-agent-pattern-notice-slot';

/**
 * Module-level map preserving original metadata for rollback.
 * Key: pattern name, Value: { description, keywords, categories }
 */
const originalMetadata = new Map();

/**
 * Read-modify-write on block patterns via compatibility adapter.
 *
 * @param {Array} recommendations Current recommendation set.
 *
 * @return {void}
 */
function patchInserterPatterns( recommendations ) {
	const patterns = getBlockPatterns();

	if ( patterns.length === 0 ) {
		return;
	}

	const patched = patchPatternMetadata(
		patterns,
		recommendations,
		originalMetadata
	);

	setBlockPatterns( patched );
}

export default function PatternRecommender() {
	const canRecommend = getSurfaceCapability( 'pattern' ).available;
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);
	const isInserterOpen = useSelect(
		( select ) => select( editorStore ).isInserterOpened(),
		[]
	);
	const templateType = useSelect( ( select ) => {
		const editSite = select( 'core/edit-site' );

		if ( ! editSite?.getEditedPostType ) {
			return undefined;
		}

		if ( editSite.getEditedPostType() !== 'wp_template' ) {
			return undefined;
		}

		return normalizeTemplateType( editSite.getEditedPostId() );
	}, [] );
	const selectedBlockName = useSelect( ( select ) => {
		const clientId = select( blockEditorStore ).getSelectedBlockClientId();

		if ( ! clientId ) {
			return null;
		}

		return select( blockEditorStore ).getBlockName( clientId );
	}, [] );
	const inserterRootClientId = useSelect( ( select ) => {
		return (
			select( blockEditorStore ).getBlockInsertionPoint?.()
				?.rootClientId ?? null
		);
	}, [] );
	const visiblePatternNames = useSelect(
		( select ) => {
			return getVisiblePatternNames(
				inserterRootClientId,
				select( blockEditorStore )
			);
		},
		[ inserterRootClientId ]
	);
	const patternRegistryVersion = useSelect( ( select ) => {
		const settings = select( blockEditorStore ).getSettings?.() || {};

		if ( Array.isArray( settings.blockPatterns ) ) {
			return `stable:${ settings.blockPatterns.length }`;
		}

		if ( Array.isArray( settings.__experimentalAdditionalBlockPatterns ) ) {
			return `experimental-additional:${ settings.__experimentalAdditionalBlockPatterns.length }`;
		}

		if ( Array.isArray( settings.__experimentalBlockPatterns ) ) {
			return `experimental:${ settings.__experimentalBlockPatterns.length }`;
		}

		return 'none:0';
	}, [] );
	const recommendations = useSelect(
		( select ) => select( STORE_NAME ).getPatternRecommendations(),
		[]
	);
	const { fetchPatternRecommendations } = useDispatch( STORE_NAME );
	const observerRef = useRef( null );
	const listenerRef = useRef( null );
	const debounceRef = useRef( null );
	const noticeObserverRef = useRef( null );
	const noticeSlotRef = useRef( null );

	if ( ! noticeSlotRef.current && typeof document !== 'undefined' ) {
		const noticeSlot = document.createElement( 'div' );
		noticeSlot.className = NOTICE_SLOT_CLASS;
		noticeSlotRef.current = noticeSlot;
	}

	const buildBaseInput = useCallback( () => {
		const input = {
			postType,
			visiblePatternNames,
		};

		if ( templateType ) {
			input.templateType = templateType;
		}

		return input;
	}, [ postType, templateType, visiblePatternNames ] );

	useEffect( () => {
		if ( ! canRecommend || ! postType ) {
			return;
		}

		fetchPatternRecommendations( buildBaseInput() );
	}, [
		canRecommend,
		postType,
		buildBaseInput,
		fetchPatternRecommendations,
	] );

	useEffect( () => {
		patchInserterPatterns( recommendations );
	}, [ recommendations, patternRegistryVersion ] );

	useEffect( () => {
		const noticeSlot = noticeSlotRef.current;

		const cleanupNotice = () => {
			if ( noticeObserverRef.current ) {
				noticeObserverRef.current.disconnect();
				noticeObserverRef.current = null;
			}

			if ( noticeSlot?.parentNode ) {
				noticeSlot.parentNode.removeChild( noticeSlot );
			}
		};

		const attachNotice = ( inserterContainer ) => {
			if ( ! noticeSlot || ! inserterContainer ) {
				return;
			}

			if ( noticeSlot.parentNode === inserterContainer ) {
				return;
			}

			if ( noticeSlot.parentNode ) {
				noticeSlot.parentNode.removeChild( noticeSlot );
			}

			inserterContainer.insertBefore(
				noticeSlot,
				inserterContainer.firstChild
			);
		};

		if ( canRecommend || ! isInserterOpen ) {
			cleanupNotice();
			return undefined;
		}

		const existingContainer = findInserterContainer( document );

		if ( existingContainer ) {
			attachNotice( existingContainer );
			return cleanupNotice;
		}

		if ( ! window.MutationObserver ) {
			return cleanupNotice;
		}

		const observer = new window.MutationObserver( () => {
			const inserterContainer = findInserterContainer( document );

			if ( ! inserterContainer ) {
				return;
			}

			attachNotice( inserterContainer );
			observer.disconnect();
			noticeObserverRef.current = null;
		} );

		observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );
		noticeObserverRef.current = observer;

		const timeout = setTimeout( () => {
			if ( noticeObserverRef.current ) {
				noticeObserverRef.current.disconnect();
				noticeObserverRef.current = null;
			}
		}, OBSERVER_TIMEOUT_MS );

		return () => {
			clearTimeout( timeout );
			cleanupNotice();
		};
	}, [ canRecommend, isInserterOpen ] );

	const handleSearchInput = useCallback(
		( value ) => {
			if ( debounceRef.current ) {
				clearTimeout( debounceRef.current );
			}

			debounceRef.current = setTimeout( () => {
				if ( ! postType ) {
					return;
				}

				const input = buildBaseInput();
				const trimmedValue = value.trim();

				if ( trimmedValue ) {
					input.prompt = trimmedValue;
				}

				if ( selectedBlockName ) {
					input.blockContext = { blockName: selectedBlockName };
				}

				fetchPatternRecommendations( input );
			}, SEARCH_DEBOUNCE_MS );
		},
		[
			postType,
			buildBaseInput,
			selectedBlockName,
			fetchPatternRecommendations,
		]
	);

	useEffect( () => {
		const cleanupBindings = () => {
			if ( debounceRef.current ) {
				clearTimeout( debounceRef.current );
				debounceRef.current = null;
			}

			if ( observerRef.current ) {
				observerRef.current.disconnect();
				observerRef.current = null;
			}

			if ( listenerRef.current ) {
				listenerRef.current.el.removeEventListener(
					'input',
					listenerRef.current.fn
				);
				listenerRef.current = null;
			}
		};

		if ( ! canRecommend || ! isInserterOpen ) {
			cleanupBindings();
			return undefined;
		}

		function attachToSearch( searchInput ) {
			if ( listenerRef.current ) {
				listenerRef.current.el.removeEventListener(
					'input',
					listenerRef.current.fn
				);
			}

			const fn = ( event ) => handleSearchInput( event.target.value );

			searchInput.addEventListener( 'input', fn );
			listenerRef.current = { el: searchInput, fn };
		}

		const existing = findInserterSearchInput( document );

		if ( existing ) {
			attachToSearch( existing );
			return cleanupBindings;
		}

		if ( ! window.MutationObserver ) {
			return cleanupBindings;
		}

		const observer = new window.MutationObserver( () => {
			const input = findInserterSearchInput( document );

			if ( ! input ) {
				return;
			}

			attachToSearch( input );
			observer.disconnect();
			observerRef.current = null;
		} );

		observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );
		observerRef.current = observer;

		const timeout = setTimeout( () => {
			if ( observerRef.current ) {
				observerRef.current.disconnect();
				observerRef.current = null;
			}
		}, OBSERVER_TIMEOUT_MS );

		return () => {
			clearTimeout( timeout );
			cleanupBindings();
		};
	}, [ canRecommend, isInserterOpen, handleSearchInput ] );

	if ( ! canRecommend && isInserterOpen && noticeSlotRef.current ) {
		return createPortal(
			<div className="flavor-agent-pattern-notice">
				<CapabilityNotice surface="pattern" />
			</div>,
			noticeSlotRef.current
		);
	}

	return null;
}
