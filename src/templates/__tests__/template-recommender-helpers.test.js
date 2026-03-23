import {
	buildEntityMap,
	buildTemplateFetchInput,
	buildTemplateOperationViewModel,
	buildTemplateSuggestionViewModel,
	ENTITY_ACTION_BROWSE_PATTERN,
	ENTITY_ACTION_SELECT_AREA,
	ENTITY_ACTION_SELECT_PART,
	PATTERN_BROWSE_ACTION,
	TEMPLATE_OPERATION_ASSIGN,
	TEMPLATE_OPERATION_INSERT_PATTERN,
	TEMPLATE_OPERATION_REPLACE,
	TEMPLATE_PART_REVIEW_ACTION,
} from '../template-recommender-helpers';

describe( 'template recommender helpers', () => {
	test( 'buildTemplateFetchInput trims prompt and includes visible patterns when provided', () => {
		expect(
			buildTemplateFetchInput( {
				templateRef: 'theme//single',
				templateType: 'single',
				prompt: '  Tighten the footer layout.  ',
				visiblePatternNames: [
					'theme/hero',
					'',
					'theme/hero',
					'theme/footer',
				],
			} )
		).toEqual( {
			templateRef: 'theme//single',
			templateType: 'single',
			prompt: 'Tighten the footer layout.',
			visiblePatternNames: [ 'theme/hero', 'theme/footer' ],
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

	test( 'buildTemplateOperationViewModel normalizes supported operation types', () => {
		expect(
			buildTemplateOperationViewModel( {
				type: TEMPLATE_OPERATION_REPLACE,
				currentSlug: 'header',
				slug: 'header-minimal',
				area: 'header',
			} )
		).toEqual( {
			key: 'replace_template_part|header|header-minimal|header',
			type: TEMPLATE_OPERATION_REPLACE,
			slug: 'header-minimal',
			area: 'header',
			currentSlug: 'header',
			patternName: '',
			patternTitle: '',
			badgeLabel: 'Replace',
		} );
	} );

	test( 'buildTemplateSuggestionViewModel derives review and apply data from executable operations', () => {
		const model = buildTemplateSuggestionViewModel( {
			label: 'Strengthen the footer',
			description:
				'Review the footer slot and browse a social links pattern.',
			operations: [
				{
					type: TEMPLATE_OPERATION_ASSIGN,
					slug: 'footer',
					area: 'footer',
				},
				{
					type: TEMPLATE_OPERATION_INSERT_PATTERN,
					patternName: 'theme/social-links',
				},
			],
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

		expect( model.operations ).toEqual( [
			{
				key: 'assign_template_part|footer|footer',
				type: TEMPLATE_OPERATION_ASSIGN,
				slug: 'footer',
				area: 'footer',
				currentSlug: '',
				patternName: '',
				patternTitle: '',
				badgeLabel: 'Assign',
			},
			{
				key: 'insert_pattern|theme/social-links',
				type: TEMPLATE_OPERATION_INSERT_PATTERN,
				slug: '',
				area: '',
				currentSlug: '',
				patternName: 'theme/social-links',
				patternTitle: 'theme/social-links',
				badgeLabel: 'Insert',
			},
		] );
		expect( model.canApply ).toBe( true );
	} );

	test( 'buildTemplateSuggestionViewModel resolves pattern titles from insert operations', () => {
		const model = buildTemplateSuggestionViewModel(
			{
				operations: [
					{
						type: TEMPLATE_OPERATION_INSERT_PATTERN,
						patternName: 'theme/social-links',
					},
				],
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
	} );

	test( 'buildTemplateSuggestionViewModel drops conflicting raw operations from the preview model', () => {
		const model = buildTemplateSuggestionViewModel( {
			operations: [
				{
					type: TEMPLATE_OPERATION_ASSIGN,
					slug: 'header-minimal',
					area: 'header',
				},
				{
					type: TEMPLATE_OPERATION_REPLACE,
					currentSlug: 'header-minimal',
					slug: 'header-large',
					area: 'header',
				},
			],
		} );

		expect( model.operations ).toEqual( [] );
		expect( model.canApply ).toBe( false );
		expect( model.executionError ).toContain( 'targets the “header” area more than once' );
	} );

	test( 'buildEntityMap de-dupes duplicate entity text and includes current template-part aliases', () => {
		const entities = buildEntityMap(
			[
				{
					operations: [
						{
							type: TEMPLATE_OPERATION_REPLACE,
							currentSlug: 'header',
							slug: 'header-minimal',
							area: 'header',
						},
					],
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
				operations: [
					{
						type: TEMPLATE_OPERATION_ASSIGN,
						slug: 'footer',
						area: 'site-footer',
					},
				],
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
