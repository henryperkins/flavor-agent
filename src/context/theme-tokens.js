/**
 * Theme Token Collector
 *
 * Reads the complete set of design tokens from the current theme's
 * theme.json, user customizations, and computed editor settings.
 * Produces a structured manifest the LLM uses to suggest specific
 * values — actual color hex codes, font family stacks, spacing
 * scale values, shadow presets, and layout constraints.
 *
 * Uses `getSettings().__experimentalFeatures` for origin-separated
 * presets (default / theme / custom) so the LLM knows what the theme
 * provides vs. what the user has overridden.
 */
import { select } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';

/**
 * Collect the full design token manifest.
 */
export function collectThemeTokens() {
	const settings = select( blockEditorStore ).getSettings();
	const features = settings.__experimentalFeatures || {};

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
	};
}

function collectColorTokens( settings, features ) {
	const paletteFeature = features?.color?.palette || {};
	const palette = mergeOrigins( paletteFeature );
	const gradients = mergeOrigins( features?.color?.gradients || {} );
	const duotone = mergeOrigins( features?.color?.duotone || {} );

	return {
		palette: palette.map( ( c ) => ( {
			name: c.name, slug: c.slug, color: c.color,
			cssVar: `var(--wp--preset--color--${ c.slug })`,
		} ) ),
		gradients: gradients.map( ( g ) => ( {
			name: g.name, slug: g.slug, gradient: g.gradient,
			cssVar: `var(--wp--preset--gradient--${ g.slug })`,
		} ) ),
		duotone: duotone.map( ( d ) => ( {
			name: d.name, slug: d.slug, colors: d.colors,
		} ) ),
		customColors: features?.color?.custom !== false,
		customGradients: features?.color?.customGradient !== false,
		defaultPalette: features?.color?.defaultPalette !== false,
		backgroundEnabled: features?.color?.background !== false,
		textEnabled: features?.color?.text !== false,
		linkEnabled: features?.color?.link ?? false,
	};
}

function collectTypographyTokens( settings, features ) {
	const fontSizes = mergeOrigins( features?.typography?.fontSizes || {} );
	const fontFamilies = mergeOrigins( features?.typography?.fontFamilies || {} );

	return {
		fontSizes: fontSizes.map( ( fs ) => ( {
			name: fs.name, slug: fs.slug, size: fs.size,
			fluidSize: fs.fluid || null,
			cssVar: `var(--wp--preset--font-size--${ fs.slug })`,
		} ) ),
		fontFamilies: fontFamilies.map( ( ff ) => ( {
			name: ff.name, slug: ff.slug, fontFamily: ff.fontFamily,
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
	const units = features?.spacing?.units ?? [ 'px', 'em', 'rem', 'vh', 'vw', '%' ];
	return {
		spacingSizes: spacingSizes.map( ( s ) => ( {
			name: s.name, slug: s.slug, size: s.size,
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
		allowCustomContentAndWideSize: layout.allowCustomContentAndWideSize !== false,
	};
}

function collectShadowTokens( features ) {
	const presets = mergeOrigins( features?.shadow?.presets || {} );
	return {
		presets: presets.map( ( s ) => ( {
			name: s.name, slug: s.slug, shadow: s.shadow,
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

	if ( Array.isArray( feature ) ) return feature;

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
 */
export function summarizeTokens( tokens ) {
	return {
		colors: tokens.color.palette.map( ( c ) => `${ c.slug }: ${ c.color }` ),
		gradients: tokens.color.gradients.map( ( g ) => g.slug ),
		fontSizes: tokens.typography.fontSizes.map( ( fs ) => {
			const fluid = fs.fluidSize ? ` (fluid: ${ JSON.stringify( fs.fluidSize ) })` : '';
			return `${ fs.slug }: ${ fs.size }${ fluid }`;
		} ),
		fontFamilies: tokens.typography.fontFamilies.map(
			( ff ) => `${ ff.slug }: ${ ff.fontFamily }`
		),
		spacing: tokens.spacing.spacingSizes.map( ( s ) => `${ s.slug }: ${ s.size }` ),
		shadows: tokens.shadow.presets.map( ( s ) => `${ s.slug }: ${ s.shadow }` ),
		layout: {
			content: tokens.layout.contentSize,
			wide: tokens.layout.wideSize,
		},
		enabledFeatures: {
			lineHeight: tokens.typography.lineHeight,
			dropCap: tokens.typography.dropCap,
			customColors: tokens.color.customColors,
			linkColor: tokens.color.linkEnabled,
			fluid: tokens.typography.fluidTypography,
			margin: tokens.spacing.margin,
			padding: tokens.spacing.padding,
			borderColor: tokens.border.color,
			borderRadius: tokens.border.radius,
		},
		blockPseudoStyles: tokens.blockPseudoStyles,
	};
}
