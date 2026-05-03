import { createBlock, rawHandler } from '@wordpress/blocks';

export function resolvePatternBlocks( pattern ) {
	if (
		pattern?.type === 'user' &&
		pattern?.syncStatus !== 'unsynced' &&
		pattern?.id
	) {
		return [ createBlock( 'core/block', { ref: pattern.id } ) ];
	}

	if ( Array.isArray( pattern?.blocks ) && pattern.blocks.length > 0 ) {
		return pattern.blocks;
	}

	if ( typeof pattern?.content === 'string' && pattern.content.trim() ) {
		try {
			return rawHandler( { HTML: pattern.content } ).filter( Boolean );
		} catch {
			return [];
		}
	}

	return [];
}

export function getRejectedPatternBlockNames(
	pattern,
	rootClientId,
	blockEditor
) {
	if ( typeof blockEditor?.canInsertBlockType !== 'function' ) {
		return [];
	}

	const rejected = [];

	for ( const block of resolvePatternBlocks( pattern ) ) {
		if ( ! block?.name ) {
			continue;
		}

		if (
			! blockEditor.canInsertBlockType( block.name, rootClientId ?? null )
		) {
			rejected.push( block.name );
		}
	}

	return rejected;
}

export function filterInsertableRecommendedPatterns(
	recommendedPatterns,
	rootClientId,
	blockEditor
) {
	if ( ! Array.isArray( recommendedPatterns ) ) {
		return [];
	}

	return recommendedPatterns.filter( ( { pattern } ) => {
		const blocks = resolvePatternBlocks( pattern );

		return (
			blocks.length > 0 &&
			getRejectedPatternBlockNames( pattern, rootClientId, blockEditor )
				.length === 0
		);
	} );
}
