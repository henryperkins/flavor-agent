/**
 * Suggestion chips for Inspector sub-panels.
 * Renders compact apply buttons inside ToolsPanel grids.
 */
import { Button } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import { check } from '@wordpress/icons';

import {
	getTonePillClassName,
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
	interactive = true,
	title = '',
	tone = '',
} ) {
	const { applySuggestion } = useDispatch( STORE_NAME );
	const liveContextSignature = useSelect(
		( select ) => getLiveBlockContextSignature( select, clientId ),
		[ clientId ]
	);
	const liveContext = useMemo( () => {
		void liveContextSignature;

		return clientId ? collectBlockContext( clientId ) : null;
	}, [ clientId, liveContextSignature ] );
	const requestPrompt = useSelect(
		( select ) => {
			const store = select( STORE_NAME );

			return store.getBlockRecommendations?.( clientId )?.prompt || '';
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
	const resolvedRequestSignature =
		currentRequestSignature || fallbackRequestSignature;
	const resolvedRequestInput = currentRequestInput || fallbackRequestInput;
	const { appliedKey, feedback, handleApply } = useSuggestionApplyFeedback( {
		applySuggestion: ( targetClientId, suggestion ) =>
			applySuggestion(
				targetClientId,
				suggestion,
				resolvedRequestSignature,
				resolvedRequestInput
			),
		buildFeedback: buildChipFeedback,
		clientId,
		getKey: getSuggestionKey,
		suggestions,
	} );
	const tonePillClassName = isStale
		? 'flavor-agent-pill--stale'
		: getTonePillClassName( tone );

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
						<span
							className={ `flavor-agent-pill${
								tonePillClassName
									? ` ${ tonePillClassName }`
									: ''
							}` }
						>
							{ isStale ? STALE_STATUS_LABEL : tone }
						</span>
					) }
				</div>
			) }

			{ ! interactive && ! isStale && (
				<div
					className="flavor-agent-chip-surface__passive"
					role="status"
					aria-live="polite"
				>
					<p className="flavor-agent-panel__intro-copy">
						These hints mirror the latest AI Recommendations result.
						Apply them from that main panel.
					</p>
				</div>
			) }

			{ isStale && (
				<div
					className="flavor-agent-chip-surface__stale"
					role="status"
					aria-live="polite"
				>
					<p className="flavor-agent-panel__intro-copy">
						These suggestions reflect the last AI Recommendations
						request. Refresh that main panel to update them for the
						current block.
					</p>
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
					const isChipDisabled =
						Boolean( s?.isCurrentStyle ) ||
						Boolean( s?.disabled ) ||
						wasApplied ||
						disabled ||
						isStale;

					if ( ! interactive ) {
						return (
							<span
								key={ key }
								className="flavor-agent-chip flavor-agent-chip--passive"
								title={ s.description || s.label }
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
								{ s.preview && (
									<span
										className="flavor-agent-chip__preview"
										aria-hidden="true"
									/>
								) }
							</span>
						);
					}

					return (
						<Button
							key={ key }
							variant={ wasApplied ? 'primary' : 'secondary' }
							size="small"
							onClick={ () => void handleApply( s ) }
							disabled={ isChipDisabled }
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
								! isChipDisabled &&
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

			{ interactive && feedback && (
				<InlineActionFeedback
					compact
					message={ feedback.label }
					className="flavor-agent-chip-surface__feedback"
				/>
			) }
		</div>
	);
}
