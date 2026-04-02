/**
 * Suggestion chips for Inspector sub-panels.
 * Renders compact apply buttons inside ToolsPanel grids.
 */
import { Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useState, useCallback, useEffect, useRef } from '@wordpress/element';
import { check } from '@wordpress/icons';

import { STORE_NAME } from '../store';
import { getSuggestionKey } from './suggestion-keys';

const FEEDBACK_MS = 1200;

export default function SuggestionChips( { clientId, suggestions, label } ) {
	const { applySuggestion } = useDispatch( STORE_NAME );
	const [ appliedKey, setAppliedKey ] = useState( null );
	const [ feedback, setFeedback ] = useState( null );
	const resetTimerRef = useRef( null );

	useEffect( () => {
		return () => {
			if ( resetTimerRef.current ) {
				window.clearTimeout( resetTimerRef.current );
			}
		};
	}, [] );

	useEffect( () => {
		if ( resetTimerRef.current ) {
			window.clearTimeout( resetTimerRef.current );
			resetTimerRef.current = null;
		}

		setAppliedKey( null );
		setFeedback( null );
	}, [ suggestions ] );

	const handleApply = useCallback(
		async ( suggestion ) => {
			const didApply = await applySuggestion( clientId, suggestion );

			if ( ! didApply ) {
				return;
			}

			const key = getSuggestionKey( suggestion );

			if ( resetTimerRef.current ) {
				window.clearTimeout( resetTimerRef.current );
			}

			setAppliedKey( key );
			setFeedback( {
				key,
				label: suggestion?.label || 'Suggestion',
			} );

			resetTimerRef.current = window.setTimeout( () => {
				setAppliedKey( null );
				setFeedback( null );
				resetTimerRef.current = null;
			}, FEEDBACK_MS );
		},
		[ clientId, applySuggestion ]
	);

	return (
		<div className="flavor-agent-chip-surface">
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
								{ wasApplied ? 'Applied' : s.label }
							</span>
							{ ! wasApplied && s.preview && (
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
				<div
					className="flavor-agent-inline-feedback flavor-agent-inline-feedback--compact"
					role="status"
					aria-live="polite"
				>
					<span className="flavor-agent-pill flavor-agent-pill--success">
						Applied
					</span>
					<span className="flavor-agent-inline-feedback__message">
						{ feedback.label }
					</span>
				</div>
			) }
		</div>
	);
}
