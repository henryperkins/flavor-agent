jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '../../utils/template-actions', () => ( {
	applyTemplateSuggestionOperations: jest.fn(),
	getTemplateActivityUndoState: jest.fn( ( activity ) => activity?.undo || {} ),
	undoTemplateSuggestionOperations: jest.fn(),
} ) );

import apiFetch from '@wordpress/api-fetch';

import {
	applyTemplateSuggestionOperations,
	getTemplateActivityUndoState,
	undoTemplateSuggestionOperations,
} from '../../utils/template-actions';
import { actions } from '../index';

describe( 'store action thunks', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		actions._patternAbort = null;
		actions._templateAbort = null;
		getTemplateActivityUndoState.mockImplementation(
			( activity ) => activity?.undo || {}
		);
	} );

	test( 'fetchBlockRecommendations reads request state from thunk selectors', async () => {
		apiFetch.mockResolvedValue( {
			payload: {
				settings: [],
				styles: [],
				block: [],
				explanation: 'Mocked response',
			},
		} );

		const dispatch = jest.fn();
		const select = {
			getBlockRequestToken: jest.fn().mockReturnValue( 2 ),
		};
		const context = {
			block: {
				name: 'core/paragraph',
			},
		};

		await actions.fetchBlockRecommendations(
			'block-1',
			context,
			'Tighten this copy.'
		)( {
			dispatch,
			select,
		} );

		expect( select.getBlockRequestToken ).toHaveBeenCalledWith( 'block-1' );
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-block',
				method: 'POST',
				data: {
					editorContext: context,
					prompt: 'Tighten this copy.',
					clientId: 'block-1',
				},
			} )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setBlockRequestState( 'block-1', 'loading', null, 3 )
		);
		expect( dispatch.mock.calls[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( {
				type: 'SET_BLOCK_RECS',
				clientId: 'block-1',
				requestToken: 3,
				recommendations: expect.objectContaining( {
					blockName: 'core/paragraph',
					blockContext: context.block,
					explanation: 'Mocked response',
				} ),
			} )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			3,
			actions.setBlockRequestState( 'block-1', 'ready', null, 3 )
		);
	} );

	test( 'fetchTemplateRecommendations reads request token from thunk selectors', async () => {
		apiFetch.mockResolvedValue( {
			suggestions: [ { label: 'Refresh template hierarchy' } ],
			explanation: 'Mocked template response',
		} );

		const dispatch = jest.fn();
		const select = {
			getTemplateRequestToken: jest.fn().mockReturnValue( 4 ),
		};
		const input = {
			templateRef: 'theme//home',
			prompt: 'Tighten the structure.',
			templateType: 'home',
		};

		await actions.fetchTemplateRecommendations( input )( {
			dispatch,
			select,
		} );

		expect( select.getTemplateRequestToken ).toHaveBeenCalled();
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/recommend-template',
				method: 'POST',
				data: input,
			} )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setTemplateStatus( 'loading', null, 5 )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			2,
			actions.setTemplateRecommendations(
				'theme//home',
				{
					suggestions: [ { label: 'Refresh template hierarchy' } ],
					explanation: 'Mocked template response',
				},
				'Tighten the structure.',
				5
			)
		);
	} );

	test( 'applySuggestion uses registry-backed block-editor access inside thunks', async () => {
		const updateBlockAttributes = jest.fn();
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getBlockRecommendations: jest.fn().mockReturnValue( {
				blockContext: { name: 'core/paragraph' },
				prompt: 'Tighten the copy.',
			} ),
			getBlockRequestToken: jest.fn().mockReturnValue( 4 ),
		};
		const registry = {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/block-editor'
					? {
							getBlocks: jest.fn().mockReturnValue( [
								{
									clientId: 'block-1',
									name: 'core/paragraph',
									attributes: {
										content: 'Old copy',
									},
								},
							] ),
							getBlockAttributes: jest.fn().mockReturnValue( {
								content: 'Old copy',
							} ),
					  }
					: {}
			),
			dispatch: jest.fn().mockReturnValue( {
				updateBlockAttributes,
			} ),
		};

		const result = await actions.applySuggestion( 'block-1', {
			label: 'Refresh content',
			attributeUpdates: {
				content: 'New copy',
			},
		} )( {
			dispatch,
			registry,
			select,
		} );

		expect( select.getBlockRecommendations ).toHaveBeenCalledWith(
			'block-1'
		);
		expect( registry.select ).toHaveBeenCalledWith( 'core/block-editor' );
		expect( registry.dispatch ).toHaveBeenCalledWith( 'core/block-editor' );
		expect( updateBlockAttributes ).toHaveBeenCalledWith( 'block-1', {
			content: 'New copy',
		} );
		expect( dispatch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'LOG_ACTIVITY',
				entry: expect.objectContaining( {
					type: 'apply_suggestion',
					target: expect.objectContaining( {
						clientId: 'block-1',
						blockPath: [ 0 ],
					} ),
					request: expect.objectContaining( {
						prompt: 'Tighten the copy.',
						reference: 'block:block-1:4',
					} ),
					suggestion: 'Refresh content',
				} ),
			} )
		);
		expect( result ).toBe( true );
	} );

	test( 'applyTemplateSuggestion records success with thunk selector methods', async () => {
		applyTemplateSuggestionOperations.mockReturnValue( {
			ok: true,
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
				},
			],
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getTemplateRequestPrompt: jest
				.fn()
				.mockReturnValue( 'Make the layout more editorial.' ),
			getTemplateResultRef: jest.fn().mockReturnValue( 'theme//home' ),
			getTemplateResultToken: jest.fn().mockReturnValue( 3 ),
		};
		const suggestion = {
			label: 'Clarify template hierarchy',
			suggestionKey: 'Clarify template hierarchy-0',
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
				},
			],
		};

		const result = await actions.applyTemplateSuggestion( suggestion )( {
			dispatch,
			registry: null,
			select,
		} );

		expect( select.getTemplateResultRef ).toHaveBeenCalled();
		expect( dispatch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'LOG_ACTIVITY',
				entry: expect.objectContaining( {
					type: 'apply_template_suggestion',
					target: expect.objectContaining( {
						templateRef: 'theme//home',
					} ),
					request: expect.objectContaining( {
						prompt: 'Make the layout more editorial.',
						reference: 'template:theme//home:3',
					} ),
					suggestion: 'Clarify template hierarchy',
					suggestionKey: 'Clarify template hierarchy-0',
				} ),
			} )
		);
		expect( dispatch ).toHaveBeenLastCalledWith(
			actions.setTemplateApplyState(
				'success',
				null,
				'Clarify template hierarchy-0',
				[
					{
						type: 'insert_pattern',
						patternName: 'theme/hero',
					},
				]
			)
		);
		expect( result ).toEqual( {
			ok: true,
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
				},
			],
		} );
	} );

	test( 'applyTemplateSuggestion surfaces executor validation errors without logging activity', async () => {
		applyTemplateSuggestionOperations.mockReturnValue( {
			ok: false,
			error: 'This suggestion targets the “header” area more than once and cannot be applied automatically.',
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( null ),
			getTemplateRequestPrompt: jest.fn().mockReturnValue( '' ),
			getTemplateResultRef: jest.fn().mockReturnValue( 'theme//home' ),
			getTemplateResultToken: jest.fn().mockReturnValue( 3 ),
		};

		const result = await actions.applyTemplateSuggestion( {
			label: 'Conflicting suggestion',
			operations: [],
		} )( {
			dispatch,
			registry: null,
			select,
		} );

		expect( dispatch ).toHaveBeenNthCalledWith(
			1,
			actions.setTemplateApplyState( 'applying' )
		);
		expect( dispatch ).toHaveBeenNthCalledWith(
			2,
			actions.setTemplateApplyState(
				'error',
				'This suggestion targets the “header” area more than once and cannot be applied automatically.'
			)
		);
		expect(
			dispatch.mock.calls.some(
				( [ action ] ) => action?.type === 'LOG_ACTIVITY'
			)
		).toBe( false );
		expect( result ).toEqual( {
			ok: false,
			error: 'This suggestion targets the “header” area more than once and cannot be applied automatically.',
		} );
	} );

	test( 'undoActivity restores the latest block suggestion and marks it undone', async () => {
		const updateBlockAttributes = jest.fn();
		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest.fn().mockReturnValue( 'post:42' ),
			getActivityLog: jest.fn().mockReturnValue( [
				{
					id: 'activity-1',
					type: 'apply_suggestion',
					surface: 'block',
					target: {
						clientId: 'block-1',
					},
					before: {
						attributes: {
							content: 'Old copy',
						},
					},
					after: {
						attributes: {
							content: 'New copy',
							className: 'is-style-contrast',
						},
					},
					undo: {
						canUndo: true,
						status: 'available',
					},
				},
			] ),
			getLatestAppliedActivity: jest.fn().mockReturnValue( {
				id: 'activity-1',
				type: 'apply_suggestion',
				surface: 'block',
				target: {
					clientId: 'block-1',
				},
				before: {
					attributes: {
						content: 'Old copy',
					},
				},
				after: {
					attributes: {
						content: 'New copy',
						className: 'is-style-contrast',
					},
				},
				undo: {
					canUndo: true,
					status: 'available',
				},
			} ),
		};
		const registry = {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/block-editor'
					? {
							getBlock: jest.fn().mockReturnValue( {
								clientId: 'block-1',
								name: 'core/paragraph',
								attributes: {
									content: 'New copy',
									className: 'is-style-contrast',
								},
							} ),
							getBlockAttributes: jest.fn().mockReturnValue( {
								content: 'New copy',
								className: 'is-style-contrast',
							} ),
					  }
					: {}
			),
			dispatch: jest.fn().mockReturnValue( {
				updateBlockAttributes,
			} ),
		};

		const result = await actions.undoActivity( 'activity-1' )( {
			dispatch,
			registry,
			select,
		} );

		expect( updateBlockAttributes ).toHaveBeenCalledWith( 'block-1', {
			content: 'Old copy',
			className: undefined,
		} );
		expect( dispatch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'UPDATE_ACTIVITY_UNDO_STATE',
				activityId: 'activity-1',
				status: 'undone',
			} )
		);
		expect( result ).toEqual( { ok: true } );
	} );

	test( 'undoActivity delegates template rollback to template-actions helpers', async () => {
		undoTemplateSuggestionOperations.mockReturnValue( {
			ok: true,
			operations: [],
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest
				.fn()
				.mockReturnValue( 'wp_template:home' ),
			getActivityLog: jest.fn().mockReturnValue( [
				{
					id: 'activity-1',
					type: 'apply_template_suggestion',
					surface: 'template',
					target: {
						templateRef: 'theme//home',
					},
					undo: {
						canUndo: true,
						status: 'available',
					},
				},
			] ),
			getLatestAppliedActivity: jest.fn().mockReturnValue( {
				id: 'activity-1',
				type: 'apply_template_suggestion',
				surface: 'template',
				target: {
					templateRef: 'theme//home',
				},
				undo: {
					canUndo: true,
					status: 'available',
				},
			} ),
		};

		await actions.undoActivity( 'activity-1' )( {
			dispatch,
			registry: null,
			select,
		} );

		expect( undoTemplateSuggestionOperations ).toHaveBeenCalledWith(
			expect.objectContaining( {
				id: 'activity-1',
				surface: 'template',
			} )
		);
		expect( dispatch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'SET_UNDO_STATE',
				status: 'success',
				activityId: 'activity-1',
			} )
		);
	} );

	test( 'undoActivity marks template actions failed when dynamic undo resolution rejects them', async () => {
		getTemplateActivityUndoState.mockReturnValue( {
			canUndo: false,
			status: 'failed',
			error: 'Inserted pattern content changed after apply and cannot be undone automatically.',
		} );

		const dispatch = jest.fn();
		const select = {
			getActivityScopeKey: jest
				.fn()
				.mockReturnValue( 'wp_template:home' ),
			getActivityLog: jest.fn().mockReturnValue( [
				{
					id: 'activity-1',
					type: 'apply_template_suggestion',
					surface: 'template',
					target: {
						templateRef: 'theme//home',
					},
					undo: {
						canUndo: true,
						status: 'available',
					},
				},
			] ),
			getLatestAppliedActivity: jest.fn().mockReturnValue( {
				id: 'activity-1',
				type: 'apply_template_suggestion',
				surface: 'template',
				target: {
					templateRef: 'theme//home',
				},
				undo: {
					canUndo: true,
					status: 'available',
				},
			} ),
		};

		const result = await actions.undoActivity( 'activity-1' )( {
			dispatch,
			registry: null,
			select,
		} );

		expect( undoTemplateSuggestionOperations ).not.toHaveBeenCalled();
		expect( dispatch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'UPDATE_ACTIVITY_UNDO_STATE',
				activityId: 'activity-1',
				status: 'failed',
			} )
		);
		expect( result ).toEqual( {
			ok: false,
			error: 'Inserted pattern content changed after apply and cannot be undone automatically.',
		} );
	} );
} );
