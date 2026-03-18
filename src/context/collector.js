/**
 * Context Collector (Phase 1 — block-scoped only)
 *
 * Assembles a context snapshot for a single block's Inspector
 * recommendations. Combines block introspection with theme tokens.
 */
import { select } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';

import {
	introspectBlockInstance,
	introspectBlockTree,
	summarizeTree,
} from './block-inspector';
import { collectThemeTokens, summarizeTokens } from './theme-tokens';
import { buildStructuralContext } from '../utils/structural-identity';

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
			styles: instance.styles,
			activeStyle: instance.activeStyle,
			variations: instance.variations,
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
