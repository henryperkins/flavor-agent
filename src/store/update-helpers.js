import { isPlainObject } from '../utils/type-guards';
import { buildBlockRecommendationExecutionContract } from '../utils/block-execution-contract';
import {
	FREEFORM_STYLE_VALIDATORS,
	normalizePresetType,
	validateCssCustomPropertyReference,
	validateFreeformStyleValueByKind,
} from '../utils/style-validation';
import {
	buildBlockOperationValidationContext,
	BLOCK_OPERATION_ERROR_CLIENT_SERVER_OPERATION_MISMATCH,
	validateBlockOperationSequence,
} from '../utils/block-operation-catalog';
import {
	classifyBlockSuggestionActionability,
	summarizeActionability,
} from '../utils/recommendation-actionability';

const NESTED_MERGE_KEYS = new Set( [ 'metadata', 'style' ] );
const BANNED_TOP_LEVEL_ATTRIBUTE_KEYS = new Set( [ 'customCSS' ] );
const ADVISORY_ONLY_BLOCK_SUGGESTION_TYPES = new Set( [
	'structural_recommendation',
	'pattern_replacement',
] );

function withComputedActionability( suggestion, actionability ) {
	if ( ! suggestion || typeof suggestion !== 'object' ) {
		return suggestion;
	}

	return {
		...suggestion,
		eligibility: actionability,
		actionability,
	};
}

/**
 * @param {unknown} left  Left snapshot value.
 * @param {unknown} right Right snapshot value.
 * @return {boolean} Whether the snapshot values are structurally equal.
 */
function areSnapshotValuesEqual( left, right ) {
	if ( Object.is( left, right ) ) {
		return true;
	}

	if ( typeof left === 'string' && typeof right === 'string' ) {
		return (
			normalizeSnapshotString( left ) === normalizeSnapshotString( right )
		);
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

function normalizeSnapshotString( value ) {
	const normalized = value.replace( /\r\n?/g, '\n' ).trim();
	const paragraphMatch = normalized.match(
		/^<p(?:\s[^>]*)?>([\s\S]*)<\/p>$/i
	);

	if ( ! paragraphMatch ) {
		return normalized;
	}

	const innerContent = paragraphMatch[ 1 ].trim();

	return /<\/?p(?:\s|>)/i.test( innerContent ) ? normalized : innerContent;
}

function isEmptyDefaultSnapshotValue( value ) {
	if (
		value === undefined ||
		value === null ||
		value === '' ||
		value === false
	) {
		return true;
	}

	if ( Array.isArray( value ) ) {
		return value.length === 0;
	}

	if ( isPlainObject( value ) ) {
		return Object.values( value ).every( isEmptyDefaultSnapshotValue );
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
	if ( ! suggestion || typeof suggestion !== 'object' ) {
		return suggestion;
	}

	const proposedOperations = Array.isArray( suggestion.proposedOperations )
		? suggestion.proposedOperations
		: [];
	const fallbackProposedOperations =
		proposedOperations.length === 0 &&
		Array.isArray( suggestion.operations )
			? suggestion.operations
			: proposedOperations;
	const normalized = {
		...suggestion,
		operations: Array.isArray( suggestion.operations )
			? suggestion.operations
			: [],
		proposedOperations: fallbackProposedOperations,
		rejectedOperations: Array.isArray( suggestion.rejectedOperations )
			? suggestion.rejectedOperations
			: [],
	};

	if ( ! isAdvisoryOnlyBlockSuggestion( normalized ) ) {
		return normalized;
	}

	return {
		...normalized,
		attributeUpdates: [],
	};
}

/**
 * @param {Object}  blockContext      Context stored with a block recommendation set.
 * @param {?Object} executionContract Server-normalized execution contract.
 * @return {string[]} Content attribute names.
 */
function getContentAttributeKeys( blockContext, executionContract = null ) {
	if ( Array.isArray( executionContract?.contentAttributeKeys ) ) {
		return executionContract.contentAttributeKeys.filter( Boolean );
	}

	return Object.keys( blockContext?.contentAttributes || {} );
}

/**
 * Some contentOnly-compatible container blocks expose editable content only
 * through their inner blocks, not through direct wrapper attributes.
 *
 * @param {Object}  blockContext      Context stored with a block recommendation set.
 * @param {?Object} executionContract Server-normalized execution contract.
 * @return {boolean} Whether editable content is expressed through inner blocks only.
 */
function usesInnerBlocksAsContent( blockContext, executionContract = null ) {
	if ( executionContract?.usesInnerBlocksAsContent === true ) {
		return true;
	}

	return (
		blockContext?.supportsContentRole === true &&
		getContentAttributeKeys( blockContext, executionContract ).length === 0
	);
}

/**
 * @param {Object}  blockContext      Block context.
 * @param {?Object} executionContract Server-normalized execution contract.
 * @return {?string[]} Bindable attribute names when context exposes them.
 */
function getBindableAttributeKeys( blockContext, executionContract = null ) {
	if ( Array.isArray( executionContract?.bindableAttributes ) ) {
		return [
			...new Set(
				executionContract.bindableAttributes
					.filter(
						( attribute ) =>
							typeof attribute === 'string' &&
							attribute.trim() !== ''
					)
					.map( ( attribute ) => attribute.trim() )
			),
		];
	}

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
 * @param {Object}  blockContext      Block context.
 * @param {?Object} executionContract Server-normalized execution contract.
 * @return {{ contentOnly: boolean, disabled: boolean }} Editing restriction flags.
 */
function getEditingRestrictions( blockContext, executionContract = null ) {
	if ( executionContract && typeof executionContract === 'object' ) {
		return {
			disabled: executionContract?.editingMode === 'disabled',
			contentOnly:
				executionContract?.isInsideContentOnly === true ||
				executionContract?.editingMode === 'contentOnly',
		};
	}

	return {
		disabled: blockContext?.editingMode === 'disabled',
		contentOnly:
			blockContext?.isInsideContentOnly ||
			blockContext?.editingMode === 'contentOnly',
	};
}

function getOperationIdentity( operation = {} ) {
	return JSON.stringify( {
		catalogVersion: operation.catalogVersion || 1,
		type: operation.type || '',
		patternName: operation.patternName || '',
		targetClientId: operation.targetClientId || '',
		targetType: operation.targetType || 'block',
		targetSignature: operation.targetSignature || '',
		position: operation.position || '',
		action: operation.action || '',
		expectedTargetClientId: operation.expectedTarget?.clientId || '',
		expectedTargetName: operation.expectedTarget?.name || '',
	} );
}

function getServerApprovedOperationIdentities( operations = [] ) {
	return new Set(
		( Array.isArray( operations ) ? operations : [] ).map(
			getOperationIdentity
		)
	);
}

function appendUniqueRejections( left = [], right = [] ) {
	const result = [];
	const seen = new Set();

	for ( const rejection of [
		...( Array.isArray( left ) ? left : [] ),
		...( Array.isArray( right ) ? right : [] ),
	] ) {
		const key = JSON.stringify( {
			code: rejection?.code || '',
			operation: rejection?.operation || null,
		} );

		if ( seen.has( key ) ) {
			continue;
		}

		seen.add( key );
		result.push( rejection );
	}

	return result;
}

function buildClientServerOperationMismatchRejections(
	serverOperations = [],
	validationOperationIdentities = new Set()
) {
	return ( Array.isArray( serverOperations ) ? serverOperations : [] )
		.filter(
			( operation ) =>
				! validationOperationIdentities.has(
					getOperationIdentity( operation )
				)
		)
		.map( ( operation ) => ( {
			code: BLOCK_OPERATION_ERROR_CLIENT_SERVER_OPERATION_MISMATCH,
			message:
				'The browser validation result no longer matches the server-approved structural operation.',
			operation,
		} ) );
}

function resolveBlockOperationValidation( suggestion, blockContext ) {
	const proposedOperations = Array.isArray( suggestion?.proposedOperations )
		? suggestion.proposedOperations
		: [];
	const fallbackProposedOperations =
		proposedOperations.length === 0 &&
		Array.isArray( suggestion?.operations )
			? suggestion.operations
			: proposedOperations;
	const serverOperations = Array.isArray( suggestion?.operations )
		? suggestion.operations
		: [];
	const validation = validateBlockOperationSequence(
		fallbackProposedOperations,
		buildBlockOperationValidationContext( blockContext )
	);
	const approvedOperationIdentities =
		getServerApprovedOperationIdentities( serverOperations );
	const validationOperationIdentities = new Set(
		validation.operations.map( getOperationIdentity )
	);
	const executableOperations =
		approvedOperationIdentities.size > 0
			? serverOperations.filter( ( operation ) =>
					validationOperationIdentities.has(
						getOperationIdentity( operation )
					)
			  )
			: [];
	const mismatchRejections =
		approvedOperationIdentities.size > 0 && validation.operations.length > 0
			? buildClientServerOperationMismatchRejections(
					serverOperations,
					validationOperationIdentities
			  )
			: [];
	const rejectedOperations = appendUniqueRejections(
		suggestion?.rejectedOperations,
		[ ...validation.rejectedOperations, ...mismatchRejections ]
	);

	return {
		...validation,
		ok: executableOperations.length > 0,
		operations: executableOperations,
		rejectedOperations,
	};
}

function resolveExecutionContract(
	blockContext = {},
	executionContract = null
) {
	if ( executionContract && typeof executionContract === 'object' ) {
		const blockContextContract = buildBlockRecommendationExecutionContract(
			blockContext,
			{}
		);
		const hasContentKeys = Array.isArray(
			executionContract.contentAttributeKeys
		);
		const hasConfigKeys = Array.isArray(
			executionContract.configAttributeKeys
		);

		return {
			...blockContextContract,
			...executionContract,
			contentAttributeKeys: hasContentKeys
				? executionContract.contentAttributeKeys
				: blockContextContract.contentAttributeKeys,
			configAttributeKeys: hasConfigKeys
				? executionContract.configAttributeKeys
				: blockContextContract.configAttributeKeys,
			isAuthoritative: executionContract.isAuthoritative !== false,
		};
	}

	return {
		...buildBlockRecommendationExecutionContract( blockContext, {} ),
		isAuthoritative: false,
	};
}

function isAuthoritativeExecutionContract( executionContract = {} ) {
	return executionContract?.isAuthoritative !== false;
}

function executionContractKnowsPanelMapping( executionContract = {} ) {
	return executionContract?.panelMappingKnown === true;
}

function normalizePanelKey( value ) {
	return typeof value === 'string'
		? value
				.trim()
				.toLowerCase()
				.replace( /[^a-z0-9_-]/g, '' )
		: '';
}

function getAllowedPanelsLookup( executionContract = {} ) {
	return Object.fromEntries(
		( Array.isArray( executionContract?.allowedPanels )
			? executionContract.allowedPanels
			: []
		)
			.filter(
				( panel ) => typeof panel === 'string' && panel.trim() !== ''
			)
			.map( ( panel ) => [ panel.trim(), true ] )
	);
}

function executionContractSupportsPath(
	executionContract = {},
	supportPath = ''
) {
	if ( ! supportPath ) {
		return false;
	}

	const supportedPaths = Array.isArray( executionContract?.styleSupportPaths )
		? executionContract.styleSupportPaths
		: [];

	if (
		supportedPaths.length === 0 &&
		! executionContractKnowsPanelMapping( executionContract )
	) {
		return true;
	}

	return new Set( supportedPaths ).has( supportPath );
}

function executionContractFeatureEnabled(
	executionContract = {},
	featureKey = ''
) {
	if ( ! featureKey ) {
		return true;
	}

	const enabledFeatures =
		executionContract &&
		typeof executionContract.enabledFeatures === 'object'
			? executionContract.enabledFeatures
			: {};

	return enabledFeatures[ featureKey ] !== false;
}

function getAllowedPresetSlugs( executionContract = {}, presetType = '' ) {
	const presetSlugs = Array.isArray(
		executionContract?.presetSlugs?.[ normalizePresetType( presetType ) ]
	)
		? executionContract.presetSlugs[ normalizePresetType( presetType ) ]
		: [];

	return {
		known:
			presetSlugs.length > 0 ||
			isAuthoritativeExecutionContract( executionContract ),
		slugs: new Set( presetSlugs ),
	};
}

function presetTypeAllowsFreeformFallback(
	executionContract = {},
	presetType = ''
) {
	const normalizedType = normalizePresetType( presetType );

	if (
		! normalizedType ||
		! isAuthoritativeExecutionContract( executionContract )
	) {
		return false;
	}

	const presetSlugs =
		executionContract && typeof executionContract.presetSlugs === 'object'
			? executionContract.presetSlugs
			: {};

	return (
		Object.prototype.hasOwnProperty.call( presetSlugs, normalizedType ) &&
		Array.isArray( presetSlugs[ normalizedType ] ) &&
		presetSlugs[ normalizedType ].length === 0
	);
}

function parsePresetReference( value ) {
	if ( typeof value !== 'string' ) {
		return null;
	}

	const trimmed = value.trim();
	let matches = trimmed.match( /^var:preset\|([a-z-]+)\|([a-z0-9_-]+)$/i );

	if ( matches ) {
		return {
			type: normalizePresetType( matches[ 1 ] ),
			slug: matches[ 2 ],
		};
	}

	matches = trimmed.match(
		/^var\(--wp--preset--([a-z-]+)--([a-z0-9_-]+)\)$/i
	);

	if ( matches ) {
		return {
			type: normalizePresetType( matches[ 1 ] ),
			slug: matches[ 2 ],
		};
	}

	return null;
}

function validateRawPresetSlug( value, presetType, executionContract ) {
	if ( typeof value !== 'string' ) {
		return null;
	}

	const slug = value.trim();

	if ( ! slug ) {
		return null;
	}

	const allowedPresetSlugs = getAllowedPresetSlugs(
		executionContract,
		presetType
	);

	if ( ! allowedPresetSlugs.known ) {
		return slug;
	}

	return allowedPresetSlugs.slugs.has( slug ) ? slug : null;
}

function validatePresetReferenceValue( value, presetType, executionContract ) {
	if ( typeof value !== 'string' ) {
		return null;
	}

	const trimmed = value.trim();
	const parsed = parsePresetReference( trimmed );

	if ( ! parsed || parsed.type !== normalizePresetType( presetType ) ) {
		return null;
	}

	const allowedPresetSlugs = getAllowedPresetSlugs(
		executionContract,
		presetType
	);

	if ( ! allowedPresetSlugs.known ) {
		return trimmed;
	}

	return allowedPresetSlugs.slugs.has( parsed.slug ) ? trimmed : null;
}

function validatePresetBackedStyleValue(
	value,
	{ presetType, allowCustomProperty = false, fallbackValidator = '' },
	executionContract
) {
	const presetReference = validatePresetReferenceValue(
		value,
		presetType,
		executionContract
	);

	if ( presetReference ) {
		return presetReference;
	}

	if ( allowCustomProperty ) {
		const customProperty = validateCssCustomPropertyReference( value );

		if ( customProperty.valid ) {
			return customProperty.value;
		}
	}

	if (
		fallbackValidator &&
		presetTypeAllowsFreeformFallback( executionContract, presetType )
	) {
		return (
			validateFreeformStyleValueByKind( fallbackValidator, value )
				?.value || null
		);
	}

	return null;
}

function sanitizeScalarValue( value ) {
	if (
		typeof value === 'string' ||
		typeof value === 'number' ||
		typeof value === 'boolean'
	) {
		return value;
	}

	return null;
}

function validateSupportedScalarAttribute(
	value,
	supportPath,
	executionContract
) {
	if ( ! executionContractSupportsPath( executionContract, supportPath ) ) {
		return null;
	}

	return sanitizeScalarValue( value );
}

function validateTopLevelPresetAttribute(
	value,
	supportPath,
	presetType,
	featureKey,
	executionContract
) {
	if ( ! executionContractSupportsPath( executionContract, supportPath ) ) {
		return null;
	}

	if (
		featureKey &&
		! executionContractFeatureEnabled( executionContract, featureKey )
	) {
		return null;
	}

	return validateRawPresetSlug( value, presetType, executionContract );
}

function validateSpacingScalarValue( value, executionContract ) {
	return (
		validatePresetReferenceValue( value, 'spacing', executionContract ) ||
		validateFreeformStyleValueByKind( 'length-or-percentage', value )
			?.value ||
		null
	);
}

function validateSpacingBoxValue( value, dotPath, executionContract ) {
	const supportPath =
		dotPath === 'spacing.padding' ? 'spacing.padding' : 'spacing.margin';
	const featureKey = dotPath === 'spacing.padding' ? 'padding' : 'margin';

	if ( ! executionContractSupportsPath( executionContract, supportPath ) ) {
		return null;
	}

	if ( ! executionContractFeatureEnabled( executionContract, featureKey ) ) {
		return null;
	}

	if ( isPlainObject( value ) ) {
		const filtered = Object.fromEntries(
			Object.entries( value )
				.map( ( [ side, sideValue ] ) => [
					side,
					validateSpacingScalarValue( sideValue, executionContract ),
				] )
				.filter( ( [ , sideValue ] ) => sideValue !== null )
		);

		return Object.keys( filtered ).length > 0 ? filtered : null;
	}

	return validateSpacingScalarValue( value, executionContract );
}

function validateStyleLeafValue( dotPath, value, executionContract ) {
	const rules = {
		'color.background': {
			supportPath: 'color.background',
			featureKey: 'backgroundColor',
			presetType: 'color',
			allowCustomProperty: true,
			fallbackValidator: FREEFORM_STYLE_VALIDATORS.COLOR,
		},
		'color.text': {
			supportPath: 'color.text',
			featureKey: 'textColor',
			presetType: 'color',
			allowCustomProperty: true,
			fallbackValidator: FREEFORM_STYLE_VALIDATORS.COLOR,
		},
		'color.gradient': {
			supportPath: 'color.gradients',
			presetType: 'gradient',
			allowCustomProperty: true,
		},
		'color.duotone': {
			supportPath: 'filter.duotone',
			presetType: 'duotone',
		},
		'typography.fontSize': {
			supportPath: 'typography.fontSize',
			presetType: 'fontsize',
			allowCustomProperty: true,
			fallbackValidator: FREEFORM_STYLE_VALIDATORS.LENGTH_OR_PERCENTAGE,
		},
		'typography.fontFamily': {
			supportPath: [
				'typography.fontFamily',
				'typography.__experimentalFontFamily',
			],
			presetType: 'fontfamily',
			allowCustomProperty: true,
			fallbackValidator: FREEFORM_STYLE_VALIDATORS.FONT_FAMILY,
		},
		'typography.lineHeight': {
			supportPath: 'typography.lineHeight',
			featureKey: 'lineHeight',
			validator: FREEFORM_STYLE_VALIDATORS.LINE_HEIGHT,
		},
		'typography.fontStyle': {
			supportPath: 'typography.fontStyle',
			featureKey: 'fontStyle',
			validator: FREEFORM_STYLE_VALIDATORS.FONT_STYLE,
		},
		'typography.fontWeight': {
			supportPath: 'typography.fontWeight',
			featureKey: 'fontWeight',
			validator: FREEFORM_STYLE_VALIDATORS.FONT_WEIGHT,
		},
		'typography.letterSpacing': {
			supportPath: 'typography.letterSpacing',
			featureKey: 'letterSpacing',
			validator: FREEFORM_STYLE_VALIDATORS.LETTER_SPACING,
		},
		'typography.textDecoration': {
			supportPath: 'typography.textDecoration',
			featureKey: 'textDecoration',
			validator: FREEFORM_STYLE_VALIDATORS.TEXT_DECORATION,
		},
		'typography.textTransform': {
			supportPath: 'typography.textTransform',
			featureKey: 'textTransform',
			validator: FREEFORM_STYLE_VALIDATORS.TEXT_TRANSFORM,
		},
		'spacing.blockGap': {
			supportPath: 'spacing.blockGap',
			featureKey: 'blockGap',
			presetType: 'spacing',
			allowCustomProperty: true,
			fallbackValidator: FREEFORM_STYLE_VALIDATORS.LENGTH_OR_PERCENTAGE,
		},
		'border.color': {
			supportPath: 'border.color',
			featureKey: 'borderColor',
			presetType: 'color',
			allowCustomProperty: true,
			fallbackValidator: FREEFORM_STYLE_VALIDATORS.COLOR,
		},
		'border.radius': {
			supportPath: 'border.radius',
			featureKey: 'borderRadius',
			validator: FREEFORM_STYLE_VALIDATORS.LENGTH_OR_PERCENTAGE,
		},
		'border.style': {
			supportPath: 'border.style',
			featureKey: 'borderStyle',
			validator: FREEFORM_STYLE_VALIDATORS.BORDER_STYLE,
		},
		'border.width': {
			supportPath: 'border.width',
			featureKey: 'borderWidth',
			validator: FREEFORM_STYLE_VALIDATORS.LENGTH,
		},
		shadow: {
			supportPath: 'shadow',
			presetType: 'shadow',
			allowCustomProperty: true,
			fallbackValidator: FREEFORM_STYLE_VALIDATORS.SHADOW,
		},
		'background.backgroundImage': {
			supportPath: 'background.backgroundImage',
			featureKey: 'backgroundImage',
		},
		'background.backgroundSize': {
			supportPath: 'background.backgroundSize',
			featureKey: 'backgroundSize',
		},
	};
	const rule = rules[ dotPath ];

	if ( ! rule ) {
		return null;
	}

	const supportPaths = Array.isArray( rule.supportPath )
		? rule.supportPath
		: [ rule.supportPath ];

	if (
		! supportPaths.some( ( supportPath ) =>
			executionContractSupportsPath( executionContract, supportPath )
		)
	) {
		return null;
	}

	if (
		rule.featureKey &&
		! executionContractFeatureEnabled( executionContract, rule.featureKey )
	) {
		return null;
	}

	if ( rule.presetType ) {
		return validatePresetBackedStyleValue( value, rule, executionContract );
	}

	if ( rule.validator ) {
		return validateFreeformStyleValueByKind( rule.validator, value )?.value;
	}

	return sanitizeScalarValue( value );
}

function filterStyleAttributeUpdatesForExecutionContract(
	styleUpdates,
	executionContract,
	path = []
) {
	if ( ! isPlainObject( styleUpdates ) ) {
		return {};
	}

	const filtered = {};

	for ( const [ key, value ] of Object.entries( styleUpdates ) ) {
		const nextPath = [ ...path, key ];
		const dotPath = nextPath.join( '.' );

		if ( dotPath === 'spacing.padding' || dotPath === 'spacing.margin' ) {
			const validated = validateSpacingBoxValue(
				value,
				dotPath,
				executionContract
			);

			if ( validated !== null ) {
				filtered[ key ] = validated;
			}

			continue;
		}

		if ( isPlainObject( value ) ) {
			const nested = filterStyleAttributeUpdatesForExecutionContract(
				value,
				executionContract,
				nextPath
			);

			if ( Object.keys( nested ).length > 0 ) {
				filtered[ key ] = nested;
			}

			continue;
		}

		const validated = validateStyleLeafValue(
			dotPath,
			value,
			executionContract
		);

		if ( validated !== null ) {
			filtered[ key ] = validated;
		}
	}

	return filtered;
}

function filterAttributeUpdatesForExecutionContract(
	attributeUpdates,
	executionContract,
	{ allowClassName = false } = {}
) {
	if ( ! isPlainObject( attributeUpdates ) ) {
		return {};
	}

	const filtered = {};
	const allowedLocalAttributes =
		getAllowedLocalAttributeKeys( executionContract );

	for ( const [ key, value ] of Object.entries( attributeUpdates ) ) {
		let validated = value;

		switch ( key ) {
			case 'lock':
				validated = null;
				break;
			case 'className':
				validated =
					allowClassName && typeof value === 'string'
						? normalizeRegisteredStyleVariationClassName(
								value,
								executionContract
						  )
						: null;
				break;
			case 'metadata':
				validated = filterMetadataAttributeUpdatesForExecutionContract(
					value,
					executionContract
				);
				break;
			case 'backgroundColor':
				validated = validateTopLevelPresetAttribute(
					value,
					'color.background',
					'color',
					'backgroundColor',
					executionContract
				);
				break;
			case 'textColor':
				validated = validateTopLevelPresetAttribute(
					value,
					'color.text',
					'color',
					'textColor',
					executionContract
				);
				break;
			case 'gradient':
				validated = validateTopLevelPresetAttribute(
					value,
					'color.gradients',
					'gradient',
					null,
					executionContract
				);
				break;
			case 'fontSize':
				validated = validateTopLevelPresetAttribute(
					value,
					'typography.fontSize',
					'fontsize',
					null,
					executionContract
				);
				break;
			case 'textAlign':
				validated = validateSupportedScalarAttribute(
					value,
					'typography.textAlign',
					executionContract
				);
				break;
			case 'minHeight':
				validated = validateSupportedScalarAttribute(
					value,
					'dimensions.minHeight',
					executionContract
				);
				break;
			case 'minHeightUnit':
				validated = validateSupportedScalarAttribute(
					value,
					'dimensions.minHeight',
					executionContract
				);
				break;
			case 'height':
				validated = validateSupportedScalarAttribute(
					value,
					'dimensions.height',
					executionContract
				);
				break;
			case 'width':
				validated = validateSupportedScalarAttribute(
					value,
					'dimensions.width',
					executionContract
				);
				break;
			case 'aspectRatio':
				validated = validateSupportedScalarAttribute(
					value,
					'dimensions.aspectRatio',
					executionContract
				);
				break;
			case 'style':
				validated = filterStyleAttributeUpdatesForExecutionContract(
					value,
					executionContract
				);
				break;
			default:
				validated = allowedLocalAttributes.has( key ) ? value : null;
				break;
		}

		if (
			validated === null ||
			( isPlainObject( validated ) &&
				Object.keys( validated ).length === 0 )
		) {
			continue;
		}

		filtered[ key ] = validated;
	}

	return filtered;
}

function getAllowedLocalAttributeKeys( executionContract = {} ) {
	return new Set( [
		...( Array.isArray( executionContract?.contentAttributeKeys )
			? executionContract.contentAttributeKeys
			: [] ),
		...( Array.isArray( executionContract?.configAttributeKeys )
			? executionContract.configAttributeKeys
			: [] ),
	] );
}

function filterMetadataAttributeUpdatesForExecutionContract(
	metadata,
	executionContract = {}
) {
	if ( ! isPlainObject( metadata ) ) {
		return {};
	}

	const filtered = {};

	if (
		Object.prototype.hasOwnProperty.call( metadata, 'blockVisibility' ) &&
		( metadata.blockVisibility === false ||
			isPlainObject( metadata.blockVisibility ) )
	) {
		filtered.blockVisibility = metadata.blockVisibility;
	}

	if ( isPlainObject( metadata.bindings ) ) {
		const bindableAttributeKeys = getBindableAttributeKeys(
			{},
			executionContract
		);

		if ( Array.isArray( bindableAttributeKeys ) ) {
			const allowedKeys = new Set( bindableAttributeKeys );
			const filteredBindings = Object.fromEntries(
				Object.entries( metadata.bindings ).filter( ( [ key ] ) =>
					allowedKeys.has( key )
				)
			);

			if ( Object.keys( filteredBindings ).length > 0 ) {
				filtered.bindings = filteredBindings;
			}
		} else {
			filtered.bindings = metadata.bindings;
		}
	}

	return filtered;
}

function normalizeStyleName( value = '' ) {
	return typeof value === 'string'
		? value
				.trim()
				.toLowerCase()
				.replace( /[^a-z0-9_-]/g, '' )
		: '';
}

function normalizeRegisteredStyleVariationClassName(
	className = '',
	executionContract = {}
) {
	const registeredStyleNames = Array.isArray(
		executionContract?.registeredStyles
	)
		? executionContract.registeredStyles.map( normalizeStyleName )
		: [];
	const registeredStyles = new Set( registeredStyleNames.filter( Boolean ) );
	const tokens =
		typeof className === 'string' ? className.trim().split( /\s+/ ) : [];
	const styleClasses = [];

	if ( registeredStyles.size === 0 || tokens.length === 0 ) {
		return null;
	}

	for ( const token of tokens ) {
		const match = token.match( /^is-style-([a-z0-9_-]+)$/i );

		if ( ! match ) {
			return null;
		}

		const styleName = normalizeStyleName( match[ 1 ] );

		if ( ! registeredStyles.has( styleName ) ) {
			return null;
		}

		styleClasses.push( `is-style-${ styleName }` );
	}

	const distinct = [ ...new Set( styleClasses ) ];

	return distinct.length === 1 ? distinct[ 0 ] : null;
}

function isValidStyleVariationSuggestion( suggestion, executionContract ) {
	const className = suggestion?.attributeUpdates?.className;

	if ( typeof className !== 'string' ) {
		return false;
	}

	return Boolean(
		normalizeRegisteredStyleVariationClassName(
			className,
			executionContract
		)
	);
}

function getSuggestionPanel( suggestion = {} ) {
	return typeof suggestion?.panel === 'string'
		? normalizePanelKey( suggestion.panel )
		: '';
}

function suggestionHasValidExecutionPanel(
	suggestion = {},
	executionContract = {}
) {
	const isAdvisoryOnly = isAdvisoryOnlyBlockSuggestion( suggestion );
	const hasExplicitlyEmptyPanels =
		executionContract?.hasExplicitlyEmptyPanels === true;
	const shouldEnforceBlockPanels =
		hasExplicitlyEmptyPanels ||
		executionContractKnowsPanelMapping( executionContract );

	if ( isAdvisoryOnly || ! shouldEnforceBlockPanels ) {
		return true;
	}

	const panel = getSuggestionPanel( suggestion );
	const allowedPanels = getAllowedPanelsLookup( executionContract );

	if ( suggestion?.type === 'style_variation' ) {
		return ! panel || Boolean( allowedPanels[ panel ] );
	}

	return Boolean( panel && allowedPanels[ panel ] );
}

function sanitizeSuggestionForExecutionContract(
	suggestion,
	group,
	executionContract
) {
	if ( ! suggestion || typeof suggestion !== 'object' ) {
		return null;
	}

	const isAdvisoryOnly =
		group === 'block' && isAdvisoryOnlyBlockSuggestion( suggestion );
	const isStyleVariation = suggestion?.type === 'style_variation';
	const panel = getSuggestionPanel( suggestion );
	const allowedPanels = getAllowedPanelsLookup( executionContract );
	const hasExplicitlyEmptyPanels =
		executionContract?.hasExplicitlyEmptyPanels === true;
	const shouldEnforcePanels =
		hasExplicitlyEmptyPanels ||
		Object.keys( allowedPanels ).length > 0 ||
		executionContractKnowsPanelMapping( executionContract );
	const shouldEnforceBlockPanels =
		hasExplicitlyEmptyPanels ||
		executionContractKnowsPanelMapping( executionContract );

	if ( group === 'settings' || group === 'styles' ) {
		if ( shouldEnforcePanels && ( ! panel || ! allowedPanels[ panel ] ) ) {
			return null;
		}
	} else if (
		group === 'block' &&
		! isAdvisoryOnly &&
		shouldEnforceBlockPanels
	) {
		if ( isStyleVariation ) {
			if ( panel && ! allowedPanels[ panel ] ) {
				return null;
			}
		} else if ( ! panel || ! allowedPanels[ panel ] ) {
			return null;
		}
	}

	if (
		group === 'block' &&
		! isAdvisoryOnly &&
		hasExplicitlyEmptyPanels &&
		! isStyleVariation
	) {
		return null;
	}

	if (
		isStyleVariation &&
		! isValidStyleVariationSuggestion( suggestion, executionContract )
	) {
		return null;
	}

	if ( ! isPlainObject( suggestion?.attributeUpdates ) ) {
		return group === 'block' ? suggestion : null;
	}

	const filteredUpdates = filterAttributeUpdatesForExecutionContract(
		suggestion.attributeUpdates,
		executionContract,
		{ allowClassName: isStyleVariation }
	);

	if ( Object.keys( filteredUpdates ).length === 0 ) {
		return isAdvisoryOnly
			? normalizeBlockSuggestionForExecution( suggestion )
			: null;
	}

	return {
		...suggestion,
		attributeUpdates: filteredUpdates,
	};
}

function sanitizeSuggestionGroupForExecutionContract(
	suggestions,
	group,
	executionContract
) {
	return suggestions
		.map( ( suggestion ) =>
			sanitizeSuggestionForExecutionContract(
				suggestion,
				group,
				executionContract
			)
		)
		.filter( Boolean );
}

function attachBlockSuggestionActionability(
	suggestions,
	blockContext,
	executionContract
) {
	const resolvedExecutionContract = resolveExecutionContract(
		blockContext,
		executionContract
	);
	const restrictions = getEditingRestrictions(
		blockContext,
		resolvedExecutionContract
	);

	return suggestions.map( ( suggestion ) => {
		const normalizedSuggestion =
			normalizeBlockSuggestionForExecution( suggestion );
		const operationValidation = resolveBlockOperationValidation(
			normalizedSuggestion,
			blockContext
		);
		const advisoryOnly =
			isAdvisoryOnlyBlockSuggestion( normalizedSuggestion );
		const allowedUpdates = advisoryOnly
			? {}
			: getSuggestionAttributeUpdates(
					normalizedSuggestion,
					blockContext,
					resolvedExecutionContract
			  );
		const suggestionWithOperations = {
			...normalizedSuggestion,
			operations: operationValidation.operations,
			rejectedOperations: operationValidation.rejectedOperations,
		};
		const actionability = classifyBlockSuggestionActionability( {
			suggestion: suggestionWithOperations,
			allowedUpdates,
			isAdvisoryOnly: advisoryOnly,
			restrictions,
			operationValidation,
		} );

		return withComputedActionability(
			suggestionWithOperations,
			actionability
		);
	} );
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
		} else if ( key === 'className' && typeof value === 'string' ) {
			safeUpdates[ key ] = mergeStyleVariationClassNameWithCurrent(
				currentAttributes[ key ],
				value
			);
		} else {
			safeUpdates[ key ] = value;
		}
	}

	return safeUpdates;
}

function mergeStyleVariationClassNameWithCurrent(
	currentClassName,
	suggestedClassName
) {
	const suggestedTokens =
		typeof suggestedClassName === 'string'
			? suggestedClassName.trim().split( /\s+/ ).filter( Boolean )
			: [];
	const currentTokens =
		typeof currentClassName === 'string'
			? currentClassName.trim().split( /\s+/ ).filter( Boolean )
			: [];
	const preservedTokens = currentTokens.filter(
		( token ) => ! /^is-style-/i.test( token )
	);
	const merged = [];
	const seen = new Set();

	for ( const token of [ ...preservedTokens, ...suggestedTokens ] ) {
		if ( ! seen.has( token ) ) {
			seen.add( token );
			merged.push( token );
		}
	}

	return merged.join( ' ' );
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

export function hasRecordedAttributeSnapshot( snapshot = {} ) {
	return isPlainObject( snapshot ) && Object.keys( snapshot ).length > 0;
}

/**
 * @param {Object} storedSnapshot  Recorded attribute snapshot.
 * @param {Object} currentSnapshot Current live attributes.
 * @return {boolean} Whether every recorded snapshot key still matches.
 */
export function recordedAttributeSnapshotMatchesCurrent(
	storedSnapshot = {},
	currentSnapshot = {}
) {
	if (
		! isPlainObject( storedSnapshot ) ||
		! isPlainObject( currentSnapshot )
	) {
		return false;
	}

	const storedKeys = Object.keys( storedSnapshot );

	if ( storedKeys.length === 0 ) {
		return false;
	}

	return storedKeys.every( ( key ) =>
		Object.prototype.hasOwnProperty.call( currentSnapshot, key )
			? areSnapshotValuesEqual(
					storedSnapshot[ key ],
					currentSnapshot[ key ]
			  )
			: isEmptyDefaultSnapshotValue( storedSnapshot[ key ] )
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
	if (
		! Object.prototype.hasOwnProperty.call(
			blockContext,
			'inspectorPanels'
		)
	) {
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

function hasExplicitlyEmptyPanelsForContext(
	blockContext = {},
	executionContract = null
) {
	if ( executionContract?.hasExplicitlyEmptyPanels === true ) {
		return true;
	}

	return hasExplicitlyEmptyInspectorPanels( blockContext );
}

function summarizeSuggestionCounts( counts = {} ) {
	const parts = [];

	if ( Number.isInteger( counts.settings ) && counts.settings > 0 ) {
		parts.push(
			`${ counts.settings } setting${ counts.settings === 1 ? '' : 's' }`
		);
	}

	if ( Number.isInteger( counts.styles ) && counts.styles > 0 ) {
		parts.push(
			`${ counts.styles } style${ counts.styles === 1 ? '' : 's' }`
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

function normalizePreFilteringCounts( counts = {} ) {
	if ( ! isPlainObject( counts ) ) {
		return {};
	}

	return Object.fromEntries(
		[ 'settings', 'styles', 'block' ]
			.map( ( key ) => {
				const value = counts[ key ];
				let numericValue = null;

				if ( typeof value === 'number' ) {
					numericValue = value;
				} else if ( typeof value === 'string' && value.trim() !== '' ) {
					numericValue = Number( value );
				}

				return Number.isFinite( numericValue )
					? [ key, Math.max( 0, Math.floor( numericValue ) ) ]
					: null;
			} )
			.filter( Boolean )
	);
}

/**
 * Build a diagnostic summary for successful block requests whose block lane
 * ends up empty after validation.
 *
 * @param {Object}  rawRecommendations       Recommendation payload before client sanitization.
 * @param {Object}  sanitizedRecommendations Recommendation payload after client sanitization.
 * @param {Object}  [blockContext={}]        Block context used for sanitization.
 * @param {?Object} executionContract        Server-normalized execution contract.
 * @return {?Object} Diagnostics for empty block-lane results.
 */
export function buildBlockRecommendationDiagnostics(
	rawRecommendations,
	sanitizedRecommendations,
	blockContext = {},
	executionContract = null
) {
	const finalCounts = getRecommendationGroupCounts(
		sanitizedRecommendations
	);

	if ( finalCounts.block > 0 ) {
		return null;
	}

	const rawCounts = getRecommendationGroupCounts( rawRecommendations );
	const preFilteringCounts = normalizePreFilteringCounts(
		rawRecommendations.preFilteringCounts
	);
	const normalized = normalizeSuggestionGroups( rawRecommendations );
	const normalizedBlockSuggestions = normalized.block.map( ( suggestion ) =>
		normalizeBlockSuggestionForExecution( suggestion )
	);
	const resolvedExecutionContract = resolveExecutionContract(
		blockContext,
		executionContract
	);
	const restrictions = getEditingRestrictions(
		blockContext,
		resolvedExecutionContract
	);
	const bindableAttributeKeys = getBindableAttributeKeys(
		blockContext,
		resolvedExecutionContract
	);
	const themeSafeBlockSuggestions = sanitizeSuggestionGroupForThemeSafety(
		normalizedBlockSuggestions
	);
	const executionContractSafeBlockSuggestions =
		sanitizeSuggestionGroupForExecutionContract(
			themeSafeBlockSuggestions,
			'block',
			resolvedExecutionContract
		);
	const bindingSafeBlockSuggestions =
		sanitizeSuggestionGroupForBindableAttributes(
			executionContractSafeBlockSuggestions,
			bindableAttributeKeys
		);
	let contentSafeBlockSuggestions = bindingSafeBlockSuggestions;
	const reasonCodes = [];
	const detailLines = [];

	if ( restrictions.disabled ) {
		contentSafeBlockSuggestions = [];
	} else if ( restrictions.contentOnly ) {
		if (
			usesInnerBlocksAsContent( blockContext, resolvedExecutionContract )
		) {
			contentSafeBlockSuggestions = bindingSafeBlockSuggestions.filter(
				( suggestion ) => isAdvisoryOnlyBlockSuggestion( suggestion )
			);
		} else {
			const contentAttributeKeys = getContentAttributeKeys(
				blockContext,
				resolvedExecutionContract
			);

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

	const modelBlockCount = preFilteringCounts.block ?? rawCounts.block;

	if ( modelBlockCount === 0 ) {
		reasonCodes.push( 'model_returned_no_block_items' );

		if ( rawCounts.settings > 0 || rawCounts.styles > 0 ) {
			reasonCodes.push( 'suggestions_routed_to_other_lanes' );
			detailLines.push(
				`Flavor Agent returned ${ summarizeSuggestionCounts(
					rawCounts
				) }, but none in the block lane.`
			);
		} else {
			detailLines.push(
				'Flavor Agent returned no block-lane suggestions for this request.'
			);
		}
	} else if ( rawCounts.block === 0 ) {
		reasonCodes.push( 'server_filters_removed_all_block_items' );
		detailLines.push(
			`Flavor Agent returned ${ modelBlockCount } block suggestion${
				modelBlockCount === 1 ? '' : 's'
			}, but server-side validation filtered all of them.`
		);
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
			executionContractSafeBlockSuggestions.length <
			themeSafeBlockSuggestions.length
		) {
			reasonCodes.push( 'execution_contract_removed_block_items' );
			detailLines.push(
				'At least one block suggestion was removed because it targeted an unsupported panel, style path, preset, or style variation for this block.'
			);
		}

		if (
			bindingSafeBlockSuggestions.length <
			executionContractSafeBlockSuggestions.length
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
			if (
				usesInnerBlocksAsContent(
					blockContext,
					resolvedExecutionContract
				)
			) {
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

	if (
		hasExplicitlyEmptyPanelsForContext(
			blockContext,
			resolvedExecutionContract
		)
	) {
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
			executionContractSafe: executionContractSafeBlockSuggestions.length,
			bindingSafe: bindingSafeBlockSuggestions.length,
			contentSafe: contentSafeBlockSuggestions.length,
		},
		actionabilitySummary: summarizeActionability(
			attachBlockSuggestionActionability(
				contentSafeBlockSuggestions,
				blockContext,
				resolvedExecutionContract
			).map( ( suggestion ) => suggestion.actionability )
		),
		restrictions: {
			disabled: restrictions.disabled,
			contentOnly: restrictions.contentOnly,
			usesInnerBlocksAsContent: usesInnerBlocksAsContent(
				blockContext,
				resolvedExecutionContract
			),
		},
	};
}

/**
 * @param {Object}  recommendations   Raw recommendations payload.
 * @param {Object}  [blockContext={}] Block context used to enforce locking rules.
 * @param {?Object} executionContract Server-normalized execution contract.
 * @return {Object} Normalized and filtered recommendation payload.
 */
export function sanitizeRecommendationsForContext(
	recommendations,
	blockContext = {},
	executionContract = null
) {
	const normalized = normalizeSuggestionGroups( recommendations );
	const normalizedBlockSuggestions = normalized.block.map( ( suggestion ) =>
		normalizeBlockSuggestionForExecution( suggestion )
	);
	const resolvedExecutionContract = resolveExecutionContract(
		blockContext,
		executionContract
	);
	const restrictions = getEditingRestrictions(
		blockContext,
		resolvedExecutionContract
	);
	const bindableAttributeKeys = getBindableAttributeKeys(
		blockContext,
		resolvedExecutionContract
	);
	const themeSafeRecommendations = {
		...normalized,
		settings: sanitizeSuggestionGroupForThemeSafety( normalized.settings ),
		styles: sanitizeSuggestionGroupForThemeSafety( normalized.styles ),
		block: sanitizeSuggestionGroupForThemeSafety(
			normalizedBlockSuggestions
		),
	};
	const executionContractSafeRecommendations = {
		...themeSafeRecommendations,
		settings: sanitizeSuggestionGroupForExecutionContract(
			themeSafeRecommendations.settings,
			'settings',
			resolvedExecutionContract
		),
		styles: sanitizeSuggestionGroupForExecutionContract(
			themeSafeRecommendations.styles,
			'styles',
			resolvedExecutionContract
		),
		block: sanitizeSuggestionGroupForExecutionContract(
			themeSafeRecommendations.block,
			'block',
			resolvedExecutionContract
		),
	};
	const bindingSafeRecommendations = {
		...executionContractSafeRecommendations,
		settings: sanitizeSuggestionGroupForBindableAttributes(
			executionContractSafeRecommendations.settings,
			bindableAttributeKeys
		),
		styles: sanitizeSuggestionGroupForBindableAttributes(
			executionContractSafeRecommendations.styles,
			bindableAttributeKeys
		),
		block: sanitizeSuggestionGroupForBindableAttributes(
			executionContractSafeRecommendations.block,
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
		return {
			...bindingSafeRecommendations,
			block: attachBlockSuggestionActionability(
				bindingSafeRecommendations.block,
				blockContext,
				resolvedExecutionContract
			),
		};
	}

	if ( usesInnerBlocksAsContent( blockContext, resolvedExecutionContract ) ) {
		const blockSuggestions = bindingSafeRecommendations.block.filter(
			( suggestion ) => isAdvisoryOnlyBlockSuggestion( suggestion )
		);

		return {
			...bindingSafeRecommendations,
			settings: [],
			styles: [],
			block: attachBlockSuggestionActionability(
				blockSuggestions,
				blockContext,
				resolvedExecutionContract
			),
		};
	}

	const contentAttributeKeys = getContentAttributeKeys(
		blockContext,
		resolvedExecutionContract
	);

	return {
		...bindingSafeRecommendations,
		settings: [],
		styles: [],
		block: attachBlockSuggestionActionability(
			bindingSafeRecommendations.block
				.map( ( suggestion ) =>
					filterSuggestionForContentOnly(
						suggestion,
						contentAttributeKeys
					)
				)
				.filter( Boolean ),
			blockContext,
			resolvedExecutionContract
		),
	};
}

/**
 * @param {Object}  suggestion        Suggestion candidate.
 * @param {Object}  [blockContext={}] Block context used to enforce locking rules.
 * @param {?Object} executionContract Server-normalized execution contract.
 * @return {Object} Attribute updates allowed for the current block context.
 */
export function getSuggestionAttributeUpdates(
	suggestion,
	blockContext = {},
	executionContract = null
) {
	if ( ! isPlainObject( suggestion?.attributeUpdates ) ) {
		return {};
	}

	const resolvedExecutionContract = resolveExecutionContract(
		blockContext,
		executionContract
	);
	const restrictions = getEditingRestrictions(
		blockContext,
		resolvedExecutionContract
	);

	if ( restrictions.disabled ) {
		return {};
	}

	if (
		suggestion?.type === 'style_variation' &&
		! isValidStyleVariationSuggestion(
			suggestion,
			resolvedExecutionContract
		)
	) {
		return {};
	}

	const themeSafeUpdates = filterThemeSafeAttributeUpdates(
		suggestion.attributeUpdates
	);

	if ( Object.keys( themeSafeUpdates ).length === 0 ) {
		return {};
	}

	const executionContractSafeUpdates =
		filterAttributeUpdatesForExecutionContract(
			themeSafeUpdates,
			resolvedExecutionContract,
			{ allowClassName: suggestion?.type === 'style_variation' }
		);

	if ( Object.keys( executionContractSafeUpdates ).length === 0 ) {
		return {};
	}

	const bindingSafeUpdates = filterAttributeUpdatesForBindableAttributes(
		executionContractSafeUpdates,
		getBindableAttributeKeys( blockContext, resolvedExecutionContract )
	);

	if ( ! restrictions.contentOnly ) {
		return bindingSafeUpdates;
	}

	if ( usesInnerBlocksAsContent( blockContext, resolvedExecutionContract ) ) {
		return {};
	}

	return filterAttributeUpdatesForContentOnly(
		bindingSafeUpdates,
		getContentAttributeKeys( blockContext, resolvedExecutionContract )
	);
}

/**
 * @param {Object}  suggestion        Suggestion candidate.
 * @param {Object}  [blockContext={}] Block context used to enforce locking rules.
 * @param {?Object} executionContract Server-normalized execution contract.
 * @return {{ allowedUpdates: Object, actionability: Object, eligibility: Object, isAdvisory: boolean, isAdvisoryOnly: boolean, isExecutable: boolean }}
 * Block suggestion execution metadata.
 */
export function getBlockSuggestionExecutionInfo(
	suggestion,
	blockContext = {},
	executionContract = null
) {
	const normalizedSuggestion =
		normalizeBlockSuggestionForExecution( suggestion );
	const operationValidation = resolveBlockOperationValidation(
		normalizedSuggestion,
		blockContext
	);
	const advisoryOnly = isAdvisoryOnlyBlockSuggestion( normalizedSuggestion );
	const resolvedExecutionContract = resolveExecutionContract(
		blockContext,
		executionContract
	);
	const hasValidExecutionPanel = suggestionHasValidExecutionPanel(
		normalizedSuggestion,
		resolvedExecutionContract
	);
	const allowedUpdates =
		advisoryOnly || ! hasValidExecutionPanel
			? {}
			: getSuggestionAttributeUpdates(
					normalizedSuggestion,
					blockContext,
					resolvedExecutionContract
			  );
	const hasExecutableUpdates = Object.keys( allowedUpdates ).length > 0;
	const hasReviewOperation = operationValidation.operations.length === 1;
	const restrictions = getEditingRestrictions(
		blockContext,
		resolvedExecutionContract
	);
	const actionability = classifyBlockSuggestionActionability( {
		suggestion: {
			...normalizedSuggestion,
			operations: operationValidation.operations,
			rejectedOperations: operationValidation.rejectedOperations,
		},
		allowedUpdates,
		isAdvisoryOnly: advisoryOnly,
		restrictions,
		operationValidation,
	} );

	return {
		allowedUpdates,
		actionability,
		eligibility: actionability,
		isAdvisory:
			( advisoryOnly && ! hasReviewOperation ) ||
			( ! hasExecutableUpdates && ! hasReviewOperation ),
		isAdvisoryOnly: advisoryOnly,
		isExecutable: ! advisoryOnly && hasExecutableUpdates,
	};
}
