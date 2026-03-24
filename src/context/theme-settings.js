import { select } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';

export const STABLE_THEME_FEATURES_KEY = 'features';
export const EXPERIMENTAL_THEME_FEATURES_KEY = '__experimentalFeatures';

const BLOCK_PSEUDO_CLASSES = [
	':hover',
	':focus',
	':focus-visible',
	':active',
];
const DEFAULT_SPACING_UNITS = [ 'px', 'em', 'rem', 'vh', 'vw', '%' ];

function isObjectLike( value ) {
	return (
		Boolean( value ) &&
		typeof value === 'object' &&
		! Array.isArray( value )
	);
}

function normalizeComparableValue( value ) {
	if ( Array.isArray( value ) ) {
		return value.map( ( item ) => normalizeComparableValue( item ) );
	}

	if ( isObjectLike( value ) ) {
		return Object.fromEntries(
			Object.entries( value )
				.sort( ( [ leftKey ], [ rightKey ] ) =>
					leftKey.localeCompare( rightKey )
				)
				.map( ( [ key, entryValue ] ) => [
					key,
					normalizeComparableValue( entryValue ),
				] )
		);
	}

	return value;
}

function normalizeOriginCollection( collection ) {
	if ( Array.isArray( collection ) ) {
		return normalizeComparableValue( collection );
	}

	if ( ! isObjectLike( collection ) ) {
		return {};
	}

	return normalizeComparableValue( {
		default: collection.default || [],
		theme: collection.theme || [],
		custom: collection.custom || [],
	} );
}

function collectBlockPseudoStylesForParity( features ) {
	const blockStyles = features?.styles?.blocks || {};
	const result = {};

	for ( const [ blockName, styleDef ] of Object.entries( blockStyles ) ) {
		const pseudos = {};

		for ( const pseudo of BLOCK_PSEUDO_CLASSES ) {
			if ( styleDef?.[ pseudo ] ) {
				pseudos[ pseudo ] = styleDef[ pseudo ];
			}
		}

		if ( Object.keys( pseudos ).length > 0 ) {
			result[ blockName ] = pseudos;
		}
	}

	return normalizeComparableValue( result );
}

function buildThemeParitySnapshot( settings = {}, features = {} ) {
	const color = features?.color || {};
	const typography = features?.typography || {};
	const spacing = features?.spacing || {};
	const layout = features?.layout || {};
	const shadow = features?.shadow || {};
	const border = features?.border || {};
	const background = features?.background || {};

	return normalizeComparableValue( {
		presets: {
			color: normalizeOriginCollection( color.palette ),
			gradients: normalizeOriginCollection( color.gradients ),
			duotone: normalizeOriginCollection( color.duotone ),
			fontSizes: normalizeOriginCollection( typography.fontSizes ),
			fontFamilies: normalizeOriginCollection( typography.fontFamilies ),
			spacingSizes: normalizeOriginCollection( spacing.spacingSizes ),
			shadows: normalizeOriginCollection( shadow.presets ),
		},
		capabilities: {
			color: {
				custom: color.custom !== false,
				customGradient: color.customGradient !== false,
				defaultPalette: color.defaultPalette !== false,
				background: color.background !== false,
				text: color.text !== false,
				link: color.link ?? false,
			},
			typography: {
				customFontSize: typography.customFontSize !== false,
				lineHeight: typography.lineHeight ?? false,
				dropCap: typography.dropCap ?? true,
				fontStyle: typography.fontStyle ?? false,
				fontWeight: typography.fontWeight ?? false,
				letterSpacing: typography.letterSpacing ?? false,
				textDecoration: typography.textDecoration ?? false,
				textTransform: typography.textTransform ?? false,
				writingMode: typography.writingMode ?? false,
				fluid: typography.fluid ?? false,
			},
			spacing: {
				units: spacing.units ?? DEFAULT_SPACING_UNITS,
				margin: spacing.margin ?? false,
				padding: spacing.padding ?? false,
				blockGap: spacing.blockGap ?? null,
				customSpacingSize: spacing.customSpacingSize !== false,
			},
			shadow: {
				defaultPresets: shadow.defaultPresets ?? true,
			},
			border: {
				color: border.color ?? false,
				radius: border.radius ?? false,
				style: border.style ?? false,
				width: border.width ?? false,
			},
			background: {
				backgroundImage: background.backgroundImage ?? false,
				backgroundSize: background.backgroundSize ?? false,
			},
		},
		layout: {
			contentSize:
				layout.contentSize || settings?.layout?.contentSize || '',
			wideSize: layout.wideSize || settings?.layout?.wideSize || '',
			allowEditing: layout.allowEditing !== false,
			allowCustomContentAndWideSize:
				layout.allowCustomContentAndWideSize !== false,
		},
		elements: normalizeComparableValue( features?.styles?.elements || {} ),
		blockPseudoStyles: collectBlockPseudoStylesForParity( features ),
	} );
}

function hasStableThemeTokenParity(
	settings,
	stableFeatures,
	experimentalFeatures
) {
	return (
		JSON.stringify(
			buildThemeParitySnapshot( settings, stableFeatures )
		) ===
		JSON.stringify(
			buildThemeParitySnapshot( settings, experimentalFeatures )
		)
	);
}

export function getThemeEditorSettings() {
	return select( blockEditorStore ).getSettings?.() || {};
}

export function getThemeTokenSourceDetails(
	settings = getThemeEditorSettings()
) {
	const stableFeatures = isObjectLike( settings[ STABLE_THEME_FEATURES_KEY ] )
		? settings[ STABLE_THEME_FEATURES_KEY ]
		: null;
	const experimentalFeatures = isObjectLike(
		settings[ EXPERIMENTAL_THEME_FEATURES_KEY ]
	)
		? settings[ EXPERIMENTAL_THEME_FEATURES_KEY ]
		: null;

	if ( stableFeatures && experimentalFeatures ) {
		if (
			hasStableThemeTokenParity(
				settings,
				stableFeatures,
				experimentalFeatures
			)
		) {
			return {
				source: 'stable',
				settingsKey: STABLE_THEME_FEATURES_KEY,
				reason: 'stable-parity',
				features: stableFeatures,
			};
		}

		return {
			source: 'experimental',
			settingsKey: EXPERIMENTAL_THEME_FEATURES_KEY,
			reason: 'stable-parity-mismatch',
			features: experimentalFeatures,
		};
	}

	if ( experimentalFeatures ) {
		return {
			source: 'experimental',
			settingsKey: EXPERIMENTAL_THEME_FEATURES_KEY,
			reason: 'experimental-only',
			features: experimentalFeatures,
		};
	}

	if ( stableFeatures ) {
		return {
			source: 'stable-fallback',
			settingsKey: STABLE_THEME_FEATURES_KEY,
			reason: 'stable-unverified',
			features: stableFeatures,
		};
	}

	return {
		source: 'none',
		settingsKey: null,
		reason: 'missing',
		features: {},
	};
}

export function getThemeTokenSource( settings ) {
	return getThemeTokenSourceDetails( settings ).source;
}

export function getThemeTokenFeatures( settings ) {
	return getThemeTokenSourceDetails( settings ).features;
}
