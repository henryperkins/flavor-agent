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
	const layout = features?.layout || {};

	return normalizeComparableValue( {
		presets: {
			color: normalizeOriginCollection( features?.color?.palette ),
			gradients: normalizeOriginCollection( features?.color?.gradients ),
			duotone: normalizeOriginCollection( features?.color?.duotone ),
			fontSizes: normalizeOriginCollection(
				features?.typography?.fontSizes
			),
			fontFamilies: normalizeOriginCollection(
				features?.typography?.fontFamilies
			),
			spacingSizes: normalizeOriginCollection(
				features?.spacing?.spacingSizes
			),
			shadows: normalizeOriginCollection( features?.shadow?.presets ),
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
