jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );

const mockRegistrySelect = jest.fn();
const mockRegistryDispatch = jest.fn();
const mockRawHandler = jest.fn();
const mockCreateBlock = jest.fn();
const mockGetBlockType = jest.fn();
let generatedBlockId = 0;

jest.mock( '@wordpress/data', () => ( {
	select: ( ...args ) => mockRegistrySelect( ...args ),
	dispatch: ( ...args ) => mockRegistryDispatch( ...args ),
} ) );

jest.mock( '@wordpress/editor', () => ( {
	store: 'core/editor',
} ) );

jest.mock( '@wordpress/blocks', () => ( {
	rawHandler: ( ...args ) => mockRawHandler( ...args ),
	createBlock: ( ...args ) => mockCreateBlock( ...args ),
	getBlockType: ( ...args ) => mockGetBlockType( ...args ),
} ) );

jest.mock( '@wordpress/rich-text', () => ( {
	toHTMLString: ( { value } ) => {
		if ( typeof value?.toHTMLString === 'function' ) {
			return value.toHTMLString();
		}

		if ( typeof value === 'string' ) {
			return value;
		}

		throw new Error( 'Unsupported rich text test value.' );
	},
} ) );

import {
	applyTemplatePartSuggestionOperations,
	applyTemplateSuggestionOperations,
	getTemplateActivityUndoState,
	getTemplatePartActivityUndoState,
	normalizeBlockSnapshot,
	prepareTemplatePartSuggestionOperations,
	prepareTemplatePartUndoOperations,
	prepareTemplateSuggestionOperations,
	prepareTemplateUndoOperations,
	undoTemplatePartSuggestionOperations,
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

function createParagraphBlock(
	clientId,
	content = 'Inserted by Flavor Agent'
) {
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
	allowedPatterns,
	blockTypes = {},
	validateBlocksToTemplate,
	initialTemplateValidity = true,
	template = null,
	templateLock = false,
} = {} ) {
	const resolvedBlockTypes = {
		'core/group': {
			attributes: {},
		},
		'core/paragraph': {
			attributes: {
				content: {
					type: 'rich-text',
				},
				dropCap: {
					type: 'boolean',
					default: false,
				},
			},
		},
		'core/template-part': {
			attributes: {
				area: {
					type: 'string',
				},
				slug: {
					type: 'string',
				},
			},
		},
		...cloneValue( blockTypes ),
	};
	const state = {
		blocks: cloneValue( blocks ),
		insertionPoint: cloneValue( insertionPoint ),
		patterns: cloneValue( patterns ),
	};
	let templateValidity = initialTemplateValidity;

	const blockEditorSelect = {
		getBlocks: jest.fn( () => state.blocks ),
		getBlock: jest.fn( ( clientId ) =>
			findBlockByClientId( state.blocks, clientId )
		),
		getTemplate: jest.fn( () => template ),
		getTemplateLock: jest.fn( () => templateLock ),
		isValidTemplate: jest.fn( () => templateValidity ),
		getSettings: jest.fn( () => ( {
			__experimentalBlockPatterns: state.patterns,
		} ) ),
		getBlockInsertionPoint: jest.fn( () => state.insertionPoint ),
		canInsertBlockType: jest.fn( canInsertBlockType ),
	};

	blockEditorSelect.getAllowedPatterns = jest.fn( () =>
		cloneValue( allowedPatterns !== undefined ? allowedPatterns : patterns )
	);

	const updateBlockAttributes = jest.fn( ( clientId, attributes ) => {
		const block = findBlockByClientId( state.blocks, clientId );

		if ( block ) {
			const nextAttributes = {
				...block.attributes,
				...attributes,
			};

			for ( const [ key, value ] of Object.entries( nextAttributes ) ) {
				if ( value === undefined ) {
					delete nextAttributes[ key ];
				}
			}

			block.attributes = nextAttributes;
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
	const validateTemplate = jest.fn( ( nextBlocks ) => {
		if ( typeof validateBlocksToTemplate === 'function' ) {
			templateValidity = validateBlocksToTemplate(
				cloneValue( nextBlocks )
			);
		}

		return templateValidity;
	} );
	const blockEditorDispatch = {
		updateBlockAttributes,
		selectBlock,
		insertBlocks,
		removeBlocks,
		validateBlocksToTemplate: validateTemplate,
	};

	mockRegistrySelect.mockImplementation( ( storeName ) =>
		storeName === 'core/block-editor' ? blockEditorSelect : {}
	);
	mockRegistryDispatch.mockImplementation( ( storeName ) =>
		storeName === 'core/block-editor' ? blockEditorDispatch : {}
	);
	mockGetBlockType.mockImplementation(
		( name ) => resolvedBlockTypes[ name ] || undefined
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
		mockCreateBlock.mockReset();
		mockGetBlockType.mockReset();
		generatedBlockId = 0;
		mockCreateBlock.mockImplementation(
			( name, attributes = {}, innerBlocks = [] ) => ( {
				clientId: `generated-${ ++generatedBlockId }`,
				name,
				attributes,
				innerBlocks,
			} )
		);
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
					content: '<!-- wp:paragraph {"content":"Inserted"} /-->',
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
					placement: 'start',
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
				placement: 'start',
				index: 0,
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

	test( 'prepareTemplatePartSuggestionOperations rejects non-canonical block paths', () => {
		setupBlockEditor( {
			blocks: [ createParagraphBlock( 'existing-1', 'Existing' ) ],
		} );

		const result = prepareTemplatePartSuggestionOperations( {
			operations: [
				{
					type: 'remove_block',
					expectedBlockName: 'core/paragraph',
					targetPath: [ '' ],
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'Template-part block removals must include expectedBlockName and targetPath.',
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
					placement: 'start',
				},
				{
					type: 'insert_pattern',
					patternName: 'theme/cta',
					placement: 'end',
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'Only one pattern insertion can be applied automatically per suggestion.',
		} );
	} );

	test( 'prepareTemplateSuggestionOperations rejects template inserts without explicit placement', () => {
		setupBlockEditor( {
			patterns: [
				{
					name: 'theme/hero',
					title: 'Hero Banner',
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
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'Template pattern insertions must include a placement.',
		} );
	} );

	test( 'prepareTemplateSuggestionOperations rejects template inserts that include a targetPath without explicit placement', () => {
		setupBlockEditor( {
			patterns: [
				{
					name: 'theme/hero',
					title: 'Hero Banner',
					content: '<!-- wp:paragraph /-->',
				},
			],
		} );

		const result = prepareTemplateSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
					targetPath: [ 0 ],
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'Template pattern insertions must include a placement.',
		} );
	} );

	test( 'prepareTemplateSuggestionOperations rejects legacy template inserts with malformed target paths', () => {
		setupBlockEditor( {
			patterns: [
				{
					name: 'theme/hero',
					title: 'Hero Banner',
					content: '<!-- wp:paragraph /-->',
				},
			],
		} );

		const result = prepareTemplateSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
					targetPath: [],
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'Template pattern insertions that include a targetPath must provide a non-empty array of non-negative indexes.',
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
					content: '<!-- wp:paragraph {"content":"Inserted"} /-->',
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
					placement: 'start',
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
			0,
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
				placement: 'start',
				targetPath: null,
				expectedTarget: null,
				targetBlockName: '',
				rootLocator: {
					type: 'root',
					path: [],
				},
				index: 0,
				insertedBlocksSnapshot: [
					normalizeBlockSnapshot(
						createParagraphBlock( 'pattern-1', 'Inserted' )
					),
				],
			},
		] );
	} );

	test( 'prepareTemplateSuggestionOperations accepts built-in template-part area slugs without localized registry data', () => {
		window.flavorAgentData = {
			templatePartAreas: {},
		};
		setupBlockEditor( {
			blocks: [
				{
					clientId: 'tp-1',
					name: 'core/template-part',
					attributes: {
						area: 'header',
					},
					innerBlocks: [],
				},
			],
		} );

		const result = prepareTemplateSuggestionOperations( {
			operations: [
				{
					type: 'assign_template_part',
					slug: 'header',
					area: 'header',
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect( result.operations ).toEqual( [
			expect.objectContaining( {
				type: 'assign_template_part',
				clientId: 'tp-1',
				nextAttributes: {
					slug: 'header',
					area: 'header',
				},
			} ),
		] );
	} );

	test( 'applyTemplateSuggestionOperations rolls back earlier template-part changes when a later insert fails', () => {
		const { state, blockEditorDispatch } = setupBlockEditor( {
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
					content: '<!-- wp:paragraph {"content":"Inserted"} /-->',
				},
			],
		} );
		blockEditorDispatch.insertBlocks.mockImplementation( () => {} );
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
					placement: 'end',
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'Pattern “Hero Banner” could not be inserted into this template.',
		} );
		expect( state.blocks[ 0 ].attributes ).toEqual( {
			slug: 'header',
			area: 'header',
		} );
		expect(
			blockEditorDispatch.updateBlockAttributes
		).toHaveBeenLastCalledWith( 'tp-1', {
			slug: 'header',
			area: 'header',
		} );
	} );

	test( 'normalizeBlockSnapshot serializes rich text attribute values to stable HTML strings', () => {
		const snapshot = normalizeBlockSnapshot( {
			clientId: 'rich-1',
			name: 'core/paragraph',
			attributes: {
				content: {
					toHTMLString: () => 'Inserted by Flavor Agent',
				},
			},
			innerBlocks: [],
		} );

		expect( snapshot ).toEqual( {
			name: 'core/paragraph',
			attributes: {
				content: 'Inserted by Flavor Agent',
			},
			innerBlocks: [],
		} );
	} );

	test( 'applyTemplateSuggestionOperations anchors template start insertions at the root container', () => {
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
					placement: 'start',
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect( blockEditorDispatch.insertBlocks ).toHaveBeenCalledWith(
			[ createParagraphBlock( 'pattern-1', 'Inserted' ) ],
			0,
			null,
			true,
			0
		);
		expect( result.operations ).toEqual( [
			expect.objectContaining( {
				type: 'insert_pattern',
				rootLocator: {
					type: 'root',
					path: [],
				},
				index: 0,
			} ),
		] );
	} );

	test( 'applyTemplateSuggestionOperations records the intended snapshot when the inserted slice is stale', () => {
		const { blockEditorDispatch } = setupBlockEditor( {
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
					content: '<!-- wp:paragraph {"content":"Inserted"} /-->',
				},
			],
		} );
		blockEditorDispatch.insertBlocks.mockImplementation( () => {} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Inserted' ),
		] );

		const result = applyTemplateSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
					placement: 'start',
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect( result.operations ).toEqual( [
			expect.objectContaining( {
				type: 'insert_pattern',
				insertedBlocksSnapshot: [
					normalizeBlockSnapshot(
						createParagraphBlock( 'pattern-1', 'Inserted' )
					),
				],
			} ),
		] );
	} );

	test( 'prepareTemplateSuggestionOperations supports anchored insertions before a target block path', () => {
		setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Intro' ),
				createParagraphBlock( 'existing-2', 'Target' ),
			],
			patterns: [
				{
					name: 'theme/hero',
					title: 'Hero Banner',
					content: '<!-- wp:paragraph {"content":"Inserted"} /-->',
				},
			],
		} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Inserted' ),
		] );

		const result = prepareTemplateSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
					placement: 'before_block_path',
					targetPath: [ 1 ],
					expectedTarget: {
						name: 'core/paragraph',
						label: 'Paragraph',
						attributes: {
							content: 'Target',
						},
						childCount: 0,
					},
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect( result.operations ).toEqual( [
			expect.objectContaining( {
				type: 'insert_pattern',
				patternName: 'theme/hero',
				placement: 'before_block_path',
				targetPath: [ 1 ],
				targetBlockName: 'core/paragraph',
				rootLocator: {
					type: 'root',
					path: [],
				},
				index: 1,
			} ),
		] );
	} );

	test( 'prepareTemplateSuggestionOperations does not require childCount when the anchored target has children', () => {
		setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Intro' ),
				{
					clientId: 'group-1',
					name: 'core/group',
					attributes: {},
					innerBlocks: [
						createParagraphBlock( 'nested-1', 'Nested target' ),
					],
				},
			],
			patterns: [
				{
					name: 'theme/hero',
					title: 'Hero Banner',
					content: '<!-- wp:paragraph {"content":"Inserted"} /-->',
				},
			],
		} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Inserted' ),
		] );

		const result = prepareTemplateSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
					placement: 'before_block_path',
					targetPath: [ 1 ],
					expectedTarget: {
						name: 'core/group',
						label: 'Group',
					},
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect( result.operations ).toEqual( [
			expect.objectContaining( {
				type: 'insert_pattern',
				targetPath: [ 1 ],
				targetBlockName: 'core/group',
				index: 1,
			} ),
		] );
	} );

	test( 'applyTemplateSuggestionOperations records anchored template insertion metadata', () => {
		const { blockEditorDispatch } = setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Intro' ),
				createParagraphBlock( 'existing-2', 'Target' ),
			],
			patterns: [
				{
					name: 'theme/hero',
					title: 'Hero Banner',
					content: '<!-- wp:paragraph {"content":"Inserted"} /-->',
				},
			],
		} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Inserted' ),
		] );

		const result = applyTemplateSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
					placement: 'before_block_path',
					targetPath: [ 1 ],
					expectedTarget: {
						name: 'core/paragraph',
						label: 'Paragraph',
						attributes: {
							content: 'Target',
						},
						childCount: 0,
					},
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect( blockEditorDispatch.insertBlocks ).toHaveBeenCalledWith(
			[ createParagraphBlock( 'pattern-1', 'Inserted' ) ],
			1,
			null,
			true,
			0
		);
		expect( result.operations ).toEqual( [
			{
				type: 'insert_pattern',
				patternName: 'theme/hero',
				patternTitle: 'Hero Banner',
				placement: 'before_block_path',
				targetPath: [ 1 ],
				expectedTarget: {
					name: 'core/paragraph',
					label: 'Paragraph',
					attributes: {
						content: 'Target',
					},
					childCount: 0,
				},
				targetBlockName: 'core/paragraph',
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

	test( 'applyTemplateSuggestionOperations fails when inserted blocks are not readable immediately', () => {
		const { blockEditorDispatch } = setupBlockEditor( {
			blocks: [ createParagraphBlock( 'existing-1', 'Intro' ) ],
			patterns: [
				{
					name: 'theme/hero',
					title: 'Hero Banner',
					content: '<!-- wp:paragraph {"content":"Inserted"} /-->',
				},
			],
		} );
		blockEditorDispatch.insertBlocks.mockImplementation( () => {} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Inserted' ),
		] );

		const result = applyTemplateSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
					placement: 'end',
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'Pattern “Hero Banner” could not be inserted into this template.',
		} );
	} );

	test( 'applyTemplateSuggestionOperations rolls back when WordPress template validation fails', () => {
		const { state, blockEditorDispatch } = setupBlockEditor( {
			blocks: [ createParagraphBlock( 'existing-1', 'Intro' ) ],
			patterns: [
				{
					name: 'theme/hero',
					title: 'Hero Banner',
					content: '<!-- wp:paragraph {"content":"Inserted"} /-->',
				},
			],
			template: [ [ 'core/paragraph', {} ] ],
			validateBlocksToTemplate: ( currentBlocks ) =>
				currentBlocks.length === 1,
		} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Inserted' ),
		] );

		const result = applyTemplateSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
					placement: 'start',
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'Flavor Agent could not keep this document aligned with the current WordPress template constraints. The changes were reverted.',
		} );
		expect( blockEditorDispatch.insertBlocks ).toHaveBeenCalled();
		expect(
			blockEditorDispatch.validateBlocksToTemplate
		).toHaveBeenCalled();
		expect( blockEditorDispatch.removeBlocks ).toHaveBeenCalledWith(
			[ 'pattern-1' ],
			false
		);
		expect( state.blocks ).toEqual( [
			createParagraphBlock( 'existing-1', 'Intro' ),
		] );
	} );

	test( 'prepareTemplateSuggestionOperations rejects anchored template insertions when the target no longer matches', () => {
		setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Intro' ),
				createParagraphBlock( 'existing-2', 'Changed target' ),
			],
			patterns: [
				{
					name: 'theme/hero',
					title: 'Hero Banner',
					content: '<!-- wp:paragraph {"content":"Inserted"} /-->',
				},
			],
		} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Inserted' ),
		] );

		const result = prepareTemplateSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
					placement: 'before_block_path',
					targetPath: [ 1 ],
					expectedTarget: {
						name: 'core/paragraph',
						label: 'Paragraph',
						attributes: {
							content: 'Target',
						},
						childCount: 0,
					},
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'The anchored insertion target at path 1 no longer matches the expected Paragraph. Regenerate recommendations and try again.',
		} );
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
					placement: 'start',
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'Pattern “theme/missing” is not available in the current editor context.',
		} );
	} );

	test( 'prepareTemplateSuggestionOperations fails before mutation when a pattern is not allowed at the live insertion root', () => {
		setupBlockEditor( {
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
					content: '<!-- wp:paragraph {"content":"Inserted"} /-->',
				},
			],
			allowedPatterns: [],
			insertionPoint: {
				rootClientId: 'group-1',
				index: 0,
			},
		} );

		const result = prepareTemplateSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
					placement: 'start',
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'Pattern “theme/hero” is not available in the current editor context.',
		} );
	} );

	test( 'prepareTemplatePartSuggestionOperations validates explicit template-part placement before apply', () => {
		setupBlockEditor( {
			blocks: [ createParagraphBlock( 'existing-1', 'Existing' ) ],
			patterns: [
				{
					name: 'theme/header-utility',
					title: 'Header Utility',
					content: '<!-- wp:paragraph {"content":"Utility"} /-->',
				},
			],
		} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Utility' ),
		] );

		const result = prepareTemplatePartSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/header-utility',
					placement: 'start',
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect( result.operations ).toEqual( [
			expect.objectContaining( {
				type: 'insert_pattern',
				patternName: 'theme/header-utility',
				placement: 'start',
				index: 0,
				rootLocator: {
					type: 'root',
					path: [],
				},
			} ),
		] );
	} );

	test( 'prepareTemplatePartSuggestionOperations keeps start and end at the template-part root when a single group exists', () => {
		setupBlockEditor( {
			blocks: [
				{
					clientId: 'group-1',
					name: 'core/group',
					attributes: {},
					innerBlocks: [
						createParagraphBlock( 'existing-1', 'Existing' ),
						createParagraphBlock( 'existing-2', 'Target' ),
					],
				},
			],
			patterns: [
				{
					name: 'theme/header-utility',
					title: 'Header Utility',
					content: '<!-- wp:paragraph {"content":"Utility"} /-->',
				},
			],
		} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Utility' ),
		] );

		const result = prepareTemplatePartSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/header-utility',
					placement: 'end',
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect( result.operations ).toEqual( [
			expect.objectContaining( {
				type: 'insert_pattern',
				patternName: 'theme/header-utility',
				placement: 'end',
				index: 1,
				rootLocator: {
					type: 'root',
					path: [],
				},
			} ),
		] );
	} );

	test( 'prepareTemplatePartSuggestionOperations supports anchored insertions before a target block path', () => {
		setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Existing' ),
				createParagraphBlock( 'existing-2', 'Target' ),
			],
			patterns: [
				{
					name: 'theme/header-utility',
					title: 'Header Utility',
					content: '<!-- wp:paragraph {"content":"Utility"} /-->',
				},
			],
		} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Utility' ),
		] );

		const result = prepareTemplatePartSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/header-utility',
					placement: 'before_block_path',
					targetPath: [ 1 ],
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect( result.operations ).toEqual( [
			expect.objectContaining( {
				type: 'insert_pattern',
				patternName: 'theme/header-utility',
				placement: 'before_block_path',
				targetPath: [ 1 ],
				index: 1,
				rootLocator: {
					type: 'root',
					path: [],
				},
			} ),
		] );
	} );

	test( 'prepareTemplatePartSuggestionOperations rejects anchored insertions when the target fingerprint no longer matches', () => {
		setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Existing' ),
				createParagraphBlock( 'existing-2', 'Changed target' ),
			],
			patterns: [
				{
					name: 'theme/header-utility',
					title: 'Header Utility',
					content: '<!-- wp:paragraph {"content":"Utility"} /-->',
				},
			],
		} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Utility' ),
		] );

		const result = prepareTemplatePartSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/header-utility',
					placement: 'before_block_path',
					targetPath: [ 1 ],
					expectedTarget: {
						name: 'core/paragraph',
						label: 'Paragraph',
						attributes: {
							content: 'Target',
						},
						childCount: 0,
					},
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'The anchored insertion target at path 1 no longer matches the expected Paragraph. Regenerate recommendations and try again.',
		} );
	} );

	test( 'prepareTemplatePartSuggestionOperations rejects missing explicit placement', () => {
		setupBlockEditor( {
			patterns: [
				{
					name: 'theme/header-utility',
					title: 'Header Utility',
					content: '<!-- wp:paragraph /-->',
				},
			],
		} );

		const result = prepareTemplatePartSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/header-utility',
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'Template-part pattern insertions must include both a pattern name and placement.',
		} );
	} );

	test( 'applyTemplatePartSuggestionOperations records refresh-safe root insertion metadata', () => {
		const { blockEditorDispatch } = setupBlockEditor( {
			blocks: [ createParagraphBlock( 'existing-1', 'Existing' ) ],
			patterns: [
				{
					name: 'theme/header-utility',
					title: 'Header Utility',
					content: '<!-- wp:paragraph {"content":"Utility"} /-->',
				},
			],
		} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Utility' ),
		] );

		const result = applyTemplatePartSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/header-utility',
					placement: 'end',
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect( blockEditorDispatch.insertBlocks ).toHaveBeenCalledWith(
			[ createParagraphBlock( 'pattern-1', 'Utility' ) ],
			1,
			null,
			true,
			0
		);
		expect( result.operations ).toEqual( [
			{
				type: 'insert_pattern',
				patternName: 'theme/header-utility',
				patternTitle: 'Header Utility',
				placement: 'end',
				targetPath: null,
				expectedTarget: null,
				targetBlockName: '',
				rootLocator: {
					type: 'root',
					path: [],
				},
				index: 1,
				insertedBlocksSnapshot: [
					normalizeBlockSnapshot(
						createParagraphBlock( 'pattern-1', 'Utility' )
					),
				],
			},
		] );
	} );

	test( 'applyTemplatePartSuggestionOperations replaces a targeted block with a pattern snapshot', () => {
		const { blockEditorDispatch, state } = setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Keep' ),
				createParagraphBlock( 'existing-2', 'Replace me' ),
			],
			patterns: [
				{
					name: 'theme/header-utility',
					title: 'Header Utility',
					content: '<!-- wp:paragraph {"content":"Utility"} /-->',
				},
			],
		} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Utility' ),
		] );

		const result = applyTemplatePartSuggestionOperations( {
			operations: [
				{
					type: 'replace_block_with_pattern',
					patternName: 'theme/header-utility',
					expectedBlockName: 'core/paragraph',
					targetPath: [ 1 ],
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect( blockEditorDispatch.removeBlocks ).toHaveBeenCalledWith(
			[ 'existing-2' ],
			false
		);
		expect( blockEditorDispatch.insertBlocks ).toHaveBeenCalledWith(
			[ createParagraphBlock( 'pattern-1', 'Utility' ) ],
			1,
			null,
			true,
			0
		);
		expect( result.operations ).toEqual( [
			{
				type: 'replace_block_with_pattern',
				patternName: 'theme/header-utility',
				patternTitle: 'Header Utility',
				expectedBlockName: 'core/paragraph',
				expectedTarget: {
					name: 'core/paragraph',
				},
				targetPath: [ 1 ],
				rootLocator: {
					type: 'root',
					path: [],
				},
				index: 1,
				removedBlocksSnapshot: [
					normalizeBlockSnapshot(
						createParagraphBlock( 'existing-2', 'Replace me' )
					),
				],
				insertedBlocksSnapshot: [
					normalizeBlockSnapshot(
						createParagraphBlock( 'pattern-1', 'Utility' )
					),
				],
			},
		] );
		expect( state.blocks ).toEqual( [
			createParagraphBlock( 'existing-1', 'Keep' ),
			createParagraphBlock( 'pattern-1', 'Utility' ),
		] );
	} );

	test( 'prepareTemplatePartSuggestionOperations rejects replacing a locked block', () => {
		setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Keep' ),
				{
					...createParagraphBlock( 'existing-2', 'Replace me' ),
					attributes: {
						content: 'Replace me',
						lock: {
							remove: true,
						},
					},
				},
			],
			patterns: [
				{
					name: 'theme/header-utility',
					title: 'Header Utility',
					content: '<!-- wp:paragraph {"content":"Utility"} /-->',
				},
			],
		} );

		const result = prepareTemplatePartSuggestionOperations( {
			operations: [
				{
					type: 'replace_block_with_pattern',
					patternName: 'theme/header-utility',
					expectedBlockName: 'core/paragraph',
					targetPath: [ 1 ],
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'The target block at path 1 is locked and cannot be replaced automatically.',
		} );
	} );

	test( 'prepareTemplatePartSuggestionOperations rejects replacing a block inside a locked container', () => {
		setupBlockEditor( {
			blocks: [
				{
					clientId: 'group-1',
					name: 'core/group',
					attributes: {
						templateLock: 'all',
					},
					innerBlocks: [
						createParagraphBlock( 'existing-2', 'Replace me' ),
					],
				},
			],
			patterns: [
				{
					name: 'theme/header-utility',
					title: 'Header Utility',
					content: '<!-- wp:paragraph {"content":"Utility"} /-->',
				},
			],
		} );

		const result = prepareTemplatePartSuggestionOperations( {
			operations: [
				{
					type: 'replace_block_with_pattern',
					patternName: 'theme/header-utility',
					expectedBlockName: 'core/paragraph',
					targetPath: [ 0, 0 ],
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'The target block at path 0 > 0 is inside a locked container and cannot be replaced automatically.',
		} );
	} );

	test( 'applyTemplatePartSuggestionOperations removes a targeted block and records an undo anchor', () => {
		const { blockEditorDispatch, state } = setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Keep' ),
				createParagraphBlock( 'existing-2', 'Remove me' ),
				createParagraphBlock( 'existing-3', 'After me' ),
			],
		} );

		const result = applyTemplatePartSuggestionOperations( {
			operations: [
				{
					type: 'remove_block',
					expectedBlockName: 'core/paragraph',
					targetPath: [ 1 ],
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect( blockEditorDispatch.removeBlocks ).toHaveBeenCalledWith(
			[ 'existing-2' ],
			false
		);
		expect( result.operations ).toEqual( [
			{
				type: 'remove_block',
				expectedBlockName: 'core/paragraph',
				expectedTarget: {
					name: 'core/paragraph',
				},
				targetPath: [ 1 ],
				rootLocator: {
					type: 'root',
					path: [],
				},
				index: 1,
				removedBlocksSnapshot: [
					normalizeBlockSnapshot(
						createParagraphBlock( 'existing-2', 'Remove me' )
					),
				],
				postApplyAnchor: {
					type: 'next-block',
					blocksSnapshot: [
						normalizeBlockSnapshot(
							createParagraphBlock( 'existing-3', 'After me' )
						),
					],
				},
			},
		] );
		expect( state.blocks ).toEqual( [
			createParagraphBlock( 'existing-1', 'Keep' ),
			createParagraphBlock( 'existing-3', 'After me' ),
		] );
	} );

	test( 'applyTemplatePartSuggestionOperations restores the removed block when replacement insertion fails', () => {
		const { blockEditorDispatch, state } = setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Keep' ),
				createParagraphBlock( 'existing-2', 'Replace me' ),
			],
			patterns: [
				{
					name: 'theme/header-utility',
					title: 'Header Utility',
					content: '<!-- wp:paragraph {"content":"Utility"} /-->',
				},
			],
		} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Utility' ),
		] );
		blockEditorDispatch.insertBlocks
			.mockImplementationOnce( () => {} )
			.mockImplementation( ( blocksToInsert, index, rootClientId ) => {
				const container = findBlockContainer(
					state.blocks,
					rootClientId
				);
				container.splice( index, 0, ...cloneValue( blocksToInsert ) );
			} );

		const result = applyTemplatePartSuggestionOperations( {
			operations: [
				{
					type: 'replace_block_with_pattern',
					patternName: 'theme/header-utility',
					expectedBlockName: 'core/paragraph',
					targetPath: [ 1 ],
					expectedTarget: {
						name: 'core/paragraph',
						label: 'Paragraph',
						attributes: {
							content: 'Replace me',
						},
						childCount: 0,
					},
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'Pattern “Header Utility” could not replace the targeted block in this template part.',
		} );
		expect( state.blocks ).toHaveLength( 2 );
		expect( state.blocks[ 0 ] ).toEqual(
			createParagraphBlock( 'existing-1', 'Keep' )
		);
		expect( state.blocks[ 1 ] ).toEqual(
			expect.objectContaining( {
				name: 'core/paragraph',
				attributes: {
					content: 'Replace me',
				},
				innerBlocks: [],
			} )
		);
	} );

	test( 'applyTemplatePartSuggestionOperations rolls back earlier operations when a later operation fails', () => {
		const { blockEditorDispatch, state } = setupBlockEditor( {
			blocks: [ createParagraphBlock( 'existing-1', 'Existing' ) ],
			patterns: [
				{
					name: 'theme/header-utility',
					title: 'Header Utility',
					content: '<!-- wp:paragraph {"content":"Utility"} /-->',
				},
			],
		} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Utility' ),
		] );
		blockEditorDispatch.removeBlocks.mockImplementation( ( clientIds ) => {
			if ( clientIds.includes( 'existing-1' ) ) {
				return;
			}

			removeBlocksByClientIds( state.blocks, clientIds );
		} );

		const result = applyTemplatePartSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/header-utility',
					placement: 'end',
				},
				{
					type: 'remove_block',
					expectedBlockName: 'core/paragraph',
					targetPath: [ 0 ],
					expectedTarget: {
						name: 'core/paragraph',
						label: 'Paragraph',
						attributes: {
							content: 'Existing',
						},
						childCount: 0,
					},
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'The target block at path 0 could not be removed automatically.',
		} );
		expect( state.blocks ).toEqual( [
			createParagraphBlock( 'existing-1', 'Existing' ),
		] );
	} );

	test( 'applyTemplatePartSuggestionOperations records the intended snapshot when the inserted slice is not readable immediately', () => {
		const { blockEditorDispatch } = setupBlockEditor( {
			blocks: [ createParagraphBlock( 'existing-1', 'Existing' ) ],
			patterns: [
				{
					name: 'theme/header-utility',
					title: 'Header Utility',
					content: '<!-- wp:paragraph {"content":"Utility"} /-->',
				},
			],
		} );
		blockEditorDispatch.insertBlocks.mockImplementation( () => {} );
		mockRawHandler.mockReturnValue( [
			createParagraphBlock( 'pattern-1', 'Utility' ),
		] );

		const result = applyTemplatePartSuggestionOperations( {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/header-utility',
					placement: 'end',
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'Pattern “Header Utility” could not be inserted into this template part.',
		} );
	} );

	test( 'prepareTemplatePartSuggestionOperations rejects removing a locked block', () => {
		setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Keep' ),
				createParagraphBlock( 'existing-2', 'Remove me' ),
			],
			templateLock: 'all',
		} );

		const result = prepareTemplatePartSuggestionOperations( {
			operations: [
				{
					type: 'remove_block',
					expectedBlockName: 'core/paragraph',
					targetPath: [ 1 ],
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'The target block at path 1 is inside a locked container and cannot be removed automatically.',
		} );
	} );

	test( 'applyTemplatePartSuggestionOperations fails when a removal does not remove the target block', () => {
		const { blockEditorDispatch } = setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Keep' ),
				createParagraphBlock( 'existing-2', 'Remove me' ),
			],
		} );
		blockEditorDispatch.removeBlocks.mockImplementation( () => {} );

		const result = applyTemplatePartSuggestionOperations( {
			operations: [
				{
					type: 'remove_block',
					expectedBlockName: 'core/paragraph',
					targetPath: [ 1 ],
					expectedTarget: {
						name: 'core/paragraph',
						label: 'Paragraph',
						attributes: {
							content: 'Remove me',
						},
						childCount: 0,
					},
				},
			],
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'The target block at path 1 could not be removed automatically.',
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

	test( 'prepareTemplatePartUndoOperations resolves refresh-safe template-part undo targets after reload', () => {
		setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Existing' ),
				createParagraphBlock( 'pattern-reloaded', 'Utility' ),
			],
		} );

		const result = prepareTemplatePartUndoOperations( {
			after: {
				operations: [
					{
						type: 'insert_pattern',
						patternName: 'theme/header-utility',
						patternTitle: 'Header Utility',
						placement: 'end',
						rootLocator: {
							type: 'root',
							path: [],
						},
						index: 1,
						insertedBlocksSnapshot: [
							normalizeBlockSnapshot(
								createParagraphBlock(
									'pattern-before-refresh',
									'Utility'
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
					patternName: 'theme/header-utility',
					patternTitle: 'Header Utility',
				},
			],
		} );
	} );

	test( 'prepareTemplatePartUndoOperations tolerates editor-added default attributes on inserted blocks', () => {
		setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Existing' ),
				{
					clientId: 'pattern-reloaded',
					name: 'core/paragraph',
					attributes: {
						content: 'Utility',
						dropCap: false,
					},
					innerBlocks: [],
				},
			],
		} );

		const result = prepareTemplatePartUndoOperations( {
			after: {
				operations: [
					{
						type: 'insert_pattern',
						patternName: 'theme/header-utility',
						patternTitle: 'Header Utility',
						placement: 'end',
						rootLocator: {
							type: 'root',
							path: [],
						},
						index: 1,
						insertedBlocksSnapshot: [
							{
								name: 'core/paragraph',
								attributes: {
									content: 'Utility',
								},
								innerBlocks: [],
							},
						],
					},
				],
			},
		} );

		expect( result.ok ).toBe( true );
		expect( result.operations[ 0 ] ).toEqual(
			expect.objectContaining( {
				type: 'insert_pattern',
				insertedClientIds: [ 'pattern-reloaded' ],
			} )
		);
	} );

	test( 'prepareTemplatePartUndoOperations rejects editor-added non-default attributes on inserted blocks', () => {
		setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Existing' ),
				{
					clientId: 'pattern-reloaded',
					name: 'core/paragraph',
					attributes: {
						content: 'Utility',
						dropCap: true,
					},
					innerBlocks: [],
				},
			],
		} );

		const result = prepareTemplatePartUndoOperations( {
			after: {
				operations: [
					{
						type: 'insert_pattern',
						patternName: 'theme/header-utility',
						patternTitle: 'Header Utility',
						placement: 'end',
						rootLocator: {
							type: 'root',
							path: [],
						},
						index: 1,
						insertedBlocksSnapshot: [
							{
								name: 'core/paragraph',
								attributes: {
									content: 'Utility',
								},
								innerBlocks: [],
							},
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

	test( 'prepareTemplatePartUndoOperations resolves replaced blocks after refresh when inserted content is unchanged', () => {
		setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Keep' ),
				createParagraphBlock( 'pattern-reloaded', 'Utility' ),
			],
		} );

		const result = prepareTemplatePartUndoOperations( {
			after: {
				operations: [
					{
						type: 'replace_block_with_pattern',
						patternName: 'theme/header-utility',
						patternTitle: 'Header Utility',
						expectedBlockName: 'core/paragraph',
						targetPath: [ 1 ],
						rootLocator: {
							type: 'root',
							path: [],
						},
						index: 1,
						removedBlocksSnapshot: [
							normalizeBlockSnapshot(
								createParagraphBlock(
									'existing-2',
									'Replace me'
								)
							),
						],
						insertedBlocksSnapshot: [
							normalizeBlockSnapshot(
								createParagraphBlock(
									'pattern-before-refresh',
									'Utility'
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
					type: 'replace_block_with_pattern',
					insertedClientIds: [ 'pattern-reloaded' ],
					rootLocator: {
						type: 'root',
						path: [],
					},
					index: 1,
					removedBlocksSnapshot: [
						normalizeBlockSnapshot(
							createParagraphBlock( 'existing-2', 'Replace me' )
						),
					],
				},
			],
		} );
	} );

	test( 'prepareTemplatePartUndoOperations resolves removed blocks when the post-apply anchor is unchanged', () => {
		setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Keep' ),
				createParagraphBlock( 'existing-3', 'After me' ),
			],
		} );

		const result = prepareTemplatePartUndoOperations( {
			after: {
				operations: [
					{
						type: 'remove_block',
						expectedBlockName: 'core/paragraph',
						targetPath: [ 1 ],
						rootLocator: {
							type: 'root',
							path: [],
						},
						index: 1,
						removedBlocksSnapshot: [
							normalizeBlockSnapshot(
								createParagraphBlock(
									'existing-2',
									'Remove me'
								)
							),
						],
						postApplyAnchor: {
							type: 'next-block',
							blocksSnapshot: [
								normalizeBlockSnapshot(
									createParagraphBlock(
										'existing-3',
										'After me'
									)
								),
							],
						},
					},
				],
			},
		} );

		expect( result ).toEqual( {
			ok: true,
			operations: [
				{
					type: 'remove_block',
					rootLocator: {
						type: 'root',
						path: [],
					},
					index: 1,
					removedBlocksSnapshot: [
						normalizeBlockSnapshot(
							createParagraphBlock( 'existing-2', 'Remove me' )
						),
					],
				},
			],
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
								createParagraphBlock(
									'pattern-before',
									'Inserted'
								)
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
		expect(
			blockEditorDispatch.updateBlockAttributes
		).toHaveBeenCalledWith( 'tp-reloaded', {
			slug: 'header',
			area: 'header',
		} );
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

	test( 'undoTemplatePartSuggestionOperations removes inserted template-part pattern blocks', () => {
		const { blockEditorDispatch, state } = setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Existing' ),
				createParagraphBlock( 'pattern-reloaded', 'Utility' ),
			],
		} );

		const result = undoTemplatePartSuggestionOperations( {
			after: {
				operations: [
					{
						type: 'insert_pattern',
						patternName: 'theme/header-utility',
						patternTitle: 'Header Utility',
						placement: 'end',
						rootLocator: {
							type: 'root',
							path: [],
						},
						index: 1,
						insertedBlocksSnapshot: [
							normalizeBlockSnapshot(
								createParagraphBlock(
									'pattern-before-refresh',
									'Utility'
								)
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
		expect( state.blocks ).toEqual( [
			createParagraphBlock( 'existing-1', 'Existing' ),
		] );
		expect( result.ok ).toBe( true );
	} );

	test( 'undoTemplatePartSuggestionOperations restores a replaced block after removing the inserted pattern', () => {
		const { blockEditorDispatch, state } = setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Keep' ),
				createParagraphBlock( 'pattern-reloaded', 'Utility' ),
			],
		} );

		const result = undoTemplatePartSuggestionOperations( {
			after: {
				operations: [
					{
						type: 'replace_block_with_pattern',
						patternName: 'theme/header-utility',
						patternTitle: 'Header Utility',
						rootLocator: {
							type: 'root',
							path: [],
						},
						index: 1,
						removedBlocksSnapshot: [
							normalizeBlockSnapshot(
								createParagraphBlock(
									'existing-2',
									'Replace me'
								)
							),
						],
						insertedBlocksSnapshot: [
							normalizeBlockSnapshot(
								createParagraphBlock(
									'pattern-before-refresh',
									'Utility'
								)
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
		expect( blockEditorDispatch.insertBlocks ).toHaveBeenCalledWith(
			[
				expect.objectContaining( {
					name: 'core/paragraph',
					attributes: {
						content: 'Replace me',
					},
				} ),
			],
			1,
			null,
			true,
			0
		);
		expect( state.blocks ).toEqual( [
			createParagraphBlock( 'existing-1', 'Keep' ),
			expect.objectContaining( {
				name: 'core/paragraph',
				attributes: {
					content: 'Replace me',
				},
			} ),
		] );
		expect( result.ok ).toBe( true );
	} );

	test( 'undoTemplatePartSuggestionOperations restores a removed block when the anchor still matches', () => {
		const { blockEditorDispatch, state } = setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Keep' ),
				createParagraphBlock( 'existing-3', 'After me' ),
			],
		} );

		const result = undoTemplatePartSuggestionOperations( {
			after: {
				operations: [
					{
						type: 'remove_block',
						rootLocator: {
							type: 'root',
							path: [],
						},
						index: 1,
						removedBlocksSnapshot: [
							normalizeBlockSnapshot(
								createParagraphBlock(
									'existing-2',
									'Remove me'
								)
							),
						],
						postApplyAnchor: {
							type: 'next-block',
							blocksSnapshot: [
								normalizeBlockSnapshot(
									createParagraphBlock(
										'existing-3',
										'After me'
									)
								),
							],
						},
					},
				],
			},
		} );

		expect( blockEditorDispatch.insertBlocks ).toHaveBeenCalledWith(
			[
				expect.objectContaining( {
					name: 'core/paragraph',
					attributes: {
						content: 'Remove me',
					},
				} ),
			],
			1,
			null,
			true,
			0
		);
		expect( state.blocks ).toEqual( [
			createParagraphBlock( 'existing-1', 'Keep' ),
			expect.objectContaining( {
				name: 'core/paragraph',
				attributes: {
					content: 'Remove me',
				},
			} ),
			createParagraphBlock( 'existing-3', 'After me' ),
		] );
		expect( result.ok ).toBe( true );
	} );

	test( 'prepareTemplateUndoOperations rejects edited inserted paragraph content', () => {
		setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'pattern-1', 'Changed after apply' ),
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
								createParagraphBlock(
									'pattern-before',
									'Inserted by Flavor Agent'
								)
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
								createParagraphBlock(
									'pattern-before',
									'Inserted by Flavor Agent'
								)
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
								createParagraphBlock(
									'pattern-before-1',
									'First block'
								)
							),
							normalizeBlockSnapshot(
								createParagraphBlock(
									'pattern-before-2',
									'Second block'
								)
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

	test( 'getTemplateActivityUndoState recomputes availability after a transient failed state', () => {
		setupBlockEditor( {
			blocks: [ createParagraphBlock( 'pattern-reloaded', 'Inserted' ) ],
		} );

		const result = getTemplateActivityUndoState( {
			surface: 'template',
			undo: {
				canUndo: false,
				status: 'failed',
				error: 'Inserted pattern content changed after apply and cannot be undone automatically.',
			},
			after: {
				operations: [
					{
						type: 'insert_pattern',
						patternName: 'theme/hero',
						patternTitle: 'Hero Banner',
						rootLocator: {
							type: 'root',
							path: [],
						},
						index: 0,
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
			canUndo: true,
			status: 'available',
			error: null,
		} );
	} );

	test( 'getTemplatePartActivityUndoState recomputes availability after a transient failed state', () => {
		setupBlockEditor( {
			blocks: [
				createParagraphBlock( 'existing-1', 'Existing' ),
				createParagraphBlock( 'pattern-reloaded', 'Utility' ),
			],
		} );

		const result = getTemplatePartActivityUndoState( {
			surface: 'template-part',
			undo: {
				canUndo: false,
				status: 'failed',
				error: 'Inserted pattern content changed after apply and cannot be undone automatically.',
			},
			after: {
				operations: [
					{
						type: 'insert_pattern',
						patternName: 'theme/header-utility',
						patternTitle: 'Header Utility',
						rootLocator: {
							type: 'root',
							path: [],
						},
						index: 1,
						insertedBlocksSnapshot: [
							normalizeBlockSnapshot(
								createParagraphBlock(
									'pattern-before-refresh',
									'Utility'
								)
							),
						],
					},
				],
			},
		} );

		expect( result ).toEqual( {
			canUndo: true,
			status: 'available',
			error: null,
		} );
	} );
} );
