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

	test( 'marks matching reviews stale when docs grounding becomes unavailable', async () => {
		const { setReviewState, thunk } = buildReviewThunk( {
			reviewContextSignature: 'review-stored',
			docsGrounding: { status: 'unavailable' },
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
		expect( dispatch ).toHaveBeenNthCalledWith( 2, {
			type: 'REVIEW_STATE',
			status: 'stale',
			payload: {
				requestToken: 3,
				staleReason: 'docs-grounding-unavailable',
			},
		} );
		expect( setReviewState ).toHaveBeenCalledWith( 'stale', {
			requestToken: 3,
			staleReason: 'docs-grounding-unavailable',
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
		expect( result ).toEqual( {
			ok: false,
			staleReason: 'docs-grounding-unavailable',
			surface: 'template',
			docsGrounding: { status: 'unavailable' },
		} );
	} );

	test( 'prefers docs grounding unavailable over review signature drift', async () => {
		const { setReviewState, thunk } = buildReviewThunk( {
			reviewContextSignature: 'review-changed-by-docs-fingerprint',
			docsGrounding: {
				status: 'unavailable',
				message: 'Developer Docs grounding is unavailable.',
			},
		} );
		const dispatch = jest.fn();

		const result = await thunk( {
			dispatch,
			select: {},
		} );

		expect( setReviewState ).toHaveBeenLastCalledWith( 'stale', {
			requestToken: 3,
			staleReason: 'docs-grounding-unavailable',
		} );
		expect( result ).toEqual( {
			ok: false,
			staleReason: 'docs-grounding-unavailable',
			surface: 'template',
			docsGrounding: {
				status: 'unavailable',
				message: 'Developer Docs grounding is unavailable.',
			},
		} );
	} );

	test( 'dispatches normalized docs grounding warnings without marking matching reviews stale', async () => {
		const docsGrounding = {
			status: 'degraded',
			message: 'Docs grounding is partial.',
			coverage: { status: 'current' },
		};
		const { setReviewState, thunk } = buildReviewThunk( {
			reviewContextSignature: 'review-stored',
			docsGrounding,
		} );
		const dispatch = jest.fn();

		const result = await thunk( {
			dispatch,
			select: {},
		} );

		expect( setReviewState ).toHaveBeenLastCalledWith( 'fresh', {
			requestToken: 3,
			docsGroundingWarning: {
				status: 'degraded',
				message: 'Docs grounding is partial.',
				coverageStatus: 'current',
				coverageMessage: '',
				source: '',
				checkedAt: '',
			},
		} );
		expect( result ).toEqual( {
			ok: true,
			reviewContextSignature: 'review-stored',
			surface: 'template',
			docsGroundingWarning: {
				status: 'degraded',
				message: 'Docs grounding is partial.',
				coverageStatus: 'current',
				coverageMessage: '',
				source: '',
				checkedAt: '',
			},
		} );
	} );

	test( 'captures coverage-only docs grounding warnings from grounded freshness responses', async () => {
		const { setReviewState, thunk } = buildReviewThunk( {
			reviewContextSignature: 'review-stored',
			docsGrounding: {
				status: 'grounded',
				coverage: {
					status: 'missing-current-release-cycle',
					message: 'Current release-cycle docs were not confirmed.',
				},
			},
		} );
		const dispatch = jest.fn();

		const result = await thunk( {
			dispatch,
			select: {},
		} );

		const docsGroundingWarning = {
			status: 'grounded',
			message: '',
			coverageStatus: 'missing-current-release-cycle',
			coverageMessage: 'Current release-cycle docs were not confirmed.',
			source: '',
			checkedAt: '',
		};

		expect( setReviewState ).toHaveBeenLastCalledWith( 'fresh', {
			requestToken: 3,
			docsGroundingWarning,
		} );
		expect( result ).toEqual( {
			ok: true,
			reviewContextSignature: 'review-stored',
			surface: 'template',
			docsGroundingWarning,
		} );
	} );
} );

describe( 'executable-surface runtime apply thunk', () => {
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
					key: 'template_part:home//header',
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
