export function getInnerBlocks( block = {} ) {
	return Array.isArray( block?.innerBlocks ) ? block.innerBlocks : [];
}

export function summarizeBlockAttributes( attributes = {}, fields = [] ) {
	const summary = {};

	for ( const field of fields ) {
		const value = attributes?.[ field ];

		if (
			typeof value === 'string' ||
			typeof value === 'number' ||
			typeof value === 'boolean'
		) {
			summary[ field ] = value;
		}
	}

	return summary;
}

export function collectNestedBlockStats(
	blocks = [],
	getBlockChildren = getInnerBlocks
) {
	const stats = {
		blockCount: 0,
		maxDepth: 0,
		blockCounts: {},
	};

	const visit = ( branch = [], depth = 1 ) => {
		if ( ! Array.isArray( branch ) ) {
			return;
		}

		branch.forEach( ( block ) => {
			if ( ! block || typeof block !== 'object' || ! block.name ) {
				return;
			}

			stats.blockCount += 1;
			stats.maxDepth = Math.max( stats.maxDepth, depth );
			stats.blockCounts[ block.name ] =
				( stats.blockCounts[ block.name ] || 0 ) + 1;

			visit( getBlockChildren( block ), depth + 1 );
		} );
	};

	visit( blocks );

	return stats;
}

export function normalizeVisiblePatternNames( visiblePatternNames ) {
	if ( ! Array.isArray( visiblePatternNames ) ) {
		return null;
	}

	return Array.from( new Set( visiblePatternNames.filter( Boolean ) ) );
}
