function normalizeGlobalStylesId( value ) {
	if ( typeof value === 'string' && value.trim() ) {
		return value.trim();
	}

	if ( Number.isInteger( value ) && value > 0 ) {
		return String( value );
	}

	return null;
}

function safelyCallSelector( coreSelect, selectorName ) {
	try {
		return coreSelect?.[ selectorName ]?.();
	} catch {
		return undefined;
	}
}

export function getCurrentGlobalStylesId( coreSelect ) {
	const stableId = normalizeGlobalStylesId(
		safelyCallSelector( coreSelect, 'getCurrentGlobalStylesId' )
	);

	if ( stableId ) {
		return stableId;
	}

	return normalizeGlobalStylesId(
		safelyCallSelector(
			coreSelect,
			'__experimentalGetCurrentGlobalStylesId'
		)
	);
}

export function getCurrentThemeBaseGlobalStyles( coreSelect ) {
	return (
		safelyCallSelector( coreSelect, 'getCurrentThemeBaseGlobalStyles' ) ||
		safelyCallSelector(
			coreSelect,
			'__experimentalGetCurrentThemeBaseGlobalStyles'
		) ||
		null
	);
}

export const EMPTY_GLOBAL_STYLE_VARIATIONS = Object.freeze( [] );

export function getCurrentThemeGlobalStylesVariationRecords( coreSelect ) {
	const stableVariations = safelyCallSelector(
		coreSelect,
		'getCurrentThemeGlobalStylesVariations'
	);

	if ( Array.isArray( stableVariations ) ) {
		return stableVariations;
	}

	const experimentalVariations = safelyCallSelector(
		coreSelect,
		'__experimentalGetCurrentThemeGlobalStylesVariations'
	);

	return Array.isArray( experimentalVariations )
		? experimentalVariations
		: null;
}

export function getCurrentThemeGlobalStylesVariations( coreSelect ) {
	return (
		getCurrentThemeGlobalStylesVariationRecords( coreSelect ) ||
		EMPTY_GLOBAL_STYLE_VARIATIONS
	);
}
