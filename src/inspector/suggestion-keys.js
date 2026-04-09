import { stableSerialize } from '../utils/structural-equality';

/**
 * @param {Object} suggestion Suggestion object.
 * @return {string} Normalized panel bucket for the suggestion.
 */
export function getSuggestionPanel( suggestion ) {
	return suggestion?.panel || 'general';
}

function normalizeKeyFragment( value ) {
	const normalized = String( value || '' )
		.trim()
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /(^-|-$)/g, '' );

	return normalized || 'suggestion';
}

function hashFingerprint( input ) {
	let hash = 5381;

	for ( let index = 0; index < input.length; index++ ) {
		// eslint-disable-next-line no-bitwise
		hash = ( ( hash << 5 ) + hash ) ^ input.charCodeAt( index );
	}

	// eslint-disable-next-line no-bitwise
	return ( hash >>> 0 ).toString( 36 );
}

/**
 * @param {Object} suggestion Suggestion object.
 * @return {string} Stable UI key for applied-state tracking.
 */
export function getSuggestionKey( suggestion ) {
	if (
		typeof suggestion?.suggestionKey === 'string' &&
		suggestion.suggestionKey
	) {
		return suggestion.suggestionKey;
	}

	const panel = getSuggestionPanel( suggestion );
	const label = normalizeKeyFragment(
		suggestion?.label || suggestion?.cssVar || suggestion?.type
	);
	const fingerprint = stableSerialize( {
		panel,
		type: suggestion?.type || '',
		label: suggestion?.label || '',
		description: suggestion?.description || '',
		preview: suggestion?.preview || '',
		cssVar: suggestion?.cssVar || '',
		category: suggestion?.category || '',
		tone: suggestion?.tone || '',
		attributeUpdates: suggestion?.attributeUpdates || null,
		currentValue: suggestion?.currentValue ?? null,
		suggestedValue: suggestion?.suggestedValue ?? null,
		operations: Array.isArray( suggestion?.operations )
			? suggestion.operations
			: [],
		isCurrentStyle: Boolean( suggestion?.isCurrentStyle ),
		isRecommended: Boolean( suggestion?.isRecommended ),
	} );

	return `${ panel }-${ label }-${ hashFingerprint( fingerprint ) }`;
}
