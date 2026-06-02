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
	insertionPoint
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
	const siblingOrder = editor.getBlockOrder?.( inserterRootClientId ) || [];
	const insertIndex = insertionPoint?.index ?? siblingOrder.length;
	const nearbySiblings = [];
	const start = Math.max( 0, insertIndex - 3 );
	const end = Math.min( siblingOrder.length, insertIndex + 3 );

	for ( let i = start; i < end; i++ ) {
		const name = editor.getBlockName?.( siblingOrder[ i ] );

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
		void blockTree;
		void siblingOrder;

		if ( ! enabled || ! editor ) {
			return null;
		}

		return buildInsertionContext( editor, inserterRootClientId, {
			rootClientId: inserterRootClientId,
			index: insertionIndex,
		} );
	}, [
		blockTree,
		editor,
		enabled,
		inserterRootClientId,
		insertionIndex,
		siblingOrder,
	] );
}
