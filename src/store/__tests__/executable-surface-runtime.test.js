import { executeFlavorAgentAbility } from '../abilities-client';
import {
	buildExecutableSurfaceApplyThunk,
	buildExecutableSurfaceReviewFreshnessThunk,
} from '../executable-surface-runtime';
import { getClientRequestSessionId } from '../client-request-identity';

jest.mock( '../abilities-client', () => ( {
	executeFlavorAgentAbility: jest.fn(),
} ) );

describe( 'executable-surface runtime review freshness thunk', () => {
	beforeEach( () => {
		executeFlavorAgentAbility.mockReset();
	} );

	function buildReviewThunk( result ) {
		executeFlavorAgentAbility.mockResolvedValue( result );

		const setReviewState = jest.fn( ( status, payload ) => ( {
			type: 'REVIEW_STATE',
			status,
			payload,
		} ) );
		const thunk = buildExecutableSurfaceReviewFreshnessThunk(
			{
				abilityName: 'test/review',
				getReviewRequestToken: jest.fn( () => 2 ),
				getStoredRequestSignature: jest.fn( () => 'request' ),
				getStoredReviewContextSignature: jest.fn(
					() => 'review-stored'
				),
				setReviewState,
				surface: 'template',
			},
			'request',
			{
				templateRef: 'theme//home',
				prompt: 'Refine the template.',
			},
			{
				getReviewContextSignatureFromResponse: ( response ) =>
					response?.reviewContextSignature || '',
			}
		);

		return { setReviewState, thunk };
	}

	test( 'keeps matching reviews fresh during a docs grounding outage and attaches the soft warning', async () => {
		const { setReviewState, thunk } = buildReviewThunk( {
			reviewContextSignature: 'review-stored',
			docsGrounding: {
				available: false,
				sourceTypes: [],
				count: 0,
				reason: 'signature_cache_miss',
			},
		} );
		const dispatch = jest.fn();

		const result = await thunk( {
			dispatch,
			select: {},
		} );

		expect( dispatch ).toHaveBeenNthCalledWith( 1, {
			type: 'REVIEW_STATE',
			status: 'checking',
			payload: { requestToken: 3 },
		} );
		expect( executeFlavorAgentAbility ).toHaveBeenCalledWith(
			'test/review',
			expect.objectContaining( {
				resolveSignatureOnly: true,
				clientRequest: expect.objectContaining( {
					sessionId: getClientRequestSessionId(),
					requestToken: 3,
				} ),
			} ),
			{ forceRest: true }
		);
		expect( setReviewState ).toHaveBeenLastCalledWith( 'fresh', {
			requestToken: 3,
			docsGroundingWarning: expect.objectContaining( {
				tone: 'info',
			} ),
		} );
		expect( result.ok ).toBe( true );
		expect( result.reviewContextSignature ).toBe( 'review-stored' );
		expect( result.docsGroundingWarning.message ).toContain(
			'cache-only and no docs result was cached'
		);
	} );

	test( 'review signature drift stales the result even during a docs grounding outage', async () => {
		const { setReviewState, thunk } = buildReviewThunk( {
			reviewContextSignature: 'review-changed-by-docs-fingerprint',
			docsGrounding: { available: false, sourceTypes: [], count: 0 },
		} );
		const dispatch = jest.fn();

		const result = await thunk( {
			dispatch,
			select: {},
		} );

		expect( setReviewState ).toHaveBeenLastCalledWith( 'stale', {
			requestToken: 3,
			staleReason: 'server-review',
		} );
		expect( result ).toEqual( {
			ok: false,
			staleReason: 'server-review',
			surface: 'template',
		} );
	} );

	test( 'keeps matching grounded reviews fresh without attaching a warning', async () => {
		const { setReviewState, thunk } = buildReviewThunk( {
			reviewContextSignature: 'review-stored',
			docsGrounding: {
				available: true,
				sourceTypes: [ 'developer-docs' ],
				count: 2,
			},
		} );
		const dispatch = jest.fn();

		const result = await thunk( {
			dispatch,
			select: {},
		} );

		expect( setReviewState ).toHaveBeenLastCalledWith( 'fresh', {
			requestToken: 3,
			docsGroundingWarning: null,
		} );
		expect( result ).toEqual( {
			ok: true,
			reviewContextSignature: 'review-stored',
			surface: 'template',
		} );
	} );
} );

describe( 'executable-surface runtime apply thunk', () => {
	test.each(
		[ 'template', 'template-part', 'global-styles', 'style-book' ].flatMap(
			( surface ) =>
				[
					'blocks',
					'scope',
					'settings',
					'theme features',
					'request',
					'draft',
					'unavailable request',
					'none',
					'replaced result',
					...( surface === 'global-styles' || surface === 'style-book'
						? [ 'style config' ]
						: [] ),
				].map( ( drift ) => [ surface, drift ] )
		)
	)(
		'rechecks %s apply when %s changes during preflight',
		async ( surface, drift ) => {
			let resolveFreshness;
			const state = {
				scope: {
					key: 'wp_template:theme//home',
					entityId: 'theme//home',
				},
				blocks: [
					{
						clientId: 'paragraph-a',
						name: 'core/paragraph',
						attributes: { content: 'Original' },
						innerBlocks: [],
					},
				],
				settings: { allowedBlockTypes: true },
				resultToken: 1,
				styleConfig: {
					settings: {},
					styles: { color: { text: '#123456' } },
				},
			};
			let liveRequestState = {
				requestSignature: 'request',
				requestInput: { prompt: 'Refine this surface.' },
			};
			const executeSuggestion = jest.fn( () => ( {
				ok: true,
				operations: [],
			} ) );
			const dispatched = [];
			const registry = {
				select: ( name ) => {
					if ( name === 'core/block-editor' ) {
						return {
							getBlocks: () => state.blocks,
							getSettings: () => state.settings,
						};
					}
					if ( name === 'core' ) {
						return {
							getCurrentGlobalStylesId: () => 17,
							getEditedEntityRecord: () => state.styleConfig,
						};
					}
					return {};
				},
			};
			const thunk = buildExecutableSurfaceApplyThunk(
				{
					applyFailureMessage: 'Apply failed.',
					abilityName: 'test/apply',
					buildActivityEntry: null,
					executeSuggestion,
					getStoredRequestSignature: () => 'request',
					getStoredResolvedContextSignature: () => 'resolved',
					getStoredRequestToken: () => 1,
					getStoredResultToken: () => state.resultToken,
					setApplyState: ( status, payload ) => ( {
						type: 'APPLY_STATE',
						status,
						payload,
					} ),
					surface,
					unexpectedErrorMessage: 'Unexpected failure.',
				},
				{ suggestionKey: 'suggestion-1' },
				'request',
				liveRequestState.requestInput,
				{
					getCurrentActivityScope: () => state.scope,
					guardSurfaceApplyFreshness: () => null,
					guardSurfaceApplyResolvedFreshness: () =>
						new Promise( ( resolve ) => {
							resolveFreshness = resolve;
						} ),
					recordActivityEntry: jest.fn(),
					syncActivitySession: jest.fn(),
				},
				() => liveRequestState
			);
			const pending = thunk( {
				dispatch: ( action ) => {
					dispatched.push( action );
					return action;
				},
				registry,
				select: {},
			} );
			if ( drift === 'blocks' ) {
				state.blocks = [
					{
						...state.blocks[ 0 ],
						attributes: { content: 'Manual edit' },
					},
				];
			} else if ( drift === 'scope' ) {
				state.scope = {
					key: 'wp_template:theme//other',
					entityId: 'theme//other',
				};
			} else if ( drift === 'settings' ) {
				state.settings = { allowedBlockTypes: [ 'core/heading' ] };
			} else if ( drift === 'theme features' ) {
				state.settings = {
					allowedBlockTypes: true,
					features: { color: { custom: false } },
				};
			} else if ( drift === 'request' ) {
				liveRequestState = {
					requestSignature: 'new-request',
					requestInput: { prompt: 'New draft' },
				};
			} else if ( drift === 'draft' ) {
				liveRequestState = {
					requestSignature: 'request',
					requestInput: { prompt: 'New draft' },
				};
			} else if ( drift === 'unavailable request' ) {
				liveRequestState = null;
			} else if ( drift === 'style config' ) {
				state.styleConfig = {
					settings: {},
					styles: { color: { text: '#abcdef' } },
				};
			} else if ( drift === 'replaced result' ) {
				state.resultToken = 2;
			}
			resolveFreshness( { ok: true } );
			const result = await pending;
			expect( result ).toEqual(
				expect.objectContaining(
					drift === 'none'
						? { ok: true }
						: {
								ok: false,
								...( drift === 'replaced result'
									? { skipped: true }
									: { staleReason: 'client' } ),
						  }
				)
			);
			expect( executeSuggestion ).toHaveBeenCalledTimes(
				drift === 'none' ? 1 : 0
			);
			expect(
				dispatched.filter( ( action ) => action.status === 'success' )
			).toHaveLength( drift === 'none' ? 1 : 0 );
		}
	);

	test( 'marks apply in flight before awaiting resolved freshness validation', async () => {
		let resolveFreshness;
		const dispatched = [];
		const setApplyState = jest.fn( ( status, payload ) => ( {
			type: 'APPLY_STATE',
			status,
			payload,
		} ) );
		const thunk = buildExecutableSurfaceApplyThunk(
			{
				applyFailureMessage: 'Apply failed.',
				abilityName: 'test/apply',
				buildActivityEntry: null,
				executeSuggestion: jest.fn( () =>
					Promise.resolve( { ok: true, operations: [] } )
				),
				getStoredRequestSignature: jest.fn( () => 'request' ),
				getStoredResolvedContextSignature: jest.fn( () => 'resolved' ),
				setApplyState,
				surface: 'global-styles',
				unexpectedErrorMessage: 'Unexpected failure.',
			},
			{ suggestionKey: 'suggestion-1' },
			'request',
			{ prompt: 'Refine styles.' },
			{
				dispatchToastForActivity: null,
				getCurrentActivityScope: jest.fn( () => ( {
					key: 'global_styles:17',
				} ) ),
				guardSurfaceApplyFreshness: jest.fn( () => null ),
				guardSurfaceApplyResolvedFreshness: jest.fn(
					() =>
						new Promise( ( resolve ) => {
							resolveFreshness = resolve;
						} )
				),
				recordActivityEntry: jest.fn( () => Promise.resolve( null ) ),
				syncActivitySession: jest.fn(),
			}
		);

		const resultPromise = thunk( {
			dispatch: ( action ) => {
				dispatched.push( action );
				return action;
			},
			registry: {},
			select: {},
		} );

		await Promise.resolve();

		expect( dispatched[ 0 ] ).toEqual( {
			type: 'APPLY_STATE',
			status: 'applying',
			payload: undefined,
		} );

		resolveFreshness( { ok: true } );
		await resultPromise;
	} );

	test( 'records the validator code on validation_blocked, not the generic fallback', async () => {
		const recordOutcomeAction = jest.fn( ( outcome ) => ( {
			type: 'RECORD_OUTCOME',
			...outcome,
		} ) );
		const setApplyState = jest.fn( ( status, payload ) => ( {
			type: 'APPLY_STATE',
			status,
			payload,
		} ) );
		const thunk = buildExecutableSurfaceApplyThunk(
			{
				applyFailureMessage: 'Apply failed.',
				abilityName: 'test/apply',
				buildActivityEntry: null,
				executeSuggestion: jest.fn( () =>
					Promise.resolve( {
						ok: false,
						error: 'This suggestion targets overlapping template-part block paths and cannot be applied automatically.',
						code: 'overlapping_block_paths',
					} )
				),
				getStoredRequestSignature: jest.fn( () => 'request' ),
				getStoredResolvedContextSignature: jest.fn( () => 'resolved' ),
				recordOutcomeAction,
				setApplyState,
				surface: 'template-part',
				unexpectedErrorMessage: 'Unexpected failure.',
			},
			{ suggestionKey: 'suggestion-overlap' },
			'request',
			{ prompt: 'Refine the template part.' },
			{
				dispatchToastForActivity: null,
				getCurrentActivityScope: jest.fn( () => ( {
					key: 'wp_template_part:home//header',
				} ) ),
				guardSurfaceApplyFreshness: jest.fn( () => null ),
				guardSurfaceApplyResolvedFreshness: jest.fn( () =>
					Promise.resolve( { ok: true } )
				),
				recordActivityEntry: jest.fn( () => Promise.resolve( null ) ),
				syncActivitySession: jest.fn(),
			}
		);

		const result = await thunk( {
			dispatch: ( action ) => action,
			registry: {},
			select: {},
		} );

		expect( result ).toEqual(
			expect.objectContaining( {
				ok: false,
				code: 'overlapping_block_paths',
			} )
		);
		expect( recordOutcomeAction ).toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'validation_blocked',
				surface: 'template-part',
				reason: 'overlapping_block_paths',
			} )
		);
	} );

	test( 'falls back to operation_validation_failed when the result code is not a string', async () => {
		const recordOutcomeAction = jest.fn( ( outcome ) => ( {
			type: 'RECORD_OUTCOME',
			...outcome,
		} ) );
		const thunk = buildExecutableSurfaceApplyThunk(
			{
				applyFailureMessage: 'Apply failed.',
				abilityName: 'test/apply',
				buildActivityEntry: null,
				executeSuggestion: jest.fn( () =>
					Promise.resolve( {
						ok: false,
						error: 'Apply failed for an unmapped reason.',
						code: { value: 'overlapping_block_paths' },
					} )
				),
				getStoredRequestSignature: jest.fn( () => 'request' ),
				getStoredResolvedContextSignature: jest.fn( () => 'resolved' ),
				recordOutcomeAction,
				setApplyState: jest.fn( ( status, payload ) => ( {
					type: 'APPLY_STATE',
					status,
					payload,
				} ) ),
				surface: 'global-styles',
				unexpectedErrorMessage: 'Unexpected failure.',
			},
			{ suggestionKey: 'suggestion-unmapped' },
			'request',
			{ prompt: 'Refine styles.' },
			{
				dispatchToastForActivity: null,
				getCurrentActivityScope: jest.fn( () => ( {
					key: 'global_styles:17',
				} ) ),
				guardSurfaceApplyFreshness: jest.fn( () => null ),
				guardSurfaceApplyResolvedFreshness: jest.fn( () =>
					Promise.resolve( { ok: true } )
				),
				recordActivityEntry: jest.fn( () => Promise.resolve( null ) ),
				syncActivitySession: jest.fn(),
			}
		);

		await thunk( {
			dispatch: ( action ) => action,
			registry: {},
			select: {},
		} );

		expect( recordOutcomeAction ).toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'validation_blocked',
				surface: 'global-styles',
				reason: 'operation_validation_failed',
			} )
		);
	} );
} );
