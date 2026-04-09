export function getExecutableSurfaceEffectiveStaleReason( {
	clientStaleReason = null,
	reviewStaleReason = null,
	storedStaleReason = null,
} ) {
	if ( clientStaleReason ) {
		return clientStaleReason;
	}

	if ( reviewStaleReason === 'server-review' ) {
		return 'server-review';
	}

	if (
		storedStaleReason === 'server' ||
		storedStaleReason === 'server-apply'
	) {
		return 'server-apply';
	}

	return null;
}

export function getExecutableSurfaceStaleMessage( {
	surfaceLabel,
	staleReasonType = null,
	liveContextLabel,
} ) {
	if ( ! staleReasonType ) {
		return '';
	}

	if ( staleReasonType === 'server-review' ) {
		return `This ${ surfaceLabel } result no longer matches the current server review context. Refresh before reviewing or applying anything from the previous result.`;
	}

	if ( staleReasonType === 'server-apply' ) {
		return `This ${ surfaceLabel } result no longer matches the current server-resolved apply context. Refresh before reviewing or applying anything from the previous result.`;
	}

	return `This ${ surfaceLabel } result no longer matches ${ liveContextLabel }. Refresh before reviewing or applying anything from the previous result.`;
}
