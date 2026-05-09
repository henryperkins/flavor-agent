import { buildExecutableSurfaceApplyThunk } from '../executable-surface-runtime';

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
} );
