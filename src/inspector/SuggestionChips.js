/**
 * Suggestion chips for Inspector sub-panels.
 * Renders compact apply buttons inside ToolsPanel grids.
 */
import { Button } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useCallback, useMemo } from '@wordpress/element';
import { check } from '@wordpress/icons';

import {
	REFRESH_ACTION_LABEL,
	STALE_STATUS_LABEL,
} from '../components/surface-labels';
import InlineActionFeedback from '../components/InlineActionFeedback';
import { STORE_NAME } from '../store';
import {
	collectBlockContext,
	getLiveBlockContextSignature,
} from '../context/collector';
import { buildBlockRecommendationRequestData } from './block-recommendation-request';
import { getSuggestionKey } from './suggestion-keys';
import useSuggestionApplyFeedback from './use-suggestion-apply-feedback';

function buildChipFeedback( suggestion, key ) {
	return {
		key,
		label: suggestion?.label || 'Suggestion',
	};
}

export default function SuggestionChips( {
	clientId,
	suggestions,
	label,
	currentRequestSignature = null,
	currentRequestInput = null,
	disabled = false,
	isStale = false,
	title = '',
	tone = '',
} ) {
	const { applySuggestion, fetchBlockRecommendations } =
		useDispatch( STORE_NAME );
	const liveContextSignature = useSelect(
		( select ) => getLiveBlockContextSignature( select, clientId ),
		[ clientId ]
	);
	const liveContext = useMemo( () => {
		void liveContextSignature;

		return clientId ? collectBlockContext( clientId ) : null;
	}, [ clientId, liveContextSignature ] );
	const { isRefreshing, requestPrompt } = useSelect(
		( select ) => {
			const store = select( STORE_NAME );

			return {
				isRefreshing: store.isBlockLoading?.( clientId ) || false,
				requestPrompt:
					store.getBlockRecommendations?.( clientId )?.prompt || '',
			};
		},
		[ clientId ]
	);
	const {
		requestSignature: fallbackRequestSignature,
		requestInput: fallbackRequestInput,
	} = useMemo(
		() =>
			buildBlockRecommendationRequestData( {
				clientId,
				liveContext,
				liveContextSignature,
				prompt: requestPrompt,
			} ),
		[ clientId, liveContext, liveContextSignature, requestPrompt ]
	);
	const handleRefresh = useCallback( () => {
		const liveContext = collectBlockContext( clientId );

		if ( ! liveContext ) {
			return;
		}

		fetchBlockRecommendations( clientId, liveContext, requestPrompt );
	}, [ clientId, fetchBlockRecommendations, requestPrompt ] );
	const { appliedKey, feedback, handleApply } = useSuggestionApplyFeedback( {
		applySuggestion: ( targetClientId, suggestion ) =>
			applySuggestion(
				targetClientId,
				suggestion,
				currentRequestSignature || fallbackRequestSignature,
				currentRequestInput || fallbackRequestInput
			),
		buildFeedback: buildChipFeedback,
		clientId,
		currentRequestSignature,
		fallbackRequestSignature,
		getKey: getSuggestionKey,
		suggestions,
	} );

	return (
		<div className="flavor-agent-chip-surface">
			{ ( title || tone || isStale ) && (
				<div className="flavor-agent-chip-surface__header">
					{ title && (
						<div className="flavor-agent-section-label">
							{ title }
						</div>
					) }
					{ ( tone || isStale ) && (
						<span className="flavor-agent-pill">
							{ isStale ? STALE_STATUS_LABEL : tone }
						</span>
					) }
				</div>
			) }

			{ isStale && (
				<div className="flavor-agent-chip-surface__stale">
					<p className="flavor-agent-panel__intro-copy">
						These suggestions are shown for reference from the last
						request. Refresh before applying them.
					</p>
					<Button
						variant="secondary"
						size="small"
						onClick={ handleRefresh }
						disabled={ isRefreshing }
						className="flavor-agent-chip-surface__refresh"
					>
						{ isRefreshing
							? `${ REFRESH_ACTION_LABEL }…`
							: REFRESH_ACTION_LABEL }
					</Button>
				</div>
			) }

			<div
				className="flavor-agent-chips"
				role="group"
				aria-label={ label }
			>
				{ suggestions.map( ( s ) => {
					const key = getSuggestionKey( s );
					const wasApplied = appliedKey === key;

					return (
						<Button
							key={ key }
							variant={ wasApplied ? 'primary' : 'secondary' }
							size="small"
							onClick={ () => void handleApply( s ) }
							disabled={ wasApplied || disabled || isStale }
							title={ s.description || s.label }
							icon={ wasApplied ? check : undefined }
							className={ `flavor-agent-chip${
								wasApplied ? ' is-applied' : ''
							}` }
							style={
								s.preview
									? {
											'--flavor-agent-chip-preview':
												s.preview,
									  }
									: undefined
							}
						>
							<span className="flavor-agent-chip__label">
								{ s.label }
							</span>
							{ ! wasApplied &&
								! disabled &&
								! isStale &&
								s.preview && (
									<span
										className="flavor-agent-chip__preview"
										aria-hidden="true"
									/>
								) }
						</Button>
					);
				} ) }
			</div>

			{ feedback && (
				<InlineActionFeedback
					compact
					message={ feedback.label }
					className="flavor-agent-chip-surface__feedback"
				/>
			) }
		</div>
	);
}
