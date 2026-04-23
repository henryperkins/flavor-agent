import { PanelBody, Notice } from '@wordpress/components';
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
	STALE_STATUS_LABEL,
} from '../components/surface-labels';
import NavigationRecommendations from './NavigationRecommendations';
import SuggestionChips from './SuggestionChips';
import { getSuggestionKey } from './suggestion-keys';
import { getSurfaceCapability } from '../utils/capability-flags';
import { describeEditorBlockLabel } from '../utils/editor-context-metadata';
import { shallowStructuralEqual } from '../utils/structural-equality';

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

export function blockPathMatches( left, right ) {
	return shallowStructuralEqual( left || [], right || [] );
}

function getCanRecommendBlocks() {
	return getSurfaceCapability( 'block' ).available;
}

function useBlockRecommendationState( clientId ) {
	const canRecommendBlocks = getCanRecommendBlocks();
	const blockEditorSelection = useSelect( ( select ) => {
		const blockEditor = select( blockEditorStore );

		return {
			getBlock: ( targetClientId ) =>
				blockEditor.getBlock?.( targetClientId ) || null,
			getBlockAttributes: ( targetClientId ) =>
				blockEditor.getBlockAttributes?.( targetClientId ) || null,
			getBlocks: () => blockEditor.getBlocks?.() || [],
		};
	}, [] );

	const {
		recommendations,
		isLoading,
		error,
		status,
		storedContextSignature,
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
	advisoryBlockSuggestions
) {
	if ( executableBlockSuggestions.length > 0 ) {
		return {
			suggestion: executableBlockSuggestions[ 0 ],
			tone: APPLY_NOW_LABEL,
			why: 'Flavor Agent can safely apply this directly on the current block.',
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
	const { executableBlockSuggestions, advisoryBlockSuggestions } =
		useMemo( () => {
			const blockContext = recommendations?.blockContext || {};
			const executionContract =
				recommendations?.executionContract || null;
			const executable = [];
			const advisory = [];

			for ( const suggestion of blockSuggestions ) {
				const execution = getBlockSuggestionExecutionInfo(
					suggestion,
					blockContext,
					executionContract
				);

				if ( execution.isExecutable ) {
					executable.push( suggestion );
				} else {
					advisory.push( suggestion );
				}
			}

			return {
				executableBlockSuggestions: executable,
				advisoryBlockSuggestions: advisory,
			};
		}, [
			blockSuggestions,
			recommendations?.blockContext,
			recommendations?.executionContract,
		] );
	const featuredSuggestion = useMemo(
		() =>
			isStaleResult
				? null
				: getFeaturedSuggestion(
						executableBlockSuggestions,
						advisoryBlockSuggestions
				  ),
		[ advisoryBlockSuggestions, executableBlockSuggestions, isStaleResult ]
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
							: 'One-click apply remains available when Flavor Agent can safely change local block attributes.'
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

function AdvisorySuggestionCard( { suggestion } ) {
	const typeLabel = getAdvisorySuggestionTypeLabel( suggestion );

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
								{ typeLabel }
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
		</div>
	);
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
