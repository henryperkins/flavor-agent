import {
	getTemplatePartAreaLookup,
	resolveTemplatePartAreaEvidence,
} from './template-part-areas';

const BLOCK_LABELS = {
	'core/comments': 'Comments',
	'core/navigation': 'Navigation',
	'core/post-content': 'Post content',
	'core/post-title': 'Post title',
	'core/query': 'Query loop',
	'core/search': 'Search',
	'core/site-logo': 'Site logo',
	'core/site-tagline': 'Site tagline',
	'core/site-title': 'Site title',
	'core/template-part': 'Template part',
};

function getNodeAttributes( node ) {
	if (
		node?.currentAttributes &&
		typeof node.currentAttributes === 'object'
	) {
		return node.currentAttributes;
	}

	if ( node?.attributes && typeof node.attributes === 'object' ) {
		return node.attributes;
	}

	return {};
}

export function getStructuralIdentityFingerprintAttributes( node ) {
	const attributes = getNodeAttributes( node );
	const fingerprint = {};

	if ( node?.name === 'core/template-part' ) {
		if ( typeof attributes.area === 'string' && attributes.area !== '' ) {
			fingerprint.area = attributes.area;
		}

		if ( typeof attributes.slug === 'string' && attributes.slug !== '' ) {
			fingerprint.slug = attributes.slug;
		}

		if (
			typeof attributes.tagName === 'string' &&
			attributes.tagName !== ''
		) {
			fingerprint.tagName = attributes.tagName;
		}
	}

	if (
		node?.name === 'core/query' &&
		attributes?.query &&
		typeof attributes.query === 'object' &&
		'inherit' in attributes.query
	) {
		fingerprint.query = {
			inherit: attributes.query.inherit,
		};
	}

	return fingerprint;
}

function getBlockKey( blockName ) {
	if ( typeof blockName !== 'string' || blockName === '' ) {
		return 'block';
	}

	const key = blockName.includes( '/' )
		? blockName.split( '/' )[ 1 ]
		: blockName;

	return key || 'block';
}

function toTitleCase( value ) {
	if ( typeof value !== 'string' || value === '' ) {
		return '';
	}

	return value
		.split( '-' )
		.map( ( part ) => part.charAt( 0 ).toUpperCase() + part.slice( 1 ) )
		.join( ' ' );
}

function getBlockLabel( node ) {
	if ( typeof node?.title === 'string' && node.title !== '' ) {
		return node.title;
	}

	if ( BLOCK_LABELS[ node?.name ] ) {
		return BLOCK_LABELS[ node.name ];
	}

	return toTitleCase( getBlockKey( node?.name ) );
}

function getLocationPhrase( context ) {
	if ( context.templatePartSlug ) {
		return `the "${ context.templatePartSlug }" template part`;
	}

	if ( context.location === 'content' ) {
		return 'the content area';
	}

	if ( context.location ) {
		return `the ${ context.location } area`;
	}

	return 'this part of the page';
}

function findNearestTemplatePart( ancestors ) {
	for ( let index = ancestors.length - 1; index >= 0; index-- ) {
		const ancestor = ancestors[ index ];
		const identity = ancestor?.structuralIdentity || {};

		if (
			ancestor?.name === 'core/template-part' ||
			identity.templateArea
		) {
			return {
				area: identity.templateArea || '',
				slug:
					identity.templatePartSlug ||
					getNodeAttributes( ancestor ).slug ||
					'',
			};
		}
	}

	return null;
}

function resolveLocationContext( node, context ) {
	const attrs = getNodeAttributes( node );
	const templatePartEvidence =
		node?.name === 'core/template-part'
			? resolveTemplatePartAreaEvidence(
					attrs,
					context.templatePartAreas
			  )
			: { area: '', source: '' };
	const nearestTemplatePart = findNearestTemplatePart( context.ancestors );
	const templateArea =
		templatePartEvidence.area || nearestTemplatePart?.area || '';
	let templatePartSlug = nearestTemplatePart?.slug || '';

	if ( node?.name === 'core/template-part' ) {
		templatePartSlug = typeof attrs.slug === 'string' ? attrs.slug : '';
	}
	const location = templateArea || 'content';
	const evidence = [];

	if ( node?.name === 'core/template-part' ) {
		if ( templatePartEvidence.source === 'explicit' ) {
			evidence.push( 'explicit-template-area' );
		} else if ( templatePartEvidence.source === 'registry' ) {
			evidence.push( 'template-part-registry' );
		} else if ( templatePartEvidence.source === 'slug' ) {
			evidence.push( 'template-part-slug' );
		} else if ( templatePartEvidence.source === 'tag' ) {
			evidence.push( 'template-part-tag' );
		}
	} else if ( nearestTemplatePart?.area ) {
		evidence.push( 'ancestor-template-part' );
	} else {
		evidence.push( 'default-content-surface' );
	}

	return {
		location,
		templateArea: templateArea || null,
		templatePartSlug: templatePartSlug || null,
		evidence,
	};
}

function buildGenericIdentity( node, context ) {
	const blockLabel = getBlockLabel( node );
	const roleBase = getBlockKey( node?.name );
	const role =
		context.location === 'content'
			? roleBase
			: `${ context.location }-${ roleBase }`;
	const label =
		context.location === 'content'
			? blockLabel
			: `${ toTitleCase( context.location ) } ${ blockLabel }`;

	return {
		role,
		label,
		job: `${ blockLabel } block in ${ getLocationPhrase( context ) }.`,
		evidence: [],
	};
}

function buildTemplatePartIdentity( context ) {
	if ( context.templateArea ) {
		const areaLabel = toTitleCase( context.templateArea );
		const slugSuffix = context.templatePartSlug
			? ` using "${ context.templatePartSlug }"`
			: '';

		return {
			role: `${ context.templateArea }-slot`,
			label: `${ areaLabel } slot`,
			job: `${ areaLabel } template-part slot${ slugSuffix }.`,
			evidence: [],
		};
	}

	return {
		role: 'template-part',
		label: 'Template part',
		job: 'Template-part block.',
		evidence: [],
	};
}

function buildNavigationIdentity( context ) {
	if ( context.location === 'header' && context.locationTypeIndex === 1 ) {
		return {
			role: 'primary-navigation',
			label: 'Primary navigation',
			job: 'Primary navigation in the header.',
			evidence: [ 'first-navigation-in-header' ],
		};
	}

	return {
		role: `${ context.location }-navigation`,
		label: `${ toTitleCase( context.location ) } navigation`,
		job: `Navigation block in ${ getLocationPhrase( context ) }.`,
		evidence: [],
	};
}

function buildQueryIdentity( node, context ) {
	const attrs = getNodeAttributes( node );
	const query = attrs?.query;
	const inheritsMainQuery =
		!! query &&
		typeof query === 'object' &&
		query.inherit === true &&
		context.location === 'content';

	if ( inheritsMainQuery ) {
		return {
			role: 'main-query',
			label: 'Main query loop',
			job: 'Inherited main query loop in the content area.',
			evidence: [ 'inherited-query' ],
		};
	}

	if ( context.location === 'content' ) {
		return {
			role: 'supplemental-query',
			label: 'Supplemental query loop',
			job: 'Supplemental query loop in the content area.',
			evidence: [],
		};
	}

	return {
		role: `${ context.location }-query`,
		label: `${ toTitleCase( context.location ) } query loop`,
		job: `Query loop in ${ getLocationPhrase( context ) }.`,
		evidence: [],
	};
}

function buildContextualIdentity( node, context, roleBase, labelBase ) {
	if ( context.location === 'content' ) {
		return {
			role: roleBase,
			label: labelBase,
			job: `${ labelBase } block in the content area.`,
			evidence: [],
		};
	}

	return {
		role: `${ context.location }-${ roleBase }`,
		label: `${ toTitleCase(
			context.location
		) } ${ labelBase.toLowerCase() }`,
		job: `${ labelBase } block in ${ getLocationPhrase( context ) }.`,
		evidence: [],
	};
}

function resolveIdentityShape( node, context ) {
	switch ( node?.name ) {
		case 'core/template-part':
			return buildTemplatePartIdentity( context );
		case 'core/navigation':
			return buildNavigationIdentity( context );
		case 'core/query':
			return buildQueryIdentity( node, context );
		case 'core/site-title':
			return buildContextualIdentity(
				node,
				context,
				'site-title',
				'Site title'
			);
		case 'core/site-logo':
			return buildContextualIdentity(
				node,
				context,
				'site-logo',
				'Site logo'
			);
		case 'core/site-tagline':
			return buildContextualIdentity(
				node,
				context,
				'site-tagline',
				'Site tagline'
			);
		case 'core/search':
			return buildContextualIdentity( node, context, 'search', 'Search' );
		case 'core/post-content':
			return {
				role: 'main-content',
				label: 'Main content',
				job: 'Primary post-content block in the content area.',
				evidence: [],
			};
		case 'core/post-title':
			return {
				role: 'main-title',
				label: 'Main title',
				job: 'Primary post-title block in the content area.',
				evidence: [],
			};
		case 'core/comments':
			return {
				role: 'comments-section',
				label: 'Comments section',
				job: `Comments block in ${ getLocationPhrase( context ) }.`,
				evidence: [],
			};
		default:
			return buildGenericIdentity( node, context );
	}
}

function countSiblingTypes( nodes ) {
	return nodes.reduce( ( counts, node ) => {
		const blockName = node?.name || '';

		if ( blockName === '' ) {
			return counts;
		}

		return {
			...counts,
			[ blockName ]: ( counts[ blockName ] || 0 ) + 1,
		};
	}, {} );
}

function getSiblingTypeIndex( nodes, index ) {
	const blockName = nodes[ index ]?.name || '';

	if ( blockName === '' ) {
		return 1;
	}

	let count = 0;

	for ( let pointer = 0; pointer <= index; pointer++ ) {
		if ( nodes[ pointer ]?.name === blockName ) {
			count++;
		}
	}

	return count;
}

function dedupeEvidence( evidence ) {
	return Array.from(
		new Set(
			evidence.filter(
				( item ) => typeof item === 'string' && item.trim() !== ''
			)
		)
	);
}

function annotateNodes( nodes, context ) {
	const siblingCounts = countSiblingTypes( nodes );

	return nodes.map( ( node, index ) => {
		const locationContext = resolveLocationContext( node, context );
		const locationKey = `${ locationContext.location }|${
			node?.name || ''
		}`;
		const locationTypeIndex =
			( context.locationTypeCounts.get( locationKey ) || 0 ) + 1;

		context.locationTypeCounts.set( locationKey, locationTypeIndex );

		const identityContext = {
			...locationContext,
			locationTypeIndex,
		};
		const resolvedIdentity = resolveIdentityShape( node, identityContext );
		const structuralIdentity = {
			role: resolvedIdentity.role || null,
			label: resolvedIdentity.label || null,
			job: resolvedIdentity.job || null,
			location: locationContext.location || null,
			templateArea: locationContext.templateArea,
			templatePartSlug: locationContext.templatePartSlug,
			position: {
				depth: context.ancestors.length + 1,
				siblingIndex: index + 1,
				siblingCount: nodes.length,
				sameTypeIndex: getSiblingTypeIndex( nodes, index ),
				sameTypeCount: siblingCounts[ node?.name ] || 1,
				typeOrderInLocation: locationTypeIndex,
			},
			evidence: dedupeEvidence( [
				...locationContext.evidence,
				...( resolvedIdentity.evidence || [] ),
			] ),
		};
		const annotatedNode = {
			...node,
			structuralIdentity,
		};

		annotatedNode.innerBlocks = annotateNodes(
			Array.isArray( node?.innerBlocks ) ? node.innerBlocks : [],
			{
				...context,
				ancestors: [ ...context.ancestors, annotatedNode ],
			}
		);

		return annotatedNode;
	} );
}

export function annotateStructuralIdentity( tree, options = {} ) {
	return annotateNodes( Array.isArray( tree ) ? tree : [], {
		ancestors: [],
		locationTypeCounts: new Map(),
		templatePartAreas:
			options.templatePartAreas || getTemplatePartAreaLookup(),
	} );
}

export function findNodePath( tree, predicate ) {
	for ( const node of Array.isArray( tree ) ? tree : [] ) {
		if ( predicate( node ) ) {
			return [ node ];
		}

		const childPath = findNodePath( node?.innerBlocks || [], predicate );

		if ( childPath ) {
			return [ node, ...childPath ];
		}
	}

	return null;
}

export function toStructuralSummary( node ) {
	const identity = node?.structuralIdentity || {};
	const summary = {
		block: node?.name || '',
		title: node?.title || '',
	};

	if ( identity.role ) {
		summary.role = identity.role;
	}

	if ( identity.job ) {
		summary.job = identity.job;
	}

	if ( identity.location ) {
		summary.location = identity.location;
	}

	if ( identity.templateArea ) {
		summary.templateArea = identity.templateArea;
	}

	if ( identity.templatePartSlug ) {
		summary.templatePartSlug = identity.templatePartSlug;
	}

	return summary;
}

export function findBranchRoot( path ) {
	for ( let index = path.length - 1; index >= 0; index-- ) {
		if ( path[ index ]?.name === 'core/template-part' ) {
			return path[ index ];
		}
	}

	return path[ 0 ] || null;
}

export function buildStructuralContext( tree, selectedClientId, options = {} ) {
	const annotatedTree = annotateStructuralIdentity( tree, options );
	const path = findNodePath(
		annotatedTree,
		( node ) => node?.clientId === selectedClientId
	);

	if ( ! path ) {
		return {
			annotatedTree,
			blockIdentity: {},
			structuralAncestors: [],
			branchRoot: null,
		};
	}

	const selectedNode = path[ path.length - 1 ];

	return {
		annotatedTree,
		blockIdentity: selectedNode?.structuralIdentity || {},
		structuralAncestors: path
			.slice( 0, -1 )
			.map( ( node ) => toStructuralSummary( node ) ),
		branchRoot: findBranchRoot( path ),
	};
}
