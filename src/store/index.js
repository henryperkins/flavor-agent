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
import { buildBlockRecommendationContextSignature } from '../utils/block-recommendation-context';
import {
	buildBlockRecommendationRequestSignature,
	buildGlobalStylesRecommendationRequestSignature,
	buildNavigationRecommendationRequestSignature,
	buildStyleBookRecommendationRequestSignature,
	buildTemplatePartRecommendationRequestSignature,
	buildTemplateRecommendationRequestSignature,
} from '../utils/recommendation-request-signature';
import {
	applyGlobalStyleSuggestionOperations,
	getGlobalStylesActivityUndoState,
	undoGlobalStyleSuggestionOperations,
} from '../utils/style-operations';
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
	getActivityEntityKey,
	getBlockActivityUndoState,
	getCurrentActivityScope,
	getLatestAppliedActivity,
	getLatestUndoableActivity,
	getPendingActivitySyncType,
	getResolvedActivityEntries,
	isLocalActivityEntry,
	limitActivityLog,
	readPersistedActivityLog,
	writePersistedActivityLog,
} from './activity-history';
import { resolveActivityBlock } from './block-targeting';
import {
	buildExecutableSurfaceApplyThunk,
	buildExecutableSurfaceFetchThunk,
	buildExecutableSurfaceReviewFreshnessThunk,
	createExecutableSurfaceApplyConfig,
	createExecutableSurfaceFetchConfig,
	createExecutableSurfaceReviewFreshnessConfig,
} from './executable-surface-runtime';
import {
	attributeSnapshotsMatch,
	buildSafeAttributeUpdates,
	buildUndoAttributeUpdates,
	buildBlockRecommendationDiagnostics,
	getBlockSuggestionExecutionInfo,
	sanitizeRecommendationsForContext,
} from './update-helpers';
import { isPlainObject } from '../utils/type-guards';

const STORE_NAME = 'flavor-agent';
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
	patternStatus: 'idle',
	patternError: null,
	patternBadge: null,
	templateRecommendations: [],
	templateExplanation: '',
	templateStatus: 'idle',
	templateError: null,
	templateRequestPrompt: '',
	templateRef: null,
	templateContextSignature: null,
	templateReviewContextSignature: null,
	templateResolvedContextSignature: null,
	templateRequestToken: 0,
	templateResultToken: 0,
	templateReviewRequestToken: 0,
	templateReviewFreshnessStatus: 'idle',
	templateSelectedSuggestionKey: null,
	templateApplyStatus: 'idle',
	templateApplyError: null,
	templateLastAppliedSuggestionKey: null,
	templateLastAppliedOperations: [],
	templateReviewStaleReason: null,
	templateStaleReason: null,
	templatePartRecommendations: [],
	templatePartExplanation: '',
	templatePartStatus: 'idle',
	templatePartError: null,
	templatePartRequestPrompt: '',
	templatePartRef: null,
	templatePartContextSignature: null,
	templatePartReviewContextSignature: null,
	templatePartResolvedContextSignature: null,
	templatePartRequestToken: 0,
	templatePartResultToken: 0,
	templatePartReviewRequestToken: 0,
	templatePartReviewFreshnessStatus: 'idle',
	templatePartSelectedSuggestionKey: null,
	templatePartApplyStatus: 'idle',
	templatePartApplyError: null,
	templatePartLastAppliedSuggestionKey: null,
	templatePartLastAppliedOperations: [],
	templatePartReviewStaleReason: null,
	templatePartStaleReason: null,
	globalStylesSuggestions: [],
	globalStylesExplanation: '',
	globalStylesStatus: 'idle',
	globalStylesError: null,
	globalStylesRequestPrompt: '',
	globalStylesScopeKey: null,
	globalStylesEntityId: null,
	globalStylesContextSignature: null,
	globalStylesReviewContextSignature: null,
	globalStylesResolvedContextSignature: null,
	globalStylesRequestToken: 0,
	globalStylesResultToken: 0,
	globalStylesReviewRequestToken: 0,
	globalStylesReviewFreshnessStatus: 'idle',
	globalStylesSelectedSuggestionKey: null,
	globalStylesApplyStatus: 'idle',
	globalStylesApplyError: null,
	globalStylesLastAppliedSuggestionKey: null,
	globalStylesLastAppliedOperations: [],
	globalStylesReviewStaleReason: null,
	globalStylesStaleReason: null,
	styleBookSuggestions: [],
	styleBookExplanation: '',
	styleBookStatus: 'idle',
	styleBookError: null,
	styleBookRequestPrompt: '',
	styleBookScopeKey: null,
	styleBookGlobalStylesId: null,
	styleBookBlockName: null,
	styleBookBlockTitle: '',
	styleBookContextSignature: null,
	styleBookReviewContextSignature: null,
	styleBookResolvedContextSignature: null,
	styleBookRequestToken: 0,
	styleBookResultToken: 0,
	styleBookReviewRequestToken: 0,
	styleBookReviewFreshnessStatus: 'idle',
	styleBookSelectedSuggestionKey: null,
	styleBookApplyStatus: 'idle',
	styleBookApplyError: null,
	styleBookLastAppliedSuggestionKey: null,
	styleBookLastAppliedOperations: [],
	styleBookReviewStaleReason: null,
	styleBookStaleReason: null,
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

function getTemplateHasResult( state ) {
	return Boolean( state.templateRef );
}

function getTemplatePartHasResult( state ) {
	return Boolean( state.templatePartRef );
}

function getGlobalStylesHasResult( state ) {
	return Boolean( state.globalStylesEntityId );
}

function getStyleBookHasResult( state ) {
	return Boolean( state.styleBookScopeKey );
}

function getScopeKey( scope = null ) {
	return normalizeStringMessage( scope?.scopeKey || scope?.key ) || null;
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

async function guardSurfaceApplyResolvedFreshness( {
	surface,
	endpoint,
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
		const result = await apiFetch( {
			path: endpoint,
			method: 'POST',
			data: {
				...requestData,
				resolveSignatureOnly: true,
			},
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

function isStaleTemplateRequest( state, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state.templateRequestToken || 0 );
}

function isStaleTemplateReviewRequest( state, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state.templateReviewRequestToken || 0 );
}

function isStaleTemplatePartRequest( state, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state.templatePartRequestToken || 0 );
}

function isStaleTemplatePartReviewRequest( state, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state.templatePartReviewRequestToken || 0 );
}

function isStaleNavigationRequest( state, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state.navigationRequestToken || 0 );
}

function isStaleNavigationReviewRequest( state, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state.navigationReviewRequestToken || 0 );
}

function isStaleGlobalStylesRequest( state, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state.globalStylesRequestToken || 0 );
}

function isStaleGlobalStylesReviewRequest( state, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state.globalStylesReviewRequestToken || 0 );
}

function isStaleStyleBookRequest( state, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state.styleBookRequestToken || 0 );
}

function isStaleStyleBookReviewRequest( state, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state.styleBookReviewRequestToken || 0 );
}

function buildActivityDocument( scope ) {
	const scopeKey = getScopeKey( scope );

	if ( ! scopeKey ) {
		return null;
	}

	return {
		scopeKey,
		postType: scope.postType,
		entityId: scope.entityId,
		entityKind: scope.entityKind || '',
		entityName: scope.entityName || '',
		stylesheet: scope.stylesheet || '',
	};
}

function getRequestDocumentFromScope( scope ) {
	return buildActivityDocument( scope );
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

function syncActivitySession(
	localDispatch,
	select,
	scope,
	{ allowUnsavedMigration = false } = {}
) {
	const currentScopeKey = select.getActivityScopeKey?.() || null;
	const nextScopeKey = getScopeKey( scope );

	if ( currentScopeKey === nextScopeKey ) {
		return select.getActivityLog?.() || [];
	}

	const currentEntries = select.getActivityLog?.() || [];

	if (
		currentScopeKey === null &&
		nextScopeKey &&
		currentEntries.length > 0 &&
		allowUnsavedMigration
	) {
		const reassignedEntries = alignActivityEntriesToScope(
			currentEntries,
			scope
		);

		localDispatch(
			actions.setActivitySession( nextScopeKey, reassignedEntries )
		);
		writePersistedActivityLog( nextScopeKey, reassignedEntries );

		return reassignedEntries;
	}

	const cachedEntries = nextScopeKey
		? readPersistedActivityLog( nextScopeKey )
		: [];

	localDispatch( actions.setActivitySession( nextScopeKey, cachedEntries ) );

	return cachedEntries;
}

function persistActivitySession( select ) {
	const scopeKey = select.getActivityScopeKey?.() || null;

	if ( ! scopeKey ) {
		return;
	}

	writePersistedActivityLog( scopeKey, select.getActivityLog?.() || [] );
}

function getApiErrorStatus( error ) {
	const dataStatus = Number( error?.data?.status );

	if ( Number.isInteger( dataStatus ) && dataStatus > 0 ) {
		return dataStatus;
	}

	const errorStatus = Number( error?.status );

	if ( Number.isInteger( errorStatus ) && errorStatus > 0 ) {
		return errorStatus;
	}

	const responseStatus = Number( error?.response?.status );

	return Number.isInteger( responseStatus ) && responseStatus > 0
		? responseStatus
		: 0;
}

function getApiErrorMessage( error, fallback = 'Request failed.' ) {
	return typeof error?.message === 'string' && error.message
		? error.message
		: fallback;
}

function getApiErrorCode( error ) {
	if ( typeof error?.code === 'string' && error.code ) {
		return error.code;
	}

	if ( typeof error?.data?.code === 'string' && error.data.code ) {
		return error.data.code;
	}

	return '';
}

function buildBlockRecommendationFailureDiagnostics(
	error,
	requestData = {},
	requestToken = null
) {
	const message = getApiErrorMessage( error, 'Request failed.' );
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

	return {
		type: 'failure',
		title: `Block request failed: ${ message }`,
		detailLines,
		requestMeta,
		errorCode: getApiErrorCode( error ),
		errorMessage: message,
		requestToken,
		timestamp: new Date().toISOString(),
		prompt: requestData.prompt || '',
		blockName: requestData.editorContext?.block?.name || '',
	};
}

function buildActivityQueryPath( {
	scopeKey,
	surface = '',
	entityType = '',
	entityRef = '',
	limit = null,
} ) {
	const params = new URLSearchParams();

	if ( scopeKey ) {
		params.set( 'scopeKey', scopeKey );
	}

	if ( surface ) {
		params.set( 'surface', surface );
	}

	if ( entityType ) {
		params.set( 'entityType', entityType );
	}

	if ( entityRef ) {
		params.set( 'entityRef', entityRef );
	}

	if ( Number.isInteger( limit ) && limit > 0 ) {
		params.set( 'limit', String( limit ) );
	}

	const query = params.toString();

	return query
		? `/flavor-agent/v1/activity?${ query }`
		: '/flavor-agent/v1/activity';
}

function mergeActivityEntries( ...entrySets ) {
	const mergedEntries = new Map();

	entrySets
		.flat()
		.filter( Boolean )
		.forEach( ( entry ) => {
			if ( entry?.id ) {
				mergedEntries.set( entry.id, entry );
			}
		} );

	return limitActivityLog( [ ...mergedEntries.values() ] );
}

function refreshActivitySession( localDispatch, scopeKey, entries ) {
	localDispatch( actions.setActivitySession( scopeKey, entries ) );
	writePersistedActivityLog( scopeKey, entries );
}

async function reloadScopedActivitySession( localDispatch, registry, select ) {
	const scope = getCurrentActivityScope( registry );
	const scopeKey =
		getScopeKey( scope ) || select.getActivityScopeKey?.() || null;

	if ( ! scopeKey ) {
		return;
	}

	try {
		const serverEntries = await fetchServerActivityEntries( scopeKey );
		const mergedEntries = mergeActivityEntries(
			serverEntries,
			( select.getActivityLog?.() || [] ).filter( isLocalActivityEntry )
		);

		refreshActivitySession( localDispatch, scopeKey, mergedEntries );
	} catch {
		// Keep the current scoped cache when the server activity reload fails.
	}
}

async function fetchServerActivityEntries( scopeKey ) {
	const response = await apiFetch( {
		path: buildActivityQueryPath( {
			scopeKey,
		} ),
		method: 'GET',
	} );

	return limitActivityLog( response?.entries || [] );
}

function scheduleActivitySessionReload( options = {} ) {
	if ( typeof window === 'undefined' ) {
		return;
	}

	if ( actions._activitySessionRetryTimer ) {
		window.clearTimeout( actions._activitySessionRetryTimer );
	}

	actions._activitySessionRetryTimer = window.setTimeout( () => {
		actions._activitySessionRetryTimer = null;

		const storeDispatch = window.wp?.data?.dispatch?.( STORE_NAME );
		const { scope: retryScope, ...retryOptions } = options || {};

		if ( typeof storeDispatch?.loadActivitySession === 'function' ) {
			storeDispatch.loadActivitySession( {
				...retryOptions,
				...( getScopeKey( retryScope ) ? { scope: retryScope } : {} ),
				retryIfScopeUnavailable: false,
			} );
		}
	}, 150 );
}

async function persistServerActivityEntry( entry ) {
	const response = await apiFetch( {
		path: '/flavor-agent/v1/activity',
		method: 'POST',
		data: {
			entry,
		},
	} );

	return response?.entry || entry;
}

async function persistActivityUndoTransition( entry ) {
	const response = await apiFetch( {
		path: `/flavor-agent/v1/activity/${ encodeURIComponent(
			entry.id
		) }/undo`,
		method: 'POST',
		data: entry?.undo?.error
			? {
					status: entry?.undo?.status,
					error: entry.undo.error,
			  }
			: {
					status: entry?.undo?.status,
			  },
	} );

	return response?.entry || entry;
}

function shouldSyncUndoTransition( entry ) {
	const pendingSyncType = getPendingActivitySyncType( entry );

	if ( pendingSyncType === 'undo' ) {
		return true;
	}

	return entry?.persistence?.status === 'server';
}

function buildActivityPersistenceUpdate(
	status,
	syncType = null,
	updatedAt = new Date().toISOString()
) {
	return {
		status,
		syncType: status === 'server' ? null : syncType || 'create',
		updatedAt,
	};
}

function buildUndoAuditSyncError( message ) {
	return `${ message } Flavor Agent could not persist the activity audit update and will retry on the next activity sync.`;
}

function isServerBackedActivityEntry( entry ) {
	return entry?.persistence?.status === 'server';
}

function isRetryableActivitySyncError( error ) {
	const status = getApiErrorStatus( error );

	if ( status === 0 || status === 408 || status === 425 || status === 429 ) {
		return true;
	}

	return status >= 500;
}

function isRetryableRateLimitError( error ) {
	if ( error?.data?.retryable === true ) {
		return true;
	}
	return getApiErrorStatus( error ) === 429;
}

function getRetryAfterSeconds( error ) {
	const retryAfter = error?.data?.retry_after ?? error?.retry_after;
	if ( Number.isInteger( retryAfter ) && retryAfter > 0 ) {
		return Math.min( retryAfter, 60 );
	}
	return 5;
}

function sleep( ms ) {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}

function isNonRetryableUndoSyncError( entry, error ) {
	if (
		! isServerBackedActivityEntry( entry ) &&
		getPendingActivitySyncType( entry ) !== 'undo'
	) {
		return false;
	}

	const status = getApiErrorStatus( error );
	if ( status < 400 || status >= 500 ) {
		return false;
	}

	if (
		status === 409 &&
		getApiErrorCode( error ) ===
			'flavor_agent_activity_invalid_undo_transition'
	) {
		return false;
	}

	return ! isRetryableActivitySyncError( error );
}

function isUndoSyncConflictError( error ) {
	return (
		getApiErrorStatus( error ) === 409 &&
		getApiErrorCode( error ) ===
			'flavor_agent_activity_invalid_undo_transition'
	);
}

function buildNonRetryableUndoSyncEntry(
	entry,
	error,
	timestamp = new Date().toISOString()
) {
	const message = getApiErrorMessage(
		error,
		'This AI action can no longer be undone automatically.'
	);

	return {
		...entry,
		undo: {
			...( entry?.undo || {} ),
			canUndo: false,
			status: 'failed',
			error: message,
			updatedAt: timestamp,
			undoneAt: null,
		},
		persistence: buildActivityPersistenceUpdate(
			'server',
			null,
			timestamp
		),
	};
}

async function persistPendingActivityEntry( entry ) {
	switch ( getPendingActivitySyncType( entry ) ) {
		case 'undo':
			return persistActivityUndoTransition( entry );
		case 'create':
		default:
			return persistServerActivityEntry( entry );
	}
}

async function persistPendingActivityEntries( entries = [] ) {
	const persistedEntries = [];
	const failedEntries = [];
	const terminalEntries = [];

	for ( const entry of entries ) {
		try {
			persistedEntries.push( await persistPendingActivityEntry( entry ) );
		} catch ( error ) {
			if ( isUndoSyncConflictError( error ) ) {
				let reconciledEntry = null;

				try {
					reconciledEntry = await reconcileActivityEntryFromServer(
						entry,
						entry?.document?.scopeKey || null
					);
				} catch {
					reconciledEntry = null;
				}

				if ( reconciledEntry ) {
					persistedEntries.push( reconciledEntry );
					continue;
				}
			}

			if ( isNonRetryableUndoSyncError( entry, error ) ) {
				terminalEntries.push(
					buildNonRetryableUndoSyncEntry( entry, error )
				);
				continue;
			}

			failedEntries.push( entry );
		}
	}

	return {
		persistedEntries,
		failedEntries,
		terminalEntries,
	};
}

async function reconcileActivityEntryFromServer( entry, scopeKey ) {
	if ( ! entry?.id || ! scopeKey ) {
		return null;
	}

	const serverEntries = await fetchServerActivityEntries( scopeKey );

	return (
		serverEntries.find( ( serverEntry ) => serverEntry?.id === entry.id ) ||
		null
	);
}

async function recordActivityEntry( localDispatch, select, entry ) {
	let nextEntry = entry;

	if ( entry?.document?.scopeKey ) {
		try {
			nextEntry = await persistServerActivityEntry( entry );
		} catch {
			nextEntry = entry;
		}
	}

	localDispatch( actions.logActivity( nextEntry ) );
	persistActivitySession( select );

	return nextEntry;
}

function dispatchTemplateRecommendations( {
	contextSignature,
	dispatch,
	input: requestInput,
	payload,
	requestToken,
	reviewContextSignature,
	resolvedContextSignature,
} ) {
	dispatch(
		actions.setTemplateRecommendations(
			requestInput.templateRef,
			payload,
			requestInput.prompt || '',
			requestToken,
			contextSignature,
			reviewContextSignature,
			resolvedContextSignature
		)
	);
}

function dispatchTemplatePartRecommendations( {
	contextSignature,
	dispatch,
	input: requestInput,
	payload,
	requestToken,
	reviewContextSignature,
	resolvedContextSignature,
} ) {
	dispatch(
		actions.setTemplatePartRecommendations(
			requestInput.templatePartRef,
			payload,
			requestInput.prompt || '',
			requestToken,
			contextSignature,
			reviewContextSignature,
			resolvedContextSignature
		)
	);
}

function dispatchGlobalStylesRecommendations( {
	contextSignature,
	dispatch,
	input: requestInput,
	payload,
	requestToken,
	reviewContextSignature,
	resolvedContextSignature,
} ) {
	dispatch(
		actions.setGlobalStylesRecommendations(
			requestInput.scope,
			payload,
			requestInput.prompt || '',
			requestToken,
			contextSignature,
			reviewContextSignature,
			resolvedContextSignature
		)
	);
}

function dispatchStyleBookRecommendations( {
	contextSignature,
	dispatch,
	input: requestInput,
	payload,
	requestToken,
	reviewContextSignature,
	resolvedContextSignature,
} ) {
	dispatch(
		actions.setStyleBookRecommendations(
			requestInput.scope,
			payload,
			requestInput.prompt || '',
			requestToken,
			contextSignature,
			reviewContextSignature,
			resolvedContextSignature
		)
	);
}

function getTemplateStoredRequestSignature( select ) {
	return buildTemplateRecommendationRequestSignature( {
		templateRef: select.getTemplateResultRef?.() || '',
		prompt: select.getTemplateRequestPrompt?.() || '',
		contextSignature: select.getTemplateContextSignature?.() || null,
	} );
}

function getNavigationStoredRequestSignature( select ) {
	return buildNavigationRecommendationRequestSignature( {
		blockClientId: select.getNavigationBlockClientId?.() || '',
		prompt: select.getNavigationRequestPrompt?.() || '',
		contextSignature: select.getNavigationContextSignature?.() || null,
	} );
}

function getTemplatePartStoredRequestSignature( select ) {
	return buildTemplatePartRecommendationRequestSignature( {
		templatePartRef: select.getTemplatePartResultRef?.() || '',
		prompt: select.getTemplatePartRequestPrompt?.() || '',
		contextSignature: select.getTemplatePartContextSignature?.() || null,
	} );
}

function getGlobalStylesStoredRequestSignature( select ) {
	return buildGlobalStylesRecommendationRequestSignature( {
		scope: {
			scopeKey: select.getGlobalStylesScopeKey?.() || '',
			globalStylesId: select.getGlobalStylesResultRef?.() || '',
			entityId: select.getGlobalStylesResultRef?.() || '',
		},
		prompt: select.getGlobalStylesRequestPrompt?.() || '',
		contextSignature: select.getGlobalStylesContextSignature?.() || null,
	} );
}

function getStyleBookStoredRequestSignature( select ) {
	return buildStyleBookRecommendationRequestSignature( {
		scope: {
			scopeKey: select.getStyleBookScopeKey?.() || '',
			globalStylesId: select.getStyleBookGlobalStylesId?.() || '',
			entityId: select.getStyleBookGlobalStylesId?.() || '',
			blockName: select.getStyleBookBlockName?.() || '',
		},
		prompt: select.getStyleBookRequestPrompt?.() || '',
		contextSignature: select.getStyleBookContextSignature?.() || null,
	} );
}

function buildTemplateActivityEntryFromStore( {
	result,
	scope,
	select,
	suggestion,
} ) {
	return buildTemplateActivityEntry( {
		operations: result.operations,
		requestPrompt: select.getTemplateRequestPrompt?.() || '',
		requestMeta: suggestion?.requestMeta || null,
		requestToken: select.getTemplateResultToken?.() || 0,
		scope,
		suggestion,
		templateRef: select.getTemplateResultRef(),
	} );
}

function buildTemplatePartActivityEntryFromStore( {
	result,
	scope,
	select,
	suggestion,
} ) {
	return buildTemplatePartActivityEntry( {
		operations: result.operations,
		requestPrompt: select.getTemplatePartRequestPrompt?.() || '',
		requestMeta: suggestion?.requestMeta || null,
		requestToken: select.getTemplatePartResultToken?.() || 0,
		scope,
		suggestion,
		templatePartRef: select.getTemplatePartResultRef(),
	} );
}

function buildGlobalStylesActivityEntryFromStore( {
	result,
	scope,
	select,
	suggestion,
} ) {
	return buildGlobalStylesActivityEntry( {
		operations: result.operations,
		beforeConfig: result.beforeConfig,
		afterConfig: result.afterConfig,
		requestPrompt: select.getGlobalStylesRequestPrompt?.() || '',
		requestMeta: suggestion?.requestMeta || null,
		requestToken: select.getGlobalStylesResultToken?.() || 0,
		scope,
		suggestion,
		globalStylesId: result.globalStylesId,
	} );
}

function buildStyleBookActivityEntryFromStore( {
	result,
	scope,
	select,
	suggestion,
} ) {
	return buildStyleBookActivityEntry( {
		operations: result.operations,
		beforeConfig: result.beforeConfig,
		afterConfig: result.afterConfig,
		requestPrompt: select.getStyleBookRequestPrompt?.() || '',
		requestMeta: suggestion?.requestMeta || null,
		requestToken: select.getStyleBookResultToken?.() || 0,
		scope,
		suggestion,
		globalStylesId: result.globalStylesId,
		blockName: scope?.blockName || '',
		blockTitle: scope?.blockTitle || '',
	} );
}

const EXECUTABLE_SURFACE_FETCH_DEPS = {
	attachRequestMetaToRecommendationPayload,
	getReviewContextSignatureFromResponse,
	getResolvedContextSignatureFromResponse,
	runAbortableRecommendationRequest,
};

const EXECUTABLE_SURFACE_APPLY_DEPS = {
	getCurrentActivityScope,
	guardSurfaceApplyFreshness,
	guardSurfaceApplyResolvedFreshness,
	recordActivityEntry,
	syncActivitySession,
};

const EXECUTABLE_SURFACE_REVIEW_DEPS = {
	getReviewContextSignatureFromResponse,
};

function getTemplateExecutableSurfaceFetchConfig() {
	return createExecutableSurfaceFetchConfig( {
		abortKey: '_templateAbort',
		dispatchRecommendations: dispatchTemplateRecommendations,
		endpoint: '/flavor-agent/v1/recommend-template',
		getRequestToken: ( select ) =>
			( select.getTemplateRequestToken?.() || 0 ) + 1,
		requestErrorMessage: 'Template recommendation request failed.',
		setStatusAction: actions.setTemplateStatus,
	} );
}

function getTemplatePartExecutableSurfaceFetchConfig() {
	return createExecutableSurfaceFetchConfig( {
		abortKey: '_templatePartAbort',
		dispatchRecommendations: dispatchTemplatePartRecommendations,
		endpoint: '/flavor-agent/v1/recommend-template-part',
		getRequestToken: ( select ) =>
			( select.getTemplatePartRequestToken?.() || 0 ) + 1,
		requestErrorMessage: 'Template-part recommendation request failed.',
		setStatusAction: actions.setTemplatePartStatus,
	} );
}

function getGlobalStylesExecutableSurfaceFetchConfig() {
	return createExecutableSurfaceFetchConfig( {
		abortKey: '_globalStylesAbort',
		dispatchRecommendations: dispatchGlobalStylesRecommendations,
		endpoint: '/flavor-agent/v1/recommend-style',
		getRequestToken: ( select ) =>
			( select.getGlobalStylesRequestToken?.() || 0 ) + 1,
		requestErrorMessage: 'Global Styles recommendation request failed.',
		setStatusAction: actions.setGlobalStylesStatus,
	} );
}

function getStyleBookExecutableSurfaceFetchConfig() {
	return createExecutableSurfaceFetchConfig( {
		abortKey: '_styleBookAbort',
		dispatchRecommendations: dispatchStyleBookRecommendations,
		endpoint: '/flavor-agent/v1/recommend-style',
		getRequestToken: ( select ) =>
			( select.getStyleBookRequestToken?.() || 0 ) + 1,
		requestErrorMessage: 'Style Book recommendation request failed.',
		setStatusAction: actions.setStyleBookStatus,
	} );
}

function getNavigationReviewConfig() {
	return createExecutableSurfaceReviewFreshnessConfig( {
		endpoint: '/flavor-agent/v1/recommend-navigation',
		getReviewRequestToken: ( select ) =>
			select.getNavigationReviewRequestToken?.() || 0,
		getStoredRequestSignature: getNavigationStoredRequestSignature,
		getStoredReviewContextSignature: ( select ) =>
			select.getNavigationReviewContextSignature?.() || null,
		setReviewStateAction: actions.setNavigationReviewFreshnessState,
		surface: 'navigation',
	} );
}

function getTemplateExecutableSurfaceApplyConfig() {
	return createExecutableSurfaceApplyConfig( {
		applyFailureMessage: 'Template apply failed.',
		buildActivityEntry: buildTemplateActivityEntryFromStore,
		endpoint: '/flavor-agent/v1/recommend-template',
		executeSuggestion: ( { suggestion } ) =>
			applyTemplateSuggestionOperations( suggestion ),
		getStoredRequestSignature: getTemplateStoredRequestSignature,
		getStoredResolvedContextSignature: ( select ) =>
			select.getTemplateResolvedContextSignature?.() || null,
		setApplyStateAction: actions.setTemplateApplyState,
		surface: 'template',
		unexpectedErrorMessage: 'Template apply failed unexpectedly.',
	} );
}

function getTemplateExecutableSurfaceReviewConfig() {
	return createExecutableSurfaceReviewFreshnessConfig( {
		endpoint: '/flavor-agent/v1/recommend-template',
		getReviewRequestToken: ( select ) =>
			select.getTemplateReviewRequestToken?.() || 0,
		getStoredRequestSignature: getTemplateStoredRequestSignature,
		getStoredReviewContextSignature: ( select ) =>
			select.getTemplateReviewContextSignature?.() || null,
		setReviewStateAction: actions.setTemplateReviewFreshnessState,
		surface: 'template',
	} );
}

function getTemplatePartExecutableSurfaceApplyConfig() {
	return createExecutableSurfaceApplyConfig( {
		applyFailureMessage: 'Template-part apply failed.',
		buildActivityEntry: buildTemplatePartActivityEntryFromStore,
		endpoint: '/flavor-agent/v1/recommend-template-part',
		executeSuggestion: ( { suggestion } ) =>
			applyTemplatePartSuggestionOperations( suggestion ),
		getStoredRequestSignature: getTemplatePartStoredRequestSignature,
		getStoredResolvedContextSignature: ( select ) =>
			select.getTemplatePartResolvedContextSignature?.() || null,
		setApplyStateAction: actions.setTemplatePartApplyState,
		surface: 'template-part',
		unexpectedErrorMessage: 'Template-part apply failed unexpectedly.',
	} );
}

function getTemplatePartExecutableSurfaceReviewConfig() {
	return createExecutableSurfaceReviewFreshnessConfig( {
		endpoint: '/flavor-agent/v1/recommend-template-part',
		getReviewRequestToken: ( select ) =>
			select.getTemplatePartReviewRequestToken?.() || 0,
		getStoredRequestSignature: getTemplatePartStoredRequestSignature,
		getStoredReviewContextSignature: ( select ) =>
			select.getTemplatePartReviewContextSignature?.() || null,
		setReviewStateAction: actions.setTemplatePartReviewFreshnessState,
		surface: 'template-part',
	} );
}

function getGlobalStylesExecutableSurfaceApplyConfig() {
	return createExecutableSurfaceApplyConfig( {
		applyFailureMessage: 'Global Styles apply failed.',
		buildActivityEntry: buildGlobalStylesActivityEntryFromStore,
		endpoint: '/flavor-agent/v1/recommend-style',
		executeSuggestion: ( { registry, suggestion } ) =>
			applyGlobalStyleSuggestionOperations( suggestion, registry, {
				surface: 'global-styles',
			} ),
		getStoredRequestSignature: getGlobalStylesStoredRequestSignature,
		getStoredResolvedContextSignature: ( select ) =>
			select.getGlobalStylesResolvedContextSignature?.() || null,
		setApplyStateAction: actions.setGlobalStylesApplyState,
		surface: 'global-styles',
		unexpectedErrorMessage: 'Global Styles apply failed unexpectedly.',
	} );
}

function getGlobalStylesExecutableSurfaceReviewConfig() {
	return createExecutableSurfaceReviewFreshnessConfig( {
		endpoint: '/flavor-agent/v1/recommend-style',
		getReviewRequestToken: ( select ) =>
			select.getGlobalStylesReviewRequestToken?.() || 0,
		getStoredRequestSignature: getGlobalStylesStoredRequestSignature,
		getStoredReviewContextSignature: ( select ) =>
			select.getGlobalStylesReviewContextSignature?.() || null,
		setReviewStateAction: actions.setGlobalStylesReviewFreshnessState,
		surface: 'global-styles',
	} );
}

function getStyleBookExecutableSurfaceApplyConfig() {
	return createExecutableSurfaceApplyConfig( {
		applyFailureMessage: 'Style Book apply failed.',
		buildActivityEntry: buildStyleBookActivityEntryFromStore,
		endpoint: '/flavor-agent/v1/recommend-style',
		executeSuggestion: ( { registry, suggestion } ) =>
			applyGlobalStyleSuggestionOperations( suggestion, registry, {
				surface: 'style-book',
			} ),
		getStoredRequestSignature: getStyleBookStoredRequestSignature,
		getStoredResolvedContextSignature: ( select ) =>
			select.getStyleBookResolvedContextSignature?.() || null,
		setApplyStateAction: actions.setStyleBookApplyState,
		surface: 'style-book',
		unexpectedErrorMessage: 'Style Book apply failed unexpectedly.',
	} );
}

function getStyleBookExecutableSurfaceReviewConfig() {
	return createExecutableSurfaceReviewFreshnessConfig( {
		endpoint: '/flavor-agent/v1/recommend-style',
		getReviewRequestToken: ( select ) =>
			select.getStyleBookReviewRequestToken?.() || 0,
		getStoredRequestSignature: getStyleBookStoredRequestSignature,
		getStoredReviewContextSignature: ( select ) =>
			select.getStyleBookReviewContextSignature?.() || null,
		setReviewStateAction: actions.setStyleBookReviewFreshnessState,
		surface: 'style-book',
	} );
}

function getEntityActivityEntries( activityLog, activity ) {
	const entityKey = getActivityEntityKey( activity );

	if ( ! entityKey ) {
		return [];
	}

	return activityLog.filter(
		( entry ) => getActivityEntityKey( entry ) === entityKey
	);
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

function buildBlockActivityEntry( {
	afterAttributes,
	beforeAttributes,
	blockContext,
	blockPath = null,
	clientId,
	requestPrompt = '',
	requestMeta = null,
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
		requestMeta,
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
					area: operation?.undoLocator?.area || operation?.area || '',
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
					targetPath: Array.isArray( operation.targetPath )
						? operation.targetPath
						: null,
					expectedTarget:
						operation.expectedTarget &&
						typeof operation.expectedTarget === 'object'
							? operation.expectedTarget
							: null,
					targetBlockName: operation.targetBlockName || '',
					rootLocator: operation.rootLocator || null,
					index: Number.isInteger( operation.index )
						? operation.index
						: null,
				};

			case 'replace_block_with_pattern':
			case 'remove_block':
				return {
					type: operation.type,
					patternName: operation.patternName || '',
					patternTitle: operation.patternTitle || '',
					expectedBlockName: operation.expectedBlockName || '',
					expectedTarget:
						operation.expectedTarget &&
						typeof operation.expectedTarget === 'object'
							? operation.expectedTarget
							: null,
					targetPath: Array.isArray( operation.targetPath )
						? operation.targetPath
						: null,
					rootLocator: operation.rootLocator || null,
					index: Number.isInteger( operation.index )
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
	requestMeta = null,
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
		requestMeta,
		document: buildActivityDocument( scope ),
	} );
}

function buildTemplatePartActivityEntry( {
	operations,
	requestPrompt = '',
	requestMeta = null,
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
		requestRef: `template-part:${
			templatePartRef || 'unknown'
		}:${ requestToken }`,
		requestMeta,
		document: buildActivityDocument( scope ),
	} );
}

function buildGlobalStylesActivityEntry( {
	operations,
	beforeConfig,
	afterConfig,
	requestPrompt = '',
	requestMeta = null,
	requestToken = 0,
	scope = null,
	suggestion,
	globalStylesId,
} ) {
	return createActivityEntry( {
		type: 'apply_global_styles_suggestion',
		surface: 'global-styles',
		target: {
			globalStylesId,
		},
		suggestion: suggestion?.label || '',
		suggestionKey: suggestion?.suggestionKey || null,
		before: {
			userConfig: beforeConfig,
		},
		after: {
			userConfig: afterConfig,
			operations,
		},
		prompt: requestPrompt,
		requestRef: `global-styles:${
			globalStylesId || 'unknown'
		}:${ requestToken }`,
		requestMeta,
		document: buildActivityDocument( scope ),
	} );
}

function buildStyleBookActivityEntry( {
	operations,
	beforeConfig,
	afterConfig,
	requestPrompt = '',
	requestMeta = null,
	requestToken = 0,
	scope = null,
	suggestion,
	globalStylesId,
	blockName,
	blockTitle = '',
} ) {
	return createActivityEntry( {
		type: 'apply_style_book_suggestion',
		surface: 'style-book',
		target: {
			globalStylesId,
			blockName,
			blockTitle,
		},
		suggestion: suggestion?.label || '',
		suggestionKey: suggestion?.suggestionKey || null,
		before: {
			userConfig: beforeConfig,
		},
		after: {
			userConfig: afterConfig,
			operations,
		},
		prompt: requestPrompt,
		requestRef: `style-book:${ globalStylesId || 'unknown' }:${
			blockName || 'unknown'
		}:${ requestToken }`,
		requestMeta,
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

function getActivityRuntimeUndoResolver( surface, registry ) {
	const blockEditorSelect = registry?.select?.( 'core/block-editor' ) || {};

	switch ( surface ) {
		case 'template':
			return ( entry ) =>
				getTemplateActivityUndoState( entry, blockEditorSelect );
		case 'template-part':
			return ( entry ) =>
				getTemplatePartActivityUndoState( entry, blockEditorSelect );
		case 'global-styles':
		case 'style-book':
			return ( entry ) =>
				getGlobalStylesActivityUndoState( entry, registry );
		case 'block':
			return ( entry ) =>
				getBlockActivityUndoState( entry, blockEditorSelect );
		default:
			return null;
	}
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
	buildRequest = () => ( {} ),
	dispatch,
	endpoint,
	input,
	onError,
	onLoading,
	onSuccess,
	select,
} ) {
	const request = {
		...( buildRequest( { input, select } ) || {} ),
	};
	const abortId =
		request.abortId === null || request.abortId === undefined
			? null
			: String( request.abortId );
	const requestData = request.requestData ?? input;
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
			const result = await apiFetch( {
				path: endpoint,
				method: 'POST',
				data: requestData,
				signal: controller.signal,
			} );

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
		return async ( { dispatch, registry, select } ) => {
			const requestToken = ( actions._activitySessionLoadToken || 0 ) + 1;
			actions._activitySessionLoadToken = requestToken;
			const scope = options?.scope || getCurrentActivityScope( registry );
			const nextScopeKey = getScopeKey( scope );

			if ( ! nextScopeKey ) {
				if ( options?.retryIfScopeUnavailable !== false ) {
					scheduleActivitySessionReload( options );
				}

				syncActivitySession( dispatch, select, scope, {
					allowUnsavedMigration:
						options?.allowUnsavedMigration === true,
				} );

				return;
			}

			const workingEntries = syncActivitySession(
				dispatch,
				select,
				scope,
				{
					allowUnsavedMigration:
						options?.allowUnsavedMigration === true,
				}
			);
			const pendingEntries =
				workingEntries.filter( isLocalActivityEntry );
			const { persistedEntries, failedEntries, terminalEntries } =
				await persistPendingActivityEntries( pendingEntries );
			const terminalEntryIds = new Set(
				terminalEntries.map( ( entry ) => entry?.id ).filter( Boolean )
			);

			try {
				const serverEntries =
					await fetchServerActivityEntries( nextScopeKey );
				const mergedEntries = mergeActivityEntries(
					serverEntries,
					persistedEntries,
					failedEntries,
					terminalEntries
				);

				if ( actions._activitySessionLoadToken !== requestToken ) {
					return;
				}

				refreshActivitySession( dispatch, nextScopeKey, mergedEntries );
			} catch {
				const fallbackEntries = mergeActivityEntries(
					workingEntries.filter(
						( entry ) => ! terminalEntryIds.has( entry?.id )
					),
					persistedEntries,
					failedEntries,
					terminalEntries
				);

				if ( actions._activitySessionLoadToken !== requestToken ) {
					return;
				}

				refreshActivitySession(
					dispatch,
					nextScopeKey,
					fallbackEntries
				);
			}
		};
	},

	fetchBlockRecommendations( clientId, context, prompt = '' ) {
		return ( { dispatch, select } ) =>
			runAbortableRecommendationRequest( {
				abortKey: '_blockRecommendationAbort',
				buildRequest: ( {
					input: requestInput,
					select: registrySelect,
				} ) => {
					const contextSignature =
						requestInput?.contextSignature || null;

					return {
						abortId: requestInput?.clientId || null,
						clientId: requestInput?.clientId || '',
						contextSignature,
						requestData: {
							editorContext: requestInput?.context || {},
							prompt: requestInput?.prompt || '',
							clientId: requestInput?.clientId || '',
						},
						requestToken:
							( registrySelect.getBlockRequestToken?.(
								requestInput?.clientId
							) || 0 ) + 1,
					};
				},
				dispatch,
				endpoint: '/flavor-agent/v1/recommend-block',
				input: {
					clientId,
					context,
					contextSignature:
						buildBlockRecommendationContextSignature( context ),
					prompt,
				},
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
							err?.message || 'Request failed.',
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
						result.payload || {}
					);
					const blockContext = requestData.editorContext?.block || {};
					const sanitizedPayload = sanitizeRecommendationsForContext(
						payload,
						blockContext
					);
					const diagnosticsBase = buildBlockRecommendationDiagnostics(
						payload,
						sanitizedPayload,
						blockContext
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
			const scope = getCurrentActivityScope( registry );
			const applyErrorMessage =
				'This suggestion includes unsupported or unsafe attribute changes and could not be applied.';
			const advisoryApplyMessage =
				'This suggestion is advisory and requires manual follow-through or a broader preview/apply flow.';

			syncActivitySession( localDispatch, select, scope );

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

			const resolvedFreshness = await guardSurfaceApplyResolvedFreshness(
				{
					surface: 'block',
					endpoint: '/flavor-agent/v1/recommend-block',
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

			localDispatch( actions.setBlockApplyState( clientId, 'applying' ) );

			const storedRecommendationPayload =
				select.getBlockRecommendations( clientId ) || null;
			const storedRecommendations = storedRecommendationPayload || {};
			const blockContext = storedRecommendations.blockContext || {};
			const blockEditorSelect =
				registry?.select?.( 'core/block-editor' ) || {};
			const blockEditorDispatch =
				registry?.dispatch?.( 'core/block-editor' ) || {};
			const currentAttributes =
				blockEditorSelect.getBlockAttributes?.( clientId ) || {};
			const execution = getBlockSuggestionExecutionInfo(
				suggestion,
				blockContext
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

			await recordActivityEntry(
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
					requestToken: select.getBlockRequestToken( clientId ) || 0,
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

			return true;
		};
	},

	setPatternStatus( status, error = null ) {
		return { type: 'SET_PATTERN_STATUS', status, error };
	},

	setPatternRecommendations( recommendations ) {
		return { type: 'SET_PATTERN_RECS', recommendations };
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
				buildRequest: ( { input: requestInput } ) => ( {
					requestData: {
						...( requestInput || {} ),
						document: getRequestDocumentFromScope(
							getCurrentActivityScope( registry )
						),
					},
				} ),
				dispatch,
				endpoint: '/flavor-agent/v1/recommend-patterns',
				input,
				onError: ( { dispatch: localDispatch, err } ) => {
					localDispatch( actions.setPatternRecommendations( [] ) );
					localDispatch(
						actions.setPatternStatus(
							'error',
							err?.message ||
								'Pattern recommendation request failed.'
						)
					);
					return reloadScopedActivitySession(
						localDispatch,
						registry,
						select
					);
				},
				onLoading: ( { dispatch: localDispatch } ) => {
					localDispatch( actions.setPatternStatus( 'loading' ) );
				},
				onSuccess: ( { dispatch: localDispatch, result } ) => {
					localDispatch(
						actions.setPatternRecommendations(
							result.recommendations || []
						)
					);
					localDispatch( actions.setPatternStatus( 'ready' ) );
					return reloadScopedActivitySession(
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
				endpoint: '/flavor-agent/v1/recommend-content',
				input,
				onError: ( { dispatch: localDispatch, err, requestToken } ) => {
					localDispatch(
						actions.setContentStatus(
							'error',
							err?.message ||
								'Content recommendation request failed.',
							requestToken
						)
					);
					return reloadScopedActivitySession(
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
					return reloadScopedActivitySession(
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
				endpoint: '/flavor-agent/v1/recommend-navigation',
				input,
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
					return reloadScopedActivitySession(
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
					return reloadScopedActivitySession(
						localDispatch,
						registry,
						select
					);
				},
				select,
			} );
	},

	setTemplateStatus( status, error = null, requestToken = null ) {
		return { type: 'SET_TEMPLATE_STATUS', status, error, requestToken };
	},

	setTemplateRecommendations(
		templateRef,
		payload,
		prompt = '',
		requestToken = null,
		contextSignature = null,
		reviewContextSignature = null,
		resolvedContextSignature = null
	) {
		return {
			type: 'SET_TEMPLATE_RECS',
			templateRef,
			payload,
			prompt,
			requestToken,
			contextSignature,
			reviewContextSignature,
			resolvedContextSignature,
		};
	},

	setTemplateReviewFreshnessState(
		status,
		requestToken = null,
		staleReason = null
	) {
		return {
			type: 'SET_TEMPLATE_REVIEW_FRESHNESS_STATE',
			status,
			requestToken,
			staleReason,
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
		operations = [],
		staleReason = null
	) {
		return {
			type: 'SET_TEMPLATE_APPLY_STATE',
			status,
			error,
			suggestionKey,
			operations,
			staleReason,
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
		requestToken = null,
		contextSignature = null,
		reviewContextSignature = null,
		resolvedContextSignature = null
	) {
		return {
			type: 'SET_TEMPLATE_PART_RECS',
			templatePartRef,
			payload,
			prompt,
			requestToken,
			contextSignature,
			reviewContextSignature,
			resolvedContextSignature,
		};
	},

	setTemplatePartReviewFreshnessState(
		status,
		requestToken = null,
		staleReason = null
	) {
		return {
			type: 'SET_TEMPLATE_PART_REVIEW_FRESHNESS_STATE',
			status,
			requestToken,
			staleReason,
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
		operations = [],
		staleReason = null
	) {
		return {
			type: 'SET_TEMPLATE_PART_APPLY_STATE',
			status,
			error,
			suggestionKey,
			operations,
			staleReason,
		};
	},

	setGlobalStylesStatus( status, error = null, requestToken = null ) {
		return {
			type: 'SET_GLOBAL_STYLES_STATUS',
			status,
			error,
			requestToken,
		};
	},

	setGlobalStylesRecommendations(
		scope,
		payload,
		prompt = '',
		requestToken = null,
		contextSignature = null,
		reviewContextSignature = null,
		resolvedContextSignature = null
	) {
		return {
			type: 'SET_GLOBAL_STYLES_RECS',
			scope,
			payload,
			prompt,
			requestToken,
			contextSignature,
			reviewContextSignature,
			resolvedContextSignature,
		};
	},

	setGlobalStylesReviewFreshnessState(
		status,
		requestToken = null,
		staleReason = null
	) {
		return {
			type: 'SET_GLOBAL_STYLES_REVIEW_FRESHNESS_STATE',
			status,
			requestToken,
			staleReason,
		};
	},

	setGlobalStylesSelectedSuggestion( suggestionKey = null ) {
		return {
			type: 'SET_GLOBAL_STYLES_SELECTED_SUGGESTION',
			suggestionKey,
		};
	},

	setGlobalStylesApplyState(
		status,
		error = null,
		suggestionKey = null,
		operations = [],
		staleReason = null
	) {
		return {
			type: 'SET_GLOBAL_STYLES_APPLY_STATE',
			status,
			error,
			suggestionKey,
			operations,
			staleReason,
		};
	},

	setStyleBookStatus( status, error = null, requestToken = null ) {
		return {
			type: 'SET_STYLE_BOOK_STATUS',
			status,
			error,
			requestToken,
		};
	},

	setStyleBookRecommendations(
		scope,
		payload,
		prompt = '',
		requestToken = null,
		contextSignature = null,
		reviewContextSignature = null,
		resolvedContextSignature = null
	) {
		return {
			type: 'SET_STYLE_BOOK_RECS',
			scope,
			payload,
			prompt,
			requestToken,
			contextSignature,
			reviewContextSignature,
			resolvedContextSignature,
		};
	},

	setStyleBookReviewFreshnessState(
		status,
		requestToken = null,
		staleReason = null
	) {
		return {
			type: 'SET_STYLE_BOOK_REVIEW_FRESHNESS_STATE',
			status,
			requestToken,
			staleReason,
		};
	},

	setStyleBookSelectedSuggestion( suggestionKey = null ) {
		return {
			type: 'SET_STYLE_BOOK_SELECTED_SUGGESTION',
			suggestionKey,
		};
	},

	setStyleBookApplyState(
		status,
		error = null,
		suggestionKey = null,
		operations = [],
		staleReason = null
	) {
		return {
			type: 'SET_STYLE_BOOK_APPLY_STATE',
			status,
			error,
			suggestionKey,
			operations,
			staleReason,
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

	clearGlobalStylesRecommendations() {
		return ( { dispatch } ) => {
			if ( actions._globalStylesAbort ) {
				actions._globalStylesAbort.abort();
				actions._globalStylesAbort = null;
			}

			dispatch( { type: 'CLEAR_GLOBAL_STYLES_RECS' } );
		};
	},

	clearStyleBookRecommendations() {
		return ( { dispatch } ) => {
			if ( actions._styleBookAbort ) {
				actions._styleBookAbort.abort();
				actions._styleBookAbort = null;
			}

			dispatch( { type: 'CLEAR_STYLE_BOOK_RECS' } );
		};
	},

	undoActivity( activityId ) {
		return async ( { dispatch: localDispatch, registry, select } ) => {
			const scope = getCurrentActivityScope( registry );

			syncActivitySession( localDispatch, select, scope );
			let activityLog = select.getActivityLog?.() || [];
			let activity =
				activityLog.find( ( entry ) => entry?.id === activityId ) ||
				null;

			if ( ! activity ) {
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

			const scopeKey =
				activity?.document?.scopeKey ||
				getScopeKey( scope ) ||
				select.getActivityScopeKey?.() ||
				null;
			const reconcileUndoConflict = async ( syncError, timestamp ) => {
				if ( ! isUndoSyncConflictError( syncError ) || ! scopeKey ) {
					return null;
				}

				try {
					const reconciledEntry =
						await reconcileActivityEntryFromServer(
							activity,
							scopeKey
						);

					if ( ! reconciledEntry ) {
						return null;
					}

					const reconciledEntries = mergeActivityEntries(
						select.getActivityLog?.() || [],
						[ reconciledEntry ]
					);

					refreshActivitySession(
						localDispatch,
						scopeKey,
						reconciledEntries
					);

					const reconciledUndo = reconciledEntry?.undo || {};

					if ( reconciledUndo.status === 'undone' ) {
						return {
							ok: true,
							timestamp:
								reconciledUndo.updatedAt ||
								reconciledUndo.undoneAt ||
								timestamp,
							entry: reconciledEntry,
						};
					}

					if ( reconciledUndo.status === 'failed' ) {
						return {
							ok: false,
							timestamp: reconciledUndo.updatedAt || timestamp,
							error:
								reconciledUndo.error ||
								'This AI action can no longer be undone automatically.',
							entry: reconciledEntry,
						};
					}
				} catch {
					return null;
				}

				return null;
			};

			if ( scopeKey && isServerBackedActivityEntry( activity ) ) {
				try {
					const serverEntries =
						await fetchServerActivityEntries( scopeKey );
					const refreshedEntries = mergeActivityEntries(
						serverEntries,
						activityLog.filter( isLocalActivityEntry )
					);

					refreshActivitySession(
						localDispatch,
						scopeKey,
						refreshedEntries
					);
					activityLog = refreshedEntries;
					activity =
						activityLog.find(
							( entry ) => entry?.id === activityId
						) || null;

					if ( ! activity ) {
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
				} catch {
					// Fall back to the local activity cache when the server is unavailable.
				}
			}

			const entityEntries = getEntityActivityEntries(
				activityLog,
				activity
			);
			const runtimeUndoResolver = getActivityRuntimeUndoResolver(
				activity?.surface,
				registry
			);
			const resolvedActivity =
				getResolvedActivityEntries(
					entityEntries,
					runtimeUndoResolver
				).find( ( entry ) => entry?.id === activityId ) || null;
			const currentPendingSyncType =
				getPendingActivitySyncType( activity );
			const buildUndoTransitionEntry = (
				status,
				error = null,
				timestamp = new Date().toISOString()
			) => ( {
				...activity,
				undo: {
					...( activity.undo || {} ),
					canUndo: false,
					status,
					error,
					updatedAt: timestamp,
					undoneAt:
						status === 'undone'
							? timestamp
							: activity?.undo?.undoneAt || null,
				},
			} );
			const syncUndoStateChange = async ( status, error = null ) => {
				const timestamp = new Date().toISOString();
				const persistence = shouldSyncUndoTransition( activity )
					? buildActivityPersistenceUpdate(
							'server',
							null,
							timestamp
					  )
					: buildActivityPersistenceUpdate(
							'local',
							currentPendingSyncType || 'create',
							timestamp
					  );

				localDispatch(
					actions.updateActivityUndoState(
						activityId,
						status,
						error,
						timestamp,
						persistence
					)
				);

				if ( ! shouldSyncUndoTransition( activity ) ) {
					persistActivitySession( select );

					return {
						ok: true,
						timestamp,
					};
				}

				try {
					await persistActivityUndoTransition(
						buildUndoTransitionEntry( status, error, timestamp )
					);
					persistActivitySession( select );

					return {
						ok: true,
						timestamp,
					};
				} catch ( syncError ) {
					const reconciledResult = await reconcileUndoConflict(
						syncError,
						timestamp
					);

					if ( reconciledResult ) {
						return reconciledResult;
					}

					if ( isNonRetryableUndoSyncError( activity, syncError ) ) {
						const terminalEntry = buildNonRetryableUndoSyncEntry(
							activity,
							syncError,
							timestamp
						);

						localDispatch(
							actions.updateActivityUndoState(
								activityId,
								terminalEntry.undo.status,
								terminalEntry.undo.error,
								terminalEntry.undo.updatedAt,
								terminalEntry.persistence
							)
						);
						persistActivitySession( select );

						return {
							ok: false,
							timestamp,
							error: terminalEntry.undo.error,
						};
					}

					localDispatch(
						actions.updateActivityUndoState(
							activityId,
							status,
							error,
							timestamp,
							buildActivityPersistenceUpdate(
								'local',
								'undo',
								timestamp
							)
						)
					);
					persistActivitySession( select );

					return {
						ok: false,
						timestamp,
					};
				}
			};
			const resolvedUndo = resolvedActivity?.undo || activity?.undo || {};

			if ( resolvedUndo?.status === 'undone' ) {
				return {
					ok: true,
					alreadyUndone: true,
				};
			}

			if ( resolvedUndo?.status === 'failed' ) {
				const failureMessage =
					resolvedUndo?.error ||
					'This AI action can no longer be undone automatically.';
				const syncResult = await syncUndoStateChange(
					'failed',
					failureMessage
				);
				const surfacedError = syncResult.ok
					? failureMessage
					: syncResult.error ||
					  buildUndoAuditSyncError( failureMessage );

				localDispatch(
					actions.setUndoState( 'error', surfacedError, activityId )
				);

				return {
					ok: false,
					error: surfacedError,
				};
			}

			if (
				resolvedUndo?.canUndo !== true ||
				resolvedUndo?.status !== 'available'
			) {
				localDispatch(
					actions.setUndoState(
						'error',
						resolvedUndo?.error ||
							'This AI action can no longer be undone automatically.',
						activityId
					)
				);

				return {
					ok: false,
					error:
						resolvedUndo?.error ||
						'This AI action can no longer be undone automatically.',
				};
			}

			localDispatch(
				actions.setUndoState( 'undoing', null, activityId )
			);

			let result;

			if ( activity.surface === 'template' ) {
				result = undoTemplateSuggestionOperations( activity );
			} else if ( activity.surface === 'template-part' ) {
				result = undoTemplatePartSuggestionOperations( activity );
			} else if (
				activity.surface === 'global-styles' ||
				activity.surface === 'style-book'
			) {
				result = undoGlobalStyleSuggestionOperations(
					activity,
					registry
				);
			} else {
				result = undoBlockActivity( activity, registry );
			}

			if ( ! result.ok ) {
				const failureMessage = result.error || 'Undo failed.';
				const syncResult = await syncUndoStateChange(
					'failed',
					failureMessage
				);
				const surfacedError = syncResult.ok
					? failureMessage
					: syncResult.error ||
					  buildUndoAuditSyncError( failureMessage );

				localDispatch(
					actions.setUndoState( 'error', surfacedError, activityId )
				);

				return {
					...result,
					error: surfacedError,
				};
			}

			const syncResult = await syncUndoStateChange( 'undone' );

			if ( ! syncResult.ok ) {
				const surfacedError =
					syncResult.error ||
					buildUndoAuditSyncError( 'Undo applied locally.' );

				localDispatch(
					actions.setUndoState( 'error', surfacedError, activityId )
				);

				return {
					...result,
					ok: false,
					error: surfacedError,
				};
			}

			localDispatch(
				actions.setUndoState( 'success', null, activityId )
			);

			return result;
		};
	},

	applyTemplateSuggestion(
		suggestion,
		currentRequestSignature = null,
		liveRequestInput = null
	) {
		return buildExecutableSurfaceApplyThunk(
			getTemplateExecutableSurfaceApplyConfig(),
			suggestion,
			currentRequestSignature,
			liveRequestInput,
			EXECUTABLE_SURFACE_APPLY_DEPS
		);
	},

	applyTemplatePartSuggestion(
		suggestion,
		currentRequestSignature = null,
		liveRequestInput = null
	) {
		return buildExecutableSurfaceApplyThunk(
			getTemplatePartExecutableSurfaceApplyConfig(),
			suggestion,
			currentRequestSignature,
			liveRequestInput,
			EXECUTABLE_SURFACE_APPLY_DEPS
		);
	},

	applyGlobalStylesSuggestion(
		suggestion,
		currentRequestSignature = null,
		liveRequestInput = null
	) {
		return buildExecutableSurfaceApplyThunk(
			getGlobalStylesExecutableSurfaceApplyConfig(),
			suggestion,
			currentRequestSignature,
			liveRequestInput,
			EXECUTABLE_SURFACE_APPLY_DEPS
		);
	},

	applyStyleBookSuggestion(
		suggestion,
		currentRequestSignature = null,
		liveRequestInput = null
	) {
		return buildExecutableSurfaceApplyThunk(
			getStyleBookExecutableSurfaceApplyConfig(),
			suggestion,
			currentRequestSignature,
			liveRequestInput,
			EXECUTABLE_SURFACE_APPLY_DEPS
		);
	},

	revalidateTemplateReviewFreshness(
		currentRequestSignature = null,
		liveRequestInput = null
	) {
		return buildExecutableSurfaceReviewFreshnessThunk(
			getTemplateExecutableSurfaceReviewConfig(),
			currentRequestSignature,
			liveRequestInput,
			EXECUTABLE_SURFACE_REVIEW_DEPS
		);
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

	revalidateTemplatePartReviewFreshness(
		currentRequestSignature = null,
		liveRequestInput = null
	) {
		return buildExecutableSurfaceReviewFreshnessThunk(
			getTemplatePartExecutableSurfaceReviewConfig(),
			currentRequestSignature,
			liveRequestInput,
			EXECUTABLE_SURFACE_REVIEW_DEPS
		);
	},

	revalidateGlobalStylesReviewFreshness(
		currentRequestSignature = null,
		liveRequestInput = null
	) {
		return buildExecutableSurfaceReviewFreshnessThunk(
			getGlobalStylesExecutableSurfaceReviewConfig(),
			currentRequestSignature,
			liveRequestInput,
			EXECUTABLE_SURFACE_REVIEW_DEPS
		);
	},

	revalidateStyleBookReviewFreshness(
		currentRequestSignature = null,
		liveRequestInput = null
	) {
		return buildExecutableSurfaceReviewFreshnessThunk(
			getStyleBookExecutableSurfaceReviewConfig(),
			currentRequestSignature,
			liveRequestInput,
			EXECUTABLE_SURFACE_REVIEW_DEPS
		);
	},

	fetchTemplateRecommendations( input ) {
		return buildExecutableSurfaceFetchThunk(
			getTemplateExecutableSurfaceFetchConfig(),
			input,
			EXECUTABLE_SURFACE_FETCH_DEPS
		);
	},

	fetchTemplatePartRecommendations( input ) {
		return buildExecutableSurfaceFetchThunk(
			getTemplatePartExecutableSurfaceFetchConfig(),
			input,
			EXECUTABLE_SURFACE_FETCH_DEPS
		);
	},

	fetchGlobalStylesRecommendations( input ) {
		return buildExecutableSurfaceFetchThunk(
			getGlobalStylesExecutableSurfaceFetchConfig(),
			input,
			EXECUTABLE_SURFACE_FETCH_DEPS
		);
	},

	fetchStyleBookRecommendations( input ) {
		return buildExecutableSurfaceFetchThunk(
			getStyleBookExecutableSurfaceFetchConfig(),
			input,
			EXECUTABLE_SURFACE_FETCH_DEPS
		);
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
							action.status === 'error'
								? action.staleReason ?? null
								: null,
					},
				},
			};
		}
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
			return {
				...state,
				patternStatus: action.status,
				patternError: action.error ?? null,
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
			return {
				...state,
				patternRecommendations: action.recommendations,
				patternBadge: getPatternBadgeReason( action.recommendations ),
				patternError: null,
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
				navigationReviewFreshnessStatus:
					action.reviewContextSignature ? 'fresh' : 'idle',
				navigationStatus: 'ready',
				navigationError: null,
				navigationReviewStaleReason: null,
			};
		case 'SET_NAVIGATION_REVIEW_FRESHNESS_STATE':
			if ( isStaleNavigationReviewRequest( state, action.requestToken ) ) {
				return state;
			}

			return {
				...state,
				navigationReviewRequestToken:
					action.requestToken ?? state.navigationReviewRequestToken,
				navigationReviewFreshnessStatus:
					action.status ?? state.navigationReviewFreshnessStatus,
				navigationReviewStaleReason:
					action.status === 'stale'
						? action.staleReason ?? null
						: null,
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
					state.templateSelectedSuggestionKey,
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
				templateReviewRequestToken:
					action.status === 'loading'
						? state.templateReviewRequestToken + 1
						: state.templateReviewRequestToken,
				templateReviewFreshnessStatus:
					action.status === 'loading'
						? 'idle'
						: state.templateReviewFreshnessStatus,
				templateReviewStaleReason:
					action.status === 'loading'
						? null
						: state.templateReviewStaleReason,
				templateStaleReason:
					action.status === 'loading'
						? null
						: state.templateStaleReason,
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
				templateContextSignature: action.contextSignature ?? null,
				templateReviewContextSignature:
					action.reviewContextSignature ?? null,
				templateResolvedContextSignature:
					action.resolvedContextSignature ?? null,
				templateRequestToken:
					action.requestToken ?? state.templateRequestToken,
				templateResultToken: state.templateResultToken + 1,
				templateReviewRequestToken:
					state.templateReviewRequestToken + 1,
				templateReviewFreshnessStatus: action.reviewContextSignature
					? 'fresh'
					: 'idle',
				templateStatus: 'ready',
				templateError: null,
				templateSelectedSuggestionKey: null,
				templateApplyStatus: 'idle',
				templateApplyError: null,
				templateLastAppliedSuggestionKey: null,
				templateLastAppliedOperations: [],
				templateReviewStaleReason: null,
				templateStaleReason: null,
			};
		case 'SET_TEMPLATE_REVIEW_FRESHNESS_STATE':
			if ( isStaleTemplateReviewRequest( state, action.requestToken ) ) {
				return state;
			}

			return {
				...state,
				templateReviewRequestToken:
					action.requestToken ?? state.templateReviewRequestToken,
				templateReviewFreshnessStatus:
					action.status ?? state.templateReviewFreshnessStatus,
				templateReviewStaleReason:
					action.status === 'stale'
						? action.staleReason ?? null
						: null,
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
				templateStaleReason:
					action.status === 'error'
						? action.staleReason ?? null
						: null,
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
				templateContextSignature: null,
				templateReviewContextSignature: null,
				templateResolvedContextSignature: null,
				templateRequestToken: state.templateRequestToken + 1,
				templateResultToken: state.templateResultToken + 1,
				templateReviewRequestToken:
					state.templateReviewRequestToken + 1,
				templateReviewFreshnessStatus: 'idle',
				templateSelectedSuggestionKey: null,
				templateApplyStatus: 'idle',
				templateApplyError: null,
				templateLastAppliedSuggestionKey: null,
				templateLastAppliedOperations: [],
				templateReviewStaleReason: null,
				templateStaleReason: null,
			};
		case 'SET_GLOBAL_STYLES_STATUS':
			if ( isStaleGlobalStylesRequest( state, action.requestToken ) ) {
				return state;
			}

			return {
				...state,
				globalStylesStatus: action.status,
				globalStylesError: action.error ?? null,
				globalStylesRequestToken:
					action.requestToken ?? state.globalStylesRequestToken,
				globalStylesSelectedSuggestionKey:
					state.globalStylesSelectedSuggestionKey,
				globalStylesApplyStatus:
					action.status === 'loading'
						? 'idle'
						: state.globalStylesApplyStatus,
				globalStylesApplyError:
					action.status === 'loading'
						? null
						: state.globalStylesApplyError,
				globalStylesLastAppliedSuggestionKey:
					action.status === 'loading'
						? null
						: state.globalStylesLastAppliedSuggestionKey,
				globalStylesLastAppliedOperations:
					action.status === 'loading'
						? []
						: state.globalStylesLastAppliedOperations,
				globalStylesReviewRequestToken:
					action.status === 'loading'
						? state.globalStylesReviewRequestToken + 1
						: state.globalStylesReviewRequestToken,
				globalStylesReviewFreshnessStatus:
					action.status === 'loading'
						? 'idle'
						: state.globalStylesReviewFreshnessStatus,
				globalStylesReviewStaleReason:
					action.status === 'loading'
						? null
						: state.globalStylesReviewStaleReason,
				globalStylesStaleReason:
					action.status === 'loading'
						? null
						: state.globalStylesStaleReason,
			};
		case 'SET_GLOBAL_STYLES_RECS':
			if ( isStaleGlobalStylesRequest( state, action.requestToken ) ) {
				return state;
			}

			return {
				...state,
				globalStylesSuggestions: action.payload?.suggestions ?? [],
				globalStylesExplanation: action.payload?.explanation ?? '',
				globalStylesRequestPrompt: action.prompt ?? '',
				globalStylesScopeKey: getScopeKey( action.scope ),
				globalStylesEntityId:
					action.scope?.globalStylesId ||
					action.scope?.entityId ||
					null,
				globalStylesContextSignature: action.contextSignature ?? null,
				globalStylesReviewContextSignature:
					action.reviewContextSignature ?? null,
				globalStylesResolvedContextSignature:
					action.resolvedContextSignature ?? null,
				globalStylesRequestToken:
					action.requestToken ?? state.globalStylesRequestToken,
				globalStylesResultToken: state.globalStylesResultToken + 1,
				globalStylesReviewRequestToken:
					state.globalStylesReviewRequestToken + 1,
				globalStylesReviewFreshnessStatus: action.reviewContextSignature
					? 'fresh'
					: 'idle',
				globalStylesStatus: 'ready',
				globalStylesError: null,
				globalStylesSelectedSuggestionKey: null,
				globalStylesApplyStatus: 'idle',
				globalStylesApplyError: null,
				globalStylesLastAppliedSuggestionKey: null,
				globalStylesLastAppliedOperations: [],
				globalStylesReviewStaleReason: null,
				globalStylesStaleReason: null,
			};
		case 'SET_GLOBAL_STYLES_REVIEW_FRESHNESS_STATE':
			if (
				isStaleGlobalStylesReviewRequest( state, action.requestToken )
			) {
				return state;
			}

			return {
				...state,
				globalStylesReviewRequestToken:
					action.requestToken ?? state.globalStylesReviewRequestToken,
				globalStylesReviewFreshnessStatus:
					action.status ?? state.globalStylesReviewFreshnessStatus,
				globalStylesReviewStaleReason:
					action.status === 'stale'
						? action.staleReason ?? null
						: null,
			};
		case 'SET_GLOBAL_STYLES_SELECTED_SUGGESTION':
			return {
				...state,
				globalStylesSelectedSuggestionKey: action.suggestionKey ?? null,
				globalStylesApplyStatus:
					state.globalStylesApplyStatus === 'error'
						? 'idle'
						: state.globalStylesApplyStatus,
				globalStylesApplyError:
					state.globalStylesApplyStatus === 'error'
						? null
						: state.globalStylesApplyError,
			};
		case 'SET_GLOBAL_STYLES_APPLY_STATE':
			return {
				...state,
				globalStylesApplyStatus: action.status,
				globalStylesApplyError: action.error ?? null,
				globalStylesLastAppliedSuggestionKey:
					action.status === 'success'
						? action.suggestionKey ?? null
						: state.globalStylesLastAppliedSuggestionKey,
				globalStylesLastAppliedOperations:
					action.status === 'success'
						? action.operations ?? []
						: state.globalStylesLastAppliedOperations,
				globalStylesStaleReason:
					action.status === 'error'
						? action.staleReason ?? null
						: null,
			};
		case 'SET_STYLE_BOOK_STATUS':
			if ( isStaleStyleBookRequest( state, action.requestToken ) ) {
				return state;
			}

			return {
				...state,
				styleBookStatus: action.status,
				styleBookError: action.error ?? null,
				styleBookRequestToken:
					action.requestToken ?? state.styleBookRequestToken,
				styleBookSelectedSuggestionKey:
					state.styleBookSelectedSuggestionKey,
				styleBookApplyStatus:
					action.status === 'loading'
						? 'idle'
						: state.styleBookApplyStatus,
				styleBookApplyError:
					action.status === 'loading'
						? null
						: state.styleBookApplyError,
				styleBookLastAppliedSuggestionKey:
					action.status === 'loading'
						? null
						: state.styleBookLastAppliedSuggestionKey,
				styleBookLastAppliedOperations:
					action.status === 'loading'
						? []
						: state.styleBookLastAppliedOperations,
				styleBookReviewRequestToken:
					action.status === 'loading'
						? state.styleBookReviewRequestToken + 1
						: state.styleBookReviewRequestToken,
				styleBookReviewFreshnessStatus:
					action.status === 'loading'
						? 'idle'
						: state.styleBookReviewFreshnessStatus,
				styleBookReviewStaleReason:
					action.status === 'loading'
						? null
						: state.styleBookReviewStaleReason,
				styleBookStaleReason:
					action.status === 'loading'
						? null
						: state.styleBookStaleReason,
			};
		case 'SET_STYLE_BOOK_RECS':
			if ( isStaleStyleBookRequest( state, action.requestToken ) ) {
				return state;
			}

			return {
				...state,
				styleBookSuggestions: action.payload?.suggestions ?? [],
				styleBookExplanation: action.payload?.explanation ?? '',
				styleBookRequestPrompt: action.prompt ?? '',
				styleBookScopeKey: getScopeKey( action.scope ),
				styleBookGlobalStylesId: action.scope?.globalStylesId || null,
				styleBookBlockName: action.scope?.blockName || null,
				styleBookBlockTitle: action.scope?.blockTitle || '',
				styleBookContextSignature: action.contextSignature ?? null,
				styleBookReviewContextSignature:
					action.reviewContextSignature ?? null,
				styleBookResolvedContextSignature:
					action.resolvedContextSignature ?? null,
				styleBookRequestToken:
					action.requestToken ?? state.styleBookRequestToken,
				styleBookResultToken: state.styleBookResultToken + 1,
				styleBookReviewRequestToken:
					state.styleBookReviewRequestToken + 1,
				styleBookReviewFreshnessStatus: action.reviewContextSignature
					? 'fresh'
					: 'idle',
				styleBookStatus: 'ready',
				styleBookError: null,
				styleBookSelectedSuggestionKey: null,
				styleBookApplyStatus: 'idle',
				styleBookApplyError: null,
				styleBookLastAppliedSuggestionKey: null,
				styleBookLastAppliedOperations: [],
				styleBookReviewStaleReason: null,
				styleBookStaleReason: null,
			};
		case 'SET_STYLE_BOOK_REVIEW_FRESHNESS_STATE':
			if ( isStaleStyleBookReviewRequest( state, action.requestToken ) ) {
				return state;
			}

			return {
				...state,
				styleBookReviewRequestToken:
					action.requestToken ?? state.styleBookReviewRequestToken,
				styleBookReviewFreshnessStatus:
					action.status ?? state.styleBookReviewFreshnessStatus,
				styleBookReviewStaleReason:
					action.status === 'stale'
						? action.staleReason ?? null
						: null,
			};
		case 'SET_STYLE_BOOK_SELECTED_SUGGESTION':
			return {
				...state,
				styleBookSelectedSuggestionKey: action.suggestionKey ?? null,
				styleBookApplyStatus:
					state.styleBookApplyStatus === 'error'
						? 'idle'
						: state.styleBookApplyStatus,
				styleBookApplyError:
					state.styleBookApplyStatus === 'error'
						? null
						: state.styleBookApplyError,
			};
		case 'SET_STYLE_BOOK_APPLY_STATE':
			return {
				...state,
				styleBookApplyStatus: action.status,
				styleBookApplyError: action.error ?? null,
				styleBookLastAppliedSuggestionKey:
					action.status === 'success'
						? action.suggestionKey ?? null
						: state.styleBookLastAppliedSuggestionKey,
				styleBookLastAppliedOperations:
					action.status === 'success'
						? action.operations ?? []
						: state.styleBookLastAppliedOperations,
				styleBookStaleReason:
					action.status === 'error'
						? action.staleReason ?? null
						: null,
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
					state.templatePartSelectedSuggestionKey,
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
				templatePartReviewRequestToken:
					action.status === 'loading'
						? state.templatePartReviewRequestToken + 1
						: state.templatePartReviewRequestToken,
				templatePartReviewFreshnessStatus:
					action.status === 'loading'
						? 'idle'
						: state.templatePartReviewFreshnessStatus,
				templatePartReviewStaleReason:
					action.status === 'loading'
						? null
						: state.templatePartReviewStaleReason,
				templatePartStaleReason:
					action.status === 'loading'
						? null
						: state.templatePartStaleReason,
			};
		case 'SET_TEMPLATE_PART_RECS':
			if ( isStaleTemplatePartRequest( state, action.requestToken ) ) {
				return state;
			}

			return {
				...state,
				templatePartRecommendations: action.payload?.suggestions ?? [],
				templatePartExplanation: action.payload?.explanation ?? '',
				templatePartRequestPrompt: action.prompt ?? '',
				templatePartRef: action.templatePartRef,
				templatePartContextSignature: action.contextSignature ?? null,
				templatePartReviewContextSignature:
					action.reviewContextSignature ?? null,
				templatePartResolvedContextSignature:
					action.resolvedContextSignature ?? null,
				templatePartRequestToken:
					action.requestToken ?? state.templatePartRequestToken,
				templatePartResultToken: state.templatePartResultToken + 1,
				templatePartReviewRequestToken:
					state.templatePartReviewRequestToken + 1,
				templatePartReviewFreshnessStatus: action.reviewContextSignature
					? 'fresh'
					: 'idle',
				templatePartStatus: 'ready',
				templatePartError: null,
				templatePartSelectedSuggestionKey: null,
				templatePartApplyStatus: 'idle',
				templatePartApplyError: null,
				templatePartLastAppliedSuggestionKey: null,
				templatePartLastAppliedOperations: [],
				templatePartReviewStaleReason: null,
				templatePartStaleReason: null,
			};
		case 'SET_TEMPLATE_PART_REVIEW_FRESHNESS_STATE':
			if (
				isStaleTemplatePartReviewRequest( state, action.requestToken )
			) {
				return state;
			}

			return {
				...state,
				templatePartReviewRequestToken:
					action.requestToken ?? state.templatePartReviewRequestToken,
				templatePartReviewFreshnessStatus:
					action.status ?? state.templatePartReviewFreshnessStatus,
				templatePartReviewStaleReason:
					action.status === 'stale'
						? action.staleReason ?? null
						: null,
			};
		case 'SET_TEMPLATE_PART_SELECTED_SUGGESTION':
			return {
				...state,
				templatePartSelectedSuggestionKey: action.suggestionKey ?? null,
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
				templatePartStaleReason:
					action.status === 'error'
						? action.staleReason ?? null
						: null,
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
				templatePartContextSignature: null,
				templatePartReviewContextSignature: null,
				templatePartResolvedContextSignature: null,
				templatePartRequestToken: state.templatePartRequestToken + 1,
				templatePartResultToken: state.templatePartResultToken + 1,
				templatePartReviewRequestToken:
					state.templatePartReviewRequestToken + 1,
				templatePartReviewFreshnessStatus: 'idle',
				templatePartSelectedSuggestionKey: null,
				templatePartApplyStatus: 'idle',
				templatePartApplyError: null,
				templatePartLastAppliedSuggestionKey: null,
				templatePartLastAppliedOperations: [],
				templatePartReviewStaleReason: null,
				templatePartStaleReason: null,
			};
		case 'CLEAR_GLOBAL_STYLES_RECS':
			return {
				...state,
				globalStylesSuggestions: [],
				globalStylesExplanation: '',
				globalStylesStatus: 'idle',
				globalStylesError: null,
				globalStylesRequestPrompt: '',
				globalStylesScopeKey: null,
				globalStylesEntityId: null,
				globalStylesContextSignature: null,
				globalStylesReviewContextSignature: null,
				globalStylesResolvedContextSignature: null,
				globalStylesRequestToken: state.globalStylesRequestToken + 1,
				globalStylesResultToken: state.globalStylesResultToken + 1,
				globalStylesReviewRequestToken:
					state.globalStylesReviewRequestToken + 1,
				globalStylesReviewFreshnessStatus: 'idle',
				globalStylesSelectedSuggestionKey: null,
				globalStylesApplyStatus: 'idle',
				globalStylesApplyError: null,
				globalStylesLastAppliedSuggestionKey: null,
				globalStylesLastAppliedOperations: [],
				globalStylesReviewStaleReason: null,
				globalStylesStaleReason: null,
			};
		case 'CLEAR_STYLE_BOOK_RECS':
			return {
				...state,
				styleBookSuggestions: [],
				styleBookExplanation: '',
				styleBookStatus: 'idle',
				styleBookError: null,
				styleBookRequestPrompt: '',
				styleBookScopeKey: null,
				styleBookGlobalStylesId: null,
				styleBookBlockName: null,
				styleBookBlockTitle: '',
				styleBookContextSignature: null,
				styleBookReviewContextSignature: null,
				styleBookResolvedContextSignature: null,
				styleBookRequestToken: state.styleBookRequestToken + 1,
				styleBookResultToken: state.styleBookResultToken + 1,
				styleBookReviewRequestToken:
					state.styleBookReviewRequestToken + 1,
				styleBookReviewFreshnessStatus: 'idle',
				styleBookSelectedSuggestionKey: null,
				styleBookApplyStatus: 'idle',
				styleBookApplyError: null,
				styleBookLastAppliedSuggestionKey: null,
				styleBookLastAppliedOperations: [],
				styleBookReviewStaleReason: null,
				styleBookStaleReason: null,
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
	getPatternStatus: ( state ) => state.patternStatus,
	getPatternBadge: ( state ) => state.patternBadge,
	getPatternError: ( state ) => state.patternError,
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
	getTemplateRecommendations: ( state ) => state.templateRecommendations,
	getTemplateExplanation: ( state ) => state.templateExplanation,
	getTemplateError: ( state ) => state.templateError,
	getTemplateRequestPrompt: ( state ) => state.templateRequestPrompt,
	getTemplateResultRef: ( state ) => state.templateRef,
	getTemplateContextSignature: ( state ) => state.templateContextSignature,
	getTemplateReviewContextSignature: ( state ) =>
		state.templateReviewContextSignature,
	getTemplateResolvedContextSignature: ( state ) =>
		state.templateResolvedContextSignature,
	getTemplateRequestToken: ( state ) => state.templateRequestToken,
	getTemplateResultToken: ( state ) => state.templateResultToken,
	getTemplateReviewRequestToken: ( state ) =>
		state.templateReviewRequestToken,
	getTemplateReviewFreshnessStatus: ( state ) =>
		state.templateReviewFreshnessStatus,
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
	getTemplateReviewStaleReason: ( state ) => state.templateReviewStaleReason,
	getTemplateStaleReason: ( state ) => state.templateStaleReason,
	getTemplatePartRecommendations: ( state ) =>
		state.templatePartRecommendations,
	getTemplatePartExplanation: ( state ) => state.templatePartExplanation,
	getTemplatePartError: ( state ) => state.templatePartError,
	getTemplatePartRequestPrompt: ( state ) => state.templatePartRequestPrompt,
	getTemplatePartResultRef: ( state ) => state.templatePartRef,
	getTemplatePartContextSignature: ( state ) =>
		state.templatePartContextSignature,
	getTemplatePartReviewContextSignature: ( state ) =>
		state.templatePartReviewContextSignature,
	getTemplatePartResolvedContextSignature: ( state ) =>
		state.templatePartResolvedContextSignature,
	getTemplatePartRequestToken: ( state ) => state.templatePartRequestToken,
	getTemplatePartResultToken: ( state ) => state.templatePartResultToken,
	getTemplatePartReviewRequestToken: ( state ) =>
		state.templatePartReviewRequestToken,
	getTemplatePartReviewFreshnessStatus: ( state ) =>
		state.templatePartReviewFreshnessStatus,
	isTemplatePartLoading: ( state ) => state.templatePartStatus === 'loading',
	getTemplatePartStatus: ( state ) => state.templatePartStatus,
	getTemplatePartSelectedSuggestionKey: ( state ) =>
		state.templatePartSelectedSuggestionKey,
	getTemplatePartApplyStatus: ( state ) => state.templatePartApplyStatus,
	getTemplatePartApplyError: ( state ) => state.templatePartApplyError,
	isTemplatePartApplying: ( state ) =>
		state.templatePartApplyStatus === 'applying',
	getTemplatePartLastAppliedSuggestionKey: ( state ) =>
		state.templatePartLastAppliedSuggestionKey,
	getTemplatePartLastAppliedOperations: ( state ) =>
		state.templatePartLastAppliedOperations,
	getTemplatePartReviewStaleReason: ( state ) =>
		state.templatePartReviewStaleReason,
	getTemplatePartStaleReason: ( state ) => state.templatePartStaleReason,
	getGlobalStylesRecommendations: ( state ) => state.globalStylesSuggestions,
	getGlobalStylesExplanation: ( state ) => state.globalStylesExplanation,
	getGlobalStylesError: ( state ) => state.globalStylesError,
	getGlobalStylesRequestPrompt: ( state ) => state.globalStylesRequestPrompt,
	getGlobalStylesResultRef: ( state ) => state.globalStylesEntityId,
	getGlobalStylesScopeKey: ( state ) => state.globalStylesScopeKey,
	getGlobalStylesContextSignature: ( state ) =>
		state.globalStylesContextSignature,
	getGlobalStylesReviewContextSignature: ( state ) =>
		state.globalStylesReviewContextSignature,
	getGlobalStylesResolvedContextSignature: ( state ) =>
		state.globalStylesResolvedContextSignature,
	getGlobalStylesRequestToken: ( state ) => state.globalStylesRequestToken,
	getGlobalStylesResultToken: ( state ) => state.globalStylesResultToken,
	getGlobalStylesReviewRequestToken: ( state ) =>
		state.globalStylesReviewRequestToken,
	getGlobalStylesReviewFreshnessStatus: ( state ) =>
		state.globalStylesReviewFreshnessStatus,
	isGlobalStylesLoading: ( state ) => state.globalStylesStatus === 'loading',
	getGlobalStylesStatus: ( state ) => state.globalStylesStatus,
	getGlobalStylesSelectedSuggestionKey: ( state ) =>
		state.globalStylesSelectedSuggestionKey,
	getGlobalStylesApplyStatus: ( state ) => state.globalStylesApplyStatus,
	getGlobalStylesApplyError: ( state ) => state.globalStylesApplyError,
	isGlobalStylesApplying: ( state ) =>
		state.globalStylesApplyStatus === 'applying',
	getGlobalStylesLastAppliedSuggestionKey: ( state ) =>
		state.globalStylesLastAppliedSuggestionKey,
	getGlobalStylesLastAppliedOperations: ( state ) =>
		state.globalStylesLastAppliedOperations,
	getGlobalStylesReviewStaleReason: ( state ) =>
		state.globalStylesReviewStaleReason,
	getGlobalStylesStaleReason: ( state ) => state.globalStylesStaleReason,
	getStyleBookRecommendations: ( state ) => state.styleBookSuggestions,
	getStyleBookExplanation: ( state ) => state.styleBookExplanation,
	getStyleBookError: ( state ) => state.styleBookError,
	getStyleBookRequestPrompt: ( state ) => state.styleBookRequestPrompt,
	getStyleBookResultRef: ( state ) => state.styleBookScopeKey,
	getStyleBookScopeKey: ( state ) => state.styleBookScopeKey,
	getStyleBookGlobalStylesId: ( state ) => state.styleBookGlobalStylesId,
	getStyleBookBlockName: ( state ) => state.styleBookBlockName,
	getStyleBookBlockTitle: ( state ) => state.styleBookBlockTitle,
	getStyleBookContextSignature: ( state ) => state.styleBookContextSignature,
	getStyleBookReviewContextSignature: ( state ) =>
		state.styleBookReviewContextSignature,
	getStyleBookResolvedContextSignature: ( state ) =>
		state.styleBookResolvedContextSignature,
	getStyleBookRequestToken: ( state ) => state.styleBookRequestToken,
	getStyleBookResultToken: ( state ) => state.styleBookResultToken,
	getStyleBookReviewRequestToken: ( state ) =>
		state.styleBookReviewRequestToken,
	getStyleBookReviewFreshnessStatus: ( state ) =>
		state.styleBookReviewFreshnessStatus,
	isStyleBookLoading: ( state ) => state.styleBookStatus === 'loading',
	getStyleBookStatus: ( state ) => state.styleBookStatus,
	getStyleBookSelectedSuggestionKey: ( state ) =>
		state.styleBookSelectedSuggestionKey,
	getStyleBookApplyStatus: ( state ) => state.styleBookApplyStatus,
	getStyleBookApplyError: ( state ) => state.styleBookApplyError,
	isStyleBookApplying: ( state ) => state.styleBookApplyStatus === 'applying',
	getStyleBookLastAppliedSuggestionKey: ( state ) =>
		state.styleBookLastAppliedSuggestionKey,
	getStyleBookLastAppliedOperations: ( state ) =>
		state.styleBookLastAppliedOperations,
	getStyleBookReviewStaleReason: ( state ) =>
		state.styleBookReviewStaleReason,
	getStyleBookStaleReason: ( state ) => state.styleBookStaleReason,
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
	getTemplateInteractionState: ( state, options = {} ) =>
		getNormalizedInteractionState( 'template', {
			requestStatus: state.templateStatus,
			requestError: normalizeStringMessage( state.templateError ),
			applyStatus: state.templateApplyStatus,
			applyError: normalizeStringMessage( state.templateApplyError ),
			undoStatus: state.undoStatus,
			undoError: normalizeStringMessage( options.undoError ),
			hasResult: getTemplateHasResult( state ),
			hasPreview: Boolean(
				options.hasPreview ?? state.templateSelectedSuggestionKey
			),
			hasSuccess: Boolean( options.hasSuccess ),
			hasUndoSuccess: Boolean( options.hasUndoSuccess ),
			...options,
		} ),
	getTemplatePartInteractionState: ( state, options = {} ) =>
		getNormalizedInteractionState( 'template-part', {
			requestStatus: state.templatePartStatus,
			requestError: normalizeStringMessage( state.templatePartError ),
			applyStatus: state.templatePartApplyStatus,
			applyError: normalizeStringMessage( state.templatePartApplyError ),
			undoStatus: state.undoStatus,
			undoError: normalizeStringMessage( options.undoError ),
			hasResult: getTemplatePartHasResult( state ),
			hasPreview: Boolean(
				options.hasPreview ?? state.templatePartSelectedSuggestionKey
			),
			hasSuccess: Boolean( options.hasSuccess ),
			hasUndoSuccess: Boolean( options.hasUndoSuccess ),
			...options,
		} ),
	getGlobalStylesInteractionState: ( state, options = {} ) =>
		getNormalizedInteractionState( 'global-styles', {
			requestStatus: state.globalStylesStatus,
			requestError: normalizeStringMessage( state.globalStylesError ),
			applyStatus: state.globalStylesApplyStatus,
			applyError: normalizeStringMessage( state.globalStylesApplyError ),
			undoStatus: state.undoStatus,
			undoError: normalizeStringMessage( options.undoError ),
			hasResult: getGlobalStylesHasResult( state ),
			hasPreview: Boolean(
				options.hasPreview ?? state.globalStylesSelectedSuggestionKey
			),
			hasSuccess: Boolean( options.hasSuccess ),
			hasUndoSuccess: Boolean( options.hasUndoSuccess ),
			...options,
		} ),
	getStyleBookInteractionState: ( state, options = {} ) =>
		getNormalizedInteractionState( 'style-book', {
			requestStatus: state.styleBookStatus,
			requestError: normalizeStringMessage( state.styleBookError ),
			applyStatus: state.styleBookApplyStatus,
			applyError: normalizeStringMessage( state.styleBookApplyError ),
			undoStatus: state.undoStatus,
			undoError: normalizeStringMessage( options.undoError ),
			hasResult: getStyleBookHasResult( state ),
			hasPreview: Boolean(
				options.hasPreview ?? state.styleBookSelectedSuggestionKey
			),
			hasSuccess: Boolean( options.hasSuccess ),
			hasUndoSuccess: Boolean( options.hasUndoSuccess ),
			...options,
		} ),
};

const store = createReduxStore( STORE_NAME, { reducer, actions, selectors } );

register( store );

export { actions, reducer, selectors, STORE_NAME };
export default store;
