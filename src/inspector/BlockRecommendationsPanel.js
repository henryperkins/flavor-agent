import { Button, PanelBody, Notice } from '@wordpress/components';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { starFilled as icon } from '@wordpress/icons';

import { STORE_NAME } from '../store';
import {
	getBlockActivityUndoState,
	getLatestAppliedActivity,
	getLatestUndoableActivity,
	getResolvedActivityEntries,
} from '../store/activity-history';
import { getBlockSuggestionExecutionInfo } from '../store/update-helpers';
import {
	collectBlockContext,
	getLiveBlockContextSignature,
} from '../context/collector';
import {
	buildBlockRecommendationRequestData,
	getBlockRecommendationFreshness,
} from './block-recommendation-request';
import AIActivitySection from '../components/AIActivitySection';
import AIAdvisorySection from '../components/AIAdvisorySection';
import AIStatusNotice from '../components/AIStatusNotice';
import CapabilityNotice from '../components/CapabilityNotice';
import RecommendationHero from '../components/RecommendationHero';
import RecommendationLane from '../components/RecommendationLane';
import SurfaceComposer from '../components/SurfaceComposer';
import SurfacePanelIntro from '../components/SurfacePanelIntro';
import SurfaceScopeBar from '../components/SurfaceScopeBar';
import {
	APPLY_NOW_LABEL,
	MANUAL_IDEAS_LABEL,
	REFRESH_ACTION_LABEL,
	REVIEW_LANE_LABEL,
	STALE_STATUS_LABEL,
} from '../components/surface-labels';
import NavigationRecommendations from './NavigationRecommendations';
import SuggestionChips from './SuggestionChips';
import { getSuggestionKey } from './suggestion-keys';
import { getSurfaceCapability } from '../utils/capability-flags';
import { describeEditorBlockLabel } from '../utils/editor-context-metadata';
import {
	ACTIONABILITY_TIER_REVIEW_SAFE,
	classifyOperationActionability,
	getActionabilityLabel,
	getActionabilityReasonLabel,
} from '../utils/recommendation-actionability';
import { shallowStructuralEqual } from '../utils/structural-equality';
import {
	buildBlockReviewState,
	isBlockReviewStateCurrent,
} from './block-review-state';

const EMPTY_BLOCK_SUGGESTIONS = [];
const EMPTY_SURFACE_SUGGESTIONS = [];
const BLOCK_COMPOSER_HELPER_TEXT =
	'Flavor Agent keeps one-click apply limited to safe local block attribute changes.';
const CONTENT_ONLY_COMPOSER_HELPER_TEXT =
	'Flavor Agent will stay within editable content for this block and avoid style or settings changes.';
const CONTENT_ONLY_NOTICE_TEXT =
	'This block is content-restricted. Flavor Agent will stay within editable content and may keep broader block ideas as manual guidance only.';
const DEFAULT_BLOCK_STARTER_PROMPTS = [
	'Improve clarity and spacing',
	'Make this feel more editorial',
	'Simplify the layout',
];
const CONTENT_ONLY_STARTER_PROMPTS = [
	'Tighten the copy',
	'Clarify the message',
	'Make the content more concise',
];

export function findBlockPath( blocks, clientId, path = [] ) {
	for ( let index = 0; index < blocks.length; index++ ) {
		const block = blocks[ index ];
		const nextPath = [ ...path, index ];

		if ( block?.clientId === clientId ) {
			return nextPath;
		}

		if ( Array.isArray( block?.innerBlocks ) && block.innerBlocks.length ) {
			const nestedPath = findBlockPath(
				block.innerBlocks,
				clientId,
				nextPath
			);

			if ( nestedPath ) {
				return nestedPath;
			}
		}
	}

	return null;
}

function findBlockByClientId( blocks, clientId ) {
	for ( const block of blocks ) {
		if ( block?.clientId === clientId ) {
			return block;
		}

		if ( Array.isArray( block?.innerBlocks ) && block.innerBlocks.length ) {
			const nestedBlock = findBlockByClientId(
				block.innerBlocks,
				clientId
			);

			if ( nestedBlock ) {
				return nestedBlock;
			}
		}
	}

	return null;
}

export function blockPathMatches( left, right ) {
	return shallowStructuralEqual( left || [], right || [] );
}

function getCanRecommendBlocks() {
	return getSurfaceCapability( 'block' ).available;
}

function useBlockRecommendationState( clientId ) {
	const canRecommendBlocks = getCanRecommendBlocks();
	const editorBlocks = useSelect( ( select ) => {
		return select( blockEditorStore )?.getBlocks?.() || [];
	}, [] );
	const blockEditorSelection = useMemo(
		() => ( {
			getBlock: ( targetClientId ) =>
				findBlockByClientId( editorBlocks, targetClientId ),
			getBlockAttributes: ( targetClientId ) =>
				findBlockByClientId( editorBlocks, targetClientId )
					?.attributes || null,
			getBlocks: () => editorBlocks,
		} ),
		[ editorBlocks ]
	);

	const {
		recommendations,
		isLoading,
		error,
		status,
		storedContextSignature,
		requestToken,
		storedStaleReason,
		requestDiagnostics,
		blockActivityLog,
		blockApplyError,
		undoError,
		undoStatus,
		lastUndoneActivityId,
		editingMode,
		isInsideContentOnly,
		block,
	} = useSelect(
		( select ) => {
			const store = select( STORE_NAME );
			const blockEditor = select( blockEditorStore );
			const blocks = blockEditor.getBlocks?.() || [];
			const currentBlockPath = findBlockPath( blocks, clientId );
			const activityLog = store.getActivityLog() || [];
			const blockEntries = activityLog.filter(
				( entry ) =>
					entry?.surface === 'block' &&
					( entry?.target?.clientId === clientId ||
						blockPathMatches(
							entry?.target?.blockPath,
							currentBlockPath
						) )
			);
			const resolvedBlock = blockEditor.getBlock?.( clientId ) || null;
			const parentIds = blockEditor.getBlockParents?.( clientId ) || [];

			return {
				recommendations: store.getBlockRecommendations( clientId ),
				isLoading: store.isBlockLoading( clientId ),
				error: store.getBlockError( clientId ),
				status: store.getBlockStatus( clientId ),
				storedContextSignature:
					store.getBlockRecommendationContextSignature( clientId ),
				requestToken: store.getBlockRequestToken?.( clientId ) || 0,
				storedStaleReason:
					store.getBlockStaleReason?.( clientId ) || null,
				requestDiagnostics:
					store.getBlockRequestDiagnostics?.( clientId ) || null,
				blockActivityLog: blockEntries,
				blockApplyError: store.getBlockApplyError?.( clientId ) || null,
				undoError: store.getUndoError(),
				undoStatus: store.getUndoStatus(),
				lastUndoneActivityId: store.getLastUndoneActivityId(),
				editingMode: blockEditor.getBlockEditingMode?.( clientId ),
				isInsideContentOnly: parentIds.some(
					( parentId ) =>
						blockEditor.getBlockEditingMode?.( parentId ) ===
						'contentOnly'
				),
				block: resolvedBlock,
			};
		},
		[ clientId ]
	);
	const resolvedBlockActivities = useMemo(
		() =>
			getResolvedActivityEntries( blockActivityLog, ( entry ) =>
				getBlockActivityUndoState( entry, blockEditorSelection )
			),
		[ blockActivityLog, blockEditorSelection ]
	);
	const blockActivityEntries = useMemo(
		() => [ ...resolvedBlockActivities ].reverse(),
		[ resolvedBlockActivities ]
	);
	const latestBlockActivity = useMemo(
		() => getLatestAppliedActivity( resolvedBlockActivities ),
		[ resolvedBlockActivities ]
	);
	const latestUndoableActivityId = useMemo(
		() => getLatestUndoableActivity( resolvedBlockActivities )?.id || null,
		[ resolvedBlockActivities ]
	);
	const lastUndoneBlockActivity = useMemo(
		() =>
			resolvedBlockActivities.find(
				( entry ) => entry?.id === lastUndoneActivityId
			) || null,
		[ resolvedBlockActivities, lastUndoneActivityId ]
	);

	return {
		canRecommendBlocks,
		recommendations,
		isLoading,
		error,
		status,
		storedContextSignature,
		requestToken,
		storedStaleReason,
		requestDiagnostics,
		blockActivityEntries,
		latestBlockActivity,
		latestUndoableActivityId,
		lastUndoneBlockActivity,
		blockApplyError,
		undoError,
		undoStatus,
		isDisabled: editingMode === 'disabled',
		isContentRestricted:
			editingMode === 'contentOnly' || isInsideContentOnly,
		block,
	};
}

function getFeaturedSuggestion(
	executableBlockSuggestions,
	reviewBlockSuggestions,
	advisoryBlockSuggestions
) {
	if ( executableBlockSuggestions.length > 0 ) {
		return {
			suggestion: executableBlockSuggestions[ 0 ],
			tone: APPLY_NOW_LABEL,
			why: 'Flavor Agent can safely apply this directly on the current block.',
		};
	}

	if ( reviewBlockSuggestions.length > 0 ) {
		return {
			suggestion: reviewBlockSuggestions[ 0 ],
			tone: REVIEW_LANE_LABEL,
			why: 'This is the strongest validated structural change, but it requires review before any apply path.',
		};
	}

	if ( advisoryBlockSuggestions.length > 0 ) {
		return {
			suggestion: advisoryBlockSuggestions[ 0 ],
			tone: MANUAL_IDEAS_LABEL,
			why: 'This is the strongest next move, but it still needs manual follow-through.',
		};
	}

	return null;
}

function isReviewSafeSuggestion( suggestion = {} ) {
	const actionability =
		suggestion?.actionability || suggestion?.eligibility || {};

	return (
		actionability?.tier === ACTIONABILITY_TIER_REVIEW_SAFE &&
		Array.isArray( actionability.executableOperations ) &&
		actionability.executableOperations.length > 0
	);
}

function getAdvisoryRemainderOperations( suggestion = {} ) {
	const actionability =
		suggestion?.actionability || suggestion?.eligibility || {};

	return Array.isArray( actionability.advisoryOperationsRejected )
		? actionability.advisoryOperationsRejected
		: [];
}

function getReviewRemainderOperations( suggestion = {} ) {
	const actionability =
		suggestion?.actionability || suggestion?.eligibility || {};

	return Array.isArray( actionability.reviewOperations )
		? actionability.reviewOperations
		: [];
}

function buildAdvisoryRemainderSuggestion( suggestion ) {
	const actionability = classifyOperationActionability( {
		operations: getAdvisoryRemainderOperations( suggestion ),
		validation: {
			ok: false,
			operations: [],
			rejectedOperations: Array.isArray( suggestion?.rejectedOperations )
				? suggestion.rejectedOperations
				: [],
		},
	} );

	return {
		...suggestion,
		suggestionKey: `${ getSuggestionKey( suggestion ) }-advisory-remainder`,
		eligibility: actionability,
		actionability,
	};
}

function buildReviewRemainderSuggestion( suggestion ) {
	const reviewOperations = getReviewRemainderOperations( suggestion );
	const actionability = classifyOperationActionability( {
		operations: reviewOperations,
		validation: {
			ok: true,
			operations: reviewOperations,
			rejectedOperations: [],
		},
	} );

	return {
		...suggestion,
		suggestionKey: `${ getSuggestionKey( suggestion ) }-review-remainder`,
		eligibility: actionability,
		actionability,
	};
}

export function BlockRecommendationsContent( {
	clientId,
	eyebrow = 'Selected Block',
	introCopy = 'Ask for a specific outcome or fetch recommendations based on the current block context.',
	prompt = undefined,
	onPromptChange = undefined,
} ) {
	const {
		canRecommendBlocks,
		recommendations,
		isLoading,
		error,
		status,
		storedContextSignature,
		requestToken,
		storedStaleReason,
		requestDiagnostics,
		blockActivityEntries,
		latestBlockActivity,
		latestUndoableActivityId,
		lastUndoneBlockActivity,
		blockApplyError,
		undoError,
		undoStatus,
		isDisabled,
		isContentRestricted,
		block,
	} = useBlockRecommendationState( clientId );
	const {
		fetchBlockRecommendations,
		clearBlockError,
		clearUndoError,
		undoActivity,
	} = useDispatch( STORE_NAME );
	const liveContextSignature = useSelect(
		( select ) => getLiveBlockContextSignature( select, clientId ),
		[ clientId ]
	);
	const liveContext = useMemo( () => {
		void liveContextSignature;

		return clientId ? collectBlockContext( clientId ) : null;
	}, [ clientId, liveContextSignature ] );
	const isPromptControlled = typeof prompt === 'string';
	const [ uncontrolledPrompt, setUncontrolledPrompt ] = useState(
		() => recommendations?.prompt || ''
	);
	const [ activeReviewState, setActiveReviewState ] = useState( null );
	const currentPrompt = isPromptControlled ? prompt : uncontrolledPrompt;
	const handlePromptChange = isPromptControlled
		? onPromptChange
		: setUncontrolledPrompt;

	useEffect( () => {
		if ( isPromptControlled ) {
			return;
		}

		setUncontrolledPrompt( recommendations?.prompt || '' );
	}, [ clientId, isPromptControlled, recommendations?.prompt ] );
	const hasApplySuccess =
		Boolean( latestBlockActivity ) &&
		latestBlockActivity?.id === latestUndoableActivityId;
	const hasUndoSuccess =
		undoStatus === 'success' &&
		lastUndoneBlockActivity?.undo?.status === 'undone';
	const {
		requestSignature: currentRequestSignature,
		requestInput: currentRequestInput,
	} = useMemo(
		() =>
			buildBlockRecommendationRequestData( {
				clientId,
				liveContext,
				liveContextSignature,
				prompt: currentPrompt,
			} ),
		[ clientId, currentPrompt, liveContext, liveContextSignature ]
	);
	const {
		effectiveStaleReason,
		hasFreshResult,
		hasStoredResult: hasResult,
		isStaleResult,
	} = useMemo(
		() =>
			getBlockRecommendationFreshness( {
				clientId,
				recommendations,
				status,
				storedContextSignature,
				storedStaleReason,
				liveContextSignature,
				prompt: currentPrompt,
			} ),
		[
			clientId,
			currentPrompt,
			liveContextSignature,
			recommendations,
			status,
			storedContextSignature,
			storedStaleReason,
		]
	);
	const blockSuggestions = hasResult
		? recommendations?.block ?? EMPTY_BLOCK_SUGGESTIONS
		: EMPTY_BLOCK_SUGGESTIONS;
	const settingsSuggestions = hasResult
		? recommendations?.settings ?? EMPTY_SURFACE_SUGGESTIONS
		: EMPTY_SURFACE_SUGGESTIONS;
	const styleSuggestions =
		hasResult && ! isContentRestricted
			? recommendations?.styles ?? EMPTY_SURFACE_SUGGESTIONS
			: EMPTY_SURFACE_SUGGESTIONS;
	const hasBlockSuggestions = blockSuggestions.length > 0;
	const hasSurfaceSuggestions =
		hasBlockSuggestions ||
		settingsSuggestions.length > 0 ||
		styleSuggestions.length > 0;
	const { statusNotice } = useSelect( ( select ) => {
		const store = select( STORE_NAME );

		return {
			statusNotice: store.getSurfaceStatusNotice( 'block', {
				requestError: error,
				applyError: blockApplyError,
				undoError,
				undoStatus,
				hasResult,
				hasSuggestions: hasSurfaceSuggestions,
				hasSuccess: hasApplySuccess,
				hasUndoSuccess,
				emptyMessage:
					hasFreshResult && ! hasSurfaceSuggestions
						? 'No recommendations were returned for the current prompt.'
						: '',
				applySuccessMessage: hasApplySuccess
					? `Applied ${
							latestBlockActivity?.suggestion || 'suggestion'
					  }.`
					: '',
				undoSuccessMessage: hasUndoSuccess
					? `Undid ${
							lastUndoneBlockActivity?.suggestion || 'suggestion'
					  }.`
					: '',
				onDismissAction: Boolean( error ),
				onApplyDismissAction: Boolean( blockApplyError ),
				onUndoDismissAction: Boolean( undoError ),
			} ),
		};
	} );
	const reviewScope = useMemo(
		() => ( {
			clientId,
			requestToken,
			requestSignature: currentRequestSignature || '',
		} ),
		[ clientId, currentRequestSignature, requestToken ]
	);
	useEffect( () => {
		if (
			activeReviewState &&
			( isStaleResult ||
				! isBlockReviewStateCurrent( activeReviewState, reviewScope ) )
		) {
			setActiveReviewState( null );
		}
	}, [ activeReviewState, isStaleResult, reviewScope ] );

	const {
		executableBlockSuggestions,
		reviewBlockSuggestions,
		advisoryBlockSuggestions,
	} = useMemo( () => {
		const blockContext = recommendations?.blockContext || {};
		const executionContract = recommendations?.executionContract || null;
		const executable = [];
		const review = [];
		const advisory = [];

		for ( const suggestion of blockSuggestions ) {
			const execution = getBlockSuggestionExecutionInfo(
				suggestion,
				blockContext,
				executionContract
			);
			const viewSuggestion = {
				...suggestion,
				eligibility: execution.eligibility,
				actionability: execution.actionability,
			};

			if ( execution.isExecutable ) {
				executable.push( viewSuggestion );
			} else if ( isReviewSafeSuggestion( viewSuggestion ) ) {
				review.push( viewSuggestion );
			} else {
				advisory.push( viewSuggestion );
			}

			if ( getReviewRemainderOperations( viewSuggestion ).length > 0 ) {
				review.push( buildReviewRemainderSuggestion( viewSuggestion ) );
			}

			if ( getAdvisoryRemainderOperations( viewSuggestion ).length > 0 ) {
				advisory.push(
					buildAdvisoryRemainderSuggestion( viewSuggestion )
				);
			}
		}

		return {
			executableBlockSuggestions: executable,
			reviewBlockSuggestions: review,
			advisoryBlockSuggestions: advisory,
		};
	}, [
		blockSuggestions,
		recommendations?.blockContext,
		recommendations?.executionContract,
	] );
	const activeReviewSuggestion = useMemo( () => {
		if (
			! activeReviewState ||
			isStaleResult ||
			! isBlockReviewStateCurrent( activeReviewState, reviewScope )
		) {
			return null;
		}

		return (
			reviewBlockSuggestions.find(
				( suggestion ) =>
					getSuggestionKey( suggestion ) ===
					activeReviewState.suggestionKey
			) || null
		);
	}, [
		activeReviewState,
		isStaleResult,
		reviewBlockSuggestions,
		reviewScope,
	] );
	const featuredSuggestion = useMemo(
		() =>
			isStaleResult
				? null
				: getFeaturedSuggestion(
						executableBlockSuggestions,
						reviewBlockSuggestions,
						advisoryBlockSuggestions
				  ),
		[
			advisoryBlockSuggestions,
			executableBlockSuggestions,
			isStaleResult,
			reviewBlockSuggestions,
		]
	);
	const diagnosticActivityEntry = useMemo( () => {
		const isFailureDiagnostic = requestDiagnostics?.type === 'failure';
		const isEmptyResultDiagnostic =
			hasFreshResult && requestDiagnostics?.hasEmptyBlockResult;

		if ( ! isFailureDiagnostic && ! isEmptyResultDiagnostic ) {
			return null;
		}

		return {
			id: `block-request-diagnostic:${ clientId || 'unknown' }:${
				requestDiagnostics.requestToken || 0
			}`,
			type: 'request_diagnostic',
			surface: 'block',
			suggestion:
				requestDiagnostics.title ||
				'No block-lane suggestions returned',
			target: {
				clientId,
				blockName:
					requestDiagnostics.blockName ||
					block?.name ||
					recommendations?.blockName ||
					'',
			},
			request: {
				prompt:
					requestDiagnostics.prompt || recommendations?.prompt || '',
				...( requestDiagnostics.requestMeta
					? {
							ai: requestDiagnostics.requestMeta,
					  }
					: {} ),
				...( requestDiagnostics.errorMessage
					? {
							error: {
								code: requestDiagnostics.errorCode || '',
								message: requestDiagnostics.errorMessage,
							},
					  }
					: {} ),
			},
			diagnostic: {
				detailLines: Array.isArray( requestDiagnostics.detailLines )
					? requestDiagnostics.detailLines
					: [],
				rawCounts: requestDiagnostics.rawCounts || null,
				finalCounts: requestDiagnostics.finalCounts || null,
				reasonCodes: Array.isArray( requestDiagnostics.reasonCodes )
					? requestDiagnostics.reasonCodes
					: [],
			},
			undo: {
				canUndo: false,
				status: isFailureDiagnostic ? 'failed' : 'review',
				error: isFailureDiagnostic
					? requestDiagnostics.errorMessage || null
					: null,
			},
			timestamp: requestDiagnostics.timestamp || new Date().toISOString(),
			executionResult: 'review',
		};
	}, [
		block?.name,
		clientId,
		hasFreshResult,
		recommendations?.blockName,
		recommendations?.prompt,
		requestDiagnostics,
	] );
	const activitySectionEntries = useMemo(
		() =>
			diagnosticActivityEntry
				? [ diagnosticActivityEntry, ...blockActivityEntries ]
				: blockActivityEntries,
		[ blockActivityEntries, diagnosticActivityEntry ]
	);
	const activitySectionDescription = diagnosticActivityEntry
		? 'Recent request diagnostics and applied actions for this block.'
		: 'Undo follows the same latest-valid-action rule used across every executable Flavor Agent surface.';

	const handleFetch = useCallback( () => {
		if ( ! canRecommendBlocks ) {
			return;
		}

		if ( liveContext ) {
			fetchBlockRecommendations( clientId, liveContext, currentPrompt );
		}
	}, [
		canRecommendBlocks,
		clientId,
		currentPrompt,
		fetchBlockRecommendations,
		liveContext,
	] );
	const handleUndo = useCallback(
		( activityId ) => {
			undoActivity( activityId );
		},
		[ undoActivity ]
	);
	const handleRefresh = useCallback( () => {
		if ( ! canRecommendBlocks || ! liveContext ) {
			return;
		}

		fetchBlockRecommendations( clientId, liveContext, currentPrompt );
	}, [
		canRecommendBlocks,
		clientId,
		currentPrompt,
		fetchBlockRecommendations,
		liveContext,
	] );
	const handleOpenReview = useCallback(
		( suggestion ) => {
			if ( isStaleResult ) {
				return;
			}

			setActiveReviewState(
				buildBlockReviewState( {
					...reviewScope,
					suggestionKey: getSuggestionKey( suggestion ),
				} )
			);
		},
		[ isStaleResult, reviewScope ]
	);
	let dismissStatusNotice;

	if ( statusNotice?.source === 'request' ) {
		dismissStatusNotice = () => clearBlockError( clientId );
	} else if ( statusNotice?.source === 'apply' ) {
		dismissStatusNotice = () => clearBlockError( clientId );
	} else if ( statusNotice?.source === 'undo' ) {
		dismissStatusNotice = clearUndoError;
	}

	const composerHelperText = isContentRestricted
		? CONTENT_ONLY_COMPOSER_HELPER_TEXT
		: BLOCK_COMPOSER_HELPER_TEXT;
	const composerStarterPrompts = isContentRestricted
		? CONTENT_ONLY_STARTER_PROMPTS
		: DEFAULT_BLOCK_STARTER_PROMPTS;
	const composerLabel = isContentRestricted
		? 'What do you want to improve about this content?'
		: 'What do you want to improve about this block?';
	const composerPlaceholder = isContentRestricted
		? 'Describe the content change you want for this block.'
		: 'Describe the outcome you want for this block.';

	if ( ! clientId || ! block || isDisabled ) {
		return null;
	}

	const blockScopeLabel = describeEditorBlockLabel(
		block?.name || '',
		block?.attributes || {}
	);
	let staleScopeReason = '';

	if ( isStaleResult ) {
		staleScopeReason =
			effectiveStaleReason === 'server-apply'
				? 'This result no longer matches the current server-resolved apply context. Refresh before applying anything from the previous result.'
				: 'This result no longer matches the current block or prompt. Refresh before applying anything from the previous result.';
	}

	return (
		<div className="flavor-agent-panel">
			<SurfacePanelIntro eyebrow={ eyebrow } introCopy={ introCopy } />

			<SurfaceScopeBar
				scopeLabel={ blockScopeLabel }
				isFresh={ hasFreshResult }
				hasResult={ hasResult }
				announceChanges
				staleReason={ staleScopeReason }
				refreshLabel={ REFRESH_ACTION_LABEL }
				onRefresh={ isStaleResult ? handleRefresh : undefined }
				isRefreshing={ isLoading }
			/>

			{ ! canRecommendBlocks && <CapabilityNotice surface="block" /> }

			{ isContentRestricted && (
				<Notice
					status="info"
					isDismissible={ false }
					className="flavor-agent-content-notice"
				>
					{ CONTENT_ONLY_NOTICE_TEXT }
				</Notice>
			) }

			<SurfaceComposer
				title="Ask Flavor Agent"
				prompt={ currentPrompt }
				onPromptChange={ handlePromptChange }
				onFetch={ handleFetch }
				placeholder={ composerPlaceholder }
				label={ composerLabel }
				rows={ 3 }
				helperText={ composerHelperText }
				starterPrompts={ composerStarterPrompts }
				submitHint="Press Cmd/Ctrl+Enter to submit."
				fetchIcon={ icon }
				isLoading={ isLoading }
				disabled={ ! canRecommendBlocks }
			/>

			<AIStatusNotice
				notice={ statusNotice }
				onAction={
					statusNotice?.actionType === 'undo' && latestBlockActivity
						? () => handleUndo( latestBlockActivity.id )
						: undefined
				}
				onDismiss={ dismissStatusNotice }
			/>

			{ isStaleResult && (
				<RecommendationHero
					title="Refresh recommendations for the current block"
					description="Flavor Agent kept the previous result visible so you can compare it against the current block."
					tone={ STALE_STATUS_LABEL }
					why="Apply actions stay disabled until you refresh against the live block context and current prompt."
					primaryActionLabel={ REFRESH_ACTION_LABEL }
					onPrimaryAction={ handleRefresh }
					primaryActionDisabled={ isLoading }
				/>
			) }

			{ featuredSuggestion && (
				<RecommendationHero
					title={
						featuredSuggestion?.suggestion?.label ||
						'Recommended next change'
					}
					description={
						featuredSuggestion?.suggestion?.description || ''
					}
					tone={ featuredSuggestion.tone }
					why={ featuredSuggestion.why }
				/>
			) }

			{ hasResult && recommendations?.explanation && (
				<p className="flavor-agent-explanation flavor-agent-panel__note">
					{ recommendations.explanation }
				</p>
			) }

			{ executableBlockSuggestions.length > 0 && (
				<RecommendationLane
					title={ APPLY_NOW_LABEL }
					tone={
						isStaleResult ? STALE_STATUS_LABEL : APPLY_NOW_LABEL
					}
					count={ executableBlockSuggestions.length }
					countNoun="suggestion"
					description={
						isStaleResult
							? 'These suggestions are shown for reference from the last request. Refresh before applying them.'
							: 'Validator-computed inline-safe suggestions can change local block attributes directly.'
					}
					meta={
						<EligibilitySummary
							suggestions={ executableBlockSuggestions }
						/>
					}
				>
					<SuggestionChips
						clientId={ clientId }
						suggestions={ executableBlockSuggestions }
						label="AI block suggestions"
						currentRequestSignature={ currentRequestSignature }
						currentRequestInput={ currentRequestInput }
						disabled={ isStaleResult }
					/>
				</RecommendationLane>
			) }

			{ reviewBlockSuggestions.length > 0 && (
				<RecommendationLane
					title={ REVIEW_LANE_LABEL }
					tone={
						isStaleResult ? STALE_STATUS_LABEL : REVIEW_LANE_LABEL
					}
					count={ reviewBlockSuggestions.length }
					countNoun="suggestion"
					description={
						isStaleResult
							? 'These structural suggestions are shown for reference from the last request. Refresh before reviewing them.'
							: 'Validated structural operations require review before apply.'
					}
					meta={
						<EligibilitySummary
							suggestions={ reviewBlockSuggestions }
						/>
					}
				>
					{ reviewBlockSuggestions.map( ( suggestion ) => (
						<ReviewSuggestionCard
							key={ getSuggestionKey( suggestion ) }
							suggestion={ suggestion }
							isActive={
								activeReviewSuggestion &&
								getSuggestionKey( activeReviewSuggestion ) ===
									getSuggestionKey( suggestion )
							}
							isStale={ isStaleResult }
							onReview={ handleOpenReview }
						/>
					) ) }
				</RecommendationLane>
			) }

			{ settingsSuggestions.length > 0 && (
				<RecommendationLane
					title="Settings suggestions"
					tone={
						isStaleResult ? STALE_STATUS_LABEL : APPLY_NOW_LABEL
					}
					count={ settingsSuggestions.length }
					countNoun="suggestion"
					description={
						isStaleResult
							? 'These settings suggestions are shown for reference from the last request. Refresh before applying them.'
							: 'Flavor Agent keeps settings changes executable here and mirrors them into native panels for context only.'
					}
				>
					<SuggestionChips
						clientId={ clientId }
						suggestions={ settingsSuggestions }
						label="AI settings suggestions"
						currentRequestSignature={ currentRequestSignature }
						currentRequestInput={ currentRequestInput }
						disabled={ isStaleResult }
					/>
				</RecommendationLane>
			) }

			{ styleSuggestions.length > 0 && (
				<RecommendationLane
					title="Style suggestions"
					tone={
						isStaleResult ? STALE_STATUS_LABEL : APPLY_NOW_LABEL
					}
					count={ styleSuggestions.length }
					countNoun="suggestion"
					description={
						isStaleResult
							? 'These style suggestions are shown for reference from the last request. Refresh before applying them.'
							: 'Flavor Agent keeps style changes executable here and mirrors them into native panels for context only.'
					}
				>
					<SuggestionChips
						clientId={ clientId }
						suggestions={ styleSuggestions }
						label="AI style suggestions"
						currentRequestSignature={ currentRequestSignature }
						currentRequestInput={ currentRequestInput }
						disabled={ isStaleResult }
					/>
				</RecommendationLane>
			) }

			{ advisoryBlockSuggestions.length > 0 && (
				<AIAdvisorySection
					title={ MANUAL_IDEAS_LABEL }
					count={ advisoryBlockSuggestions.length }
					countNoun="suggestion"
					initialOpen
					description={
						isStaleResult
							? 'These ideas are shown for reference from the last request. Refresh before acting on them against the current block.'
							: 'These ideas need manual follow-through or a broader review flow, so Flavor Agent keeps them advisory.'
					}
				>
					{ advisoryBlockSuggestions.map( ( suggestion ) => (
						<AdvisorySuggestionCard
							key={ getSuggestionKey( suggestion ) }
							suggestion={ suggestion }
						/>
					) ) }
				</AIAdvisorySection>
			) }

			<NavigationRecommendations clientId={ clientId } embedded />

			<AIActivitySection
				description={ activitySectionDescription }
				entries={ activitySectionEntries }
				isUndoing={ undoStatus === 'undoing' }
				onUndo={ handleUndo }
				initialOpen={ ! hasResult }
				resetKey={ clientId || 'block' }
				maxVisible={ 3 }
			/>
		</div>
	);
}

function getReviewOperationSummary( operation = {} ) {
	if ( operation?.type === 'insert_pattern' ) {
		return operation.position === 'insert_before'
			? 'Insert pattern before the selected block.'
			: 'Insert pattern after the selected block.';
	}

	if ( operation?.type === 'replace_block_with_pattern' ) {
		return 'Replace the selected block with a pattern.';
	}

	return 'Review structural operation.';
}

function getReviewOperationDetails( operation = {} ) {
	return [
		[ 'Pattern', operation.patternName ],
		[ 'Target', operation.targetClientId ],
		[ 'Expected block', operation.expectedTarget?.name ],
		[ 'Target signature', operation.targetSignature ],
		[ 'Position', operation.position ],
		[ 'Action', operation.action ],
	].filter( ( [ , value ] ) => typeof value === 'string' && value !== '' );
}

function getReviewDetailsId( suggestion ) {
	return `flavor-agent-block-review-${ getSuggestionKey( suggestion ).replace(
		/[^a-zA-Z0-9_-]+/g,
		'-'
	) }`;
}

function ReviewSuggestionCard( { suggestion, isActive, isStale, onReview } ) {
	const typeLabel = getAdvisorySuggestionTypeLabel( suggestion );
	const eligibility =
		suggestion?.eligibility || suggestion?.actionability || {};
	const executableOperations = Array.isArray(
		eligibility.executableOperations
	)
		? eligibility.executableOperations
		: [];
	const operationSummaries = executableOperations.map(
		getReviewOperationSummary
	);
	const operationDetails = executableOperations.flatMap(
		getReviewOperationDetails
	);
	const label = suggestion?.label || 'Suggestion';
	const reviewDetailsId = getReviewDetailsId( suggestion );
	const reviewButtonLabel = isStale
		? `Refresh to review ${ label }`
		: `Review ${ label }`;

	return (
		<div className="flavor-agent-card">
			<div className="flavor-agent-card__header flavor-agent-card__header--spaced">
				<div className="flavor-agent-card__lead">
					<span className="flavor-agent-card__label">{ label }</span>
					<div className="flavor-agent-card__meta">
						<span className="flavor-agent-pill">
							{ getActionabilityLabel( eligibility?.tier ) }
						</span>
						<span className="flavor-agent-pill">{ typeLabel }</span>
						<span className="flavor-agent-pill">
							Validator computed
						</span>
					</div>
				</div>
				<Button
					variant="secondary"
					size="small"
					disabled={ isStale }
					onClick={ () => onReview( suggestion ) }
					className="flavor-agent-card__apply"
					aria-label={ reviewButtonLabel }
					aria-expanded={ isActive ? 'true' : 'false' }
					aria-controls={ isActive ? reviewDetailsId : undefined }
				>
					{ isStale ? 'Refresh to review' : 'Review' }
				</Button>
			</div>

			{ suggestion?.description && (
				<p className="flavor-agent-card__description">
					{ suggestion.description }
				</p>
			) }

			{ operationSummaries.length > 0 && (
				<ul className="flavor-agent-card__list">
					{ operationSummaries.map( ( summary ) => (
						<li key={ summary }>{ summary }</li>
					) ) }
				</ul>
			) }

			{ operationDetails.length > 0 && (
				<ul className="flavor-agent-card__list">
					{ operationDetails.map( ( [ detailLabel, value ] ) => (
						<li key={ `${ detailLabel }:${ value }` }>
							{ detailLabel }: { value }
						</li>
					) ) }
				</ul>
			) }

			{ isActive && (
				<div
					id={ reviewDetailsId }
					className="flavor-agent-card__description"
					role="status"
				>
					<strong>Selected structural review</strong>
					<p>
						Block structural apply is not available in this
						milestone. This review state is scoped to the current
						block, request token, and request signature.
					</p>
				</div>
			) }
		</div>
	);
}

function AdvisorySuggestionCard( { suggestion } ) {
	const typeLabel = getAdvisorySuggestionTypeLabel( suggestion );
	const eligibility =
		suggestion?.eligibility || suggestion?.actionability || {};
	const tierLabel = getActionabilityLabel( eligibility?.tier );
	const reasonLabels = ( eligibility?.blockers || eligibility?.reasons || [] )
		.map( getActionabilityReasonLabel )
		.filter( Boolean );

	return (
		<div className="flavor-agent-card">
			<div className="flavor-agent-card__header flavor-agent-card__header--spaced">
				<div className="flavor-agent-card__lead">
					<span className="flavor-agent-card__label">
						{ suggestion?.label || 'Suggestion' }
					</span>
					{ typeLabel && (
						<div className="flavor-agent-card__meta">
							<span className="flavor-agent-pill">
								{ tierLabel }
							</span>
							<span className="flavor-agent-pill">
								{ typeLabel }
							</span>
							<span className="flavor-agent-pill">
								Validator computed
							</span>
						</div>
					) }
				</div>
			</div>

			{ suggestion?.description && (
				<p className="flavor-agent-card__description">
					{ suggestion.description }
				</p>
			) }

			{ reasonLabels.length > 0 && (
				<p className="flavor-agent-card__description">
					{ `Eligibility blockers: ${ reasonLabels.join( ', ' ) }.` }
				</p>
			) }
		</div>
	);
}

function EligibilitySummary( { suggestions = [] } ) {
	const tierLabels = [
		...new Set(
			suggestions
				.map( ( suggestion ) =>
					getActionabilityLabel(
						suggestion?.eligibility?.tier ||
							suggestion?.actionability?.tier
					)
				)
				.filter( Boolean )
		),
	];

	if ( tierLabels.length === 0 ) {
		return null;
	}

	return tierLabels.map( ( label ) => (
		<span key={ label } className="flavor-agent-pill">
			{ label }
		</span>
	) );
}

function getAdvisorySuggestionTypeLabel( suggestion ) {
	switch ( suggestion?.type ) {
		case 'structural_recommendation':
			return 'Structure';
		case 'pattern_replacement':
			return 'Pattern';
		case 'style_variation':
			return 'Style variation';
		default:
			return 'Manual idea';
	}
}

export function BlockRecommendationsPanel( props ) {
	return (
		<PanelBody
			title="AI Recommendations"
			initialOpen={ false }
			icon={ icon }
		>
			<BlockRecommendationsContent { ...props } />
		</PanelBody>
	);
}

export function BlockRecommendationsDocumentPanel() {
	const [ rememberedClientId, setRememberedClientId ] = useState( null );
	const { selectedBlockClientId, selectedBlock } = useSelect( ( select ) => {
		const blockEditor = select( blockEditorStore );
		const clientId = blockEditor.getSelectedBlockClientId?.() || null;

		return {
			selectedBlockClientId: clientId,
			selectedBlock: clientId ? blockEditor.getBlock?.( clientId ) : null,
		};
	}, [] );
	const rememberedBlock = useSelect(
		( select ) => {
			const blockEditor = select( blockEditorStore );

			return rememberedClientId
				? blockEditor.getBlock?.( rememberedClientId ) || null
				: null;
		},
		[ rememberedClientId ]
	);

	useEffect( () => {
		if ( selectedBlockClientId && selectedBlock ) {
			setRememberedClientId( selectedBlockClientId );
		}
	}, [ selectedBlockClientId, selectedBlock ] );

	if ( selectedBlockClientId || ! rememberedClientId || ! rememberedBlock ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="flavor-agent-block-recommendations"
			title="AI Recommendations"
		>
			<BlockRecommendationsContent
				clientId={ rememberedClientId }
				eyebrow="Last Selected Block"
				introCopy="Saving cleared block selection. Flavor Agent stays scoped to the last block you selected until you choose another block."
			/>
		</PluginDocumentSettingPanel>
	);
}
