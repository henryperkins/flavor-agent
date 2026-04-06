import {
	buildGlobalStyleDesignSemantics,
	buildStyleBookDesignSemantics,
} from '../style-design-semantics';

describe( 'style-design-semantics', () => {
	test( 'summarizes top-level semantic sections for global styles', () => {
		const semantics = buildGlobalStyleDesignSemantics(
			[
				{
					name: 'core/template-part',
					attributes: {
						slug: 'header',
						area: 'header',
					},
					innerBlocks: [
						{
							name: 'core/site-title',
							innerBlocks: [],
						},
						{
							name: 'core/navigation',
							innerBlocks: [],
						},
					],
				},
				{
					name: 'core/group',
					innerBlocks: [
						{
							name: 'core/query',
							attributes: {
								query: {
									inherit: true,
								},
							},
							innerBlocks: [],
						},
					],
				},
			],
			{ templateType: 'home' }
		);

		expect( semantics.surface ).toBe( 'global-styles' );
		expect( semantics.templateType ).toBe( 'home' );
		expect( semantics.sectionCount ).toBe( 2 );
		expect( semantics.sections[ 0 ] ).toMatchObject( {
			role: 'header-slot',
			location: 'header',
			templateArea: 'header',
			templatePartSlug: 'header',
			emphasisHint: 'supporting',
		} );
		expect( semantics.sections[ 0 ].childRoles ).toEqual(
			expect.arrayContaining( [
				'header-site-title',
				'primary-navigation',
			] )
		);
		expect( semantics.roleSummary ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( { value: 'primary-navigation' } ),
				expect.objectContaining( { value: 'main-query' } ),
			] )
		);
	} );

	test( 'summarizes style book occurrences with nearby structure and semantic hints', () => {
		const semantics = buildStyleBookDesignSemantics(
			[
				{
					name: 'core/template-part',
					attributes: {
						slug: 'footer',
						area: 'footer',
					},
					innerBlocks: [
						{
							name: 'core/heading',
							innerBlocks: [],
						},
						{
							name: 'core/paragraph',
							attributes: {
								metadata: {
									blockVisibility: {
										viewport: {
											mobile: false,
											desktop: true,
										},
									},
								},
							},
							innerBlocks: [],
						},
						{
							name: 'core/buttons',
							innerBlocks: [],
						},
					],
				},
			],
			{
				blockName: 'core/paragraph',
				blockTitle: 'Paragraph',
				templateType: 'home',
			}
		);

		expect( semantics.surface ).toBe( 'style-book' );
		expect( semantics.targetBlockName ).toBe( 'core/paragraph' );
		expect( semantics.occurrenceCount ).toBe( 1 );
		expect( semantics.confidence ).toBe( 'high' );
		expect( semantics.dominantRole ).toBe( 'footer-paragraph' );
		expect( semantics.dominantLocation ).toBe( 'footer' );
		expect( semantics.occurrences[ 0 ] ).toMatchObject( {
			role: 'footer-paragraph',
			location: 'footer',
			templateArea: 'footer',
			templatePartSlug: 'footer',
			densityHint: 'balanced',
			emphasisHint: 'conditional',
			hiddenViewports: [ 'mobile' ],
			visibleViewports: [ 'desktop' ],
		} );
		expect( semantics.occurrences[ 0 ].nearbyBlocks.before ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					block: 'core/heading',
				} ),
			] )
		);
		expect( semantics.occurrences[ 0 ].nearbyBlocks.after ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					block: 'core/buttons',
				} ),
			] )
		);
	} );
} );
