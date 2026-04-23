import { getPatternBadgeReason } from '../recommendation-utils';

describe( 'getPatternBadgeReason', () => {
	it( 'returns the first high-confidence recommendation reason', () => {
		expect(
			getPatternBadgeReason( [
				{ name: 'theme/a', score: 0.82, reason: 'Too low' },
				{
					name: 'theme/b',
					score: 0.9,
					reason: 'High-confidence recommendation',
				},
			] )
		).toBe( 'High-confidence recommendation' );
	} );

	it( 'returns null when no high-confidence recommendation exists', () => {
		expect(
			getPatternBadgeReason( [
				{
					name: 'theme/a',
					score: 0.4,
					reason: 'Not enough confidence',
				},
			] )
		).toBeNull();
	} );
} );
