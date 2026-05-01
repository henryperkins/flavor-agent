import { buildBlockRecommendationRequestSignature } from '../../utils/recommendation-request-signature';
import { getBlockRecommendationFreshness } from '../block-recommendation-request';

describe( 'block recommendation request freshness', () => {
	test( 'treats stored results without a stored context signature as stale', () => {
		const freshness = getBlockRecommendationFreshness( {
			clientId: 'block-1',
			recommendations: {
				prompt: 'Tighten the copy.',
				block: [ { label: 'Shorten paragraph' } ],
			},
			status: 'ready',
			storedContextSignature: '',
			liveContextSignature: 'live-context',
			prompt: 'Tighten the copy.',
		} );

		expect( freshness.clientStaleReason ).toBe( 'client' );
		expect( freshness.effectiveStaleReason ).toBe( 'client' );
		expect( freshness.hasFreshResult ).toBe( false );
		expect( freshness.isStaleResult ).toBe( true );
		expect( freshness.storedRequestSignature ).toBeNull();
	} );

	test( 'keeps passive server staleness distinct from apply-time server staleness', () => {
		const storedContextSignature = 'stored-context';
		const requestSignature = buildBlockRecommendationRequestSignature( {
			clientId: 'block-1',
			prompt: 'Tighten the copy.',
			contextSignature: storedContextSignature,
		} );
		const freshness = getBlockRecommendationFreshness( {
			clientId: 'block-1',
			recommendations: {
				prompt: 'Tighten the copy.',
				block: [ { label: 'Shorten paragraph' } ],
			},
			status: 'ready',
			storedContextSignature,
			storedStaleReason: 'server',
			liveContextSignature: storedContextSignature,
			prompt: 'Tighten the copy.',
		} );

		expect( freshness.clientStaleReason ).toBeNull();
		expect( freshness.effectiveStaleReason ).toBe( 'server' );
		expect( freshness.storedRequestSignature ).toBe( requestSignature );
		expect( freshness.hasFreshResult ).toBe( false );
		expect( freshness.isStaleResult ).toBe( true );
	} );

	test( 'preserves apply-time server staleness for apply guards', () => {
		const storedContextSignature = 'stored-context';
		const freshness = getBlockRecommendationFreshness( {
			clientId: 'block-1',
			recommendations: {
				prompt: 'Tighten the copy.',
				block: [ { label: 'Shorten paragraph' } ],
			},
			status: 'ready',
			storedContextSignature,
			storedStaleReason: 'server-apply',
			liveContextSignature: storedContextSignature,
			prompt: 'Tighten the copy.',
		} );

		expect( freshness.clientStaleReason ).toBeNull();
		expect( freshness.effectiveStaleReason ).toBe( 'server-apply' );
		expect( freshness.hasFreshResult ).toBe( false );
		expect( freshness.isStaleResult ).toBe( true );
	} );
} );
