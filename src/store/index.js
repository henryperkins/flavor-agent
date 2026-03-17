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

const STORE_NAME = 'flavor-agent';

const DEFAULT_STATE = {
	status: 'idle',
	error: null,
	blockRecommendations: {},
	activityLog: [],
	patternRecommendations: [],
	patternStatus: 'idle',
	patternBadge: null,
};

const actions = {
	setStatus( status, error = null ) {
		return { type: 'SET_STATUS', status, error };
	},

	setBlockRecommendations( clientId, recommendations ) {
		return { type: 'SET_BLOCK_RECS', clientId, recommendations };
	},

	clearBlockRecommendations( clientId ) {
		return { type: 'CLEAR_BLOCK_RECS', clientId };
	},

	logActivity( entry ) {
		return { type: 'LOG_ACTIVITY', entry };
	},

	fetchBlockRecommendations( clientId, context, prompt = '' ) {
		return async ( { dispatch } ) => {
			dispatch( actions.setStatus( 'loading' ) );

			try {
				const result = await apiFetch( {
					path: '/flavor-agent/v1/recommend-block',
					method: 'POST',
					data: { editorContext: context, prompt, clientId },
				} );

				dispatch(
					actions.setBlockRecommendations( clientId, {
						blockName: context.block?.name || '',
						settings: result.payload?.settings || [],
						styles: result.payload?.styles || [],
						block: result.payload?.block || [],
						explanation: result.payload?.explanation || '',
						timestamp: Date.now(),
					} )
				);
				dispatch( actions.setStatus( 'idle' ) );
			} catch ( err ) {
				dispatch(
					actions.setStatus(
						'error',
						err.message || 'Request failed.'
					)
				);
			}
		};
	},

	applySuggestion( clientId, suggestion ) {
		return async ( { dispatch: localDispatch } ) => {
			if ( suggestion.attributeUpdates ) {
				const { dispatch: wpDispatch } = await import(
					'@wordpress/data'
				);

				wpDispatch( 'core/block-editor' ).updateBlockAttributes(
					clientId,
					suggestion.attributeUpdates
				);
			}

			localDispatch(
				actions.logActivity( {
					type: 'apply_suggestion',
					blockClientId: clientId,
					suggestion: suggestion.label,
					timestamp: new Date().toISOString(),
				} )
			);
		};
	},

	setPatternStatus( status ) {
		return { type: 'SET_PATTERN_STATUS', status };
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
				dispatch( actions.setPatternStatus( 'error' ) );
			}
		};
	},
};

function reducer( state = DEFAULT_STATE, action ) {
	switch ( action.type ) {
		case 'SET_STATUS':
			return { ...state, status: action.status, error: action.error };
		case 'SET_BLOCK_RECS':
			return {
				...state,
				blockRecommendations: {
					...state.blockRecommendations,
					[ action.clientId ]: action.recommendations,
				},
			};
		case 'CLEAR_BLOCK_RECS': {
			const next = { ...state.blockRecommendations };

			delete next[ action.clientId ];

			return { ...state, blockRecommendations: next };
		}
		case 'LOG_ACTIVITY':
			return {
				...state,
				activityLog: [ ...state.activityLog, action.entry ],
			};
		case 'SET_PATTERN_STATUS':
			return { ...state, patternStatus: action.status };
		case 'SET_PATTERN_RECS':
			return {
				...state,
				patternRecommendations: action.recommendations,
				patternBadge: getPatternBadgeReason( action.recommendations ),
			};
		default:
			return state;
	}
}

const selectors = {
	getStatus: ( state ) => state.status,
	getError: ( state ) => state.error,
	isLoading: ( state ) => state.status === 'loading',
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
	getPatternBadge: ( state ) => state.patternBadge,
	isPatternLoading: ( state ) => state.patternStatus === 'loading',
};

const store = createReduxStore( STORE_NAME, { reducer, actions, selectors } );

register( store );

export default store;
export { STORE_NAME };
