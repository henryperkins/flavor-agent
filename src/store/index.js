/**
 * Flavor Agent data store.
 *
 * Per-block, per-tab recommendation state. Each recommendation set
 * contains suggestions scoped to Settings, Styles, and Block tabs
 * so Inspector injection components render in the right place.
 */
import { rawHandler } from '@wordpress/blocks';
import { createReduxStore, register } from '@wordpress/data';

import { buildBlockRecommendationContextSignature } from '../utils/block-recommendation-context';
import {
	buildBlockRecommendationRequestSignature,
	buildNavigationRecommendationRequestSignature,
	buildPatternRecommendationRequestSignature,
} from '../utils/recommendation-request-signature';
import {
	getCurrentActivityScope,
	getLatestAppliedActivity,
	getLatestUndoableActivity,
	limitActivityLog,
} from './activity-history';
import {
	createLoadActivitySessionAction,
	getApiErrorCode,
	getApiErrorMessage,
	getRequestDocumentFromScope,
	getRetryAfterSeconds,
	isRetryableRateLimitError,
	recordActivityEntry,
	reloadScopedActivitySession,
	sleep,
	syncActivitySession,
} from './activity-session';
import {
	buildBlockActivityEntry,
	buildBlockStructuralActivityEntry,
	createUndoActivityAction,
	findBlockPath,
	getNextLastUndoneActivityId,
} from './activity-undo';
import {
	applyBlockStructuralSuggestionOperations,
	getBlockStructuralActionErrorMessage,
} from '../utils/block-structural-actions';
import {
	buildExecutableSurfaceReviewFreshnessThunk,
	createExecutableSurfaceReviewFreshnessConfig,
} from './executable-surface-runtime';
import {
	createExecutableSurfaceDefaultState,
	createExecutableSurfaceRuntimeActionCreators,
	createExecutableSurfaceSelectors,
	createExecutableSurfaceStateActionCreators,
	reduceExecutableSurfaceState,
} from './executable-surfaces';
import {
	buildToastForActivity,
	reduceToastsState,
	toastsActionCreators,
	toastsDefaultState,
	toastsSelectors,
	TOAST_DEFAULTS,
} from './toasts';
import {
	attributeSnapshotsMatch,
	buildSafeAttributeUpdates,
	buildBlockRecommendationDiagnostics,
	getBlockSuggestionExecutionInfo,
	sanitizeRecommendationsForContext,
} from './update-helpers';
import { isPlainObject } from '../utils/type-guards';
import {
	getAllowedPatterns,
	getBlockPatterns,
} from '../patterns/pattern-settings';
import { executeFlavorAgentAbility } from './abilities-client';

const STORE_NAME = 'flavor-agent';
const CLIENT_REQUEST_SESSION_ID = `flavor-agent-${ Date.now() }-${ Math.random()
	.toString( 36 )
	.slice( 2 ) }`;
const DEFAULT_BLOCK_REQUEST_STATE = {
	status: 'idle',
	error: null,
	requestToken: 0,
	contextSignature: null,
	resolvedContextSignature: null,
	diagnostics: null,
	applyStatus: 'idle',
	applyError: null,
	lastAppliedSuggestionKey: null,
	staleReason: null,
};

const DEFAULT_STATE = {
	blockRecommendations: {},
	blockRequestState: {},
	contentRecommendation: null,
	contentStatus: 'idle',
	contentError: null,
	contentMode: 'draft',
	contentRequestPrompt: '',
	contentRequestToken: 0,
	contentResultToken: 0,
	navigationRecommendations: [],
	navigationExplanation: '',
	navigationStatus: 'idle',
	navigationError: null,
	navigationRequestPrompt: '',
	navigationBlockClientId: null,
	navigationContextSignature: null,
	navigationReviewContextSignature: null,
	navigationRequestToken: 0,
	navigationResultToken: 0,
	navigationReviewRequestToken: 0,
	navigationReviewFreshnessStatus: 'idle',
	navigationReviewStaleReason: null,
	activityScopeKey: null,
	activityLog: [],
	undoStatus: 'idle',
	undoError: null,
	lastUndoneActivityId: null,
	patternRecommendations: [],
	patternDiagnostics: null,
	patternStatus: 'idle',
	patternError: null,
	patternRequestToken: 0,
	patternResultToken: 0,
	patternRequestSignature: '',
	...createExecutableSurfaceDefaultState(),
	...toastsDefaultState,
};

const SHARED_PANEL_SEQUENCE = Object.freeze( [
	'prompt',
	'suggestions',
	'explanation',
	'review',
	'apply',
	'undo-history',
] );

const SURFACE_INTERACTION_CONTRACT = Object.freeze( {
	block: Object.freeze( {
		surface: 'block',
		advisoryOnly: false,
		allowsInlineApply: true,
		previewRequired: false,
		readyState: 'advisory-ready',
		stages: SHARED_PANEL_SEQUENCE,
	} ),
	content: Object.freeze( {
		surface: 'content',
		advisoryOnly: true,
		allowsInlineApply: false,
		previewRequired: false,
		readyState: 'advisory-ready',
		stages: SHARED_PANEL_SEQUENCE,
	} ),
	navigation: Object.freeze( {
		surface: 'navigation',
		advisoryOnly: true,
		allowsInlineApply: false,
		previewRequired: false,
		readyState: 'advisory-ready',
		stages: SHARED_PANEL_SEQUENCE,
	} ),
	template: Object.freeze( {
		surface: 'template',
		advisoryOnly: false,
		allowsInlineApply: false,
		previewRequired: true,
		readyState: 'advisory-ready',
		stages: SHARED_PANEL_SEQUENCE,
	} ),
	'template-part': Object.freeze( {
		surface: 'template-part',
		advisoryOnly: false,
		allowsInlineApply: false,
		previewRequired: true,
		readyState: 'advisory-ready',
		stages: SHARED_PANEL_SEQUENCE,
	} ),
	'global-styles': Object.freeze( {
		surface: 'global-styles',
		advisoryOnly: false,
		allowsInlineApply: false,
		previewRequired: true,
		readyState: 'advisory-ready',
		stages: SHARED_PANEL_SEQUENCE,
	} ),
	'style-book': Object.freeze( {
		surface: 'style-book',
		advisoryOnly: false,
		allowsInlineApply: false,
		previewRequired: true,
		readyState: 'advisory-ready',
		stages: SHARED_PANEL_SEQUENCE,
	} ),
} );
const BLOCK_REST_INVALID_JSON_MESSAGE =
	'The block recommendation endpoint returned a non-JSON response.';
const BLOCK_REST_INVALID_JSON_DETAIL =
	'WordPress REST returned a response the editor could not parse as JSON. Check the HTTP response body and PHP debug log for warning output, a fatal error page, or a proxy/auth HTML response.';

function getSurfaceContract( surface ) {
	return SURFACE_INTERACTION_CONTRACT[ surface ] || null;
}

function normalizeStringMessage( value ) {
	return typeof value === 'string' && value.trim() ? value.trim() : '';
}

function normalizeRequestMeta( requestMeta = null ) {
	return isPlainObject( requestMeta ) ? requestMeta : null;
}

function normalizeBlockRequestDiagnostics( diagnostics = null ) {
	return isPlainObject( diagnostics ) ? diagnostics : null;
}

function normalizePatternDiagnostics( diagnostics = null ) {
	const unreadableSyncedPatterns = Number(
		diagnostics?.filteredCandidates?.unreadableSyncedPatterns ?? 0
	);

	return {
		filteredCandidates: {
			unreadableSyncedPatterns: Number.isFinite(
				unreadableSyncedPatterns
			)
				? Math.max( 0, unreadableSyncedPatterns )
				: 0,
		},
	};
}

function attachRequestMetaToSuggestion( suggestion, requestMeta ) {
	const normalizedRequestMeta = normalizeRequestMeta( requestMeta );

	if ( ! normalizedRequestMeta || ! isPlainObject( suggestion ) ) {
		return suggestion;
	}

	return {
		...suggestion,
		requestMeta: normalizedRequestMeta,
	};
}

function attachRequestMetaToSuggestionList( suggestions, requestMeta ) {
	if ( ! Array.isArray( suggestions ) ) {
		return [];
	}

	return suggestions.map( ( suggestion ) =>
		attachRequestMetaToSuggestion( suggestion, requestMeta )
	);
}

function attachRequestMetaToRecommendationPayload( payload = {} ) {
	if ( ! isPlainObject( payload ) ) {
		return payload;
	}

	const requestMeta = normalizeRequestMeta( payload.requestMeta );

	if ( ! requestMeta ) {
		return payload;
	}

	return {
		...payload,
		settings: attachRequestMetaToSuggestionList(
			payload.settings,
			requestMeta
		),
		styles: attachRequestMetaToSuggestionList(
			payload.styles,
			requestMeta
		),
		block: attachRequestMetaToSuggestionList( payload.block, requestMeta ),
		suggestions: attachRequestMetaToSuggestionList(
			payload.suggestions,
			requestMeta
		),
		requestMeta,
	};
}

function getNormalizedReadyState(
	surface,
	{ hasPreview = false, hasResult = false }
) {
	const contract = getSurfaceContract( surface );

	if ( ! contract || ! hasResult ) {
		return 'idle';
	}

	if ( contract.previewRequired && hasPreview ) {
		return 'preview-ready';
	}

	return contract.readyState;
}

function getNormalizedInteractionState( surface, options = {} ) {
	const {
		requestStatus = 'idle',
		requestError = '',
		applyStatus = 'idle',
		applyError = '',
		undoStatus = 'idle',
		undoError = '',
		hasResult = false,
		hasPreview = false,
		hasSuccess = false,
		hasUndoSuccess = false,
	} = options;

	if (
		normalizeStringMessage( requestError ) ||
		normalizeStringMessage( applyError ) ||
		normalizeStringMessage( undoError )
	) {
		return 'error';
	}

	if ( undoStatus === 'undoing' ) {
		return 'undoing';
	}

	if ( applyStatus === 'applying' ) {
		return 'applying';
	}

	if ( requestStatus === 'loading' ) {
		return 'loading';
	}

	if ( hasUndoSuccess || hasSuccess ) {
		return 'success';
	}

	return getNormalizedReadyState( surface, {
		hasPreview,
		hasResult,
	} );
}

function isSurfaceApplyAllowedForState( surface, options = {} ) {
	const contract = getSurfaceContract( surface );

	if ( ! contract || contract.advisoryOnly ) {
		return false;
	}

	if ( options.isStale ) {
		return false;
	}

	if ( contract.previewRequired ) {
		return Boolean(
			options.hasPreview &&
				options.hasOperations &&
				options.applyStatus !== 'applying'
		);
	}

	return Boolean( options.hasResult );
}

function getBlockRequestError( state, clientId ) {
	return normalizeStringMessage(
		getStoredBlockRequestState( state, clientId ).error
	);
}

function getBlockApplyError( state, clientId ) {
	return normalizeStringMessage(
		getStoredBlockRequestState( state, clientId ).applyError
	);
}

function getNavigationRequestError( state, blockClientId = null ) {
	return normalizeStringMessage(
		blockClientId && state.navigationBlockClientId !== blockClientId
			? ''
			: state.navigationError
	);
}

function getNavigationHasResult( state, blockClientId = null ) {
	return Boolean(
		( ! blockClientId ||
			state.navigationBlockClientId === blockClientId ) &&
			state.navigationStatus === 'ready'
	);
}

function getContentHasResult( state ) {
	return Boolean(
		state.contentStatus === 'ready' && state.contentRecommendation
	);
}

function getBlockHasResult( state, clientId ) {
	const requestState = getStoredBlockRequestState( state, clientId );

	return Boolean(
		requestState.status === 'ready' &&
			state.blockRecommendations[ clientId ]
	);
}

function buildClientStaleApplyErrorMessage( surface ) {
	switch ( surface ) {
		case 'template':
			return 'This template result is stale. Refresh recommendations before applying it.';
		case 'template-part':
			return 'This template-part result is stale. Refresh recommendations before applying it.';
		case 'global-styles':
			return 'This Global Styles result is stale. Refresh recommendations before applying it.';
		case 'style-book':
			return 'This Style Book result is stale. Refresh recommendations before applying it.';
		default:
			return 'This result is stale. Refresh recommendations before applying it.';
	}
}

function buildServerStaleApplyErrorMessage( surface ) {
	switch ( surface ) {
		case 'template':
			return 'This template result no longer matches the current server-resolved apply context. Refresh recommendations before applying it.';
		case 'template-part':
			return 'This template-part result no longer matches the current server-resolved apply context. Refresh recommendations before applying it.';
		case 'global-styles':
			return 'This Global Styles result no longer matches the current server-resolved apply context. Refresh recommendations before applying it.';
		case 'style-book':
			return 'This Style Book result no longer matches the current server-resolved apply context. Refresh recommendations before applying it.';
		default:
			return 'This result no longer matches the current server-resolved apply context. Refresh recommendations before applying it.';
	}
}

function buildServerRevalidationErrorMessage( surface ) {
	switch ( surface ) {
		case 'template':
			return 'Flavor Agent could not revalidate this template result against the current server apply context. Try again or refresh recommendations.';
		case 'template-part':
			return 'Flavor Agent could not revalidate this template-part result against the current server apply context. Try again or refresh recommendations.';
		case 'global-styles':
			return 'Flavor Agent could not revalidate this Global Styles result against the current server apply context. Try again or refresh recommendations.';
		case 'style-book':
			return 'Flavor Agent could not revalidate this Style Book result against the current server apply context. Try again or refresh recommendations.';
		default:
			return 'Flavor Agent could not revalidate this result against the current server apply context. Try again or refresh recommendations.';
	}
}

function guardSurfaceApplyFreshness( {
	surface,
	currentRequestSignature = null,
	getStoredRequestSignature,
	localDispatch,
	setApplyState,
} ) {
	const storedRequestSignature = getStoredRequestSignature?.() || null;

	if (
		! storedRequestSignature ||
		! currentRequestSignature ||
		storedRequestSignature === currentRequestSignature
	) {
		return null;
	}

	const error = buildClientStaleApplyErrorMessage( surface );

	localDispatch( setApplyState( 'error', error, 'client' ) );

	return {
		ok: false,
		error,
		staleReason: 'client',
	};
}

function getResolvedContextSignatureFromResponse( result = null ) {
	const resolvedContextSignature = normalizeStringMessage(
		result?.payload?.resolvedContextSignature ||
			result?.resolvedContextSignature
	);

	return resolvedContextSignature || null;
}

function getReviewContextSignatureFromResponse( result = null ) {
	const reviewContextSignature = normalizeStringMessage(
		result?.payload?.reviewContextSignature ||
			result?.reviewContextSignature
	);

	return reviewContextSignature || null;
}

function stripContextSignatureFromRequestInput( requestInput = null ) {
	if ( ! isPlainObject( requestInput ) ) {
		return {};
	}

	const { contextSignature, ...requestData } = requestInput;

	return requestData;
}

function buildClientRequestIdentity( {
	abortId = null,
	requestData = {},
	requestToken = null,
} = {} ) {
	return {
		sessionId: CLIENT_REQUEST_SESSION_ID,
		requestToken: Number.isFinite( requestToken ) ? requestToken : null,
		abortId:
			abortId === null || abortId === undefined ? '' : String( abortId ),
		scopeKey: requestData?.document?.scopeKey || '',
	};
}

function attachClientRequestIdentity( requestData = {}, clientRequest = null ) {
	if ( ! clientRequest ) {
		return requestData;
	}

	return {
		...requestData,
		clientRequest,
	};
}

async function guardSurfaceApplyResolvedFreshness( {
	surface,
	abilityName,
	liveRequestInput,
	storedResolvedContextSignature = null,
	localDispatch,
	setApplyState,
} ) {
	const storedSignature = normalizeStringMessage(
		storedResolvedContextSignature
	);

	if ( ! storedSignature ) {
		const error = buildServerStaleApplyErrorMessage( surface );

		localDispatch( setApplyState( 'error', error, 'server-apply' ) );

		return {
			ok: false,
			error,
			staleReason: 'server-apply',
		};
	}

	const requestData =
		stripContextSignatureFromRequestInput( liveRequestInput );

	if ( Object.keys( requestData ).length === 0 ) {
		const error = buildServerRevalidationErrorMessage( surface );

		localDispatch( setApplyState( 'error', error ) );

		return {
			ok: false,
			error,
		};
	}

	try {
		const result = await executeFlavorAgentAbility( abilityName, {
			...requestData,
			resolveSignatureOnly: true,
		} );
		const resolvedContextSignature =
			getResolvedContextSignatureFromResponse( result );

		if (
			! resolvedContextSignature ||
			resolvedContextSignature !== storedSignature
		) {
			const error = buildServerStaleApplyErrorMessage( surface );

			localDispatch( setApplyState( 'error', error, 'server-apply' ) );

			return {
				ok: false,
				error,
				staleReason: 'server-apply',
			};
		}

		return {
			ok: true,
			resolvedContextSignature,
		};
	} catch {
		const error = buildServerRevalidationErrorMessage( surface );

		localDispatch( setApplyState( 'error', error ) );

		return {
			ok: false,
			error,
		};
	}
}

function getSurfaceStatusNotice( surface, options = {} ) {
	const requestError = normalizeStringMessage( options.requestError );

	if ( requestError ) {
		return {
			source: 'request',
			tone: 'error',
			message: requestError,
			isDismissible: Boolean( options.onDismissAction ),
			actionType: options.onDismissAction ? 'dismiss' : null,
			actionLabel: '',
			actionDisabled: false,
		};
	}

	const undoError = normalizeStringMessage( options.undoError );

	if ( undoError ) {
		return {
			source: 'undo',
			tone: 'error',
			message: undoError,
			isDismissible: Boolean( options.onUndoDismissAction ),
			actionType: options.onUndoDismissAction ? 'dismiss' : null,
			actionLabel: '',
			actionDisabled: false,
		};
	}

	const undoSuccessMessage = normalizeStringMessage(
		options.undoSuccessMessage
	);

	if ( undoSuccessMessage ) {
		return {
			source: 'undo',
			tone: 'success',
			message: undoSuccessMessage,
			isDismissible: false,
			actionType: null,
			actionLabel: '',
			actionDisabled: false,
		};
	}

	const applyError = normalizeStringMessage( options.applyError );

	if ( applyError ) {
		return {
			source: 'apply',
			tone: 'error',
			message: applyError,
			isDismissible: Boolean( options.onApplyDismissAction ),
			actionType: options.onApplyDismissAction ? 'dismiss' : null,
			actionLabel: '',
			actionDisabled: false,
		};
	}

	const applySuccessMessage = normalizeStringMessage(
		options.applySuccessMessage
	);

	if ( applySuccessMessage ) {
		return {
			source: 'apply',
			tone: 'success',
			message: applySuccessMessage,
			isDismissible: false,
			actionType: 'undo',
			actionLabel: 'Undo',
			actionDisabled: options.undoStatus === 'undoing',
		};
	}

	// Stale notices stay surface-owned so each surface can pair the stale copy
	// with the right disabled-action behavior for its interaction model.
	const isStale = options.isStale === true;

	if ( isStale ) {
		return null;
	}

	const interactionState = getNormalizedInteractionState( surface, options );
	const emptyMessage = normalizeStringMessage( options.emptyMessage );

	if (
		options.hasResult &&
		! options.hasSuggestions &&
		emptyMessage &&
		interactionState !== 'loading'
	) {
		return {
			source: 'empty',
			tone: 'info',
			message: emptyMessage,
			isDismissible: false,
			actionType: null,
			actionLabel: '',
			actionDisabled: false,
		};
	}

	const advisoryMessage = normalizeStringMessage( options.advisoryMessage );

	if ( interactionState === 'advisory-ready' && advisoryMessage ) {
		return {
			source: 'advisory',
			tone: 'info',
			message: advisoryMessage,
			isDismissible: false,
			actionType: null,
			actionLabel: '',
			actionDisabled: false,
		};
	}

	return null;
}

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

function isStaleNavigationRequest( state, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state.navigationRequestToken || 0 );
}

function isStalePatternRequest( state, requestToken, requestSignature = '' ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	const currentToken = state.patternRequestToken || 0;

	if ( requestToken < currentToken ) {
		return true;
	}

	return (
		requestToken === currentToken &&
		Boolean( requestSignature ) &&
		Boolean( state.patternRequestSignature ) &&
		requestSignature !== state.patternRequestSignature
	);
}

function isStaleNavigationReviewRequest( state, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state.navigationReviewRequestToken || 0 );
}
function buildBlockRecommendationFailureDiagnostics(
	error,
	requestData = {},
	requestToken = null
) {
	const rawMessage = getApiErrorMessage( error, 'Request failed.' );
	const errorCode = getApiErrorCode( error );
	const isInvalidJsonResponse = errorCode === 'invalid_json';
	const message = isInvalidJsonResponse
		? BLOCK_REST_INVALID_JSON_MESSAGE
		: rawMessage;
	const requestMeta = normalizeRequestMeta( error?.data?.requestMeta );
	const wrappedMessage =
		normalizeStringMessage(
			requestMeta?.errorSummary?.wrappedMessage ||
				error?.data?.requestMeta?.errorSummary?.wrappedMessage
		) || '';
	const detailLines = [];

	if ( wrappedMessage && wrappedMessage !== message ) {
		detailLines.push( `Transport detail: ${ wrappedMessage }` );
	}

	if ( isInvalidJsonResponse ) {
		detailLines.push( BLOCK_REST_INVALID_JSON_DETAIL );

		if ( rawMessage && rawMessage !== message ) {
			detailLines.push( `Original parser message: ${ rawMessage }` );
		}
	}

	return {
		type: 'failure',
		title: `Block request failed: ${ message }`,
		detailLines,
		requestMeta,
		errorCode,
		errorMessage: message,
		requestToken,
		timestamp: new Date().toISOString(),
		prompt: requestData.prompt || '',
		blockName: requestData.editorContext?.block?.name || '',
	};
}

function getNavigationStoredRequestSignature( select ) {
	return buildNavigationRecommendationRequestSignature( {
		blockClientId: select.getNavigationBlockClientId?.() || '',
		prompt: select.getNavigationRequestPrompt?.() || '',
		contextSignature: select.getNavigationContextSignature?.() || null,
	} );
}

const EXECUTABLE_SURFACE_FETCH_DEPS = {
	attachRequestMetaToRecommendationPayload,
	getReviewContextSignatureFromResponse,
	getResolvedContextSignatureFromResponse,
	runAbortableRecommendationRequest,
};

function syncStoreActivitySession(
	localDispatch,
	select,
	scope,
	options = {}
) {
	return syncActivitySession(
		localDispatch,
		select,
		scope,
		actions.setActivitySession,
		options
	);
}

function reloadStoreActivitySession( localDispatch, registry, select ) {
	return reloadScopedActivitySession( localDispatch, registry, select, {
		getCurrentActivityScope,
		setActivitySession: actions.setActivitySession,
	} );
}

function logStoreActivityEntry( localDispatch, select, entry ) {
	return recordActivityEntry(
		localDispatch,
		select,
		entry,
		actions.logActivity,
		actions.setActivitySession
	);
}

function findRegisteredPattern(
	blockEditorSelect = {},
	patternName = '',
	rootClientId = null
) {
	const settingsPatterns = getBlockPatterns( blockEditorSelect );
	const allowedPatterns = getAllowedPatterns(
		rootClientId,
		blockEditorSelect
	);
	const candidatePatterns = [
		...( Array.isArray( settingsPatterns ) ? settingsPatterns : [] ),
		...( Array.isArray( allowedPatterns ) ? allowedPatterns : [] ),
	];

	return (
		candidatePatterns.find(
			( pattern ) => pattern?.name === patternName
		) || null
	);
}

function createBlockStructuralPatternParser(
	blockEditorSelect = {},
	rootClientId = null
) {
	return ( patternName ) => {
		const pattern = findRegisteredPattern(
			blockEditorSelect,
			patternName,
			rootClientId
		);

		if ( ! pattern ) {
			const error = new Error(
				getBlockStructuralActionErrorMessage( 'pattern_missing' )
			);
			error.code = 'pattern_missing';
			throw error;
		}

		if ( Array.isArray( pattern.blocks ) && pattern.blocks.length > 0 ) {
			return pattern.blocks;
		}

		if ( typeof pattern.content === 'string' && pattern.content.trim() ) {
			return rawHandler( { HTML: pattern.content } ).filter( Boolean );
		}

		const error = new Error(
			getBlockStructuralActionErrorMessage( 'pattern_missing' )
		);
		error.code = 'pattern_missing';
		throw error;
	};
}

function dispatchExecutableSurfaceToast( {
	localDispatch,
	persistedEntry,
	surface,
	suggestion,
	extras,
} ) {
	localDispatch(
		actions.enqueueToast(
			buildToastForActivity( {
				surface,
				persistedEntry,
				suggestion,
				extras,
			} )
		)
	);
}

const EXECUTABLE_SURFACE_APPLY_DEPS = {
	dispatchToastForActivity: dispatchExecutableSurfaceToast,
	getCurrentActivityScope,
	guardSurfaceApplyFreshness,
	guardSurfaceApplyResolvedFreshness,
	recordActivityEntry: logStoreActivityEntry,
	syncActivitySession: syncStoreActivitySession,
};

const EXECUTABLE_SURFACE_REVIEW_DEPS = {
	getReviewContextSignatureFromResponse,
};

function getNavigationReviewConfig() {
	return createExecutableSurfaceReviewFreshnessConfig( {
		abilityName: 'flavor-agent/recommend-navigation',
		getReviewRequestToken: ( select ) =>
			select.getNavigationReviewRequestToken?.() || 0,
		getStoredRequestSignature: getNavigationStoredRequestSignature,
		getStoredReviewContextSignature: ( select ) =>
			select.getNavigationReviewContextSignature?.() || null,
		setReviewStateAction: actions.setNavigationReviewFreshnessState,
		surface: 'navigation',
	} );
}

function clearAbortController( abortKey, abortId, controller ) {
	if ( abortId === null ) {
		if ( actions[ abortKey ] === controller ) {
			actions[ abortKey ] = null;
		}
	} else if ( isPlainObject( actions[ abortKey ] ) ) {
		const currentAbortControllers = { ...actions[ abortKey ] };
		if ( currentAbortControllers[ abortId ] === controller ) {
			delete currentAbortControllers[ abortId ];
			actions[ abortKey ] =
				Object.keys( currentAbortControllers ).length > 0
					? currentAbortControllers
					: null;
		}
	}
}

function getEmptySuggestionResponse() {
	return {
		suggestions: [],
		explanation: '',
	};
}

async function runAbortableRecommendationRequest( {
	abortKey,
	abilityName,
	buildRequest = () => ( {} ),
	dispatch,
	input,
	onError,
	onLoading,
	onSuccess,
	registry,
	select,
} ) {
	const request = {
		...( buildRequest( { input, registry, select } ) || {} ),
	};
	const abortId =
		request.abortId === null || request.abortId === undefined
			? null
			: String( request.abortId );
	let requestData = request.requestData ?? input;
	const clientRequest = buildClientRequestIdentity( {
		abortId,
		requestData,
		requestToken: request.requestToken,
	} );
	requestData = attachClientRequestIdentity( requestData, clientRequest );
	const controller = new AbortController();

	if ( abortId === null ) {
		if ( actions[ abortKey ] ) {
			actions[ abortKey ].abort();
		}

		actions[ abortKey ] = controller;
	} else {
		const currentAbortControllers = isPlainObject( actions[ abortKey ] )
			? actions[ abortKey ]
			: {};

		currentAbortControllers[ abortId ]?.abort?.();
		currentAbortControllers[ abortId ] = controller;
		actions[ abortKey ] = currentAbortControllers;
	}

	request.requestData = requestData;
	onLoading?.( { dispatch, input, ...request } );

	const maxRetries = 1;

	for ( let attempt = 0; attempt <= maxRetries; attempt++ ) {
		try {
			const result = await executeFlavorAgentAbility(
				abilityName,
				requestData,
				{
					signal: controller.signal,
				}
			);

			clearAbortController( abortKey, abortId, controller );
			await onSuccess?.( {
				dispatch,
				input,
				result,
				...request,
			} );
			return;
		} catch ( err ) {
			if ( err?.name === 'AbortError' ) {
				clearAbortController( abortKey, abortId, controller );
				return;
			}

			if ( attempt < maxRetries && isRetryableRateLimitError( err ) ) {
				const retryAfter = getRetryAfterSeconds( err );
				await sleep( retryAfter * 1000 );
				continue;
			}

			clearAbortController( abortKey, abortId, controller );
			await onError?.( {
				dispatch,
				err,
				input,
				...request,
			} );
			return;
		}
	}
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

	setBlockApplyState(
		clientId,
		status,
		error = null,
		suggestionKey = null,
		staleReason = null
	) {
		return {
			type: 'SET_BLOCK_APPLY_STATE',
			clientId,
			status,
			error,
			suggestionKey,
			staleReason,
		};
	},

	setBlockRecommendations(
		clientId,
		recommendations,
		requestToken = null,
		contextSignature = null,
		diagnostics = null,
		resolvedContextSignature = null
	) {
		return {
			type: 'SET_BLOCK_RECS',
			clientId,
			recommendations,
			requestToken,
			contextSignature,
			diagnostics,
			resolvedContextSignature,
		};
	},

	clearBlockRecommendations( clientId ) {
		return ( { dispatch } ) => {
			if ( isPlainObject( actions._blockRecommendationAbort ) ) {
				actions._blockRecommendationAbort[ clientId ]?.abort?.();
			}

			dispatch( { type: 'CLEAR_BLOCK_RECS', clientId } );
		};
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
		timestamp = new Date().toISOString(),
		persistence = null
	) {
		return {
			type: 'UPDATE_ACTIVITY_UNDO_STATE',
			activityId,
			status,
			error,
			timestamp,
			persistence,
		};
	},

	clearUndoError() {
		return { type: 'CLEAR_UNDO_ERROR' };
	},

	setContentStatus( status, error = null, requestToken = null ) {
		return {
			type: 'SET_CONTENT_STATUS',
			status,
			error,
			requestToken,
		};
	},

	setContentRecommendation(
		payload,
		prompt = '',
		mode = 'draft',
		requestToken = null
	) {
		return {
			type: 'SET_CONTENT_RECOMMENDATION',
			payload,
			prompt,
			mode,
			requestToken,
		};
	},

	setContentMode( mode = 'draft' ) {
		return {
			type: 'SET_CONTENT_MODE',
			mode,
		};
	},

	clearContentError() {
		return { type: 'CLEAR_CONTENT_ERROR' };
	},

	clearContentRecommendation() {
		return ( { dispatch } ) => {
			if ( actions._contentAbort ) {
				actions._contentAbort.abort();
				actions._contentAbort = null;
			}

			dispatch( { type: 'CLEAR_CONTENT_RECOMMENDATION' } );
		};
	},

	loadActivitySession( options = {} ) {
		return createLoadActivitySessionAction( {
			runtime: actions,
			storeName: STORE_NAME,
			getCurrentActivityScope,
			setActivitySession: actions.setActivitySession,
		} )( options );
	},

	fetchBlockRecommendations( clientId, context, prompt = '' ) {
		return ( { dispatch, registry, select } ) =>
			runAbortableRecommendationRequest( {
				abortKey: '_blockRecommendationAbort',
				buildRequest: ( {
					input: requestInput,
					registry: requestRegistry,
					select: registrySelect,
				} ) => {
					const contextSignature =
						requestInput?.contextSignature || null;
					const document = getRequestDocumentFromScope(
						getCurrentActivityScope( requestRegistry )
					);

					return {
						abortId: requestInput?.clientId || null,
						clientId: requestInput?.clientId || '',
						contextSignature,
						requestData: {
							editorContext: requestInput?.context || {},
							prompt: requestInput?.prompt || '',
							clientId: requestInput?.clientId || '',
							...( document ? { document } : {} ),
						},
						requestToken:
							( registrySelect.getBlockRequestToken?.(
								requestInput?.clientId
							) || 0 ) + 1,
					};
				},
				dispatch,
				abilityName: 'flavor-agent/recommend-block',
				input: {
					clientId,
					context,
					contextSignature:
						buildBlockRecommendationContextSignature( context ),
					prompt,
				},
				registry,
				onError: ( {
					clientId: requestClientId,
					contextSignature,
					dispatch: localDispatch,
					err,
					requestData,
					requestToken,
				} ) => {
					const diagnostics =
						buildBlockRecommendationFailureDiagnostics(
							err,
							requestData,
							requestToken
						);

					localDispatch(
						actions.setBlockRequestState(
							requestClientId,
							'error',
							diagnostics.errorMessage || 'Request failed.',
							requestToken
						)
					);
					localDispatch(
						actions.setBlockRecommendations(
							requestClientId,
							{
								blockName:
									requestData.editorContext?.block?.name ||
									'',
								blockContext:
									requestData.editorContext?.block || {},
								prompt: requestData.prompt || '',
								settings: [],
								styles: [],
								block: [],
								explanation: '',
								requestMeta: diagnostics.requestMeta || null,
								timestamp: Date.now(),
							},
							requestToken,
							contextSignature,
							diagnostics
						)
					);
				},
				onLoading: ( {
					clientId: requestClientId,
					dispatch: localDispatch,
					requestToken,
				} ) => {
					localDispatch(
						actions.setBlockRequestState(
							requestClientId,
							'loading',
							null,
							requestToken
						)
					);
				},
				onSuccess: ( {
					clientId: requestClientId,
					contextSignature,
					dispatch: localDispatch,
					requestData,
					requestToken,
					result,
				} ) => {
					const resolvedContextSignature =
						getResolvedContextSignatureFromResponse( result );
					const payload = attachRequestMetaToRecommendationPayload(
						isPlainObject( result ) ? result : {}
					);
					const editorContext = requestData.editorContext || {};
					const blockOperationContext =
						editorContext.blockOperationContext || null;
					const blockContext = {
						...( editorContext.block || {} ),
						...( blockOperationContext
							? { blockOperationContext }
							: {} ),
					};
					const executionContract = payload.executionContract || null;
					const sanitizedPayload = sanitizeRecommendationsForContext(
						payload,
						blockContext,
						executionContract
					);
					const diagnosticsBase = buildBlockRecommendationDiagnostics(
						payload,
						sanitizedPayload,
						blockContext,
						executionContract
					);
					const requestMeta = normalizeRequestMeta(
						payload.requestMeta
					);
					const diagnostics = diagnosticsBase
						? {
								...diagnosticsBase,
								clientId: requestClientId,
								blockName:
									requestData.editorContext?.block?.name ||
									'',
								prompt: requestData.prompt || '',
								requestToken,
								timestamp: new Date().toISOString(),
								requestMeta,
						  }
						: null;

					localDispatch(
						actions.setBlockRecommendations(
							requestClientId,
							{
								blockName:
									requestData.editorContext?.block?.name ||
									'',
								blockContext,
								blockOperationContext,
								executionContract,
								prompt: requestData.prompt || '',
								...sanitizedPayload,
								requestMeta,
								timestamp: Date.now(),
							},
							requestToken,
							contextSignature,
							diagnostics,
							resolvedContextSignature
						)
					);
					localDispatch(
						actions.setBlockRequestState(
							requestClientId,
							'ready',
							null,
							requestToken
						)
					);
				},
				select,
			} );
	},

	applySuggestion(
		clientId,
		suggestion,
		currentRequestSignature = null,
		liveRequestInput = null
	) {
		return async ( { dispatch: localDispatch, registry, select } ) => {
			if ( select.getBlockApplyStatus?.( clientId ) === 'applying' ) {
				return false;
			}

			const scope = getCurrentActivityScope( registry );
			const applyErrorMessage =
				'This suggestion includes unsupported or unsafe attribute changes and could not be applied.';
			const advisoryApplyMessage =
				'This suggestion is advisory and requires manual follow-through or a broader preview/apply flow.';

			syncStoreActivitySession( localDispatch, select, scope );

			const staleApplyResult = guardSurfaceApplyFreshness( {
				surface: 'block',
				currentRequestSignature,
				getStoredRequestSignature: () =>
					buildBlockRecommendationRequestSignature( {
						clientId,
						prompt:
							select.getBlockRecommendations?.( clientId )
								?.prompt || '',
						contextSignature:
							select.getBlockRecommendationContextSignature?.(
								clientId
							) || null,
					} ),
				localDispatch,
				setApplyState: ( status, error, staleReason = null ) =>
					actions.setBlockApplyState(
						clientId,
						status,
						error,
						null,
						staleReason
					),
			} );

			if ( staleApplyResult ) {
				return false;
			}

			localDispatch( actions.setBlockApplyState( clientId, 'applying' ) );

			const resolvedFreshness = await guardSurfaceApplyResolvedFreshness(
				{
					surface: 'block',
					abilityName: 'flavor-agent/recommend-block',
					liveRequestInput,
					storedResolvedContextSignature:
						select.getBlockResolvedContextSignature?.( clientId ) ||
						null,
					localDispatch,
					setApplyState: ( status, error, staleReason = null ) =>
						actions.setBlockApplyState(
							clientId,
							status,
							error,
							null,
							staleReason
						),
				}
			);

			if ( ! resolvedFreshness.ok ) {
				return false;
			}

			try {
				const storedRecommendationPayload =
					select.getBlockRecommendations( clientId ) || null;
				const storedRecommendations = storedRecommendationPayload || {};
				const blockContext = storedRecommendations.blockContext || {};
				const executionContract =
					storedRecommendations.executionContract || null;
				const blockEditorSelect =
					registry?.select?.( 'core/block-editor' ) || {};
				const blockEditorDispatch =
					registry?.dispatch?.( 'core/block-editor' ) || {};
				const currentAttributes =
					blockEditorSelect.getBlockAttributes?.( clientId ) || {};
				const execution = getBlockSuggestionExecutionInfo(
					suggestion,
					blockContext,
					executionContract
				);
				const allowedUpdates = execution.allowedUpdates;
				let nextAttributes = null;
				let didApply = false;
				let isNoOp = false;

				if ( execution.isAdvisoryOnly ) {
					localDispatch(
						actions.setBlockApplyState(
							clientId,
							'error',
							advisoryApplyMessage
						)
					);
					return false;
				}

				if ( Object.keys( allowedUpdates ).length > 0 ) {
					const safeUpdates = buildSafeAttributeUpdates(
						currentAttributes,
						allowedUpdates
					);
					const proposedNextAttributes = {
						...currentAttributes,
						...safeUpdates,
					};

					if (
						Object.keys( safeUpdates ).length > 0 &&
						attributeSnapshotsMatch(
							currentAttributes,
							proposedNextAttributes
						)
					) {
						isNoOp = true;
					}

					if (
						Object.keys( safeUpdates ).length > 0 &&
						! isNoOp &&
						typeof blockEditorDispatch.updateBlockAttributes ===
							'function'
					) {
						nextAttributes = proposedNextAttributes;
						blockEditorDispatch.updateBlockAttributes(
							clientId,
							safeUpdates
						);
						didApply = true;
					}
				}

				if ( ! didApply ) {
					if ( isNoOp ) {
						localDispatch(
							actions.setBlockApplyState( clientId, 'idle' )
						);
						return false;
					}

					localDispatch(
						actions.setBlockApplyState(
							clientId,
							'error',
							applyErrorMessage
						)
					);
					return false;
				}

				const persistedBlockEntry = await logStoreActivityEntry(
					localDispatch,
					select,
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
						requestMeta:
							suggestion?.requestMeta ||
							storedRecommendations.requestMeta ||
							null,
						requestToken:
							select.getBlockRequestToken( clientId ) || 0,
						scope,
						suggestion,
					} )
				);

				localDispatch(
					actions.setBlockApplyState(
						clientId,
						'success',
						null,
						suggestion?.suggestionKey || suggestion?.label || null
					)
				);

				localDispatch(
					actions.enqueueToast(
						buildToastForActivity( {
							surface: 'block',
							persistedEntry: persistedBlockEntry,
							suggestion,
							extras: { blockContext },
						} )
					)
				);

				return true;
			} catch ( error ) {
				localDispatch(
					actions.setBlockApplyState(
						clientId,
						'error',
						error?.message || applyErrorMessage
					)
				);
				throw error;
			}
		};
	},

	applyBlockStructuralSuggestion(
		clientId,
		suggestion,
		currentRequestSignature = null,
		liveRequestInput = null
	) {
		return async ( { dispatch: localDispatch, registry, select } ) => {
			if ( select.getBlockApplyStatus?.( clientId ) === 'applying' ) {
				return false;
			}

			const scope = getCurrentActivityScope( registry );

			syncStoreActivitySession( localDispatch, select, scope );

			const staleApplyResult = guardSurfaceApplyFreshness( {
				surface: 'block',
				currentRequestSignature,
				getStoredRequestSignature: () =>
					buildBlockRecommendationRequestSignature( {
						clientId,
						prompt:
							select.getBlockRecommendations?.( clientId )
								?.prompt || '',
						contextSignature:
							select.getBlockRecommendationContextSignature?.(
								clientId
							) || null,
					} ),
				localDispatch,
				setApplyState: ( status, error, staleReason = null ) =>
					actions.setBlockApplyState(
						clientId,
						status,
						error,
						null,
						staleReason
					),
			} );

			if ( staleApplyResult ) {
				return false;
			}

			localDispatch( actions.setBlockApplyState( clientId, 'applying' ) );

			const resolvedFreshness = await guardSurfaceApplyResolvedFreshness(
				{
					surface: 'block',
					abilityName: 'flavor-agent/recommend-block',
					liveRequestInput,
					storedResolvedContextSignature:
						select.getBlockResolvedContextSignature?.( clientId ) ||
						null,
					localDispatch,
					setApplyState: ( status, error, staleReason = null ) =>
						actions.setBlockApplyState(
							clientId,
							status,
							error,
							null,
							staleReason
						),
				}
			);

			if ( ! resolvedFreshness.ok ) {
				return false;
			}

			try {
				const storedRecommendationPayload =
					select.getBlockRecommendations( clientId ) || null;
				const storedRecommendations = storedRecommendationPayload || {};
				const blockEditorSelect =
					registry?.select?.( 'core/block-editor' ) || {};
				const blockEditorDispatch =
					registry?.dispatch?.( 'core/block-editor' ) || {};
				const blockContext =
					liveRequestInput?.editorContext?.block ||
					storedRecommendations.blockContext ||
					{};
				const blockOperationContext =
					liveRequestInput?.editorContext?.blockOperationContext ||
					storedRecommendations.blockOperationContext ||
					null;

				if ( ! blockOperationContext ) {
					const error =
						getBlockStructuralActionErrorMessage(
							'operation_invalid'
						);

					localDispatch(
						actions.setBlockApplyState( clientId, 'error', error )
					);

					return false;
				}

				const targetClientId =
					blockOperationContext.targetClientId || clientId;
				const targetRootClientId =
					blockEditorSelect.getBlockRootClientId?.(
						targetClientId
					) || null;
				const blockPath = findBlockPath(
					blockEditorSelect.getBlocks?.() || [],
					targetClientId
				);

				const result = {
					...applyBlockStructuralSuggestionOperations( {
						suggestion,
						blockOperationContext,
						blockEditorSelect,
						blockEditorDispatch,
						parsePatternBlocks: createBlockStructuralPatternParser(
							blockEditorSelect,
							targetRootClientId
						),
					} ),
					blockPath,
				};

				if ( ! result.ok ) {
					localDispatch(
						actions.setBlockApplyState(
							clientId,
							'error',
							result.error ||
								getBlockStructuralActionErrorMessage(
									result.code
								)
						)
					);

					return false;
				}

				const persistedStructuralEntry = await logStoreActivityEntry(
					localDispatch,
					select,
					buildBlockStructuralActivityEntry( {
						blockContext,
						clientId,
						requestPrompt: storedRecommendations.prompt || '',
						requestMeta:
							suggestion?.requestMeta ||
							storedRecommendations.requestMeta ||
							null,
						requestToken:
							select.getBlockRequestToken( clientId ) || 0,
						blockPath: result.blockPath,
						result,
						scope,
						suggestion,
					} )
				);

				localDispatch(
					actions.setBlockApplyState(
						clientId,
						'success',
						null,
						suggestion?.suggestionKey || suggestion?.label || null
					)
				);

				localDispatch(
					actions.enqueueToast(
						buildToastForActivity( {
							surface: 'block',
							persistedEntry: persistedStructuralEntry,
							suggestion,
							extras: {
								blockContext,
								result,
								operations: result?.operations,
							},
						} )
					)
				);

				return true;
			} catch ( error ) {
				localDispatch(
					actions.setBlockApplyState(
						clientId,
						'error',
						error?.message ||
							getBlockStructuralActionErrorMessage(
								'operation_invalid'
							)
					)
				);
				throw error;
			}
		};
	},

	setPatternStatus(
		status,
		error = null,
		requestToken = null,
		requestSignature = ''
	) {
		return {
			type: 'SET_PATTERN_STATUS',
			status,
			error,
			requestToken,
			requestSignature,
		};
	},

	setPatternRecommendations(
		recommendations,
		requestToken = null,
		requestSignature = '',
		diagnostics = null
	) {
		return {
			type: 'SET_PATTERN_RECS',
			recommendations,
			requestToken,
			requestSignature,
			diagnostics,
		};
	},

	setNavigationStatus(
		status,
		error = null,
		requestToken = null,
		blockClientId = null
	) {
		return {
			type: 'SET_NAVIGATION_STATUS',
			status,
			error,
			requestToken,
			blockClientId,
		};
	},

	setNavigationRecommendations(
		blockClientId,
		payload,
		prompt = '',
		requestToken = null,
		contextSignature = null,
		reviewContextSignature = null
	) {
		return {
			type: 'SET_NAVIGATION_RECS',
			blockClientId,
			payload,
			prompt,
			requestToken,
			contextSignature,
			reviewContextSignature,
		};
	},

	setNavigationReviewFreshnessState(
		status,
		requestToken = null,
		staleReason = null
	) {
		return {
			type: 'SET_NAVIGATION_REVIEW_FRESHNESS_STATE',
			status,
			requestToken,
			staleReason,
		};
	},

	clearNavigationError() {
		return { type: 'CLEAR_NAVIGATION_ERROR' };
	},

	clearNavigationRecommendations() {
		return ( { dispatch } ) => {
			if ( actions._navigationAbort ) {
				actions._navigationAbort.abort();
				actions._navigationAbort = null;
			}

			dispatch( { type: 'CLEAR_NAVIGATION_RECS' } );
		};
	},

	fetchPatternRecommendations( input ) {
		return ( { dispatch, registry, select } ) =>
			runAbortableRecommendationRequest( {
				abortKey: '_patternAbort',
				buildRequest: ( {
					input: requestInput,
					select: registrySelect,
				} ) => {
					const requestData = {
						...( requestInput || {} ),
						document: getRequestDocumentFromScope(
							getCurrentActivityScope( registry )
						),
					};
					const requestToken =
						( registrySelect.getPatternRequestToken?.() || 0 ) + 1;
					const requestSignature =
						buildPatternRecommendationRequestSignature(
							requestData
						);

					return {
						requestData,
						requestToken,
						requestSignature,
					};
				},
				dispatch,
				abilityName: 'flavor-agent/recommend-patterns',
				input,
				registry,
				onError: ( {
					dispatch: localDispatch,
					err,
					requestSignature,
					requestToken,
				} ) => {
					localDispatch(
						actions.setPatternRecommendations(
							[],
							requestToken,
							requestSignature
						)
					);
					localDispatch(
						actions.setPatternStatus(
							'error',
							err?.message ||
								'Pattern recommendation request failed.',
							requestToken,
							requestSignature
						)
					);
					return reloadStoreActivitySession(
						localDispatch,
						registry,
						select
					);
				},
				onLoading: ( {
					dispatch: localDispatch,
					requestSignature,
					requestToken,
				} ) => {
					localDispatch(
						actions.setPatternStatus(
							'loading',
							null,
							requestToken,
							requestSignature
						)
					);
				},
				onSuccess: ( {
					dispatch: localDispatch,
					requestSignature,
					requestToken,
					result,
				} ) => {
					localDispatch(
						actions.setPatternRecommendations(
							result.recommendations || [],
							requestToken,
							requestSignature,
							result.diagnostics || null
						)
					);
					localDispatch(
						actions.setPatternStatus(
							'ready',
							null,
							requestToken,
							requestSignature
						)
					);
					return reloadStoreActivitySession(
						localDispatch,
						registry,
						select
					);
				},
				select,
			} );
	},

	fetchContentRecommendations( input ) {
		return ( { dispatch, registry, select } ) =>
			runAbortableRecommendationRequest( {
				abortKey: '_contentAbort',
				buildRequest: ( {
					input: requestInput,
					select: registrySelect,
				} ) => ( {
					requestData: {
						...( requestInput || {} ),
						document: getRequestDocumentFromScope(
							getCurrentActivityScope( registry )
						),
					},
					requestToken:
						( registrySelect.getContentRequestToken?.() || 0 ) + 1,
				} ),
				dispatch,
				abilityName: 'flavor-agent/recommend-content',
				input,
				registry,
				onError: ( { dispatch: localDispatch, err, requestToken } ) => {
					localDispatch(
						actions.setContentStatus(
							'error',
							err?.message ||
								'Content recommendation request failed.',
							requestToken
						)
					);
					return reloadStoreActivitySession(
						localDispatch,
						registry,
						select
					);
				},
				onLoading: ( { dispatch: localDispatch, requestToken } ) => {
					localDispatch(
						actions.setContentStatus(
							'loading',
							null,
							requestToken
						)
					);
				},
				onSuccess: ( {
					dispatch: localDispatch,
					requestData,
					requestToken,
					result,
				} ) => {
					localDispatch(
						actions.setContentRecommendation(
							result,
							requestData.prompt || '',
							requestData.mode || 'draft',
							requestToken
						)
					);
					return reloadStoreActivitySession(
						localDispatch,
						registry,
						select
					);
				},
				select,
			} );
	},

	fetchNavigationRecommendations( input ) {
		return ( { dispatch, registry, select } ) =>
			runAbortableRecommendationRequest( {
				abortKey: '_navigationAbort',
				buildRequest: ( {
					input: requestInput,
					select: registrySelect,
				} ) => {
					const requestToken =
						( registrySelect.getNavigationRequestToken?.() || 0 ) +
						1;
					const {
						blockClientId = null,
						contextSignature = null,
						...requestData
					} = requestInput || {};

					return {
						blockClientId,
						contextSignature,
						requestData: {
							...requestData,
							document: getRequestDocumentFromScope(
								getCurrentActivityScope( registry )
							),
						},
						requestToken,
					};
				},
				dispatch,
				abilityName: 'flavor-agent/recommend-navigation',
				input,
				registry,
				onError: ( {
					blockClientId,
					contextSignature,
					dispatch: localDispatch,
					err,
					requestData,
					requestToken,
				} ) => {
					localDispatch(
						actions.setNavigationRecommendations(
							blockClientId,
							getEmptySuggestionResponse(),
							requestData.prompt || '',
							requestToken,
							contextSignature,
							null
						)
					);
					localDispatch(
						actions.setNavigationStatus(
							'error',
							err?.message ||
								'Navigation recommendation request failed.',
							requestToken,
							blockClientId
						)
					);
					return reloadStoreActivitySession(
						localDispatch,
						registry,
						select
					);
				},
				onLoading: ( {
					blockClientId,
					dispatch: localDispatch,
					requestToken,
				} ) => {
					localDispatch(
						actions.setNavigationStatus(
							'loading',
							null,
							requestToken,
							blockClientId
						)
					);
				},
				onSuccess: ( {
					blockClientId,
					contextSignature,
					dispatch: localDispatch,
					requestData,
					requestToken,
					result,
				} ) => {
					localDispatch(
						actions.setNavigationRecommendations(
							blockClientId,
							result,
							requestData.prompt || '',
							requestToken,
							contextSignature,
							getReviewContextSignatureFromResponse( result )
						)
					);
					return reloadStoreActivitySession(
						localDispatch,
						registry,
						select
					);
				},
				select,
			} );
	},

	undoActivity( activityId ) {
		return createUndoActivityAction( {
			getCurrentActivityScope,
			setActivitySession: actions.setActivitySession,
			setUndoState: actions.setUndoState,
			updateActivityUndoState: actions.updateActivityUndoState,
		} )( activityId );
	},

	revalidateBlockReviewFreshness(
		clientId,
		requestInput = null,
		options = {}
	) {
		return async ( { dispatch, select } ) => {
			if ( ! clientId || ! requestInput ) {
				return;
			}

			const requestSignature = normalizeStringMessage(
				options?.requestSignature
			);
			const requestToken = Number.isFinite( options?.requestToken )
				? options.requestToken
				: null;
			const getStoredRequestSignature = () =>
				buildBlockRecommendationRequestSignature( {
					clientId,
					prompt:
						select.getBlockRecommendations?.( clientId )?.prompt ||
						'',
					contextSignature:
						select.getBlockRecommendationContextSignature?.(
							clientId
						) || null,
				} );
			const storedResolvedSig = normalizeStringMessage(
				select.getBlockResolvedContextSignature?.( clientId ) || ''
			);
			const storedRequestSignature = getStoredRequestSignature();
			const storedRequestToken =
				select.getBlockRequestToken?.( clientId ) || 0;

			if (
				! storedResolvedSig ||
				( requestSignature &&
					storedRequestSignature !== requestSignature ) ||
				( requestToken !== null && requestToken !== storedRequestToken )
			) {
				return;
			}

			try {
				const response = await executeFlavorAgentAbility(
					'flavor-agent/recommend-block',
					{
						...requestInput,
						resolveSignatureOnly: true,
					}
				);

				const serverSig =
					getResolvedContextSignatureFromResponse( response ) || '';
				const currentResolvedSig = normalizeStringMessage(
					select.getBlockResolvedContextSignature?.( clientId ) || ''
				);
				const currentRequestToken =
					select.getBlockRequestToken?.( clientId ) || 0;
				const currentRequestSignature = getStoredRequestSignature();

				if (
					! currentResolvedSig ||
					currentResolvedSig !== storedResolvedSig ||
					( requestSignature &&
						currentRequestSignature !== requestSignature ) ||
					( requestToken !== null &&
						currentRequestToken !== requestToken )
				) {
					return;
				}

				if (
					serverSig &&
					currentResolvedSig &&
					serverSig !== currentResolvedSig
				) {
					dispatch(
						actions.setBlockApplyState(
							clientId,
							'idle',
							null,
							null,
							'server'
						)
					);
				} else if (
					serverSig === currentResolvedSig &&
					select.getBlockStaleReason?.( clientId ) === 'server'
				) {
					dispatch( actions.setBlockApplyState( clientId, 'idle' ) );
				}
			} catch {
				// Background revalidation failures are silent.
			}
		};
	},

	revalidateNavigationReviewFreshness(
		currentRequestSignature = null,
		liveRequestInput = null
	) {
		return buildExecutableSurfaceReviewFreshnessThunk(
			getNavigationReviewConfig(),
			currentRequestSignature,
			liveRequestInput,
			EXECUTABLE_SURFACE_REVIEW_DEPS
		);
	},
};

Object.assign( actions, createExecutableSurfaceStateActionCreators( actions ) );
Object.assign( actions, toastsActionCreators );
Object.assign( actions, {
	undoToastAction( toastId, activityId ) {
		return async ( { dispatch: localDispatch } ) => {
			if ( ! activityId ) {
				localDispatch( actions.dismissToast( toastId ) );
				return false;
			}

			try {
				await localDispatch( actions.undoActivity( activityId ) );
				localDispatch( actions.dismissToast( toastId ) );
				return true;
			} catch ( error ) {
				localDispatch(
					actions.updateToast( toastId, {
						variant: 'error',
						title: 'Undo failed',
						errorHint:
							error?.message ||
							'The change could not be reverted.',
						autoDismissMs: TOAST_DEFAULTS.errorMs,
						interacted: false,
					} )
				);
				return false;
			}
		};
	},
} );
Object.assign(
	actions,
	createExecutableSurfaceRuntimeActionCreators( actions, {
		fetchDeps: EXECUTABLE_SURFACE_FETCH_DEPS,
		applyDeps: EXECUTABLE_SURFACE_APPLY_DEPS,
		reviewDeps: EXECUTABLE_SURFACE_REVIEW_DEPS,
	} )
);

function reducer( state = DEFAULT_STATE, action ) {
	const executableSurfaceState = reduceExecutableSurfaceState(
		state,
		action
	);

	if ( executableSurfaceState ) {
		return executableSurfaceState;
	}

	const toastsState = reduceToastsState( state, action );

	if ( toastsState ) {
		return toastsState;
	}

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
						diagnostics:
							action.status === 'loading' ||
							action.status === 'error'
								? null
								: currentEntry.diagnostics ?? null,
						contextSignature:
							action.contextSignature ??
							currentEntry.contextSignature,
						applyStatus:
							action.status === 'loading'
								? 'idle'
								: currentEntry.applyStatus,
						applyError:
							action.status === 'loading'
								? null
								: currentEntry.applyError,
						lastAppliedSuggestionKey:
							action.status === 'loading'
								? null
								: currentEntry.lastAppliedSuggestionKey,
						resolvedContextSignature:
							action.status === 'loading'
								? null
								: currentEntry.resolvedContextSignature,
						staleReason:
							action.status === 'loading'
								? null
								: currentEntry.staleReason,
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
				blockRequestState: {
					...state.blockRequestState,
					[ action.clientId ]: {
						...getStoredBlockRequestState( state, action.clientId ),
						contextSignature: action.contextSignature ?? null,
						resolvedContextSignature:
							action.resolvedContextSignature ?? null,
						diagnostics: normalizeBlockRequestDiagnostics(
							action.diagnostics
						),
						applyStatus: 'idle',
						applyError: null,
						lastAppliedSuggestionKey: null,
						staleReason: null,
					},
				},
			};
		case 'SET_BLOCK_APPLY_STATE': {
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
						applyStatus: action.status,
						applyError: action.error ?? null,
						lastAppliedSuggestionKey:
							action.status === 'success'
								? action.suggestionKey ?? null
								: currentEntry.lastAppliedSuggestionKey,
						staleReason:
							action.status === 'error' ||
							( action.status === 'idle' &&
								action.staleReason !== null )
								? action.staleReason ?? null
								: null,
					},
				},
			};
		}
		case 'CLEAR_BLOCK_RECS': {
			const hasExistingState = Boolean(
				state.blockRecommendations[ action.clientId ] ||
					state.blockRequestState[ action.clientId ]
			);

			if ( ! hasExistingState ) {
				return state;
			}

			const currentEntry = getStoredBlockRequestState(
				state,
				action.clientId
			);
			const nextRecommendations = { ...state.blockRecommendations };
			const nextRequestState = {
				...state.blockRequestState,
				[ action.clientId ]: {
					...DEFAULT_BLOCK_REQUEST_STATE,
					requestToken: currentEntry.requestToken + 1,
				},
			};

			delete nextRecommendations[ action.clientId ];

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
						applyStatus:
							currentEntry.applyStatus === 'error'
								? 'idle'
								: currentEntry.applyStatus,
						applyError: null,
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
			const isGlobalStylesUndone =
				action.status === 'undone' &&
				matchedEntry?.surface === 'global-styles';
			const isStyleBookUndone =
				action.status === 'undone' &&
				matchedEntry?.surface === 'style-book';

			return {
				...state,
				activityLog: state.activityLog.map( ( entry ) => {
					if ( entry?.id !== action.activityId ) {
						return entry;
					}

					const nextPersistence = action.persistence
						? {
								...( entry.persistence || {} ),
								...action.persistence,
								syncType:
									action.persistence.status === 'server'
										? null
										: action.persistence.syncType ||
										  entry?.persistence?.syncType ||
										  'undo',
								updatedAt:
									action.persistence.updatedAt ||
									action.timestamp,
						  }
						: entry.persistence;

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
						persistence: nextPersistence,
					};
				} ),
				templateApplyStatus: isTemplateUndone
					? 'idle'
					: state.templateApplyStatus,
				templateApplyError: isTemplateUndone
					? null
					: state.templateApplyError,
				templateLastAppliedSuggestionKey: isTemplateUndone
					? null
					: state.templateLastAppliedSuggestionKey,
				templateLastAppliedOperations: isTemplateUndone
					? []
					: state.templateLastAppliedOperations,
				templatePartApplyStatus: isTemplatePartUndone
					? 'idle'
					: state.templatePartApplyStatus,
				templatePartApplyError: isTemplatePartUndone
					? null
					: state.templatePartApplyError,
				templatePartLastAppliedSuggestionKey: isTemplatePartUndone
					? null
					: state.templatePartLastAppliedSuggestionKey,
				templatePartLastAppliedOperations: isTemplatePartUndone
					? []
					: state.templatePartLastAppliedOperations,
				globalStylesApplyStatus: isGlobalStylesUndone
					? 'idle'
					: state.globalStylesApplyStatus,
				globalStylesApplyError: isGlobalStylesUndone
					? null
					: state.globalStylesApplyError,
				globalStylesLastAppliedSuggestionKey: isGlobalStylesUndone
					? null
					: state.globalStylesLastAppliedSuggestionKey,
				globalStylesLastAppliedOperations: isGlobalStylesUndone
					? []
					: state.globalStylesLastAppliedOperations,
				styleBookApplyStatus: isStyleBookUndone
					? 'idle'
					: state.styleBookApplyStatus,
				styleBookApplyError: isStyleBookUndone
					? null
					: state.styleBookApplyError,
				styleBookLastAppliedSuggestionKey: isStyleBookUndone
					? null
					: state.styleBookLastAppliedSuggestionKey,
				styleBookLastAppliedOperations: isStyleBookUndone
					? []
					: state.styleBookLastAppliedOperations,
			};
		}
		case 'SET_PATTERN_STATUS':
			if (
				isStalePatternRequest(
					state,
					action.requestToken,
					action.requestSignature
				)
			) {
				return state;
			}

			return {
				...state,
				patternStatus: action.status,
				patternError: action.error ?? null,
				patternRequestToken:
					action.requestToken ?? state.patternRequestToken,
				patternRequestSignature:
					action.requestSignature || state.patternRequestSignature,
			};
		case 'SET_CONTENT_STATUS':
			if ( action.requestToken < ( state.contentRequestToken || 0 ) ) {
				return state;
			}

			return {
				...state,
				contentStatus: action.status,
				contentError: action.error ?? null,
				contentRequestToken:
					action.requestToken ?? state.contentRequestToken,
				contentRecommendation:
					action.status === 'loading'
						? null
						: state.contentRecommendation,
			};
		case 'SET_CONTENT_RECOMMENDATION':
			if ( action.requestToken < ( state.contentRequestToken || 0 ) ) {
				return state;
			}

			return {
				...state,
				contentRecommendation: action.payload || null,
				contentRequestPrompt: action.prompt ?? '',
				contentMode: action.mode || 'draft',
				contentRequestToken:
					action.requestToken ?? state.contentRequestToken,
				contentResultToken: state.contentResultToken + 1,
				contentStatus: 'ready',
				contentError: null,
			};
		case 'SET_CONTENT_MODE':
			return {
				...state,
				contentMode: [ 'draft', 'edit', 'critique' ].includes(
					action.mode
				)
					? action.mode
					: 'draft',
			};
		case 'CLEAR_CONTENT_ERROR':
			return {
				...state,
				contentStatus:
					state.contentStatus === 'error'
						? 'idle'
						: state.contentStatus,
				contentError: null,
			};
		case 'CLEAR_CONTENT_RECOMMENDATION':
			return {
				...state,
				contentRecommendation: null,
				contentStatus: 'idle',
				contentError: null,
				contentRequestPrompt: '',
				contentRequestToken: state.contentRequestToken + 1,
				contentResultToken: state.contentResultToken + 1,
			};
		case 'SET_PATTERN_RECS':
			if (
				isStalePatternRequest(
					state,
					action.requestToken,
					action.requestSignature
				)
			) {
				return state;
			}

			return {
				...state,
				patternRecommendations: action.recommendations,
				patternDiagnostics: normalizePatternDiagnostics(
					action.diagnostics
				),
				patternError: null,
				patternRequestToken:
					action.requestToken ?? state.patternRequestToken,
				patternResultToken: state.patternResultToken + 1,
				patternRequestSignature:
					action.requestSignature || state.patternRequestSignature,
			};
		case 'SET_NAVIGATION_STATUS':
			if ( isStaleNavigationRequest( state, action.requestToken ) ) {
				return state;
			}

			return {
				...state,
				navigationStatus: action.status,
				navigationError: action.error ?? null,
				navigationRequestToken:
					action.requestToken ?? state.navigationRequestToken,
				navigationBlockClientId:
					action.blockClientId ?? state.navigationBlockClientId,
				navigationReviewRequestToken:
					action.status === 'loading'
						? state.navigationReviewRequestToken + 1
						: state.navigationReviewRequestToken,
				navigationReviewFreshnessStatus:
					action.status === 'loading'
						? 'idle'
						: state.navigationReviewFreshnessStatus,
				navigationReviewStaleReason:
					action.status === 'loading'
						? null
						: state.navigationReviewStaleReason,
			};
		case 'SET_NAVIGATION_RECS':
			if ( isStaleNavigationRequest( state, action.requestToken ) ) {
				return state;
			}

			return {
				...state,
				navigationRecommendations: action.payload?.suggestions ?? [],
				navigationExplanation: action.payload?.explanation ?? '',
				navigationRequestPrompt: action.prompt ?? '',
				navigationBlockClientId: action.blockClientId ?? null,
				navigationContextSignature: action.contextSignature ?? null,
				navigationReviewContextSignature:
					action.reviewContextSignature ?? null,
				navigationRequestToken:
					action.requestToken ?? state.navigationRequestToken,
				navigationResultToken: state.navigationResultToken + 1,
				navigationReviewRequestToken:
					state.navigationReviewRequestToken + 1,
				navigationReviewFreshnessStatus: action.reviewContextSignature
					? 'fresh'
					: 'idle',
				navigationStatus: 'ready',
				navigationError: null,
				navigationReviewStaleReason: null,
			};
		case 'SET_NAVIGATION_REVIEW_FRESHNESS_STATE':
			if (
				isStaleNavigationReviewRequest( state, action.requestToken )
			) {
				return state;
			}

			let nextNavigationReviewStaleReason =
				state.navigationReviewStaleReason;

			if ( action.status === 'stale' ) {
				nextNavigationReviewStaleReason = action.staleReason ?? null;
			} else if ( action.status === 'fresh' ) {
				nextNavigationReviewStaleReason = null;
			}

			return {
				...state,
				navigationReviewRequestToken:
					action.requestToken ?? state.navigationReviewRequestToken,
				navigationReviewFreshnessStatus:
					action.status ?? state.navigationReviewFreshnessStatus,
				navigationReviewStaleReason: nextNavigationReviewStaleReason,
			};
		case 'CLEAR_NAVIGATION_ERROR':
			return {
				...state,
				navigationStatus:
					state.navigationStatus === 'error'
						? 'idle'
						: state.navigationStatus,
				navigationError: null,
			};
		case 'CLEAR_NAVIGATION_RECS':
			return {
				...state,
				navigationRecommendations: [],
				navigationExplanation: '',
				navigationStatus: 'idle',
				navigationError: null,
				navigationRequestPrompt: '',
				navigationBlockClientId: null,
				navigationContextSignature: null,
				navigationReviewContextSignature: null,
				navigationRequestToken: state.navigationRequestToken + 1,
				navigationResultToken: state.navigationResultToken + 1,
				navigationReviewRequestToken:
					state.navigationReviewRequestToken + 1,
				navigationReviewFreshnessStatus: 'idle',
				navigationReviewStaleReason: null,
			};
		default:
			return state;
	}
}

const executableSurfaceSelectors = createExecutableSurfaceSelectors( {
	getNormalizedInteractionState,
	normalizeStringMessage,
} );

const selectors = {
	getBlockRequestState: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ),
	getBlockStatus: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).status,
	getBlockError: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).error,
	getBlockRequestToken: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).requestToken,
	getBlockRecommendationContextSignature: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).contextSignature,
	getBlockResolvedContextSignature: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).resolvedContextSignature,
	getBlockRequestDiagnostics: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).diagnostics,
	getBlockApplyStatus: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).applyStatus,
	getBlockApplyError: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).applyError,
	getBlockLastAppliedSuggestionKey: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).lastAppliedSuggestionKey,
	getBlockStaleReason: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).staleReason,
	isBlockLoading: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).status === 'loading',
	isBlockApplying: ( state, clientId ) =>
		getStoredBlockRequestState( state, clientId ).applyStatus ===
		'applying',
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
	getPatternDiagnostics: ( state ) => state.patternDiagnostics,
	getPatternStatus: ( state ) => state.patternStatus,
	getPatternError: ( state ) => state.patternError,
	getPatternRequestToken: ( state ) => state.patternRequestToken,
	getPatternResultToken: ( state ) => state.patternResultToken,
	getPatternRequestSignature: ( state ) => state.patternRequestSignature,
	isPatternLoading: ( state ) => state.patternStatus === 'loading',
	getContentRecommendation: ( state ) => state.contentRecommendation,
	getContentStatus: ( state ) => state.contentStatus,
	getContentError: ( state ) => state.contentError,
	getContentMode: ( state ) => state.contentMode,
	getContentRequestPrompt: ( state ) => state.contentRequestPrompt,
	getContentRequestToken: ( state ) => state.contentRequestToken,
	getContentResultToken: ( state ) => state.contentResultToken,
	isContentLoading: ( state ) => state.contentStatus === 'loading',
	getNavigationRecommendations: ( state, blockClientId = null ) =>
		blockClientId && state.navigationBlockClientId !== blockClientId
			? []
			: state.navigationRecommendations,
	getNavigationExplanation: ( state, blockClientId = null ) =>
		blockClientId && state.navigationBlockClientId !== blockClientId
			? ''
			: state.navigationExplanation,
	getNavigationError: ( state, blockClientId = null ) =>
		blockClientId && state.navigationBlockClientId !== blockClientId
			? null
			: state.navigationError,
	getNavigationStatus: ( state, blockClientId = null ) =>
		blockClientId && state.navigationBlockClientId !== blockClientId
			? 'idle'
			: state.navigationStatus,
	getNavigationRequestPrompt: ( state, blockClientId = null ) =>
		blockClientId && state.navigationBlockClientId !== blockClientId
			? ''
			: state.navigationRequestPrompt,
	getNavigationBlockClientId: ( state ) => state.navigationBlockClientId,
	getNavigationContextSignature: ( state, blockClientId = null ) =>
		blockClientId && state.navigationBlockClientId !== blockClientId
			? null
			: state.navigationContextSignature,
	getNavigationReviewContextSignature: ( state, blockClientId = null ) =>
		blockClientId && state.navigationBlockClientId !== blockClientId
			? null
			: state.navigationReviewContextSignature,
	getNavigationRequestToken: ( state ) => state.navigationRequestToken,
	getNavigationReviewRequestToken: ( state ) =>
		state.navigationReviewRequestToken,
	getNavigationResultToken: ( state, blockClientId = null ) =>
		blockClientId && state.navigationBlockClientId !== blockClientId
			? 0
			: state.navigationResultToken,
	getNavigationReviewFreshnessStatus: ( state, blockClientId = null ) =>
		blockClientId && state.navigationBlockClientId !== blockClientId
			? 'idle'
			: state.navigationReviewFreshnessStatus,
	getNavigationReviewStaleReason: ( state, blockClientId = null ) =>
		blockClientId && state.navigationBlockClientId !== blockClientId
			? null
			: state.navigationReviewStaleReason,
	isNavigationLoading: ( state, blockClientId = null ) =>
		( ! blockClientId ||
			state.navigationBlockClientId === blockClientId ) &&
		state.navigationStatus === 'loading',
	...executableSurfaceSelectors,
	...toastsSelectors,
	getSurfaceInteractionContract: ( state, surface ) => {
		void state;

		return getSurfaceContract( surface );
	},
	isSurfaceAdvisoryOnly: ( state, surface ) => {
		void state;

		return Boolean( getSurfaceContract( surface )?.advisoryOnly );
	},
	isSurfacePreviewRequired: ( state, surface ) => {
		void state;

		return Boolean( getSurfaceContract( surface )?.previewRequired );
	},
	isSurfaceApplyAllowed: ( state, surface, options = {} ) => {
		void state;

		return isSurfaceApplyAllowedForState( surface, options );
	},
	getSurfaceInteractionState: ( state, surface, options = {} ) => {
		void state;

		return getNormalizedInteractionState( surface, options );
	},
	getSurfaceStatusNotice: ( state, surface, options = {} ) => {
		void state;

		return getSurfaceStatusNotice( surface, options );
	},
	getBlockInteractionState: ( state, clientId, options = {} ) =>
		getNormalizedInteractionState( 'block', {
			requestStatus: getStoredBlockRequestState( state, clientId ).status,
			requestError: getBlockRequestError( state, clientId ),
			applyStatus: getStoredBlockRequestState( state, clientId )
				.applyStatus,
			applyError: getBlockApplyError( state, clientId ),
			hasResult: getBlockHasResult( state, clientId ),
			undoStatus: state.undoStatus,
			undoError: normalizeStringMessage( options.undoError ),
			hasSuccess: Boolean( options.hasSuccess ),
			hasUndoSuccess: Boolean( options.hasUndoSuccess ),
			...options,
		} ),
	getNavigationInteractionState: (
		state,
		blockClientId = null,
		options = {}
	) =>
		getNormalizedInteractionState( 'navigation', {
			requestStatus:
				blockClientId && state.navigationBlockClientId !== blockClientId
					? 'idle'
					: state.navigationStatus,
			requestError: getNavigationRequestError( state, blockClientId ),
			hasResult: getNavigationHasResult( state, blockClientId ),
			hasSuggestions:
				selectors.getNavigationRecommendations( state, blockClientId )
					.length > 0,
			...options,
		} ),
	getContentInteractionState: ( state, options = {} ) =>
		getNormalizedInteractionState( 'content', {
			requestStatus: state.contentStatus,
			requestError: normalizeStringMessage( state.contentError ),
			hasResult: getContentHasResult( state ),
			hasSuggestions: Boolean(
				state.contentRecommendation?.content ||
					state.contentRecommendation?.summary ||
					state.contentRecommendation?.title ||
					( Array.isArray( state.contentRecommendation?.notes ) &&
						state.contentRecommendation.notes.length > 0 ) ||
					( Array.isArray( state.contentRecommendation?.issues ) &&
						state.contentRecommendation.issues.length > 0 )
			),
			...options,
		} ),
};

const store = createReduxStore( STORE_NAME, { reducer, actions, selectors } );

register( store );

export { actions, reducer, selectors, STORE_NAME };
export default store;
