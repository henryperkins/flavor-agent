jest.mock( '@wordpress/i18n', () =>
	require( '../../test-utils/i18n-mock' ).createI18nMock()
);

const i18n = require( '@wordpress/i18n' );

import {
	applyBlockStructuralSuggestionOperations,
	getBlockStructuralActivitySignature,
	getBlockStructuralActivityUndoState,
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

function getAllClientIds( blocks = [] ) {
	return blocks
		.flatMap( ( block ) => [
			block?.clientId,
			...getAllClientIds(
				Array.isArray( block?.innerBlocks ) ? block.innerBlocks : []
			),
		] )
		.filter( Boolean );
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
	nextInsertBlockCount = null,
	canInsertBlockType = () => true,
	canRemoveBlock = () => true,
	canRemoveBlocks = ( clientIds ) =>
		clientIds.every( ( clientId ) => canRemoveBlock( clientId ) ),
	noOpNextRemove = false,
	noOpRemoveAtAttempt = null,
	noOpNextRestoreInsert = false,
} = {} ) {
	const state = {
		blocks: cloneValue( blocks ),
		nextInsertBlockCount: failNextInsert ? 0 : nextInsertBlockCount,
		noOpNextRemove,
		noOpRemoveAtAttempt,
		noOpNextRestoreInsert,
		insertAttempts: [],
		mutationEvents: [],
		removeAttemptCount: 0,
		initialClientIds: new Set( getAllClientIds( blocks ) ),
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
		canInsertBlockType: jest.fn( canInsertBlockType ),
		canRemoveBlock: jest.fn( canRemoveBlock ),
		canRemoveBlocks: jest.fn( canRemoveBlocks ),
	};
	const blockEditorDispatch = {
		insertBlocks: jest.fn( ( blocksToInsert, index, rootClientId ) => {
			const attemptedBlocks = cloneValue( blocksToInsert );
			const topLevelClientIds = attemptedBlocks.map(
				( block ) => block.clientId
			);
			const isRestoration = attemptedBlocks.some(
				( block ) =>
					state.initialClientIds.has( block.clientId ) &&
					! findBlockByClientId( state.blocks, block.clientId )
			);

			state.insertAttempts.push( {
				blocks: attemptedBlocks,
				topLevelClientIds,
				innerClientIds: attemptedBlocks.flatMap( ( block ) =>
					getAllClientIds( block.innerBlocks )
				),
				index,
				rootClientId,
				isRestoration,
			} );
			state.mutationEvents.push( {
				type: 'insert',
				clientIds: topLevelClientIds,
				index,
				rootClientId,
			} );

			if ( isRestoration && state.noOpNextRestoreInsert ) {
				state.noOpNextRestoreInsert = false;
				return;
			}

			const insertCount = Number.isInteger( state.nextInsertBlockCount )
				? state.nextInsertBlockCount
				: attemptedBlocks.length;
			state.nextInsertBlockCount = null;
			const container = getBlockContainer( state.blocks, rootClientId );
			container.splice(
				index,
				0,
				...attemptedBlocks.slice( 0, insertCount )
			);
		} ),
		removeBlocks: jest.fn( ( clientIds ) => {
			state.removeAttemptCount += 1;
			state.mutationEvents.push( {
				type: 'remove',
				clientIds: [ ...clientIds ],
			} );

			if (
				state.noOpNextRemove ||
				state.removeAttemptCount === state.noOpRemoveAtAttempt
			) {
				state.noOpNextRemove = false;
				return;
			}

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

const cachedNestedPatternBlocks = [
	{
		clientId: 'cached-pattern-group',
		name: 'core/group',
		attributes: {
			className: 'cached-group',
		},
		innerBlocks: [
			{
				clientId: 'cached-pattern-inner',
				name: 'core/paragraph',
				attributes: {
					content: 'Nested pattern content',
				},
				innerBlocks: [],
			},
		],
	},
	{
		clientId: 'cached-pattern-heading',
		name: 'core/heading',
		attributes: {
			content: 'Pattern heading',
		},
		innerBlocks: [],
	},
];

function parseCachedNestedPatternBlocks( patternName ) {
	if ( patternName !== 'theme/hero' ) {
		throw new Error( 'Pattern missing.' );
	}

	return cachedNestedPatternBlocks;
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

function buildReplaceOperation( overrides = {} ) {
	return {
		...baseOperation,
		type: 'replace_block_with_pattern',
		action: 'replace',
		position: undefined,
		...overrides,
	};
}

function applyOperation( {
	editor,
	operation = baseOperation,
	context = baseContext,
	parser = parsePatternBlocks,
} ) {
	return applyBlockStructuralSuggestionOperations( {
		suggestion: buildSuggestion( operation ),
		blockOperationContext: context,
		blockEditorSelect: editor.blockEditorSelect,
		blockEditorDispatch: editor.blockEditorDispatch,
		parsePatternBlocks: parser,
	} );
}

function buildActivityFromResult( result, operations = result.operations ) {
	return {
		surface: 'block',
		type: 'apply_block_structural_suggestion',
		before: {
			structuralSignature: result.beforeSignature,
		},
		after: {
			operations,
			structuralSignature: result.afterSignature,
		},
		undo: {
			canUndo: true,
			status: 'available',
			error: null,
		},
	};
}

function createMultiOperationUndoFixture( options = {} ) {
	const originalBlockB = {
		clientId: 'original-b',
		name: 'core/heading',
		attributes: { content: 'Original B' },
		innerBlocks: [],
	};
	const originalBlockC = {
		clientId: 'original-c',
		name: 'core/group',
		attributes: { className: 'original-c' },
		innerBlocks: [
			{
				clientId: 'original-c-inner',
				name: 'core/paragraph',
				attributes: { content: 'Original nested content' },
				innerBlocks: [],
			},
		],
	};
	const beforeBlocks = [
		{
			clientId: 'keep-start',
			name: 'core/paragraph',
			attributes: { content: 'Keep start' },
			innerBlocks: [],
		},
		originalBlockB,
		originalBlockC,
		{
			clientId: 'keep-end',
			name: 'core/paragraph',
			attributes: { content: 'Keep end' },
			innerBlocks: [],
		},
	];
	const afterBlocks = [
		beforeBlocks[ 0 ],
		{
			clientId: 'inserted-a',
			name: 'core/paragraph',
			attributes: { content: 'Inserted A' },
			innerBlocks: [],
		},
		{
			clientId: 'replacement-b',
			name: 'core/quote',
			attributes: { value: 'Replacement B' },
			innerBlocks: [],
		},
		{
			clientId: 'replacement-c',
			name: 'core/quote',
			attributes: { value: 'Replacement C' },
			innerBlocks: [],
		},
		beforeBlocks[ 3 ],
	];
	const rootLocator = { type: 'root', rootClientId: null };
	const operations = [
		{
			type: 'insert_pattern',
			insertedClientIds: [ 'inserted-a' ],
			rootLocator,
			index: 1,
			insertedBlocksSnapshot: [
				{
					name: 'core/paragraph',
					attributes: { content: 'Inserted A' },
					innerBlocks: [],
				},
			],
		},
		{
			type: 'replace_block_with_pattern',
			replacementClientIds: [ 'replacement-b' ],
			rootLocator,
			index: 2,
			insertedBlocksSnapshot: [
				{
					name: 'core/quote',
					attributes: { value: 'Replacement B' },
					innerBlocks: [],
				},
			],
			removedBlocksSnapshot: [ originalBlockB ],
		},
		{
			type: 'replace_block_with_pattern',
			replacementClientIds: [ 'replacement-c' ],
			rootLocator,
			index: 3,
			insertedBlocksSnapshot: [
				{
					name: 'core/quote',
					attributes: { value: 'Replacement C' },
					innerBlocks: [],
				},
			],
			removedBlocksSnapshot: [ originalBlockC ],
		},
	];
	const editor = createBlockEditor( { blocks: afterBlocks, ...options } );
	const activity = {
		after: {
			operations,
			structuralSignature: getBlockStructuralActivitySignature(
				{ after: { operations } },
				editor.blockEditorSelect
			),
		},
	};

	return { activity, beforeBlocks, editor };
}

describe( 'block structural actions', () => {
	afterEach( () => {
		i18n.__.mockImplementation( ( text ) => text );
	} );

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

	test( 'prepareBlockStructuralOperation rejects stale signatures, disabled flags, content-only targets, missing patterns, and invalid actions', () => {
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

	test.each( [
		[ 'move', { lock: { move: true } } ],
		[ 'remove', { lock: { remove: true } } ],
		[ 'template lock', { templateLock: 'all' } ],
	] )(
		'prepares an allowed operation despite the selected block %s attribute',
		( _label, attributes ) => {
			const { blockEditorSelect } = createBlockEditor( {
				blocks: [
					{
						clientId: 'block-1',
						name: 'core/group',
						attributes,
						innerBlocks: [],
					},
				],
			} );

			expect(
				prepareBlockStructuralOperation( {
					operation: baseOperation,
					blockOperationContext: baseContext,
					blockEditorSelect,
				} )
			).toEqual( expect.objectContaining( { ok: true } ) );
		}
	);

	test( 'repeated cached nested-pattern applies receive fresh recursive identities and record exact runtime IDs', () => {
		const sourceSnapshot = cloneValue( cachedNestedPatternBlocks );
		const editor = createBlockEditor();

		const firstResult = applyOperation( {
			editor,
			parser: parseCachedNestedPatternBlocks,
		} );
		const firstAttempt = editor.state.insertAttempts[ 0 ];
		const firstLiveBlocks = firstAttempt.topLevelClientIds.map(
			( clientId ) =>
				cloneValue( editor.blockEditorSelect.getBlock( clientId ) )
		);
		const secondResult = applyOperation( {
			editor,
			parser: parseCachedNestedPatternBlocks,
		} );
		const secondAttempt = editor.state.insertAttempts[ 1 ];

		expect( firstResult.ok ).toBe( true );
		expect( secondResult.ok ).toBe( true );
		expect( firstAttempt.topLevelClientIds ).not.toEqual(
			expect.arrayContaining( [
				'cached-pattern-group',
				'cached-pattern-heading',
			] )
		);
		expect( firstAttempt.innerClientIds ).not.toContain(
			'cached-pattern-inner'
		);
		expect( secondAttempt.topLevelClientIds ).not.toEqual(
			expect.arrayContaining( [
				'cached-pattern-group',
				'cached-pattern-heading',
			] )
		);
		expect( secondAttempt.innerClientIds ).not.toContain(
			'cached-pattern-inner'
		);
		expect(
			firstAttempt.topLevelClientIds.map(
				( clientId, index ) =>
					clientId !== secondAttempt.topLevelClientIds[ index ]
			)
		).toEqual( [ true, true ] );
		expect(
			firstAttempt.innerClientIds.map(
				( clientId, index ) =>
					clientId !== secondAttempt.innerClientIds[ index ]
			)
		).toEqual( [ true ] );
		expect( firstResult.operations[ 0 ].insertedClientIds ).toEqual(
			firstAttempt.topLevelClientIds
		);
		expect( secondResult.operations[ 0 ].insertedClientIds ).toEqual(
			secondAttempt.topLevelClientIds
		);
		expect( firstLiveBlocks[ 0 ].innerBlocks[ 0 ].clientId ).toBe(
			firstAttempt.innerClientIds[ 0 ]
		);
		expect(
			editor.blockEditorSelect.getBlock(
				secondAttempt.topLevelClientIds[ 0 ]
			).innerBlocks[ 0 ].clientId
		).toBe( secondAttempt.innerClientIds[ 0 ] );
		expect( cachedNestedPatternBlocks ).toEqual( sourceSnapshot );
		expect( parseCachedNestedPatternBlocks( 'theme/hero' ) ).toBe(
			cachedNestedPatternBlocks
		);
	} );

	test.each( [
		{ position: 'insert_before', nextInsertBlockCount: 0 },
		{ position: 'insert_before', nextInsertBlockCount: 1 },
		{ position: 'insert_after', nextInsertBlockCount: 0 },
		{ position: 'insert_after', nextInsertBlockCount: 1 },
	] )(
		'rolls back only exact newly-present IDs for $position with insert count $nextInsertBlockCount',
		( { position, nextInsertBlockCount } ) => {
			const initialBlocks =
				position === 'insert_before'
					? [
							{
								clientId: 'before-neighbor',
								name: 'core/paragraph',
								attributes: { content: 'Before neighbor' },
								innerBlocks: [],
							},
							{
								clientId: 'block-1',
								name: 'core/group',
								attributes: {},
								innerBlocks: [],
							},
					  ]
					: [
							{
								clientId: 'block-1',
								name: 'core/group',
								attributes: {},
								innerBlocks: [],
							},
							{
								clientId: 'after-neighbor',
								name: 'core/paragraph',
								attributes: { content: 'After neighbor' },
								innerBlocks: [],
							},
					  ];
			const initialIds = initialBlocks.map( ( block ) => block.clientId );
			const editor = createBlockEditor( {
				blocks: initialBlocks,
				nextInsertBlockCount,
			} );

			const result = applyOperation( {
				editor,
				operation: { ...baseOperation, position },
				parser: parseCachedNestedPatternBlocks,
			} );
			const attemptedIds =
				editor.state.insertAttempts[ 0 ].topLevelClientIds;
			const rollbackCalls =
				editor.blockEditorDispatch.removeBlocks.mock.calls;

			expect( result.ok ).toBe( false );
			expect( result.operations ).toBeUndefined();
			expect(
				editor.state.blocks.map( ( block ) => block.clientId )
			).toEqual( initialIds );
			expect( rollbackCalls ).toEqual(
				nextInsertBlockCount === 0
					? []
					: [ [ [ attemptedIds[ 0 ] ], false ] ]
			);
			expect( rollbackCalls.flat( 2 ) ).not.toEqual(
				expect.arrayContaining( initialIds )
			);
		}
	);

	test.each( [
		{
			label: 'insertion',
			operation: baseOperation,
			expected:
				'Pattern “theme/hero” could not be inserted for the selected block.',
		},
		{
			label: 'replacement',
			operation: buildReplaceOperation(),
			expected:
				'Pattern “theme/hero” could not replace the selected block.',
		},
	] )(
		'reports the supplied pattern name when $label verification fails',
		( { operation, expected } ) => {
			const editor = createBlockEditor( { nextInsertBlockCount: 0 } );
			const result = applyOperation( {
				editor,
				operation,
				parser: parseCachedNestedPatternBlocks,
			} );

			expect( result ).toEqual(
				expect.objectContaining( {
					ok: false,
					code: 'operation_invalid',
					error: expected,
				} )
			);
		}
	);

	test.each( [
		{
			label: 'insertion',
			operation: baseOperation,
			expected:
				'Pattern “unknown” could not be inserted for the selected block.',
		},
		{
			label: 'replacement',
			operation: buildReplaceOperation(),
			expected: 'Pattern “unknown” could not replace the selected block.',
		},
	] )(
		'uses the product-owned unknown fallback when $label verification loses the pattern name',
		( { operation, expected } ) => {
			const editor = createBlockEditor( { nextInsertBlockCount: 0 } );
			const result = applyOperation( {
				editor,
				operation,
				parser: ( patternName, preparedOperation ) => {
					preparedOperation.patternName = '';
					return parseCachedNestedPatternBlocks( patternName );
				},
			} );

			expect( result.error ).toBe( expected );
		}
	);

	test( 'formats translated insertion failures without translating a supplied pattern name', () => {
		i18n.__.mockImplementation( ( text ) => {
			if (
				text ===
				'Pattern “%s” could not be inserted for the selected block.'
			) {
				return 'Translated insertion failure for “%s”.';
			}

			return text;
		} );
		const editor = createBlockEditor( { nextInsertBlockCount: 0 } );
		const result = applyOperation( {
			editor,
			parser: parseCachedNestedPatternBlocks,
		} );

		expect( result.error ).toBe(
			'Translated insertion failure for “theme/hero”.'
		);
	} );

	test( 'reports rollback failure when exact partial-insert cleanup is a no-op', () => {
		const editor = createBlockEditor( {
			blocks: [
				{
					clientId: 'block-1',
					name: 'core/group',
					attributes: {},
					innerBlocks: [],
				},
				{
					clientId: 'after-neighbor',
					name: 'core/paragraph',
					attributes: {},
					innerBlocks: [],
				},
			],
			nextInsertBlockCount: 1,
			noOpNextRemove: true,
		} );

		const result = applyOperation( {
			editor,
			parser: parseCachedNestedPatternBlocks,
		} );
		const insertedId =
			editor.state.insertAttempts[ 0 ].topLevelClientIds[ 0 ];

		expect( result ).toEqual(
			expect.objectContaining( {
				ok: false,
				code: 'rollback_failed',
				error: 'Flavor Agent could not safely roll back the structural change. Review the block structure before continuing.',
			} )
		);
		expect(
			editor.blockEditorSelect.getBlock( insertedId )
		).not.toBeNull();
		expect(
			editor.blockEditorSelect.getBlock( 'after-neighbor' )
		).not.toBeNull();
		expect( result.operations ).toBeUndefined();
	} );

	test.each( [ 0, 1 ] )(
		'restores an exact replacement snapshot after insert count %i without deleting its neighbor',
		( nextInsertBlockCount ) => {
			const originalBlocks = [
				{
					clientId: 'block-1',
					name: 'core/group',
					attributes: { className: 'original-target' },
					innerBlocks: [
						{
							clientId: 'original-inner',
							name: 'core/paragraph',
							attributes: { content: 'Original nested content' },
							innerBlocks: [],
						},
					],
				},
				{
					clientId: 'replacement-neighbor',
					name: 'core/heading',
					attributes: { content: 'Keep me' },
					innerBlocks: [],
				},
			];
			const editor = createBlockEditor( {
				blocks: originalBlocks,
				nextInsertBlockCount,
			} );

			const result = applyOperation( {
				editor,
				operation: buildReplaceOperation(),
				parser: parseCachedNestedPatternBlocks,
			} );
			const attemptedIds =
				editor.state.insertAttempts[ 0 ].topLevelClientIds;
			const removalCalls =
				editor.blockEditorDispatch.removeBlocks.mock.calls;

			expect( result.ok ).toBe( false );
			expect( result.operations ).toBeUndefined();
			expect( editor.state.blocks ).toEqual( originalBlocks );
			expect( removalCalls ).toEqual(
				nextInsertBlockCount === 0
					? [ [ [ 'block-1' ], false ] ]
					: [
							[ [ 'block-1' ], false ],
							[ [ attemptedIds[ 0 ] ], false ],
					  ]
			);
			expect( removalCalls.flat( 2 ) ).not.toContain(
				'replacement-neighbor'
			);
		}
	);

	test( 'reports restore failure when replacement rollback cannot confirm the original target', () => {
		const editor = createBlockEditor( {
			nextInsertBlockCount: 0,
			noOpNextRestoreInsert: true,
		} );

		const result = applyOperation( {
			editor,
			operation: buildReplaceOperation(),
			parser: parseCachedNestedPatternBlocks,
		} );

		expect( result ).toEqual(
			expect.objectContaining( {
				ok: false,
				code: 'restore_failed',
				error: 'Flavor Agent could not restore the replaced block after the structural change failed. Review the block structure before continuing.',
			} )
		);
		expect( editor.blockEditorSelect.getBlock( 'block-1' ) ).toBeNull();
		expect( result.operations ).toBeUndefined();
	} );

	test.each( [ 'insert_before', 'insert_after' ] )(
		'preflights every parsed type at the destination before %s dispatch',
		( position ) => {
			const editor = createBlockEditor( {
				canInsertBlockType: ( name ) => name !== 'core/heading',
			} );

			const result = applyOperation( {
				editor,
				operation: { ...baseOperation, position },
				parser: parseCachedNestedPatternBlocks,
			} );

			expect( result.ok ).toBe( false );
			expect(
				editor.blockEditorDispatch.insertBlocks
			).not.toHaveBeenCalled();
			expect(
				editor.blockEditorSelect.canInsertBlockType
			).toHaveBeenCalledWith( 'core/heading', null );
		}
	);

	test( 'replacement preflights target removal, replacement insertion, and original restoration before mutation', () => {
		const targetDenied = createBlockEditor( {
			canRemoveBlock: ( clientId ) => clientId !== 'block-1',
		} );
		const targetDeniedResult = applyOperation( {
			editor: targetDenied,
			operation: buildReplaceOperation(),
		} );
		expect( targetDeniedResult.ok ).toBe( false );
		expect(
			targetDenied.blockEditorDispatch.removeBlocks
		).not.toHaveBeenCalled();
		expect(
			targetDenied.blockEditorDispatch.insertBlocks
		).not.toHaveBeenCalled();

		const replacementDenied = createBlockEditor( {
			canInsertBlockType: ( name ) => name !== 'core/heading',
		} );
		const replacementDeniedResult = applyOperation( {
			editor: replacementDenied,
			operation: buildReplaceOperation(),
			parser: parseCachedNestedPatternBlocks,
		} );
		expect( replacementDeniedResult.ok ).toBe( false );
		expect(
			replacementDenied.blockEditorDispatch.removeBlocks
		).not.toHaveBeenCalled();
		expect(
			replacementDenied.blockEditorDispatch.insertBlocks
		).not.toHaveBeenCalled();

		const restoreDenied = createBlockEditor( {
			canInsertBlockType: ( name ) => name !== 'core/group',
		} );
		const restoreDeniedResult = applyOperation( {
			editor: restoreDenied,
			operation: buildReplaceOperation(),
		} );
		expect( restoreDeniedResult.ok ).toBe( false );
		expect(
			restoreDenied.blockEditorDispatch.removeBlocks
		).not.toHaveBeenCalled();
		expect(
			restoreDenied.blockEditorDispatch.insertBlocks
		).not.toHaveBeenCalled();
	} );

	test( 'fails closed when live insertion or rollback selectors are missing', () => {
		for ( const selectorName of [
			'canInsertBlockType',
			'canRemoveBlocks',
			'getBlockRootClientId',
		] ) {
			const editor = createBlockEditor();
			delete editor.blockEditorSelect[ selectorName ];

			const result = applyOperation( { editor } );

			expect( result.ok ).toBe( false );
			expect(
				editor.blockEditorDispatch.insertBlocks
			).not.toHaveBeenCalled();
		}

		const replacementEditor = createBlockEditor();
		delete replacementEditor.blockEditorSelect.canRemoveBlock;
		const replacementResult = applyOperation( {
			editor: replacementEditor,
			operation: buildReplaceOperation(),
		} );
		expect( replacementResult.ok ).toBe( false );
		expect(
			replacementEditor.blockEditorDispatch.removeBlocks
		).not.toHaveBeenCalled();
	} );

	test( 'returns rollback failure without guessing when Core denies removal of a partial runtime insertion', () => {
		const editor = createBlockEditor( {
			blocks: [
				{
					clientId: 'block-1',
					name: 'core/group',
					attributes: {},
					innerBlocks: [],
				},
				{
					clientId: 'after-neighbor',
					name: 'core/paragraph',
					attributes: {},
					innerBlocks: [],
				},
			],
			nextInsertBlockCount: 1,
			canRemoveBlocks: () => false,
		} );

		const result = applyOperation( {
			editor,
			parser: parseCachedNestedPatternBlocks,
		} );

		expect( result ).toEqual(
			expect.objectContaining( {
				ok: false,
				code: 'rollback_failed',
			} )
		);
		expect(
			editor.blockEditorDispatch.removeBlocks
		).not.toHaveBeenCalled();
		expect(
			editor.blockEditorSelect.getBlock( 'after-neighbor' )
		).not.toBeNull();
		expect( result.operations ).toBeUndefined();
	} );

	test.each( [
		{ label: 'truthy lock', remove: true, ok: false },
		{ label: 'explicit false lock', remove: false, ok: true },
		{ label: 'malformed truthy lock', remove: 'yes', ok: false },
	] )(
		'uses the parsed top-level block own $label only for rollback capability',
		( { remove, ok } ) => {
			const editor = createBlockEditor();
			const parser = () => [
				{
					clientId: 'locked-source',
					name: 'core/paragraph',
					attributes: { lock: { remove } },
					innerBlocks: [],
				},
			];

			const result = applyOperation( { editor, parser } );

			expect( result.ok ).toBe( ok );
			expect(
				editor.blockEditorDispatch.insertBlocks
			).toHaveBeenCalledTimes( ok ? 1 : 0 );
		}
	);

	test.each( [
		{ attributes: { lock: { move: true, remove: false } }, type: 'insert' },
		{
			attributes: { lock: { move: true, remove: false } },
			type: 'replace',
		},
		{ attributes: { templateLock: 'all' }, type: 'insert' },
		{ attributes: { templateLock: 'all' }, type: 'replace' },
	] )(
		'allows $type beside a selected block with attributes $attributes when Core selectors allow it',
		( { attributes, type } ) => {
			const editor = createBlockEditor( {
				blocks: [
					{
						clientId: 'block-1',
						name: 'core/group',
						attributes,
						innerBlocks: [],
					},
				],
			} );

			const result = applyOperation( {
				editor,
				operation:
					type === 'replace'
						? buildReplaceOperation()
						: baseOperation,
			} );

			expect( result.ok ).toBe( true );
		}
	);

	test( 'keeps move-unlocked remove-locked targets eligible for sibling insertion but not replacement', () => {
		const blocks = [
			{
				clientId: 'block-1',
				name: 'core/group',
				attributes: { lock: { move: false, remove: true } },
				innerBlocks: [],
			},
		];
		const insertEditor = createBlockEditor( {
			blocks,
			canRemoveBlock: ( clientId ) => clientId !== 'block-1',
		} );
		const replaceEditor = createBlockEditor( {
			blocks,
			canRemoveBlock: ( clientId ) => clientId !== 'block-1',
		} );

		expect( applyOperation( { editor: insertEditor } ).ok ).toBe( true );
		expect(
			applyOperation( {
				editor: replaceEditor,
				operation: buildReplaceOperation(),
			} ).ok
		).toBe( false );
		expect(
			replaceEditor.blockEditorDispatch.removeBlocks
		).not.toHaveBeenCalled();
	} );

	test( 'successful insert and replacement operations record exact fresh top-level runtime IDs', () => {
		const insertEditor = createBlockEditor();
		const insertResult = applyOperation( {
			editor: insertEditor,
			parser: parseCachedNestedPatternBlocks,
		} );
		const insertIds =
			insertEditor.state.insertAttempts[ 0 ].topLevelClientIds;

		expect( insertResult.ok ).toBe( true );
		expect( insertResult.operations[ 0 ].insertedClientIds ).toEqual(
			insertIds
		);
		expect( insertIds ).not.toEqual(
			expect.arrayContaining( [
				'cached-pattern-group',
				'cached-pattern-heading',
			] )
		);

		const replaceEditor = createBlockEditor();
		const replaceResult = applyOperation( {
			editor: replaceEditor,
			operation: buildReplaceOperation(),
			parser: parseCachedNestedPatternBlocks,
		} );
		const replacementIds =
			replaceEditor.state.insertAttempts[ 0 ].topLevelClientIds;

		expect( replaceResult.ok ).toBe( true );
		expect( replaceResult.operations[ 0 ].replacementClientIds ).toEqual(
			replacementIds
		);
		expect( replacementIds ).not.toEqual(
			expect.arrayContaining( [
				'cached-pattern-group',
				'cached-pattern-heading',
			] )
		);
	} );

	test( 'undo removes exact inserted runtime IDs', () => {
		const editor = createBlockEditor();
		const result = applyOperation( {
			editor,
			parser: parseCachedNestedPatternBlocks,
		} );
		const insertedClientIds = result.operations[ 0 ].insertedClientIds;
		editor.blockEditorDispatch.removeBlocks.mockClear();

		const undoResult = undoBlockStructuralSuggestionOperations(
			buildActivityFromResult( result ),
			{
				select: () => editor.blockEditorSelect,
				dispatch: () => editor.blockEditorDispatch,
			}
		);

		expect( undoResult ).toEqual( { ok: true } );
		expect( editor.blockEditorDispatch.removeBlocks ).toHaveBeenCalledWith(
			insertedClientIds,
			false
		);
		expect(
			editor.state.blocks.map( ( block ) => block.clientId )
		).toEqual( [ 'block-1' ] );
	} );

	test( 'replacement undo removes exact replacement IDs and restores the original snapshot', () => {
		const originalBlocks = [
			{
				clientId: 'block-1',
				name: 'core/group',
				attributes: { className: 'original' },
				innerBlocks: [
					{
						clientId: 'original-inner',
						name: 'core/paragraph',
						attributes: { content: 'Original' },
						innerBlocks: [],
					},
				],
			},
			{
				clientId: 'neighbor',
				name: 'core/heading',
				attributes: { content: 'Neighbor' },
				innerBlocks: [],
			},
		];
		const editor = createBlockEditor( { blocks: originalBlocks } );
		const result = applyOperation( {
			editor,
			operation: buildReplaceOperation(),
			parser: parseCachedNestedPatternBlocks,
		} );
		const replacementClientIds =
			result.operations[ 0 ].replacementClientIds;
		editor.blockEditorDispatch.removeBlocks.mockClear();
		editor.state.insertAttempts = [];

		const undoResult = undoBlockStructuralSuggestionOperations(
			buildActivityFromResult( result ),
			{
				select: () => editor.blockEditorSelect,
				dispatch: () => editor.blockEditorDispatch,
			}
		);

		expect( undoResult ).toEqual( { ok: true } );
		expect( editor.blockEditorDispatch.removeBlocks ).toHaveBeenCalledWith(
			replacementClientIds,
			false
		);
		expect( editor.state.insertAttempts[ 0 ].topLevelClientIds ).toEqual( [
			'block-1',
		] );
		expect( editor.state.blocks ).toEqual( originalBlocks );
	} );

	test( 'multi-operation undo reverses exact removals and replacement restoration against live state', () => {
		const { activity, beforeBlocks, editor } =
			createMultiOperationUndoFixture();

		const result = undoBlockStructuralSuggestionOperations( activity, {
			select: () => editor.blockEditorSelect,
			dispatch: () => editor.blockEditorDispatch,
		} );

		expect( result ).toEqual( { ok: true } );
		expect( editor.state.mutationEvents ).toEqual( [
			{ type: 'remove', clientIds: [ 'replacement-c' ] },
			{
				type: 'insert',
				clientIds: [ 'original-c' ],
				index: 3,
				rootClientId: '',
			},
			{ type: 'remove', clientIds: [ 'replacement-b' ] },
			{
				type: 'insert',
				clientIds: [ 'original-b' ],
				index: 2,
				rootClientId: '',
			},
			{ type: 'remove', clientIds: [ 'inserted-a' ] },
		] );
		expect( editor.state.blocks ).toEqual( beforeBlocks );
	} );

	test( 'multi-operation undo stops after a middle no-op and preserves the remaining live operations', () => {
		const { activity, editor } = createMultiOperationUndoFixture( {
			noOpRemoveAtAttempt: 2,
		} );

		const result = undoBlockStructuralSuggestionOperations( activity, {
			select: () => editor.blockEditorSelect,
			dispatch: () => editor.blockEditorDispatch,
		} );

		expect( result ).toEqual( {
			ok: false,
			error: 'The structural action could not be undone completely. Review the block structure before continuing.',
		} );
		expect( editor.state.mutationEvents ).toEqual( [
			{ type: 'remove', clientIds: [ 'replacement-c' ] },
			{
				type: 'insert',
				clientIds: [ 'original-c' ],
				index: 3,
				rootClientId: '',
			},
			{ type: 'remove', clientIds: [ 'replacement-b' ] },
		] );
		expect(
			editor.state.blocks.map( ( block ) => block.clientId )
		).toEqual( [
			'keep-start',
			'inserted-a',
			'replacement-b',
			'original-c',
			'keep-end',
		] );
		expect(
			editor.blockEditorSelect.getBlock( 'replacement-c' )
		).toBeNull();
		expect( editor.blockEditorSelect.getBlock( 'original-b' ) ).toBeNull();
		expect( editor.blockEditorSelect.getBlock( 'original-c' ) ).toEqual(
			expect.objectContaining( {
				attributes: { className: 'original-c' },
				innerBlocks: [
					expect.objectContaining( {
						clientId: 'original-c-inner',
						attributes: { content: 'Original nested content' },
					} ),
				],
			} )
		);
	} );

	test.each( [
		{
			label: 'missing runtime IDs',
			buildIds: () => undefined,
			deleteIds: true,
			expectedError:
				'This block structural action is missing its recorded structure and cannot be undone automatically.',
		},
		{
			label: 'empty runtime IDs',
			buildIds: () => [],
			expectedError:
				'This block structural action is missing its recorded structure and cannot be undone automatically.',
		},
		{
			label: 'duplicate runtime IDs',
			buildIds: ( validId ) => [ validId, validId ],
			expectedError:
				'This block structural action is missing its recorded structure and cannot be undone automatically.',
		},
		{
			label: 'unresolved runtime IDs',
			buildIds: () => [ 'missing-id' ],
			expectedError:
				'The recorded blocks are no longer available in this editor session, so this structural action cannot be undone automatically.',
		},
		{
			label: 'wrong-root runtime IDs',
			buildIds: () => [ 'nested-existing' ],
			expectedError:
				'The recorded blocks are no longer available in this editor session, so this structural action cannot be undone automatically.',
		},
	] )(
		'fails undo before dispatch for $label and does not advertise Undo',
		( { buildIds, deleteIds, expectedError } ) => {
			const editor = createBlockEditor( {
				blocks: [
					{
						clientId: 'block-1',
						name: 'core/group',
						attributes: {},
						innerBlocks: [],
					},
					{
						clientId: 'existing-container',
						name: 'core/group',
						attributes: {},
						innerBlocks: [
							{
								clientId: 'nested-existing',
								name: 'core/paragraph',
								attributes: {},
								innerBlocks: [],
							},
						],
					},
				],
			} );
			const result = applyOperation( { editor } );
			const operation = cloneValue( result.operations[ 0 ] );
			const validId =
				editor.state.insertAttempts[ 0 ].topLevelClientIds[ 0 ];

			if ( deleteIds ) {
				delete operation.insertedClientIds;
			} else {
				operation.insertedClientIds = buildIds( validId );
			}

			const activity = buildActivityFromResult( result, [ operation ] );
			editor.blockEditorDispatch.removeBlocks.mockClear();
			editor.blockEditorDispatch.insertBlocks.mockClear();

			expect(
				getBlockStructuralActivityUndoState(
					activity,
					editor.blockEditorSelect
				)
			).toEqual(
				expect.objectContaining( {
					canUndo: false,
					status: 'failed',
					error: expectedError,
				} )
			);
			const undoResult = undoBlockStructuralSuggestionOperations(
				activity,
				{
					select: () => editor.blockEditorSelect,
					dispatch: () => editor.blockEditorDispatch,
				}
			);
			expect( undoResult ).toEqual( {
				ok: false,
				error: expectedError,
			} );
			expect(
				editor.blockEditorDispatch.removeBlocks
			).not.toHaveBeenCalled();
			expect(
				editor.blockEditorDispatch.insertBlocks
			).not.toHaveBeenCalled();
		}
	);

	test( 'fails undo permission preflight before dispatch for native denial and missing selectors', () => {
		for ( const mode of [ 'denied', 'missing' ] ) {
			const editor = createBlockEditor();
			const result = applyOperation( { editor } );
			const activity = buildActivityFromResult( result );
			editor.blockEditorDispatch.removeBlocks.mockClear();

			if ( mode === 'denied' ) {
				editor.blockEditorSelect.canRemoveBlocks.mockReturnValue(
					false
				);
			} else {
				delete editor.blockEditorSelect.canRemoveBlocks;
			}

			expect(
				getBlockStructuralActivityUndoState(
					activity,
					editor.blockEditorSelect
				)
			).toEqual(
				expect.objectContaining( {
					canUndo: false,
					error: 'The current editor constraints do not allow this structural action to be undone automatically.',
				} )
			);
			expect(
				undoBlockStructuralSuggestionOperations( activity, {
					select: () => editor.blockEditorSelect,
					dispatch: () => editor.blockEditorDispatch,
				} ).ok
			).toBe( false );
			expect(
				editor.blockEditorDispatch.removeBlocks
			).not.toHaveBeenCalled();
		}
	} );

	test( 'replacement undo fails before dispatch when the original target type cannot be reinserted', () => {
		const editor = createBlockEditor();
		const result = applyOperation( {
			editor,
			operation: buildReplaceOperation(),
		} );
		const activity = buildActivityFromResult( result );
		editor.blockEditorDispatch.removeBlocks.mockClear();
		editor.blockEditorDispatch.insertBlocks.mockClear();
		editor.blockEditorSelect.canInsertBlockType.mockImplementation(
			( name ) => name !== 'core/group'
		);

		expect(
			getBlockStructuralActivityUndoState(
				activity,
				editor.blockEditorSelect
			).canUndo
		).toBe( false );
		expect(
			undoBlockStructuralSuggestionOperations( activity, {
				select: () => editor.blockEditorSelect,
				dispatch: () => editor.blockEditorDispatch,
			} ).ok
		).toBe( false );
		expect(
			editor.blockEditorDispatch.removeBlocks
		).not.toHaveBeenCalled();
		expect(
			editor.blockEditorDispatch.insertBlocks
		).not.toHaveBeenCalled();
	} );

	test( 'undo reports incomplete when exact runtime-ID removal is a no-op', () => {
		const editor = createBlockEditor();
		const result = applyOperation( { editor } );
		const runtimeId =
			editor.state.insertAttempts[ 0 ].topLevelClientIds[ 0 ];
		editor.state.noOpNextRemove = true;
		editor.blockEditorDispatch.removeBlocks.mockClear();

		const undoResult = undoBlockStructuralSuggestionOperations(
			buildActivityFromResult( result ),
			{
				select: () => editor.blockEditorSelect,
				dispatch: () => editor.blockEditorDispatch,
			}
		);

		expect( undoResult ).toEqual( {
			ok: false,
			error: 'The structural action could not be undone completely. Review the block structure before continuing.',
		} );
		expect( editor.blockEditorSelect.getBlock( runtimeId ) ).not.toBeNull();
	} );

	test( 'replacement undo reports incomplete when original restoration is a no-op', () => {
		const editor = createBlockEditor();
		const result = applyOperation( {
			editor,
			operation: buildReplaceOperation(),
		} );
		editor.state.noOpNextRestoreInsert = true;
		editor.blockEditorDispatch.removeBlocks.mockClear();

		const undoResult = undoBlockStructuralSuggestionOperations(
			buildActivityFromResult( result ),
			{
				select: () => editor.blockEditorSelect,
				dispatch: () => editor.blockEditorDispatch,
			}
		);

		expect( undoResult ).toEqual( {
			ok: false,
			error: 'The structural action could not be undone completely. Review the block structure before continuing.',
		} );
		expect( editor.blockEditorSelect.getBlock( 'block-1' ) ).toBeNull();
	} );

	test( 'post-apply structural drift blocks undo before runtime-ID permission checks', () => {
		const editor = createBlockEditor();
		const result = applyOperation( { editor } );
		editor.state.blocks[ 1 ].attributes.content = 'Edited after apply';
		editor.blockEditorSelect.canRemoveBlocks.mockClear();
		editor.blockEditorDispatch.removeBlocks.mockClear();

		const undoResult = undoBlockStructuralSuggestionOperations(
			buildActivityFromResult( result ),
			{
				select: () => editor.blockEditorSelect,
				dispatch: () => editor.blockEditorDispatch,
			}
		);

		expect( undoResult ).toEqual(
			expect.objectContaining( {
				ok: false,
				error: 'The block structure changed after Flavor Agent applied this suggestion and cannot be undone automatically.',
			} )
		);
		expect(
			editor.blockEditorSelect.canRemoveBlocks
		).not.toHaveBeenCalled();
		expect(
			editor.blockEditorDispatch.removeBlocks
		).not.toHaveBeenCalled();
	} );

	test( 'reloaded structurally identical blocks with regenerated IDs fail at the editor-session boundary', () => {
		const applyEditor = createBlockEditor();
		const result = applyOperation( {
			editor: applyEditor,
			parser: parseCachedNestedPatternBlocks,
		} );
		let reloadSequence = 0;
		const regenerateIds = ( blocks ) =>
			blocks.map( ( block ) => ( {
				...cloneValue( block ),
				clientId: `reloaded-${ ++reloadSequence }`,
				innerBlocks: regenerateIds( block.innerBlocks || [] ),
			} ) );
		const reloadEditor = createBlockEditor( {
			blocks: regenerateIds( applyEditor.state.blocks ),
		} );
		const activity = buildActivityFromResult( result );

		expect(
			getBlockStructuralActivityUndoState(
				activity,
				reloadEditor.blockEditorSelect
			)
		).toEqual(
			expect.objectContaining( {
				canUndo: false,
				status: 'failed',
				error: 'The recorded blocks are no longer available in this editor session, so this structural action cannot be undone automatically.',
			} )
		);
		expect(
			undoBlockStructuralSuggestionOperations( activity, {
				select: () => reloadEditor.blockEditorSelect,
				dispatch: () => reloadEditor.blockEditorDispatch,
			} )
		).toEqual( {
			ok: false,
			error: 'The recorded blocks are no longer available in this editor session, so this structural action cannot be undone automatically.',
		} );
		expect(
			reloadEditor.blockEditorDispatch.removeBlocks
		).not.toHaveBeenCalled();
		expect(
			reloadEditor.blockEditorDispatch.insertBlocks
		).not.toHaveBeenCalled();
	} );
} );
