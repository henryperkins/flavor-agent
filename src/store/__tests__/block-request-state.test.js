jest.mock( '../../utils/template-actions', () => ( {
	applyTemplateSuggestionOperations: jest.fn(),
	getTemplateActivityUndoState: jest.fn(
		( activity ) => activity?.undo || {}
	),
	undoTemplateSuggestionOperations: jest.fn(),
} ) );

import { actions, reducer, selectors } from '../index';

describe( 'block request state', () => {
	beforeEach( () => {
		actions._blockRecommendationAbort = null;
	} );

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

	test( 'block apply state is isolated per clientId', () => {
		const state = reducer(
			undefined,
			actions.setBlockApplyState(
				'block-a',
				'error',
				'This result is stale. Refresh recommendations before applying it.'
			)
		);

		expect( selectors.getBlockApplyError( state, 'block-a' ) ).toBe(
			'This result is stale. Refresh recommendations before applying it.'
		);
		expect( selectors.getBlockApplyError( state, 'block-b' ) ).toBeNull();
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
		const abort = jest.fn();
		const dispatch = jest.fn();

		actions._blockRecommendationAbort = {
			'block-a': {
				abort,
			},
		};
		actions.clearBlockRecommendations( 'block-a' )( { dispatch } );
		state = reducer( state, dispatch.mock.calls[ 0 ][ 0 ] );

		expect(
			selectors.getBlockRecommendations( state, 'block-a' )
		).toBeNull();
		expect( selectors.getBlockStatus( state, 'block-a' ) ).toBe( 'idle' );
		expect( selectors.getBlockError( state, 'block-a' ) ).toBeNull();
		expect( selectors.getBlockRequestToken( state, 'block-a' ) ).toBe( 4 );
		expect( abort ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'clearing block recommendations invalidates in-flight completions', () => {
		let state = reducer(
			undefined,
			actions.setBlockRequestState( 'block-a', 'loading', null, 3 )
		);

		state = reducer(
			state,
			actions.setBlockRecommendations(
				'block-a',
				{
					block: [ { label: 'Current result' } ],
				},
				3
			)
		);
		const dispatch = jest.fn();

		actions.clearBlockRecommendations( 'block-a' )( { dispatch } );
		state = reducer( state, dispatch.mock.calls[ 0 ][ 0 ] );
		state = reducer(
			state,
			actions.setBlockRecommendations(
				'block-a',
				{
					block: [ { label: 'Late result' } ],
				},
				3
			)
		);
		state = reducer(
			state,
			actions.setBlockRequestState( 'block-a', 'ready', null, 3 )
		);

		expect( selectors.getBlockRecommendations( state, 'block-a' ) ).toBeNull();
		expect( selectors.getBlockStatus( state, 'block-a' ) ).toBe( 'idle' );
		expect( selectors.getBlockRequestToken( state, 'block-a' ) ).toBe( 4 );
	} );

	test( 'stores block request diagnostics with the latest successful result and clears them on reload', () => {
		const diagnostics = {
			hasEmptyBlockResult: true,
			title: 'No block-lane suggestions returned',
		};
		let state = reducer(
			undefined,
			actions.setBlockRecommendations(
				'block-a',
				{
					block: [],
					settings: [],
					styles: [],
				},
				2,
				'block-context',
				diagnostics
			)
		);

		expect(
			selectors.getBlockRequestDiagnostics( state, 'block-a' )
		).toEqual( diagnostics );

		state = reducer(
			state,
			actions.setBlockRequestState( 'block-a', 'loading', null, 3 )
		);

		expect(
			selectors.getBlockRequestDiagnostics( state, 'block-a' )
		).toBeNull();
	} );

	test( 'loading a new block request clears prior block apply errors', () => {
		let state = reducer(
			undefined,
			actions.setBlockApplyState(
				'block-a',
				'error',
				'This result is stale. Refresh recommendations before applying it.'
			)
		);

		state = reducer(
			state,
			actions.setBlockRequestState( 'block-a', 'loading', null, 3 )
		);

		expect( selectors.getBlockApplyError( state, 'block-a' ) ).toBeNull();
		expect( selectors.getBlockApplyStatus( state, 'block-a' ) ).toBe(
			'idle'
		);
	} );

	test( 'clearBlockError clears block apply errors as well as request errors', () => {
		let state = reducer(
			undefined,
			actions.setBlockApplyState(
				'block-a',
				'error',
				'This suggestion includes unsupported or unsafe attribute changes and could not be applied.'
			)
		);

		state = reducer( state, actions.clearBlockError( 'block-a' ) );

		expect( selectors.getBlockApplyError( state, 'block-a' ) ).toBeNull();
		expect( selectors.getBlockApplyStatus( state, 'block-a' ) ).toBe(
			'idle'
		);
	} );

	test( 'global styles and style book reducers accept scope.key as well as scope.scopeKey', () => {
		let state = reducer(
			undefined,
			actions.setGlobalStylesRecommendations(
				{
					key: 'global_styles:17',
					entityId: '17',
				},
				{
					suggestions: [ { label: 'Use accent canvas' } ],
					explanation: 'Editorial palette.',
				},
				'Make this feel more editorial.',
				2
			)
		);

		expect( selectors.getGlobalStylesScopeKey( state ) ).toBe(
			'global_styles:17'
		);
		expect( selectors.getGlobalStylesInteractionState( state ) ).toBe(
			'advisory-ready'
		);

		state = reducer(
			state,
			actions.setStyleBookRecommendations(
				{
					key: 'style_book:17:core/paragraph',
					globalStylesId: '17',
					blockName: 'core/paragraph',
					blockTitle: 'Paragraph',
				},
				{
					suggestions: [ { label: 'Tighten paragraph rhythm' } ],
					explanation: 'Use a denser type rhythm.',
				},
				'Make this paragraph feel more editorial.',
				3
			)
		);

		expect( selectors.getStyleBookScopeKey( state ) ).toBe(
			'style_book:17:core/paragraph'
		);
		expect( selectors.getStyleBookInteractionState( state ) ).toBe(
			'advisory-ready'
		);
	} );

	test( 'empty successful results still count as ready across block and editor-wide surfaces', () => {
		let state = reducer(
			undefined,
			actions.setBlockRecommendations(
				'block-a',
				{
					block: [],
					settings: [],
					styles: [],
					explanation: '',
				},
				1,
				'block-context'
			)
		);
		state = reducer(
			state,
			actions.setBlockRequestState( 'block-a', 'ready', null, 1 )
		);

		expect( selectors.getBlockInteractionState( state, 'block-a' ) ).toBe(
			'advisory-ready'
		);

		state = reducer(
			state,
			actions.setTemplateRecommendations(
				'theme//home',
				{
					suggestions: [],
					explanation: '',
				},
				'',
				2,
				'template-context'
			)
		);

		expect( selectors.getTemplateInteractionState( state ) ).toBe(
			'advisory-ready'
		);

		state = reducer(
			state,
			actions.setTemplatePartRecommendations(
				'theme//header',
				{
					suggestions: [],
					explanation: '',
				},
				'',
				3,
				'template-part-context'
			)
		);

		expect( selectors.getTemplatePartInteractionState( state ) ).toBe(
			'advisory-ready'
		);

		state = reducer(
			state,
			actions.setGlobalStylesRecommendations(
				{
					key: 'global_styles:17',
					entityId: '17',
				},
				{
					suggestions: [],
					explanation: '',
				},
				'',
				4,
				'global-styles-context'
			)
		);

		expect( selectors.getGlobalStylesInteractionState( state ) ).toBe(
			'advisory-ready'
		);

		state = reducer(
			state,
			actions.setStyleBookRecommendations(
				{
					key: 'style_book:17:core/paragraph',
					globalStylesId: '17',
					blockName: 'core/paragraph',
					blockTitle: 'Paragraph',
				},
				{
					suggestions: [],
					explanation: '',
				},
				'',
				5,
				'style-book-context'
			)
		);

		expect( selectors.getStyleBookInteractionState( state ) ).toBe(
			'advisory-ready'
		);
	} );

	test( 'global styles and style book interaction state stays error when the latest request failed', () => {
		let state = reducer(
			undefined,
			actions.setGlobalStylesRecommendations(
				{
					key: 'global_styles:17',
					entityId: '17',
				},
				{
					suggestions: [],
					explanation: '',
				},
				'',
				1,
				'context-a'
			)
		);
		state = reducer(
			state,
			actions.setGlobalStylesStatus( 'error', 'Global styles failed.', 1 )
		);

		expect( selectors.getGlobalStylesInteractionState( state ) ).toBe(
			'error'
		);

		state = reducer(
			state,
			actions.setStyleBookRecommendations(
				{
					key: 'style_book:17:core/paragraph',
					globalStylesId: '17',
					blockName: 'core/paragraph',
					blockTitle: 'Paragraph',
				},
				{
					suggestions: [],
					explanation: '',
				},
				'',
				2,
				'context-b'
			)
		);
		state = reducer(
			state,
			actions.setStyleBookStatus( 'error', 'Style Book failed.', 2 )
		);

		expect( selectors.getStyleBookInteractionState( state ) ).toBe(
			'error'
		);
	} );

	test( 'normalized block interaction state exposes advisory-ready and inline-apply semantics', () => {
		let state = reducer(
			undefined,
			actions.setBlockRequestState( 'block-a', 'loading', null, 1 )
		);

		expect( selectors.getBlockInteractionState( state, 'block-a' ) ).toBe(
			'loading'
		);

		state = reducer(
			state,
			actions.setBlockRecommendations(
				'block-a',
				{
					block: [ { label: 'Refresh hierarchy' } ],
				},
				1
			)
		);
		state = reducer(
			state,
			actions.setBlockRequestState( 'block-a', 'ready', null, 1 )
		);

		expect( selectors.getBlockInteractionState( state, 'block-a' ) ).toBe(
			'advisory-ready'
		);
		expect(
			selectors.getSurfaceInteractionContract( state, 'block' )
		).toEqual(
			expect.objectContaining( {
				allowsInlineApply: true,
				previewRequired: false,
			} )
		);
		expect(
			selectors.isSurfaceApplyAllowed( state, 'block', {
				hasResult: true,
			} )
		).toBe( true );
		expect(
			selectors.getBlockInteractionState( state, 'block-a', {
				hasSuccess: true,
			} )
		).toBe( 'success' );
		expect(
			selectors.getBlockInteractionState( state, 'block-a', {
				undoStatus: 'success',
			} )
		).toBe( 'advisory-ready' );
	} );

	test( 'dismissing an inline block apply failure preserves the last ready recommendation set', () => {
		let state = reducer(
			undefined,
			actions.setBlockRecommendations(
				'block-a',
				{
					block: [ { label: 'Refresh hierarchy' } ],
				},
				3,
				'block-context'
			)
		);

		state = reducer(
			state,
			actions.setBlockRequestState( 'block-a', 'ready', null, 3 )
		);
		state = reducer(
			state,
			actions.setBlockRequestState(
				'block-a',
				'ready',
				'This suggestion could not be applied safely.',
				3
			)
		);

		expect( selectors.getBlockStatus( state, 'block-a' ) ).toBe( 'ready' );
		expect( selectors.getBlockError( state, 'block-a' ) ).toBe(
			'This suggestion could not be applied safely.'
		);
		expect( selectors.getBlockInteractionState( state, 'block-a' ) ).toBe(
			'error'
		);

		state = reducer( state, actions.clearBlockError( 'block-a' ) );

		expect( selectors.getBlockStatus( state, 'block-a' ) ).toBe( 'ready' );
		expect( selectors.getBlockError( state, 'block-a' ) ).toBeNull();
		expect( selectors.getBlockRecommendations( state, 'block-a' ) ).toEqual(
			{
				block: [ { label: 'Refresh hierarchy' } ],
			}
		);
		expect( selectors.getBlockInteractionState( state, 'block-a' ) ).toBe(
			'advisory-ready'
		);
	} );
} );
