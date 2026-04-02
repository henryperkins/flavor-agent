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

import AIActivitySection from '../components/AIActivitySection';
import AIReviewSection from '../components/AIReviewSection';
import AIStatusNotice from '../components/AIStatusNotice';
import CapabilityNotice from '../components/CapabilityNotice';
import {
	buildBlockStyleExecutionContractFromSettings,
	collectThemeTokenDiagnosticsFromSettings,
} from '../context/theme-tokens';
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
	currentStyles,
	mergedStyles,
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
			blockName: scope?.blockName || '',
			blockTitle: scope?.blockTitle || '',
		},
		styleContext: {
			currentConfig,
			mergedConfig,
			availableVariations: [],
			themeTokenDiagnostics,
			styleBookTarget: {
				blockName: scope?.blockName || '',
				blockTitle: scope?.blockTitle || '',
				currentStyles,
				mergedStyles,
			},
		},
		contextSignature,
		...( normalizedPrompt ? { prompt: normalizedPrompt } : {} ),
	};
}

function StyleBookPanel( {
	prompt,
	setPrompt,
	capabilityAvailable,
	isLoading,
	isApplying,
	isUndoing,
	selectedSuggestion,
	suggestions,
	explanation,
	notice,
	activityEntries,
	blockTitle,
	onNoticeAction,
	onRequest,
	onReview,
	onCancelReview,
	onApply,
	onUndo,
} ) {
	return (
		<div className="flavor-agent-panel flavor-agent-style-book-panel">
			<CapabilityNotice surface="style-book" />
			<AIStatusNotice notice={ notice } onAction={ onNoticeAction } />

			<div className="flavor-agent-panel__group">
				<div className="flavor-agent-panel__group-header">
					<div className="flavor-agent-panel__group-title">
						Ask Flavor Agent
					</div>
				</div>
				<div className="flavor-agent-panel__group-body">
					<TextareaControl
						label="Describe the block style direction"
						help={
							blockTitle
								? `Flavor Agent will keep changes inside the theme-backed Style Book controls for ${ blockTitle }. Raw CSS and custom CSS are out of scope.`
								: 'Select a Style Book example to request safe, theme-backed block style changes. Raw CSS and custom CSS are out of scope.'
						}
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
						{ suggestions.map( ( suggestion ) => (
							<div
								key={ suggestion.suggestionKey }
								className="flavor-agent-card"
							>
								<div className="flavor-agent-style-row">
									<div className="flavor-agent-style-row__info">
										<div className="flavor-agent-style-row__header">
											<div className="flavor-agent-style-row__label">
												{ suggestion.label }
											</div>
											<span className="flavor-agent-pill">
												{ suggestion.category ||
													'advisory' }
											</span>
										</div>
										{ suggestion.description && (
											<p className="flavor-agent-style-row__description">
												{ suggestion.description }
											</p>
										) }
										{ suggestion.operations?.length > 0 && (
											<div className="flavor-agent-activity-row__meta">
												{ suggestion.operations
													.map( formatOperation )
													.join( ' · ' ) }
											</div>
										) }
									</div>

									{ suggestion.tone === 'executable' && (
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
											Review
										</Button>
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
				>
					<ul className="flavor-agent-global-styles-panel__operations">
						{ ( selectedSuggestion.operations || [] ).map(
							( operation, index ) => (
								<li
									key={ `${ selectedSuggestion.suggestionKey }-${ index }` }
								>
									{ formatOperation( operation ) }
								</li>
							)
						) }
					</ul>
				</AIReviewSection>
			) }

			<AIActivitySection
				entries={ activityEntries }
				isUndoing={ isUndoing }
				onUndo={ onUndo }
				title="Recent AI Style Book Actions"
				description="Undo is only available while the current Style Book block styles still match the applied AI change."
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
			const blocksStore = select( 'core/blocks' );
			const store = select( STORE_NAME );
			const activeComplementaryArea =
				interfaceStore?.getActiveComplementaryArea?.( 'core' ) || '';
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
					( entry ) => entry?.id === selectorLastUndoneActivityId
				);
			const mappedSuggestions = (
				store?.getStyleBookRecommendations?.() || []
			).map( ( suggestion, index ) => ( {
				...suggestion,
				suggestionKey: getSuggestionKey( suggestion, index ),
			} ) );

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
			availableVariations: [],
			themeTokenDiagnostics,
			executionContract,
		} );
	const hasMatchingResult = Boolean(
		scope?.scopeKey &&
			currentResultRef === scope.scopeKey &&
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
	const latestStyleBookActivity = useMemo(
		() => getLatestAppliedActivity( activityEntries ),
		[ activityEntries ]
	);
	const latestUndoableActivityId = useMemo(
		() => getLatestUndoableActivity( activityEntries )?.id || null,
		[ activityEntries ]
	);
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
				hasResult: suggestions.length > 0 || Boolean( explanation ),
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

		const hasStoredResultForScope = Boolean(
			currentScopeKey &&
				currentResultRef === currentScopeKey &&
				currentResultContextSignature
		);
		const shouldClearRecommendations =
			entityChanged ||
			( recommendationContextChanged &&
				( hasStoredResultForScope || isLoading ) );

		previousScopeKey.current = currentScopeKey;
		previousRecommendationContextSignature.current =
			recommendationContextSignature;

		if ( ! shouldClearRecommendations ) {
			return;
		}

		clearStyleBookRecommendations();

		if ( entityChanged ) {
			setPrompt( '' );
		}
	}, [
		clearStyleBookRecommendations,
		currentResultContextSignature,
		currentResultRef,
		isLoading,
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
				currentStyles,
				mergedStyles,
				contextSignature: recommendationContextSignature,
			} )
		);
	}, [
		blockType,
		currentConfig,
		currentStyles,
		fetchStyleBookRecommendations,
		mergedConfig,
		mergedStyles,
		prompt,
		recommendationContextSignature,
		scope,
		themeTokenDiagnostics,
	] );

	const handleApply = useCallback( () => {
		if ( selectedSuggestion ) {
			applyStyleBookSuggestion( selectedSuggestion );
		}
	}, [ applyStyleBookSuggestion, selectedSuggestion ] );

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
			selectedSuggestion={ selectedSuggestion }
			suggestions={ suggestions }
			explanation={ explanation }
			notice={ notice }
			activityEntries={ activityEntries }
			blockTitle={ scope?.blockTitle || '' }
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
