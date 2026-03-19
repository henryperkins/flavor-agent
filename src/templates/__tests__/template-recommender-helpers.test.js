import {
	buildEntityMap,
	buildTemplateFetchInput,
	buildTemplateSuggestionViewModel,
	ENTITY_ACTION_BROWSE_PATTERN,
	ENTITY_ACTION_SELECT_AREA,
	ENTITY_ACTION_SELECT_PART,
	PATTERN_BROWSE_ACTION,
	TEMPLATE_PART_REVIEW_ACTION,
} from '../template-recommender-helpers';

describe( 'template recommender helpers', () => {
	test( 'buildTemplateFetchInput trims prompt and omits advisory-scope drift fields', () => {
		expect(
			buildTemplateFetchInput( {
				templateRef: 'theme//single',
				templateType: 'single',
				prompt: '  Tighten the footer layout.  ',
			} )
		).toEqual( {
			templateRef: 'theme//single',
			templateType: 'single',
			prompt: 'Tighten the footer layout.',
		} );
	} );

	test( 'buildTemplateFetchInput omits blank optional values', () => {
		const input = buildTemplateFetchInput( {
			templateRef: 'theme//index',
			templateType: '',
			prompt: '   ',
		} );

		expect( input ).toEqual( { templateRef: 'theme//index' } );
		expect( input ).not.toHaveProperty( 'visiblePatternNames' );
	} );

	test( 'buildTemplateSuggestionViewModel exposes advisory-only template part review actions', () => {
		const model = buildTemplateSuggestionViewModel( {
			label: 'Strengthen the footer',
			description:
				'Review the footer slot and browse a social links pattern.',
			templateParts: [
				{
					slug: 'footer',
					area: 'footer',
					reason: 'Matches the footer area.',
				},
			],
		} );

		expect( model.templateParts ).toEqual( [
			{
				key: 'footer|footer',
				slug: 'footer',
				area: 'footer',
				reason: 'Matches the footer area.',
				actionType: TEMPLATE_PART_REVIEW_ACTION,
				ctaLabel: 'Review in editor',
			},
		] );

		expect( JSON.stringify( model ) ).not.toMatch(
			/Assign|Insert|Apply All/
		);
	} );

	test( 'buildTemplateSuggestionViewModel exposes browse-only pattern actions', () => {
		const model = buildTemplateSuggestionViewModel(
			{
				patternSuggestions: [ 'theme/social-links' ],
			},
			{
				'theme/social-links': 'Social Links',
			}
		);

		expect( model.patternSuggestions ).toEqual( [
			{
				name: 'theme/social-links',
				title: 'Social Links',
				actionType: PATTERN_BROWSE_ACTION,
				ctaLabel: 'Browse pattern',
			},
		] );
		expect( JSON.stringify( model ) ).not.toMatch(
			/Assign|Insert|Apply All/
		);
	} );

	test( 'buildEntityMap de-dupes duplicate entity text and preserves pattern title aliases', () => {
		const entities = buildEntityMap(
			[
				{
					templateParts: [
						{ slug: 'header', area: 'header' },
						{ slug: 'header', area: 'header' },
					],
					patternSuggestions: [ 'theme/hero' ],
				},
				{
					templateParts: [ { slug: 'header', area: 'header' } ],
					patternSuggestions: [ 'theme/hero' ],
				},
			],
			{ 'theme/hero': 'Hero Banner' }
		);

		expect( entities.map( ( entity ) => entity.text ) ).toEqual( [
			'Hero Banner',
			'theme/hero',
			'header',
		] );
		expect( entities[ 0 ].actionType ).toBe( ENTITY_ACTION_BROWSE_PATTERN );
		expect( entities[ 1 ].filterValue ).toBe( 'Hero Banner' );
		expect( entities[ 2 ].actionType ).toBe( ENTITY_ACTION_SELECT_PART );
	} );

	test( 'buildEntityMap keeps area actions and tolerates missing optional arrays', () => {
		const entities = buildEntityMap( [
			{
				templateParts: [ { slug: 'footer', area: 'site-footer' } ],
			},
			{
				label: 'No arrays here',
			},
		] );

		expect( entities ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					text: 'site-footer',
					actionType: ENTITY_ACTION_SELECT_AREA,
				} ),
			] )
		);
	} );
} );
