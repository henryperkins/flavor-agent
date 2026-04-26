import { __, sprintf } from '@wordpress/i18n';

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
	const blockPath = formatBlockPath( entry?.target?.blockPath || [] );

	if ( ! blockPath ) {
		return EMPTY_VALUE;
	}

	const blockName = formatBlockName( entry?.target?.blockName || '' );

	return blockName ? `${ blockName } · ${ blockPath }` : blockPath;
}

export function formatSurfaceLabel( surface = '' ) {
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

function getUndoReason( status, resolvedUndo = null, entry = null ) {
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

function getActivityTitle( entry ) {
	if ( typeof entry?.suggestion === 'string' && entry.suggestion.trim() ) {
		return entry.suggestion.trim();
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

export function getActivityStatus( entry, allEntries = [] ) {
	const explicitStatus =
		typeof entry?.status === 'string' ? entry.status.trim() : '';

	if (
		[ 'applied', 'review', 'undone', 'blocked', 'failed' ].includes(
			explicitStatus
		)
	) {
		return explicitStatus;
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
	const isFailedRequestDiagnostic =
		typeof entry === 'object' &&
		entry?.type === 'request_diagnostic' &&
		status === 'failed';

	switch ( status ) {
		case 'review':
			return __( 'Review', 'flavor-agent' );
		case 'failed':
			return isFailedRequestDiagnostic
				? __( 'Request failed', 'flavor-agent' )
				: __( 'Undo unavailable', 'flavor-agent' );
		case 'undone':
			return __( 'Undone', 'flavor-agent' );
		case 'blocked':
			return __( 'Undo blocked', 'flavor-agent' );
		default:
			return __( 'Applied', 'flavor-agent' );
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
	let undoError = EMPTY_VALUE;
	let userLabel = EMPTY_VALUE;

	if ( typeof entry?.userLabel === 'string' && entry.userLabel.trim() ) {
		userLabel = entry.userLabel.trim();
	} else if ( entry?.userId ) {
		userLabel = sprintf(
			/* translators: %s: user ID. */
			__( 'User #%s', 'flavor-agent' ),
			entry.userId
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
		undoStatusLabel:
			status === 'applied' && resolvedUndo?.status === 'available'
				? __( 'Undo available', 'flavor-agent' )
				: getActivityStatusLabel( status ),
		undoError,
		undoReason: getUndoReason( status, resolvedUndo, entry ),
		provider: diagnostics.provider,
		model: diagnostics.model,
		tokenUsage: diagnostics.tokenUsageLabel,
		latency: diagnostics.latencyLabel,
		requestFallback:
			diagnostics.usedFallback &&
			diagnostics.selectedProvider !== EMPTY_VALUE
				? sprintf(
						/* translators: %s: selected provider name. */
						__( 'Fallback from selected %s.', 'flavor-agent' ),
						diagnostics.selectedProvider
				  )
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
