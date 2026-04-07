import {
	buildEntityMap,
	buildEditorTemplateTopLevelStructureSnapshot,
	buildEditorTemplateSlotSnapshot,
	buildTemplateFetchInput,
	buildTemplateRecommendationContextSignature,
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
	test( 'buildTemplateFetchInput trims prompt and keeps template requests template-global', () => {
		expect(
			buildTemplateFetchInput( {
				templateRef: 'theme//single',
				templateType: 'single',
				prompt: '  Tighten the footer layout.  ',
				editorSlots: {
					assignedParts: [ { slug: 'site-header', area: 'header' } ],
					emptyAreas: [ 'footer' ],
					allowedAreas: [ 'header', 'footer' ],
				},
				editorStructure: {
					topLevelBlockTree: [
						{
							path: [ 0 ],
							name: 'core/group',
						},
					],
				},
				visiblePatternNames: [
					'theme/hero',
					'theme/footer',
					'theme/hero',
				],
			} )
		).toEqual( {
			templateRef: 'theme//single',
			templateType: 'single',
			prompt: 'Tighten the footer layout.',
			editorSlots: {
				assignedParts: [ { slug: 'site-header', area: 'header' } ],
				emptyAreas: [ 'footer' ],
				allowedAreas: [ 'header', 'footer' ],
			},
			editorStructure: {
				topLevelBlockTree: [
					{
						path: [ 0 ],
						name: 'core/group',
					},
				],
			},
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
	} );

	test( 'buildTemplateFetchInput preserves an explicit empty visiblePatternNames array', () => {
		expect(
			buildTemplateFetchInput( {
				templateRef: 'theme//page',
				templateType: '',
				prompt: '   ',
				visiblePatternNames: [],
			} )
		).toEqual( {
			templateRef: 'theme//page',
			visiblePatternNames: [],
		} );
	} );

	test( 'buildTemplateRecommendationContextSignature includes normalized visible patterns', () => {
		expect(
			buildTemplateRecommendationContextSignature( {
				editorSlots: {
					assignedParts: [ { slug: 'site-header', area: 'header' } ],
				},
				visiblePatternNames: [
					'theme/hero',
					'',
					'theme/footer',
					'theme/hero',
				],
			} )
		).toBe(
			JSON.stringify( {
				editorSlots: {
					assignedParts: [ { slug: 'site-header', area: 'header' } ],
				},
				topLevelBlockTree: null,
				currentPatternOverrides: null,
				currentViewportVisibility: null,
				visiblePatternNames: [ 'theme/footer', 'theme/hero' ],
			} )
		);
	} );

	test( 'buildTemplateRecommendationContextSignature stays stable when visible patterns only reorder', () => {
		const firstSignature = buildTemplateRecommendationContextSignature( {
			editorSlots: {
				assignedParts: [ { slug: 'site-header', area: 'header' } ],
			},
			visiblePatternNames: [ 'theme/footer', 'theme/hero' ],
		} );
		const secondSignature = buildTemplateRecommendationContextSignature( {
			editorSlots: {
				assignedParts: [ { slug: 'site-header', area: 'header' } ],
			},
			visiblePatternNames: [ 'theme/hero', 'theme/footer', 'theme/hero' ],
		} );

		expect( firstSignature ).toBe( secondSignature );
	} );

	test( 'buildTemplateRecommendationContextSignature includes top-level template structure', () => {
		expect(
			buildTemplateRecommendationContextSignature( {
				editorStructure: {
					topLevelBlockTree: [
						{
							path: [ 0 ],
							name: 'core/group',
							label: 'Group',
						},
						{
							path: [ 1 ],
							name: 'core/template-part',
							label: 'site-header template part (header)',
							slot: {
								slug: 'site-header',
								area: 'header',
								isEmpty: false,
							},
						},
					],
				},
			} )
		).toBe(
			JSON.stringify( {
				editorSlots: null,
				topLevelBlockTree: [
					{
						path: [ 0 ],
						name: 'core/group',
						label: 'Group',
					},
					{
						path: [ 1 ],
						name: 'core/template-part',
						label: 'site-header template part (header)',
						slot: {
							slug: 'site-header',
							area: 'header',
							isEmpty: false,
						},
					},
				],
				currentPatternOverrides: null,
				currentViewportVisibility: null,
				visiblePatternNames: null,
			} )
		);
	} );

	test( 'buildEditorTemplateSlotSnapshot mirrors live template-part slots from the editor tree', () => {
		expect(
			buildEditorTemplateSlotSnapshot(
				[
					{
						name: 'core/group',
						attributes: {},
						innerBlocks: [
							{
								name: 'core/template-part',
								attributes: {
									slug: 'site-header',
								},
								innerBlocks: [],
							},
						],
					},
					{
						name: 'core/template-part',
						attributes: {
							area: 'footer',
						},
						innerBlocks: [],
					},
				],
				{
					'site-header': 'header',
				}
			)
		).toEqual( {
			assignedParts: [ { slug: 'site-header', area: 'header' } ],
			emptyAreas: [ 'footer' ],
			allowedAreas: [ 'footer', 'header' ],
		} );
	} );

	test( 'buildEditorTemplateTopLevelStructureSnapshot mirrors live top-level template blocks', () => {
		expect(
			buildEditorTemplateTopLevelStructureSnapshot(
				[
					{
						name: 'core/group',
						attributes: {
							tagName: 'main',
						},
						innerBlocks: [ { name: 'core/paragraph' } ],
					},
					{
						name: 'core/template-part',
						attributes: {
							slug: 'site-header',
						},
						innerBlocks: [],
					},
				],
				{
					'site-header': 'header',
				}
			)
		).toEqual( {
			topLevelBlockTree: [
				{
					path: [ 0 ],
					name: 'core/group',
					label: 'Group',
					attributes: {
						tagName: 'main',
					},
					childCount: 1,
				},
				{
					path: [ 1 ],
					name: 'core/template-part',
					label: 'site-header template part (header)',
					attributes: {
						slug: 'site-header',
					},
					childCount: 0,
					slot: {
						slug: 'site-header',
						area: 'header',
						isEmpty: false,
					},
				},
			],
			currentPatternOverrides: {
				hasOverrides: false,
				blockCount: 0,
				blockNames: [],
				blocks: [],
			},
			currentViewportVisibility: {
				hasVisibilityRules: false,
				blockCount: 0,
				blocks: [],
			},
		} );
	} );

	test( 'buildEditorTemplateTopLevelStructureSnapshot summarizes live pattern overrides and viewport visibility', () => {
		expect(
			buildEditorTemplateTopLevelStructureSnapshot( [
				{
					name: 'core/group',
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
					innerBlocks: [
						{
							name: 'core/heading',
							attributes: {
								metadata: {
									bindings: {
										content: {
											source: 'core/pattern-overrides',
										},
									},
								},
							},
							innerBlocks: [],
						},
					],
				},
			] )
		).toEqual( {
			topLevelBlockTree: [
				{
					path: [ 0 ],
					name: 'core/group',
					label: 'Group',
					attributes: {},
					childCount: 1,
				},
			],
			currentPatternOverrides: {
				hasOverrides: true,
				blockCount: 1,
				blockNames: [ 'core/heading' ],
				blocks: [
					{
						path: [ 0, 0 ],
						name: 'core/heading',
						label: 'Heading',
						overrideAttributes: [ 'content' ],
						usesDefaultBinding: false,
					},
				],
			},
			currentViewportVisibility: {
				hasVisibilityRules: true,
				blockCount: 1,
				blocks: [
					{
						path: [ 0 ],
						name: 'core/group',
						label: 'Group',
						hiddenViewports: [ 'mobile' ],
						visibleViewports: [ 'desktop' ],
					},
				],
			},
		} );
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

	test( 'buildTemplateOperationViewModel keeps anchored template insert metadata', () => {
		expect(
			buildTemplateOperationViewModel( {
				type: TEMPLATE_OPERATION_INSERT_PATTERN,
				patternName: 'theme/social-links',
				placement: 'before_block_path',
				targetPath: [ 1 ],
			} )
		).toEqual( {
			key: 'insert_pattern|theme/social-links|before_block_path|1',
			type: TEMPLATE_OPERATION_INSERT_PATTERN,
			slug: '',
			area: '',
			currentSlug: '',
			patternName: 'theme/social-links',
			patternTitle: 'theme/social-links',
			placement: 'before_block_path',
			targetPath: [ 1 ],
			badgeLabel: 'Insert',
		} );
	} );

	test( 'buildTemplateOperationViewModel omits absent optional template insert metadata', () => {
		expect(
			buildTemplateOperationViewModel( {
				type: TEMPLATE_OPERATION_INSERT_PATTERN,
				patternName: 'theme/social-links',
				placement: 'end',
			} )
		).toEqual( {
			key: 'insert_pattern|theme/social-links|end|',
			type: TEMPLATE_OPERATION_INSERT_PATTERN,
			slug: '',
			area: '',
			currentSlug: '',
			patternName: 'theme/social-links',
			patternTitle: 'theme/social-links',
			placement: 'end',
			badgeLabel: 'Insert',
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
					placement: 'before_block_path',
					targetPath: [ 1 ],
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
				key: 'insert_pattern|theme/social-links|before_block_path|1',
				type: TEMPLATE_OPERATION_INSERT_PATTERN,
				slug: '',
				area: '',
				currentSlug: '',
				patternName: 'theme/social-links',
				patternTitle: 'theme/social-links',
				placement: 'before_block_path',
				targetPath: [ 1 ],
				badgeLabel: 'Insert',
			},
		] );
		expect( model.canApply ).toBe( true );
	} );

	test( 'buildTemplateSuggestionViewModel keeps pattern-only template suggestions advisory', () => {
		const model = buildTemplateSuggestionViewModel(
			{
				patternSuggestions: [ 'theme/social-links' ],
			},
			{
				'theme/social-links': 'Social Links',
			}
		);

		expect( model.operations ).toEqual( [] );
		expect( model.patternSuggestions ).toEqual( [
			{
				name: 'theme/social-links',
				title: 'Social Links',
				actionType: PATTERN_BROWSE_ACTION,
				ctaLabel: 'Browse pattern',
			},
		] );
		expect( model.executionError ).toBe( '' );
		expect( model.canApply ).toBe( false );
	} );

	test( 'buildTemplateSuggestionViewModel keeps advisory template-part summaries when no operations are present', () => {
		const model = buildTemplateSuggestionViewModel( {
			templateParts: [
				{
					slug: 'footer-main',
					area: 'footer',
					reason: 'Populate the empty footer slot manually.',
				},
				{
					slug: 'footer-main',
					area: 'footer',
					reason: 'Duplicate should collapse.',
				},
			],
		} );

		expect( model.operations ).toEqual( [] );
		expect( model.templateParts ).toEqual( [
			{
				key: 'footer-main|footer',
				slug: 'footer-main',
				area: 'footer',
				reason: 'Populate the empty footer slot manually.',
				actionType: TEMPLATE_PART_REVIEW_ACTION,
				ctaLabel: 'Review in editor',
			},
		] );
		expect( model.executionError ).toBe( '' );
		expect( model.canApply ).toBe( false );
	} );

	test( 'buildTemplateSuggestionViewModel resolves pattern titles from insert operations', () => {
		const model = buildTemplateSuggestionViewModel(
			{
				operations: [
					{
						type: TEMPLATE_OPERATION_INSERT_PATTERN,
						patternName: 'theme/social-links',
						placement: 'start',
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
		expect( model.executionError ).toContain(
			'targets the “header” area more than once'
		);
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
