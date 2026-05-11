import { buildContextSignature } from './context-signature';

function normalizeStringValue( value ) {
	return typeof value === 'string' ? value.trim() : '';
}

function normalizeStringArray( value ) {
	if ( ! Array.isArray( value ) ) {
		return [];
	}

	return [
		...new Set(
			value
				.map( ( entry ) => normalizeStringValue( entry ) )
				.filter( Boolean )
		),
	].sort();
}

function normalizeNullableInteger( value ) {
	if ( value === null || value === undefined || value === '' ) {
		return null;
	}

	const number = Number( value );

	return Number.isInteger( number ) ? number : null;
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

function normalizePostContext( postContext ) {
	if ( ! postContext || typeof postContext !== 'object' ) {
		return {};
	}

	return {
		postId: postContext.postId ?? null,
		postType: normalizeStringValue( postContext.postType ),
		title: normalizeStringValue( postContext.title ),
		excerpt: normalizeStringValue( postContext.excerpt ),
		content: normalizeStringValue( postContext.content ),
		slug: normalizeStringValue( postContext.slug ),
		status: normalizeStringValue( postContext.status ),
	};
}

export function buildContentRecommendationRequestSignature( {
	mode = 'draft',
	prompt = '',
	postContext = null,
} = {} ) {
	const normalizedPostContext = normalizePostContext( postContext );
	const scopeKey = String(
		normalizedPostContext.postId ?? normalizedPostContext.postType ?? ''
	);

	return buildContextSignature( {
		surface: 'content',
		mode: normalizeStringValue( mode ) || 'draft',
		prompt: normalizeStringValue( prompt ),
		postContext: normalizedPostContext,
		scopeKey,
		entityRef: scopeKey,
	} );
}

export function buildPatternInsertionTargetSignature( input = {} ) {
	const normalizedInput =
		input && typeof input === 'object' && ! Array.isArray( input )
			? input
			: {};

	return buildContextSignature( {
		surface: 'pattern-insertion-target',
		postType: normalizeStringValue( normalizedInput.postType ),
		templateType: normalizeStringValue( normalizedInput.templateType ),
		inserterRootClientId: normalizeStringValue(
			normalizedInput.inserterRootClientId
		),
		insertionIndex: normalizeNullableInteger(
			normalizedInput.insertionIndex
		),
		insertionContext: normalizedInput.insertionContext || null,
	} );
}

export function buildPatternRecommendationRequestSignature( input = {} ) {
	const normalizedInput =
		input && typeof input === 'object' && ! Array.isArray( input )
			? input
			: {};

	// `document` is intentionally excluded: it carries activity-scope metadata
	// that the PHP execution layer strips before reaching the LLM
	// (RecommendationAbilityExecution::build_execution_input). Including it
	// here previously caused a false-positive freshness mismatch on every
	// Insert click, because the live signature in PatternRecommender does not
	// have access to the activity scope.
	return buildContextSignature( {
		surface: 'pattern',
		postType: normalizeStringValue( normalizedInput.postType ),
		templateType: normalizeStringValue( normalizedInput.templateType ),
		prompt: normalizeStringValue( normalizedInput.prompt ),
		visiblePatternNames: normalizeStringArray(
			normalizedInput.visiblePatternNames
		),
		insertionContext: normalizedInput.insertionContext || null,
		blockContext: normalizedInput.blockContext || null,
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
