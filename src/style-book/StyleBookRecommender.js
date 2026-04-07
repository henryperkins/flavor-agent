import { Button } from '@wordpress/components';
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
	REVIEW_LANE_LABEL,
	REVIEW_SECTION_TITLE,
} from '../components/surface-labels';
import {
	buildBlockStyleExecutionContractFromSettings,
	collectThemeTokenDiagnosticsFromSettings,
} from '../context/theme-tokens';
import {
	buildTemplateStructureSnapshot,
	collectViewportVisibilitySummary,
} from '../utils/editor-context-metadata';
import { buildStyleBookDesignSemantics } from '../utils/style-design-semantics';
import { STORE_NAME } from '../store';
import {
	getLatestAppliedActivity,
	getLatestUndoableActivity,
	getResolvedActivityEntries,
} from '../store/activity-history';
import { getSurfaceCapability } from '../utils/capability-flags';
import {
	buildGlobalStylesRecommendationContextSignature,
	getGlobalStylesActivityUndoState,
	getGlobalStylesUserConfig,
} from '../utils/style-operations';
import { normalizeTemplateType } from '../utils/template-types';
import {
	findStylesSidebarMountNode,
	getStyleBookUiState,
	subscribeToStyleBookUi,
} from './dom';

function getSuggestionKey( suggestion, index ) {
	if (
		typeof suggestion?.suggestionKey === 'string' &&
		suggestion.suggestionKey
	) {
		return suggestion.suggestionKey;
	}

	return `style-book-${ index }-${
		suggestion?.label || 'suggestion'
	}-${ JSON.stringify( suggestion?.operations || [] ) }`;
}

function formatPath( path = [] ) {
	return Array.isArray( path ) ? path.join( '.' ) : '';
}

function getCanonicalPresetSlug( operation = {} ) {
	if ( typeof operation?.value === 'string' ) {
		const match = operation.value.match(
			/^var:preset\|[a-z0-9-]+\|([a-z0-9_-]+)$/i
		);

		if ( match?.[ 1 ] ) {
			return match[ 1 ];
		}
	}

	return typeof operation?.presetSlug === 'string'
		? operation.presetSlug
		: '';
}

function formatOperation( operation = {} ) {
	if ( operation?.type === 'set_block_styles' ) {
		const pathLabel = formatPath( operation.path );
		const presetSlug = getCanonicalPresetSlug( operation );

		if ( presetSlug ) {
			return `${ pathLabel } → ${ presetSlug }`;
		}

		return `${ pathLabel } → ${ String( operation.value || '' ) }`;
	}

	return 'Review this change before applying it.';
}

function getBlockStyleBranch( config = {}, blockName = '' ) {
	if ( ! blockName ) {
		return {};
	}

	return config?.styles?.blocks?.[ blockName ] || {};
}

function buildRequestInput( {
	scope,
	prompt,
	currentConfig,
	mergedConfig,
	themeTokenDiagnostics,
	blockDescription,
	currentStyles,
	mergedStyles,
	templateStructure,
	templateVisibility,
	designSemantics,
	contextSignature,
} ) {
	const normalizedPrompt = typeof prompt === 'string' ? prompt.trim() : '';

	return {
		scope: {
			surface: 'style-book',
			scopeKey: scope?.scopeKey || '',
			globalStylesId: scope?.globalStylesId || '',
			postType: scope?.postType || 'global_styles',
			entityId: scope?.entityId || scope?.blockName || '',
			entityKind: scope?.entityKind || 'block',
			entityName: scope?.entityName || 'styleBook',
			stylesheet: scope?.stylesheet || '',
			templateSlug: scope?.templateSlug || '',
			templateType: scope?.templateType || '',
			blockName: scope?.blockName || '',
			blockTitle: scope?.blockTitle || '',
		},
		styleContext: {
			currentConfig,
			mergedConfig,
			themeTokenDiagnostics,
			styleBookTarget: {
				blockName: scope?.blockName || '',
				blockTitle: scope?.blockTitle || '',
				description: blockDescription || '',
				currentStyles,
				mergedStyles,
			},
			templateStructure,
			templateVisibility,
			designSemantics,
		},
		contextSignature,
		...( normalizedPrompt ? { prompt: normalizedPrompt } : {} ),
	};
}

function isInlineStyleNotice( notice ) {
	return notice?.source === 'apply' || notice?.source === 'undo';
}

function formatBadgeLabel( value = '' ) {
	return String( value )
		.replace( /[-_]+/g, ' ' )
		.replace( /\b\w/g, ( char ) => char.toUpperCase() );
}

function getToneLabel( suggestion ) {
	return suggestion?.tone === 'executable'
		? REVIEW_LANE_LABEL
		: MANUAL_IDEAS_LABEL;
}

function OperationList( {
	operations = [],
	compact = false,
	suggestionKey = '',
} ) {
	if ( operations.length === 0 ) {
		return null;
	}

	return (
		<ul
			className={ `flavor-agent-style-operations${
				compact ? ' flavor-agent-style-operations--compact' : ''
			}` }
		>
			{ operations.map( ( operation, index ) => (
				<li key={ `${ suggestionKey }-${ index }` }>
					{ formatOperation( operation ) }
				</li>
			) ) }
		</ul>
	);
}

function StyleBookPanel( {
	prompt,
	setPrompt,
	capabilityAvailable,
	isLoading,
	isApplying,
	isUndoing,
	isStale,
	selectedSuggestion,
	suggestions,
	explanation,
	notice,
	activityEntries,
	activityResetKey,
	blockTitle,
	hasResult,
	hasMatchingResult,
	onNoticeAction,
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
	let promptHelp = '';
	let reviewHint = '';

	if ( showSecondaryGuidance ) {
		promptHelp = blockTitle
			? `Flavor Agent will keep changes inside the theme-backed Style Book controls for ${ blockTitle }. Raw CSS and custom CSS are out of scope.`
			: 'Select a Style Book example to request safe, theme-backed block style changes. Raw CSS and custom CSS are out of scope.';
	}

	if ( isStale ) {
		reviewHint =
			'This review is stale. Refresh recommendations before applying these operations.';
	} else if ( showSecondaryGuidance ) {
		reviewHint =
			'Only the operations shown here will run against the active Style Book example.';
	}

	const renderSuggestionCard = ( suggestion ) => (
		<div
			key={ suggestion.suggestionKey }
			className={ `flavor-agent-card flavor-agent-style-card${
				selectedSuggestion?.suggestionKey === suggestion.suggestionKey
					? ' flavor-agent-style-card--active'
					: ''
			}` }
		>
			<div className="flavor-agent-card__header flavor-agent-card__header--spaced">
				<div className="flavor-agent-card__lead">
					<div className="flavor-agent-card__label">
						{ suggestion.label }
					</div>
					{ suggestion.description && (
						<p className="flavor-agent-card__description">
							{ suggestion.description }
						</p>
					) }
				</div>
				<div className="flavor-agent-style-card__badges">
					<span className="flavor-agent-pill">
						{ getToneLabel( suggestion ) }
					</span>
					{ suggestion.category && (
						<span className="flavor-agent-pill">
							{ formatBadgeLabel( suggestion.category ) }
						</span>
					) }
					{ selectedSuggestion?.suggestionKey ===
						suggestion.suggestionKey && (
						<span className="flavor-agent-pill flavor-agent-pill--success">
							Review open
						</span>
					) }
				</div>
			</div>

			<OperationList
				operations={ suggestion.operations || [] }
				compact
				suggestionKey={ suggestion.suggestionKey }
			/>

			<div className="flavor-agent-style-card__footer">
				{ showSecondaryGuidance && (
					<span className="flavor-agent-panel__intro-copy">
						{ suggestion.tone === 'executable'
							? `Preview the exact operations before applying them to ${
									blockTitle || 'the active block example'
							  }.`
							: 'This stays advisory until the backend can express it as a safe theme-backed block style operation set.' }
					</span>
				) }

				{ suggestion.tone === 'executable' && (
					<div className="flavor-agent-style-card__actions">
						<Button
							variant="secondary"
							size="small"
							onClick={ () =>
								onReview( suggestion.suggestionKey )
							}
							className="flavor-agent-card__apply"
							disabled={ isStale }
						>
							{ selectedSuggestion?.suggestionKey ===
							suggestion.suggestionKey
								? 'Reviewing'
								: 'Review' }
						</Button>
					</div>
				) }
			</div>
		</div>
	);

	return (
		<div className="flavor-agent-panel flavor-agent-style-book-panel">
			<CapabilityNotice surface="style-book" />
			<SurfacePanelIntro
				eyebrow="Style Book"
				introCopy="Review stays required before Flavor Agent applies theme-backed block style changes to the active Style Book example."
				className="flavor-agent-style-surface__intro"
			>
				<div className="flavor-agent-style-surface__meta">
					<span className="flavor-agent-pill">Style Book</span>
					{ blockTitle && (
						<span className="flavor-agent-pill">
							{ blockTitle }
						</span>
					) }
					<span className="flavor-agent-pill">
						{ REVIEW_LANE_LABEL }
					</span>
					{ suggestions.length > 0 && (
						<span className="flavor-agent-pill">
							{ formatCount( suggestions.length, 'suggestion' ) }
						</span>
					) }
				</div>
			</SurfacePanelIntro>
			<SurfaceScopeBar
				scopeLabel="Style Book"
				scopeDetails={ blockTitle ? [ blockTitle ] : [] }
				isFresh={ hasMatchingResult }
				hasResult={ hasResult }
				staleReason={
					isStale
						? 'This Style Book block changed after the last request. Refresh before reviewing or applying anything from the previous result.'
						: ''
				}
				onRefresh={ isStale ? onRequest : undefined }
				isRefreshing={ isLoading }
			/>
			<AIStatusNotice
				notice={ panelNotice }
				onAction={ onNoticeAction }
			/>

			<SurfaceComposer
				title="Ask Flavor Agent"
				prompt={ prompt }
				onPromptChange={ setPrompt }
				onFetch={ onRequest }
				label="Describe the block style direction"
				placeholder="Describe the block style direction you want."
				helperText={ promptHelp }
				rows={ 4 }
				starterPrompts={ [
					'Make this block feel more editorial',
					'Strengthen contrast and hierarchy',
					'Soften spacing and surfaces',
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
					className="flavor-agent-style-feedback-notice"
				/>
			) }

			{ explanation && suggestions.length > 0 && (
				<p className="flavor-agent-panel__intro-copy flavor-agent-panel__note">
					{ explanation }
				</p>
			) }

			{ featuredSuggestion && (
				<RecommendationHero
					title={
						featuredSuggestion.label ||
						'Recommended style-book adjustment'
					}
					description={ featuredSuggestion.description || '' }
					tone={ getToneLabel( featuredSuggestion ) }
					why={
						featuredSuggestion.tone === 'executable'
							? 'Start here first, then review the exact operations before applying them.'
							: 'Start here first, then use the remaining ideas as manual follow-through guidance.'
					}
				/>
			) }

			{ executableSuggestions.length > 0 && (
				<RecommendationLane
					title={ REVIEW_LANE_LABEL }
					tone={ REVIEW_LANE_LABEL }
					count={ executableSuggestions.length }
					countNoun="suggestion"
					description={
						showSecondaryGuidance
							? 'Preview the exact operations before applying them to the active Style Book example.'
							: ''
					}
				>
					{ executableSuggestions.map( renderSuggestionCard ) }
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
							? 'These ideas stay advisory until Flavor Agent can express them as safe theme-backed block style operations.'
							: ''
					}
				>
					{ manualSuggestions.map( renderSuggestionCard ) }
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
					confirmDisabled={ isApplying || isStale }
					confirmLabel={
						isApplying ? 'Applying…' : 'Confirm Apply'
					}
					className="flavor-agent-style-review"
					hint={ reviewHint }
				>
					{ inlineNotice && (
						<AIStatusNotice
							notice={ inlineNotice }
							onAction={ onNoticeAction }
							className="flavor-agent-style-feedback-notice"
						/>
					) }
					<OperationList
						operations={ selectedSuggestion.operations || [] }
						suggestionKey={ selectedSuggestion.suggestionKey }
					/>
				</AIReviewSection>
			) }

			<AIActivitySection
				entries={ activityEntries }
				isUndoing={ isUndoing }
				onUndo={ onUndo }
				title="Recent AI Style Book Actions"
				description="Undo is only available while the current Style Book block styles still match the applied AI change."
				initialOpen={ ! hasResult }
				resetKey={ activityResetKey }
				maxVisible={ 3 }
			/>
		</div>
	);
}

export default function StyleBookRecommender() {
	const [ prompt, setPrompt ] = useState( '' );
	const [ portalNode, setPortalNode ] = useState( null );
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
	const isStyleBookUiActive = Boolean( styleBookUiState?.isActive );
	const styleBookTargetBlockName = styleBookUiState?.target?.blockName || '';
	const styleBookTargetBlockTitle =
		styleBookUiState?.target?.blockTitle || '';
	const {
		isGlobalStylesActive,
		isSiteEditor,
		scope,
		blockType,
		currentConfig,
		mergedConfig,
		currentStyles,
		mergedStyles,
		templateStructure,
		templateVisibility,
		designSemantics,
		rawSuggestions,
		currentExplanation,
		currentResultContextSignature,
		currentResultRef,
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
	} = useSelect(
		( select ) => {
			const interfaceStore = select( 'core/interface' );
			const editSite = select( 'core/edit-site' );
			const blockEditor = select( 'core/block-editor' );
			const blocksStore = select( 'core/blocks' );
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
			const blockName = styleBookTargetBlockName;
			const blockTitle = styleBookTargetBlockTitle;
			const styleScope =
				globalStylesData?.globalStylesId && blockName
					? {
							surface: 'style-book',
							scopeKey: `style_book:${ globalStylesData.globalStylesId }:${ blockName }`,
							globalStylesId: globalStylesData.globalStylesId,
							postType: 'global_styles',
							entityId: blockName,
							entityKind: 'block',
							entityName: 'styleBook',
							templateSlug,
							templateType,
							blockName,
							blockTitle,
					  }
					: null;
			const scopedEntries = ( store?.getActivityLog?.() || [] ).filter(
				( entry ) =>
					entry?.surface === 'style-book' &&
					String( entry?.target?.globalStylesId || '' ) ===
						String( globalStylesData?.globalStylesId || '' ) &&
					String( entry?.target?.blockName || '' ) === blockName
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
				store?.getStyleBookRecommendations?.() || []
			).map( ( suggestion, index ) => ( {
				...suggestion,
				suggestionKey: getSuggestionKey( suggestion, index ),
			} ) );
			const editedBlocks = blockEditor?.getBlocks?.() || [];

			return {
				isGlobalStylesActive:
					activeComplementaryArea === 'edit-site/global-styles',
				isSiteEditor: Boolean( editSite ),
				scope: styleScope,
				blockType: blockName
					? blocksStore?.getBlockType?.( blockName ) || null
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
				currentStyles: getBlockStyleBranch(
					globalStylesData?.userConfig || {},
					blockName
				),
				mergedStyles: getBlockStyleBranch(
					globalStylesData?.mergedConfig || {},
					blockName
				),
				templateStructure:
					buildTemplateStructureSnapshot( editedBlocks ),
				templateVisibility:
					collectViewportVisibilitySummary( editedBlocks ),
				designSemantics: buildStyleBookDesignSemantics( editedBlocks, {
					blockName,
					blockTitle,
					templateType,
				} ),
				rawSuggestions: mappedSuggestions,
				currentExplanation: store?.getStyleBookExplanation?.() || '',
				currentResultContextSignature:
					store?.getStyleBookContextSignature?.() || null,
				currentResultRef: store?.getStyleBookResultRef?.() || null,
				status: store?.getStyleBookStatus?.() || 'idle',
				selectedSuggestionKey:
					store?.getStyleBookSelectedSuggestionKey?.() || null,
				applyStatus: store?.getStyleBookApplyStatus?.() || 'idle',
				undoStatus: store?.getUndoStatus?.() || 'idle',
				activityEntries: resolvedActivityEntries,
				currentError: store?.getStyleBookError?.() || null,
				currentApplyError: store?.getStyleBookApplyError?.() || null,
				currentUndoError: store?.getUndoError?.() || null,
				hasUndoSuccess: hasUndoSuccessForScope,
				buildNotice: store?.getSurfaceStatusNotice
					? ( options ) =>
							store.getSurfaceStatusNotice(
								'style-book',
								options
							)
					: null,
			};
		},
		[ styleBookTargetBlockName, styleBookTargetBlockTitle ]
	);
	const executionContract = useMemo(
		() =>
			buildBlockStyleExecutionContractFromSettings(
				blockEditorSettings,
				blockType || {}
			),
		[ blockEditorSettings, blockType ]
	);
	const recommendationContextSignature =
		buildGlobalStylesRecommendationContextSignature( {
			scope,
			currentConfig,
			mergedConfig,
			templateStructure,
			templateVisibility,
			designSemantics,
			themeTokenDiagnostics,
			executionContract,
		} );
	const hasStoredResultForScope = Boolean(
		scope?.scopeKey &&
			currentResultRef === scope.scopeKey &&
			currentResultContextSignature
	);
	const hasMatchingResult = Boolean(
		hasStoredResultForScope &&
			status === 'ready' &&
			currentResultContextSignature === recommendationContextSignature
	);
	const isStaleResult = Boolean(
		hasStoredResultForScope &&
			currentResultContextSignature !== recommendationContextSignature
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
	const latestStyleBookActivity = useMemo(
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
		Boolean( latestStyleBookActivity ) &&
		latestStyleBookActivity?.id === latestUndoableActivityId;
	const {
		applyStyleBookSuggestion,
		clearStyleBookRecommendations,
		fetchStyleBookRecommendations,
		setStyleBookSelectedSuggestion,
		undoActivity,
	} = useDispatch( STORE_NAME );
	const isLoading = status === 'loading';
	const isApplying = applyStatus === 'applying' || undoStatus === 'undoing';
	const baseNotice = buildNotice
		? buildNotice( {
				requestError: currentError,
				applyError: currentApplyError,
				undoError: hasUndoSuccess ? '' : currentUndoError,
				undoSuccessMessage: hasUndoSuccess
					? 'Flavor Agent restored the previous Style Book block styles.'
					: '',
				applySuccessMessage: hasApplySuccess
					? 'Flavor Agent applied the selected Style Book change.'
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
				emptyMessage:
					'No safe Style Book changes were returned for this prompt.',
				advisoryMessage:
					'Review a theme-backed block style change before applying it.',
		  } )
		: null;
	let fallbackNotice = null;

	if ( ! scope ) {
		fallbackNotice = {
			source: 'scope',
			tone: 'info',
			message:
				'Select a block example in Style Book to request recommendations.',
			isDismissible: false,
			actionType: null,
			actionLabel: '',
			actionDisabled: false,
		};
	} else if ( ! blockType ) {
		fallbackNotice = {
			source: 'scope',
			tone: 'error',
			message:
				'The selected Style Book example is not backed by a registered block.',
			isDismissible: false,
			actionType: null,
			actionLabel: '',
			actionDisabled: false,
		};
	}

	const notice = baseNotice || fallbackNotice;

	useEffect( () => {
		if ( typeof document === 'undefined' ) {
			return undefined;
		}

		return subscribeToStyleBookUi( document, setStyleBookUiState );
	}, [] );

	useEffect( () => {
		if (
			! isGlobalStylesActive ||
			! isStyleBookUiActive ||
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
			nextPortalNode.className = 'flavor-agent-style-book-sidebar-slot';
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
	}, [ isGlobalStylesActive, isStyleBookUiActive ] );

	const previousScopeKey = useRef( scope?.scopeKey || null );
	const previousRecommendationContextSignature = useRef(
		recommendationContextSignature
	);

	useEffect( () => {
		const currentScopeKey = scope?.scopeKey || null;
		const entityChanged = previousScopeKey.current !== currentScopeKey;
		const recommendationContextChanged =
			previousRecommendationContextSignature.current !==
			recommendationContextSignature;

		if ( ! entityChanged && ! recommendationContextChanged ) {
			return;
		}

		previousScopeKey.current = currentScopeKey;
		previousRecommendationContextSignature.current =
			recommendationContextSignature;

		if ( ! entityChanged ) {
			return;
		}

		clearStyleBookRecommendations();

		if ( entityChanged ) {
			setPrompt( '' );
		}
	}, [
		clearStyleBookRecommendations,
		recommendationContextSignature,
		scope?.scopeKey,
	] );

	const handleRequest = useCallback( () => {
		if ( ! scope || ! blockType ) {
			return;
		}

		fetchStyleBookRecommendations(
			buildRequestInput( {
				scope,
				prompt,
				currentConfig,
				mergedConfig,
				themeTokenDiagnostics,
				blockDescription: blockType?.description || '',
				currentStyles,
				mergedStyles,
				templateStructure,
				templateVisibility,
				designSemantics,
				contextSignature: recommendationContextSignature,
			} )
		);
	}, [
		blockType,
		currentConfig,
		currentStyles,
		designSemantics,
		fetchStyleBookRecommendations,
		mergedConfig,
		mergedStyles,
		prompt,
		recommendationContextSignature,
		scope,
		templateStructure,
		templateVisibility,
		themeTokenDiagnostics,
	] );

	const handleApply = useCallback( () => {
		if ( selectedSuggestion ) {
			applyStyleBookSuggestion(
				selectedSuggestion,
				recommendationContextSignature
			);
		}
	}, [
		applyStyleBookSuggestion,
		recommendationContextSignature,
		selectedSuggestion,
	] );

	const handleUndo = useCallback(
		( activityId ) => {
			undoActivity( activityId );
		},
		[ undoActivity ]
	);

	if ( ! isSiteEditor || ! isGlobalStylesActive || ! isStyleBookUiActive ) {
		return null;
	}

	const capability = getSurfaceCapability( 'style-book' );
	const panel = (
		<StyleBookPanel
			prompt={ prompt }
			setPrompt={ setPrompt }
			capabilityAvailable={
				capability.available && Boolean( scope ) && Boolean( blockType )
			}
			isLoading={ isLoading }
			isApplying={ isApplying }
			isUndoing={ undoStatus === 'undoing' }
			isStale={ isStaleResult }
			selectedSuggestion={ selectedSuggestion }
			suggestions={ suggestions }
			explanation={ explanation }
			notice={ notice }
			activityEntries={ activityEntries }
			activityResetKey={ scope?.scopeKey || 'style-book' }
			blockTitle={ scope?.blockTitle || '' }
			hasResult={ hasResult }
			hasMatchingResult={ hasMatchingResult }
			onNoticeAction={
				notice?.actionType === 'undo' && latestStyleBookActivity
					? () => handleUndo( latestStyleBookActivity.id )
					: undefined
			}
			onRequest={ handleRequest }
			onReview={ setStyleBookSelectedSuggestion }
			onCancelReview={ () => setStyleBookSelectedSuggestion( null ) }
			onApply={ handleApply }
			onUndo={ handleUndo }
			showSecondaryGuidance={ showSecondaryGuidance }
		/>
	);

	if ( portalNode ) {
		return createPortal( panel, portalNode );
	}

	return (
		<PluginDocumentSettingPanel
			name="flavor-agent-style-book"
			title="AI Style Book Suggestions"
		>
			{ panel }
		</PluginDocumentSettingPanel>
	);
}
