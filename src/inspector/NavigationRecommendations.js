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

import { formatCount, humanizeString } from '../utils/format-count';
import AIStatusNotice from '../components/AIStatusNotice';
import CapabilityNotice from '../components/CapabilityNotice';
import RecommendationHero from '../components/RecommendationHero';
import RecommendationLane from '../components/RecommendationLane';
import SurfaceComposer from '../components/SurfaceComposer';
import SurfacePanelIntro from '../components/SurfacePanelIntro';
import SurfaceScopeBar from '../components/SurfaceScopeBar';
import { STORE_NAME } from '../store';
import {
	MANUAL_IDEAS_LABEL,
	STALE_STATUS_LABEL,
} from '../components/surface-labels';
import { getSurfaceCapability } from '../utils/capability-flags';

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
} ) {
	if ( block?.name !== 'core/navigation' ) {
		return null;
	}

	const menuId = Number( block?.attributes?.ref || 0 );
	const input = {
		blockClientId,
	};
	const trimmedPrompt = prompt.trim();

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

	if ( ! input.menuId && ! input.navigationMarkup ) {
		return null;
	}

	return input;
}

function buildNavigationContextSignature( { block, blockClientId } ) {
	const input = buildNavigationFetchInput( {
		block,
		blockClientId,
		prompt: '',
	} );

	if ( ! input ) {
		return '';
	}

	const requestContext = {
		...input,
	};

	delete requestContext.blockClientId;

	return JSON.stringify( requestContext );
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
		isLoading,
		requestPrompt,
		status,
		resultBlockClientId,
		currentResultContextSignature,
	} = useSelect(
		( select ) => {
			const store = select( STORE_NAME );
			const blockEditor = select( blockEditorStore );

			return {
				navigationBlock: blockEditor.getBlock?.( clientId ) || null,
				recommendations: store.getNavigationRecommendations( clientId ),
				explanation: store.getNavigationExplanation( clientId ),
				error: store.getNavigationError( clientId ),
				isLoading: store.isNavigationLoading( clientId ),
				requestPrompt: store.getNavigationRequestPrompt( clientId ),
				status: store.getNavigationStatus( clientId ),
				resultBlockClientId: store.getNavigationBlockClientId(),
				currentResultContextSignature:
					store.getNavigationContextSignature( clientId ),
			};
		},
		[ clientId ]
	);
	const {
		clearNavigationError,
		clearNavigationRecommendations,
		fetchNavigationRecommendations,
	} = useDispatch( STORE_NAME );
	const [ prompt, setPrompt ] = useState( '' );
	const previousClientId = useRef( clientId );
	const requestInput = useMemo(
		() =>
			buildNavigationFetchInput( {
				block: navigationBlock,
				blockClientId: clientId,
				prompt,
			} ),
		[ clientId, navigationBlock, prompt ]
	);
	const recommendationContextSignature = useMemo(
		() =>
			buildNavigationContextSignature( {
				block: navigationBlock,
				blockClientId: clientId,
			} ),
		[ clientId, navigationBlock ]
	);
	const hasStoredResult =
		resultBlockClientId === clientId && status === 'ready';
	const hasMatchingResult =
		hasStoredResult &&
		( ! currentResultContextSignature ||
			currentResultContextSignature === recommendationContextSignature );
	const isStaleResult =
		hasStoredResult &&
		Boolean( currentResultContextSignature ) &&
		currentResultContextSignature !== recommendationContextSignature;
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
			hasMatchingResult,
			hasSuggestions,
			isStaleResult,
			status,
		]
	);

	useEffect( () => {
		const blockChanged = previousClientId.current !== clientId;

		if ( ! blockChanged ) {
			return;
		}

		previousClientId.current = clientId;

		clearNavigationRecommendations();
		setPrompt( '' );
	}, [ clientId, clearNavigationRecommendations ] );

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
		const refreshInput = buildNavigationFetchInput( {
			block: navigationBlock,
			blockClientId: clientId,
			prompt: prompt.trim() || requestPrompt,
		} );

		if ( canRecommend && refreshInput ) {
			fetchNavigationRecommendations( {
				...refreshInput,
				contextSignature: recommendationContextSignature,
			} );
		}
	}, [
		canRecommend,
		clientId,
		fetchNavigationRecommendations,
		navigationBlock,
		prompt,
		recommendationContextSignature,
		requestPrompt,
	] );

	if ( navigationBlock?.name !== 'core/navigation' ) {
		return null;
	}

	const menuId = Number( navigationBlock?.attributes?.ref || 0 );
	const laneTone = isStaleResult ? STALE_STATUS_LABEL : MANUAL_IDEAS_LABEL;
	let laneDescription =
		'Use this subsection to ask for navigation-specific next steps without creating a second top-level recommendation stack.';

	if ( interactionState === 'advisory-ready' ) {
		laneDescription =
			'Navigation recommendations stay advisory here. Make accepted changes manually in the editor.';
	}

	if ( isStaleResult ) {
		laneDescription =
			'These ideas are shown for reference from the last request. Refresh before using them to change the current navigation block.';
	}

	return (
		<>
			{ ! embedded && (
				<SurfacePanelIntro
					eyebrow="Navigation Recommendations"
					introCopy="Ask for structure, overlay, or accessibility guidance for this navigation block. Flavor Agent keeps this surface advisory-only, so accepted changes still need manual follow-through."
					meta={
						menuId > 0 ? (
							<span className="flavor-agent-pill">
								Menu ID { menuId }
							</span>
						) : null
					}
				/>
			) }

			{ ! canRecommend && <CapabilityNotice surface="navigation" /> }

			{ canRecommend && (
				<>
					{ ( ! embedded || hasResult ) && (
						<SurfaceScopeBar
							scopeLabel="Navigation Block"
							scopeDetails={
								menuId > 0 ? [ `Menu ID ${ menuId }` ] : []
							}
							isFresh={ hasMatchingResult }
							hasResult={ hasResult }
							staleReason={
								isStaleResult
									? 'This navigation changed after the last request. Refresh before relying on the previous guidance.'
									: ''
							}
							onRefresh={
								isStaleResult ? handleRefresh : undefined
							}
							isRefreshing={ isLoading }
						/>
					) }

					<RecommendationLane
						title="Recommended Next Changes"
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
							title={
								embedded
									? 'Ask About Navigation'
									: 'Ask Flavor Agent'
							}
							prompt={ prompt }
							onPromptChange={ setPrompt }
							onFetch={ handleFetch }
							placeholder="Describe the structure or behavior you want."
							label="What do you want to improve about this navigation?"
							helperText="Flavor Agent will suggest the next navigation changes to make manually."
							starterPrompts={ [
								'Improve menu hierarchy',
								'Reduce overlay friction',
								'Improve keyboard support',
							] }
							fetchLabel="Get Navigation Suggestions"
							loadingLabel="Getting navigation suggestions\u2026"
							fetchVariant="secondary"
							isLoading={ isLoading }
							disabled={ ! requestInput }
						/>

						<AIStatusNotice
							notice={ statusNotice }
							onDismiss={
								statusNotice?.source === 'request'
									? clearNavigationError
									: undefined
							}
						/>

						{ hasResult && explanation && (
							<p className="flavor-agent-explanation flavor-agent-panel__note">
								{ explanation }
							</p>
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
								why={
									isStaleResult
										? 'These ideas came from the previous navigation state. Refresh before using them as your next step.'
										: 'Start with this change first, then work through the supporting ideas below.'
								}
							>
								<NavigationSuggestionCard
									suggestion={ featuredSuggestion }
								/>
							</RecommendationHero>
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
									description={
										isStaleResult
											? 'These accepted changes came from the previous request. Refresh before following them in the current navigation block.'
											: 'Make these accepted changes manually in the navigation block.'
									}
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
			) }
		</>
	);
}
