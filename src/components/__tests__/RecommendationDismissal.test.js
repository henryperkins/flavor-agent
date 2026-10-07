jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);
jest.mock( '@wordpress/data', () => ( { useDispatch: jest.fn() } ) );
jest.mock( '../../store', () => ( { STORE_NAME: 'flavor-agent' } ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );
const { useDispatch } = require( '@wordpress/data' );
import * as control from '../RecommendationDismissal';

const { getContainer, getRoot } = setupReactTest();
const suggestions = [ 's1', 's2', 's3', 's4' ].map( ( suggestionKey ) => ( {
	recommendationOutcome: {
		recommendationSetId: 'set-1',
		suggestionKey,
		sourceRequestSignature: 'hash_fresh',
	},
} ) );

describe( 'RecommendationDismissal', () => {
	let observations;
	beforeEach( () => {
		observations = [];
		useDispatch.mockReturnValue( {
			recordRecommendationOutcome: async ( observation ) => {
				observations.push( observation );
				return { persistence: { status: 'server' } };
			},
			dismissRecommendationSuggestions: async ( input ) => {
				observations.push( { ...input, event: 'dismissed' } );
				return [ { persistence: { status: 'server' } } ];
			},
		} );
	} );

	async function render( overrides = {} ) {
		expect( typeof control.default ).toBe( 'function' );
		await act( async () =>
			getRoot().render(
				<control.default
					surface="block"
					suggestions={ suggestions }
					currentRequestSignature="hash_fresh"
					{ ...overrides }
				/>
			)
		);
	}

	test( 'records every displayed identity and dismisses only after an explicit click', async () => {
		await render();
		expect( observations ).toEqual( [
			expect.objectContaining( {
				event: 'shown',
				shownSuggestionKeys: [ 's1', 's2', 's3', 's4' ],
			} ),
		] );
		await act( async () =>
			getContainer().querySelector( 'button' ).click()
		);
		expect(
			observations.filter( ( item ) => item.event === 'dismissed' )
		).toHaveLength( 1 );
		expect( getContainer().textContent ).toContain( 'Dismissal recorded' );
	} );

	test( 'unmount and inactivity never record dismissal', async () => {
		await render();
		await act( async () => getRoot().render( null ) );
		expect(
			observations.some( ( item ) => item.event === 'dismissed' )
		).toBe( false );
	} );

	test( 'names the visible target accessibly without adding its text to shown diagnostics', async () => {
		await render( {
			suggestions: [
				{ ...suggestions[ 0 ], label: 'Visible suggestion title' },
			],
		} );
		expect(
			getContainer()
				.querySelector( 'button' )
				.getAttribute( 'aria-label' )
		).toBe( 'Dismiss for now: Visible suggestion title' );
		expect( JSON.stringify( observations ) ).not.toContain(
			'Visible suggestion title'
		);
	} );

	test.each( [
		{ isStale: true },
		{ currentRequestSignature: 'hash_old' },
		{ suggestions: [ {} ] },
	] )( 'blocks stale or missing identities: %p', async ( input ) => {
		await render( input );
		const button = getContainer().querySelector( 'button' );
		expect( button === null || button.disabled ).toBe( true );
		expect( observations ).toEqual( [] );
	} );

	test( 'failed recording stays retryable without accepting a local-only observation', async () => {
		useDispatch.mockReturnValue( {
			recordRecommendationOutcome: async () => ( {
				persistence: { status: 'local' },
			} ),
			dismissRecommendationSuggestions: async () => {
				throw new Error( 'must not dismiss' );
			},
		} );
		await render();
		await act( async () =>
			getContainer().querySelector( 'button' ).click()
		);
		expect( getContainer().textContent ).toContain(
			'could not be recorded'
		);
		expect( getContainer().querySelector( 'button' ).disabled ).toBe(
			false
		);
	} );

	test( 'duplicate mounted controls never confirm a pending null receipt and remain retryable after failure', async () => {
		let finish;
		const pending = new Promise( ( resolve ) => {
			finish = resolve;
		} );
		const dismiss = jest
			.fn()
			.mockReturnValueOnce( pending )
			.mockResolvedValueOnce( [ null ] );
		useDispatch.mockReturnValue( {
			recordRecommendationOutcome: async () => ( {
				persistence: { status: 'server' },
			} ),
			dismissRecommendationSuggestions: dismiss,
		} );
		await act( async () =>
			getRoot().render(
				<>
					<control.default
						surface="block"
						suggestions={ suggestions }
						currentRequestSignature="hash_fresh"
					/>
					<control.default
						surface="block"
						suggestions={ suggestions }
						currentRequestSignature="hash_fresh"
					/>
				</>
			)
		);
		await act( async () => {
			for ( const button of getContainer().querySelectorAll(
				'button'
			) ) {
				button.click();
			}
		} );
		expect( getContainer().textContent ).not.toContain(
			'Dismissal recorded'
		);
		await act( async () =>
			finish( [ { persistence: { status: 'local' } } ] )
		);
		for ( const button of getContainer().querySelectorAll( 'button' ) ) {
			expect( button.disabled ).toBe( false );
		}
		expect( getContainer().textContent ).not.toContain(
			'Dismissal recorded'
		);
	} );

	test( 'a null shown receipt cannot authorize dismissal', async () => {
		const dismiss = jest.fn();
		useDispatch.mockReturnValue( {
			recordRecommendationOutcome: async () => null,
			dismissRecommendationSuggestions: dismiss,
		} );
		await render();
		await act( async () =>
			getContainer().querySelector( 'button' ).click()
		);
		expect( dismiss ).not.toHaveBeenCalled();
		expect( getContainer().querySelector( 'button' ).disabled ).toBe(
			false
		);
	} );

	test.each( [ 'server', 'local', 'rejected' ] )(
		'a pending %s dismissal receipt cannot change a replacement generation',
		async ( receiptStatus ) => {
			let finish;
			let fail;
			const pending = new Promise( ( resolve, reject ) => {
				finish = resolve;
				fail = reject;
			} );
			const dismiss = jest
				.fn()
				.mockReturnValueOnce( pending )
				.mockResolvedValueOnce( [
					{ persistence: { status: 'server' } },
				] );
			useDispatch.mockReturnValue( {
				recordRecommendationOutcome: async () => ( {
					persistence: { status: 'server' },
				} ),
				dismissRecommendationSuggestions: dismiss,
			} );
			await render();
			await act( async () =>
				getContainer().querySelector( 'button' ).click()
			);
			const replacement = suggestions.map( ( suggestion ) => ( {
				...suggestion,
				recommendationOutcome: {
					...suggestion.recommendationOutcome,
					recommendationSetId: 'set-2',
				},
			} ) );
			await render( { suggestions: replacement } );
			await act( async () => {
				if ( receiptStatus === 'rejected' ) {
					fail( new Error( 'Dismissal failed after replacement' ) );
				} else {
					finish( [ { persistence: { status: receiptStatus } } ] );
				}
			} );
			expect( getContainer().textContent ).not.toContain(
				'Dismissal recorded'
			);
			expect( getContainer().textContent ).not.toContain(
				'could not be recorded'
			);
			expect( getContainer().querySelector( 'button' ).disabled ).toBe(
				false
			);
			await act( async () =>
				getContainer().querySelector( 'button' ).click()
			);
			expect( dismiss ).toHaveBeenNthCalledWith(
				2,
				expect.objectContaining( { suggestions: replacement } )
			);
			expect( getContainer().textContent ).toContain(
				'Dismissal recorded'
			);
		}
	);

	test( 'a replacement generation cancels dispatch while its predecessor shown receipt is pending', async () => {
		let finishShown;
		const pendingShown = new Promise( ( resolve ) => {
			finishShown = resolve;
		} );
		const dismiss = jest
			.fn()
			.mockResolvedValue( [ { persistence: { status: 'server' } } ] );
		const observe = jest
			.fn()
			.mockReturnValueOnce( pendingShown )
			.mockResolvedValueOnce( { persistence: { status: 'server' } } );
		useDispatch.mockReturnValue( {
			recordRecommendationOutcome: observe,
			dismissRecommendationSuggestions: dismiss,
		} );
		await render();
		await act( async () =>
			getContainer().querySelector( 'button' ).click()
		);
		const replacement = suggestions.map( ( suggestion ) => ( {
			...suggestion,
			recommendationOutcome: {
				...suggestion.recommendationOutcome,
				recommendationSetId: 'set-2',
			},
		} ) );
		await render( { suggestions: replacement } );
		await act( async () =>
			finishShown( { persistence: { status: 'server' } } )
		);
		expect( dismiss ).not.toHaveBeenCalled();
		expect( getContainer().querySelector( 'button' ).disabled ).toBe(
			false
		);
		await act( async () =>
			getContainer().querySelector( 'button' ).click()
		);
		expect( dismiss ).toHaveBeenCalledTimes( 1 );
		expect( dismiss ).toHaveBeenCalledWith(
			expect.objectContaining( { suggestions: replacement } )
		);
	} );
} );
