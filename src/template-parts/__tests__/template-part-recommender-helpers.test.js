import {
	buildEditorTemplatePartStructureSnapshot,
	buildTemplatePartFetchInput,
	buildTemplatePartRecommendationContextSignature,
} from '../template-part-recommender-helpers';

describe( 'template-part recommender helpers', () => {
	test( 'buildTemplatePartFetchInput trims prompt and keeps the full live structure snapshot', () => {
		expect(
			buildTemplatePartFetchInput( {
				templatePartRef: 'theme//header',
				prompt: '  Tighten the header utility row.  ',
				visiblePatternNames: [
					'theme/header-utility',
					'theme/header-minimal',
					'theme/header-utility',
				],
				editorStructure: {
					blockTree: [],
					allBlockPaths: [],
					topLevelBlocks: [],
					blockCounts: {},
					structureStats: {
						blockCount: 0,
						maxDepth: 0,
					},
					currentPatternOverrides: {
						hasOverrides: false,
						blockCount: 0,
						blockNames: [],
						blocks: [],
					},
					operationTargets: [],
					insertionAnchors: [
						{
							placement: 'start',
							label: 'Start of template part',
						},
						{
							placement: 'end',
							label: 'End of template part',
						},
					],
					structuralConstraints: {
						contentOnlyPaths: [],
						lockedPaths: [],
						hasContentOnly: false,
						hasLockedBlocks: false,
					},
				},
			} )
		).toEqual( {
			templatePartRef: 'theme//header',
			prompt: 'Tighten the header utility row.',
			visiblePatternNames: [
				'theme/header-utility',
				'theme/header-minimal',
			],
			editorStructure: {
				blockTree: [],
				allBlockPaths: [],
				topLevelBlocks: [],
				blockCounts: {},
				structureStats: {
					blockCount: 0,
					maxDepth: 0,
				},
				currentPatternOverrides: {
					hasOverrides: false,
					blockCount: 0,
					blockNames: [],
					blocks: [],
				},
				operationTargets: [],
				insertionAnchors: [
					{
						placement: 'start',
						label: 'Start of template part',
					},
					{
						placement: 'end',
						label: 'End of template part',
					},
				],
				structuralConstraints: {
					contentOnlyPaths: [],
					lockedPaths: [],
					hasContentOnly: false,
					hasLockedBlocks: false,
				},
			},
		} );
	} );

	test( 'buildTemplatePartRecommendationContextSignature changes when anchors or constraints change', () => {
		const baseStructure = {
			blockTree: [],
			allBlockPaths: [],
			topLevelBlocks: [],
			blockCounts: {},
			structureStats: {
				blockCount: 0,
				maxDepth: 0,
			},
			currentPatternOverrides: {
				hasOverrides: false,
				blockCount: 0,
				blockNames: [],
				blocks: [],
			},
			operationTargets: [],
			insertionAnchors: [
				{
					placement: 'start',
					label: 'Start of template part',
				},
				{
					placement: 'end',
					label: 'End of template part',
				},
			],
			structuralConstraints: {
				contentOnlyPaths: [],
				lockedPaths: [],
				hasContentOnly: false,
				hasLockedBlocks: false,
			},
		};

		const firstSignature = buildTemplatePartRecommendationContextSignature(
			{
				visiblePatternNames: [ 'theme/header-utility' ],
				editorStructure: baseStructure,
			}
		);
		const secondSignature = buildTemplatePartRecommendationContextSignature(
			{
				visiblePatternNames: [ 'theme/header-utility' ],
				editorStructure: {
					...baseStructure,
					insertionAnchors: [
						...baseStructure.insertionAnchors,
						{
							placement: 'before_block_path',
							targetPath: [ 0, 2 ],
							blockName: 'core/navigation',
							label: 'Before Navigation',
						},
					],
				},
			}
		);
		const thirdSignature = buildTemplatePartRecommendationContextSignature(
			{
				visiblePatternNames: [ 'theme/header-utility' ],
				editorStructure: {
					...baseStructure,
					structuralConstraints: {
						contentOnlyPaths: [ [ 0 ] ],
						lockedPaths: [],
						hasContentOnly: true,
						hasLockedBlocks: false,
					},
				},
			}
		);

		expect( firstSignature ).not.toBe( secondSignature );
		expect( secondSignature ).not.toBe( thirdSignature );
	} );

	test( 'buildEditorTemplatePartStructureSnapshot mirrors the live template-part structure and executable targets', () => {
		expect(
			buildEditorTemplatePartStructureSnapshot( [
				{
					name: 'core/group',
					attributes: {
						tagName: 'header',
						align: 'wide',
					},
					innerBlocks: [
						{
							name: 'core/site-logo',
							attributes: {},
							innerBlocks: [],
						},
						{
							name: 'core/navigation',
							attributes: {
								overlayMenu: 'mobile',
								maxNestingLevel: 2,
							},
							innerBlocks: [],
						},
					],
				},
			] )
		).toEqual( {
			blockTree: [
				{
					path: [ 0 ],
					name: 'core/group',
					label: 'Group',
					attributes: {
						tagName: 'header',
						align: 'wide',
					},
					childCount: 2,
					children: [
						{
							path: [ 0, 0 ],
							name: 'core/site-logo',
							label: 'Site Logo',
							attributes: {},
							childCount: 0,
							children: [],
						},
						{
							path: [ 0, 1 ],
							name: 'core/navigation',
							label: 'Navigation',
							attributes: {
								overlayMenu: 'mobile',
								maxNestingLevel: 2,
							},
							childCount: 0,
							children: [],
						},
					],
				},
			],
			allBlockPaths: [
				{
					path: [ 0 ],
					name: 'core/group',
					label: 'Group',
					attributes: {
						tagName: 'header',
						align: 'wide',
					},
					childCount: 2,
				},
				{
					path: [ 0, 0 ],
					name: 'core/site-logo',
					label: 'Site Logo',
					attributes: {},
					childCount: 0,
				},
				{
					path: [ 0, 1 ],
					name: 'core/navigation',
					label: 'Navigation',
					attributes: {
						overlayMenu: 'mobile',
						maxNestingLevel: 2,
					},
					childCount: 0,
				},
			],
			topLevelBlocks: [ 'core/group' ],
			blockCounts: {
				'core/group': 1,
				'core/site-logo': 1,
				'core/navigation': 1,
			},
			structureStats: {
				blockCount: 3,
				maxDepth: 2,
				hasNavigation: true,
				containsLogo: true,
				containsSiteTitle: false,
				containsSearch: false,
				containsSocialLinks: false,
				containsQuery: false,
				containsColumns: false,
				containsButtons: false,
				containsSpacer: false,
				containsSeparator: false,
				firstTopLevelBlock: 'core/group',
				lastTopLevelBlock: 'core/group',
				hasSingleWrapperGroup: true,
				isNearlyEmpty: false,
			},
			currentPatternOverrides: {
				hasOverrides: false,
				blockCount: 0,
				blockNames: [],
				blocks: [],
			},
			operationTargets: [
				{
					path: [ 0 ],
					name: 'core/group',
					label: 'Group',
					allowedOperations: [
						'replace_block_with_pattern',
						'remove_block',
					],
					allowedInsertions: [
						'before_block_path',
						'after_block_path',
					],
				},
				{
					path: [ 0, 0 ],
					name: 'core/site-logo',
					label: 'Site Logo',
					allowedOperations: [
						'replace_block_with_pattern',
						'remove_block',
					],
					allowedInsertions: [
						'before_block_path',
						'after_block_path',
					],
				},
				{
					path: [ 0, 1 ],
					name: 'core/navigation',
					label: 'Navigation',
					allowedOperations: [
						'replace_block_with_pattern',
						'remove_block',
					],
					allowedInsertions: [
						'before_block_path',
						'after_block_path',
					],
				},
			],
			insertionAnchors: [
				{
					placement: 'start',
					label: 'Start of template part',
				},
				{
					placement: 'end',
					label: 'End of template part',
				},
				{
					placement: 'before_block_path',
					targetPath: [ 0 ],
					blockName: 'core/group',
					label: 'Before Group',
				},
				{
					placement: 'after_block_path',
					targetPath: [ 0 ],
					blockName: 'core/group',
					label: 'After Group',
				},
				{
					placement: 'before_block_path',
					targetPath: [ 0, 0 ],
					blockName: 'core/site-logo',
					label: 'Before Site Logo',
				},
				{
					placement: 'after_block_path',
					targetPath: [ 0, 0 ],
					blockName: 'core/site-logo',
					label: 'After Site Logo',
				},
				{
					placement: 'before_block_path',
					targetPath: [ 0, 1 ],
					blockName: 'core/navigation',
					label: 'Before Navigation',
				},
				{
					placement: 'after_block_path',
					targetPath: [ 0, 1 ],
					blockName: 'core/navigation',
					label: 'After Navigation',
				},
			],
			structuralConstraints: {
				contentOnlyPaths: [],
				lockedPaths: [],
				hasContentOnly: false,
				hasLockedBlocks: false,
			},
		} );
	} );

	test( 'buildEditorTemplatePartStructureSnapshot keeps empty template parts explicit', () => {
		expect( buildEditorTemplatePartStructureSnapshot( [] ) ).toEqual( {
			blockTree: [],
			allBlockPaths: [],
			topLevelBlocks: [],
			blockCounts: {},
			structureStats: {
				blockCount: 0,
				maxDepth: 0,
				hasNavigation: false,
				containsLogo: false,
				containsSiteTitle: false,
				containsSearch: false,
				containsSocialLinks: false,
				containsQuery: false,
				containsColumns: false,
				containsButtons: false,
				containsSpacer: false,
				containsSeparator: false,
				firstTopLevelBlock: '',
				lastTopLevelBlock: '',
				hasSingleWrapperGroup: false,
				isNearlyEmpty: true,
			},
			currentPatternOverrides: {
				hasOverrides: false,
				blockCount: 0,
				blockNames: [],
				blocks: [],
			},
			operationTargets: [],
			insertionAnchors: [
				{
					placement: 'start',
					label: 'Start of template part',
				},
				{
					placement: 'end',
					label: 'End of template part',
				},
			],
			structuralConstraints: {
				contentOnlyPaths: [],
				lockedPaths: [],
				hasContentOnly: false,
				hasLockedBlocks: false,
			},
		} );
	} );
} );
