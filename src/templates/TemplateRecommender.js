/**
 * Template Recommender
 *
 * Advisory AI template composition suggestions in the Site Editor
 * sidebar. Every block-editor entity the LLM mentions is wired to the
 * correct review surface:
 *
 *   Template-part slugs / areas  →  selectBlock (highlights in canvas,
 *       block inspector shows the template-part controls)
 *   Pattern names                →  opens the block Inserter on the
 *       Patterns tab, pre-filtered to that pattern so the user sees
 *       a live preview and chooses where to insert it
 *
 * Free-form text (explanation, description, reason) is scanned for
 * entity mentions and linked inline with the same type-aware actions.
 */
import { Button, Tooltip } from '@wordpress/components';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';

import AIActivitySection from '../components/AIActivitySection';
import AIAdvisorySection from '../components/AIAdvisorySection';
import AIReviewSection from '../components/AIReviewSection';
import AIStatusNotice from '../components/AIStatusNotice';
import CapabilityNotice from '../components/CapabilityNotice';
import LinkedEntityText from '../components/LinkedEntityText';
import RecommendationHero from '../components/RecommendationHero';
import RecommendationLane from '../components/RecommendationLane';
import SurfaceComposer from '../components/SurfaceComposer';
import SurfacePanelIntro from '../components/SurfacePanelIntro';
import SurfaceScopeBar from '../components/SurfaceScopeBar';
import { STORE_NAME } from '../store';
import {
	getLatestAppliedActivity,
	getLatestUndoableActivity,
	getResolvedActivityEntries,
} from '../store/activity-history';
import { normalizeTemplateType } from '../utils/template-types';
import { getVisiblePatternNames } from '../utils/visible-patterns';
import { getBlockPatterns as getCompatBlockPatterns } from '../patterns/compat';
import {
	getTemplateActivityUndoState,
	openInserterForPattern,
	selectBlockByArea,
	selectBlockBySlugOrArea,
} from '../utils/template-actions';
import {
	buildEntityMap,
	buildEditorTemplateTopLevelStructureSnapshot,
	buildEditorTemplateSlotSnapshot,
	buildTemplateRecommendationContextSignature,
	buildTemplateFetchInput,
	buildTemplateSuggestionViewModel,
	ENTITY_ACTION_BROWSE_PATTERN,
	ENTITY_ACTION_SELECT_AREA,
	ENTITY_ACTION_SELECT_PART,
	formatCount,
	formatTemplateTypeLabel,
	getSuggestionCardKey,
	TEMPLATE_OPERATION_ASSIGN,
	TEMPLATE_OPERATION_INSERT_PATTERN,
	TEMPLATE_OPERATION_REPLACE,
} from './template-recommender-helpers';
import { getSurfaceCapability } from '../utils/capability-flags';
import {
	getEditedPostTypeEntity,
	usePostTypeEntityContract,
} from '../utils/editor-entity-contracts';

function formatBlockLabel(block) {
	if (!block) {
		return 'the template';
	}

	if (block.name === 'core/template-part') {
		return (
			block.attributes?.slug ||
			block.attributes?.area ||
			'current template part'
		);
	}

	return block.name
		? block.name.replace('core/', '').replaceAll('-', ' ')
		: 'the current block';
}

function getBlockByPath(blocks, path = []) {
	let currentBlocks = blocks;
	let block = null;

	for (const index of path) {
		if (!Array.isArray(currentBlocks)) {
			return null;
		}

		block = currentBlocks[index] || null;

		if (!block) {
			return null;
		}

		currentBlocks = block.innerBlocks || [];
	}

	return block;
}

function formatBlockPath(path = []) {
	if (!Array.isArray(path) || path.length === 0) {
		return '';
	}

	return `Path ${path.map((value) => Number(value) + 1).join(' > ')}`;
}

function formatPlacementLabel(placement = '') {
	switch (placement) {
		case 'start':
			return 'Start of template';
		case 'end':
			return 'End of template';
		case 'before_block_path':
			return 'Before target block';
		case 'after_block_path':
			return 'After target block';
		default:
			return '';
	}
}

function describeTemplatePatternPlacement(
	operation,
	templateBlocks,
	insertionPointLabel
) {
	const placement = operation?.placement || '';
	const targetPath = Array.isArray(operation?.targetPath)
		? operation.targetPath
		: null;
	const targetBlock = targetPath
		? getBlockByPath(templateBlocks, targetPath)
		: null;

	if (placement === 'start') {
		return {
			mappingLabel: formatPlacementLabel(placement),
			reason: 'at the start of the template.',
		};
	}

	if (placement === 'end') {
		return {
			mappingLabel: formatPlacementLabel(placement),
			reason: 'at the end of the template.',
		};
	}

	if (placement === 'before_block_path' || placement === 'after_block_path') {
		const relation = placement === 'before_block_path' ? 'before' : 'after';
		const blockLabel = targetBlock
			? formatBlockLabel(targetBlock)
			: 'target block';
		const pathLabel = targetPath ? formatBlockPath(targetPath) : 'target path';

		return {
			mappingLabel: `${formatPlacementLabel(placement)} (${pathLabel})`,
			reason: `${relation} ${blockLabel} at ${pathLabel}.`,
		};
	}

	return {
		mappingLabel: '',
		reason: `${insertionPointLabel}.`,
	};
}

function describeInsertionPoint({ selectedBlock, rootBlock, insertionPoint }) {
	if (selectedBlock) {
		return `after ${formatBlockLabel(selectedBlock)}`;
	}

	if (rootBlock) {
		return `inside ${formatBlockLabel(rootBlock)}`;
	}

	if (Number.isFinite(insertionPoint?.index) && insertionPoint.index === 0) {
		return 'at the start of the template';
	}

	return 'at the end of the template';
}

export default function TemplateRecommender() {
	const canRecommend = getSurfaceCapability('template').available;
	const templateContract = usePostTypeEntityContract('wp_template');
	const templateBlocks = useSelect(
		(select) => select(blockEditorStore)?.getBlocks?.() || [],
		[]
	);
	const templateRef = useSelect(
		(select) =>
			getEditedPostTypeEntity(select, 'wp_template')?.entityId || null,
		[]
	);
	const templateType = normalizeTemplateType(templateRef);
	const {
		recommendations,
		explanation,
		error,
		resultRef,
		resultContextSignature,
		resultToken,
		isLoading,
		status,
		selectedSuggestionKey,
		applyStatus,
		applyError,
		lastAppliedSuggestionKey,
		lastAppliedOperations,
		activityLog,
		undoError,
		undoStatus,
		lastUndoneActivityId,
	} = useSelect((select) => {
		const store = select(STORE_NAME);

		return {
			recommendations: store.getTemplateRecommendations(),
			explanation: store.getTemplateExplanation(),
			error: store.getTemplateError(),
			resultRef: store.getTemplateResultRef(),
			resultContextSignature: store.getTemplateContextSignature(),
			resultToken: store.getTemplateResultToken(),
			isLoading: store.isTemplateLoading(),
			status: store.getTemplateStatus(),
			selectedSuggestionKey: store.getTemplateSelectedSuggestionKey(),
			applyStatus: store.getTemplateApplyStatus(),
			applyError: store.getTemplateApplyError(),
			lastAppliedSuggestionKey: store.getTemplateLastAppliedSuggestionKey(),
			lastAppliedOperations: store.getTemplateLastAppliedOperations(),
			activityLog: store.getActivityLog() || [],
			undoError: store.getUndoError(),
			undoStatus: store.getUndoStatus(),
			lastUndoneActivityId: store.getLastUndoneActivityId(),
		};
	}, []);
	const editorBlocks = useSelect(
		(select) => select(blockEditorStore).getBlocks?.() || [],
		[]
	);
	const blockEditorSelection = useMemo(
		() => ({
			getBlocks: () => editorBlocks,
		}),
		[editorBlocks]
	);
	const resolvedTemplateActivities = useMemo(
		() =>
			getResolvedActivityEntries(
				activityLog.filter(
					(entry) =>
						entry?.surface === 'template' &&
						entry?.target?.templateRef === templateRef
				),
				(entry) => getTemplateActivityUndoState(entry, blockEditorSelection)
			),
		[activityLog, blockEditorSelection, templateRef]
	);
	const templateActivityEntries = useMemo(
		() => [...resolvedTemplateActivities].slice(-3).reverse(),
		[resolvedTemplateActivities]
	);
	const latestTemplateActivity = useMemo(
		() => getLatestAppliedActivity(resolvedTemplateActivities),
		[resolvedTemplateActivities]
	);
	const latestUndoableActivityId = useMemo(
		() => getLatestUndoableActivity(resolvedTemplateActivities)?.id || null,
		[resolvedTemplateActivities]
	);
	const lastUndoneTemplateActivity = useMemo(
		() =>
			resolvedTemplateActivities.find(
				(entry) => entry?.id === lastUndoneActivityId
			) || null,
		[resolvedTemplateActivities, lastUndoneActivityId]
	);
	const patternTitleMap = useSelect(() => {
		const patterns = getCompatBlockPatterns();

		return patterns.reduce((acc, pattern) => {
			if (pattern?.name) {
				acc[pattern.name] = pattern.title || pattern.name;
			}

			return acc;
		}, {});
	}, []);
	const insertionPointLabel = useSelect((select) => {
		const blockEditorStoreSelect = select(blockEditorStore);
		const selectedBlockClientId =
			blockEditorStoreSelect?.getSelectedBlockClientId?.() || null;
		const selectedBlock = selectedBlockClientId
			? blockEditorStoreSelect?.getBlock?.(selectedBlockClientId)
			: null;
		const insertionPoint =
			blockEditorStoreSelect?.getBlockInsertionPoint?.() || null;
		const rootClientId = insertionPoint?.rootClientId || null;
		const rootBlock = rootClientId
			? blockEditorStoreSelect?.getBlock?.(rootClientId)
			: null;

		return describeInsertionPoint({
			selectedBlock,
			rootBlock,
			insertionPoint,
		});
	}, []);
	const visiblePatternNames = useSelect((select) => {
		const blockEditorStoreSelect = select(blockEditorStore);

		return getVisiblePatternNames(null, blockEditorStoreSelect);
	}, []);
	const {
		applyTemplateSuggestion,
		clearUndoError,
		clearTemplateRecommendations,
		fetchTemplateRecommendations,
		setTemplateSelectedSuggestion,
		undoActivity,
	} = useDispatch(STORE_NAME);
	const [prompt, setPrompt] = useState('');
	const previousTemplateRef = useRef(templateRef);
	const editorSlots = useMemo(
		() =>
			Array.isArray(templateBlocks) && templateBlocks.length > 0
				? buildEditorTemplateSlotSnapshot(templateBlocks)
				: null,
		[templateBlocks]
	);
	const editorStructure = useMemo(
		() =>
			Array.isArray(templateBlocks) && templateBlocks.length > 0
				? buildEditorTemplateTopLevelStructureSnapshot(templateBlocks)
				: null,
		[templateBlocks]
	);
	const recommendationContextSignature = useMemo(
		() =>
			buildTemplateRecommendationContextSignature({
				editorSlots,
				editorStructure,
				visiblePatternNames,
			}),
		[editorSlots, editorStructure, visiblePatternNames]
	);
	const currentPatternOverrideCount =
		editorStructure?.currentPatternOverrides?.blockCount || 0;
	const currentVisibilityConstraintCount =
		editorStructure?.currentViewportVisibility?.blockCount || 0;
	const previousRecommendationContextSignature = useRef(
		recommendationContextSignature
	);
	const hasStoredResultForTemplate = resultRef === templateRef;
	const hasCurrentContext =
		!resultContextSignature ||
		resultContextSignature === recommendationContextSignature;
	const hasMatchingResult =
		hasStoredResultForTemplate && status === 'ready' && hasCurrentContext;
	const isStaleResult = hasStoredResultForTemplate && !hasCurrentContext;
	const visibleRecommendations = useMemo(
		() => (hasMatchingResult || isStaleResult ? recommendations : []),
		[hasMatchingResult, isStaleResult, recommendations]
	);
	const hasResult = hasMatchingResult || isStaleResult;
	const hasSuggestions = visibleRecommendations.length > 0;

	useEffect(() => {
		const templateChanged = previousTemplateRef.current !== templateRef;
		const recommendationContextChanged =
			previousRecommendationContextSignature.current !==
			recommendationContextSignature;

		if (!templateChanged && !recommendationContextChanged) {
			return;
		}

		previousTemplateRef.current = templateRef;
		previousRecommendationContextSignature.current =
			recommendationContextSignature;

		if (!templateChanged) {
			return;
		}

		clearTemplateRecommendations();

		if (templateChanged) {
			setPrompt('');
		}
	}, [
		clearTemplateRecommendations,
		recommendationContextSignature,
		templateRef,
	]);

	const entityMap = useMemo(
		() => buildEntityMap(visibleRecommendations, patternTitleMap),
		[visibleRecommendations, patternTitleMap]
	);
	const suggestionCards = useMemo(
		() =>
			visibleRecommendations.map((suggestion, index) =>
				buildTemplateSuggestionViewModel(
					{
						...suggestion,
						suggestionKey: getSuggestionCardKey(suggestion, index),
					},
					patternTitleMap
				)
			),
		[visibleRecommendations, patternTitleMap]
	);
	const executableSuggestionCards = useMemo(
		() => suggestionCards.filter((suggestion) => suggestion.canApply),
		[suggestionCards]
	);
	const advisorySuggestionCards = useMemo(
		() => suggestionCards.filter((suggestion) => !suggestion.canApply),
		[suggestionCards]
	);
	const selectedSuggestion = useMemo(
		() =>
			executableSuggestionCards.find(
				(suggestion) => suggestion.suggestionKey === selectedSuggestionKey
			) || null,
		[executableSuggestionCards, selectedSuggestionKey]
	);
	const hasApplySuccess =
		applyStatus === 'success' &&
		lastAppliedSuggestionKey &&
		lastAppliedOperations.length > 0 &&
		latestTemplateActivity &&
		latestTemplateActivity.id === latestUndoableActivityId;
	const hasUndoSuccess =
		undoStatus === 'success' &&
		lastUndoneTemplateActivity?.undo?.status === 'undone';
	const statusNotice = useSelect(
		(select) => {
			const store = select(STORE_NAME);

			return store.getSurfaceStatusNotice('template', {
				requestStatus: status,
				requestError: error,
				isStale: isStaleResult,
				applyError,
				undoError,
				undoStatus,
				applyStatus,
				hasResult,
				hasSuggestions,
				hasPreview: Boolean(selectedSuggestionKey),
				hasSuccess: Boolean(hasApplySuccess),
				hasUndoSuccess,
				applySuccessMessage: hasApplySuccess
					? `Applied ${formatCount(
							lastAppliedOperations.length,
							'template operation'
					  )}.`
					: '',
				undoSuccessMessage: hasUndoSuccess
					? `Undid ${lastUndoneTemplateActivity?.suggestion || 'suggestion'}.`
					: '',
				onUndoDismissAction: Boolean(undoError),
			});
		},
		[
			applyError,
			applyStatus,
			error,
			hasApplySuccess,
			hasResult,
			hasSuggestions,
			isStaleResult,
			lastAppliedOperations,
			lastUndoneTemplateActivity,
			selectedSuggestionKey,
			status,
			undoError,
			undoStatus,
			hasUndoSuccess,
		]
	);
	const showSecondaryGuidance =
		!hasResult &&
		templateActivityEntries.length === 0 &&
		!selectedSuggestionKey;
	const featuredSuggestionCard = isStaleResult
		? null
		: executableSuggestionCards[0] || advisorySuggestionCards[0] || null;

	const handleFetch = useCallback(() => {
		if (!canRecommend) {
			return;
		}

		fetchTemplateRecommendations(
			buildTemplateFetchInput({
				templateRef,
				templateType,
				prompt,
				editorSlots,
				editorStructure,
				visiblePatternNames,
				contextSignature: recommendationContextSignature,
			})
		);
	}, [
		canRecommend,
		editorSlots,
		editorStructure,
		fetchTemplateRecommendations,
		prompt,
		recommendationContextSignature,
		templateRef,
		templateType,
		visiblePatternNames,
	]);

	const handleEntityAction = useCallback((entity) => {
		switch (entity?.actionType) {
			case ENTITY_ACTION_SELECT_PART:
				selectBlockBySlugOrArea(entity.slug, entity.area);
				break;
			case ENTITY_ACTION_SELECT_AREA:
				selectBlockByArea(entity.area);
				break;
			case ENTITY_ACTION_BROWSE_PATTERN:
				openInserterForPattern(entity.filterValue || entity.name);
				break;
		}
	}, []);
	const handlePreviewSuggestion = useCallback(
		(suggestionKey) => {
			setTemplateSelectedSuggestion(suggestionKey);
		},
		[setTemplateSelectedSuggestion]
	);
	const handleCancelPreview = useCallback(() => {
		setTemplateSelectedSuggestion(null);
	}, [setTemplateSelectedSuggestion]);
	const handleApplySuggestion = useCallback(
		(suggestion, currentContextSignature) => {
			applyTemplateSuggestion(suggestion, currentContextSignature);
		},
		[applyTemplateSuggestion]
	);
	const handleUndo = useCallback(
		(activityId) => {
			undoActivity(activityId);
		},
		[undoActivity]
	);

	if (
		!templateRef ||
		!templateContract.hasConfig ||
		!templateContract.titleField
	) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="flavor-agent-template-recommendations"
			title="AI Template Recommendations"
		>
			<div className="flavor-agent-panel">
				<SurfacePanelIntro
					eyebrow={formatTemplateTypeLabel(templateType)}
					introCopy="Describe the structure or layout you want. Review each suggested template-part change or pattern insertion, then confirm before Flavor Agent mutates the template."
					meta={
						<>
							{currentPatternOverrideCount > 0 && (
								<span className="flavor-agent-pill">
									{formatCount(
										currentPatternOverrideCount,
										'override-ready block'
									)}
								</span>
							)}
							{currentVisibilityConstraintCount > 0 && (
								<span className="flavor-agent-pill">
									{formatCount(
										currentVisibilityConstraintCount,
										'viewport constraint'
									)}
								</span>
							)}
						</>
					}
				/>

				<SurfaceScopeBar
					scopeLabel={formatTemplateTypeLabel(templateType)}
					scopeDetails={templateRef ? [templateRef] : []}
					isFresh={hasMatchingResult}
					hasResult={hasResult}
					staleReason={
						isStaleResult
							? 'This template changed after the last request. Refresh before reviewing or applying anything from the previous result.'
							: ''
					}
					onRefresh={isStaleResult ? handleFetch : undefined}
					isRefreshing={isLoading}
				/>

				{!canRecommend && <CapabilityNotice surface="template" />}

				{canRecommend && (
					<SurfaceComposer
						title="Ask Flavor Agent"
						prompt={prompt}
						onPromptChange={setPrompt}
						onFetch={handleFetch}
						placeholder="Describe the structure or layout you want."
						label="What are you trying to achieve with this template?"
						helperText="Flavor Agent keeps executable template suggestions bounded to validated template-part assignments and pattern insertions."
						starterPrompts={[
							'Strengthen the page hierarchy',
							'Create a clearer opening section',
							'Balance the template structure',
						]}
						submitHint="Press Cmd/Ctrl+Enter to submit."
						isLoading={isLoading}
					/>
				)}

				{canRecommend && isLoading && (
					<AIStatusNotice
						notice={{
							tone: 'info',
							message: 'Analyzing template structure…',
						}}
					/>
				)}

				<AIStatusNotice
					notice={statusNotice}
					onAction={
						statusNotice?.actionType === 'undo' && latestTemplateActivity
							? () => handleUndo(latestTemplateActivity.id)
							: undefined
					}
					onDismiss={
						statusNotice?.source === 'undo' ? clearUndoError : undefined
					}
				/>

				{canRecommend && hasResult && explanation && (
					<p className="flavor-agent-explanation flavor-agent-panel__note">
						<LinkedEntityText
							text={explanation}
							entities={entityMap}
							onEntityClick={handleEntityAction}
						/>
					</p>
				)}

				{canRecommend && featuredSuggestionCard && (
					<RecommendationHero
						title={
							featuredSuggestionCard.label || 'Recommended template change'
						}
						description={featuredSuggestionCard.description || ''}
						tone={
							featuredSuggestionCard.canApply ? 'Review first' : 'Manual ideas'
						}
						why={
							featuredSuggestionCard.canApply
								? 'Flavor Agent validated a deterministic operation sequence for this suggestion, so review it before applying.'
								: 'This is the strongest next idea, but it still needs manual follow-through.'
						}
					/>
				)}

				{canRecommend && executableSuggestionCards.length > 0 && (
					<RecommendationLane
						title="Review first"
						tone="Review first"
						count={executableSuggestionCards.length}
						countNoun="suggestion"
						description="Preview the validated operations below before Flavor Agent mutates the template."
					>
						{executableSuggestionCards.map((suggestion, index) => (
							<TemplateSuggestionCard
								key={`${resultToken}-${getSuggestionCardKey(
									suggestion,
									index
								)}`}
								suggestion={suggestion}
								entityMap={entityMap}
								isApplied={
									lastAppliedSuggestionKey === suggestion.suggestionKey
								}
								isApplying={applyStatus === 'applying'}
								isStale={isStaleResult}
								isSelected={selectedSuggestionKey === suggestion.suggestionKey}
								onEntityClick={handleEntityAction}
								onPreviewSuggestion={handlePreviewSuggestion}
							/>
						))}
					</RecommendationLane>
				)}

				{canRecommend && advisorySuggestionCards.length > 0 && (
					<AIAdvisorySection
						title="Manual ideas"
						count={advisorySuggestionCards.length}
						countNoun="suggestion"
						initialOpen
						advisoryLabel=""
						description={
							showSecondaryGuidance
								? 'These ideas stay visible for review, but Flavor Agent could not validate a deterministic structural mutation for them.'
								: ''
						}
					>
						{advisorySuggestionCards.map((suggestion, index) => (
							<TemplateSuggestionCard
								key={`advisory-${resultToken}-${getSuggestionCardKey(
									suggestion,
									index
								)}`}
								suggestion={suggestion}
								entityMap={entityMap}
								isApplied={
									lastAppliedSuggestionKey === suggestion.suggestionKey
								}
								isApplying={applyStatus === 'applying'}
								isStale={isStaleResult}
								isSelected={selectedSuggestionKey === suggestion.suggestionKey}
								onEntityClick={handleEntityAction}
								onPreviewSuggestion={handlePreviewSuggestion}
							/>
						))}
					</AIAdvisorySection>
				)}

				{canRecommend && selectedSuggestion && (
					<AIReviewSection
						title="Review Before Apply"
						statusLabel="Review first"
						count={selectedSuggestion.operations?.length || 0}
						countNoun="operation"
						summary={
							selectedSuggestion.description ||
							'Review the validated structural operations below before Flavor Agent mutates the template.'
						}
						hint={
							isStaleResult
								? 'This preview is stale. Refresh recommendations before applying these operations.'
								: 'Pattern insertions use the validated template structure shown below, and Flavor Agent will refuse to apply them if that target has drifted.'
						}
						confirmLabel={
							applyStatus === 'applying' ? 'Applying…' : 'Confirm Apply'
						}
						confirmDisabled={applyStatus === 'applying' || isStaleResult}
						onConfirm={() =>
							handleApplySuggestion(
								selectedSuggestion,
								recommendationContextSignature
							)
						}
						onCancel={handleCancelPreview}
						className="flavor-agent-template-review"
					>
						{selectedSuggestion.operations.map((operation) => (
							<TemplateOperationPreviewRow
								key={operation.key}
								insertionPointLabel={insertionPointLabel}
								operation={operation}
								templateBlocks={templateBlocks}
							/>
						))}
					</AIReviewSection>
				)}

				<AIActivitySection
					description="Template actions use the same latest-valid undo rule as the block review surface."
					entries={templateActivityEntries}
					isUndoing={undoStatus === 'undoing'}
					onUndo={handleUndo}
					initialOpen={!hasResult || !canRecommend}
					resetKey={templateRef || 'template'}
					maxVisible={3}
				/>
			</div>
		</PluginDocumentSettingPanel>
	);
}

function TemplateSuggestionCard({
	suggestion,
	entityMap = [],
	isApplied = false,
	isApplying = false,
	isStale = false,
	isSelected = false,
	onEntityClick,
	onPreviewSuggestion,
}) {
	const hasParts = suggestion.templateParts?.length > 0;
	const hasPatterns = suggestion.patternSuggestions?.length > 0;
	const summaryParts = [];

	if (hasParts) {
		summaryParts.push(formatCount(suggestion.templateParts.length, 'part'));
	}

	if (hasPatterns) {
		summaryParts.push(
			formatCount(suggestion.patternSuggestions.length, 'pattern')
		);
	}

	return (
		<div
			className={`flavor-agent-card flavor-agent-card--template${
				isApplied ? ' is-applied' : ''
			}${isSelected ? ' is-review-selected' : ''}`}
		>
			<div className="flavor-agent-card__header flavor-agent-card__header--spaced">
				<div className="flavor-agent-card__lead">
					<div className="flavor-agent-card__label">{suggestion.label}</div>
					<div className="flavor-agent-card__meta">
						<span className="flavor-agent-pill">
							{suggestion.canApply ? 'Review first' : 'Manual ideas'}
						</span>
						{summaryParts.length > 0 && (
							<span className="flavor-agent-pill">
								{summaryParts.join(' • ')}
							</span>
						)}
						{isSelected && (
							<span className="flavor-agent-pill flavor-agent-pill--success">
								Review open
							</span>
						)}
						{isApplied && (
							<span className="flavor-agent-done-badge">Applied</span>
						)}
					</div>
				</div>

				{suggestion.canApply && (
					<Button
						size="small"
						variant={isSelected ? 'secondary' : 'primary'}
						onClick={() => onPreviewSuggestion(suggestion.suggestionKey)}
						className="flavor-agent-card__apply"
						disabled={isApplying || isStale}
					>
						{isSelected ? 'Reviewing' : 'Review'}
					</Button>
				)}
			</div>

			{suggestion.description && (
				<p className="flavor-agent-card__description">
					<LinkedEntityText
						text={suggestion.description}
						entities={entityMap}
						onEntityClick={onEntityClick}
					/>
				</p>
			)}

			{suggestion.executionError && (
				<p className="flavor-agent-card__description">
					{suggestion.executionError}
				</p>
			)}

			{hasParts && (
				<div className="flavor-agent-template-list">
					<div className="flavor-agent-template-list__header">
						<div className="flavor-agent-section-label">Template Parts</div>
						<span className="flavor-agent-pill">
							{formatCount(suggestion.templateParts.length, 'part')}
						</span>
					</div>
					{suggestion.templateParts.map((part) => (
						<div key={part.key} className="flavor-agent-tpl-row">
							<span className="flavor-agent-tpl-row__mapping">
								<Tooltip text={`Select “${part.slug}” block in editor`}>
									<Button
										size="small"
										variant="link"
										onClick={() =>
											selectBlockBySlugOrArea(part.slug, part.area)
										}
										className="flavor-agent-action-link flavor-agent-action-link--part"
									>
										{part.slug}
									</Button>
								</Tooltip>

								<span className="flavor-agent-tpl-row__arrow">→</span>

								<Tooltip text={`Select “${part.area}” area in editor`}>
									<Button
										size="small"
										variant="link"
										onClick={() => selectBlockByArea(part.area)}
										className="flavor-agent-action-link flavor-agent-action-link--area"
									>
										{part.area}
									</Button>
								</Tooltip>
							</span>

							<span className="flavor-agent-pill">{part.ctaLabel}</span>

							{part.reason && (
								<div className="flavor-agent-tpl-row__reason">
									<LinkedEntityText
										text={part.reason}
										entities={entityMap}
										onEntityClick={onEntityClick}
									/>
								</div>
							)}
						</div>
					))}
				</div>
			)}

			{hasPatterns && (
				<div className="flavor-agent-template-list">
					<div className="flavor-agent-template-list__header">
						<div className="flavor-agent-section-label">Suggested Patterns</div>
						<span className="flavor-agent-pill">
							{formatCount(suggestion.patternSuggestions.length, 'pattern')}
						</span>
					</div>
					{suggestion.patternSuggestions.map((pattern) => (
						<div key={pattern.name} className="flavor-agent-tpl-row">
							<Tooltip text={`Browse “${pattern.title}” in pattern inserter`}>
								<Button
									size="small"
									variant="link"
									onClick={() => openInserterForPattern(pattern.title)}
									className="flavor-agent-action-link flavor-agent-action-link--pattern"
								>
									{pattern.title}
								</Button>
							</Tooltip>

							<Button
								size="small"
								variant="tertiary"
								onClick={() => openInserterForPattern(pattern.title)}
								className="flavor-agent-assign-btn"
							>
								{pattern.ctaLabel}
							</Button>
						</div>
					))}
				</div>
			)}
		</div>
	);
}

function TemplateOperationPreviewRow({
	operation,
	insertionPointLabel,
	templateBlocks = [],
}) {
	switch (operation.type) {
		case TEMPLATE_OPERATION_ASSIGN:
			return (
				<div className="flavor-agent-tpl-row">
					<span className="flavor-agent-tpl-row__mapping">
						<span className="flavor-agent-preview-token flavor-agent-preview-token--part">
							{operation.slug}
						</span>
						<span className="flavor-agent-tpl-row__arrow">→</span>
						<span className="flavor-agent-preview-token flavor-agent-preview-token--area">
							{operation.area}
						</span>
					</span>
					<span className="flavor-agent-pill">{operation.badgeLabel}</span>
					<div className="flavor-agent-tpl-row__reason">
						Assign <code>{operation.slug}</code> to the{' '}
						<code>{operation.area}</code> area.
					</div>
				</div>
			);

		case TEMPLATE_OPERATION_REPLACE:
			return (
				<div className="flavor-agent-tpl-row">
					<span className="flavor-agent-tpl-row__mapping">
						<span className="flavor-agent-preview-token flavor-agent-preview-token--part">
							{operation.currentSlug}
						</span>
						<span className="flavor-agent-tpl-row__arrow">→</span>
						<span className="flavor-agent-preview-token flavor-agent-preview-token--part">
							{operation.slug}
						</span>
					</span>
					<span className="flavor-agent-pill">{operation.badgeLabel}</span>
					<div className="flavor-agent-tpl-row__reason">
						Replace the current <code>{operation.currentSlug}</code> template
						part in the <code>{operation.area}</code> area with{' '}
						<code>{operation.slug}</code>.
					</div>
				</div>
			);

		case TEMPLATE_OPERATION_INSERT_PATTERN: {
			const placementDetails = describeTemplatePatternPlacement(
				operation,
				templateBlocks,
				insertionPointLabel
			);

			return (
				<div className="flavor-agent-tpl-row">
					<span className="flavor-agent-tpl-row__mapping">
						<span className="flavor-agent-preview-token flavor-agent-preview-token--pattern">
							{operation.patternTitle}
						</span>
						{placementDetails.mappingLabel && (
							<>
								<span className="flavor-agent-tpl-row__arrow">→</span>
								<span className="flavor-agent-preview-token flavor-agent-preview-token--area">
									{placementDetails.mappingLabel}
								</span>
							</>
						)}
					</span>
					<span className="flavor-agent-pill">{operation.badgeLabel}</span>
					<div className="flavor-agent-tpl-row__reason">
						Insert <code>{operation.patternTitle}</code>{' '}
						{placementDetails.reason}
					</div>
				</div>
			);
		}
	}

	return null;
}
