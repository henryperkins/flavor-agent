jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '../../utils/template-actions', () => ( {
	applyTemplatePartSuggestionOperations: jest.fn(),
	applyTemplateSuggestionOperations: jest.fn(),
	getTemplateActivityUndoState: jest.fn(
		( activity ) => activity?.undo || {}
	),
	getTemplatePartActivityUndoState: jest.fn(
		( activity ) => activity?.undo || {}
	),
	undoTemplatePartSuggestionOperations: jest.fn(),
	undoTemplateSuggestionOperations: jest.fn(),
} ) );

import apiFetch from '@wordpress/api-fetch';

import {
	applyTemplatePartSuggestionOperations,
	applyTemplateSuggestionOperations,
	getTemplateActivityUndoState,
	getTemplatePartActivityUndoState,
	undoTemplatePartSuggestionOperations,
	undoTemplateSuggestionOperations,
} from '../../utils/template-actions';
import {
	createActivityEntry,
	readPersistedActivityLog,
} from '../activity-history';
import { actions } from '../index';

describe( 'store action thunks', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		jest.useFakeTimers();
		window.sessionStorage.clear();
		actions._activitySessionLoadToken = 0;
		actions._activitySessionRetryTimer = null;
		actions._navigationAbort = null;
		actions._patternAbort = null;
		actions._templateAbort = null;
		actions._templatePartAbort = null;
		actions._globalStylesAbort = null;
		actions._styleBookAbort = null;
		getTemplateActivityUndoState.mockImplementation(
			( activity ) => activity?.undo || {}
		);
		getTemplatePartActivityUndoState.mockImplementation(
			( activity ) => activity?.undo || {}
		);
	} );

	afterEach( () => {
		if ( actions._activitySessionRetryTimer ) {
			window.clearTimeout( actions._activitySessionRetryTimer );
			actions._activitySessionRetryTimer = null;
		}

		jest.useRealTimers();
	} );

	test( 'fetchBlockRecommendations reads request state from thunk selectors', async () => {
		apiFetch.mockResolvedValue( {
			payload: {
				settings: [],
				styles: [],
				block: [],
				explanation: 'Mocked response',
			},
		} );

		const dispatch = jest.fn();
		const select = {
			getBlockRequestToken: jest.fn().mockReturnValue( 2 ),
		};
		const context = {
			block: {
				name: 'core/paragraph',
			},
		};

		await actions.fetchBlockRecommendations(
			'block-1',
			context,
			'Tighten this copy.'
		)( {
			dispatch,
			select,
		} );

		expect( select.getBlockRequestToken ).toHaveBeenCalledWith( 'block-1' );
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-block',
				method: 'POST',
				data: {
					editorContext: context,
					prompt: 'Tighten this copy.',
					clientId: 'block-1',
				},
			} )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setBlockRequestState( 'block-1', 'loading', null, 3 )
		);
		expect( dispatch.mock.calls[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( {
				type: 'SET_BLOCK_RECS',
				clientId: 'block-1',
				requestToken: 3,
				recommendations: expect.objectContaining( {
					blockName: 'core/paragraph',
					blockContext: context.block,
					explanation: 'Mocked response',
				} ),
			} )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			3,
			actions.setBlockRequestState( 'block-1', 'ready', null, 3 )
		);
	} );

	test( 'fetchNavigationRecommendations reads request token from thunk selectors', async () => {
		apiFetch.mockResolvedValue( {
			suggestions: [ { label: 'Group utility links' } ],
			explanation: 'Mocked navigation response',
		} );

		const dispatch = jest.fn();
		const select = {
			getNavigationRequestToken: jest.fn().mockReturnValue( 1 ),
		};
		const input = {
			blockClientId: 'nav-1',
			menuId: 42,
			navigationMarkup:
				'<!-- wp:navigation --><!-- wp:navigation-link {"label":"Home"} /--><!-- /wp:navigation -->',
			prompt: 'Simplify the header menu.',
		};

		await actions.fetchNavigationRecommendations( input )( {
			dispatch,
			select,
		} );

		expect( select.getNavigationRequestToken ).toHaveBeenCalled();
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-navigation',
				method: 'POST',
				data: {
					menuId: 42,
					navigationMarkup:
						'<!-- wp:navigation --><!-- wp:navigation-link {"label":"Home"} /--><!-- /wp:navigation -->',
					prompt: 'Simplify the header menu.',
				},
			} )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setNavigationStatus( 'loading', null, 2, 'nav-1' )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			2,
			actions.setNavigationRecommendations(
				'nav-1',
				{
					suggestions: [ { label: 'Group utility links' } ],
					explanation: 'Mocked navigation response',
				},
				'Simplify the header menu.',
				2
			)
		);
	} );

	test( 'fetchNavigationRecommendations dispatches fallback data on request failures', async () => {
		apiFetch.mockRejectedValue( new Error( 'Network blew up.' ) );

		const dispatch = jest.fn();
		const select = {
			getNavigationRequestToken: jest.fn().mockReturnValue( 3 ),
		};
		const input = {
			blockClientId: 'nav-2',
			menuId: 84,
			prompt: 'Tighten the utility links.',
		};

		await actions.fetchNavigationRecommendations( input )( {
			dispatch,
			select,
		} );

		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setNavigationStatus( 'loading', null, 4, 'nav-2' )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			2,
			actions.setNavigationRecommendations(
				'nav-2',
				{
					suggestions: [],
					explanation: '',
				},
				'Tighten the utility links.',
				4
			)
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			3,
			actions.setNavigationStatus(
				'error',
				'Network blew up.',
				4,
				'nav-2'
			)
		);
		expect( actions._navigationAbort ).toBeNull();
	} );

	test( 'fetchTemplateRecommendations reads request token from thunk selectors', async () => {
		apiFetch.mockResolvedValue( {
			suggestions: [ { label: 'Refresh template hierarchy' } ],
			explanation: 'Mocked template response',
		} );

		const dispatch = jest.fn();
		const select = {
			getTemplateRequestToken: jest.fn().mockReturnValue( 4 ),
		};
		const input = {
			templateRef: 'theme//home',
			prompt: 'Tighten the structure.',
			templateType: 'home',
		};

		await actions.fetchTemplateRecommendations( input )( {
			dispatch,
			select,
		} );

		expect( select.getTemplateRequestToken ).toHaveBeenCalled();
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-template',
				method: 'POST',
				data: input,
			} )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setTemplateStatus( 'loading', null, 5 )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			2,
			actions.setTemplateRecommendations(
				'theme//home',
				{
					suggestions: [ { label: 'Refresh template hierarchy' } ],
					explanation: 'Mocked template response',
				},
				'Tighten the structure.',
				5
			)
		);
	} );

	test( 'fetchGlobalStylesRecommendations stores the request context signature without posting it to the API', async () => {
		apiFetch.mockResolvedValue( {
			suggestions: [ { label: 'Use accent canvas' } ],
			explanation: 'Mocked Global Styles response',
		} );

		const dispatch = jest.fn();
		const select = {
			getGlobalStylesRequestToken: jest.fn().mockReturnValue( 2 ),
		};
		const input = {
			scope: {
				surface: 'global-styles',
				scopeKey: 'global_styles:17',
				globalStylesId: '17',
			},
			styleContext: {
				currentConfig: { styles: {} },
				mergedConfig: { styles: {} },
				availableVariations: [],
				themeTokenDiagnostics: {
					source: 'stable',
					settingsKey: 'features',
					reason: 'stable-parity',
				},
			},
			prompt: 'Make the site feel more editorial.',
			contextSignature:
				'{"scopeKey":"global_styles:17","globalStylesId":"17"}',
		};

		await actions.fetchGlobalStylesRecommendations( input )( {
			dispatch,
			select,
		} );

		expect( select.getGlobalStylesRequestToken ).toHaveBeenCalled();
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-style',
				method: 'POST',
				data: {
					scope: input.scope,
					styleContext: input.styleContext,
					prompt: input.prompt,
				},
			} )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setGlobalStylesStatus( 'loading', null, 3 )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			2,
			actions.setGlobalStylesRecommendations(
				input.scope,
				{
					suggestions: [ { label: 'Use accent canvas' } ],
					explanation: 'Mocked Global Styles response',
				},
				'Make the site feel more editorial.',
				3,
				input.contextSignature
			)
		);
	} );

	test( 'fetchPatternRecommendations aborts the previous request and ignores abort errors', async () => {
		const previousAbort = jest.fn();
		actions._patternAbort = { abort: previousAbort };
		apiFetch.mockRejectedValue( { name: 'AbortError' } );

		const dispatch = jest.fn();

		await actions.fetchPatternRecommendations( {
			postType: 'page',
			prompt: 'Find cleaner pattern options.',
		} )( {
			dispatch,
			select: {},
		} );

		expect( previousAbort ).toHaveBeenCalledTimes( 1 );
		expect( dispatch ).toHaveBeenCalledTimes( 1 );
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setPatternStatus( 'loading' )
		);
		expect( actions._patternAbort ).toBeNull();
	} );

	test( 'fetchStyleBookRecommendations stores block-scoped request metadata without posting the context signature', async () => {
		apiFetch.mockResolvedValue( {
			suggestions: [ { label: 'Tighten paragraph rhythm' } ],
			explanation: 'Mocked Style Book response',
		} );

		const dispatch = jest.fn();
		const select = {
			getStyleBookRequestToken: jest.fn().mockReturnValue( 1 ),
		};
		const input = {
			scope: {
				surface: 'style-book',
				scopeKey: 'style_book:17:core/paragraph',
				globalStylesId: '17',
				entityId: 'core/paragraph',
				blockName: 'core/paragraph',
				blockTitle: 'Paragraph',
			},
			styleContext: {
				currentConfig: { styles: {} },
				mergedConfig: { styles: {} },
				availableVariations: [],
				themeTokenDiagnostics: {
					source: 'stable',
				},
				styleBookTarget: {
					blockName: 'core/paragraph',
					blockTitle: 'Paragraph',
					currentStyles: {},
					mergedStyles: {},
				},
			},
			prompt: 'Make the paragraph example feel more editorial.',
			contextSignature:
				'{"scopeKey":"style_book:17:core/paragraph","globalStylesId":"17"}',
		};

		await actions.fetchStyleBookRecommendations( input )( {
			dispatch,
			select,
		} );

		expect( select.getStyleBookRequestToken ).toHaveBeenCalled();
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-style',
				method: 'POST',
				data: {
					scope: input.scope,
					styleContext: input.styleContext,
					prompt: input.prompt,
				},
			} )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setStyleBookStatus( 'loading', null, 2 )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			2,
			actions.setStyleBookRecommendations(
				input.scope,
				{
					suggestions: [ { label: 'Tighten paragraph rhythm' } ],
					explanation: 'Mocked Style Book response',
				},
				input.prompt,
				2,
				input.contextSignature
			)
		);
	} );

	test( 'loadActivitySession migrates in-memory unsaved activity into the first concrete document scope when explicitly allowed', async () => {
		const draftEntry = createActivityEntry( {
			type: 'apply_suggestion',
			surface: 'block',
			suggestion: 'Refresh content',
			target: {
				clientId: 'block-1',
			},
		} );
		const persistedEntry = {
			...draftEntry,
			document: {
				scopeKey: 'post:42',
				postType: 'post',
				entityId: '42',
			},
			persistence: {
				status: 'server',
				updatedAt: draftEntry.timestamp,
			},
		};
		apiFetch.mockImplementation( ( { path, method, data } ) => {
			if ( path === '/flavor-agent/v1/activity' && method === 'POST' ) {
				return Promise.resolve( {
					entry: {
						...data.entry,
						persistence: {
							status: 'server',
							updatedAt: draftEntry.timestamp,
						},
					},
				} );
			}

			if (
				path === '/flavor-agent/v1/activity?scopeKey=post%3A42' &&
				method === 'GET'
			) {
				return Promise.resolve( {
					entries: [ persistedEntry ],
				} );
			}

			return Promise.reject(
				new Error( `Unexpected apiFetch: ${ path }` )
			);
		} );
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getActivityLog: jest.fn().mockReturnValue( [ draftEntry ] ),
		};
		const registry = {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/editor'
					? {
							getCurrentPostType: () => 'post',
							getCurrentPostId: () => 42,
					  }
					: {}
			),
		};

		await actions.loadActivitySession( {
			allowUnsavedMigration: true,
		} )( {
			dispatch,
			registry,
			select,
		} );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/activity',
				method: 'POST',
			} )
		);
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/activity?scopeKey=post%3A42',
				method: 'GET',
			} )
		);
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setActivitySession( 'post:42', [
				expect.objectContaining( {
					id: draftEntry.id,
					document: expect.objectContaining( {
						scopeKey: 'post:42',
						postType: 'post',
						entityId: '42',
					} ),
				} ),
			] )
		);
		expect( readPersistedActivityLog( 'post:42' ) ).toEqual( [
			expect.objectContaining( {
				id: persistedEntry.id,
				document: expect.objectContaining( {
					scopeKey: 'post:42',
					postType: 'post',
					entityId: '42',
				} ),
				persistence: expect.objectContaining( {
					status: 'server',
					updatedAt: draftEntry.timestamp,
				} ),
			} ),
		] );
	} );

	test( 'loadActivitySession does not reassign unsaved activity without an explicit save migration signal', async () => {
		const draftEntry = createActivityEntry( {
			type: 'apply_suggestion',
			surface: 'block',
			suggestion: 'Refresh content',
			target: {
				clientId: 'block-1',
			},
		} );
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getActivityLog: jest.fn().mockReturnValue( [ draftEntry ] ),
		};
		const registry = {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/editor'
					? {
							getCurrentPostType: () => 'post',
							getCurrentPostId: () => 42,
					  }
					: {}
			),
		};

		apiFetch.mockResolvedValue( {
			entries: [],
		} );

		await actions.loadActivitySession()( {
			dispatch,
			registry,
			select,
		} );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/activity?scopeKey=post%3A42',
				method: 'GET',
			} )
		);
		expect( apiFetch ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/activity',
				method: 'POST',
			} )
		);
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setActivitySession( 'post:42', [] )
		);
		expect( readPersistedActivityLog( 'post:42' ) ).toEqual( [] );
	} );

	test( 'loadActivitySession ignores stale async completions from a previous scope', async () => {
		let resolveFirstRequest;
		const firstRequest = new Promise( ( resolve ) => {
			resolveFirstRequest = resolve;
		} );
		const dispatch = jest.fn();
		const firstSelect = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getActivityLog: jest.fn().mockReturnValue( [] ),
		};
		const secondSelect = {
			getActivityScopeKey: jest.fn().mockReturnValue( 'post:42' ),
			getActivityLog: jest.fn().mockReturnValue( [] ),
		};
		const firstRegistry = {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/editor'
					? {
							getCurrentPostType: () => 'post',
							getCurrentPostId: () => 42,
					  }
					: {}
			),
		};
		const secondRegistry = {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/editor'
					? {
							getCurrentPostType: () => 'post',
							getCurrentPostId: () => 99,
					  }
					: {}
			),
		};

		apiFetch.mockImplementation( ( { path, method } ) => {
			if (
				path === '/flavor-agent/v1/activity?scopeKey=post%3A42' &&
				method === 'GET'
			) {
				return firstRequest;
			}

			if (
				path === '/flavor-agent/v1/activity?scopeKey=post%3A99' &&
				method === 'GET'
			) {
				return Promise.resolve( {
					entries: [
						{
							id: 'activity-fresh',
							timestamp: '2026-03-24T10:00:00Z',
						},
					],
				} );
			}

			return Promise.reject(
				new Error( `Unexpected apiFetch: ${ path }` )
			);
		} );

		const firstLoad = actions.loadActivitySession()( {
			dispatch,
			registry: firstRegistry,
			select: firstSelect,
		} );
		const secondLoad = actions.loadActivitySession()( {
			dispatch,
			registry: secondRegistry,
			select: secondSelect,
		} );

		await secondLoad;
		resolveFirstRequest( {
			entries: [
				{
					id: 'activity-stale',
					timestamp: '2026-03-24T09:00:00Z',
				},
			],
		} );
		await firstLoad;

		expect( dispatch ).toHaveBeenCalledWith(
			actions.setActivitySession( 'post:99', [
				{
					id: 'activity-fresh',
					timestamp: '2026-03-24T10:00:00Z',
				},
			] )
		);
		expect( dispatch ).not.toHaveBeenCalledWith(
			actions.setActivitySession( 'post:42', [
				{
					id: 'activity-stale',
					timestamp: '2026-03-24T09:00:00Z',
				},
			] )
		);
	} );

	test( 'loadActivitySession retries pending undo sync before refreshing server history', async () => {
		const pendingEntry = {
			id: 'activity-1',
			type: 'apply_suggestion',
			surface: 'block',
			document: {
				scopeKey: 'post:42',
			},
			undo: {
				canUndo: false,
				status: 'undone',
				error: null,
				updatedAt: '2026-03-24T10:01:00Z',
				undoneAt: '2026-03-24T10:01:00Z',
			},
			persistence: {
				status: 'local',
				syncType: 'undo',
				updatedAt: '2026-03-24T10:01:00Z',
			},
		};
		const syncedEntry = {
			...pendingEntry,
			persistence: {
				status: 'server',
				syncType: null,
				updatedAt: '2026-03-24T10:01:00Z',
			},
		};
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( 'post:42' ),
			getActivityLog: jest.fn().mockReturnValue( [ pendingEntry ] ),
		};
		const registry = {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/editor'
					? {
							getCurrentPostType: () => 'post',
							getCurrentPostId: () => 42,
					  }
					: {}
			),
		};

		apiFetch.mockImplementation( ( { path, method } ) => {
			if (
				path === '/flavor-agent/v1/activity/activity-1/undo' &&
				method === 'POST'
			) {
				return Promise.resolve( {
					entry: syncedEntry,
				} );
			}

			if (
				path === '/flavor-agent/v1/activity?scopeKey=post%3A42' &&
				method === 'GET'
			) {
				return Promise.resolve( {
					entries: [ syncedEntry ],
				} );
			}

			return Promise.reject(
				new Error( `Unexpected apiFetch: ${ path }` )
			);
		} );

		await actions.loadActivitySession()( {
			dispatch,
			registry,
			select,
		} );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/activity/activity-1/undo',
				method: 'POST',
			} )
		);
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setActivitySession( 'post:42', [ syncedEntry ] )
		);
	} );

	test( 'loadActivitySession preserves local retry state when the server copy is stale', async () => {
		const pendingEntry = {
			id: 'activity-1',
			type: 'apply_suggestion',
			surface: 'block',
			document: {
				scopeKey: 'post:42',
			},
			undo: {
				canUndo: false,
				status: 'undone',
				error: null,
				updatedAt: '2026-03-24T10:01:00Z',
				undoneAt: '2026-03-24T10:01:00Z',
			},
			persistence: {
				status: 'local',
				syncType: 'undo',
				updatedAt: '2026-03-24T10:01:00Z',
			},
		};
		const staleServerEntry = {
			...pendingEntry,
			undo: {
				canUndo: true,
				status: 'available',
				error: null,
				updatedAt: '2026-03-24T10:00:00Z',
				undoneAt: null,
			},
			persistence: {
				status: 'server',
				syncType: null,
				updatedAt: '2026-03-24T10:00:00Z',
			},
		};
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( 'post:42' ),
			getActivityLog: jest.fn().mockReturnValue( [ pendingEntry ] ),
		};
		const registry = {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/editor'
					? {
							getCurrentPostType: () => 'post',
							getCurrentPostId: () => 42,
					  }
					: {}
			),
		};

		apiFetch.mockImplementation( ( { path, method } ) => {
			if (
				path === '/flavor-agent/v1/activity/activity-1/undo' &&
				method === 'POST'
			) {
				return Promise.reject( new Error( 'Network unavailable.' ) );
			}

			if (
				path === '/flavor-agent/v1/activity?scopeKey=post%3A42' &&
				method === 'GET'
			) {
				return Promise.resolve( {
					entries: [ staleServerEntry ],
				} );
			}

			return Promise.reject(
				new Error( `Unexpected apiFetch: ${ path }` )
			);
		} );

		await actions.loadActivitySession()( {
			dispatch,
			registry,
			select,
		} );

		expect( dispatch ).toHaveBeenCalledWith(
			actions.setActivitySession( 'post:42', [ pendingEntry ] )
		);
	} );

	test( 'loadActivitySession drops a local undo retry after an authoritative server rejection', async () => {
		const pendingEntry = {
			id: 'activity-1',
			type: 'apply_suggestion',
			surface: 'block',
			document: {
				scopeKey: 'post:42',
			},
			target: {
				clientId: 'block-1',
				blockPath: [ 0 ],
			},
			undo: {
				canUndo: false,
				status: 'undone',
				error: null,
				updatedAt: '2026-03-24T10:01:00Z',
				undoneAt: '2026-03-24T10:01:00Z',
			},
			persistence: {
				status: 'local',
				syncType: 'undo',
				updatedAt: '2026-03-24T10:01:00Z',
			},
		};
		const serverEntry = {
			...pendingEntry,
			undo: {
				canUndo: true,
				status: 'available',
				error: null,
				updatedAt: '2026-03-24T10:00:00Z',
				undoneAt: null,
			},
			persistence: {
				status: 'server',
				syncType: null,
				updatedAt: '2026-03-24T10:00:00Z',
			},
		};
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( 'post:42' ),
			getActivityLog: jest.fn().mockReturnValue( [ pendingEntry ] ),
		};
		const registry = {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/editor'
					? {
							getCurrentPostType: () => 'post',
							getCurrentPostId: () => 42,
					  }
					: {}
			),
		};

		apiFetch.mockImplementation( ( { path, method } ) => {
			if (
				path === '/flavor-agent/v1/activity/activity-1/undo' &&
				method === 'POST'
			) {
				return Promise.reject( {
					code: 'flavor_agent_activity_undo_blocked',
					message: 'Undo blocked by newer AI actions.',
					data: {
						status: 409,
					},
				} );
			}

			if (
				path === '/flavor-agent/v1/activity?scopeKey=post%3A42' &&
				method === 'GET'
			) {
				return Promise.resolve( {
					entries: [ serverEntry ],
				} );
			}

			return Promise.reject(
				new Error( `Unexpected apiFetch: ${ path }` )
			);
		} );

		await actions.loadActivitySession()( {
			dispatch,
			registry,
			select,
		} );

		expect( dispatch ).toHaveBeenCalledWith(
			actions.setActivitySession( 'post:42', [ serverEntry ] )
		);
		expect( readPersistedActivityLog( 'post:42' ) ).toEqual( [
			expect.objectContaining( {
				id: 'activity-1',
				persistence: expect.objectContaining( {
					status: 'server',
					syncType: null,
				} ),
				undo: expect.objectContaining( {
					status: 'available',
					canUndo: true,
				} ),
			} ),
		] );
	} );

	test( 'loadActivitySession honors an explicit scope when registry selectors are late on reload', async () => {
		const serverEntry = {
			id: 'activity-hydrated',
			type: 'apply_suggestion',
			surface: 'block',
			timestamp: '2026-03-24T10:00:00Z',
			target: {
				clientId: 'block-1',
				blockPath: [ 0 ],
				blockName: 'core/paragraph',
			},
			document: {
				scopeKey: 'post:42',
				postType: 'post',
				entityId: '42',
			},
			undo: {
				canUndo: true,
				status: 'available',
			},
			persistence: {
				status: 'server',
			},
		};
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getActivityLog: jest.fn().mockReturnValue( [] ),
		};
		const registry = {
			select: jest.fn().mockReturnValue( {} ),
		};

		apiFetch.mockImplementation( ( { path, method } ) => {
			if (
				path === '/flavor-agent/v1/activity?scopeKey=post%3A42' &&
				method === 'GET'
			) {
				return Promise.resolve( {
					entries: [ serverEntry ],
				} );
			}

			return Promise.reject(
				new Error( `Unexpected apiFetch: ${ path }` )
			);
		} );

		await actions.loadActivitySession( {
			scope: {
				key: 'post:42',
				hint: 'post:42',
				postType: 'post',
				entityId: '42',
			},
		} )( {
			dispatch,
			registry,
			select,
		} );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/activity?scopeKey=post%3A42',
				method: 'GET',
			} )
		);
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setActivitySession( 'post:42', [ serverEntry ] )
		);
		expect( readPersistedActivityLog( 'post:42' ) ).toEqual( [
			expect.objectContaining( {
				id: 'activity-hydrated',
				surface: 'block',
				type: 'apply_suggestion',
				target: expect.objectContaining( {
					clientId: 'block-1',
					blockName: 'core/paragraph',
					blockPath: [ 0 ],
				} ),
				document: expect.objectContaining( {
					scopeKey: 'post:42',
					postType: 'post',
					entityId: '42',
				} ),
				undo: expect.objectContaining( {
					status: 'available',
					canUndo: true,
				} ),
				persistence: expect.objectContaining( {
					status: 'server',
				} ),
			} ),
		] );
	} );

	test( 'loadActivitySession retries once when reload scope is temporarily unavailable', async () => {
		const loadActivitySession = jest.fn();
		window.wp = {
			data: {
				dispatch: jest.fn( ( storeName ) =>
					storeName === 'flavor-agent'
						? {
							loadActivitySession,
						  }
						: {}
				),
			},
		};

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getActivityLog: jest.fn().mockReturnValue( [] ),
		};
		const registry = {
			select: jest.fn().mockReturnValue( {} ),
		};

		await actions.loadActivitySession()( {
			dispatch,
			registry,
			select,
		} );

		expect( dispatch ).not.toHaveBeenCalled();
		expect( loadActivitySession ).not.toHaveBeenCalled();

		jest.advanceTimersByTime( 150 );

		expect( loadActivitySession ).toHaveBeenCalledWith( {
			retryIfScopeUnavailable: false,
		} );

		delete window.wp;
	} );

	test( 'applySuggestion uses registry-backed block-editor access inside thunks', async () => {
		const updateBlockAttributes = jest.fn();
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getBlockRecommendations: jest.fn().mockReturnValue( {
				blockContext: { name: 'core/paragraph' },
				prompt: 'Tighten the copy.',
				requestMeta: {
					backendLabel: 'Azure OpenAI responses',
					model: 'gpt-5.3-chat',
					pathLabel: 'Azure OpenAI via Settings > Flavor Agent',
				},
			} ),
			getBlockRequestToken: jest.fn().mockReturnValue( 4 ),
		};
		const registry = {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/block-editor'
					? {
							getBlocks: jest.fn().mockReturnValue( [
								{
									clientId: 'block-1',
									name: 'core/paragraph',
									attributes: {
										content: 'Old copy',
									},
								},
							] ),
							getBlockAttributes: jest.fn().mockReturnValue( {
								content: 'Old copy',
							} ),
					  }
					: {}
			),
			dispatch: jest.fn().mockReturnValue( {
				updateBlockAttributes,
			} ),
		};

		const result = await actions.applySuggestion( 'block-1', {
			label: 'Refresh content',
			attributeUpdates: {
				content: 'New copy',
			},
		} )( {
			dispatch,
			registry,
			select,
		} );

		expect( select.getBlockRecommendations ).toHaveBeenCalledWith(
			'block-1'
		);
		expect( registry.select ).toHaveBeenCalledWith( 'core/block-editor' );
		expect( registry.dispatch ).toHaveBeenCalledWith( 'core/block-editor' );
		expect( updateBlockAttributes ).toHaveBeenCalledWith( 'block-1', {
			content: 'New copy',
		} );
		expect( dispatch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'LOG_ACTIVITY',
				entry: expect.objectContaining( {
					type: 'apply_suggestion',
					target: expect.objectContaining( {
						clientId: 'block-1',
						blockPath: [ 0 ],
					} ),
					request: expect.objectContaining( {
						prompt: 'Tighten the copy.',
						reference: 'block:block-1:4',
						ai: expect.objectContaining( {
							backendLabel: 'Azure OpenAI responses',
							model: 'gpt-5.3-chat',
						} ),
					} ),
					suggestion: 'Refresh content',
				} ),
			} )
		);
		expect( result ).toBe( true );
	} );

	test( 'applySuggestion surfaces a deterministic error when no safe attribute updates remain', async () => {
		const updateBlockAttributes = jest.fn();
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getBlockRecommendations: jest.fn().mockReturnValue( {
				blockContext: { name: 'core/paragraph' },
				prompt: 'Tighten the copy.',
			} ),
			getBlockRequestToken: jest.fn().mockReturnValue( 4 ),
		};
		const registry = {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/block-editor'
					? {
							getBlocks: jest.fn().mockReturnValue( [] ),
							getBlockAttributes: jest.fn().mockReturnValue( {
								content: 'Old copy',
							} ),
					  }
					: {}
			),
			dispatch: jest.fn().mockReturnValue( {
				updateBlockAttributes,
			} ),
		};

		const result = await actions.applySuggestion( 'block-1', {
			label: 'Inject CSS',
			attributeUpdates: {
				customCSS: '.wp-block-paragraph { color: red; }',
			},
		} )( {
			dispatch,
			registry,
			select,
		} );

		expect( updateBlockAttributes ).not.toHaveBeenCalled();
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setBlockRequestState(
				'block-1',
				'error',
				'This suggestion includes unsupported or unsafe attribute changes and could not be applied.',
				4
			)
		);
		expect( result ).toBe( false );
	} );

	test( 'applySuggestion rejects advisory block suggestions even when they include safe local updates', async () => {
		const updateBlockAttributes = jest.fn();
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getBlockRecommendations: jest.fn().mockReturnValue( {
				blockContext: { name: 'core/paragraph' },
				prompt: 'Improve the layout.',
			} ),
			getBlockRequestToken: jest.fn().mockReturnValue( 7 ),
		};
		const registry = {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/block-editor'
					? {
							getBlocks: jest.fn().mockReturnValue( [] ),
							getBlockAttributes: jest.fn().mockReturnValue( {
								content: 'Old copy',
							} ),
					  }
					: {}
			),
			dispatch: jest.fn().mockReturnValue( {
				updateBlockAttributes,
			} ),
		};

		const result = await actions.applySuggestion( 'block-1', {
			label: 'Wrap this block in a Group',
			type: 'structural_recommendation',
			attributeUpdates: {
				metadata: {
					blockVisibility: {
						viewport: {
							mobile: false,
						},
					},
				},
			},
		} )( {
			dispatch,
			registry,
			select,
		} );

		expect( updateBlockAttributes ).not.toHaveBeenCalled();
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setBlockRequestState(
				'block-1',
				'error',
				'This suggestion is advisory and requires manual follow-through or a broader preview/apply flow.',
				7
			)
		);
		expect( result ).toBe( false );
	} );

	test( 'applyTemplateSuggestion records success with thunk selector methods', async () => {
		applyTemplateSuggestionOperations.mockReturnValue( {
			ok: true,
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
				},
			],
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getTemplateRequestPrompt: jest
				.fn()
				.mockReturnValue( 'Make the layout more editorial.' ),
			getTemplateResultRef: jest.fn().mockReturnValue( 'theme//home' ),
			getTemplateResultToken: jest.fn().mockReturnValue( 3 ),
		};
		const suggestion = {
			label: 'Clarify template hierarchy',
			suggestionKey: 'Clarify template hierarchy-0',
			requestMeta: {
				backendLabel: 'WordPress AI Client',
				model: 'provider-managed',
				pathLabel: 'WordPress AI Client via Settings > Connectors',
			},
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
				},
			],
		};

		const result = await actions.applyTemplateSuggestion( suggestion )( {
			dispatch,
			registry: null,
			select,
		} );

		expect( select.getTemplateResultRef ).toHaveBeenCalled();
		expect( dispatch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'LOG_ACTIVITY',
				entry: expect.objectContaining( {
					type: 'apply_template_suggestion',
					target: expect.objectContaining( {
						templateRef: 'theme//home',
					} ),
					request: expect.objectContaining( {
						prompt: 'Make the layout more editorial.',
						reference: 'template:theme//home:3',
						ai: expect.objectContaining( {
							backendLabel: 'WordPress AI Client',
							model: 'provider-managed',
						} ),
					} ),
					suggestion: 'Clarify template hierarchy',
					suggestionKey: 'Clarify template hierarchy-0',
				} ),
			} )
		);
		expect( dispatch ).toHaveBeenLastCalledWith(
			actions.setTemplateApplyState(
				'success',
				null,
				'Clarify template hierarchy-0',
				[
					{
						type: 'insert_pattern',
						patternName: 'theme/hero',
					},
				]
			)
		);
		expect( result ).toEqual( {
			ok: true,
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
				},
			],
		} );
	} );

	test( 'applyTemplateSuggestion surfaces executor validation errors without logging activity', async () => {
		applyTemplateSuggestionOperations.mockReturnValue( {
			ok: false,
			error: 'This suggestion targets the “header” area more than once and cannot be applied automatically.',
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getTemplateRequestPrompt: jest.fn().mockReturnValue( '' ),
			getTemplateResultRef: jest.fn().mockReturnValue( 'theme//home' ),
			getTemplateResultToken: jest.fn().mockReturnValue( 3 ),
		};

		const result = await actions.applyTemplateSuggestion( {
			label: 'Conflicting suggestion',
			operations: [],
		} )( {
			dispatch,
			registry: null,
			select,
		} );

		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setTemplateApplyState( 'applying' )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			2,
			actions.setTemplateApplyState(
				'error',
				'This suggestion targets the “header” area more than once and cannot be applied automatically.'
			)
		);
		expect(
			dispatch.mock.calls.some(
				( [ action ] ) => action?.type === 'LOG_ACTIVITY'
			)
		).toBe( false );
		expect( result ).toEqual( {
			ok: false,
			error: 'This suggestion targets the “header” area more than once and cannot be applied automatically.',
		} );
	} );

	test( 'fetchTemplatePartRecommendations reads request token from thunk selectors', async () => {
		apiFetch.mockResolvedValue( {
			suggestions: [ { label: 'Add utility row' } ],
			explanation: 'Mocked template-part response',
		} );

		const dispatch = jest.fn();
		const select = {
			getTemplatePartRequestToken: jest.fn().mockReturnValue( 2 ),
		};
		const input = {
			templatePartRef: 'theme//header',
			prompt: 'Add a compact utility row.',
			visiblePatternNames: [ 'theme/header-utility' ],
		};

		await actions.fetchTemplatePartRecommendations( input )( {
			dispatch,
			select,
		} );

		expect( select.getTemplatePartRequestToken ).toHaveBeenCalled();
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-template-part',
				method: 'POST',
				data: input,
			} )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setTemplatePartStatus( 'loading', null, 3 )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			2,
			actions.setTemplatePartRecommendations(
				'theme//header',
				{
					suggestions: [ { label: 'Add utility row' } ],
					explanation: 'Mocked template-part response',
				},
				'Add a compact utility row.',
				3
			)
		);
	} );

	test( 'applyTemplatePartSuggestion records success with thunk selector methods', async () => {
		applyTemplatePartSuggestionOperations.mockReturnValue( {
			ok: true,
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/header-utility',
					placement: 'start',
				},
			],
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getTemplatePartRequestPrompt: jest
				.fn()
				.mockReturnValue( 'Add a utility row.' ),
			getTemplatePartResultRef: jest
				.fn()
				.mockReturnValue( 'theme//header' ),
			getTemplatePartResultToken: jest.fn().mockReturnValue( 4 ),
		};
		const suggestion = {
			label: 'Add utility row',
			suggestionKey: 'Add utility row-0',
			requestMeta: {
				backendLabel: 'Azure OpenAI responses',
				model: 'gpt-5.3-chat',
			},
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/header-utility',
					placement: 'start',
				},
			],
		};

		const result = await actions.applyTemplatePartSuggestion( suggestion )(
			{
				dispatch,
				registry: null,
				select,
			}
		);

		expect( select.getTemplatePartResultRef ).toHaveBeenCalled();
		expect( dispatch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'LOG_ACTIVITY',
				entry: expect.objectContaining( {
					type: 'apply_template_part_suggestion',
					surface: 'template-part',
					target: expect.objectContaining( {
						templatePartRef: 'theme//header',
					} ),
					request: expect.objectContaining( {
						prompt: 'Add a utility row.',
						reference: 'template-part:theme//header:4',
						ai: expect.objectContaining( {
							backendLabel: 'Azure OpenAI responses',
							model: 'gpt-5.3-chat',
						} ),
					} ),
					suggestion: 'Add utility row',
					suggestionKey: 'Add utility row-0',
				} ),
			} )
		);
		expect( dispatch ).toHaveBeenLastCalledWith(
			actions.setTemplatePartApplyState(
				'success',
				null,
				'Add utility row-0',
				[
					{
						type: 'insert_pattern',
						patternName: 'theme/header-utility',
						placement: 'start',
					},
				]
			)
		);
		expect( result ).toEqual( {
			ok: true,
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/header-utility',
					placement: 'start',
				},
			],
		} );
	} );

	test( 'undoActivity restores the latest block suggestion and marks it undone', async () => {
		const updateBlockAttributes = jest.fn();
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( 'post:42' ),
			getActivityLog: jest.fn().mockReturnValue( [
				{
					id: 'activity-1',
					type: 'apply_suggestion',
					surface: 'block',
					target: {
						clientId: 'block-1',
					},
					before: {
						attributes: {
							content: 'Old copy',
						},
					},
					after: {
						attributes: {
							content: 'New copy',
							className: 'is-style-contrast',
						},
					},
					undo: {
						canUndo: true,
						status: 'available',
					},
				},
			] ),
			getLatestAppliedActivity: jest.fn().mockReturnValue( {
				id: 'activity-1',
				type: 'apply_suggestion',
				surface: 'block',
				target: {
					clientId: 'block-1',
				},
				before: {
					attributes: {
						content: 'Old copy',
					},
				},
				after: {
					attributes: {
						content: 'New copy',
						className: 'is-style-contrast',
					},
				},
				undo: {
					canUndo: true,
					status: 'available',
				},
			} ),
		};
		const registry = {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/block-editor'
					? {
							getBlock: jest.fn().mockReturnValue( {
								clientId: 'block-1',
								name: 'core/paragraph',
								attributes: {
									content: 'New copy',
									className: 'is-style-contrast',
								},
							} ),
							getBlockAttributes: jest.fn().mockReturnValue( {
								content: 'New copy',
								className: 'is-style-contrast',
							} ),
					  }
					: {}
			),
			dispatch: jest.fn().mockReturnValue( {
				updateBlockAttributes,
			} ),
		};

		const result = await actions.undoActivity( 'activity-1' )( {
			dispatch,
			registry,
			select,
		} );

		expect( updateBlockAttributes ).toHaveBeenCalledWith( 'block-1', {
			content: 'Old copy',
			className: undefined,
		} );
		expect( dispatch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'UPDATE_ACTIVITY_UNDO_STATE',
				activityId: 'activity-1',
				status: 'undone',
			} )
		);
		expect( result ).toEqual( { ok: true } );
	} );

	test( 'undoActivity marks applied undos as pending when the audit write fails', async () => {
		apiFetch.mockRejectedValue( new Error( 'Network unavailable.' ) );

		const updateBlockAttributes = jest.fn();
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( 'post:42' ),
			getActivityLog: jest.fn().mockReturnValue( [
				{
					id: 'activity-1',
					type: 'apply_suggestion',
					surface: 'block',
					target: {
						clientId: 'block-1',
					},
					before: {
						attributes: {
							content: 'Old copy',
						},
					},
					after: {
						attributes: {
							content: 'New copy',
						},
					},
					document: {
						scopeKey: 'post:42',
					},
					undo: {
						canUndo: true,
						status: 'available',
					},
					persistence: {
						status: 'server',
						syncType: null,
						updatedAt: '2026-03-24T10:00:00Z',
					},
				},
			] ),
		};
		const registry = {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/block-editor'
					? {
							getBlock: jest.fn().mockReturnValue( {
								clientId: 'block-1',
								name: 'core/paragraph',
								attributes: {
									content: 'New copy',
								},
							} ),
							getBlockAttributes: jest.fn().mockReturnValue( {
								content: 'New copy',
							} ),
					  }
					: {}
			),
			dispatch: jest.fn().mockReturnValue( {
				updateBlockAttributes,
			} ),
		};

		const result = await actions.undoActivity( 'activity-1' )( {
			dispatch,
			registry,
			select,
		} );

		expect( updateBlockAttributes ).toHaveBeenCalledWith( 'block-1', {
			content: 'Old copy',
		} );
		expect( dispatch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'UPDATE_ACTIVITY_UNDO_STATE',
				activityId: 'activity-1',
				status: 'undone',
				persistence: expect.objectContaining( {
					status: 'local',
					syncType: 'undo',
				} ),
			} )
		);
		expect( dispatch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'SET_UNDO_STATE',
				status: 'error',
				activityId: 'activity-1',
				error: expect.stringContaining(
					'will retry on the next activity sync'
				),
			} )
		);
		expect( result ).toEqual(
			expect.objectContaining( {
				ok: false,
				error: expect.stringContaining(
					'will retry on the next activity sync'
				),
			} )
		);
	} );

	test( 'undoActivity delegates template rollback to template-actions helpers', async () => {
		undoTemplateSuggestionOperations.mockReturnValue( {
			ok: true,
			operations: [],
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest
				.fn()
				.mockReturnValue( 'wp_template:home' ),
			getActivityLog: jest.fn().mockReturnValue( [
				{
					id: 'activity-1',
					type: 'apply_template_suggestion',
					surface: 'template',
					target: {
						templateRef: 'theme//home',
					},
					undo: {
						canUndo: true,
						status: 'available',
					},
				},
			] ),
			getLatestAppliedActivity: jest.fn().mockReturnValue( {
				id: 'activity-1',
				type: 'apply_template_suggestion',
				surface: 'template',
				target: {
					templateRef: 'theme//home',
				},
				undo: {
					canUndo: true,
					status: 'available',
				},
			} ),
		};

		await actions.undoActivity( 'activity-1' )( {
			dispatch,
			registry: null,
			select,
		} );

		expect( undoTemplateSuggestionOperations ).toHaveBeenCalledWith(
			expect.objectContaining( {
				id: 'activity-1',
				surface: 'template',
			} )
		);
		expect( dispatch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'SET_UNDO_STATE',
				status: 'success',
				activityId: 'activity-1',
			} )
		);
	} );

	test( 'undoActivity blocks historical undo until newer entity actions are already undone', async () => {
		const dispatch = jest.fn();
		const olderActivity = {
			id: 'activity-older',
			type: 'apply_suggestion',
			surface: 'block',
			timestamp: '2026-03-24T10:00:00Z',
			target: {
				clientId: 'block-1',
				blockPath: [ 0 ],
			},
			document: {
				scopeKey: 'post:42',
			},
			undo: {
				canUndo: true,
				status: 'available',
			},
		};
		const newerActivity = {
			id: 'activity-newer',
			type: 'apply_suggestion',
			surface: 'block',
			timestamp: '2026-03-24T10:00:01Z',
			target: {
				clientId: 'block-1',
				blockPath: [ 0 ],
			},
			document: {
				scopeKey: 'post:42',
			},
			undo: {
				canUndo: true,
				status: 'available',
			},
		};
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( 'post:42' ),
			getActivityLog: jest
				.fn()
				.mockReturnValue( [ olderActivity, newerActivity ] ),
		};

		const result = await actions.undoActivity( 'activity-older' )( {
			dispatch,
			registry: null,
			select,
		} );

		expect( dispatch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'SET_UNDO_STATE',
				status: 'error',
				activityId: 'activity-older',
				error: 'Undo blocked by newer AI actions.',
			} )
		);
		expect( result ).toEqual( {
			ok: false,
			error: 'Undo blocked by newer AI actions.',
		} );
	} );

	test( 'undoActivity allows an older block action once native undo has already reverted the newer AI action', async () => {
		const updateBlockAttributes = jest.fn();
		const dispatch = jest.fn();
		const olderActivity = {
			id: 'activity-older',
			type: 'apply_suggestion',
			surface: 'block',
			timestamp: '2026-03-24T10:00:00Z',
			target: {
				clientId: 'block-1',
				blockName: 'core/paragraph',
				blockPath: [ 0 ],
			},
			document: {
				scopeKey: 'post:42',
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
			undo: {
				canUndo: true,
				status: 'available',
			},
		};
		const newerActivity = {
			id: 'activity-newer',
			type: 'apply_suggestion',
			surface: 'block',
			timestamp: '2026-03-24T10:00:01Z',
			target: {
				clientId: 'block-1',
				blockName: 'core/paragraph',
				blockPath: [ 0 ],
			},
			document: {
				scopeKey: 'post:42',
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
			undo: {
				canUndo: true,
				status: 'available',
			},
		};
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( 'post:42' ),
			getActivityLog: jest
				.fn()
				.mockReturnValue( [ olderActivity, newerActivity ] ),
		};
		const registry = {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/block-editor'
					? {
							getBlock: jest.fn().mockReturnValue( {
								clientId: 'block-1',
								name: 'core/paragraph',
								attributes: {
									content: 'Beta',
								},
							} ),
							getBlockAttributes: jest.fn().mockReturnValue( {
								content: 'Beta',
							} ),
							getBlocks: jest.fn().mockReturnValue( [
								{
									clientId: 'block-1',
									name: 'core/paragraph',
									attributes: {
										content: 'Beta',
									},
									innerBlocks: [],
								},
							] ),
					  }
					: {}
			),
			dispatch: jest.fn().mockReturnValue( {
				updateBlockAttributes,
			} ),
		};

		const result = await actions.undoActivity( 'activity-older' )( {
			dispatch,
			registry,
			select,
		} );

		expect( updateBlockAttributes ).toHaveBeenCalledWith( 'block-1', {
			content: 'Alpha',
		} );
		expect( dispatch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'UPDATE_ACTIVITY_UNDO_STATE',
				activityId: 'activity-older',
				status: 'undone',
			} )
		);
		expect( result ).toEqual( { ok: true } );
	} );

	test( 'undoActivity treats a block action already reverted by native undo as already undone', async () => {
		const updateBlockAttributes = jest.fn();
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( 'post:42' ),
			getActivityLog: jest.fn().mockReturnValue( [
				{
					id: 'activity-1',
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
					undo: {
						canUndo: true,
						status: 'available',
					},
				},
			] ),
		};
		const registry = {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/block-editor'
					? {
							getBlock: jest.fn().mockReturnValue( {
								clientId: 'block-1',
								name: 'core/paragraph',
								attributes: {
									content: 'Before',
								},
							} ),
							getBlockAttributes: jest.fn().mockReturnValue( {
								content: 'Before',
							} ),
							getBlocks: jest.fn().mockReturnValue( [
								{
									clientId: 'block-1',
									name: 'core/paragraph',
									attributes: {
										content: 'Before',
									},
									innerBlocks: [],
								},
							] ),
					  }
					: {}
			),
			dispatch: jest.fn().mockReturnValue( {
				updateBlockAttributes,
			} ),
		};

		const result = await actions.undoActivity( 'activity-1' )( {
			dispatch,
			registry,
			select,
		} );

		expect( updateBlockAttributes ).not.toHaveBeenCalled();
		expect( dispatch ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'SET_UNDO_STATE',
				status: 'error',
			} )
		);
		expect( result ).toEqual( {
			ok: true,
			alreadyUndone: true,
		} );
	} );

	test( 'undoActivity refreshes server-backed activity before allowing a historical undo', async () => {
		const dispatch = jest.fn();
		const olderActivity = {
			id: 'activity-older',
			type: 'apply_suggestion',
			surface: 'block',
			timestamp: '2026-03-24T10:00:00Z',
			target: {
				clientId: 'block-1',
				blockName: 'core/paragraph',
				blockPath: [ 0 ],
			},
			document: {
				scopeKey: 'post:42',
			},
			undo: {
				canUndo: true,
				status: 'available',
			},
			persistence: {
				status: 'server',
			},
		};
		const newerActivity = {
			id: 'activity-newer',
			type: 'apply_suggestion',
			surface: 'block',
			timestamp: '2026-03-24T10:00:01Z',
			target: {
				clientId: 'block-1',
				blockName: 'core/paragraph',
				blockPath: [ 0 ],
			},
			document: {
				scopeKey: 'post:42',
			},
			undo: {
				canUndo: true,
				status: 'available',
			},
			persistence: {
				status: 'server',
			},
		};
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( 'post:42' ),
			getActivityLog: jest.fn().mockReturnValue( [ olderActivity ] ),
		};

		apiFetch.mockResolvedValue( {
			entries: [ olderActivity, newerActivity ],
		} );

		const result = await actions.undoActivity( 'activity-older' )( {
			dispatch,
			registry: null,
			select,
		} );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/activity?scopeKey=post%3A42',
				method: 'GET',
			} )
		);
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setActivitySession( 'post:42', [
				olderActivity,
				newerActivity,
			] )
		);
		expect( result ).toEqual( {
			ok: false,
			error: 'Undo blocked by newer AI actions.',
		} );
	} );

	test( 'undoActivity delegates template-part rollback to template-actions helpers', async () => {
		undoTemplatePartSuggestionOperations.mockReturnValue( {
			ok: true,
			operations: [],
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest
				.fn()
				.mockReturnValue( 'wp_template_part:header' ),
			getActivityLog: jest.fn().mockReturnValue( [
				{
					id: 'activity-1',
					type: 'apply_template_part_suggestion',
					surface: 'template-part',
					target: {
						templatePartRef: 'theme//header',
					},
					undo: {
						canUndo: true,
						status: 'available',
					},
				},
			] ),
			getLatestAppliedActivity: jest.fn().mockReturnValue( {
				id: 'activity-1',
				type: 'apply_template_part_suggestion',
				surface: 'template-part',
				target: {
					templatePartRef: 'theme//header',
				},
				undo: {
					canUndo: true,
					status: 'available',
				},
			} ),
		};

		await actions.undoActivity( 'activity-1' )( {
			dispatch,
			registry: null,
			select,
		} );

		expect( undoTemplatePartSuggestionOperations ).toHaveBeenCalledWith(
			expect.objectContaining( {
				id: 'activity-1',
				surface: 'template-part',
			} )
		);
		expect( dispatch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'SET_UNDO_STATE',
				status: 'success',
				activityId: 'activity-1',
			} )
		);
	} );

	test( 'undoActivity marks template actions failed when dynamic undo resolution rejects them', async () => {
		getTemplateActivityUndoState.mockReturnValue( {
			canUndo: false,
			status: 'failed',
			error: 'Inserted pattern content changed after apply and cannot be undone automatically.',
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest
				.fn()
				.mockReturnValue( 'wp_template:home' ),
			getActivityLog: jest.fn().mockReturnValue( [
				{
					id: 'activity-1',
					type: 'apply_template_suggestion',
					surface: 'template',
					target: {
						templateRef: 'theme//home',
					},
					undo: {
						canUndo: true,
						status: 'available',
					},
				},
			] ),
			getLatestAppliedActivity: jest.fn().mockReturnValue( {
				id: 'activity-1',
				type: 'apply_template_suggestion',
				surface: 'template',
				target: {
					templateRef: 'theme//home',
				},
				undo: {
					canUndo: true,
					status: 'available',
				},
			} ),
		};

		const result = await actions.undoActivity( 'activity-1' )( {
			dispatch,
			registry: null,
			select,
		} );

		expect( undoTemplateSuggestionOperations ).not.toHaveBeenCalled();
		expect( dispatch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'UPDATE_ACTIVITY_UNDO_STATE',
				activityId: 'activity-1',
				status: 'failed',
			} )
		);
		expect( result ).toEqual( {
			ok: false,
			error: 'Inserted pattern content changed after apply and cannot be undone automatically.',
		} );
	} );
} );
