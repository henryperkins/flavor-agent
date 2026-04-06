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
import { formatCount } from '../utils/format-count';
import {
	getBlockPatternCategories,
	getBlockPatterns,
	setBlockPatternCategories,
	setBlockPatterns,
} from './pattern-settings';
import { findInserterContainer, findInserterSearchInput } from './inserter-dom';
import {
	patchPatternCategoryRegistry,
	patchPatternMetadata,
} from './recommendation-utils';
import { STORE_NAME } from '../store';
import { getSurfaceCapability } from '../utils/capability-flags';
import { usePostTypeEntityContract } from '../utils/editor-entity-contracts';
import {
	getTemplatePartAreaLookup,
	inferTemplatePartArea,
} from '../utils/template-part-areas';
import { normalizeTemplateType } from '../utils/template-types';
import { getVisiblePatternNames } from '../utils/visible-patterns';

const SEARCH_DEBOUNCE_MS = 400;
const OBSERVER_TIMEOUT_MS = 3000;
const INSERTER_SLOT_CLASS = 'flavor-agent-pattern-inserter-slot';

function getRegistryVersion( entries, prefix, includeLabel = false ) {
	if ( ! Array.isArray( entries ) ) {
		return `${ prefix }:none`;
	}

	return `${ prefix }:${ entries
		.map( ( entry, index ) => {
			const name =
				typeof entry?.name === 'string' && entry.name
					? entry.name
					: `index-${ index }`;

			if ( ! includeLabel ) {
				return name;
			}

			const label = typeof entry?.label === 'string' ? entry.label : '';

			return `${ name }:${ label }`;
		} )
		.join( '|' ) }`;
}

function createPatchState() {
	return {
		originalMetadata: new Map(),
		categoryOwnership: {
			injectedCategories: new Set(),
			registry: null,
		},
	};
}

function getNonEmptyString( value ) {
	return typeof value === 'string' && value.trim() !== '' ? value.trim() : '';
}

function buildAncestorEntries( editor, inserterRootClientId ) {
	if ( ! inserterRootClientId ) {
		return [];
	}

	const ancestors = [];
	let parentId = inserterRootClientId;

	while ( parentId ) {
		ancestors.unshift( {
			clientId: parentId,
			blockName: editor.getBlockName?.( parentId ) || '',
			attributes: editor.getBlockAttributes?.( parentId ) || {},
		} );
		parentId = editor.getBlockRootClientId?.( parentId ) ?? null;
	}

	return ancestors;
}

function buildInsertionContext( editor, inserterRootClientId, insertionPoint ) {
	const ancestorEntries = buildAncestorEntries(
		editor,
		inserterRootClientId
	);
	const rootEntry = ancestorEntries[ ancestorEntries.length - 1 ] || null;
	const areaLookup = getTemplatePartAreaLookup();
	const nearestTemplatePart = [ ...ancestorEntries ]
		.reverse()
		.find( ( entry ) => entry.blockName === 'core/template-part' );
	const templatePartArea = nearestTemplatePart
		? inferTemplatePartArea( nearestTemplatePart.attributes, areaLookup )
		: '';
	const templatePartSlug = getNonEmptyString(
		nearestTemplatePart?.attributes?.slug
	);
	const containerLayout = getNonEmptyString(
		rootEntry?.attributes?.layout?.type
	);
	const siblingOrder = editor.getBlockOrder?.( inserterRootClientId ) || [];
	const insertIndex = insertionPoint?.index ?? siblingOrder.length;
	const nearbySiblings = [];
	const start = Math.max( 0, insertIndex - 3 );
	const end = Math.min( siblingOrder.length, insertIndex + 3 );

	for ( let i = start; i < end; i++ ) {
		const name = editor.getBlockName?.( siblingOrder[ i ] );

		if ( name ) {
			nearbySiblings.push( name );
		}
	}

	return {
		rootBlock: rootEntry?.blockName || null,
		ancestors: ancestorEntries
			.map( ( entry ) => entry.blockName )
			.filter( Boolean ),
		nearbySiblings,
		...( templatePartArea ? { templatePartArea } : {} ),
		...( templatePartSlug ? { templatePartSlug } : {} ),
		...( containerLayout ? { containerLayout } : {} ),
	};
}

/**
 * Read-modify-write on block patterns via compatibility adapter.
 *
 * @param {Array}  recommendations     Current recommendation set.
 * @param {string} recommendedCategory Recommended category slug.
 * @param {Object} patchState          Surface-local rollback state.
 *
 * @return {void}
 */
function patchInserterPatterns(
	recommendations,
	recommendedCategory,
	patchState
) {
	const patterns = getBlockPatterns();

	if ( patterns.length === 0 ) {
		return;
	}

	const categories = getBlockPatternCategories();
	const patched = patchPatternMetadata(
		patterns,
		recommendations,
		patchState.originalMetadata,
		recommendedCategory
	);

	setBlockPatterns( patched );
	setBlockPatternCategories(
		patchPatternCategoryRegistry(
			categories,
			recommendations,
			patchState.categoryOwnership,
			recommendedCategory
		)
	);
}

function PatternSummary( { count } ) {
	return (
		<div
			className="flavor-agent-pattern-summary"
			role="status"
			aria-live="polite"
		>
			<div className="flavor-agent-pattern-summary__header">
				<span className="flavor-agent-pill">Flavor Agent</span>
				<span className="flavor-agent-pill">
					{ formatCount( count, 'recommendation' ) }
				</span>
			</div>
			<p className="flavor-agent-pattern-summary__copy">
				Recommended now includes{ ' ' }
				{ formatCount( count, 'AI-ranked pattern' ) } for this insertion
				point.
			</p>
		</div>
	);
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
	const patternContract = usePostTypeEntityContract( 'wp_block' );
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
	const insertionContext = useSelect(
		( select ) => {
			const editor = select( blockEditorStore );
			const insertionPoint = editor.getBlockInsertionPoint?.();

			if ( ! insertionPoint ) {
				return null;
			}

			return buildInsertionContext(
				editor,
				inserterRootClientId,
				insertionPoint
			);
		},
		[ inserterRootClientId ]
	);
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
			return getRegistryVersion( settings.blockPatterns, 'stable' );
		}

		if ( Array.isArray( settings.__experimentalAdditionalBlockPatterns ) ) {
			return getRegistryVersion(
				settings.__experimentalAdditionalBlockPatterns,
				'experimental-additional'
			);
		}

		if ( Array.isArray( settings.__experimentalBlockPatterns ) ) {
			return getRegistryVersion(
				settings.__experimentalBlockPatterns,
				'experimental'
			);
		}

		return 'none:0';
	}, [] );
	const categoryRegistryVersion = useSelect( ( select ) => {
		const settings = select( blockEditorStore ).getSettings?.() || {};

		if ( Array.isArray( settings.blockPatternCategories ) ) {
			return getRegistryVersion(
				settings.blockPatternCategories,
				'stable',
				true
			);
		}

		if (
			Array.isArray(
				settings.__experimentalAdditionalBlockPatternCategories
			)
		) {
			return getRegistryVersion(
				settings.__experimentalAdditionalBlockPatternCategories,
				'experimental-additional',
				true
			);
		}

		if ( Array.isArray( settings.__experimentalBlockPatternCategories ) ) {
			return getRegistryVersion(
				settings.__experimentalBlockPatternCategories,
				'experimental',
				true
			);
		}

		return 'none:0';
	}, [] );
	const { patternStatus, recommendations } = useSelect( ( select ) => {
		const store = select( STORE_NAME );

		return {
			patternStatus: store.getPatternStatus(),
			recommendations: store.getPatternRecommendations(),
		};
	}, [] );
	const { fetchPatternRecommendations } = useDispatch( STORE_NAME );
	const patchStateRef = useRef( createPatchState() );
	const observerRef = useRef( null );
	const listenerRef = useRef( null );
	const debounceRef = useRef( null );
	const noticeObserverRef = useRef( null );
	const noticeSlotRef = useRef( null );

	if ( ! noticeSlotRef.current && typeof document !== 'undefined' ) {
		const noticeSlot = document.createElement( 'div' );
		noticeSlot.className = INSERTER_SLOT_CLASS;
		noticeSlotRef.current = noticeSlot;
	}

	const shouldRenderInserterAffordance =
		isInserterOpen &&
		( ! canRecommend ||
			( patternStatus === 'ready' && recommendations.length > 0 ) );

	const buildBaseInput = useCallback( () => {
		const input = {
			postType,
			visiblePatternNames,
		};

		if ( templateType ) {
			input.templateType = templateType;
		}

		if ( insertionContext ) {
			input.insertionContext = insertionContext;
		}

		return input;
	}, [ postType, templateType, visiblePatternNames, insertionContext ] );

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
		patchInserterPatterns(
			recommendations,
			patternContract.recommendedPatternCategory,
			patchStateRef.current
		);
	}, [
		recommendations,
		patternRegistryVersion,
		categoryRegistryVersion,
		patternContract.recommendedPatternCategory,
	] );

	useEffect( () => {
		const noticeSlot = noticeSlotRef.current;

		if ( ! noticeSlot ) {
			return undefined;
		}

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

		const syncNotice = () => {
			const inserterContainer = findInserterContainer( document );

			if ( ! inserterContainer ) {
				return;
			}

			attachNotice( inserterContainer );
		};

		if ( ! shouldRenderInserterAffordance ) {
			cleanupNotice();
			return undefined;
		}

		syncNotice();

		if ( ! window.MutationObserver ) {
			return cleanupNotice;
		}

		// Gutenberg can replace the inserter container without toggling the
		// open state, so keep the slot attached for the whole affordance lifetime.
		const observer = new window.MutationObserver( () => {
			syncNotice();
		} );

		observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );
		noticeObserverRef.current = observer;

		return () => {
			cleanupNotice();
		};
	}, [ shouldRenderInserterAffordance ] );

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

				if ( insertionContext ) {
					input.insertionContext = insertionContext;
				}

				fetchPatternRecommendations( input );
			}, SEARCH_DEBOUNCE_MS );
		},
		[
			postType,
			buildBaseInput,
			selectedBlockName,
			insertionContext,
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

	if ( shouldRenderInserterAffordance && noticeSlotRef.current ) {
		return createPortal(
			canRecommend ? (
				<PatternSummary count={ recommendations.length } />
			) : (
				<CapabilityNotice surface="pattern" />
			),
			noticeSlotRef.current
		);
	}

	return null;
}
