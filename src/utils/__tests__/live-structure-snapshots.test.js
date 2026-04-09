import {
	collectNestedBlockStats,
	getInnerBlocks,
	normalizeVisiblePatternNames,
	summarizeBlockAttributes,
} from '../live-structure-snapshots';

describe( 'live structure snapshot helpers', () => {
	test( 'normalizes visible pattern names with deduplication', () => {
		expect( normalizeVisiblePatternNames( null ) ).toBeNull();
		expect(
			normalizeVisiblePatternNames( [ 'hero', '', 'hero', 'footer' ] )
		).toEqual( [ 'hero', 'footer' ] );
	} );

	test( 'summarizes only scalar attributes from allowed fields', () => {
		expect(
			summarizeBlockAttributes(
				{
					tagName: 'section',
					align: 'wide',
					metadata: {
						name: 'Ignored',
					},
					columns: 3,
					featured: true,
				},
				[ 'tagName', 'align', 'columns', 'featured', 'metadata' ]
			)
		).toEqual( {
			tagName: 'section',
			align: 'wide',
			columns: 3,
			featured: true,
		} );
	} );

	test( 'collects nested block stats using the provided child accessor', () => {
		const blocks = [
			{
				name: 'core/group',
				innerBlocks: [
					{
						name: 'core/heading',
						innerBlocks: [],
					},
					{
						name: 'core/group',
						innerBlocks: [
							{
								name: 'core/paragraph',
								innerBlocks: [],
							},
						],
					},
				],
			},
		];

		expect( collectNestedBlockStats( blocks, getInnerBlocks ) ).toEqual( {
			blockCount: 4,
			maxDepth: 3,
			blockCounts: {
				'core/group': 2,
				'core/heading': 1,
				'core/paragraph': 1,
			},
		} );
	} );
} );
