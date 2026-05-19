const STYLE_SUPPORT_PATH_ALIASES = {
	'typography.__experimentalFontFamily': 'typography.fontFamily',
};

export function normalizeStyleSupportPath( supportPath = '' ) {
	if ( typeof supportPath !== 'string' ) {
		return '';
	}

	const normalized = supportPath.trim();

	return STYLE_SUPPORT_PATH_ALIASES[ normalized ] || normalized;
}

export function normalizeStyleSupportPaths( supportPaths = [] ) {
	if ( ! Array.isArray( supportPaths ) ) {
		return [];
	}

	return [
		...new Set(
			supportPaths
				.map( ( supportPath ) =>
					normalizeStyleSupportPath( supportPath )
				)
				.filter( Boolean )
		),
	].sort( ( left, right ) => left.localeCompare( right ) );
}
