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
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

import CapabilityNotice from '../components/CapabilityNotice';
import DocsGroundingNotice from '../components/DocsGroundingNotice';
import { getResolvedContextSignatureFromResponse, STORE_NAME } from '../store';
import { buildPatternInsertionTargetSignature } from '../utils/recommendation-request-signature';
import {
	buildRecommendationSetId,
	getSuggestionOutcomeKey,
	normalizeSourceRequestSignature,
} from '../store/recommendation-outcomes';
import { formatCount } from '../utils/format-count';
import {
	getConnectorApprovalNotice,
	getSurfaceCapability,
} from '../utils/capability-flags';
import {
	getTemplatePartAreaLookup,
	inferTemplatePartArea,
} from '../utils/template-part-areas';
import { normalizeTemplateType } from '../utils/template-types';
import { getVisiblePatternNames } from '../utils/visible-patterns';
import { findInserterContainer, findInserterSearchInput } from './inserter-dom';
import { getAllowedPatterns } from './pattern-settings';
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
	const rootBlock = getNonEmptyString( rootEntry?.blockName );
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
		...( rootBlock ? { rootBlock } : {} ),
		ancestors: ancestorEntries
			.map( ( entry ) => entry.blockName )
			.filter( Boolean ),
		nearbySiblings,
		...( templatePartArea ? { templatePartArea } : {} ),
		...( templatePartSlug ? { templatePartSlug } : {} ),
		...( containerLayout ? { containerLayout } : {} ),
	};
}

function getPatternTitle( pattern ) {
	return getNonEmptyString( pattern?.title ) || pattern?.name || 'Pattern';
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
	const insertionPoint = useSelect(
		( select ) =>
			select( blockEditorStore ).getBlockInsertionPoint?.() || null,
		[]
	);
	const inserterRootClientId = insertionPoint?.rootClientId ?? null;
	const insertionIndex = insertionPoint?.index;
	const insertionContext = useSelect(
		( select ) => {
			if ( ! insertionPoint ) {
				return null;
			}

			const editor = select( blockEditorStore );

			return buildInsertionContext(
				editor,
				inserterRootClientId,
				insertionPoint
			);
		},
		[ inserterRootClientId, insertionPoint ]
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
	const { insertBlocks } = useDispatch( blockEditorStore );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );
	const effectivePostType =
		getNonEmptyString( postType ) ||
		( siteEditorPostType === 'wp_template' ? siteEditorPostType : '' );
	const observerRef = useRef( null );
	const listenerRef = useRef( null );
	const debounceRef = useRef( null );
	const noticeObserverRef = useRef( null );
	const noticeSlotRef = useRef( null );
	const shownRecommendationSetRef = useRef( '' );

	if ( ! noticeSlotRef.current && typeof document !== 'undefined' ) {
		const noticeSlot = document.createElement( 'div' );
		noticeSlot.className = INSERTER_SLOT_CLASS;
		noticeSlotRef.current = noticeSlot;
	}

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

			return {
				event,
				surface: 'pattern',
				recommendationSetId: patternRecommendationSetId,
				suggestionKey,
				sourceRequestSignature: patternSourceRequestSignature,
				reason,
				patternKey: pattern?.name || suggestionKey,
				rank: rank > 0 ? rank : null,
				resultCount: recommendedPatterns.length,
				topSuggestionKeys: recommendedPatterns
					.slice( 0, 3 )
					.map( ( item, index ) =>
						getSuggestionOutcomeKey(
							item?.recommendation || item?.pattern || {},
							item?.pattern?.name || `pattern:${ index + 1 }`
						)
					),
				target: {
					patternKey: pattern?.name || suggestionKey,
					rank: rank > 0 ? rank : null,
					blockName: insertionContext?.rootBlock || '',
				},
			};
		},
		[
			insertionContext,
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

			insertBlocks(
				blocks.map( ( block ) => cloneBlock( block ) ),
				insertionIndex,
				inserterRootClientId,
				true
			);
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
		if ( ! shouldShowPatternShelf ) {
			return;
		}

		if ( noticeSlotRef.current?.parentNode ) {
			recordShownPatternOutcome();
		}
	}, [ shouldShowPatternShelf, recordShownPatternOutcome ] );

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
				recordShownPatternOutcome();
				return;
			}

			if ( noticeSlot.parentNode ) {
				noticeSlot.parentNode.removeChild( noticeSlot );
			}

			inserterContainer.insertBefore(
				noticeSlot,
				inserterContainer.firstChild
			);
			recordShownPatternOutcome();
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
	}, [ shouldRenderInserterAffordance, recordShownPatternOutcome ] );

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

	if ( shouldRenderInserterAffordance && noticeSlotRef.current ) {
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

		return createPortal(
			<>
				{ docsGroundingNotice }
				{ notice }
			</>,
			noticeSlotRef.current
		);
	}

	return null;
}
