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

export const BLOCK_TARGET_MOVED_ERROR =
	'The target block changed position or type and cannot be undone automatically.';

export function getBlockPathByClientId(
	blocks = [],
	clientId = '',
	path = []
) {
	if ( ! clientId ) {
		return null;
	}

	for ( let index = 0; index < blocks.length; index++ ) {
		const block = blocks[ index ];
		const nextPath = [ ...path, index ];

		if ( block?.clientId === clientId ) {
			return nextPath;
		}

		if ( Array.isArray( block?.innerBlocks ) && block.innerBlocks.length ) {
			const nestedPath = getBlockPathByClientId(
				block.innerBlocks,
				clientId,
				nextPath
			);

			if ( nestedPath ) {
				return nestedPath;
			}
		}
	}

	return null;
}

function blockPathsMatch( left = [], right = [] ) {
	if ( ! Array.isArray( left ) || ! Array.isArray( right ) ) {
		return false;
	}

	if ( left.length !== right.length ) {
		return false;
	}

	return left.every( ( value, index ) => value === right[ index ] );
}

export function resolveActivityBlockTarget(
	blockEditorSelect = {},
	target = {}
) {
	const blocks = blockEditorSelect?.getBlocks?.() || [];

	if ( target?.clientId ) {
		const directBlock = blockEditorSelect?.getBlock?.( target.clientId );
		const directBlockPath = getBlockPathByClientId(
			blocks,
			target.clientId
		);

		if ( directBlock && directBlockPath ) {
			return {
				block: directBlock,
				blockPath: directBlockPath,
				resolvedBy: 'clientId',
			};
		}
	}

	if ( Array.isArray( target?.blockPath ) ) {
		const block = getBlockByPath( blocks, target.blockPath );

		return {
			block,
			blockPath: block ? target.blockPath : null,
			resolvedBy: block ? 'blockPath' : null,
		};
	}

	return {
		block: null,
		blockPath: null,
		resolvedBy: null,
	};
}

export function hasResolvedActivityBlockMoved(
	resolvedTarget = {},
	target = {}
) {
	if (
		resolvedTarget?.resolvedBy !== 'clientId' ||
		! target?.clientId ||
		! Array.isArray( target?.blockPath ) ||
		! Array.isArray( resolvedTarget?.blockPath )
	) {
		return false;
	}

	return ! blockPathsMatch( target.blockPath, resolvedTarget.blockPath );
}

export function resolveActivityBlock( blockEditorSelect = {}, target = {} ) {
	return resolveActivityBlockTarget( blockEditorSelect, target ).block;
}
