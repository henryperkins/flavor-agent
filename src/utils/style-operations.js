import { dispatch, select } from '@wordpress/data';
import { store as blocksStore } from '@wordpress/blocks';
import { __, sprintf } from '@wordpress/i18n';

import {
	buildBlockStyleExecutionContractFromSettings,
	buildGlobalStylesExecutionContractFromSettings,
	collectThemeTokensFromSettings,
	summarizeTokens,
} from '../context/theme-tokens';
import {
	getCurrentGlobalStylesId,
	getCurrentThemeBaseGlobalStyles,
	getCurrentThemeGlobalStylesVariations,
} from '../global-styles/selectors';
import { buildContextSignature } from './context-signature';
import { deepStructuralEqual } from './structural-equality';
import {
	displayPresetType,
	normalizePresetType,
	sanitizeStyleKey,
	validateFreeformStyleValueByKind,
} from './style-validation';

const CONTRAST_AA_THRESHOLD = 4.5;
const CONTRAST_SUPPORTED_ELEMENTS = [ 'button', 'link', 'heading' ];
const CONTRAST_SCOPE_ORDER = [
	'root',
	'elements.button',
	'elements.link',
	'elements.heading',
];

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

function getBlocksSelect( registry ) {
	return registry?.select?.( blocksStore ) || select( blocksStore ) || {};
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

function parsePresetValue( value ) {
	if ( typeof value !== 'string' ) {
		return null;
	}

	const matches = value.match( /^var:preset\|([a-z0-9-]+)\|([a-z0-9_-]+)$/i );

	if ( ! matches ) {
		return null;
	}

	const type = normalizePresetType( matches[ 1 ] );
	const slug = sanitizeStyleKey( matches[ 2 ] );

	if ( ! type || ! slug ) {
		return null;
	}

	return { type, slug };
}

function normalizeHexValue( value ) {
	if ( typeof value !== 'string' ) {
		return null;
	}

	const matches = value.match(
		/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i
	);

	if ( ! matches ) {
		return null;
	}

	let digits = matches[ 1 ].toLowerCase();

	if ( digits.length === 3 || digits.length === 4 ) {
		digits = digits
			.split( '' )
			.map( ( char ) => `${ char }${ char }` )
			.join( '' );
	}

	if ( digits.length === 8 && digits.slice( 6 ) !== 'ff' ) {
		return null;
	}

	return `#${ digits.slice( 0, 6 ) }`;
}

function buildColorPresetIndex( themeTokens = {} ) {
	const index = new Map();
	const presets = Array.isArray( themeTokens?.colorPresets )
		? themeTokens.colorPresets
		: [];

	for ( const preset of presets ) {
		const slug = sanitizeStyleKey( preset?.slug );

		if ( slug ) {
			index.set( slug, preset?.color || '' );
		}
	}

	return index;
}

function resolveColorValue( value, themeTokens = {} ) {
	const normalizedHex = normalizeHexValue( value );

	if ( normalizedHex ) {
		return {
			resolved: true,
			hex: normalizedHex,
		};
	}

	if ( typeof value !== 'string' || ! value ) {
		return {
			resolved: false,
			hex: null,
		};
	}

	const presetMatch =
		value.match( /^var:preset\|color\|([a-z0-9_-]+)$/i ) ||
		value.match( /^var\(--wp--preset--color--([a-z0-9_-]+)\)$/i );

	if ( ! presetMatch ) {
		return {
			resolved: false,
			hex: null,
		};
	}

	const rawHex = buildColorPresetIndex( themeTokens ).get(
		sanitizeStyleKey( presetMatch[ 1 ] )
	);
	const presetHex = normalizeHexValue( rawHex );

	return {
		resolved: Boolean( presetHex ),
		hex: presetHex,
	};
}

function colorPairPathSide( path = [] ) {
	if ( ! Array.isArray( path ) || path.length < 2 ) {
		return '';
	}

	const tail = path.slice( -2 );

	return tail[ 0 ] === 'color' &&
		( tail[ 1 ] === 'text' || tail[ 1 ] === 'background' )
		? tail[ 1 ]
		: '';
}

function getContrastScopeKeyForOperation( operation = {} ) {
	const path = Array.isArray( operation?.path ) ? operation.path : [];

	if ( ! colorPairPathSide( path ) ) {
		return null;
	}

	const type = sanitizeStyleKey( operation?.type );

	if ( type === 'set_block_styles' ) {
		const blockName =
			typeof operation?.blockName === 'string'
				? operation.blockName.trim()
				: '';

		return blockName ? `blocks.${ blockName }` : null;
	}

	if ( type !== 'set_styles' ) {
		return null;
	}

	if ( path.length === 2 ) {
		return 'root';
	}

	if ( path.length === 4 && path[ 0 ] === 'elements' ) {
		const element = sanitizeStyleKey( path[ 1 ] );

		return CONTRAST_SUPPORTED_ELEMENTS.includes( element )
			? `elements.${ element }`
			: 'unsupported';
	}

	return 'unsupported';
}

function hasThemeVariationOperation( operations = [] ) {
	return operations.some(
		( operation ) =>
			sanitizeStyleKey( operation?.type ) === 'set_theme_variation'
	);
}

function hasReadableColorOperation( operations = [] ) {
	return operations.some( ( operation ) => {
		const type = sanitizeStyleKey( operation?.type );

		return (
			( type === 'set_styles' || type === 'set_block_styles' ) &&
			Boolean( colorPairPathSide( operation?.path ) )
		);
	} );
}

function getContrastScopeKeysInOrder( scopeKeys = [] ) {
	const known = [];
	const blocks = [];

	for ( const key of scopeKeys ) {
		const orderIndex = CONTRAST_SCOPE_ORDER.indexOf( key );

		if ( orderIndex !== -1 ) {
			known[ orderIndex ] = key;
		} else if ( key.startsWith( 'blocks.' ) ) {
			blocks.push( key );
		}
	}

	return [
		...known.filter( Boolean ),
		...blocks.sort( ( left, right ) => left.localeCompare( right ) ),
	];
}

function getScopeSpecificComplementValue(
	scopeKey,
	side,
	mergedStyles = {},
	themeTokens = {}
) {
	if ( scopeKey === 'root' ) {
		return null;
	}

	if ( scopeKey.startsWith( 'elements.' ) ) {
		const element = scopeKey.slice( 'elements.'.length );
		const mergedElement = mergedStyles?.elements?.[ element ]?.color || {};

		if ( Object.hasOwn( mergedElement, side ) ) {
			return mergedElement[ side ];
		}

		return themeTokens?.elementStyles?.[ element ]?.base?.[ side ] ?? null;
	}

	if ( scopeKey.startsWith( 'blocks.' ) ) {
		const blockName = scopeKey.slice( 'blocks.'.length );

		return mergedStyles?.blocks?.[ blockName ]?.color?.[ side ] ?? null;
	}

	return null;
}

function getMergedComplementHex( scopeKey, side, context = {} ) {
	const themeTokens = context?.themeTokens || {};
	const mergedStyles = context?.mergedConfig?.styles || {};
	const scopedValue = getScopeSpecificComplementValue(
		scopeKey,
		side,
		mergedStyles,
		themeTokens
	);

	if ( scopedValue !== null && scopedValue !== undefined ) {
		const scopedColor = resolveColorValue( scopedValue, themeTokens );

		if ( scopedColor.resolved ) {
			return scopedColor.hex;
		}
	}

	const rootValue = mergedStyles?.color?.[ side ];

	if ( rootValue !== null && rootValue !== undefined ) {
		const rootColor = resolveColorValue( rootValue, themeTokens );

		if ( rootColor.resolved ) {
			return rootColor.hex;
		}
	}

	return null;
}

function getUnavailableContrastResult( side, scopeKey ) {
	return {
		ok: false,
		error: sprintf(
			/* translators: 1: color side, such as "background" or "text". 2: style scope key. */
			__(
				'Contrast check unavailable: unresolved %1$s at %2$s.',
				'flavor-agent'
			),
			side,
			scopeKey
		),
		code: 'failed_contrast',
	};
}

function resolveContrastSide( operation, side, scopeKey, context ) {
	if ( operation ) {
		const resolved = resolveColorValue(
			operation?.value,
			context.themeTokens
		);

		return resolved.resolved
			? {
					ok: true,
					hex: resolved.hex,
			  }
			: getUnavailableContrastResult( side, scopeKey );
	}

	const hex = getMergedComplementHex( scopeKey, side, context );

	return hex
		? {
				ok: true,
				hex,
		  }
		: getUnavailableContrastResult( side, scopeKey );
}

function getRelativeLuminance( hex ) {
	const normalized = normalizeHexValue( hex ) || '#000000';
	const channels = [ 0, 2, 4 ].map( ( index ) => {
		const channel =
			parseInt( normalized.slice( index + 1, index + 3 ), 16 ) / 255;

		return channel <= 0.03928
			? channel / 12.92
			: Math.pow( ( channel + 0.055 ) / 1.055, 2.4 );
	} );

	return (
		0.2126 * channels[ 0 ] + 0.7152 * channels[ 1 ] + 0.0722 * channels[ 2 ]
	);
}

function getContrastRatio( foregroundHex, backgroundHex ) {
	const foreground = getRelativeLuminance( foregroundHex );
	const background = getRelativeLuminance( backgroundHex );
	const lighter = Math.max( foreground, background );
	const darker = Math.min( foreground, background );

	return ( lighter + 0.05 ) / ( darker + 0.05 );
}

function getContrastLabel( operation, hexFallback ) {
	const parsed = parsePresetValue( operation?.value );

	return parsed?.type === 'color' ? parsed.slug : hexFallback;
}

function validateReadableColorContrast( operations = [], context = {} ) {
	const groups = {};
	let unsupported = false;

	if (
		hasThemeVariationOperation( operations ) &&
		hasReadableColorOperation( operations )
	) {
		return {
			ok: false,
			error: __(
				'Contrast check unavailable: theme variation and color overrides must be reviewed separately.',
				'flavor-agent'
			),
			code: 'failed_contrast',
		};
	}

	for ( const operation of operations ) {
		const scopeKey = getContrastScopeKeyForOperation( operation );

		if ( ! scopeKey ) {
			continue;
		}

		if ( scopeKey === 'unsupported' ) {
			unsupported = true;
			continue;
		}

		const side = colorPairPathSide( operation?.path );

		if ( ! side ) {
			continue;
		}

		groups[ scopeKey ] = groups[ scopeKey ] || {};
		groups[ scopeKey ][ side ] = [
			...( groups[ scopeKey ][ side ] || [] ),
			operation,
		];
	}

	if ( unsupported ) {
		return {
			ok: false,
			error: __(
				'Contrast check unavailable: unsupported readable color path.',
				'flavor-agent'
			),
			code: 'failed_contrast',
		};
	}

	for ( const scopeKey of getContrastScopeKeysInOrder(
		Object.keys( groups )
	) ) {
		const sides = groups[ scopeKey ];
		const backgroundOperations = sides.background || [];
		const textOperations = sides.text || [];
		const backgroundOperation =
			backgroundOperations[ backgroundOperations.length - 1 ] || null;
		const textOperation =
			textOperations[ textOperations.length - 1 ] || null;
		const background = resolveContrastSide(
			backgroundOperation,
			'background',
			scopeKey,
			context
		);

		if ( ! background.ok ) {
			return background;
		}

		const text = resolveContrastSide(
			textOperation,
			'text',
			scopeKey,
			context
		);

		if ( ! text.ok ) {
			return text;
		}

		const ratio = getContrastRatio( text.hex, background.hex );

		if ( ratio < CONTRAST_AA_THRESHOLD ) {
			return {
				ok: false,
				error: sprintf(
					/* translators: 1: contrast ratio. 2: text color label. 3: background color label. 4: style scope key. */
					__(
						'Contrast check: %1$s:1 between "%2$s" and "%3$s" at %4$s, below the 4.5:1 minimum.',
						'flavor-agent'
					),
					ratio.toFixed( 1 ),
					getContrastLabel( textOperation, text.hex ),
					getContrastLabel( backgroundOperation, background.hex ),
					scopeKey
				),
				code: 'failed_contrast',
			};
		}
	}

	return {
		ok: true,
	};
}

function buildPresetValue( presetType, presetSlug ) {
	return `var:preset|${ displayPresetType( presetType ) }|${ presetSlug }`;
}

function buildPresetCssVar( presetType, presetSlug ) {
	return `var(--wp--preset--${ displayPresetType(
		presetType
	) }--${ presetSlug })`;
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

function normalizeComparableTemplateStructure( nodes = [] ) {
	if ( ! Array.isArray( nodes ) ) {
		return [];
	}

	return nodes
		.map( ( node ) => {
			if ( ! node || typeof node !== 'object' ) {
				return null;
			}

			const normalizedNode = {
				name: typeof node?.name === 'string' ? node.name : '',
			};
			const innerBlocks = normalizeComparableTemplateStructure(
				node?.innerBlocks
			);

			if ( innerBlocks.length > 0 ) {
				normalizedNode.innerBlocks = innerBlocks;
			}

			return normalizedNode.name ? normalizedNode : null;
		} )
		.filter( Boolean );
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
					validation:
						typeof pathEntry?.validation === 'string'
							? pathEntry.validation
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
	templateStructure,
	templateVisibility,
	designSemantics,
} ) {
	// Style Book requests cannot apply theme variations, so variation drift should
	// not invalidate otherwise matching block-scoped recommendations.
	const includeVariations = scope?.surface !== 'style-book';

	return buildContextSignature( {
		scopeKey: scope?.scopeKey || '',
		globalStylesId: scope?.globalStylesId || '',
		stylesheet: scope?.stylesheet || '',
		templateSlug: scope?.templateSlug || '',
		templateType: scope?.templateType || '',
		currentConfig: getComparableGlobalStylesConfig( currentConfig ),
		mergedConfig: getComparableGlobalStylesConfig( mergedConfig ),
		availableVariations:
			includeVariations && Array.isArray( availableVariations )
				? availableVariations.map( normalizeComparableVariation )
				: [],
		templateStructure:
			normalizeComparableTemplateStructure( templateStructure ),
		templateVisibility: sortObjectKeysDeep(
			normalizeOperationValue( templateVisibility || {} )
		),
		designSemantics: sortObjectKeysDeep(
			normalizeOperationValue( designSemantics || {} )
		),
		themeTokenDiagnostics: sortObjectKeysDeep(
			normalizeOperationValue( themeTokenDiagnostics || {} )
		),
		executionContract:
			normalizeComparableExecutionContract( executionContract ),
	} );
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

function removePath( value, path = [] ) {
	if ( path.length === 0 ) {
		return undefined;
	}

	if ( ! value || typeof value !== 'object' || Array.isArray( value ) ) {
		return value;
	}

	const [ head, ...rest ] = path;

	if ( ! Object.hasOwn( value, head ) ) {
		return value;
	}

	const next = Array.isArray( value ) ? [ ...value ] : { ...value };

	if ( rest.length === 0 ) {
		delete next[ head ];
		return next;
	}

	const nextBranch = removePath( next[ head ], rest );

	if (
		nextBranch === undefined ||
		( nextBranch &&
			typeof nextBranch === 'object' &&
			! Array.isArray( nextBranch ) &&
			Object.keys( nextBranch ).length === 0 )
	) {
		delete next[ head ];
	} else {
		next[ head ] = nextBranch;
	}

	return next;
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
	const baseConfig = getCurrentThemeBaseGlobalStyles( coreSelect );

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
				blockName:
					typeof operation?.blockName === 'string'
						? operation.blockName.trim()
						: '',
				path: Array.isArray( operation?.path ) ? operation.path : [],
				value: normalizeOperationValue( operation?.value ),
		  } ) )
		: [];
	const themeVariations = [];
	const orderedOperations = [];

	for ( const operation of normalized ) {
		if ( operation?.type === 'set_theme_variation' ) {
			themeVariations.push( operation );
			continue;
		}

		orderedOperations.push( operation );
	}

	return [ ...themeVariations, ...orderedOperations ];
}

function validateNormalizedOperations( operations = [] ) {
	const themeVariationCount = operations.filter(
		( operation ) => operation?.type === 'set_theme_variation'
	).length;

	if ( themeVariationCount > 1 ) {
		return {
			ok: false,
			error: 'Global Styles suggestions may include at most one set_theme_variation operation.',
		};
	}

	return {
		ok: true,
	};
}

function getExpectedStyleBookBlockName( options = {} ) {
	const candidates = [
		options?.scope?.blockName,
		options?.styleBookBlockName,
		options?.blockName,
	];

	for ( const candidate of candidates ) {
		if ( typeof candidate !== 'string' ) {
			continue;
		}

		const normalized = candidate.trim();

		if ( normalized ) {
			return normalized;
		}
	}

	return '';
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
	return deepStructuralEqual(
		getComparableGlobalStylesConfig( left ),
		getComparableGlobalStylesConfig( right )
	);
}

function getStyleBookBranchPath( activity = {} ) {
	const blockName =
		typeof activity?.target?.blockName === 'string'
			? activity.target.blockName.trim()
			: '';

	return blockName ? [ 'styles', 'blocks', blockName ] : null;
}

function getComparableConfigBranchAtPath( config = {}, path = [] ) {
	return normalizeComparableConfigBranch( readPath( config, path ) );
}

function validatePresetStyleOperation(
	operation = {},
	pathEntry = {},
	executionContract = {},
	surfaceLabel = 'Global Styles'
) {
	const pathKey = getStylePathKey( operation?.path );
	const valueType = sanitizeStyleKey( operation?.valueType );

	if ( valueType !== 'preset' ) {
		return {
			ok: false,
			error: `The suggested ${ surfaceLabel } value for ${ pathKey } must use a theme preset. ${ surfaceLabel } changed; regenerate suggestions.`,
			code: 'preset_required',
		};
	}

	const expectedPresetType = normalizePresetType( pathEntry?.valueSource );
	const presetType = normalizePresetType( operation?.presetType );
	const presetSlug = sanitizeStyleKey( operation?.presetSlug );

	if ( presetType !== expectedPresetType || ! presetSlug ) {
		return {
			ok: false,
			error: `The suggested ${ surfaceLabel } preset metadata for ${ pathKey } no longer matches the live theme contract. ${ surfaceLabel } changed; regenerate suggestions.`,
			code: 'preset_metadata_mismatch',
		};
	}

	const parsedPresetValue = parsePresetValue( operation?.value );

	if (
		! parsedPresetValue ||
		parsedPresetValue.type !== expectedPresetType ||
		parsedPresetValue.slug !== presetSlug
	) {
		return {
			ok: false,
			error: `The suggested ${ surfaceLabel } preset reference for ${ pathKey } no longer matches its metadata. ${ surfaceLabel } changed; regenerate suggestions.`,
			code: 'preset_reference_mismatch',
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
			) } preset "${ presetSlug }" is no longer available. ${ surfaceLabel } changed; regenerate suggestions.`,
			code: 'preset_unavailable',
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

function applyPathBasedStyleOperation( {
	beforeConfig,
	afterConfig,
	operation,
	executionContract,
	configPath,
	surfaceLabel,
} ) {
	if ( ! Array.isArray( operation?.path ) || operation.path.length === 0 ) {
		return {
			ok: false,
			error: `A ${ surfaceLabel } operation is missing its style path.`,
			code: 'unsupported_path',
		};
	}

	const pathEntry = findSupportedStylePathEntry(
		operation.path,
		executionContract
	);

	if ( ! pathEntry ) {
		return {
			ok: false,
			error: `The ${ surfaceLabel } path ${ getStylePathKey(
				operation.path
			) } is no longer supported. ${ surfaceLabel } changed; regenerate suggestions.`,
			code: 'unsupported_path',
		};
	}

	const fullPath = [ 'styles', ...configPath ];
	const expectedValueSource = normalizePresetType(
		pathEntry?.valueSource || 'freeform'
	);

	if ( expectedValueSource === 'freeform' ) {
		if (
			operation?.valueType &&
			sanitizeStyleKey( operation.valueType ) !== 'freeform'
		) {
			return {
				ok: false,
				error: `The suggested ${ surfaceLabel } value for ${ getStylePathKey(
					operation.path
				) } must stay freeform. ${ surfaceLabel } changed; regenerate suggestions.`,
				code: 'invalid_freeform_value',
			};
		}

		const validatedFreeformValue = validateFreeformStyleValueByKind(
			pathEntry?.validation,
			normalizeOperationValue( operation.value )
		);

		if ( ! validatedFreeformValue.valid ) {
			return {
				ok: false,
				error:
					validatedFreeformValue.error ||
					`The suggested ${ surfaceLabel } value for ${ getStylePathKey(
						operation.path
					) } is invalid.`,
				code: 'invalid_freeform_value',
			};
		}

		const nextValue = validatedFreeformValue.value;

		return {
			ok: true,
			afterConfig: {
				...afterConfig,
				styles: writePath( afterConfig.styles, configPath, nextValue ),
			},
			appliedOperation: {
				...operation,
				beforeValue: normalizeOperationValue(
					readPath( beforeConfig, fullPath )
				),
				value: nextValue,
				valueType: 'freeform',
				presetType: '',
				presetSlug: '',
				cssVar: '',
			},
		};
	}

	const validatedPreset = validatePresetStyleOperation(
		operation,
		pathEntry,
		executionContract,
		surfaceLabel
	);

	if ( ! validatedPreset.ok ) {
		return validatedPreset;
	}

	return {
		ok: true,
		afterConfig: {
			...afterConfig,
			styles: writePath(
				afterConfig.styles,
				configPath,
				validatedPreset.value
			),
		},
		appliedOperation: {
			...operation,
			beforeValue: normalizeOperationValue(
				readPath( beforeConfig, fullPath )
			),
			value: validatedPreset.value,
			valueType: 'preset',
			presetType: validatedPreset.presetType,
			presetSlug: validatedPreset.presetSlug,
			cssVar: validatedPreset.cssVar,
		},
	};
}

function applyOperationToConfig( {
	beforeConfig,
	afterConfig,
	operation,
	surface,
	availableVariations,
	executionContract,
	blockEditorSettings,
	blocksSelect,
	expectedStyleBookBlockName,
} ) {
	if ( operation?.type === 'set_styles' ) {
		if ( surface === 'style-book' ) {
			return {
				ok: false,
				error: 'Style Book suggestions cannot apply site-level Global Styles operations.',
				code: 'unsupported_scope',
			};
		}

		return applyPathBasedStyleOperation( {
			beforeConfig,
			afterConfig,
			operation,
			executionContract,
			configPath: operation.path,
			surfaceLabel: 'Global Styles',
		} );
	}

	if ( operation?.type === 'set_block_styles' ) {
		if ( surface !== 'style-book' ) {
			return {
				ok: false,
				error: 'Global Styles suggestions cannot apply Style Book block operations.',
				code: 'unsupported_scope',
			};
		}

		const blockName =
			typeof operation?.blockName === 'string'
				? operation.blockName.trim()
				: '';

		if ( ! blockName ) {
			return {
				ok: false,
				error: 'A Style Book operation is missing its target block name.',
				code: 'missing_style_book_target',
			};
		}

		if ( ! expectedStyleBookBlockName ) {
			return {
				ok: false,
				error: 'Style Book suggestions require the active Style Book block scope before applying.',
				code: 'missing_style_book_target',
			};
		}

		if ( blockName !== expectedStyleBookBlockName ) {
			return {
				ok: false,
				error: `Style Book suggestion target "${ blockName }" no longer matches the active Style Book block "${ expectedStyleBookBlockName }".`,
				code: 'missing_style_book_target',
			};
		}

		const blockType = blocksSelect?.getBlockType?.( blockName );

		if ( ! blockType ) {
			return {
				ok: false,
				error: `The Style Book target block "${ blockName }" is no longer registered in the editor.`,
				code: 'missing_style_book_target',
			};
		}

		const blockExecutionContract =
			buildBlockStyleExecutionContractFromSettings(
				blockEditorSettings || {},
				blockType
			);

		return applyPathBasedStyleOperation( {
			beforeConfig,
			afterConfig,
			operation: {
				...operation,
				blockName,
			},
			executionContract: blockExecutionContract,
			configPath: [ 'blocks', blockName, ...( operation.path || [] ) ],
			surfaceLabel: 'Style Book',
		} );
	}

	if ( operation?.type === 'set_theme_variation' ) {
		if ( surface === 'style-book' ) {
			return {
				ok: false,
				error: 'Style Book suggestions cannot switch the active site theme variation.',
				code: 'unsupported_scope',
			};
		}

		const variation = resolveVariation( operation, availableVariations );

		if ( ! variation ) {
			return {
				ok: false,
				error: 'The suggested theme variation is no longer available in the Site Editor.',
				code: 'unavailable_variation',
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
		code: 'unsupported_scope',
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
		variations: getCurrentThemeGlobalStylesVariations( runtime.coreSelect ),
	};
}

export function applyGlobalStyleSuggestionOperations(
	suggestion,
	registry,
	options = {}
) {
	const runtime = getGlobalStylesRuntime( registry );
	const surface =
		options?.surface === 'style-book' ? 'style-book' : 'global-styles';

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
			code: 'no_executable_operations',
		};
	}

	const operationValidation = validateNormalizedOperations( operations );

	if ( ! operationValidation.ok ) {
		return operationValidation;
	}

	const blockEditorSettings =
		getBlockEditorSelect( registry )?.getSettings?.() || {};
	const blocksSelect = operations.some(
		( operation ) => operation?.type === 'set_block_styles'
	)
		? getBlocksSelect( registry )
		: null;

	const availableVariations = getCurrentThemeGlobalStylesVariations(
		runtime.coreSelect
	);
	const beforeConfig = runtime.userConfig;
	let afterConfig = normalizeConfig( beforeConfig );
	const appliedOperations = [];
	const expectedStyleBookBlockName =
		surface === 'style-book'
			? getExpectedStyleBookBlockName( options )
			: '';

	for ( const operation of operations ) {
		const nextState = applyOperationToConfig( {
			beforeConfig,
			afterConfig,
			operation,
			surface,
			availableVariations,
			executionContract: executionContractRuntime.executionContract,
			blockEditorSettings,
			blocksSelect,
			expectedStyleBookBlockName,
		} );

		if ( ! nextState.ok ) {
			return nextState;
		}

		afterConfig = nextState.afterConfig;
		appliedOperations.push( nextState.appliedOperation );
	}

	const themeTokens = summarizeTokens(
		collectThemeTokensFromSettings( blockEditorSettings )
	);
	const contrastValidation = validateReadableColorContrast(
		appliedOperations,
		{
			themeTokens,
			mergedConfig: getCurrentMergedConfig(
				runtime.coreSelect,
				afterConfig
			),
		}
	);

	if ( ! contrastValidation.ok ) {
		return contrastValidation;
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

	const isStyleBookActivity = activity?.surface === 'style-book';
	const styleBookBranchPath = getStyleBookBranchPath( activity );

	if ( isStyleBookActivity && ! styleBookBranchPath ) {
		return {
			canUndo: false,
			status: 'failed',
			error: 'The Style Book target block for this AI action is missing.',
		};
	}

	const matchesBeforeConfig = isStyleBookActivity
		? JSON.stringify(
				getComparableConfigBranchAtPath(
					runtime.userConfig,
					styleBookBranchPath
				)
		  ) ===
		  JSON.stringify(
				getComparableConfigBranchAtPath(
					activity?.before?.userConfig || {},
					styleBookBranchPath
				)
		  )
		: configsMatch(
				runtime.userConfig,
				activity?.before?.userConfig || {}
		  );

	if ( matchesBeforeConfig ) {
		return {
			canUndo: false,
			status: 'undone',
			error: null,
		};
	}

	const configsStillMatch = isStyleBookActivity
		? JSON.stringify(
				getComparableConfigBranchAtPath(
					runtime.userConfig,
					styleBookBranchPath
				)
		  ) ===
		  JSON.stringify(
				getComparableConfigBranchAtPath(
					activity?.after?.userConfig || {},
					styleBookBranchPath
				)
		  )
		: configsMatch( runtime.userConfig, activity?.after?.userConfig || {} );

	if ( ! configsStillMatch ) {
		return {
			canUndo: false,
			status: 'failed',
			error: isStyleBookActivity
				? 'Style Book target styles changed after Flavor Agent applied this suggestion and cannot be undone automatically.'
				: 'Global Styles changed after Flavor Agent applied this suggestion and cannot be undone automatically.',
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

	const isStyleBookActivity = activity?.surface === 'style-book';
	const styleBookBranchPath = getStyleBookBranchPath( activity );

	if ( isStyleBookActivity ) {
		if ( ! styleBookBranchPath ) {
			return {
				ok: false,
				error: 'The Style Book target block for this AI action is missing.',
			};
		}

		const beforeConfig = normalizeConfig(
			activity?.before?.userConfig || {}
		);
		const currentConfig = normalizeConfig( runtime.userConfig || {} );
		const previousBranch = readPath( beforeConfig, styleBookBranchPath );
		const blockBranchPath = styleBookBranchPath.slice( 1 );
		const nextStyles =
			previousBranch === undefined
				? removePath( currentConfig.styles, blockBranchPath )
				: writePath(
						currentConfig.styles,
						blockBranchPath,
						normalizeOperationValue( previousBranch )
				  );

		runtime.coreDispatch.editEntityRecord(
			'root',
			'globalStyles',
			runtime.globalStylesId,
			{
				...currentConfig,
				styles: nextStyles,
			}
		);

		return { ok: true };
	}

	runtime.coreDispatch.editEntityRecord(
		'root',
		'globalStyles',
		runtime.globalStylesId,
		normalizeConfig( activity?.before?.userConfig || {} )
	);

	return { ok: true };
}
