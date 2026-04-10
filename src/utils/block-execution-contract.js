const STYLE_PANELS = new Set( [
	'color',
	'filter',
	'typography',
	'dimensions',
	'border',
	'shadow',
	'background',
] );

function sanitizeStringList( values = [] ) {
	if ( ! Array.isArray( values ) ) {
		return [];
	}

	return [
		...new Set(
			values
				.filter(
					( value ) =>
						typeof value === 'string' && value.trim() !== ''
				)
				.map( ( value ) => value.trim() )
		),
	].sort( ( left, right ) => left.localeCompare( right ) );
}

function normalizePanelKey( value ) {
	return typeof value === 'string'
		? value
				.trim()
				.toLowerCase()
				.replace( /[^a-z0-9_-]/g, '' )
		: '';
}

function normalizeInspectorPanels( inspectorPanels = {} ) {
	if ( Array.isArray( inspectorPanels ) ) {
		return {};
	}

	if ( ! inspectorPanels || typeof inspectorPanels !== 'object' ) {
		return {};
	}

	return Object.fromEntries(
		Object.entries( inspectorPanels )
			.map( ( [ panel, entries ] ) => [
				normalizePanelKey( panel ),
				sanitizeStringList( entries ),
			] )
			.filter( ( [ panel ] ) => panel )
			.sort( ( [ left ], [ right ] ) => left.localeCompare( right ) )
	);
}

function collectPresetSlugs( presets = [] ) {
	return [
		...new Set(
			( Array.isArray( presets ) ? presets : [] )
				.map( ( preset ) => preset?.slug )
				.filter(
					( slug ) => typeof slug === 'string' && slug.trim() !== ''
				)
				.map( ( slug ) => slug.trim() )
		),
	].sort( ( left, right ) => left.localeCompare( right ) );
}

export function buildBlockRecommendationExecutionContract(
	blockContext = {},
	themeTokens = {}
) {
	const inspectorPanels = normalizeInspectorPanels(
		blockContext?.inspectorPanels || {}
	);
	const contentAttributeKeys = Object.keys(
		blockContext?.contentAttributes || {}
	).filter( Boolean );
	const bindableAttributes = sanitizeStringList(
		blockContext?.bindableAttributes || []
	);
	const registeredStyles = sanitizeStringList(
		( Array.isArray( blockContext?.styles )
			? blockContext.styles
			: []
		).map( ( style ) => style?.name )
	);
	const styleSupportPaths = Object.entries( inspectorPanels )
		.filter( ( [ panel ] ) => STYLE_PANELS.has( panel ) )
		.flatMap( ( [ , entries ] ) => entries );

	return {
		inspectorPanels,
		allowedPanels: Object.keys( inspectorPanels ),
		hasExplicitlyEmptyPanels:
			Object.prototype.hasOwnProperty.call(
				blockContext || {},
				'inspectorPanels'
			) && Object.keys( inspectorPanels ).length === 0,
		styleSupportPaths: sanitizeStringList( styleSupportPaths ),
		bindableAttributes,
		contentAttributeKeys,
		configAttributeKeys: Object.keys(
			blockContext?.configAttributes || {}
		).filter( Boolean ),
		supportsContentRole: blockContext?.supportsContentRole === true,
		editingMode:
			blockContext?.editingMode === 'contentOnly' ||
			blockContext?.editingMode === 'disabled'
				? blockContext.editingMode
				: 'default',
		isInsideContentOnly: blockContext?.isInsideContentOnly === true,
		usesInnerBlocksAsContent:
			blockContext?.supportsContentRole === true &&
			contentAttributeKeys.length === 0,
		registeredStyles,
		presetSlugs: {
			color: collectPresetSlugs( themeTokens?.colorPresets || [] ),
			gradient: collectPresetSlugs( themeTokens?.gradientPresets || [] ),
			duotone: collectPresetSlugs( themeTokens?.duotonePresets || [] ),
			fontsize: collectPresetSlugs( themeTokens?.fontSizePresets || [] ),
			fontfamily: collectPresetSlugs(
				themeTokens?.fontFamilyPresets || []
			),
			spacing: collectPresetSlugs( themeTokens?.spacingPresets || [] ),
			shadow: collectPresetSlugs( themeTokens?.shadowPresets || [] ),
		},
		enabledFeatures:
			themeTokens && typeof themeTokens.enabledFeatures === 'object'
				? { ...themeTokens.enabledFeatures }
				: {},
		layout:
			themeTokens && typeof themeTokens.layout === 'object'
				? { ...themeTokens.layout }
				: {},
	};
}
