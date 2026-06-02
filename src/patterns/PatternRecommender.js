/**
 * Pattern Recommender
 *
 * Fetches AI-ranked pattern recommendations and renders them as a
 * Flavor Agent-owned shelf inside the inserter. This surface stays
 * browse-only from Gutenberg's perspective: it does not rewrite the
 * native pattern registry or category metadata.
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
import { cloneBlock } from '@wordpress/blocks';
import { Button } from '@wordpress/components';
import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import {
	createPortal,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

import CapabilityNotice from '../components/CapabilityNotice';
import DocsGroundingNotice from '../components/DocsGroundingNotice';
import { getResolvedContextSignatureFromResponse, STORE_NAME } from '../store';
import { buildPatternInsertionTargetSignature } from '../utils/recommendation-request-signature';
import {
	buildRecommendationSetId,
	getRecommendationOutcomeSummaryFromPayload,
	getSuggestionOutcomeKey,
	normalizeSourceRequestSignature,
} from '../store/recommendation-outcomes';
import { formatCount } from '../utils/format-count';
import {
	getConnectorApprovalNotice,
	getSurfaceCapability,
} from '../utils/capability-flags';
import { normalizeTemplateType } from '../utils/template-types';
import { getVisiblePatternNames } from '../utils/visible-patterns';
import { findInserterContainer, findInserterSearchInput } from './inserter-dom';
import { getAllowedPatterns } from './pattern-settings';
import {
	getNonEmptyString,
	usePatternInsertionContext,
} from './use-pattern-insertion-context';
import {
	filterInsertableRecommendedPatterns,
	getRejectedPatternBlockNames,
	resolvePatternBlocks,
} from './pattern-insertability';
import {
	buildRecommendedPatterns,
	getPatternRecommendationInsights,
} from './recommendation-utils';

const SEARCH_DEBOUNCE_MS = 400;
const INSERTER_SLOT_CLASS = 'flavor-agent-pattern-inserter-slot';
const EMPTY_BLOCK_TREE = [];
const EMPTY_SIBLING_ORDER = [];

function PatternInserterPortal( { children, onAttached } ) {
	const noticeObserverRef = useRef( null );
	const noticeResyncTimerRef = useRef( null );
	const noticeSlotRef = useRef( null );
	const [ noticeSlotReady, setNoticeSlotReady ] = useState( false );

	useEffect( () => {
		if ( noticeSlotRef.current || typeof document === 'undefined' ) {
			return undefined;
		}

		const noticeSlot = document.createElement( 'div' );
		noticeSlot.className = INSERTER_SLOT_CLASS;
		noticeSlotRef.current = noticeSlot;
		setNoticeSlotReady( true );

		return () => {
			if ( noticeResyncTimerRef.current ) {
				window.clearTimeout( noticeResyncTimerRef.current );
				noticeResyncTimerRef.current = null;
			}

			if ( noticeObserverRef.current ) {
				noticeObserverRef.current.disconnect();
				noticeObserverRef.current = null;
			}

			if ( noticeSlot.parentNode ) {
				noticeSlot.parentNode.removeChild( noticeSlot );
			}

			if ( noticeSlotRef.current === noticeSlot ) {
				noticeSlotRef.current = null;
			}
		};
	}, [] );

	useEffect( () => {
		const noticeSlot = noticeSlotRef.current;

		if ( ! noticeSlot ) {
			return undefined;
		}

		const cleanupNotice = () => {
			if ( noticeResyncTimerRef.current ) {
				window.clearTimeout( noticeResyncTimerRef.current );
				noticeResyncTimerRef.current = null;
			}

			if ( noticeObserverRef.current ) {
				noticeObserverRef.current.disconnect();
				noticeObserverRef.current = null;
			}

			if ( noticeSlot.parentNode ) {
				noticeSlot.parentNode.removeChild( noticeSlot );
			}
		};

		const attachNotice = ( inserterContainer ) => {
			if ( ! inserterContainer ) {
				return;
			}

			if ( noticeSlot.parentNode === inserterContainer ) {
				onAttached();
				return;
			}

			if ( noticeSlot.parentNode ) {
				noticeSlot.parentNode.removeChild( noticeSlot );
			}

			inserterContainer.insertBefore(
				noticeSlot,
				inserterContainer.firstChild
			);
			onAttached();
		};

		const syncNotice = () => {
			const inserterContainer = findInserterContainer( document );

			if ( ! inserterContainer ) {
				return;
			}

			attachNotice( inserterContainer );
		};

		const scheduleNoticeSync = () => {
			if ( noticeResyncTimerRef.current ) {
				return;
			}

			noticeResyncTimerRef.current = window.setTimeout( () => {
				noticeResyncTimerRef.current = null;
				syncNotice();
			}, 50 );
		};

		syncNotice();

		if ( ! window.MutationObserver ) {
			return cleanupNotice;
		}

		// Gutenberg can replace the inserter container without toggling the
		// open state, so keep the slot attached for the whole affordance lifetime.
		const observer = new window.MutationObserver( () => {
			scheduleNoticeSync();
		} );

		observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );
		noticeObserverRef.current = observer;

		return () => {
			cleanupNotice();
		};
		// `onAttached` is a load-bearing dependency, not merely a referenced value.
		// Its identity changes when the shelf becomes visible (recordShownPatternOutcome
		// depends on shouldShowPatternShelf), and that re-run is what re-attaches the slot
		// and records the "shown" outcome for recommendations that hydrate AFTER the
		// inserter has already opened. Dropping it silently loses that telemetry.
	}, [ onAttached ] );

	if ( ! noticeSlotReady || ! noticeSlotRef.current ) {
		return null;
	}

	return createPortal( children, noticeSlotRef.current );
}

function getPatternTitle( pattern ) {
	return getNonEmptyString( pattern?.title ) || pattern?.name || 'Pattern';
}

function getBlockListSnapshot( blockEditor, rootClientId ) {
	if ( typeof blockEditor?.getBlocks !== 'function' ) {
		return null;
	}

	const blocks = blockEditor.getBlocks( rootClientId ?? null );
	if ( ! Array.isArray( blocks ) ) {
		return null;
	}

	return blocks.map( ( block ) => ( {
		clientId: getNonEmptyString( block?.clientId ),
		name: getNonEmptyString( block?.name ),
	} ) );
}

function countBlockNames( blocks ) {
	return blocks.reduce( ( counts, block ) => {
		if ( block.name ) {
			counts.set( block.name, ( counts.get( block.name ) || 0 ) + 1 );
		}

		return counts;
	}, new Map() );
}

function didInsertBlocksAtTarget(
	beforeSnapshot,
	afterSnapshot,
	insertedBlocks,
	insertionIndex
) {
	if (
		! Array.isArray( beforeSnapshot ) ||
		! Array.isArray( afterSnapshot ) ||
		! Array.isArray( insertedBlocks ) ||
		insertedBlocks.length === 0
	) {
		return false;
	}

	if (
		afterSnapshot.length <
		beforeSnapshot.length + insertedBlocks.length
	) {
		return false;
	}

	const expectedClientIds = insertedBlocks.map( ( block ) =>
		getNonEmptyString( block?.clientId )
	);
	const expectedNames = insertedBlocks.map( ( block ) =>
		getNonEmptyString( block?.name )
	);
	const hasExplicitInsertionIndex =
		Number.isInteger( insertionIndex ) && insertionIndex >= 0;

	const boundedIndex = hasExplicitInsertionIndex
		? Math.min(
				insertionIndex,
				Math.max( 0, afterSnapshot.length - insertedBlocks.length )
		  )
		: Math.max( 0, afterSnapshot.length - insertedBlocks.length );
	const insertedWindow = afterSnapshot.slice(
		boundedIndex,
		boundedIndex + insertedBlocks.length
	);

	if (
		insertedWindow.length === insertedBlocks.length &&
		insertedWindow.every( ( block, index ) => {
			if ( expectedClientIds[ index ] ) {
				return block.clientId === expectedClientIds[ index ];
			}

			return block.name && block.name === expectedNames[ index ];
		} )
	) {
		return true;
	}

	if ( hasExplicitInsertionIndex ) {
		return false;
	}

	const allExpectedClientIdsPresent = expectedClientIds.every( Boolean );
	const afterClientIds = new Set(
		afterSnapshot.map( ( block ) => block.clientId ).filter( Boolean )
	);

	if (
		allExpectedClientIdsPresent &&
		expectedClientIds.every( ( clientId ) =>
			afterClientIds.has( clientId )
		)
	) {
		return true;
	}

	const beforeCounts = countBlockNames( beforeSnapshot );
	const afterCounts = countBlockNames( afterSnapshot );
	const expectedCounts = countBlockNames(
		expectedNames.map( ( name ) => ( { name } ) )
	);

	for ( const [ name, count ] of expectedCounts.entries() ) {
		if (
			( afterCounts.get( name ) || 0 ) -
				( beforeCounts.get( name ) || 0 ) <
			count
		) {
			return false;
		}
	}

	return expectedCounts.size > 0;
}

function getExpectedInsertedBlockClientIds( insertedBlocks ) {
	return insertedBlocks
		.map( ( block ) => getNonEmptyString( block?.clientId ) )
		.filter( Boolean );
}

function getBlockPresenceSnapshot( blockEditor, insertedBlocks ) {
	if (
		typeof blockEditor?.getBlock !== 'function' ||
		! Array.isArray( insertedBlocks )
	) {
		return null;
	}

	return new Map(
		getExpectedInsertedBlockClientIds( insertedBlocks ).map(
			( clientId ) => [
				clientId,
				Boolean( blockEditor.getBlock( clientId ) ),
			]
		)
	);
}

function getInsertedBlockClientIds(
	beforePresence,
	afterPresence,
	beforeSnapshot,
	afterSnapshot,
	insertedBlocks
) {
	if ( ! Array.isArray( insertedBlocks ) ) {
		return [];
	}

	const expectedClientIds =
		getExpectedInsertedBlockClientIds( insertedBlocks );

	if ( beforePresence instanceof Map && afterPresence instanceof Map ) {
		return expectedClientIds.filter(
			( clientId ) =>
				beforePresence.get( clientId ) !== true &&
				afterPresence.get( clientId ) === true
		);
	}

	if (
		! Array.isArray( beforeSnapshot ) ||
		! Array.isArray( afterSnapshot )
	) {
		return [];
	}

	const beforeClientIds = new Set(
		beforeSnapshot.map( ( block ) => block.clientId ).filter( Boolean )
	);
	const afterClientIds = new Set(
		afterSnapshot.map( ( block ) => block.clientId ).filter( Boolean )
	);

	return expectedClientIds.filter(
		( clientId ) =>
			! beforeClientIds.has( clientId ) && afterClientIds.has( clientId )
	);
}

function consumeE2EPatternInsertFailureMode( pattern ) {
	if (
		typeof window === 'undefined' ||
		! window.flavorAgentData?.e2ePatternInsertFailureHarness ||
		! pattern?.name
	) {
		return '';
	}

	const failures = window.__flavorAgentPatternInsertFailures || {};
	const failureMode = failures[ pattern.name ];

	if (
		failureMode !== 'insert_blocks_exception' &&
		failureMode !== 'insert_blocks_noop' &&
		failureMode !== 'insert_blocks_wrong_target'
	) {
		return '';
	}

	delete failures[ pattern.name ];
	window.__flavorAgentPatternInsertFailures = failures;

	return failureMode;
}

function getPatternInsertabilityDropReason(
	pattern,
	rootClientId,
	blockEditor
) {
	const blocks = resolvePatternBlocks( pattern );

	if ( blocks.length === 0 ) {
		return 'empty_pattern_blocks';
	}

	return getRejectedPatternBlockNames( pattern, rootClientId, blockEditor )
		.length > 0
		? 'disallowed_block_types'
		: '';
}

function getDroppedRecommendedPatterns(
	recommendations,
	builtRecommendedPatterns,
	rootClientId,
	blockEditor
) {
	if ( ! Array.isArray( recommendations ) ) {
		return [];
	}

	const matchedByName = new Map(
		builtRecommendedPatterns
			.filter( ( item ) =>
				getNonEmptyString( item?.recommendation?.name )
			)
			.map( ( item ) => [ item.recommendation.name, item ] )
	);
	const drops = [];

	recommendations.forEach( ( recommendation ) => {
		const name = getNonEmptyString( recommendation?.name );

		if ( ! name ) {
			return;
		}

		if ( ! matchedByName.has( name ) ) {
			drops.push( {
				pattern: null,
				recommendation,
				reason: 'not_visible_in_inserter',
			} );
		}
	} );

	builtRecommendedPatterns.forEach( ( item ) => {
		const reason = getPatternInsertabilityDropReason(
			item?.pattern,
			rootClientId,
			blockEditor
		);

		if ( reason ) {
			drops.push( {
				pattern: item.pattern,
				recommendation: item.recommendation,
				reason,
			} );
		}
	} );

	return drops;
}

function getUnreadableSyncedPatternCount( diagnostics ) {
	const count = Number(
		diagnostics?.filteredCandidates?.unreadableSyncedPatterns ?? 0
	);

	return Number.isFinite( count ) ? Math.max( 0, count ) : 0;
}

function getUnreadableSyncedPatternMessage( diagnostics ) {
	const count = getUnreadableSyncedPatternCount( diagnostics );

	if ( count <= 0 ) {
		return '';
	}

	return `${ formatCount( count, 'synced pattern' ) } ${
		count === 1 ? 'was' : 'were'
	} skipped because current WordPress permissions do not allow read access.`;
}

function PatternFilteredCandidateNotice( { diagnostics } ) {
	const message = getUnreadableSyncedPatternMessage( diagnostics );

	if ( ! message ) {
		return null;
	}

	return (
		<p className="flavor-agent-pattern-summary__filtered-note">
			{ message }
		</p>
	);
}

function getPatternEmptyMessage(
	recommendations,
	diagnostics,
	{ matchedRecommendationCount = 0, insertableRecommendationCount = 0 } = {}
) {
	const unreadableMessage = getUnreadableSyncedPatternMessage( diagnostics );

	if ( unreadableMessage ) {
		return unreadableMessage;
	}

	if (
		matchedRecommendationCount > 0 &&
		insertableRecommendationCount === 0
	) {
		return 'Flavor Agent found ranked patterns, but the matched pattern blocks are not allowed at this insertion point.';
	}

	return Array.isArray( recommendations ) && recommendations.length > 0
		? 'Flavor Agent found ranked patterns, but Gutenberg is not currently exposing those patterns for this insertion point.'
		: '';
}

function PatternShelf( { items, onInsert, diagnostics } ) {
	return (
		<div className="flavor-agent-pattern-summary flavor-agent-pattern-shelf">
			<div
				className="screen-reader-text flavor-agent-pattern-shelf__status"
				role="status"
				aria-live="polite"
			>
				{ `Flavor Agent. ${ formatCount(
					items.length,
					'recommendation'
				) }.` }
			</div>
			<div className="flavor-agent-pattern-summary__header">
				<span className="flavor-agent-pill flavor-agent-pill--lane">
					Flavor Agent
				</span>
				<span className="flavor-agent-pill">
					{ formatCount( items.length, 'recommendation' ) }
				</span>
			</div>
			<PatternFilteredCandidateNotice diagnostics={ diagnostics } />
			<div className="flavor-agent-pattern-shelf__items">
				{ items.map( ( { pattern, recommendation } ) => {
					const patternTitle = getPatternTitle( pattern );
					const insights = getPatternRecommendationInsights(
						pattern,
						recommendation
					);

					return (
						<div
							key={ pattern.name }
							className="flavor-agent-pattern-shelf__item"
						>
							<div className="flavor-agent-pattern-shelf__body">
								<div className="flavor-agent-pattern-shelf__title">
									{ patternTitle }
								</div>
								{ recommendation?.reason && (
									<p className="flavor-agent-pattern-shelf__reason">
										{ recommendation.reason }
									</p>
								) }
								{ insights.length > 0 && (
									<ul className="flavor-agent-pattern-shelf__insights">
										{ insights.map( ( insight ) => (
											<li
												key={ insight }
												className="flavor-agent-pattern-shelf__insight"
											>
												{ insight }
											</li>
										) ) }
									</ul>
								) }
							</div>
							<Button
								variant="secondary"
								size="small"
								onClick={ () =>
									onInsert( pattern, recommendation )
								}
								className="flavor-agent-card__apply"
								aria-label={ sprintf(
									/* translators: %s: block pattern title. */
									__( 'Insert %s', 'flavor-agent' ),
									patternTitle
								) }
							>
								{ __( 'Insert', 'flavor-agent' ) }
							</Button>
						</div>
					);
				} ) }
			</div>
		</div>
	);
}

function PatternInserterNotice( {
	status,
	error = '',
	message = '',
	onRetry,
} ) {
	let resolvedMessage =
		message ||
		__(
			'Preparing pattern recommendations for this insertion point.',
			'flavor-agent'
		);

	if ( status === 'loading' ) {
		resolvedMessage = __(
			'Ranking patterns for this insertion point.',
			'flavor-agent'
		);
	} else if ( status === 'error' ) {
		resolvedMessage =
			error ||
			__(
				'Pattern recommendation request failed for this insertion point.',
				'flavor-agent'
			);
	} else if ( status === 'empty' && ! message ) {
		resolvedMessage = __(
			'Flavor Agent did not find a strong pattern match for this insertion point yet.',
			'flavor-agent'
		);
	}

	return (
		<div className="flavor-agent-pattern-summary">
			<div className="flavor-agent-pattern-summary__header">
				<span className="flavor-agent-pill flavor-agent-pill--lane">
					Flavor Agent
				</span>
				{ status === 'empty' && (
					<span className="flavor-agent-pill">
						{ __( 'No matches yet', 'flavor-agent' ) }
					</span>
				) }
				{ status === 'error' && (
					<span className="flavor-agent-pill flavor-agent-pill--stale">
						{ __( 'Ranking failed', 'flavor-agent' ) }
					</span>
				) }
				{ status === 'loading' && (
					<span className="flavor-agent-pill">
						{ __( 'Ranking…', 'flavor-agent' ) }
					</span>
				) }
			</div>
			<p
				className="flavor-agent-pattern-summary__copy"
				role="status"
				aria-live="polite"
			>
				{ resolvedMessage }
			</p>
			{ status === 'error' && typeof onRetry === 'function' && (
				<Button
					variant="link"
					onClick={ onRetry }
					className="flavor-agent-pattern-summary__retry"
				>
					{ __( 'Retry', 'flavor-agent' ) }
				</Button>
			) }
		</div>
	);
}

export default function PatternRecommender() {
	const registry = useRegistry();
	const canRecommend = getSurfaceCapability( 'pattern' ).available;
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);
	const siteEditorPostType = useSelect( ( select ) => {
		const editSite = select( 'core/edit-site' );

		return getNonEmptyString( editSite?.getEditedPostType?.() );
	}, [] );
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
	const { hasInsertionPoint, inserterRootClientId, insertionIndex } =
		useSelect( ( select ) => {
			const point =
				select( blockEditorStore ).getBlockInsertionPoint?.() || null;

			return {
				hasInsertionPoint: Boolean( point ),
				inserterRootClientId: point?.rootClientId ?? null,
				insertionIndex: point?.index,
			};
		}, [] );
	const insertionBlockEditor = useMemo(
		() => registry.select( blockEditorStore ),
		[ registry ]
	);
	const insertionBlockTree = useSelect(
		( select ) =>
			select( blockEditorStore ).getBlocks?.() || EMPTY_BLOCK_TREE,
		[]
	);
	const insertionSiblingOrder = useSelect(
		( select ) =>
			select( blockEditorStore ).getBlockOrder?.(
				inserterRootClientId
			) || EMPTY_SIBLING_ORDER,
		[ inserterRootClientId ]
	);
	const insertionContext = usePatternInsertionContext( {
		enabled: hasInsertionPoint,
		editor: insertionBlockEditor,
		inserterRootClientId,
		insertionIndex,
		blockTree: insertionBlockTree,
		siblingOrder: insertionSiblingOrder,
	} );
	const visiblePatternNames = useSelect(
		( select ) => {
			return getVisiblePatternNames(
				inserterRootClientId,
				select( blockEditorStore )
			);
		},
		[ inserterRootClientId ]
	);
	const allowedPatterns = useSelect(
		( select ) => {
			return getAllowedPatterns(
				inserterRootClientId,
				select( blockEditorStore )
			);
		},
		[ inserterRootClientId ]
	);
	const {
		patternError,
		patternErrorDetails,
		patternStatus,
		recommendations,
		patternDiagnostics,
		patternRequestSignature,
		patternInsertionTargetSignature,
		patternResolvedContextSignature,
		patternDocsGroundingWarning,
	} = useSelect( ( select ) => {
		const store = select( STORE_NAME );

		return {
			patternError: store.getPatternError?.() || '',
			patternErrorDetails: store.getPatternErrorDetails?.() || null,
			patternStatus: store.getPatternStatus(),
			recommendations: store.getPatternRecommendations(),
			patternDiagnostics: store.getPatternDiagnostics?.() || null,
			patternRequestSignature: store.getPatternRequestSignature?.() || '',
			patternInsertionTargetSignature:
				store.getPatternInsertionTargetSignature?.() || '',
			patternResolvedContextSignature:
				store.getPatternResolvedContextSignature?.() || '',
			patternDocsGroundingWarning:
				store.getPatternDocsGroundingWarning?.() || null,
		};
	}, [] );
	const {
		fetchPatternRecommendations,
		recordRecommendationOutcome,
		resolvePatternRecommendationSignature,
	} = useDispatch( STORE_NAME );
	const { insertBlocks, removeBlocks } = useDispatch( blockEditorStore );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );
	const effectivePostType =
		getNonEmptyString( postType ) ||
		( siteEditorPostType === 'wp_template' ? siteEditorPostType : '' );
	const observerRef = useRef( null );
	const listenerRef = useRef( null );
	const debounceRef = useRef( null );
	const shownRecommendationSetRef = useRef( '' );
	const droppedRecommendationOutcomeRef = useRef( new Set() );

	const shouldRenderInserterAffordance =
		isInserterOpen &&
		( ! canRecommend ||
			patternStatus === 'loading' ||
			patternStatus === 'error' ||
			patternStatus === 'ready' ||
			patternStatus === 'idle' );
	const builtRecommendedPatterns = useMemo(
		() => buildRecommendedPatterns( recommendations, allowedPatterns ),
		[ allowedPatterns, recommendations ]
	);
	const recommendedPatterns = useSelect(
		( select ) => {
			const blockEditor = select( blockEditorStore );

			return filterInsertableRecommendedPatterns(
				builtRecommendedPatterns,
				inserterRootClientId,
				blockEditor
			);
		},
		[ builtRecommendedPatterns, inserterRootClientId ]
	);
	const droppedRecommendedPatterns = useSelect(
		( select ) => {
			const blockEditor = select( blockEditorStore );

			return getDroppedRecommendedPatterns(
				recommendations,
				builtRecommendedPatterns,
				inserterRootClientId,
				blockEditor
			);
		},
		[ recommendations, builtRecommendedPatterns, inserterRootClientId ]
	);
	const connectorApprovalNotice = useMemo(
		() => getConnectorApprovalNotice( 'pattern', patternErrorDetails ),
		[ patternErrorDetails ]
	);
	const shouldShowPatternShelf =
		shouldRenderInserterAffordance &&
		canRecommend &&
		! connectorApprovalNotice &&
		patternStatus === 'ready' &&
		recommendedPatterns.length > 0;

	const buildBaseInput = useCallback( () => {
		const input = {
			postType: effectivePostType,
			visiblePatternNames,
		};

		if ( templateType ) {
			input.templateType = templateType;
		}

		if ( insertionContext ) {
			input.insertionContext = insertionContext;
		}

		return input;
	}, [
		effectivePostType,
		templateType,
		visiblePatternNames,
		insertionContext,
	] );

	const currentInsertionTargetSignature = useMemo(
		() =>
			buildPatternInsertionTargetSignature( {
				postType: effectivePostType,
				templateType,
				inserterRootClientId,
				insertionIndex,
				insertionContext,
			} ),
		[
			effectivePostType,
			templateType,
			inserterRootClientId,
			insertionIndex,
			insertionContext,
		]
	);
	const patternSourceRequestSignature = useMemo(
		() =>
			normalizeSourceRequestSignature(
				patternRequestSignature ||
					patternInsertionTargetSignature ||
					currentInsertionTargetSignature
			),
		[
			currentInsertionTargetSignature,
			patternInsertionTargetSignature,
			patternRequestSignature,
		]
	);
	const patternRecommendationSetId = useMemo(
		() =>
			buildRecommendationSetId( {
				surface: 'pattern',
				requestToken:
					patternRequestSignature ||
					patternInsertionTargetSignature ||
					currentInsertionTargetSignature,
				sourceRequestSignature: patternSourceRequestSignature,
				resultRef: currentInsertionTargetSignature,
			} ),
		[
			currentInsertionTargetSignature,
			patternInsertionTargetSignature,
			patternRequestSignature,
			patternSourceRequestSignature,
		]
	);
	const patternOutcomeSummary = useMemo(
		() =>
			getRecommendationOutcomeSummaryFromPayload( {
				recommendationOutcome: {
					recommendationSetId: patternRecommendationSetId,
					sourceRequestSignature: patternSourceRequestSignature,
				},
				recommendations: recommendedPatterns.map(
					( { recommendation } ) => recommendation
				),
			} ),
		[
			patternRecommendationSetId,
			patternSourceRequestSignature,
			recommendedPatterns,
		]
	);

	const fetchPatternRecommendationsForCurrentTarget = useCallback(
		( input = buildBaseInput() ) =>
			fetchPatternRecommendations( input, {
				insertionTargetSignature: currentInsertionTargetSignature,
			} ),
		[
			buildBaseInput,
			currentInsertionTargetSignature,
			fetchPatternRecommendations,
		]
	);

	const clearSearchDebounce = useCallback( () => {
		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
			debounceRef.current = null;
		}
	}, [] );

	const scheduleSearchFetch = useCallback(
		( callback ) => {
			clearSearchDebounce();
			debounceRef.current = setTimeout( () => {
				debounceRef.current = null;
				callback();
			}, SEARCH_DEBOUNCE_MS );
		},
		[ clearSearchDebounce ]
	);

	const handleRetry = useCallback( () => {
		if ( ! canRecommend || ! effectivePostType ) {
			return;
		}

		fetchPatternRecommendationsForCurrentTarget();
	}, [
		canRecommend,
		effectivePostType,
		fetchPatternRecommendationsForCurrentTarget,
	] );

	const buildPatternOutcomePayload = useCallback(
		(
			event,
			{ pattern = null, recommendation = null, reason = '' } = {}
		) => {
			const suggestionKey = getSuggestionOutcomeKey(
				recommendation || pattern || {},
				pattern?.name || ''
			);
			const rank =
				recommendedPatterns.findIndex(
					( item ) => item?.pattern?.name === pattern?.name
				) + 1;
			const topSuggestionKeys =
				Array.isArray( patternOutcomeSummary?.topSuggestionKeys ) &&
				patternOutcomeSummary.topSuggestionKeys.length > 0
					? patternOutcomeSummary.topSuggestionKeys
					: recommendedPatterns
							.slice( 0, 3 )
							.map( ( item, index ) =>
								getSuggestionOutcomeKey(
									item?.recommendation || item?.pattern || {},
									item?.pattern?.name ||
										`pattern:${ index + 1 }`
								)
							);

			return {
				event,
				surface: 'pattern',
				recommendationSetId: patternRecommendationSetId,
				suggestionKey,
				sourceRequestSignature: patternSourceRequestSignature,
				reason,
				patternKey: pattern?.name || suggestionKey,
				rank: rank > 0 ? rank : null,
				resultCount:
					patternOutcomeSummary?.resultCount ??
					recommendedPatterns.length,
				topSuggestionKeys,
				...( event === 'shown' &&
				Array.isArray( patternOutcomeSummary?.rankingSet ) &&
				patternOutcomeSummary.rankingSet.length > 0
					? { rankingSet: patternOutcomeSummary.rankingSet }
					: {} ),
				...( event !== 'shown' && recommendation
					? { suggestion: recommendation }
					: {} ),
				target: {
					patternKey: pattern?.name || suggestionKey,
					rank: rank > 0 ? rank : null,
					blockName: insertionContext?.rootBlock || '',
				},
			};
		},
		[
			insertionContext,
			patternOutcomeSummary,
			patternRecommendationSetId,
			patternSourceRequestSignature,
			recommendedPatterns,
		]
	);

	const recordPatternOutcome = useCallback(
		( event, options = {} ) => {
			if ( typeof recordRecommendationOutcome !== 'function' ) {
				return;
			}

			recordRecommendationOutcome(
				buildPatternOutcomePayload( event, options )
			);
		},
		[ buildPatternOutcomePayload, recordRecommendationOutcome ]
	);

	useEffect( () => {
		if (
			! canRecommend ||
			patternStatus !== 'ready' ||
			! Array.isArray( droppedRecommendedPatterns ) ||
			droppedRecommendedPatterns.length === 0
		) {
			return;
		}

		droppedRecommendedPatterns.forEach(
			( { pattern = null, recommendation = null, reason = '' } ) => {
				const suggestionKey = getSuggestionOutcomeKey(
					recommendation || pattern || {},
					pattern?.name || ''
				);
				const dedupeKey = [
					patternRecommendationSetId,
					suggestionKey,
					reason,
				].join( ':' );

				if (
					droppedRecommendationOutcomeRef.current.has( dedupeKey )
				) {
					return;
				}

				droppedRecommendationOutcomeRef.current.add( dedupeKey );
				recordPatternOutcome( 'validation_blocked', {
					pattern,
					recommendation,
					reason,
				} );
			}
		);
	}, [
		canRecommend,
		droppedRecommendedPatterns,
		patternRecommendationSetId,
		patternStatus,
		recordPatternOutcome,
	] );

	const handleInsertPattern = useCallback(
		async ( pattern, recommendation = null ) => {
			const blocks = resolvePatternBlocks( pattern );

			if ( blocks.length === 0 ) {
				recordPatternOutcome( 'validation_blocked', {
					pattern,
					recommendation,
					reason: 'empty_pattern_blocks',
				} );
				createErrorNotice(
					sprintf(
						/* translators: %s: block pattern title. */
						__(
							'Cannot insert pattern "%s" because Gutenberg did not provide insertable block content for it.',
							'flavor-agent'
						),
						getPatternTitle( pattern )
					),
					{
						type: 'snackbar',
						id: 'inserter-notice',
					}
				);
				return;
			}

			const liveInput = buildBaseInput();

			// Freshness guard: the inserter root/index can move after the
			// recommendation was ranked. Compare only the insertion-target
			// signature captured at fetch time so ranking inputs such as the
			// visible pattern set do not cause false stale-target rejections.
			if (
				patternInsertionTargetSignature &&
				currentInsertionTargetSignature &&
				effectivePostType
			) {
				if (
					currentInsertionTargetSignature !==
					patternInsertionTargetSignature
				) {
					recordPatternOutcome( 'stale_blocked', {
						pattern,
						recommendation,
						reason: 'insertion_target_changed',
					} );
					createErrorNotice(
						sprintf(
							/* translators: %s: block pattern title. */
							__(
								'Cannot insert pattern "%s" because the insertion point has changed since these recommendations were ranked. Refreshing now — try again in a moment.',
								'flavor-agent'
							),
							getPatternTitle( pattern )
						),
						{
							type: 'snackbar',
							id: 'inserter-notice',
						}
					);
					fetchPatternRecommendationsForCurrentTarget( liveInput );
					return;
				}
			}

			const blockEditor = registry?.select?.( blockEditorStore );
			const rejected = getRejectedPatternBlockNames(
				pattern,
				inserterRootClientId,
				blockEditor
			);

			if ( rejected.length > 0 ) {
				recordPatternOutcome( 'validation_blocked', {
					pattern,
					recommendation,
					reason: 'disallowed_block_types',
				} );
				createErrorNotice(
					sprintf(
						/* translators: 1: pattern title 2: comma-separated block names. */
						__(
							'Cannot insert pattern "%1$s" here. The following blocks are not allowed at this insertion point: %2$s.',
							'flavor-agent'
						),
						getPatternTitle( pattern ),
						rejected.join( ', ' )
					),
					{
						type: 'snackbar',
						id: 'inserter-notice',
					}
				);
				return;
			}

			if (
				! patternResolvedContextSignature ||
				typeof resolvePatternRecommendationSignature !== 'function'
			) {
				recordPatternOutcome( 'stale_blocked', {
					pattern,
					recommendation,
					reason: 'missing_resolved_context',
				} );
				createErrorNotice(
					sprintf(
						/* translators: %s: block pattern title. */
						__(
							'Cannot insert pattern "%s" because Flavor Agent could not verify the current server apply context. Refreshing now — try again in a moment.',
							'flavor-agent'
						),
						getPatternTitle( pattern )
					),
					{
						type: 'snackbar',
						id: 'inserter-notice',
					}
				);
				fetchPatternRecommendationsForCurrentTarget( liveInput );
				return;
			}

			try {
				const resolved =
					await resolvePatternRecommendationSignature( liveInput );
				const currentResolvedContextSignature =
					getResolvedContextSignatureFromResponse( resolved );

				if (
					! currentResolvedContextSignature ||
					currentResolvedContextSignature !==
						patternResolvedContextSignature
				) {
					recordPatternOutcome( 'stale_blocked', {
						pattern,
						recommendation,
						reason: 'resolved_context_changed',
					} );
					createErrorNotice(
						sprintf(
							/* translators: %s: block pattern title. */
							__(
								'Cannot insert pattern "%s" because the server-resolved apply context has changed since these recommendations were ranked. Refreshing now — try again in a moment.',
								'flavor-agent'
							),
							getPatternTitle( pattern )
						),
						{
							type: 'snackbar',
							id: 'inserter-notice',
						}
					);
					fetchPatternRecommendationsForCurrentTarget( liveInput );
					return;
				}
			} catch {
				recordPatternOutcome( 'stale_blocked', {
					pattern,
					recommendation,
					reason: 'revalidation_failed',
				} );
				createErrorNotice(
					sprintf(
						/* translators: %s: block pattern title. */
						__(
							'Cannot insert pattern "%s" because Flavor Agent could not revalidate the current server apply context. Try again or refresh recommendations.',
							'flavor-agent'
						),
						getPatternTitle( pattern )
					),
					{
						type: 'snackbar',
						id: 'inserter-notice',
					}
				);
				return;
			}

			let insertionVerified = false;
			let insertedClientIds = [];
			const e2eFailureMode =
				consumeE2EPatternInsertFailureMode( pattern );

			try {
				if ( e2eFailureMode === 'insert_blocks_exception' ) {
					throw new Error( 'E2E forced insertBlocks exception' );
				}

				const clonedBlocks = blocks.map( ( block ) =>
					cloneBlock( block )
				);
				const beforeBlockEditor =
					registry?.select?.( blockEditorStore );
				const beforeInsertSnapshot = getBlockListSnapshot(
					beforeBlockEditor,
					inserterRootClientId
				);
				const beforeBlockPresence = getBlockPresenceSnapshot(
					beforeBlockEditor,
					clonedBlocks
				);

				if ( e2eFailureMode !== 'insert_blocks_noop' ) {
					let dispatchInsertionIndex = insertionIndex;

					if ( e2eFailureMode === 'insert_blocks_wrong_target' ) {
						dispatchInsertionIndex =
							insertionIndex === 0 ? Number.MAX_SAFE_INTEGER : 0;
					}

					await insertBlocks(
						clonedBlocks,
						dispatchInsertionIndex,
						inserterRootClientId,
						true
					);

					const afterBlockEditor =
						registry?.select?.( blockEditorStore );
					const afterInsertSnapshot = getBlockListSnapshot(
						afterBlockEditor,
						inserterRootClientId
					);
					const afterBlockPresence = getBlockPresenceSnapshot(
						afterBlockEditor,
						clonedBlocks
					);

					insertionVerified = didInsertBlocksAtTarget(
						beforeInsertSnapshot,
						afterInsertSnapshot,
						clonedBlocks,
						insertionIndex
					);
					if ( ! insertionVerified ) {
						insertedClientIds = getInsertedBlockClientIds(
							beforeBlockPresence,
							afterBlockPresence,
							beforeInsertSnapshot,
							afterInsertSnapshot,
							clonedBlocks
						);
					}
				}
			} catch {
				recordPatternOutcome( 'insert_failed', {
					pattern,
					recommendation,
					reason: 'insert_blocks_exception',
				} );
				createErrorNotice(
					sprintf(
						/* translators: %s: block pattern title. */
						__(
							'Cannot insert pattern "%s" because Gutenberg rejected the insertion request.',
							'flavor-agent'
						),
						getPatternTitle( pattern )
					),
					{
						type: 'snackbar',
						id: 'inserter-notice',
					}
				);
				return;
			}

			if ( ! insertionVerified ) {
				const insertedOutsideTarget = insertedClientIds.length > 0;
				if (
					insertedOutsideTarget &&
					typeof removeBlocks === 'function'
				) {
					removeBlocks( insertedClientIds, false );
				}
				const failureMessage = insertedOutsideTarget
					? sprintf(
							/* translators: %s: block pattern title. */
							__(
								'Cannot insert pattern "%s" at the requested location. Gutenberg inserted it somewhere else, so Flavor Agent removed those blocks.',
								'flavor-agent'
							),
							getPatternTitle( pattern )
					  )
					: sprintf(
							/* translators: %s: block pattern title. */
							__(
								'Cannot confirm pattern "%s" was inserted. Gutenberg did not report the inserted blocks at the target location.',
								'flavor-agent'
							),
							getPatternTitle( pattern )
					  );

				recordPatternOutcome( 'insert_failed', {
					pattern,
					recommendation,
					reason: insertedOutsideTarget
						? 'insert_blocks_wrong_target'
						: 'insert_blocks_noop',
				} );
				createErrorNotice( failureMessage, {
					type: 'snackbar',
					id: 'inserter-notice',
				} );
				return;
			}

			recordPatternOutcome( 'pattern_inserted_from_shelf', {
				pattern,
				recommendation,
				reason: 'insert_blocks_success',
			} );
			createSuccessNotice(
				sprintf(
					/* translators: %s: block pattern title. */
					__( 'Block pattern "%s" inserted.', 'flavor-agent' ),
					getPatternTitle( pattern )
				),
				{
					type: 'snackbar',
					id: 'inserter-notice',
				}
			);
		},
		[
			buildBaseInput,
			createErrorNotice,
			createSuccessNotice,
			effectivePostType,
			fetchPatternRecommendationsForCurrentTarget,
			insertBlocks,
			removeBlocks,
			insertionIndex,
			inserterRootClientId,
			currentInsertionTargetSignature,
			patternInsertionTargetSignature,
			patternResolvedContextSignature,
			recordPatternOutcome,
			registry,
			resolvePatternRecommendationSignature,
		]
	);

	const recordShownPatternOutcome = useCallback( () => {
		if ( ! shouldShowPatternShelf ) {
			return;
		}

		if (
			shownRecommendationSetRef.current === patternRecommendationSetId
		) {
			return;
		}

		shownRecommendationSetRef.current = patternRecommendationSetId;
		recordPatternOutcome( 'shown', {
			reason: 'recommendation_set_visible',
		} );
	}, [
		patternRecommendationSetId,
		shouldShowPatternShelf,
		recordPatternOutcome,
	] );

	useEffect( () => {
		if ( ! canRecommend || ! effectivePostType ) {
			return;
		}

		fetchPatternRecommendationsForCurrentTarget();
	}, [
		canRecommend,
		effectivePostType,
		fetchPatternRecommendationsForCurrentTarget,
	] );

	const handleSearchInput = useCallback(
		( value ) => {
			scheduleSearchFetch( () => {
				if ( ! effectivePostType ) {
					return;
				}

				// buildBaseInput() already carries the latest insertionContext.
				const input = buildBaseInput();
				const trimmedValue = value.trim();

				if ( trimmedValue ) {
					input.prompt = trimmedValue;
				}

				if ( selectedBlockName ) {
					input.blockContext = { blockName: selectedBlockName };
				}

				fetchPatternRecommendationsForCurrentTarget( input );
			} );
		},
		[
			effectivePostType,
			buildBaseInput,
			selectedBlockName,
			fetchPatternRecommendationsForCurrentTarget,
			scheduleSearchFetch,
		]
	);

	useEffect( () => {
		const cleanupBindings = () => {
			clearSearchDebounce();

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
			if ( listenerRef.current?.el === searchInput ) {
				return;
			}

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

		function syncSearchInput() {
			const input = findInserterSearchInput( document );

			if ( ! input ) {
				return;
			}

			attachToSearch( input );
		}

		syncSearchInput();

		if ( ! window.MutationObserver ) {
			return cleanupBindings;
		}

		const observer = new window.MutationObserver( () => {
			syncSearchInput();
		} );

		observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );
		observerRef.current = observer;

		return () => {
			cleanupBindings();
		};
	}, [
		canRecommend,
		clearSearchDebounce,
		isInserterOpen,
		handleSearchInput,
	] );

	if ( shouldRenderInserterAffordance ) {
		let notice = null;
		const docsGroundingNotice =
			patternStatus === 'ready' ? (
				<DocsGroundingNotice warning={ patternDocsGroundingWarning } />
			) : null;

		if ( ! canRecommend ) {
			notice = <CapabilityNotice surface="pattern" />;
		} else if ( connectorApprovalNotice ) {
			notice = (
				<CapabilityNotice
					surface="pattern"
					notice={ connectorApprovalNotice }
				/>
			);
		} else if ( shouldShowPatternShelf ) {
			notice = (
				<PatternShelf
					items={ recommendedPatterns }
					onInsert={ handleInsertPattern }
					diagnostics={ patternDiagnostics }
				/>
			);
		} else if ( patternStatus === 'error' ) {
			notice = (
				<PatternInserterNotice
					status="error"
					error={ patternError }
					onRetry={ handleRetry }
				/>
			);
		} else if ( patternStatus === 'ready' ) {
			notice = (
				<PatternInserterNotice
					status="empty"
					message={ getPatternEmptyMessage(
						recommendations,
						patternDiagnostics,
						{
							matchedRecommendationCount:
								builtRecommendedPatterns.length,
							insertableRecommendationCount:
								recommendedPatterns.length,
						}
					) }
				/>
			);
		} else if ( patternStatus === 'idle' ) {
			notice = <PatternInserterNotice status="idle" />;
		} else {
			notice = <PatternInserterNotice status="loading" />;
		}

		return (
			<PatternInserterPortal onAttached={ recordShownPatternOutcome }>
				{ docsGroundingNotice }
				{ notice }
			</PatternInserterPortal>
		);
	}

	return null;
}
