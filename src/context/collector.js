/**
 * Context Collector (Phase 1 — block-scoped only)
 *
 * Assembles a context snapshot for a single block's Inspector
 * recommendations. Combines block introspection with theme tokens.
 */
import { select } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';

import { introspectBlockInstance } from './block-inspector';
import { collectThemeTokens, summarizeTokens } from './theme-tokens';

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

	const themeTokens = collectThemeTokens();
	const tokenSummary = summarizeTokens( themeTokens );

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
		},
		siblingsBefore: getSiblingNames( clientId, 'before', 3 ),
		siblingsAfter: getSiblingNames( clientId, 'after', 3 ),
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
