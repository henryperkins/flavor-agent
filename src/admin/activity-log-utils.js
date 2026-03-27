import { getResolvedActivityUndoState } from '../store/activity-history';

export const VIEW_STORAGE_KEY = 'flavor-agent:activity-log:view';

export const DEFAULT_ACTIVITY_VIEW = Object.freeze( {
	type: 'activity',
	search: '',
	page: 1,
	perPage: 20,
	filters: [],
	fields: [ 'timestampDisplay', 'surface', 'status', 'user', 'provider' ],
	titleField: 'title',
	descriptionField: 'description',
	mediaField: 'icon',
	showMedia: true,
	sort: {
		field: 'timestamp',
		direction: 'desc',
	},
	groupBy: {
		field: 'day',
		direction: 'desc',
		showLabel: false,
	},
	layout: {
		density: 'comfortable',
	},
} );

const EMPTY_VALUE = 'Not recorded';

function isPlainObject( value ) {
	return (
		Boolean( value ) &&
		typeof value === 'object' &&
		! Array.isArray( value )
	);
}

function readPath( value, path = [] ) {
	let current = value;

	for ( const key of path ) {
		if ( ! isPlainObject( current ) || ! Object.hasOwn( current, key ) ) {
			return undefined;
		}

		current = current[ key ];
	}

	return current;
}

function getFirstString( value, paths = [] ) {
	for ( const path of paths ) {
		const candidate = readPath( value, path );

		if ( typeof candidate === 'string' && candidate.trim() ) {
			return candidate.trim();
		}
	}

	return '';
}

function getFirstNumber( value, paths = [] ) {
	for ( const path of paths ) {
		const candidate = readPath( value, path );

		if ( typeof candidate === 'number' && Number.isFinite( candidate ) ) {
			return candidate;
		}

		if ( typeof candidate === 'string' && candidate.trim() !== '' ) {
			const parsed = Number( candidate );

			if ( Number.isFinite( parsed ) ) {
				return parsed;
			}
		}
	}

	return null;
}

function normalizeAdminBaseUrl( adminBaseUrl = '' ) {
	if ( typeof adminBaseUrl !== 'string' || ! adminBaseUrl.trim() ) {
		return '';
	}

	return adminBaseUrl.endsWith( '/' ) ? adminBaseUrl : `${ adminBaseUrl }/`;
}

function buildAdminUrl( adminBaseUrl, path, query = {} ) {
	const base = normalizeAdminBaseUrl( adminBaseUrl );

	if ( ! base ) {
		return '';
	}

	const url = `${ base }${ String( path || '' ).replace( /^\/+/, '' ) }`;
	const params = new URLSearchParams();

	for ( const [ key, value ] of Object.entries( query ) ) {
		if ( value === null || value === undefined || value === '' ) {
			continue;
		}

		params.set( key, String( value ) );
	}

	const queryString = params.toString();

	return queryString ? `${ url }?${ queryString }` : url;
}

function formatBlockName( blockName = '' ) {
	if ( typeof blockName !== 'string' || ! blockName ) {
		return 'Block';
	}

	return blockName
		.replace( /^core\//, '' )
		.split( /[-_/]/ )
		.filter( Boolean )
		.map( ( part ) => part.charAt( 0 ).toUpperCase() + part.slice( 1 ) )
		.join( ' ' );
}

function parseScopeKey( scopeKey = '' ) {
	if ( typeof scopeKey !== 'string' || ! scopeKey.includes( ':' ) ) {
		return {
			postType: '',
			entityId: '',
		};
	}

	const [ postType, ...rest ] = scopeKey.split( ':' );

	return {
		postType: postType || '',
		entityId: rest.join( ':' ) || '',
	};
}

function getDocumentContext( entry ) {
	const document = isPlainObject( entry?.document ) ? entry.document : {};
	const parsedScope = parseScopeKey( document.scopeKey || '' );

	return {
		postType: String( document.postType || parsedScope.postType || '' ),
		entityId: String( document.entityId || parsedScope.entityId || '' ),
		scopeKey: String( document.scopeKey || '' ),
	};
}

function formatDocumentLabel( postType = '', entityId = '' ) {
	if ( ! postType && ! entityId ) {
		return '';
	}

	if ( postType === 'wp_template' ) {
		return `Template ${ entityId || EMPTY_VALUE }`;
	}

	if ( postType === 'wp_template_part' ) {
		return `Template part ${ entityId || EMPTY_VALUE }`;
	}

	if ( /^\d+$/.test( entityId ) ) {
		return `${ postType || 'Post' } #${ entityId }`;
	}

	if ( entityId ) {
		return `${ postType || 'Document' } ${ entityId }`;
	}

	return postType || '';
}

export function formatSurfaceLabel( surface = '' ) {
	switch ( surface ) {
		case 'template':
			return 'Template';
		case 'template-part':
			return 'Template part';
		case 'block':
			return 'Block';
		default:
			return 'Activity';
	}
}

export function summarizeActivityState( state ) {
	if ( ! isPlainObject( state ) || Object.keys( state ).length === 0 ) {
		return EMPTY_VALUE;
	}

	if ( Array.isArray( state.operations ) && state.operations.length ) {
		return state.operations
			.map( ( operation ) => {
				const type = String(
					operation?.type || 'operation'
				).replaceAll( '_', ' ' );

				if ( operation?.patternName ) {
					return `${ type }: ${ operation.patternName }`;
				}

				if ( operation?.slug ) {
					return `${ type }: ${ operation.slug }`;
				}

				return type;
			} )
			.join( '; ' );
	}

	if ( isPlainObject( state.attributes ) ) {
		const attributeKeys = Object.keys( state.attributes );

		return attributeKeys.length
			? `Attributes: ${ attributeKeys.join( ', ' ) }`
			: EMPTY_VALUE;
	}

	try {
		return JSON.stringify( state );
	} catch {
		return EMPTY_VALUE;
	}
}

function getRequestDiagnostics( request = {} ) {
	const provider = getFirstString( request, [
		[ 'provider' ],
		[ 'providerName' ],
		[ 'metadata', 'provider' ],
		[ 'ai', 'provider' ],
		[ 'result', 'provider' ],
	] );
	const model = getFirstString( request, [
		[ 'model' ],
		[ 'modelName' ],
		[ 'metadata', 'model' ],
		[ 'ai', 'model' ],
		[ 'result', 'model' ],
	] );
	const totalTokens = getFirstNumber( request, [
		[ 'tokenUsage', 'total' ],
		[ 'usage', 'total_tokens' ],
		[ 'usage', 'totalTokens' ],
		[ 'metadata', 'tokenUsage', 'total' ],
		[ 'totalTokens' ],
	] );
	const inputTokens = getFirstNumber( request, [
		[ 'tokenUsage', 'input' ],
		[ 'usage', 'input_tokens' ],
		[ 'usage', 'inputTokens' ],
	] );
	const outputTokens = getFirstNumber( request, [
		[ 'tokenUsage', 'output' ],
		[ 'usage', 'output_tokens' ],
		[ 'usage', 'outputTokens' ],
	] );
	const latencyMs = getFirstNumber( request, [
		[ 'latencyMs' ],
		[ 'durationMs' ],
		[ 'timing', 'latencyMs' ],
		[ 'metadata', 'latencyMs' ],
	] );

	let tokenUsageLabel = EMPTY_VALUE;

	if ( totalTokens !== null ) {
		tokenUsageLabel = `${ totalTokens } total tokens`;
	} else if ( inputTokens !== null || outputTokens !== null ) {
		tokenUsageLabel = [
			inputTokens !== null ? `${ inputTokens } input` : null,
			outputTokens !== null ? `${ outputTokens } output` : null,
		]
			.filter( Boolean )
			.join( ' / ' );
	}

	return {
		provider: provider || EMPTY_VALUE,
		model: model || EMPTY_VALUE,
		tokenUsageLabel,
		latencyLabel: latencyMs !== null ? `${ latencyMs } ms` : EMPTY_VALUE,
	};
}

function getActivityTitle( entry ) {
	if ( typeof entry?.suggestion === 'string' && entry.suggestion.trim() ) {
		return entry.suggestion.trim();
	}

	if ( entry?.surface === 'template' ) {
		return 'Template suggestion applied';
	}

	if ( entry?.surface === 'template-part' ) {
		return 'Template-part suggestion applied';
	}

	if ( entry?.target?.blockName ) {
		return `${ formatBlockName(
			entry.target.blockName
		) } suggestion applied`;
	}

	return 'AI action recorded';
}

function getActivityEntityLabel( entry ) {
	if ( entry?.surface === 'template' ) {
		return `Template ${ entry?.target?.templateRef || EMPTY_VALUE }`;
	}

	if ( entry?.surface === 'template-part' ) {
		return `Template part ${
			entry?.target?.templatePartRef || EMPTY_VALUE
		}`;
	}

	if ( entry?.target?.blockName ) {
		return `${ formatBlockName( entry.target.blockName ) } block`;
	}

	return formatSurfaceLabel( entry?.surface );
}

function getActivityDescription( entry, entityLabel, documentLabel ) {
	const segments = [ formatSurfaceLabel( entry?.surface ), entityLabel ];

	if ( documentLabel && documentLabel !== entityLabel ) {
		segments.push( documentLabel );
	}

	return segments.filter( Boolean ).join( ' · ' );
}

export function getActivityStatus( entry, allEntries = [] ) {
	const resolvedUndo = getResolvedActivityUndoState( entry, allEntries );

	switch ( resolvedUndo?.status ) {
		case 'undone':
			return 'undone';
		case 'blocked':
			return 'blocked';
		case 'failed':
			return 'failed';
		default:
			return 'applied';
	}
}

export function getActivityStatusLabel( entry, allEntries = [] ) {
	switch ( getActivityStatus( entry, allEntries ) ) {
		case 'undone':
			return 'Undone';
		case 'blocked':
			return 'Undo blocked';
		case 'failed':
			return 'Undo unavailable';
		default:
			return 'Applied';
	}
}

export function buildActivityTargetUrl( entry, adminBaseUrl = '' ) {
	const { postType, entityId } = getDocumentContext( entry );

	if ( postType === 'wp_template' || entry?.surface === 'template' ) {
		return buildAdminUrl( adminBaseUrl, 'site-editor.php', {
			postType: 'wp_template',
			postId: entry?.target?.templateRef || entityId,
		} );
	}

	if (
		postType === 'wp_template_part' ||
		entry?.surface === 'template-part'
	) {
		return buildAdminUrl( adminBaseUrl, 'site-editor.php', {
			postType: 'wp_template_part',
			postId: entry?.target?.templatePartRef || entityId,
		} );
	}

	if ( /^\d+$/.test( entityId ) ) {
		return buildAdminUrl( adminBaseUrl, 'post.php', {
			post: entityId,
			action: 'edit',
		} );
	}

	return '';
}

function formatTimestamp( timestamp ) {
	if ( typeof timestamp !== 'string' || ! timestamp ) {
		return EMPTY_VALUE;
	}

	const date = new Date( timestamp );

	if ( Number.isNaN( date.getTime() ) ) {
		return EMPTY_VALUE;
	}

	return date.toLocaleString();
}

function getStorage( storage ) {
	if ( storage !== undefined ) {
		return storage;
	}

	if ( typeof window === 'undefined' ) {
		return null;
	}

	try {
		return window.localStorage;
	} catch {
		return null;
	}
}

export function normalizeStoredActivityView( view ) {
	if ( ! isPlainObject( view ) ) {
		return {
			...DEFAULT_ACTIVITY_VIEW,
		};
	}

	const sort = isPlainObject( view.sort )
		? {
				field:
					typeof view.sort.field === 'string' && view.sort.field
						? view.sort.field
						: DEFAULT_ACTIVITY_VIEW.sort.field,
				direction: view.sort.direction === 'asc' ? 'asc' : 'desc',
		  }
		: DEFAULT_ACTIVITY_VIEW.sort;
	let groupBy = DEFAULT_ACTIVITY_VIEW.groupBy;

	if ( view.groupBy === undefined ) {
		groupBy = DEFAULT_ACTIVITY_VIEW.groupBy;
	} else if ( isPlainObject( view.groupBy ) ) {
		groupBy = {
			field:
				typeof view.groupBy.field === 'string' && view.groupBy.field
					? view.groupBy.field
					: DEFAULT_ACTIVITY_VIEW.groupBy.field,
			direction: view.groupBy.direction === 'asc' ? 'asc' : 'desc',
			showLabel: view.groupBy.showLabel !== false,
		};
	} else {
		groupBy = undefined;
	}

	return {
		...DEFAULT_ACTIVITY_VIEW,
		search: typeof view.search === 'string' ? view.search : '',
		page: Number.isInteger( view.page ) && view.page > 0 ? view.page : 1,
		perPage:
			Number.isInteger( view.perPage ) && view.perPage > 0
				? view.perPage
				: DEFAULT_ACTIVITY_VIEW.perPage,
		filters: Array.isArray( view.filters ) ? view.filters : [],
		fields: Array.isArray( view.fields )
			? view.fields
			: DEFAULT_ACTIVITY_VIEW.fields,
		sort,
		groupBy,
		layout: isPlainObject( view.layout )
			? {
					...DEFAULT_ACTIVITY_VIEW.layout,
					...view.layout,
			  }
			: DEFAULT_ACTIVITY_VIEW.layout,
	};
}

export function areActivityViewsEqual( left, right ) {
	return (
		JSON.stringify( normalizeStoredActivityView( left ) ) ===
		JSON.stringify( normalizeStoredActivityView( right ) )
	);
}

export function readPersistedActivityView( storage ) {
	const resolvedStorage = getStorage( storage );

	if ( ! resolvedStorage ) {
		return {
			...DEFAULT_ACTIVITY_VIEW,
		};
	}

	try {
		return normalizeStoredActivityView(
			JSON.parse( resolvedStorage.getItem( VIEW_STORAGE_KEY ) )
		);
	} catch {
		return {
			...DEFAULT_ACTIVITY_VIEW,
		};
	}
}

export function writePersistedActivityView( view, storage ) {
	const resolvedStorage = getStorage( storage );

	if ( ! resolvedStorage ) {
		return;
	}

	try {
		resolvedStorage.setItem(
			VIEW_STORAGE_KEY,
			JSON.stringify( normalizeStoredActivityView( view ) )
		);
	} catch {
		// Ignore storage errors in wp-admin.
	}
}

export function normalizeActivityEntry(
	entry,
	allEntries = [],
	{ adminBaseUrl = '', settingsUrl = '', connectorsUrl = '' } = {}
) {
	const { postType, entityId } = getDocumentContext( entry );
	const diagnostics = getRequestDiagnostics( entry?.request || {} );
	const resolvedUndo = getResolvedActivityUndoState( entry, allEntries );
	const status = getActivityStatus( entry, allEntries );
	const entityLabel = getActivityEntityLabel( entry );
	const documentLabel = formatDocumentLabel( postType, entityId );
	let userLabel = EMPTY_VALUE;

	if ( typeof entry?.userLabel === 'string' && entry.userLabel.trim() ) {
		userLabel = entry.userLabel.trim();
	} else if ( entry?.userId ) {
		userLabel = `User #${ entry.userId }`;
	}

	return {
		...entry,
		icon: status,
		title: getActivityTitle( entry ),
		description: getActivityDescription(
			entry,
			entityLabel,
			documentLabel
		),
		day:
			typeof entry?.timestamp === 'string'
				? entry.timestamp.slice( 0, 10 )
				: '',
		timestampDisplay: formatTimestamp( entry?.timestamp ),
		status,
		statusLabel: getActivityStatusLabel( entry, allEntries ),
		surface: String( entry?.surface || '' ),
		surfaceLabel: formatSurfaceLabel( entry?.surface ),
		user: userLabel,
		entity: entityLabel,
		documentLabel: documentLabel || EMPTY_VALUE,
		requestPrompt:
			typeof entry?.request?.prompt === 'string' &&
			entry.request.prompt.trim()
				? entry.request.prompt.trim()
				: EMPTY_VALUE,
		requestReference:
			typeof entry?.request?.reference === 'string' &&
			entry.request.reference.trim()
				? entry.request.reference.trim()
				: EMPTY_VALUE,
		beforeSummary: summarizeActivityState( entry?.before ),
		afterSummary: summarizeActivityState( entry?.after ),
		undoStatusLabel:
			resolvedUndo?.status === 'available'
				? 'Undo available'
				: getActivityStatusLabel(
						{
							...entry,
							undo: resolvedUndo,
						},
						allEntries
				  ),
		undoError:
			typeof resolvedUndo?.error === 'string' && resolvedUndo.error.trim()
				? resolvedUndo.error.trim()
				: EMPTY_VALUE,
		provider: diagnostics.provider,
		model: diagnostics.model,
		tokenUsage: diagnostics.tokenUsageLabel,
		latency: diagnostics.latencyLabel,
		targetUrl: buildActivityTargetUrl( entry, adminBaseUrl ),
		settingsUrl: settingsUrl || '',
		connectorsUrl: connectorsUrl || '',
	};
}

export function normalizeActivityEntries( entries, context = {} ) {
	const normalizedEntries = Array.isArray( entries )
		? entries.filter( Boolean )
		: [];

	return normalizedEntries.map( ( entry ) =>
		normalizeActivityEntry( entry, normalizedEntries, context )
	);
}
