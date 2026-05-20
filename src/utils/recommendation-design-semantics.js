export const DESIGN_SEMANTICS_LIST_CAP = 6;
export const DESIGN_SEMANTICS_SURFACE_LEAF_CAP = 8;

const ALLOWED_SURFACES = [
	'block',
	'template',
	'template-part',
	'global-styles',
	'style-book',
];

const ALLOWED_SECTION_ROLES = [
	'hero',
	'header',
	'footer',
	'card',
	'sidebar',
	'post-body',
	'cta',
	'archive-list',
	'unknown',
];

const ALLOWED_VISUAL_DENSITY = [ 'sparse', 'balanced', 'dense', 'unknown' ];

const ALLOWED_CONTRAST_CONTEXT = [
	'dark-parent',
	'light-parent',
	'image-overlay',
	'unknown',
];

const ALLOWED_LAYOUT_RHYTHM = [
	'constrained',
	'full-width',
	'grid',
	'stacked',
	'media-text',
	'sidebar',
	'unknown',
];

const ALLOWED_TYPOGRAPHY_ROLES = [
	'heading',
	'body',
	'metadata',
	'navigation',
	'callout',
	'unknown',
];

const ALLOWED_DESIGN_ISSUES = [
	'contrast',
	'spacing',
	'hierarchy',
	'rhythm',
	'alignment',
	'consistency',
	'accessibility',
	'none',
	'unknown',
];

const TEMPLATE_PART_AREA_ROLES = [ 'header', 'footer', 'sidebar' ];

function isPlainObject( value ) {
	return (
		value !== null && typeof value === 'object' && ! Array.isArray( value )
	);
}

function cleanString( value ) {
	return typeof value === 'string' ? value.trim() : '';
}

function cleanSlug( value ) {
	return cleanString( value ).toLowerCase();
}

function normalizeEnum( value, allowedValues, fallback = 'unknown' ) {
	const normalized = cleanSlug( value );

	return allowedValues.includes( normalized ) ? normalized : fallback;
}

function uniqueSortedStrings( values = [], cap = DESIGN_SEMANTICS_LIST_CAP ) {
	if ( ! Array.isArray( values ) ) {
		return [];
	}

	return Array.from(
		new Set( values.map( cleanString ).filter( ( value ) => value !== '' ) )
	)
		.slice( 0, cap )
		.sort( ( left, right ) => left.localeCompare( right ) );
}

function clampScore( value ) {
	const number = Number( value );

	if ( ! Number.isFinite( number ) ) {
		return 0;
	}

	return Math.min( 1, Math.max( 0, number ) );
}

function normalizeTokenAffinity( value = {} ) {
	const source = isPlainObject( value ) ? value : {};

	return {
		color: uniqueSortedStrings( source.color ),
		spacing: uniqueSortedStrings( source.spacing ),
		fontSize: uniqueSortedStrings( source.fontSize ),
	};
}

function isScalarLeaf( value ) {
	return (
		typeof value === 'string' ||
		typeof value === 'boolean' ||
		( typeof value === 'number' && Number.isFinite( value ) )
	);
}

function normalizeScalarLeaf( value ) {
	if ( typeof value === 'string' ) {
		return cleanString( value );
	}

	return value;
}

function normalizeSurfaceDetails( value = {} ) {
	if ( ! isPlainObject( value ) ) {
		return {};
	}

	let leafCount = 0;

	function visit( source ) {
		const normalized = {};

		for ( const key of Object.keys( source ).sort( ( left, right ) =>
			left.localeCompare( right )
		) ) {
			if ( leafCount >= DESIGN_SEMANTICS_SURFACE_LEAF_CAP ) {
				break;
			}

			const child = source[ key ];

			if ( isScalarLeaf( child ) ) {
				const normalizedLeaf = normalizeScalarLeaf( child );
				if ( normalizedLeaf === '' ) {
					continue;
				}

				normalized[ key ] = normalizedLeaf;
				leafCount += 1;
				continue;
			}

			if ( isPlainObject( child ) ) {
				const nested = visit( child );
				if ( Object.keys( nested ).length > 0 ) {
					normalized[ key ] = nested;
				}
			}
		}

		return normalized;
	}

	return visit( value );
}

function detailIfNotEmpty( target, key, value ) {
	const normalized = normalizeSurfaceDetails( value );

	if ( Object.keys( normalized ).length > 0 ) {
		target[ key ] = normalized;
	}
}

export function normalizeDesignSemantics( value = {}, surface = '' ) {
	if ( ! isPlainObject( value ) ) {
		return {};
	}

	const normalizedSurface = normalizeEnum(
		value.surface || surface,
		ALLOWED_SURFACES,
		ALLOWED_SURFACES.includes( surface ) ? surface : 'block'
	);

	const semantics = {
		surface: normalizedSurface,
		sectionRole: normalizeEnum( value.sectionRole, ALLOWED_SECTION_ROLES ),
		visualDensity: normalizeEnum(
			value.visualDensity,
			ALLOWED_VISUAL_DENSITY
		),
		contrastContext: normalizeEnum(
			value.contrastContext,
			ALLOWED_CONTRAST_CONTEXT
		),
		layoutRhythm: normalizeEnum(
			value.layoutRhythm,
			ALLOWED_LAYOUT_RHYTHM
		),
		typographyRole: normalizeEnum(
			value.typographyRole,
			ALLOWED_TYPOGRAPHY_ROLES
		),
		tokenAffinity: normalizeTokenAffinity( value.tokenAffinity ),
		existingDesignScore: clampScore( value.existingDesignScore ),
		mainDesignIssue: normalizeEnum(
			value.mainDesignIssue,
			ALLOWED_DESIGN_ISSUES
		),
		negativeSignals: uniqueSortedStrings( value.negativeSignals ),
	};

	detailIfNotEmpty( semantics, 'block', value.block );
	detailIfNotEmpty( semantics, 'template', value.template );
	detailIfNotEmpty( semantics, 'templatePart', value.templatePart );

	return semantics;
}

function textIncludes( value, needles = [] ) {
	const haystack = cleanSlug( value );

	return needles.some( ( needle ) => haystack.includes( needle ) );
}

function deriveSectionRole( ...values ) {
	const joined = values.map( cleanSlug ).join( ' ' );

	if ( textIncludes( joined, [ 'hero' ] ) ) {
		return 'hero';
	}
	if ( textIncludes( joined, [ 'header' ] ) ) {
		return 'header';
	}
	if ( textIncludes( joined, [ 'footer' ] ) ) {
		return 'footer';
	}
	if ( textIncludes( joined, [ 'sidebar' ] ) ) {
		return 'sidebar';
	}
	if ( textIncludes( joined, [ 'card' ] ) ) {
		return 'card';
	}
	if ( textIncludes( joined, [ 'cta', 'call-to-action' ] ) ) {
		return 'cta';
	}
	if ( textIncludes( joined, [ 'archive', 'query', 'loop', 'list' ] ) ) {
		return 'archive-list';
	}
	if ( textIncludes( joined, [ 'post', 'body', 'content', 'main' ] ) ) {
		return 'post-body';
	}

	return 'unknown';
}

function getNestedValue( source, path = [] ) {
	let current = source;

	for ( const segment of path ) {
		if ( ! isPlainObject( current ) || ! ( segment in current ) ) {
			return undefined;
		}

		current = current[ segment ];
	}

	return current;
}

function deriveContrastContext( parentContext = {} ) {
	const hints = isPlainObject( parentContext?.visualHints )
		? parentContext.visualHints
		: {};

	if (
		hints.gradient ||
		hints.dimRatio ||
		textIncludes( parentContext?.block, [ 'cover' ] )
	) {
		return 'image-overlay';
	}

	const background = [
		hints.backgroundColor,
		getNestedValue( hints, [ 'style', 'color', 'background' ] ),
		hints.textColor,
		getNestedValue( hints, [ 'style', 'color', 'text' ] ),
	]
		.map( cleanSlug )
		.filter( Boolean )
		.join( ' ' );

	if ( textIncludes( background, [ 'contrast', 'black', 'dark' ] ) ) {
		return 'dark-parent';
	}

	if ( textIncludes( background, [ 'base', 'white', 'light' ] ) ) {
		return 'light-parent';
	}

	return 'unknown';
}

function deriveLayoutRhythm( {
	parentContext = {},
	siblingSummaries = [],
} = {} ) {
	const hints = isPlainObject( parentContext?.visualHints )
		? parentContext.visualHints
		: {};
	const layoutType = cleanSlug(
		getNestedValue( hints, [ 'layout', 'type' ] )
	);

	if ( layoutType === 'constrained' ) {
		return 'constrained';
	}
	if ( layoutType === 'grid' ) {
		return 'grid';
	}
	if ( layoutType === 'flex' ) {
		return 'media-text';
	}

	const siblingText = siblingSummaries
		.map(
			( sibling ) => `${ sibling?.block || '' } ${ sibling?.role || '' }`
		)
		.join( ' ' );

	if ( textIncludes( siblingText, [ 'grid' ] ) ) {
		return 'grid';
	}
	if ( textIncludes( siblingText, [ 'media-text', 'columns' ] ) ) {
		return 'media-text';
	}

	const align = cleanSlug( hints.align );
	if ( align === 'full' ) {
		return 'full-width';
	}
	if ( deriveSectionRole( siblingText, parentContext?.role ) === 'sidebar' ) {
		return 'sidebar';
	}

	return 'stacked';
}

function deriveTypographyRole( block = {} ) {
	const name = cleanSlug( block?.name );
	const role = cleanSlug( block?.structuralIdentity?.role );
	const title = cleanSlug( block?.title );
	const text = `${ name } ${ role } ${ title }`;

	if ( textIncludes( text, [ 'heading', 'title' ] ) ) {
		return 'heading';
	}
	if ( textIncludes( text, [ 'navigation', 'menu' ] ) ) {
		return 'navigation';
	}
	if (
		textIncludes( text, [
			'date',
			'author',
			'byline',
			'metadata',
			'meta',
			'caption',
		] )
	) {
		return 'metadata';
	}
	if ( textIncludes( text, [ 'button', 'quote', 'pullquote', 'cta' ] ) ) {
		return 'callout';
	}
	if ( textIncludes( text, [ 'paragraph', 'list', 'content' ] ) ) {
		return 'body';
	}

	return 'unknown';
}

function getPresetSlugs( presets = [] ) {
	return Array.isArray( presets )
		? presets
				.map( ( preset ) => cleanString( preset?.slug ) )
				.filter( Boolean )
		: [];
}

function collectTokenAffinity( block = {}, themeTokens = {} ) {
	const attributes = isPlainObject( block?.currentAttributes )
		? block.currentAttributes
		: {};
	const colorPresets = getPresetSlugs( themeTokens?.colorPresets );
	const fontSizePresets = getPresetSlugs( themeTokens?.fontSizePresets );
	const spacingPresets = getPresetSlugs( themeTokens?.spacingPresets );
	const colorValues = [
		attributes.textColor,
		attributes.backgroundColor,
		attributes.gradient,
		getNestedValue( attributes, [ 'style', 'color', 'text' ] ),
		getNestedValue( attributes, [ 'style', 'color', 'background' ] ),
	];
	const spacingValues = [
		getNestedValue( attributes, [ 'style', 'spacing', 'blockGap' ] ),
		getNestedValue( attributes, [ 'style', 'spacing', 'padding', 'top' ] ),
		getNestedValue( attributes, [
			'style',
			'spacing',
			'padding',
			'bottom',
		] ),
		getNestedValue( attributes, [ 'style', 'spacing', 'margin', 'top' ] ),
		getNestedValue( attributes, [
			'style',
			'spacing',
			'margin',
			'bottom',
		] ),
	];

	return {
		color: colorValues.filter( ( value ) =>
			colorPresets.includes( cleanString( value ) )
		),
		fontSize: [ attributes.fontSize ].filter( ( value ) =>
			fontSizePresets.includes( cleanString( value ) )
		),
		spacing: spacingValues
			.map( ( value ) =>
				cleanString( value ).replace( /^var:preset\|spacing\|/, '' )
			)
			.filter( ( value ) => spacingPresets.includes( value ) ),
	};
}

function hasTypographySupport( block = {} ) {
	const panels = isPlainObject( block?.inspectorPanels )
		? block.inspectorPanels
		: {};
	const styles = Array.isArray( panels.styles ) ? panels.styles : [];

	return Boolean(
		panels.typography ||
			styles.includes( 'typography' ) ||
			styles.includes( 'fontSize' )
	);
}

function hasStructuralPatternActions( blockOperationContext = {} ) {
	const allowedPatterns = Array.isArray(
		blockOperationContext?.allowedPatterns
	)
		? blockOperationContext.allowedPatterns
		: [];

	return allowedPatterns.some( ( pattern ) =>
		Array.isArray( pattern?.allowedActions )
			? pattern.allowedActions.length > 0
			: false
	);
}

function collectBlockNegativeSignals( context = {} ) {
	const signals = [];
	const block = isPlainObject( context?.block ) ? context.block : {};

	if ( ! hasTypographySupport( block ) ) {
		signals.push( 'no-typography-support' );
	}
	if ( block.isInsideContentOnly ) {
		signals.push( 'content-only-context' );
	}
	if (
		[ 'disabled', 'contentOnly' ].includes(
			cleanString( block.editingMode )
		)
	) {
		signals.push( 'locked-editing-mode' );
	}
	if (
		context?.blockOperationContext &&
		! hasStructuralPatternActions( context.blockOperationContext )
	) {
		signals.push( 'no-structural-pattern-actions' );
	}
	if (
		deriveContrastContext( context?.parentContext ) !== 'unknown' &&
		( block?.currentAttributes?.textColor ||
			getNestedValue( block?.currentAttributes, [
				'style',
				'color',
				'text',
			] ) )
	) {
		signals.push( 'parent-already-supplies-contrast' );
	}

	return signals;
}

function deriveVisualDensityFromCount( count ) {
	const number = Number( count );

	if ( ! Number.isFinite( number ) ) {
		return 'balanced';
	}
	if ( number <= 0 ) {
		return 'sparse';
	}
	if ( number >= 8 ) {
		return 'dense';
	}

	return 'balanced';
}

export function buildBlockDesignSemantics( context = {} ) {
	const block = isPlainObject( context?.block ) ? context.block : {};
	const identity = isPlainObject( block?.structuralIdentity )
		? block.structuralIdentity
		: {};
	const siblings = [
		...( Array.isArray( context?.siblingSummariesBefore )
			? context.siblingSummariesBefore
			: [] ),
		...( Array.isArray( context?.siblingSummariesAfter )
			? context.siblingSummariesAfter
			: [] ),
	];

	return normalizeDesignSemantics( {
		surface: 'block',
		sectionRole: deriveSectionRole(
			identity.location,
			identity.role,
			context?.parentContext?.role,
			...( Array.isArray( context?.structuralAncestors )
				? context.structuralAncestors.map(
						( ancestor ) => ancestor?.role
				  )
				: [] )
		),
		visualDensity: deriveVisualDensityFromCount(
			Number( block.childCount || 0 ) + siblings.length
		),
		contrastContext: deriveContrastContext( context?.parentContext ),
		layoutRhythm: deriveLayoutRhythm( {
			parentContext: context?.parentContext,
			siblingSummaries: siblings,
		} ),
		typographyRole: deriveTypographyRole( block ),
		tokenAffinity: collectTokenAffinity( block, context?.themeTokens ),
		existingDesignScore: 0,
		mainDesignIssue: 'none',
		negativeSignals: collectBlockNegativeSignals( context ),
		block: {
			name: block.name,
			title: block.title,
			role: identity.role,
			location: identity.location,
			job: identity.job,
			parentBlock: context?.parentContext?.block,
			parentRole: context?.parentContext?.role,
		},
	} );
}

function getStructureStats( editorStructure = {} ) {
	return isPlainObject( editorStructure?.structureStats )
		? editorStructure.structureStats
		: {};
}

function getTopLevelBlocks( editorStructure = {} ) {
	if ( Array.isArray( editorStructure?.topLevelBlocks ) ) {
		return editorStructure.topLevelBlocks;
	}

	if ( Array.isArray( editorStructure?.topLevelBlockTree ) ) {
		return editorStructure.topLevelBlockTree
			.map( ( block ) => cleanString( block?.name ) )
			.filter( Boolean );
	}

	return [];
}

function deriveTemplateSectionRole( {
	templateType = '',
	stats = {},
	topLevelBlocks = [],
} = {} ) {
	const topLevelText = topLevelBlocks.join( ' ' );

	if (
		textIncludes( templateType, [
			'archive',
			'search',
			'category',
			'tag',
		] ) ||
		stats.hasQuery ||
		textIncludes( topLevelText, [ 'core/query' ] )
	) {
		return 'archive-list';
	}

	if ( textIncludes( templateType, [ 'single', 'page', 'post' ] ) ) {
		return 'post-body';
	}

	return deriveSectionRole( templateType, topLevelText );
}

function deriveTemplateRhythm( {
	stats = {},
	topLevelBlocks = [],
	visiblePatternNames = [],
} = {} ) {
	const text = [ ...topLevelBlocks, ...visiblePatternNames ].join( ' ' );

	if ( stats.hasQuery || textIncludes( text, [ 'query', 'grid' ] ) ) {
		return 'grid';
	}
	if ( stats.hasSingleWrapperGroup ) {
		return 'constrained';
	}
	if ( textIncludes( text, [ 'media-text' ] ) ) {
		return 'media-text';
	}

	return topLevelBlocks.length <= 1 ? 'constrained' : 'stacked';
}

function hasAssignedPartArea( assignedParts = [], area = '' ) {
	return Array.isArray( assignedParts )
		? assignedParts.some( ( part ) => cleanSlug( part?.area ) === area )
		: false;
}

export function buildTemplateDesignSemantics( {
	templateType,
	editorSlots,
	editorStructure,
	visiblePatternNames,
} = {} ) {
	const slots = isPlainObject( editorSlots ) ? editorSlots : {};
	const structure = isPlainObject( editorStructure ) ? editorStructure : {};
	const stats = getStructureStats( structure );
	const topLevelBlocks = getTopLevelBlocks( structure );
	const patterns = uniqueSortedStrings( visiblePatternNames );
	const assignedParts = Array.isArray( slots.assignedParts )
		? slots.assignedParts
		: [];
	const normalizedTemplateType =
		cleanString( templateType ) ||
		cleanString( slots.templateType ) ||
		cleanString( slots.type ) ||
		cleanString( slots.slug );

	return normalizeDesignSemantics( {
		surface: 'template',
		sectionRole: deriveTemplateSectionRole( {
			templateType: normalizedTemplateType,
			stats,
			topLevelBlocks,
		} ),
		visualDensity: deriveVisualDensityFromCount( stats.blockCount ),
		contrastContext: 'unknown',
		layoutRhythm: deriveTemplateRhythm( {
			stats,
			topLevelBlocks,
			visiblePatternNames: patterns,
		} ),
		typographyRole: 'unknown',
		tokenAffinity: {},
		existingDesignScore: 0,
		mainDesignIssue: stats.hasQuery ? 'rhythm' : 'unknown',
		negativeSignals: patterns.length === 0 ? [ 'no-visible-patterns' ] : [],
		template: {
			templateType: normalizedTemplateType,
			hasHeader: hasAssignedPartArea( assignedParts, 'header' ),
			hasFooter: hasAssignedPartArea( assignedParts, 'footer' ),
			blockCount: Number.isFinite( Number( stats.blockCount ) )
				? Number( stats.blockCount )
				: 0,
			topLevelBlockCount: topLevelBlocks.length,
			visiblePatternCount: patterns.length,
			hasQuery: Boolean( stats.hasQuery ),
		},
	} );
}

function deriveTemplatePartRhythm( { stats = {}, topLevelBlocks = [] } = {} ) {
	if ( stats.hasSingleWrapperGroup ) {
		return 'constrained';
	}

	const text = topLevelBlocks.join( ' ' );

	if ( textIncludes( text, [ 'query', 'grid' ] ) ) {
		return 'grid';
	}
	if ( textIncludes( text, [ 'navigation', 'columns' ] ) ) {
		return 'stacked';
	}

	return topLevelBlocks.length <= 1 ? 'constrained' : 'stacked';
}

export function buildTemplatePartDesignSemantics( {
	templatePartRef,
	slug,
	area,
	editorStructure,
	visiblePatternNames,
} = {} ) {
	const structure = isPlainObject( editorStructure ) ? editorStructure : {};
	const stats = getStructureStats( structure );
	const topLevelBlocks = getTopLevelBlocks( structure );
	const patterns = uniqueSortedStrings( visiblePatternNames );
	const normalizedArea = cleanSlug( area );
	const normalizedSlug = cleanSlug( slug );
	const role = deriveSectionRole(
		normalizedArea,
		normalizedSlug,
		cleanString( templatePartRef )
	);
	const resolvedArea =
		normalizedArea ||
		( TEMPLATE_PART_AREA_ROLES.includes( role ) ? role : '' );

	return normalizeDesignSemantics( {
		surface: 'template-part',
		sectionRole: role,
		visualDensity: deriveVisualDensityFromCount( stats.blockCount ),
		contrastContext:
			role === 'footer' || role === 'sidebar' ? 'dark-parent' : 'unknown',
		layoutRhythm: deriveTemplatePartRhythm( {
			stats,
			topLevelBlocks,
		} ),
		typographyRole: stats.hasNavigation ? 'navigation' : 'unknown',
		tokenAffinity: {},
		existingDesignScore: 0,
		mainDesignIssue: 'unknown',
		negativeSignals: patterns.length === 0 ? [ 'no-visible-patterns' ] : [],
		templatePart: {
			ref: cleanString( templatePartRef ),
			slug: normalizedSlug,
			area: resolvedArea,
			blockCount: Number.isFinite( Number( stats.blockCount ) )
				? Number( stats.blockCount )
				: 0,
			topLevelBlockCount: topLevelBlocks.length,
			visiblePatternCount: patterns.length,
			hasNavigation: Boolean( stats.hasNavigation ),
			containsSocialLinks: Boolean( stats.containsSocialLinks ),
		},
	} );
}
