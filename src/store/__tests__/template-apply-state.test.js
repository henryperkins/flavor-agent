jest.mock( '../../utils/template-actions', () => ( {
	applyTemplateSuggestionOperations: jest.fn(),
	getTemplateActivityUndoState: jest.fn(
		( activity ) => activity?.undo || {}
	),
	undoTemplateSuggestionOperations: jest.fn(),
} ) );

import { actions, reducer, selectors } from '../index';

describe( 'template apply state', () => {
	test( 'preview selection and apply success are tracked separately from fetched suggestions', () => {
		let state = reducer(
			undefined,
			actions.setTemplateRecommendations( 'theme//home', {
				suggestions: [ { label: 'Refresh header' } ],
				explanation: 'Review before applying.',
			} )
		);

		state = reducer(
			state,
			actions.setTemplateSelectedSuggestion( 'Refresh header-0' )
		);
		state = reducer(
			state,
			actions.setTemplateApplyState(
				'success',
				null,
				'Refresh header-0',
				[
					{
						type: 'replace_template_part',
						area: 'header',
					},
				]
			)
		);

		expect( selectors.getTemplateSelectedSuggestionKey( state ) ).toBe(
			'Refresh header-0'
		);
		expect( selectors.getTemplateApplyStatus( state ) ).toBe( 'success' );
		expect( selectors.getTemplateLastAppliedSuggestionKey( state ) ).toBe(
			'Refresh header-0'
		);
		expect( selectors.getTemplateLastAppliedOperations( state ) ).toEqual( [
			{
				type: 'replace_template_part',
				area: 'header',
			},
		] );
	} );

	test( 'loading a fresh template recommendation set resets preview and apply feedback', () => {
		let state = reducer(
			undefined,
			actions.setTemplateRecommendations( 'theme//home', {
				suggestions: [ { label: 'Refresh header' } ],
				explanation: 'Review before applying.',
			} )
		);

		state = reducer(
			state,
			actions.setTemplateSelectedSuggestion( 'Refresh header-0' )
		);
		state = reducer(
			state,
			actions.setTemplateApplyState(
				'success',
				null,
				'Refresh header-0',
				[
					{
						type: 'insert_pattern',
						patternName: 'theme/hero',
					},
				]
			)
		);
		state = reducer( state, actions.setTemplateStatus( 'loading' ) );

		expect(
			selectors.getTemplateSelectedSuggestionKey( state )
		).toBeNull();
		expect( selectors.getTemplateApplyStatus( state ) ).toBe( 'idle' );
		expect( selectors.getTemplateApplyError( state ) ).toBeNull();
		expect(
			selectors.getTemplateLastAppliedSuggestionKey( state )
		).toBeNull();
		expect( selectors.getTemplateLastAppliedOperations( state ) ).toEqual(
			[]
		);
	} );

	test( 'selecting a new preview clears stale apply errors', () => {
		let state = reducer(
			undefined,
			actions.setTemplateApplyState(
				'error',
				'Pattern is missing in the editor.',
				null,
				[]
			)
		);

		state = reducer(
			state,
			actions.setTemplateSelectedSuggestion( 'Refresh footer-0' )
		);

		expect( selectors.getTemplateApplyStatus( state ) ).toBe( 'idle' );
		expect( selectors.getTemplateApplyError( state ) ).toBeNull();
		expect( selectors.getTemplateSelectedSuggestionKey( state ) ).toBe(
			'Refresh footer-0'
		);
	} );

	test( 'stale template recommendation completions are ignored', () => {
		let state = reducer(
			undefined,
			actions.setTemplateStatus( 'loading', null, 1 )
		);

		state = reducer(
			state,
			actions.setTemplateStatus( 'loading', null, 2 )
		);
		state = reducer(
			state,
			actions.setTemplateRecommendations(
				'theme//home',
				{
					suggestions: [ { label: 'Fresh result' } ],
					explanation: 'Use the updated template.',
				},
				'New prompt',
				2
			)
		);

		const staleState = reducer(
			state,
			actions.setTemplateRecommendations(
				'theme//archive',
				{
					suggestions: [ { label: 'Stale result' } ],
					explanation: 'Outdated template response.',
				},
				'Old prompt',
				1
			)
		);
		const finalState = reducer(
			staleState,
			actions.setTemplateStatus( 'error', 'Stale request failed.', 1 )
		);

		expect( selectors.getTemplateRecommendations( finalState ) ).toEqual( [
			{ label: 'Fresh result' },
		] );
		expect( selectors.getTemplateResultRef( finalState ) ).toBe(
			'theme//home'
		);
		expect( selectors.getTemplateStatus( finalState ) ).toBe( 'ready' );
		expect( selectors.getTemplateRequestToken( finalState ) ).toBe( 2 );
	} );

	test( 'clearing template recommendations invalidates in-flight completions', () => {
		let state = reducer(
			undefined,
			actions.setTemplateStatus( 'loading', null, 1 )
		);

		state = reducer(
			state,
			actions.setTemplateRecommendations(
				'theme//home',
				{
					suggestions: [ { label: 'Refresh header' } ],
					explanation: 'Review before applying.',
				},
				'Prompt',
				1
			)
		);
		state = reducer(
			state,
			actions.setTemplateSelectedSuggestion( 'Refresh header-0' )
		);
		state = reducer(
			state,
			actions.setTemplateApplyState(
				'success',
				null,
				'Refresh header-0',
				[
					{
						type: 'insert_pattern',
						patternName: 'theme/hero',
					},
				]
			)
		);
		state = reducer(
			state,
			actions.setTemplateApplyState(
				'error',
				'Pattern is missing in the editor.',
				'Refresh header-0',
				[]
			)
		);
		state = reducer( state, { type: 'CLEAR_TEMPLATE_RECS' } );
		state = reducer(
			state,
			actions.setTemplateRecommendations(
				'theme//home',
				{
					suggestions: [ { label: 'Late result' } ],
					explanation: 'This should be ignored.',
				},
				'Prompt',
				1
			)
		);

		expect( selectors.getTemplateRecommendations( state ) ).toEqual( [] );
		expect( selectors.getTemplateStatus( state ) ).toBe( 'idle' );
		expect( selectors.getTemplateRequestToken( state ) ).toBe( 2 );
		expect(
			selectors.getTemplateSelectedSuggestionKey( state )
		).toBeNull();
		expect( selectors.getTemplateApplyStatus( state ) ).toBe( 'idle' );
		expect( selectors.getTemplateApplyError( state ) ).toBeNull();
		expect(
			selectors.getTemplateLastAppliedSuggestionKey( state )
		).toBeNull();
		expect( selectors.getTemplateLastAppliedOperations( state ) ).toEqual(
			[]
		);
	} );
} );
