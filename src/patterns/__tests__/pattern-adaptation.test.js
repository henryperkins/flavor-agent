const mockCloneBlock = jest.fn();

jest.mock( '@wordpress/blocks', () => ( {
	cloneBlock: ( ...args ) => mockCloneBlock( ...args ),
} ) );

jest.mock( '@wordpress/data', () => ( {
	select: jest.fn(),
} ) );

import { buildPatternAdaptationPreview } from '../pattern-adaptation';
import { collectThemeTokensFromSettings } from '../../context/theme-tokens';

function deepClone( block ) {
	return JSON.parse( JSON.stringify( block ) );
}

const THEME_TOKENS = {
	color: {
		palette: [
			{ slug: 'base' },
			{ slug: 'contrast' },
			{ slug: 'primary' },
		],
		backgroundEnabled: true,
		textEnabled: true,
	},
	spacing: {
		spacingSizes: [ { slug: '20' }, { slug: '40' }, { slug: '60' } ],
	},
};

const EMPTY_THEME_TOKENS = {
	color: { palette: [], backgroundEnabled: true, textEnabled: true },
	spacing: { spacingSizes: [] },
};

const REGISTRY = {
	getBlockType: jest.fn( () => ( { supports: {} } ) ),
	getBlockStyles: jest.fn( () => [] ),
};

const BASE_CTX = {
	precedingHeadingLevel: null,
	nearbyHeadingLevels: [],
	rootAlign: '',
	siblingAligns: [],
};

beforeEach( () => {
	mockCloneBlock.mockReset();
	mockCloneBlock.mockImplementation( deepClone );
	REGISTRY.getBlockType.mockReset();
	REGISTRY.getBlockType.mockReturnValue( { supports: {} } );
	REGISTRY.getBlockStyles.mockReset();
	REGISTRY.getBlockStyles.mockReturnValue( [] );
} );

function run( overrides = {} ) {
	return buildPatternAdaptationPreview( {
		pattern: { name: 'theme/hero' },
		sourceBlocks: [ { name: 'core/paragraph', attributes: {} } ],
		adaptationContext: BASE_CTX,
		insertionTargetSignature: 'target-sig',
		resolvedContextSignature: 'resolved-sig',
		themeTokens: THEME_TOKENS,
		blockRegistry: REGISTRY,
		...overrides,
	} );
}

describe( 'buildPatternAdaptationPreview scaffold', () => {
	test( 'refuses synced/user pattern references', () => {
		const result = run( {
			pattern: { name: 'core/block/12', type: 'user', id: 12 },
			sourceBlocks: [ { name: 'core/block', attributes: { ref: 12 } } ],
		} );

		expect( result.status ).toBe( 'blocked' );
		expect( result.reason ).toBe( 'unsupported_synced_reference' );
		expect( result.blocks ).toEqual( [] );
		expect( result.plan ).toBeNull();
		expect( result.adaptationSignature ).toBe( '' );
	} );

	test( 'returns an unchanged preview when no adjustment is needed', () => {
		const result = run();

		expect( result.status ).toBe( 'unchanged' );
		expect( result.reason ).toBe( 'no_changes_needed' );
		expect( result.blocks ).toEqual( [
			{ name: 'core/paragraph', attributes: {} },
		] );
		expect( result.plan.changes ).toEqual( [] );
		expect( result.diagnostics ).toEqual( [] );
		expect( result.adaptationSignature ).not.toBe( '' );
	} );

	test( 'does not require presets for a pattern that inherits theme styles', () => {
		expect( run( { themeTokens: EMPTY_THEME_TOKENS } ).status ).toBe(
			'unchanged'
		);
	} );

	test( 'reports missing_theme_tokens when the theme exposes no presets', () => {
		const result = run( {
			sourceBlocks: [
				{
					name: 'core/group',
					attributes: { backgroundColor: 'off-theme' },
				},
			],
			themeTokens: EMPTY_THEME_TOKENS,
		} );

		expect( result.status ).toBe( 'blocked' );
		expect( result.reason ).toBe( 'missing_theme_tokens' );
	} );

	test( 'clones source blocks exactly once and never mutates the source', () => {
		const sourceBlocks = [ { name: 'core/paragraph', attributes: {} } ];
		run( { sourceBlocks } );

		expect( mockCloneBlock ).toHaveBeenCalledTimes( sourceBlocks.length );
		expect( sourceBlocks[ 0 ].attributes ).toEqual( {} );
	} );

	test( 'blocks an empty source block array', () => {
		const result = run( { sourceBlocks: [] } );

		expect( result.status ).toBe( 'blocked' );
		expect( result.reason ).toBe( 'adapted_blocks_not_insertable' );
	} );
} );

describe( 'heading level + alignment rules', () => {
	test( 'sets heading level to nearest preceding heading + 1', () => {
		const result = run( {
			sourceBlocks: [
				{ name: 'core/heading', attributes: { level: 4 } },
			],
			adaptationContext: { ...BASE_CTX, precedingHeadingLevel: 2 },
		} );

		expect( result.status ).toBe( 'ready' );
		expect( result.blocks[ 0 ].attributes.level ).toBe( 3 );
		expect( result.plan.changes ).toContainEqual(
			expect.objectContaining( {
				attribute: 'level',
				from: 4,
				to: 3,
				reason: 'nearby_heading_hierarchy',
			} )
		);
	} );

	test( 'clamps heading level to 6 and skips when already aligned', () => {
		const aligned = run( {
			sourceBlocks: [
				{ name: 'core/heading', attributes: { level: 3 } },
			],
			adaptationContext: { ...BASE_CTX, precedingHeadingLevel: 2 },
		} );
		expect( aligned.status ).toBe( 'unchanged' );

		const clamped = run( {
			sourceBlocks: [
				{ name: 'core/heading', attributes: { level: 2 } },
			],
			adaptationContext: { ...BASE_CTX, precedingHeadingLevel: 6 },
		} );
		expect( clamped.blocks[ 0 ].attributes.level ).toBe( 6 );
	} );

	test( 'matches container alignment when the block supports it', () => {
		REGISTRY.getBlockType.mockImplementation( ( name ) =>
			name === 'core/image'
				? { supports: { align: [ 'wide', 'full' ] } }
				: { supports: {} }
		);

		const result = run( {
			sourceBlocks: [ { name: 'core/image', attributes: {} } ],
			adaptationContext: { ...BASE_CTX, rootAlign: 'full' },
		} );

		expect( result.status ).toBe( 'ready' );
		expect( result.blocks[ 0 ].attributes.align ).toBe( 'full' );
		expect( result.plan.changes ).toContainEqual(
			expect.objectContaining( {
				attribute: 'align',
				to: 'full',
				reason: 'match_container_alignment',
			} )
		);
	} );

	test( 'does not apply an alignment the block does not support', () => {
		REGISTRY.getBlockType.mockReturnValue( {
			supports: { align: [ 'wide' ] },
		} );

		const result = run( {
			sourceBlocks: [ { name: 'core/image', attributes: {} } ],
			adaptationContext: { ...BASE_CTX, rootAlign: 'full' },
		} );

		expect( result.status ).toBe( 'unchanged' );
	} );

	test( 'falls back to the most frequent sibling align with no root align', () => {
		REGISTRY.getBlockType.mockReturnValue( {
			supports: { align: [ 'wide', 'full' ] },
		} );

		const result = run( {
			sourceBlocks: [ { name: 'core/image', attributes: {} } ],
			adaptationContext: {
				...BASE_CTX,
				rootAlign: '',
				siblingAligns: [ 'wide', 'full', 'wide' ],
			},
		} );

		expect( result.status ).toBe( 'ready' );
		expect( result.blocks[ 0 ].attributes.align ).toBe( 'wide' );
	} );
} );

describe( 'color + spacing remap rules', () => {
	test.each( [ true, {}, { gradients: true, link: true } ] )(
		'honors implicit text and background support for color declaration %j',
		( color ) => {
			REGISTRY.getBlockType.mockReturnValue( { supports: { color } } );
			const result = run( {
				sourceBlocks: [
					{
						name: 'core/paragraph',
						attributes: {
							textColor: 'foreground',
							backgroundColor: 'background',
						},
					},
				],
			} );
			expect( result.status ).toBe( 'ready' );
			expect( result.blocks[ 0 ].attributes ).toEqual( {
				textColor: 'contrast',
				backgroundColor: 'base',
			} );
		}
	);

	test( 'does not call an unresolved spacing preset unchanged', () => {
		REGISTRY.getBlockType.mockReturnValue( {
			supports: { spacing: { padding: true } },
		} );
		const result = run( {
			sourceBlocks: [
				{
					name: 'core/group',
					attributes: {
						style: {
							spacing: {
								padding: { top: 'var:preset|spacing|roomy' },
							},
						},
					},
				},
			],
		} );
		expect( result.status ).toBe( 'blocked' );
		expect( result.reason ).toBe( 'unmapped_spacing_preset' );
	} );

	test( 'remaps an off-theme background slug to a same-role theme slug', () => {
		REGISTRY.getBlockType.mockReturnValue( {
			supports: { color: { background: true, text: true } },
		} );

		const result = run( {
			sourceBlocks: [
				{
					name: 'core/group',
					attributes: { backgroundColor: 'accent' },
				},
			],
		} );

		expect( result.status ).toBe( 'ready' );
		expect( result.blocks[ 0 ].attributes.backgroundColor ).toBe(
			'primary'
		);
		expect( result.plan.changes ).toContainEqual(
			expect.objectContaining( {
				attribute: 'backgroundColor',
				from: 'accent',
				to: 'primary',
				reason: 'theme_color_alignment',
			} )
		);
	} );

	test( 'keeps an in-theme color slug unchanged', () => {
		REGISTRY.getBlockType.mockReturnValue( {
			supports: { color: { background: true } },
		} );

		const result = run( {
			sourceBlocks: [
				{
					name: 'core/group',
					attributes: { backgroundColor: 'primary' },
				},
			],
		} );

		expect( result.status ).toBe( 'unchanged' );
	} );

	test( 'remaps a numeric off-theme spacing slug to the nearest theme slug', () => {
		REGISTRY.getBlockType.mockReturnValue( {
			supports: { spacing: { padding: true } },
		} );

		const result = run( {
			sourceBlocks: [
				{
					name: 'core/group',
					attributes: {
						style: {
							spacing: {
								padding: {
									top: 'var:preset|spacing|50',
								},
							},
						},
					},
				},
			],
		} );

		expect( result.status ).toBe( 'ready' );
		expect( result.blocks[ 0 ].attributes.style.spacing.padding.top ).toBe(
			'var:preset|spacing|40'
		);
		expect( result.plan.changes ).toContainEqual(
			expect.objectContaining( {
				attribute: 'style',
				reason: 'theme_spacing_alignment',
			} )
		);
	} );
} );

describe( 'button style rule', () => {
	test( 'applies the first registered non-default button style', () => {
		REGISTRY.getBlockStyles.mockImplementation( ( name ) =>
			name === 'core/button'
				? [ { name: 'fill', isDefault: true }, { name: 'outline' } ]
				: []
		);

		const result = run( {
			sourceBlocks: [ { name: 'core/button', attributes: {} } ],
		} );

		expect( result.status ).toBe( 'ready' );
		expect( result.blocks[ 0 ].attributes.className ).toBe(
			'is-style-outline'
		);
		expect( result.plan.changes ).toContainEqual(
			expect.objectContaining( {
				attribute: 'className',
				to: 'is-style-outline',
				reason: 'theme_button_style',
			} )
		);
	} );

	test( 'leaves a button that already has an explicit style', () => {
		REGISTRY.getBlockStyles.mockReturnValue( [
			{ name: 'fill', isDefault: true },
			{ name: 'outline' },
		] );

		const result = run( {
			sourceBlocks: [
				{
					name: 'core/button',
					attributes: { className: 'is-style-squared' },
				},
			],
		} );

		expect( result.status ).toBe( 'unchanged' );
	} );
} );

describe( 'custom theme color roles and diagnostics', () => {
	const palette = [
		{ slug: 'parchment-50', name: 'Parchment 50', color: '#FAF6EC' },
		{ slug: 'ink-900', name: 'Ink 900', color: '#1B231D' },
		{ slug: 'green-700', name: 'Evergreen 700 (brand)', color: '#2E4A3A' },
		{ slug: 'gold-500', name: 'Gold 500 (accent)', color: '#C29A44' },
	];

	function previewColor( slug, overrides = {} ) {
		REGISTRY.getBlockType.mockReturnValue( {
			supports: { color: { text: true, background: true } },
		} );
		return run( {
			sourceBlocks: [
				{ name: 'core/paragraph', attributes: { textColor: slug } },
			],
			themeTokens: {
				...THEME_TOKENS,
				color: { ...THEME_TOKENS.color, palette },
			},
			...overrides,
		} );
	}

	test.each( [
		[ 'primary', 'green-700' ],
		[ 'accent', 'gold-500' ],
	] )(
		'maps %s to the explicitly labeled custom theme preset %s',
		( from, to ) => {
			const result = previewColor( from );
			expect( result.status ).toBe( 'ready' );
			expect( result.blocks[ 0 ].attributes.textColor ).toBe( to );
			expect( result.diagnostics ).toEqual( [] );
		}
	);

	test( 'preserves custom presets already in the theme', () => {
		const result = previewColor( 'ink-900' );
		expect( result.status ).toBe( 'unchanged' );
		expect( result.blocks[ 0 ].attributes.textColor ).toBe( 'ink-900' );
	} );

	test( 'does not reinterpret a numbered secondary accent as a primary accent', () => {
		const result = previewColor( 'accent', {
			themeTokens: {
				color: {
					...THEME_TOKENS.color,
					palette: [
						{ slug: 'river-500', name: 'River (accent 2)' },
					],
				},
			},
		} );
		expect( result.reason ).toBe( 'unmapped_color_preset' );
	} );

	test.each( [ false, undefined, { text: false } ] )(
		'does not mutate a color with disabled or absent color support %j',
		( color ) => {
			REGISTRY.getBlockType.mockReturnValue( {
				supports: { color },
			} );
			const result = run( {
				sourceBlocks: [
					{
						name: 'core/paragraph',
						attributes: { textColor: 'accent' },
					},
				],
			} );
			expect( result.status ).toBe( 'blocked' );
			expect( result.reason ).toBe( 'unsupported_block_support' );
		}
	);

	test( 'respects the theme text-color opt-out even when the block supports it', () => {
		const result = previewColor( 'primary', {
			themeTokens: { color: { palette, textEnabled: false } },
		} );
		expect( result.status ).toBe( 'blocked' );
		expect( result.reason ).toBe( 'unsupported_block_support' );
	} );

	test( 'explains an unmapped preset without treating it as no changes needed', () => {
		const result = previewColor( 'unknown-brand' );
		expect( result.status ).toBe( 'blocked' );
		expect( result.reason ).toBe( 'unmapped_color_preset' );
		expect( result.diagnostics ).toContainEqual( {
			code: 'unmapped_color_preset',
			blockName: 'core/paragraph',
			attribute: 'textColor',
			value: 'unknown-brand',
		} );
	} );

	test( 'does not choose arbitrarily between equally labeled custom colors', () => {
		const result = previewColor( 'accent', {
			themeTokens: {
				color: {
					...THEME_TOKENS.color,
					palette: [
						{ slug: 'gold-500', name: 'Gold (accent)' },
						{ slug: 'river-500', name: 'River (accent)' },
					],
				},
			},
		} );
		expect( result.status ).toBe( 'blocked' );
		expect( result.reason ).toBe( 'ambiguous_color_role' );
	} );

	test( 'reports unresolved colors alongside other successful adjustments', () => {
		const result = previewColor( 'unknown-brand', {
			sourceBlocks: [
				{
					name: 'core/heading',
					attributes: { level: 5, textColor: 'unknown-brand' },
				},
			],
			adaptationContext: { ...BASE_CTX, precedingHeadingLevel: 2 },
		} );
		expect( result.status ).toBe( 'ready' );
		expect( result.blocks[ 0 ].attributes ).toEqual( {
			level: 3,
			textColor: 'unknown-brand',
		} );
		expect( result.diagnostics[ 0 ].code ).toBe( 'unmapped_color_preset' );
	} );
} );

describe( 'bundled theme palettes and role labels', () => {
	const entries = ( pairs ) =>
		pairs.map( ( [ slug, name ] ) => ( { slug, name } ) );
	// Editor settings keep core's default palette even when a theme hides it.
	const CORE_DEFAULT_PALETTE = entries( [
		[ 'black', 'Black' ],
		[ 'cyan-bluish-gray', 'Cyan bluish gray' ],
		[ 'white', 'White' ],
		[ 'pale-pink', 'Pale pink' ],
		[ 'vivid-red', 'Vivid red' ],
		[ 'luminous-vivid-orange', 'Luminous vivid orange' ],
		[ 'luminous-vivid-amber', 'Luminous vivid amber' ],
		[ 'light-green-cyan', 'Light green cyan' ],
		[ 'vivid-green-cyan', 'Vivid green cyan' ],
		[ 'pale-cyan-blue', 'Pale cyan blue' ],
		[ 'vivid-cyan-blue', 'Vivid cyan blue' ],
		[ 'vivid-purple', 'Vivid purple' ],
	] );
	const TT4_PALETTE = entries( [
		[ 'base', 'Base' ],
		[ 'base-2', 'Base / Two' ],
		[ 'contrast', 'Contrast' ],
		[ 'contrast-2', 'Contrast / Two' ],
		[ 'contrast-3', 'Contrast / Three' ],
		[ 'accent', 'Accent' ],
		[ 'accent-2', 'Accent / Two' ],
		[ 'accent-3', 'Accent / Three' ],
		[ 'accent-4', 'Accent / Four' ],
		[ 'accent-5', 'Accent / Five' ],
	] );
	const TT5_PALETTE = entries( [
		[ 'base', 'Base' ],
		[ 'contrast', 'Contrast' ],
		[ 'accent-1', 'Accent 1' ],
		[ 'accent-2', 'Accent 2' ],
		[ 'accent-3', 'Accent 3' ],
		[ 'accent-4', 'Accent 4' ],
		[ 'accent-5', 'Accent 5' ],
		[ 'accent-6', 'Accent 6' ],
	] );

	function remapTextColor( slug, palette ) {
		REGISTRY.getBlockType.mockReturnValue( { supports: { color: {} } } );
		const result = run( {
			sourceBlocks: [
				{ name: 'core/paragraph', attributes: { textColor: slug } },
			],
			themeTokens: {
				...THEME_TOKENS,
				color: { ...THEME_TOKENS.color, palette },
			},
		} );

		return result.status === 'ready'
			? result.blocks[ 0 ].attributes.textColor
			: result.reason;
	}

	test.each( [
		[ 'primary', 'accent' ],
		[ 'brand', 'accent' ],
		[ 'accent-1', 'accent' ],
		[ 'secondary', 'accent-2' ],
		[ 'foreground', 'contrast' ],
		[ 'dark', 'contrast' ],
		[ 'background', 'base' ],
		[ 'light', 'base' ],
	] )( 'maps %s to %s on Twenty Twenty-Four', ( from, to ) => {
		expect(
			remapTextColor( from, [ ...CORE_DEFAULT_PALETTE, ...TT4_PALETTE ] )
		).toBe( to );
	} );

	test.each( [
		[ 'primary', 'accent-1' ],
		[ 'foreground', 'contrast' ],
		[ 'light', 'base' ],
	] )( 'maps %s to %s on Twenty Twenty-Five', ( from, to ) => {
		expect(
			remapTextColor( from, [ ...CORE_DEFAULT_PALETTE, ...TT5_PALETTE ] )
		).toBe( to );
	} );

	test( 'does not read modifier words in color names as role labels', () => {
		const palette = entries( [
			[ 'primary', 'Primary' ],
			[ 'primary-dark', 'Primary Dark' ],
			[ 'primary-light', 'Primary Light' ],
			[ 'white', 'White' ],
			[ 'black', 'Black' ],
		] );

		expect( remapTextColor( 'foreground', palette ) ).toBe( 'black' );
		expect( remapTextColor( 'light', palette ) ).toBe( 'white' );
		expect( remapTextColor( 'brand', palette ) ).toBe( 'primary' );
		expect( remapTextColor( 'brand', palette.slice( 1 ) ) ).toBe(
			'unmapped_color_preset'
		);
	} );

	test.each( [
		[ 'Evergreen (brand, primary)', 'primary' ],
		[ 'Brand', 'brand' ],
	] )( 'accepts the explicit role label in %s', ( name, from ) => {
		expect( remapTextColor( from, [ { slug: 'green-700', name } ] ) ).toBe(
			'green-700'
		);
	} );
} );

describe( 'unresolved theme presets', () => {
	const PRESET_TOKENS = {
		...THEME_TOKENS,
		color: { ...THEME_TOKENS.color, gradients: [ { slug: 'dusk' } ] },
		typography: {
			fontSizes: [ { slug: 'medium' } ],
			fontFamilies: [ { slug: 'body' } ],
		},
		shadow: { presets: [ { slug: 'natural' } ] },
	};
	const PRESET_SUPPORTS = {
		color: { gradients: true },
		typography: { fontSize: true, __experimentalFontFamily: true },
		__experimentalBorder: { color: true },
		spacing: { padding: true },
	};

	function preview( sourceBlocks, supports = PRESET_SUPPORTS, overrides ) {
		REGISTRY.getBlockType.mockReturnValue( { supports } );
		return run( {
			sourceBlocks,
			themeTokens: PRESET_TOKENS,
			...overrides,
		} );
	}

	const paragraph = ( attributes ) => ( {
		name: 'core/paragraph',
		attributes,
	} );

	test.each( [
		[ { __experimentalBorder: { color: true } }, 'blocked' ],
		[ { border: { color: true } }, 'blocked' ],
		[
			{ border: { color: true }, __experimentalBorder: { color: false } },
			'blocked',
		],
		[ { __experimentalBorder: { color: false } }, 'unchanged' ],
		[ {}, 'unchanged' ],
	] )(
		'checks a missing border preset for support %j',
		( supports, status ) => {
			const result = preview(
				[
					{
						name: 'core/group',
						attributes: { borderColor: 'missing-brand' },
					},
				],
				supports
			);
			expect( result.status ).toBe( status );
			expect( result.diagnostics ).toEqual(
				status === 'blocked'
					? [
							{
								code: 'unresolved_theme_preset',
								blockName: 'core/group',
								attribute: 'borderColor',
								presetType: 'color',
								value: 'missing-brand',
							},
					  ]
					: []
			);
		}
	);

	test.each( [
		[ 'fontSize', { fontSize: 'gigantic' }, 'font-size', 'gigantic' ],
		[ 'fontFamily', { fontFamily: 'heading' }, 'font-family', 'heading' ],
		[ 'gradient', { gradient: 'sunset' }, 'gradient', 'sunset' ],
		[ 'borderColor', { borderColor: 'brand-ink' }, 'color', 'brand-ink' ],
		[
			'style.color.text',
			{ style: { color: { text: 'var:preset|color|brand-ink' } } },
			'color',
			'brand-ink',
		],
		[
			'style.elements.link.color.text',
			{
				style: {
					elements: {
						link: { color: { text: 'var:preset|color|brand-ink' } },
					},
				},
			},
			'color',
			'brand-ink',
		],
		[
			'style.typography.fontSize',
			{
				style: {
					typography: {
						fontSize: 'var(--wp--preset--font-size--gigantic)',
					},
				},
			},
			'font-size',
			'gigantic',
		],
		[
			'style.shadow',
			{ style: { shadow: 'var:preset|shadow|glow' } },
			'shadow',
			'glow',
		],
	] )(
		'blocks a zero-change preview with an unresolved %s preset',
		( attribute, attributes, presetType, value ) => {
			const result = preview( [ paragraph( attributes ) ] );

			expect( result.status ).toBe( 'blocked' );
			expect( result.reason ).toBe( 'unresolved_theme_preset' );
			expect( result.diagnostics ).toEqual( [
				{
					code: 'unresolved_theme_preset',
					blockName: 'core/paragraph',
					attribute,
					presetType,
					value,
				},
			] );
		}
	);

	test( 'reports no changes needed when every preset is in the theme', () => {
		const result = preview( [
			paragraph( {
				fontSize: 'medium',
				fontFamily: 'body',
				gradient: 'dusk',
				borderColor: 'contrast',
				style: {
					color: { text: 'var:preset|color|base' },
					typography: {
						fontSize: 'var(--wp--preset--font-size--medium)',
					},
					shadow: 'var:preset|shadow|natural',
				},
			} ),
		] );

		expect( result.status ).toBe( 'unchanged' );
		expect( result.diagnostics ).toEqual( [] );
	} );

	test( 'ignores preset-named attributes on blocks without that support', () => {
		const result = preview(
			[
				paragraph( {
					fontSize: 'gigantic',
					gradient: 'sunset',
					borderColor: 'brand-ink',
				} ),
			],
			{}
		);

		expect( result.status ).toBe( 'unchanged' );
	} );

	test( 'skips preset types without collected theme tokens', () => {
		expect(
			preview( [
				paragraph( {
					style: {
						border: { radius: 'var:preset|border-radius|round' },
					},
				} ),
			] ).status
		).toBe( 'unchanged' );
		expect(
			preview( [ paragraph( { fontSize: 'gigantic' } ) ], undefined, {
				themeTokens: THEME_TOKENS,
			} ).status
		).toBe( 'unchanged' );
	} );

	test( 'checks core block preset attributes that are not block supports', () => {
		const result = preview(
			[
				{
					name: 'core/cover',
					attributes: {
						overlayColor: 'midnight',
						gradient: 'sunset',
					},
				},
			],
			{}
		);

		expect( result.status ).toBe( 'blocked' );
		expect( result.diagnostics ).toEqual( [
			expect.objectContaining( {
				attribute: 'overlayColor',
				presetType: 'color',
				value: 'midnight',
			} ),
			expect.objectContaining( {
				attribute: 'gradient',
				presetType: 'gradient',
				value: 'sunset',
			} ),
		] );
	} );

	test( 'leaves spacing presets to the spacing rule', () => {
		const result = preview( [
			{
				name: 'core/group',
				attributes: {
					style: {
						spacing: {
							padding: {
								top: 'var:preset|spacing|roomy',
								left: 'var:preset|spacing|50',
							},
						},
					},
				},
			},
		] );

		// 50 is remapped to 40; roomy stays unresolved and is reported once.
		expect( result.status ).toBe( 'ready' );
		expect( result.diagnostics ).toEqual( [
			{
				code: 'unmapped_spacing_preset',
				blockName: 'core/group',
				attribute: 'style.spacing.padding',
				value: 'roomy',
			},
		] );
	} );

	test( 'reports unresolved presets in inner blocks beside ready adjustments', () => {
		const result = preview(
			[
				{
					name: 'core/group',
					attributes: {},
					innerBlocks: [
						{ name: 'core/heading', attributes: { level: 5 } },
						paragraph( { fontSize: 'gigantic' } ),
					],
				},
			],
			undefined,
			{ adaptationContext: { ...BASE_CTX, precedingHeadingLevel: 2 } }
		);

		expect( result.status ).toBe( 'ready' );
		expect( result.blocks[ 0 ].innerBlocks[ 0 ].attributes.level ).toBe(
			3
		);
		expect( result.diagnostics ).toEqual( [
			expect.objectContaining( {
				code: 'unresolved_theme_preset',
				attribute: 'fontSize',
				value: 'gigantic',
			} ),
		] );
	} );
} );

describe( 'block-specific adaptation presets', () => {
	const settings = {
		__experimentalFeatures: {
			color: {
				palette: { theme: [ { slug: 'contrast', color: '#000000' } ] },
			},
			typography: {
				fontSizes: { theme: [ { slug: 'medium', size: '1rem' } ] },
			},
			spacing: {
				spacingSizes: { theme: [ { slug: '40', size: '2rem' } ] },
			},
			blocks: {
				'core/group': {
					color: {
						palette: {
							theme: [
								{
									slug: 'group-ink',
									name: 'Brand',
									color: '#123456',
								},
							],
						},
					},
					spacing: {
						spacingSizes: {
							theme: [ { slug: '30', size: '1rem' } ],
						},
					},
				},
				'core/paragraph': {
					typography: {
						fontSizes: {
							theme: [
								{ slug: 'paragraph-large', size: '2rem' },
							],
						},
					},
				},
			},
		},
	};

	function preview( sourceBlocks, editorSettings = settings ) {
		REGISTRY.getBlockType.mockReturnValue( {
			supports: {
				color: {},
				typography: { fontSize: true },
				spacing: { padding: true },
			},
		} );
		return run( {
			sourceBlocks,
			themeTokens: collectThemeTokensFromSettings( editorSettings ),
			getThemeTokensForBlock: ( blockName ) =>
				collectThemeTokensFromSettings( editorSettings, blockName ),
		} );
	}

	test( 'preserves scoped color and spacing and resolves inner blocks independently', () => {
		const sourceBlocks = [
			{
				name: 'core/group',
				attributes: {
					textColor: 'group-ink',
					style: {
						spacing: { padding: { top: 'var:preset|spacing|30' } },
					},
				},
				innerBlocks: [
					{
						name: 'core/paragraph',
						attributes: {
							fontSize: 'paragraph-large',
							textColor: 'contrast',
						},
					},
				],
			},
		];
		const result = preview( sourceBlocks );
		expect( result.status ).toBe( 'unchanged' );
		expect( result.diagnostics ).toEqual( [] );
		expect( result.blocks ).toEqual( sourceBlocks );
	} );

	test( "does not accept another block type's presets on a nested block", () => {
		const result = preview( [
			{
				name: 'core/group',
				attributes: {},
				innerBlocks: [
					{
						name: 'core/heading',
						attributes: { fontSize: 'paragraph-large' },
					},
				],
			},
		] );
		expect( result.status ).toBe( 'blocked' );
		expect( result.diagnostics ).toEqual( [
			expect.objectContaining( {
				blockName: 'core/heading',
				attribute: 'fontSize',
				value: 'paragraph-large',
			} ),
		] );
	} );

	test( 'uses scoped presets for color and spacing adjustments', () => {
		const result = preview( [
			{
				name: 'core/group',
				attributes: {
					textColor: 'primary',
					style: {
						spacing: { padding: { top: 'var:preset|spacing|25' } },
					},
				},
			},
		] );
		expect( result.status ).toBe( 'ready' );
		expect( result.diagnostics ).toEqual( [] );
		expect( result.blocks[ 0 ].attributes ).toEqual( {
			textColor: 'group-ink',
			style: { spacing: { padding: { top: 'var:preset|spacing|30' } } },
		} );
	} );

	test.each( [
		[
			'color',
			'palette',
			{ color: '#123456' },
			{ color: { text: 'var(--wp--preset--color--inherited)' } },
		],
		[
			'color',
			'gradients',
			{ gradient: 'linear-gradient(red, blue)' },
			{ color: { gradient: 'var:preset|gradient|inherited' } },
		],
		[
			'typography',
			'fontSizes',
			{ size: '2rem' },
			{
				typography: {
					fontSize: 'var(--wp--preset--font-size--inherited)',
				},
			},
		],
		[
			'typography',
			'fontFamilies',
			{ fontFamily: 'serif' },
			{ typography: { fontFamily: 'var:preset|font-family|inherited' } },
		],
		[
			'shadow',
			'presets',
			{ shadow: '0 1px 2px #000000' },
			{ shadow: 'var(--wp--preset--shadow--inherited)' },
		],
		[
			'spacing',
			'spacingSizes',
			{ size: '1rem' },
			{ spacing: { padding: { top: 'var:preset|spacing|inherited' } } },
		],
	] )(
		'preserves an ancestor %s.%s CSS preset through nested containers',
		( group, key, value, style ) => {
			const editorSettings = {
				features: {
					blocks: {
						'core/group': {
							[ group ]: {
								[ key ]: {
									theme: [ { slug: 'inherited', ...value } ],
								},
							},
						},
					},
				},
			};
			const sourceBlocks = [
				{
					name: 'core/group',
					attributes: {},
					innerBlocks: [
						{
							name: 'core/columns',
							attributes: {},
							innerBlocks: [
								{
									name: 'core/paragraph',
									attributes: { style },
								},
							],
						},
					],
				},
			];
			const result = preview( sourceBlocks, editorSettings );
			expect( result.status ).toBe( 'unchanged' );
			expect( result.diagnostics ).toEqual( [] );
			expect( result.blocks ).toEqual( sourceBlocks );
		}
	);

	test( 'does not remap a numeric spacing variable inherited from a container', () => {
		const sourceBlocks = [
			{
				name: 'core/group',
				attributes: {},
				innerBlocks: [
					{
						name: 'core/paragraph',
						attributes: {
							style: {
								spacing: {
									padding: { top: 'var:preset|spacing|30' },
								},
							},
						},
					},
				],
			},
		];
		const result = preview( sourceBlocks );
		expect( result.status ).toBe( 'unchanged' );
		expect( result.blocks ).toEqual( sourceBlocks );
	} );

	test( 'keeps inherited variables inside their ancestor branch', () => {
		const child = {
			name: 'core/paragraph',
			attributes: {
				style: {
					color: { text: 'var(--wp--preset--color--group-ink)' },
				},
			},
		};
		const result = preview( [
			{ name: 'core/group', attributes: {}, innerBlocks: [ child ] },
			child,
		] );
		expect( result.status ).toBe( 'blocked' );
		expect( result.diagnostics ).toEqual( [
			expect.objectContaining( {
				code: 'unresolved_theme_preset',
				attribute: 'style.color.text',
				value: 'group-ink',
			} ),
		] );
	} );

	test( 'does not inherit ancestor color control opt-outs', () => {
		const editorSettings = JSON.parse( JSON.stringify( settings ) );
		editorSettings.__experimentalFeatures.blocks[
			'core/group'
		].color.text = false;
		const result = preview(
			[
				{
					name: 'core/group',
					attributes: {},
					innerBlocks: [
						{
							name: 'core/paragraph',
							attributes: { textColor: 'foreground' },
						},
					],
				},
			],
			editorSettings
		);
		expect( result.status ).toBe( 'ready' );
		expect( result.blocks[ 0 ].innerBlocks[ 0 ].attributes.textColor ).toBe(
			'contrast'
		);
	} );

	test( 'does not inherit ancestor preset classes', () => {
		// Class-backed presets only exist on the block type that declares them.
		const invalidClass = preview( [
			{
				name: 'core/group',
				attributes: {},
				innerBlocks: [
					{
						name: 'core/paragraph',
						attributes: { textColor: 'group-ink' },
					},
				],
			},
		] );
		expect( invalidClass.status ).toBe( 'blocked' );
		expect( invalidClass.diagnostics[ 0 ].attribute ).toBe( 'textColor' );
	} );

	test( 'honors a block-specific theme color opt-out', () => {
		const disabled = JSON.parse( JSON.stringify( settings ) );
		disabled.__experimentalFeatures.blocks[
			'core/group'
		].color.text = false;
		const result = preview(
			[ { name: 'core/group', attributes: { textColor: 'foreground' } } ],
			disabled
		);
		expect( result.status ).toBe( 'blocked' );
		expect( result.reason ).toBe( 'unsupported_block_support' );
	} );
} );

describe( 'WordPress CSS preset identifiers', () => {
	test.each( [
		[ 'brandBlue', 'brand-blue' ],
		[ 'accent2', 'accent-2' ],
		[ 'BRANDInk', 'brand-ink' ],
		[ 'brand_ink', 'brand-ink' ],
	] )(
		'recognizes %s as the CSS preset %s without rewriting attributes',
		( slug, cssSlug ) => {
			const themeTokens = collectThemeTokensFromSettings( {
				features: {
					color: { palette: [ { slug, color: '#123456' } ] },
				},
			} );
			for ( const value of [
				`var(--wp--preset--color--${ cssSlug })`,
				`var:preset|color|${ slug }`,
				`var:preset|color|${ cssSlug }`,
			] ) {
				const sourceBlocks = [
					{
						name: 'core/paragraph',
						attributes: {
							textColor: slug,
							style: { color: { text: value } },
						},
					},
				];
				const result = run( { sourceBlocks, themeTokens } );
				expect( result.status ).toBe( 'unchanged' );
				expect( result.diagnostics ).toEqual( [] );
				expect( result.blocks ).toEqual( sourceBlocks );
			}
		}
	);

	test( 'does not normalize a literal CSS variable that the theme never defines', () => {
		const result = run( {
			themeTokens: collectThemeTokensFromSettings( {
				features: {
					color: {
						palette: [ { slug: 'brandBlue', color: '#123456' } ],
					},
				},
			} ),
			sourceBlocks: [
				{
					name: 'core/paragraph',
					attributes: {
						style: {
							color: {
								text: 'var(--wp--preset--color--brandBlue)',
							},
						},
					},
				},
			],
		} );
		expect( result.status ).toBe( 'blocked' );
		expect( result.diagnostics[ 0 ].value ).toBe( 'brandBlue' );
	} );
} );
