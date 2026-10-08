import { useCallback, useRef, useState } from '@wordpress/element';

/** Keep editable request drafts independent of asynchronously received results. */
export default function useRecommendationDraft() {
	const [ prompt, setValue ] = useState( '' );
	const promptRef = useRef( '' );
	const editedRef = useRef( false );
	const setPrompt = useCallback( ( nextPrompt ) => {
		editedRef.current = true;
		promptRef.current = nextPrompt;
		setValue( nextPrompt );
	}, [] );
	const hydratePrompt = useCallback( ( requestPrompt ) => {
		if ( ! editedRef.current ) {
			promptRef.current = requestPrompt || '';
			setValue( requestPrompt || '' );
		}
	}, [] );
	const resetPrompt = useCallback( () => {
		editedRef.current = false;
		promptRef.current = '';
		setValue( '' );
	}, [] );
	const getPrompt = useCallback( () => promptRef.current, [] );

	return { prompt, setPrompt, hydratePrompt, resetPrompt, getPrompt };
}
