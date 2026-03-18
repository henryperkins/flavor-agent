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
} );
