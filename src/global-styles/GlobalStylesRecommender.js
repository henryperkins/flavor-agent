import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import {
	createPortal,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';

import { formatCount } from '../utils/format-count';
import AIActivitySection from '../components/AIActivitySection';
import AIAdvisorySection from '../components/AIAdvisorySection';
import AIReviewSection from '../components/AIReviewSection';
import AIStatusNotice from '../components/AIStatusNotice';
import CapabilityNotice from '../components/CapabilityNotice';
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
import {
	buildGlobalStylesExecutionContractFromSettings,
	collectThemeTokenDiagnosticsFromSettings,
} from '../context/theme-tokens';
import {
	buildTemplateStructureSnapshot,
	collectViewportVisibilitySummary,
} from '../utils/editor-context-metadata';
import {
	getExecutableSurfaceEffectiveStaleReason,
	getExecutableSurfaceStaleMessage,
} from '../utils/recommendation-stale-reasons';
import { buildGlobalStyleDesignSemantics } from '../utils/style-design-semantics';
import {
	findStylesSidebarMountNode,
	getStyleBookUiState,
	subscribeToStyleBookUi,
} from '../style-book/dom';
import { buildStyleRecommendationRequestInput } from '../style-surfaces/request-input';
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
	getResolvedActivityEntries,
} from '../store/activity-history';
import { getSurfaceCapability } from '../utils/capability-flags';
import { buildGlobalStylesRecommendationRequestSignature } from '../utils/recommendation-request-signature';
import {
	buildGlobalStylesRecommendationContextSignature,
	getGlobalStylesActivityUndoState,
	getGlobalStylesUserConfig,
} from '../utils/style-operations';
import { normalizeTemplateType } from '../utils/template-types';
import { getSuggestionKey } from '../inspector/suggestion-keys';

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
	notice,
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
		reviewHint =
			'This review is stale. Refresh recommendations before applying these operations.';
		staleReason = getExecutableSurfaceStaleMessage( {
			surfaceLabel: 'Global Styles',
			staleReasonType,
			liveContextLabel: 'the current live style state or prompt',
		} );
	} else if ( showSecondaryGuidance ) {
		reviewHint =
			'Only the operations shown here will run against the current Global Styles scope.';
	}

	return (
		<div className="flavor-agent-panel flavor-agent-global-styles-panel">
			<CapabilityNotice surface="global-styles" />
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
				onRefresh={ isStale ? onRequest : undefined }
				isRefreshing={ isLoading }
			/>
			<AIStatusNotice
				notice={ panelNotice }
				onAction={ onNoticeAction }
				onDismiss={ onNoticeDismiss }
			/>

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

			{ isStale && (
				<RecommendationHero
					title="Refresh recommendations for Global Styles"
					description="Flavor Agent kept the previous result visible so you can compare it against the current Global Styles config."
					tone={ STALE_STATUS_LABEL }
					why="Review and apply actions stay disabled until you refresh against the live Global Styles context and current prompt."
					primaryActionLabel={ REFRESH_ACTION_LABEL }
					onPrimaryAction={ onRequest }
					primaryActionDisabled={ isLoading }
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

			{ explanation && suggestions.length > 0 && (
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
		scope,
		currentConfig,
		mergedConfig,
		availableVariations,
		templateStructure,
		templateVisibility,
		designSemantics,
		rawSuggestions,
		currentExplanation,
		currentRequestPrompt,
		currentReviewContextSignature,
		currentResultToken,
		currentResultContextSignature,
		currentResultRef,
		reviewStaleReason,
		storedStaleReason,
		status,
		selectedSuggestionKey,
		applyStatus,
		undoStatus,
		activityEntries,
		currentError,
		currentApplyError,
		currentUndoError,
		hasUndoSuccess,
		buildNotice,
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
		const templateSlug =
			typeof editedTemplateRef === 'string' ? editedTemplateRef : '';
		const templateType = normalizeTemplateType( templateSlug ) || '';
		const globalStylesData = getGlobalStylesUserConfig( {
			select: ( storeName ) => select( storeName ),
		} );
		const scopedEntries = ( store?.getActivityLog?.() || [] ).filter(
			( entry ) =>
				entry?.surface === 'global-styles' &&
				String( entry?.target?.globalStylesId || '' ) ===
					String( globalStylesData?.globalStylesId || '' )
		);
		const resolvedActivityEntries = getResolvedActivityEntries(
			scopedEntries,
			( entry ) =>
				getGlobalStylesActivityUndoState( entry, {
					select: ( storeName ) => select( storeName ),
				} )
		);
		const selectorLastUndoneActivityId =
			store?.getLastUndoneActivityId?.() || null;
		const hasUndoSuccessForScope =
			typeof selectorLastUndoneActivityId === 'string' &&
			resolvedActivityEntries.some(
				( entry ) =>
					entry?.id === selectorLastUndoneActivityId &&
					entry?.undo?.status === 'undone'
			);
		const mappedSuggestions = (
			store?.getGlobalStylesRecommendations?.() || []
		).map( ( suggestion, index ) => ( {
			...suggestion,
			suggestionKey: getSuggestionKey( suggestion, index ),
		} ) );
		const editedBlocks = blockEditor?.getBlocks?.() || [];

		return {
			isGlobalStylesActive:
				activeComplementaryArea === 'edit-site/global-styles',
			isSiteEditor: Boolean( editSite ),
			scope: globalStylesData
				? {
						surface: 'global-styles',
						scopeKey: `global_styles:${ globalStylesData.globalStylesId }`,
						globalStylesId: globalStylesData.globalStylesId,
						postType: 'global_styles',
						entityId: globalStylesData.globalStylesId,
						entityKind: 'root',
						entityName: 'globalStyles',
						templateSlug,
						templateType,
				  }
				: null,
			currentConfig: globalStylesData?.userConfig || {
				settings: {},
				styles: {},
				_links: {},
			},
			mergedConfig: globalStylesData?.mergedConfig || {
				settings: {},
				styles: {},
				_links: {},
			},
			availableVariations: Array.isArray( globalStylesData?.variations )
				? globalStylesData.variations
				: [],
			templateStructure: buildTemplateStructureSnapshot( editedBlocks ),
			templateVisibility:
				collectViewportVisibilitySummary( editedBlocks ),
			designSemantics: buildGlobalStyleDesignSemantics( editedBlocks, {
				templateType,
			} ),
			rawSuggestions: mappedSuggestions,
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
			status: store?.getGlobalStylesStatus?.() || 'idle',
			selectedSuggestionKey:
				store?.getGlobalStylesSelectedSuggestionKey?.() || null,
			applyStatus: store?.getGlobalStylesApplyStatus?.() || 'idle',
			undoStatus: store?.getUndoStatus?.() || 'idle',
			activityEntries: resolvedActivityEntries,
			currentError: store?.getGlobalStylesError?.() || null,
			currentApplyError: store?.getGlobalStylesApplyError?.() || null,
			currentUndoError: store?.getUndoError?.() || null,
			hasUndoSuccess: hasUndoSuccessForScope,
			buildNotice: store?.getSurfaceStatusNotice
				? ( options ) =>
						store.getSurfaceStatusNotice( 'global-styles', options )
				: null,
		};
	}, [] );

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
		revalidateGlobalStylesReviewFreshness,
		setGlobalStylesApplyState,
		setGlobalStylesSelectedSuggestion,
		setGlobalStylesStatus,
		undoActivity,
	} = useDispatch( STORE_NAME );

	const isLoading = status === 'loading';
	const isApplying = applyStatus === 'applying';
	const isUndoing = undoStatus === 'undoing';
	const baseNotice = buildNotice
		? buildNotice( {
				requestError: currentError,
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
		  } )
		: null;
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
		if ( typeof document === 'undefined' ) {
			return undefined;
		}

		return subscribeToStyleBookUi( document, setStyleBookUiState );
	}, [] );

	useEffect( () => {
		if (
			! isGlobalStylesActive ||
			isStyleBookActive ||
			typeof document === 'undefined'
		) {
			setPortalNode( null );
			return undefined;
		}

		if ( typeof window.MutationObserver !== 'function' ) {
			setPortalNode( null );
			return undefined;
		}

		let nextPortalNode = null;
		const ensurePortalNode = () => {
			const panel = findStylesSidebarMountNode( document );

			if ( ! panel ) {
				if ( nextPortalNode ) {
					nextPortalNode.remove();
					nextPortalNode = null;
				}

				setPortalNode( null );
				return;
			}

			if (
				nextPortalNode &&
				nextPortalNode.isConnected &&
				nextPortalNode.parentNode === panel
			) {
				return;
			}

			if ( nextPortalNode ) {
				nextPortalNode.remove();
			}

			nextPortalNode = document.createElement( 'div' );
			nextPortalNode.className =
				'flavor-agent-global-styles-sidebar-slot';
			panel.appendChild( nextPortalNode );
			setPortalNode( nextPortalNode );
		};

		const observer = new window.MutationObserver( () => {
			ensurePortalNode();
		} );

		ensurePortalNode();
		observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );

		return () => {
			observer.disconnect();

			if ( nextPortalNode ) {
				nextPortalNode.remove();
			}

			setPortalNode( null );
		};
	}, [ isGlobalStylesActive, isStyleBookActive ] );

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
		if ( ! currentRequestInput ) {
			return;
		}

		fetchGlobalStylesRecommendations( currentRequestInput );
	}, [ fetchGlobalStylesRecommendations, currentRequestInput ] );

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

	const capability = getSurfaceCapability( 'global-styles' );
	const panel = (
		<GlobalStylesPanel
			prompt={ prompt }
			setPrompt={ setPrompt }
			capabilityAvailable={ capability.available && Boolean( scope ) }
			visibilityConstraintCount={ templateVisibility?.blockCount || 0 }
			isLoading={ isLoading }
			isApplying={ isApplying }
			isUndoing={ isUndoing }
			isStale={ isStaleResult }
			staleReasonType={ effectiveStaleReason }
			selectedSuggestion={ selectedSuggestion }
			suggestions={ suggestions }
			explanation={ explanation }
			notice={ notice }
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
			onReview={ setGlobalStylesSelectedSuggestion }
			onCancelReview={ () => setGlobalStylesSelectedSuggestion( null ) }
			onApply={ handleApply }
			onUndo={ handleUndo }
			showSecondaryGuidance={ showSecondaryGuidance }
		/>
	);

	// Use the native Styles sidebar when available and fall back to a
	// document panel only when the sidebar mount point is unavailable.
	if ( portalNode ) {
		return createPortal( panel, portalNode );
	}

	return (
		<PluginDocumentSettingPanel
			name="flavor-agent-global-styles"
			title="AI Style Suggestions"
		>
			{ panel }
		</PluginDocumentSettingPanel>
	);
}
