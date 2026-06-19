import {
	createBlock,
	getBlockBindingsSources,
	rawHandler,
} from '@wordpress/blocks';

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
	return getRejectedResolvedBlockNames(
		resolvePatternBlocks( pattern ),
		rootClientId,
		blockEditor
	);
}

export function getRejectedResolvedBlockNames(
	blocks,
	rootClientId,
	blockEditor
) {
	if (
		typeof blockEditor?.canInsertBlockType !== 'function' ||
		! Array.isArray( blocks )
	) {
		return [];
	}

	const rejected = [];

	for ( const block of blocks ) {
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

// Single source of truth for "this recommendation is a synced/user reference".
// Mirrors resolvePatternBlocks: a user pattern by type/id, or a pattern whose
// resolved blocks are a single core/block reference.
export function isSyncedPatternReference(
	pattern,
	sourceBlocks = resolvePatternBlocks( pattern )
) {
	if (
		pattern?.type === 'user' &&
		pattern?.syncStatus !== 'unsynced' &&
		pattern?.id
	) {
		return true;
	}

	return (
		Array.isArray( sourceBlocks ) &&
		sourceBlocks.length === 1 &&
		sourceBlocks[ 0 ]?.name === 'core/block'
	);
}

function getRegisteredBlockBindingsSources() {
	if ( typeof getBlockBindingsSources === 'function' ) {
		const sources = getBlockBindingsSources();

		return sources && typeof sources === 'object' ? sources : null;
	}

	if ( typeof window === 'undefined' ) {
		return null;
	}

	const globalBlocks = window.wp?.blocks;
	const sourceResolver =
		globalBlocks?.getBlockBindingsSources ||
		globalBlocks?.getAllBlockBindingsSources;

	if ( typeof sourceResolver !== 'function' ) {
		return null;
	}

	const sources = sourceResolver();

	return sources && typeof sources === 'object' ? sources : null;
}

function collectBlockBindingSourceNames( block, sourceNames = new Set() ) {
	const bindings = block?.attributes?.metadata?.bindings;

	if ( bindings && typeof bindings === 'object' ) {
		Object.values( bindings ).forEach( ( binding ) => {
			const source = binding?.source;

			if ( typeof source === 'string' && source.trim() ) {
				sourceNames.add( source.trim() );
			}
		} );
	}

	if ( Array.isArray( block?.innerBlocks ) ) {
		block.innerBlocks.forEach( ( innerBlock ) => {
			collectBlockBindingSourceNames( innerBlock, sourceNames );
		} );
	}

	return sourceNames;
}

export function getUnsafePatternBindingSourceNames(
	pattern,
	bindingSources = getRegisteredBlockBindingsSources()
) {
	if ( ! bindingSources || typeof bindingSources !== 'object' ) {
		return [];
	}

	const sourceNames = new Set();

	resolvePatternBlocks( pattern ).forEach( ( block ) => {
		collectBlockBindingSourceNames( block, sourceNames );
	} );

	return Array.from( sourceNames ).filter( ( sourceName ) => {
		const source = bindingSources[ sourceName ];

		return typeof source?.getValues !== 'function';
	} );
}

export function filterInsertableRecommendedPatterns(
	recommendedPatterns,
	rootClientId,
	blockEditor
) {
	if ( ! Array.isArray( recommendedPatterns ) ) {
		return [];
	}

	const bindingSources = getRegisteredBlockBindingsSources();

	return recommendedPatterns.filter( ( { pattern } ) => {
		const blocks = resolvePatternBlocks( pattern );

		return (
			blocks.length > 0 &&
			getUnsafePatternBindingSourceNames( pattern, bindingSources )
				.length === 0 &&
			getRejectedPatternBlockNames( pattern, rootClientId, blockEditor )
				.length === 0
		);
	} );
}
