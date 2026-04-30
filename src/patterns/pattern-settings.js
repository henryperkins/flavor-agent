import { select as registrySelect } from '@wordpress/data';

const blockEditorStore = 'core/block-editor';

export const STABLE_PATTERNS_KEY = 'blockPatterns';
export const EXPERIMENTAL_PATTERNS_KEY = '__experimentalBlockPatterns';
export const EXPERIMENTAL_ADDITIONAL_PATTERNS_KEY =
	'__experimentalAdditionalBlockPatterns';
export const STABLE_CATEGORIES_KEY = 'blockPatternCategories';
export const EXPERIMENTAL_CATEGORIES_KEY =
	'__experimentalBlockPatternCategories';
export const EXPERIMENTAL_ADDITIONAL_CATEGORIES_KEY =
	'__experimentalAdditionalBlockPatternCategories';
export const STABLE_ALLOWED_SELECTOR = 'getAllowedPatterns';
export const EXPERIMENTAL_ALLOWED_SELECTOR = '__experimentalGetAllowedPatterns';

function getSettings( blockEditor ) {
	if ( blockEditor?.getSettings ) {
		return blockEditor.getSettings() || {};
	}

	return registrySelect( blockEditorStore ).getSettings?.() || {};
}

function resolvePatternsPath( settings ) {
	if ( Array.isArray( settings[ STABLE_PATTERNS_KEY ] ) ) {
		return 'stable';
	}

	if (
		Array.isArray( settings[ EXPERIMENTAL_ADDITIONAL_PATTERNS_KEY ] ) ||
		Array.isArray( settings[ EXPERIMENTAL_PATTERNS_KEY ] )
	) {
		return 'experimental';
	}

	return 'none';
}

function resolvePatternsKey( settings ) {
	if ( Array.isArray( settings[ STABLE_PATTERNS_KEY ] ) ) {
		return STABLE_PATTERNS_KEY;
	}

	if ( Array.isArray( settings[ EXPERIMENTAL_ADDITIONAL_PATTERNS_KEY ] ) ) {
		return EXPERIMENTAL_ADDITIONAL_PATTERNS_KEY;
	}

	return EXPERIMENTAL_PATTERNS_KEY;
}

function resolveCategoriesPath( settings ) {
	if ( Array.isArray( settings[ STABLE_CATEGORIES_KEY ] ) ) {
		return 'stable';
	}

	if (
		Array.isArray( settings[ EXPERIMENTAL_ADDITIONAL_CATEGORIES_KEY ] ) ||
		Array.isArray( settings[ EXPERIMENTAL_CATEGORIES_KEY ] )
	) {
		return 'experimental';
	}

	return 'none';
}

function resolveCategoriesKey( settings ) {
	if ( Array.isArray( settings[ STABLE_CATEGORIES_KEY ] ) ) {
		return STABLE_CATEGORIES_KEY;
	}

	if ( Array.isArray( settings[ EXPERIMENTAL_ADDITIONAL_CATEGORIES_KEY ] ) ) {
		return EXPERIMENTAL_ADDITIONAL_CATEGORIES_KEY;
	}

	return EXPERIMENTAL_CATEGORIES_KEY;
}

function resolveAllowedPatternsResult(
	rootClientId = null,
	blockEditor = registrySelect( blockEditorStore )
) {
	if ( typeof blockEditor?.[ STABLE_ALLOWED_SELECTOR ] === 'function' ) {
		return {
			value: blockEditor[ STABLE_ALLOWED_SELECTOR ]( rootClientId ) || [],
			path: 'stable-selector',
			fallbackMode: 'contextual',
		};
	}

	if (
		typeof blockEditor?.[ EXPERIMENTAL_ALLOWED_SELECTOR ] === 'function'
	) {
		return {
			value:
				blockEditor[ EXPERIMENTAL_ALLOWED_SELECTOR ]( rootClientId ) ||
				[],
			path: 'experimental-selector',
			fallbackMode: 'contextual',
		};
	}

	return {
		value: [],
		path: 'missing-selector',
		fallbackMode: 'none',
	};
}

export function getBlockPatterns( blockEditor ) {
	const settings = getSettings( blockEditor );
	const key = resolvePatternsKey( settings );
	const patterns = settings[ key ];

	return Array.isArray( patterns ) ? patterns : [];
}

export function getBlockPatternCategories() {
	const settings = getSettings();
	const key = resolveCategoriesKey( settings );
	const categories = settings[ key ];

	return Array.isArray( categories ) ? categories : [];
}

export function getAllowedPatterns( rootClientId = null, blockEditor ) {
	return resolveAllowedPatternsResult( rootClientId, blockEditor ).value;
}

export function getPatternAPIPath() {
	return resolvePatternsPath( getSettings() );
}

export function getPatternRuntimeDiagnostics(
	rootClientId = null,
	blockEditor
) {
	const settings = getSettings();
	const allowedPatterns = resolveAllowedPatternsResult(
		rootClientId,
		blockEditor
	);

	return {
		patternsPath: resolvePatternsPath( settings ),
		categoriesPath: resolveCategoriesPath( settings ),
		allowedPatternsPath: allowedPatterns.path,
		allowedPatternsFallbackMode: allowedPatterns.fallbackMode,
	};
}
