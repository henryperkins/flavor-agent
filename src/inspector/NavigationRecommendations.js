import { serialize } from '@wordpress/blocks';
import { Button, TextareaControl } from '@wordpress/components';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';

import AIAdvisorySection from '../components/AIAdvisorySection';
import AIStatusNotice from '../components/AIStatusNotice';
import CapabilityNotice from '../components/CapabilityNotice';
import { STORE_NAME } from '../store';
import { getSurfaceCapability } from '../utils/capability-flags';

function formatCount( count, noun ) {
	return `${ count } ${ count === 1 ? noun : `${ noun }s` }`;
}

function humanizeValue( value ) {
	return String( value || '' )
		.split( /[-_]/ )
		.filter( Boolean )
		.map( ( part ) => part.charAt( 0 ).toUpperCase() + part.slice( 1 ) )
		.join( ' ' );
}

function formatChangeType( type ) {
	return humanizeValue( type || 'change' );
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

export default function NavigationRecommendations( { clientId } ) {
	const canRecommend = getSurfaceCapability( 'navigation' ).available;
	const {
		navigationBlock,
		recommendations,
		explanation,
		error,
		isLoading,
		status,
		resultBlockClientId,
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
				status: store.getNavigationStatus( clientId ),
				resultBlockClientId: store.getNavigationBlockClientId(),
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
	const previousRecommendationContextSignature = useRef(
		recommendationContextSignature
	);
	const hasMatchingResult =
		resultBlockClientId === clientId && status === 'ready';
	const hasSuggestions = hasMatchingResult && recommendations.length > 0;
	const { interactionState, statusNotice } = useSelect(
		( select ) => {
			const store = select( STORE_NAME );

			return {
				interactionState:
					store.getNavigationInteractionState( clientId ),
				statusNotice: store.getSurfaceStatusNotice( 'navigation', {
					requestStatus: status,
					requestError: error,
					hasResult: hasMatchingResult,
					hasSuggestions,
					emptyMessage: hasMatchingResult
						? 'No navigation suggestions were returned for the current prompt.'
						: '',
					onDismissAction: Boolean( error ),
				} ),
			};
		},
		[ clientId, error, hasMatchingResult, hasSuggestions, status ]
	);

	useEffect( () => {
		const blockChanged = previousClientId.current !== clientId;
		const recommendationContextChanged =
			previousRecommendationContextSignature.current !==
			recommendationContextSignature;

		if ( ! blockChanged && ! recommendationContextChanged ) {
			return;
		}

		previousClientId.current = clientId;
		previousRecommendationContextSignature.current =
			recommendationContextSignature;

		if (
			! blockChanged &&
			! ( resultBlockClientId === clientId || isLoading || error )
		) {
			return;
		}

		clearNavigationRecommendations();

		if ( blockChanged ) {
			setPrompt( '' );
		}
	}, [
		clientId,
		clearNavigationRecommendations,
		error,
		isLoading,
		recommendationContextSignature,
		resultBlockClientId,
	] );

	const handleFetch = useCallback( () => {
		if ( canRecommend && requestInput ) {
			fetchNavigationRecommendations( requestInput );
		}
	}, [ canRecommend, fetchNavigationRecommendations, requestInput ] );

	if ( navigationBlock?.name !== 'core/navigation' ) {
		return null;
	}

	const menuId = Number( navigationBlock?.attributes?.ref || 0 );

	return (
		<>
			<AIAdvisorySection
				title="Navigation recommendations"
				description="Ask for structure, overlay, or accessibility guidance for this navigation block. Flavor Agent keeps this surface advisory-only in v1.0, so the status model stays consistent without introducing an apply path."
				meta={
					menuId > 0 ? (
						<span className="flavor-agent-pill">
							Menu ID { menuId }
						</span>
					) : null
				}
			/>

			{ ! canRecommend && <CapabilityNotice surface="navigation" /> }

			{ canRecommend && (
				<>
					<div className="flavor-agent-panel__composer">
						<TextareaControl
							__nextHasNoMarginBottom
							label="What do you want to improve about this navigation?"
							hideLabelFromVision
							placeholder="Describe the structure or behavior you want."
							value={ prompt }
							onChange={ setPrompt }
							rows={ 3 }
							className="flavor-agent-prompt"
						/>

						<Button
							variant="secondary"
							onClick={ handleFetch }
							disabled={ isLoading || ! requestInput }
							className="flavor-agent-fetch-button"
						>
							{ isLoading
								? 'Getting navigation suggestions…'
								: 'Get Navigation Suggestions' }
						</Button>
					</div>

					<AIStatusNotice
						notice={ statusNotice }
						onDismiss={
							statusNotice?.source === 'request'
								? clearNavigationError
								: undefined
						}
					/>

					{ hasMatchingResult && explanation && (
						<p className="flavor-agent-explanation flavor-agent-panel__note">
							{ explanation }
						</p>
					) }

					{ hasSuggestions && (
						<AIAdvisorySection
							title="Navigation ideas"
							count={ recommendations.length }
							countNoun="idea"
							description={
								interactionState === 'advisory-ready'
									? 'Review the suggested changes below and make any accepted edits manually in the editor.'
									: ''
							}
							advisoryLabel="Advisory only"
						>
							{ recommendations.map( ( suggestion, index ) => (
								<div
									key={ `${
										suggestion?.label || 'navigation'
									}-${ index }` }
									className="flavor-agent-card"
								>
									<div className="flavor-agent-card__header flavor-agent-card__header--spaced">
										<div className="flavor-agent-card__lead">
											<div className="flavor-agent-card__label">
												{ suggestion?.label ||
													'Navigation suggestion' }
											</div>
											<div className="flavor-agent-card__meta">
												<span className="flavor-agent-pill">
													{ formatCategoryLabel(
														suggestion?.category
													) }
												</span>
												<span className="flavor-agent-pill">
													{ formatCount(
														suggestion?.changes
															?.length || 0,
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
													key={ `${
														suggestion?.label ||
														'navigation'
													}-${
														change?.type || 'change'
													}-${ changeIndex }` }
													className="flavor-agent-navigation-change"
												>
													<div className="flavor-agent-card__meta">
														<span className="flavor-agent-pill">
															{ formatChangeType(
																change?.type
															) }
														</span>
														{ change?.target && (
															<span className="flavor-agent-navigation-change__target">
																{
																	change.target
																}
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
							) ) }
						</AIAdvisorySection>
					) }
				</>
			) }
		</>
	);
}
