/**
 * Deterministic pattern adaptation engine.
 *
 * Clones a non-synced pattern's resolved block tree exactly once and applies
 * only bounded, attribute-level cosmetic mutations that align the clone to the
 * current theme tokens and local insertion context. The returned `blocks` array
 * is the source of truth for insertion; the `plan` is diagnostics only.
 */
import { cloneBlock } from '@wordpress/blocks';

import { getBlockStyleSupportedStylePathsFromTokens } from '../context/theme-tokens';
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

	if (
		! supportedAlignments( blockRegistry, block.name ).includes( target )
	) {
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

const COLOR_ROLE_SYNONYMS = {
	background: [ 'background', 'base', 'base-2', 'white', 'light' ],
	foreground: [
		'foreground',
		'contrast',
		'contrast-2',
		'text',
		'dark',
		'black',
	],
	primary: [ 'primary', 'accent', 'accent-1', 'brand' ],
	secondary: [ 'secondary', 'accent-2', 'accent-3', 'tertiary' ],
};

function roleForColorSlug( slug ) {
	for ( const [ role, slugs ] of Object.entries( COLOR_ROLE_SYNONYMS ) ) {
		if ( slugs.includes( slug ) ) {
			return role;
		}
	}

	return '';
}

function themeColorSlugs( themeTokens ) {
	const palette = themeTokens?.color?.palette;

	return Array.isArray( palette )
		? palette.map( ( entry ) => entry?.slug ).filter( Boolean )
		: [];
}

function remapColorSlug( slug, themeTokens ) {
	const slugs = themeColorSlugs( themeTokens );

	if ( slugs.includes( slug ) ) {
		return '';
	}

	const role = roleForColorSlug( slug );

	if ( ! role ) {
		return '';
	}

	return (
		slugs.find( ( candidate ) => roleForColorSlug( candidate ) === role ) ||
		''
	);
}

function supportsStylePath( themeTokens, blockSupports, path ) {
	return getBlockStyleSupportedStylePathsFromTokens(
		themeTokens,
		blockSupports || {}
	).some(
		( entry ) =>
			entry.path.length === path.length &&
			entry.path.every( ( segment, index ) => segment === path[ index ] )
	);
}

function colorRule( attribute, path ) {
	return ( block, { themeTokens, blockRegistry } ) => {
		const blockSupports = blockRegistry?.getBlockType?.(
			block?.name
		)?.supports;

		if ( ! supportsStylePath( themeTokens, blockSupports, path ) ) {
			return null;
		}

		const from = block?.attributes?.[ attribute ];

		if ( typeof from !== 'string' || ! from ) {
			return null;
		}

		const to = remapColorSlug( from, themeTokens );

		return to
			? { attribute, from, to, reason: 'theme_color_alignment' }
			: null;
	};
}

function themeSpacingSlugs( themeTokens ) {
	const sizes = themeTokens?.spacing?.spacingSizes;

	return Array.isArray( sizes )
		? sizes.map( ( entry ) => entry?.slug ).filter( Boolean )
		: [];
}

function nearestNumericSlug( slug, themeSlugs ) {
	const source = Number( slug );
	const numeric = themeSlugs
		.map( ( candidate ) => ( {
			slug: candidate,
			value: Number( candidate ),
		} ) )
		.filter( ( entry ) => Number.isFinite( entry.value ) );

	if ( ! Number.isFinite( source ) || numeric.length === 0 ) {
		return '';
	}

	numeric.sort(
		( a, b ) =>
			Math.abs( a.value - source ) - Math.abs( b.value - source ) ||
			a.value - b.value
	);

	return numeric[ 0 ].slug;
}

const SPACING_PRESET_RE = /^var:preset\|spacing\|(.+)$/;

function remapSpacingValue( value, themeTokens ) {
	if ( typeof value !== 'string' ) {
		return value;
	}

	const match = value.match( SPACING_PRESET_RE );

	if ( ! match ) {
		return value;
	}

	const slug = match[ 1 ];
	const themeSlugs = themeSpacingSlugs( themeTokens );

	if ( themeSlugs.includes( slug ) ) {
		return value;
	}

	const replacement = nearestNumericSlug( slug, themeSlugs );

	return replacement ? `var:preset|spacing|${ replacement }` : value;
}

function remapSpacingTree( node, themeTokens, state ) {
	if ( Array.isArray( node ) ) {
		return node.map( ( item ) =>
			remapSpacingTree( item, themeTokens, state )
		);
	}

	if ( node && typeof node === 'object' ) {
		return Object.fromEntries(
			Object.entries( node ).map( ( [ key, value ] ) => [
				key,
				remapSpacingTree( value, themeTokens, state ),
			] )
		);
	}

	const next = remapSpacingValue( node, themeTokens );

	if ( next !== node ) {
		state.changed = true;
	}

	return next;
}

function spacingFacetSupported( blockSupports, facet ) {
	const value = blockSupports?.spacing?.[ facet ];

	if ( value === true ) {
		return true;
	}

	return Array.isArray( value ) && value.length > 0;
}

function spacingRule( block, { themeTokens, blockRegistry } ) {
	const blockSupports = blockRegistry?.getBlockType?.(
		block?.name
	)?.supports;
	const spacing = block?.attributes?.style?.spacing;

	if ( ! spacing || typeof spacing !== 'object' ) {
		return null;
	}

	const state = { changed: false };
	const nextSpacing = { ...spacing };

	for ( const facet of [ 'padding', 'margin', 'blockGap' ] ) {
		if (
			spacing[ facet ] === undefined ||
			! spacingFacetSupported( blockSupports, facet )
		) {
			continue;
		}

		nextSpacing[ facet ] = remapSpacingTree(
			spacing[ facet ],
			themeTokens,
			state
		);
	}

	if ( ! state.changed ) {
		return null;
	}

	return {
		attribute: 'style',
		from: block.attributes.style,
		to: { ...block.attributes.style, spacing: nextSpacing },
		reason: 'theme_spacing_alignment',
	};
}

function hasStyleVariation( className ) {
	return /(^|\s)is-style-[\w-]+/.test(
		typeof className === 'string' ? className : ''
	);
}

function buttonStyleRule( block, { blockRegistry } ) {
	if ( block?.name !== 'core/button' ) {
		return null;
	}

	const className = block?.attributes?.className || '';

	if ( hasStyleVariation( className ) ) {
		return null;
	}

	const styles = blockRegistry?.getBlockStyles?.( 'core/button' );

	if ( ! Array.isArray( styles ) ) {
		return null;
	}

	const variation = styles.find( ( style ) => style && ! style.isDefault );

	if ( ! variation?.name ) {
		return null;
	}

	const token = `is-style-${ variation.name }`;
	const to = className ? `${ className } ${ token }`.trim() : token;

	return {
		attribute: 'className',
		from: className || null,
		to,
		reason: 'theme_button_style',
	};
}

const ADAPTATION_RULES = [
	headingLevelRule,
	alignmentRule,
	colorRule( 'backgroundColor', [ 'color', 'background' ] ),
	colorRule( 'textColor', [ 'color', 'text' ] ),
	spacingRule,
	buttonStyleRule,
];

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
