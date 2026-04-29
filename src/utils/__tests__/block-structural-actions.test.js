import {
	applyBlockStructuralSuggestionOperations,
	prepareBlockStructuralOperation,
	undoBlockStructuralSuggestionOperations,
} from '../block-structural-actions';

const baseOperation = {
	catalogVersion: 1,
	type: 'insert_pattern',
	patternName: 'theme/hero',
	targetClientId: 'block-1',
	position: 'insert_after',
	targetSignature: 'target-sig',
	expectedTarget: {
		clientId: 'block-1',
		name: 'core/group',
	},
};

const baseContext = {
	enableBlockStructuralActions: true,
	targetClientId: 'block-1',
	targetBlockName: 'core/group',
	targetSignature: 'target-sig',
	allowedPatterns: [
		{
			name: 'theme/hero',
			title: 'Hero',
			allowedActions: [ 'insert_after', 'insert_before', 'replace' ],
		},
	],
};

function cloneValue( value ) {
	return JSON.parse( JSON.stringify( value ) );
}

function findBlockByClientId( blocks, clientId ) {
	for ( const block of blocks ) {
		if ( block?.clientId === clientId ) {
			return block;
		}

		const nested = findBlockByClientId(
			Array.isArray( block?.innerBlocks ) ? block.innerBlocks : [],
			clientId
		);

		if ( nested ) {
			return nested;
		}
	}

	return null;
}

function findBlockLocation( blocks, clientId, rootClientId = null ) {
	for ( let index = 0; index < blocks.length; index++ ) {
		const block = blocks[ index ];

		if ( block?.clientId === clientId ) {
			return {
				block,
				index,
				rootClientId,
			};
		}

		const nested = findBlockLocation(
			Array.isArray( block?.innerBlocks ) ? block.innerBlocks : [],
			clientId,
			block?.clientId || null
		);

		if ( nested ) {
			return nested;
		}
	}

	return null;
}

function getBlockContainer( blocks, rootClientId = null ) {
	if ( ! rootClientId ) {
		return blocks;
	}

	const root = findBlockByClientId( blocks, rootClientId );

	return Array.isArray( root?.innerBlocks ) ? root.innerBlocks : null;
}

function removeBlocksByClientIds( blocks, clientIds ) {
	for ( let index = blocks.length - 1; index >= 0; index-- ) {
		const block = blocks[ index ];

		if ( clientIds.includes( block?.clientId ) ) {
			blocks.splice( index, 1 );
			continue;
		}

		removeBlocksByClientIds(
			Array.isArray( block?.innerBlocks ) ? block.innerBlocks : [],
			clientIds
		);
	}
}

function createBlockEditor( {
	blocks = [
		{
			clientId: 'block-1',
			name: 'core/group',
			attributes: {},
			innerBlocks: [],
		},
	],
	editingModes = {},
	failNextInsert = false,
} = {} ) {
	const state = {
		blocks: cloneValue( blocks ),
		failNextInsert,
	};

	const blockEditorSelect = {
		getBlock: jest.fn( ( clientId ) =>
			findBlockByClientId( state.blocks, clientId )
		),
		getBlocks: jest.fn(
			( rootClientId = null ) =>
				getBlockContainer( state.blocks, rootClientId ) || []
		),
		getBlockRootClientId: jest.fn(
			( clientId ) =>
				findBlockLocation( state.blocks, clientId )?.rootClientId ||
				null
		),
		getBlockIndex: jest.fn(
			( clientId, rootClientId = null ) =>
				findBlockLocation( state.blocks, clientId, rootClientId )
					?.index ?? -1
		),
		getBlockEditingMode: jest.fn(
			( clientId ) => editingModes[ clientId ] || 'default'
		),
	};
	const blockEditorDispatch = {
		insertBlocks: jest.fn( ( blocksToInsert, index, rootClientId ) => {
			if ( state.failNextInsert ) {
				state.failNextInsert = false;
				return;
			}

			const container = getBlockContainer( state.blocks, rootClientId );
			container.splice( index, 0, ...cloneValue( blocksToInsert ) );
		} ),
		removeBlocks: jest.fn( ( clientIds ) => {
			removeBlocksByClientIds( state.blocks, clientIds );
		} ),
		selectBlock: jest.fn(),
	};

	return {
		state,
		blockEditorSelect,
		blockEditorDispatch,
	};
}

function parsePatternBlocks( patternName ) {
	if ( patternName !== 'theme/hero' ) {
		const error = new Error( 'Pattern missing.' );
		error.code = 'pattern_missing';
		throw error;
	}

	return [
		{
			clientId: 'pattern-1',
			name: 'core/paragraph',
			attributes: {
				content: 'Pattern content',
			},
			innerBlocks: [],
		},
	];
}

function buildSuggestion( operation = baseOperation ) {
	return {
		label: 'Add hero pattern',
		suggestionKey: 'add-hero-pattern',
		actionability: {
			tier: 'review-safe',
			executableOperations: [ operation ],
		},
	};
}

describe( 'block structural actions', () => {
	test( 'prepareBlockStructuralOperation rejects a missing live target', () => {
		const result = prepareBlockStructuralOperation( {
			operation: baseOperation,
			blockOperationContext: baseContext,
			blockEditorSelect: {
				getBlock: () => null,
			},
		} );

		expect( result ).toEqual(
			expect.objectContaining( {
				ok: false,
				code: 'target_missing',
			} )
		);
	} );

	test( 'prepareBlockStructuralOperation rejects stale signatures, disabled flags, locks, content-only targets, missing patterns, and invalid actions', () => {
		const { blockEditorSelect } = createBlockEditor();

		expect(
			prepareBlockStructuralOperation( {
				operation: {
					...baseOperation,
					targetSignature: 'old-sig',
				},
				blockOperationContext: baseContext,
				blockEditorSelect,
			} )
		).toEqual( expect.objectContaining( { code: 'target_mismatch' } ) );

		expect(
			prepareBlockStructuralOperation( {
				operation: baseOperation,
				blockOperationContext: {
					...baseContext,
					enableBlockStructuralActions: false,
				},
				blockEditorSelect,
			} )
		).toEqual(
			expect.objectContaining( { code: 'structural_actions_disabled' } )
		);

		expect(
			prepareBlockStructuralOperation( {
				operation: baseOperation,
				blockOperationContext: {
					...baseContext,
					isTargetLocked: true,
				},
				blockEditorSelect,
			} )
		).toEqual( expect.objectContaining( { code: 'locked_target' } ) );

		expect(
			prepareBlockStructuralOperation( {
				operation: baseOperation,
				blockOperationContext: {
					...baseContext,
					isContentOnly: true,
				},
				blockEditorSelect,
			} )
		).toEqual( expect.objectContaining( { code: 'content_only_target' } ) );

		expect(
			prepareBlockStructuralOperation( {
				operation: {
					...baseOperation,
					patternName: 'theme/missing',
				},
				blockOperationContext: baseContext,
				blockEditorSelect,
			} )
		).toEqual( expect.objectContaining( { code: 'pattern_missing' } ) );

		expect(
			prepareBlockStructuralOperation( {
				operation: {
					...baseOperation,
					position: 'insert_inside',
				},
				blockOperationContext: baseContext,
				blockEditorSelect,
			} )
		).toEqual( expect.objectContaining( { code: 'operation_invalid' } ) );
	} );

	test( 'applyBlockStructuralSuggestionOperations inserts patterns before and after the selected block', () => {
		const { state, blockEditorSelect, blockEditorDispatch } =
			createBlockEditor( {
				blocks: [
					{
						clientId: 'before',
						name: 'core/paragraph',
						attributes: {},
						innerBlocks: [],
					},
					{
						clientId: 'block-1',
						name: 'core/group',
						attributes: {},
						innerBlocks: [],
					},
				],
			} );

		const afterResult = applyBlockStructuralSuggestionOperations( {
			suggestion: buildSuggestion(),
			blockOperationContext: baseContext,
			blockEditorSelect,
			blockEditorDispatch,
			parsePatternBlocks,
		} );

		expect( afterResult.ok ).toBe( true );
		expect( state.blocks.map( ( block ) => block.clientId ) ).toEqual( [
			'before',
			'block-1',
			'pattern-1',
		] );

		const beforeResult = applyBlockStructuralSuggestionOperations( {
			suggestion: buildSuggestion( {
				...baseOperation,
				targetClientId: 'before',
				position: 'insert_before',
				expectedTarget: {
					clientId: 'before',
					name: 'core/paragraph',
				},
			} ),
			blockOperationContext: {
				...baseContext,
				targetClientId: 'before',
				targetBlockName: 'core/paragraph',
			},
			blockEditorSelect,
			blockEditorDispatch,
			parsePatternBlocks,
		} );

		expect( beforeResult.ok ).toBe( true );
		expect( state.blocks.map( ( block ) => block.clientId ) ).toEqual( [
			'pattern-1',
			'before',
			'block-1',
			'pattern-1',
		] );
	} );

	test( 'applyBlockStructuralSuggestionOperations replaces the selected block transactionally', () => {
		const { state, blockEditorSelect, blockEditorDispatch } =
			createBlockEditor();

		const result = applyBlockStructuralSuggestionOperations( {
			suggestion: buildSuggestion( {
				...baseOperation,
				type: 'replace_block_with_pattern',
				action: 'replace',
				position: undefined,
			} ),
			blockOperationContext: baseContext,
			blockEditorSelect,
			blockEditorDispatch,
			parsePatternBlocks,
		} );

		expect( result ).toEqual(
			expect.objectContaining( {
				ok: true,
				operations: [
					expect.objectContaining( {
						type: 'replace_block_with_pattern',
						removedBlocksSnapshot: [
							expect.objectContaining( {
								clientId: 'block-1',
								name: 'core/group',
							} ),
						],
						insertedBlocksSnapshot: [
							expect.objectContaining( {
								name: 'core/paragraph',
							} ),
						],
					} ),
				],
			} )
		);
		expect( state.blocks.map( ( block ) => block.clientId ) ).toEqual( [
			'pattern-1',
		] );
	} );

	test( 'applyBlockStructuralSuggestionOperations restores removed blocks when replacement insertion fails', () => {
		const { state, blockEditorSelect, blockEditorDispatch } =
			createBlockEditor( { failNextInsert: true } );

		const result = applyBlockStructuralSuggestionOperations( {
			suggestion: buildSuggestion( {
				...baseOperation,
				type: 'replace_block_with_pattern',
				action: 'replace',
				position: undefined,
			} ),
			blockOperationContext: baseContext,
			blockEditorSelect,
			blockEditorDispatch,
			parsePatternBlocks,
		} );

		expect( result.ok ).toBe( false );
		expect( state.blocks ).toEqual( [
			expect.objectContaining( {
				clientId: 'block-1',
				name: 'core/group',
			} ),
		] );
	} );

	test( 'undoBlockStructuralSuggestionOperations removes inserted blocks when post-apply state has not drifted', () => {
		const { state, blockEditorSelect, blockEditorDispatch } =
			createBlockEditor();
		const result = applyBlockStructuralSuggestionOperations( {
			suggestion: buildSuggestion(),
			blockOperationContext: baseContext,
			blockEditorSelect,
			blockEditorDispatch,
			parsePatternBlocks,
		} );

		const undoResult = undoBlockStructuralSuggestionOperations(
			{
				after: {
					operations: result.operations,
					structuralSignature: result.afterSignature,
				},
			},
			{
				select: () => blockEditorSelect,
				dispatch: () => blockEditorDispatch,
			}
		);

		expect( undoResult ).toEqual( { ok: true } );
		expect( state.blocks.map( ( block ) => block.clientId ) ).toEqual( [
			'block-1',
		] );
	} );

	test( 'undoBlockStructuralSuggestionOperations blocks undo when post-apply structure drifts', () => {
		const { state, blockEditorSelect, blockEditorDispatch } =
			createBlockEditor();
		const result = applyBlockStructuralSuggestionOperations( {
			suggestion: buildSuggestion(),
			blockOperationContext: baseContext,
			blockEditorSelect,
			blockEditorDispatch,
			parsePatternBlocks,
		} );
		state.blocks[ 1 ].attributes.content = 'Edited after apply';

		const undoResult = undoBlockStructuralSuggestionOperations(
			{
				after: {
					operations: result.operations,
					structuralSignature: result.afterSignature,
				},
			},
			{
				select: () => blockEditorSelect,
				dispatch: () => blockEditorDispatch,
			}
		);

		expect( undoResult ).toEqual(
			expect.objectContaining( {
				ok: false,
				error: 'The block structure changed after Flavor Agent applied this suggestion and cannot be undone automatically.',
			} )
		);
		expect( state.blocks.map( ( block ) => block.clientId ) ).toEqual( [
			'block-1',
			'pattern-1',
		] );
	} );
} );
