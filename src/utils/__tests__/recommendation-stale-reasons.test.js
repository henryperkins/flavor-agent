import {
	getExecutableSurfaceEffectiveStaleReason,
	getExecutableSurfaceStaleMessage,
} from '../recommendation-stale-reasons';

describe( 'getExecutableSurfaceEffectiveStaleReason', () => {
	test( 'returns null when nothing is stale', () => {
		expect( getExecutableSurfaceEffectiveStaleReason( {} ) ).toBeNull();
	} );

	test( 'prefers a client-side stale reason over server signals', () => {
		expect(
			getExecutableSurfaceEffectiveStaleReason( {
				clientStaleReason: 'context-changed',
				reviewStaleReason: 'server-review',
				storedStaleReason: 'server-apply',
			} )
		).toBe( 'context-changed' );
	} );

	test( 'falls back to server-review when only the review signal is set', () => {
		expect(
			getExecutableSurfaceEffectiveStaleReason( {
				reviewStaleReason: 'server-review',
			} )
		).toBe( 'server-review' );
	} );

	test( 'ignores review stale reasons that are not "server-review"', () => {
		expect(
			getExecutableSurfaceEffectiveStaleReason( {
				reviewStaleReason: 'something-else',
			} )
		).toBeNull();
	} );

	test( 'normalizes stored "server" and "server-apply" to server-apply', () => {
		expect(
			getExecutableSurfaceEffectiveStaleReason( {
				storedStaleReason: 'server',
			} )
		).toBe( 'server-apply' );
		expect(
			getExecutableSurfaceEffectiveStaleReason( {
				storedStaleReason: 'server-apply',
			} )
		).toBe( 'server-apply' );
	} );

	test( 'ignores unrecognized stored stale reasons', () => {
		expect(
			getExecutableSurfaceEffectiveStaleReason( {
				storedStaleReason: 'pending',
			} )
		).toBeNull();
	} );
} );

describe( 'getExecutableSurfaceStaleMessage', () => {
	test( 'returns an empty string when no stale reason is provided', () => {
		expect(
			getExecutableSurfaceStaleMessage( {
				surfaceLabel: 'Style Book',
				liveContextLabel: 'the current style book',
			} )
		).toBe( '' );
	} );

	test( 'produces server-review messaging tied to the surface label', () => {
		expect(
			getExecutableSurfaceStaleMessage( {
				surfaceLabel: 'Global Styles',
				staleReasonType: 'server-review',
				liveContextLabel: 'the current site',
			} )
		).toBe(
			'This Global Styles result no longer matches the current server review context. Refresh before reviewing or applying anything from the previous result.'
		);
	} );

	test( 'produces server-apply messaging tied to the surface label', () => {
		expect(
			getExecutableSurfaceStaleMessage( {
				surfaceLabel: 'Style Book',
				staleReasonType: 'server-apply',
				liveContextLabel: 'the current style book',
			} )
		).toBe(
			'This Style Book result no longer matches the current server-resolved apply context. Refresh before reviewing or applying anything from the previous result.'
		);
	} );

	test( 'falls back to live context messaging for unrecognized stale reasons', () => {
		expect(
			getExecutableSurfaceStaleMessage( {
				surfaceLabel: 'Pattern',
				staleReasonType: 'context-changed',
				liveContextLabel: 'the current block selection',
			} )
		).toBe(
			'This Pattern result no longer matches the current block selection. Refresh before reviewing or applying anything from the previous result.'
		);
	} );
} );
