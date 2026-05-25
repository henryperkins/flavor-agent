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

	test( 'falls back to docs grounding unavailable when only the review signal is set', () => {
		expect(
			getExecutableSurfaceEffectiveStaleReason( {
				reviewStaleReason: 'docs-grounding-unavailable',
			} )
		).toBe( 'docs-grounding-unavailable' );
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

	test( 'preserves stored docs grounding and missing signature apply reasons', () => {
		expect(
			getExecutableSurfaceEffectiveStaleReason( {
				storedStaleReason: 'docs-grounding-unavailable',
			} )
		).toBe( 'docs-grounding-unavailable' );
		expect(
			getExecutableSurfaceEffectiveStaleReason( {
				storedStaleReason: 'docs-grounding-changed',
			} )
		).toBe( 'docs-grounding-changed' );
		expect(
			getExecutableSurfaceEffectiveStaleReason( {
				storedStaleReason: 'missing-resolved-signature',
			} )
		).toBe( 'missing-resolved-signature' );
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

	test( 'produces docs grounding unavailable messaging tied to the surface label', () => {
		expect(
			getExecutableSurfaceStaleMessage( {
				surfaceLabel: 'Template',
				staleReasonType: 'docs-grounding-unavailable',
				liveContextLabel: 'the current template',
			} )
		).toBe(
			'This Template result no longer has trusted WordPress Developer Docs grounding. Refresh before reviewing or applying anything from the previous result.'
		);
	} );

	test( 'produces docs grounding changed messaging tied to the surface label', () => {
		expect(
			getExecutableSurfaceStaleMessage( {
				surfaceLabel: 'Template',
				staleReasonType: 'docs-grounding-changed',
				liveContextLabel: 'the current template',
			} )
		).toBe(
			'This Template result no longer matches the current WordPress Developer Docs grounding. Refresh before reviewing or applying anything from the previous result.'
		);
	} );

	test( 'produces missing resolved signature messaging tied to the surface label', () => {
		expect(
			getExecutableSurfaceStaleMessage( {
				surfaceLabel: 'Template',
				staleReasonType: 'missing-resolved-signature',
				liveContextLabel: 'the current template',
			} )
		).toBe(
			'This Template result is missing server-resolved apply context. Refresh before reviewing or applying anything from the previous result.'
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
