import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import {
	createPortal,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { formatCount } from '../utils/format-count';
import AIActivitySection from '../components/AIActivitySection';
import AIAdvisorySection from '../components/AIAdvisorySection';
import AIReviewSection from '../components/AIReviewSection';
import AIStatusNotice from '../components/AIStatusNotice';
import CapabilityNotice from '../components/CapabilityNotice';
import DocsGroundingNotice from '../components/DocsGroundingNotice';
import RecommendationHero from '../components/RecommendationHero';
import RecommendationLane from '../components/RecommendationLane';
import SurfaceComposer from '../components/SurfaceComposer';
import SurfacePanelIntro from '../components/SurfacePanelIntro';
import SurfaceScopeBar from '../components/SurfaceScopeBar';
import {
	MANUAL_IDEAS_LABEL,
	REVIEW_LANE_LABEL,
	REVIEW_SECTION_TITLE,
} from '../components/surface-labels';
import {
	buildGlobalStylesExecutionContractFromSettings,
	collectThemeTokenDiagnosticsFromSettings,
} from '../context/theme-tokens';
import {
	getExecutableSurfaceEffectiveStaleReason,
	getExecutableSurfaceStaleMessage,
} from '../utils/recommendation-stale-reasons';
import { buildGlobalStyleDesignSemantics } from '../utils/style-design-semantics';
import { getStyleBookUiState, subscribeToStyleBookUi } from '../style-book/dom';
import { buildStyleRecommendationRequestInput } from '../style-surfaces/request-input';
import {
	selectGlobalStylesDataDependencies,
	useGlobalStylesData,
} from '../style-surfaces/use-global-styles-data';
import { useStyleSurfaceActivityContext } from '../style-surfaces/use-style-surface-activity-context';
import { useStyleSurfaceDerivedContext } from '../style-surfaces/use-style-surface-derived-context';
import {
	getStyleSuggestionToneLabel,
	isInlineStyleNotice,
	StyleOperationList,
	StyleSuggestionCard,
} from '../style-surfaces/presentation';
import { STORE_NAME } from '../store';
import {
	getLatestAppliedActivity,
	getLatestUndoableActivity,
} from '../store/activity-history';
import {
	getConnectorApprovalNotice,
	getSurfaceCapability,
} from '../utils/capability-flags';
import { buildGlobalStylesRecommendationRequestSignature } from '../utils/recommendation-request-signature';
import { buildGlobalStylesRecommendationContextSignature } from '../utils/style-operations';
import { normalizeTemplateType } from '../utils/template-types';

function GlobalStylesPanel( {
	prompt,
	setPrompt,
	capabilityAvailable,
	visibilityConstraintCount,
	isLoading,
	isApplying,
	isUndoing,
	isStale,
	staleReasonType = null,
	selectedSuggestion,
	suggestions,
	explanation,
	docsGroundingWarning,
	notice,
	connectorApprovalNotice,
	activityEntries,
	activityResetKey,
	hasResult,
	hasMatchingResult,
	onNoticeAction,
	onNoticeDismiss,
	onRequest,
	onReview,
	onCancelReview,
	onApply,
	onUndo,
	showSecondaryGuidance,
} ) {
	const panelNotice = isInlineStyleNotice( notice ) ? null : notice;
	const inlineNotice = isInlineStyleNotice( notice ) ? notice : null;
	const executableSuggestions = suggestions.filter(
		( suggestion ) => suggestion?.tone === 'executable'
	);
	const manualSuggestions = suggestions.filter(
		( suggestion ) => suggestion?.tone !== 'executable'
	);
	const featuredSuggestion = isStale
		? null
		: executableSuggestions[ 0 ] || manualSuggestions[ 0 ] || null;
	let reviewHint = '';
	let staleReason = '';

	if ( isStale ) {
		staleReason = getExecutableSurfaceStaleMessage( {
			surfaceLabel: __( 'Global Styles', 'flavor-agent' ),
			staleReasonType,
			liveContextLabel: __(
				'the current live style state or prompt',
				'flavor-agent'
			),
		} );
	} else if ( showSecondaryGuidance ) {
		reviewHint = __(
			'Only the operations shown here will run against the current Global Styles scope.',
			'flavor-agent'
		);
	}

	return (
		<div className="flavor-agent-panel flavor-agent-global-styles-panel">
			<CapabilityNotice surface="global-styles" />
			{ connectorApprovalNotice && (
				<CapabilityNotice
					surface="global-styles"
					notice={ connectorApprovalNotice }
				/>
			) }
			<SurfacePanelIntro
				eyebrow="Site Editor Styles"
				introCopy="Global Styles suggestions stay theme-backed and keep the review-before-apply contract intact."
				className="flavor-agent-style-surface__intro"
			>
				<div className="flavor-agent-style-surface__meta">
					<span className="flavor-agent-pill">Global Styles</span>
					<span className="flavor-agent-pill">
						{ REVIEW_LANE_LABEL }
					</span>
					{ visibilityConstraintCount > 0 && (
						<span className="flavor-agent-pill">
							{ formatCount(
								visibilityConstraintCount,
								'viewport constraint'
							) }
						</span>
					) }
					{ suggestions.length > 0 && (
						<span className="flavor-agent-pill">
							{ formatCount( suggestions.length, 'suggestion' ) }
						</span>
					) }
				</div>
			</SurfacePanelIntro>
			<SurfaceScopeBar
				scopeLabel="Global Styles"
				isFresh={ hasMatchingResult }
				hasResult={ hasResult }
				announceChanges
				staleReason={ staleReason }
				onRefresh={
					isStale && capabilityAvailable ? onRequest : undefined
				}
				isRefreshing={ isLoading }
			/>
			<AIStatusNotice
				notice={ panelNotice }
				onAction={ onNoticeAction }
				onDismiss={ onNoticeDismiss }
			/>

			{ ! isStale && (
				<DocsGroundingNotice warning={ docsGroundingWarning } />
			) }

			<SurfaceComposer
				title="Ask Flavor Agent"
				prompt={ prompt }
				onPromptChange={ setPrompt }
				onFetch={ onRequest }
				label="Describe the style direction"
				placeholder="Describe the style direction you want across the site."
				helperText={
					showSecondaryGuidance
						? 'Flavor Agent will keep recommendations inside theme-backed Global Styles controls. Raw CSS and custom CSS are out of scope.'
						: ''
				}
				rows={ 4 }
				starterPrompts={ [
					'Refine hierarchy and rhythm',
					'Make the palette feel warmer',
					'Tighten spacing and rhythm',
				] }
				fetchLabel="Get Style Suggestions"
				loadingLabel="Thinking…"
				submitHint="Press Cmd/Ctrl+Enter to submit."
				isLoading={ isLoading }
				disabled={ ! capabilityAvailable }
			/>

			{ inlineNotice && ! selectedSuggestion && (
				<AIStatusNotice
					notice={ inlineNotice }
					onAction={ onNoticeAction }
					onDismiss={ onNoticeDismiss }
					className="flavor-agent-style-feedback-notice"
				/>
			) }

			{ featuredSuggestion && (
				<RecommendationHero
					title={
						featuredSuggestion.label ||
						'Recommended style adjustment'
					}
					description={ featuredSuggestion.description || '' }
					tone={ getStyleSuggestionToneLabel( featuredSuggestion ) }
					why={
						featuredSuggestion.tone === 'executable'
							? 'Start here first, then review the exact operations before applying them.'
							: 'Start here first, then use the remaining ideas as manual follow-through guidance.'
					}
				/>
			) }

			{ explanation &&
				( suggestions.length > 0 || hasMatchingResult ) && (
					<p className="flavor-agent-panel__intro-copy flavor-agent-panel__note">
						{ explanation }
					</p>
				) }

			{ executableSuggestions.length > 0 && (
				<RecommendationLane
					title={ REVIEW_LANE_LABEL }
					tone={ REVIEW_LANE_LABEL }
					count={ executableSuggestions.length }
					countNoun="suggestion"
					description={
						showSecondaryGuidance
							? 'Preview the exact operations before applying them to the current Global Styles scope.'
							: ''
					}
				>
					{ executableSuggestions.map( ( suggestion ) => (
						<StyleSuggestionCard
							key={ suggestion.suggestionKey }
							suggestion={ suggestion }
							isSelected={
								selectedSuggestion?.suggestionKey ===
								suggestion.suggestionKey
							}
							isStale={ isStale }
							onReview={ onReview }
							showSecondaryGuidance={ showSecondaryGuidance }
							executableGuidance="Preview the exact operations before applying them to the current Global Styles scope."
							manualGuidance="This stays advisory until the backend can express it as a safe theme-backed operation set."
						/>
					) ) }
				</RecommendationLane>
			) }

			{ manualSuggestions.length > 0 && (
				<AIAdvisorySection
					title={ MANUAL_IDEAS_LABEL }
					count={ manualSuggestions.length }
					countNoun="suggestion"
					initialOpen
					description={
						showSecondaryGuidance
							? 'These ideas stay advisory until Flavor Agent can express them as safe theme-backed operations.'
							: ''
					}
				>
					{ manualSuggestions.map( ( suggestion ) => (
						<StyleSuggestionCard
							key={ suggestion.suggestionKey }
							suggestion={ suggestion }
							isSelected={
								selectedSuggestion?.suggestionKey ===
								suggestion.suggestionKey
							}
							isStale={ isStale }
							onReview={ onReview }
							showSecondaryGuidance={ showSecondaryGuidance }
							executableGuidance="Preview the exact operations before applying them to the current Global Styles scope."
							manualGuidance="This stays advisory until the backend can express it as a safe theme-backed operation set."
						/>
					) ) }
				</AIAdvisorySection>
			) }

			{ selectedSuggestion && (
				<AIReviewSection
					title={ REVIEW_SECTION_TITLE }
					statusLabel={ REVIEW_LANE_LABEL }
					count={ selectedSuggestion.operations?.length || 0 }
					summary={ selectedSuggestion.description }
					onConfirm={ onApply }
					onCancel={ onCancelReview }
					confirmDisabled={ isApplying || isUndoing || isStale }
					confirmLabel={ isApplying ? 'Applying…' : 'Confirm Apply' }
					className="flavor-agent-style-review"
					hint={ reviewHint }
				>
					{ inlineNotice && (
						<AIStatusNotice
							notice={ inlineNotice }
							onAction={ onNoticeAction }
							onDismiss={ onNoticeDismiss }
							className="flavor-agent-style-feedback-notice"
						/>
					) }
					<StyleOperationList
						operations={ selectedSuggestion.operations || [] }
						suggestionKey={ selectedSuggestion.suggestionKey }
					/>
				</AIReviewSection>
			) }

			<AIActivitySection
				entries={ activityEntries }
				isUndoing={ isUndoing }
				onUndo={ onUndo }
				title="Recent AI Style Actions"
				description="Undo is only available while the current Global Styles state still matches the applied AI change."
				initialOpen={ ! hasResult }
				resetKey={ activityResetKey }
				maxVisible={ 3 }
			/>
		</div>
	);
}

export default function GlobalStylesRecommender() {
	const registry = useRegistry();
	const [ prompt, setPrompt ] = useState( '' );
	const [ portalNode, setPortalNode ] = useState( null );
	const hydratedResultKeyRef = useRef( null );
	const [ styleBookUiState, setStyleBookUiState ] = useState( () =>
		typeof document === 'undefined'
			? {
					isActive: false,
					target: null,
			  }
			: getStyleBookUiState( document )
	);
	const blockEditorSettings = useSelect(
		( select ) => select( 'core/block-editor' )?.getSettings?.() || {},
		[]
	);
	const globalStylesDataDependencies = useSelect(
		( select ) => selectGlobalStylesDataDependencies( select ),
		[]
	);
	const globalStylesData = useGlobalStylesData(
		registry,
		globalStylesDataDependencies
	);
	const { globalStylesId, currentConfig, mergedConfig, availableVariations } =
		globalStylesData;
	const sidebarMountNode = styleBookUiState?.sidebarMountNode || null;
	const themeTokenDiagnostics = useMemo(
		() => collectThemeTokenDiagnosticsFromSettings( blockEditorSettings ),
		[ blockEditorSettings ]
	);
	const executionContract = useMemo(
		() =>
			buildGlobalStylesExecutionContractFromSettings(
				blockEditorSettings
			),
		[ blockEditorSettings ]
	);

	const {
		isGlobalStylesActive,
		isSiteEditor,
		templateSlug,
		editedBlocks: surfaceEditedBlocks,
		rawRecommendations,
		templateType: surfaceTemplateType,
		currentExplanation,
		currentRequestPrompt,
		currentReviewContextSignature,
		currentResultToken,
		currentResultContextSignature,
		currentResultRef,
		reviewStaleReason,
		storedStaleReason,
		docsGroundingWarning,
		status,
		selectedSuggestionKey,
		applyStatus,
		undoStatus,
		activityLog,
		lastUndoneActivityId,
		currentError,
		currentErrorDetails,
		currentApplyError,
		currentUndoError,
	} = useSelect( ( select ) => {
		const interfaceStore = select( 'core/interface' );
		const editSite = select( 'core/edit-site' );
		const blockEditor = select( 'core/block-editor' );
		const store = select( STORE_NAME );
		const activeComplementaryArea =
			interfaceStore?.getActiveComplementaryArea?.( 'core' ) || '';
		const editedPostType = editSite?.getEditedPostType?.() || '';
		const editedTemplateRef =
			editedPostType === 'wp_template'
				? editSite?.getEditedPostId?.() || ''
				: '';
		const selectedTemplateSlug =
			typeof editedTemplateRef === 'string' ? editedTemplateRef : '';
		const templateType =
			normalizeTemplateType( selectedTemplateSlug ) || '';
		const editedBlocks = blockEditor?.getBlocks?.() || null;

		return {
			isGlobalStylesActive:
				activeComplementaryArea === 'edit-site/global-styles',
			isSiteEditor: Boolean( editSite ),
			templateSlug: selectedTemplateSlug,
			editedBlocks,
			rawRecommendations:
				store?.getGlobalStylesRecommendations?.() || null,
			templateType,
			currentExplanation: store?.getGlobalStylesExplanation?.() || '',
			currentRequestPrompt: store?.getGlobalStylesRequestPrompt?.() || '',
			currentReviewContextSignature:
				store?.getGlobalStylesReviewContextSignature?.() || null,
			currentResultToken: store?.getGlobalStylesResultToken?.() || 0,
			currentResultContextSignature:
				store?.getGlobalStylesContextSignature?.() || null,
			currentResultRef: store?.getGlobalStylesResultRef?.() || null,
			reviewStaleReason:
				store?.getGlobalStylesReviewStaleReason?.() || null,
			storedStaleReason: store?.getGlobalStylesStaleReason?.() || null,
			docsGroundingWarning:
				store?.getGlobalStylesDocsGroundingWarning?.() || null,
			status: store?.getGlobalStylesStatus?.() || 'idle',
			selectedSuggestionKey:
				store?.getGlobalStylesSelectedSuggestionKey?.() || null,
			applyStatus: store?.getGlobalStylesApplyStatus?.() || 'idle',
			undoStatus: store?.getUndoStatus?.() || 'idle',
			activityLog: store?.getActivityLog?.() || null,
			lastUndoneActivityId: store?.getLastUndoneActivityId?.() || null,
			currentError: store?.getGlobalStylesError?.() || null,
			currentErrorDetails: store?.getGlobalStylesErrorDetails?.() || null,
			currentApplyError: store?.getGlobalStylesApplyError?.() || null,
			currentUndoError: store?.getUndoError?.() || null,
		};
	}, [] );

	const scope = useMemo(
		() =>
			globalStylesId
				? {
						surface: 'global-styles',
						scopeKey: `global_styles:${ globalStylesId }`,
						globalStylesId,
						postType: 'global_styles',
						entityId: globalStylesId,
						entityKind: 'root',
						entityName: 'globalStyles',
						templateSlug,
						templateType: surfaceTemplateType,
				  }
				: null,
		[ globalStylesId, surfaceTemplateType, templateSlug ]
	);
	const { activityEntries, hasUndoSuccess } = useStyleSurfaceActivityContext(
		{
			surface: 'global-styles',
			activityLog,
			globalStylesId,
			registry,
			lastUndoneActivityId,
			runtimeDependency: globalStylesData,
		}
	);

	const buildDesignSemantics = useCallback(
		( blocks ) =>
			buildGlobalStyleDesignSemantics( blocks, {
				templateType: surfaceTemplateType,
			} ),
		[ surfaceTemplateType ]
	);
	const {
		templateStructure,
		templateVisibility,
		designSemantics,
		rawSuggestions,
	} = useStyleSurfaceDerivedContext( {
		editedBlocks: surfaceEditedBlocks,
		rawRecommendations,
		buildDesignSemantics,
	} );

	const recommendationContextSignature =
		buildGlobalStylesRecommendationContextSignature( {
			scope,
			currentConfig,
			mergedConfig,
			availableVariations,
			templateStructure,
			templateVisibility,
			designSemantics,
			themeTokenDiagnostics,
			executionContract,
		} );
	const recommendationRequestSignature =
		buildGlobalStylesRecommendationRequestSignature( {
			scope,
			prompt,
			contextSignature: recommendationContextSignature,
		} );
	const currentRequestInput = useMemo(
		() =>
			scope
				? buildStyleRecommendationRequestInput( {
						surface: 'global-styles',
						scope,
						prompt,
						currentConfig,
						mergedConfig,
						availableVariations,
						templateStructure,
						templateVisibility,
						designSemantics,
						contextSignature: recommendationContextSignature,
						themeTokenDiagnostics,
				  } )
				: null,
		[
			availableVariations,
			currentConfig,
			designSemantics,
			mergedConfig,
			prompt,
			recommendationContextSignature,
			scope,
			templateStructure,
			templateVisibility,
			themeTokenDiagnostics,
		]
	);
	const resultRequestSignature =
		buildGlobalStylesRecommendationRequestSignature( {
			scope: {
				scopeKey: scope?.scopeKey || '',
				globalStylesId: currentResultRef || '',
				entityId: currentResultRef || '',
			},
			prompt: currentRequestPrompt,
			contextSignature: currentResultContextSignature,
		} );
	const hasStoredResultForScope = Boolean(
		scope?.globalStylesId && currentResultRef === scope.globalStylesId
	);
	const clientStaleReason =
		hasStoredResultForScope &&
		resultRequestSignature !== recommendationRequestSignature
			? 'client'
			: null;
	const effectiveStaleReason = getExecutableSurfaceEffectiveStaleReason( {
		clientStaleReason,
		reviewStaleReason,
		storedStaleReason,
	} );
	const hasMatchingResult = Boolean(
		hasStoredResultForScope &&
			status === 'ready' &&
			effectiveStaleReason === null &&
			resultRequestSignature === recommendationRequestSignature
	);
	const isStaleResult = Boolean(
		hasStoredResultForScope && effectiveStaleReason !== null
	);
	const suggestions = useMemo(
		() => ( hasMatchingResult || isStaleResult ? rawSuggestions : [] ),
		[ hasMatchingResult, isStaleResult, rawSuggestions ]
	);
	const explanation =
		hasMatchingResult || isStaleResult ? currentExplanation : '';
	const hasResult = hasMatchingResult || isStaleResult;
	const selectedSuggestion = useMemo(
		() =>
			suggestions.find(
				( suggestion ) =>
					suggestion.suggestionKey === selectedSuggestionKey
			) || null,
		[ selectedSuggestionKey, suggestions ]
	);
	const latestGlobalStylesActivity = useMemo(
		() => getLatestAppliedActivity( activityEntries ),
		[ activityEntries ]
	);
	const latestUndoableActivityId = useMemo(
		() => getLatestUndoableActivity( activityEntries )?.id || null,
		[ activityEntries ]
	);
	const showSecondaryGuidance = ! hasResult && activityEntries.length === 0;
	const hasApplySuccess =
		applyStatus === 'success' &&
		Boolean( latestGlobalStylesActivity ) &&
		latestGlobalStylesActivity?.id === latestUndoableActivityId;

	const {
		applyGlobalStylesSuggestion,
		clearUndoError,
		clearGlobalStylesRecommendations,
		fetchGlobalStylesRecommendations,
		recordRecommendationOutcome,
		revalidateGlobalStylesReviewFreshness,
		setGlobalStylesApplyState,
		setGlobalStylesSelectedSuggestion,
		setGlobalStylesStatus,
		undoActivity,
	} = useDispatch( STORE_NAME );

	const isLoading = status === 'loading';
	const isApplying = applyStatus === 'applying';
	const isUndoing = undoStatus === 'undoing';
	const baseNotice = useMemo(
		() =>
			registry
				.select( STORE_NAME )
				?.getSurfaceStatusNotice?.( 'global-styles', {
					requestError: currentError,
					requestErrorDetails: currentErrorDetails,
					applyError: currentApplyError,
					undoError: hasUndoSuccess ? '' : currentUndoError,
					undoSuccessMessage: hasUndoSuccess
						? 'Flavor Agent restored the previous Global Styles config.'
						: '',
					applySuccessMessage: hasApplySuccess
						? 'Flavor Agent applied the selected Global Styles change.'
						: '',
					requestStatus: status,
					isStale: isStaleResult,
					hasResult,
					hasSuggestions: suggestions.length > 0,
					hasPreview: Boolean( selectedSuggestion ),
					hasOperations:
						( selectedSuggestion?.operations || [] ).length > 0,
					applyStatus,
					undoStatus,
					onDismissAction: Boolean( currentError ),
					onApplyDismissAction: Boolean( currentApplyError ),
					onUndoDismissAction: Boolean( currentUndoError ),
					emptyMessage:
						'No safe Global Styles changes were returned for this prompt.',
					advisoryMessage:
						'Review a theme-backed change before applying it.',
				} ) || null,
		[
			applyStatus,
			currentApplyError,
			currentError,
			currentErrorDetails,
			currentUndoError,
			hasApplySuccess,
			hasResult,
			hasUndoSuccess,
			isStaleResult,
			registry,
			selectedSuggestion,
			status,
			suggestions.length,
			undoStatus,
		]
	);
	const connectorApprovalNotice = useMemo(
		() =>
			getConnectorApprovalNotice( 'global-styles', currentErrorDetails ),
		[ currentErrorDetails ]
	);
	let fallbackNotice = null;

	if ( ! scope ) {
		fallbackNotice = {
			source: 'scope',
			tone: 'info',
			message:
				'Flavor Agent could not resolve the current Global Styles scope. Refresh the Styles sidebar before requesting recommendations.',
			isDismissible: false,
			actionType: null,
			actionLabel: '',
			actionDisabled: false,
		};
	}

	const notice = baseNotice || fallbackNotice;
	const isStyleBookActive = Boolean( styleBookUiState?.isActive );
	const capability = getSurfaceCapability( 'global-styles' );
	const capabilityAvailable = capability.available && Boolean( scope );
	const dismissStatusNotice = useCallback( () => {
		switch ( notice?.source ) {
			case 'request':
				setGlobalStylesStatus(
					hasStoredResultForScope ? 'ready' : 'idle'
				);
				break;
			case 'apply':
				setGlobalStylesApplyState( 'idle' );
				break;
			case 'undo':
				clearUndoError();
				break;
		}
	}, [
		clearUndoError,
		hasStoredResultForScope,
		notice?.source,
		setGlobalStylesApplyState,
		setGlobalStylesStatus,
	] );

	useEffect( () => {
		if ( ! isGlobalStylesActive || typeof document === 'undefined' ) {
			return undefined;
		}

		return subscribeToStyleBookUi( document, setStyleBookUiState );
	}, [ isGlobalStylesActive ] );

	useEffect( () => {
		if (
			! isGlobalStylesActive ||
			isStyleBookActive ||
			! sidebarMountNode ||
			typeof document === 'undefined'
		) {
			setPortalNode( null );
			return undefined;
		}

		const nextPortalNode = document.createElement( 'div' );
		nextPortalNode.className = 'flavor-agent-global-styles-sidebar-slot';
		sidebarMountNode.appendChild( nextPortalNode );
		setPortalNode( nextPortalNode );

		return () => {
			nextPortalNode.remove();
			setPortalNode( null );
		};
	}, [ isGlobalStylesActive, isStyleBookActive, sidebarMountNode ] );

	const previousGlobalStylesId = useRef( scope?.globalStylesId || null );
	const previousRecommendationContextSignature = useRef(
		recommendationContextSignature
	);

	useEffect( () => {
		const currentGlobalStylesId = scope?.globalStylesId || null;
		const entityChanged =
			previousGlobalStylesId.current !== currentGlobalStylesId;
		const recommendationContextChanged =
			previousRecommendationContextSignature.current !==
			recommendationContextSignature;

		if ( ! entityChanged && ! recommendationContextChanged ) {
			return;
		}

		previousGlobalStylesId.current = currentGlobalStylesId;
		previousRecommendationContextSignature.current =
			recommendationContextSignature;

		if ( ! entityChanged ) {
			return;
		}

		hydratedResultKeyRef.current = null;
		clearGlobalStylesRecommendations();

		if ( entityChanged ) {
			setPrompt( '' );
		}
	}, [
		clearGlobalStylesRecommendations,
		recommendationContextSignature,
		scope?.globalStylesId,
	] );

	useEffect( () => {
		const hydrationKey =
			hasStoredResultForScope && status === 'ready'
				? `${ currentResultRef || '' }:${
						currentResultToken || resultRequestSignature
				  }`
				: '';

		if ( ! hydrationKey || hydratedResultKeyRef.current === hydrationKey ) {
			return;
		}

		hydratedResultKeyRef.current = hydrationKey;
		setPrompt( currentRequestPrompt );
	}, [
		currentRequestPrompt,
		currentResultRef,
		currentResultToken,
		hasStoredResultForScope,
		resultRequestSignature,
		status,
	] );

	useEffect( () => {
		if ( ! hasStoredResultForScope || status !== 'ready' ) {
			return;
		}

		revalidateGlobalStylesReviewFreshness(
			recommendationRequestSignature,
			currentRequestInput
		);
	}, [
		currentRequestInput,
		currentReviewContextSignature,
		currentResultRef,
		currentResultToken,
		hasStoredResultForScope,
		recommendationRequestSignature,
		revalidateGlobalStylesReviewFreshness,
		status,
	] );

	const handleRequest = useCallback( () => {
		if ( ! currentRequestInput || ! capability.available || ! scope ) {
			return;
		}

		fetchGlobalStylesRecommendations( currentRequestInput );
	}, [
		capability.available,
		fetchGlobalStylesRecommendations,
		currentRequestInput,
		scope,
	] );

	const handleReviewSuggestion = useCallback(
		( suggestionKey ) => {
			const suggestion = suggestions.find(
				( item ) => item?.suggestionKey === suggestionKey
			);

			if (
				suggestion &&
				typeof recordRecommendationOutcome === 'function'
			) {
				recordRecommendationOutcome( {
					event: 'selected_for_review',
					surface: 'global-styles',
					suggestion,
					reason: 'review_opened',
				} );
			}

			setGlobalStylesSelectedSuggestion( suggestionKey );
		},
		[
			recordRecommendationOutcome,
			setGlobalStylesSelectedSuggestion,
			suggestions,
		]
	);

	const handleApply = useCallback( () => {
		if ( selectedSuggestion ) {
			applyGlobalStylesSuggestion(
				selectedSuggestion,
				recommendationRequestSignature,
				currentRequestInput
			);
		}
	}, [
		applyGlobalStylesSuggestion,
		currentRequestInput,
		recommendationRequestSignature,
		selectedSuggestion,
	] );

	const handleUndo = useCallback(
		( activityId ) => {
			undoActivity( activityId );
		},
		[ undoActivity ]
	);

	if ( ! isSiteEditor || ! isGlobalStylesActive || isStyleBookActive ) {
		return null;
	}

	const panel = (
		<GlobalStylesPanel
			prompt={ prompt }
			setPrompt={ setPrompt }
			capabilityAvailable={ capabilityAvailable }
			visibilityConstraintCount={ templateVisibility?.blockCount || 0 }
			isLoading={ isLoading }
			isApplying={ isApplying }
			isUndoing={ isUndoing }
			isStale={ isStaleResult }
			staleReasonType={ effectiveStaleReason }
			selectedSuggestion={ selectedSuggestion }
			suggestions={ suggestions }
			explanation={ explanation }
			docsGroundingWarning={
				hasMatchingResult ? docsGroundingWarning : null
			}
			notice={ notice }
			connectorApprovalNotice={ connectorApprovalNotice }
			activityEntries={ activityEntries }
			activityResetKey={ scope?.globalStylesId || 'global-styles' }
			hasResult={ hasResult }
			hasMatchingResult={ hasMatchingResult }
			onNoticeAction={
				notice?.actionType === 'undo' && latestGlobalStylesActivity
					? () => handleUndo( latestGlobalStylesActivity.id )
					: undefined
			}
			onNoticeDismiss={ dismissStatusNotice }
			onRequest={ handleRequest }
			onReview={ handleReviewSuggestion }
			onCancelReview={ () => setGlobalStylesSelectedSuggestion( null ) }
			onApply={ handleApply }
			onUndo={ handleUndo }
			showSecondaryGuidance={ showSecondaryGuidance }
		/>
	);

	if ( portalNode ) {
		return createPortal( panel, portalNode );
	}

	return null;
}
