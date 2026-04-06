import { isPlainObject } from '../utils/type-guards';

const NESTED_MERGE_KEYS = new Set( [ 'metadata', 'style' ] );
const BANNED_TOP_LEVEL_ATTRIBUTE_KEYS = new Set( [ 'customCSS' ] );
const ADVISORY_ONLY_BLOCK_SUGGESTION_TYPES = new Set( [
	'structural_recommendation',
	'pattern_replacement',
] );

/**
 * @param {unknown} left  Left snapshot value.
 * @param {unknown} right Right snapshot value.
 * @return {boolean} Whether the snapshot values are structurally equal.
 */
function areSnapshotValuesEqual( left, right ) {
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
			areSnapshotValuesEqual( value, right[ index ] )
		);
	}

	if ( isPlainObject( left ) || isPlainObject( right ) ) {
		if ( ! isPlainObject( left ) || ! isPlainObject( right ) ) {
			return false;
		}

		const leftKeys = Object.keys( left );
		const rightKeys = Object.keys( right );

		if ( leftKeys.length !== rightKeys.length ) {
			return false;
		}

		return leftKeys.every(
			( key ) =>
				Object.prototype.hasOwnProperty.call( right, key ) &&
				areSnapshotValuesEqual( left[ key ], right[ key ] )
		);
	}

	return false;
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
	return areSnapshotValuesEqual( previousSnapshot, currentSnapshot );
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
		delete nextMetadata.bindings;
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

function hasExplicitlyEmptyInspectorPanels( blockContext = {} ) {
	if ( ! Object.prototype.hasOwnProperty.call( blockContext, 'inspectorPanels' ) ) {
		return false;
	}

	if ( Array.isArray( blockContext.inspectorPanels ) ) {
		return blockContext.inspectorPanels.length === 0;
	}

	if ( isPlainObject( blockContext.inspectorPanels ) ) {
		return Object.keys( blockContext.inspectorPanels ).length === 0;
	}

	return false;
}

function summarizeSuggestionCounts( counts = {} ) {
	const parts = [];

	if ( Number.isInteger( counts.settings ) && counts.settings > 0 ) {
		parts.push(
			`${ counts.settings } setting${
				counts.settings === 1 ? '' : 's'
			}`
		);
	}

	if ( Number.isInteger( counts.styles ) && counts.styles > 0 ) {
		parts.push(
			`${ counts.styles } style${
				counts.styles === 1 ? '' : 's'
			}`
		);
	}

	if ( Number.isInteger( counts.block ) && counts.block > 0 ) {
		parts.push(
			`${ counts.block } block suggestion${
				counts.block === 1 ? '' : 's'
			}`
		);
	}

	return parts.length > 0 ? parts.join( ', ' ) : 'no suggestions';
}

function getRecommendationGroupCounts( recommendations = {} ) {
	const normalized = normalizeSuggestionGroups( recommendations );

	return {
		settings: normalized.settings.length,
		styles: normalized.styles.length,
		block: normalized.block.length,
	};
}

/**
 * Build a diagnostic summary for successful block requests whose block lane
 * ends up empty after validation.
 *
 * @param {Object} rawRecommendations       Recommendation payload before client sanitization.
 * @param {Object} sanitizedRecommendations Recommendation payload after client sanitization.
 * @param {Object} [blockContext={}]        Block context used for sanitization.
 * @return {?Object} Diagnostics for empty block-lane results.
 */
export function buildBlockRecommendationDiagnostics(
	rawRecommendations,
	sanitizedRecommendations,
	blockContext = {}
) {
	const rawCounts = getRecommendationGroupCounts( rawRecommendations );
	const finalCounts = getRecommendationGroupCounts( sanitizedRecommendations );

	if ( finalCounts.block > 0 ) {
		return null;
	}

	const normalized = normalizeSuggestionGroups( rawRecommendations );
	const normalizedBlockSuggestions = normalized.block.map( ( suggestion ) =>
		normalizeBlockSuggestionForExecution( suggestion )
	);
	const restrictions = getEditingRestrictions( blockContext );
	const bindableAttributeKeys = getBindableAttributeKeys( blockContext );
	const themeSafeBlockSuggestions = sanitizeSuggestionGroupForThemeSafety(
		normalizedBlockSuggestions
	);
	const bindingSafeBlockSuggestions =
		sanitizeSuggestionGroupForBindableAttributes(
			themeSafeBlockSuggestions,
			bindableAttributeKeys
		);
	let contentSafeBlockSuggestions = bindingSafeBlockSuggestions;
	const reasonCodes = [];
	const detailLines = [];

	if ( restrictions.disabled ) {
		contentSafeBlockSuggestions = [];
	} else if ( restrictions.contentOnly ) {
		if ( usesInnerBlocksAsContent( blockContext ) ) {
			contentSafeBlockSuggestions =
				bindingSafeBlockSuggestions.filter( ( suggestion ) =>
					isAdvisoryOnlyBlockSuggestion( suggestion )
				);
		} else {
			const contentAttributeKeys = getContentAttributeKeys( blockContext );

			contentSafeBlockSuggestions = bindingSafeBlockSuggestions
				.map( ( suggestion ) =>
					filterSuggestionForContentOnly(
						suggestion,
						contentAttributeKeys
					)
				)
				.filter( Boolean );
		}
	}

	if ( rawCounts.block === 0 ) {
		reasonCodes.push( 'model_returned_no_block_items' );

		if ( finalCounts.settings > 0 || finalCounts.styles > 0 ) {
			reasonCodes.push( 'suggestions_routed_to_other_lanes' );
			detailLines.push(
				`Flavor Agent returned ${ summarizeSuggestionCounts(
					finalCounts
				) }, but none in the block lane.`
			);
		} else {
			detailLines.push(
				'Flavor Agent returned no block-lane suggestions for this request.'
			);
		}
	} else {
		detailLines.push(
			`Block-lane suggestions changed from ${ rawCounts.block } raw to ${ finalCounts.block } after validation.`
		);

		if (
			themeSafeBlockSuggestions.length < normalizedBlockSuggestions.length
		) {
			reasonCodes.push( 'theme_safety_removed_block_items' );
			detailLines.push(
				'At least one block suggestion was removed because its attribute updates were empty or unsafe after theme-safety checks.'
			);
		}

		if (
			bindingSafeBlockSuggestions.length < themeSafeBlockSuggestions.length
		) {
			reasonCodes.push( 'binding_filters_removed_block_items' );
			detailLines.push(
				Array.isArray( bindableAttributeKeys ) &&
					bindableAttributeKeys.length > 0
					? 'At least one block suggestion targeted unsupported bindings for this block.'
					: 'Binding-only block suggestions were removed because this block exposes no bindable attributes.'
			);
		}

		if ( restrictions.disabled ) {
			reasonCodes.push( 'block_editing_disabled' );
			detailLines.push(
				'The selected block is in disabled editing mode, so block-lane suggestions cannot be used.'
			);
		} else if ( restrictions.contentOnly ) {
			if ( usesInnerBlocksAsContent( blockContext ) ) {
				reasonCodes.push( 'content_only_inner_blocks_lock' );
				detailLines.push(
					'This block is content-restricted and exposes editable content only through inner blocks, so wrapper-level block updates were removed.'
				);
			} else if (
				contentSafeBlockSuggestions.length <
				bindingSafeBlockSuggestions.length
			) {
				reasonCodes.push( 'content_only_removed_block_items' );
				detailLines.push(
					'This block is content-restricted, so non-content block updates were removed.'
				);
			}
		}

		if ( reasonCodes.length === 0 ) {
			reasonCodes.push( 'block_items_removed_after_validation' );
			detailLines.push(
				'Block-lane suggestions were removed after validation for the current block context.'
			);
		}
	}

	if ( hasExplicitlyEmptyInspectorPanels( blockContext ) ) {
		reasonCodes.push( 'no_mapped_inspector_panels' );
		detailLines.push(
			'The block context exposed no mapped inspector panels for this request.'
		);
	}

	return {
		hasEmptyBlockResult: true,
		title:
			rawCounts.block === 0
				? 'No block-lane suggestions returned'
				: 'Block-lane suggestions were filtered out',
		detailLines,
		reasonCodes: [ ...new Set( reasonCodes ) ],
		rawCounts,
		finalCounts,
		filterCounts: {
			themeSafe: themeSafeBlockSuggestions.length,
			bindingSafe: bindingSafeBlockSuggestions.length,
			contentSafe: contentSafeBlockSuggestions.length,
		},
		restrictions: {
			disabled: restrictions.disabled,
			contentOnly: restrictions.contentOnly,
			usesInnerBlocksAsContent: usesInnerBlocksAsContent( blockContext ),
		},
	};
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
	const normalizedBlockSuggestions = normalized.block.map( ( suggestion ) =>
		normalizeBlockSuggestionForExecution( suggestion )
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
			block: bindingSafeRecommendations.block.filter( ( suggestion ) =>
				isAdvisoryOnlyBlockSuggestion( suggestion )
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
