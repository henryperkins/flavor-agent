import {
	buildBlockReviewState,
	getBlockReviewStateKey,
	isBlockReviewStateCurrent,
} from '../block-review-state';

describe( 'block review state', () => {
	test( 'keys review state by clientId, request token, request signature, and suggestion', () => {
		const state = buildBlockReviewState( {
			clientId: 'block-1',
			requestToken: 7,
			requestSignature: 'request-signature',
			suggestionKey: 'suggestion-key',
		} );

		expect( state ).toEqual( {
			clientId: 'block-1',
			requestToken: 7,
			requestSignature: 'request-signature',
			suggestionKey: 'suggestion-key',
			key: 'block-1:7:request-signature:suggestion-key',
		} );
		expect( getBlockReviewStateKey( state ) ).toBe(
			'block-1:7:request-signature:suggestion-key'
		);
	} );

	test( 'treats client, token, or signature drift as stale review state', () => {
		const state = buildBlockReviewState( {
			clientId: 'block-1',
			requestToken: 7,
			requestSignature: 'request-signature',
			suggestionKey: 'suggestion-key',
		} );

		expect(
			isBlockReviewStateCurrent( state, {
				clientId: 'block-1',
				requestToken: 7,
				requestSignature: 'request-signature',
			} )
		).toBe( true );
		expect(
			isBlockReviewStateCurrent( state, {
				clientId: 'block-2',
				requestToken: 7,
				requestSignature: 'request-signature',
			} )
		).toBe( false );
		expect(
			isBlockReviewStateCurrent( state, {
				clientId: 'block-1',
				requestToken: 8,
				requestSignature: 'request-signature',
			} )
		).toBe( false );
		expect(
			isBlockReviewStateCurrent( state, {
				clientId: 'block-1',
				requestToken: 7,
				requestSignature: 'updated-request-signature',
			} )
		).toBe( false );
	} );
} );
