import {
	buildRecommendedPatterns,
	getPatternBadgeReason,
	getPatternRecommendationInsights,
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

	it( 'matches Cloudflare AI Search synced recommendations by current core/block name', () => {
		expect(
			buildRecommendedPatterns(
				[
					{
						name: 'core/block/94',
						source: 'synced',
						syncedPatternId: 94,
						reason: 'Current readable synced pattern.',
					},
				],
				[
					{
						name: 'core/block/94',
						title: 'Current Shared Banner',
					},
				]
			)
		).toEqual( [
			{
				pattern: {
					name: 'core/block/94',
					title: 'Current Shared Banner',
				},
				recommendation: {
					name: 'core/block/94',
					source: 'synced',
					syncedPatternId: 94,
					reason: 'Current readable synced pattern.',
				},
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

describe( 'getPatternRecommendationInsights', () => {
	it( 'builds display-safe insight labels from ranking metadata', () => {
		expect(
			getPatternRecommendationInsights(
				{ name: 'theme/hero', categories: [ 'featured' ] },
				{
					name: 'theme/hero',
					categories: [ 'hero' ],
					ranking: {
						sourceSignals: [ 'cloudflare_ai_search', 'llm_ranker' ],
						rankingHint: {
							matchesNearbyBlock: true,
						},
					},
				}
			)
		).toEqual( [
			'AI Search match',
			'Model ranked',
			'Category: hero',
			'Allowed here',
			'Nearby block fit',
		] );
	} );

	it( 'falls back to pattern categories and dedupes labels', () => {
		expect(
			getPatternRecommendationInsights(
				{ name: 'theme/cards', categories: [ 'cards' ] },
				{
					name: 'theme/cards',
					ranking: {
						sourceSignals: [
							'cloudflare_ai_search',
							'cloudflare_ai_search',
						],
						rankingHint: {},
					},
				}
			)
		).toEqual( [ 'AI Search match', 'Category: cards', 'Allowed here' ] );
	} );
} );
