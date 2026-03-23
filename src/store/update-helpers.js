const NESTED_MERGE_KEYS = new Set( [ 'metadata', 'style' ] );

/**
 * @param {unknown} value Candidate value.
 * @return {boolean} Whether the value is a plain object.
 */
function isPlainObject( value ) {
	return (
		value !== null && typeof value === 'object' && ! Array.isArray( value )
	);
}

/**
 * @param {Record<string, unknown>} currentValue Existing object value.
 * @param {Record<string, unknown>} nextValue    Incoming object value.
 * @return {Record<string, unknown>} Deeply merged object.
 */
function deepMergeObjects( currentValue, nextValue ) {
	if ( ! isPlainObject( currentValue ) || ! isPlainObject( nextValue ) ) {
		return nextValue;
	}

	const merged = { ...currentValue };

	for ( const [ key, value ] of Object.entries( nextValue ) ) {
		if ( isPlainObject( value ) && isPlainObject( currentValue[ key ] ) ) {
			merged[ key ] = deepMergeObjects( currentValue[ key ], value );
		} else {
			merged[ key ] = value;
		}
	}

	return merged;
}

/**
 * @param {Object} recommendations Raw recommendations payload.
 * @return {Object} Normalized recommendations groups.
 */
function normalizeSuggestionGroups( recommendations ) {
	return {
		settings: Array.isArray( recommendations?.settings )
			? recommendations.settings
			: [],
		styles: Array.isArray( recommendations?.styles )
			? recommendations.styles
			: [],
		block: Array.isArray( recommendations?.block )
			? recommendations.block
			: [],
		explanation: recommendations?.explanation || '',
	};
}

/**
 * @param {Object} blockContext Context stored with a block recommendation set.
 * @return {string[]} Content attribute names.
 */
function getContentAttributeKeys( blockContext ) {
	return Object.keys( blockContext?.contentAttributes || {} );
}

/**
 * Some contentOnly-compatible container blocks expose editable content only
 * through their inner blocks, not through direct wrapper attributes.
 *
 * @param {Object} blockContext Block context.
 * @return {boolean} Whether editable content is expressed through inner blocks only.
 */
function usesInnerBlocksAsContent( blockContext ) {
	return (
		blockContext?.supportsContentRole === true &&
		getContentAttributeKeys( blockContext ).length === 0
	);
}

/**
 * Derive editing restrictions from block context.
 *
 * WordPress editing modes: 'default' (unrestricted), 'contentOnly', 'disabled'.
 * 'default' intentionally falls through — no restrictions applied.
 *
 * @param {Object} blockContext Block context.
 * @return {{ contentOnly: boolean, disabled: boolean }} Editing restriction flags.
 */
function getEditingRestrictions( blockContext ) {
	return {
		disabled: blockContext?.editingMode === 'disabled',
		contentOnly:
			blockContext?.isInsideContentOnly ||
			blockContext?.editingMode === 'contentOnly',
	};
}

/**
 * @param {Object}   suggestion           Suggestion candidate.
 * @param {string[]} contentAttributeKeys Allowed content attribute keys.
 * @return {object|null} Filtered suggestion or null when no allowed updates remain.
 */
function filterSuggestionForContentOnly( suggestion, contentAttributeKeys ) {
	if ( ! suggestion || ! isPlainObject( suggestion.attributeUpdates ) ) {
		return null;
	}

	const filteredUpdates = filterAttributeUpdatesForContentOnly(
		suggestion.attributeUpdates,
		contentAttributeKeys
	);

	if ( Object.keys( filteredUpdates ).length === 0 ) {
		return null;
	}

	return {
		...suggestion,
		attributeUpdates: filteredUpdates,
	};
}

/**
 * @param {Object} currentAttributes Current block attributes.
 * @param {Object} suggestedUpdates  Suggested attribute updates.
 * @return {Object} Safe attribute patch that preserves nested metadata/style state.
 */
export function buildSafeAttributeUpdates(
	currentAttributes = {},
	suggestedUpdates = {}
) {
	if ( ! isPlainObject( suggestedUpdates ) ) {
		return {};
	}

	const safeUpdates = {};

	for ( const [ key, value ] of Object.entries( suggestedUpdates ) ) {
		if (
			NESTED_MERGE_KEYS.has( key ) &&
			isPlainObject( value ) &&
			isPlainObject( currentAttributes[ key ] )
		) {
			safeUpdates[ key ] = deepMergeObjects(
				currentAttributes[ key ],
				value
			);
		} else {
			safeUpdates[ key ] = value;
		}
	}

	return safeUpdates;
}

/**
 * @param {Object}   attributeUpdates          Suggested attribute updates.
 * @param {string[]} [contentAttributeKeys=[]] Allowed content attribute keys.
 * @return {Object} Filtered content-only-safe attribute updates.
 */
export function filterAttributeUpdatesForContentOnly(
	attributeUpdates,
	contentAttributeKeys = []
) {
	if ( ! isPlainObject( attributeUpdates ) ) {
		return {};
	}

	const allowedKeys = new Set( contentAttributeKeys );
	const filteredUpdates = {};

	for ( const [ key, value ] of Object.entries( attributeUpdates ) ) {
		if ( allowedKeys.has( key ) ) {
			filteredUpdates[ key ] = value;
		}
	}

	return filteredUpdates;
}

/**
 * @param {Object} recommendations   Raw recommendations payload.
 * @param {Object} [blockContext={}] Block context used to enforce locking rules.
 * @return {Object} Normalized and filtered recommendation payload.
 */
export function sanitizeRecommendationsForContext(
	recommendations,
	blockContext = {}
) {
	const normalized = normalizeSuggestionGroups( recommendations );
	const restrictions = getEditingRestrictions( blockContext );

	if ( restrictions.disabled ) {
		return {
			...normalized,
			settings: [],
			styles: [],
			block: [],
		};
	}

	if ( ! restrictions.contentOnly ) {
		return normalized;
	}

	if ( usesInnerBlocksAsContent( blockContext ) ) {
		return {
			...normalized,
			settings: [],
			styles: [],
			block: [],
		};
	}

	const contentAttributeKeys = getContentAttributeKeys( blockContext );

	return {
		...normalized,
		settings: [],
		styles: [],
		block: normalized.block
			.map( ( suggestion ) =>
				filterSuggestionForContentOnly(
					suggestion,
					contentAttributeKeys
				)
			)
			.filter( Boolean ),
	};
}

/**
 * @param {Object} suggestion        Suggestion candidate.
 * @param {Object} [blockContext={}] Block context used to enforce locking rules.
 * @return {Object} Attribute updates allowed for the current block context.
 */
export function getSuggestionAttributeUpdates( suggestion, blockContext = {} ) {
	if ( ! isPlainObject( suggestion?.attributeUpdates ) ) {
		return {};
	}

	const restrictions = getEditingRestrictions( blockContext );

	if ( restrictions.disabled ) {
		return {};
	}

	if ( ! restrictions.contentOnly ) {
		return suggestion.attributeUpdates;
	}

	if ( usesInnerBlocksAsContent( blockContext ) ) {
		return {};
	}

	return filterAttributeUpdatesForContentOnly(
		suggestion.attributeUpdates,
		getContentAttributeKeys( blockContext )
	);
}
