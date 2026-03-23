jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );

const mockRegistrySelect = jest.fn();
const mockRegistryDispatch = jest.fn();
const mockRawHandler = jest.fn();

jest.mock( '@wordpress/data', () => ( {
	select: ( ...args ) => mockRegistrySelect( ...args ),
	dispatch: ( ...args ) => mockRegistryDispatch( ...args ),
} ) );

jest.mock( '@wordpress/editor', () => ( {
	store: 'core/editor',
} ) );

jest.mock( '@wordpress/blocks', () => ( {
	rawHandler: ( ...args ) => mockRawHandler( ...args ),
} ) );

import {
	applyTemplateSuggestionOperations,
	normalizeBlockSnapshot,
	prepareTemplateSuggestionOperations,
	prepareTemplateUndoOperations,
	undoTemplateSuggestionOperations,
} from '../template-actions';

function cloneValue( value ) {
	return JSON.parse( JSON.stringify( value ) );
}

function findBlockByClientId( blocks, clientId ) {
	for ( const block of blocks ) {
		if ( block?.clientId === clientId ) {
			return block;
		}

		if ( Array.isArray( block?.innerBlocks ) ) {
			const nested = findBlockByClientId( block.innerBlocks, clientId );

			if ( nested ) {
				return nested;
			}
		}
	}

	return null;
}

function findBlockContainer( blocks, clientId ) {
	if ( ! clientId ) {
		return blocks;
	}

	const block = findBlockByClientId( blocks, clientId );

	return Array.isArray( block?.innerBlocks ) ? block.innerBlocks : null;
}

function removeBlocksByClientIds( blocks, clientIds ) {
	for ( let index = blocks.length - 1; index >= 0; index-- ) {
		const block = blocks[ index ];

		if ( clientIds.includes( block?.clientId ) ) {
			blocks.splice( index, 1 );
			continue;
		}

		if ( Array.isArray( block?.innerBlocks ) ) {
			removeBlocksByClientIds( block.innerBlocks, clientIds );
		}
	}
}

function createParagraphBlock( clientId, content = 'Inserted by Flavor Agent' ) {
	return {
		clientId,
		name: 'core/paragraph',
		attributes: {
			content,
		},
		innerBlocks: [],
	};
}

function setupBlockEditor( {
	blocks = [],
	patterns = [],
	insertionPoint = { rootClientId: null, index: 0 },
	canInsertBlockType = () => true,
} = {} ) {
	const state = {
		blocks: cloneValue( blocks ),
		insertionPoint: cloneValue( insertionPoint ),
		patterns: cloneValue( patterns ),
	};

	const blockEditorSelect = {
		getBlocks: jest.fn( () => state.blocks ),
		getBlock: jest.fn( ( clientId ) =>
			findBlockByClientId( state.blocks, clientId )
		),
		getSettings: jest.fn( () => ( {
			__experimentalBlockPatterns: state.patterns,
		} ) ),
		getBlockInsertionPoint: jest.fn( () => state.insertionPoint ),
		canInsertBlockType: jest.fn( canInsertBlockType ),
	};

	const updateBlockAttributes = jest.fn( ( clientId, attributes ) => {
		const block = findBlockByClientId( state.blocks, clientId );

		if ( block ) {
			block.attributes = {
				...block.attributes,
				...attributes,
			};
		}
	} );
	const selectBlock = jest.fn();
	const insertBlocks = jest.fn( ( blocksToInsert, index, rootClientId ) => {
		const container = findBlockContainer( state.blocks, rootClientId );

		container.splice( index, 0, ...cloneValue( blocksToInsert ) );
	} );
	const removeBlocks = jest.fn( ( clientIds ) => {
		removeBlocksByClientIds( state.blocks, clientIds );
	} );
	const blockEditorDispatch = {
		updateBlockAttributes,
		selectBlock,
		insertBlocks,
		removeBlocks,
	};

	mockRegistrySelect.mockImplementation( ( storeName ) =>
		storeName === 'core/block-editor' ? blockEditorSelect : {}
	);
	mockRegistryDispatch.mockImplementation( ( storeName ) =>
		storeName === 'core/block-editor' ? blockEditorDispatch : {}
	);

	return {
		state,
		blockEditorSelect,
		blockEditorDispatch,
	};
}

describe( 'template-actions', () => {
	beforeEach( () => {
		mockRegistrySelect.mockReset();
		mockRegistryDispatch.mockReset();
		mockRawHandler.mockReset();
		window.flavorAgentData = {
			templatePartAreas: {
				header: 'header',
				'header-minimal': 'header',
				'header-large': 'header',
			},
		};
	} );

	test( 'prepareTemplateSuggestionOperations validates template-part and pattern operations before apply', () => {
		setupBlockEditor( {
			blocks: [
				{
					clientId: 'tp-1',
					name: 'core/template-part',
					attributes: {
						slug: 'header',
						area: 'header',
					},
					innerBlocks: [],
				},
			],
			patterns: [
				{
					name: 'theme/hero',
					title: 'Hero Banner',
					content:
						'<!-- wp:paragraph {"content":"Inserted"} /-->',
				},
			],
			insertionPoint: {
				rootClientId: null,
				index: 1,
			},
		} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Inserted' ),
		] );

		const result = prepareTemplateSuggestionOperations( {
			operations: [
				{
					type: 'replace_template_part',
					currentSlug: 'header',
					slug: 'header-minimal',
					area: 'header',
				},
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect( result.operations ).toEqual( [
			expect.objectContaining( {
				type: 'replace_template_part',
				clientId: 'tp-1',
				nextAttributes: {
					slug: 'header-minimal',
					area: 'header',
				},
				undoLocator: {
					area: 'header',
					expectedSlug: 'header-minimal',
				},
			} ),
			expect.objectContaining( {
				type: 'insert_pattern',
				patternName: 'theme/hero',
				index: 1,
				rootLocator: {
					type: 'root',
					path: [],
				},
			} ),
		] );
	} );

	test( 'prepareTemplateSuggestionOperations rejects conflicting same-area mutations before apply', () => {
		setupBlockEditor( {
			blocks: [
				{
					clientId: 'tp-1',
					name: 'core/template-part',
					attributes: {
						slug: 'header',
						area: 'header',
					},
					innerBlocks: [],
				},
			],
		} );

		const result = prepareTemplateSuggestionOperations( {
			operations: [
				{
					type: 'replace_template_part',
					currentSlug: 'header',
					slug: 'header-minimal',
					area: 'header',
				},
				{
					type: 'replace_template_part',
					currentSlug: 'header-minimal',
					slug: 'header-large',
					area: 'header',
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'This suggestion targets the “header” area more than once and cannot be applied automatically.',
		} );
	} );

	test( 'prepareTemplateSuggestionOperations rejects multiple pattern insert operations before apply', () => {
		setupBlockEditor( {
			patterns: [
				{
					name: 'theme/hero',
					title: 'Hero Banner',
					content: '<!-- wp:paragraph /-->',
				},
				{
					name: 'theme/cta',
					title: 'CTA',
					content: '<!-- wp:paragraph /-->',
				},
			],
		} );

		const result = prepareTemplateSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
				},
				{
					type: 'insert_pattern',
					patternName: 'theme/cta',
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'Only one pattern insertion can be applied automatically per suggestion.',
		} );
	} );

	test( 'prepareTemplateSuggestionOperations surfaces a stale live-template error when the target area no longer exists', () => {
		setupBlockEditor( {
			blocks: [
				{
					clientId: 'tp-footer',
					name: 'core/template-part',
					attributes: {
						slug: 'footer',
						area: 'footer',
					},
					innerBlocks: [],
				},
			],
		} );

		const result = prepareTemplateSuggestionOperations( {
			operations: [
				{
					type: 'assign_template_part',
					slug: 'header-minimal',
					area: 'header',
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'The template no longer has a live template-part block for the “header” area. Regenerate recommendations and try again.',
		} );
	} );

	test( 'applyTemplateSuggestionOperations executes updates in order and records a stable inserted snapshot', () => {
		const {
			blockEditorDispatch: {
				insertBlocks,
				selectBlock,
				updateBlockAttributes,
			},
		} = setupBlockEditor( {
			blocks: [
				{
					clientId: 'tp-1',
					name: 'core/template-part',
					attributes: {
						slug: 'header',
						area: 'header',
					},
					innerBlocks: [],
				},
			],
			patterns: [
				{
					name: 'theme/hero',
					title: 'Hero Banner',
					content:
						'<!-- wp:paragraph {"content":"Inserted"} /-->',
				},
			],
			insertionPoint: {
				rootClientId: null,
				index: 1,
			},
		} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Inserted' ),
		] );

		const result = applyTemplateSuggestionOperations( {
			operations: [
				{
					type: 'replace_template_part',
					currentSlug: 'header',
					slug: 'header-minimal',
					area: 'header',
				},
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect( updateBlockAttributes ).toHaveBeenCalledWith( 'tp-1', {
			slug: 'header-minimal',
			area: 'header',
		} );
		expect( selectBlock ).toHaveBeenCalledWith( 'tp-1' );
		expect( insertBlocks ).toHaveBeenCalledWith(
			[ createParagraphBlock( 'pattern-1', 'Inserted' ) ],
			1,
			null,
			true,
			0
		);
		expect( result.operations ).toEqual( [
			expect.objectContaining( {
				type: 'replace_template_part',
				slug: 'header-minimal',
				area: 'header',
				undoLocator: {
					area: 'header',
					expectedSlug: 'header-minimal',
				},
			} ),
			{
				type: 'insert_pattern',
				patternName: 'theme/hero',
				patternTitle: 'Hero Banner',
				rootLocator: {
					type: 'root',
					path: [],
				},
				index: 1,
				insertedBlocksSnapshot: [
					normalizeBlockSnapshot(
						createParagraphBlock( 'pattern-1', 'Inserted' )
					),
				],
			},
		] );
	} );

	test( 'applyTemplateSuggestionOperations preserves nested insertion locators for pattern inserts', () => {
		const { blockEditorDispatch } = setupBlockEditor( {
			blocks: [
				{
					clientId: 'group-1',
					name: 'core/group',
					attributes: {},
					innerBlocks: [],
				},
			],
			patterns: [
				{
					name: 'theme/hero',
					title: 'Hero Banner',
					content: '<!-- wp:paragraph /-->',
				},
			],
			insertionPoint: {
				rootClientId: 'group-1',
				index: 0,
			},
		} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Inserted' ),
		] );

		const result = applyTemplateSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect( blockEditorDispatch.insertBlocks ).toHaveBeenCalledWith(
			[ createParagraphBlock( 'pattern-1', 'Inserted' ) ],
			0,
			'group-1',
			true,
			0
		);
		expect( result.operations ).toEqual( [
			expect.objectContaining( {
				type: 'insert_pattern',
				rootLocator: {
					type: 'block',
					path: [ 0 ],
					blockName: 'core/group',
				},
				index: 0,
			} ),
		] );
	} );

	test( 'prepareTemplateSuggestionOperations fails before mutation when a pattern is missing', () => {
		setupBlockEditor( {
			patterns: [],
		} );

		const result = prepareTemplateSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/missing',
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'Pattern “theme/missing” is not available in the current editor context.',
		} );
	} );

	test( 'prepareTemplateUndoOperations resolves refresh-safe template undo targets after reload', () => {
		setupBlockEditor( {
			blocks: [
				{
					clientId: 'tp-reloaded',
					name: 'core/template-part',
					attributes: {
						slug: 'header-minimal',
						area: 'header',
					},
					innerBlocks: [],
				},
				createParagraphBlock( 'pattern-reloaded', 'Inserted' ),
			],
		} );

		const result = prepareTemplateUndoOperations( {
			after: {
				operations: [
					{
						type: 'replace_template_part',
						slug: 'header-minimal',
						area: 'header',
						nextAttributes: {
							slug: 'header-minimal',
							area: 'header',
						},
						previousAttributes: {
							slug: 'header',
							area: 'header',
						},
						undoLocator: {
							area: 'header',
							expectedSlug: 'header-minimal',
						},
					},
					{
						type: 'insert_pattern',
						patternName: 'theme/hero',
						patternTitle: 'Hero Banner',
						rootLocator: {
							type: 'root',
							path: [],
						},
						index: 1,
						insertedBlocksSnapshot: [
							normalizeBlockSnapshot(
								createParagraphBlock(
									'pattern-before-refresh',
									'Inserted'
								)
							),
						],
					},
				],
			},
		} );

		expect( result ).toEqual( {
			ok: true,
			operations: [
				{
					type: 'insert_pattern',
					insertedClientIds: [ 'pattern-reloaded' ],
					patternName: 'theme/hero',
					patternTitle: 'Hero Banner',
				},
				{
					type: 'replace_template_part',
					clientId: 'tp-reloaded',
					previousAttributes: {
						slug: 'header',
						area: 'header',
					},
				},
			],
		} );
	} );

	test( 'prepareTemplateUndoOperations rejects legacy clientId-only pattern undo metadata', () => {
		setupBlockEditor( {
			blocks: [ createParagraphBlock( 'pattern-1', 'Inserted' ) ],
		} );

		const result = prepareTemplateUndoOperations( {
			after: {
				operations: [
					{
						type: 'insert_pattern',
						patternName: 'theme/hero',
						insertedClientIds: [ 'pattern-1' ],
					},
				],
			},
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'This pattern insertion was recorded before refresh-safe undo support and cannot be undone automatically.',
		} );
	} );

	test( 'undoTemplateSuggestionOperations removes inserted blocks and restores previous template parts', () => {
		const { blockEditorDispatch, state } = setupBlockEditor( {
			blocks: [
				{
					clientId: 'tp-reloaded',
					name: 'core/template-part',
					attributes: {
						slug: 'header-minimal',
						area: 'header',
					},
					innerBlocks: [],
				},
				createParagraphBlock( 'pattern-reloaded', 'Inserted' ),
			],
		} );

		const result = undoTemplateSuggestionOperations( {
			after: {
				operations: [
					{
						type: 'replace_template_part',
						slug: 'header-minimal',
						area: 'header',
						nextAttributes: {
							slug: 'header-minimal',
							area: 'header',
						},
						previousAttributes: {
							slug: 'header',
							area: 'header',
						},
						undoLocator: {
							area: 'header',
							expectedSlug: 'header-minimal',
						},
					},
					{
						type: 'insert_pattern',
						patternName: 'theme/hero',
						patternTitle: 'Hero Banner',
						rootLocator: {
							type: 'root',
							path: [],
						},
						index: 1,
						insertedBlocksSnapshot: [
							normalizeBlockSnapshot(
								createParagraphBlock( 'pattern-before', 'Inserted' )
							),
						],
					},
				],
			},
		} );

		expect( blockEditorDispatch.removeBlocks ).toHaveBeenCalledWith(
			[ 'pattern-reloaded' ],
			false
		);
		expect( blockEditorDispatch.updateBlockAttributes ).toHaveBeenCalledWith(
			'tp-reloaded',
			{
				slug: 'header',
				area: 'header',
			}
		);
		expect( state.blocks ).toEqual( [
			{
				clientId: 'tp-reloaded',
				name: 'core/template-part',
				attributes: {
					slug: 'header',
					area: 'header',
				},
				innerBlocks: [],
			},
		] );
		expect( result.ok ).toBe( true );
	} );

	test( 'prepareTemplateUndoOperations rejects edited inserted paragraph content', () => {
		setupBlockEditor( {
			blocks: [ createParagraphBlock( 'pattern-1', 'Changed after apply' ) ],
		} );

		const result = prepareTemplateUndoOperations( {
			after: {
				operations: [
					{
						type: 'insert_pattern',
						patternName: 'theme/hero',
						rootLocator: {
							type: 'root',
							path: [],
						},
						index: 0,
						insertedBlocksSnapshot: [
							normalizeBlockSnapshot(
								createParagraphBlock( 'pattern-before', 'Inserted by Flavor Agent' )
							),
						],
					},
				],
			},
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'Inserted pattern content changed after apply and cannot be undone automatically.',
		} );
	} );

	test( 'prepareTemplateUndoOperations rejects moved inserted blocks', () => {
		setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'other-1', 'Unrelated' ),
				createParagraphBlock( 'pattern-1', 'Inserted by Flavor Agent' ),
			],
		} );

		const result = prepareTemplateUndoOperations( {
			after: {
				operations: [
					{
						type: 'insert_pattern',
						patternName: 'theme/hero',
						rootLocator: {
							type: 'root',
							path: [],
						},
						index: 0,
						insertedBlocksSnapshot: [
							normalizeBlockSnapshot(
								createParagraphBlock( 'pattern-before', 'Inserted by Flavor Agent' )
							),
						],
					},
				],
			},
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'Inserted pattern content changed after apply and cannot be undone automatically.',
		} );
	} );

	test( 'prepareTemplateUndoOperations rejects partially deleted inserted blocks', () => {
		setupBlockEditor( {
			blocks: [ createParagraphBlock( 'pattern-1', 'First block' ) ],
		} );

		const result = prepareTemplateUndoOperations( {
			after: {
				operations: [
					{
						type: 'insert_pattern',
						patternName: 'theme/hero',
						rootLocator: {
							type: 'root',
							path: [],
						},
						index: 0,
						insertedBlocksSnapshot: [
							normalizeBlockSnapshot(
								createParagraphBlock( 'pattern-before-1', 'First block' )
							),
							normalizeBlockSnapshot(
								createParagraphBlock( 'pattern-before-2', 'Second block' )
							),
						],
					},
				],
			},
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'Inserted pattern content changed after apply and cannot be undone automatically.',
		} );
	} );
} );
