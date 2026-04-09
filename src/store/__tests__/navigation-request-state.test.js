jest.mock( '../../utils/template-actions', () => ( {
	applyTemplateSuggestionOperations: jest.fn(),
	getTemplateActivityUndoState: jest.fn(
		( activity ) => activity?.undo || {}
	),
	undoTemplateSuggestionOperations: jest.fn(),
} ) );

import { actions, reducer, selectors } from '../index';

describe( 'navigation request state', () => {
	test( 'navigation results are scoped to the active block clientId', () => {
		let state = reducer(
			undefined,
			actions.setNavigationStatus( 'loading', null, 1, 'nav-1' )
		);

		state = reducer(
			state,
			actions.setNavigationRecommendations(
				'nav-1',
				{
					suggestions: [ { label: 'Group utility links' } ],
					explanation: 'Review the top-level structure.',
				},
				'Simplify the nav.',
				1
			)
		);

		expect(
			selectors.getNavigationRecommendations( state, 'nav-1' )
		).toEqual( [ { label: 'Group utility links' } ] );
		expect( selectors.getNavigationExplanation( state, 'nav-1' ) ).toBe(
			'Review the top-level structure.'
		);
		expect(
			selectors.getNavigationRecommendations( state, 'nav-2' )
		).toEqual( [] );
		expect( selectors.getNavigationExplanation( state, 'nav-2' ) ).toBe(
			''
		);
		expect( selectors.getNavigationResultToken( state, 'nav-2' ) ).toBe(
			0
		);
	} );

	test( 'stale navigation completions are ignored', () => {
		let state = reducer(
			undefined,
			actions.setNavigationStatus( 'loading', null, 1, 'nav-1' )
		);

		state = reducer(
			state,
			actions.setNavigationStatus( 'loading', null, 2, 'nav-1' )
		);
		state = reducer(
			state,
			actions.setNavigationRecommendations(
				'nav-1',
				{
					suggestions: [ { label: 'Fresh result' } ],
					explanation: 'Use the current menu structure.',
				},
				'Prompt',
				2
			)
		);

		const staleState = reducer(
			state,
			actions.setNavigationRecommendations(
				'nav-1',
				{
					suggestions: [ { label: 'Stale result' } ],
					explanation: 'Outdated response.',
				},
				'Old prompt',
				1
			)
		);
		const finalState = reducer(
			staleState,
			actions.setNavigationStatus(
				'error',
				'Stale request failed.',
				1,
				'nav-1'
			)
		);

		expect(
			selectors.getNavigationRecommendations( finalState, 'nav-1' )
		).toEqual( [ { label: 'Fresh result' } ] );
		expect( selectors.getNavigationStatus( finalState, 'nav-1' ) ).toBe(
			'ready'
		);
		expect( selectors.getNavigationRequestToken( finalState ) ).toBe( 2 );
	} );

	test( 'navigation maps into the shared advisory-only interaction contract', () => {
		let state = reducer(
			undefined,
			actions.setNavigationStatus( 'loading', null, 1, 'nav-1' )
		);

		expect(
			selectors.getNavigationInteractionState( state, 'nav-1' )
		).toBe( 'loading' );

		state = reducer(
			state,
			actions.setNavigationRecommendations(
				'nav-1',
				{
					suggestions: [ { label: 'Group utility links' } ],
					explanation: 'Keep utility items together.',
				},
				'Prompt',
				1
			)
		);

		expect(
			selectors.getNavigationInteractionState( state, 'nav-1' )
		).toBe( 'advisory-ready' );
		expect(
			selectors.getSurfaceInteractionContract( state, 'navigation' )
		).toEqual(
			expect.objectContaining( {
				advisoryOnly: true,
				previewRequired: false,
			} )
		);
		expect(
			selectors.isSurfaceApplyAllowed( state, 'navigation', {
				hasResult: true,
			} )
		).toBe( false );
	} );

	test( 'navigation loading invalidates in-flight review freshness completions', () => {
		let state = reducer(
			undefined,
			actions.setNavigationRecommendations(
				'nav-1',
				{
					suggestions: [ { label: 'Group utility links' } ],
					explanation: 'Keep utility items together.',
				},
				'Prompt',
				1,
				'navigation-signature',
				'review-navigation'
			)
		);

		expect(
			selectors.getNavigationReviewFreshnessStatus( state, 'nav-1' )
		).toBe( 'fresh' );

		state = reducer(
			state,
			actions.setNavigationStatus( 'loading', null, 2, 'nav-1' )
		);

		expect(
			selectors.getNavigationReviewFreshnessStatus( state, 'nav-1' )
		).toBe( 'idle' );

		const staleCompletion = reducer(
			state,
			actions.setNavigationReviewFreshnessState(
				'stale',
				1,
				'server-review'
			)
		);

		expect(
			selectors.getNavigationReviewFreshnessStatus(
				staleCompletion,
				'nav-1'
			)
		).toBe( 'idle' );
		expect(
			selectors.getNavigationReviewStaleReason(
				staleCompletion,
				'nav-1'
			)
		).toBeNull();
	} );
} );
