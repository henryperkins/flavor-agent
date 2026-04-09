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
			actions.setTemplateRecommendations(
				'theme//home',
				{
					suggestions: [ { label: 'Refresh header' } ],
					explanation: 'Review before applying.',
				},
				'',
				null,
				null,
				'review-template',
				'resolved-template'
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

	test( 'loading a fresh template recommendation set keeps the active review target but resets apply feedback', () => {
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
		state = reducer(
			state,
			actions.setTemplateReviewFreshnessState(
				'stale',
				2,
				'server-review'
			)
		);
		state = reducer( state, actions.setTemplateStatus( 'loading' ) );

		expect( selectors.getTemplateSelectedSuggestionKey( state ) ).toBe(
			'Refresh header-0'
		);
		expect( selectors.getTemplateApplyStatus( state ) ).toBe( 'idle' );
		expect( selectors.getTemplateApplyError( state ) ).toBeNull();
		expect(
			selectors.getTemplateLastAppliedSuggestionKey( state )
		).toBeNull();
		expect( selectors.getTemplateLastAppliedOperations( state ) ).toEqual(
			[]
		);
		expect( selectors.getTemplateReviewFreshnessStatus( state ) ).toBe(
			'idle'
		);
		expect( selectors.getTemplateReviewStaleReason( state ) ).toBeNull();
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
				1,
				null,
				'review-template',
				'resolved-template'
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

	test( 'template loading invalidates in-flight review freshness completions', () => {
		let state = reducer(
			undefined,
			actions.setTemplateRecommendations(
				'theme//home',
				{
					suggestions: [ { label: 'Refresh header' } ],
					explanation: 'Review before applying.',
				},
				'Prompt',
				1,
				'template-signature',
				'review-template',
				'resolved-template'
			)
		);

		expect( selectors.getTemplateReviewFreshnessStatus( state ) ).toBe(
			'fresh'
		);

		state = reducer(
			state,
			actions.setTemplateStatus( 'loading', null, 2 )
		);

		expect( selectors.getTemplateReviewFreshnessStatus( state ) ).toBe(
			'idle'
		);

		const staleCompletion = reducer(
			state,
			actions.setTemplateReviewFreshnessState(
				'stale',
				1,
				'server-review'
			)
		);

		expect(
			selectors.getTemplateReviewFreshnessStatus( staleCompletion )
		).toBe( 'idle' );
		expect(
			selectors.getTemplateReviewStaleReason( staleCompletion )
		).toBeNull();
	} );

	test( 'loading a fresh template-part recommendation set keeps the active review target but resets apply feedback', () => {
		let state = reducer(
			undefined,
			actions.setTemplatePartRecommendations(
				'theme//header',
				{
					suggestions: [ { label: 'Add utility row' } ],
					explanation: 'Review before applying.',
				},
				'Prompt',
				1,
				'header-signature'
			)
		);

		state = reducer(
			state,
			actions.setTemplatePartSelectedSuggestion( 'Add utility row-0' )
		);
		state = reducer(
			state,
			actions.setTemplatePartApplyState(
				'error',
				'Pattern is missing in the template part.'
			)
		);
		state = reducer( state, actions.setTemplatePartStatus( 'loading' ) );

		expect( selectors.getTemplatePartSelectedSuggestionKey( state ) ).toBe(
			'Add utility row-0'
		);
		expect( selectors.getTemplatePartApplyStatus( state ) ).toBe( 'idle' );
		expect( selectors.getTemplatePartApplyError( state ) ).toBeNull();
	} );

	test( 'loading a fresh Global Styles recommendation set keeps the active review target but resets apply feedback', () => {
		let state = reducer(
			undefined,
			actions.setGlobalStylesRecommendations(
				{
					scopeKey: 'global_styles:17',
					globalStylesId: '17',
				},
				{
					suggestions: [ { label: 'Use accent canvas' } ],
					explanation: 'Review before applying.',
				},
				'Prompt',
				1,
				'global-styles-signature'
			)
		);

		state = reducer(
			state,
			actions.setGlobalStylesSelectedSuggestion( 'Use accent canvas-0' )
		);
		state = reducer(
			state,
			actions.setGlobalStylesApplyState(
				'error',
				'The styles branch no longer matches.'
			)
		);
		state = reducer( state, actions.setGlobalStylesStatus( 'loading' ) );

		expect( selectors.getGlobalStylesSelectedSuggestionKey( state ) ).toBe(
			'Use accent canvas-0'
		);
		expect( selectors.getGlobalStylesApplyStatus( state ) ).toBe( 'idle' );
		expect( selectors.getGlobalStylesApplyError( state ) ).toBeNull();
	} );

	test( 'loading a fresh Style Book recommendation set keeps the active review target but resets apply feedback', () => {
		let state = reducer(
			undefined,
			actions.setStyleBookRecommendations(
				{
					scopeKey: 'style_book:17:core/paragraph',
					globalStylesId: '17',
					blockName: 'core/paragraph',
					blockTitle: 'Paragraph',
				},
				{
					suggestions: [ { label: 'Refine paragraph rhythm' } ],
					explanation: 'Review before applying.',
				},
				'Prompt',
				1,
				'style-book-signature'
			)
		);

		state = reducer(
			state,
			actions.setStyleBookSelectedSuggestion(
				'Refine paragraph rhythm-0'
			)
		);
		state = reducer(
			state,
			actions.setStyleBookApplyState(
				'error',
				'The example block no longer matches.'
			)
		);
		state = reducer( state, actions.setStyleBookStatus( 'loading' ) );

		expect( selectors.getStyleBookSelectedSuggestionKey( state ) ).toBe(
			'Refine paragraph rhythm-0'
		);
		expect( selectors.getStyleBookApplyStatus( state ) ).toBe( 'idle' );
		expect( selectors.getStyleBookApplyError( state ) ).toBeNull();
	} );

	test( 'template normalized interaction state distinguishes preview-ready, applying, and error', () => {
		let state = reducer(
			undefined,
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

		expect( selectors.getTemplateInteractionState( state ) ).toBe(
			'advisory-ready'
		);

		state = reducer(
			state,
			actions.setTemplateSelectedSuggestion( 'Refresh header-0' )
		);

		expect( selectors.getTemplateInteractionState( state ) ).toBe(
			'preview-ready'
		);

		state = reducer( state, actions.setTemplateApplyState( 'applying' ) );

		expect( selectors.getTemplateInteractionState( state ) ).toBe(
			'applying'
		);

		state = reducer(
			state,
			actions.setTemplateApplyState(
				'error',
				'Pattern is missing in the editor.'
			)
		);

		expect( selectors.getTemplateInteractionState( state ) ).toBe(
			'error'
		);
	} );

	test( 'undo success notice wins over stale template apply errors', () => {
		const state = reducer(
			undefined,
			actions.setTemplateApplyState(
				'error',
				'Pattern is missing in the editor.'
			)
		);

		expect(
			selectors.getSurfaceStatusNotice( state, 'template', {
				applyError: selectors.getTemplateApplyError( state ),
				undoSuccessMessage: 'Undid Refresh header.',
			} )
		).toEqual(
			expect.objectContaining( {
				source: 'undo',
				tone: 'success',
				message: 'Undid Refresh header.',
			} )
		);
	} );

	test( 'apply success notices expose the shared undo action affordance', () => {
		expect(
			selectors.getSurfaceStatusNotice( undefined, 'template', {
				applySuccessMessage: 'Applied 1 template operation.',
			} )
		).toEqual(
			expect.objectContaining( {
				source: 'apply',
				tone: 'success',
				message: 'Applied 1 template operation.',
				actionType: 'undo',
				actionLabel: 'Undo',
			} )
		);
	} );

	test( 'empty notices stay suppressed while a same-surface request is loading', () => {
		expect(
			selectors.getSurfaceStatusNotice( undefined, 'template-part', {
				requestStatus: 'loading',
				hasResult: true,
				hasSuggestions: false,
				emptyMessage:
					'No template-part suggestions were returned for this request.',
			} )
		).toBeNull();
	} );

	test( 'empty-result notices win over advisory copy once a zero-suggestion result is ready', () => {
		expect(
			selectors.getSurfaceStatusNotice( undefined, 'global-styles', {
				hasResult: true,
				hasSuggestions: false,
				emptyMessage:
					'No safe Global Styles changes were returned for this prompt.',
				advisoryMessage:
					'Review a theme-backed change before applying it.',
			} )
		).toEqual(
			expect.objectContaining( {
				source: 'empty',
				message:
					'No safe Global Styles changes were returned for this prompt.',
			} )
		);
	} );

	test( 'stale surfaces suppress empty and advisory notices until the result is refreshed', () => {
		expect(
			selectors.getSurfaceStatusNotice( undefined, 'template', {
				hasResult: true,
				hasSuggestions: false,
				isStale: true,
				emptyMessage:
					'No safe template changes were returned for this prompt.',
				advisoryMessage:
					'Review a template-backed change before applying it.',
			} )
		).toBeNull();
	} );

	test( 'template interaction state stays error after a failed request with no successful result', () => {
		const state = reducer(
			undefined,
			actions.setTemplateStatus( 'error', 'Template request failed.', 1 )
		);

		expect( selectors.getTemplateInteractionState( state ) ).toBe(
			'error'
		);
	} );
} );
