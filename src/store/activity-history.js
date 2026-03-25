const ACTIVITY_STORAGE_PREFIX = 'flavor-agent:activity:';
const ACTIVITY_STORAGE_VERSION = 3;
const MAX_ACTIVITY_HISTORY = 20;
const LEGACY_TEMPLATE_UNDO_ERROR =
	'This template action was recorded before refresh-safe undo support and cannot be undone automatically.';

export const ORDERED_UNDO_BLOCKED_ERROR =
	'Undo blocked by newer AI actions.';

let activitySequence = 0;

function canUseSessionStorage() {
	try {
		return (
			typeof window !== 'undefined' && Boolean( window.sessionStorage )
		);
	} catch {
		return false;
	}
}

function normalizeScopeValue( value ) {
	if ( value === null || value === undefined || value === '' ) {
		return '';
	}

	return String( value );
}

function normalizeActivityTimestamp( value ) {
	if ( typeof value !== 'string' || ! value ) {
		return new Date().toISOString();
	}

	const parsed = new Date( value );

	if ( Number.isNaN( parsed.getTime() ) ) {
		return new Date().toISOString();
	}

	return parsed.toISOString();
}

function getStorageKey( scopeKey ) {
	return `${ ACTIVITY_STORAGE_PREFIX }${ scopeKey }`;
}

function normalizeUndoStatus( status ) {
	switch ( status ) {
		case 'available':
		case 'blocked':
		case 'failed':
		case 'undone':
			return status;
		default:
			return 'available';
	}
}

function buildUndoState( timestamp, undo = {} ) {
	const status = normalizeUndoStatus( undo?.status );
	const updatedAt = normalizeActivityTimestamp(
		undo?.updatedAt || timestamp
	);

	return {
		canUndo:
			status === 'available'
				? undo?.canUndo ?? true
				: false,
		status,
		error:
			status === 'blocked'
				? undo?.error || ORDERED_UNDO_BLOCKED_ERROR
				: undo?.error ?? null,
		updatedAt,
		undoneAt:
			status === 'undone'
				? normalizeActivityTimestamp(
						undo?.undoneAt || undo?.updatedAt || timestamp
				  )
				: undo?.undoneAt
					? normalizeActivityTimestamp( undo.undoneAt )
					: null,
	};
}

function buildPersistenceState( timestamp, persistence = {} ) {
	const status = persistence?.status === 'server' ? 'server' : 'local';

	return {
		status,
		syncType:
			status === 'server'
				? null
				: persistence?.syncType === 'undo'
					? 'undo'
					: 'create',
		updatedAt: normalizeActivityTimestamp(
			persistence?.updatedAt || timestamp
		),
	};
}

function getTemplateActivityOperations( entry ) {
	if ( Array.isArray( entry?.after?.operations ) ) {
		return entry.after.operations;
	}

	return Array.isArray( entry?.operations ) ? entry.operations : [];
}

function hasRefreshSafeTemplateUndoMetadata( entry ) {
	const operations = getTemplateActivityOperations( entry );

	if ( operations.length === 0 ) {
		return false;
	}

	return operations.every( ( operation ) => {
		switch ( operation?.type ) {
			case 'assign_template_part':
			case 'replace_template_part':
				return Boolean(
					operation?.previousAttributes &&
						(
							operation?.undoLocator?.area ||
							operation?.area ||
							operation?.nextAttributes?.area
						) &&
						(
							operation?.undoLocator?.expectedSlug ||
							operation?.slug ||
							operation?.nextAttributes?.slug
						)
				);

			case 'insert_pattern':
				return Boolean(
					operation?.rootLocator &&
						Number.isInteger( operation?.index ) &&
						Array.isArray( operation?.insertedBlocksSnapshot ) &&
						operation.insertedBlocksSnapshot.length > 0
				);

			default:
				return false;
		}
	} );
}

function compareActivityEntries( left, right ) {
	const leftTimestamp = Date.parse(
		typeof left?.timestamp === 'string' ? left.timestamp : ''
	);
	const rightTimestamp = Date.parse(
		typeof right?.timestamp === 'string' ? right.timestamp : ''
	);

	if (
		! Number.isNaN( leftTimestamp ) &&
		! Number.isNaN( rightTimestamp ) &&
		leftTimestamp !== rightTimestamp
	) {
		return leftTimestamp - rightTimestamp;
	}

	return String( left?.id || '' ).localeCompare( String( right?.id || '' ) );
}

function normalizePersistedActivityEntry(
	entry,
	storageVersion = ACTIVITY_STORAGE_VERSION
) {
	if ( ! entry || typeof entry !== 'object' ) {
		return null;
	}

	const timestamp = normalizeActivityTimestamp( entry.timestamp );
	const normalizedEntry = {
		...entry,
		schemaVersion:
			Number.isInteger( entry.schemaVersion ) && entry.schemaVersion > 0
				? entry.schemaVersion
				: storageVersion,
		timestamp,
		undo: buildUndoState( timestamp, entry.undo ),
		persistence: buildPersistenceState( timestamp, entry.persistence ),
	};

	if (
		(
			normalizedEntry.surface === 'template' ||
			normalizedEntry.surface === 'template-part'
		) &&
		normalizedEntry.undo.status !== 'undone' &&
		(
			storageVersion < ACTIVITY_STORAGE_VERSION ||
			normalizedEntry.schemaVersion < ACTIVITY_STORAGE_VERSION ||
			! hasRefreshSafeTemplateUndoMetadata( normalizedEntry )
		)
	) {
		return {
			...normalizedEntry,
			undo: {
				...normalizedEntry.undo,
				canUndo: false,
				status: 'failed',
				error: LEGACY_TEMPLATE_UNDO_ERROR,
			},
		};
	}

	return normalizedEntry;
}

function getBaseUndoState( entry ) {
	const timestamp = normalizeActivityTimestamp( entry?.timestamp );
	const undo = buildUndoState( timestamp, entry?.undo );

	if ( undo.status === 'blocked' ) {
		return {
			...undo,
			canUndo: true,
			status: 'available',
			error: null,
		};
	}

	return undo;
}

function isOrderedUndoEligible( entry, entries = [] ) {
	const entityKey = getActivityEntityKey( entry );

	if ( ! entityKey ) {
		return getBaseUndoState( entry ).status === 'available';
	}

	const orderedEntries = sortActivityEntries( entries ).filter(
		( currentEntry ) => getActivityEntityKey( currentEntry ) === entityKey
	);

	for ( let index = orderedEntries.length - 1; index >= 0; index-- ) {
		const currentEntry = orderedEntries[ index ];

		if ( currentEntry?.id === entry?.id ) {
			return true;
		}

		if ( getBaseUndoState( currentEntry ).status !== 'undone' ) {
			return false;
		}
	}

	return false;
}

export function resolveActivityScope( postType, entityId ) {
	const normalizedPostType = normalizeScopeValue( postType );

	if ( ! normalizedPostType ) {
		return null;
	}

	const normalizedEntityId = normalizeScopeValue( entityId );

	return {
		key: normalizedEntityId
			? `${ normalizedPostType }:${ normalizedEntityId }`
			: null,
		hint: `${ normalizedPostType }:${
			normalizedEntityId || '__unsaved__'
		}`,
		postType: normalizedPostType,
		entityId: normalizedEntityId,
	};
}

export function sortActivityEntries( entries = [] ) {
	return Array.isArray( entries )
		? [ ...entries ].filter( Boolean ).sort( compareActivityEntries )
		: [];
}

export function limitActivityLog( entries = [] ) {
	return sortActivityEntries( entries ).slice( -MAX_ACTIVITY_HISTORY );
}

export function getCurrentActivityScope( registry ) {
	const editor = registry?.select?.( 'core/editor' ) || {};
	const editSite = registry?.select?.( 'core/edit-site' ) || {};
	const postType =
		normalizeScopeValue( editor.getCurrentPostType?.() ) ||
		normalizeScopeValue( editSite.getEditedPostType?.() );
	const entityId =
		normalizeScopeValue( editor.getCurrentPostId?.() ) ||
		normalizeScopeValue( editSite.getEditedPostId?.() );

	return resolveActivityScope( postType, entityId );
}

export function readPersistedActivityLog( scopeKey ) {
	if ( ! scopeKey || ! canUseSessionStorage() ) {
		return [];
	}

	try {
		const raw = window.sessionStorage.getItem( getStorageKey( scopeKey ) );

		if ( ! raw ) {
			return [];
		}

		const parsed = JSON.parse( raw );
		const storageVersion = Number.isInteger( parsed?.version )
			? parsed.version
			: 1;

		return limitActivityLog( parsed?.entries )
			.map( ( entry ) =>
				normalizePersistedActivityEntry(
					entry,
					storageVersion
				)
			)
			.filter( Boolean );
	} catch {
		return [];
	}
}

export function writePersistedActivityLog( scopeKey, entries ) {
	if ( ! scopeKey || ! canUseSessionStorage() ) {
		return;
	}

	const limitedEntries = limitActivityLog( entries );

	try {
		if ( limitedEntries.length === 0 ) {
			window.sessionStorage.removeItem( getStorageKey( scopeKey ) );
			return;
		}

		window.sessionStorage.setItem(
			getStorageKey( scopeKey ),
			JSON.stringify( {
				version: ACTIVITY_STORAGE_VERSION,
				updatedAt: new Date().toISOString(),
				entries: limitedEntries,
			} )
		);
	} catch {
		// Session history is only a cache now that the audit log is server-backed.
	}
}

export function createActivityEntry( {
	type,
	surface,
	target = {},
	suggestion = '',
	suggestionKey = null,
	before = {},
	after = {},
	prompt = '',
	requestRef = '',
	document = null,
	timestamp = new Date().toISOString(),
} ) {
	activitySequence += 1;

	const normalizedTimestamp = normalizeActivityTimestamp( timestamp );

	return {
		id: `activity-${ Date.now() }-${ activitySequence }`,
		schemaVersion: ACTIVITY_STORAGE_VERSION,
		type,
		surface,
		target,
		suggestion,
		suggestionKey,
		before,
		after,
		request: {
			prompt,
			reference: requestRef,
		},
		document,
		timestamp: normalizedTimestamp,
		executionResult: 'applied',
		undo: buildUndoState( normalizedTimestamp ),
		persistence: buildPersistenceState( normalizedTimestamp, {
			status: 'local',
			syncType: 'create',
		} ),
	};
}

export function isLocalActivityEntry( entry ) {
	return entry?.persistence?.status !== 'server';
}

export function getPendingActivitySyncType( entry ) {
	if ( entry?.persistence?.status === 'server' ) {
		return null;
	}

	return entry?.persistence?.syncType === 'undo' ? 'undo' : 'create';
}

export function getActivityEntityKey( entry ) {
	if ( ! entry ) {
		return '';
	}

	const surface = String( entry?.surface || '' );
	const documentScopeKey = String( entry?.document?.scopeKey || '' );

	if ( surface === 'template' ) {
		return `template:${ String( entry?.target?.templateRef || '' ) }`;
	}

	if ( surface === 'template-part' ) {
		return `template-part:${ String(
			entry?.target?.templatePartRef || ''
		) }`;
	}

	const blockPath = Array.isArray( entry?.target?.blockPath )
		? entry.target.blockPath.join( '.' )
		: '';
	const blockIdentity =
		blockPath ||
		String( entry?.target?.clientId || '' ) ||
		'unknown';
	const blockName = String( entry?.target?.blockName || '' );

	return `block:${ documentScopeKey }:${ blockIdentity }:${ blockName }`;
}

export function getResolvedActivityUndoState(
	entry,
	entries = [],
	runtimeUndoState = null
) {
	if ( ! entry ) {
		return buildUndoState( new Date().toISOString(), {
			status: 'failed',
			error: 'The activity entry is unavailable.',
		} );
	}

	const timestamp = normalizeActivityTimestamp( entry.timestamp );
	const baseUndo = getBaseUndoState( entry );

	if ( baseUndo.status === 'undone' ) {
		return {
			...baseUndo,
			canUndo: false,
		};
	}

	if ( ! isOrderedUndoEligible( entry, entries ) ) {
		return {
			...baseUndo,
			canUndo: false,
			status: 'blocked',
			error: ORDERED_UNDO_BLOCKED_ERROR,
		};
	}

	if ( runtimeUndoState ) {
		const resolvedUndo = buildUndoState( timestamp, {
			...baseUndo,
			...runtimeUndoState,
		} );

		if ( resolvedUndo.status !== 'available' ) {
			return {
				...resolvedUndo,
				canUndo: false,
			};
		}

		return {
			...resolvedUndo,
			canUndo: resolvedUndo.canUndo !== false,
		};
	}

	if ( baseUndo.status !== 'available' ) {
		return {
			...baseUndo,
			canUndo: false,
		};
	}

	return {
		...baseUndo,
		canUndo: baseUndo.canUndo !== false,
	};
}

export function getResolvedActivityEntries(
	entries = [],
	runtimeUndoResolver = null
) {
	const orderedEntries = sortActivityEntries( entries );

	return orderedEntries.map( ( entry ) => ( {
		...entry,
		undo: getResolvedActivityUndoState(
			entry,
			orderedEntries,
			typeof runtimeUndoResolver === 'function'
				? runtimeUndoResolver( entry )
				: null
		),
	} ) );
}

export function getLatestAppliedActivity( entries = [] ) {
	return (
		[ ...sortActivityEntries( entries ) ]
			.reverse()
			.find( ( entry ) => getBaseUndoState( entry ).status !== 'undone' ) ||
		null
	);
}

export function getLatestUndoableActivity(
	entries = [],
	runtimeUndoResolver = null
) {
	return (
		[ ...getResolvedActivityEntries( entries, runtimeUndoResolver ) ]
			.reverse()
			.find(
				( entry ) =>
					entry?.undo?.canUndo === true &&
					entry?.undo?.status === 'available'
			) || null
	);
}
