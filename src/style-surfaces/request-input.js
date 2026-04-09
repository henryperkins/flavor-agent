function buildStyleScope( surface, scope = {} ) {
	return {
		surface,
		scopeKey: scope?.scopeKey || '',
		globalStylesId: scope?.globalStylesId || '',
		postType: scope?.postType || 'global_styles',
		entityId:
			scope?.entityId ||
			( surface === 'style-book'
				? scope?.blockName || ''
				: scope?.globalStylesId || '' ),
		entityKind:
			scope?.entityKind ||
			( surface === 'style-book' ? 'block' : 'root' ),
		entityName:
			scope?.entityName ||
			( surface === 'style-book' ? 'styleBook' : 'globalStyles' ),
		stylesheet: scope?.stylesheet || '',
		templateSlug: scope?.templateSlug || '',
		templateType: scope?.templateType || '',
		...( surface === 'style-book'
			? {
					blockName: scope?.blockName || '',
					blockTitle: scope?.blockTitle || '',
			  }
			: {} ),
	};
}

export function buildStyleRecommendationRequestInput( {
	surface = 'global-styles',
	scope = null,
	prompt = '',
	contextSignature = '',
	currentConfig,
	mergedConfig,
	templateStructure,
	templateVisibility,
	designSemantics,
	themeTokenDiagnostics,
	availableVariations,
	styleBookTarget,
} ) {
	const normalizedPrompt = typeof prompt === 'string' ? prompt.trim() : '';
	const styleContext = {
		currentConfig,
		mergedConfig,
		templateStructure,
		templateVisibility,
		designSemantics,
		themeTokenDiagnostics,
	};

	if ( Array.isArray( availableVariations ) ) {
		styleContext.availableVariations = availableVariations;
	}

	if ( styleBookTarget ) {
		styleContext.styleBookTarget = styleBookTarget;
	}

	return {
		scope: buildStyleScope( surface, scope ),
		styleContext,
		contextSignature,
		...( normalizedPrompt ? { prompt: normalizedPrompt } : {} ),
	};
}
