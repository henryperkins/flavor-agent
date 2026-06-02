import { useMemo } from '@wordpress/element';

import {
	getTemplatePartAreaLookup,
	inferTemplatePartArea,
} from '../utils/template-part-areas';

export function getNonEmptyString( value ) {
	return typeof value === 'string' && value.trim() !== '' ? value.trim() : '';
}

export function buildAncestorEntries( editor, inserterRootClientId ) {
	if ( ! inserterRootClientId ) {
		return [];
	}

	const ancestors = [];
	let parentId = inserterRootClientId;

	while ( parentId ) {
		ancestors.unshift( {
			clientId: parentId,
			blockName: editor.getBlockName?.( parentId ) || '',
			attributes: editor.getBlockAttributes?.( parentId ) || {},
		} );
		parentId = editor.getBlockRootClientId?.( parentId ) ?? null;
	}

	return ancestors;
}

export function buildInsertionContext(
	editor,
	inserterRootClientId,
	insertionPoint,
	siblingOrder
) {
	const ancestorEntries = buildAncestorEntries(
		editor,
		inserterRootClientId
	);
	const rootEntry = ancestorEntries[ ancestorEntries.length - 1 ] || null;
	const areaLookup = getTemplatePartAreaLookup();
	const nearestTemplatePart = [ ...ancestorEntries ]
		.reverse()
		.find( ( entry ) => entry.blockName === 'core/template-part' );
	const templatePartArea = nearestTemplatePart
		? inferTemplatePartArea( nearestTemplatePart.attributes, areaLookup )
		: '';
	const templatePartSlug = getNonEmptyString(
		nearestTemplatePart?.attributes?.slug
	);
	const containerLayout = getNonEmptyString(
		rootEntry?.attributes?.layout?.type
	);
	const rootBlock = getNonEmptyString( rootEntry?.blockName );
	const resolvedSiblingOrder = Array.isArray( siblingOrder )
		? siblingOrder
		: editor.getBlockOrder?.( inserterRootClientId ) || [];
	const insertIndex = insertionPoint?.index ?? resolvedSiblingOrder.length;
	const nearbySiblings = [];
	const start = Math.max( 0, insertIndex - 3 );
	const end = Math.min( resolvedSiblingOrder.length, insertIndex + 3 );

	for ( let i = start; i < end; i++ ) {
		const name = editor.getBlockName?.( resolvedSiblingOrder[ i ] );

		if ( name ) {
			nearbySiblings.push( name );
		}
	}

	return {
		...( rootBlock ? { rootBlock } : {} ),
		ancestors: ancestorEntries
			.map( ( entry ) => entry.blockName )
			.filter( Boolean ),
		nearbySiblings,
		...( templatePartArea ? { templatePartArea } : {} ),
		...( templatePartSlug ? { templatePartSlug } : {} ),
		...( containerLayout ? { containerLayout } : {} ),
	};
}

export function usePatternInsertionContext( {
	enabled = true,
	editor,
	inserterRootClientId,
	insertionIndex,
	blockTree,
	siblingOrder,
} ) {
	return useMemo( () => {
		// `blockTree` (a stable `getBlocks()` reference) is consumed only as a
		// memo-invalidation key: the ancestor walk reads live block state via
		// `editor` selectors, so keying on the block-tree reference recomputes
		// the context when an ancestor's attributes change without re-walking
		// the tree on every unrelated store tick. `siblingOrder` is both a key
		// and the data source for nearby-sibling resolution below.
		void blockTree;

		if ( ! enabled || ! editor ) {
			return null;
		}

		return buildInsertionContext(
			editor,
			inserterRootClientId,
			{
				rootClientId: inserterRootClientId,
				index: insertionIndex,
			},
			siblingOrder
		);
	}, [
		blockTree,
		editor,
		enabled,
		inserterRootClientId,
		insertionIndex,
		siblingOrder,
	] );
}
