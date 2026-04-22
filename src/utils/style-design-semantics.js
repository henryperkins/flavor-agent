import { collectViewportVisibilitySummary } from './editor-context-metadata';
import { annotateStructuralIdentity } from './structural-identity';

const MAX_SUMMARY_VALUES = 6;
const MAX_STYLE_BOOK_OCCURRENCES = 6;
const MAX_NEARBY_BLOCKS = 2;

function getPathKey( path = [] ) {
	return Array.isArray( path ) ? path.join( '.' ) : '';
}

function getNodeIdentity( node = {} ) {
	return node?.structuralIdentity &&
		typeof node.structuralIdentity === 'object'
		? node.structuralIdentity
		: {};
}

function getNodeLabel( node = {} ) {
	return (
		getNodeIdentity( node )?.label || node?.title || node?.name || 'Block'
	);
}

function uniqueNonEmptyValues( values = [] ) {
	return Array.from(
		new Set(
			values.filter(
				( value ) => typeof value === 'string' && value.trim() !== ''
			)
		)
	).slice( 0, MAX_SUMMARY_VALUES );
}

function summarizeCounts( values = [] ) {
	const counts = new Map();

	for ( const value of values ) {
		if ( typeof value !== 'string' || value.trim() === '' ) {
			continue;
		}

		counts.set( value, ( counts.get( value ) || 0 ) + 1 );
	}

	return Array.from( counts.entries() )
		.sort(
			( [ leftValue, leftCount ], [ rightValue, rightCount ] ) =>
				rightCount - leftCount || leftValue.localeCompare( rightValue )
		)
		.slice( 0, MAX_SUMMARY_VALUES )
		.map( ( [ value, count ] ) => ( {
			value,
			count,
		} ) );
}

function getDominantValue( summary = [] ) {
	if ( ! Array.isArray( summary ) || summary.length === 0 ) {
		return '';
	}

	const [ first, second ] = summary;

	if ( ! first?.value ) {
		return '';
	}

	if ( ! second || first.count > second.count ) {
		return first.value;
	}

	return '';
}

function getOverallDensityHint( entries = [] ) {
	const compactCount = entries.filter(
		( entry ) => entry?.densityHint === 'compact'
	).length;
	const airyCount = entries.filter(
		( entry ) => entry?.densityHint === 'airy'
	).length;

	if ( compactCount > airyCount && compactCount > 0 ) {
		return 'compact';
	}

	if ( airyCount > compactCount && airyCount > 0 ) {
		return 'airy';
	}

	return 'balanced';
}

function getOccurrenceConfidence(
	occurrenceCount,
	roleSummary,
	locationSummary
) {
	if ( occurrenceCount <= 1 ) {
		return 'high';
	}

	const topRoleCount = roleSummary[ 0 ]?.count || 0;
	const topLocationCount = locationSummary[ 0 ]?.count || 0;
	const ratio = Math.max( topRoleCount, topLocationCount ) / occurrenceCount;

	if ( ratio >= 0.85 ) {
		return 'high';
	}

	if ( ratio >= 0.6 ) {
		return 'medium';
	}

	return 'low';
}

function resolveDensityHint( { siblingCount = 1, childCount = 0 } = {} ) {
	const densityScore = siblingCount + childCount;

	if ( densityScore >= 6 ) {
		return 'compact';
	}

	if ( densityScore <= 1 ) {
		return 'airy';
	}

	return 'balanced';
}

function resolveEmphasisHint( {
	identity = {},
	path = [],
	hiddenViewports = [],
	siblingCount = 1,
	childCount = 0,
} = {} ) {
	if ( hiddenViewports.length > 0 ) {
		return 'conditional';
	}

	const role = String( identity?.role || '' );

	if (
		[
			'main-title',
			'main-content',
			'main-query',
			'primary-navigation',
		].includes( role )
	) {
		return 'primary';
	}

	const location = String( identity?.location || '' );

	if (
		location === 'footer' ||
		location === 'sidebar' ||
		role.startsWith( 'footer-' ) ||
		role.startsWith( 'sidebar-' )
	) {
		return 'supporting';
	}

	if ( path.length === 1 && path[ 0 ] === 0 && location === 'content' ) {
		return 'primary';
	}

	if ( path.length === 1 && siblingCount <= 1 && childCount > 0 ) {
		return 'primary';
	}

	return 'supporting';
}

function buildVisibilityMap( blocks = [] ) {
	const visibilitySummary = collectViewportVisibilitySummary( blocks );
	const visibilityMap = new Map();

	for ( const block of visibilitySummary?.blocks || [] ) {
		visibilityMap.set( getPathKey( block?.path || [] ), {
			hiddenViewports: Array.isArray( block?.hiddenViewports )
				? block.hiddenViewports
				: [],
			visibleViewports: Array.isArray( block?.visibleViewports )
				? block.visibleViewports
				: [],
		} );
	}

	return visibilityMap;
}

function buildNearbyBlocks( siblings = [], index = 0 ) {
	const summarizeNode = ( node = {} ) => ( {
		block: node?.name || '',
		role: getNodeIdentity( node )?.role || '',
		label: getNodeLabel( node ),
	} );

	return {
		before: siblings
			.slice( Math.max( 0, index - MAX_NEARBY_BLOCKS ), index )
			.map( summarizeNode ),
		after: siblings
			.slice( index + 1, index + 1 + MAX_NEARBY_BLOCKS )
			.map( summarizeNode ),
	};
}

function buildSemanticEntry( node, path, visibilityMap, siblings, index ) {
	const identity = getNodeIdentity( node );
	const childNodes = Array.isArray( node?.innerBlocks )
		? node.innerBlocks
		: [];
	const childRoles = uniqueNonEmptyValues(
		childNodes.map( ( child ) => getNodeIdentity( child )?.role || '' )
	);
	const visibility = visibilityMap.get( getPathKey( path ) ) || {
		hiddenViewports: [],
		visibleViewports: [],
	};
	const siblingCount = Array.isArray( siblings ) ? siblings.length : 1;
	const childCount = childNodes.length;

	return {
		path,
		block: node?.name || '',
		label: getNodeLabel( node ),
		role: identity?.role || '',
		job: identity?.job || '',
		location: identity?.location || '',
		templateArea: identity?.templateArea || '',
		templatePartSlug: identity?.templatePartSlug || '',
		childCount,
		childRoles,
		nearbyBlocks: buildNearbyBlocks( siblings, index ),
		hiddenViewports: visibility.hiddenViewports,
		visibleViewports: visibility.visibleViewports,
		densityHint: resolveDensityHint( {
			siblingCount,
			childCount,
		} ),
		emphasisHint: resolveEmphasisHint( {
			identity,
			path,
			hiddenViewports: visibility.hiddenViewports,
			siblingCount,
			childCount,
		} ),
	};
}

function walkAnnotatedTree( nodes = [], visit, path = [] ) {
	if ( ! Array.isArray( nodes ) ) {
		return;
	}

	nodes.forEach( ( node, index ) => {
		const nextPath = [ ...path, index ];

		visit( node, nextPath, nodes, index );
		walkAnnotatedTree( node?.innerBlocks || [], visit, nextPath );
	} );
}

export function buildGlobalStyleDesignSemantics(
	blocks = [],
	{ templateType = '' } = {}
) {
	const annotatedTree = annotateStructuralIdentity( blocks );
	const visibilityMap = buildVisibilityMap( blocks );
	const sections = annotatedTree.map( ( node, index, siblings ) =>
		buildSemanticEntry( node, [ index ], visibilityMap, siblings, index )
	);

	return {
		surface: 'global-styles',
		templateType,
		sectionCount: sections.length,
		overallDensityHint: getOverallDensityHint( sections ),
		locationSummary: summarizeCounts(
			sections.map( ( section ) => section.location || 'content' )
		),
		roleSummary: summarizeCounts(
			sections.flatMap( ( section ) => [
				section.role,
				...section.childRoles,
			] )
		),
		sections,
	};
}

export function buildStyleBookDesignSemantics(
	blocks = [],
	{ blockName = '', blockTitle = '', templateType = '' } = {}
) {
	const annotatedTree = annotateStructuralIdentity( blocks );
	const visibilityMap = buildVisibilityMap( blocks );
	const occurrences = [];

	walkAnnotatedTree( annotatedTree, ( node, path, siblings, index ) => {
		if ( node?.name !== blockName ) {
			return;
		}

		occurrences.push(
			buildSemanticEntry( node, path, visibilityMap, siblings, index )
		);
	} );

	const roleSummary = summarizeCounts(
		occurrences.map( ( occurrence ) => occurrence.role )
	);
	const locationSummary = summarizeCounts(
		occurrences.map( ( occurrence ) => occurrence.location )
	);
	const templateAreaSummary = summarizeCounts(
		occurrences.map( ( occurrence ) => occurrence.templateArea )
	);
	const emphasisSummary = summarizeCounts(
		occurrences.map( ( occurrence ) => occurrence.emphasisHint )
	);
	const densitySummary = summarizeCounts(
		occurrences.map( ( occurrence ) => occurrence.densityHint )
	);

	return {
		surface: 'style-book',
		templateType,
		targetBlockName: blockName,
		targetBlockTitle: blockTitle,
		occurrenceCount: occurrences.length,
		sampledOccurrenceCount: Math.min(
			occurrences.length,
			MAX_STYLE_BOOK_OCCURRENCES
		),
		omittedOccurrenceCount: Math.max(
			0,
			occurrences.length - MAX_STYLE_BOOK_OCCURRENCES
		),
		confidence: getOccurrenceConfidence(
			occurrences.length,
			roleSummary,
			locationSummary
		),
		dominantRole: getDominantValue( roleSummary ),
		dominantLocation: getDominantValue( locationSummary ),
		dominantTemplateArea: getDominantValue( templateAreaSummary ),
		roleSummary,
		locationSummary,
		templateAreaSummary,
		emphasisSummary,
		densitySummary,
		occurrences: occurrences.slice( 0, MAX_STYLE_BOOK_OCCURRENCES ),
	};
}
