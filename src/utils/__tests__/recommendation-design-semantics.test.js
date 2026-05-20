import {
	buildBlockDesignSemantics,
	buildTemplateDesignSemantics,
	buildTemplatePartDesignSemantics,
	normalizeDesignSemantics,
} from '../recommendation-design-semantics';

describe( 'recommendation-design-semantics', () => {
	test( 'buildBlockDesignSemantics derives role, contrast, rhythm, tokens, and negative signals', () => {
		const semantics = buildBlockDesignSemantics( {
			block: {
				name: 'core/paragraph',
				title: 'Paragraph',
				currentAttributes: {
					textColor: 'contrast',
					fontSize: 'small',
				},
				inspectorPanels: {
					styles: [ 'color' ],
				},
				structuralIdentity: {
					role: 'footer-paragraph',
					location: 'footer',
					job: 'supporting-copy',
				},
			},
			parentContext: {
				block: 'core/group',
				role: 'footer',
				visualHints: {
					backgroundColor: 'contrast',
					layout: {
						type: 'constrained',
					},
				},
			},
			siblingSummariesBefore: [
				{
					block: 'core/heading',
					role: 'footer-heading',
					visualHints: {
						textAlign: 'center',
					},
				},
			],
			themeTokens: {
				colors: [
					'base: #ffffff',
					'contrast: #111111',
					'accent: #3858e9',
				],
				colorPresets: [
					{ slug: 'base', color: '#ffffff' },
					{ slug: 'contrast', color: '#111111' },
					{ slug: 'accent', color: '#3858e9' },
				],
				fontSizes: [ 'small: 0.875rem', 'heading: 2rem' ],
				fontSizePresets: [
					{ slug: 'small', size: '0.875rem' },
					{ slug: 'heading', size: '2rem' },
				],
				spacing: [ 'medium: 1.5rem', 'large: 3rem' ],
				spacingPresets: [
					{ slug: 'medium', size: '1.5rem' },
					{ slug: 'large', size: '3rem' },
				],
			},
			blockOperationContext: {
				targetClientId: 'target-1',
				targetBlockName: 'core/paragraph',
				targetSignature: 'target-signature',
				allowedPatterns: [],
			},
		} );

		expect( semantics ).toMatchObject( {
			surface: 'block',
			sectionRole: 'footer',
			visualDensity: 'balanced',
			contrastContext: 'dark-parent',
			layoutRhythm: 'constrained',
			typographyRole: 'body',
			mainDesignIssue: 'none',
			block: {
				name: 'core/paragraph',
				role: 'footer-paragraph',
				parentBlock: 'core/group',
			},
		} );
		expect( semantics.tokenAffinity.color ).toEqual(
			expect.arrayContaining( [ 'contrast' ] )
		);
		expect( semantics.tokenAffinity.fontSize ).toEqual(
			expect.arrayContaining( [ 'small' ] )
		);
		expect( semantics.negativeSignals ).toEqual(
			expect.arrayContaining( [ 'no-structural-pattern-actions' ] )
		);
	} );

	test( 'buildTemplatePartDesignSemantics preserves template-part identity and area role', () => {
		const semantics = buildTemplatePartDesignSemantics( {
			templatePartRef: 'twentytwentyfive//footer',
			slug: 'footer',
			area: 'footer',
			editorStructure: {
				topLevelBlocks: [ 'core/group' ],
				structureStats: {
					blockCount: 4,
					hasNavigation: true,
					containsSocialLinks: true,
					hasSingleWrapperGroup: true,
				},
			},
			visiblePatternNames: [ 'twentytwentyfive/footer' ],
		} );

		expect( semantics ).toMatchObject( {
			surface: 'template-part',
			sectionRole: 'footer',
			layoutRhythm: 'constrained',
			templatePart: {
				ref: 'twentytwentyfive//footer',
				slug: 'footer',
				area: 'footer',
			},
		} );
	} );

	test( 'buildTemplatePartDesignSemantics preserves header area role', () => {
		const semantics = buildTemplatePartDesignSemantics( {
			templatePartRef: 'twentytwentyfive//header',
			slug: 'header',
			area: 'header',
			editorStructure: {
				topLevelBlocks: [ 'core/group', 'core/navigation' ],
				structureStats: {
					blockCount: 3,
					hasNavigation: true,
				},
			},
			visiblePatternNames: [ 'twentytwentyfive/header' ],
		} );

		expect( semantics ).toMatchObject( {
			surface: 'template-part',
			sectionRole: 'header',
			typographyRole: 'navigation',
			templatePart: {
				ref: 'twentytwentyfive//header',
				slug: 'header',
				area: 'header',
			},
		} );
	} );

	test( 'normalizeDesignSemantics caps arrays and removes unknown top-level keys', () => {
		expect(
			normalizeDesignSemantics( {
				surface: 'block',
				sectionRole: 'hero',
				unknownKey: 'dropped',
				tokenAffinity: {
					color: [
						'base',
						'contrast',
						'accent',
						'primary',
						'secondary',
						'tertiary',
						'extra',
					],
				},
				negativeSignals: [ 'a', 'b', 'c', 'd', 'e', 'f', 'g' ],
			} )
		).toEqual( {
			surface: 'block',
			sectionRole: 'hero',
			visualDensity: 'unknown',
			contrastContext: 'unknown',
			layoutRhythm: 'unknown',
			typographyRole: 'unknown',
			tokenAffinity: {
				color: [
					'accent',
					'base',
					'contrast',
					'primary',
					'secondary',
					'tertiary',
				],
				spacing: [],
				fontSize: [],
			},
			existingDesignScore: 0,
			mainDesignIssue: 'unknown',
			negativeSignals: [ 'a', 'b', 'c', 'd', 'e', 'f' ],
		} );
	} );

	test( 'buildTemplateDesignSemantics returns stable sorted pattern affinity', () => {
		const semantics = buildTemplateDesignSemantics( {
			templateType: 'archive',
			editorSlots: {
				assignedParts: [
					{ slug: 'header', area: 'header' },
					{ slug: 'footer', area: 'footer' },
				],
				emptyAreas: [],
				allowedAreas: [ 'header', 'footer' ],
			},
			editorStructure: {
				topLevelBlocks: [ 'core/query', 'core/group' ],
				structureStats: {
					blockCount: 9,
					hasQuery: true,
					hasSingleWrapperGroup: false,
				},
			},
			visiblePatternNames: [
				'twentytwentyfive/query-card',
				'twentytwentyfive/archive-grid',
			],
		} );

		expect( semantics ).toMatchObject( {
			surface: 'template',
			sectionRole: 'archive-list',
			layoutRhythm: 'grid',
			template: {
				templateType: 'archive',
				hasHeader: true,
				hasFooter: true,
				visiblePatternCount: 2,
			},
		} );
	} );

	test( 'buildTemplateDesignSemantics reads runtime assigned parts and explicit template type', () => {
		const semantics = buildTemplateDesignSemantics( {
			templateType: 'archive',
			editorSlots: {
				assignedParts: [
					{ slug: 'site-header', area: 'header' },
					{ slug: 'site-footer', area: 'footer' },
				],
				emptyAreas: [],
				allowedAreas: [ 'header', 'footer' ],
			},
			editorStructure: {
				topLevelBlockTree: [
					{ name: 'core/template-part' },
					{ name: 'core/query' },
					{ name: 'core/template-part' },
				],
				structureStats: {
					blockCount: 9,
					hasQuery: true,
					hasSingleWrapperGroup: false,
				},
			},
			visiblePatternNames: [ 'twentytwentyfive/query-card' ],
		} );

		expect( semantics ).toMatchObject( {
			surface: 'template',
			sectionRole: 'archive-list',
			layoutRhythm: 'grid',
			template: {
				templateType: 'archive',
				hasHeader: true,
				hasFooter: true,
				visiblePatternCount: 1,
			},
		} );
	} );
} );
