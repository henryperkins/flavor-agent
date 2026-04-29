function normalizeKeyPart( value ) {
	return String( value ?? '' );
}

export function getBlockReviewStateKey( {
	clientId = '',
	requestToken = 0,
	requestSignature = '',
	suggestionKey = '',
} = {} ) {
	return [
		normalizeKeyPart( clientId ),
		normalizeKeyPart( requestToken ),
		normalizeKeyPart( requestSignature ),
		normalizeKeyPart( suggestionKey ),
	].join( ':' );
}

export function buildBlockReviewState( {
	clientId = '',
	requestToken = 0,
	requestSignature = '',
	suggestionKey = '',
} = {} ) {
	const state = {
		clientId: normalizeKeyPart( clientId ),
		requestToken: Number.isFinite( requestToken ) ? requestToken : 0,
		requestSignature: normalizeKeyPart( requestSignature ),
		suggestionKey: normalizeKeyPart( suggestionKey ),
	};

	return {
		...state,
		key: getBlockReviewStateKey( state ),
	};
}

export function isBlockReviewStateCurrent(
	state,
	{ clientId = '', requestToken = 0, requestSignature = '' } = {}
) {
	if ( ! state ) {
		return false;
	}

	return (
		state.clientId === normalizeKeyPart( clientId ) &&
		state.requestToken ===
			( Number.isFinite( requestToken ) ? requestToken : 0 ) &&
		state.requestSignature === normalizeKeyPart( requestSignature )
	);
}
