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
import { collectThemeTokenDiagnosticsFromSettings } from '../context/theme-tokens';
import { STORE_NAME } from '../store';
import { getResolvedActivityEntries } from '../store/activity-history';
import { getSurfaceCapability } from '../utils/capability-flags';
import {
	buildGlobalStylesRecommendationContextSignature,
	getGlobalStylesActivityUndoState,
	getGlobalStylesUserConfig,
} from '../utils/style-operations';

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
	contextSignature,
	themeTokenDiagnostics,
} ) {
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
		},
		styleContext: {
			currentConfig,
			mergedConfig,
			availableVariations,
			themeTokenDiagnostics,
		},
		contextSignature,
		prompt,
	};
}

function GlobalStylesPanel( {
	prompt,
	setPrompt,
	capabilityAvailable,
	isLoading,
	isApplying,
	selectedSuggestion,
	suggestions,
	explanation,
	notice,
	activityEntries,
	onRequest,
	onReview,
	onCancelReview,
	onApply,
	onUndo,
} ) {
	return (
		<div className="flavor-agent-panel flavor-agent-global-styles-panel">
			<CapabilityNotice surface="global-styles" />
			<AIStatusNotice notice={ notice } />

			<div className="flavor-agent-panel__group">
				<div className="flavor-agent-panel__group-header">
					<div className="flavor-agent-panel__group-title">
						Ask Flavor Agent
					</div>
				</div>
				<div className="flavor-agent-panel__group-body">
					<TextareaControl
						label="Describe the style direction"
						help="Flavor Agent will keep recommendations inside preset-backed Global Styles changes."
						value={ prompt }
						onChange={ setPrompt }
						rows={ 4 }
					/>
					<Button
						variant="primary"
						onClick={ onRequest }
						disabled={
							! capabilityAvailable ||
							isLoading ||
							! prompt.trim()
						}
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
				isUndoing={ isApplying }
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
	const blockEditorSettings = useSelect(
		( select ) => select( 'core/block-editor' )?.getSettings?.() || {},
		[]
	);
	const themeTokenDiagnostics = useMemo(
		() => collectThemeTokenDiagnosticsFromSettings( blockEditorSettings ),
		[ blockEditorSettings ]
	);

	const {
		isGlobalStylesActive,
		isSiteEditor,
		scope,
		currentConfig,
		mergedConfig,
		availableVariations,
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
		const store = select( STORE_NAME );
		const activeComplementaryArea =
			interfaceStore?.getActiveComplementaryArea?.( 'core' ) || '';
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
				( entry ) => entry?.id === selectorLastUndoneActivityId
			);
		const mappedSuggestions = (
			store?.getGlobalStylesRecommendations?.() || []
		).map( ( suggestion, index ) => ( {
			...suggestion,
			suggestionKey: getSuggestionKey( suggestion, index ),
		} ) );

		return {
			isGlobalStylesActive:
				activeComplementaryArea === 'edit-site/global-styles',
			isSiteEditor: Boolean( editSite?.getEditedPostType ),
			scope: globalStylesData
				? {
						surface: 'global-styles',
						scopeKey: `global_styles:${ globalStylesData.globalStylesId }`,
						globalStylesId: globalStylesData.globalStylesId,
						postType: 'global_styles',
						entityId: globalStylesData.globalStylesId,
						entityKind: 'root',
						entityName: 'globalStyles',
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
			themeTokenDiagnostics,
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
				applySuccessMessage:
					applyStatus === 'success'
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

	useEffect( () => {
		if ( ! isGlobalStylesActive || typeof document === 'undefined' ) {
			setPortalNode( null );
			return undefined;
		}

		if ( typeof window.MutationObserver !== 'function' ) {
			setPortalNode( null );
			return undefined;
		}

		let nextPortalNode = null;
		const ensurePortalNode = () => {
			const panel = document.querySelector(
				'.editor-global-styles-sidebar__panel'
			);

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
	}, [ isGlobalStylesActive ] );

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
		if ( ! scope || ! prompt.trim() ) {
			return;
		}

		fetchGlobalStylesRecommendations(
			buildRequestInput( {
				scope,
				prompt: prompt.trim(),
				currentConfig,
				mergedConfig,
				availableVariations,
				contextSignature: recommendationContextSignature,
				themeTokenDiagnostics,
			} )
		);
	}, [
		availableVariations,
		currentConfig,
		fetchGlobalStylesRecommendations,
		mergedConfig,
		prompt,
		recommendationContextSignature,
		scope,
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

	if ( ! isSiteEditor || ! isGlobalStylesActive ) {
		return null;
	}

	const capability = getSurfaceCapability( 'global-styles' );
	const panel = (
		<GlobalStylesPanel
			prompt={ prompt }
			setPrompt={ setPrompt }
			capabilityAvailable={ capability.available && Boolean( scope ) }
			isLoading={ isLoading }
			isApplying={ isApplying }
			selectedSuggestion={ selectedSuggestion }
			suggestions={ suggestions }
			explanation={ explanation }
			notice={ notice }
			activityEntries={ activityEntries }
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
