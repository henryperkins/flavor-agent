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
jest.mock( '../../utils/style-operations', () => ( {
	applyGlobalStyleSuggestionOperations: jest.fn(),
	getGlobalStylesActivityUndoState: jest.fn(
		( activity ) => activity?.undo || {}
	),
	undoGlobalStyleSuggestionOperations: jest.fn(),
} ) );

import apiFetch from '@wordpress/api-fetch';

import {
	applyGlobalStyleSuggestionOperations,
	getGlobalStylesActivityUndoState,
} from '../../utils/style-operations';
import {
	applyTemplatePartSuggestionOperations,
	applyTemplateSuggestionOperations,
	getTemplateActivityUndoState,
	getTemplatePartActivityUndoState,
	undoTemplatePartSuggestionOperations,
	undoTemplateSuggestionOperations,
} from '../../utils/template-actions';
import { buildBlockRecommendationContextSignature } from '../../utils/block-recommendation-context';
import {
	buildBlockRecommendationRequestSignature,
	buildGlobalStylesRecommendationRequestSignature,
	buildNavigationRecommendationRequestSignature,
	buildStyleBookRecommendationRequestSignature,
	buildTemplatePartRecommendationRequestSignature,
	buildTemplateRecommendationRequestSignature,
} from '../../utils/recommendation-request-signature';
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
		actions._blockRecommendationAbort = null;
		actions._contentAbort = null;
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
		getGlobalStylesActivityUndoState.mockImplementation(
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

	test( 'fetchBlockRecommendations stores the request signature without posting it to the API', async () => {
		apiFetch.mockResolvedValue( {
			payload: {
				settings: [],
				styles: [],
				block: [ { label: 'Rewrite intro' } ],
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
		const contextSignature =
			buildBlockRecommendationContextSignature( context );

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
		expect( apiFetch ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				data: expect.objectContaining( {
					contextSignature,
				} ),
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
				contextSignature,
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
		apiFetch.mockImplementation( ( { path, method } ) => {
			if (
				path === '/flavor-agent/v1/recommend-navigation' &&
				method === 'POST'
			) {
				return Promise.resolve( {
					suggestions: [ { label: 'Group utility links' } ],
					explanation: 'Mocked navigation response',
					reviewContextSignature: 'review-navigation',
				} );
			}

			if (
				path ===
					'/flavor-agent/v1/activity?scopeKey=wp_template%3Atheme%2F%2Fhome' &&
				method === 'GET'
			) {
				return Promise.resolve( { entries: [] } );
			}

			return Promise.reject(
				new Error( `Unexpected apiFetch: ${ path }` )
			);
		} );

		const dispatch = jest.fn();
		const select = {
			getNavigationRequestToken: jest.fn().mockReturnValue( 1 ),
		};
		const input = {
			blockClientId: 'nav-1',
			contextSignature: 'navigation-signature',
			menuId: 42,
			navigationMarkup:
				'<!-- wp:navigation --><!-- wp:navigation-link {"label":"Home"} /--><!-- /wp:navigation -->',
			prompt: 'Simplify the header menu.',
		};

		await actions.fetchNavigationRecommendations( input )( {
			dispatch,
			registry: {
				select: jest.fn( ( storeName ) =>
					storeName === 'core/editor'
						? {
								getCurrentPostType: () => 'wp_template',
								getCurrentPostId: () => 'theme//home',
						  }
						: {}
				),
			},
			select,
		} );

		expect( select.getNavigationRequestToken ).toHaveBeenCalled();
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-navigation',
				method: 'POST',
				data: {
					document: {
						scopeKey: 'wp_template:theme//home',
						postType: 'wp_template',
						entityId: 'theme//home',
						entityKind: '',
						entityName: '',
						stylesheet: '',
					},
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
					reviewContextSignature: 'review-navigation',
				},
				'Simplify the header menu.',
				2,
				'navigation-signature',
				'review-navigation'
			)
		);
	} );

	test( 'fetchBlockRecommendations stores fallback diagnostics after surfacing request errors', async () => {
		apiFetch.mockRejectedValue( new Error( 'Network blew up.' ) );

		const dispatch = jest.fn();
		const select = {
			getBlockRequestToken: jest.fn().mockReturnValue( 4 ),
		};
		const context = {
			block: {
				name: 'core/paragraph',
				attributes: {
					content: 'Hello world',
				},
			},
		};
		const contextSignature =
			buildBlockRecommendationContextSignature( context );

		await actions.fetchBlockRecommendations(
			'block-1',
			context,
			'Tighten this copy.'
		)( {
			dispatch,
			select,
		} );

		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setBlockRequestState( 'block-1', 'loading', null, 5 )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			2,
			actions.setBlockRequestState(
				'block-1',
				'error',
				'Network blew up.',
				5
			)
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			3,
			expect.objectContaining( {
				type: 'SET_BLOCK_RECS',
				clientId: 'block-1',
				requestToken: 5,
				contextSignature,
				recommendations: expect.objectContaining( {
					blockName: 'core/paragraph',
					blockContext: context.block,
					prompt: 'Tighten this copy.',
					settings: [],
					styles: [],
					block: [],
					explanation: '',
					requestMeta: null,
					timestamp: expect.any( Number ),
				} ),
				diagnostics: expect.objectContaining( {
					type: 'failure',
					errorMessage: 'Network blew up.',
					prompt: 'Tighten this copy.',
					requestToken: 5,
				} ),
			} )
		);
	} );

	test( 'fetchNavigationRecommendations dispatches fallback data on request failures', async () => {
		apiFetch.mockImplementation( ( { path, method } ) => {
			if (
				path === '/flavor-agent/v1/recommend-navigation' &&
				method === 'POST'
			) {
				return Promise.reject( new Error( 'Network blew up.' ) );
			}

			if (
				path ===
					'/flavor-agent/v1/activity?scopeKey=wp_template%3Atheme%2F%2Fhome' &&
				method === 'GET'
			) {
				return Promise.resolve( { entries: [] } );
			}

			return Promise.reject(
				new Error( `Unexpected apiFetch: ${ path }` )
			);
		} );

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
			registry: {
				select: jest.fn( ( storeName ) =>
					storeName === 'core/editor'
						? {
								getCurrentPostType: () => 'wp_template',
								getCurrentPostId: () => 'theme//home',
						  }
						: {}
				),
			},
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
			reviewContextSignature: 'review-template',
			resolvedContextSignature: 'resolved-template',
		} );

		const dispatch = jest.fn();
		const select = {
			getTemplateRequestToken: jest.fn().mockReturnValue( 4 ),
		};
		const input = {
			contextSignature: 'template-signature',
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
				data: {
					templateRef: 'theme//home',
					prompt: 'Tighten the structure.',
					templateType: 'home',
				},
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
					reviewContextSignature: 'review-template',
					resolvedContextSignature: 'resolved-template',
				},
				'Tighten the structure.',
				5,
				'template-signature',
				'review-template',
				'resolved-template'
			)
		);
	} );

	test( 'fetchGlobalStylesRecommendations stores the request context signature without posting it to the API', async () => {
		apiFetch.mockResolvedValue( {
			suggestions: [ { label: 'Use accent canvas' } ],
			explanation: 'Mocked Global Styles response',
			reviewContextSignature: 'review-global-styles',
			resolvedContextSignature: 'resolved-global-styles',
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
					reviewContextSignature: 'review-global-styles',
					resolvedContextSignature: 'resolved-global-styles',
				},
				'Make the site feel more editorial.',
				3,
				input.contextSignature,
				'review-global-styles',
				'resolved-global-styles'
			)
		);
	} );

	test( 'fetchPatternRecommendations aborts the previous request and ignores abort errors', async () => {
		const previousAbort = jest.fn();
		actions._patternAbort = { abort: previousAbort };
		apiFetch.mockImplementation( ( { path, method } ) => {
			if (
				path === '/flavor-agent/v1/recommend-patterns' &&
				method === 'POST'
			) {
				return Promise.reject( { name: 'AbortError' } );
			}

			return Promise.reject(
				new Error( `Unexpected apiFetch: ${ path }` )
			);
		} );

		const dispatch = jest.fn();

		await actions.fetchPatternRecommendations( {
			postType: 'page',
			prompt: 'Find cleaner pattern options.',
		} )( {
			dispatch,
			registry: {
				select: jest.fn( ( storeName ) =>
					storeName === 'core/editor'
						? {
								getCurrentPostType: () => 'post',
								getCurrentPostId: () => 42,
						  }
						: {}
				),
			},
			select: {},
		} );

		expect( previousAbort ).toHaveBeenCalledTimes( 1 );
		expect( dispatch ).toHaveBeenCalledTimes( 1 );
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setPatternStatus( 'loading' )
		);
		expect( actions._patternAbort ).toBeNull();
	} );

	test( 'fetchContentRecommendations sends document scope and refreshes scoped activity', async () => {
		apiFetch.mockImplementation( ( { path, method } ) => {
			if (
				path === '/flavor-agent/v1/recommend-content' &&
				method === 'POST'
			) {
				return Promise.resolve( {
					mode: 'edit',
					title: 'Retail floors to agent workflows',
					summary:
						'Lead with the progression and tighten the opener.',
					content:
						'Retail floors. WordPress themes. Cloud platforms.',
					notes: [ 'Keep the first paragraph shorter.' ],
					issues: [],
				} );
			}

			if (
				path === '/flavor-agent/v1/activity?scopeKey=post%3A42' &&
				method === 'GET'
			) {
				return Promise.resolve( { entries: [] } );
			}

			return Promise.reject(
				new Error( `Unexpected apiFetch: ${ path }` )
			);
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityLog: jest.fn().mockReturnValue( [] ),
			getActivityScopeKey: jest.fn().mockReturnValue( 'post:42' ),
			getContentRequestToken: jest.fn().mockReturnValue( 2 ),
		};

		await actions.fetchContentRecommendations( {
			mode: 'edit',
			prompt: 'Tighten the opener and keep the rhythm brisk.',
			postContext: {
				postType: 'post',
				title: 'Working draft',
				content: 'Retail floors. WordPress themes.',
			},
		} )( {
			dispatch,
			registry: {
				select: jest.fn( ( storeName ) =>
					storeName === 'core/editor'
						? {
								getCurrentPostType: () => 'post',
								getCurrentPostId: () => 42,
						  }
						: {}
				),
			},
			select,
		} );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-content',
				method: 'POST',
				data: {
					mode: 'edit',
					prompt: 'Tighten the opener and keep the rhythm brisk.',
					postContext: {
						postType: 'post',
						title: 'Working draft',
						content: 'Retail floors. WordPress themes.',
					},
					document: {
						scopeKey: 'post:42',
						postType: 'post',
						entityId: '42',
						entityKind: '',
						entityName: '',
						stylesheet: '',
					},
				},
			} )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setContentStatus( 'loading', null, 3 )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			2,
			actions.setContentRecommendation(
				{
					mode: 'edit',
					title: 'Retail floors to agent workflows',
					summary:
						'Lead with the progression and tighten the opener.',
					content:
						'Retail floors. WordPress themes. Cloud platforms.',
					notes: [ 'Keep the first paragraph shorter.' ],
					issues: [],
				},
				'Tighten the opener and keep the rhythm brisk.',
				'edit',
				3
			)
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			3,
			actions.setActivitySession( 'post:42', [] )
		);
	} );

	test( 'fetchStyleBookRecommendations stores block-scoped request metadata without posting the context signature', async () => {
		apiFetch.mockResolvedValue( {
			suggestions: [ { label: 'Tighten paragraph rhythm' } ],
			explanation: 'Mocked Style Book response',
			reviewContextSignature: 'review-style-book',
			resolvedContextSignature: 'resolved-style-book',
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
					reviewContextSignature: 'review-style-book',
					resolvedContextSignature: 'resolved-style-book',
				},
				input.prompt,
				2,
				input.contextSignature,
				'review-style-book',
				'resolved-style-book'
			)
		);
	} );

	test( 'revalidateTemplateReviewFreshness marks stored template reviews stale when the server review signature drifts', async () => {
		apiFetch.mockResolvedValue( {
			reviewContextSignature: 'review-template-next',
		} );

		const dispatch = jest.fn();
		const select = {
			getTemplateReviewRequestToken: jest.fn().mockReturnValue( 2 ),
			getTemplateResultRef: jest.fn().mockReturnValue( 'theme//home' ),
			getTemplateRequestPrompt: jest
				.fn()
				.mockReturnValue( 'Tighten the structure.' ),
			getTemplateContextSignature: jest
				.fn()
				.mockReturnValue( 'template-signature' ),
			getTemplateReviewContextSignature: jest
				.fn()
				.mockReturnValue( 'review-template-stored' ),
		};
		const currentRequestSignature =
			buildTemplateRecommendationRequestSignature( {
				templateRef: 'theme//home',
				prompt: 'Tighten the structure.',
				contextSignature: 'template-signature',
			} );

		const result = await actions.revalidateTemplateReviewFreshness(
			currentRequestSignature,
			{
				templateRef: 'theme//home',
				prompt: 'Tighten the structure.',
				contextSignature: 'template-signature',
			}
		)( {
			dispatch,
			select,
		} );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-template',
				method: 'POST',
				data: {
					templateRef: 'theme//home',
					prompt: 'Tighten the structure.',
					resolveSignatureOnly: true,
				},
			} )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setTemplateReviewFreshnessState( 'checking', 3 )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			2,
			actions.setTemplateReviewFreshnessState(
				'stale',
				3,
				'server-review'
			)
		);
		expect( result ).toEqual( {
			ok: false,
			staleReason: 'server-review',
			surface: 'template',
		} );
	} );

	test( 'revalidateNavigationReviewFreshness marks stored navigation reviews stale when the server review signature drifts', async () => {
		apiFetch.mockResolvedValue( {
			reviewContextSignature: 'review-navigation-next',
		} );

		const dispatch = jest.fn();
		const select = {
			getNavigationReviewRequestToken: jest.fn().mockReturnValue( 2 ),
			getNavigationBlockClientId: jest.fn().mockReturnValue( 'nav-1' ),
			getNavigationRequestPrompt: jest
				.fn()
				.mockReturnValue( 'Simplify the header navigation.' ),
			getNavigationContextSignature: jest
				.fn()
				.mockReturnValue( 'navigation-context-signature' ),
			getNavigationReviewContextSignature: jest
				.fn()
				.mockReturnValue( 'review-navigation-stored' ),
		};
		const currentRequestSignature =
			buildNavigationRecommendationRequestSignature( {
				blockClientId: 'nav-1',
				prompt: 'Simplify the header navigation.',
				contextSignature: 'navigation-context-signature',
			} );

		const result = await actions.revalidateNavigationReviewFreshness(
			currentRequestSignature,
			{
				menuId: 42,
				navigationMarkup:
					'<!-- wp:navigation {"ref":42} /-->',
				prompt: 'Simplify the header navigation.',
				editorContext: {
					block: {
						name: 'core/navigation',
					},
				},
			}
		)( {
			dispatch,
			select,
		} );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-navigation',
				method: 'POST',
				data: {
					menuId: 42,
					navigationMarkup:
						'<!-- wp:navigation {"ref":42} /-->',
					prompt: 'Simplify the header navigation.',
					editorContext: {
						block: {
							name: 'core/navigation',
						},
					},
					resolveSignatureOnly: true,
				},
			} )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setNavigationReviewFreshnessState( 'checking', 3 )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			2,
			actions.setNavigationReviewFreshnessState(
				'stale',
				3,
				'server-review'
			)
		);
		expect( result ).toEqual( {
			ok: false,
			staleReason: 'server-review',
			surface: 'navigation',
		} );
	} );

	test( 'revalidateGlobalStylesReviewFreshness marks stored global styles reviews fresh when the server review signature matches', async () => {
		apiFetch.mockResolvedValue( {
			reviewContextSignature: 'review-global-styles',
		} );

		const dispatch = jest.fn();
		const select = {
			getGlobalStylesReviewRequestToken: jest.fn().mockReturnValue( 4 ),
			getGlobalStylesScopeKey: jest
				.fn()
				.mockReturnValue( 'global_styles:17' ),
			getGlobalStylesResultRef: jest.fn().mockReturnValue( '17' ),
			getGlobalStylesRequestPrompt: jest
				.fn()
				.mockReturnValue( 'Keep the palette restrained.' ),
			getGlobalStylesContextSignature: jest
				.fn()
				.mockReturnValue( 'shared-style-context' ),
			getGlobalStylesReviewContextSignature: jest
				.fn()
				.mockReturnValue( 'review-global-styles' ),
		};
		const currentRequestSignature =
			buildGlobalStylesRecommendationRequestSignature( {
				scope: {
					scopeKey: 'global_styles:17',
					globalStylesId: '17',
				},
				prompt: 'Keep the palette restrained.',
				contextSignature: 'shared-style-context',
			} );

		const result = await actions.revalidateGlobalStylesReviewFreshness(
			currentRequestSignature,
			{
				scope: {
					surface: 'global-styles',
					scopeKey: 'global_styles:17',
					globalStylesId: '17',
				},
				styleContext: {
					currentConfig: { styles: {} },
					mergedConfig: { styles: {} },
				},
				prompt: 'Keep the palette restrained.',
				contextSignature: 'shared-style-context',
			}
		)( {
			dispatch,
			select,
		} );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-style',
				method: 'POST',
				data: {
					scope: {
						surface: 'global-styles',
						scopeKey: 'global_styles:17',
						globalStylesId: '17',
					},
					styleContext: {
						currentConfig: { styles: {} },
						mergedConfig: { styles: {} },
					},
					prompt: 'Keep the palette restrained.',
					resolveSignatureOnly: true,
				},
			} )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setGlobalStylesReviewFreshnessState( 'checking', 5 )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			2,
			actions.setGlobalStylesReviewFreshnessState( 'fresh', 5 )
		);
		expect( result ).toEqual( {
			ok: true,
			reviewContextSignature: 'review-global-styles',
			surface: 'global-styles',
		} );
	} );

	test( 'revalidateTemplatePartReviewFreshness marks stored template-part reviews stale when the server review signature drifts', async () => {
		apiFetch.mockResolvedValue( {
			reviewContextSignature: 'review-template-part-next',
		} );

		const dispatch = jest.fn();
		const select = {
			getTemplatePartReviewRequestToken: jest.fn().mockReturnValue( 1 ),
			getTemplatePartResultRef: jest
				.fn()
				.mockReturnValue( 'theme//header' ),
			getTemplatePartRequestPrompt: jest
				.fn()
				.mockReturnValue( 'Add a compact utility row.' ),
			getTemplatePartContextSignature: jest
				.fn()
				.mockReturnValue( 'template-part-signature' ),
			getTemplatePartReviewContextSignature: jest
				.fn()
				.mockReturnValue( 'review-template-part-stored' ),
		};
		const currentRequestSignature =
			buildTemplatePartRecommendationRequestSignature( {
				templatePartRef: 'theme//header',
				prompt: 'Add a compact utility row.',
				contextSignature: 'template-part-signature',
			} );

		const result = await actions.revalidateTemplatePartReviewFreshness(
			currentRequestSignature,
			{
				templatePartRef: 'theme//header',
				prompt: 'Add a compact utility row.',
				visiblePatternNames: [ 'theme/header-utility' ],
				contextSignature: 'template-part-signature',
			}
		)( {
			dispatch,
			select,
		} );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-template-part',
				method: 'POST',
				data: {
					templatePartRef: 'theme//header',
					prompt: 'Add a compact utility row.',
					visiblePatternNames: [ 'theme/header-utility' ],
					resolveSignatureOnly: true,
				},
			} )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setTemplatePartReviewFreshnessState( 'checking', 2 )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			2,
			actions.setTemplatePartReviewFreshnessState(
				'stale',
				2,
				'server-review'
			)
		);
		expect( result ).toEqual( {
			ok: false,
			staleReason: 'server-review',
			surface: 'template-part',
		} );
	} );

	test( 'revalidateStyleBookReviewFreshness marks stored style book reviews fresh when the server review signature matches', async () => {
		apiFetch.mockResolvedValue( {
			reviewContextSignature: 'review-style-book',
		} );

		const dispatch = jest.fn();
		const select = {
			getStyleBookReviewRequestToken: jest.fn().mockReturnValue( 3 ),
			getStyleBookScopeKey: jest
				.fn()
				.mockReturnValue( 'style_book:17:core/paragraph' ),
			getStyleBookGlobalStylesId: jest.fn().mockReturnValue( '17' ),
			getStyleBookBlockName: jest
				.fn()
				.mockReturnValue( 'core/paragraph' ),
			getStyleBookRequestPrompt: jest
				.fn()
				.mockReturnValue( 'Keep the paragraph understated.' ),
			getStyleBookContextSignature: jest
				.fn()
				.mockReturnValue( 'shared-style-book-context' ),
			getStyleBookReviewContextSignature: jest
				.fn()
				.mockReturnValue( 'review-style-book' ),
		};
		const currentRequestSignature =
			buildStyleBookRecommendationRequestSignature( {
				scope: {
					scopeKey: 'style_book:17:core/paragraph',
					globalStylesId: '17',
					blockName: 'core/paragraph',
				},
				prompt: 'Keep the paragraph understated.',
				contextSignature: 'shared-style-book-context',
			} );

		const result = await actions.revalidateStyleBookReviewFreshness(
			currentRequestSignature,
			{
				scope: {
					surface: 'style-book',
					scopeKey: 'style_book:17:core/paragraph',
					globalStylesId: '17',
					entityId: 'core/paragraph',
					blockName: 'core/paragraph',
				},
				styleContext: {
					currentConfig: { styles: {} },
					mergedConfig: { styles: {} },
					styleBookTarget: {
						blockName: 'core/paragraph',
						blockTitle: 'Paragraph',
						currentStyles: {},
						mergedStyles: {},
					},
				},
				prompt: 'Keep the paragraph understated.',
				contextSignature: 'shared-style-book-context',
			}
		)( {
			dispatch,
			select,
		} );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-style',
				method: 'POST',
				data: {
					scope: {
						surface: 'style-book',
						scopeKey: 'style_book:17:core/paragraph',
						globalStylesId: '17',
						entityId: 'core/paragraph',
						blockName: 'core/paragraph',
					},
					styleContext: {
						currentConfig: { styles: {} },
						mergedConfig: { styles: {} },
						styleBookTarget: {
							blockName: 'core/paragraph',
							blockTitle: 'Paragraph',
							currentStyles: {},
							mergedStyles: {},
						},
					},
					prompt: 'Keep the paragraph understated.',
					resolveSignatureOnly: true,
				},
			} )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setStyleBookReviewFreshnessState( 'checking', 4 )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			2,
			actions.setStyleBookReviewFreshnessState( 'fresh', 4 )
		);
		expect( result ).toEqual( {
			ok: true,
			reviewContextSignature: 'review-style-book',
			surface: 'style-book',
		} );
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

	test( 'loadActivitySession preserves an authoritative terminal undo failure across a successful server refresh', async () => {
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
		const expectedTerminalError = 'Undo blocked by newer AI actions.';
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
			actions.setActivitySession( 'post:42', [
				expect.objectContaining( {
					id: 'activity-1',
					persistence: expect.objectContaining( {
						status: 'server',
						syncType: null,
					} ),
					undo: expect.objectContaining( {
						status: 'failed',
						canUndo: false,
						error: expectedTerminalError,
					} ),
				} ),
			] )
		);
		expect( readPersistedActivityLog( 'post:42' ) ).toEqual( [
			expect.objectContaining( {
				id: 'activity-1',
				persistence: expect.objectContaining( {
					status: 'server',
					syncType: null,
				} ),
				undo: expect.objectContaining( {
					status: 'failed',
					canUndo: false,
					error: expectedTerminalError,
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
		apiFetch.mockResolvedValue( {
			payload: {
				resolvedContextSignature: 'resolved-block',
			},
		} );

		const updateBlockAttributes = jest.fn();
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getBlockResolvedContextSignature: jest
				.fn()
				.mockReturnValue( 'resolved-block' ),
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

		const result = await actions.applySuggestion(
			'block-1',
			{
				label: 'Refresh content',
				attributeUpdates: {
					content: 'New copy',
				},
			},
			null,
			{
				clientId: 'block-1',
				editorContext: {
					block: {
						name: 'core/paragraph',
					},
				},
				prompt: 'Tighten the copy.',
			}
		)( {
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
		apiFetch.mockResolvedValue( {
			payload: {
				resolvedContextSignature: 'resolved-block',
			},
		} );

		const updateBlockAttributes = jest.fn();
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getBlockResolvedContextSignature: jest
				.fn()
				.mockReturnValue( 'resolved-block' ),
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

		const result = await actions.applySuggestion(
			'block-1',
			{
				label: 'Inject CSS',
				attributeUpdates: {
					customCSS: '.wp-block-paragraph { color: red; }',
				},
			},
			null,
			{
				clientId: 'block-1',
				editorContext: {
					block: {
						name: 'core/paragraph',
					},
				},
				prompt: 'Tighten the copy.',
			}
		)( {
			dispatch,
			registry,
			select,
		} );

		expect( updateBlockAttributes ).not.toHaveBeenCalled();
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setBlockApplyState(
				'block-1',
				'error',
				'This suggestion includes unsupported or unsafe attribute changes and could not be applied.'
			)
		);
		expect( result ).toBe( false );
	} );

	test( 'applySuggestion ignores no-op updates without surfacing an error or logging activity', async () => {
		apiFetch.mockResolvedValue( {
			payload: {
				resolvedContextSignature: 'resolved-block',
			},
		} );

		const updateBlockAttributes = jest.fn();
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getBlockResolvedContextSignature: jest
				.fn()
				.mockReturnValue( 'resolved-block' ),
			getBlockRecommendations: jest.fn().mockReturnValue( {
				blockContext: { name: 'core/paragraph' },
				prompt: 'Keep the same content.',
			} ),
			getBlockRequestToken: jest.fn().mockReturnValue( 4 ),
		};
		const registry = {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/block-editor'
					? {
							getBlocks: jest.fn().mockReturnValue( [] ),
							getBlockAttributes: jest.fn().mockReturnValue( {
								content: 'Same copy',
							} ),
					  }
					: {}
			),
			dispatch: jest.fn().mockReturnValue( {
				updateBlockAttributes,
			} ),
		};

		const result = await actions.applySuggestion(
			'block-1',
			{
				label: 'Keep current copy',
				attributeUpdates: {
					content: 'Same copy',
				},
			},
			null,
			{
				clientId: 'block-1',
				editorContext: {
					block: {
						name: 'core/paragraph',
					},
				},
				prompt: 'Keep the same content.',
			}
		)( {
			dispatch,
			registry,
			select,
		} );

		expect( updateBlockAttributes ).not.toHaveBeenCalled();
		expect(
			dispatch.mock.calls.some(
				( [ action ] ) =>
					action?.type === 'LOG_ACTIVITY' ||
					action?.type === 'SET_BLOCK_REQUEST_STATE'
			)
		).toBe( false );
		expect( result ).toBe( false );
	} );

	test( 'applySuggestion rejects advisory block suggestions even when they include safe local updates', async () => {
		apiFetch.mockResolvedValue( {
			payload: {
				resolvedContextSignature: 'resolved-block',
			},
		} );

		const updateBlockAttributes = jest.fn();
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getBlockResolvedContextSignature: jest
				.fn()
				.mockReturnValue( 'resolved-block' ),
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

		const result = await actions.applySuggestion(
			'block-1',
			{
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
			},
			null,
			{
				clientId: 'block-1',
				editorContext: {
					block: {
						name: 'core/paragraph',
					},
				},
				prompt: 'Improve the layout.',
			}
		)( {
			dispatch,
			registry,
			select,
		} );

		expect( updateBlockAttributes ).not.toHaveBeenCalled();
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setBlockApplyState(
				'block-1',
				'error',
				'This suggestion is advisory and requires manual follow-through or a broader preview/apply flow.'
			)
		);
		expect( result ).toBe( false );
	} );

	test( 'applySuggestion blocks stale block results before mutating attributes', async () => {
		const updateBlockAttributes = jest.fn();
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getBlockRecommendationContextSignature: jest
				.fn()
				.mockReturnValue( 'stored-context' ),
			getBlockRecommendations: jest.fn().mockReturnValue( {
				blockContext: { name: 'core/paragraph' },
				prompt: 'Refresh content.',
			} ),
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

		const result = await actions.applySuggestion(
			'block-1',
			{
				label: 'Refresh content',
				attributeUpdates: {
					content: 'New copy',
				},
			},
			'live-context'
		)( {
			dispatch,
			registry,
			select,
		} );

		expect( updateBlockAttributes ).not.toHaveBeenCalled();
		expect( apiFetch ).not.toHaveBeenCalled();
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setBlockApplyState(
				'block-1',
				'error',
				'This result is stale. Refresh recommendations before applying it.',
				null,
				'client'
			)
		);
		expect( result ).toBe( false );
	} );

	test( 'applySuggestion blocks server-stale block results after resolveSignatureOnly drift', async () => {
		apiFetch.mockResolvedValue( {
			payload: {
				resolvedContextSignature: 'resolved-block-next',
			},
		} );

		const updateBlockAttributes = jest.fn();
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getBlockRecommendationContextSignature: jest
				.fn()
				.mockReturnValue( 'shared-context' ),
			getBlockRecommendations: jest.fn().mockReturnValue( {
				blockContext: { name: 'core/paragraph' },
				prompt: 'Refresh content.',
			} ),
			getBlockResolvedContextSignature: jest
				.fn()
				.mockReturnValue( 'resolved-block-stored' ),
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
		const currentRequestSignature =
			buildBlockRecommendationRequestSignature( {
				clientId: 'block-1',
				prompt: 'Refresh content.',
				contextSignature: 'shared-context',
			} );

		const result = await actions.applySuggestion(
			'block-1',
			{
				label: 'Refresh content',
				attributeUpdates: {
					content: 'New copy',
				},
			},
			currentRequestSignature,
			{
				clientId: 'block-1',
				editorContext: {
					block: {
						name: 'core/paragraph',
					},
				},
				prompt: 'Refresh content.',
				contextSignature: 'shared-context',
			}
		)( {
			dispatch,
			registry,
			select,
		} );

		expect( updateBlockAttributes ).not.toHaveBeenCalled();
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-block',
				method: 'POST',
				data: {
					clientId: 'block-1',
					editorContext: {
						block: {
							name: 'core/paragraph',
						},
					},
					prompt: 'Refresh content.',
					resolveSignatureOnly: true,
				},
			} )
		);
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setBlockApplyState(
				'block-1',
				'error',
				'This result no longer matches the current server-resolved apply context. Refresh recommendations before applying it.',
				null,
				'server-apply'
			)
		);
		expect( result ).toBe( false );
	} );

	test( 'applySuggestion blocks prompt-stale block results before mutating attributes', async () => {
		const updateBlockAttributes = jest.fn();
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getBlockRecommendationContextSignature: jest
				.fn()
				.mockReturnValue( 'shared-context' ),
			getBlockRecommendations: jest.fn().mockReturnValue( {
				blockContext: { name: 'core/paragraph' },
				prompt: 'Keep the copy concise.',
			} ),
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
		const currentRequestSignature =
			buildBlockRecommendationRequestSignature( {
				clientId: 'block-1',
				prompt: 'Make the copy more conversational.',
				contextSignature: 'shared-context',
			} );

		const result = await actions.applySuggestion(
			'block-1',
			{
				label: 'Refresh content',
				attributeUpdates: {
					content: 'New copy',
				},
			},
			currentRequestSignature
		)( {
			dispatch,
			registry,
			select,
		} );

		expect( updateBlockAttributes ).not.toHaveBeenCalled();
		expect( apiFetch ).not.toHaveBeenCalled();
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setBlockApplyState(
				'block-1',
				'error',
				'This result is stale. Refresh recommendations before applying it.',
				null,
				'client'
			)
		);
		expect( result ).toBe( false );
	} );

	test( 'applyGlobalStylesSuggestion converts thrown executor exceptions into apply errors', async () => {
		applyGlobalStyleSuggestionOperations.mockImplementation( () => {
			throw new Error( 'Global Styles executor crashed.' );
		} );
		apiFetch.mockResolvedValue( {
			resolvedContextSignature: 'resolved-global-styles',
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getActivityLog: jest.fn().mockReturnValue( [] ),
			getGlobalStylesResolvedContextSignature: jest
				.fn()
				.mockReturnValue( 'resolved-global-styles' ),
		};

		const result = await actions.applyGlobalStylesSuggestion(
			{
				label: 'Use accent canvas',
			},
			null,
			{
				scope: {
					scopeKey: 'global_styles:17',
					globalStylesId: '17',
					entityId: '17',
				},
				styleContext: {
					currentConfig: { styles: {} },
					mergedConfig: { styles: {} },
				},
			}
		)( {
			dispatch,
			registry: null,
			select,
		} );

		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setGlobalStylesApplyState( 'applying' )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			2,
			actions.setGlobalStylesApplyState(
				'error',
				'Global Styles executor crashed.'
			)
		);
		expect(
			dispatch.mock.calls.some(
				( [ action ] ) => action?.type === 'LOG_ACTIVITY'
			)
		).toBe( false );
		expect( result ).toEqual( {
			ok: false,
			error: 'Global Styles executor crashed.',
		} );
	} );

	test( 'applyStyleBookSuggestion converts thrown executor exceptions into apply errors', async () => {
		applyGlobalStyleSuggestionOperations.mockImplementation( () => {
			throw new Error( 'Style Book executor crashed.' );
		} );
		apiFetch.mockResolvedValue( {
			resolvedContextSignature: 'resolved-style-book',
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getActivityLog: jest.fn().mockReturnValue( [] ),
			getStyleBookResolvedContextSignature: jest
				.fn()
				.mockReturnValue( 'resolved-style-book' ),
		};

		const result = await actions.applyStyleBookSuggestion(
			{
				label: 'Refine paragraph rhythm',
			},
			null,
			{
				scope: {
					scopeKey: 'style_book:17:core/paragraph',
					globalStylesId: '17',
					entityId: 'core/paragraph',
					blockName: 'core/paragraph',
				},
				styleContext: {
					currentConfig: { styles: {} },
					mergedConfig: { styles: {} },
					styleBookTarget: {
						blockName: 'core/paragraph',
						blockTitle: 'Paragraph',
						currentStyles: {},
						mergedStyles: {},
					},
				},
			}
		)( {
			dispatch,
			registry: null,
			select,
		} );

		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setStyleBookApplyState( 'applying' )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			2,
			actions.setStyleBookApplyState(
				'error',
				'Style Book executor crashed.'
			)
		);
		expect(
			dispatch.mock.calls.some(
				( [ action ] ) => action?.type === 'LOG_ACTIVITY'
			)
		).toBe( false );
		expect( result ).toEqual( {
			ok: false,
			error: 'Style Book executor crashed.',
		} );
	} );

	test( 'applyGlobalStylesSuggestion rejects prompt-stale results before running the executor', async () => {
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getGlobalStylesScopeKey: jest
				.fn()
				.mockReturnValue( 'global_styles:17' ),
			getGlobalStylesResultRef: jest.fn().mockReturnValue( '17' ),
			getGlobalStylesContextSignature: jest
				.fn()
				.mockReturnValue( 'shared-style-context' ),
			getGlobalStylesRequestPrompt: jest
				.fn()
				.mockReturnValue( 'Keep the palette restrained.' ),
		};
		const currentRequestSignature =
			buildGlobalStylesRecommendationRequestSignature( {
				scope: {
					scopeKey: 'global_styles:17',
					globalStylesId: '17',
				},
				prompt: 'Push the palette further.',
				contextSignature: 'shared-style-context',
			} );

		const result = await actions.applyGlobalStylesSuggestion(
			{
				label: 'Use accent canvas',
			},
			currentRequestSignature
		)( {
			dispatch,
			registry: null,
			select,
		} );

		expect( applyGlobalStyleSuggestionOperations ).not.toHaveBeenCalled();
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setGlobalStylesApplyState(
				'error',
				'This Global Styles result is stale. Refresh recommendations before applying it.',
				null,
				[],
				'client'
			)
		);
		expect( result ).toEqual( {
			ok: false,
			error: 'This Global Styles result is stale. Refresh recommendations before applying it.',
			staleReason: 'client',
		} );
	} );

	test( 'applyGlobalStylesSuggestion blocks server-stale results after resolveSignatureOnly drift', async () => {
		apiFetch.mockResolvedValue( {
			resolvedContextSignature: 'resolved-global-styles-next',
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getGlobalStylesScopeKey: jest
				.fn()
				.mockReturnValue( 'global_styles:17' ),
			getGlobalStylesResultRef: jest.fn().mockReturnValue( '17' ),
			getGlobalStylesContextSignature: jest
				.fn()
				.mockReturnValue( 'shared-style-context' ),
			getGlobalStylesRequestPrompt: jest
				.fn()
				.mockReturnValue( 'Keep the palette restrained.' ),
			getGlobalStylesResolvedContextSignature: jest
				.fn()
				.mockReturnValue( 'resolved-global-styles-stored' ),
		};
		const currentRequestSignature =
			buildGlobalStylesRecommendationRequestSignature( {
				scope: {
					scopeKey: 'global_styles:17',
					globalStylesId: '17',
				},
				prompt: 'Keep the palette restrained.',
				contextSignature: 'shared-style-context',
			} );

		const result = await actions.applyGlobalStylesSuggestion(
			{
				label: 'Use accent canvas',
			},
			currentRequestSignature,
			{
				scope: {
					surface: 'global-styles',
					scopeKey: 'global_styles:17',
					globalStylesId: '17',
					entityId: '17',
				},
				styleContext: {
					currentConfig: { styles: {} },
					mergedConfig: { styles: {} },
				},
				prompt: 'Keep the palette restrained.',
				contextSignature: 'shared-style-context',
			}
		)( {
			dispatch,
			registry: null,
			select,
		} );

		expect( applyGlobalStyleSuggestionOperations ).not.toHaveBeenCalled();
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-style',
				method: 'POST',
				data: {
					scope: {
						surface: 'global-styles',
						scopeKey: 'global_styles:17',
						globalStylesId: '17',
						entityId: '17',
					},
					styleContext: {
						currentConfig: { styles: {} },
						mergedConfig: { styles: {} },
					},
					prompt: 'Keep the palette restrained.',
					resolveSignatureOnly: true,
				},
			} )
		);
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setGlobalStylesApplyState(
				'error',
				'This Global Styles result no longer matches the current server-resolved apply context. Refresh recommendations before applying it.',
				null,
				[],
				'server-apply'
			)
		);
		expect( result ).toEqual( {
			ok: false,
			error: 'This Global Styles result no longer matches the current server-resolved apply context. Refresh recommendations before applying it.',
			staleReason: 'server-apply',
		} );
	} );

	test( 'applyStyleBookSuggestion rejects prompt-stale results before running the executor', async () => {
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getStyleBookScopeKey: jest
				.fn()
				.mockReturnValue( 'style_book:17:core/paragraph' ),
			getStyleBookGlobalStylesId: jest.fn().mockReturnValue( '17' ),
			getStyleBookBlockName: jest
				.fn()
				.mockReturnValue( 'core/paragraph' ),
			getStyleBookContextSignature: jest
				.fn()
				.mockReturnValue( 'shared-style-book-context' ),
			getStyleBookRequestPrompt: jest
				.fn()
				.mockReturnValue( 'Keep the paragraph understated.' ),
		};
		const currentRequestSignature =
			buildStyleBookRecommendationRequestSignature( {
				scope: {
					scopeKey: 'style_book:17:core/paragraph',
					globalStylesId: '17',
					blockName: 'core/paragraph',
				},
				prompt: 'Make the paragraph feel more editorial.',
				contextSignature: 'shared-style-book-context',
			} );

		const result = await actions.applyStyleBookSuggestion(
			{
				label: 'Refine paragraph rhythm',
			},
			currentRequestSignature
		)( {
			dispatch,
			registry: null,
			select,
		} );

		expect( applyGlobalStyleSuggestionOperations ).not.toHaveBeenCalled();
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setStyleBookApplyState(
				'error',
				'This Style Book result is stale. Refresh recommendations before applying it.',
				null,
				[],
				'client'
			)
		);
		expect( result ).toEqual( {
			ok: false,
			error: 'This Style Book result is stale. Refresh recommendations before applying it.',
			staleReason: 'client',
		} );
	} );

	test( 'applyStyleBookSuggestion blocks server-stale results after resolveSignatureOnly drift', async () => {
		apiFetch.mockResolvedValue( {
			resolvedContextSignature: 'resolved-style-book-next',
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getStyleBookScopeKey: jest
				.fn()
				.mockReturnValue( 'style_book:17:core/paragraph' ),
			getStyleBookGlobalStylesId: jest.fn().mockReturnValue( '17' ),
			getStyleBookBlockName: jest
				.fn()
				.mockReturnValue( 'core/paragraph' ),
			getStyleBookContextSignature: jest
				.fn()
				.mockReturnValue( 'shared-style-book-context' ),
			getStyleBookRequestPrompt: jest
				.fn()
				.mockReturnValue( 'Keep the paragraph understated.' ),
			getStyleBookResolvedContextSignature: jest
				.fn()
				.mockReturnValue( 'resolved-style-book-stored' ),
		};
		const currentRequestSignature =
			buildStyleBookRecommendationRequestSignature( {
				scope: {
					scopeKey: 'style_book:17:core/paragraph',
					globalStylesId: '17',
					blockName: 'core/paragraph',
				},
				prompt: 'Keep the paragraph understated.',
				contextSignature: 'shared-style-book-context',
			} );

		const result = await actions.applyStyleBookSuggestion(
			{
				label: 'Refine paragraph rhythm',
			},
			currentRequestSignature,
			{
				scope: {
					surface: 'style-book',
					scopeKey: 'style_book:17:core/paragraph',
					globalStylesId: '17',
					entityId: 'core/paragraph',
					blockName: 'core/paragraph',
				},
				styleContext: {
					currentConfig: { styles: {} },
					mergedConfig: { styles: {} },
					styleBookTarget: {
						blockName: 'core/paragraph',
						blockTitle: 'Paragraph',
						currentStyles: {},
						mergedStyles: {},
					},
				},
				prompt: 'Keep the paragraph understated.',
				contextSignature: 'shared-style-book-context',
			}
		)( {
			dispatch,
			registry: null,
			select,
		} );

		expect( applyGlobalStyleSuggestionOperations ).not.toHaveBeenCalled();
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-style',
				method: 'POST',
				data: {
					scope: {
						surface: 'style-book',
						scopeKey: 'style_book:17:core/paragraph',
						globalStylesId: '17',
						entityId: 'core/paragraph',
						blockName: 'core/paragraph',
					},
					styleContext: {
						currentConfig: { styles: {} },
						mergedConfig: { styles: {} },
						styleBookTarget: {
							blockName: 'core/paragraph',
							blockTitle: 'Paragraph',
							currentStyles: {},
							mergedStyles: {},
						},
					},
					prompt: 'Keep the paragraph understated.',
					resolveSignatureOnly: true,
				},
			} )
		);
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setStyleBookApplyState(
				'error',
				'This Style Book result no longer matches the current server-resolved apply context. Refresh recommendations before applying it.',
				null,
				[],
				'server-apply'
			)
		);
		expect( result ).toEqual( {
			ok: false,
			error: 'This Style Book result no longer matches the current server-resolved apply context. Refresh recommendations before applying it.',
			staleReason: 'server-apply',
		} );
	} );

	test( 'applyTemplateSuggestion rejects stale results before running the executor', async () => {
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getTemplateContextSignature: jest
				.fn()
				.mockReturnValue( 'stored-template-signature' ),
		};

		const result = await actions.applyTemplateSuggestion(
			{
				label: 'Clarify template hierarchy',
				suggestionKey: 'Clarify template hierarchy-0',
				operations: [
					{
						type: 'insert_pattern',
						patternName: 'theme/hero',
					},
				],
			},
			'live-template-signature'
		)( {
			dispatch,
			registry: null,
			select,
		} );

		expect( applyTemplateSuggestionOperations ).not.toHaveBeenCalled();
		expect( dispatch ).toHaveBeenCalledTimes( 1 );
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setTemplateApplyState(
				'error',
				'This template result is stale. Refresh recommendations before applying it.',
				null,
				[],
				'client'
			)
		);
		expect(
			dispatch.mock.calls.some(
				( [ action ] ) => action?.type === 'LOG_ACTIVITY'
			)
		).toBe( false );
		expect( result ).toEqual( {
			ok: false,
			error: 'This template result is stale. Refresh recommendations before applying it.',
			staleReason: 'client',
		} );
	} );

	test( 'applyTemplateSuggestion rejects prompt-stale results before running the executor', async () => {
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getTemplateContextSignature: jest
				.fn()
				.mockReturnValue( 'shared-template-context' ),
			getTemplateRequestPrompt: jest
				.fn()
				.mockReturnValue( 'Clarify the structure.' ),
			getTemplateResultRef: jest.fn().mockReturnValue( 'theme//home' ),
		};
		const currentRequestSignature =
			buildTemplateRecommendationRequestSignature( {
				templateRef: 'theme//home',
				prompt: 'Make the template feel more editorial.',
				contextSignature: 'shared-template-context',
			} );

		const result = await actions.applyTemplateSuggestion(
			{
				label: 'Clarify template hierarchy',
				suggestionKey: 'Clarify template hierarchy-0',
				operations: [
					{
						type: 'insert_pattern',
						patternName: 'theme/hero',
					},
				],
			},
			currentRequestSignature
		)( {
			dispatch,
			registry: null,
			select,
		} );

		expect( applyTemplateSuggestionOperations ).not.toHaveBeenCalled();
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setTemplateApplyState(
				'error',
				'This template result is stale. Refresh recommendations before applying it.',
				null,
				[],
				'client'
			)
		);
		expect( result ).toEqual( {
			ok: false,
			error: 'This template result is stale. Refresh recommendations before applying it.',
			staleReason: 'client',
		} );
	} );

	test( 'applyTemplateSuggestion blocks server-stale results after resolveSignatureOnly drift', async () => {
		apiFetch.mockResolvedValue( {
			resolvedContextSignature: 'resolved-template-next',
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getTemplateContextSignature: jest
				.fn()
				.mockReturnValue( 'shared-template-context' ),
			getTemplateRequestPrompt: jest
				.fn()
				.mockReturnValue( 'Clarify the structure.' ),
			getTemplateResultRef: jest.fn().mockReturnValue( 'theme//home' ),
			getTemplateResolvedContextSignature: jest
				.fn()
				.mockReturnValue( 'resolved-template-stored' ),
		};
		const currentRequestSignature =
			buildTemplateRecommendationRequestSignature( {
				templateRef: 'theme//home',
				prompt: 'Clarify the structure.',
				contextSignature: 'shared-template-context',
			} );

		const result = await actions.applyTemplateSuggestion(
			{
				label: 'Clarify template hierarchy',
				suggestionKey: 'Clarify template hierarchy-0',
				operations: [
					{
						type: 'insert_pattern',
						patternName: 'theme/hero',
					},
				],
			},
			currentRequestSignature,
			{
				templateRef: 'theme//home',
				prompt: 'Clarify the structure.',
			}
		)( {
			dispatch,
			registry: null,
			select,
		} );

		expect( applyTemplateSuggestionOperations ).not.toHaveBeenCalled();
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-template',
				method: 'POST',
				data: {
					templateRef: 'theme//home',
					prompt: 'Clarify the structure.',
					resolveSignatureOnly: true,
				},
			} )
		);
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setTemplateApplyState(
				'error',
				'This template result no longer matches the current server-resolved apply context. Refresh recommendations before applying it.',
				null,
				[],
				'server-apply'
			)
		);
		expect( result ).toEqual( {
			ok: false,
			error: 'This template result no longer matches the current server-resolved apply context. Refresh recommendations before applying it.',
			staleReason: 'server-apply',
		} );
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
		apiFetch.mockResolvedValue( {
			resolvedContextSignature: 'resolved-template',
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getTemplateRequestPrompt: jest
				.fn()
				.mockReturnValue( 'Make the layout more editorial.' ),
			getTemplateResultRef: jest.fn().mockReturnValue( 'theme//home' ),
			getTemplateResultToken: jest.fn().mockReturnValue( 3 ),
			getTemplateResolvedContextSignature: jest
				.fn()
				.mockReturnValue( 'resolved-template' ),
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

		const result = await actions.applyTemplateSuggestion(
			suggestion,
			null,
			{
				templateRef: 'theme//home',
				prompt: 'Make the layout more editorial.',
			}
		)( {
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
		apiFetch.mockResolvedValue( {
			resolvedContextSignature: 'resolved-template',
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getTemplateRequestPrompt: jest.fn().mockReturnValue( '' ),
			getTemplateResultRef: jest.fn().mockReturnValue( 'theme//home' ),
			getTemplateResultToken: jest.fn().mockReturnValue( 3 ),
			getTemplateResolvedContextSignature: jest
				.fn()
				.mockReturnValue( 'resolved-template' ),
		};

		const result = await actions.applyTemplateSuggestion(
			{
				label: 'Conflicting suggestion',
				operations: [],
			},
			null,
			{
				templateRef: 'theme//home',
				prompt: '',
			}
		)( {
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
			reviewContextSignature: 'review-template-part',
			resolvedContextSignature: 'resolved-template-part',
		} );

		const dispatch = jest.fn();
		const select = {
			getTemplatePartRequestToken: jest.fn().mockReturnValue( 2 ),
		};
		const input = {
			contextSignature: 'template-part-signature',
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
				data: {
					templatePartRef: 'theme//header',
					prompt: 'Add a compact utility row.',
					visiblePatternNames: [ 'theme/header-utility' ],
				},
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
					reviewContextSignature: 'review-template-part',
					resolvedContextSignature: 'resolved-template-part',
				},
				'Add a compact utility row.',
				3,
				'template-part-signature',
				'review-template-part',
				'resolved-template-part'
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
		apiFetch.mockResolvedValue( {
			resolvedContextSignature: 'resolved-template-part',
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
			getTemplatePartResolvedContextSignature: jest
				.fn()
				.mockReturnValue( 'resolved-template-part' ),
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

		const result = await actions.applyTemplatePartSuggestion(
			suggestion,
			null,
			{
				templatePartRef: 'theme//header',
				prompt: 'Add a utility row.',
			}
		)( {
			dispatch,
			registry: null,
			select,
		} );

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

	test( 'applyTemplatePartSuggestion rejects prompt-stale results before running the executor', async () => {
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getTemplatePartContextSignature: jest
				.fn()
				.mockReturnValue( 'shared-template-part-context' ),
			getTemplatePartRequestPrompt: jest
				.fn()
				.mockReturnValue( 'Keep the header compact.' ),
			getTemplatePartResultRef: jest
				.fn()
				.mockReturnValue( 'theme//header' ),
		};
		const currentRequestSignature =
			buildTemplatePartRecommendationRequestSignature( {
				templatePartRef: 'theme//header',
				prompt: 'Add more breathing room to the header.',
				contextSignature: 'shared-template-part-context',
			} );

		const result = await actions.applyTemplatePartSuggestion(
			{
				label: 'Add utility row',
				suggestionKey: 'Add utility row-0',
				operations: [
					{
						type: 'insert_pattern',
						patternName: 'theme/header-utility',
						placement: 'start',
					},
				],
			},
			currentRequestSignature
		)( {
			dispatch,
			registry: null,
			select,
		} );

		expect( applyTemplatePartSuggestionOperations ).not.toHaveBeenCalled();
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setTemplatePartApplyState(
				'error',
				'This template-part result is stale. Refresh recommendations before applying it.',
				null,
				[],
				'client'
			)
		);
		expect( result ).toEqual( {
			ok: false,
			error: 'This template-part result is stale. Refresh recommendations before applying it.',
			staleReason: 'client',
		} );
	} );

	test( 'applyTemplatePartSuggestion blocks server-stale results after resolveSignatureOnly drift', async () => {
		apiFetch.mockResolvedValue( {
			resolvedContextSignature: 'resolved-template-part-next',
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getTemplatePartContextSignature: jest
				.fn()
				.mockReturnValue( 'shared-template-part-context' ),
			getTemplatePartRequestPrompt: jest
				.fn()
				.mockReturnValue( 'Keep the header compact.' ),
			getTemplatePartResultRef: jest
				.fn()
				.mockReturnValue( 'theme//header' ),
			getTemplatePartResolvedContextSignature: jest
				.fn()
				.mockReturnValue( 'resolved-template-part-stored' ),
		};
		const currentRequestSignature =
			buildTemplatePartRecommendationRequestSignature( {
				templatePartRef: 'theme//header',
				prompt: 'Keep the header compact.',
				contextSignature: 'shared-template-part-context',
			} );

		const result = await actions.applyTemplatePartSuggestion(
			{
				label: 'Add utility row',
				suggestionKey: 'Add utility row-0',
				operations: [
					{
						type: 'insert_pattern',
						patternName: 'theme/header-utility',
						placement: 'start',
					},
				],
			},
			currentRequestSignature,
			{
				templatePartRef: 'theme//header',
				prompt: 'Keep the header compact.',
				visiblePatternNames: [ 'theme/header-utility' ],
				contextSignature: 'shared-template-part-context',
			}
		)( {
			dispatch,
			registry: null,
			select,
		} );

		expect( applyTemplatePartSuggestionOperations ).not.toHaveBeenCalled();
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-template-part',
				method: 'POST',
				data: {
					templatePartRef: 'theme//header',
					prompt: 'Keep the header compact.',
					visiblePatternNames: [ 'theme/header-utility' ],
					resolveSignatureOnly: true,
				},
			} )
		);
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setTemplatePartApplyState(
				'error',
				'This template-part result no longer matches the current server-resolved apply context. Refresh recommendations before applying it.',
				null,
				[],
				'server-apply'
			)
		);
		expect( result ).toEqual( {
			ok: false,
			error: 'This template-part result no longer matches the current server-resolved apply context. Refresh recommendations before applying it.',
			staleReason: 'server-apply',
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

	test( 'undoActivity reconciles terminal 409 undo conflicts against server state instead of marking the action failed locally', async () => {
		const localActivity = {
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
			},
		};
		const reconciledServerEntry = {
			...localActivity,
			undo: {
				canUndo: false,
				status: 'undone',
				updatedAt: '2026-03-24T10:00:02Z',
				undoneAt: '2026-03-24T10:00:02Z',
			},
		};

		apiFetch
			.mockResolvedValueOnce( {
				entries: [ localActivity ],
			} )
			.mockRejectedValueOnce( {
				code: 'flavor_agent_activity_invalid_undo_transition',
				message:
					'Flavor Agent only allows undo status changes from the available state.',
				data: {
					status: 409,
					code: 'flavor_agent_activity_invalid_undo_transition',
				},
			} )
			.mockResolvedValueOnce( {
				entries: [ reconciledServerEntry ],
			} );

		const updateBlockAttributes = jest.fn();
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( 'post:42' ),
			getActivityLog: jest
				.fn()
				.mockReturnValueOnce( [ localActivity ] )
				.mockReturnValue( [ reconciledServerEntry ] ),
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

		expect( result ).toEqual(
			expect.objectContaining( {
				ok: true,
			} )
		);
		expect( dispatch ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'SET_UNDO_STATE',
				status: 'error',
				activityId: 'activity-1',
			} )
		);
		expect( dispatch ).toHaveBeenCalledWith(
			actions.setActivitySession( 'post:42', [
				expect.objectContaining( {
					id: 'activity-1',
					undo: expect.objectContaining( {
						status: 'undone',
					} ),
				} ),
			] )
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

	test( 'loadActivitySession reconciles pending undo sync 409 conflicts against the server entry', async () => {
		const pendingUndoEntry = {
			id: 'activity-1',
			type: 'apply_suggestion',
			surface: 'block',
			target: {
				clientId: 'block-1',
			},
			document: {
				scopeKey: 'post:42',
			},
			undo: {
				canUndo: false,
				status: 'undone',
				updatedAt: '2026-03-24T10:00:01Z',
				undoneAt: '2026-03-24T10:00:01Z',
			},
			persistence: {
				status: 'local',
				syncType: 'undo',
				updatedAt: '2026-03-24T10:00:01Z',
			},
		};
		const persistedUndoEntry = {
			...pendingUndoEntry,
			persistence: {
				status: 'server',
				syncType: null,
				updatedAt: '2026-03-24T10:00:02Z',
			},
		};

		apiFetch
			.mockRejectedValueOnce( {
				code: 'flavor_agent_activity_invalid_undo_transition',
				message:
					'Flavor Agent only allows undo status changes from the available state.',
				data: {
					status: 409,
					code: 'flavor_agent_activity_invalid_undo_transition',
				},
			} )
			.mockResolvedValueOnce( {
				entries: [ persistedUndoEntry ],
			} )
			.mockResolvedValueOnce( {
				entries: [ persistedUndoEntry ],
			} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( 'post:42' ),
			getActivityLog: jest.fn().mockReturnValue( [ pendingUndoEntry ] ),
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

		await actions.loadActivitySession()( {
			dispatch,
			registry,
			select,
		} );

		expect( dispatch ).toHaveBeenCalledWith(
			actions.setActivitySession( 'post:42', [
				expect.objectContaining( {
					id: 'activity-1',
					undo: expect.objectContaining( {
						status: 'undone',
					} ),
					persistence: expect.objectContaining( {
						status: 'server',
					} ),
				} ),
			] )
		);
	} );

	test( 'loadActivitySession falls back to failedEntries when conflict reconciliation cannot reach the server', async () => {
		const pendingUndoEntry = {
			id: 'activity-1',
			type: 'apply_suggestion',
			surface: 'block',
			target: {
				clientId: 'block-1',
			},
			document: {
				scopeKey: 'post:42',
			},
			undo: {
				canUndo: false,
				status: 'undone',
				updatedAt: '2026-03-24T10:00:01Z',
				undoneAt: '2026-03-24T10:00:01Z',
			},
			persistence: {
				status: 'local',
				syncType: 'undo',
				updatedAt: '2026-03-24T10:00:01Z',
			},
		};
		const staleServerEntry = {
			...pendingUndoEntry,
			undo: {
				canUndo: true,
				status: 'available',
				updatedAt: '2026-03-24T10:00:00Z',
				undoneAt: null,
			},
			persistence: {
				status: 'server',
				syncType: null,
				updatedAt: '2026-03-24T10:00:00Z',
			},
		};

		apiFetch
			.mockRejectedValueOnce( {
				code: 'flavor_agent_activity_invalid_undo_transition',
				message:
					'Flavor Agent only allows undo status changes from the available state.',
				data: {
					status: 409,
					code: 'flavor_agent_activity_invalid_undo_transition',
				},
			} )
			.mockRejectedValueOnce(
				new Error( 'Conflict reconciliation fetch failed.' )
			)
			.mockResolvedValueOnce( {
				entries: [ staleServerEntry ],
			} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( 'post:42' ),
			getActivityLog: jest.fn().mockReturnValue( [ pendingUndoEntry ] ),
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

		await actions.loadActivitySession()( {
			dispatch,
			registry,
			select,
		} );

		expect( dispatch ).toHaveBeenCalledWith(
			actions.setActivitySession( 'post:42', [
				expect.objectContaining( {
					id: 'activity-1',
					persistence: expect.objectContaining( {
						status: 'local',
						syncType: 'undo',
					} ),
					undo: expect.objectContaining( {
						status: 'undone',
					} ),
				} ),
			] )
		);
	} );
} );
