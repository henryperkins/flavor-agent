function normalizeGlobalStylesId( value ) {
	if ( typeof value === 'string' && value.trim() ) {
		return value.trim();
	}

	if ( Number.isInteger( value ) && value > 0 ) {
		return String( value );
	}

	return null;
}

export function getCurrentGlobalStylesId( coreSelect ) {
	const stableId = normalizeGlobalStylesId(
		coreSelect?.getCurrentGlobalStylesId?.()
	);

	if ( stableId ) {
		return stableId;
	}

	return normalizeGlobalStylesId(
		coreSelect?.__experimentalGetCurrentGlobalStylesId?.()
	);
}

export function getCurrentThemeBaseGlobalStyles( coreSelect ) {
	return (
		coreSelect?.getCurrentThemeBaseGlobalStyles?.() ||
		coreSelect?.__experimentalGetCurrentThemeBaseGlobalStyles?.() ||
		null
	);
}

export function getCurrentThemeGlobalStylesVariations( coreSelect ) {
	const stableVariations =
		coreSelect?.getCurrentThemeGlobalStylesVariations?.();

	if ( Array.isArray( stableVariations ) ) {
		return stableVariations;
	}

	const experimentalVariations =
		coreSelect?.__experimentalGetCurrentThemeGlobalStylesVariations?.();

	return Array.isArray( experimentalVariations )
		? experimentalVariations
		: [];
}
