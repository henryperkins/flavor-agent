const mockCreateBlock = jest.fn();
const mockRawHandler = jest.fn();
const mockGetBlockBindingsSources = jest.fn();

jest.mock( '@wordpress/blocks', () => ( {
	createBlock: ( ...args ) => mockCreateBlock( ...args ),
	getBlockBindingsSources: ( ...args ) =>
		mockGetBlockBindingsSources( ...args ),
	rawHandler: ( ...args ) => mockRawHandler( ...args ),
} ) );

import {
	filterInsertableRecommendedPatterns,
	getRejectedPatternBlockNames,
	getRejectedResolvedBlockNames,
	isSyncedPatternReference,
	resolvePatternBlocks,
} from '../pattern-insertability';

describe( 'resolvePatternBlocks', () => {
	beforeEach( () => {
		mockCreateBlock.mockReset();
		mockCreateBlock.mockImplementation( ( name, attributes ) => ( {
			name,
			attributes,
		} ) );
		mockRawHandler.mockReset();
		mockRawHandler.mockReturnValue( [] );
		mockGetBlockBindingsSources.mockReset();
		mockGetBlockBindingsSources.mockReturnValue( {} );
	} );

	test( 'resolves synced user patterns to a core/block reference', () => {
		expect(
			resolvePatternBlocks( {
				type: 'user',
				syncStatus: 'fully',
				id: 77,
			} )[ 0 ]
		).toMatchObject( {
			name: 'core/block',
			attributes: {
				ref: 77,
			},
		} );
	} );

	test( 'uses already parsed pattern blocks before parsing content', () => {
		const blocks = [ { name: 'core/paragraph', attributes: {} } ];

		expect( resolvePatternBlocks( { blocks } ) ).toBe( blocks );
	} );

	test( 'converts pattern content through the Gutenberg raw handler', () => {
		const blocks = [
			{ name: 'core/paragraph', attributes: { content: 'Hero' } },
		];

		mockRawHandler.mockReturnValue( [ blocks[ 0 ], null ] );

		expect(
			resolvePatternBlocks( {
				content:
					'<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->',
			} )
		).toEqual( blocks );
		expect( mockRawHandler ).toHaveBeenCalledWith( {
			HTML: '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->',
		} );
	} );
} );

describe( 'getRejectedPatternBlockNames', () => {
	test( 'returns rejected top-level block names for the inserter root', () => {
		const blockEditor = {
			canInsertBlockType: jest.fn(
				( blockName ) => blockName !== 'core/template-part'
			),
		};
		const pattern = {
			name: 'theme/template',
			blocks: [
				{ name: 'core/template-part', attributes: { slug: 'header' } },
				{ name: 'core/group', attributes: {} },
				{ name: 'core/template-part', attributes: { slug: 'footer' } },
			],
		};

		expect(
			getRejectedPatternBlockNames( pattern, 'root-a', blockEditor )
		).toEqual( [ 'core/template-part', 'core/template-part' ] );
		expect( blockEditor.canInsertBlockType ).toHaveBeenCalledWith(
			'core/template-part',
			'root-a'
		);
	} );
} );

describe( 'getRejectedResolvedBlockNames', () => {
	test( 'returns top-level block names not insertable at the root', () => {
		const blockEditor = {
			canInsertBlockType: jest.fn(
				( name ) => name !== 'core/template-part'
			),
		};
		const blocks = [
			{ name: 'core/template-part', attributes: {} },
			{ name: 'core/group', attributes: {} },
		];

		expect(
			getRejectedResolvedBlockNames( blocks, 'root-a', blockEditor )
		).toEqual( [ 'core/template-part' ] );
		expect( blockEditor.canInsertBlockType ).toHaveBeenCalledWith(
			'core/group',
			'root-a'
		);
	} );

	test( 'returns an empty list for null, undefined, or empty block input', () => {
		const blockEditor = { canInsertBlockType: jest.fn() };

		expect(
			getRejectedResolvedBlockNames( null, 'root-a', blockEditor )
		).toEqual( [] );
		expect(
			getRejectedResolvedBlockNames( undefined, 'root-a', blockEditor )
		).toEqual( [] );
		expect(
			getRejectedResolvedBlockNames( [], 'root-a', blockEditor )
		).toEqual( [] );
		expect( blockEditor.canInsertBlockType ).not.toHaveBeenCalled();
	} );

	test( 'returns an empty list when canInsertBlockType is unavailable', () => {
		const blocks = [
			{ name: 'core/template-part', attributes: {} },
			{ name: 'core/group', attributes: {} },
		];

		expect( getRejectedResolvedBlockNames( blocks, 'root-a', {} ) ).toEqual(
			[]
		);
		expect(
			getRejectedResolvedBlockNames( blocks, 'root-a', undefined )
		).toEqual( [] );
	} );
} );

describe( 'isSyncedPatternReference', () => {
	test( 'is true for a user pattern by type/id/syncStatus', () => {
		expect(
			isSyncedPatternReference( {
				type: 'user',
				syncStatus: 'fully',
				id: 7,
			} )
		).toBe( true );
	} );

	test( 'is true for a resolved single core/block reference', () => {
		expect(
			isSyncedPatternReference( { name: 'theme/ref' }, [
				{ name: 'core/block', attributes: { ref: 7 } },
			] )
		).toBe( true );
	} );

	test( 'is false for a normal multi-block pattern', () => {
		expect(
			isSyncedPatternReference( { name: 'theme/hero' }, [
				{ name: 'core/heading', attributes: {} },
			] )
		).toBe( false );
	} );
} );

describe( 'filterInsertableRecommendedPatterns', () => {
	test( 'keeps only recommendation pairs insertable at the active root', () => {
		const rejectedPair = {
			pattern: {
				name: 'theme/template',
				blocks: [ { name: 'core/template-part', attributes: {} } ],
			},
			recommendation: { name: 'theme/template', reason: 'Template.' },
		};
		const acceptedPair = {
			pattern: {
				name: 'theme/hero',
				blocks: [ { name: 'core/paragraph', attributes: {} } ],
			},
			recommendation: { name: 'theme/hero', reason: 'Hero.' },
		};
		const blockEditor = {
			canInsertBlockType: jest.fn(
				( blockName ) => blockName !== 'core/template-part'
			),
		};

		expect(
			filterInsertableRecommendedPatterns(
				[ rejectedPair, acceptedPair ],
				'root-a',
				blockEditor
			)
		).toEqual( [ acceptedPair ] );
	} );

	test( 'drops recommendations whose pattern resolves to no blocks', () => {
		const emptyPair = {
			pattern: {
				name: 'theme/empty',
				content: '<p>Not block parseable</p>',
			},
			recommendation: { name: 'theme/empty', reason: 'Empty.' },
		};
		const acceptedPair = {
			pattern: {
				name: 'theme/hero',
				blocks: [ { name: 'core/paragraph', attributes: {} } ],
			},
			recommendation: { name: 'theme/hero', reason: 'Hero.' },
		};
		const blockEditor = {
			canInsertBlockType: jest.fn( () => true ),
		};

		mockRawHandler.mockReturnValue( [] );

		expect(
			filterInsertableRecommendedPatterns(
				[ emptyPair, acceptedPair ],
				'root-a',
				blockEditor
			)
		).toEqual( [ acceptedPair ] );
	} );

	test( 'drops patterns bound to client sources without getValues', () => {
		const unsafePair = {
			pattern: {
				name: 'twentytwentyfive/binding-format',
				blocks: [
					{
						name: 'core/group',
						attributes: {},
						innerBlocks: [
							{
								name: 'core/paragraph',
								attributes: {
									metadata: {
										bindings: {
											content: {
												source: 'twentytwentyfive/format',
											},
										},
									},
								},
							},
						],
					},
				],
			},
			recommendation: {
				name: 'twentytwentyfive/binding-format',
				reason: 'Post format label.',
			},
		};
		const safePair = {
			pattern: {
				name: 'theme/pattern-overrides',
				blocks: [
					{
						name: 'core/paragraph',
						attributes: {
							metadata: {
								bindings: {
									content: {
										source: 'core/pattern-overrides',
									},
								},
							},
						},
					},
				],
			},
			recommendation: {
				name: 'theme/pattern-overrides',
				reason: 'Safe overrides.',
			},
		};
		const blockEditor = {
			canInsertBlockType: jest.fn( () => true ),
		};

		mockGetBlockBindingsSources.mockReturnValue( {
			'core/pattern-overrides': {
				getValues: jest.fn(),
			},
			'twentytwentyfive/format': {
				getValues: undefined,
			},
		} );

		expect(
			filterInsertableRecommendedPatterns(
				[ unsafePair, safePair ],
				'root-a',
				blockEditor
			)
		).toEqual( [ safePair ] );
	} );
} );
