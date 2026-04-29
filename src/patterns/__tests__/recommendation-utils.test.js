import {
	buildRecommendedPatterns,
	getPatternBadgeReason,
} from '../recommendation-utils';

describe( 'buildRecommendedPatterns', () => {
	it( 'matches server names to allowed pattern names', () => {
		expect(
			buildRecommendedPatterns(
				[
					{ name: 'theme/hero', reason: 'Hero' },
					{ name: 'theme/footer', reason: 'Footer' },
				],
				[
					{ name: 'theme/hero', title: 'Hero' },
					{ name: 'theme/sidebar', title: 'Sidebar' },
				]
			)
		).toEqual( [
			{
				pattern: { name: 'theme/hero', title: 'Hero' },
				recommendation: { name: 'theme/hero', reason: 'Hero' },
			},
		] );
	} );

	it( 'preserves synced core/block matches', () => {
		expect(
			buildRecommendedPatterns(
				[ { name: 'core/block/77', reason: 'Reusable' } ],
				[ { name: 'core/block/77', title: 'Synced Hero' } ]
			)
		).toEqual( [
			{
				pattern: { name: 'core/block/77', title: 'Synced Hero' },
				recommendation: { name: 'core/block/77', reason: 'Reusable' },
			},
		] );
	} );

	it( 'returns empty for ranked names missing from allowed patterns', () => {
		expect(
			buildRecommendedPatterns(
				[ { name: 'theme/private', reason: 'No local match' } ],
				[ { name: 'theme/hero', title: 'Hero' } ]
			)
		).toEqual( [] );
	} );
} );

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
