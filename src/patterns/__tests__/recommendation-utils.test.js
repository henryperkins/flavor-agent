import {
	getPatternBadgeReason,
	patchPatternMetadata,
} from '../recommendation-utils';

describe( 'patchPatternMetadata', () => {
	it( 'restores original metadata when recommendations are cleared', () => {
		const patterns = [
			{
				name: 'theme/hero',
				description: 'Original hero description',
				keywords: [ 'hero' ],
				categories: [ 'featured' ],
			},
			{
				name: 'theme/cta',
				description: 'Original CTA description',
				categories: [ 'call-to-action' ],
			},
		];
		const originalMetadata = new Map();

		const patched = patchPatternMetadata(
			patterns,
			[
				{
					name: 'theme/cta',
					reason: 'Strong CTA layout for landing pages',
					score: 0.95,
				},
			],
			originalMetadata
		);

		expect( patched[ 1 ].description ).toBe(
			'Strong CTA layout for landing pages'
		);
		expect( patched[ 1 ].categories ).toEqual( [
			'call-to-action',
			'recommended',
		] );
		expect( patched[ 1 ].keywords ).toEqual(
			expect.arrayContaining( [ 'strong', 'layout', 'landing', 'pages' ] )
		);

		const restored = patchPatternMetadata( patched, [], originalMetadata );

		expect( restored ).toEqual( patterns );
		expect( originalMetadata.size ).toBe( 0 );
	} );

	it( 'preserves the native pattern order while tagging recommendations', () => {
		const patterns = [
			{
				name: 'theme/hero',
				description: 'Hero',
				categories: [ 'featured' ],
			},
			{
				name: 'theme/gallery',
				description: 'Gallery',
				categories: [ 'media' ],
			},
		];

		const patched = patchPatternMetadata(
			patterns,
			[
				{
					name: 'theme/gallery',
					reason: 'Gallery matches the media-heavy section',
					score: 0.99,
				},
				{
					name: 'theme/hero',
					reason: 'Hero also works here',
					score: 0.91,
				},
			],
			new Map()
		);

		expect( patched.map( ( pattern ) => pattern.name ) ).toEqual( [
			'theme/hero',
			'theme/gallery',
		] );
	} );

	it( 'restores undefined optional properties exactly', () => {
		const patterns = [
			{
				name: 'theme/minimal',
				description: 'Minimal pattern',
				categories: [ 'text' ],
			},
		];
		const originalMetadata = new Map();

		const patched = patchPatternMetadata(
			patterns,
			[
				{
					name: 'theme/minimal',
					reason: 'Minimal pattern suits sparse layouts',
					score: 0.9,
				},
			],
			originalMetadata
		);
		const restored = patchPatternMetadata( patched, [], originalMetadata );

		expect( restored[ 0 ] ).not.toHaveProperty( 'keywords' );
		expect( restored[ 0 ].categories ).toEqual( [ 'text' ] );
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
