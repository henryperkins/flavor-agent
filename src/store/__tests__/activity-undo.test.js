// activity-undo transitively imports `@wordpress/block-editor` via
// template-actions; we don't exercise either here, so the upstream modules
// are stubbed to keep the test self-contained.
jest.mock( '../../utils/template-actions', () => ( {
	applyTemplatePartSuggestionOperations: jest.fn(),
	applyTemplateSuggestionOperations: jest.fn(),
	getTemplateActivityUndoState: jest.fn(),
	getTemplatePartActivityUndoState: jest.fn(),
	undoTemplatePartSuggestionOperations: jest.fn(),
	undoTemplateSuggestionOperations: jest.fn(),
} ) );
jest.mock( '../../utils/style-operations', () => ( {
	applyGlobalStyleSuggestionOperations: jest.fn(),
	getGlobalStylesActivityUndoState: jest.fn(),
	undoGlobalStyleSuggestionOperations: jest.fn(),
} ) );
jest.mock( '../../utils/block-structural-actions', () => ( {
	undoBlockStructuralSuggestionOperations: jest.fn(),
} ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn() );

import {
	buildBlockBatchActivityEntry,
	buildGlobalStylesActivityEntry,
	buildStyleBookActivityEntry,
	createUndoActivityAction,
} from '../activity-undo';
import { writePersistedActivityLog } from '../activity-history';
import { undoGlobalStyleSuggestionOperations } from '../../utils/style-operations';

describe( 'buildStyleBookActivityEntry', () => {
	const baseUserConfig = {
		settings: {
			color: { palette: [ { slug: 'accent', color: '#00f' } ] },
		},
		styles: {
			color: { background: 'var:preset|color|accent' },
			blocks: {
				'core/paragraph': {
					typography: { fontSize: 'var:preset|font-size|body' },
				},
				'core/heading': {
					color: { text: 'var:preset|color|accent' },
				},
			},
		},
	};

	test( 'trims before/after userConfig to the targeted block branch', () => {
		const entry = buildStyleBookActivityEntry( {
			operations: [
				{
					type: 'set_block_styles',
					blockName: 'core/paragraph',
					path: [ 'color', 'background' ],
					value: 'var:preset|color|accent',
					valueType: 'preset',
				},
			],
			beforeConfig: baseUserConfig,
			afterConfig: {
				...baseUserConfig,
				styles: {
					...baseUserConfig.styles,
					blocks: {
						...baseUserConfig.styles.blocks,
						'core/paragraph': {
							typography: {
								fontSize: 'var:preset|font-size|body',
							},
							color: { background: 'var:preset|color|accent' },
						},
					},
				},
			},
			scope: {
				surface: 'style-book',
				scopeKey: 'style_book:17:core/paragraph',
				postType: 'global_styles',
				entityId: 'core/paragraph',
				entityKind: 'block',
				entityName: 'styleBook',
				stylesheet: 'twentytwentyfive',
			},
			suggestion: {
				label: 'Brighten paragraph background',
				suggestionKey: 'k1',
			},
			globalStylesId: '17',
			blockName: 'core/paragraph',
			blockTitle: 'Paragraph',
		} );

		expect( entry.before.userConfig ).toEqual( {
			styles: {
				blocks: {
					'core/paragraph': {
						typography: {
							fontSize: 'var:preset|font-size|body',
						},
					},
				},
			},
		} );
		expect( entry.after.userConfig ).toEqual( {
			styles: {
				blocks: {
					'core/paragraph': {
						typography: {
							fontSize: 'var:preset|font-size|body',
						},
						color: { background: 'var:preset|color|accent' },
					},
				},
			},
		} );
		// settings, top-level styles.color and the unrelated core/heading
		// branch are excluded — undo only needs the targeted block branch.
		expect( entry.before.userConfig.settings ).toBeUndefined();
		expect(
			entry.before.userConfig?.styles?.blocks?.[ 'core/heading' ]
		).toBeUndefined();
		expect( entry.after.operations ).toHaveLength( 1 );
	} );

	test( 'returns an empty userConfig when the block branch is missing before apply', () => {
		const entry = buildStyleBookActivityEntry( {
			operations: [],
			beforeConfig: { styles: {} },
			afterConfig: {
				styles: {
					blocks: {
						'core/quote': {
							typography: { lineHeight: '1.5' },
						},
					},
				},
			},
			scope: {
				surface: 'style-book',
				scopeKey: 'style_book:17:core/quote',
			},
			suggestion: { label: 'Tighten quote rhythm', suggestionKey: 'k2' },
			globalStylesId: '17',
			blockName: 'core/quote',
			blockTitle: 'Quote',
		} );

		// No prior block branch → before.userConfig is empty so the undo path
		// can call removePath safely. After contains the new branch.
		expect( entry.before.userConfig ).toEqual( {} );
		expect( entry.after.userConfig ).toEqual( {
			styles: {
				blocks: {
					'core/quote': {
						typography: { lineHeight: '1.5' },
					},
				},
			},
		} );
	} );

	test( 'returns an empty userConfig when blockName is missing', () => {
		const entry = buildStyleBookActivityEntry( {
			operations: [],
			beforeConfig: baseUserConfig,
			afterConfig: baseUserConfig,
			scope: { surface: 'style-book', scopeKey: 'style_book:17:' },
			suggestion: { label: '', suggestionKey: null },
			globalStylesId: '17',
			blockName: '',
			blockTitle: '',
		} );

		expect( entry.before.userConfig ).toEqual( {} );
		expect( entry.after.userConfig ).toEqual( {} );
	} );
} );

describe( 'buildGlobalStylesActivityEntry', () => {
	test( 'preserves the full user config (no trim)', () => {
		const beforeConfig = {
			settings: { color: { palette: [] } },
			styles: { color: { background: '#fff' } },
		};
		const afterConfig = {
			settings: { color: { palette: [] } },
			styles: { color: { background: 'var:preset|color|accent' } },
		};

		const entry = buildGlobalStylesActivityEntry( {
			operations: [
				{
					type: 'set_styles',
					path: [ 'color', 'background' ],
					value: 'var:preset|color|accent',
				},
			],
			beforeConfig,
			afterConfig,
			scope: {
				surface: 'global-styles',
				scopeKey: 'global_styles:17',
			},
			suggestion: { label: 'Use accent canvas', suggestionKey: 'k3' },
			globalStylesId: '17',
		} );

		// Wholesale undo for global-styles depends on the full snapshot, so
		// no trimming here.
		expect( entry.before.userConfig ).toEqual( beforeConfig );
		expect( entry.after.userConfig ).toEqual( afterConfig );
	} );
} );

describe( 'createUndoActivityAction', () => {
	beforeEach( () => {
		window.sessionStorage.clear();
		undoGlobalStyleSuggestionOperations.mockReturnValue( { ok: true } );
	} );

	test( 'targets the provided toast activity scope before resolving the undo entry', async () => {
		const targetDocument = {
			scopeKey: 'global_styles:17',
			postType: 'global_styles',
			entityId: '17',
		};
		const targetEntry = {
			id: 'activity-17',
			surface: 'global-styles',
			document: targetDocument,
			target: {
				globalStylesId: '17',
			},
			undo: {
				canUndo: true,
				status: 'available',
			},
			persistence: {
				status: 'local',
			},
			timestamp: '2026-05-09T10:00:00.000Z',
		};
		let scopeKey = 'post:99';
		let activityLog = [];
		const dispatched = [];
		const setActivitySession = jest.fn( ( nextScopeKey, entries ) => ( {
			type: 'SET_ACTIVITY_SESSION',
			scopeKey: nextScopeKey,
			entries,
		} ) );
		const setUndoState = jest.fn( ( status, error, activityId ) => ( {
			type: 'SET_UNDO_STATE',
			status,
			error,
			activityId,
		} ) );
		const updateActivityUndoState = jest.fn(
			( activityId, status, error, timestamp, persistence ) => ( {
				type: 'UPDATE_UNDO_STATE',
				activityId,
				status,
				error,
				timestamp,
				persistence,
			} )
		);
		const undoActivity = createUndoActivityAction( {
			getCurrentActivityScope: () => ( {
				scopeKey: 'post:99',
				postType: 'post',
				entityId: '99',
			} ),
			setActivitySession,
			setUndoState,
			updateActivityUndoState,
		} );
		const dispatch = jest.fn( ( action ) => {
			dispatched.push( action );

			if ( action?.type === 'SET_ACTIVITY_SESSION' ) {
				scopeKey = action.scopeKey;
				activityLog = action.entries;
			}

			return action;
		} );
		const select = {
			getActivityScopeKey: () => scopeKey,
			getActivityLog: () => activityLog,
		};

		writePersistedActivityLog( targetDocument.scopeKey, [ targetEntry ] );

		const result = await undoActivity( 'activity-17', {
			document: targetDocument,
			scopeKey: targetDocument.scopeKey,
		} )( {
			dispatch,
			registry: {},
			select,
		} );

		expect( result.ok ).toBe( true );
		expect( setActivitySession ).toHaveBeenCalledWith(
			'global_styles:17',
			expect.arrayContaining( [
				expect.objectContaining( {
					document: targetDocument,
					id: 'activity-17',
				} ),
			] )
		);
		expect( undoGlobalStyleSuggestionOperations ).toHaveBeenCalledWith(
			expect.objectContaining( {
				id: 'activity-17',
			} ),
			{}
		);
		expect( setUndoState ).toHaveBeenCalledWith(
			'success',
			null,
			'activity-17'
		);
		expect(
			dispatched.some(
				( action ) =>
					action?.type === 'SET_UNDO_STATE' &&
					action.status === 'error' &&
					action.error === 'There is no AI action available to undo.'
			)
		).toBe( false );
	} );

	test( 'buildBlockBatchActivityEntry records ordered members and noun phrase', () => {
		const entry = buildBlockBatchActivityEntry( {
			afterAttributes: {
				align: 'wide',
				style: { color: { background: 'var:preset|color|accent' } },
			},
			beforeAttributes: {
				align: undefined,
				style: {},
			},
			blockContext: { name: 'core/group' },
			blockPath: [ 0 ],
			clientId: 'block-1',
			memberSuggestionKeys: [ 'block:settings:1', 'block:styles:1' ],
			recommendationSetId: 'block:1:hash_set',
			requestPrompt: 'Improve this block.',
			requestToken: 1,
			scope: { scopeKey: 'post:1' },
			suggestionKey: 'block-batch:block:1:hash_set:hash_members',
		} );

		expect( entry.type ).toBe( 'apply_suggestion' );
		expect( entry.suggestion ).toBe( '2 suggestions' );
		expect( entry.suggestionKey ).toBe(
			'block-batch:block:1:hash_set:hash_members'
		);
		expect( entry.target.members ).toEqual( [
			'block:settings:1',
			'block:styles:1',
		] );
		expect( entry.request.recommendation.members ).toEqual( [
			'block:settings:1',
			'block:styles:1',
		] );
	} );
} );
