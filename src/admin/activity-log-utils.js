import { __, _n, sprintf } from '@wordpress/i18n';

import {
	getResolvedActivityUndoState,
	ORDERED_UNDO_BLOCKED_ERROR,
} from '../store/activity-history';
import { truncateActivityTitle } from '../utils/activity-title';

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

const EXPLICIT_FILTER_OPERATORS = new Set( [ 'is', 'isNot' ] );
const TEXT_FILTER_OPERATORS = new Set( [
	'contains',
	'notContains',
	'startsWith',
] );
const DAY_FILTER_OPERATORS = new Set( [
	'between',
	'on',
	'before',
	'after',
	'inThePast',
	'over',
] );
const FILTER_FIELD_OPERATORS = new Map( [
	[ 'surface', EXPLICIT_FILTER_OPERATORS ],
	[ 'status', EXPLICIT_FILTER_OPERATORS ],
	[ 'postType', EXPLICIT_FILTER_OPERATORS ],
	[ 'provider', EXPLICIT_FILTER_OPERATORS ],
	[ 'providerPath', EXPLICIT_FILTER_OPERATORS ],
	[ 'configurationOwner', EXPLICIT_FILTER_OPERATORS ],
	[ 'credentialSource', EXPLICIT_FILTER_OPERATORS ],
	[ 'selectedProvider', EXPLICIT_FILTER_OPERATORS ],
	[ 'userId', EXPLICIT_FILTER_OPERATORS ],
	[ 'operationType', EXPLICIT_FILTER_OPERATORS ],
	[ 'entityId', TEXT_FILTER_OPERATORS ],
	[ 'blockPath', TEXT_FILTER_OPERATORS ],
	[ 'day', DAY_FILTER_OPERATORS ],
] );
const SORT_FIELDS = new Set( [
	'timestamp',
	'status',
	'surface',
	'postType',
	'userId',
	'operationType',
	'provider',
	'providerPath',
	'configurationOwner',
	'credentialSource',
	'selectedProvider',
] );

function isValidStoredFilter( filter ) {
	if ( ! isPlainObject( filter ) ) {
		return false;
	}

	if ( typeof filter.field !== 'string' ) {
		return false;
	}

	const allowedOperators = FILTER_FIELD_OPERATORS.get( filter.field );

	if ( ! allowedOperators ) {
		return false;
	}

	if ( typeof filter.operator !== 'string' ) {
		return false;
	}

	return allowedOperators.has( filter.operator );
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

function getAdminMetadata( entry ) {
	return isPlainObject( entry?.admin ) ? entry.admin : {};
}

function getAdminMetadataString( admin, key ) {
	const value = isPlainObject( admin ) ? admin[ key ] : undefined;

	return typeof value === 'string' && value.trim() ? value.trim() : '';
}

function getAdminString( entry, key ) {
	return getAdminMetadataString( getAdminMetadata( entry ), key );
}

function getAdminPositiveInteger( entry, key ) {
	const value = getAdminMetadata( entry )[ key ];
	const normalized = Number( value );

	return Number.isInteger( normalized ) && normalized > 0 ? normalized : null;
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
		return __( 'Block', 'flavor-agent' );
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
	const adminPostType = getAdminString( entry, 'postType' );
	const adminEntityId = getAdminString( entry, 'entityId' );
	const document = isPlainObject( entry?.document ) ? entry.document : {};
	const parsedScope = parseScopeKey( document.scopeKey || '' );

	return {
		postType: String(
			adminPostType || document.postType || parsedScope.postType || ''
		),
		entityId: String(
			adminEntityId || document.entityId || parsedScope.entityId || ''
		),
		scopeKey: String( document.scopeKey || '' ),
	};
}

function formatDocumentLabel( postType = '', entityId = '' ) {
	if ( ! postType && ! entityId ) {
		return '';
	}

	if ( postType === 'wp_template' ) {
		return sprintf(
			/* translators: %s: template identifier. */
			__( 'Template %s', 'flavor-agent' ),
			entityId || EMPTY_VALUE
		);
	}

	if ( postType === 'wp_template_part' ) {
		return sprintf(
			/* translators: %s: template part identifier. */
			__( 'Template part %s', 'flavor-agent' ),
			entityId || EMPTY_VALUE
		);
	}

	if ( postType === 'global_styles' ) {
		return sprintf(
			/* translators: %s: Global Styles entity identifier. */
			__( 'Global Styles %s', 'flavor-agent' ),
			entityId || EMPTY_VALUE
		);
	}

	if ( /^\d+$/.test( entityId ) ) {
		return sprintf(
			/* translators: 1: post type, 2: numeric post ID. */
			__( '%1$s #%2$s', 'flavor-agent' ),
			postType || __( 'Post', 'flavor-agent' ),
			entityId
		);
	}

	if ( entityId ) {
		return sprintf(
			/* translators: 1: document type, 2: document identifier. */
			__( '%1$s %2$s', 'flavor-agent' ),
			postType || __( 'Document', 'flavor-agent' ),
			entityId
		);
	}

	return postType || '';
}

function formatActivityTypeLabel( activityType = '' ) {
	switch ( activityType ) {
		case 'request_diagnostic':
			return __( 'Record request', 'flavor-agent' );
		case 'apply_suggestion':
			return __( 'Apply block suggestion', 'flavor-agent' );
		case 'apply_block_structural_suggestion':
			return __( 'Apply block structural suggestion', 'flavor-agent' );
		case 'apply_template_suggestion':
			return __( 'Apply template suggestion', 'flavor-agent' );
		case 'apply_template_part_suggestion':
			return __( 'Apply template-part suggestion', 'flavor-agent' );
		case 'apply_global_styles_suggestion':
			return __( 'Apply Global Styles suggestion', 'flavor-agent' );
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

function getPresetSlugFromOperation( operation = {} ) {
	if ( typeof operation?.presetSlug === 'string' && operation.presetSlug ) {
		return operation.presetSlug;
	}

	if ( typeof operation?.value === 'string' ) {
		const match = operation.value.match(
			/^var:preset\|[a-z0-9-]+\|([a-z0-9_-]+)$/i
		);

		if ( match?.[ 1 ] ) {
			return match[ 1 ];
		}
	}

	return '';
}

function formatStylePath( path = [] ) {
	return Array.isArray( path ) && path.length
		? path.map( String ).join( '.' )
		: __( 'style value', 'flavor-agent' );
}

function formatStyleValue( value ) {
	if ( value === undefined ) {
		return '';
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

function formatStyleOperationSummary( operation = {} ) {
	if ( operation?.type === 'set_theme_variation' ) {
		return sprintf(
			/* translators: %s: theme variation title. */
			__( 'Theme variation → %s', 'flavor-agent' ),
			operation.variationTitle ||
				operation.variation ||
				operation.slug ||
				''
		);
	}

	if (
		operation?.type === 'set_styles' ||
		operation?.type === 'set_block_styles'
	) {
		const proposedValue =
			getPresetSlugFromOperation( operation ) ||
			formatStyleValue( operation.value );

		return `${ formatStylePath( operation.path ) } → ${ proposedValue }`;
	}

	return summarizeOperation( operation );
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
	const adminOperationType = getAdminString( entry, 'operationType' );
	const adminOperationTypeLabel = getAdminString(
		entry,
		'operationTypeLabel'
	);

	if ( adminOperationType || adminOperationTypeLabel ) {
		return {
			value: adminOperationType || String( entry?.type || 'recorded' ),
			label:
				adminOperationTypeLabel ||
				humanizeValueLabel( adminOperationType ),
		};
	}

	if ( entry?.type === 'request_diagnostic' ) {
		return {
			value: 'request-diagnostic',
			label: __( 'Request diagnostic', 'flavor-agent' ),
		};
	}

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
					? __( 'Insert pattern', 'flavor-agent' )
					: __( 'Insert block', 'flavor-agent' ),
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
					? __( 'Replace template part', 'flavor-agent' )
					: __( 'Assign template part', 'flavor-agent' ),
		};
	}

	if ( hasStyleMutation( entry ) ) {
		return {
			value: 'apply-style',
			label: __( 'Apply style', 'flavor-agent' ),
		};
	}

	if ( entry?.surface === 'block' ) {
		return {
			value: 'modify-attributes',
			label: __( 'Modify attributes', 'flavor-agent' ),
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
	const adminBlockPath = getAdminString( entry, 'blockPath' );

	if ( adminBlockPath ) {
		return adminBlockPath;
	}

	const blockPath = formatBlockPath( entry?.target?.blockPath || [] );

	if ( ! blockPath ) {
		return EMPTY_VALUE;
	}

	const blockName = formatBlockName( entry?.target?.blockName || '' );

	return blockName ? `${ blockName } · ${ blockPath }` : blockPath;
}

function formatSurfaceLabel( surface = '' ) {
	switch ( surface ) {
		case 'content':
			return __( 'Content', 'flavor-agent' );
		case 'navigation':
			return __( 'Navigation', 'flavor-agent' );
		case 'pattern':
			return __( 'Pattern', 'flavor-agent' );
		case 'template':
			return __( 'Template', 'flavor-agent' );
		case 'template-part':
			return __( 'Template part', 'flavor-agent' );
		case 'global-styles':
			return __( 'Global Styles', 'flavor-agent' );
		case 'style-book':
			return __( 'Style Book', 'flavor-agent' );
		case 'block':
			return __( 'Block', 'flavor-agent' );
		default:
			return __( 'Activity', 'flavor-agent' );
	}
}

function summarizeActivityState( state ) {
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
			? sprintf(
					/* translators: %s: comma-separated attribute keys. */
					__( 'Attributes: %s', 'flavor-agent' ),
					attributeKeys.join( ', ' )
			  )
			: EMPTY_VALUE;
	}

	try {
		return JSON.stringify( state );
	} catch {
		return EMPTY_VALUE;
	}
}

function getRequestDiagnostics( request = {}, admin = {} ) {
	const provider =
		getAdminMetadataString( admin, 'provider' ) ||
		getFirstString( request, [
			[ 'ai', 'backendLabel' ],
			[ 'ai', 'providerLabel' ],
			[ 'provider' ],
			[ 'providerName' ],
			[ 'metadata', 'provider' ],
			[ 'ai', 'provider' ],
			[ 'result', 'provider' ],
		] );
	const model =
		getAdminMetadataString( admin, 'model' ) ||
		getFirstString( request, [
			[ 'ai', 'model' ],
			[ 'model' ],
			[ 'modelName' ],
			[ 'metadata', 'model' ],
			[ 'result', 'model' ],
		] );
	const providerPath =
		getAdminMetadataString( admin, 'providerPath' ) ||
		getFirstString( request, [ [ 'ai', 'pathLabel' ], [ 'pathLabel' ] ] );
	const configurationOwner =
		getAdminMetadataString( admin, 'configurationOwner' ) ||
		getFirstString( request, [ [ 'ai', 'ownerLabel' ], [ 'ownerLabel' ] ] );
	const credentialSource =
		getAdminMetadataString( admin, 'credentialSource' ) ||
		getFirstString( request, [
			[ 'ai', 'credentialSourceLabel' ],
			[ 'ai', 'credentialSource' ],
			[ 'credentialSourceLabel' ],
			[ 'credentialSource' ],
		] );
	const selectedProvider =
		getAdminMetadataString( admin, 'selectedProvider' ) ||
		getFirstString( request, [
			[ 'ai', 'selectedProviderLabel' ],
			[ 'ai', 'selectedProvider' ],
			[ 'selectedProviderLabel' ],
			[ 'selectedProvider' ],
		] );
	const connector = getFirstString( request, [
		[ 'ai', 'connectorLabel' ],
		[ 'ai', 'connectorId' ],
		[ 'connectorLabel' ],
		[ 'connectorId' ],
	] );
	const connectorPlugin = getFirstString( request, [
		[ 'ai', 'connectorPluginSlug' ],
		[ 'connectorPluginSlug' ],
	] );
	const requestAbility =
		getAdminMetadataString( admin, 'requestAbility' ) ||
		getFirstString( request, [ [ 'ai', 'ability' ], [ 'ability' ] ] );
	const requestRoute =
		getAdminMetadataString( admin, 'requestRoute' ) ||
		getFirstString( request, [ [ 'ai', 'route' ], [ 'route' ] ] );
	const fallbackValue =
		readPath( request, [ 'ai', 'usedFallback' ] ) ?? request?.usedFallback;
	const hasFallbackSignal = typeof fallbackValue === 'boolean';
	const usedFallback = Boolean( fallbackValue );
	const totalTokens = getFirstNumber( request, [
		[ 'ai', 'tokenUsage', 'total' ],
		[ 'tokenUsage', 'total' ],
		[ 'usage', 'total_tokens' ],
		[ 'usage', 'totalTokens' ],
		[ 'metadata', 'tokenUsage', 'total' ],
		[ 'totalTokens' ],
	] );
	const inputTokens = getFirstNumber( request, [
		[ 'ai', 'tokenUsage', 'input' ],
		[ 'tokenUsage', 'input' ],
		[ 'usage', 'input_tokens' ],
		[ 'usage', 'inputTokens' ],
	] );
	const outputTokens = getFirstNumber( request, [
		[ 'ai', 'tokenUsage', 'output' ],
		[ 'tokenUsage', 'output' ],
		[ 'usage', 'output_tokens' ],
		[ 'usage', 'outputTokens' ],
	] );
	const latencyMs = getFirstNumber( request, [
		[ 'ai', 'latencyMs' ],
		[ 'latencyMs' ],
		[ 'durationMs' ],
		[ 'timing', 'latencyMs' ],
		[ 'metadata', 'latencyMs' ],
	] );
	const endpointHost = getFirstString( request, [
		[ 'ai', 'transport', 'host' ],
		[ 'transport', 'host' ],
	] );
	const endpointPath = getFirstString( request, [
		[ 'ai', 'transport', 'path' ],
		[ 'transport', 'path' ],
	] );
	const timeoutSeconds = getFirstNumber( request, [
		[ 'ai', 'transport', 'timeoutSeconds' ],
		[ 'transport', 'timeoutSeconds' ],
	] );
	const requestBodyBytes = getFirstNumber( request, [
		[ 'ai', 'requestSummary', 'bodyBytes' ],
		[ 'requestSummary', 'bodyBytes' ],
	] );
	const instructionsChars = getFirstNumber( request, [
		[ 'ai', 'requestSummary', 'instructionsChars' ],
		[ 'requestSummary', 'instructionsChars' ],
	] );
	const requestInputChars = getFirstNumber( request, [
		[ 'ai', 'requestSummary', 'inputChars' ],
		[ 'requestSummary', 'inputChars' ],
	] );
	const maxOutputTokens = getFirstNumber( request, [
		[ 'ai', 'requestSummary', 'maxOutputTokens' ],
		[ 'requestSummary', 'maxOutputTokens' ],
	] );
	const reasoningEffort = getFirstString( request, [
		[ 'ai', 'requestSummary', 'reasoningEffort' ],
		[ 'requestSummary', 'reasoningEffort' ],
	] );
	const httpStatus = getFirstNumber( request, [
		[ 'ai', 'responseSummary', 'httpStatus' ],
		[ 'responseSummary', 'httpStatus' ],
	] );
	const responseBodyBytes = getFirstNumber( request, [
		[ 'ai', 'responseSummary', 'bodyBytes' ],
		[ 'responseSummary', 'bodyBytes' ],
	] );
	const processingMs = getFirstNumber( request, [
		[ 'ai', 'responseSummary', 'processingMs' ],
		[ 'responseSummary', 'processingMs' ],
	] );
	const retryAfter = getFirstNumber( request, [
		[ 'ai', 'responseSummary', 'retryAfter' ],
		[ 'responseSummary', 'retryAfter' ],
	] );
	const responseRegion = getFirstString( request, [
		[ 'ai', 'responseSummary', 'region' ],
		[ 'responseSummary', 'region' ],
	] );
	const providerRequestId = getFirstString( request, [
		[ 'ai', 'responseSummary', 'providerRequestId' ],
		[ 'responseSummary', 'providerRequestId' ],
	] );
	const transportError = getFirstString( request, [
		[ 'ai', 'errorSummary', 'wrappedMessage' ],
		[ 'errorSummary', 'wrappedMessage' ],
	] );

	let tokenUsageLabel = EMPTY_VALUE;
	let transportEndpoint = EMPTY_VALUE;
	let timeoutLabel = EMPTY_VALUE;
	let requestPayloadLabel = EMPTY_VALUE;
	let responseLabel = EMPTY_VALUE;

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

	if ( endpointHost ) {
		transportEndpoint = `${ endpointHost }${ endpointPath || '' }`;
	}

	if ( timeoutSeconds !== null ) {
		timeoutLabel = `${ timeoutSeconds } s`;
	}

	requestPayloadLabel =
		[
			requestBodyBytes !== null ? `${ requestBodyBytes } bytes` : null,
			instructionsChars !== null
				? `${ instructionsChars } instruction chars`
				: null,
			requestInputChars !== null
				? `${ requestInputChars } input chars`
				: null,
			maxOutputTokens !== null
				? `${ maxOutputTokens } max output tokens`
				: null,
			reasoningEffort ? `reasoning ${ reasoningEffort }` : null,
		]
			.filter( Boolean )
			.join( ' · ' ) || EMPTY_VALUE;

	responseLabel =
		[
			httpStatus !== null ? `HTTP ${ httpStatus }` : null,
			responseBodyBytes !== null ? `${ responseBodyBytes } bytes` : null,
			processingMs !== null ? `${ processingMs } ms processing` : null,
			retryAfter !== null ? `${ retryAfter } s retry-after` : null,
			responseRegion ? `region ${ responseRegion }` : null,
		]
			.filter( Boolean )
			.join( ' · ' ) || EMPTY_VALUE;

	return {
		provider: provider || EMPTY_VALUE,
		model: model || EMPTY_VALUE,
		providerPath: providerPath || EMPTY_VALUE,
		configurationOwner: configurationOwner || EMPTY_VALUE,
		credentialSource: credentialSource || EMPTY_VALUE,
		selectedProvider: selectedProvider || EMPTY_VALUE,
		connector: connector || EMPTY_VALUE,
		connectorPlugin: connectorPlugin || EMPTY_VALUE,
		requestAbility: requestAbility || EMPTY_VALUE,
		requestRoute: requestRoute || EMPTY_VALUE,
		hasFallbackSignal,
		usedFallback,
		tokenUsageLabel,
		latencyLabel: latencyMs !== null ? `${ latencyMs } ms` : EMPTY_VALUE,
		transportEndpoint,
		timeoutLabel,
		requestPayloadLabel,
		responseLabel,
		providerRequestId: providerRequestId || EMPTY_VALUE,
		transportError: transportError || EMPTY_VALUE,
	};
}

function getAiRequestLogMetadata(
	request = {},
	admin = {},
	adminBaseUrl = ''
) {
	const requestToken =
		getAdminMetadataString( admin, 'aiRequestToken' ) ||
		getAdminMetadataString( admin, 'requestToken' ) ||
		getFirstString( request, [
			[ 'ai', 'requestToken' ],
			[ 'requestToken' ],
		] );
	const requestLogId =
		getAdminMetadataString( admin, 'aiRequestLogId' ) ||
		getAdminMetadataString( admin, 'requestLogId' ) ||
		getFirstString( request, [
			[ 'ai', 'requestLogId' ],
			[ 'requestLogId' ],
		] );

	return {
		aiRequestToken: requestToken,
		aiRequestLogId: requestLogId,
		aiRequestLogsUrl: buildAdminUrl( adminBaseUrl, 'tools.php', {
			page: 'ai-request-logs',
		} ),
	};
}

function getUndoReason( status, resolvedUndo = null, entry = null ) {
	if (
		entry?.apply &&
		[ 'pending', 'rejected', 'expired' ].includes( status )
	) {
		return __( 'No mutation has been applied.', 'flavor-agent' );
	}

	if ( entry?.apply && status === 'failed' ) {
		return (
			entry.apply.failureMessage ||
			entry.apply.failureCode ||
			__( 'The apply did not mutate the site.', 'flavor-agent' )
		);
	}

	if ( status === 'applied' && resolvedUndo?.status === 'available' ) {
		return __(
			'This is the newest still-applied AI action for this entity.',
			'flavor-agent'
		);
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
		return __( 'Undo already completed.', 'flavor-agent' );
	}

	if ( status === 'failed' ) {
		return __( 'Undo is unavailable.', 'flavor-agent' );
	}

	return EMPTY_VALUE;
}

function getUndoStatusLabel( status, resolvedUndo = null, entry = null ) {
	if (
		entry?.apply &&
		[ 'pending', 'rejected', 'expired', 'failed' ].includes( status )
	) {
		return __( 'Undo not applicable', 'flavor-agent' );
	}

	if ( status === 'applied' && resolvedUndo?.status === 'available' ) {
		return __( 'Undo available', 'flavor-agent' );
	}

	return getActivityStatusLabel( status );
}

function getActivityTitle( entry ) {
	if ( typeof entry?.suggestion === 'string' && entry.suggestion.trim() ) {
		return truncateActivityTitle( entry.suggestion );
	}

	const operationType = deriveOperationType( entry );

	if ( operationType.label && operationType.label !== EMPTY_VALUE ) {
		return operationType.label;
	}

	if ( entry?.surface === 'template' ) {
		return __( 'Template suggestion applied', 'flavor-agent' );
	}

	if ( entry?.surface === 'template-part' ) {
		return __( 'Template-part suggestion applied', 'flavor-agent' );
	}

	if ( entry?.surface === 'global-styles' ) {
		return __( 'Global Styles suggestion applied', 'flavor-agent' );
	}

	if ( entry?.surface === 'style-book' ) {
		return __( 'Style Book suggestion applied', 'flavor-agent' );
	}

	if ( entry?.target?.blockName ) {
		return sprintf(
			/* translators: %s: block name. */
			__( '%s suggestion applied', 'flavor-agent' ),
			formatBlockName( entry.target.blockName )
		);
	}

	return __( 'AI action recorded', 'flavor-agent' );
}

function getActivityEntityLabel( entry ) {
	if ( entry?.surface === 'template' ) {
		return sprintf(
			/* translators: %s: template identifier. */
			__( 'Template %s', 'flavor-agent' ),
			entry?.target?.templateRef || EMPTY_VALUE
		);
	}

	if ( entry?.surface === 'content' ) {
		return __( 'Content', 'flavor-agent' );
	}

	if ( entry?.surface === 'navigation' ) {
		return __( 'Navigation', 'flavor-agent' );
	}

	if ( entry?.surface === 'pattern' ) {
		return __( 'Pattern', 'flavor-agent' );
	}

	if ( entry?.surface === 'template-part' ) {
		return sprintf(
			/* translators: %s: template part identifier. */
			__( 'Template part %s', 'flavor-agent' ),
			entry?.target?.templatePartRef || EMPTY_VALUE
		);
	}

	if ( entry?.surface === 'global-styles' ) {
		return sprintf(
			/* translators: %s: Global Styles entity identifier. */
			__( 'Global Styles %s', 'flavor-agent' ),
			entry?.target?.globalStylesId || EMPTY_VALUE
		);
	}

	if ( entry?.surface === 'style-book' ) {
		const blockTitle =
			String( entry?.target?.blockTitle || '' ) || EMPTY_VALUE;

		return sprintf(
			/* translators: %s: block title. */
			__( 'Style Book %s', 'flavor-agent' ),
			blockTitle
		);
	}

	if ( entry?.target?.blockName ) {
		return sprintf(
			/* translators: %s: block name. */
			__( '%s block', 'flavor-agent' ),
			formatBlockName( entry.target.blockName )
		);
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

function getActivityStatus( entry, allEntries = [] ) {
	const explicitStatus =
		typeof entry?.status === 'string' ? entry.status.trim() : '';

	if (
		[
			'applied',
			'review',
			'undone',
			'blocked',
			'failed',
			'pending',
			'rejected',
			'expired',
		].includes( explicitStatus )
	) {
		return explicitStatus;
	}

	const adminStatus = getAdminString( entry, 'status' );

	if (
		[
			'applied',
			'review',
			'undone',
			'blocked',
			'failed',
			'pending',
			'rejected',
			'expired',
		].includes( adminStatus )
	) {
		return adminStatus;
	}

	if (
		entry?.type === 'request_diagnostic' ||
		entry?.executionResult === 'review' ||
		entry?.undo?.status === 'review'
	) {
		if ( entry?.undo?.status === 'failed' ) {
			return 'failed';
		}

		return 'review';
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
	const adminStatusLabel =
		typeof entry === 'object' ? getAdminString( entry, 'statusLabel' ) : '';
	const isFailedRequestDiagnostic =
		typeof entry === 'object' &&
		entry?.type === 'request_diagnostic' &&
		status === 'failed';
	const isFailedExternalApply =
		typeof entry === 'object' &&
		status === 'failed' &&
		Boolean( entry?.apply );

	if (
		adminStatusLabel &&
		! isFailedRequestDiagnostic &&
		! isFailedExternalApply
	) {
		return adminStatusLabel;
	}

	switch ( status ) {
		case 'review':
			return __( 'Review', 'flavor-agent' );
		case 'failed':
			if ( isFailedRequestDiagnostic ) {
				return __( 'Request failed', 'flavor-agent' );
			}

			if ( isFailedExternalApply ) {
				return __( 'Apply failed', 'flavor-agent' );
			}

			return __( 'Undo unavailable', 'flavor-agent' );
		case 'undone':
			return __( 'Undone', 'flavor-agent' );
		case 'blocked':
			return __( 'Undo blocked', 'flavor-agent' );
		case 'pending':
			return __( 'Pending approval', 'flavor-agent' );
		case 'rejected':
			return __( 'Rejected', 'flavor-agent' );
		case 'expired':
			return __( 'Expired', 'flavor-agent' );
		default:
			return __( 'Applied', 'flavor-agent' );
	}
}

export function isPendingExternalApply( entry ) {
	return (
		entry?.status === 'pending' &&
		Boolean( entry?.apply ) &&
		typeof entry.apply === 'object'
	);
}

export function getExternalApplyDetails( entry ) {
	const apply =
		entry?.apply && typeof entry.apply === 'object' ? entry.apply : {};

	return {
		status: typeof apply.status === 'string' ? apply.status : '',
		requestedBy: Number.isFinite( Number( apply.requestedBy ) )
			? Number( apply.requestedBy )
			: 0,
		requestedAt:
			typeof apply.requestedAt === 'string' ? apply.requestedAt : '',
		expiresAt: typeof apply.expiresAt === 'string' ? apply.expiresAt : '',
		decidedBy: Number.isFinite( Number( apply.decidedBy ) )
			? Number( apply.decidedBy )
			: 0,
		decidedAt: typeof apply.decidedAt === 'string' ? apply.decidedAt : '',
		decisionNote:
			typeof apply.decisionNote === 'string' ? apply.decisionNote : '',
		failureCode:
			typeof apply.failureCode === 'string' ? apply.failureCode : '',
		failureMessage:
			typeof apply.failureMessage === 'string'
				? apply.failureMessage
				: '',
		executedAt:
			typeof apply.executedAt === 'string' ? apply.executedAt : '',
		operations: Array.isArray( apply.operations ) ? apply.operations : [],
		requestReference:
			typeof apply.requestReference === 'string'
				? apply.requestReference
				: '',
		signatures: isPlainObject( apply.signatures ) ? apply.signatures : {},
	};
}

function getGovernanceLifecycleStatus( entry, details ) {
	const entryStatus = typeof entry?.status === 'string' ? entry.status : '';
	const applyStatus =
		typeof details?.status === 'string' ? details.status : '';

	if (
		[ 'pending', 'rejected', 'expired', 'failed' ].includes( entryStatus )
	) {
		return entryStatus;
	}

	if ( entryStatus === 'undone' || entry?.undo?.status === 'undone' ) {
		return 'undone';
	}

	if ( entryStatus === 'blocked' || entry?.undo?.status === 'blocked' ) {
		return 'blocked';
	}

	if ( applyStatus === 'available' || entryStatus === 'applied' ) {
		return 'available';
	}

	return applyStatus || entryStatus || '';
}

function getGovernanceLifecycleLabel( status ) {
	switch ( status ) {
		case 'pending':
			return __( 'Approval required', 'flavor-agent' );
		case 'rejected':
			return __( 'Rejected', 'flavor-agent' );
		case 'expired':
			return __( 'Expired', 'flavor-agent' );
		case 'failed':
			return __( 'Apply failed', 'flavor-agent' );
		case 'undone':
			return __( 'Undone', 'flavor-agent' );
		case 'blocked':
			return __( 'Undo blocked', 'flavor-agent' );
		case 'available':
			return __( 'Applied', 'flavor-agent' );
		default:
			return __( 'External apply', 'flavor-agent' );
	}
}

function formatUserIdLabel( userId ) {
	return userId
		? sprintf(
				/* translators: %s: user ID. */
				__( 'User #%s', 'flavor-agent' ),
				userId
		  )
		: EMPTY_VALUE;
}

function getStyleConfigSnapshot( state = {} ) {
	if ( isPlainObject( state?.userConfig ) ) {
		return state.userConfig;
	}

	if ( isPlainObject( state?.currentConfig ) ) {
		return state.currentConfig;
	}

	if ( isPlainObject( state?.styleContext?.currentConfig ) ) {
		return state.styleContext.currentConfig;
	}

	if ( isPlainObject( state?.baselineConfig ) ) {
		return state.baselineConfig;
	}

	if ( isPlainObject( state ) ) {
		return state;
	}

	return {};
}

function getStyleRootForOperation( state = {}, operation = {}, entry = {} ) {
	const config = getStyleConfigSnapshot( state );
	const styles = isPlainObject( config.styles ) ? config.styles : config;

	if ( operation?.type !== 'set_block_styles' ) {
		return styles;
	}

	const blockName =
		typeof operation?.blockName === 'string' && operation.blockName
			? operation.blockName
			: entry?.target?.blockName || '';
	const blocks = isPlainObject( styles.blocks ) ? styles.blocks : {};

	return isPlainObject( blocks[ blockName ] ) ? blocks[ blockName ] : {};
}

function getPendingBaselineState( entry = {} ) {
	if (
		isPlainObject( entry?.before ) &&
		Object.keys( entry.before ).length
	) {
		return entry.before;
	}

	if ( isPlainObject( entry?.apply?.baselineConfig ) ) {
		return { userConfig: entry.apply.baselineConfig };
	}

	if ( isPlainObject( entry?.request?.apply?.baselineConfig ) ) {
		return { userConfig: entry.request.apply.baselineConfig };
	}

	return {};
}

function getOperationsForGovernance( entry = {} ) {
	const applyOperations = Array.isArray( entry?.apply?.operations )
		? entry.apply.operations
		: [];

	if ( applyOperations.length ) {
		return applyOperations;
	}

	return Array.isArray( entry?.after?.operations )
		? entry.after.operations
		: [];
}

function getOperationProposedValue( operation = {} ) {
	if ( operation?.type === 'set_theme_variation' ) {
		return (
			operation.variationTitle ||
			operation.variation ||
			operation.slug ||
			EMPTY_VALUE
		);
	}

	return (
		getPresetSlugFromOperation( operation ) ||
		formatStyleValue( operation.value ) ||
		EMPTY_VALUE
	);
}

function getUnknownOperationSummary( operation = {} ) {
	try {
		return JSON.stringify( operation );
	} catch {
		return summarizeOperation( operation );
	}
}

function getComparisonStatus( lifecycleStatus, before, after ) {
	if (
		[ 'pending', 'rejected', 'expired', 'failed' ].includes(
			lifecycleStatus
		)
	) {
		return 'proposed';
	}

	return before === after ? 'unchanged' : 'changed';
}

export function getStyleComparisonRows( entry = {} ) {
	if (
		! [ 'global-styles', 'style-book' ].includes( entry?.surface ) &&
		! [
			'apply_global_styles_suggestion',
			'apply_style_book_suggestion',
		].includes( entry?.type )
	) {
		return [];
	}

	const details = getExternalApplyDetails( entry );
	const lifecycleStatus = getGovernanceLifecycleStatus( entry, details );
	const operations = getOperationsForGovernance( entry );
	const hasExecutedMutation = ! [
		'pending',
		'rejected',
		'expired',
		'failed',
	].includes( lifecycleStatus );
	const beforeState =
		lifecycleStatus === 'pending'
			? getPendingBaselineState( entry )
			: entry?.before || {};
	const afterState = hasExecutedMutation ? entry?.after || {} : {};

	return operations.map( ( operation ) => {
		if (
			operation?.type !== 'set_styles' &&
			operation?.type !== 'set_block_styles' &&
			operation?.type !== 'set_theme_variation'
		) {
			return {
				label: humanizeValueLabel( operation?.type || 'operation' ),
				before: EMPTY_VALUE,
				proposed: getUnknownOperationSummary( operation ),
				after: hasExecutedMutation
					? EMPTY_VALUE
					: __( 'Not applied', 'flavor-agent' ),
				status: 'unsupported',
			};
		}

		if ( operation.type === 'set_theme_variation' ) {
			return {
				label: __( 'Theme variation', 'flavor-agent' ),
				before: EMPTY_VALUE,
				proposed: getOperationProposedValue( operation ),
				after: hasExecutedMutation
					? getOperationProposedValue( operation )
					: __( 'Not applied', 'flavor-agent' ),
				status: getComparisonStatus(
					lifecycleStatus,
					EMPTY_VALUE,
					getOperationProposedValue( operation )
				),
			};
		}

		const beforeRoot = getStyleRootForOperation(
			beforeState,
			operation,
			entry
		);
		const afterRoot = getStyleRootForOperation(
			afterState,
			operation,
			entry
		);
		const beforeValue = readPath( beforeRoot, operation.path || [] );
		const afterValue = hasExecutedMutation
			? readPath( afterRoot, operation.path || [] )
			: undefined;
		let after;

		if ( afterValue !== undefined ) {
			after = formatStyleValue( afterValue );
		} else if ( hasExecutedMutation ) {
			after = __( 'After unavailable', 'flavor-agent' );
		} else {
			after = __( 'Not applied', 'flavor-agent' );
		}

		const blockTitle =
			operation.type === 'set_block_styles'
				? entry?.target?.blockTitle ||
				  formatBlockName( entry?.target?.blockName )
				: '';
		const label = [ blockTitle, formatStylePath( operation.path ) ]
			.filter( Boolean )
			.join( ' ' );

		return {
			label,
			before:
				beforeValue === undefined
					? __( 'Baseline unavailable', 'flavor-agent' )
					: formatStyleValue( beforeValue ),
			proposed: getOperationProposedValue( operation ),
			after,
			status: getComparisonStatus(
				lifecycleStatus,
				formatStyleValue( beforeValue ),
				formatStyleValue( afterValue )
			),
		};
	} );
}

function getGovernanceTargetLabel( entry ) {
	if ( entry?.surface === 'style-book' ) {
		const blockTitle =
			entry?.target?.blockTitle ||
			formatBlockName( entry?.target?.blockName );
		const globalStylesId =
			entry?.target?.globalStylesId ||
			getDocumentContext( entry ).entityId;

		return blockTitle
			? sprintf(
					/* translators: 1: block title, 2: global styles ID. */
					__(
						'Style Book %1$s · Global Styles %2$s',
						'flavor-agent'
					),
					blockTitle,
					globalStylesId || EMPTY_VALUE
			  )
			: __( 'Style Book', 'flavor-agent' );
	}

	const globalStylesId =
		entry?.target?.globalStylesId || getDocumentContext( entry ).entityId;

	return sprintf(
		/* translators: %s: Global Styles ID. */
		__( 'Global Styles %s', 'flavor-agent' ),
		globalStylesId || EMPTY_VALUE
	);
}

function getGovernanceDiagnosticText( entry, details ) {
	const signatures = details.signatures || {};
	const rows = [
		[ 'activityId', entry?.id || '' ],
		[ 'requestReference', details.requestReference ],
		[ 'resolvedContextSignature', signatures.resolvedContextSignature ],
		[ 'reviewContextSignature', signatures.reviewContextSignature ],
		[ 'baselineConfigHash', signatures.baselineConfigHash ],
	].filter( ( [ , value ] ) => value );

	return rows
		.map( ( [ label, value ] ) => `${ label }: ${ value }` )
		.join( '\n' );
}

export function getGovernanceDetails( entry = {} ) {
	if ( ! entry?.apply ) {
		return null;
	}

	const details = getExternalApplyDetails( entry );
	const lifecycleStatus = getGovernanceLifecycleStatus( entry, details );
	const signatures = details.signatures || {};
	const comparisonRows = getStyleComparisonRows( entry );
	const proposedOperations = details.operations.map(
		formatStyleOperationSummary
	);
	const executedOperations = Array.isArray( entry?.after?.operations )
		? entry.after.operations.map( formatStyleOperationSummary )
		: [];
	const undoStatus =
		typeof entry?.undo?.status === 'string' ? entry.undo.status : '';
	const undoReason =
		typeof entry?.undo?.error === 'string' && entry.undo.error.trim()
			? entry.undo.error.trim()
			: getUndoReason( getActivityStatus( entry ), null, entry );

	return {
		status: lifecycleStatus,
		statusLabel: getActivityStatusLabel( entry ),
		lifecycleLabel: getGovernanceLifecycleLabel( lifecycleStatus ),
		activityId: entry?.id || '',
		targetLabel: getGovernanceTargetLabel( entry ),
		surfaceLabel: formatSurfaceLabel( entry?.surface ),
		requestedBy: details.requestedBy,
		requestedByLabel: formatUserIdLabel( details.requestedBy ),
		requestedAt: details.requestedAt,
		expiresAt: details.expiresAt,
		requestReference: details.requestReference,
		decidedBy: details.decidedBy,
		decidedByLabel: formatUserIdLabel( details.decidedBy ),
		decidedAt: details.decidedAt,
		decisionNote: details.decisionNote,
		executedAt: details.executedAt,
		failureCode: details.failureCode,
		failureMessage: details.failureMessage,
		hasResolvedSignature: Boolean( signatures.resolvedContextSignature ),
		hasReviewSignature: Boolean( signatures.reviewContextSignature ),
		hasBaselineHash: Boolean( signatures.baselineConfigHash ),
		proposedOperations,
		executedOperations,
		comparisonRows,
		undoStatus,
		canUndo: Boolean( entry?.undo?.canUndo ),
		undoReason,
		diagnosticText: getGovernanceDiagnosticText( entry, details ),
	};
}

/**
 * Derives a short, plain-language "What happened" summary from governance
 * details so a non-technical reviewer can read the outcome at a glance.
 *
 * This is a presentation-only derivation over fields already produced by
 * getGovernanceDetails(); it reads no new data and changes no lifecycle.
 *
 * @param {Object}   details         Output of getGovernanceDetails().
 * @param {Function} formatTimestamp Optional formatter for ISO timestamps.
 * @return {Array<{label: string, value: string}>} Plain-language rows.
 */
export function getGovernancePlainSummary(
	details,
	formatTimestamp = ( value ) => value
) {
	if ( ! details ) {
		return [];
	}

	const opsCount =
		details.executedOperations.length ||
		details.proposedOperations.length ||
		details.comparisonRows.length ||
		0;
	const applied = details.executedOperations.length > 0;
	const target = details.targetLabel;

	let whatChanged;
	if ( opsCount > 0 && applied ) {
		whatChanged = target
			? sprintf(
					/* translators: 1: number of changes, 2: change target, e.g. "Global Styles 17". */
					_n(
						'%1$d change applied to %2$s',
						'%1$d changes applied to %2$s',
						opsCount,
						'flavor-agent'
					),
					opsCount,
					target
			  )
			: sprintf(
					/* translators: %d: number of changes. */
					_n(
						'%d change applied',
						'%d changes applied',
						opsCount,
						'flavor-agent'
					),
					opsCount
			  );
	} else if ( opsCount > 0 ) {
		whatChanged = target
			? sprintf(
					/* translators: 1: number of changes, 2: change target, e.g. "Global Styles 17". */
					_n(
						'%1$d change proposed to %2$s',
						'%1$d changes proposed to %2$s',
						opsCount,
						'flavor-agent'
					),
					opsCount,
					target
			  )
			: sprintf(
					/* translators: %d: number of changes. */
					_n(
						'%d change proposed',
						'%d changes proposed',
						opsCount,
						'flavor-agent'
					),
					opsCount
			  );
	} else {
		whatChanged = target || EMPTY_VALUE;
	}

	const requestedWho =
		details.requestedByLabel && details.requestedByLabel !== EMPTY_VALUE
			? details.requestedByLabel
			: '';
	const requestedWhen = details.requestedAt
		? formatTimestamp( details.requestedAt )
		: '';
	const requestedParts = [ requestedWho, requestedWhen ].filter( Boolean );
	const requested = requestedParts.length
		? requestedParts.join( ' · ' )
		: EMPTY_VALUE;

	let currentWhenApplied;
	switch ( details.status ) {
		case 'available':
			currentWhenApplied = __(
				'Yes — confirmed current when applied',
				'flavor-agent'
			);
			break;
		case 'undone':
			currentWhenApplied = __(
				'Yes — was current when applied, later undone',
				'flavor-agent'
			);
			break;
		case 'blocked':
			currentWhenApplied = __(
				'Yes — was current when applied',
				'flavor-agent'
			);
			break;
		case 'pending':
			currentWhenApplied = details.expiresAt
				? sprintf(
						/* translators: %s: expiry timestamp. */
						__( 'Pending approval — expires %s', 'flavor-agent' ),
						formatTimestamp( details.expiresAt )
				  )
				: __( 'Pending approval', 'flavor-agent' );
			break;
		case 'expired':
			currentWhenApplied = __(
				'No — the request lapsed before approval',
				'flavor-agent'
			);
			break;
		case 'rejected':
			currentWhenApplied = __(
				'Not applied — rejected before approval',
				'flavor-agent'
			);
			break;
		case 'failed':
			currentWhenApplied = details.failureMessage
				? sprintf(
						/* translators: %s: failure reason. */
						__( 'No — apply blocked: %s', 'flavor-agent' ),
						details.failureMessage
				  )
				: __( 'No — apply did not complete', 'flavor-agent' );
			break;
		default:
			currentWhenApplied = details.executedAt
				? __( 'Yes — confirmed current when applied', 'flavor-agent' )
				: EMPTY_VALUE;
	}

	let reversible;
	if ( details.canUndo ) {
		reversible = __( 'Yes — this apply can be undone', 'flavor-agent' );
	} else if ( details.undoStatus === 'undone' ) {
		reversible = __( 'Already undone', 'flavor-agent' );
	} else if ( details.undoStatus === 'blocked' ) {
		reversible = details.undoReason
			? sprintf(
					/* translators: %s: reason the undo is blocked. */
					__( 'Undo blocked — %s', 'flavor-agent' ),
					details.undoReason
			  )
			: __( 'Undo blocked', 'flavor-agent' );
	} else if ( details.status === 'pending' ) {
		reversible = __( 'Not yet — awaiting approval', 'flavor-agent' );
	} else if (
		details.status === 'rejected' ||
		details.status === 'expired'
	) {
		reversible = __( 'Nothing to undo — never applied', 'flavor-agent' );
	} else if ( details.status === 'failed' ) {
		reversible = __(
			'Nothing to undo — apply did not complete',
			'flavor-agent'
		);
	} else {
		reversible =
			details.undoReason || __( 'Not reversible', 'flavor-agent' );
	}

	return [
		{ label: __( 'What changed', 'flavor-agent' ), value: whatChanged },
		{ label: __( 'Requested', 'flavor-agent' ), value: requested },
		{
			label: __( 'Current when applied', 'flavor-agent' ),
			value: currentWhenApplied,
		},
		{ label: __( 'Reversible', 'flavor-agent' ), value: reversible },
	];
}

export function buildDecisionRequest( bootData, activityId, decision, note ) {
	return {
		url: `${
			bootData?.restUrl || ''
		}flavor-agent/v1/activity/${ encodeURIComponent(
			activityId
		) }/decision`,
		method: 'POST',
		headers: { 'X-WP-Nonce': bootData?.nonce || '' },
		data: {
			decision,
			note: typeof note === 'string' ? note : '',
		},
	};
}

export function buildActivityTargetLink( entry, adminBaseUrl = '' ) {
	const { postType, entityId } = getDocumentContext( entry );

	if ( postType === 'wp_template' || entry?.surface === 'template' ) {
		return {
			url: buildAdminUrl( adminBaseUrl, 'site-editor.php', {
				postType: 'wp_template',
				postId: entry?.target?.templateRef || entityId,
			} ),
			label: __( 'Open template', 'flavor-agent' ),
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
			label: __( 'Open template part', 'flavor-agent' ),
		};
	}

	if ( postType === 'global_styles' || entry?.surface === 'global-styles' ) {
		return {
			url: buildAdminUrl( adminBaseUrl, 'site-editor.php', {
				canvas: 'edit',
				path: '/wp_global_styles',
			} ),
			label: __( 'Open Styles', 'flavor-agent' ),
		};
	}

	if ( entry?.surface === 'style-book' ) {
		return {
			url: buildAdminUrl( adminBaseUrl, 'site-editor.php', {
				canvas: 'edit',
				path: '/wp_global_styles',
			} ),
			label: __( 'Open Styles', 'flavor-agent' ),
		};
	}

	if ( /^\d+$/.test( entityId ) ) {
		return {
			url: buildAdminUrl( adminBaseUrl, 'post.php', {
				post: entityId,
				action: 'edit',
			} ),
			label: __( 'Open post', 'flavor-agent' ),
		};
	}

	return {
		url: '',
		label: __( 'Not available', 'flavor-agent' ),
	};
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
					typeof view.sort.field === 'string' &&
					SORT_FIELDS.has( view.sort.field )
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
		filters: Array.isArray( view.filters )
			? view.filters.filter( isValidStoredFilter )
			: [],
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

	// Pagination metadata not loaded yet (or response held no entries). Leave the
	// page untouched so a persisted page > 1 isn't reset to 1 before the first
	// REST response arrives — the clamp re-runs once real totalPages is known.
	if ( totalPages <= 0 ) {
		return normalizedView;
	}

	const nextPage = Math.min( normalizedView.page, totalPages );

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

const MODEL_REQUEST_REASONS = new Set( [
	'no_rankable_candidates',
	'missing_visible_patterns',
] );

function normalizeModelRequestMarker( entry ) {
	const marker =
		entry?.after?.modelRequest ||
		entry?.response?.diagnostics?.modelRequest;

	if (
		! marker ||
		marker.attempted !== false ||
		! MODEL_REQUEST_REASONS.has( marker.reason )
	) {
		return null;
	}

	return {
		attempted: false,
		reason: marker.reason,
	};
}

function normalizeActivityEntry(
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
	const request = isPlainObject( entry?.request ) ? entry.request : {};
	const admin = getAdminMetadata( entry );
	const { postType, entityId } = getDocumentContext( entry );
	const diagnostics = getRequestDiagnostics( request, admin );
	const aiRequestLog = getAiRequestLogMetadata(
		request,
		admin,
		adminBaseUrl
	);
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
	let undoError = EMPTY_VALUE;
	let userLabel = EMPTY_VALUE;
	const adminUserId = getAdminPositiveInteger( entry, 'userId' );
	const adminUserLabel = getAdminString( entry, 'userLabel' );
	const entryUserId =
		entry?.userId || entry?.userId === 0
			? String( entry.userId )
			: EMPTY_VALUE;
	const userId = adminUserId !== null ? String( adminUserId ) : entryUserId;

	if ( adminUserLabel ) {
		userLabel = adminUserLabel;
	} else if (
		typeof entry?.userLabel === 'string' &&
		entry.userLabel.trim()
	) {
		userLabel = entry.userLabel.trim();
	} else if ( adminUserId || entry?.userId ) {
		userLabel = sprintf(
			/* translators: %s: user ID. */
			__( 'User #%s', 'flavor-agent' ),
			adminUserId || entry.userId
		);
	}

	if ( typeof entry?.undo?.error === 'string' && entry.undo.error.trim() ) {
		undoError = entry.undo.error.trim();
	} else if (
		status === 'blocked' &&
		typeof resolvedUndo?.error !== 'string'
	) {
		undoError = ORDERED_UNDO_BLOCKED_ERROR;
	}

	let requestFallback = EMPTY_VALUE;
	if ( diagnostics.hasFallbackSignal && ! diagnostics.usedFallback ) {
		requestFallback = __( 'No fallback', 'flavor-agent' );
	} else if (
		diagnostics.usedFallback &&
		diagnostics.selectedProvider !== EMPTY_VALUE
	) {
		requestFallback = sprintf(
			/* translators: %s: selected provider name. */
			__( 'Fallback from selected %s.', 'flavor-agent' ),
			diagnostics.selectedProvider
		);
	}

	const requestPromptValue =
		typeof request?.prompt === 'string' ? request.prompt.trim() : '';
	const requestReferenceValue =
		typeof request?.reference === 'string' ? request.reference.trim() : '';
	const requestPrompt =
		getAdminString( entry, 'requestPrompt' ) ||
		requestPromptValue ||
		EMPTY_VALUE;
	const requestReference =
		getAdminString( entry, 'requestReference' ) ||
		requestReferenceValue ||
		EMPTY_VALUE;
	const governanceDetails =
		entry?.apply && typeof entry.apply === 'object'
			? getGovernanceDetails( entry )
			: null;

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
		statusLabel: getActivityStatusLabel( entry, allEntries ),
		apply:
			entry?.apply && typeof entry.apply === 'object'
				? entry.apply
				: null,
		governanceDetails,
		surface:
			getAdminString( entry, 'surface' ) ||
			String( entry?.surface || '' ),
		surfaceLabel:
			getAdminString( entry, 'surfaceLabel' ) ||
			formatSurfaceLabel( entry?.surface ),
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
		userId,
		user: userLabel,
		entity: entityLabel,
		documentLabel: documentLabel || EMPTY_VALUE,
		requestPrompt,
		requestReference,
		providerPath: diagnostics.providerPath,
		configurationOwner: diagnostics.configurationOwner,
		credentialSource: diagnostics.credentialSource,
		selectedProvider: diagnostics.selectedProvider,
		connector: diagnostics.connector,
		connectorPlugin: diagnostics.connectorPlugin,
		requestAbility: diagnostics.requestAbility,
		requestRoute: diagnostics.requestRoute,
		transportEndpoint: diagnostics.transportEndpoint,
		timeout: diagnostics.timeoutLabel,
		requestPayload: diagnostics.requestPayloadLabel,
		responseSummary: diagnostics.responseLabel,
		providerRequestId: diagnostics.providerRequestId,
		transportError: diagnostics.transportError,
		beforeSummary: summarizeActivityState( entry?.before ),
		afterSummary: summarizeActivityState( entry?.after ),
		stateDiff: buildStructuredStateDiff( entry?.before, entry?.after ),
		undoStatusLabel: getUndoStatusLabel( status, resolvedUndo, entry ),
		undoError,
		undoReason: getUndoReason( status, resolvedUndo, entry ),
		provider: diagnostics.provider,
		model: diagnostics.model,
		tokenUsage: diagnostics.tokenUsageLabel,
		latency: diagnostics.latencyLabel,
		aiRequestToken: aiRequestLog.aiRequestToken,
		aiRequestLogId: aiRequestLog.aiRequestLogId,
		aiRequestLogsUrl: aiRequestLog.aiRequestLogsUrl,
		modelRequest: normalizeModelRequestMarker( entry ),
		requestFallback,
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
