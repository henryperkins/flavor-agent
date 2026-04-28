jest.mock( '../../utils/template-actions', () => ( {
	applyTemplateSuggestionOperations: jest.fn(),
	getTemplateActivityUndoState: jest.fn(
		( activity ) => activity?.undo || {}
	),
	undoTemplateSuggestionOperations: jest.fn(),
} ) );

import { actions, reducer } from '../index';

describe( 'pattern status store contract', () => {
	test( 'SET_PATTERN_STATUS stores status and error together', () => {
		const state = reducer(
			undefined,
			actions.setPatternStatus( 'error', 'Some message' )
		);

		expect( state.patternStatus ).toBe( 'error' );
		expect( state.patternError ).toBe( 'Some message' );
	} );

	test( 'SET_PATTERN_RECS clears patternError and recomputes the badge', () => {
		const errorState = reducer(
			undefined,
			actions.setPatternStatus( 'error', 'Some message' )
		);
		const nextState = reducer(
			errorState,
			actions.setPatternRecommendations( [
				{
					name: 'theme/hero',
					reason: 'High-confidence recommendation',
					score: 0.95,
				},
			] )
		);

		expect( nextState.patternRecommendations ).toHaveLength( 1 );
		expect( nextState.patternError ).toBeNull();
		expect( nextState.patternBadge ).toBe(
			'High-confidence recommendation'
		);
	} );

	test( 'success cycle ends with a cleared error state', () => {
		const loadingState = reducer(
			undefined,
			actions.setPatternStatus( 'loading' )
		);
		const withRecommendations = reducer(
			loadingState,
			actions.setPatternRecommendations( [
				{
					name: 'theme/gallery',
					reason: 'Gallery matches the media-heavy section',
					score: 0.99,
				},
			] )
		);
		const readyState = reducer(
			withRecommendations,
			actions.setPatternStatus( 'ready' )
		);

		expect( readyState.patternStatus ).toBe( 'ready' );
		expect( readyState.patternError ).toBeNull();
		expect( readyState.patternBadge ).toBe(
			'Gallery matches the media-heavy section'
		);
	} );

	test( 'empty recommendations clear the badge and preserve an empty list', () => {
		const stateWithBadge = reducer(
			undefined,
			actions.setPatternRecommendations( [
				{
					name: 'theme/gallery',
					reason: 'Gallery matches the media-heavy section',
					score: 0.99,
				},
			] )
		);
		const clearedState = reducer(
			stateWithBadge,
			actions.setPatternRecommendations( [] )
		);

		expect( clearedState.patternRecommendations ).toEqual( [] );
		expect( clearedState.patternBadge ).toBeNull();
		expect( clearedState.patternError ).toBeNull();
	} );

	test( 'stale pattern completions are ignored', () => {
		let state = reducer(
			undefined,
			actions.setPatternStatus( 'loading', null, 1, 'signature-a' )
		);
		state = reducer(
			state,
			actions.setPatternStatus( 'loading', null, 2, 'signature-b' )
		);
		state = reducer(
			state,
			actions.setPatternRecommendations(
				[
					{
						name: 'theme/fresh',
						reason: 'Fresh result.',
						score: 0.98,
					},
				],
				2,
				'signature-b'
			)
		);
		state = reducer(
			state,
			actions.setPatternStatus( 'ready', null, 2, 'signature-b' )
		);

		const staleRecommendationsState = reducer(
			state,
			actions.setPatternRecommendations(
				[
					{
						name: 'theme/stale',
						reason: 'Stale result.',
						score: 0.99,
					},
				],
				1,
				'signature-a'
			)
		);
		const staleStatusState = reducer(
			staleRecommendationsState,
			actions.setPatternStatus(
				'error',
				'Old request failed.',
				1,
				'signature-a'
			)
		);

		expect( staleStatusState.patternRecommendations ).toEqual( [
			{
				name: 'theme/fresh',
				reason: 'Fresh result.',
				score: 0.98,
			},
		] );
		expect( staleStatusState.patternStatus ).toBe( 'ready' );
		expect( staleStatusState.patternError ).toBeNull();
		expect( staleStatusState.patternRequestToken ).toBe( 2 );
		expect( staleStatusState.patternRequestSignature ).toBe(
			'signature-b'
		);
	} );

	test( 'same-token pattern completions with mismatched signatures are ignored', () => {
		let state = reducer(
			undefined,
			actions.setPatternStatus(
				'loading',
				null,
				5,
				'signature-current'
			)
		);
		state = reducer(
			state,
			actions.setPatternRecommendations(
				[
					{
						name: 'theme/current',
						reason: 'Current result.',
						score: 0.98,
					},
				],
				5,
				'signature-current'
			)
		);
		state = reducer(
			state,
			actions.setPatternStatus(
				'ready',
				null,
				5,
				'signature-current'
			)
		);

		const staleRecommendationsState = reducer(
			state,
			actions.setPatternRecommendations(
				[
					{
						name: 'theme/same-token-stale',
						reason: 'Same token, stale signature.',
						score: 0.99,
					},
				],
				5,
				'signature-stale'
			)
		);
		const staleStatusState = reducer(
			staleRecommendationsState,
			actions.setPatternStatus(
				'error',
				'Same-token stale request failed.',
				5,
				'signature-stale'
			)
		);

		expect( staleStatusState.patternRecommendations ).toEqual( [
			{
				name: 'theme/current',
				reason: 'Current result.',
				score: 0.98,
			},
		] );
		expect( staleStatusState.patternStatus ).toBe( 'ready' );
		expect( staleStatusState.patternError ).toBeNull();
		expect( staleStatusState.patternRequestToken ).toBe( 5 );
		expect( staleStatusState.patternRequestSignature ).toBe(
			'signature-current'
		);
	} );
} );
