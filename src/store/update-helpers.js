const NESTED_MERGE_KEYS = new Set( [ 'metadata', 'style' ] );
const BANNED_TOP_LEVEL_ATTRIBUTE_KEYS = new Set( [ 'customCSS' ] );
const ADVISORY_ONLY_BLOCK_SUGGESTION_TYPES = new Set( [
	'structural_recommendation',
	'pattern_replacement',
] );

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
 * @param {string} value Candidate string.
 * @return {boolean} Whether the value looks like raw CSS.
 */
function looksLikeCssPayload( value ) {
	if ( typeof value !== 'string' ) {
		return false;
	}

	const trimmed = value.trim();

	if ( ! trimmed ) {
		return false;
	}

	if ( /[{};]/.test( trimmed ) ) {
		return true;
	}

	if ( /!\s*important\b/i.test( trimmed ) ) {
		return true;
	}

	if (
		/^@(container|font-face|import|keyframes|layer|media|supports)\b/i.test(
			trimmed
		)
	) {
		return true;
	}

	return (
		/^[a-z-]+\s*:\s*.+$/i.test( trimmed ) &&
		! /^var:preset\|/i.test( trimmed ) &&
		! /^var\(--/i.test( trimmed )
	);
}

/**
 * @param {string[]} path Current attribute path.
 * @return {boolean} Whether the path is a banned CSS channel.
 */
function isBannedAttributeUpdatePath( path ) {
	if ( ! Array.isArray( path ) || path.length === 0 ) {
		return false;
	}

	if ( BANNED_TOP_LEVEL_ATTRIBUTE_KEYS.has( path[ 0 ] ) ) {
		return true;
	}

	for ( let index = 0; index < path.length - 1; index++ ) {
		if ( path[ index ] === 'style' && path[ index + 1 ] === 'css' ) {
			return true;
		}
	}

	return false;
}

/**
 * @param {string[]} path Current attribute path.
 * @return {boolean} Whether the path is style-adjacent.
 */
function isStyleAdjacentPath( path ) {
	if ( ! Array.isArray( path ) || path.length === 0 ) {
		return false;
	}

	return (
		BANNED_TOP_LEVEL_ATTRIBUTE_KEYS.has( path[ 0 ] ) ||
		path.includes( 'style' ) ||
		path[ path.length - 1 ] === 'css'
	);
}

/**
 * @param {unknown}  attributeUpdates Candidate attribute update tree.
 * @param {string[]} path             Current traversal path.
 * @return {{ kept: boolean, value: unknown }} Filtered update payload.
 */
function filterThemeSafeAttributeUpdatesResult( attributeUpdates, path = [] ) {
	if ( Array.isArray( attributeUpdates ) ) {
		const values = attributeUpdates
			.map( ( value, index ) =>
				filterThemeSafeAttributeUpdatesResult( value, [
					...path,
					String( index ),
				] )
			)
			.filter( ( entry ) => entry.kept )
			.map( ( entry ) => entry.value );

		return {
			kept: values.length > 0 || path.length === 0,
			value: values,
		};
	}

	if ( ! isPlainObject( attributeUpdates ) ) {
		if (
			typeof attributeUpdates === 'string' &&
			isStyleAdjacentPath( path ) &&
			looksLikeCssPayload( attributeUpdates )
		) {
			return { kept: false, value: undefined };
		}

		return { kept: true, value: attributeUpdates };
	}

	const values = {};

	for ( const [ key, value ] of Object.entries( attributeUpdates ) ) {
		const nextPath = [ ...path, key ];

		if ( isBannedAttributeUpdatePath( nextPath ) ) {
			continue;
		}

		const filtered = filterThemeSafeAttributeUpdatesResult(
			value,
			nextPath
		);

		if ( filtered.kept ) {
			values[ key ] = filtered.value;
		}
	}

	return {
		kept: Object.keys( values ).length > 0 || path.length === 0,
		value: values,
	};
}

/**
 * @param {Object} attributeUpdates Candidate attribute updates.
 * @return {Object} Theme-safe attribute updates.
 */
function filterThemeSafeAttributeUpdates( attributeUpdates ) {
	if ( ! isPlainObject( attributeUpdates ) ) {
		return {};
	}

	const filtered = filterThemeSafeAttributeUpdatesResult( attributeUpdates );

	return isPlainObject( filtered.value ) ? filtered.value : {};
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
 * @param {Object} suggestion Suggestion candidate.
 * @return {boolean} Whether the block suggestion is advisory-only by type.
 */
function isAdvisoryOnlyBlockSuggestion( suggestion ) {
	return ADVISORY_ONLY_BLOCK_SUGGESTION_TYPES.has( suggestion?.type );
}

/**
 * Advisory-only block suggestions should never carry executable updates.
 *
 * @param {Object} suggestion Suggestion candidate.
 * @return {Object} Normalized suggestion.
 */
function normalizeBlockSuggestionForExecution( suggestion ) {
	if ( ! suggestion || ! isAdvisoryOnlyBlockSuggestion( suggestion ) ) {
		return suggestion;
	}

	return {
		...suggestion,
		attributeUpdates: [],
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
 * @param {Object} blockContext Block context.
 * @return {?string[]} Bindable attribute names when context exposes them.
 */
function getBindableAttributeKeys( blockContext ) {
	if ( ! Array.isArray( blockContext?.bindableAttributes ) ) {
		return null;
	}

	return [
		...new Set(
			blockContext.bindableAttributes
				.filter(
					( attribute ) =>
						typeof attribute === 'string' && attribute.trim() !== ''
				)
				.map( ( attribute ) => attribute.trim() )
		),
	];
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
	const normalizedSuggestion =
		normalizeBlockSuggestionForExecution( suggestion );

	if ( isAdvisoryOnlyBlockSuggestion( normalizedSuggestion ) ) {
		return normalizedSuggestion;
	}

	if (
		! normalizedSuggestion ||
		! isPlainObject( normalizedSuggestion.attributeUpdates )
	) {
		return null;
	}

	const filteredUpdates = filterAttributeUpdatesForContentOnly(
		normalizedSuggestion.attributeUpdates,
		contentAttributeKeys
	);

	if ( Object.keys( filteredUpdates ).length === 0 ) {
		return null;
	}

	return {
		...normalizedSuggestion,
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
	const themeSafeUpdates =
		filterThemeSafeAttributeUpdates( suggestedUpdates );

	if ( ! isPlainObject( themeSafeUpdates ) ) {
		return {};
	}

	const safeUpdates = {};

	for ( const [ key, value ] of Object.entries( themeSafeUpdates ) ) {
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
 * Build a restoration patch that can revert an applied attribute snapshot.
 *
 * Keys that existed only in the applied snapshot are explicitly unset so the
 * block returns to the previous serialized shape.
 *
 * @param {Object} previousAttributes Snapshot taken before apply.
 * @param {Object} nextAttributes     Snapshot taken after apply.
 * @return {Object} Restoration patch suitable for updateBlockAttributes().
 */
export function buildUndoAttributeUpdates(
	previousAttributes = {},
	nextAttributes = {}
) {
	const restoreUpdates = {};
	const keys = new Set( [
		...Object.keys( nextAttributes || {} ),
		...Object.keys( previousAttributes || {} ),
	] );

	for ( const key of keys ) {
		if ( Object.prototype.hasOwnProperty.call( previousAttributes, key ) ) {
			restoreUpdates[ key ] = previousAttributes[ key ];
		} else {
			restoreUpdates[ key ] = undefined;
		}
	}

	return restoreUpdates;
}

/**
 * @param {Object} previousSnapshot Stored snapshot.
 * @param {Object} currentSnapshot  Current live attributes.
 * @return {boolean} Whether the snapshots still match closely enough to undo.
 */
export function attributeSnapshotsMatch(
	previousSnapshot = {},
	currentSnapshot = {}
) {
	return (
		JSON.stringify( previousSnapshot ) === JSON.stringify( currentSnapshot )
	);
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
 * @param {Object}    attributeUpdates      Suggested attribute updates.
 * @param {?string[]} bindableAttributeKeys Supported binding targets, or null when unknown.
 * @return {Object} Binding-safe attribute updates.
 */
function filterAttributeUpdatesForBindableAttributes(
	attributeUpdates,
	bindableAttributeKeys = null
) {
	if (
		! isPlainObject( attributeUpdates ) ||
		! Array.isArray( bindableAttributeKeys )
	) {
		return isPlainObject( attributeUpdates ) ? attributeUpdates : {};
	}

	if (
		! isPlainObject( attributeUpdates.metadata ) ||
		! isPlainObject( attributeUpdates.metadata.bindings )
	) {
		return attributeUpdates;
	}

	const allowedKeys = new Set( bindableAttributeKeys );
	const filteredBindings = {};

	for ( const [ key, value ] of Object.entries(
		attributeUpdates.metadata.bindings
	) ) {
		if ( allowedKeys.has( key ) ) {
			filteredBindings[ key ] = value;
		}
	}

	const nextUpdates = { ...attributeUpdates };
	const nextMetadata = { ...attributeUpdates.metadata };

	if ( Object.keys( filteredBindings ).length > 0 ) {
		nextMetadata.bindings = filteredBindings;
	} else {
		delete nextUpdates.metadata;
		return nextUpdates;
	}

	if ( Object.keys( nextMetadata ).length > 0 ) {
		nextUpdates.metadata = nextMetadata;
	} else {
		delete nextUpdates.metadata;
	}

	return nextUpdates;
}

/**
 * @param {Object}    suggestion            Suggestion candidate.
 * @param {?string[]} bindableAttributeKeys Supported binding targets, or null when unknown.
 * @return {object|null} Sanitized suggestion or null when no applicable updates remain.
 */
function sanitizeSuggestionForBindableAttributes(
	suggestion,
	bindableAttributeKeys
) {
	if ( ! isPlainObject( suggestion?.attributeUpdates ) ) {
		return suggestion;
	}

	if (
		! Array.isArray( bindableAttributeKeys ) ||
		! isPlainObject( suggestion.attributeUpdates.metadata ) ||
		! isPlainObject( suggestion.attributeUpdates.metadata.bindings )
	) {
		return suggestion;
	}

	const filteredUpdates = filterAttributeUpdatesForBindableAttributes(
		suggestion.attributeUpdates,
		bindableAttributeKeys
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
 * @param {Array}     suggestions           Suggestion group.
 * @param {?string[]} bindableAttributeKeys Supported binding targets, or null when unknown.
 * @return {Array} Sanitized suggestion group.
 */
function sanitizeSuggestionGroupForBindableAttributes(
	suggestions,
	bindableAttributeKeys
) {
	return suggestions
		.map( ( suggestion ) =>
			sanitizeSuggestionForBindableAttributes(
				suggestion,
				bindableAttributeKeys
			)
		)
		.filter( Boolean );
}

/**
 * @param {Object} suggestion Suggestion candidate.
 * @return {object|null} Theme-safe suggestion or null when no safe updates remain.
 */
function sanitizeSuggestionForThemeSafety( suggestion ) {
	if ( ! isPlainObject( suggestion?.attributeUpdates ) ) {
		return suggestion;
	}

	const filteredUpdates = filterThemeSafeAttributeUpdates(
		suggestion.attributeUpdates
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
 * @param {Array} suggestions Suggestion group.
 * @return {Array} Theme-safe suggestion group.
 */
function sanitizeSuggestionGroupForThemeSafety( suggestions ) {
	return suggestions
		.map( ( suggestion ) => sanitizeSuggestionForThemeSafety( suggestion ) )
		.filter( Boolean );
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
	const normalizedBlockSuggestions = normalized.block.map(
		( suggestion ) => normalizeBlockSuggestionForExecution( suggestion )
	);
	const restrictions = getEditingRestrictions( blockContext );
	const bindableAttributeKeys = getBindableAttributeKeys( blockContext );
	const themeSafeRecommendations = {
		...normalized,
		settings: sanitizeSuggestionGroupForThemeSafety( normalized.settings ),
		styles: sanitizeSuggestionGroupForThemeSafety( normalized.styles ),
		block: sanitizeSuggestionGroupForThemeSafety(
			normalizedBlockSuggestions
		),
	};
	const bindingSafeRecommendations = {
		...themeSafeRecommendations,
		settings: sanitizeSuggestionGroupForBindableAttributes(
			themeSafeRecommendations.settings,
			bindableAttributeKeys
		),
		styles: sanitizeSuggestionGroupForBindableAttributes(
			themeSafeRecommendations.styles,
			bindableAttributeKeys
		),
		block: sanitizeSuggestionGroupForBindableAttributes(
			themeSafeRecommendations.block,
			bindableAttributeKeys
		),
	};

	if ( restrictions.disabled ) {
		return {
			...bindingSafeRecommendations,
			settings: [],
			styles: [],
			block: [],
		};
	}

	if ( ! restrictions.contentOnly ) {
		return bindingSafeRecommendations;
	}

	if ( usesInnerBlocksAsContent( blockContext ) ) {
		return {
			...bindingSafeRecommendations,
			settings: [],
			styles: [],
			block: bindingSafeRecommendations.block.filter(
				( suggestion ) => isAdvisoryOnlyBlockSuggestion( suggestion )
			),
		};
	}

	const contentAttributeKeys = getContentAttributeKeys( blockContext );

	return {
		...bindingSafeRecommendations,
		settings: [],
		styles: [],
		block: bindingSafeRecommendations.block
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

	const themeSafeUpdates = filterThemeSafeAttributeUpdates(
		suggestion.attributeUpdates
	);

	if ( Object.keys( themeSafeUpdates ).length === 0 ) {
		return {};
	}

	const bindingSafeUpdates = filterAttributeUpdatesForBindableAttributes(
		themeSafeUpdates,
		getBindableAttributeKeys( blockContext )
	);

	if ( ! restrictions.contentOnly ) {
		return bindingSafeUpdates;
	}

	if ( usesInnerBlocksAsContent( blockContext ) ) {
		return {};
	}

	return filterAttributeUpdatesForContentOnly(
		bindingSafeUpdates,
		getContentAttributeKeys( blockContext )
	);
}

/**
 * @param {Object} suggestion        Suggestion candidate.
 * @param {Object} [blockContext={}] Block context used to enforce locking rules.
 * @return {{ allowedUpdates: Object, isAdvisory: boolean, isAdvisoryOnly: boolean, isExecutable: boolean }}
 * Block suggestion execution metadata.
 */
export function getBlockSuggestionExecutionInfo(
	suggestion,
	blockContext = {}
) {
	const advisoryOnly = isAdvisoryOnlyBlockSuggestion( suggestion );
	const allowedUpdates = advisoryOnly
		? {}
		: getSuggestionAttributeUpdates( suggestion, blockContext );
	const hasExecutableUpdates = Object.keys( allowedUpdates ).length > 0;

	return {
		allowedUpdates,
		isAdvisory: advisoryOnly || ! hasExecutableUpdates,
		isAdvisoryOnly: advisoryOnly,
		isExecutable: ! advisoryOnly && hasExecutableUpdates,
	};
}
