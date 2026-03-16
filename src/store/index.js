/**
 * Flavor Agent data store.
 *
 * Per-block, per-tab recommendation state. Each recommendation set
 * contains suggestions scoped to Settings, Styles, and Block tabs
 * so Inspector injection components render in the right place.
 */
import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

const STORE_NAME = 'flavor-agent';

const DEFAULT_STATE = {
	status: 'idle',
	error: null,
	blockRecommendations: {},
	activityLog: [],
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
				dispatch( actions.setBlockRecommendations( clientId, {
					blockName: context.block?.name || '',
					settings: result.payload?.settings || [],
					styles: result.payload?.styles || [],
					block: result.payload?.block || [],
					explanation: result.payload?.explanation || '',
					timestamp: Date.now(),
				} ) );
				dispatch( actions.setStatus( 'idle' ) );
			} catch ( err ) {
				dispatch( actions.setStatus( 'error', err.message || 'Request failed.' ) );
			}
		};
	},

	applySuggestion( clientId, suggestion ) {
		return async ( { dispatch: localDispatch } ) => {
			if ( suggestion.attributeUpdates ) {
				const { dispatch: wpDispatch } = await import( '@wordpress/data' );
				wpDispatch( 'core/block-editor' )
					.updateBlockAttributes( clientId, suggestion.attributeUpdates );
			}
			localDispatch( actions.logActivity( {
				type: 'apply_suggestion',
				blockClientId: clientId,
				suggestion: suggestion.label,
				timestamp: new Date().toISOString(),
			} ) );
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
			return { ...state, activityLog: [ ...state.activityLog, action.entry ] };
		default:
			return state;
	}
}

const selectors = {
	getStatus: ( state ) => state.status,
	getError: ( state ) => state.error,
	isLoading: ( state ) => state.status === 'loading',
	getBlockRecommendations: ( state, clientId ) => state.blockRecommendations[ clientId ] || null,
	getSettingsSuggestions: ( state, clientId ) => state.blockRecommendations[ clientId ]?.settings || [],
	getStylesSuggestions: ( state, clientId ) => state.blockRecommendations[ clientId ]?.styles || [],
	getBlockSuggestions: ( state, clientId ) => state.blockRecommendations[ clientId ]?.block || [],
	getActivityLog: ( state ) => state.activityLog,
};

const store = createReduxStore( STORE_NAME, { reducer, actions, selectors } );
register( store );

export default store;
export { STORE_NAME };
