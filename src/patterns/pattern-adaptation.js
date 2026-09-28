/**
 * Deterministic pattern adaptation engine.
 *
 * Clones a non-synced pattern's resolved block tree exactly once and applies
 * only bounded, attribute-level cosmetic mutations that align the clone to the
 * current theme tokens and local insertion context. The returned `blocks` array
 * is the source of truth for insertion; the `plan` is diagnostics only.
 */
import { cloneBlock } from '@wordpress/blocks';
import { kebabCase } from '@wordpress/kebab-case';

import { buildContextSignature } from '../utils/context-signature';
import { hasBlockColorSupport } from '../utils/block-color-support';
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
	primary: [ 'primary', 'brand', 'accent', 'accent-1' ],
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

function normalizeColorLabel( label ) {
	return typeof label === 'string'
		? label
				.toLowerCase()
				.replace( /[^a-z0-9]+/g, ' ' )
				.trim()
		: '';
}

/**
 * Role labels a palette entry declares explicitly: its slug, its name outside
 * parentheses, and each comma-separated label inside parentheses. A role word
 * inside a longer name ("Accent / Two", "Primary Dark", "Light green cyan")
 * names a variant or hue, so it is not a label on its own.
 *
 * @param {Object} entry Palette entry.
 * @return {Set<string>} Normalized labels.
 */
function colorEntryLabels( entry ) {
	const name = typeof entry?.name === 'string' ? entry.name : '';
	const annotations = [ ...name.matchAll( /\(([^()]*)\)/g ) ].flatMap(
		( [ , annotation ] ) => annotation.split( ',' )
	);

	return new Set(
		[ entry?.slug, name.replace( /\([^()]*\)/g, ' ' ), ...annotations ]
			.map( normalizeColorLabel )
			.filter( Boolean )
	);
}

function remapColorSlug( slug, themeTokens ) {
	const role = roleForColorSlug( slug );

	if ( ! role ) {
		return { code: 'unmapped_color_preset' };
	}

	const palette = themeTokens.color.palette
		.filter( ( entry ) => entry?.slug )
		.map( ( entry ) => ( {
			slug: entry.slug,
			labels: colorEntryLabels( entry ),
		} ) );

	// Try the pattern's own slug, then aliases of that role. Themes can name
	// tokens freely and label the role (e.g. "Evergreen (brand)"); a color is
	// never assigned a role from its hue, brightness, or palette position.
	for ( const alias of new Set( [ slug, ...COLOR_ROLE_SYNONYMS[ role ] ] ) ) {
		const label = normalizeColorLabel( alias );
		const slugs = [
			...new Set(
				palette
					.filter( ( entry ) => entry.labels.has( label ) )
					.map( ( entry ) => entry.slug )
			),
		];
		if ( slugs.length > 1 ) {
			return { code: 'ambiguous_color_role' };
		}
		if ( slugs.length === 1 ) {
			return { slug: slugs[ 0 ] };
		}
	}
	return { code: 'unmapped_color_preset' };
}

function supportsColor( themeTokens, blockSupports, facet ) {
	return (
		hasBlockColorSupport( blockSupports, facet ) &&
		themeTokens?.color?.[ `${ facet }Enabled` ] !== false
	);
}

function colorRule( attribute, facet ) {
	return ( block, { themeTokens, blockRegistry, diagnostics } ) => {
		const from = block?.attributes?.[ attribute ];
		const slugs = themeColorSlugs( themeTokens );
		if ( typeof from !== 'string' || ! from || slugs.includes( from ) ) {
			return null;
		}
		const blockSupports = blockRegistry?.getBlockType?.(
			block?.name
		)?.supports;
		let result;
		if ( slugs.length === 0 ) {
			result = { code: 'missing_theme_tokens' };
		} else if ( ! supportsColor( themeTokens, blockSupports, facet ) ) {
			result = { code: 'unsupported_block_support' };
		} else {
			result = remapColorSlug( from, themeTokens );
		}
		if ( result.code ) {
			diagnostics.push( {
				code: result.code,
				blockName: block.name,
				attribute,
				value: from,
			} );
			return null;
		}

		return {
			attribute,
			from,
			to: result.slug,
			reason: 'theme_color_alignment',
		};
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

function remapSpacingValue( value, themeTokens, state ) {
	if ( typeof value !== 'string' ) {
		return value;
	}

	const match = value.match( SPACING_PRESET_RE );

	if ( ! match ) {
		return value;
	}

	const slug = match[ 1 ];
	const themeSlugs = themeSpacingSlugs( themeTokens );

	if (
		themeSlugs.includes( slug ) ||
		state.stylePresetSlugs?.get( 'spacing' )?.has( kebabCase( slug ) )
	) {
		return value;
	}

	const replacement = nearestNumericSlug( slug, themeSlugs );
	if ( ! replacement || ! state.supported ) {
		let code = 'unmapped_spacing_preset';
		if ( themeSlugs.length === 0 ) {
			code = 'missing_theme_tokens';
		} else if ( ! state.supported ) {
			code = 'unsupported_block_support';
		}
		state.report( code, slug );
		return value;
	}

	return `var:preset|spacing|${ replacement }`;
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

	const next = remapSpacingValue( node, themeTokens, state );

	if ( next !== node ) {
		state.changed = true;
	}

	return next;
}

const SPACING_FACETS = [ 'padding', 'margin', 'blockGap' ];

function spacingFacetSupported( blockSupports, facet ) {
	const value = blockSupports?.spacing?.[ facet ];

	if ( value === true ) {
		return true;
	}

	return Array.isArray( value ) && value.length > 0;
}

function spacingRule(
	block,
	{ themeTokens, blockRegistry, diagnostics, stylePresetSlugs }
) {
	const blockSupports = blockRegistry?.getBlockType?.(
		block?.name
	)?.supports;
	const spacing = block?.attributes?.style?.spacing;

	if ( ! spacing || typeof spacing !== 'object' ) {
		return null;
	}

	const state = { changed: false, stylePresetSlugs };
	const nextSpacing = { ...spacing };

	for ( const facet of SPACING_FACETS ) {
		if ( spacing[ facet ] === undefined ) {
			continue;
		}
		state.supported = spacingFacetSupported( blockSupports, facet );
		state.report = ( code, value ) =>
			diagnostics.push( {
				code,
				blockName: block.name,
				attribute: `style.spacing.${ facet }`,
				value,
			} );

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
	colorRule( 'backgroundColor', 'background' ),
	colorRule( 'textColor', 'text' ),
	spacingRule,
	buttonStyleRule,
];

// Collected theme presets by the type named in `var:preset|{type}|{slug}` and
// `var(--wp--preset--{type}--{slug})`. References to other types are skipped.
const THEME_PRESET_SOURCES = new Map( [
	[ 'color', ( themeTokens ) => themeTokens?.color?.palette ],
	[ 'gradient', ( themeTokens ) => themeTokens?.color?.gradients ],
	[ 'duotone', ( themeTokens ) => themeTokens?.color?.duotone ],
	[ 'font-size', ( themeTokens ) => themeTokens?.typography?.fontSizes ],
	[ 'font-family', ( themeTokens ) => themeTokens?.typography?.fontFamilies ],
	[ 'spacing', ( themeTokens ) => themeTokens?.spacing?.spacingSizes ],
	[ 'shadow', ( themeTokens ) => themeTokens?.shadow?.presets ],
] );

// Preset-slug attributes that no rule adapts. Block-support attributes hold a
// slug only when the block declares that support; the core block attributes
// always do.
const SUPPORT_PRESET_ATTRIBUTES = [
	{
		attribute: 'gradient',
		presetType: 'gradient',
		supportPaths: [ [ 'color', 'gradients' ] ],
	},
	{
		attribute: 'fontSize',
		presetType: 'font-size',
		supportPaths: [ [ 'typography', 'fontSize' ] ],
	},
	{
		attribute: 'fontFamily',
		presetType: 'font-family',
		supportPaths: [
			[ 'typography', 'fontFamily' ],
			[ 'typography', '__experimentalFontFamily' ],
		],
	},
	{
		attribute: 'borderColor',
		presetType: 'color',
		supportPaths: [
			[ 'border', 'color' ],
			[ '__experimentalBorder', 'color' ],
		],
	},
];

const BLOCK_PRESET_ATTRIBUTES = new Map( [
	[ 'core/cover', { overlayColor: 'color', gradient: 'gradient' } ],
	[
		'core/navigation',
		{ overlayBackgroundColor: 'color', overlayTextColor: 'color' },
	],
	[
		'core/social-links',
		{ iconColor: 'color', iconBackgroundColor: 'color' },
	],
] );

const STYLE_PRESET_RE = /^var:preset\|([a-z0-9-]+)\|(.+)$/;
const CSS_PRESET_RE =
	/var\(\s*--wp--preset--([a-z]+(?:-[a-z]+)*)--([^\s,()]+)\s*[,)]/g;

function themePresetSlugs( themeTokens, presetType ) {
	const presets = THEME_PRESET_SOURCES.get( presetType )?.( themeTokens );

	return Array.isArray( presets )
		? presets.map( ( entry ) => entry?.slug ).filter( Boolean )
		: null;
}

function collectStylePresetSlugs( themeTokens, inherited ) {
	const result = new Map();

	for ( const presetType of THEME_PRESET_SOURCES.keys() ) {
		const localSlugs = themePresetSlugs( themeTokens, presetType );
		// Duotone presets reference SVG filters, not inheritable CSS variables.
		const ancestorSlugs =
			presetType === 'duotone' ? null : inherited?.get( presetType );

		if ( localSlugs || ancestorSlugs ) {
			result.set(
				presetType,
				new Set( [
					...( ancestorSlugs || [] ),
					...( localSlugs || [] ).map( ( slug ) =>
						kebabCase( slug )
					),
				] )
			);
		}
	}

	return result;
}

function hasStyleSupport( blockSupports, supportPaths ) {
	return supportPaths.some(
		( [ group, facet ] ) => !! blockSupports?.[ group ]?.[ facet ]
	);
}

function collectStylePresetReferences( node, path = [], references = [] ) {
	if ( typeof node === 'string' ) {
		const match = node.match( STYLE_PRESET_RE );

		if ( match ) {
			references.push( {
				path,
				presetType: match[ 1 ],
				slug: match[ 2 ],
				isPresetValue: true,
			} );
		}

		for ( const [ , presetType, slug ] of node.matchAll( CSS_PRESET_RE ) ) {
			references.push( { path, presetType, slug } );
		}
	} else if ( node && typeof node === 'object' ) {
		for ( const [ key, value ] of Object.entries( node ) ) {
			collectStylePresetReferences( value, [ ...path, key ], references );
		}
	}

	return references;
}

/**
 * Report theme presets the adapted block still references but the theme does
 * not define, so a zero-change result is never mislabeled as unchanged. Colors
 * handled by `colorRule` and spacing presets handled by `spacingRule` report
 * their own diagnostics.
 *
 * @param {Object}      block                Adapted block.
 * @param {Object}      env                  Adaptation environment.
 * @param {Object}      env.themeTokens      Current theme tokens.
 * @param {Object|null} env.blockRegistry    Block type registry.
 * @param {Object[]}    env.diagnostics      Diagnostics to append to.
 * @param {Map}         env.stylePresetSlugs CSS preset names available in this branch.
 */
function reportUnresolvedPresets(
	block,
	{ themeTokens, blockRegistry, diagnostics, stylePresetSlugs }
) {
	const attributes = block?.attributes || {};
	const blockSupports = blockRegistry?.getBlockType?.(
		block?.name
	)?.supports;
	const attributeTypes = new Map(
		Object.entries( BLOCK_PRESET_ATTRIBUTES.get( block?.name ) || {} )
	);

	for ( const {
		attribute,
		presetType,
		supportPaths,
	} of SUPPORT_PRESET_ATTRIBUTES ) {
		if ( hasStyleSupport( blockSupports, supportPaths ) ) {
			attributeTypes.set( attribute, presetType );
		}
	}

	const references = [ ...attributeTypes ].map(
		( [ attribute, presetType ] ) => ( {
			attribute,
			presetType,
			slug: attributes[ attribute ],
		} )
	);

	for ( const reference of collectStylePresetReferences(
		attributes.style
	) ) {
		const [ group, facet ] = reference.path;
		const handledBySpacingRule =
			reference.isPresetValue &&
			reference.presetType === 'spacing' &&
			group === 'spacing' &&
			SPACING_FACETS.includes( facet );

		if ( ! handledBySpacingRule ) {
			references.push( {
				attribute: [ 'style', ...reference.path ].join( '.' ),
				presetType: reference.presetType,
				slug: reference.slug,
				isStyleReference: true,
				isPresetValue: reference.isPresetValue,
			} );
		}
	}

	for ( const {
		attribute,
		presetType,
		slug,
		isStyleReference,
		isPresetValue,
	} of references ) {
		if ( typeof slug !== 'string' || ! slug ) {
			continue;
		}

		const slugs = isStyleReference
			? stylePresetSlugs?.get( presetType )
			: themePresetSlugs( themeTokens, presetType );
		// WordPress normalizes encoded style presets when generating CSS. Literal
		// CSS variable names are case-sensitive and must match exactly as written.
		const isAvailable = isStyleReference
			? slugs?.has( isPresetValue ? kebabCase( slug ) : slug )
			: slugs?.includes( slug );

		if ( slugs && ! isAvailable ) {
			diagnostics.push( {
				code: 'unresolved_theme_preset',
				blockName: block.name,
				attribute,
				presetType,
				value: slug,
			} );
		}
	}
}

function applyRulesToTree( blocks, env, basePath = [] ) {
	const changes = [];

	blocks.forEach( ( block, index ) => {
		const path = [ ...basePath, index ];
		const themeTokens =
			env.getThemeTokensForBlock?.( block.name ) ?? env.themeTokens;
		const blockEnv = {
			...env,
			themeTokens,
			stylePresetSlugs: collectStylePresetSlugs(
				themeTokens,
				env.stylePresetSlugs
			),
		};

		for ( const rule of ADAPTATION_RULES ) {
			const change = rule( block, blockEnv );

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

		reportUnresolvedPresets( block, blockEnv );

		if ( Array.isArray( block.innerBlocks ) && block.innerBlocks.length ) {
			changes.push(
				...applyRulesToTree(
					block.innerBlocks,
					{ ...env, stylePresetSlugs: blockEnv.stylePresetSlugs },
					[ ...path, 'innerBlocks' ]
				)
			);
		}
	} );

	return changes;
}

function blocked( reason, diagnostics = [] ) {
	return {
		status: 'blocked',
		reason,
		diagnostics,
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
	getThemeTokensForBlock = null,
	blockRegistry = null,
} = {} ) {
	if ( isSyncedPatternReference( pattern, sourceBlocks ) ) {
		return blocked( 'unsupported_synced_reference' );
	}

	if ( ! Array.isArray( sourceBlocks ) || sourceBlocks.length === 0 ) {
		return blocked( 'adapted_blocks_not_insertable' );
	}

	const clonedBlocks = sourceBlocks.map( ( block ) => cloneBlock( block ) );
	const diagnostics = [];
	const env = {
		adaptationContext,
		themeTokens,
		getThemeTokensForBlock,
		blockRegistry,
		diagnostics,
	};
	const changes = applyRulesToTree( clonedBlocks, env );

	if ( changes.length === 0 && diagnostics.length > 0 ) {
		return blocked( diagnostics[ 0 ].code, diagnostics );
	}

	const sourcePatternName = pattern?.name || '';
	const targetSignature = buildContextSignature( {
		insertionTargetSignature,
		resolvedContextSignature,
	} );

	return {
		status: changes.length > 0 ? 'ready' : 'unchanged',
		reason:
			changes.length > 0 ? 'adapted_preview_ready' : 'no_changes_needed',
		diagnostics,
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
