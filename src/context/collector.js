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
	toStructuralSummary,
} from '../utils/structural-identity';
import { collectThemeTokens, summarizeTokens } from './theme-tokens';
import { buildBlockRecommendationContextSignature } from '../utils/block-recommendation-context';
export { buildBlockRecommendationContextSignature };

// ── Annotated tree cache ─────────────────────────────────────────────────────
//
// introspectBlockTree() + annotateStructuralIdentity() together are O(n) in the
// block tree.  collectBlockContext is called on every block selection, so a
// single shared annotated tree is built once per selection change and reused
// for both the structural context pass and the structuralBranch summarizeTree
// pass.

/**
 * Sort object keys recursively so structurally-identical values stringify
 * consistently regardless of key insertion order.
 *
 * @param {unknown} value Comparable value.
 * @return {unknown} Normalized comparable value.
 */
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
			currentAttributes: normalizeComparableValue(
				node?.currentAttributes || {}
			),
			innerBlocks: toIdentityInputs( node?.innerBlocks || [] ),
		} ) );
	}

	return JSON.stringify( toIdentityInputs( tree ) );
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
		structuralAncestors = path
			.slice( 0, -1 )
			.map( ( node ) => toStructuralSummary( node ) );
		branchRoot = findBranchRoot( path );
	}

	const structuralBranch = branchRoot
		? summarizeTree( [ branchRoot ], {
				focusClientId: clientId,
				includeBlockCapabilities: false,
				includeStructuralIdentity: true,
				maxChildren: 6,
				maxDepth: 3,
		  } )
		: [];

	const themeTokens = collectThemeTokens();
	const tokenSummary = summarizeTokens( themeTokens );

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
		siblingsBefore: getSiblingNames( clientId, 'before', 3 ),
		siblingsAfter: getSiblingNames( clientId, 'after', 3 ),
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
