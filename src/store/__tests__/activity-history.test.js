jest.mock( '../../style-book/dom', () => ( {
	getStyleBookUiState: jest.fn( () => ( {
		isActive: false,
		target: null,
	} ) ),
} ) );

import {
	createActivityEntry,
	getBlockActivityUndoState,
	getCurrentActivityScope,
	getLatestUndoableActivity,
	getResolvedActivityEntries,
	readPersistedActivityLog,
	resolveActivityScope,
	resolveGlobalStylesScope,
	resolveStyleBookScope,
	writePersistedActivityLog,
} from '../activity-history';
import { getStyleBookUiState } from '../../style-book/dom';

describe( 'activity history helpers', () => {
	beforeEach( () => {
		window.sessionStorage.clear();
		getStyleBookUiState.mockReturnValue( {
			isActive: false,
			target: null,
		} );
	} );

	test( 'getCurrentActivityScope resolves the current edited entity from registry selectors', () => {
		expect(
			getCurrentActivityScope( {
				select: ( storeName ) =>
					storeName === 'core/editor'
						? {
								getCurrentPostType: () => 'post',
								getCurrentPostId: () => 42,
						  }
						: {},
			} )
		).toEqual( {
			key: 'post:42',
			hint: 'post:42',
			postType: 'post',
			entityId: '42',
		} );
	} );

	test( 'resolveActivityScope keeps unsaved documents unscoped until they have a real entity id', () => {
		expect( resolveActivityScope( 'post', '' ) ).toEqual( {
			key: null,
			hint: 'post:__unsaved__',
			postType: 'post',
			entityId: '',
		} );
		expect(
			getCurrentActivityScope( {
				select: ( storeName ) =>
					storeName === 'core/editor'
						? {
								getCurrentPostType: () => 'post',
								getCurrentPostId: () => null,
						  }
						: {},
			} )
		).toEqual( {
			key: null,
			hint: 'post:__unsaved__',
			postType: 'post',
			entityId: '',
		} );
	} );

	test( 'persisted activity history is scoped per document', () => {
		const entry = createActivityEntry( {
			type: 'apply_suggestion',
			surface: 'block',
			suggestion: 'Refresh content',
			target: {
				clientId: 'block-1',
			},
		} );

		writePersistedActivityLog( 'post:42', [ entry ] );

		expect( readPersistedActivityLog( 'post:42' ) ).toEqual( [ entry ] );
		expect( readPersistedActivityLog( 'post:99' ) ).toEqual( [] );
	} );

	test( 'resolveGlobalStylesScope returns a stable explicit scope key', () => {
		expect( resolveGlobalStylesScope( '17' ) ).toEqual( {
			key: 'global_styles:17',
			hint: 'global_styles:17',
			postType: 'global_styles',
			entityId: '17',
			entityKind: 'root',
			entityName: 'globalStyles',
			stylesheet: '',
		} );
	} );

	test( 'resolveStyleBookScope returns a stable block-scoped session key', () => {
		expect(
			resolveStyleBookScope( '17', 'core/paragraph', {
				blockTitle: 'Paragraph',
			} )
		).toEqual( {
			key: 'style_book:17:core/paragraph',
			hint: 'style_book:17:core/paragraph',
			postType: 'global_styles',
			entityId: '17',
			entityKind: 'block',
			entityName: 'styleBook',
			globalStylesId: '17',
			blockName: 'core/paragraph',
			blockTitle: 'Paragraph',
			stylesheet: '',
		} );
	} );

	test( 'getCurrentActivityScope resolves Style Book targets before falling back to Global Styles', () => {
		getStyleBookUiState.mockReturnValue( {
			isActive: true,
			target: {
				blockName: 'core/paragraph',
				blockTitle: 'Paragraph',
			},
		} );

		expect(
			getCurrentActivityScope( {
				select: ( storeName ) =>
					storeName === 'core/interface'
						? {
								getActiveComplementaryArea: () =>
									'edit-site/global-styles',
						  }
						: storeName === 'core'
							? {
									__experimentalGetCurrentGlobalStylesId:
										() => '17',
							  }
							: {},
			} )
		).toEqual(
			expect.objectContaining( {
				key: 'style_book:17:core/paragraph',
				globalStylesId: '17',
				blockName: 'core/paragraph',
			} )
		);
	} );

	test( 'legacy persisted template activity entries load as non-undoable', () => {
		window.sessionStorage.setItem(
			'flavor-agent:activity:wp_template:home',
			JSON.stringify( {
				version: 1,
				updatedAt: '2026-03-23T00:00:00Z',
				entries: [
					{
						id: 'activity-1',
						surface: 'template',
						type: 'apply_template_suggestion',
						timestamp: '2026-03-23T00:00:00Z',
						after: {
							operations: [
								{
									type: 'insert_pattern',
									patternName: 'theme/hero',
									insertedClientIds: [ 'legacy-1' ],
								},
							],
						},
						undo: {
							canUndo: true,
							status: 'available',
							error: null,
							updatedAt: '2026-03-23T00:00:00Z',
							undoneAt: null,
						},
					},
				],
			} )
		);

		expect( readPersistedActivityLog( 'wp_template:home' ) ).toEqual( [
			expect.objectContaining( {
				id: 'activity-1',
				undo: expect.objectContaining( {
					canUndo: false,
					status: 'failed',
					error: expect.stringContaining(
						'before refresh-safe undo support'
					),
				} ),
			} ),
		] );
	} );

	test( 'ordered undo eligibility only unlocks older entries after newer ones are undone', () => {
		const older = createActivityEntry( {
			type: 'apply_suggestion',
			surface: 'block',
			suggestion: 'Refresh content',
			target: {
				clientId: 'block-1',
				blockPath: [ 0 ],
			},
			document: {
				scopeKey: 'post:42',
			},
			timestamp: '2026-03-24T10:00:00Z',
		} );
		const newer = createActivityEntry( {
			type: 'apply_suggestion',
			surface: 'block',
			suggestion: 'Tighten spacing',
			target: {
				clientId: 'block-1',
				blockPath: [ 0 ],
			},
			document: {
				scopeKey: 'post:42',
			},
			timestamp: '2026-03-24T10:00:01Z',
		} );
		const undoneNewer = {
			...newer,
			undo: {
				canUndo: false,
				status: 'undone',
				error: null,
				updatedAt: '2026-03-24T10:01:00Z',
				undoneAt: '2026-03-24T10:01:00Z',
			},
		};

		expect(
			getResolvedActivityEntries( [ older, newer ] )[ 0 ].undo
		).toEqual(
			expect.objectContaining( {
				canUndo: false,
				status: 'blocked',
			} )
		);
		expect( getLatestUndoableActivity( [ older, newer ] ) ).toEqual(
			expect.objectContaining( {
				id: newer.id,
			} )
		);
		expect( getLatestUndoableActivity( [ older, undoneNewer ] ) ).toEqual(
			expect.objectContaining( {
				id: older.id,
			} )
		);
	} );

	test( 'block runtime state treats a native-undone newer action as undone and re-unlocks the older AI action', () => {
		const older = createActivityEntry( {
			type: 'apply_suggestion',
			surface: 'block',
			target: {
				clientId: 'block-1',
				blockName: 'core/paragraph',
				blockPath: [ 0 ],
			},
			before: {
				attributes: {
					content: 'Alpha',
				},
			},
			after: {
				attributes: {
					content: 'Beta',
				},
			},
			document: {
				scopeKey: 'post:42',
			},
			timestamp: '2026-03-24T10:00:00Z',
		} );
		const newer = createActivityEntry( {
			type: 'apply_suggestion',
			surface: 'block',
			target: {
				clientId: 'block-1',
				blockName: 'core/paragraph',
				blockPath: [ 0 ],
			},
			before: {
				attributes: {
					content: 'Beta',
				},
			},
			after: {
				attributes: {
					content: 'Gamma',
				},
			},
			document: {
				scopeKey: 'post:42',
			},
			timestamp: '2026-03-24T10:00:01Z',
		} );
		const currentBlock = {
			clientId: 'block-1',
			name: 'core/paragraph',
			attributes: {
				content: 'Beta',
			},
		};
		const blockEditorSelect = {
			getBlock: () => currentBlock,
			getBlockAttributes: () => currentBlock.attributes,
			getBlocks: () => [ currentBlock ],
		};

		const resolvedEntries = getResolvedActivityEntries(
			[ older, newer ],
			( entry ) => getBlockActivityUndoState( entry, blockEditorSelect )
		);

		expect( resolvedEntries[ 0 ].undo ).toEqual(
			expect.objectContaining( {
				status: 'available',
				canUndo: true,
			} )
		);
		expect( resolvedEntries[ 1 ].undo ).toEqual(
			expect.objectContaining( {
				status: 'undone',
				canUndo: false,
			} )
		);
		expect( getLatestUndoableActivity( resolvedEntries ) ).toEqual(
			expect.objectContaining( {
				id: older.id,
			} )
		);
	} );

	test( 'block runtime state revives an AI action after native redo reapplies the recorded after snapshot', () => {
		const entry = {
			...createActivityEntry( {
				type: 'apply_suggestion',
				surface: 'block',
				target: {
					clientId: 'block-1',
					blockName: 'core/paragraph',
					blockPath: [ 0 ],
				},
				before: {
					attributes: {
						content: 'Before',
					},
				},
				after: {
					attributes: {
						content: 'After',
					},
				},
				document: {
					scopeKey: 'post:42',
				},
			} ),
			undo: {
				canUndo: false,
				status: 'undone',
				error: null,
				updatedAt: '2026-03-24T10:02:00Z',
				undoneAt: '2026-03-24T10:02:00Z',
			},
		};
		const currentBlock = {
			clientId: 'block-1',
			name: 'core/paragraph',
			attributes: {
				content: 'After',
			},
		};
		const blockEditorSelect = {
			getBlock: () => currentBlock,
			getBlockAttributes: () => currentBlock.attributes,
			getBlocks: () => [ currentBlock ],
		};

		expect(
			getResolvedActivityEntries( [ entry ], ( activity ) =>
				getBlockActivityUndoState( activity, blockEditorSelect )
			)[ 0 ].undo
		).toEqual(
			expect.objectContaining( {
				status: 'available',
				canUndo: true,
				error: null,
			} )
		);
	} );
} );
