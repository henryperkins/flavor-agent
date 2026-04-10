import apiFetch from '@wordpress/api-fetch';

import {
	getPendingActivitySyncType,
	isLocalActivityEntry,
	limitActivityLog,
	readPersistedActivityLog,
	writePersistedActivityLog,
} from './activity-history';

export function getScopeKey( scope = null ) {
	return typeof scope?.scopeKey === 'string' && scope.scopeKey.trim()
		? scope.scopeKey.trim()
		: typeof scope?.key === 'string' && scope.key.trim()
		? scope.key.trim()
		: null;
}

export function buildActivityDocument( scope ) {
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

export function getRequestDocumentFromScope( scope ) {
	return buildActivityDocument( scope );
}

export function alignActivityEntriesToScope( entries, scope ) {
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

export function syncActivitySession(
	localDispatch,
	select,
	scope,
	setActivitySession,
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

		localDispatch( setActivitySession( nextScopeKey, reassignedEntries ) );
		writePersistedActivityLog( nextScopeKey, reassignedEntries );

		return reassignedEntries;
	}

	const cachedEntries = nextScopeKey
		? readPersistedActivityLog( nextScopeKey )
		: [];

	localDispatch( setActivitySession( nextScopeKey, cachedEntries ) );

	return cachedEntries;
}

export function persistActivitySession( select ) {
	const scopeKey = select.getActivityScopeKey?.() || null;

	if ( ! scopeKey ) {
		return;
	}

	writePersistedActivityLog( scopeKey, select.getActivityLog?.() || [] );
}

export function getApiErrorStatus( error ) {
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

export function getApiErrorMessage( error, fallback = 'Request failed.' ) {
	return typeof error?.message === 'string' && error.message
		? error.message
		: fallback;
}

export function getApiErrorCode( error ) {
	if ( typeof error?.code === 'string' && error.code ) {
		return error.code;
	}

	if ( typeof error?.data?.code === 'string' && error.data.code ) {
		return error.data.code;
	}

	return '';
}

export function buildActivityQueryPath( {
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

export function mergeActivityEntries( ...entrySets ) {
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

export function refreshActivitySession(
	localDispatch,
	scopeKey,
	entries,
	setActivitySession
) {
	localDispatch( setActivitySession( scopeKey, entries ) );
	writePersistedActivityLog( scopeKey, entries );
}

export async function reloadScopedActivitySession(
	localDispatch,
	registry,
	select,
	{ getCurrentActivityScope, setActivitySession }
) {
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

		refreshActivitySession(
			localDispatch,
			scopeKey,
			mergedEntries,
			setActivitySession
		);
	} catch {
		// Keep the current scoped cache when the server activity reload fails.
	}
}

export async function fetchServerActivityEntries( scopeKey ) {
	const response = await apiFetch( {
		path: buildActivityQueryPath( {
			scopeKey,
		} ),
		method: 'GET',
	} );

	return limitActivityLog( response?.entries || [] );
}

export function scheduleActivitySessionReload(
	runtime,
	options = {},
	storeName = 'flavor-agent'
) {
	if ( typeof window === 'undefined' ) {
		return;
	}

	if ( runtime._activitySessionRetryTimer ) {
		window.clearTimeout( runtime._activitySessionRetryTimer );
	}

	runtime._activitySessionRetryTimer = window.setTimeout( () => {
		runtime._activitySessionRetryTimer = null;

		const storeDispatch = window.wp?.data?.dispatch?.( storeName );
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

export async function persistServerActivityEntry( entry ) {
	const response = await apiFetch( {
		path: '/flavor-agent/v1/activity',
		method: 'POST',
		data: {
			entry,
		},
	} );

	return response?.entry || entry;
}

export async function persistActivityUndoTransition( entry ) {
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

export function shouldSyncUndoTransition( entry ) {
	const pendingSyncType = getPendingActivitySyncType( entry );

	if ( pendingSyncType === 'undo' ) {
		return true;
	}

	return entry?.persistence?.status === 'server';
}

export function buildActivityPersistenceUpdate(
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

export function buildUndoAuditSyncError( message ) {
	return `${ message } Flavor Agent could not persist the activity audit update and will retry on the next activity sync.`;
}

export function isServerBackedActivityEntry( entry ) {
	return entry?.persistence?.status === 'server';
}

export function isRetryableActivitySyncError( error ) {
	const status = getApiErrorStatus( error );

	if ( status === 0 || status === 408 || status === 425 || status === 429 ) {
		return true;
	}

	return status >= 500;
}

export function isRetryableRateLimitError( error ) {
	if ( error?.data?.retryable === true ) {
		return true;
	}
	return getApiErrorStatus( error ) === 429;
}

export function getRetryAfterSeconds( error ) {
	const retryAfter = error?.data?.retry_after ?? error?.retry_after;
	if ( Number.isInteger( retryAfter ) && retryAfter > 0 ) {
		return Math.min( retryAfter, 60 );
	}
	return 5;
}

export function sleep( ms ) {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}

export function isNonRetryableUndoSyncError( entry, error ) {
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

export function isUndoSyncConflictError( error ) {
	return (
		getApiErrorStatus( error ) === 409 &&
		getApiErrorCode( error ) ===
			'flavor_agent_activity_invalid_undo_transition'
	);
}

export function buildNonRetryableUndoSyncEntry(
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

export async function persistPendingActivityEntries( entries = [] ) {
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

export async function reconcileActivityEntryFromServer( entry, scopeKey ) {
	if ( ! entry?.id || ! scopeKey ) {
		return null;
	}

	const serverEntries = await fetchServerActivityEntries( scopeKey );

	return (
		serverEntries.find( ( serverEntry ) => serverEntry?.id === entry.id ) ||
		null
	);
}

export async function recordActivityEntry(
	localDispatch,
	select,
	entry,
	logActivity
) {
	let nextEntry = entry;

	if ( entry?.document?.scopeKey ) {
		try {
			nextEntry = await persistServerActivityEntry( entry );
		} catch {
			nextEntry = entry;
		}
	}

	localDispatch( logActivity( nextEntry ) );
	persistActivitySession( select );

	return nextEntry;
}

export function createLoadActivitySessionAction( {
	runtime,
	storeName,
	getCurrentActivityScope,
	setActivitySession,
} ) {
	return function loadActivitySession( options = {} ) {
		return async ( { dispatch, registry, select } ) => {
			const requestToken = ( runtime._activitySessionLoadToken || 0 ) + 1;
			runtime._activitySessionLoadToken = requestToken;
			const scope = options?.scope || getCurrentActivityScope( registry );
			const nextScopeKey = getScopeKey( scope );

			if ( ! nextScopeKey ) {
				if ( options?.retryIfScopeUnavailable !== false ) {
					scheduleActivitySessionReload(
						runtime,
						options,
						storeName
					);
				}

				syncActivitySession( dispatch, select, scope, setActivitySession, {
					allowUnsavedMigration:
						options?.allowUnsavedMigration === true,
				} );

				return;
			}

			const workingEntries = syncActivitySession(
				dispatch,
				select,
				scope,
				setActivitySession,
				{
					allowUnsavedMigration:
						options?.allowUnsavedMigration === true,
				}
			);
			const pendingEntries = workingEntries.filter( isLocalActivityEntry );
			const { persistedEntries, failedEntries, terminalEntries } =
				await persistPendingActivityEntries( pendingEntries );
			const terminalEntryIds = new Set(
				terminalEntries.map( ( entry ) => entry?.id ).filter( Boolean )
			);

			try {
				const serverEntries = await fetchServerActivityEntries(
					nextScopeKey
				);
				const mergedEntries = mergeActivityEntries(
					serverEntries,
					persistedEntries,
					failedEntries,
					terminalEntries
				);

				if ( runtime._activitySessionLoadToken !== requestToken ) {
					return;
				}

				refreshActivitySession(
					dispatch,
					nextScopeKey,
					mergedEntries,
					setActivitySession
				);
			} catch {
				const fallbackEntries = mergeActivityEntries(
					workingEntries.filter(
						( entry ) => ! terminalEntryIds.has( entry?.id )
					),
					persistedEntries,
					failedEntries,
					terminalEntries
				);

				if ( runtime._activitySessionLoadToken !== requestToken ) {
					return;
				}

				refreshActivitySession(
					dispatch,
					nextScopeKey,
					fallbackEntries,
					setActivitySession
				);
			}
		};
	};
}
