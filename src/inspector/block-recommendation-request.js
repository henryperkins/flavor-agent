import { buildBlockRecommendationRequestSignature } from '../utils/recommendation-request-signature';

export function buildBlockRecommendationRequestData( {
	clientId,
	liveContext = null,
	liveContextSignature = '',
	prompt = '',
} ) {
	if ( ! clientId ) {
		return {
			requestInput: null,
			requestSignature: null,
		};
	}

	const trimmedPrompt =
		typeof prompt === 'string' ? prompt.trim() : '';
	const requestSignature = buildBlockRecommendationRequestSignature( {
		clientId,
		prompt: trimmedPrompt,
		contextSignature: liveContextSignature || '',
	} );

	return {
		requestInput: liveContext
			? {
					clientId,
					editorContext: liveContext,
					contextSignature: liveContextSignature || '',
					...( trimmedPrompt ? { prompt: trimmedPrompt } : {} ),
			  }
			: null,
		requestSignature,
	};
}
