import { buildContextSignature } from './context-signature';

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
	return buildContextSignature( {
		surface: normalizeStringValue( surface ),
		prompt: normalizeStringValue( prompt ),
		contextSignature: normalizeStringValue( contextSignature ),
		scopeKey: normalizeStringValue( scopeKey ),
		entityRef: normalizeStringValue( entityRef ),
	} );
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

export function buildNavigationRecommendationRequestSignature( {
	blockClientId = '',
	prompt = '',
	contextSignature = '',
} = {} ) {
	return buildRecommendationRequestSignature( {
		surface: 'navigation',
		prompt,
		contextSignature,
		scopeKey: blockClientId,
		entityRef: blockClientId,
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
		entityRef: [
			scope?.globalStylesId || scope?.entityId || '',
			scope?.blockName || '',
		]
			.filter( Boolean )
			.join( ':' ),
	} );
}
