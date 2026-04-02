import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

const FEEDBACK_MS = 1200;

export default function useSuggestionApplyFeedback( {
	applySuggestion,
	buildFeedback = null,
	clientId,
	getKey = () => null,
	suggestions,
} ) {
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
		async ( suggestion, keyOverride = null ) => {
			const didApply = await applySuggestion( clientId, suggestion );

			if ( ! didApply ) {
				return;
			}

			const key = keyOverride ?? getKey( suggestion );

			if ( resetTimerRef.current ) {
				window.clearTimeout( resetTimerRef.current );
			}

			setAppliedKey( key );
			setFeedback(
				typeof buildFeedback === 'function'
					? buildFeedback( suggestion, key )
					: null
			);

			resetTimerRef.current = window.setTimeout( () => {
				setAppliedKey( null );
				setFeedback( null );
				resetTimerRef.current = null;
			}, FEEDBACK_MS );
		},
		[ applySuggestion, buildFeedback, clientId, getKey ]
	);

	return {
		appliedKey,
		feedback,
		handleApply,
	};
}
