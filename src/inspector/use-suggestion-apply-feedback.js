import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';

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
	const [ pendingKey, setPendingKey ] = useState( null );
	const resetTimerRef = useRef( null );
	const pendingKeyRef = useRef( null );
	const suggestionSetKey = useMemo( () => {
		if ( ! Array.isArray( suggestions ) || suggestions.length === 0 ) {
			return '';
		}

		return suggestions
			.map( ( suggestion, index ) =>
				String( getKey( suggestion ) || `suggestion-${ index }` )
			)
			.join( '|' );
	}, [ getKey, suggestions ] );

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
		setPendingKey( null );
		pendingKeyRef.current = null;
	}, [ suggestionSetKey ] );

	const handleApply = useCallback(
		async ( suggestion, keyOverride = null ) => {
			if ( pendingKeyRef.current ) {
				return;
			}

			const key = keyOverride ?? getKey( suggestion );

			pendingKeyRef.current = key || 'pending';
			setPendingKey( pendingKeyRef.current );

			let didApply = false;

			try {
				didApply = await applySuggestion( clientId, suggestion );
			} finally {
				pendingKeyRef.current = null;
				setPendingKey( null );
			}

			if ( ! didApply ) {
				return;
			}

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
		pendingKey,
	};
}
