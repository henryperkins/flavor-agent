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
import {
	MANUAL_IDEAS_LABEL,
	REFRESH_ACTION_LABEL,
	REVIEW_LANE_LABEL,
	REVIEW_SECTION_TITLE,
	STALE_STATUS_LABEL,
} from '../components/surface-labels';
import { getBlockPatterns as getCompatBlockPatterns } from '../patterns/compat';
import { STORE_NAME } from '../store';
import {
	getLatestAppliedActivity,
	getLatestUndoableActivity,
	getResolvedActivityEntries,
} from '../store/activity-history';
import {
	getTemplatePartActivityUndoState,
	openInserterForPattern,
	selectBlockByPath,
} from '../utils/template-actions';
import { getVisiblePatternNames } from '../utils/visible-patterns';
import {
	TEMPLATE_OPERATION_INSERT_PATTERN,
	TEMPLATE_OPERATION_REMOVE_BLOCK,
	TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN,
	TEMPLATE_PART_PLACEMENT_AFTER_BLOCK_PATH,
	TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH,
	validateTemplatePartOperationSequence,
} from '../utils/template-operation-sequence';
import { getTemplatePartAreaLookup } from '../utils/template-part-areas';
import { getSurfaceCapability } from '../utils/capability-flags';
import { formatCount } from '../utils/format-count';
import {
	getEditedPostTypeEntity,
	usePostTypeEntityContract,
} from '../utils/editor-entity-contracts';
import { buildTemplatePartRecommendationRequestSignature } from '../utils/recommendation-request-signature';
import {
	buildEditorTemplatePartStructureSnapshot,
	buildTemplatePartFetchInput,
	buildTemplatePartRecommendationContextSignature,
} from './template-part-recommender-helpers';

const ENTITY_ACTION_BROWSE_PATTERN = 'browse-pattern';
const ENTITY_ACTION_SELECT_BLOCK_HINT = 'select-block-hint';

function normalizeTemplatePartSlug(templatePartRef) {
	if (typeof templatePartRef !== 'string' || templatePartRef === '') {
		return '';
	}

	return templatePartRef.includes('//')
		? templatePartRef.slice(templatePartRef.indexOf('//') + 2)
		: templatePartRef;
}

function humanizeLabel(value) {
	if (!value) {
		return '';
	}

	return value
		.split(/[-_]/)
		.filter(Boolean)
		.map((part) => part.charAt(0).toUpperCase() + part.slice(1))
		.join(' ');
}

function formatTemplatePartLabel(slug, area) {
	return `${humanizeLabel(area || slug || 'Current')} Template Part`;
}

function formatBlockPath(path = []) {
	if (!Array.isArray(path) || path.length === 0) {
		return '';
	}

	return `Path ${path.map((value) => Number(value) + 1).join(' > ')}`;
}

function deriveTemplatePartArea(
	slug,
	areaLookup = getTemplatePartAreaLookup(),
	knownAreas = []
) {
	if (typeof areaLookup?.[slug] === 'string' && areaLookup[slug]) {
		return areaLookup[slug];
	}

	if (Array.isArray(knownAreas) && knownAreas.includes(slug)) {
		return slug;
	}

	return '';
}

function getSuggestionCardKey(suggestion = {}, index) {
	return `${suggestion.label || 'suggestion'}-${index}`;
}

function getOperationKey(operation = {}) {
	return `${operation?.type || 'operation'}|${operation?.patternName || ''}|${
		operation?.placement || ''
	}|${
		Array.isArray(operation?.targetPath) ? operation.targetPath.join('.') : ''
	}|${operation?.expectedBlockName || ''}|${
		operation?.expectedTarget?.name || ''
	}`;
}

function formatPlacementLabel(placement) {
	if (placement === 'start') {
		return 'Start of this template part';
	}

	if (placement === 'end') {
		return 'End of this template part';
	}

	if (placement === TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH) {
		return 'Before target block';
	}

	return 'After target block';
}

function formatBlockNameLabel(blockName = '') {
	if (!blockName) {
		return 'block';
	}

	const normalized = blockName.includes('/')
		? blockName.split('/')[1]
		: blockName;

	return humanizeLabel(normalized) || blockName;
}

function formatTargetPathLabel(path = []) {
	return Array.isArray(path) && path.length > 0
		? `Target ${formatBlockPath(path)}`
		: 'Target block';
}

function buildTemplatePartSuggestionViewModel(
	suggestion = {},
	patternTitleMap = {}
) {
	const blockHints = Array.isArray(suggestion?.blockHints)
		? suggestion.blockHints
		: [];
	const rawPatternSuggestions = Array.isArray(suggestion?.patternSuggestions)
		? suggestion.patternSuggestions
		: [];
	const rawOperations = Array.isArray(suggestion?.operations)
		? suggestion.operations
		: [];
	const executableOperations =
		rawOperations.length > 0
			? validateTemplatePartOperationSequence(rawOperations)
			: { ok: true, operations: [] };
	const operations = executableOperations.ok
		? executableOperations.operations
				.map((operation) => {
					switch (operation?.type) {
						case TEMPLATE_OPERATION_INSERT_PATTERN:
							return {
								key: getOperationKey(operation),
								type: TEMPLATE_OPERATION_INSERT_PATTERN,
								patternName: operation.patternName,
								patternTitle:
									patternTitleMap[operation.patternName] ||
									operation.patternName,
								placement: operation.placement,
								targetPath: Array.isArray(operation.targetPath)
									? operation.targetPath
									: null,
								badgeLabel: 'Insert',
							};

						case TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN:
							return {
								key: getOperationKey(operation),
								type: TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN,
								patternName: operation.patternName,
								patternTitle:
									patternTitleMap[operation.patternName] ||
									operation.patternName,
								expectedBlockName: operation.expectedBlockName,
								targetPath: operation.targetPath,
								badgeLabel: 'Replace',
							};

						case TEMPLATE_OPERATION_REMOVE_BLOCK:
							return {
								key: getOperationKey(operation),
								type: TEMPLATE_OPERATION_REMOVE_BLOCK,
								expectedBlockName: operation.expectedBlockName,
								targetPath: operation.targetPath,
								badgeLabel: 'Remove',
							};

						default:
							return null;
					}
				})
				.filter(Boolean)
		: [];
	const mergedPatternSuggestions = Array.from(
		new Set(
			[
				...rawPatternSuggestions,
				...operations.map((operation) => operation.patternName).filter(Boolean),
			].filter(Boolean)
		)
	).map((patternName) => ({
		name: patternName,
		title: patternTitleMap[patternName] || patternName,
		ctaLabel: 'Browse pattern',
	}));

	return {
		suggestionKey: suggestion?.suggestionKey || '',
		label: suggestion?.label || '',
		description: suggestion?.description || '',
		blockHints,
		patternSuggestions: mergedPatternSuggestions,
		operations,
		executionError:
			rawOperations.length > 0 && !executableOperations.ok
				? executableOperations.error || ''
				: '',
		canApply:
			rawOperations.length > 0 &&
			executableOperations.ok &&
			operations.length > 0,
	};
}

function buildTemplatePartEntityMap(suggestions = []) {
	const entities = [];
	const seen = new Set();

	for (const suggestion of suggestions) {
		const blockHints = Array.isArray(suggestion?.blockHints)
			? suggestion.blockHints
			: [];
		const patternSuggestions = Array.isArray(suggestion?.patternSuggestions)
			? suggestion.patternSuggestions
			: [];

		for (const hint of blockHints) {
			if (
				typeof hint?.label !== 'string' ||
				!hint.label ||
				!Array.isArray(hint?.path)
			) {
				continue;
			}

			const key = `hint:${hint.label}:${hint.path.join('.')}`;
			if (seen.has(key)) {
				continue;
			}

			seen.add(key);
			entities.push({
				action: ENTITY_ACTION_SELECT_BLOCK_HINT,
				path: hint.path,
				text: hint.label,
				tooltip: `Select “${hint.label}” in editor`,
				type: 'block',
			});
		}

		for (const pattern of patternSuggestions) {
			if (typeof pattern?.title !== 'string' || !pattern.title) {
				continue;
			}

			const key = `pattern:${pattern.name || pattern.title}`;
			if (seen.has(key)) {
				continue;
			}

			seen.add(key);
			entities.push({
				action: ENTITY_ACTION_BROWSE_PATTERN,
				filterValue: pattern.title,
				text: pattern.title,
				tooltip: `Browse pattern “${pattern.title}”`,
				type: 'pattern',
			});
		}
	}

	return entities.sort((left, right) => right.text.length - left.text.length);
}

export default function TemplatePartRecommender() {
	const canRecommend = getSurfaceCapability('template-part').available;
	const templatePartContract = usePostTypeEntityContract('wp_template_part');
	const templatePartRef = useSelect(
		(select) =>
			getEditedPostTypeEntity(select, 'wp_template_part')?.entityId || null,
		[]
	);
	const {
		recommendations,
		explanation,
		error,
		resultPrompt,
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
		storedStaleReason,
		activityLog,
		undoError,
		undoStatus,
		lastUndoneActivityId,
	} = useSelect((select) => {
		const store = select(STORE_NAME);

		return {
			recommendations: store.getTemplatePartRecommendations(),
			explanation: store.getTemplatePartExplanation(),
			error: store.getTemplatePartError(),
			resultPrompt: store.getTemplatePartRequestPrompt?.() || '',
			resultRef: store.getTemplatePartResultRef(),
			resultContextSignature: store.getTemplatePartContextSignature(),
			resultToken: store.getTemplatePartResultToken(),
			isLoading: store.isTemplatePartLoading(),
			status: store.getTemplatePartStatus(),
			selectedSuggestionKey: store.getTemplatePartSelectedSuggestionKey(),
			applyStatus: store.getTemplatePartApplyStatus(),
			applyError: store.getTemplatePartApplyError(),
			lastAppliedSuggestionKey: store.getTemplatePartLastAppliedSuggestionKey(),
			lastAppliedOperations: store.getTemplatePartLastAppliedOperations(),
			storedStaleReason: store.getTemplatePartStaleReason?.() || null,
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
	const resolvedTemplatePartActivities = useMemo(
		() =>
			getResolvedActivityEntries(
				activityLog.filter(
					(entry) =>
						entry?.surface === 'template-part' &&
						entry?.target?.templatePartRef === templatePartRef
				),
				(entry) => getTemplatePartActivityUndoState(entry, blockEditorSelection)
			),
		[activityLog, blockEditorSelection, templatePartRef]
	);
	const templatePartActivityEntries = useMemo(
		() => [...resolvedTemplatePartActivities].slice(-3).reverse(),
		[resolvedTemplatePartActivities]
	);
	const latestTemplatePartActivity = useMemo(
		() => getLatestAppliedActivity(resolvedTemplatePartActivities),
		[resolvedTemplatePartActivities]
	);
	const latestUndoableActivityId = useMemo(
		() => getLatestUndoableActivity(resolvedTemplatePartActivities)?.id || null,
		[resolvedTemplatePartActivities]
	);
	const lastUndoneTemplatePartActivity = useMemo(
		() =>
			resolvedTemplatePartActivities.find(
				(entry) => entry?.id === lastUndoneActivityId
			) || null,
		[resolvedTemplatePartActivities, lastUndoneActivityId]
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
	const visiblePatternNames = useSelect((select) => {
		const blockEditorStoreSelect = select(blockEditorStore);

		return getVisiblePatternNames(null, blockEditorStoreSelect);
	}, []);
	const {
		applyTemplatePartSuggestion,
		clearTemplatePartRecommendations,
		clearUndoError,
		fetchTemplatePartRecommendations,
		setTemplatePartApplyState,
		setTemplatePartSelectedSuggestion,
		setTemplatePartStatus,
		undoActivity,
	} = useDispatch(STORE_NAME);
	const [prompt, setPrompt] = useState('');
	const hydratedResultKeyRef = useRef(null);
	const previousTemplatePartRef = useRef(templatePartRef);
	const templatePartAreaLookup = useMemo(() => getTemplatePartAreaLookup(), []);
	const editorStructure = useMemo(
		() =>
			buildEditorTemplatePartStructureSnapshot(
				editorBlocks,
				templatePartAreaLookup
			),
		[editorBlocks, templatePartAreaLookup]
	);
	const currentPatternOverrides =
		editorStructure?.currentPatternOverrides || null;
	const recommendationContextSignature = useMemo(
		() =>
			buildTemplatePartRecommendationContextSignature({
				visiblePatternNames,
				editorStructure,
			}),
		[editorStructure, visiblePatternNames]
	);
	const previousRecommendationContextSignature = useRef(
		recommendationContextSignature
	);

	const slug = useMemo(
		() => normalizeTemplatePartSlug(templatePartRef),
		[templatePartRef]
	);
	const contractAreaValues = useMemo(
		() =>
			Array.isArray(templatePartContract.templatePartAreaOptions)
				? templatePartContract.templatePartAreaOptions.map(
						(option) => option.value
				  )
				: [],
		[templatePartContract.templatePartAreaOptions]
	);
	const area = useMemo(
		() => deriveTemplatePartArea(slug, undefined, contractAreaValues),
		[slug, contractAreaValues]
	);
	const areaLabel = useMemo(
		() =>
			templatePartContract.templatePartAreaLabels?.[area] ||
			humanizeLabel(area),
		[templatePartContract.templatePartAreaLabels, area]
	);
	const hasStoredResultForTemplatePart = resultRef === templatePartRef;
	const recommendationRequestSignature = useMemo(
		() =>
			buildTemplatePartRecommendationRequestSignature({
				templatePartRef,
				prompt,
				contextSignature: recommendationContextSignature,
			}),
		[templatePartRef, prompt, recommendationContextSignature]
	);
	const currentRequestInput = useMemo(
		() =>
			buildTemplatePartFetchInput({
				templatePartRef,
				prompt,
				visiblePatternNames,
				editorStructure,
				contextSignature: recommendationContextSignature,
			}),
		[
			editorStructure,
			prompt,
			recommendationContextSignature,
			templatePartRef,
			visiblePatternNames,
		]
	);
	const resultRequestSignature = useMemo(
		() =>
			buildTemplatePartRecommendationRequestSignature({
				templatePartRef: resultRef,
				prompt: resultPrompt,
				contextSignature: resultContextSignature,
			}),
		[resultContextSignature, resultPrompt, resultRef]
	);
	const clientStaleReason =
		hasStoredResultForTemplatePart &&
		resultRequestSignature !== recommendationRequestSignature
			? 'client'
			: null;
	const effectiveStaleReason =
		clientStaleReason ||
		(storedStaleReason === 'server' ? 'server' : null);
	const hasMatchingResult =
		hasStoredResultForTemplatePart &&
		status === 'ready' &&
		effectiveStaleReason === null &&
		resultRequestSignature === recommendationRequestSignature;
	const isStaleResult =
		hasStoredResultForTemplatePart && effectiveStaleReason !== null;
	const visibleRecommendations = useMemo(
		() => (hasMatchingResult || isStaleResult ? recommendations : []),
		[hasMatchingResult, isStaleResult, recommendations]
	);
	const hasResult = hasMatchingResult || isStaleResult;
	const hasSuggestions = visibleRecommendations.length > 0;

	useEffect(() => {
		const templatePartChanged =
			previousTemplatePartRef.current !== templatePartRef;
		const recommendationContextChanged =
			previousRecommendationContextSignature.current !==
			recommendationContextSignature;

		if (!templatePartChanged && !recommendationContextChanged) {
			return;
		}

		previousTemplatePartRef.current = templatePartRef;
		previousRecommendationContextSignature.current =
			recommendationContextSignature;

		if (!templatePartChanged) {
			return;
		}

		hydratedResultKeyRef.current = null;
		clearTemplatePartRecommendations();

		if (templatePartChanged) {
			setPrompt('');
		}
	}, [
		clearTemplatePartRecommendations,
		recommendationContextSignature,
		templatePartRef,
	]);

	useEffect(() => {
		const hydrationKey =
			hasStoredResultForTemplatePart && status === 'ready'
				? `${resultRef || ''}:${resultToken || resultRequestSignature}`
				: '';

		if (!hydrationKey || hydratedResultKeyRef.current === hydrationKey) {
			return;
		}

		hydratedResultKeyRef.current = hydrationKey;
		setPrompt(resultPrompt);
	}, [
		hasStoredResultForTemplatePart,
		resultPrompt,
		resultRef,
		resultRequestSignature,
		resultToken,
		status,
	]);

	const suggestionCards = useMemo(
		() =>
			visibleRecommendations.map((suggestion, index) =>
				buildTemplatePartSuggestionViewModel(
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
	const entityMap = useMemo(
		() => buildTemplatePartEntityMap(suggestionCards),
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
		latestTemplatePartActivity &&
		latestTemplatePartActivity.id === latestUndoableActivityId;
	const hasUndoSuccess =
		undoStatus === 'success' &&
		lastUndoneTemplatePartActivity?.undo?.status === 'undone';
	const statusNotice = useSelect(
		(select) => {
			const store = select(STORE_NAME);

			return store.getSurfaceStatusNotice('template-part', {
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
							'template-part operation'
					  )}.`
					: '',
				undoSuccessMessage: hasUndoSuccess
					? `Undid ${
							lastUndoneTemplatePartActivity?.suggestion || 'suggestion'
					  }.`
					: '',
				onDismissAction: Boolean(error),
				onApplyDismissAction: Boolean(applyError),
				onUndoDismissAction: Boolean(undoError),
				emptyMessage: hasResult
					? 'No template-part suggestions were returned for this request.'
					: '',
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
			lastUndoneTemplatePartActivity,
			selectedSuggestionKey,
			status,
			undoError,
			undoStatus,
			hasUndoSuccess,
		]
	);
	const showSecondaryGuidance =
		!hasResult &&
		templatePartActivityEntries.length === 0 &&
		!selectedSuggestionKey;
	const featuredSuggestionCard = isStaleResult
		? null
		: executableSuggestionCards[0] || advisorySuggestionCards[0] || null;
	const dismissStatusNotice = useCallback(() => {
		switch (statusNotice?.source) {
			case 'request':
				setTemplatePartStatus(
					hasStoredResultForTemplatePart ? 'ready' : 'idle'
				);
				break;
			case 'apply':
				setTemplatePartApplyState('idle');
				break;
			case 'undo':
				clearUndoError();
				break;
		}
	}, [
		clearUndoError,
		hasStoredResultForTemplatePart,
		setTemplatePartApplyState,
		setTemplatePartStatus,
		statusNotice?.source,
	]);

	const handleFetch = useCallback(() => {
		if (!canRecommend) {
			return;
		}

		fetchTemplatePartRecommendations(currentRequestInput);
	}, [
		canRecommend,
		fetchTemplatePartRecommendations,
		currentRequestInput,
	]);
	const handlePreviewSuggestion = useCallback(
		(suggestionKey) => {
			setTemplatePartSelectedSuggestion(suggestionKey);
		},
		[setTemplatePartSelectedSuggestion]
	);
	const handleCancelPreview = useCallback(() => {
		setTemplatePartSelectedSuggestion(null);
	}, [setTemplatePartSelectedSuggestion]);
	const handleApplySuggestion = useCallback(
		(suggestion, currentRequestSignature) => {
			applyTemplatePartSuggestion(
				suggestion,
				currentRequestSignature,
				currentRequestInput
			);
		},
		[applyTemplatePartSuggestion, currentRequestInput]
	);
	const handleUndo = useCallback(
		(activityId) => {
			undoActivity(activityId);
		},
		[undoActivity]
	);
	const handleEntityAction = useCallback((entity) => {
		switch (entity?.action) {
			case ENTITY_ACTION_SELECT_BLOCK_HINT:
				if (Array.isArray(entity.path)) {
					selectBlockByPath(entity.path);
				}
				break;
			case ENTITY_ACTION_BROWSE_PATTERN:
				if (entity.filterValue) {
					openInserterForPattern(entity.filterValue);
				}
				break;
		}
	}, []);

	if (
		!templatePartRef ||
		!templatePartContract.hasConfig ||
		!templatePartContract.titleField
	) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="flavor-agent-template-part-recommendations"
			title="AI Template Part Recommendations"
		>
			<div className="flavor-agent-panel">
				<SurfacePanelIntro
					eyebrow={formatTemplatePartLabel(slug, area)}
					introCopy="Describe the structural change you want inside this template part. Review the focus blocks and pattern suggestions first, then confirm only the executable operations Flavor Agent can validate deterministically."
					meta={
						<>
							{area && (
								<span className="flavor-agent-pill">Area: {areaLabel}</span>
							)}
							{currentPatternOverrides.blockCount > 0 && (
								<span className="flavor-agent-pill">
									{formatCount(
										currentPatternOverrides.blockCount,
										'override-ready block'
									)}
								</span>
							)}
							{slug && (
								<code className="flavor-agent-pill flavor-agent-pill--code">
									Slug: {slug}
								</code>
							)}
						</>
					}
				/>

				<SurfaceScopeBar
					scopeLabel={formatTemplatePartLabel(slug, area)}
					scopeDetails={[
						area ? `Area: ${areaLabel}` : '',
						slug ? `Slug: ${slug}` : '',
					].filter(Boolean)}
					isFresh={hasMatchingResult}
					hasResult={hasResult}
					staleReason={
						isStaleResult
							? effectiveStaleReason === 'server'
								? 'This template-part result no longer matches the current server-resolved recommendation context. Refresh before reviewing or applying anything from the previous result.'
								: 'This template-part result no longer matches the current live structure or prompt. Refresh before reviewing or applying anything from the previous result.'
							: ''
					}
					onRefresh={isStaleResult ? handleFetch : undefined}
					isRefreshing={isLoading}
				/>

				{!canRecommend && <CapabilityNotice surface="template-part" />}

				{canRecommend && (
					<SurfaceComposer
						title="Ask Flavor Agent"
						prompt={prompt}
						onPromptChange={setPrompt}
						onFetch={handleFetch}
						placeholder="Describe the structure or layout you want."
						label="What are you trying to achieve with this template part?"
						helperText="Flavor Agent keeps executable template-part suggestions bounded to validated operations inside the current template part."
						starterPrompts={[
							'Clarify the structure and hierarchy',
							'Strengthen the layout around the key blocks',
							'Simplify the template-part composition',
						]}
						submitHint="Press Cmd/Ctrl+Enter to submit."
						isLoading={isLoading}
					/>
				)}

				{canRecommend && isLoading && (
					<AIStatusNotice
						notice={{
							tone: 'info',
							message: 'Analyzing template-part structure…',
						}}
					/>
				)}

				<AIStatusNotice
					notice={statusNotice}
					onAction={
						statusNotice?.actionType === 'undo' && latestTemplatePartActivity
							? () => handleUndo(latestTemplatePartActivity.id)
							: undefined
					}
					onDismiss={dismissStatusNotice}
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

				{canRecommend && isStaleResult && (
					<RecommendationHero
						title="Refresh recommendations for this template part"
						description="Flavor Agent kept the previous result visible so you can compare it against the current template part."
						tone={STALE_STATUS_LABEL}
						why="Review and apply actions stay disabled until you refresh against the live template-part context and current prompt."
						primaryActionLabel={REFRESH_ACTION_LABEL}
						onPrimaryAction={handleFetch}
						primaryActionDisabled={isLoading}
					/>
				)}

				{canRecommend && featuredSuggestionCard && (
					<RecommendationHero
						title={
							featuredSuggestionCard.label || 'Recommended template-part change'
						}
						description={featuredSuggestionCard.description || ''}
						tone={
							featuredSuggestionCard.canApply
								? REVIEW_LANE_LABEL
								: MANUAL_IDEAS_LABEL
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
						title={REVIEW_LANE_LABEL}
						tone={REVIEW_LANE_LABEL}
						count={executableSuggestionCards.length}
						countNoun="suggestion"
						description="Preview the validated operations below before Flavor Agent mutates the template part."
					>
						{executableSuggestionCards.map((suggestion, index) => (
							<TemplatePartSuggestionCard
								key={`${resultToken}-${getSuggestionCardKey(
									suggestion,
									index
								)}`}
								suggestion={suggestion}
								isApplied={
									lastAppliedSuggestionKey === suggestion.suggestionKey
								}
								isApplying={applyStatus === 'applying'}
								isStale={isStaleResult}
								isSelected={selectedSuggestionKey === suggestion.suggestionKey}
								entityMap={entityMap}
								onEntityClick={handleEntityAction}
								onPreviewSuggestion={handlePreviewSuggestion}
							/>
						))}
					</RecommendationLane>
				)}

				{canRecommend && advisorySuggestionCards.length > 0 && (
					<AIAdvisorySection
						title={MANUAL_IDEAS_LABEL}
						count={advisorySuggestionCards.length}
						countNoun="suggestion"
						initialOpen
						description={
							showSecondaryGuidance
								? 'These suggestions stay visible, but Flavor Agent could not validate an exact deterministic operation sequence for them.'
								: ''
						}
					>
						{advisorySuggestionCards.map((suggestion, index) => (
							<TemplatePartSuggestionCard
								key={`advisory-${resultToken}-${getSuggestionCardKey(
									suggestion,
									index
								)}`}
								suggestion={suggestion}
								isApplied={
									lastAppliedSuggestionKey === suggestion.suggestionKey
								}
								isApplying={applyStatus === 'applying'}
								isStale={isStaleResult}
								isSelected={selectedSuggestionKey === suggestion.suggestionKey}
								entityMap={entityMap}
								onEntityClick={handleEntityAction}
								onPreviewSuggestion={handlePreviewSuggestion}
							/>
						))}
					</AIAdvisorySection>
				)}

				{canRecommend && selectedSuggestion && (
					<AIReviewSection
						title={REVIEW_SECTION_TITLE}
						statusLabel={REVIEW_LANE_LABEL}
						count={selectedSuggestion.operations?.length || 0}
						countNoun="operation"
						summary={
							selectedSuggestion.description ||
							'Review the validated operations below before Flavor Agent mutates this template part.'
						}
						hint={
							isStaleResult
								? 'This preview is stale. Refresh recommendations before applying these operations.'
								: 'Flavor Agent will only apply the exact deterministic operations shown here inside the current template part.'
						}
						confirmLabel={
							applyStatus === 'applying' ? 'Applying…' : 'Confirm Apply'
						}
						confirmDisabled={applyStatus === 'applying' || isStaleResult}
						onConfirm={() =>
							handleApplySuggestion(
								selectedSuggestion,
								recommendationRequestSignature
							)
						}
						onCancel={handleCancelPreview}
						className="flavor-agent-template-part-review"
					>
						{selectedSuggestion.operations.map((operation) => (
							<TemplatePartOperationPreviewRow
								key={operation.key}
								operation={operation}
							/>
						))}
					</AIReviewSection>
				)}

				<AIActivitySection
					description="Template-part actions share the same history and latest-valid undo behavior as the other executable review surfaces."
					entries={templatePartActivityEntries}
					isUndoing={undoStatus === 'undoing'}
					onUndo={handleUndo}
					initialOpen={!hasResult || !canRecommend}
					resetKey={templatePartRef || 'template-part'}
					maxVisible={3}
				/>
			</div>
		</PluginDocumentSettingPanel>
	);
}

function TemplatePartSuggestionCard({
	suggestion,
	entityMap = [],
	isApplied = false,
	isApplying = false,
	isStale = false,
	isSelected = false,
	onEntityClick,
	onPreviewSuggestion,
}) {
	const blockHints = Array.isArray(suggestion?.blockHints)
		? suggestion.blockHints
		: [];
	const patternSuggestions = Array.isArray(suggestion?.patternSuggestions)
		? suggestion.patternSuggestions
		: [];
	const summaryParts = [];

	if (blockHints.length > 0) {
		summaryParts.push(formatCount(blockHints.length, 'block'));
	}

	if (patternSuggestions.length > 0) {
		summaryParts.push(formatCount(patternSuggestions.length, 'pattern'));
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
							{suggestion.canApply
								? REVIEW_LANE_LABEL
								: MANUAL_IDEAS_LABEL}
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

			{blockHints.length > 0 && (
				<div className="flavor-agent-template-list">
					<div className="flavor-agent-template-list__header">
						<div className="flavor-agent-section-label">Focus Blocks</div>
						<span className="flavor-agent-pill">
							{formatCount(blockHints.length, 'block')}
						</span>
					</div>
					{blockHints.map((hint) => (
						<div
							key={formatBlockPath(hint.path)}
							className="flavor-agent-tpl-row"
						>
							<Tooltip text={`Select “${hint.label}” in editor`}>
								<Button
									size="small"
									variant="link"
									onClick={() => selectBlockByPath(hint.path)}
									className="flavor-agent-action-link flavor-agent-action-link--part"
								>
									{hint.label}
								</Button>
							</Tooltip>

							<span className="flavor-agent-pill">
								{formatBlockPath(hint.path)}
							</span>

							<div className="flavor-agent-tpl-row__reason">
								{hint.blockName}
								{hint.reason ? `: ${hint.reason}` : ''}
							</div>
						</div>
					))}
				</div>
			)}

			{patternSuggestions.length > 0 && (
				<div className="flavor-agent-template-list">
					<div className="flavor-agent-template-list__header">
						<div className="flavor-agent-section-label">Suggested Patterns</div>
						<span className="flavor-agent-pill">
							{formatCount(patternSuggestions.length, 'pattern')}
						</span>
					</div>
					{patternSuggestions.map((pattern) => (
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

function TemplatePartOperationPreviewRow({ operation }) {
	if (operation.type === TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN) {
		return (
			<div className="flavor-agent-tpl-row">
				<span className="flavor-agent-tpl-row__mapping">
					<span className="flavor-agent-preview-token flavor-agent-preview-token--area">
						{formatTargetPathLabel(operation.targetPath)}
					</span>
					<span className="flavor-agent-tpl-row__arrow">→</span>
					<span className="flavor-agent-preview-token flavor-agent-preview-token--pattern">
						{operation.patternTitle}
					</span>
				</span>
				<span className="flavor-agent-pill">{operation.badgeLabel}</span>
				<div className="flavor-agent-tpl-row__reason">
					Replace the{' '}
					<code>{formatBlockNameLabel(operation.expectedBlockName)}</code> at{' '}
					<code>{formatBlockPath(operation.targetPath)}</code> with{' '}
					<code>{operation.patternTitle}</code>.
				</div>
			</div>
		);
	}

	if (operation.type === TEMPLATE_OPERATION_REMOVE_BLOCK) {
		return (
			<div className="flavor-agent-tpl-row">
				<span className="flavor-agent-tpl-row__mapping">
					<span className="flavor-agent-preview-token flavor-agent-preview-token--area">
						{formatTargetPathLabel(operation.targetPath)}
					</span>
				</span>
				<span className="flavor-agent-pill">{operation.badgeLabel}</span>
				<div className="flavor-agent-tpl-row__reason">
					Remove the{' '}
					<code>{formatBlockNameLabel(operation.expectedBlockName)}</code> at{' '}
					<code>{formatBlockPath(operation.targetPath)}</code>.
				</div>
			</div>
		);
	}

	const placementTarget =
		operation.placement === TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH ||
		operation.placement === TEMPLATE_PART_PLACEMENT_AFTER_BLOCK_PATH
			? `${formatPlacementLabel(operation.placement)} (${formatBlockPath(
					operation.targetPath
			  )})`
			: formatPlacementLabel(operation.placement);

	return (
		<div className="flavor-agent-tpl-row">
			<span className="flavor-agent-tpl-row__mapping">
				<span className="flavor-agent-preview-token flavor-agent-preview-token--pattern">
					{operation.patternTitle}
				</span>
				<span className="flavor-agent-tpl-row__arrow">→</span>
				<span className="flavor-agent-preview-token flavor-agent-preview-token--area">
					{placementTarget}
				</span>
			</span>
			<span className="flavor-agent-pill">{operation.badgeLabel}</span>
			<div className="flavor-agent-tpl-row__reason">
				Insert <code>{operation.patternTitle}</code>{' '}
				{operation.targetPath ? 'relative to' : 'at'}{' '}
				<code>
					{operation.targetPath
						? formatBlockPath(operation.targetPath)
						: formatPlacementLabel(operation.placement)}
				</code>
				.
			</div>
		</div>
	);
}
