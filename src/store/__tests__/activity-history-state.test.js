jest.mock( '../../utils/template-actions', () => ( {
	applyTemplateSuggestionOperations: jest.fn(),
	getTemplateActivityUndoState: jest.fn(
		( activity ) => activity?.undo || {}
	),
	undoTemplateSuggestionOperations: jest.fn(),
} ) );

import { actions, reducer, selectors } from '../index';

describe( 'activity history reducer state', () => {
	test( 'hydrating a session restores activity history for the current scope', () => {
		const state = reducer(
			undefined,
			actions.setActivitySession( 'post:42', [
				{
					id: 'activity-1',
					type: 'apply_suggestion',
					surface: 'block',
					suggestion: 'Refresh content',
					target: {
						clientId: 'block-1',
					},
					undo: {
						canUndo: true,
						status: 'available',
					},
				},
			] )
		);

		expect( selectors.getActivityScopeKey( state ) ).toBe( 'post:42' );
		expect( selectors.getActivityLog( state ) ).toHaveLength( 1 );
		expect( selectors.getLatestUndoableActivity( state ) ).toEqual(
			expect.objectContaining( {
				id: 'activity-1',
			} )
		);
	} );

	test( 'marking a template activity as undone clears template apply feedback', () => {
		let state = reducer(
			undefined,
			actions.setTemplateApplyState( 'success', null, 'template-1', [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
				},
			] )
		);

		state = reducer(
			state,
			actions.logActivity( {
				id: 'activity-1',
				type: 'apply_template_suggestion',
				surface: 'template',
				suggestion: 'Clarify hierarchy',
				target: {
					templateRef: 'theme//home',
				},
				undo: {
					canUndo: true,
					status: 'available',
				},
			} )
		);

		state = reducer(
			state,
			actions.updateActivityUndoState(
				'activity-1',
				'undone',
				null,
				'2026-03-23T00:00:00Z'
			)
		);

		expect( selectors.getTemplateApplyStatus( state ) ).toBe( 'idle' );
		expect(
			selectors.getTemplateLastAppliedSuggestionKey( state )
		).toBeNull();
		expect( selectors.getTemplateLastAppliedOperations( state ) ).toEqual(
			[]
		);
		expect( selectors.getActivityLog( state )[ 0 ].undo.status ).toBe(
			'undone'
		);
	} );

	test( 'hydrating legacy template activity keeps the row but marks undo unavailable', () => {
		const state = reducer(
			undefined,
			actions.setActivitySession( 'wp_template:home', [
				{
					id: 'activity-legacy',
					type: 'apply_template_suggestion',
					surface: 'template',
					suggestion: 'Legacy insert',
					target: {
						templateRef: 'theme//home',
					},
					undo: {
						canUndo: false,
						status: 'failed',
						error: 'This template action was recorded before refresh-safe undo support and cannot be undone automatically.',
					},
				},
			] )
		);

		expect( selectors.getLatestUndoableActivity( state ) ).toBeNull();
		expect( selectors.getActivityLog( state )[ 0 ].undo ).toEqual(
			expect.objectContaining( {
				canUndo: false,
				status: 'failed',
			} )
		);
	} );
} );
