/**
 * Context Collector (Phase 1 — block-scoped only)
 *
 * Assembles a context snapshot for a single block's Inspector
 * recommendations. Combines block introspection with theme tokens.
 */
import { store as blocksStore } from '@wordpress/blocks';
import { select } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';

import {
	introspectBlockInstance,
	introspectBlockTree,
	summarizeTree,
} from './block-inspector';
import {
	annotateStructuralIdentity,
	findBranchRoot,
	findNodePath,
	getStructuralIdentityFingerprintAttributes,
	toStructuralSummary,
} from '../utils/structural-identity';
import { collectThemeTokens, summarizeTokens } from './theme-tokens';
import {
	BLOCK_SIBLING_SUMMARY_MAX_ITEMS,
	BLOCK_STRUCTURAL_BRANCH_MAX_CHILDREN,
	BLOCK_STRUCTURAL_BRANCH_MAX_DEPTH,
	capBlockStructuralAncestorItems,
	capBlockStructuralBranchItems,
	buildBlockRecommendationContextSignature,
} from '../utils/block-recommendation-context';
import { buildContextSignature } from '../utils/context-signature';
export { buildBlockRecommendationContextSignature };

const BASE_VISUAL_HINT_PATHS = [
	'backgroundColor',
	'textColor',
	'gradient',
	'align',
	'textAlign',
	'style.color.background',
	'style.color.text',
	'layout.type',
	'layout.justifyContent',
];

const PARENT_VISUAL_HINT_PATHS = [
	...BASE_VISUAL_HINT_PATHS,
	'dimRatio',
	'minHeight',
	'minHeightUnit',
	'tagName',
];

function getValueAtPath( source, pathSegments ) {
	if ( ! source || 'object' !== typeof source ) {
		return undefined;
	}

	return pathSegments.reduce(
		( value, segment ) =>
			value && 'object' === typeof value && segment in value
				? value[ segment ]
				: undefined,
		source
	);
}

function setNestedHint( target, pathSegments, value ) {
	let cursor = target;

	pathSegments.forEach( ( segment, index ) => {
		if ( index === pathSegments.length - 1 ) {
			cursor[ segment ] = value;
			return;
		}

		if ( ! cursor[ segment ] || 'object' !== typeof cursor[ segment ] ) {
			cursor[ segment ] = {};
		}
		cursor = cursor[ segment ];
	} );
}

function extractVisualHints( attributes, allowlist ) {
	const hints = {};

	if ( ! attributes || 'object' !== typeof attributes ) {
		return hints;
	}

	allowlist.forEach( ( path ) => {
		const segments = path.split( '.' );
		const value = getValueAtPath( attributes, segments );

		if (
			value === undefined ||
			value === null ||
			value === '' ||
			( typeof value === 'object' &&
				! Array.isArray( value ) &&
				Object.keys( value ).length === 0 )
		) {
			return;
		}

		setNestedHint( hints, segments, value );
	} );

	return hints;
}

/**
 * Build a clientId → structuralIdentity lookup index from an annotated tree.
 * Single O(n) traversal; pass the result to helpers that would otherwise each
 * call findNodePath() (an O(n) tree walk) for every sibling and parent.
 *
 * @param {object[]} annotatedTree Annotated block tree.
 * @return {Object} Plain object keyed by clientId.
 */
function buildIdentityIndex( annotatedTree ) {
	const index = {};
	function visit( nodes ) {
		if ( ! Array.isArray( nodes ) ) {
			return;
		}
		for ( const node of nodes ) {
			if ( node?.clientId ) {
				index[ node.clientId ] = node.structuralIdentity || {};
			}
			visit( node?.innerBlocks );
		}
	}
	visit( annotatedTree );
	return index;
}

function findStructuralIdentity( annotatedTree, clientId, identityIndex ) {
	if ( ! clientId ) {
		return {};
	}

	if ( identityIndex ) {
		return identityIndex[ clientId ] || {};
	}

	if ( ! Array.isArray( annotatedTree ) ) {
		return {};
	}

	const path = findNodePath(
		annotatedTree,
		( node ) => node?.clientId === clientId
	);

	return path?.[ path.length - 1 ]?.structuralIdentity || {};
}

function getSiblingSummaries( clientId, direction, count, annotatedTree, identityIndex ) {
	const editor = select( blockEditorStore );
	const rootId = editor.getBlockRootClientId( clientId );
	const order = editor.getBlockOrder( rootId || '' );
	const index = order.indexOf( clientId );
	if ( index === -1 ) {
		return [];
	}

	const slice =
		direction === 'before'
			? order.slice( Math.max( 0, index - count ), index )
			: order.slice( index + 1, index + 1 + count );

	return slice
		.map( ( id ) => {
			const blockName = editor.getBlockName( id );

			if ( ! blockName ) {
				return null;
			}

			const attributes = editor.getBlockAttributes?.( id ) || {};
			const visualHints = extractVisualHints(
				attributes,
				BASE_VISUAL_HINT_PATHS
			);
			const identity = findStructuralIdentity( annotatedTree, id, identityIndex );
			const summary = {
				block: blockName,
			};

			if ( identity.role ) {
				summary.role = identity.role;
			}

			if ( Object.keys( visualHints ).length ) {
				summary.visualHints = visualHints;
			}

			return summary;
		} )
		.filter( Boolean );
}

function getParentContext( clientId, annotatedTree, identityIndex ) {
	const editor = select( blockEditorStore );
	const parentId = editor.getBlockRootClientId( clientId );

	if ( ! parentId ) {
		return null;
	}

	const parentBlockName = editor.getBlockName( parentId );
	if ( ! parentBlockName ) {
		return null;
	}

	const blocks = select( blocksStore );
	const attributes = editor.getBlockAttributes?.( parentId ) || {};
	const visualHints = extractVisualHints(
		attributes,
		PARENT_VISUAL_HINT_PATHS
	);
	const parentType = blocks.getBlockType?.( parentBlockName );
	const identity = findStructuralIdentity( annotatedTree, parentId, identityIndex );
	const parentContext = {
		block: parentBlockName,
		title: parentType?.title || '',
		childCount: editor.getBlockCount?.( parentId ) || 0,
	};

	if ( identity.role ) {
		parentContext.role = identity.role;
	}

	if ( identity.job ) {
		parentContext.job = identity.job;
	}

	if ( Object.keys( visualHints ).length ) {
		parentContext.visualHints = visualHints;
	}

	if ( ! parentContext.title ) {
		delete parentContext.title;
	}

	if ( ! parentContext.childCount ) {
		delete parentContext.childCount;
	}

	return Object.keys( parentContext ).length ? parentContext : null;
}

// ── Annotated tree cache ─────────────────────────────────────────────────────
//
// introspectBlockTree() + annotateStructuralIdentity() together are O(n) in the
// block tree.  collectBlockContext is called on every block selection, so a
// single shared annotated tree is built once per selection change and reused
// for both the structural context pass and the structuralBranch summarizeTree
// pass.

/**
 * Build a stable fingerprint for the subset of tree data that affects
 * structural identity. This keeps the cache correct when users edit
 * identity-driving attributes without changing the tree shape.
 *
 * @param {object[]} tree Raw introspected tree.
 * @return {string} Fingerprint.
 */
function fingerprintTree( tree ) {
	function toIdentityInputs( nodes ) {
		return ( Array.isArray( nodes ) ? nodes : [] ).map( ( node ) => ( {
			clientId: node?.clientId || '',
			name: node?.name || '',
			identityAttributes: getStructuralIdentityFingerprintAttributes(
				node
			),
			innerBlocks: toIdentityInputs( node?.innerBlocks || [] ),
		} ) );
	}

	return buildContextSignature( toIdentityInputs( tree ) );
}

/**
 * @typedef {Object} AnnotatedTreeCache
 * @property {object[]} annotatedTree Cached annotated block tree.
 * @property {string}   fingerprint   Tree structure fingerprint for staleness checks.
 */

/** @type {AnnotatedTreeCache|null} */
let cachedAnnotatedTree = null;

/**
 * Invalidate the annotated-tree cache.  Call this whenever the block tree
 * is known to have changed (e.g. after a block insert, delete, or move).
 * The next call to getAnnotatedBlockTree() will rebuild from scratch.
 */
export function invalidateAnnotatedTreeCache() {
	cachedAnnotatedTree = null;
}

/**
 * Return the cached annotated block tree, rebuilding it if the tree structure
 * has changed since the last call.
 *
 * @param {number} maxDepth Maximum introspection depth (passed to introspectBlockTree).
 * @return {object[]} Annotated tree with structuralIdentity attached to every node.
 */
export function getAnnotatedBlockTree( maxDepth = 10 ) {
	const rawTree = introspectBlockTree( null, maxDepth );
	const fp = fingerprintTree( rawTree );

	if ( cachedAnnotatedTree?.fingerprint === fp ) {
		return cachedAnnotatedTree.annotatedTree;
	}

	const annotatedTree = annotateStructuralIdentity( rawTree );
	cachedAnnotatedTree = { annotatedTree, fingerprint: fp };
	return annotatedTree;
}

/**
 * Build a focused context for a single block's Inspector recommendations.
 *
 * @param {string} clientId
 * @return {object|null} Block recommendation context or null when unavailable.
 */
export function collectBlockContext( clientId ) {
	if ( ! clientId ) {
		return null;
	}

	const instance = introspectBlockInstance( clientId );
	if ( ! instance ) {
		return null;
	}

	// Single annotated tree shared by both the structural context pass and
	// the structuralBranch summarizeTree pass — no double annotation.
	const annotatedTree = getAnnotatedBlockTree();

	// Build a clientId → structuralIdentity index once so that getParentContext()
	// and getSiblingSummaries() can do O(1) lookups instead of an O(n) tree walk
	// per call.
	const identityIndex = buildIdentityIndex( annotatedTree );

	const path = findNodePath(
		annotatedTree,
		( n ) => n?.clientId === clientId
	);

	let blockIdentity = {};
	let structuralAncestors = [];
	let branchRoot = null;

	if ( path ) {
		const selectedNode = path[ path.length - 1 ];
		blockIdentity = selectedNode?.structuralIdentity || {};
		structuralAncestors = capBlockStructuralAncestorItems(
			path.slice( 0, -1 ).map( ( node ) => toStructuralSummary( node ) )
		);
		branchRoot = findBranchRoot( path );
	}

	if ( branchRoot && path ) {
		const rootIndex = path.findIndex(
			( node ) => node?.clientId === branchRoot.clientId
		);
		const depthFromRoot =
			rootIndex === -1 ? path.length : path.length - rootIndex;

		if ( depthFromRoot > BLOCK_STRUCTURAL_BRANCH_MAX_DEPTH ) {
			branchRoot =
				path[ path.length - BLOCK_STRUCTURAL_BRANCH_MAX_DEPTH ];
		}
	}

	const structuralBranch = branchRoot
		? capBlockStructuralBranchItems(
				summarizeTree( [ branchRoot ], {
					focusClientId: clientId,
					includeBlockCapabilities: false,
					includeStructuralIdentity: true,
					maxChildren: BLOCK_STRUCTURAL_BRANCH_MAX_CHILDREN,
					maxDepth: BLOCK_STRUCTURAL_BRANCH_MAX_DEPTH,
				} )
		  )
		: [];

	const themeTokens = collectThemeTokens();
	const tokenSummary = summarizeTokens( themeTokens );
	const parentContext = getParentContext( clientId, annotatedTree, identityIndex );

	return {
		block: {
			name: instance.name,
			title: instance.title,
			currentAttributes: instance.currentAttributes,
			inspectorPanels: instance.inspectorPanels,
			bindableAttributes: instance.bindableAttributes,
			styles: instance.styles,
			activeStyle: instance.activeStyle,
			variations: instance.variations,
			supportsContentRole: instance.supportsContentRole,
			contentAttributes: instance.contentAttributes,
			configAttributes: instance.configAttributes,
			editingMode: instance.editingMode,
			isInsideContentOnly: instance.isInsideContentOnly,
			blockVisibility: instance.blockVisibility,
			childCount: instance.childCount,
			structuralIdentity: blockIdentity,
		},
		siblingsBefore: getSiblingNames(
			clientId,
			'before',
			BLOCK_SIBLING_SUMMARY_MAX_ITEMS
		),
		siblingsAfter: getSiblingNames(
			clientId,
			'after',
			BLOCK_SIBLING_SUMMARY_MAX_ITEMS
		),
		siblingSummariesBefore: getSiblingSummaries(
			clientId,
			'before',
			BLOCK_SIBLING_SUMMARY_MAX_ITEMS,
			annotatedTree,
			identityIndex
		),
		siblingSummariesAfter: getSiblingSummaries(
			clientId,
			'after',
			BLOCK_SIBLING_SUMMARY_MAX_ITEMS,
			annotatedTree,
			identityIndex
		),
		...( parentContext ? { parentContext } : {} ),
		structuralAncestors,
		structuralBranch,
		themeTokens: tokenSummary,
	};
}

/**
 * Subscribe a React selector callback to every store read that influences the
 * live block recommendation context for the current block.
 *
 * @param {Function} registrySelect Registry-aware selector function from useSelect.
 * @param {string}   clientId       Selected block client ID.
 * @return {boolean} Whether the target block is available.
 */
function subscribeToBlockContextSources( registrySelect, clientId ) {
	if ( ! clientId ) {
		return false;
	}

	const editor = registrySelect( blockEditorStore );
	const block = editor?.getBlock?.( clientId ) || null;

	if ( ! block ) {
		return false;
	}

	const rootId = editor.getBlockRootClientId?.( clientId ) || '';
	const blockName = block?.name || editor.getBlockName?.( clientId ) || '';

	editor.getBlockAttributes?.( clientId );
	editor.getBlockEditingMode?.( clientId );
	editor.getBlockParents?.( clientId );
	editor.getBlockCount?.( clientId );
	editor.getBlockOrder?.( rootId );
	editor.getBlocks?.();
	editor.getSettings?.();

	if ( rootId ) {
		editor.getBlockAttributes?.( rootId );
		editor.getBlockName?.( rootId );
		editor.getBlockCount?.( rootId );
	}

	if ( Array.isArray( editor.getBlockOrder?.( rootId ) ) ) {
		const order = editor.getBlockOrder( rootId );
		const index = order.indexOf( clientId );
		const siblingIds =
			index === -1
				? []
				: order
						.slice(
							Math.max(
								0,
								index - BLOCK_SIBLING_SUMMARY_MAX_ITEMS
							),
							index
						)
						.concat(
							order.slice(
								index + 1,
								index + 1 + BLOCK_SIBLING_SUMMARY_MAX_ITEMS
							)
						);

		siblingIds.forEach( ( id ) => {
			editor.getBlockAttributes?.( id );
			editor.getBlockName?.( id );
		} );
	}

	if ( blockName ) {
		const blocks = registrySelect( blocksStore );

		blocks.getBlockType?.( blockName );
		blocks.getBlockStyles?.( blockName );
		blocks.getBlockVariations?.( blockName, 'block' );
	}

	return true;
}

/**
 * Build a signature that updates whenever the current block recommendation
 * context changes, even if the selected clientId stays the same.
 *
 * @param {Function} registrySelect Registry-aware selector function from useSelect.
 * @param {string}   clientId       Selected block client ID.
 * @return {string} Fresh block context signature, or an empty string.
 */
export function getLiveBlockContextSignature( registrySelect, clientId ) {
	if ( ! subscribeToBlockContextSources( registrySelect, clientId ) ) {
		return '';
	}

	const context = collectBlockContext( clientId );

	return context ? buildBlockRecommendationContextSignature( context ) : '';
}

function getSiblingNames( clientId, direction, count ) {
	const editor = select( blockEditorStore );
	const rootId = editor.getBlockRootClientId( clientId );
	const order = editor.getBlockOrder( rootId || '' );
	const index = order.indexOf( clientId );
	if ( index === -1 ) {
		return [];
	}

	const slice =
		direction === 'before'
			? order.slice( Math.max( 0, index - count ), index )
			: order.slice( index + 1, index + 1 + count );

	return slice.map( ( id ) => editor.getBlockName( id ) ).filter( Boolean );
}
