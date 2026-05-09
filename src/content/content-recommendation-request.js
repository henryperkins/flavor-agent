import { buildContentRecommendationRequestSignature } from '../utils/recommendation-request-signature';

export function getContentRecommendationFreshness( {
	contentRecommendation = null,
	storedRequestSignature = '',
	currentMode = 'draft',
	currentPrompt = '',
	currentPostContext = null,
	status = 'idle',
} = {} ) {
	const hasStoredResult =
		status === 'ready' && Boolean( contentRecommendation );
	const currentRequestSignature = buildContentRecommendationRequestSignature(
		{
			mode: currentMode,
			prompt: currentPrompt,
			postContext: currentPostContext,
		}
	);
	const normalizedStored =
		typeof storedRequestSignature === 'string'
			? storedRequestSignature
			: '';
	const isStaleResult =
		hasStoredResult &&
		( ! normalizedStored || normalizedStored !== currentRequestSignature );
	const hasFreshResult = hasStoredResult && ! isStaleResult;

	return {
		currentRequestSignature,
		hasFreshResult,
		hasStoredResult,
		isStaleResult,
		storedRequestSignature: normalizedStored,
	};
}
