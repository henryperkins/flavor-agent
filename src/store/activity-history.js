const ACTIVITY_STORAGE_PREFIX = 'flavor-agent:activity:';
const ACTIVITY_STORAGE_VERSION = 2;
const MAX_ACTIVITY_HISTORY = 20;
const LEGACY_TEMPLATE_UNDO_ERROR =
	'This template action was recorded before refresh-safe undo support and cannot be undone automatically.';

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

function getStorageKey( scopeKey ) {
	return `${ ACTIVITY_STORAGE_PREFIX }${ scopeKey }`;
}

function buildUndoState( timestamp, undo = {} ) {
	return {
		canUndo: undo?.canUndo ?? true,
		status: undo?.status || 'available',
		error: undo?.error ?? null,
		updatedAt: undo?.updatedAt || timestamp,
		undoneAt: undo?.undoneAt || null,
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

function normalizePersistedActivityEntry(
	entry,
	storageVersion = ACTIVITY_STORAGE_VERSION
) {
	if ( ! entry || typeof entry !== 'object' ) {
		return null;
	}

	const timestamp =
		typeof entry.timestamp === 'string' && entry.timestamp
			? entry.timestamp
			: new Date().toISOString();
	const undo = buildUndoState( timestamp, entry.undo );
	const normalizedEntry = {
		...entry,
		schemaVersion:
			Number.isInteger( entry.schemaVersion ) && entry.schemaVersion > 0
				? entry.schemaVersion
				: storageVersion,
		undo,
	};

	if (
		(
			normalizedEntry.surface === 'template' ||
			normalizedEntry.surface === 'template-part'
		) &&
		undo.status !== 'undone' &&
		(
			storageVersion < ACTIVITY_STORAGE_VERSION ||
			normalizedEntry.schemaVersion < ACTIVITY_STORAGE_VERSION ||
			! hasRefreshSafeTemplateUndoMetadata( normalizedEntry )
		)
	) {
		return {
			...normalizedEntry,
			undo: {
				...undo,
				canUndo: false,
				status: 'failed',
				error: LEGACY_TEMPLATE_UNDO_ERROR,
				updatedAt: undo.updatedAt || timestamp,
			},
		};
	}

	return normalizedEntry;
}

export function limitActivityLog( entries = [] ) {
	return Array.isArray( entries )
		? entries.filter( Boolean ).slice( -MAX_ACTIVITY_HISTORY )
		: [];
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
		// Session history is best-effort until a server-backed audit log exists.
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
		timestamp,
		executionResult: 'applied',
		undo: buildUndoState( timestamp ),
	};
}

export function getLatestAppliedActivity( entries = [] ) {
	return (
		[ ...entries ]
			.reverse()
			.find( ( entry ) => entry?.undo?.status !== 'undone' ) || null
	);
}

export function getLatestUndoableActivity( entries = [] ) {
	const latestActivity = getLatestAppliedActivity( entries );

	if (
		latestActivity?.undo?.canUndo === true &&
		latestActivity?.undo?.status === 'available'
	) {
		return latestActivity;
	}

	return null;
}
