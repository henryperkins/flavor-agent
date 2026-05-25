import { buildBlockRecommendationRequestSignature } from '../utils/recommendation-request-signature';

const STORED_SERVER_STALE_REASONS = new Set( [
	'server',
	'server-apply',
	'docs-grounding-unavailable',
	'docs-grounding-changed',
	'missing-resolved-signature',
] );

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
	const storedRequestSignature = storedContextSignature
		? buildBlockRecommendationRequestSignature( {
				clientId,
				prompt: recommendations?.prompt || '',
				contextSignature: storedContextSignature,
		  } )
		: null;
	const clientStaleReason =
		hasStoredResult &&
		( ! storedRequestSignature ||
			storedRequestSignature !== currentRequestSignature )
			? 'client'
			: null;
	const serverStaleReason = STORED_SERVER_STALE_REASONS.has(
		storedStaleReason
	)
		? storedStaleReason
		: null;
	const effectiveStaleReason = clientStaleReason || serverStaleReason;

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
