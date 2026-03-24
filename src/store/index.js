/**
 * Flavor Agent data store.
 *
 * Per-block, per-tab recommendation state. Each recommendation set
 * contains suggestions scoped to Settings, Styles, and Block tabs
 * so Inspector injection components render in the right place.
 */
import apiFetch from '@wordpress/api-fetch';
import { createReduxStore, register } from '@wordpress/data';

import { getPatternBadgeReason } from '../patterns/recommendation-utils';
import {
	applyTemplatePartSuggestionOperations,
	applyTemplateSuggestionOperations,
	getTemplateActivityUndoState,
	getTemplatePartActivityUndoState,
	undoTemplatePartSuggestionOperations,
	undoTemplateSuggestionOperations,
} from '../utils/template-actions';
import {
	createActivityEntry,
	getCurrentActivityScope,
	getLatestAppliedActivity,
	getLatestUndoableActivity,
	limitActivityLog,
	readPersistedActivityLog,
	writePersistedActivityLog,
} from './activity-history';
import {
	attributeSnapshotsMatch,
	buildSafeAttributeUpdates,
	buildUndoAttributeUpdates,
	getSuggestionAttributeUpdates,
	sanitizeRecommendationsForContext,
} from './update-helpers';

const STORE_NAME = 'flavor-agent';
const DEFAULT_BLOCK_REQUEST_STATE = {
	status: 'idle',
	error: null,
	requestToken: 0,
};

const DEFAULT_STATE = {
	blockRecommendations: {},
	blockRequestState: {},
	activityScopeKey: null,
	activityLog: [],
	undoStatus: 'idle',
	undoError: null,
	lastUndoneActivityId: null,
	patternRecommendations: [],
	patternStatus: 'idle',
	patternError: null,
	patternBadge: null,
	templateRecommendations: [],
	templateExplanation: '',
	templateStatus: 'idle',
	templateError: null,
	templateRequestPrompt: '',
	templateRef: null,
	templateRequestToken: 0,
	templateResultToken: 0,
	templateSelectedSuggestionKey: null,
	templateApplyStatus: 'idle',
	templateApplyError: null,
	templateLastAppliedSuggestionKey: null,
	templateLastAppliedOperations: [],
	templatePartRecommendations: [],
	templatePartExplanation: '',
	templatePartStatus: 'idle',
	templatePartError: null,
	templatePartRequestPrompt: '',
	templatePartRef: null,
	templatePartRequestToken: 0,
	templatePartResultToken: 0,
	templatePartSelectedSuggestionKey: null,
	templatePartApplyStatus: 'idle',
	templatePartApplyError: null,
	templatePartLastAppliedSuggestionKey: null,
	templatePartLastAppliedOperations: [],
};

function getStoredBlockRequestState( state, clientId ) {
	return state.blockRequestState[ clientId ] || DEFAULT_BLOCK_REQUEST_STATE;
}

function isStaleBlockRequest( state, clientId, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return (
		requestToken <
		getStoredBlockRequestState( state, clientId ).requestToken
	);
}

function isStaleTemplateRequest( state, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state.templateRequestToken || 0 );
}

function isStaleTemplatePartRequest( state, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state.templatePartRequestToken || 0 );
}

function buildActivityDocument( scope ) {
	if ( ! scope?.key ) {
		return null;
	}

	return {
		scopeKey: scope.key,
		postType: scope.postType,
		entityId: scope.entityId,
	};
}

function alignActivityEntriesToScope( entries, scope ) {
	const document = buildActivityDocument( scope );

	if ( ! document ) {
		return limitActivityLog( entries );
	}

	return limitActivityLog( entries ).map( ( entry ) =>
		entry
			? {
					...entry,
					document,
			  }
			: entry
	);
}

function syncActivitySession( localDispatch, select, scope ) {
	const currentScopeKey = select.getActivityScopeKey?.() || null;
	const nextScopeKey = scope?.key || null;

	if ( currentScopeKey === nextScopeKey ) {
		return;
	}

	const currentEntries = select.getActivityLog?.() || [];

	if (
		currentScopeKey === null &&
		nextScopeKey &&
		currentEntries.length > 0
	) {
		const reassignedEntries = alignActivityEntriesToScope(
			currentEntries,
			scope
		);

		localDispatch(
			actions.setActivitySession( nextScopeKey, reassignedEntries )
		);
		writePersistedActivityLog( nextScopeKey, reassignedEntries );

		return;
	}

	localDispatch(
		actions.setActivitySession(
			nextScopeKey,
			nextScopeKey ? readPersistedActivityLog( nextScopeKey ) : []
		)
	);
}

function persistActivitySession( select ) {
	const scopeKey = select.getActivityScopeKey?.() || null;

	if ( ! scopeKey ) {
		return;
	}

	writePersistedActivityLog( scopeKey, select.getActivityLog?.() || [] );
}

function findBlockPath( blocks, clientId, path = [] ) {
	for ( let index = 0; index < blocks.length; index++ ) {
		const block = blocks[ index ];
		const nextPath = [ ...path, index ];

		if ( block?.clientId === clientId ) {
			return nextPath;
		}

		if ( Array.isArray( block?.innerBlocks ) && block.innerBlocks.length ) {
			const nestedPath = findBlockPath(
				block.innerBlocks,
				clientId,
				nextPath
			);

			if ( nestedPath ) {
				return nestedPath;
			}
		}
	}

	return null;
}

function getBlockByPath( blocks, path = [] ) {
	let currentBlocks = blocks;
	let block = null;

	for ( const index of path ) {
		if ( ! Array.isArray( currentBlocks ) ) {
			return null;
		}

		block = currentBlocks[ index ] || null;

		if ( ! block ) {
			return null;
		}

		currentBlocks = block.innerBlocks || [];
	}

	return block;
}

function resolveActivityBlock( blockEditorSelect, target = {} ) {
	if ( target.clientId ) {
		const directBlock = blockEditorSelect.getBlock?.( target.clientId );

		if ( directBlock ) {
			return directBlock;
		}
	}

	return Array.isArray( target.blockPath )
		? getBlockByPath(
				blockEditorSelect.getBlocks?.() || [],
				target.blockPath
		  )
		: null;
}

function buildBlockActivityEntry( {
	afterAttributes,
	beforeAttributes,
	blockContext,
	blockPath = null,
	clientId,
	requestPrompt = '',
	requestToken = 0,
	scope = null,
	suggestion,
} ) {
	return createActivityEntry( {
		type: 'apply_suggestion',
		surface: 'block',
		target: {
			clientId,
			blockName: blockContext?.name || '',
			blockPath: Array.isArray( blockPath ) ? blockPath : [],
		},
		suggestion: suggestion?.label || '',
		suggestionKey: suggestion?.suggestionKey || null,
		before: {
			attributes: beforeAttributes,
		},
		after: {
			attributes: afterAttributes,
		},
		prompt: requestPrompt,
		requestRef: `block:${ clientId }:${ requestToken }`,
		document: buildActivityDocument( scope ),
	} );
}

function buildDocumentOperationBeforeState( operations = [] ) {
	return operations.map( ( operation ) => {
		switch ( operation?.type ) {
			case 'assign_template_part':
			case 'replace_template_part':
				return {
					type: operation.type,
					area:
						operation?.undoLocator?.area ||
						operation?.area ||
						'',
					expectedSlug:
						operation?.undoLocator?.expectedSlug ||
						operation?.nextAttributes?.slug ||
						operation?.slug ||
						'',
					previousAttributes: operation.previousAttributes || null,
				};

			case 'insert_pattern':
				return {
					type: operation.type,
					patternName: operation.patternName || '',
					patternTitle: operation.patternTitle || '',
					placement: operation.placement || '',
					rootLocator: operation.rootLocator || null,
					index:
						Number.isInteger( operation.index )
							? operation.index
							: null,
				};

			default:
				return {
					type: operation?.type || 'unknown',
				};
		}
	} );
}

function buildTemplateActivityEntry( {
	operations,
	requestPrompt = '',
	requestToken = 0,
	scope = null,
	suggestion,
	templateRef,
} ) {
	return createActivityEntry( {
		type: 'apply_template_suggestion',
		surface: 'template',
		target: {
			templateRef,
		},
		suggestion: suggestion?.label || '',
		suggestionKey: suggestion?.suggestionKey || null,
		before: {
			operations: buildDocumentOperationBeforeState( operations ),
		},
		after: { operations },
		prompt: requestPrompt,
		requestRef: `template:${ templateRef || 'unknown' }:${ requestToken }`,
		document: buildActivityDocument( scope ),
	} );
}

function buildTemplatePartActivityEntry( {
	operations,
	requestPrompt = '',
	requestToken = 0,
	scope = null,
	suggestion,
	templatePartRef,
} ) {
	return createActivityEntry( {
		type: 'apply_template_part_suggestion',
		surface: 'template-part',
		target: {
			templatePartRef,
		},
		suggestion: suggestion?.label || '',
		suggestionKey: suggestion?.suggestionKey || null,
		before: {
			operations: buildDocumentOperationBeforeState( operations ),
		},
		after: { operations },
		prompt: requestPrompt,
		requestRef: `template-part:${ templatePartRef || 'unknown' }:${ requestToken }`,
		document: buildActivityDocument( scope ),
	} );
}

function undoBlockActivity( activity, registry ) {
	const target = activity?.target || {};
	const blockEditorSelect = registry?.select?.( 'core/block-editor' ) || {};
	const blockEditorDispatch =
		registry?.dispatch?.( 'core/block-editor' ) || {};
	const resolvedBlock = resolveActivityBlock( blockEditorSelect, target );

	if ( ! resolvedBlock?.clientId ) {
		return {
			ok: false,
			error: 'The original block target for this AI action is missing.',
		};
	}

	const currentAttributes =
		blockEditorSelect.getBlockAttributes?.( resolvedBlock.clientId ) ||
		resolvedBlock.attributes ||
		null;
	const beforeAttributes = activity?.before?.attributes || {};
	const afterAttributes = activity?.after?.attributes || {};

	if ( target.blockName && resolvedBlock.name !== target.blockName ) {
		return {
			ok: false,
			error: 'The target block changed position or type and cannot be undone automatically.',
		};
	}

	if ( ! currentAttributes ) {
		return {
			ok: false,
			error: 'The target block is no longer available to undo.',
		};
	}

	if ( ! attributeSnapshotsMatch( afterAttributes, currentAttributes ) ) {
		return {
			ok: false,
			error: 'The target block changed after Flavor Agent applied this suggestion and cannot be undone automatically.',
		};
	}

	if ( typeof blockEditorDispatch.updateBlockAttributes !== 'function' ) {
		return {
			ok: false,
			error: 'The block editor could not restore the previous block attributes.',
		};
	}

	blockEditorDispatch.updateBlockAttributes(
		resolvedBlock.clientId,
		buildUndoAttributeUpdates( beforeAttributes, afterAttributes )
	);

	return { ok: true };
}

function getNextLastUndoneActivityId( currentValue, action ) {
	if ( action.status === 'success' ) {
		return action.activityId ?? null;
	}

	if ( action.status === 'idle' ) {
		return null;
	}

	return currentValue;
}

const actions = {
	setBlockRequestState(
		clientId,
		status,
		error = null,
		requestToken = null
	) {
		return {
			type: 'SET_BLOCK_REQUEST_STATE',
			clientId,
			status,
			error,
			requestToken,
		};
	},

	setBlockRecommendations( clientId, recommendations, requestToken = null ) {
		return {
			type: 'SET_BLOCK_RECS',
			clientId,
			recommendations,
			requestToken,
		};
	},

	clearBlockRecommendations( clientId ) {
		return { type: 'CLEAR_BLOCK_RECS', clientId };
	},

	clearBlockError( clientId ) {
		return { type: 'CLEAR_BLOCK_ERROR', clientId };
	},

	setActivitySession( scopeKey = null, entries = [] ) {
		return {
			type: 'SET_ACTIVITY_SESSION',
			scopeKey,
			entries,
		};
	},

	logActivity( entry ) {
		return { type: 'LOG_ACTIVITY', entry };
	},

	setUndoState( status, error = null, activityId = null ) {
		return {
			type: 'SET_UNDO_STATE',
			status,
			error,
			activityId,
		};
	},

	updateActivityUndoState(
		activityId,
		status,
		error = null,
		timestamp = new Date().toISOString()
	) {
		return {
			type: 'UPDATE_ACTIVITY_UNDO_STATE',
			activityId,
			status,
			error,
			timestamp,
		};
	},

	clearUndoError() {
		return { type: 'CLEAR_UNDO_ERROR' };
	},

	loadActivitySession() {
		return async ( { dispatch, registry, select } ) => {
			const scope = getCurrentActivityScope( registry );

			syncActivitySession( dispatch, select, scope );
		};
	},

	fetchBlockRecommendations( clientId, context, prompt = '' ) {
		return async ( { dispatch, select } ) => {
			const requestToken = select.getBlockRequestToken( clientId ) + 1;

			dispatch(
				actions.setBlockRequestState(
					clientId,
					'loading',
					null,
					requestToken
				)
			);

			try {
				const result = await apiFetch( {
					path: '/flavor-agent/v1/recommend-block',
					method: 'POST',
					data: { editorContext: context, prompt, clientId },
				} );

				dispatch(
					actions.setBlockRecommendations(
						clientId,
						{
							blockName: context.block?.name || '',
							blockContext: context.block || {},
							prompt,
							...sanitizeRecommendationsForContext(
								result.payload || {},
								context.block || {}
							),
							timestamp: Date.now(),
						},
						requestToken
					)
				);
				dispatch(
					actions.setBlockRequestState(
						clientId,
						'ready',
						null,
						requestToken
					)
				);
			} catch ( err ) {
				dispatch(
					actions.setBlockRequestState(
						clientId,
						'error',
						err.message || 'Request failed.',
						requestToken
					)
				);
			}
		};
	},

	applySuggestion( clientId, suggestion ) {
		return async ( { dispatch: localDispatch, registry, select } ) => {
			const scope = getCurrentActivityScope( registry );

			syncActivitySession( localDispatch, select, scope );

			const storedRecommendations =
				select.getBlockRecommendations( clientId ) || {};
			const blockContext = storedRecommendations.blockContext || {};
			const blockEditorSelect =
				registry?.select?.( 'core/block-editor' ) || {};
			const blockEditorDispatch =
				registry?.dispatch?.( 'core/block-editor' ) || {};
			const currentAttributes =
				blockEditorSelect.getBlockAttributes?.( clientId ) || {};
			const allowedUpdates = getSuggestionAttributeUpdates(
				suggestion,
				blockContext
			);
			let nextAttributes = null;
			let didApply = false;

			if ( Object.keys( allowedUpdates ).length > 0 ) {
				const safeUpdates = buildSafeAttributeUpdates(
					currentAttributes,
					allowedUpdates
				);

				if (
					Object.keys( safeUpdates ).length > 0 &&
					typeof blockEditorDispatch.updateBlockAttributes ===
						'function'
				) {
					nextAttributes = {
						...currentAttributes,
						...safeUpdates,
					};
					blockEditorDispatch.updateBlockAttributes(
						clientId,
						safeUpdates
					);
					didApply = true;
				}
			}

			if ( ! didApply ) {
				return false;
			}

			localDispatch(
				actions.logActivity(
					buildBlockActivityEntry( {
						afterAttributes: nextAttributes || currentAttributes,
						beforeAttributes: currentAttributes,
						blockContext,
						blockPath: findBlockPath(
							blockEditorSelect.getBlocks?.() || [],
							clientId
						),
						clientId,
						requestPrompt: storedRecommendations.prompt || '',
						requestToken:
							select.getBlockRequestToken( clientId ) || 0,
						scope,
						suggestion,
					} )
				)
			);
			persistActivitySession( select );

			return true;
		};
	},

	setPatternStatus( status, error = null ) {
		return { type: 'SET_PATTERN_STATUS', status, error };
	},

	setPatternRecommendations( recommendations ) {
		return { type: 'SET_PATTERN_RECS', recommendations };
	},

	fetchPatternRecommendations( input ) {
		return async ( { dispatch } ) => {
			if ( actions._patternAbort ) {
				actions._patternAbort.abort();
			}

			const controller = new AbortController();
			actions._patternAbort = controller;

			dispatch( actions.setPatternStatus( 'loading' ) );

			try {
				const result = await apiFetch( {
					path: '/flavor-agent/v1/recommend-patterns',
					method: 'POST',
					data: input,
					signal: controller.signal,
				} );

				dispatch(
					actions.setPatternRecommendations(
						result.recommendations || []
					)
				);
				dispatch( actions.setPatternStatus( 'ready' ) );
			} catch ( err ) {
				if ( err.name === 'AbortError' ) {
					return;
				}

				dispatch( actions.setPatternRecommendations( [] ) );
				dispatch(
					actions.setPatternStatus(
						'error',
						err?.message || 'Pattern recommendation request failed.'
					)
				);
			} finally {
				if ( actions._patternAbort === controller ) {
					actions._patternAbort = null;
				}
			}
		};
	},

	setTemplateStatus( status, error = null, requestToken = null ) {
		return { type: 'SET_TEMPLATE_STATUS', status, error, requestToken };
	},

	setTemplateRecommendations(
		templateRef,
		payload,
		prompt = '',
		requestToken = null
	) {
		return {
			type: 'SET_TEMPLATE_RECS',
			templateRef,
			payload,
			prompt,
			requestToken,
		};
	},

	setTemplateSelectedSuggestion( suggestionKey = null ) {
		return {
			type: 'SET_TEMPLATE_SELECTED_SUGGESTION',
			suggestionKey,
		};
	},

	setTemplateApplyState(
		status,
		error = null,
		suggestionKey = null,
		operations = []
	) {
		return {
			type: 'SET_TEMPLATE_APPLY_STATE',
			status,
			error,
			suggestionKey,
			operations,
		};
	},

	setTemplatePartStatus( status, error = null, requestToken = null ) {
		return {
			type: 'SET_TEMPLATE_PART_STATUS',
			status,
			error,
			requestToken,
		};
	},

	setTemplatePartRecommendations(
		templatePartRef,
		payload,
		prompt = '',
		requestToken = null
	) {
		return {
			type: 'SET_TEMPLATE_PART_RECS',
			templatePartRef,
			payload,
			prompt,
			requestToken,
		};
	},

	setTemplatePartSelectedSuggestion( suggestionKey = null ) {
		return {
			type: 'SET_TEMPLATE_PART_SELECTED_SUGGESTION',
			suggestionKey,
		};
	},

	setTemplatePartApplyState(
		status,
		error = null,
		suggestionKey = null,
		operations = []
	) {
		return {
			type: 'SET_TEMPLATE_PART_APPLY_STATE',
			status,
			error,
			suggestionKey,
			operations,
		};
	},

	clearTemplateRecommendations() {
		return ( { dispatch } ) => {
			if ( actions._templateAbort ) {
				actions._templateAbort.abort();
				actions._templateAbort = null;
			}

			dispatch( { type: 'CLEAR_TEMPLATE_RECS' } );
		};
	},

	clearTemplatePartRecommendations() {
		return ( { dispatch } ) => {
			if ( actions._templatePartAbort ) {
				actions._templatePartAbort.abort();
				actions._templatePartAbort = null;
			}

			dispatch( { type: 'CLEAR_TEMPLATE_PART_RECS' } );
		};
	},

	undoActivity( activityId ) {
		return async ( { dispatch: localDispatch, registry, select } ) => {
			const scope = getCurrentActivityScope( registry );

			syncActivitySession( localDispatch, select, scope );

			const latestActivity = select.getLatestAppliedActivity?.();

			if ( ! latestActivity ) {
				localDispatch(
					actions.setUndoState(
						'error',
						'There is no AI action available to undo.'
					)
				);

				return {
					ok: false,
					error: 'There is no AI action available to undo.',
				};
			}

			if ( latestActivity.id !== activityId ) {
				localDispatch(
					actions.setUndoState(
						'error',
						'Only the most recent AI action can be undone automatically.',
						activityId
					)
				);

				return {
					ok: false,
					error: 'Only the most recent AI action can be undone automatically.',
				};
			}

			if ( latestActivity?.undo?.status !== 'available' ) {
				localDispatch(
					actions.setUndoState(
						'error',
						latestActivity?.undo?.error ||
							'This AI action can no longer be undone automatically.',
						activityId
					)
				);

				return {
					ok: false,
					error:
						latestActivity?.undo?.error ||
						'This AI action can no longer be undone automatically.',
				};
			}

			if (
				latestActivity.surface === 'template' ||
				latestActivity.surface === 'template-part'
			) {
				const resolvedUndo =
					latestActivity.surface === 'template'
						? getTemplateActivityUndoState(
								latestActivity,
								registry?.select?.( 'core/block-editor' )
						  )
						: getTemplatePartActivityUndoState(
								latestActivity,
								registry?.select?.( 'core/block-editor' )
						  );

				if (
					resolvedUndo?.canUndo !== true ||
					resolvedUndo?.status !== 'available'
				) {
					localDispatch(
						actions.updateActivityUndoState(
							activityId,
							'failed',
							resolvedUndo?.error ||
								'This AI action can no longer be undone automatically.'
						)
					);
					localDispatch(
						actions.setUndoState(
							'error',
							resolvedUndo?.error ||
								'This AI action can no longer be undone automatically.',
							activityId
						)
					);
					persistActivitySession( select );

					return {
						ok: false,
						error:
							resolvedUndo?.error ||
							'This AI action can no longer be undone automatically.',
					};
				}
			}

			localDispatch(
				actions.setUndoState( 'undoing', null, activityId )
			);

				let result;

				if ( latestActivity.surface === 'template' ) {
					result = undoTemplateSuggestionOperations( latestActivity );
				} else if ( latestActivity.surface === 'template-part' ) {
					result = undoTemplatePartSuggestionOperations(
						latestActivity
					);
				} else {
					result = undoBlockActivity( latestActivity, registry );
				}

			if ( ! result.ok ) {
				localDispatch(
					actions.updateActivityUndoState(
						activityId,
						'failed',
						result.error || 'Undo failed.'
					)
				);
				localDispatch(
					actions.setUndoState(
						'error',
						result.error || 'Undo failed.',
						activityId
					)
				);
				persistActivitySession( select );

				return result;
			}

			localDispatch(
				actions.updateActivityUndoState( activityId, 'undone' )
			);
			localDispatch(
				actions.setUndoState( 'success', null, activityId )
			);
			persistActivitySession( select );

			return result;
		};
	},

	applyTemplateSuggestion( suggestion ) {
		return async ( { dispatch: localDispatch, registry, select } ) => {
			const scope = getCurrentActivityScope( registry );

			syncActivitySession( localDispatch, select, scope );

			localDispatch( actions.setTemplateApplyState( 'applying' ) );

			let result;

			try {
				result = applyTemplateSuggestionOperations( suggestion );
			} catch ( err ) {
				const message =
					err?.message || 'Template apply failed unexpectedly.';

				localDispatch(
					actions.setTemplateApplyState( 'error', message )
				);

				return {
					ok: false,
					error: message,
				};
			}

			if ( ! result.ok ) {
				localDispatch(
					actions.setTemplateApplyState(
						'error',
						result.error || 'Template apply failed.'
					)
				);

				return result;
			}

			const templateRef = select.getTemplateResultRef();

			localDispatch(
				actions.logActivity(
					buildTemplateActivityEntry( {
						operations: result.operations,
						requestPrompt:
							select.getTemplateRequestPrompt?.() || '',
						requestToken: select.getTemplateResultToken?.() || 0,
						scope,
						suggestion,
						templateRef,
					} )
				)
			);
			localDispatch(
				actions.setTemplateApplyState(
					'success',
					null,
					suggestion?.suggestionKey || null,
					result.operations
				)
			);
			persistActivitySession( select );

			return result;
		};
	},

	applyTemplatePartSuggestion( suggestion ) {
		return async ( { dispatch: localDispatch, registry, select } ) => {
			const scope = getCurrentActivityScope( registry );

			syncActivitySession( localDispatch, select, scope );

			localDispatch( actions.setTemplatePartApplyState( 'applying' ) );

			let result;

			try {
				result = applyTemplatePartSuggestionOperations( suggestion );
			} catch ( err ) {
				const message =
					err?.message || 'Template-part apply failed unexpectedly.';

				localDispatch(
					actions.setTemplatePartApplyState( 'error', message )
				);

				return {
					ok: false,
					error: message,
				};
			}

			if ( ! result.ok ) {
				localDispatch(
					actions.setTemplatePartApplyState(
						'error',
						result.error || 'Template-part apply failed.'
					)
				);

				return result;
			}

			const templatePartRef = select.getTemplatePartResultRef();

			localDispatch(
				actions.logActivity(
					buildTemplatePartActivityEntry( {
						operations: result.operations,
						requestPrompt:
							select.getTemplatePartRequestPrompt?.() || '',
						requestToken:
							select.getTemplatePartResultToken?.() || 0,
						scope,
						suggestion,
						templatePartRef,
					} )
				)
			);
			localDispatch(
				actions.setTemplatePartApplyState(
					'success',
					null,
					suggestion?.suggestionKey || null,
					result.operations
				)
			);
			persistActivitySession( select );

			return result;
		};
	},

	fetchTemplateRecommendations( input ) {
		return async ( { dispatch, select } ) => {
			if ( actions._templateAbort ) {
				actions._templateAbort.abort();
			}

			const controller = new AbortController();
			actions._templateAbort = controller;
			const requestToken =
				( select.getTemplateRequestToken?.() || 0 ) + 1;

			dispatch(
				actions.setTemplateStatus( 'loading', null, requestToken )
			);

			try {
				const result = await apiFetch( {
					path: '/flavor-agent/v1/recommend-template',
					method: 'POST',
					data: input,
					signal: controller.signal,
				} );

				dispatch(
					actions.setTemplateRecommendations(
						input.templateRef,
						result,
						input.prompt || '',
						requestToken
					)
				);
			} catch ( err ) {
				if ( err.name === 'AbortError' ) {
					return;
				}

				dispatch(
					actions.setTemplateRecommendations(
						input.templateRef,
						{
							suggestions: [],
							explanation: '',
						},
						input.prompt || '',
						requestToken
					)
				);
				dispatch(
					actions.setTemplateStatus(
						'error',
						err?.message ||
							'Template recommendation request failed.',
						requestToken
					)
				);
			} finally {
				if ( actions._templateAbort === controller ) {
					actions._templateAbort = null;
				}
			}
		};
	},

	fetchTemplatePartRecommendations( input ) {
		return async ( { dispatch, select } ) => {
			if ( actions._templatePartAbort ) {
				actions._templatePartAbort.abort();
			}

			const controller = new AbortController();
			actions._templatePartAbort = controller;
			const requestToken =
				( select.getTemplatePartRequestToken?.() || 0 ) + 1;

			dispatch(
				actions.setTemplatePartStatus( 'loading', null, requestToken )
			);

			try {
				const result = await apiFetch( {
					path: '/flavor-agent/v1/recommend-template-part',
					method: 'POST',
					data: input,
					signal: controller.signal,
				} );

				dispatch(
					actions.setTemplatePartRecommendations(
						input.templatePartRef,
						result,
						input.prompt || '',
						requestToken
					)
				);
			} catch ( err ) {
				if ( err.name === 'AbortError' ) {
					return;
				}

				dispatch(
					actions.setTemplatePartRecommendations(
						input.templatePartRef,
						{
							suggestions: [],
							explanation: '',
						},
						input.prompt || '',
						requestToken
					)
				);
				dispatch(
					actions.setTemplatePartStatus(
						'error',
						err?.message ||
							'Template-part recommendation request failed.',
						requestToken
					)
				);
			} finally {
				if ( actions._templatePartAbort === controller ) {
					actions._templatePartAbort = null;
				}
			}
		};
	},
};

function reducer( state = DEFAULT_STATE, action ) {
	switch ( action.type ) {
		case 'SET_BLOCK_REQUEST_STATE': {
			if (
				isStaleBlockRequest(
					state,
					action.clientId,
					action.requestToken
				)
			) {
				return state;
			}

			const currentEntry = getStoredBlockRequestState(
				state,
				action.clientId
			);

			return {
				...state,
				blockRequestState: {
					...state.blockRequestState,
					[ action.clientId ]: {
						...currentEntry,
						status: action.status,
						error: action.error ?? null,
						requestToken:
							action.requestToken ?? currentEntry.requestToken,
					},
				},
			};
		}
		case 'SET_BLOCK_RECS':
			if (
				isStaleBlockRequest(
					state,
					action.clientId,
					action.requestToken
				)
			) {
				return state;
			}

			return {
				...state,
				blockRecommendations: {
					...state.blockRecommendations,
					[ action.clientId ]: action.recommendations,
				},
			};
		case 'CLEAR_BLOCK_RECS': {
			const nextRecommendations = { ...state.blockRecommendations };
			const nextRequestState = { ...state.blockRequestState };

			delete nextRecommendations[ action.clientId ];
			delete nextRequestState[ action.clientId ];

			return {
				...state,
				blockRecommendations: nextRecommendations,
				blockRequestState: nextRequestState,
			};
		}
		case 'CLEAR_BLOCK_ERROR': {
			if ( ! state.blockRequestState[ action.clientId ] ) {
				return state;
			}

			const currentEntry = getStoredBlockRequestState(
				state,
				action.clientId
			);

			return {
				...state,
				blockRequestState: {
					...state.blockRequestState,
					[ action.clientId ]: {
						...currentEntry,
						status:
							currentEntry.status === 'error'
								? 'idle'
								: currentEntry.status,
						error: null,
					},
				},
			};
		}
		case 'SET_ACTIVITY_SESSION':
			return {
				...state,
				activityScopeKey: action.scopeKey ?? null,
				activityLog: limitActivityLog( action.entries ),
				undoStatus: 'idle',
				undoError: null,
				lastUndoneActivityId: null,
			};
		case 'LOG_ACTIVITY':
			return {
				...state,
				activityLog: limitActivityLog( [
					...state.activityLog,
					action.entry,
				] ),
				undoStatus: 'idle',
				undoError: null,
				lastUndoneActivityId: null,
			};
		case 'SET_UNDO_STATE':
			return {
				...state,
				undoStatus: action.status,
				undoError: action.error ?? null,
				lastUndoneActivityId: getNextLastUndoneActivityId(
					state.lastUndoneActivityId,
					action
				),
			};
		case 'CLEAR_UNDO_ERROR':
			return {
				...state,
				undoStatus:
					state.undoStatus === 'error' ? 'idle' : state.undoStatus,
				undoError: null,
			};
		case 'UPDATE_ACTIVITY_UNDO_STATE': {
			const matchedEntry =
				state.activityLog.find(
					( entry ) => entry?.id === action.activityId
				) || null;
			const isTemplateUndone =
				action.status === 'undone' &&
				matchedEntry?.surface === 'template';
			const isTemplatePartUndone =
				action.status === 'undone' &&
				matchedEntry?.surface === 'template-part';

			return {
				...state,
				activityLog: state.activityLog.map( ( entry ) => {
					if ( entry?.id !== action.activityId ) {
						return entry;
					}

					return {
						...entry,
						undo: {
							...entry.undo,
							status: action.status,
							error: action.error ?? null,
							updatedAt: action.timestamp,
							undoneAt:
								action.status === 'undone'
									? action.timestamp
									: entry.undo?.undoneAt || null,
						},
					};
				} ),
				templateApplyStatus:
					isTemplateUndone
						? 'idle'
						: state.templateApplyStatus,
				templateApplyError:
					isTemplateUndone
						? null
						: state.templateApplyError,
				templateLastAppliedSuggestionKey:
					isTemplateUndone
						? null
						: state.templateLastAppliedSuggestionKey,
				templateLastAppliedOperations:
					isTemplateUndone
						? []
						: state.templateLastAppliedOperations,
				templatePartApplyStatus:
					isTemplatePartUndone
						? 'idle'
						: state.templatePartApplyStatus,
				templatePartApplyError:
					isTemplatePartUndone
						? null
						: state.templatePartApplyError,
				templatePartLastAppliedSuggestionKey:
					isTemplatePartUndone
						? null
						: state.templatePartLastAppliedSuggestionKey,
				templatePartLastAppliedOperations:
					isTemplatePartUndone
						? []
						: state.templatePartLastAppliedOperations,
			};
		}
		case 'SET_PATTERN_STATUS':
			return {
				...state,
				patternStatus: action.status,
				patternError: action.error ?? null,
			};
		case 'SET_PATTERN_RECS':
			return {
				...state,
				patternRecommendations: action.recommendations,
				patternBadge: getPatternBadgeReason( action.recommendations ),
				patternError: null,
			};
		case 'SET_TEMPLATE_STATUS':
			if ( isStaleTemplateRequest( state, action.requestToken ) ) {
				return state;
			}

			return {
				...state,
				templateStatus: action.status,
				templateError: action.error ?? null,
				templateRequestToken:
					action.requestToken ?? state.templateRequestToken,
				templateSelectedSuggestionKey:
					action.status === 'loading'
						? null
						: state.templateSelectedSuggestionKey,
				templateApplyStatus:
					action.status === 'loading'
						? 'idle'
						: state.templateApplyStatus,
				templateApplyError:
					action.status === 'loading'
						? null
						: state.templateApplyError,
				templateLastAppliedSuggestionKey:
					action.status === 'loading'
						? null
						: state.templateLastAppliedSuggestionKey,
				templateLastAppliedOperations:
					action.status === 'loading'
						? []
						: state.templateLastAppliedOperations,
			};
		case 'SET_TEMPLATE_RECS':
			if ( isStaleTemplateRequest( state, action.requestToken ) ) {
				return state;
			}

			return {
				...state,
				templateRecommendations: action.payload?.suggestions ?? [],
				templateExplanation: action.payload?.explanation ?? '',
				templateRequestPrompt: action.prompt ?? '',
				templateRef: action.templateRef,
				templateRequestToken:
					action.requestToken ?? state.templateRequestToken,
				templateResultToken: state.templateResultToken + 1,
				templateStatus: 'ready',
				templateError: null,
				templateSelectedSuggestionKey: null,
				templateApplyStatus: 'idle',
				templateApplyError: null,
				templateLastAppliedSuggestionKey: null,
				templateLastAppliedOperations: [],
			};
		case 'SET_TEMPLATE_SELECTED_SUGGESTION':
			return {
				...state,
				templateSelectedSuggestionKey: action.suggestionKey ?? null,
				templateApplyStatus:
					state.templateApplyStatus === 'error'
						? 'idle'
						: state.templateApplyStatus,
				templateApplyError:
					state.templateApplyStatus === 'error'
						? null
						: state.templateApplyError,
			};
		case 'SET_TEMPLATE_APPLY_STATE':
			return {
				...state,
				templateApplyStatus: action.status,
				templateApplyError: action.error ?? null,
				templateLastAppliedSuggestionKey:
					action.status === 'success'
						? action.suggestionKey ?? null
						: state.templateLastAppliedSuggestionKey,
				templateLastAppliedOperations:
					action.status === 'success'
						? action.operations ?? []
						: state.templateLastAppliedOperations,
			};
		case 'CLEAR_TEMPLATE_RECS':
			return {
				...state,
				templateRecommendations: [],
				templateExplanation: '',
				templateStatus: 'idle',
				templateError: null,
				templateRequestPrompt: '',
				templateRef: null,
				templateRequestToken: state.templateRequestToken + 1,
				templateResultToken: state.templateResultToken + 1,
				templateSelectedSuggestionKey: null,
				templateApplyStatus: 'idle',
				templateApplyError: null,
				templateLastAppliedSuggestionKey: null,
				templateLastAppliedOperations: [],
			};
		case 'SET_TEMPLATE_PART_STATUS':
			if ( isStaleTemplatePartRequest( state, action.requestToken ) ) {
				return state;
			}

			return {
				...state,
				templatePartStatus: action.status,
				templatePartError: action.error ?? null,
				templatePartRequestToken:
					action.requestToken ?? state.templatePartRequestToken,
				templatePartSelectedSuggestionKey:
					action.status === 'loading'
						? null
						: state.templatePartSelectedSuggestionKey,
				templatePartApplyStatus:
					action.status === 'loading'
						? 'idle'
						: state.templatePartApplyStatus,
				templatePartApplyError:
					action.status === 'loading'
						? null
						: state.templatePartApplyError,
				templatePartLastAppliedSuggestionKey:
					action.status === 'loading'
						? null
						: state.templatePartLastAppliedSuggestionKey,
				templatePartLastAppliedOperations:
					action.status === 'loading'
						? []
						: state.templatePartLastAppliedOperations,
			};
		case 'SET_TEMPLATE_PART_RECS':
			if (
				isStaleTemplatePartRequest( state, action.requestToken )
			) {
				return state;
			}

			return {
				...state,
				templatePartRecommendations:
					action.payload?.suggestions ?? [],
				templatePartExplanation:
					action.payload?.explanation ?? '',
				templatePartRequestPrompt: action.prompt ?? '',
				templatePartRef: action.templatePartRef,
				templatePartRequestToken:
					action.requestToken ?? state.templatePartRequestToken,
				templatePartResultToken:
					state.templatePartResultToken + 1,
				templatePartStatus: 'ready',
				templatePartError: null,
				templatePartSelectedSuggestionKey: null,
				templatePartApplyStatus: 'idle',
				templatePartApplyError: null,
				templatePartLastAppliedSuggestionKey: null,
				templatePartLastAppliedOperations: [],
			};
		case 'SET_TEMPLATE_PART_SELECTED_SUGGESTION':
			return {
				...state,
				templatePartSelectedSuggestionKey:
					action.suggestionKey ?? null,
				templatePartApplyStatus:
					state.templatePartApplyStatus === 'error'
						? 'idle'
						: state.templatePartApplyStatus,
				templatePartApplyError:
					state.templatePartApplyStatus === 'error'
						? null
						: state.templatePartApplyError,
			};
		case 'SET_TEMPLATE_PART_APPLY_STATE':
			return {
				...state,
				templatePartApplyStatus: action.status,
				templatePartApplyError: action.error ?? null,
				templatePartLastAppliedSuggestionKey:
					action.status === 'success'
						? action.suggestionKey ?? null
						: state.templatePartLastAppliedSuggestionKey,
				templatePartLastAppliedOperations:
					action.status === 'success'
						? action.operations ?? []
						: state.templatePartLastAppliedOperations,
			};
		case 'CLEAR_TEMPLATE_PART_RECS':
			return {
				...state,
				templatePartRecommendations: [],
				templatePartExplanation: '',
				templatePartStatus: 'idle',
				templatePartError: null,
				templatePartRequestPrompt: '',
				templatePartRef: null,
				templatePartRequestToken:
					state.templatePartRequestToken + 1,
				templatePartResultToken:
					state.templatePartResultToken + 1,
				templatePartSelectedSuggestionKey: null,
				templatePartApplyStatus: 'idle',
				templatePartApplyError: null,
				templatePartLastAppliedSuggestionKey: null,
				templatePartLastAppliedOperations: [],
			};
		default:
			return state;
	}
}

const selectors = {
	getBlockRequestState: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ),
	getBlockStatus: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).status,
	getBlockError: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).error,
	getBlockRequestToken: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).requestToken,
	isBlockLoading: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).status === 'loading',
	getStatus: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).status,
	getError: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).error,
	isLoading: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).status === 'loading',
	getBlockRecommendations: ( state, clientId ) =>
		state.blockRecommendations[ clientId ] || null,
	getSettingsSuggestions: ( state, clientId ) =>
		state.blockRecommendations[ clientId ]?.settings || [],
	getStylesSuggestions: ( state, clientId ) =>
		state.blockRecommendations[ clientId ]?.styles || [],
	getBlockSuggestions: ( state, clientId ) =>
		state.blockRecommendations[ clientId ]?.block || [],
	getActivityScopeKey: ( state ) => state.activityScopeKey,
	getActivityLog: ( state ) => state.activityLog,
	getLatestAppliedActivity: ( state ) =>
		getLatestAppliedActivity( state.activityLog ),
	getLatestUndoableActivity: ( state ) =>
		getLatestUndoableActivity( state.activityLog ),
	getUndoStatus: ( state ) => state.undoStatus,
	getUndoError: ( state ) => state.undoError,
	isUndoing: ( state ) => state.undoStatus === 'undoing',
	getLastUndoneActivityId: ( state ) => state.lastUndoneActivityId,
	getPatternRecommendations: ( state ) => state.patternRecommendations,
	getPatternStatus: ( state ) => state.patternStatus,
	getPatternBadge: ( state ) => state.patternBadge,
	getPatternError: ( state ) => state.patternError,
	isPatternLoading: ( state ) => state.patternStatus === 'loading',
	getTemplateRecommendations: ( state ) => state.templateRecommendations,
	getTemplateExplanation: ( state ) => state.templateExplanation,
	getTemplateError: ( state ) => state.templateError,
	getTemplateRequestPrompt: ( state ) => state.templateRequestPrompt,
	getTemplateResultRef: ( state ) => state.templateRef,
	getTemplateRequestToken: ( state ) => state.templateRequestToken,
	getTemplateResultToken: ( state ) => state.templateResultToken,
	isTemplateLoading: ( state ) => state.templateStatus === 'loading',
	getTemplateStatus: ( state ) => state.templateStatus,
	getTemplateSelectedSuggestionKey: ( state ) =>
		state.templateSelectedSuggestionKey,
	getTemplateApplyStatus: ( state ) => state.templateApplyStatus,
	getTemplateApplyError: ( state ) => state.templateApplyError,
	isTemplateApplying: ( state ) => state.templateApplyStatus === 'applying',
	getTemplateLastAppliedSuggestionKey: ( state ) =>
		state.templateLastAppliedSuggestionKey,
	getTemplateLastAppliedOperations: ( state ) =>
		state.templateLastAppliedOperations,
	getTemplatePartRecommendations: ( state ) =>
		state.templatePartRecommendations,
	getTemplatePartExplanation: ( state ) => state.templatePartExplanation,
	getTemplatePartError: ( state ) => state.templatePartError,
	getTemplatePartRequestPrompt: ( state ) =>
		state.templatePartRequestPrompt,
	getTemplatePartResultRef: ( state ) => state.templatePartRef,
	getTemplatePartRequestToken: ( state ) =>
		state.templatePartRequestToken,
	getTemplatePartResultToken: ( state ) =>
		state.templatePartResultToken,
	isTemplatePartLoading: ( state ) =>
		state.templatePartStatus === 'loading',
	getTemplatePartStatus: ( state ) => state.templatePartStatus,
	getTemplatePartSelectedSuggestionKey: ( state ) =>
		state.templatePartSelectedSuggestionKey,
	getTemplatePartApplyStatus: ( state ) =>
		state.templatePartApplyStatus,
	getTemplatePartApplyError: ( state ) => state.templatePartApplyError,
	isTemplatePartApplying: ( state ) =>
		state.templatePartApplyStatus === 'applying',
	getTemplatePartLastAppliedSuggestionKey: ( state ) =>
		state.templatePartLastAppliedSuggestionKey,
	getTemplatePartLastAppliedOperations: ( state ) =>
		state.templatePartLastAppliedOperations,
};

const store = createReduxStore( STORE_NAME, { reducer, actions, selectors } );

register( store );

export { actions, reducer, selectors, STORE_NAME };
export default store;
