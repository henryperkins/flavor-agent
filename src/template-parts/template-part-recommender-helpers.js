import {
	collectPatternOverrideSummary,
	describeEditorBlockLabel,
} from '../utils/editor-context-metadata';

const TEMPLATE_PART_ATTRIBUTE_FIELDS = [
	'tagName',
	'align',
	'overlayMenu',
	'maxNestingLevel',
	'showSubmenuIcon',
	'placeholder',
	'slug',
	'area',
	'ref',
	'templateLock',
];

const TEMPLATE_PART_INSERTION_PLACEMENTS = [
	'before_block_path',
	'after_block_path',
];

const TEMPLATE_PART_BLOCK_TREE_MAX_DEPTH = 3;

function getBlockAttributes( block = {} ) {
	return block && typeof block.attributes === 'object'
		? block.attributes
		: {};
}

function getInnerBlocks( block = {} ) {
	return Array.isArray( block?.innerBlocks ) ? block.innerBlocks : [];
}

function hasContentOnlyTemplateLock( attributes = {} ) {
	return (
		typeof attributes?.templateLock === 'string' &&
		attributes.templateLock.trim().toLowerCase() === 'contentonly'
	);
}

function hasLockedTemplatePartBlock( attributes = {} ) {
	const lock =
		attributes?.lock &&
		typeof attributes.lock === 'object' &&
		! Array.isArray( attributes.lock )
			? attributes.lock
			: null;

	return (
		( typeof attributes?.templateLock === 'string' &&
			attributes.templateLock.trim() !== '' ) ||
		Boolean( lock && Object.keys( lock ).length > 0 )
	);
}

function summarizeTemplatePartBlockAttributes( attributes = {} ) {
	const summary = {};

	for ( const field of TEMPLATE_PART_ATTRIBUTE_FIELDS ) {
		const value = attributes?.[ field ];

		if (
			typeof value === 'string' ||
			typeof value === 'number' ||
			typeof value === 'boolean'
		) {
			summary[ field ] = value;
		}
	}

	const layout =
		attributes?.layout &&
		typeof attributes.layout === 'object' &&
		! Array.isArray( attributes.layout )
			? attributes.layout
			: null;

	if ( typeof layout?.type === 'string' && layout.type ) {
		summary.layoutType = layout.type;
	}

	if (
		typeof layout?.justifyContent === 'string' &&
		layout.justifyContent
	) {
		summary.layoutJustifyContent = layout.justifyContent;
	}

	if ( typeof layout?.orientation === 'string' && layout.orientation ) {
		summary.layoutOrientation = layout.orientation;
	}

	return summary;
}

function buildTemplatePartBlockNode( block, path, areaLookup ) {
	if ( ! block?.name ) {
		return null;
	}

	const attributes = getBlockAttributes( block );

	return {
		path,
		name: block.name,
		label: describeEditorBlockLabel( block.name, attributes, areaLookup ),
		attributes: summarizeTemplatePartBlockAttributes( attributes ),
		childCount: getInnerBlocks( block ).length,
	};
}

function buildTemplatePartBlockTree(
	blocks = [],
	areaLookup,
	path = [],
	depth = 0
) {
	if ( ! Array.isArray( blocks ) ) {
		return [];
	}

	return blocks
		.map( ( block, index ) => {
			const nextPath = [ ...path, index ];
			const node = buildTemplatePartBlockNode(
				block,
				nextPath,
				areaLookup
			);

			if ( ! node ) {
				return null;
			}

			const innerBlocks = getInnerBlocks( block );
			if (
				depth + 1 < TEMPLATE_PART_BLOCK_TREE_MAX_DEPTH &&
				innerBlocks.length > 0
			) {
				node.children = buildTemplatePartBlockTree(
					innerBlocks,
					areaLookup,
					nextPath,
					depth + 1
				);
			} else {
				node.children = [];
			}

			return node;
		} )
		.filter( Boolean );
}

function collectAllTemplatePartPaths(
	blocks = [],
	areaLookup,
	path = [],
	paths = []
) {
	if ( ! Array.isArray( blocks ) ) {
		return paths;
	}

	blocks.forEach( ( block, index ) => {
		const nextPath = [ ...path, index ];
		const node = buildTemplatePartBlockNode( block, nextPath, areaLookup );

		if ( ! node ) {
			return;
		}

		paths.push( node );
		collectAllTemplatePartPaths(
			getInnerBlocks( block ),
			areaLookup,
			nextPath,
			paths
		);
	} );

	return paths;
}

function collectTemplatePartTopLevelBlocks( blocks = [] ) {
	if ( ! Array.isArray( blocks ) ) {
		return [];
	}

	return blocks
		.map( ( block ) =>
			typeof block?.name === 'string' ? block.name.trim() : ''
		)
		.filter( Boolean );
}

function collectTemplatePartBlockStats( blocks = [] ) {
	const stats = {
		blockCount: 0,
		maxDepth: 0,
		blockCounts: {},
	};

	const visit = ( branch = [], depth = 1 ) => {
		if ( ! Array.isArray( branch ) ) {
			return;
		}

		branch.forEach( ( block ) => {
			if ( ! block || typeof block !== 'object' || ! block.name ) {
				return;
			}

			stats.blockCount += 1;
			stats.maxDepth = Math.max( stats.maxDepth, depth );
			stats.blockCounts[ block.name ] =
				( stats.blockCounts[ block.name ] || 0 ) + 1;

			visit( getInnerBlocks( block ), depth + 1 );
		} );
	};

	visit( blocks );

	return stats;
}

function buildTemplatePartStructureStats( blocks = [], topLevelBlocks = [] ) {
	const { blockCount, maxDepth, blockCounts } =
		collectTemplatePartBlockStats( blocks );

	return {
		blockCount,
		maxDepth,
		hasNavigation: Boolean( blockCounts[ 'core/navigation' ] ),
		containsLogo: Boolean( blockCounts[ 'core/site-logo' ] ),
		containsSiteTitle: Boolean( blockCounts[ 'core/site-title' ] ),
		containsSearch: Boolean( blockCounts[ 'core/search' ] ),
		containsSocialLinks: Boolean( blockCounts[ 'core/social-links' ] ),
		containsQuery: Boolean( blockCounts[ 'core/query' ] ),
		containsColumns: Boolean( blockCounts[ 'core/columns' ] ),
		containsButtons: Boolean( blockCounts[ 'core/buttons' ] ),
		containsSpacer: Boolean( blockCounts[ 'core/spacer' ] ),
		containsSeparator: Boolean( blockCounts[ 'core/separator' ] ),
		firstTopLevelBlock: topLevelBlocks[ 0 ] || '',
		lastTopLevelBlock:
			topLevelBlocks.length > 0
				? topLevelBlocks[ topLevelBlocks.length - 1 ]
				: '',
		hasSingleWrapperGroup:
			topLevelBlocks.length === 1 && topLevelBlocks[ 0 ] === 'core/group',
		isNearlyEmpty: blockCount <= 1,
		blockCounts,
	};
}

function collectTemplatePartOperationTargets(
	blocks = [],
	areaLookup,
	path = [],
	insideContentOnly = false,
	targets = []
) {
	if ( ! Array.isArray( blocks ) ) {
		return targets;
	}

	blocks.forEach( ( block, index ) => {
		if ( ! block?.name ) {
			return;
		}

		const attributes = getBlockAttributes( block );
		const nextPath = [ ...path, index ];
		const isContentOnlyContainer = hasContentOnlyTemplateLock( attributes );
		const canTarget = ! insideContentOnly && ! isContentOnlyContainer;
		const allowedOperations =
			canTarget && ! hasLockedTemplatePartBlock( attributes )
				? [ 'replace_block_with_pattern', 'remove_block' ]
				: [];

		if ( canTarget ) {
			targets.push( {
				path: nextPath,
				name: block.name,
				label: describeEditorBlockLabel(
					block.name,
					attributes,
					areaLookup
				),
				allowedOperations,
				allowedInsertions: [ ...TEMPLATE_PART_INSERTION_PLACEMENTS ],
			} );
		}

		collectTemplatePartOperationTargets(
			getInnerBlocks( block ),
			areaLookup,
			nextPath,
			insideContentOnly || isContentOnlyContainer,
			targets
		);
	} );

	return targets;
}

function buildTemplatePartInsertionAnchors( operationTargets = [] ) {
	const anchors = [
		{
			placement: 'start',
			label: 'Start of template part',
		},
		{
			placement: 'end',
			label: 'End of template part',
		},
	];

	operationTargets.forEach( ( target ) => {
		if (
			! Array.isArray( target?.path ) ||
			! target.path.length ||
			! target?.name
		) {
			return;
		}

		const label = target.label || target.name;

		anchors.push( {
			placement: 'before_block_path',
			targetPath: target.path,
			blockName: target.name,
			label: `Before ${ label }`,
		} );
		anchors.push( {
			placement: 'after_block_path',
			targetPath: target.path,
			blockName: target.name,
			label: `After ${ label }`,
		} );
	} );

	return anchors;
}

function collectTemplatePartStructuralConstraints(
	blocks = [],
	path = [],
	constraints = {
		contentOnlyPaths: [],
		lockedPaths: [],
	}
) {
	if ( ! Array.isArray( blocks ) ) {
		return constraints;
	}

	blocks.forEach( ( block, index ) => {
		if ( ! block?.name ) {
			return;
		}

		const attributes = getBlockAttributes( block );
		const nextPath = [ ...path, index ];

		if ( hasContentOnlyTemplateLock( attributes ) ) {
			constraints.contentOnlyPaths.push( nextPath );
		}

		if ( hasLockedTemplatePartBlock( attributes ) ) {
			constraints.lockedPaths.push( nextPath );
		}

		collectTemplatePartStructuralConstraints(
			getInnerBlocks( block ),
			nextPath,
			constraints
		);
	} );

	return constraints;
}

export function normalizeVisiblePatternNames( visiblePatternNames ) {
	if ( ! Array.isArray( visiblePatternNames ) ) {
		return null;
	}

	return Array.from( new Set( visiblePatternNames.filter( Boolean ) ) );
}

export function buildEditorTemplatePartStructureSnapshot(
	blocks = [],
	areaLookup
) {
	const normalizedBlocks = Array.isArray( blocks ) ? blocks : [];
	const topLevelBlocks = collectTemplatePartTopLevelBlocks( normalizedBlocks );
	const structureStats = buildTemplatePartStructureStats(
		normalizedBlocks,
		topLevelBlocks
	);
	const operationTargets = collectTemplatePartOperationTargets(
		normalizedBlocks,
		areaLookup
	);
	const structuralConstraints = collectTemplatePartStructuralConstraints(
		normalizedBlocks
	);

	return {
		blockTree: buildTemplatePartBlockTree(
			normalizedBlocks,
			areaLookup
		),
		allBlockPaths: collectAllTemplatePartPaths(
			normalizedBlocks,
			areaLookup
		),
		topLevelBlocks,
		blockCounts: structureStats.blockCounts,
		structureStats: {
			blockCount: structureStats.blockCount,
			maxDepth: structureStats.maxDepth,
			hasNavigation: structureStats.hasNavigation,
			containsLogo: structureStats.containsLogo,
			containsSiteTitle: structureStats.containsSiteTitle,
			containsSearch: structureStats.containsSearch,
			containsSocialLinks: structureStats.containsSocialLinks,
			containsQuery: structureStats.containsQuery,
			containsColumns: structureStats.containsColumns,
			containsButtons: structureStats.containsButtons,
			containsSpacer: structureStats.containsSpacer,
			containsSeparator: structureStats.containsSeparator,
			firstTopLevelBlock: structureStats.firstTopLevelBlock,
			lastTopLevelBlock: structureStats.lastTopLevelBlock,
			hasSingleWrapperGroup: structureStats.hasSingleWrapperGroup,
			isNearlyEmpty: structureStats.isNearlyEmpty,
		},
		currentPatternOverrides: collectPatternOverrideSummary(
			normalizedBlocks,
			areaLookup
		),
		operationTargets,
		insertionAnchors: buildTemplatePartInsertionAnchors(
			operationTargets
		),
		structuralConstraints: {
			contentOnlyPaths: structuralConstraints.contentOnlyPaths,
			lockedPaths: structuralConstraints.lockedPaths,
			hasContentOnly: structuralConstraints.contentOnlyPaths.length > 0,
			hasLockedBlocks: structuralConstraints.lockedPaths.length > 0,
		},
	};
}

export function buildTemplatePartRecommendationContextSignature( {
	visiblePatternNames,
	editorStructure,
} = {} ) {
	const normalizedVisiblePatternNames =
		normalizeVisiblePatternNames( visiblePatternNames );

	return JSON.stringify( {
		blockTree: Array.isArray( editorStructure?.blockTree )
			? editorStructure.blockTree
			: null,
		allBlockPaths: Array.isArray( editorStructure?.allBlockPaths )
			? editorStructure.allBlockPaths
			: null,
		topLevelBlocks: Array.isArray( editorStructure?.topLevelBlocks )
			? editorStructure.topLevelBlocks
			: null,
		blockCounts: editorStructure?.blockCounts || null,
		structureStats: editorStructure?.structureStats || null,
		operationTargets: Array.isArray( editorStructure?.operationTargets )
			? editorStructure.operationTargets
			: null,
		insertionAnchors: Array.isArray( editorStructure?.insertionAnchors )
			? editorStructure.insertionAnchors
			: null,
		structuralConstraints:
			editorStructure?.structuralConstraints || null,
		currentPatternOverrides:
			editorStructure?.currentPatternOverrides || null,
		visiblePatternNames: Array.isArray( normalizedVisiblePatternNames )
			? [ ...normalizedVisiblePatternNames ].sort()
			: null,
	} );
}

export function buildTemplatePartFetchInput( {
	templatePartRef,
	prompt,
	visiblePatternNames,
	editorStructure,
	contextSignature = '',
} ) {
	const input = { templatePartRef };
	const trimmedPrompt = prompt.trim();
	const normalizedVisiblePatternNames =
		normalizeVisiblePatternNames( visiblePatternNames );

	if ( trimmedPrompt ) {
		input.prompt = trimmedPrompt;
	}

	if ( Array.isArray( normalizedVisiblePatternNames ) ) {
		input.visiblePatternNames = normalizedVisiblePatternNames;
	}

	if ( editorStructure ) {
		input.editorStructure = editorStructure;
	}

	if ( contextSignature ) {
		input.contextSignature = contextSignature;
	}

	return input;
}
