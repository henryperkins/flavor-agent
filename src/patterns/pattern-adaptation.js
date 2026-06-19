/**
 * Deterministic pattern adaptation engine.
 *
 * Clones a non-synced pattern's resolved block tree exactly once and applies
 * only bounded, attribute-level cosmetic mutations that align the clone to the
 * current theme tokens and local insertion context. The returned `blocks` array
 * is the source of truth for insertion; the `plan` is diagnostics only.
 */
import { cloneBlock } from '@wordpress/blocks';

import { buildContextSignature } from '../utils/context-signature';
import { isSyncedPatternReference } from './pattern-insertability';

export const ADAPTATION_PLAN_VERSION = 'pattern-adaptation-v1';

const ALL_ALIGNMENTS = [ 'left', 'center', 'right', 'wide', 'full' ];

function clampLevel( level ) {
	return Math.max( 1, Math.min( 6, level ) );
}

function supportedAlignments( blockRegistry, blockName ) {
	const align = blockRegistry?.getBlockType?.( blockName )?.supports?.align;

	if ( align === true ) {
		return ALL_ALIGNMENTS;
	}

	return Array.isArray( align ) ? align : [];
}

function headingLevelRule( block, { adaptationContext } ) {
	if ( block?.name !== 'core/heading' ) {
		return null;
	}

	const preceding = adaptationContext?.precedingHeadingLevel;

	if ( ! Number.isInteger( preceding ) ) {
		return null;
	}

	const from = Number.isInteger( block?.attributes?.level )
		? block.attributes.level
		: 2;
	const to = clampLevel( preceding + 1 );

	return to === from
		? null
		: { attribute: 'level', from, to, reason: 'nearby_heading_hierarchy' };
}

function mostFrequentAlign( aligns ) {
	if ( ! Array.isArray( aligns ) || aligns.length === 0 ) {
		return '';
	}

	const counts = new Map();
	for ( const align of aligns ) {
		counts.set( align, ( counts.get( align ) || 0 ) + 1 );
	}

	let best = '';
	let bestCount = 0;
	for ( const align of aligns ) {
		const count = counts.get( align );
		if ( count > bestCount ) {
			best = align;
			bestCount = count;
		}
	}

	return best;
}

function alignmentRule( block, { adaptationContext, blockRegistry } ) {
	const target =
		adaptationContext?.rootAlign ||
		mostFrequentAlign( adaptationContext?.siblingAligns );

	if ( ! target || ! block?.name ) {
		return null;
	}

	if ( ! supportedAlignments( blockRegistry, block.name ).includes( target ) ) {
		return null;
	}

	const from = block?.attributes?.align ?? null;

	return from === target
		? null
		: {
				attribute: 'align',
				from,
				to: target,
				reason: 'match_container_alignment',
		  };
}

const ADAPTATION_RULES = [ headingLevelRule, alignmentRule ];

function themeHasAnyPreset( themeTokens ) {
	const palette = themeTokens?.color?.palette;
	const spacing = themeTokens?.spacing?.spacingSizes;

	return (
		( Array.isArray( palette ) && palette.length > 0 ) ||
		( Array.isArray( spacing ) && spacing.length > 0 )
	);
}

function applyRulesToTree( blocks, env, basePath = [] ) {
	const changes = [];

	blocks.forEach( ( block, index ) => {
		const path = [ ...basePath, index ];

		for ( const rule of ADAPTATION_RULES ) {
			const change = rule( block, env );

			if ( ! change ) {
				continue;
			}

			block.attributes = {
				...( block.attributes || {} ),
				[ change.attribute ]: change.to,
			};
			changes.push( {
				path,
				blockName: block.name,
				attribute: change.attribute,
				from: change.from,
				to: change.to,
				reason: change.reason,
			} );
		}

		if ( Array.isArray( block.innerBlocks ) && block.innerBlocks.length ) {
			changes.push(
				...applyRulesToTree( block.innerBlocks, env, [
					...path,
					'innerBlocks',
				] )
			);
		}
	} );

	return changes;
}

function blocked( reason ) {
	return {
		status: 'blocked',
		reason,
		blocks: [],
		plan: null,
		adaptationSignature: '',
	};
}

export function buildPatternAdaptationPreview( {
	pattern = null,
	sourceBlocks = [],
	adaptationContext = {},
	insertionTargetSignature = '',
	resolvedContextSignature = '',
	themeTokens = {},
	blockRegistry = null,
} = {} ) {
	if ( isSyncedPatternReference( pattern, sourceBlocks ) ) {
		return blocked( 'unsupported_synced_reference' );
	}

	if ( ! Array.isArray( sourceBlocks ) || sourceBlocks.length === 0 ) {
		return blocked( 'adapted_blocks_not_insertable' );
	}

	const clonedBlocks = sourceBlocks.map( ( block ) => cloneBlock( block ) );
	const env = { adaptationContext, themeTokens, blockRegistry };
	const changes = applyRulesToTree( clonedBlocks, env );

	if ( changes.length === 0 ) {
		return blocked(
			themeHasAnyPreset( themeTokens )
				? 'unsupported_block_support'
				: 'missing_theme_tokens'
		);
	}

	const sourcePatternName = pattern?.name || '';
	const targetSignature = buildContextSignature( {
		insertionTargetSignature,
		resolvedContextSignature,
	} );

	return {
		status: 'ready',
		blocks: clonedBlocks,
		plan: {
			version: ADAPTATION_PLAN_VERSION,
			sourcePatternName,
			targetSignature,
			changes,
		},
		adaptationSignature: buildContextSignature( {
			sourcePatternName,
			insertionTargetSignature,
			resolvedContextSignature,
			adaptationContext,
			changes: changes.map( ( change ) => ( {
				path: change.path,
				attribute: change.attribute,
				to: change.to,
				reason: change.reason,
			} ) ),
		} ),
	};
}

export const __ADAPTATION_RULES = ADAPTATION_RULES;
