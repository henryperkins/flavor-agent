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

import { patchPatternMetadata } from './recommendation-utils';
import { STORE_NAME } from '../store';

const SEARCH_DEBOUNCE_MS = 400;
const OBSERVER_TIMEOUT_MS = 3000;

/**
 * Known pattern template types that match the vocabulary used in
 * registered patterns' templateTypes arrays.
 */
const KNOWN_TEMPLATE_TYPES = new Set( [
	'index',
	'home',
	'front-page',
	'singular',
	'single',
	'page',
	'archive',
	'author',
	'category',
	'tag',
	'taxonomy',
	'date',
	'search',
	'404',
] );

/**
 * Normalize a template slug to the vocabulary used by pattern template types.
 *
 * @param {string|undefined} slug Template slug from the editor.
 *
 * @return {string|undefined} Normalized template type.
 */
function normalizeTemplateType( slug ) {
	if ( ! slug ) {
		return undefined;
	}

	if ( KNOWN_TEMPLATE_TYPES.has( slug ) ) {
		return slug;
	}

	const base = slug.split( '-' )[ 0 ];

	if ( KNOWN_TEMPLATE_TYPES.has( base ) ) {
		return base;
	}

	return undefined;
}

/**
 * Module-level map preserving original metadata for rollback.
 * Key: pattern name, Value: { description, keywords, categories }
 */
const originalMetadata = new Map();

function getVisiblePatternNames() {
	const blockEditor = registrySelect( blockEditorStore );

	if ( typeof blockEditor.__experimentalGetAllowedPatterns === 'function' ) {
		return Array.from(
			new Set(
				blockEditor
					.__experimentalGetAllowedPatterns( null )
					.map( ( pattern ) => pattern?.name )
					.filter( Boolean )
			)
		);
	}

	const settings = blockEditor.getSettings();
	const patterns = Array.isArray( settings.__experimentalBlockPatterns )
		? settings.__experimentalBlockPatterns
		: [];

	return Array.from(
		new Set(
			patterns.map( ( pattern ) => pattern?.name ).filter( Boolean )
		)
	);
}

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
			visiblePatternNames: getVisiblePatternNames(),
		};

		if ( templateType ) {
			input.templateType = templateType;
		}

		return input;
	}, [ postType, templateType ] );

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

		function findSearchInput() {
			const inserter = document.querySelector(
				'.block-editor-inserter__panel-content, .block-editor-inserter__content'
			);

			if ( ! inserter ) {
				return null;
			}

			return (
				inserter.querySelector( '[role="searchbox"]' ) ||
				inserter.querySelector(
					'.block-editor-inserter__search input'
				) ||
				inserter.querySelector( 'input[type="search"]' )
			);
		}

		const existing = findSearchInput();

		if ( existing ) {
			attachToSearch( existing );
			return cleanupBindings;
		}

		if ( ! window.MutationObserver ) {
			return cleanupBindings;
		}

		const observer = new window.MutationObserver( () => {
			const input = findSearchInput();

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
