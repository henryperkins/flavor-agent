import { serialize } from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	formatCount,
	humanizeString,
	joinClassNames,
} from '../utils/format-count';
import AIStatusNotice from '../components/AIStatusNotice';
import CapabilityNotice from '../components/CapabilityNotice';
import DocsGroundingNotice from '../components/DocsGroundingNotice';
import RecommendationHero from '../components/RecommendationHero';
import RecommendationLane from '../components/RecommendationLane';
import StaleResultBanner from '../components/StaleResultBanner';
import SurfaceComposer from '../components/SurfaceComposer';
import SurfacePanelIntro from '../components/SurfacePanelIntro';
import SurfaceScopeBar from '../components/SurfaceScopeBar';
import { STORE_NAME } from '../store';
import {
	getTonePillClassName,
	getToneLabel,
	SURFACE_TONES,
} from '../components/surface-labels';
import {
	collectBlockContext,
	getLiveBlockContextSignature,
} from '../context/collector';
import {
	getConnectorApprovalNotice,
	getSurfaceCapability,
} from '../utils/capability-flags';
import { buildContextSignature } from '../utils/context-signature';
import { buildNavigationRecommendationRequestSignature } from '../utils/recommendation-request-signature';

/**
 * Composer copy shared by the embedded and full navigation panels.
 *
 * Both branches rendered the same six strings verbatim; hoisting them keeps the
 * two surfaces from drifting and gives translators one entry per string.
 */
const NAVIGATION_COMPOSER_COPY = {
	placeholder: __(
		'Describe the structure or behavior you want.',
		'flavor-agent'
	),
	label: __(
		'What do you want to improve about this navigation?',
		'flavor-agent'
	),
	helperText: __(
		'Flavor Agent will suggest the next navigation changes to make manually.',
		'flavor-agent'
	),
	starterPrompts: [
		__( 'Improve menu hierarchy', 'flavor-agent' ),
		__( 'Reduce overlay friction', 'flavor-agent' ),
		__( 'Improve keyboard support', 'flavor-agent' ),
	],
	fetchLabel: __( 'Get Navigation Suggestions', 'flavor-agent' ),
	loadingLabel: __( 'Getting navigation suggestions\u2026', 'flavor-agent' ),
};

function formatChangeType( type ) {
	return humanizeString( type || 'change' );
}

function formatCategoryLabel( category ) {
	const normalized = String( category || 'structure' ).toLowerCase();

	switch ( normalized ) {
		case 'overlay':
			return 'Overlay';
		case 'accessibility':
			return 'Accessibility';
		default:
			return 'Structure';
	}
}

export function buildNavigationFetchInput( {
	block,
	blockClientId,
	prompt = '',
	editorContext = null,
} ) {
	if ( block?.name !== 'core/navigation' ) {
		return null;
	}

	const menuId = Number( block?.attributes?.ref || 0 );
	const input = {
		blockClientId,
	};
	const trimmedPrompt = prompt.trim();
	const navigationEditorContext =
		buildNavigationEditorContext( editorContext );

	if ( Number.isInteger( menuId ) && menuId > 0 ) {
		input.menuId = menuId;
	}

	const navigationMarkup = String( serialize( [ block ] ) || '' ).trim();

	if ( navigationMarkup ) {
		input.navigationMarkup = navigationMarkup;
	}

	if ( trimmedPrompt ) {
		input.prompt = trimmedPrompt;
	}

	if ( navigationEditorContext ) {
		input.editorContext = navigationEditorContext;
	}

	if ( ! input.menuId && ! input.navigationMarkup ) {
		return null;
	}

	return input;
}

function buildNavigationEditorContext( editorContext ) {
	if ( editorContext?.block?.name !== 'core/navigation' ) {
		return null;
	}

	const context = {
		block: {
			name: editorContext.block.name,
		},
	};
	const structuralIdentity =
		editorContext?.block?.structuralIdentity &&
		typeof editorContext.block.structuralIdentity === 'object'
			? editorContext.block.structuralIdentity
			: {};

	if ( editorContext?.block?.title ) {
		context.block.title = editorContext.block.title;
	}

	if ( Object.keys( structuralIdentity ).length > 0 ) {
		context.block.structuralIdentity = structuralIdentity;
	}

	if (
		Array.isArray( editorContext?.siblingsBefore ) &&
		editorContext.siblingsBefore.length > 0
	) {
		context.siblingsBefore = editorContext.siblingsBefore.filter( Boolean );
	}

	if (
		Array.isArray( editorContext?.siblingsAfter ) &&
		editorContext.siblingsAfter.length > 0
	) {
		context.siblingsAfter = editorContext.siblingsAfter.filter( Boolean );
	}

	if (
		Array.isArray( editorContext?.structuralAncestors ) &&
		editorContext.structuralAncestors.length > 0
	) {
		context.structuralAncestors = editorContext.structuralAncestors;
	}

	if (
		Array.isArray( editorContext?.structuralBranch ) &&
		editorContext.structuralBranch.length > 0
	) {
		context.structuralBranch = editorContext.structuralBranch;
	}

	return context;
}

function buildNavigationContextSignature( {
	block,
	blockClientId,
	editorContext,
	prompt = '',
} ) {
	const input = buildNavigationFetchInput( {
		block,
		blockClientId,
		editorContext,
		prompt,
	} );

	if ( ! input ) {
		return '';
	}

	const requestContext = {
		...input,
	};

	delete requestContext.blockClientId;

	return buildContextSignature( requestContext );
}

function groupNavigationSuggestions( suggestions = [] ) {
	return suggestions.reduce( ( groups, suggestion ) => {
		const category = suggestion?.category || 'structure';

		if ( ! groups[ category ] ) {
			groups[ category ] = [];
		}

		groups[ category ].push( suggestion );
		return groups;
	}, {} );
}

function NavigationSuggestionCard( { suggestion } ) {
	return (
		<div className="flavor-agent-card">
			<div className="flavor-agent-card__header flavor-agent-card__header--spaced">
				<div className="flavor-agent-card__lead">
					<div className="flavor-agent-card__label">
						{ suggestion?.label || 'Navigation suggestion' }
					</div>
					<div className="flavor-agent-card__meta">
						<span className="flavor-agent-pill">
							{ formatCategoryLabel( suggestion?.category ) }
						</span>
						<span className="flavor-agent-pill">
							{ formatCount(
								suggestion?.changes?.length || 0,
								'change'
							) }
						</span>
					</div>
				</div>
			</div>

			{ suggestion?.description && (
				<p className="flavor-agent-card__description">
					{ suggestion.description }
				</p>
			) }

			<div className="flavor-agent-navigation-list">
				{ ( suggestion?.changes || [] ).map(
					( change, changeIndex ) => (
						<div
							key={ `${ suggestion?.label || 'navigation' }-${
								change?.type || 'change'
							}-${ changeIndex }` }
							className="flavor-agent-navigation-change"
						>
							<div className="flavor-agent-card__meta">
								<span className="flavor-agent-pill">
									{ formatChangeType( change?.type ) }
								</span>
								{ change?.target && (
									<span className="flavor-agent-navigation-change__target">
										{ change.target }
									</span>
								) }
							</div>

							{ change?.detail && (
								<p className="flavor-agent-navigation-change__detail">
									{ change.detail }
								</p>
							) }
						</div>
					)
				) }
			</div>
		</div>
	);
}

function NavigationEmbeddedSection( {
	title = '',
	description = '',
	count = null,
	countNoun = 'idea',
	meta = null,
	children = null,
} ) {
	if ( ! title && ! children ) {
		return null;
	}

	const countLabel = formatCount( count, countNoun );

	return (
		<div className="flavor-agent-navigation-embedded__section">
			<div className="flavor-agent-navigation-embedded__section-header">
				<div className="flavor-agent-panel__group-title">{ title }</div>
				<div className="flavor-agent-card__meta">
					{ meta }
					{ countLabel && (
						<span className="flavor-agent-pill">
							{ countLabel }
						</span>
					) }
				</div>
			</div>

			{ description && (
				<p className="flavor-agent-panel__intro-copy flavor-agent-panel__note">
					{ description }
				</p>
			) }

			{ children && (
				<div className="flavor-agent-navigation-embedded__section-body">
					{ children }
				</div>
			) }
		</div>
	);
}

export default function NavigationRecommendations( {
	clientId,
	embedded = false,
} ) {
	const canRecommend = getSurfaceCapability( 'navigation' ).available;
	const {
		navigationBlock,
		recommendations,
		explanation,
		error,
		errorDetails,
		isLoading,
		requestPrompt,
		status,
		resultBlockClientId,
		currentResultContextSignature,
		currentReviewContextSignature,
		currentReviewFreshnessStatus,
		currentReviewStaleReason,
		docsGroundingWarning,
	} = useSelect(
		( select ) => {
			const store = select( STORE_NAME );
			const blockEditor = select( blockEditorStore );

			return {
				navigationBlock: blockEditor.getBlock?.( clientId ) || null,
				recommendations: store.getNavigationRecommendations( clientId ),
				explanation: store.getNavigationExplanation( clientId ),
				error: store.getNavigationError( clientId ),
				errorDetails:
					store.getNavigationErrorDetails?.( clientId ) || null,
				isLoading: store.isNavigationLoading( clientId ),
				requestPrompt: store.getNavigationRequestPrompt( clientId ),
				status: store.getNavigationStatus( clientId ),
				resultBlockClientId: store.getNavigationBlockClientId(),
				currentResultContextSignature:
					store.getNavigationContextSignature( clientId ),
				currentReviewContextSignature:
					store.getNavigationReviewContextSignature( clientId ),
				currentReviewFreshnessStatus:
					store.getNavigationReviewFreshnessStatus( clientId ),
				currentReviewStaleReason:
					store.getNavigationReviewStaleReason( clientId ),
				docsGroundingWarning:
					store.getNavigationDocsGroundingWarning?.( clientId ) ||
					null,
			};
		},
		[ clientId ]
	);
	const {
		clearNavigationError,
		clearNavigationRecommendations,
		fetchNavigationRecommendations,
		revalidateNavigationReviewFreshness,
	} = useDispatch( STORE_NAME );
	const [ prompt, setPrompt ] = useState( '' );
	const isPromptInitializedRef = useRef( false );
	const previousClientId = useRef( clientId );
	const hydratedResultKeyRef = useRef( '' );
	const liveContextSignature = useSelect(
		( select ) => getLiveBlockContextSignature( select, clientId ),
		[ clientId ]
	);
	const liveNavigationContext = useMemo( () => {
		void liveContextSignature;

		return clientId ? collectBlockContext( clientId ) : null;
	}, [ clientId, liveContextSignature ] );
	const requestInput = useMemo(
		() =>
			buildNavigationFetchInput( {
				block: navigationBlock,
				blockClientId: clientId,
				editorContext: liveNavigationContext,
				prompt,
			} ),
		[ clientId, liveNavigationContext, navigationBlock, prompt ]
	);
	const recommendationContextSignature = useMemo(
		() =>
			buildNavigationContextSignature( {
				block: navigationBlock,
				blockClientId: clientId,
				editorContext: liveNavigationContext,
				prompt,
			} ),
		[ clientId, liveNavigationContext, navigationBlock, prompt ]
	);
	const recommendationRequestSignature = useMemo(
		() =>
			buildNavigationRecommendationRequestSignature( {
				blockClientId: clientId,
				prompt,
				contextSignature: recommendationContextSignature,
			} ),
		[ clientId, prompt, recommendationContextSignature ]
	);
	const reviewRequestInput = useMemo( () => {
		if ( ! requestInput ) {
			return null;
		}

		const { blockClientId, ...serverRequestInput } = requestInput;

		void blockClientId;

		return serverRequestInput;
	}, [ requestInput ] );
	const hasStoredResult =
		resultBlockClientId === clientId && status === 'ready';
	const hasMatchingStoredContext =
		hasStoredResult &&
		( ! currentResultContextSignature ||
			currentResultContextSignature === recommendationContextSignature );
	const isClientStaleResult =
		hasStoredResult &&
		Boolean( currentResultContextSignature ) &&
		currentResultContextSignature !== recommendationContextSignature;
	const isServerStaleResult =
		hasMatchingStoredContext && currentReviewFreshnessStatus === 'stale';
	const hasMatchingResult = hasMatchingStoredContext && ! isServerStaleResult;
	const isStaleResult = isClientStaleResult || isServerStaleResult;
	const staleMessage =
		isServerStaleResult && currentReviewStaleReason === 'server-review'
			? 'Server-resolved navigation context changed after the last request. Menu structure, overlay context, or theme constraints may have shifted. Refresh before relying on the previous guidance.'
			: 'This navigation changed after the last request. Refresh before relying on the previous guidance.';
	const visibleRecommendations = useMemo(
		() => ( hasMatchingResult || isStaleResult ? recommendations : [] ),
		[ hasMatchingResult, isStaleResult, recommendations ]
	);
	const hasResult = hasMatchingResult || isStaleResult;
	const hasSuggestions = visibleRecommendations.length > 0;
	const featuredSuggestion = hasSuggestions
		? visibleRecommendations[ 0 ]
		: null;
	const groupedSuggestions = useMemo(
		() =>
			groupNavigationSuggestions(
				hasSuggestions ? visibleRecommendations.slice( 1 ) : []
			),
		[ hasSuggestions, visibleRecommendations ]
	);
	const { interactionState, statusNotice } = useSelect(
		( select ) => {
			const store = select( STORE_NAME );

			return {
				interactionState:
					store.getNavigationInteractionState( clientId ),
				statusNotice: store.getSurfaceStatusNotice( 'navigation', {
					requestStatus: status,
					requestError: error,
					requestErrorDetails: errorDetails,
					isStale: isStaleResult,
					hasResult: hasMatchingResult,
					hasSuggestions: hasMatchingResult && hasSuggestions,
					emptyMessage:
						hasMatchingResult && ! hasSuggestions
							? 'No navigation suggestions were returned for the current prompt.'
							: '',
					onDismissAction: Boolean( error ),
				} ),
			};
		},
		[
			clientId,
			error,
			errorDetails,
			hasMatchingResult,
			hasSuggestions,
			isStaleResult,
			status,
		]
	);
	const connectorApprovalNotice = useMemo(
		() => getConnectorApprovalNotice( 'navigation', errorDetails ),
		[ errorDetails ]
	);

	useEffect( () => {
		const blockChanged = previousClientId.current !== clientId;

		if ( ! blockChanged ) {
			return;
		}

		previousClientId.current = clientId;
		hydratedResultKeyRef.current = '';
		isPromptInitializedRef.current = false;

		clearNavigationRecommendations();
		setPrompt( '' );
	}, [ clientId, clearNavigationRecommendations ] );

	useEffect( () => {
		const hydrationKey =
			resultBlockClientId === clientId && status === 'ready'
				? `${ clientId }:${ requestPrompt || '' }:${
						currentResultContextSignature || ''
				  }`
				: '';

		if ( ! hydrationKey || hydratedResultKeyRef.current === hydrationKey ) {
			return;
		}

		hydratedResultKeyRef.current = hydrationKey;
		isPromptInitializedRef.current = true;
		setPrompt( requestPrompt || '' );
	}, [
		clientId,
		currentResultContextSignature,
		requestPrompt,
		resultBlockClientId,
		status,
	] );

	useEffect( () => {
		if ( ! hasStoredResult || status !== 'ready' || isClientStaleResult ) {
			return;
		}

		revalidateNavigationReviewFreshness(
			recommendationRequestSignature,
			reviewRequestInput
		);
	}, [
		currentReviewContextSignature,
		hasStoredResult,
		isClientStaleResult,
		recommendationRequestSignature,
		revalidateNavigationReviewFreshness,
		reviewRequestInput,
		status,
	] );

	const handleFetch = useCallback( () => {
		if ( canRecommend && requestInput ) {
			fetchNavigationRecommendations( {
				...requestInput,
				contextSignature: recommendationContextSignature,
			} );
		}
	}, [
		canRecommend,
		fetchNavigationRecommendations,
		recommendationContextSignature,
		requestInput,
	] );
	const handleRefresh = useCallback( () => {
		const refreshPrompt = prompt.trim() || requestPrompt || '';
		const refreshInput = buildNavigationFetchInput( {
			block: navigationBlock,
			blockClientId: clientId,
			editorContext: liveNavigationContext,
			prompt: refreshPrompt,
		} );
		const refreshContextSignature = buildNavigationContextSignature( {
			block: navigationBlock,
			blockClientId: clientId,
			editorContext: liveNavigationContext,
			prompt: refreshPrompt,
		} );

		if ( canRecommend && refreshInput ) {
			fetchNavigationRecommendations( {
				...refreshInput,
				contextSignature: refreshContextSignature,
			} );
		}
	}, [
		canRecommend,
		clientId,
		fetchNavigationRecommendations,
		liveNavigationContext,
		navigationBlock,
		prompt,
		requestPrompt,
	] );

	const handlePromptChange = useCallback( ( nextPrompt ) => {
		isPromptInitializedRef.current = true;
		setPrompt( nextPrompt );
	}, [] );

	if ( navigationBlock?.name !== 'core/navigation' ) {
		return null;
	}

	const menuId = Number( navigationBlock?.attributes?.ref || 0 );
	const laneTone = isStaleResult ? SURFACE_TONES.STALE : SURFACE_TONES.MANUAL;
	const laneToneLabel = getToneLabel( laneTone );
	let laneDescription = __(
		'Use this subsection to ask for navigation-specific next steps without creating a second top-level recommendation stack.',
		'flavor-agent'
	);
	let embeddedDescription = __(
		'Ask for navigation-specific next steps without leaving the main block recommendation flow.',
		'flavor-agent'
	);

	if ( interactionState === 'advisory-ready' ) {
		laneDescription = __(
			'Navigation recommendations stay advisory here. Make accepted changes manually in the editor.',
			'flavor-agent'
		);
		embeddedDescription = __(
			'Navigation suggestions stay advisory here and still need manual follow-through in the editor.',
			'flavor-agent'
		);
	}

	if ( isStaleResult ) {
		laneDescription = __(
			'These ideas are shown for reference from the last request. Refresh before using them to change the current navigation block.',
			'flavor-agent'
		);
	}

	const embeddedHeaderMeta = (
		<>
			<span
				className={ joinClassNames(
					'flavor-agent-pill',
					isStaleResult
						? getTonePillClassName( SURFACE_TONES.STALE )
						: ''
				) }
			>
				{ laneToneLabel }
			</span>
			{ menuId > 0 && (
				<span className="flavor-agent-pill">
					{ sprintf(
						/* translators: %d: navigation menu post ID. */
						__( 'Menu ID %d', 'flavor-agent' ),
						menuId
					) }
				</span>
			) }
			{ hasSuggestions && (
				<span className="flavor-agent-pill">
					{ formatCount( visibleRecommendations.length, 'idea' ) }
				</span>
			) }
		</>
	);
	const featuredDescription = isStaleResult
		? __(
				'These ideas came from the previous navigation state. Refresh before using them as your next step.',
				'flavor-agent'
		  )
		: __(
				'Start with this change first, then work through the supporting ideas below.',
				'flavor-agent'
		  );
	const groupedDescription = isStaleResult
		? __(
				'These accepted changes came from the previous request. Refresh before following them in the current navigation block.',
				'flavor-agent'
		  )
		: __(
				'Make these accepted changes manually in the navigation block.',
				'flavor-agent'
		  );

	return (
		<>
			{ ! embedded && (
				<SurfacePanelIntro
					eyebrow={ __(
						'Navigation Recommendations',
						'flavor-agent'
					) }
					introCopy={ __(
						'Ask for structure, overlay, or accessibility guidance for this navigation block. Flavor Agent keeps this surface advisory-only, so accepted changes still need manual follow-through. Treat each idea as guidance, not a pre-applied edit.',
						'flavor-agent'
					) }
					meta={
						menuId > 0 ? (
							<span className="flavor-agent-pill">
								{ sprintf(
									/* translators: %d: navigation menu post ID. */
									__( 'Menu ID %d', 'flavor-agent' ),
									menuId
								) }
							</span>
						) : null
					}
				/>
			) }

			{ ! canRecommend && <CapabilityNotice surface="navigation" /> }

			{ canRecommend &&
				( embedded ? (
					<div
						className="flavor-agent-navigation-embedded"
						aria-label={ __(
							'Navigation recommendations',
							'flavor-agent'
						) }
					>
						<div className="flavor-agent-navigation-embedded__header">
							<div className="flavor-agent-navigation-embedded__copy">
								<p className="flavor-agent-section-label">
									{ __( 'Navigation Ideas', 'flavor-agent' ) }
								</p>
								<p className="flavor-agent-panel__intro-copy flavor-agent-panel__note">
									{ embeddedDescription }
								</p>
							</div>
							<div className="flavor-agent-card__meta">
								{ embeddedHeaderMeta }
							</div>
						</div>

						<SurfaceComposer
							title={ __(
								'Ask About Navigation',
								'flavor-agent'
							) }
							prompt={ prompt }
							onPromptChange={ handlePromptChange }
							onFetch={ handleFetch }
							placeholder={ NAVIGATION_COMPOSER_COPY.placeholder }
							label={ NAVIGATION_COMPOSER_COPY.label }
							helperText={ NAVIGATION_COMPOSER_COPY.helperText }
							starterPrompts={
								NAVIGATION_COMPOSER_COPY.starterPrompts
							}
							fetchLabel={ NAVIGATION_COMPOSER_COPY.fetchLabel }
							loadingLabel={
								NAVIGATION_COMPOSER_COPY.loadingLabel
							}
							fetchVariant="secondary"
							isLoading={ isLoading }
							disabled={ ! requestInput }
							className="flavor-agent-navigation-embedded__composer"
						/>

						{ connectorApprovalNotice && (
							<CapabilityNotice
								surface="navigation"
								notice={ connectorApprovalNotice }
							/>
						) }

						{ isStaleResult && (
							<StaleResultBanner
								message={ staleMessage }
								onRefresh={ handleRefresh }
								isRefreshing={ isLoading }
								variant="embedded"
								className="flavor-agent-navigation-embedded__stale"
							/>
						) }

						<AIStatusNotice
							notice={
								connectorApprovalNotice ? null : statusNotice
							}
							onDismiss={
								statusNotice?.source === 'request'
									? clearNavigationError
									: undefined
							}
						/>

						{ ! isStaleResult && (
							<DocsGroundingNotice
								warning={ docsGroundingWarning }
							/>
						) }

						{ featuredSuggestion && (
							<NavigationEmbeddedSection
								title={ __(
									'Recommended next change',
									'flavor-agent'
								) }
								description={ featuredDescription }
							>
								<NavigationSuggestionCard
									suggestion={ featuredSuggestion }
								/>
							</NavigationEmbeddedSection>
						) }

						{ hasResult && explanation && (
							<p className="flavor-agent-explanation flavor-agent-panel__note">
								{ explanation }
							</p>
						) }

						{ Object.entries( groupedSuggestions ).map(
							( [ category, items ] ) => (
								<NavigationEmbeddedSection
									key={ category }
									title={ `${ formatCategoryLabel(
										category
									) } Changes` }
									description={ groupedDescription }
									count={ items.length }
								>
									{ items.map( ( suggestion, index ) => (
										<NavigationSuggestionCard
											key={ `${
												suggestion?.label ||
												'navigation'
											}-${ index }` }
											suggestion={ suggestion }
										/>
									) ) }
								</NavigationEmbeddedSection>
							)
						) }
					</div>
				) : (
					<>
						<SurfaceScopeBar
							scopeLabel="Navigation Block"
							scopeDetails={
								menuId > 0 ? [ `Menu ID ${ menuId }` ] : []
							}
							isFresh={ hasMatchingResult }
							hasResult={ hasResult }
							announceChanges
							staleReason={ isStaleResult ? staleMessage : '' }
							onRefresh={
								isStaleResult ? handleRefresh : undefined
							}
							isRefreshing={ isLoading }
						/>

						<RecommendationLane
							title={ __(
								'Recommended Next Changes',
								'flavor-agent'
							) }
							tone={ laneTone }
							count={
								hasSuggestions
									? visibleRecommendations.length
									: null
							}
							countNoun="idea"
							description={ laneDescription }
						>
							<SurfaceComposer
								title={ __(
									'Ask Flavor Agent',
									'flavor-agent'
								) }
								prompt={ prompt }
								onPromptChange={ handlePromptChange }
								onFetch={ handleFetch }
								placeholder={
									NAVIGATION_COMPOSER_COPY.placeholder
								}
								label={ NAVIGATION_COMPOSER_COPY.label }
								helperText={
									NAVIGATION_COMPOSER_COPY.helperText
								}
								starterPrompts={
									NAVIGATION_COMPOSER_COPY.starterPrompts
								}
								fetchLabel={
									NAVIGATION_COMPOSER_COPY.fetchLabel
								}
								loadingLabel={
									NAVIGATION_COMPOSER_COPY.loadingLabel
								}
								fetchVariant="secondary"
								isLoading={ isLoading }
								disabled={ ! requestInput }
							/>

							{ connectorApprovalNotice && (
								<CapabilityNotice
									surface="navigation"
									notice={ connectorApprovalNotice }
								/>
							) }

							<AIStatusNotice
								notice={
									connectorApprovalNotice
										? null
										: statusNotice
								}
								onDismiss={
									statusNotice?.source === 'request'
										? clearNavigationError
										: undefined
								}
							/>

							{ ! isStaleResult && (
								<DocsGroundingNotice
									warning={ docsGroundingWarning }
								/>
							) }

							{ featuredSuggestion && (
								<RecommendationHero
									title={
										featuredSuggestion?.label ||
										'Recommended navigation change'
									}
									description={
										featuredSuggestion?.description || ''
									}
									tone={ laneTone }
									why={ featuredDescription }
								>
									<NavigationSuggestionCard
										suggestion={ featuredSuggestion }
									/>
								</RecommendationHero>
							) }

							{ hasResult && explanation && (
								<p className="flavor-agent-explanation flavor-agent-panel__note">
									{ explanation }
								</p>
							) }

							{ Object.entries( groupedSuggestions ).map(
								( [ category, items ] ) => (
									<RecommendationLane
										key={ category }
										title={ `${ formatCategoryLabel(
											category
										) } Changes` }
										tone={ laneTone }
										count={ items.length }
										countNoun="idea"
										description={ groupedDescription }
									>
										{ items.map( ( suggestion, index ) => (
											<NavigationSuggestionCard
												key={ `${
													suggestion?.label ||
													'navigation'
												}-${ index }` }
												suggestion={ suggestion }
											/>
										) ) }
									</RecommendationLane>
								)
							) }
						</RecommendationLane>
					</>
				) ) }
		</>
	);
}
