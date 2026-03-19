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
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import {
	useDispatch,
	useSelect,
	select as registrySelect,
	dispatch as registryDispatch,
} from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useCallback, useEffect, useRef } from '@wordpress/element';

import { findInserterSearchInput } from './find-inserter-search-input';
import { patchPatternMetadata } from './recommendation-utils';
import { STORE_NAME } from '../store';
import { normalizeTemplateType } from '../utils/template-types';
import { getVisiblePatternNames } from '../utils/visible-patterns';

const SEARCH_DEBOUNCE_MS = 400;
const OBSERVER_TIMEOUT_MS = 3000;

/**
 * Module-level map preserving original metadata for rollback.
 * Key: pattern name, Value: { description, keywords, categories }
 */
const originalMetadata = new Map();

/**
 * Read-modify-write on __experimentalBlockPatterns.
 *
 * @param {Array} recommendations Current recommendation set.
 *
 * @return {void}
 */
function patchInserterPatterns( recommendations ) {
	const settings = registrySelect( blockEditorStore ).getSettings();
	const patterns = settings.__experimentalBlockPatterns;

	if ( ! Array.isArray( patterns ) ) {
		return;
	}

	const patched = patchPatternMetadata(
		patterns,
		recommendations,
		originalMetadata
	);

	registryDispatch( blockEditorStore ).updateSettings( {
		__experimentalBlockPatterns: patched,
	} );
}

export default function PatternRecommender() {
	const canRecommend = window.flavorAgentData?.canRecommendPatterns;
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
	const recommendations = useSelect(
		( select ) => select( STORE_NAME ).getPatternRecommendations(),
		[]
	);
	const { fetchPatternRecommendations } = useDispatch( STORE_NAME );
	const observerRef = useRef( null );
	const listenerRef = useRef( null );
	const debounceRef = useRef( null );

	const buildBaseInput = useCallback( () => {
		const input = {
			postType,
			visiblePatternNames: getVisiblePatternNames( inserterRootClientId ),
		};

		if ( templateType ) {
			input.templateType = templateType;
		}

		return input;
	}, [ postType, templateType, inserterRootClientId ] );

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
	}, [ recommendations ] );

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

	return null;
}
