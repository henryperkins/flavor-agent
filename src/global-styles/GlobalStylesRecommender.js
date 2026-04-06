import { Button, TextareaControl } from '@wordpress/components';
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
import AIReviewSection from '../components/AIReviewSection';
import AIStatusNotice from '../components/AIStatusNotice';
import CapabilityNotice from '../components/CapabilityNotice';
import {
	buildGlobalStylesExecutionContractFromSettings,
	collectThemeTokenDiagnosticsFromSettings,
} from '../context/theme-tokens';
import {
	buildTemplateStructureSnapshot,
	collectViewportVisibilitySummary,
} from '../utils/editor-context-metadata';
import { buildGlobalStyleDesignSemantics } from '../utils/style-design-semantics';
import {
	findStylesSidebarMountNode,
	getStyleBookUiState,
	subscribeToStyleBookUi,
} from '../style-book/dom';
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

function getSuggestionKey( suggestion, index ) {
	if (
		typeof suggestion?.suggestionKey === 'string' &&
		suggestion.suggestionKey
	) {
		return suggestion.suggestionKey;
	}

	return `global-styles-${ index }-${
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
	if ( operation?.type === 'set_theme_variation' ) {
		return `Switch to variation: ${ operation.variationTitle }`;
	}

	if ( operation?.type === 'set_styles' ) {
		const pathLabel = formatPath( operation.path );
		const presetSlug = getCanonicalPresetSlug( operation );

		if ( presetSlug ) {
			return `${ pathLabel } → ${ presetSlug }`;
		}

		return `${ pathLabel } → ${ String( operation.value || '' ) }`;
	}

	return 'Review this change before applying it.';
}

function buildRequestInput( {
	scope,
	prompt,
	currentConfig,
	mergedConfig,
	availableVariations,
	templateStructure,
	templateVisibility,
	designSemantics,
	contextSignature,
	themeTokenDiagnostics,
} ) {
	const normalizedPrompt = typeof prompt === 'string' ? prompt.trim() : '';

	return {
		scope: {
			surface: 'global-styles',
			scopeKey: scope?.scopeKey || '',
			globalStylesId: scope?.globalStylesId || '',
			postType: scope?.postType || 'global_styles',
			entityId: scope?.entityId || scope?.globalStylesId || '',
			entityKind: scope?.entityKind || 'root',
			entityName: scope?.entityName || 'globalStyles',
			stylesheet: scope?.stylesheet || '',
			templateSlug: scope?.templateSlug || '',
			templateType: scope?.templateType || '',
		},
		styleContext: {
			currentConfig,
			mergedConfig,
			availableVariations,
			templateStructure,
			templateVisibility,
			designSemantics,
			themeTokenDiagnostics,
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
	return suggestion?.tone === 'executable' ? 'Review to apply' : 'Advisory';
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

function GlobalStylesPanel( {
	prompt,
	setPrompt,
	capabilityAvailable,
	visibilityConstraintCount,
	isLoading,
	isApplying,
	isUndoing,
	selectedSuggestion,
	suggestions,
	explanation,
	notice,
	activityEntries,
	onNoticeAction,
	onRequest,
	onReview,
	onCancelReview,
	onApply,
	onUndo,
} ) {
	const panelNotice = isInlineStyleNotice( notice ) ? null : notice;
	const inlineNotice = isInlineStyleNotice( notice ) ? notice : null;

	return (
		<div className="flavor-agent-panel flavor-agent-global-styles-panel">
			<CapabilityNotice surface="global-styles" />
			<div className="flavor-agent-panel__intro flavor-agent-style-surface__intro">
				<p className="flavor-agent-panel__eyebrow">
					Site Editor Styles
				</p>
				<p className="flavor-agent-panel__intro-copy">
					Global Styles suggestions stay theme-backed and keep the
					review-before-apply contract intact.
				</p>
				<div className="flavor-agent-style-surface__meta">
					<span className="flavor-agent-pill">Global Styles</span>
					<span className="flavor-agent-pill">
						Review before apply
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
			</div>
			<AIStatusNotice
				notice={ panelNotice }
				onAction={ onNoticeAction }
			/>

			<div className="flavor-agent-panel__group">
				<div className="flavor-agent-panel__group-header">
					<div className="flavor-agent-panel__group-title">
						Ask Flavor Agent
					</div>
				</div>
				<div className="flavor-agent-panel__group-body">
					<TextareaControl
						label="Describe the style direction"
						help="Optional. Flavor Agent will keep recommendations inside theme-backed Global Styles controls. Raw CSS and custom CSS are out of scope."
						value={ prompt }
						onChange={ setPrompt }
						rows={ 4 }
					/>
					<Button
						variant="primary"
						onClick={ onRequest }
						disabled={ ! capabilityAvailable || isLoading }
						className="flavor-agent-card__apply"
					>
						{ isLoading ? 'Thinking…' : 'Get Style Suggestions' }
					</Button>
				</div>
			</div>

			{ inlineNotice &&
				! selectedSuggestion &&
				suggestions.length === 0 && (
					<AIStatusNotice
						notice={ inlineNotice }
						onAction={ onNoticeAction }
						className="flavor-agent-style-feedback-notice"
					/>
				) }

			{ suggestions.length > 0 && (
				<div className="flavor-agent-panel__group">
					<div className="flavor-agent-panel__group-header">
						<div className="flavor-agent-panel__group-title">
							Suggestions
						</div>
						<span className="flavor-agent-pill">
							{ suggestions.length }{ ' ' }
							{ suggestions.length === 1
								? 'suggestion'
								: 'suggestions' }
						</span>
					</div>

					{ explanation && (
						<p className="flavor-agent-panel__intro-copy flavor-agent-panel__note">
							{ explanation }
						</p>
					) }

					<div className="flavor-agent-panel__group-body">
						{ inlineNotice && ! selectedSuggestion && (
							<AIStatusNotice
								notice={ inlineNotice }
								onAction={ onNoticeAction }
								className="flavor-agent-style-feedback-notice"
							/>
						) }

						{ suggestions.map( ( suggestion ) => (
							<div
								key={ suggestion.suggestionKey }
								className={ `flavor-agent-card flavor-agent-style-card${
									selectedSuggestion?.suggestionKey ===
									suggestion.suggestionKey
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
												{ formatBadgeLabel(
													suggestion.category
												) }
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
									<span className="flavor-agent-panel__intro-copy">
										{ suggestion.tone === 'executable'
											? 'Preview the exact operations before applying them to the current Global Styles scope.'
											: 'This stays advisory until the backend can express it as a safe theme-backed operation set.' }
									</span>

									{ suggestion.tone === 'executable' && (
										<div className="flavor-agent-style-card__actions">
											<Button
												variant="secondary"
												size="small"
												onClick={ () =>
													onReview(
														suggestion.suggestionKey
													)
												}
												className="flavor-agent-card__apply"
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
						) ) }
					</div>
				</div>
			) }

			{ selectedSuggestion && (
				<AIReviewSection
					title="Review Before Apply"
					statusLabel="Executable"
					count={ selectedSuggestion.operations?.length || 0 }
					summary={ selectedSuggestion.description }
					onConfirm={ onApply }
					onCancel={ onCancelReview }
					confirmDisabled={ isApplying }
					confirmLabel={
						isApplying ? 'Applying…' : 'Apply Style Change'
					}
					className="flavor-agent-style-review"
					hint="Only the operations shown here will run against the current Global Styles scope."
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
				title="Recent AI Style Actions"
				description="Undo is only available while the current Global Styles state still matches the applied AI change."
			/>
		</div>
	);
}

export default function GlobalStylesRecommender() {
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
			currentResultContextSignature:
				store?.getGlobalStylesContextSignature?.() || null,
			currentResultRef: store?.getGlobalStylesResultRef?.() || null,
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
	const hasMatchingResult = Boolean(
		scope?.globalStylesId &&
			currentResultRef === scope.globalStylesId &&
			currentResultContextSignature &&
			currentResultContextSignature === recommendationContextSignature
	);
	const suggestions = useMemo(
		() => ( hasMatchingResult ? rawSuggestions : [] ),
		[ hasMatchingResult, rawSuggestions ]
	);
	const explanation = hasMatchingResult ? currentExplanation : '';
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
	const hasApplySuccess =
		applyStatus === 'success' &&
		Boolean( latestGlobalStylesActivity ) &&
		latestGlobalStylesActivity?.id === latestUndoableActivityId;

	const {
		applyGlobalStylesSuggestion,
		clearGlobalStylesRecommendations,
		fetchGlobalStylesRecommendations,
		setGlobalStylesSelectedSuggestion,
		undoActivity,
	} = useDispatch( STORE_NAME );

	const isLoading = status === 'loading';
	const isApplying = applyStatus === 'applying' || undoStatus === 'undoing';
	const notice = buildNotice
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
				hasResult: suggestions.length > 0 || Boolean( explanation ),
				hasSuggestions: suggestions.length > 0,
				hasPreview: Boolean( selectedSuggestion ),
				hasOperations:
					( selectedSuggestion?.operations || [] ).length > 0,
				applyStatus,
				undoStatus,
				emptyMessage:
					'No safe Global Styles changes were returned for this prompt.',
				advisoryMessage:
					'Review a theme-backed change before applying it.',
		  } )
		: null;
	const isStyleBookActive = Boolean( styleBookUiState?.isActive );

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

		const hasStoredResultForScope = Boolean(
			currentGlobalStylesId &&
				currentResultRef === currentGlobalStylesId &&
				currentResultContextSignature
		);
		const shouldClearRecommendations =
			entityChanged ||
			( recommendationContextChanged &&
				( hasStoredResultForScope || isLoading ) );

		previousGlobalStylesId.current = currentGlobalStylesId;
		previousRecommendationContextSignature.current =
			recommendationContextSignature;

		if ( ! shouldClearRecommendations ) {
			return;
		}

		clearGlobalStylesRecommendations();

		if ( entityChanged ) {
			setPrompt( '' );
		}
	}, [
		clearGlobalStylesRecommendations,
		currentResultContextSignature,
		currentResultRef,
		isLoading,
		recommendationContextSignature,
		scope?.globalStylesId,
	] );

	const handleRequest = useCallback( () => {
		if ( ! scope ) {
			return;
		}

		fetchGlobalStylesRecommendations(
			buildRequestInput( {
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
		);
	}, [
		availableVariations,
		currentConfig,
		designSemantics,
		fetchGlobalStylesRecommendations,
		mergedConfig,
		prompt,
		recommendationContextSignature,
		scope,
		templateStructure,
		templateVisibility,
		themeTokenDiagnostics,
	] );

	const handleApply = useCallback( () => {
		if ( selectedSuggestion ) {
			applyGlobalStylesSuggestion( selectedSuggestion );
		}
	}, [ applyGlobalStylesSuggestion, selectedSuggestion ] );

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
			isUndoing={ undoStatus === 'undoing' }
			selectedSuggestion={ selectedSuggestion }
			suggestions={ suggestions }
			explanation={ explanation }
			notice={ notice }
			activityEntries={ activityEntries }
			onNoticeAction={
				notice?.actionType === 'undo' && latestGlobalStylesActivity
					? () => handleUndo( latestGlobalStylesActivity.id )
					: undefined
			}
			onRequest={ handleRequest }
			onReview={ setGlobalStylesSelectedSuggestion }
			onCancelReview={ () => setGlobalStylesSelectedSuggestion( null ) }
			onApply={ handleApply }
			onUndo={ handleUndo }
		/>
	);

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
