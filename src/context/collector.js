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
import { collectThemeTokens, summarizeTokens } from './theme-tokens';
import { buildBlockRecommendationContextSignature } from '../utils/block-recommendation-context';
import { buildStructuralContext } from '../utils/structural-identity';
export { buildBlockRecommendationContextSignature };

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

	const structural = buildStructuralContext(
		introspectBlockTree(),
		clientId
	);
	const themeTokens = collectThemeTokens();
	const tokenSummary = summarizeTokens( themeTokens );
	const structuralBranch = structural.branchRoot
		? summarizeTree( [ structural.branchRoot ], {
				focusClientId: clientId,
				includeBlockCapabilities: false,
				includeStructuralIdentity: true,
				maxChildren: 6,
				maxDepth: 3,
		  } )
		: [];

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
			structuralIdentity: structural.blockIdentity,
		},
		siblingsBefore: getSiblingNames( clientId, 'before', 3 ),
		siblingsAfter: getSiblingNames( clientId, 'after', 3 ),
		structuralAncestors: structural.structuralAncestors,
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
