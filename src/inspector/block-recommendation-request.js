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

	const trimmedPrompt = typeof prompt === 'string' ? prompt.trim() : '';
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

export function getBlockRecommendationFreshness( {
	clientId,
	recommendations = null,
	status = 'idle',
	storedContextSignature = '',
	storedStaleReason = null,
	liveContextSignature = '',
	prompt = '',
} = {} ) {
	const hasStoredResult = status === 'ready' && Boolean( recommendations );
	const currentRequestSignature = buildBlockRecommendationRequestSignature( {
		clientId,
		prompt,
		contextSignature: liveContextSignature || '',
	} );
	const storedRequestSignature = buildBlockRecommendationRequestSignature( {
		clientId,
		prompt: recommendations?.prompt || '',
		contextSignature: storedContextSignature || liveContextSignature,
	} );
	const clientStaleReason =
		hasStoredResult && storedRequestSignature !== currentRequestSignature
			? 'client'
			: null;
	const effectiveStaleReason =
		clientStaleReason ||
		( storedStaleReason === 'server' || storedStaleReason === 'server-apply'
			? 'server-apply'
			: null );

	return {
		clientStaleReason,
		currentRequestSignature,
		effectiveStaleReason,
		hasFreshResult: hasStoredResult && effectiveStaleReason === null,
		hasStoredResult,
		isStaleResult: hasStoredResult && effectiveStaleReason !== null,
		storedRequestSignature,
	};
}
