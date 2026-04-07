import { PanelBody, Notice } from '@wordpress/components';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useState, useCallback, useMemo, useEffect } from '@wordpress/element';
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
import AIActivitySection from '../components/AIActivitySection';
import AIStatusNotice from '../components/AIStatusNotice';
import CapabilityNotice from '../components/CapabilityNotice';
import RecommendationHero from '../components/RecommendationHero';
import RecommendationLane from '../components/RecommendationLane';
import SurfaceComposer from '../components/SurfaceComposer';
import SurfacePanelIntro from '../components/SurfacePanelIntro';
import SurfaceScopeBar from '../components/SurfaceScopeBar';
import NavigationRecommendations from './NavigationRecommendations';
import SuggestionChips from './SuggestionChips';
import { getSuggestionKey } from './suggestion-keys';
import { getSurfaceCapability } from '../utils/capability-flags';

const EMPTY_BLOCK_SUGGESTIONS = [];
const BLOCK_COMPOSER_HELPER_TEXT =
	'Flavor Agent keeps one-click apply limited to safe local block attribute changes.';

export function findBlockPath(blocks, clientId, path = []) {
	for (let index = 0; index < blocks.length; index++) {
		const block = blocks[index];
		const nextPath = [...path, index];

		if (block?.clientId === clientId) {
			return nextPath;
		}

		if (Array.isArray(block?.innerBlocks) && block.innerBlocks.length) {
			const nestedPath = findBlockPath(block.innerBlocks, clientId, nextPath);

			if (nestedPath) {
				return nestedPath;
			}
		}
	}

	return null;
}

export function blockPathMatches(left, right) {
	return JSON.stringify(left || []) === JSON.stringify(right || []);
}

function getCanRecommendBlocks() {
	return getSurfaceCapability('block').available;
}

function useBlockRecommendationState(clientId) {
	const canRecommendBlocks = getCanRecommendBlocks();
	const blockEditorSelection = useSelect((select) => {
		const blockEditor = select(blockEditorStore);

		return {
			getBlock: (targetClientId) =>
				blockEditor.getBlock?.(targetClientId) || null,
			getBlockAttributes: (targetClientId) =>
				blockEditor.getBlockAttributes?.(targetClientId) || null,
			getBlocks: () => blockEditor.getBlocks?.() || [],
		};
	}, []);

	const {
		recommendations,
		isLoading,
		error,
		status,
		storedContextSignature,
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
		(select) => {
			const store = select(STORE_NAME);
			const blockEditor = select(blockEditorStore);
			const blocks = blockEditor.getBlocks?.() || [];
			const currentBlockPath = findBlockPath(blocks, clientId);
			const activityLog = store.getActivityLog() || [];
			const blockEntries = activityLog.filter(
				(entry) =>
					entry?.surface === 'block' &&
					(entry?.target?.clientId === clientId ||
						blockPathMatches(entry?.target?.blockPath, currentBlockPath))
			);
			const resolvedBlock = blockEditor.getBlock?.(clientId) || null;
			const parentIds = blockEditor.getBlockParents?.(clientId) || [];

			return {
				recommendations: store.getBlockRecommendations(clientId),
				isLoading: store.isBlockLoading(clientId),
				error: store.getBlockError(clientId),
				status: store.getBlockStatus(clientId),
				storedContextSignature:
					store.getBlockRecommendationContextSignature(clientId),
				requestDiagnostics:
					store.getBlockRequestDiagnostics?.(clientId) || null,
				blockActivityLog: blockEntries,
				blockApplyError: store.getBlockApplyError?.(clientId) || null,
				undoError: store.getUndoError(),
				undoStatus: store.getUndoStatus(),
				lastUndoneActivityId: store.getLastUndoneActivityId(),
				editingMode: blockEditor.getBlockEditingMode?.(clientId),
				isInsideContentOnly: parentIds.some(
					(parentId) =>
						blockEditor.getBlockEditingMode?.(parentId) === 'contentOnly'
				),
				block: resolvedBlock,
			};
		},
		[clientId]
	);
	const resolvedBlockActivities = useMemo(
		() =>
			getResolvedActivityEntries(blockActivityLog, (entry) =>
				getBlockActivityUndoState(entry, blockEditorSelection)
			),
		[blockActivityLog, blockEditorSelection]
	);
	const blockActivityEntries = useMemo(
		() => [...resolvedBlockActivities].reverse(),
		[resolvedBlockActivities]
	);
	const latestBlockActivity = useMemo(
		() => getLatestAppliedActivity(resolvedBlockActivities),
		[resolvedBlockActivities]
	);
	const latestUndoableActivityId = useMemo(
		() => getLatestUndoableActivity(resolvedBlockActivities)?.id || null,
		[resolvedBlockActivities]
	);
	const lastUndoneBlockActivity = useMemo(
		() =>
			resolvedBlockActivities.find(
				(entry) => entry?.id === lastUndoneActivityId
			) || null,
		[resolvedBlockActivities, lastUndoneActivityId]
	);

	return {
		canRecommendBlocks,
		recommendations,
		isLoading,
		error,
		status,
		storedContextSignature,
		requestDiagnostics,
		blockActivityEntries,
		latestBlockActivity,
		latestUndoableActivityId,
		lastUndoneBlockActivity,
		blockApplyError,
		undoError,
		undoStatus,
		isDisabled: editingMode === 'disabled',
		isContentRestricted: editingMode === 'contentOnly' || isInsideContentOnly,
		block,
	};
}

function getFeaturedSuggestion(
	executableBlockSuggestions,
	advisoryBlockSuggestions
) {
	if (executableBlockSuggestions.length > 0) {
		return {
			suggestion: executableBlockSuggestions[0],
			tone: 'Apply now',
			why: 'Flavor Agent can safely apply this directly on the current block.',
		};
	}

	if (advisoryBlockSuggestions.length > 0) {
		return {
			suggestion: advisoryBlockSuggestions[0],
			tone: 'Manual ideas',
			why: 'This is the strongest next move, but it still needs manual follow-through.',
		};
	}

	return null;
}

export function BlockRecommendationsContent({
	clientId,
	eyebrow = 'Selected Block',
	introCopy = 'Ask for a specific outcome or fetch recommendations based on the current block context.',
}) {
	const {
		canRecommendBlocks,
		recommendations,
		isLoading,
		error,
		status,
		storedContextSignature,
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
	} = useBlockRecommendationState(clientId);
	const {
		fetchBlockRecommendations,
		clearBlockError,
		clearUndoError,
		undoActivity,
	} = useDispatch(STORE_NAME);
	const [prompt, setPrompt] = useState('');
	const liveContextSignature = useSelect(
		(select) => getLiveBlockContextSignature(select, clientId),
		[clientId]
	);
	const liveContext = useMemo(() => {
		void liveContextSignature;

		return clientId ? collectBlockContext(clientId) : null;
	}, [clientId, liveContextSignature]);
	const hasApplySuccess =
		Boolean(latestBlockActivity) &&
		latestBlockActivity?.id === latestUndoableActivityId;
	const hasUndoSuccess =
		undoStatus === 'success' &&
		lastUndoneBlockActivity?.undo?.status === 'undone';
	const hasFreshResult =
		status === 'ready' &&
		Boolean(recommendations) &&
		(!storedContextSignature ||
			storedContextSignature === liveContextSignature);
	const hasResult = status === 'ready' && Boolean(recommendations);
	const isStaleResult = hasResult && !hasFreshResult;
	const blockSuggestions = hasResult
		? recommendations?.block ?? EMPTY_BLOCK_SUGGESTIONS
		: EMPTY_BLOCK_SUGGESTIONS;
	const hasBlockSuggestions = blockSuggestions.length > 0;
	const { statusNotice } = useSelect((select) => {
		const store = select(STORE_NAME);

		return {
			statusNotice: store.getSurfaceStatusNotice('block', {
				requestError: error,
				applyError: blockApplyError,
				undoError,
				undoStatus,
				hasResult,
				hasSuggestions: hasBlockSuggestions,
				hasSuccess: hasApplySuccess,
				hasUndoSuccess,
				emptyMessage:
					hasFreshResult && !hasBlockSuggestions
						? 'No block suggestions were returned for the current prompt.'
						: '',
				applySuccessMessage: hasApplySuccess
					? `Applied ${latestBlockActivity?.suggestion || 'suggestion'}.`
					: '',
				undoSuccessMessage: hasUndoSuccess
					? `Undid ${lastUndoneBlockActivity?.suggestion || 'suggestion'}.`
					: '',
				onDismissAction: Boolean(error),
				onApplyDismissAction: Boolean(blockApplyError),
				onUndoDismissAction: Boolean(undoError),
			}),
		};
	});
	const { executableBlockSuggestions, advisoryBlockSuggestions } =
		useMemo(() => {
			const blockContext = recommendations?.blockContext || {};
			const executable = [];
			const advisory = [];

			for (const suggestion of blockSuggestions) {
				const execution = getBlockSuggestionExecutionInfo(
					suggestion,
					blockContext
				);

				if (execution.isExecutable) {
					executable.push(suggestion);
				} else {
					advisory.push(suggestion);
				}
			}

			return {
				executableBlockSuggestions: executable,
				advisoryBlockSuggestions: advisory,
			};
		}, [blockSuggestions, recommendations?.blockContext]);
	const featuredSuggestion = useMemo(
		() =>
			isStaleResult
				? null
				: getFeaturedSuggestion(
						executableBlockSuggestions,
						advisoryBlockSuggestions
				  ),
		[advisoryBlockSuggestions, executableBlockSuggestions, isStaleResult]
	);
	const diagnosticActivityEntry = useMemo(() => {
		if (!hasFreshResult || !requestDiagnostics?.hasEmptyBlockResult) {
			return null;
		}

		return {
			id: `block-request-diagnostic:${clientId || 'unknown'}:${
				requestDiagnostics.requestToken || 0
			}`,
			type: 'request_diagnostic',
			surface: 'block',
			suggestion:
				requestDiagnostics.title || 'No block-lane suggestions returned',
			target: {
				clientId,
				blockName:
					requestDiagnostics.blockName ||
					block?.name ||
					recommendations?.blockName ||
					'',
			},
			request: {
				prompt: requestDiagnostics.prompt || recommendations?.prompt || '',
				...(requestDiagnostics.requestMeta
					? {
							ai: requestDiagnostics.requestMeta,
					  }
					: {}),
			},
			diagnostic: {
				detailLines: Array.isArray(requestDiagnostics.detailLines)
					? requestDiagnostics.detailLines
					: [],
				rawCounts: requestDiagnostics.rawCounts || null,
				finalCounts: requestDiagnostics.finalCounts || null,
				reasonCodes: Array.isArray(requestDiagnostics.reasonCodes)
					? requestDiagnostics.reasonCodes
					: [],
			},
			undo: {
				canUndo: false,
				status: 'failed',
				error: null,
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
	]);
	const activitySectionEntries = useMemo(
		() =>
			diagnosticActivityEntry
				? [diagnosticActivityEntry, ...blockActivityEntries]
				: blockActivityEntries,
		[blockActivityEntries, diagnosticActivityEntry]
	);
	const activitySectionDescription = diagnosticActivityEntry
		? 'Recent request diagnostics and applied actions for this block.'
		: 'Undo follows the same latest-valid-action rule used across every executable Flavor Agent surface.';

	useEffect(() => {
		setPrompt('');
	}, [clientId]);

	const handleFetch = useCallback(() => {
		if (!canRecommendBlocks) {
			return;
		}

		if (liveContext) {
			fetchBlockRecommendations(clientId, liveContext, prompt);
		}
	}, [
		canRecommendBlocks,
		clientId,
		fetchBlockRecommendations,
		liveContext,
		prompt,
	]);
	const handleUndo = useCallback(
		(activityId) => {
			undoActivity(activityId);
		},
		[undoActivity]
	);
	const handleRefresh = useCallback(() => {
		if (!canRecommendBlocks || !liveContext) {
			return;
		}

		fetchBlockRecommendations(clientId, liveContext, prompt);
	}, [
		canRecommendBlocks,
		clientId,
		fetchBlockRecommendations,
		liveContext,
		prompt,
	]);
	let dismissStatusNotice;

	if (statusNotice?.source === 'request') {
		dismissStatusNotice = () => clearBlockError(clientId);
	} else if (statusNotice?.source === 'apply') {
		dismissStatusNotice = () => clearBlockError(clientId);
	} else if (statusNotice?.source === 'undo') {
		dismissStatusNotice = clearUndoError;
	}

	if (!clientId || !block || isDisabled) {
		return null;
	}

	return (
		<div className="flavor-agent-panel">
			<SurfacePanelIntro eyebrow={eyebrow} introCopy={introCopy} />

			<SurfaceScopeBar
				scopeLabel={block?.name || ''}
				isFresh={hasFreshResult}
				hasResult={hasResult}
				staleReason={
					isStaleResult
						? 'This block changed after the last request. Refresh before applying anything from the previous result.'
						: ''
				}
				refreshLabel="Refresh"
				onRefresh={isStaleResult ? handleRefresh : undefined}
				isRefreshing={isLoading}
			/>

			{!canRecommendBlocks && <CapabilityNotice surface="block" />}

			{isContentRestricted && (
				<Notice
					status="info"
					isDismissible={false}
					className="flavor-agent-content-notice"
				>
					This block is content-restricted. Only content edits are available.
				</Notice>
			)}

			<SurfaceComposer
				title="Ask Flavor Agent"
				prompt={prompt}
				onPromptChange={setPrompt}
				onFetch={handleFetch}
				placeholder="Describe the outcome you want for this block."
				label="What do you want to improve about this block?"
				rows={3}
				helperText={BLOCK_COMPOSER_HELPER_TEXT}
				starterPrompts={[
					'Improve clarity and spacing',
					'Make this feel more editorial',
					'Simplify the layout',
				]}
				submitHint="Press Cmd/Ctrl+Enter to submit."
				fetchIcon={icon}
				isLoading={isLoading}
				disabled={!canRecommendBlocks}
			/>

			<AIStatusNotice
				notice={statusNotice}
				onAction={
					statusNotice?.actionType === 'undo' && latestBlockActivity
						? () => handleUndo(latestBlockActivity.id)
						: undefined
				}
				onDismiss={dismissStatusNotice}
			/>

			{hasResult && recommendations?.explanation && (
				<p className="flavor-agent-explanation flavor-agent-panel__note">
					{recommendations.explanation}
				</p>
			)}

			{isStaleResult && (
				<RecommendationHero
					title="Refresh recommendations for the current block"
					description="Flavor Agent kept the previous result visible so you can compare it against the current block."
					tone="Stale"
					why="Apply actions stay disabled until you refresh against the live block context."
					primaryActionLabel="Refresh"
					onPrimaryAction={handleRefresh}
					primaryActionDisabled={isLoading}
				/>
			)}

			{featuredSuggestion && (
				<RecommendationHero
					title={
						featuredSuggestion?.suggestion?.label || 'Recommended next change'
					}
					description={featuredSuggestion?.suggestion?.description || ''}
					tone={featuredSuggestion.tone}
					why={featuredSuggestion.why}
				/>
			)}

			{executableBlockSuggestions.length > 0 && (
				<RecommendationLane
					title="Apply now"
					tone={isStaleResult ? 'Stale' : 'Apply now'}
					count={executableBlockSuggestions.length}
					countNoun="suggestion"
					description={
						isStaleResult
							? 'These suggestions are shown for reference from the last request. Refresh before applying them.'
							: 'One-click apply remains available when Flavor Agent can safely change local block attributes.'
					}
				>
					<SuggestionChips
						clientId={clientId}
						suggestions={executableBlockSuggestions}
						label="AI block suggestions"
						disabled={isStaleResult}
					/>
				</RecommendationLane>
			)}

			{advisoryBlockSuggestions.length > 0 && (
				<RecommendationLane
					title="Manual ideas"
					tone="Manual ideas"
					count={advisoryBlockSuggestions.length}
					countNoun="suggestion"
					description="These ideas need manual follow-through or a broader review flow, so Flavor Agent keeps them advisory."
				>
					{advisoryBlockSuggestions.map((suggestion) => (
						<AdvisorySuggestionCard
							key={getSuggestionKey(suggestion)}
							suggestion={suggestion}
						/>
					))}
				</RecommendationLane>
			)}

			<NavigationRecommendations clientId={clientId} embedded />

			<AIActivitySection
				description={activitySectionDescription}
				entries={activitySectionEntries}
				isUndoing={undoStatus === 'undoing'}
				onUndo={handleUndo}
				initialOpen={!hasResult}
				resetKey={clientId || 'block'}
				maxVisible={3}
			/>
		</div>
	);
}

function AdvisorySuggestionCard({ suggestion }) {
	const typeLabel = getAdvisorySuggestionTypeLabel(suggestion);

	return (
		<div className="flavor-agent-card">
			<div className="flavor-agent-card__header flavor-agent-card__header--spaced">
				<div className="flavor-agent-card__lead">
					<span className="flavor-agent-card__label">
						{suggestion?.label || 'Suggestion'}
					</span>
					{typeLabel && (
						<div className="flavor-agent-card__meta">
							<span className="flavor-agent-pill">{typeLabel}</span>
						</div>
					)}
				</div>
			</div>

			{suggestion?.description && (
				<p className="flavor-agent-card__description">
					{suggestion.description}
				</p>
			)}
		</div>
	);
}

function getAdvisorySuggestionTypeLabel(suggestion) {
	switch (suggestion?.type) {
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

export function BlockRecommendationsPanel(props) {
	return (
		<PanelBody title="AI Recommendations" initialOpen={false} icon={icon}>
			<BlockRecommendationsContent {...props} />
		</PanelBody>
	);
}

export function BlockRecommendationsDocumentPanel() {
	const [rememberedClientId, setRememberedClientId] = useState(null);
	const { selectedBlockClientId, selectedBlock } = useSelect((select) => {
		const blockEditor = select(blockEditorStore);
		const clientId = blockEditor.getSelectedBlockClientId?.() || null;

		return {
			selectedBlockClientId: clientId,
			selectedBlock: clientId ? blockEditor.getBlock?.(clientId) : null,
		};
	}, []);
	const rememberedBlock = useSelect(
		(select) => {
			const blockEditor = select(blockEditorStore);

			return rememberedClientId
				? blockEditor.getBlock?.(rememberedClientId) || null
				: null;
		},
		[rememberedClientId]
	);

	useEffect(() => {
		if (selectedBlockClientId && selectedBlock) {
			setRememberedClientId(selectedBlockClientId);
		}
	}, [selectedBlockClientId, selectedBlock]);

	if (selectedBlockClientId || !rememberedClientId || !rememberedBlock) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="flavor-agent-block-recommendations"
			title="AI Recommendations"
		>
			<BlockRecommendationsContent
				clientId={rememberedClientId}
				eyebrow="Last Selected Block"
				introCopy="Saving cleared block selection. Flavor Agent stays scoped to the last block you selected until you choose another block."
			/>
		</PluginDocumentSettingPanel>
	);
}
