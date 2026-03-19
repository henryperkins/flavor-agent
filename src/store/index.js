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
	buildSafeAttributeUpdates,
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
	activityLog: [],
	patternRecommendations: [],
	patternStatus: 'idle',
	patternError: null,
	patternBadge: null,
	templateRecommendations: [],
	templateExplanation: '',
	templateStatus: 'idle',
	templateError: null,
	templateRef: null,
	templateResultToken: 0,
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

	logActivity( entry ) {
		return { type: 'LOG_ACTIVITY', entry };
	},

	fetchBlockRecommendations( clientId, context, prompt = '' ) {
		return async ( { dispatch, select } ) => {
			const requestToken =
				select( STORE_NAME ).getBlockRequestToken( clientId ) + 1;

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
		return async ( { dispatch: localDispatch, select } ) => {
			const storedRecommendations =
				select( STORE_NAME ).getBlockRecommendations( clientId ) || {};
			const blockContext = storedRecommendations.blockContext || {};
			const currentAttributes =
				select( 'core/block-editor' ).getBlockAttributes( clientId ) ||
				{};
			const allowedUpdates = getSuggestionAttributeUpdates(
				suggestion,
				blockContext
			);
			let didApply = false;

			if ( Object.keys( allowedUpdates ).length > 0 ) {
				const safeUpdates = buildSafeAttributeUpdates(
					currentAttributes,
					allowedUpdates
				);

				if ( Object.keys( safeUpdates ).length > 0 ) {
					localDispatch( 'core/block-editor' ).updateBlockAttributes(
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
				actions.logActivity( {
					type: 'apply_suggestion',
					blockClientId: clientId,
					suggestion: suggestion.label,
					timestamp: new Date().toISOString(),
				} )
			);

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

	setTemplateStatus( status, error = null ) {
		return { type: 'SET_TEMPLATE_STATUS', status, error };
	},

	setTemplateRecommendations( templateRef, payload ) {
		return { type: 'SET_TEMPLATE_RECS', templateRef, payload };
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

	fetchTemplateRecommendations( input ) {
		return async ( { dispatch } ) => {
			if ( actions._templateAbort ) {
				actions._templateAbort.abort();
			}

			const controller = new AbortController();
			actions._templateAbort = controller;

			dispatch( actions.setTemplateStatus( 'loading' ) );

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
						result
					)
				);
			} catch ( err ) {
				if ( err.name === 'AbortError' ) {
					return;
				}

				dispatch(
					actions.setTemplateRecommendations( input.templateRef, {
						suggestions: [],
						explanation: '',
					} )
				);
				dispatch(
					actions.setTemplateStatus(
						'error',
						err?.message ||
							'Template recommendation request failed.'
					)
				);
			} finally {
				if ( actions._templateAbort === controller ) {
					actions._templateAbort = null;
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
		case 'LOG_ACTIVITY':
			return {
				...state,
				activityLog: [ ...state.activityLog, action.entry ],
			};
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
			return {
				...state,
				templateStatus: action.status,
				templateError: action.error ?? null,
			};
		case 'SET_TEMPLATE_RECS':
			return {
				...state,
				templateRecommendations: action.payload?.suggestions ?? [],
				templateExplanation: action.payload?.explanation ?? '',
				templateRef: action.templateRef,
				templateResultToken: state.templateResultToken + 1,
				templateStatus: 'ready',
				templateError: null,
			};
		case 'CLEAR_TEMPLATE_RECS':
			return {
				...state,
				templateRecommendations: [],
				templateExplanation: '',
				templateStatus: 'idle',
				templateError: null,
				templateRef: null,
				templateResultToken: state.templateResultToken + 1,
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
	getActivityLog: ( state ) => state.activityLog,
	getPatternRecommendations: ( state ) => state.patternRecommendations,
	getPatternStatus: ( state ) => state.patternStatus,
	getPatternBadge: ( state ) => state.patternBadge,
	getPatternError: ( state ) => state.patternError,
	isPatternLoading: ( state ) => state.patternStatus === 'loading',
	getTemplateRecommendations: ( state ) => state.templateRecommendations,
	getTemplateExplanation: ( state ) => state.templateExplanation,
	getTemplateError: ( state ) => state.templateError,
	getTemplateResultRef: ( state ) => state.templateRef,
	getTemplateResultToken: ( state ) => state.templateResultToken,
	isTemplateLoading: ( state ) => state.templateStatus === 'loading',
	getTemplateStatus: ( state ) => state.templateStatus,
};

const store = createReduxStore( STORE_NAME, { reducer, actions, selectors } );

register( store );

export { actions, reducer, selectors, STORE_NAME };
export default store;
