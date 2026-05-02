const mockCreateBlock = jest.fn();
const mockRawHandler = jest.fn();

jest.mock( '@wordpress/blocks', () => ( {
	createBlock: ( ...args ) => mockCreateBlock( ...args ),
	rawHandler: ( ...args ) => mockRawHandler( ...args ),
} ) );

import {
	filterInsertableRecommendedPatterns,
	getRejectedPatternBlockNames,
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
} );
