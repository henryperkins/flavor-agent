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
	navigationRecommendations: [],
	navigationExplanation: '',
	navigationStatus: 'idle',
	navigationError: null,
	navigationRequestPrompt: '',
	navigationBlockClientId: null,
	navigationRequestToken: 0,
	navigationResultToken: 0,
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
	globalStylesSuggestions: [],
	globalStylesExplanation: '',
	globalStylesStatus: 'idle',
	globalStylesError: null,
	globalStylesRequestPrompt: '',
	globalStylesScopeKey: null,
	globalStylesEntityId: null,
	globalStylesContextSignature: null,
	globalStylesRequestToken: 0,
	globalStylesResultToken: 0,
	globalStylesSelectedSuggestionKey: null,
	globalStylesApplyStatus: 'idle',
	globalStylesApplyError: null,
	globalStylesLastAppliedSuggestionKey: null,
	globalStylesLastAppliedOperations: [],
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
	styleBookRequestToken: 0,
	styleBookResultToken: 0,
	styleBookSelectedSuggestionKey: null,
	styleBookApplyStatus: 'idle',
	styleBookApplyError: null,
	styleBookLastAppliedSuggestionKey: null,
	styleBookLastAppliedOperations: [],
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

function isPlainObject( value ) {
	return (
		Boolean( value ) &&
		typeof value === 'object' &&
		! Array.isArray( value )
	);
}

function normalizeRequestMeta( requestMeta = null ) {
	return isPlainObject( requestMeta ) ? requestMeta : null;
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
		settings: attachRequestMetaToSuggestionList( payload.settings, requestMeta ),
		styles: attachRequestMetaToSuggestionList( payload.styles, requestMeta ),
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

function getTemplateHasResult( state ) {
	return Boolean(
		state.templateRef &&
			( state.templateRecommendations.length > 0 ||
				normalizeStringMessage( state.templateExplanation ) )
	);
}

function getTemplatePartHasResult( state ) {
	return Boolean(
		state.templatePartRef &&
			( state.templatePartRecommendations.length > 0 ||
				normalizeStringMessage( state.templatePartExplanation ) )
	);
}

function getGlobalStylesHasResult( state ) {
	return Boolean(
		state.globalStylesEntityId &&
			( state.globalStylesSuggestions.length > 0 ||
				normalizeStringMessage( state.globalStylesExplanation ) )
	);
}

function getStyleBookHasResult( state ) {
	return Boolean(
		state.styleBookScopeKey &&
			( state.styleBookSuggestions.length > 0 ||
				normalizeStringMessage( state.styleBookExplanation ) )
	);
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
			isDismissible: false,
			actionType: null,
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

	const advisoryMessage = normalizeStringMessage( options.advisoryMessage );
	const interactionState = getNormalizedInteractionState( surface, options );

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

function isStaleTemplatePartRequest( state, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state.templatePartRequestToken || 0 );
}

function isStaleNavigationRequest( state, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state.navigationRequestToken || 0 );
}

function isStaleGlobalStylesRequest( state, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state.globalStylesRequestToken || 0 );
}

function isStaleStyleBookRequest( state, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state.styleBookRequestToken || 0 );
}

function buildActivityDocument( scope ) {
	if ( ! scope?.key ) {
		return null;
	}

	return {
		scopeKey: scope.key,
		postType: scope.postType,
		entityId: scope.entityId,
		entityKind: scope.entityKind || '',
		entityName: scope.entityName || '',
		stylesheet: scope.stylesheet || '',
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

function syncActivitySession(
	localDispatch,
	select,
	scope,
	{ allowUnsavedMigration = false } = {}
) {
	const currentScopeKey = select.getActivityScopeKey?.() || null;
	const nextScopeKey = scope?.key || null;

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
				...( retryScope?.key ? { scope: retryScope } : {} ),
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

	return ! isRetryableActivitySyncError( error );
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
	if ( actions[ abortKey ] ) {
		actions[ abortKey ].abort();
	}

	const controller = new AbortController();
	actions[ abortKey ] = controller;
	const request = {
		...( buildRequest( { input, select } ) || {} ),
	};
	const requestData = request.requestData ?? input;

	request.requestData = requestData;
	onLoading?.( { dispatch, input, ...request } );

	try {
		const result = await apiFetch( {
			path: endpoint,
			method: 'POST',
			data: requestData,
			signal: controller.signal,
		} );

		onSuccess( {
			dispatch,
			input,
			result,
			...request,
		} );
	} catch ( err ) {
		if ( err?.name === 'AbortError' ) {
			return;
		}

		onError( {
			dispatch,
			err,
			input,
			...request,
		} );
	} finally {
		if ( actions[ abortKey ] === controller ) {
			actions[ abortKey ] = null;
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

	loadActivitySession( options = {} ) {
		return async ( { dispatch, registry, select } ) => {
			const requestToken = ( actions._activitySessionLoadToken || 0 ) + 1;
			actions._activitySessionLoadToken = requestToken;
			const scope = options?.scope || getCurrentActivityScope( registry );
			const nextScopeKey = scope?.key || null;

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
					failedEntries
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
				const payload = attachRequestMetaToRecommendationPayload(
					result.payload || {}
				);

				dispatch(
					actions.setBlockRecommendations(
						clientId,
						{
							blockName: context.block?.name || '',
							blockContext: context.block || {},
							prompt,
							...sanitizeRecommendationsForContext(
								payload,
								context.block || {}
							),
							requestMeta: normalizeRequestMeta( payload.requestMeta ),
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
			const applyErrorMessage =
				'This suggestion includes unsupported or unsafe attribute changes and could not be applied.';

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
				localDispatch(
					actions.setBlockRequestState(
						clientId,
						'error',
						applyErrorMessage,
						select.getBlockRequestToken( clientId ) || 0
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
		requestToken = null
	) {
		return {
			type: 'SET_NAVIGATION_RECS',
			blockClientId,
			payload,
			prompt,
			requestToken,
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
		return ( { dispatch, select } ) =>
			runAbortableRecommendationRequest( {
				abortKey: '_patternAbort',
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
				},
				select,
			} );
	},

	fetchNavigationRecommendations( input ) {
		return ( { dispatch, select } ) =>
			runAbortableRecommendationRequest( {
				abortKey: '_navigationAbort',
				buildRequest: ( {
					input: requestInput,
					select: registrySelect,
				} ) => {
					const requestToken =
						( registrySelect.getNavigationRequestToken?.() || 0 ) +
						1;
					const { blockClientId = null, ...requestData } =
						requestInput || {};

					return {
						blockClientId,
						requestData,
						requestToken,
					};
				},
				dispatch,
				endpoint: '/flavor-agent/v1/recommend-navigation',
				input,
				onError: ( {
					blockClientId,
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
							requestToken
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
							requestToken
						)
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
		contextSignature = null
	) {
		return {
			type: 'SET_GLOBAL_STYLES_RECS',
			scope,
			payload,
			prompt,
			requestToken,
			contextSignature,
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
		operations = []
	) {
		return {
			type: 'SET_GLOBAL_STYLES_APPLY_STATE',
			status,
			error,
			suggestionKey,
			operations,
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
		contextSignature = null
	) {
		return {
			type: 'SET_STYLE_BOOK_RECS',
			scope,
			payload,
			prompt,
			requestToken,
			contextSignature,
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
		operations = []
	) {
		return {
			type: 'SET_STYLE_BOOK_APPLY_STATE',
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
				scope?.key ||
				select.getActivityScopeKey?.() ||
				null;

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

			await recordActivityEntry(
				localDispatch,
				select,
				buildTemplateActivityEntry( {
					operations: result.operations,
					requestPrompt: select.getTemplateRequestPrompt?.() || '',
					requestMeta: suggestion?.requestMeta || null,
					requestToken: select.getTemplateResultToken?.() || 0,
					scope,
					suggestion,
					templateRef,
				} )
			);
			localDispatch(
				actions.setTemplateApplyState(
					'success',
					null,
					suggestion?.suggestionKey || null,
					result.operations
				)
			);
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

			await recordActivityEntry(
				localDispatch,
				select,
				buildTemplatePartActivityEntry( {
					operations: result.operations,
					requestPrompt:
						select.getTemplatePartRequestPrompt?.() || '',
					requestMeta: suggestion?.requestMeta || null,
					requestToken: select.getTemplatePartResultToken?.() || 0,
					scope,
					suggestion,
					templatePartRef,
				} )
			);
			localDispatch(
				actions.setTemplatePartApplyState(
					'success',
					null,
					suggestion?.suggestionKey || null,
					result.operations
				)
			);
			return result;
		};
	},

	applyGlobalStylesSuggestion( suggestion ) {
		return async ( { dispatch: localDispatch, registry, select } ) => {
			const scope = getCurrentActivityScope( registry );

			syncActivitySession( localDispatch, select, scope );

			localDispatch( actions.setGlobalStylesApplyState( 'applying' ) );

			const result = applyGlobalStyleSuggestionOperations(
				suggestion,
				registry,
				{ surface: 'global-styles' }
			);

			if ( ! result.ok ) {
				localDispatch(
					actions.setGlobalStylesApplyState(
						'error',
						result.error || 'Global Styles apply failed.'
					)
				);

				return result;
			}

			await recordActivityEntry(
				localDispatch,
				select,
				buildGlobalStylesActivityEntry( {
					operations: result.operations,
					beforeConfig: result.beforeConfig,
					afterConfig: result.afterConfig,
					requestPrompt:
						select.getGlobalStylesRequestPrompt?.() || '',
					requestMeta: suggestion?.requestMeta || null,
					requestToken: select.getGlobalStylesResultToken?.() || 0,
					scope,
					suggestion,
					globalStylesId: result.globalStylesId,
				} )
			);
			localDispatch(
				actions.setGlobalStylesApplyState(
					'success',
					null,
					suggestion?.suggestionKey || null,
					result.operations
				)
			);

			return result;
		};
	},

	applyStyleBookSuggestion( suggestion ) {
		return async ( { dispatch: localDispatch, registry, select } ) => {
			const scope = getCurrentActivityScope( registry );

			syncActivitySession( localDispatch, select, scope );

			localDispatch( actions.setStyleBookApplyState( 'applying' ) );

			const result = applyGlobalStyleSuggestionOperations(
				suggestion,
				registry,
				{ surface: 'style-book' }
			);

			if ( ! result.ok ) {
				localDispatch(
					actions.setStyleBookApplyState(
						'error',
						result.error || 'Style Book apply failed.'
					)
				);

				return result;
			}

			await recordActivityEntry(
				localDispatch,
				select,
				buildStyleBookActivityEntry( {
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
				} )
			);
			localDispatch(
				actions.setStyleBookApplyState(
					'success',
					null,
					suggestion?.suggestionKey || null,
					result.operations
				)
			);

			return result;
		};
	},

	fetchTemplateRecommendations( input ) {
		return ( { dispatch, select } ) =>
			runAbortableRecommendationRequest( {
				abortKey: '_templateAbort',
				buildRequest: ( { select: registrySelect } ) => ( {
					requestToken:
						( registrySelect.getTemplateRequestToken?.() || 0 ) + 1,
				} ),
				dispatch,
				endpoint: '/flavor-agent/v1/recommend-template',
				input,
				onError: ( {
					dispatch: localDispatch,
					err,
					input: requestInput,
					requestToken,
				} ) => {
					localDispatch(
						actions.setTemplateRecommendations(
							requestInput.templateRef,
							getEmptySuggestionResponse(),
							requestInput.prompt || '',
							requestToken
						)
					);
					localDispatch(
						actions.setTemplateStatus(
							'error',
							err?.message ||
								'Template recommendation request failed.',
							requestToken
						)
					);
				},
				onLoading: ( { dispatch: localDispatch, requestToken } ) => {
					localDispatch(
						actions.setTemplateStatus(
							'loading',
							null,
							requestToken
						)
					);
				},
				onSuccess: ( {
					dispatch: localDispatch,
					input: requestInput,
					requestToken,
					result,
				} ) => {
					const payload =
						attachRequestMetaToRecommendationPayload( result );

					localDispatch(
						actions.setTemplateRecommendations(
							requestInput.templateRef,
							payload,
							requestInput.prompt || '',
							requestToken
						)
					);
				},
				select,
			} );
	},

	fetchTemplatePartRecommendations( input ) {
		return ( { dispatch, select } ) =>
			runAbortableRecommendationRequest( {
				abortKey: '_templatePartAbort',
				buildRequest: ( { select: registrySelect } ) => ( {
					requestToken:
						( registrySelect.getTemplatePartRequestToken?.() ||
							0 ) + 1,
				} ),
				dispatch,
				endpoint: '/flavor-agent/v1/recommend-template-part',
				input,
				onError: ( {
					dispatch: localDispatch,
					err,
					input: requestInput,
					requestToken,
				} ) => {
					localDispatch(
						actions.setTemplatePartRecommendations(
							requestInput.templatePartRef,
							getEmptySuggestionResponse(),
							requestInput.prompt || '',
							requestToken
						)
					);
					localDispatch(
						actions.setTemplatePartStatus(
							'error',
							err?.message ||
								'Template-part recommendation request failed.',
							requestToken
						)
					);
				},
				onLoading: ( { dispatch: localDispatch, requestToken } ) => {
					localDispatch(
						actions.setTemplatePartStatus(
							'loading',
							null,
							requestToken
						)
					);
				},
				onSuccess: ( {
					dispatch: localDispatch,
					input: requestInput,
					requestToken,
					result,
				} ) => {
					const payload =
						attachRequestMetaToRecommendationPayload( result );

					localDispatch(
						actions.setTemplatePartRecommendations(
							requestInput.templatePartRef,
							payload,
							requestInput.prompt || '',
							requestToken
						)
					);
				},
				select,
			} );
	},

	fetchGlobalStylesRecommendations( input ) {
		return ( { dispatch, select } ) =>
			runAbortableRecommendationRequest( {
				abortKey: '_globalStylesAbort',
				buildRequest: ( {
					input: requestInput,
					select: registrySelect,
				} ) => {
					const { contextSignature = null, ...requestData } =
						requestInput;

					return {
						contextSignature,
						requestData,
						requestToken:
							( registrySelect.getGlobalStylesRequestToken?.() ||
								0 ) + 1,
					};
				},
				dispatch,
				endpoint: '/flavor-agent/v1/recommend-style',
				input,
				onError: ( {
					contextSignature,
					dispatch: localDispatch,
					err,
					input: requestInput,
					requestToken,
				} ) => {
					localDispatch(
						actions.setGlobalStylesRecommendations(
							requestInput.scope,
							getEmptySuggestionResponse(),
							requestInput.prompt || '',
							requestToken,
							contextSignature
						)
					);
					localDispatch(
						actions.setGlobalStylesStatus(
							'error',
							err?.message ||
								'Global Styles recommendation request failed.',
							requestToken
						)
					);
				},
				onLoading: ( { dispatch: localDispatch, requestToken } ) => {
					localDispatch(
						actions.setGlobalStylesStatus(
							'loading',
							null,
							requestToken
						)
					);
				},
				onSuccess: ( {
					contextSignature,
					dispatch: localDispatch,
					input: requestInput,
					requestToken,
					result,
				} ) => {
					const payload =
						attachRequestMetaToRecommendationPayload( result );

					localDispatch(
						actions.setGlobalStylesRecommendations(
							requestInput.scope,
							payload,
							requestInput.prompt || '',
							requestToken,
							contextSignature
						)
					);
				},
				select,
			} );
	},

	fetchStyleBookRecommendations( input ) {
		return ( { dispatch, select } ) =>
			runAbortableRecommendationRequest( {
				abortKey: '_styleBookAbort',
				buildRequest: ( {
					input: requestInput,
					select: registrySelect,
				} ) => {
					const { contextSignature = null, ...requestData } =
						requestInput;

					return {
						contextSignature,
						requestData,
						requestToken:
							( registrySelect.getStyleBookRequestToken?.() ||
								0 ) + 1,
					};
				},
				dispatch,
				endpoint: '/flavor-agent/v1/recommend-style',
				input,
				onError: ( {
					contextSignature,
					dispatch: localDispatch,
					err,
					input: requestInput,
					requestToken,
				} ) => {
					localDispatch(
						actions.setStyleBookRecommendations(
							requestInput.scope,
							getEmptySuggestionResponse(),
							requestInput.prompt || '',
							requestToken,
							contextSignature
						)
					);
					localDispatch(
						actions.setStyleBookStatus(
							'error',
							err?.message ||
								'Style Book recommendation request failed.',
							requestToken
						)
					);
				},
				onLoading: ( { dispatch: localDispatch, requestToken } ) => {
					localDispatch(
						actions.setStyleBookStatus(
							'loading',
							null,
							requestToken
						)
					);
				},
				onSuccess: ( {
					contextSignature,
					dispatch: localDispatch,
					input: requestInput,
					requestToken,
					result,
				} ) => {
					const payload =
						attachRequestMetaToRecommendationPayload( result );

					localDispatch(
						actions.setStyleBookRecommendations(
							requestInput.scope,
							payload,
							requestInput.prompt || '',
							requestToken,
							contextSignature
						)
					);
				},
				select,
			} );
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
				navigationRequestToken:
					action.requestToken ?? state.navigationRequestToken,
				navigationResultToken: state.navigationResultToken + 1,
				navigationStatus: 'ready',
				navigationError: null,
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
				navigationRequestToken: state.navigationRequestToken + 1,
				navigationResultToken: state.navigationResultToken + 1,
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
					action.status === 'loading'
						? null
						: state.globalStylesSelectedSuggestionKey,
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
				globalStylesScopeKey: action.scope?.scopeKey || null,
				globalStylesEntityId:
					action.scope?.globalStylesId ||
					action.scope?.entityId ||
					null,
				globalStylesContextSignature: action.contextSignature ?? null,
				globalStylesRequestToken:
					action.requestToken ?? state.globalStylesRequestToken,
				globalStylesResultToken: state.globalStylesResultToken + 1,
				globalStylesStatus: 'ready',
				globalStylesError: null,
				globalStylesSelectedSuggestionKey: null,
				globalStylesApplyStatus: 'idle',
				globalStylesApplyError: null,
				globalStylesLastAppliedSuggestionKey: null,
				globalStylesLastAppliedOperations: [],
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
					action.status === 'loading'
						? null
						: state.styleBookSelectedSuggestionKey,
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
				styleBookScopeKey: action.scope?.scopeKey || null,
				styleBookGlobalStylesId: action.scope?.globalStylesId || null,
				styleBookBlockName: action.scope?.blockName || null,
				styleBookBlockTitle: action.scope?.blockTitle || '',
				styleBookContextSignature: action.contextSignature ?? null,
				styleBookRequestToken:
					action.requestToken ?? state.styleBookRequestToken,
				styleBookResultToken: state.styleBookResultToken + 1,
				styleBookStatus: 'ready',
				styleBookError: null,
				styleBookSelectedSuggestionKey: null,
				styleBookApplyStatus: 'idle',
				styleBookApplyError: null,
				styleBookLastAppliedSuggestionKey: null,
				styleBookLastAppliedOperations: [],
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
			if ( isStaleTemplatePartRequest( state, action.requestToken ) ) {
				return state;
			}

			return {
				...state,
				templatePartRecommendations: action.payload?.suggestions ?? [],
				templatePartExplanation: action.payload?.explanation ?? '',
				templatePartRequestPrompt: action.prompt ?? '',
				templatePartRef: action.templatePartRef,
				templatePartRequestToken:
					action.requestToken ?? state.templatePartRequestToken,
				templatePartResultToken: state.templatePartResultToken + 1,
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
				templatePartRequestToken: state.templatePartRequestToken + 1,
				templatePartResultToken: state.templatePartResultToken + 1,
				templatePartSelectedSuggestionKey: null,
				templatePartApplyStatus: 'idle',
				templatePartApplyError: null,
				templatePartLastAppliedSuggestionKey: null,
				templatePartLastAppliedOperations: [],
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
				globalStylesRequestToken: state.globalStylesRequestToken + 1,
				globalStylesResultToken: state.globalStylesResultToken + 1,
				globalStylesSelectedSuggestionKey: null,
				globalStylesApplyStatus: 'idle',
				globalStylesApplyError: null,
				globalStylesLastAppliedSuggestionKey: null,
				globalStylesLastAppliedOperations: [],
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
				styleBookRequestToken: state.styleBookRequestToken + 1,
				styleBookResultToken: state.styleBookResultToken + 1,
				styleBookSelectedSuggestionKey: null,
				styleBookApplyStatus: 'idle',
				styleBookApplyError: null,
				styleBookLastAppliedSuggestionKey: null,
				styleBookLastAppliedOperations: [],
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
	getNavigationRequestToken: ( state ) => state.navigationRequestToken,
	getNavigationResultToken: ( state, blockClientId = null ) =>
		blockClientId && state.navigationBlockClientId !== blockClientId
			? 0
			: state.navigationResultToken,
	isNavigationLoading: ( state, blockClientId = null ) =>
		( ! blockClientId ||
			state.navigationBlockClientId === blockClientId ) &&
		state.navigationStatus === 'loading',
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
	getTemplatePartRequestPrompt: ( state ) => state.templatePartRequestPrompt,
	getTemplatePartResultRef: ( state ) => state.templatePartRef,
	getTemplatePartRequestToken: ( state ) => state.templatePartRequestToken,
	getTemplatePartResultToken: ( state ) => state.templatePartResultToken,
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
	getGlobalStylesRecommendations: ( state ) => state.globalStylesSuggestions,
	getGlobalStylesExplanation: ( state ) => state.globalStylesExplanation,
	getGlobalStylesError: ( state ) => state.globalStylesError,
	getGlobalStylesRequestPrompt: ( state ) => state.globalStylesRequestPrompt,
	getGlobalStylesResultRef: ( state ) => state.globalStylesEntityId,
	getGlobalStylesScopeKey: ( state ) => state.globalStylesScopeKey,
	getGlobalStylesContextSignature: ( state ) =>
		state.globalStylesContextSignature,
	getGlobalStylesRequestToken: ( state ) => state.globalStylesRequestToken,
	getGlobalStylesResultToken: ( state ) => state.globalStylesResultToken,
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
	getStyleBookRequestToken: ( state ) => state.styleBookRequestToken,
	getStyleBookResultToken: ( state ) => state.styleBookResultToken,
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
			hasResult: Boolean(
				( state.blockRecommendations[ clientId ]?.block?.length || 0 ) +
					( state.blockRecommendations[ clientId ]?.settings
						?.length || 0 ) +
					( state.blockRecommendations[ clientId ]?.styles?.length ||
						0 ) >
					0
			),
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
