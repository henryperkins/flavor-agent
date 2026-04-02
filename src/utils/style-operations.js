import { dispatch, select } from '@wordpress/data';

import { buildGlobalStylesExecutionContractFromSettings } from '../context/theme-tokens';

function getCoreSelect( registry ) {
	return registry?.select?.( 'core' ) || select( 'core' ) || {};
}

function getBlockEditorSelect( registry ) {
	return (
		registry?.select?.( 'core/block-editor' ) ||
		select( 'core/block-editor' ) ||
		{}
	);
}

function getCoreDispatch( registry ) {
	return registry?.dispatch?.( 'core' ) || dispatch( 'core' ) || {};
}

function cloneConfig( value ) {
	return JSON.parse( JSON.stringify( value || {} ) );
}

function normalizeConfig( value = {} ) {
	return {
		settings: cloneConfig( value?.settings || {} ),
		styles: cloneConfig( value?.styles || {} ),
		_links: cloneConfig( value?._links || {} ),
	};
}

function sortObjectKeysDeep( value ) {
	if ( Array.isArray( value ) ) {
		return value.map( sortObjectKeysDeep );
	}

	if ( value && typeof value === 'object' ) {
		return Object.keys( value )
			.sort()
			.reduce( ( sorted, key ) => {
				sorted[ key ] = sortObjectKeysDeep( value[ key ] );
				return sorted;
			}, {} );
	}

	return value;
}

function normalizeOperationValue( value ) {
	if ( Array.isArray( value ) ) {
		return value.map( normalizeOperationValue );
	}

	if ( value && typeof value === 'object' ) {
		return Object.fromEntries(
			Object.entries( value ).map( ( [ key, entryValue ] ) => [
				key,
				normalizeOperationValue( entryValue ),
			] )
		);
	}

	return value;
}

function getStylePathKey( path = [] ) {
	return Array.isArray( path ) ? path.join( '.' ) : '';
}

function sanitizeKey( value ) {
	return String( value || '' )
		.trim()
		.toLowerCase()
		.replace( /[^a-z0-9_-]/g, '' );
}

function normalizePresetType( value ) {
	return sanitizeKey( value ).replaceAll( '-', '' );
}

function displayPresetType( presetType ) {
	switch ( normalizePresetType( presetType ) ) {
		case 'fontsize':
			return 'font-size';
		case 'fontfamily':
			return 'font-family';
		default:
			return sanitizeKey( presetType );
	}
}

function parsePresetValue( value ) {
	if ( typeof value !== 'string' ) {
		return null;
	}

	const matches = value.match(
		/^var:preset\|([a-z0-9-]+)\|([a-z0-9_-]+)$/i
	);

	if ( ! matches ) {
		return null;
	}

	const type = normalizePresetType( matches[ 1 ] );
	const slug = sanitizeKey( matches[ 2 ] );

	if ( ! type || ! slug ) {
		return null;
	}

	return { type, slug };
}

function buildPresetValue( presetType, presetSlug ) {
	return `var:preset|${ displayPresetType( presetType ) }|${ presetSlug }`;
}

function buildPresetCssVar( presetType, presetSlug ) {
	return `var(--wp--preset--${ displayPresetType( presetType ) }--${ presetSlug })`;
}

function isPositiveNumberString( value ) {
	return /^(?:\d+|\d*\.\d+)$/.test( value ) && Number( value ) > 0;
}

function isPositiveCssLength( value, { allowPercentage = false } = {} ) {
	const pattern = allowPercentage
		? /^(?:\d+|\d*\.\d+)(?:px|em|rem|vh|vw|vmin|vmax|svh|lvh|dvh|svw|lvw|dvw|ch|ex|cap|ic|lh|rlh|cm|mm|q|in|pt|pc|%)$/i
		: /^(?:\d+|\d*\.\d+)(?:px|em|rem|vh|vw|vmin|vmax|svh|lvh|dvh|svw|lvw|dvw|ch|ex|cap|ic|lh|rlh|cm|mm|q|in|pt|pc)$/i;

	return pattern.test( value ) && Number.parseFloat( value ) > 0;
}

function isZeroCssLength( value, { allowPercentage = false } = {} ) {
	const pattern = allowPercentage
		? /^0(?:\.0+)?(?:px|em|rem|vh|vw|vmin|vmax|svh|lvh|dvh|svw|lvw|dvw|ch|ex|cap|ic|lh|rlh|cm|mm|q|in|pt|pc|%)?$/i
		: /^0(?:\.0+)?(?:px|em|rem|vh|vw|vmin|vmax|svh|lvh|dvh|svw|lvw|dvw|ch|ex|cap|ic|lh|rlh|cm|mm|q|in|pt|pc)?$/i;

	return pattern.test( value );
}

function validateLineHeightValue( value ) {
	if ( typeof value === 'number' ) {
		return value > 0
			? { valid: true, value }
			: { valid: false, value: null };
	}

	if ( typeof value !== 'string' ) {
		return { valid: false, value: null };
	}

	const normalizedValue = value.trim();

	if ( ! normalizedValue ) {
		return { valid: false, value: null };
	}

	if (
		isPositiveNumberString( normalizedValue ) ||
		isPositiveCssLength( normalizedValue, {
			allowPercentage: true,
		} )
	) {
		return {
			valid: true,
			value: normalizedValue,
		};
	}

	return { valid: false, value: null };
}

function validateLengthValue( value, { allowPercentage = false } = {} ) {
	if ( typeof value === 'number' ) {
		return value === 0
			? { valid: true, value }
			: { valid: false, value: null };
	}

	if ( typeof value !== 'string' ) {
		return { valid: false, value: null };
	}

	const normalizedValue = value.trim();

	if ( ! normalizedValue ) {
		return { valid: false, value: null };
	}

	if (
		isZeroCssLength( normalizedValue, {
			allowPercentage,
		} ) ||
		isPositiveCssLength( normalizedValue, {
			allowPercentage,
		} )
	) {
		return {
			valid: true,
			value: normalizedValue,
		};
	}

	return { valid: false, value: null };
}

function validateBorderStyleValue( value ) {
	if ( typeof value !== 'string' ) {
		return { valid: false, value: null };
	}

	const normalizedValue = value.trim().toLowerCase();
	const allowedValues = new Set( [
		'none',
		'solid',
		'dashed',
		'dotted',
		'double',
		'groove',
		'ridge',
		'inset',
		'outset',
		'hidden',
	] );

	if ( ! allowedValues.has( normalizedValue ) ) {
		return { valid: false, value: null };
	}

	return {
		valid: true,
		value: normalizedValue,
	};
}

function validateFreeformStyleValue( path = [], value ) {
	const pathKey = getStylePathKey( path );

	switch ( pathKey ) {
		case 'typography.lineHeight':
			return validateLineHeightValue( value );
		case 'border.radius':
			return validateLengthValue( value, {
				allowPercentage: true,
			} );
		case 'border.width':
			return validateLengthValue( value );
		case 'border.style':
			return validateBorderStyleValue( value );
		default:
			return {
				valid: false,
				value: null,
				error: `Unsupported freeform Global Styles path: ${
					pathKey || 'unknown'
				}.`,
			};
	}
}

function normalizeComparableConfigBranch( value = {} ) {
	if ( ! value || typeof value !== 'object' || Array.isArray( value ) ) {
		return {};
	}

	return sortObjectKeysDeep( normalizeOperationValue( value ) );
}

function normalizeComparableVariation( variation = {} ) {
	return sortObjectKeysDeep( {
		title:
			typeof variation?.title === 'string' ? variation.title.trim() : '',
		description:
			typeof variation?.description === 'string'
				? variation.description.trim()
				: '',
		settings: normalizeComparableConfigBranch( variation?.settings || {} ),
		styles: normalizeComparableConfigBranch( variation?.styles || {} ),
	} );
}

function normalizeComparableExecutionContract( executionContract = {} ) {
	const supportedStylePaths = Array.isArray(
		executionContract?.supportedStylePaths
	)
		? executionContract.supportedStylePaths
				.filter(
					( pathEntry ) =>
						Array.isArray( pathEntry?.path ) &&
						pathEntry.path.length > 0
				)
				.map( ( pathEntry ) => ( {
					path: [ ...pathEntry.path ],
					valueSource:
						typeof pathEntry?.valueSource === 'string'
							? pathEntry.valueSource
							: '',
				} ) )
				.sort( ( left, right ) => {
					const pathComparison = getStylePathKey(
						left.path
					).localeCompare( getStylePathKey( right.path ) );

					if ( pathComparison !== 0 ) {
						return pathComparison;
					}

					return String( left.valueSource ).localeCompare(
						String( right.valueSource )
					);
				} )
		: [];
	const presetSlugs = Object.fromEntries(
		Object.entries( executionContract?.presetSlugs || {} )
			.sort( ( [ leftKey ], [ rightKey ] ) =>
				leftKey.localeCompare( rightKey )
			)
			.map( ( [ key, slugs ] ) => [
				key,
				Array.isArray( slugs )
					? [ ...new Set( slugs.filter( Boolean ) ) ].sort()
					: [],
			] )
	);

	return {
		supportedStylePaths,
		presetSlugs,
	};
}

function findSupportedStylePathEntry( path = [], executionContract = {} ) {
	const pathKey = getStylePathKey( path );

	return (
		( executionContract?.supportedStylePaths || [] ).find(
			( pathEntry ) => getStylePathKey( pathEntry?.path ) === pathKey
		) || null
	);
}

function getPresetSlugsForType( executionContract = {}, presetType ) {
	return Array.isArray(
		executionContract?.presetSlugs?.[ normalizePresetType( presetType ) ]
	)
		? executionContract.presetSlugs[ normalizePresetType( presetType ) ]
		: [];
}

export function getComparableGlobalStylesConfig( value = {} ) {
	return {
		settings: normalizeComparableConfigBranch( value?.settings || {} ),
		styles: normalizeComparableConfigBranch( value?.styles || {} ),
	};
}

export function buildGlobalStylesRecommendationContextSignature( {
	scope,
	currentConfig,
	mergedConfig,
	availableVariations,
	themeTokenDiagnostics,
	executionContract,
} ) {
	return JSON.stringify(
		sortObjectKeysDeep( {
			scopeKey: scope?.scopeKey || '',
			globalStylesId: scope?.globalStylesId || '',
			stylesheet: scope?.stylesheet || '',
			currentConfig: getComparableGlobalStylesConfig( currentConfig ),
			mergedConfig: getComparableGlobalStylesConfig( mergedConfig ),
			availableVariations: Array.isArray( availableVariations )
				? availableVariations.map( normalizeComparableVariation )
				: [],
			themeTokenDiagnostics: sortObjectKeysDeep(
				normalizeOperationValue( themeTokenDiagnostics || {} )
			),
			executionContract:
				normalizeComparableExecutionContract( executionContract ),
		} )
	);
}

function mergeConfigValue( baseValue, overrideValue ) {
	if ( overrideValue === undefined ) {
		return normalizeOperationValue( baseValue );
	}

	if (
		baseValue &&
		typeof baseValue === 'object' &&
		! Array.isArray( baseValue ) &&
		overrideValue &&
		typeof overrideValue === 'object' &&
		! Array.isArray( overrideValue )
	) {
		const merged = { ...normalizeOperationValue( baseValue ) };

		for ( const [ key, value ] of Object.entries( overrideValue ) ) {
			merged[ key ] = mergeConfigValue( baseValue?.[ key ], value );
		}

		return merged;
	}

	return normalizeOperationValue( overrideValue );
}

function readPath( value, path = [] ) {
	let current = value;

	for ( const segment of path ) {
		if ( ! current || typeof current !== 'object' ) {
			return undefined;
		}

		current = current[ segment ];
	}

	return current;
}

function writePath( value, path = [], nextValue ) {
	if ( path.length === 0 ) {
		return nextValue;
	}

	if ( ! value || typeof value !== 'object' || Array.isArray( value ) ) {
		value = {};
	}

	const [ head, ...rest ] = path;
	const next = Array.isArray( value ) ? [ ...value ] : { ...value };
	next[ head ] = writePath( next[ head ], rest, nextValue );

	return next;
}

function getCurrentGlobalStylesId( coreSelect ) {
	const id = coreSelect?.__experimentalGetCurrentGlobalStylesId?.();

	if ( typeof id === 'string' && id ) {
		return id;
	}

	if ( Number.isInteger( id ) && id > 0 ) {
		return String( id );
	}

	return null;
}

function getCurrentUserConfig( coreSelect, globalStylesId ) {
	const record =
		coreSelect?.getEditedEntityRecord?.(
			'root',
			'globalStyles',
			globalStylesId
		) ||
		coreSelect?.getEntityRecord?.(
			'root',
			'globalStyles',
			globalStylesId
		) ||
		null;

	return record ? normalizeConfig( record ) : null;
}

function getCurrentMergedConfig( coreSelect, userConfig ) {
	const baseConfig =
		coreSelect?.__experimentalGetCurrentThemeBaseGlobalStyles?.() || null;

	if ( ! baseConfig ) {
		return normalizeConfig( userConfig );
	}

	return normalizeConfig( {
		settings: mergeConfigValue(
			baseConfig?.settings || {},
			userConfig?.settings || {}
		),
		styles: mergeConfigValue(
			baseConfig?.styles || {},
			userConfig?.styles || {}
		),
		_links: mergeConfigValue(
			baseConfig?._links || {},
			userConfig?._links || {}
		),
	} );
}

function resolveVariation( operation = {}, variations = [] ) {
	const variationIndex = Number.isInteger( operation?.variationIndex )
		? operation.variationIndex
		: Number( operation?.variationIndex );
	const variationTitle =
		typeof operation?.variationTitle === 'string'
			? operation.variationTitle.trim()
			: '';
	const indexedVariation = Number.isInteger( variationIndex )
		? variations[ variationIndex ] || null
		: null;

	if (
		indexedVariation &&
		( ! variationTitle || indexedVariation?.title === variationTitle )
	) {
		return indexedVariation;
	}

	if ( ! variationTitle ) {
		return null;
	}

	return (
		variations.find(
			( variation ) => variation?.title?.trim?.() === variationTitle
		) || null
	);
}

function normalizeOperations( operations = [] ) {
	const normalized = Array.isArray( operations )
		? operations.filter( Boolean ).map( ( operation ) => ( {
				...operation,
				path: Array.isArray( operation?.path ) ? operation.path : [],
				value: normalizeOperationValue( operation?.value ),
		  } ) )
		: [];
	let themeVariation = null;
	const orderedOperations = [];

	for ( const operation of normalized ) {
		if ( operation?.type === 'set_theme_variation' ) {
			if ( ! themeVariation ) {
				themeVariation = operation;
			}

			continue;
		}

		orderedOperations.push( operation );
	}

	return themeVariation
		? [ themeVariation, ...orderedOperations ]
		: orderedOperations;
}

function getGlobalStylesRuntime( registry ) {
	const coreSelect = getCoreSelect( registry );
	const globalStylesId = getCurrentGlobalStylesId( coreSelect );

	if ( ! globalStylesId ) {
		return {
			ok: false,
			error: 'The Site Editor did not expose a current Global Styles entity.',
		};
	}

	const coreDispatch = getCoreDispatch( registry );

	if ( typeof coreDispatch?.editEntityRecord !== 'function' ) {
		return {
			ok: false,
			error: 'The Site Editor could not update the current Global Styles entity.',
		};
	}

	const userConfig = getCurrentUserConfig( coreSelect, globalStylesId );

	if ( ! userConfig ) {
		return {
			ok: false,
			error: 'The Site Editor could not resolve the current Global Styles config.',
		};
	}

	return {
		ok: true,
		coreDispatch,
		coreSelect,
		globalStylesId,
		userConfig,
	};
}

function getGlobalStylesExecutionContract( registry ) {
	const blockEditorSelect = getBlockEditorSelect( registry );

	if ( typeof blockEditorSelect?.getSettings !== 'function' ) {
		return {
			ok: false,
			error: 'The Site Editor could not resolve the current Global Styles execution contract.',
		};
	}

	return {
		ok: true,
		executionContract: buildGlobalStylesExecutionContractFromSettings(
			blockEditorSelect.getSettings?.() || {}
		),
	};
}

function configsMatch( left, right ) {
	return (
		JSON.stringify( getComparableGlobalStylesConfig( left ) ) ===
		JSON.stringify( getComparableGlobalStylesConfig( right ) )
	);
}

function validatePresetStyleOperation(
	operation = {},
	pathEntry = {},
	executionContract = {}
) {
	const expectedPresetType = normalizePresetType( pathEntry?.valueSource );
	const pathKey = getStylePathKey( operation?.path );
	const valueType = sanitizeKey( operation?.valueType );
	const presetType = normalizePresetType( operation?.presetType );
	const presetSlug = sanitizeKey( operation?.presetSlug );
	const parsedPresetValue = parsePresetValue( operation?.value );

	if ( valueType !== 'preset' ) {
		return {
			ok: false,
			error: `The suggested Global Styles value for ${ pathKey } must use a theme preset. Global Styles changed; regenerate suggestions.`,
		};
	}

	if ( presetType !== expectedPresetType || ! presetSlug ) {
		return {
			ok: false,
			error: `The suggested Global Styles preset metadata for ${ pathKey } no longer matches the live theme contract. Global Styles changed; regenerate suggestions.`,
		};
	}

	if (
		! parsedPresetValue ||
		parsedPresetValue.type !== expectedPresetType ||
		parsedPresetValue.slug !== presetSlug
	) {
		return {
			ok: false,
			error: `The suggested Global Styles preset reference for ${ pathKey } no longer matches its metadata. Global Styles changed; regenerate suggestions.`,
		};
	}

	if (
		! getPresetSlugsForType(
			executionContract,
			expectedPresetType
		).includes( presetSlug )
	) {
		return {
			ok: false,
			error: `The ${ displayPresetType(
				expectedPresetType
			) } preset "${ presetSlug }" is no longer available. Global Styles changed; regenerate suggestions.`,
		};
	}

	return {
		ok: true,
		value: buildPresetValue( expectedPresetType, presetSlug ),
		presetType: expectedPresetType,
		presetSlug,
		cssVar: buildPresetCssVar( expectedPresetType, presetSlug ),
	};
}

function applyOperationToConfig( {
	beforeConfig,
	afterConfig,
	operation,
	availableVariations,
	executionContract,
} ) {
	if ( operation?.type === 'set_styles' ) {
		if (
			! Array.isArray( operation.path ) ||
			operation.path.length === 0
		) {
			return {
				ok: false,
				error: 'A Global Styles operation is missing its style path.',
			};
		}

		const pathEntry = findSupportedStylePathEntry(
			operation.path,
			executionContract
		);

		if ( ! pathEntry ) {
			return {
				ok: false,
				error: `The Global Styles path ${ getStylePathKey(
					operation.path
				) } is no longer supported. Global Styles changed; regenerate suggestions.`,
			};
		}

		const fullPath = [ 'styles', ...operation.path ];
		const beforeValue = normalizeOperationValue(
			readPath( beforeConfig, fullPath )
		);
		let nextValue = normalizeOperationValue( operation.value );
		let appliedOperation = {
			...operation,
			beforeValue,
		};
		const expectedValueSource = normalizePresetType(
			pathEntry?.valueSource || 'freeform'
		);

		if ( expectedValueSource === 'freeform' ) {
			if (
				operation?.valueType &&
				sanitizeKey( operation.valueType ) !== 'freeform'
			) {
				return {
					ok: false,
					error: `The suggested Global Styles value for ${ getStylePathKey(
						operation.path
					) } must stay freeform. Global Styles changed; regenerate suggestions.`,
				};
			}

			const validatedFreeformValue = validateFreeformStyleValue(
				operation.path,
				nextValue
			);

			if ( ! validatedFreeformValue.valid ) {
				return {
					ok: false,
					error:
						validatedFreeformValue.error ||
						`The suggested Global Styles value for ${ getStylePathKey(
							operation.path
						) } is invalid.`,
				};
			}

			nextValue = validatedFreeformValue.value;
			appliedOperation = {
				...appliedOperation,
				value: nextValue,
				valueType: 'freeform',
				presetType: '',
				presetSlug: '',
				cssVar: '',
			};
		} else {
			const validatedPreset = validatePresetStyleOperation(
				operation,
				pathEntry,
				executionContract
			);

			if ( ! validatedPreset.ok ) {
				return validatedPreset;
			}

			nextValue = validatedPreset.value;
			appliedOperation = {
				...appliedOperation,
				value: validatedPreset.value,
				valueType: 'preset',
				presetType: validatedPreset.presetType,
				presetSlug: validatedPreset.presetSlug,
				cssVar: validatedPreset.cssVar,
			};
		}

		return {
			ok: true,
			afterConfig: {
				...afterConfig,
				styles: writePath(
					afterConfig.styles,
					operation.path,
					nextValue
				),
			},
			appliedOperation,
		};
	}

	if ( operation?.type === 'set_theme_variation' ) {
		const variation = resolveVariation( operation, availableVariations );

		if ( ! variation ) {
			return {
				ok: false,
				error: 'The suggested theme variation is no longer available in the Site Editor.',
			};
		}

		return {
			ok: true,
			afterConfig: {
				settings: cloneConfig( variation?.settings || {} ),
				styles: cloneConfig( variation?.styles || {} ),
				_links: cloneConfig( beforeConfig?._links || {} ),
			},
			appliedOperation: {
				...operation,
				variationTitle:
					variation?.title || operation?.variationTitle || '',
			},
		};
	}

	return {
		ok: false,
		error: `Unsupported Global Styles operation: ${
			operation?.type || 'unknown'
		}.`,
	};
}

export function getGlobalStylesUserConfig( registry ) {
	const runtime = getGlobalStylesRuntime( registry );

	if ( ! runtime.ok ) {
		return null;
	}

	return {
		globalStylesId: runtime.globalStylesId,
		userConfig: runtime.userConfig,
		mergedConfig: getCurrentMergedConfig(
			runtime.coreSelect,
			runtime.userConfig
		),
		variations:
			runtime.coreSelect?.__experimentalGetCurrentThemeGlobalStylesVariations?.() ||
			[],
	};
}

export function applyGlobalStyleSuggestionOperations( suggestion, registry ) {
	const runtime = getGlobalStylesRuntime( registry );

	if ( ! runtime.ok ) {
		return runtime;
	}

	const executionContractRuntime =
		getGlobalStylesExecutionContract( registry );

	if ( ! executionContractRuntime.ok ) {
		return executionContractRuntime;
	}

	const operations = normalizeOperations( suggestion?.operations );

	if ( operations.length === 0 ) {
		return {
			ok: false,
			error: 'This Global Styles suggestion does not include executable operations.',
		};
	}

	const availableVariations =
		runtime.coreSelect?.__experimentalGetCurrentThemeGlobalStylesVariations?.() ||
		[];
	const beforeConfig = runtime.userConfig;
	let afterConfig = normalizeConfig( beforeConfig );
	const appliedOperations = [];

	for ( const operation of operations ) {
		const nextState = applyOperationToConfig( {
			beforeConfig,
			afterConfig,
			operation,
			availableVariations,
			executionContract:
				executionContractRuntime.executionContract,
		} );

		if ( ! nextState.ok ) {
			return nextState;
		}

		afterConfig = nextState.afterConfig;
		appliedOperations.push( nextState.appliedOperation );
	}

	runtime.coreDispatch.editEntityRecord(
		'root',
		'globalStyles',
		runtime.globalStylesId,
		afterConfig
	);

	return {
		ok: true,
		globalStylesId: runtime.globalStylesId,
		beforeConfig,
		afterConfig,
		operations: appliedOperations,
	};
}

export function getGlobalStylesActivityUndoState( activity, registry ) {
	const runtime = getGlobalStylesRuntime( registry );

	if ( ! runtime.ok ) {
		return {
			canUndo: false,
			status: 'failed',
			error: runtime.error,
		};
	}

	if (
		String( activity?.target?.globalStylesId || '' ) !==
		String( runtime.globalStylesId || '' )
	) {
		return {
			canUndo: false,
			status: 'failed',
			error: 'The active Global Styles entity no longer matches this AI action.',
		};
	}

	if (
		! configsMatch( runtime.userConfig, activity?.after?.userConfig || {} )
	) {
		return {
			canUndo: false,
			status: 'failed',
			error: 'Global Styles changed after Flavor Agent applied this suggestion and cannot be undone automatically.',
		};
	}

	return {
		canUndo: true,
		status: 'available',
		error: null,
	};
}

export function undoGlobalStyleSuggestionOperations( activity, registry ) {
	const runtimeUndoState = getGlobalStylesActivityUndoState(
		activity,
		registry
	);

	if ( runtimeUndoState.status !== 'available' ) {
		return {
			ok: false,
			error: runtimeUndoState.error,
		};
	}

	const runtime = getGlobalStylesRuntime( registry );

	if ( ! runtime.ok ) {
		return runtime;
	}

	runtime.coreDispatch.editEntityRecord(
		'root',
		'globalStyles',
		runtime.globalStylesId,
		normalizeConfig( activity?.before?.userConfig || {} )
	);

	return { ok: true };
}
