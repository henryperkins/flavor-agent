import {
	getResolvedActivityUndoState,
	ORDERED_UNDO_BLOCKED_ERROR,
} from '../store/activity-history';

export const VIEW_STORAGE_KEY = 'flavor-agent:activity-log:view';

export const DEFAULT_ACTIVITY_VIEW = Object.freeze( {
	type: 'activity',
	search: '',
	page: 1,
	perPage: 20,
	filters: [],
	fields: [ 'timestampDisplay', 'status', 'surface' ],
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

function normalizeLocale( locale = '' ) {
	if ( typeof locale !== 'string' || ! locale.trim() ) {
		return undefined;
	}

	return locale.replaceAll( '_', '-' );
}

function normalizeTimeZone( timeZone = '' ) {
	if ( typeof timeZone !== 'string' || ! timeZone.trim() ) {
		return 'UTC';
	}

	return timeZone.trim();
}

function normalizePositiveInteger( value, fallback, max = Infinity ) {
	if ( ! Number.isInteger( value ) || value <= 0 ) {
		return fallback;
	}

	return Math.min( value, max );
}

function getDefaultActivityView( {
	defaultPerPage = DEFAULT_ACTIVITY_VIEW.perPage,
	maxPerPage = DEFAULT_ACTIVITY_VIEW.perPage,
} = {} ) {
	const resolvedMaxPerPage = normalizePositiveInteger(
		maxPerPage,
		DEFAULT_ACTIVITY_VIEW.perPage
	);

	return {
		...DEFAULT_ACTIVITY_VIEW,
		perPage: normalizePositiveInteger(
			defaultPerPage,
			DEFAULT_ACTIVITY_VIEW.perPage,
			resolvedMaxPerPage
		),
	};
}

const STYLE_ATTRIBUTE_KEYS = new Set( [
	'align',
	'backgroundColor',
	'borderColor',
	'className',
	'fontFamily',
	'fontSize',
	'gradient',
	'layout',
	'style',
	'textAlign',
	'textColor',
	'width',
] );

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

function humanizeValueLabel( value = '' ) {
	if ( typeof value !== 'string' || ! value.trim() ) {
		return '';
	}

	return value
		.replaceAll( '/', ' ' )
		.replaceAll( '_', ' ' )
		.replaceAll( '-', ' ' )
		.split( /\s+/ )
		.filter( Boolean )
		.map( ( part ) => part.charAt( 0 ).toUpperCase() + part.slice( 1 ) )
		.join( ' ' );
}

function formatBlockPath( blockPath = [] ) {
	if ( ! Array.isArray( blockPath ) || blockPath.length === 0 ) {
		return '';
	}

	return blockPath
		.map( ( value ) => {
			const numericValue = Number( value );

			return Number.isFinite( numericValue )
				? String( Math.trunc( numericValue ) + 1 )
				: String( value );
		} )
		.join( ' → ' );
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

	if ( postType === 'global_styles' ) {
		return `Global Styles ${ entityId || EMPTY_VALUE }`;
	}

	if ( /^\d+$/.test( entityId ) ) {
		return `${ postType || 'Post' } #${ entityId }`;
	}

	if ( entityId ) {
		return `${ postType || 'Document' } ${ entityId }`;
	}

	return postType || '';
}

function formatActivityTypeLabel( activityType = '' ) {
	switch ( activityType ) {
		case 'apply_suggestion':
			return 'Apply block suggestion';
		case 'apply_template_suggestion':
			return 'Apply template suggestion';
		case 'apply_template_part_suggestion':
			return 'Apply template-part suggestion';
		case 'apply_global_styles_suggestion':
			return 'Apply Global Styles suggestion';
		default:
			return humanizeValueLabel( activityType ) || EMPTY_VALUE;
	}
}

function getOperationEntries( state = {} ) {
	return Array.isArray( state?.operations ) ? state.operations : [];
}

function summarizeOperation( operation = {} ) {
	const typeLabel = humanizeValueLabel( operation?.type || 'operation' );

	if ( operation?.type === 'insert_pattern' ) {
		const patternLabel =
			operation?.patternTitle || operation?.patternName || typeLabel;
		const placementLabel = humanizeValueLabel( operation?.placement || '' );
		const targetBlockLabel = humanizeValueLabel(
			operation?.targetBlockName || ''
		);
		const targetPathLabel = formatBlockPath( operation?.targetPath || [] );

		if ( placementLabel ) {
			const locationParts = [ placementLabel ];

			if ( targetBlockLabel ) {
				locationParts.push( targetBlockLabel );
			}

			if ( targetPathLabel ) {
				locationParts.push( `(${ targetPathLabel })` );
			}

			return `${ typeLabel }: ${ patternLabel } -> ${ locationParts.join(
				' '
			) }`;
		}

		return `${ typeLabel }: ${ patternLabel }`;
	}

	if ( operation?.patternTitle ) {
		return `${ typeLabel }: ${ operation.patternTitle }`;
	}

	if ( operation?.patternName ) {
		return `${ typeLabel }: ${ operation.patternName }`;
	}

	if ( operation?.slug ) {
		return `${ typeLabel }: ${ operation.slug }`;
	}

	if ( operation?.expectedSlug ) {
		return `${ typeLabel }: ${ operation.expectedSlug }`;
	}

	return typeLabel;
}

function flattenStateEntries( value, prefix = '' ) {
	if ( Array.isArray( value ) ) {
		if ( prefix.endsWith( 'operations' ) ) {
			return value.map( ( entry, index ) => [
				`${ prefix }[${ index }]`,
				summarizeOperation( entry ),
			] );
		}

		return value.flatMap( ( entry, index ) =>
			flattenStateEntries( entry, `${ prefix }[${ index }]` )
		);
	}

	if ( isPlainObject( value ) ) {
		return Object.entries( value ).flatMap( ( [ key, entry ] ) =>
			flattenStateEntries( entry, prefix ? `${ prefix }.${ key }` : key )
		);
	}

	const normalizedPrefix = prefix || 'value';

	return [ [ normalizedPrefix, value ] ];
}

function simplifyDiffPath( path = '' ) {
	if ( typeof path !== 'string' || ! path ) {
		return 'value';
	}

	return path
		.replace( /^attributes\./, '' )
		.replace( /^before\./, '' )
		.replace( /^after\./, '' );
}

function formatDiffValue( value ) {
	if ( value === undefined ) {
		return '∅';
	}

	if ( value === null ) {
		return 'null';
	}

	if ( typeof value === 'string' ) {
		return value;
	}

	if ( typeof value === 'number' || typeof value === 'boolean' ) {
		return String( value );
	}

	try {
		return JSON.stringify( value );
	} catch {
		return EMPTY_VALUE;
	}
}

function buildStructuredStateDiff( beforeState, afterState ) {
	const beforeEntries = new Map( flattenStateEntries( beforeState ) );
	const afterEntries = new Map( flattenStateEntries( afterState ) );
	const keys = Array.from(
		new Set( [ ...beforeEntries.keys(), ...afterEntries.keys() ] )
	).sort( ( left, right ) => left.localeCompare( right ) );
	const lines = keys
		.map( ( key ) => {
			const beforeValue = beforeEntries.get( key );
			const afterValue = afterEntries.get( key );

			if ( beforeValue === afterValue ) {
				return null;
			}

			if ( beforeValue === undefined ) {
				return `+ ${ simplifyDiffPath( key ) }: ${ formatDiffValue(
					afterValue
				) }`;
			}

			if ( afterValue === undefined ) {
				return `- ${ simplifyDiffPath( key ) }: ${ formatDiffValue(
					beforeValue
				) }`;
			}

			return `~ ${ simplifyDiffPath( key ) }: ${ formatDiffValue(
				beforeValue
			) } → ${ formatDiffValue( afterValue ) }`;
		} )
		.filter( Boolean );

	return lines.length ? lines.join( '\n' ) : EMPTY_VALUE;
}

function areTrackedAttributeValuesEqual( left, right ) {
	if ( Object.is( left, right ) ) {
		return true;
	}

	if ( Array.isArray( left ) || Array.isArray( right ) ) {
		if ( ! Array.isArray( left ) || ! Array.isArray( right ) ) {
			return false;
		}

		if ( left.length !== right.length ) {
			return false;
		}

		return left.every( ( value, index ) =>
			areTrackedAttributeValuesEqual( value, right[ index ] )
		);
	}

	if ( isPlainObject( left ) || isPlainObject( right ) ) {
		if ( ! isPlainObject( left ) || ! isPlainObject( right ) ) {
			return false;
		}

		const leftKeys = Object.keys( left ).sort();
		const rightKeys = Object.keys( right ).sort();

		if ( leftKeys.length !== rightKeys.length ) {
			return false;
		}

		return leftKeys.every(
			( key, index ) =>
				key === rightKeys[ index ] &&
				areTrackedAttributeValuesEqual( left[ key ], right[ key ] )
		);
	}

	return false;
}

function hasStyleMutation( entry ) {
	const beforeAttributes = isPlainObject( entry?.before?.attributes )
		? entry.before.attributes
		: {};
	const afterAttributes = isPlainObject( entry?.after?.attributes )
		? entry.after.attributes
		: {};
	const keys = new Set( [
		...Object.keys( beforeAttributes ),
		...Object.keys( afterAttributes ),
	] );

	for ( const key of keys ) {
		if ( ! STYLE_ATTRIBUTE_KEYS.has( key ) ) {
			continue;
		}

		if (
			! areTrackedAttributeValuesEqual(
				beforeAttributes[ key ],
				afterAttributes[ key ]
			)
		) {
			return true;
		}
	}

	return false;
}

function getPrimaryOperation( entry ) {
	const operations = getOperationEntries( entry?.after );

	if ( operations.length ) {
		return operations[ 0 ];
	}

	const beforeOperations = getOperationEntries( entry?.before );

	return beforeOperations.length ? beforeOperations[ 0 ] : null;
}

function deriveOperationType( entry ) {
	const primaryOperation = getPrimaryOperation( entry );
	const operationType = String( primaryOperation?.type || '' );

	if (
		operationType === 'insert_pattern' ||
		operationType === 'insert_block'
	) {
		return {
			value: 'insert',
			label:
				operationType === 'insert_pattern'
					? 'Insert pattern'
					: 'Insert block',
		};
	}

	if (
		operationType === 'replace_template_part' ||
		operationType === 'assign_template_part'
	) {
		return {
			value: 'replace',
			label:
				operationType === 'replace_template_part'
					? 'Replace template part'
					: 'Assign template part',
		};
	}

	if ( hasStyleMutation( entry ) ) {
		return {
			value: 'apply-style',
			label: 'Apply style',
		};
	}

	if ( entry?.surface === 'block' ) {
		return {
			value: 'modify-attributes',
			label: 'Modify attributes',
		};
	}

	return {
		value: operationType || String( entry?.type || 'recorded' ),
		label:
			humanizeValueLabel(
				operationType || String( entry?.type || 'recorded' )
			) || EMPTY_VALUE,
	};
}

function getBlockPathLabel( entry ) {
	const blockPath = formatBlockPath( entry?.target?.blockPath || [] );

	if ( ! blockPath ) {
		return EMPTY_VALUE;
	}

	const blockName = formatBlockName( entry?.target?.blockName || '' );

	return blockName ? `${ blockName } · ${ blockPath }` : blockPath;
}

export function formatSurfaceLabel( surface = '' ) {
	switch ( surface ) {
		case 'template':
			return 'Template';
		case 'template-part':
			return 'Template part';
		case 'global-styles':
			return 'Global Styles';
		case 'style-book':
			return 'Style Book';
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
			.map( ( operation ) => summarizeOperation( operation ) )
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
		[ 'ai', 'backendLabel' ],
		[ 'ai', 'providerLabel' ],
		[ 'provider' ],
		[ 'providerName' ],
		[ 'metadata', 'provider' ],
		[ 'ai', 'provider' ],
		[ 'result', 'provider' ],
	] );
	const model = getFirstString( request, [
		[ 'ai', 'model' ],
		[ 'model' ],
		[ 'modelName' ],
		[ 'metadata', 'model' ],
		[ 'result', 'model' ],
	] );
	const providerPath = getFirstString( request, [
		[ 'ai', 'pathLabel' ],
		[ 'pathLabel' ],
	] );
	const configurationOwner = getFirstString( request, [
		[ 'ai', 'ownerLabel' ],
		[ 'ownerLabel' ],
	] );
	const credentialSource = getFirstString( request, [
		[ 'ai', 'credentialSourceLabel' ],
		[ 'ai', 'credentialSource' ],
		[ 'credentialSourceLabel' ],
		[ 'credentialSource' ],
	] );
	const selectedProvider = getFirstString( request, [
		[ 'ai', 'selectedProviderLabel' ],
		[ 'ai', 'selectedProvider' ],
		[ 'selectedProviderLabel' ],
		[ 'selectedProvider' ],
	] );
	const requestAbility = getFirstString( request, [
		[ 'ai', 'ability' ],
		[ 'ability' ],
	] );
	const requestRoute = getFirstString( request, [
		[ 'ai', 'route' ],
		[ 'route' ],
	] );
	const usedFallback = Boolean(
		readPath( request, [ 'ai', 'usedFallback' ] ) ?? request?.usedFallback
	);
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
		providerPath: providerPath || EMPTY_VALUE,
		configurationOwner: configurationOwner || EMPTY_VALUE,
		credentialSource: credentialSource || EMPTY_VALUE,
		selectedProvider: selectedProvider || EMPTY_VALUE,
		requestAbility: requestAbility || EMPTY_VALUE,
		requestRoute: requestRoute || EMPTY_VALUE,
		usedFallback,
		tokenUsageLabel,
		latencyLabel: latencyMs !== null ? `${ latencyMs } ms` : EMPTY_VALUE,
	};
}

function getUndoReason( status, resolvedUndo = null, entry = null ) {
	if ( status === 'applied' && resolvedUndo?.status === 'available' ) {
		return 'This is the newest still-applied AI action for this entity.';
	}

	if ( typeof entry?.undo?.error === 'string' && entry.undo.error.trim() ) {
		return entry.undo.error.trim();
	}

	if (
		typeof resolvedUndo?.error === 'string' &&
		resolvedUndo.error.trim()
	) {
		return resolvedUndo.error.trim();
	}

	if ( status === 'undone' ) {
		return 'Undo already completed.';
	}

	if ( status === 'failed' ) {
		return 'Undo is unavailable.';
	}

	return EMPTY_VALUE;
}

function getActivityTitle( entry ) {
	if ( typeof entry?.suggestion === 'string' && entry.suggestion.trim() ) {
		return entry.suggestion.trim();
	}

	const operationType = deriveOperationType( entry );

	if ( operationType.label && operationType.label !== EMPTY_VALUE ) {
		return operationType.label;
	}

	if ( entry?.surface === 'template' ) {
		return 'Template suggestion applied';
	}

	if ( entry?.surface === 'template-part' ) {
		return 'Template-part suggestion applied';
	}

	if ( entry?.surface === 'global-styles' ) {
		return 'Global Styles suggestion applied';
	}

	if ( entry?.surface === 'style-book' ) {
		return 'Style Book suggestion applied';
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

	if ( entry?.surface === 'global-styles' ) {
		return `Global Styles ${
			entry?.target?.globalStylesId || EMPTY_VALUE
		}`;
	}

	if ( entry?.surface === 'style-book' ) {
		const blockTitle =
			String( entry?.target?.blockTitle || '' ) || EMPTY_VALUE;

		return `Style Book ${ blockTitle }`;
	}

	if ( entry?.target?.blockName ) {
		return `${ formatBlockName( entry.target.blockName ) } block`;
	}

	return formatSurfaceLabel( entry?.surface );
}

function getActivityDescription( entry, entityLabel, documentLabel ) {
	const operationType = deriveOperationType( entry );
	const blockPathLabel = getBlockPathLabel( entry );
	const segments = [ operationType.label, entityLabel ];

	if ( blockPathLabel !== EMPTY_VALUE ) {
		segments.push( blockPathLabel );
	}

	if ( documentLabel && documentLabel !== entityLabel ) {
		segments.push( documentLabel );
	}

	return segments.filter( Boolean ).join( ' · ' );
}

export function getActivityStatus( entry, allEntries = [] ) {
	const explicitStatus =
		typeof entry?.status === 'string' ? entry.status.trim() : '';

	if (
		[ 'applied', 'undone', 'blocked', 'failed' ].includes( explicitStatus )
	) {
		return explicitStatus;
	}

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
	const status =
		typeof entry === 'string'
			? entry
			: getActivityStatus( entry, allEntries );

	switch ( status ) {
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

export function buildActivityTargetLink( entry, adminBaseUrl = '' ) {
	const { postType, entityId } = getDocumentContext( entry );

	if ( postType === 'wp_template' || entry?.surface === 'template' ) {
		return {
			url: buildAdminUrl( adminBaseUrl, 'site-editor.php', {
				postType: 'wp_template',
				postId: entry?.target?.templateRef || entityId,
			} ),
			label: 'Open template',
		};
	}

	if (
		postType === 'wp_template_part' ||
		entry?.surface === 'template-part'
	) {
		return {
			url: buildAdminUrl( adminBaseUrl, 'site-editor.php', {
				postType: 'wp_template_part',
				postId: entry?.target?.templatePartRef || entityId,
			} ),
			label: 'Open template part',
		};
	}

	if ( postType === 'global_styles' || entry?.surface === 'global-styles' ) {
		return {
			url: buildAdminUrl( adminBaseUrl, 'site-editor.php', {
				canvas: 'edit',
				path: '/wp_global_styles',
			} ),
			label: 'Open Styles',
		};
	}

	if ( entry?.surface === 'style-book' ) {
		return {
			url: buildAdminUrl( adminBaseUrl, 'site-editor.php', {
				canvas: 'edit',
				path: '/wp_global_styles',
			} ),
			label: 'Open Styles',
		};
	}

	if ( /^\d+$/.test( entityId ) ) {
		return {
			url: buildAdminUrl( adminBaseUrl, 'post.php', {
				post: entityId,
				action: 'edit',
			} ),
			label: 'Open post',
		};
	}

	return {
		url: '',
		label: 'Not available',
	};
}

export function buildActivityTargetUrl( entry, adminBaseUrl = '' ) {
	return buildActivityTargetLink( entry, adminBaseUrl ).url;
}

export function formatActivityTimestamp(
	timestamp,
	{ locale = '', timeZone = 'UTC' } = {}
) {
	if ( typeof timestamp !== 'string' || ! timestamp ) {
		return {
			timestampDisplay: EMPTY_VALUE,
			dayKey: '',
		};
	}

	const date = new Date( timestamp );

	if ( Number.isNaN( date.getTime() ) ) {
		return {
			timestampDisplay: EMPTY_VALUE,
			dayKey: '',
		};
	}

	const normalizedLocale = normalizeLocale( locale );
	const normalizedTimeZone = normalizeTimeZone( timeZone );

	const timestampFormatter = new Intl.DateTimeFormat( normalizedLocale, {
		timeZone: normalizedTimeZone,
		year: 'numeric',
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
	} );
	const dayFormatter = new Intl.DateTimeFormat( normalizedLocale, {
		timeZone: normalizedTimeZone,
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	} );
	const dayParts = Object.fromEntries(
		dayFormatter
			.formatToParts( date )
			.filter( ( part ) => part.type !== 'literal' )
			.map( ( part ) => [ part.type, part.value ] )
	);

	return {
		timestampDisplay: timestampFormatter.format( date ),
		dayKey:
			dayParts.year && dayParts.month && dayParts.day
				? `${ dayParts.year }-${ dayParts.month }-${ dayParts.day }`
				: '',
	};
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

export function normalizeStoredActivityView( view, options = {} ) {
	const defaultView = getDefaultActivityView( options );

	if ( ! isPlainObject( view ) ) {
		return {
			...defaultView,
		};
	}

	const sort = isPlainObject( view.sort )
		? {
				field:
					typeof view.sort.field === 'string' && view.sort.field
						? view.sort.field
						: defaultView.sort.field,
				direction: view.sort.direction === 'asc' ? 'asc' : 'desc',
		  }
		: defaultView.sort;
	let groupBy = defaultView.groupBy;

	if ( view.groupBy === undefined ) {
		groupBy = defaultView.groupBy;
	} else if ( isPlainObject( view.groupBy ) ) {
		groupBy = {
			field:
				typeof view.groupBy.field === 'string' && view.groupBy.field
					? view.groupBy.field
					: defaultView.groupBy.field,
			direction: view.groupBy.direction === 'asc' ? 'asc' : 'desc',
			showLabel: view.groupBy.showLabel !== false,
		};
	} else {
		groupBy = undefined;
	}

	return {
		...defaultView,
		search: typeof view.search === 'string' ? view.search : '',
		page: Number.isInteger( view.page ) && view.page > 0 ? view.page : 1,
		perPage: normalizePositiveInteger(
			view.perPage,
			defaultView.perPage,
			normalizePositiveInteger( options.maxPerPage, defaultView.perPage )
		),
		filters: Array.isArray( view.filters ) ? view.filters : [],
		fields: Array.isArray( view.fields ) ? view.fields : defaultView.fields,
		sort,
		groupBy,
		layout: isPlainObject( view.layout )
			? {
					...defaultView.layout,
					...view.layout,
			  }
			: defaultView.layout,
	};
}

export function clampActivityViewPage(
	view,
	paginationInfo = {},
	options = {}
) {
	const normalizedView = normalizeStoredActivityView( view, options );
	const totalPages = Number.isInteger( paginationInfo?.totalPages )
		? paginationInfo.totalPages
		: 0;
	const lastPage = totalPages > 0 ? totalPages : 1;
	const nextPage = Math.min( normalizedView.page, lastPage );

	if ( nextPage === normalizedView.page ) {
		return normalizedView;
	}

	return {
		...normalizedView,
		page: nextPage,
	};
}

export function areActivityViewsEqual( left, right, options = {} ) {
	return (
		JSON.stringify( normalizeStoredActivityView( left, options ) ) ===
		JSON.stringify( normalizeStoredActivityView( right, options ) )
	);
}

export function readPersistedActivityView( storage, options = {} ) {
	const resolvedStorage = getStorage( storage );
	const defaultView = getDefaultActivityView( options );

	if ( ! resolvedStorage ) {
		return {
			...defaultView,
		};
	}

	try {
		return normalizeStoredActivityView(
			JSON.parse( resolvedStorage.getItem( VIEW_STORAGE_KEY ) ),
			options
		);
	} catch {
		return {
			...defaultView,
		};
	}
}

export function writePersistedActivityView( view, storage, options = {} ) {
	const resolvedStorage = getStorage( storage );

	if ( ! resolvedStorage ) {
		return;
	}

	try {
		resolvedStorage.setItem(
			VIEW_STORAGE_KEY,
			JSON.stringify( normalizeStoredActivityView( view, options ) )
		);
	} catch {
		// Ignore storage errors in wp-admin.
	}
}

export function normalizeActivityEntry(
	entry,
	allEntries = [],
	{
		adminBaseUrl = '',
		settingsUrl = '',
		connectorsUrl = '',
		locale = '',
		timeZone = 'UTC',
	} = {}
) {
	const { postType, entityId } = getDocumentContext( entry );
	const diagnostics = getRequestDiagnostics( entry?.request || {} );
	const resolvedUndo = getResolvedActivityUndoState( entry, allEntries );
	const status = getActivityStatus( entry, allEntries );
	const { timestampDisplay, dayKey } = formatActivityTimestamp(
		entry?.timestamp,
		{
			locale,
			timeZone,
		}
	);
	const entityLabel = getActivityEntityLabel( entry );
	const documentLabel = formatDocumentLabel( postType, entityId );
	const operationType = deriveOperationType( entry );
	const targetLink = buildActivityTargetLink( entry, adminBaseUrl );
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
		day: dayKey,
		timestampDisplay,
		status,
		statusLabel: getActivityStatusLabel( status ),
		surface: String( entry?.surface || '' ),
		surfaceLabel: formatSurfaceLabel( entry?.surface ),
		activityType: String( entry?.type || '' ) || EMPTY_VALUE,
		activityTypeLabel: formatActivityTypeLabel( entry?.type ),
		operationType: operationType.value || EMPTY_VALUE,
		operationTypeLabel: operationType.label || EMPTY_VALUE,
		postType: postType || EMPTY_VALUE,
		entityId: entityId || EMPTY_VALUE,
		documentScopeKey:
			typeof entry?.document?.scopeKey === 'string' &&
			entry.document.scopeKey.trim()
				? entry.document.scopeKey.trim()
				: EMPTY_VALUE,
		blockPath: getBlockPathLabel( entry ),
		userId:
			entry?.userId || entry?.userId === 0
				? String( entry.userId )
				: EMPTY_VALUE,
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
		providerPath: diagnostics.providerPath,
		configurationOwner: diagnostics.configurationOwner,
		credentialSource: diagnostics.credentialSource,
		selectedProvider: diagnostics.selectedProvider,
		requestAbility: diagnostics.requestAbility,
		requestRoute: diagnostics.requestRoute,
		beforeSummary: summarizeActivityState( entry?.before ),
		afterSummary: summarizeActivityState( entry?.after ),
		stateDiff: buildStructuredStateDiff( entry?.before, entry?.after ),
		undoStatusLabel:
			status === 'applied' && resolvedUndo?.status === 'available'
				? 'Undo available'
				: getActivityStatusLabel( status ),
		undoError:
			typeof entry?.undo?.error === 'string' && entry.undo.error.trim()
				? entry.undo.error.trim()
				: status === 'blocked' &&
				  typeof resolvedUndo?.error !== 'string'
				? ORDERED_UNDO_BLOCKED_ERROR
				: EMPTY_VALUE,
		undoReason: getUndoReason( status, resolvedUndo, entry ),
		provider: diagnostics.provider,
		model: diagnostics.model,
		tokenUsage: diagnostics.tokenUsageLabel,
		latency: diagnostics.latencyLabel,
		requestFallback:
			diagnostics.usedFallback &&
			diagnostics.selectedProvider !== EMPTY_VALUE
				? `Fallback from selected ${ diagnostics.selectedProvider }.`
				: EMPTY_VALUE,
		targetUrl: targetLink.url,
		targetLinkLabel: targetLink.label,
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
