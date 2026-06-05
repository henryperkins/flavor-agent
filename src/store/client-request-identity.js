const CLIENT_REQUEST_SESSION_ID = `flavor-agent-${ Date.now() }-${ Math.random()
	.toString( 36 )
	.slice( 2 ) }`;

export function getClientRequestSessionId() {
	return CLIENT_REQUEST_SESSION_ID;
}

export function buildClientRequestIdentity( {
	abortId = null,
	requestData = {},
	requestToken = null,
} = {} ) {
	return {
		sessionId: getClientRequestSessionId(),
		requestToken: Number.isFinite( requestToken ) ? requestToken : null,
		abortId:
			abortId === null || abortId === undefined ? '' : String( abortId ),
		scopeKey: requestData?.document?.scopeKey || '',
	};
}
