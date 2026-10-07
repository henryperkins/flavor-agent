import { normalizeSourceRequestSignature } from './outcome-identity';

const MAX_STRING_LENGTH = 191;
const SURFACES = [
	'block',
	'template',
	'template-part',
	'global-styles',
	'style-book',
	'pattern',
	'navigation',
	'content',
	'post-blocks',
];

function cleanString( value, maxLength = MAX_STRING_LENGTH ) {
	if ( value === null || value === undefined ) {
		return '';
	}

	if (
		typeof value !== 'string' &&
		typeof value !== 'number' &&
		typeof value !== 'boolean'
	) {
		return '';
	}

	return String( value ).trim().slice( 0, maxLength );
}

export function validExplicitIdentity( value ) {
	return (
		typeof value === 'string' &&
		!! value &&
		value.length <= MAX_STRING_LENGTH &&
		cleanString( value ) === value
	);
}

export function normalizeShownSuggestionKeys( value ) {
	if (
		! Array.isArray( value ) ||
		value.length > 100 ||
		value.some( ( key ) => ! validExplicitIdentity( key ) )
	) {
		return [];
	}
	return [ ...new Set( value ) ];
}

/**
 * Names only the mounted suggestions supplied by an explicit action. No fallback identities.
 *
 * @param {Object}        root0                         Current visible recommendation context.
 * @param {string}        root0.surface                 Supported recommendation surface.
 * @param {Array<Object>} root0.suggestions             Visible canonical suggestions.
 * @param {string}        root0.currentRequestSignature Current context signature.
 * @param {boolean}       root0.isStale                 Whether the displayed context is stale.
 */
export function buildDismissalOutcomes( {
	surface,
	suggestions = [],
	currentRequestSignature = '',
	isStale = false,
} = {} ) {
	if (
		! SURFACES.includes( surface ) ||
		isStale ||
		! currentRequestSignature ||
		! Array.isArray( suggestions ) ||
		! suggestions.length ||
		suggestions.length > 100
	) {
		return [];
	}
	const signature = normalizeSourceRequestSignature(
		currentRequestSignature
	);
	const identities = suggestions.map(
		( suggestion ) => suggestion?.recommendationOutcome
	);
	const set = identities[ 0 ]?.recommendationSetId;
	if (
		! validExplicitIdentity( set ) ||
		identities.some(
			( identity ) =>
				! identity ||
				identity.recommendationSetId !== set ||
				! validExplicitIdentity( identity.suggestionKey ) ||
				identity.sourceRequestSignature !== signature
		)
	) {
		return [];
	}
	return normalizeShownSuggestionKeys(
		identities.map( ( identity ) => identity.suggestionKey )
	).map( ( suggestionKey ) => ( {
		event: 'dismissed',
		reason: 'user_dismissed',
		surface,
		recommendationSetId: set,
		suggestionKey,
		sourceRequestSignature: signature,
	} ) );
}
