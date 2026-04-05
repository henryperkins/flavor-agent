export function getBlockByPath( blocks = [], path = [] ) {
	let currentBlocks = Array.isArray( blocks ) ? blocks : [];
	let block = null;

	for ( const index of Array.isArray( path ) ? path : [] ) {
		block = currentBlocks[ index ] || null;

		if ( ! block ) {
			return null;
		}

		currentBlocks = Array.isArray( block?.innerBlocks )
			? block.innerBlocks
			: [];
	}

	return block;
}

export function resolveActivityBlock( blockEditorSelect = {}, target = {} ) {
	if ( target?.clientId ) {
		const directBlock = blockEditorSelect?.getBlock?.( target.clientId );

		if ( directBlock ) {
			return directBlock;
		}
	}

	return Array.isArray( target?.blockPath )
		? getBlockByPath(
				blockEditorSelect?.getBlocks?.() || [],
				target.blockPath
		  )
		: null;
}
