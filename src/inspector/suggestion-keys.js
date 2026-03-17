/**
 * @param {Object} suggestion Suggestion object.
 * @return {string} Normalized panel bucket for the suggestion.
 */
export function getSuggestionPanel( suggestion ) {
	return suggestion?.panel || 'general';
}

/**
 * @param {Object} suggestion Suggestion object.
 * @return {string} Stable UI key for applied-state tracking.
 */
export function getSuggestionKey( suggestion ) {
	return `${ getSuggestionPanel( suggestion ) }-${ suggestion?.label || '' }`;
}
