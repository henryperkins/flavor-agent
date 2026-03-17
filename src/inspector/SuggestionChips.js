/**
 * Suggestion chips for Inspector sub-panels.
 * Renders compact apply buttons inside ToolsPanel grids.
 */
import { Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useState, useCallback, useEffect, useRef } from '@wordpress/element';
import { check } from '@wordpress/icons';

import { STORE_NAME } from '../store';

const FEEDBACK_MS = 1200;

export default function SuggestionChips( { clientId, suggestions, label } ) {
	const { applySuggestion } = useDispatch( STORE_NAME );
	const [ appliedKey, setAppliedKey ] = useState( null );
	const resetTimerRef = useRef( null );

	useEffect( () => {
		return () => {
			if ( resetTimerRef.current ) {
				window.clearTimeout( resetTimerRef.current );
			}
		};
	}, [] );

	const handleApply = useCallback(
		( suggestion ) => {
			applySuggestion( clientId, suggestion );
			const key = `${ suggestion.panel }-${ suggestion.label }`;

			if ( resetTimerRef.current ) {
				window.clearTimeout( resetTimerRef.current );
			}

			setAppliedKey( key );

			resetTimerRef.current = window.setTimeout( () => {
				setAppliedKey( null );
				resetTimerRef.current = null;
			}, FEEDBACK_MS );
		},
		[ clientId, applySuggestion ]
	);

	return (
		<div className="flavor-agent-chips" aria-label={ label }>
			{ suggestions.map( ( s ) => {
				const key = `${ s.panel }-${ s.label }`;
				const wasApplied = appliedKey === key;

				return (
					<Button
						key={ key }
						variant={ wasApplied ? 'primary' : 'secondary' }
						size="small"
						onClick={ () => handleApply( s ) }
						title={ s.description || s.label }
						icon={ wasApplied ? check : undefined }
						className="flavor-agent-chip"
					>
						{ wasApplied ? null : s.label }
						{ ! wasApplied && s.preview && (
							<span
								className="flavor-agent-chip__preview"
								style={ {
									backgroundColor: s.preview,
								} }
							/>
						) }
					</Button>
				);
			} ) }
		</div>
	);
}
