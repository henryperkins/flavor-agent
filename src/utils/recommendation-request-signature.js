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

function normalizeStringValue( value ) {
	return typeof value === 'string' ? value.trim() : '';
}

export function buildRecommendationRequestSignature( {
	surface = '',
	prompt = '',
	contextSignature = '',
	scopeKey = '',
	entityRef = '',
} = {} ) {
	return JSON.stringify(
		normalizeComparableValue( {
			surface: normalizeStringValue( surface ),
			prompt: normalizeStringValue( prompt ),
			contextSignature: normalizeStringValue( contextSignature ),
			scopeKey: normalizeStringValue( scopeKey ),
			entityRef: normalizeStringValue( entityRef ),
		} )
	);
}

export function buildBlockRecommendationRequestSignature( {
	clientId = '',
	prompt = '',
	contextSignature = '',
} = {} ) {
	return buildRecommendationRequestSignature( {
		surface: 'block',
		prompt,
		contextSignature,
		scopeKey: clientId,
		entityRef: clientId,
	} );
}

export function buildTemplateRecommendationRequestSignature( {
	templateRef = '',
	prompt = '',
	contextSignature = '',
} = {} ) {
	return buildRecommendationRequestSignature( {
		surface: 'template',
		prompt,
		contextSignature,
		scopeKey: templateRef,
		entityRef: templateRef,
	} );
}

export function buildTemplatePartRecommendationRequestSignature( {
	templatePartRef = '',
	prompt = '',
	contextSignature = '',
} = {} ) {
	return buildRecommendationRequestSignature( {
		surface: 'template-part',
		prompt,
		contextSignature,
		scopeKey: templatePartRef,
		entityRef: templatePartRef,
	} );
}

export function buildGlobalStylesRecommendationRequestSignature( {
	scope = null,
	prompt = '',
	contextSignature = '',
} = {} ) {
	return buildRecommendationRequestSignature( {
		surface: 'global-styles',
		prompt,
		contextSignature,
		scopeKey: scope?.scopeKey || '',
		entityRef: scope?.globalStylesId || scope?.entityId || '',
	} );
}

export function buildStyleBookRecommendationRequestSignature( {
	scope = null,
	prompt = '',
	contextSignature = '',
} = {} ) {
	return buildRecommendationRequestSignature( {
		surface: 'style-book',
		prompt,
		contextSignature,
		scopeKey: scope?.scopeKey || '',
		entityRef:
			[
				scope?.globalStylesId || scope?.entityId || '',
				scope?.blockName || '',
			]
				.filter( Boolean )
				.join( ':' ),
	} );
}
