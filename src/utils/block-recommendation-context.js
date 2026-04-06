function normalizeComparableValue( value ) {
	if ( Array.isArray( value ) ) {
		return value.map( ( item ) => normalizeComparableValue( item ) );
	}

	if ( value && typeof value === 'object' ) {
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

export function buildBlockRecommendationContextSignature( context = null ) {
	if ( ! context || typeof context !== 'object' ) {
		return '';
	}

	return JSON.stringify(
		normalizeComparableValue( {
			blockContext: context?.block || {},
			siblingsBefore: context?.siblingsBefore || [],
			siblingsAfter: context?.siblingsAfter || [],
			structuralAncestors: context?.structuralAncestors || [],
			structuralBranch: context?.structuralBranch || [],
			themeTokens: context?.themeTokens || {},
		} )
	);
}
