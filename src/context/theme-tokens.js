/**
 * Theme Token Collector
 *
 * Reads the complete set of design tokens from the current theme's
 * theme.json, user customizations, and computed editor settings.
 * Produces a structured manifest the LLM uses to suggest specific
 * values — actual color hex codes, font family stacks, spacing
 * scale values, shadow presets, and layout constraints.
 *
 * Raw settings access lives in `theme-settings.js` so runtime code can
 * report which source is active without changing the manifest contract.
 */
import {
	getThemeEditorSettings,
	getThemeTokenFeatures,
	getThemeTokenSourceDetails,
} from './theme-settings';
import { FREEFORM_STYLE_VALIDATORS } from '../utils/style-validation';

/**
 * Collect the full design token manifest.
 */
export function collectThemeTokens() {
	return collectThemeTokensFromSettings( getThemeEditorSettings() );
}

function getSortedUniqueSlugs( entries = [] ) {
	return [
		...new Set( entries.map( ( entry ) => entry?.slug ).filter( Boolean ) ),
	].sort( ( left, right ) => left.localeCompare( right ) );
}

function readNestedValue( value, path = [] ) {
	let current = value;

	for ( const segment of path ) {
		if ( ! current || typeof current !== 'object' ) {
			return undefined;
		}

		current = current[ segment ];
	}

	return current;
}

function isTruthySupportValue( value ) {
	if ( value === true ) {
		return true;
	}

	if ( value === false || value === null || value === undefined ) {
		return false;
	}

	if ( Array.isArray( value ) ) {
		return value.length > 0;
	}

	if ( typeof value === 'object' ) {
		return Object.keys( value ).length > 0;
	}

	return !! value;
}

function hasAnyBlockSupportPath( blockSupports = {}, supportPaths = [] ) {
	return supportPaths.some( ( supportPath ) =>
		isTruthySupportValue( readNestedValue( blockSupports, supportPath ) )
	);
}

export function collectThemeTokenDiagnosticsFromSettings( settings = {} ) {
	const sourceDetails = getThemeTokenSourceDetails( settings );

	return {
		source: sourceDetails?.source || 'unknown',
		settingsKey: sourceDetails?.settingsKey || '',
		reason: sourceDetails?.reason || 'unknown',
	};
}

export function collectThemeTokensFromSettings( settings = {} ) {
	const features = getThemeTokenFeatures( settings );

	return {
		color: collectColorTokens( settings, features ),
		typography: collectTypographyTokens( settings, features ),
		spacing: collectSpacingTokens( features ),
		layout: collectLayoutTokens( features, settings ),
		shadow: collectShadowTokens( features ),
		border: collectBorderTokens( features ),
		background: collectBackgroundTokens( features ),
		elements: collectElementStyles( features ),
		blockPseudoStyles: collectBlockPseudoStyles( features ),
		diagnostics: collectThemeTokenDiagnosticsFromSettings( settings ),
	};
}

export function getGlobalStylesSupportedStylePathsFromTokens( tokens = {} ) {
	const supportedPaths = [];
	const hasColorPresets = Array.isArray( tokens?.color?.palette )
		? tokens.color.palette.length > 0
		: false;
	const hasFontSizePresets = Array.isArray( tokens?.typography?.fontSizes )
		? tokens.typography.fontSizes.length > 0
		: false;
	const hasFontFamilyPresets = Array.isArray(
		tokens?.typography?.fontFamilies
	)
		? tokens.typography.fontFamilies.length > 0
		: false;
	const hasSpacingPresets = Array.isArray( tokens?.spacing?.spacingSizes )
		? tokens.spacing.spacingSizes.length > 0
		: false;
	const hasShadowPresets = Array.isArray( tokens?.shadow?.presets )
		? tokens.shadow.presets.length > 0
		: false;

	if ( hasColorPresets ) {
		if ( tokens?.color?.backgroundEnabled ) {
			supportedPaths.push( {
				path: [ 'color', 'background' ],
				valueSource: 'color',
			} );
		}

		if ( tokens?.color?.textEnabled ) {
			supportedPaths.push( {
				path: [ 'color', 'text' ],
				valueSource: 'color',
			} );
		}

		if ( tokens?.color?.linkEnabled ) {
			supportedPaths.push( {
				path: [ 'elements', 'link', 'color', 'text' ],
				valueSource: 'color',
			} );
		}

		if ( tokens?.color?.buttonEnabled ) {
			supportedPaths.push(
				{
					path: [ 'elements', 'button', 'color', 'background' ],
					valueSource: 'color',
				},
				{
					path: [ 'elements', 'button', 'color', 'text' ],
					valueSource: 'color',
				}
			);
		}

		if ( tokens?.color?.headingEnabled ) {
			supportedPaths.push( {
				path: [ 'elements', 'heading', 'color', 'text' ],
				valueSource: 'color',
			} );
		}
	}

	if ( hasFontSizePresets ) {
		supportedPaths.push( {
			path: [ 'typography', 'fontSize' ],
			valueSource: 'font-size',
		} );
	}

	if ( hasFontFamilyPresets ) {
		supportedPaths.push(
			{
				path: [ 'typography', 'fontFamily' ],
				valueSource: 'font-family',
			},
			{
				path: [ 'elements', 'heading', 'typography', 'fontFamily' ],
				valueSource: 'font-family',
			}
		);
	}

	if ( tokens?.typography?.lineHeight ) {
		supportedPaths.push( {
			path: [ 'typography', 'lineHeight' ],
			valueSource: 'freeform',
			validation: FREEFORM_STYLE_VALIDATORS.LINE_HEIGHT,
		} );
	}

	if ( tokens?.spacing?.blockGap && hasSpacingPresets ) {
		supportedPaths.push( {
			path: [ 'spacing', 'blockGap' ],
			valueSource: 'spacing',
		} );
	}

	if ( tokens?.border?.color ) {
		supportedPaths.push( {
			path: [ 'border', 'color' ],
			valueSource: 'color',
		} );
	}

	if ( tokens?.border?.radius ) {
		supportedPaths.push( {
			path: [ 'border', 'radius' ],
			valueSource: 'freeform',
			validation: FREEFORM_STYLE_VALIDATORS.LENGTH_OR_PERCENTAGE,
		} );
	}

	if ( tokens?.border?.style ) {
		supportedPaths.push( {
			path: [ 'border', 'style' ],
			valueSource: 'freeform',
			validation: FREEFORM_STYLE_VALIDATORS.BORDER_STYLE,
		} );
	}

	if ( tokens?.border?.width ) {
		supportedPaths.push( {
			path: [ 'border', 'width' ],
			valueSource: 'freeform',
			validation: FREEFORM_STYLE_VALIDATORS.LENGTH,
		} );
	}

	if ( hasShadowPresets ) {
		supportedPaths.push( {
			path: [ 'shadow' ],
			valueSource: 'shadow',
		} );
	}

	return supportedPaths;
}

export function getBlockStyleSupportedStylePathsFromTokens(
	tokens = {},
	blockSupports = {}
) {
	const supportedPaths = [];
	const hasColorPresets = Array.isArray( tokens?.color?.palette )
		? tokens.color.palette.length > 0
		: false;
	const hasFontSizePresets = Array.isArray( tokens?.typography?.fontSizes )
		? tokens.typography.fontSizes.length > 0
		: false;
	const hasFontFamilyPresets = Array.isArray(
		tokens?.typography?.fontFamilies
	)
		? tokens.typography.fontFamilies.length > 0
		: false;
	const hasSpacingPresets = Array.isArray( tokens?.spacing?.spacingSizes )
		? tokens.spacing.spacingSizes.length > 0
		: false;
	const hasShadowPresets = Array.isArray( tokens?.shadow?.presets )
		? tokens.shadow.presets.length > 0
		: false;

	if (
		hasColorPresets &&
		tokens?.color?.backgroundEnabled &&
		hasAnyBlockSupportPath( blockSupports, [ [ 'color', 'background' ] ] )
	) {
		supportedPaths.push( {
			path: [ 'color', 'background' ],
			valueSource: 'color',
		} );
	}

	if (
		hasColorPresets &&
		tokens?.color?.textEnabled &&
		hasAnyBlockSupportPath( blockSupports, [ [ 'color', 'text' ] ] )
	) {
		supportedPaths.push( {
			path: [ 'color', 'text' ],
			valueSource: 'color',
		} );
	}

	if (
		hasFontSizePresets &&
		hasAnyBlockSupportPath( blockSupports, [
			[ 'typography', 'fontSize' ],
		] )
	) {
		supportedPaths.push( {
			path: [ 'typography', 'fontSize' ],
			valueSource: 'font-size',
		} );
	}

	if (
		hasFontFamilyPresets &&
		hasAnyBlockSupportPath( blockSupports, [
			[ 'typography', 'fontFamily' ],
			[ 'typography', '__experimentalFontFamily' ],
		] )
	) {
		supportedPaths.push( {
			path: [ 'typography', 'fontFamily' ],
			valueSource: 'font-family',
		} );
	}

	if (
		tokens?.typography?.lineHeight &&
		hasAnyBlockSupportPath( blockSupports, [
			[ 'typography', 'lineHeight' ],
		] )
	) {
		supportedPaths.push( {
			path: [ 'typography', 'lineHeight' ],
			valueSource: 'freeform',
			validation: FREEFORM_STYLE_VALIDATORS.LINE_HEIGHT,
		} );
	}

	if (
		tokens?.spacing?.blockGap &&
		hasSpacingPresets &&
		hasAnyBlockSupportPath( blockSupports, [ [ 'spacing', 'blockGap' ] ] )
	) {
		supportedPaths.push( {
			path: [ 'spacing', 'blockGap' ],
			valueSource: 'spacing',
		} );
	}

	if (
		tokens?.border?.color &&
		hasColorPresets &&
		hasAnyBlockSupportPath( blockSupports, [ [ 'border', 'color' ] ] )
	) {
		supportedPaths.push( {
			path: [ 'border', 'color' ],
			valueSource: 'color',
		} );
	}

	if (
		tokens?.border?.radius &&
		hasAnyBlockSupportPath( blockSupports, [ [ 'border', 'radius' ] ] )
	) {
		supportedPaths.push( {
			path: [ 'border', 'radius' ],
			valueSource: 'freeform',
			validation: FREEFORM_STYLE_VALIDATORS.LENGTH_OR_PERCENTAGE,
		} );
	}

	if (
		tokens?.border?.style &&
		hasAnyBlockSupportPath( blockSupports, [ [ 'border', 'style' ] ] )
	) {
		supportedPaths.push( {
			path: [ 'border', 'style' ],
			valueSource: 'freeform',
			validation: FREEFORM_STYLE_VALIDATORS.BORDER_STYLE,
		} );
	}

	if (
		tokens?.border?.width &&
		hasAnyBlockSupportPath( blockSupports, [ [ 'border', 'width' ] ] )
	) {
		supportedPaths.push( {
			path: [ 'border', 'width' ],
			valueSource: 'freeform',
			validation: FREEFORM_STYLE_VALIDATORS.LENGTH,
		} );
	}

	if (
		hasShadowPresets &&
		hasAnyBlockSupportPath( blockSupports, [ [ 'shadow' ] ] )
	) {
		supportedPaths.push( {
			path: [ 'shadow' ],
			valueSource: 'shadow',
		} );
	}

	return supportedPaths;
}

export function buildGlobalStylesExecutionContract( tokens = {} ) {
	return {
		supportedStylePaths:
			getGlobalStylesSupportedStylePathsFromTokens( tokens ),
		presetSlugs: {
			color: getSortedUniqueSlugs( tokens?.color?.palette || [] ),
			fontsize: getSortedUniqueSlugs(
				tokens?.typography?.fontSizes || []
			),
			fontfamily: getSortedUniqueSlugs(
				tokens?.typography?.fontFamilies || []
			),
			spacing: getSortedUniqueSlugs(
				tokens?.spacing?.spacingSizes || []
			),
			shadow: getSortedUniqueSlugs( tokens?.shadow?.presets || [] ),
		},
	};
}

export function buildBlockStyleExecutionContract(
	tokens = {},
	blockType = {}
) {
	return {
		supportedStylePaths: getBlockStyleSupportedStylePathsFromTokens(
			tokens,
			blockType?.supports || {}
		),
		presetSlugs: {
			color: getSortedUniqueSlugs( tokens?.color?.palette || [] ),
			fontsize: getSortedUniqueSlugs(
				tokens?.typography?.fontSizes || []
			),
			fontfamily: getSortedUniqueSlugs(
				tokens?.typography?.fontFamilies || []
			),
			spacing: getSortedUniqueSlugs(
				tokens?.spacing?.spacingSizes || []
			),
			shadow: getSortedUniqueSlugs( tokens?.shadow?.presets || [] ),
		},
	};
}

export function buildGlobalStylesExecutionContractFromSettings(
	settings = {}
) {
	return buildGlobalStylesExecutionContract(
		collectThemeTokensFromSettings( settings )
	);
}

export function buildBlockStyleExecutionContractFromSettings(
	settings = {},
	blockType = {}
) {
	return buildBlockStyleExecutionContract(
		collectThemeTokensFromSettings( settings ),
		blockType
	);
}

function collectColorTokens( settings, features ) {
	const paletteFeature = features?.color?.palette || {};
	const palette = mergeOrigins( paletteFeature );
	const gradients = mergeOrigins( features?.color?.gradients || {} );
	const duotone = mergeOrigins( features?.color?.duotone || {} );

	return {
		palette: palette.map( ( c ) => ( {
			name: c.name,
			slug: c.slug,
			color: c.color,
			cssVar: `var(--wp--preset--color--${ c.slug })`,
		} ) ),
		gradients: gradients.map( ( g ) => ( {
			name: g.name,
			slug: g.slug,
			gradient: g.gradient,
			cssVar: `var(--wp--preset--gradient--${ g.slug })`,
		} ) ),
		duotone: duotone.map( ( d ) => ( {
			name: d.name,
			slug: d.slug,
			colors: d.colors,
		} ) ),
		customColors: features?.color?.custom !== false,
		customGradients: features?.color?.customGradient !== false,
		defaultPalette: features?.color?.defaultPalette !== false,
		backgroundEnabled: features?.color?.background !== false,
		textEnabled: features?.color?.text !== false,
		linkEnabled: features?.color?.link ?? false,
		buttonEnabled: features?.color?.button ?? false,
		headingEnabled: features?.color?.heading ?? false,
	};
}

function collectTypographyTokens( settings, features ) {
	const fontSizes = mergeOrigins( features?.typography?.fontSizes || {} );
	const fontFamilies = mergeOrigins(
		features?.typography?.fontFamilies || {}
	);

	return {
		fontSizes: fontSizes.map( ( fs ) => ( {
			name: fs.name,
			slug: fs.slug,
			size: fs.size,
			fluidSize: fs.fluid || null,
			cssVar: `var(--wp--preset--font-size--${ fs.slug })`,
		} ) ),
		fontFamilies: fontFamilies.map( ( ff ) => ( {
			name: ff.name,
			slug: ff.slug,
			fontFamily: ff.fontFamily,
			cssVar: `var(--wp--preset--font-family--${ ff.slug })`,
		} ) ),
		customFontSize: features?.typography?.customFontSize !== false,
		lineHeight: features?.typography?.lineHeight ?? false,
		dropCap: features?.typography?.dropCap ?? true,
		fontStyle: features?.typography?.fontStyle ?? false,
		fontWeight: features?.typography?.fontWeight ?? false,
		letterSpacing: features?.typography?.letterSpacing ?? false,
		textDecoration: features?.typography?.textDecoration ?? false,
		textTransform: features?.typography?.textTransform ?? false,
		writingMode: features?.typography?.writingMode ?? false,
		fluidTypography: features?.typography?.fluid ?? false,
	};
}

function collectSpacingTokens( features ) {
	const spacingSizes = mergeOrigins( features?.spacing?.spacingSizes || {} );
	const units = features?.spacing?.units ?? [
		'px',
		'em',
		'rem',
		'vh',
		'vw',
		'%',
	];
	return {
		spacingSizes: spacingSizes.map( ( s ) => ( {
			name: s.name,
			slug: s.slug,
			size: s.size,
			cssVar: `var(--wp--preset--spacing--${ s.slug })`,
		} ) ),
		units,
		margin: features?.spacing?.margin ?? false,
		padding: features?.spacing?.padding ?? false,
		blockGap: features?.spacing?.blockGap ?? null,
		customSpacingSize: features?.spacing?.customSpacingSize !== false,
	};
}

function collectLayoutTokens( features, settings ) {
	const layout = features?.layout || {};
	return {
		contentSize: layout.contentSize || settings?.layout?.contentSize || '',
		wideSize: layout.wideSize || settings?.layout?.wideSize || '',
		allowEditing: layout.allowEditing !== false,
		allowCustomContentAndWideSize:
			layout.allowCustomContentAndWideSize !== false,
	};
}

function collectShadowTokens( features ) {
	const presets = mergeOrigins( features?.shadow?.presets || {} );
	return {
		presets: presets.map( ( s ) => ( {
			name: s.name,
			slug: s.slug,
			shadow: s.shadow,
			cssVar: `var(--wp--preset--shadow--${ s.slug })`,
		} ) ),
		defaultPresets: features?.shadow?.defaultPresets ?? true,
	};
}

function collectBorderTokens( features ) {
	return {
		color: features?.border?.color ?? false,
		radius: features?.border?.radius ?? false,
		style: features?.border?.style ?? false,
		width: features?.border?.width ?? false,
	};
}

function collectBackgroundTokens( features ) {
	return {
		backgroundImage: features?.background?.backgroundImage ?? false,
		backgroundSize: features?.background?.backgroundSize ?? false,
	};
}

function collectElementStyles( features ) {
	const styles = features?.styles?.elements || {};
	const result = {};
	for ( const [ element, styleDef ] of Object.entries( styles ) ) {
		result[ element ] = {
			base: styleDef?.color || {},
			hover: styleDef?.[ ':hover' ]?.color || {},
			focus: styleDef?.[ ':focus' ]?.color || {},
			focusVisible: styleDef?.[ ':focus-visible' ] || {},
		};
	}
	return result;
}

function collectBlockPseudoStyles( features ) {
	const blockStyles = features?.styles?.blocks || {};
	const pseudoClasses = [ ':hover', ':focus', ':focus-visible', ':active' ];
	const result = {};

	for ( const [ blockName, styleDef ] of Object.entries( blockStyles ) ) {
		const pseudos = {};
		for ( const pseudo of pseudoClasses ) {
			if ( styleDef?.[ pseudo ] ) {
				pseudos[ pseudo ] = styleDef[ pseudo ];
			}
		}
		if ( Object.keys( pseudos ).length > 0 ) {
			result[ blockName ] = pseudos;
		}
	}
	return result;
}

function mergeOrigins( feature ) {
	const defaultItems = feature?.default || [];
	const themeItems = feature?.theme || [];
	const customItems = feature?.custom || [];

	if ( Array.isArray( feature ) ) {
		return feature;
	}

	const bySlug = new Map();
	for ( const item of defaultItems ) {
		bySlug.set( item.slug, { ...item, origin: 'default' } );
	}
	for ( const item of themeItems ) {
		bySlug.set( item.slug, { ...item, origin: 'theme' } );
	}
	for ( const item of customItems ) {
		bySlug.set( item.slug, { ...item, origin: 'custom' } );
	}
	return [ ...bySlug.values() ];
}

/**
 * Produce a compact token summary for the LLM prompt.
 *
 * @param {Object} tokens Full editor token manifest.
 * @return {Object} Compact token summary for the prompt.
 */
export function summarizeTokens( tokens ) {
	return {
		colors: tokens.color.palette.map(
			( c ) => `${ c.slug }: ${ c.color }`
		),
		colorPresets: tokens.color.palette.map( ( preset ) => ( {
			name: preset.name,
			slug: preset.slug,
			color: preset.color,
			cssVar: preset.cssVar,
		} ) ),
		gradients: tokens.color.gradients.map( ( g ) =>
			g.gradient ? `${ g.slug }: ${ g.gradient }` : g.slug
		),
		gradientPresets: tokens.color.gradients.map( ( preset ) => ( {
			name: preset.name,
			slug: preset.slug,
			gradient: preset.gradient,
			cssVar: preset.cssVar,
		} ) ),
		fontSizes: tokens.typography.fontSizes.map( ( fs ) => {
			const fluid = fs.fluidSize
				? ` (fluid: ${ JSON.stringify( fs.fluidSize ) })`
				: '';
			return `${ fs.slug }: ${ fs.size }${ fluid }`;
		} ),
		fontSizePresets: tokens.typography.fontSizes.map( ( preset ) => ( {
			name: preset.name,
			slug: preset.slug,
			size: preset.size,
			fluidSize: preset.fluidSize || null,
			cssVar: preset.cssVar,
		} ) ),
		fontFamilies: tokens.typography.fontFamilies.map(
			( ff ) => `${ ff.slug }: ${ ff.fontFamily }`
		),
		fontFamilyPresets: tokens.typography.fontFamilies.map( ( preset ) => ( {
			name: preset.name,
			slug: preset.slug,
			fontFamily: preset.fontFamily,
			cssVar: preset.cssVar,
		} ) ),
		spacing: tokens.spacing.spacingSizes.map(
			( s ) => `${ s.slug }: ${ s.size }`
		),
		spacingPresets: tokens.spacing.spacingSizes.map( ( preset ) => ( {
			name: preset.name,
			slug: preset.slug,
			size: preset.size,
			cssVar: preset.cssVar,
		} ) ),
		shadows: tokens.shadow.presets.map(
			( s ) => `${ s.slug }: ${ s.shadow }`
		),
		shadowPresets: tokens.shadow.presets.map( ( preset ) => ( {
			name: preset.name,
			slug: preset.slug,
			shadow: preset.shadow,
			cssVar: preset.cssVar,
		} ) ),
		duotone: tokens.color.duotone.map( ( preset ) => {
			const colors = Array.isArray( preset.colors )
				? preset.colors.slice( 0, 2 ).join( ' / ' )
				: '';
			return colors ? `${ preset.slug }: ${ colors }` : preset.slug;
		} ),
		duotonePresets: tokens.color.duotone.map( ( preset ) => ( {
			slug: preset.slug,
			colors: Array.isArray( preset.colors ) ? preset.colors : [],
		} ) ),
		layout: {
			content: tokens.layout.contentSize,
			wide: tokens.layout.wideSize,
			allowEditing: tokens.layout.allowEditing,
			allowCustomContentAndWideSize:
				tokens.layout.allowCustomContentAndWideSize,
		},
		enabledFeatures: {
			lineHeight: tokens.typography.lineHeight,
			dropCap: tokens.typography.dropCap,
			fontStyle: tokens.typography.fontStyle,
			fontWeight: tokens.typography.fontWeight,
			letterSpacing: tokens.typography.letterSpacing,
			textDecoration: tokens.typography.textDecoration,
			textTransform: tokens.typography.textTransform,
			customColors: tokens.color.customColors,
			backgroundColor: tokens.color.backgroundEnabled,
			textColor: tokens.color.textEnabled,
			linkColor: tokens.color.linkEnabled,
			buttonColor: tokens.color.buttonEnabled,
			headingColor: tokens.color.headingEnabled,
			fluid: tokens.typography.fluidTypography,
			margin: tokens.spacing.margin,
			padding: tokens.spacing.padding,
			blockGap: tokens.spacing.blockGap,
			borderColor: tokens.border.color,
			borderRadius: tokens.border.radius,
			borderStyle: tokens.border.style,
			borderWidth: tokens.border.width,
			backgroundImage: tokens.background.backgroundImage,
			backgroundSize: tokens.background.backgroundSize,
		},
		elementStyles: tokens.elements,
		blockPseudoStyles: tokens.blockPseudoStyles,
		diagnostics: tokens.diagnostics || {
			source: 'unknown',
			settingsKey: '',
			reason: 'unknown',
		},
	};
}
