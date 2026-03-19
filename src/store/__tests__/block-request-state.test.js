import { actions, reducer, selectors } from '../index';

describe( 'block request state', () => {
	test( 'loading state is isolated per clientId', () => {
		const state = reducer(
			undefined,
			actions.setBlockRequestState( 'block-a', 'loading', null, 1 )
		);

		expect( selectors.isBlockLoading( state, 'block-a' ) ).toBe( true );
		expect( selectors.isBlockLoading( state, 'block-b' ) ).toBe( false );
		expect( selectors.getBlockStatus( state, 'block-b' ) ).toBe( 'idle' );
	} );

	test( 'error state is isolated per clientId', () => {
		const state = reducer(
			undefined,
			actions.setBlockRequestState(
				'block-a',
				'error',
				'Block A failed.',
				1
			)
		);

		expect( selectors.getBlockError( state, 'block-a' ) ).toBe(
			'Block A failed.'
		);
		expect( selectors.getBlockError( state, 'block-b' ) ).toBeNull();
	} );

	test( 'stale completions are ignored for the same block', () => {
		let state = reducer(
			undefined,
			actions.setBlockRequestState( 'block-a', 'loading', null, 1 )
		);

		state = reducer(
			state,
			actions.setBlockRequestState( 'block-a', 'loading', null, 2 )
		);
		state = reducer(
			state,
			actions.setBlockRecommendations(
				'block-a',
				{
					block: [ { label: 'Fresh result' } ],
				},
				2
			)
		);
		state = reducer(
			state,
			actions.setBlockRequestState( 'block-a', 'ready', null, 2 )
		);

		const staleState = reducer(
			state,
			actions.setBlockRecommendations(
				'block-a',
				{
					block: [ { label: 'Stale result' } ],
				},
				1
			)
		);
		const finalState = reducer(
			staleState,
			actions.setBlockRequestState( 'block-a', 'ready', null, 1 )
		);

		expect(
			selectors.getBlockRecommendations( finalState, 'block-a' )
		).toEqual( {
			block: [ { label: 'Fresh result' } ],
		} );
		expect( selectors.getBlockRequestToken( finalState, 'block-a' ) ).toBe(
			2
		);
	} );

	test( 'clearing block recommendations also clears request metadata', () => {
		let state = reducer(
			undefined,
			actions.setBlockRequestState( 'block-a', 'error', 'Nope', 3 )
		);

		state = reducer(
			state,
			actions.setBlockRecommendations(
				'block-a',
				{
					block: [ { label: 'Suggestion' } ],
				},
				3
			)
		);
		state = reducer(
			state,
			actions.clearBlockRecommendations( 'block-a' )
		);

		expect(
			selectors.getBlockRecommendations( state, 'block-a' )
		).toBeNull();
		expect( selectors.getBlockStatus( state, 'block-a' ) ).toBe( 'idle' );
		expect( selectors.getBlockError( state, 'block-a' ) ).toBeNull();
		expect( selectors.getBlockRequestToken( state, 'block-a' ) ).toBe( 0 );
	} );
} );
