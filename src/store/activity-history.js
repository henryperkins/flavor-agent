import { getStyleBookUiState } from '../style-book/dom';
import {
	BLOCK_TARGET_MOVED_ERROR,
	getBlockByPath,
	resolveActivityBlockTarget,
} from './block-targeting';
import {
	hasRecordedAttributeSnapshot,
	recordedAttributeSnapshotMatchesCurrent,
} from './update-helpers';
import { getBlockStructuralActivityUndoState } from '../utils/block-structural-actions';

const ACTIVITY_STORAGE_PREFIX = 'flavor-agent:activity:';
// v4: per-surface bucketing (was a single 20-entry cap across all surfaces).
// Bumped so any legacy v3 cache that wrote with the old aggregate semantics
// is re-evaluated through the new bucketed limiter on read.
const ACTIVITY_STORAGE_VERSION = 4;
const MAX_ACTIVITY_HISTORY_PER_SURFACE = 20;
// Aggregate cap across surfaces per scope. Each per-surface bucket holds up
// to MAX_ACTIVITY_HISTORY_PER_SURFACE entries; this guard prevents
// sessionStorage growth (8 surfaces × 20 = 160 entries × N scopes) from
// exceeding browser quotas in long-running sessions.
const MAX_ACTIVITY_HISTORY_PER_SCOPE = 80;
const LEGACY_TEMPLATE_UNDO_ERROR =
	'This template action was recorded before refresh-safe undo support and cannot be undone automatically.';

export const ORDERED_UNDO_BLOCKED_ERROR = 'Undo blocked by newer AI actions.';

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

function resolveEditedEntityScope( editor, editSite ) {
	const editorPostType = normalizeScopeValue( editor.getCurrentPostType?.() );
	const sitePostType = normalizeScopeValue( editSite.getEditedPostType?.() );
	const siteEntityId = normalizeScopeValue( editSite.getEditedPostId?.() );
	const isSiteEditorTemplateEntity =
		sitePostType === 'wp_template' || sitePostType === 'wp_template_part';

	if (
		isSiteEditorTemplateEntity &&
		siteEntityId &&
		( ! editorPostType || editorPostType === sitePostType )
	) {
		return {
			postType: sitePostType,
			entityId: siteEntityId,
		};
	}

	const editorEntityId = normalizeScopeValue( editor.getCurrentPostId?.() );

	return {
		postType: editorPostType || sitePostType,
		entityId: editorEntityId || siteEntityId,
	};
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
		case 'review':
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
	let undoneAt = null;

	if ( status === 'undone' ) {
		undoneAt = normalizeActivityTimestamp(
			undo?.undoneAt || undo?.updatedAt || timestamp
		);
	} else if ( undo?.undoneAt ) {
		undoneAt = normalizeActivityTimestamp( undo.undoneAt );
	}

	return {
		canUndo: status === 'available' ? undo?.canUndo ?? true : false,
		status,
		error: ( () => {
			if ( status === 'blocked' ) {
				return undo?.error || ORDERED_UNDO_BLOCKED_ERROR;
			}

			if ( status === 'review' ) {
				return null;
			}

			return undo?.error ?? null;
		} )(),
		updatedAt,
		undoneAt,
	};
}

function buildPersistenceState( timestamp, persistence = {} ) {
	const status = persistence?.status === 'server' ? 'server' : 'local';
	let syncType = null;

	if ( status !== 'server' ) {
		syncType = persistence?.syncType === 'undo' ? 'undo' : 'create';
	}

	return {
		status,
		syncType,
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
						( operation?.undoLocator?.area ||
							operation?.area ||
							operation?.nextAttributes?.area ) &&
						( operation?.undoLocator?.expectedSlug ||
							operation?.slug ||
							operation?.nextAttributes?.slug )
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
		( normalizedEntry.surface === 'template' ||
			normalizedEntry.surface === 'template-part' ) &&
		normalizedEntry.undo.status !== 'undone' &&
		( storageVersion < ACTIVITY_STORAGE_VERSION ||
			normalizedEntry.schemaVersion < ACTIVITY_STORAGE_VERSION ||
			! hasRefreshSafeTemplateUndoMetadata( normalizedEntry ) )
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

function getPreliminaryUndoState( entry, runtimeUndoState = null ) {
	const timestamp = normalizeActivityTimestamp( entry?.timestamp );
	const baseUndo = getBaseUndoState( entry );

	if ( ! runtimeUndoState ) {
		return buildUndoState( timestamp, baseUndo );
	}

	return buildUndoState( timestamp, {
		...baseUndo,
		...runtimeUndoState,
	} );
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

function isOrderedUndoEligibleWithResolvedStates(
	orderedEntries,
	preliminaryUndoStates,
	entryIndex
) {
	const entry = orderedEntries[ entryIndex ] || null;
	const entityKey = getActivityEntityKey( entry );

	if ( ! entityKey ) {
		return preliminaryUndoStates[ entryIndex ]?.status === 'available';
	}

	for ( let index = orderedEntries.length - 1; index >= 0; index-- ) {
		if ( getActivityEntityKey( orderedEntries[ index ] ) !== entityKey ) {
			continue;
		}

		if ( index === entryIndex ) {
			return true;
		}

		if ( preliminaryUndoStates[ index ]?.status !== 'undone' ) {
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

export function resolveGlobalStylesScope(
	globalStylesId,
	{ stylesheet = '' } = {}
) {
	const normalizedEntityId = normalizeScopeValue( globalStylesId );

	if ( ! normalizedEntityId ) {
		return null;
	}

	return {
		key: `global_styles:${ normalizedEntityId }`,
		hint: `global_styles:${ normalizedEntityId }`,
		postType: 'global_styles',
		entityId: normalizedEntityId,
		entityKind: 'root',
		entityName: 'globalStyles',
		stylesheet: normalizeScopeValue( stylesheet ),
	};
}

export function resolveStyleBookScope(
	globalStylesId,
	blockName,
	{ blockTitle = '', stylesheet = '' } = {}
) {
	const normalizedGlobalStylesId = normalizeScopeValue( globalStylesId );
	const normalizedBlockName = normalizeScopeValue( blockName );

	if ( ! normalizedGlobalStylesId || ! normalizedBlockName ) {
		return null;
	}

	return {
		key: `style_book:${ normalizedGlobalStylesId }:${ normalizedBlockName }`,
		hint: `style_book:${ normalizedGlobalStylesId }:${ normalizedBlockName }`,
		postType: 'global_styles',
		entityId: normalizedGlobalStylesId,
		entityKind: 'block',
		entityName: 'styleBook',
		globalStylesId: normalizedGlobalStylesId,
		blockName: normalizedBlockName,
		blockTitle: normalizeScopeValue( blockTitle ),
		stylesheet: normalizeScopeValue( stylesheet ),
	};
}

export function sortActivityEntries( entries = [] ) {
	return Array.isArray( entries )
		? [ ...entries ].filter( Boolean ).sort( compareActivityEntries )
		: [];
}

export function limitActivityLog( entries = [] ) {
	const entriesBySurface = new Map();

	sortActivityEntries( entries ).forEach( ( entry ) => {
		const surfaceKey = String( entry?.surface || '' );
		const surfaceEntries = entriesBySurface.get( surfaceKey ) || [];

		surfaceEntries.push( entry );
		entriesBySurface.set(
			surfaceKey,
			surfaceEntries.slice( -MAX_ACTIVITY_HISTORY_PER_SURFACE )
		);
	} );

	const bucketed = sortActivityEntries(
		[ ...entriesBySurface.values() ].flat()
	);

	if ( bucketed.length <= MAX_ACTIVITY_HISTORY_PER_SCOPE ) {
		return bucketed;
	}

	return bucketed.slice( -MAX_ACTIVITY_HISTORY_PER_SCOPE );
}

export function getCurrentActivityScope( registry ) {
	const editor = registry?.select?.( 'core/editor' ) || {};
	const editSite = registry?.select?.( 'core/edit-site' ) || {};
	const interfaceStore = registry?.select?.( 'core/interface' ) || {};
	const coreStore = registry?.select?.( 'core' ) || {};
	const activeComplementaryArea =
		interfaceStore?.getActiveComplementaryArea?.( 'core' ) || '';
	const globalStylesId =
		coreStore?.__experimentalGetCurrentGlobalStylesId?.() || null;

	if (
		activeComplementaryArea === 'edit-site/global-styles' &&
		globalStylesId !== null &&
		globalStylesId !== undefined &&
		globalStylesId !== ''
	) {
		if ( typeof document !== 'undefined' ) {
			const styleBookUiState = getStyleBookUiState( document );

			if (
				styleBookUiState?.isActive &&
				styleBookUiState?.target?.blockName
			) {
				return resolveStyleBookScope(
					globalStylesId,
					styleBookUiState.target.blockName,
					{
						blockTitle: styleBookUiState.target.blockTitle || '',
					}
				);
			}
		}

		return resolveGlobalStylesScope( globalStylesId );
	}

	const { postType, entityId } = resolveEditedEntityScope( editor, editSite );

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
				normalizePersistedActivityEntry( entry, storageVersion )
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
	requestMeta = null,
	document = null,
	timestamp = new Date().toISOString(),
} ) {
	activitySequence += 1;

	const normalizedTimestamp = normalizeActivityTimestamp( timestamp );
	const normalizedRequestMeta =
		requestMeta &&
		typeof requestMeta === 'object' &&
		! Array.isArray( requestMeta )
			? requestMeta
			: null;

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
			...( normalizedRequestMeta
				? {
						ai: normalizedRequestMeta,
				  }
				: {} ),
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
	const activityType = String( entry?.type || '' );

	if ( activityType === 'request_diagnostic' ) {
		return `request:${ surface }:${ String(
			entry?.request?.reference || entry?.id || ''
		) }`;
	}

	if ( surface === 'template' ) {
		return `template:${ String( entry?.target?.templateRef || '' ) }`;
	}

	if ( surface === 'template-part' ) {
		return `template-part:${ String(
			entry?.target?.templatePartRef || ''
		) }`;
	}

	if ( surface === 'global-styles' ) {
		return `global-styles:${ String(
			entry?.target?.globalStylesId || entry?.document?.entityId || ''
		) }`;
	}

	if ( surface === 'style-book' ) {
		return `style-book:${ String(
			entry?.target?.globalStylesId || entry?.document?.entityId || ''
		) }:${ String( entry?.target?.blockName || '' ) }`;
	}

	const documentScopeKey = String( entry?.document?.scopeKey || '' );
	const blockPath = Array.isArray( entry?.target?.blockPath )
		? entry.target.blockPath.join( '.' )
		: '';
	const blockIdentity =
		blockPath || String( entry?.target?.clientId || '' ) || 'unknown';
	const blockName = String( entry?.target?.blockName || '' );

	return `block:${ documentScopeKey }:${ blockIdentity }:${ blockName }`;
}

export function getBlockActivityUndoState( entry, blockEditorSelect = {} ) {
	const existingUndo = entry?.undo || {};

	if ( entry?.surface !== 'block' ) {
		return existingUndo;
	}

	if ( entry?.type === 'apply_block_structural_suggestion' ) {
		return getBlockStructuralActivityUndoState( entry, blockEditorSelect );
	}

	if ( entry?.type !== 'apply_suggestion' ) {
		return existingUndo;
	}

	const beforeAttributes = entry?.before?.attributes || {};
	const afterAttributes = entry?.after?.attributes || {};

	if ( ! hasRecordedAttributeSnapshot( afterAttributes ) ) {
		return {
			...existingUndo,
			canUndo: false,
			status: 'failed',
			error: 'This block action is missing its recorded after state and cannot be undone automatically.',
		};
	}

	const resolvedTarget = resolveActivityBlockTarget(
		blockEditorSelect,
		entry?.target || {}
	);
	const resolvedBlock = resolvedTarget.block;

	if ( ! resolvedBlock?.clientId ) {
		return {
			...existingUndo,
			canUndo: false,
			status: 'failed',
			error: 'The original block target for this AI action is missing.',
		};
	}

	if (
		entry?.target?.blockName &&
		resolvedBlock.name !== entry.target.blockName
	) {
		return {
			...existingUndo,
			canUndo: false,
			status: 'failed',
			error: BLOCK_TARGET_MOVED_ERROR,
		};
	}

	let currentAttributes =
		blockEditorSelect?.getBlockAttributes?.( resolvedBlock.clientId ) ||
		resolvedBlock.attributes ||
		null;

	if ( ! currentAttributes ) {
		return {
			...existingUndo,
			canUndo: false,
			status: 'failed',
			error: 'The target block is no longer available to undo.',
		};
	}

	if (
		! recordedAttributeSnapshotMatchesCurrent(
			afterAttributes,
			currentAttributes
		)
	) {
		const pathBlock = Array.isArray( entry?.target?.blockPath )
			? getBlockByPath(
					blockEditorSelect?.getBlocks?.() || [],
					entry.target.blockPath
			  )
			: null;
		const pathAttributes = pathBlock?.clientId
			? blockEditorSelect?.getBlockAttributes?.( pathBlock.clientId ) ||
			  pathBlock.attributes ||
			  null
			: null;

		if (
			pathBlock?.clientId &&
			( ! entry?.target?.blockName ||
				pathBlock.name === entry.target.blockName ) &&
			recordedAttributeSnapshotMatchesCurrent(
				afterAttributes,
				pathAttributes
			)
		) {
			currentAttributes = pathAttributes;
		}
	}

	if (
		recordedAttributeSnapshotMatchesCurrent(
			afterAttributes,
			currentAttributes
		)
	) {
		return {
			...existingUndo,
			canUndo: true,
			status: 'available',
			error: null,
		};
	}

	if (
		recordedAttributeSnapshotMatchesCurrent(
			beforeAttributes,
			currentAttributes
		)
	) {
		return {
			...existingUndo,
			canUndo: false,
			status: 'undone',
			error: null,
		};
	}

	return {
		...existingUndo,
		canUndo: false,
		status: 'failed',
		error: 'The target block changed after Flavor Agent applied this suggestion and cannot be undone automatically.',
	};
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

	const baseUndo = getPreliminaryUndoState( entry, runtimeUndoState );

	if ( baseUndo.status === 'review' ) {
		return {
			...baseUndo,
			canUndo: false,
		};
	}

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
	const preliminaryUndoStates = orderedEntries.map( ( entry ) =>
		getPreliminaryUndoState(
			entry,
			typeof runtimeUndoResolver === 'function'
				? runtimeUndoResolver( entry )
				: null
		)
	);

	return orderedEntries.map( ( entry, entryIndex ) => ( {
		...entry,
		undo: ( () => {
			const resolvedUndo =
				entryIndex >= 0
					? preliminaryUndoStates[ entryIndex ]
					: getPreliminaryUndoState( entry );

			if ( resolvedUndo?.status === 'undone' ) {
				return {
					...resolvedUndo,
					canUndo: false,
				};
			}

			if ( resolvedUndo?.status === 'review' ) {
				return {
					...resolvedUndo,
					canUndo: false,
				};
			}

			if (
				entryIndex >= 0 &&
				! isOrderedUndoEligibleWithResolvedStates(
					orderedEntries,
					preliminaryUndoStates,
					entryIndex
				)
			) {
				return {
					...resolvedUndo,
					canUndo: false,
					status: 'blocked',
					error: ORDERED_UNDO_BLOCKED_ERROR,
				};
			}

			if ( resolvedUndo?.status !== 'available' ) {
				return {
					...resolvedUndo,
					canUndo: false,
				};
			}

			return {
				...resolvedUndo,
				canUndo: resolvedUndo.canUndo !== false,
			};
		} )(),
	} ) );
}

export function getLatestAppliedActivity( entries = [] ) {
	return (
		[ ...sortActivityEntries( entries ) ]
			.reverse()
			.find(
				( entry ) => getBaseUndoState( entry ).status !== 'undone'
			) || null
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
