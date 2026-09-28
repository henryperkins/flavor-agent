jest.mock( '@wordpress/data', () => ( {
	select: jest.fn(),
} ) );

jest.mock( '@wordpress/block-editor', () => ( {
	store: {},
} ) );

const {
	buildBlockStyleExecutionContractFromSettings,
	buildGlobalStylesExecutionContractFromSettings,
	collectThemeTokensFromSettings,
	getBlockStyleSupportedStylePathsFromTokens,
	getGlobalStylesSupportedStylePathsFromTokens,
	summarizeTokens,
} = require( '../theme-tokens' );
const { getThemeTokenSourceDetails } = require( '../theme-settings' );

const COMPLETE_FEATURES = {
	color: {
		palette: {
			default: [
				{
					name: 'Base',
					slug: 'base',
					color: '#111111',
				},
			],
			theme: [
				{
					name: 'Accent',
					slug: 'accent',
					color: '#ff5500',
				},
			],
			custom: [
				{
					name: 'Brand',
					slug: 'brand',
					color: '#0055ff',
				},
			],
		},
		gradients: {
			theme: [
				{
					name: 'Sunset',
					slug: 'sunset',
					gradient: 'linear-gradient(#111111, #ffffff)',
				},
			],
		},
		duotone: {
			theme: [
				{
					name: 'Nightfall',
					slug: 'nightfall',
					colors: [ '#111111', '#f5f5f5' ],
				},
			],
		},
		custom: true,
		customGradient: true,
		defaultPalette: true,
		background: true,
		text: true,
		link: true,
		button: true,
		heading: true,
	},
	typography: {
		fontSizes: {
			theme: [
				{
					name: 'Body',
					slug: 'body',
					size: '1rem',
				},
			],
		},
		fontFamilies: {
			theme: [
				{
					name: 'Display',
					slug: 'display',
					fontFamily: 'Georgia, serif',
				},
			],
		},
		customFontSize: true,
		fontStyle: true,
		fontWeight: true,
		letterSpacing: true,
		lineHeight: true,
		textDecoration: true,
		textTransform: true,
		dropCap: false,
		fluid: true,
	},
	spacing: {
		spacingSizes: {
			theme: [
				{
					name: 'Small',
					slug: 's',
					size: '0.5rem',
				},
			],
		},
		units: [ 'px', 'rem' ],
		margin: true,
		padding: true,
		blockGap: true,
	},
	shadow: {
		presets: {
			theme: [
				{
					name: 'Soft',
					slug: 'soft',
					shadow: '0 10px 30px rgba(0,0,0,0.1)',
				},
			],
		},
		defaultPresets: true,
	},
	layout: {
		contentSize: '700px',
		wideSize: '1200px',
		allowEditing: true,
		allowCustomContentAndWideSize: false,
	},
	border: {
		style: true,
	},
	background: {
		backgroundImage: true,
		backgroundSize: true,
	},
	styles: {
		elements: {
			button: {
				color: {
					text: 'var(--wp--preset--color--accent)',
				},
				':hover': {
					color: {
						text: '#ffffff',
					},
				},
			},
		},
		blocks: {
			'core/button': {
				':hover': {
					color: {
						text: '#ffffff',
					},
				},
			},
		},
	},
};

describe( 'summarizeTokens', () => {
	test( 'includes compact duotone preset summaries keyed by slug', () => {
		const summary = summarizeTokens( {
			color: {
				palette: [
					{
						name: 'Accent',
						slug: 'accent',
						color: '#ff5500',
						cssVar: 'var(--wp--preset--color--accent)',
					},
				],
				gradients: [
					{
						name: 'Sunset',
						slug: 'sunset',
						gradient: 'linear-gradient(135deg,#f60,#fc0)',
						cssVar: 'var(--wp--preset--gradient--sunset)',
					},
				],
				duotone: [
					{
						slug: 'midnight',
						colors: [ '#111111', '#f5f5f5' ],
					},
					{
						slug: 'sepia',
						colors: [],
					},
				],
				customColors: true,
				linkEnabled: false,
			},
			background: {
				backgroundImage: true,
				backgroundSize: false,
			},
			typography: {
				fontSizes: [
					{
						name: 'Body',
						slug: 'body',
						size: '1rem',
						cssVar: 'var(--wp--preset--font-size--body)',
					},
				],
				fontFamilies: [
					{
						name: 'Display',
						slug: 'display',
						fontFamily: 'Georgia, serif',
						cssVar: 'var(--wp--preset--font-family--display)',
					},
				],
				fontStyle: true,
				fontWeight: true,
				letterSpacing: true,
				lineHeight: false,
				dropCap: true,
				textDecoration: true,
				textTransform: true,
				fluidTypography: false,
			},
			spacing: {
				spacingSizes: [
					{
						name: 'Small',
						slug: 's',
						size: '0.5rem',
						cssVar: 'var(--wp--preset--spacing--s)',
					},
				],
				margin: false,
				padding: false,
			},
			shadow: {
				presets: [
					{
						name: 'Soft',
						slug: 'soft',
						shadow: '0 10px 30px rgba(0,0,0,0.1)',
						cssVar: 'var(--wp--preset--shadow--soft)',
					},
				],
			},
			layout: {
				contentSize: '680px',
				wideSize: '1200px',
				allowEditing: false,
				allowCustomContentAndWideSize: true,
			},
			border: {
				color: false,
				radius: false,
				style: true,
				width: false,
			},
			elements: {
				button: {
					base: {
						text: 'var(--wp--preset--color--contrast)',
					},
				},
			},
			blockPseudoStyles: {},
		} );

		expect( summary.duotone ).toEqual( [
			'midnight: #111111 / #f5f5f5',
			'sepia',
		] );
		expect( summary.colorPresets ).toEqual( [
			{
				name: 'Accent',
				slug: 'accent',
				color: '#ff5500',
				cssVar: 'var(--wp--preset--color--accent)',
			},
		] );
		expect( summary.gradientPresets ).toEqual( [
			{
				name: 'Sunset',
				slug: 'sunset',
				gradient: 'linear-gradient(135deg,#f60,#fc0)',
				cssVar: 'var(--wp--preset--gradient--sunset)',
			},
		] );
		expect( summary.fontSizePresets ).toEqual( [
			{
				name: 'Body',
				slug: 'body',
				size: '1rem',
				fluidSize: null,
				cssVar: 'var(--wp--preset--font-size--body)',
			},
		] );
		expect( summary.fontFamilyPresets ).toEqual( [
			{
				name: 'Display',
				slug: 'display',
				fontFamily: 'Georgia, serif',
				cssVar: 'var(--wp--preset--font-family--display)',
			},
		] );
		expect( summary.spacingPresets ).toEqual( [
			{
				name: 'Small',
				slug: 's',
				size: '0.5rem',
				cssVar: 'var(--wp--preset--spacing--s)',
			},
		] );
		expect( summary.shadowPresets ).toEqual( [
			{
				name: 'Soft',
				slug: 'soft',
				shadow: '0 10px 30px rgba(0,0,0,0.1)',
				cssVar: 'var(--wp--preset--shadow--soft)',
			},
		] );
		expect( summary.duotonePresets ).toEqual( [
			{
				slug: 'midnight',
				colors: [ '#111111', '#f5f5f5' ],
			},
			{
				slug: 'sepia',
				colors: [],
			},
		] );
		expect( summary.layout ).toEqual( {
			content: '680px',
			wide: '1200px',
			allowEditing: false,
			allowCustomContentAndWideSize: true,
		} );
		expect( summary.enabledFeatures ).toEqual(
			expect.objectContaining( {
				backgroundImage: true,
				backgroundSize: false,
				borderStyle: true,
				fontStyle: true,
				fontWeight: true,
				letterSpacing: true,
				textDecoration: true,
				textTransform: true,
			} )
		);
		expect( summary.elementStyles ).toEqual( {
			button: {
				base: {
					text: 'var(--wp--preset--color--contrast)',
				},
			},
		} );
		expect( summary.diagnostics ).toEqual( {
			source: 'unknown',
			settingsKey: '',
			reason: 'unknown',
		} );
	} );
} );

describe( 'block-specific theme tokens', () => {
	test.each( [
		[ 'color', 'palette', 'palette', { color: '#123456' } ],
		[
			'color',
			'gradients',
			'gradients',
			{ gradient: 'linear-gradient(red, blue)' },
		],
		[ 'color', 'duotone', 'duotone', { colors: [ '#000000', '#ffffff' ] } ],
		[ 'typography', 'fontSizes', 'fontSizes', { size: '2rem' } ],
		[
			'typography',
			'fontFamilies',
			'fontFamilies',
			{ fontFamily: 'serif' },
		],
		[ 'spacing', 'spacingSizes', 'spacingSizes', { size: '2rem' } ],
		[ 'shadow', 'presets', 'presets', { shadow: '0 1px 2px #000000' } ],
	] )(
		'inherits global %s.%s presets and gives the block precedence across origins',
		( group, feature, token, value ) => {
			const settings = {
				__experimentalFeatures: {
					[ group ]: {
						[ feature ]: {
							default: [ { slug: 'inherited', ...value } ],
							custom: [
								{ slug: 'shared', name: 'Global', ...value },
							],
						},
					},
					blocks: {
						'core/paragraph': {
							[ group ]: {
								[ feature ]: {
									default: [
										{
											slug: 'shared',
											name: 'Block default',
											...value,
										},
									],
									theme: [ { slug: 'local', ...value } ],
									custom: [
										{
											slug: 'shared',
											name: 'Block custom',
											...value,
										},
									],
								},
							},
						},
					},
				},
			};
			const before = JSON.stringify( settings );
			const tokens = collectThemeTokensFromSettings(
				settings,
				'core/paragraph'
			);

			expect(
				tokens[ group ][ token ].map( ( preset ) => preset.slug )
			).toEqual( [ 'inherited', 'shared', 'local' ] );
			expect( tokens[ group ][ token ][ 1 ].name ).toBe( 'Block custom' );
			const withoutBlockCustom = JSON.parse( JSON.stringify( settings ) );
			delete withoutBlockCustom.__experimentalFeatures.blocks[
				'core/paragraph'
			][ group ][ feature ].custom;
			expect(
				collectThemeTokensFromSettings(
					withoutBlockCustom,
					'core/paragraph'
				)[ group ][ token ][ 1 ].name
			).toBe( 'Block default' );
			expect(
				collectThemeTokensFromSettings( settings, 'core/heading' )[
					group
				][ token ].map( ( preset ) => preset.slug )
			).toEqual( [ 'inherited', 'shared' ] );
			expect(
				collectThemeTokensFromSettings( settings )[ group ][
					token
				][ 1 ].name
			).toBe( 'Global' );
			expect( JSON.stringify( settings ) ).toBe( before );
		}
	);

	test( 'keeps inherited presets with flat or empty block collections and honors block opt-outs', () => {
		const settings = {
			features: {
				color: {
					text: true,
					palette: [ { slug: 'global', color: '#000000' } ],
				},
				typography: { fontSizes: [ { slug: 'medium', size: '1rem' } ] },
				blocks: {
					'core/paragraph': {
						color: { text: false, palette: [] },
						typography: {
							fontSizes: [ { slug: 'local', size: '2rem' } ],
						},
					},
				},
			},
		};
		const tokens = collectThemeTokensFromSettings(
			settings,
			'core/paragraph'
		);

		expect( tokens.color.textEnabled ).toBe( false );
		expect( tokens.color.palette.map( ( preset ) => preset.slug ) ).toEqual(
			[ 'global' ]
		);
		expect(
			tokens.typography.fontSizes.map( ( preset ) => preset.slug )
		).toEqual( [ 'medium', 'local' ] );
	} );

	test( 'fills experimental block settings when the stable source only has global parity', () => {
		const settings = {
			features: {
				typography: {
					fontSizes: { theme: [ { slug: 'medium', size: '1rem' } ] },
				},
			},
			__experimentalFeatures: {
				typography: {
					fontSizes: { theme: [ { slug: 'medium', size: '1rem' } ] },
				},
				blocks: {
					'core/paragraph': {
						typography: {
							fontSizes: {
								theme: [ { slug: 'local', size: '2rem' } ],
							},
						},
					},
				},
			},
		};

		expect(
			collectThemeTokensFromSettings(
				settings,
				'core/paragraph'
			).typography.fontSizes.map( ( preset ) => preset.slug )
		).toEqual( [ 'medium', 'local' ] );
	} );
} );

describe( 'global styles execution contract', () => {
	test( 'derives supported style paths from live color feature gates', () => {
		const contract = buildGlobalStylesExecutionContractFromSettings( {
			features: COMPLETE_FEATURES,
		} );

		expect( contract.supportedStylePaths ).toEqual(
			expect.arrayContaining( [
				{
					path: [ 'color', 'background' ],
					valueSource: 'color',
				},
				{
					path: [ 'color', 'text' ],
					valueSource: 'color',
				},
				{
					path: [ 'elements', 'button', 'color', 'background' ],
					valueSource: 'color',
				},
				{
					path: [ 'elements', 'button', 'color', 'text' ],
					valueSource: 'color',
				},
				{
					path: [ 'elements', 'heading', 'color', 'text' ],
					valueSource: 'color',
				},
			] )
		);
		expect( contract.presetSlugs ).toEqual(
			expect.objectContaining( {
				color: [ 'accent', 'base', 'brand' ],
			} )
		);
	} );

	test( 'omits disabled color controls from the executable path contract', () => {
		const tokens = collectThemeTokensFromSettings( {
			features: {
				...COMPLETE_FEATURES,
				color: {
					...COMPLETE_FEATURES.color,
					background: false,
					text: false,
					link: false,
					button: false,
					heading: false,
				},
			},
		} );

		expect(
			getGlobalStylesSupportedStylePathsFromTokens( tokens )
		).toEqual(
			expect.not.arrayContaining( [
				{
					path: [ 'color', 'background' ],
					valueSource: 'color',
				},
				{
					path: [ 'color', 'text' ],
					valueSource: 'color',
				},
				{
					path: [ 'elements', 'link', 'color', 'text' ],
					valueSource: 'color',
				},
				{
					path: [ 'elements', 'button', 'color', 'background' ],
					valueSource: 'color',
				},
				{
					path: [ 'elements', 'button', 'color', 'text' ],
					valueSource: 'color',
				},
				{
					path: [ 'elements', 'heading', 'color', 'text' ],
					valueSource: 'color',
				},
			] )
		);
	} );

	describe( 'typography.textShadow gate', () => {
		const TEXT_SHADOW_ENTRY = {
			path: [ 'typography', 'textShadow' ],
			valueSource: 'freeform',
			validation: 'text-shadow',
		};

		afterEach( () => {
			delete window.flavorAgentData;
		} );

		test( 'omits textShadow when the server reports it unsupported', () => {
			const tokens = collectThemeTokensFromSettings( {
				features: COMPLETE_FEATURES,
			} );

			expect(
				getGlobalStylesSupportedStylePathsFromTokens( tokens, {
					typographyTextShadow: false,
				} )
			).toEqual( expect.not.arrayContaining( [ TEXT_SHADOW_ENTRY ] ) );
		} );

		test( 'includes textShadow when the server reports it supported', () => {
			const tokens = collectThemeTokensFromSettings( {
				features: COMPLETE_FEATURES,
			} );

			expect(
				getGlobalStylesSupportedStylePathsFromTokens( tokens, {
					typographyTextShadow: true,
				} )
			).toEqual( expect.arrayContaining( [ TEXT_SHADOW_ENTRY ] ) );
		} );

		test( 'falls back to the localized capability when none is passed', () => {
			window.flavorAgentData = {
				capabilities: { styles: { typographyTextShadow: true } },
			};

			const tokens = collectThemeTokensFromSettings( {
				features: COMPLETE_FEATURES,
			} );

			expect(
				getGlobalStylesSupportedStylePathsFromTokens( tokens )
			).toEqual( expect.arrayContaining( [ TEXT_SHADOW_ENTRY ] ) );
		} );

		test( 'omits textShadow when no capability is localized at all', () => {
			const tokens = collectThemeTokensFromSettings( {
				features: COMPLETE_FEATURES,
			} );

			// Deny-by-default: an older server that ships no styles capability
			// must not have the client offering a path it will then refuse.
			expect(
				getGlobalStylesSupportedStylePathsFromTokens( tokens )
			).toEqual( expect.not.arrayContaining( [ TEXT_SHADOW_ENTRY ] ) );
		} );
	} );
} );

describe( 'block style execution contract', () => {
	test.each( [ 'background', 'text' ] )(
		'resolves the target block theme %s control without affecting other scopes',
		( facet ) => {
			const settings = {
				features: {
					color: {
						palette: [ { slug: 'global', color: '#111111' } ],
					},
					blocks: {
						'core/paragraph': { color: { [ facet ]: false } },
					},
				},
			};
			const blockType = {
				name: 'core/paragraph',
				supports: { color: {} },
			};
			const entry = { path: [ 'color', facet ], valueSource: 'color' };
			expect(
				buildBlockStyleExecutionContractFromSettings(
					settings,
					blockType
				).supportedStylePaths
			).not.toContainEqual( entry );
			expect(
				buildBlockStyleExecutionContractFromSettings( settings, {
					...blockType,
					name: 'core/heading',
				} ).supportedStylePaths
			).toContainEqual( entry );
			expect(
				buildGlobalStylesExecutionContractFromSettings( settings )
					.supportedStylePaths
			).toContainEqual( entry );
		}
	);

	test( 'allows a block color opt-in and its presets while retaining inherited presets', () => {
		const settings = {
			features: {
				color: {
					text: false,
					background: false,
					palette: {
						custom: [ { slug: 'global', color: '#111111' } ],
					},
				},
				blocks: {
					'core/paragraph': {
						color: {
							text: true,
							palette: {
								theme: [ { slug: 'local', color: '#ffffff' } ],
							},
						},
					},
				},
			},
		};
		const contract = buildBlockStyleExecutionContractFromSettings(
			settings,
			{
				name: 'core/paragraph',
				supports: { color: {} },
			}
		);
		expect( contract.supportedStylePaths ).toEqual( [
			{ path: [ 'color', 'text' ], valueSource: 'color' },
		] );
		expect( contract.presetSlugs.color ).toEqual( [ 'global', 'local' ] );
		expect(
			buildBlockStyleExecutionContractFromSettings( settings, {
				name: 'core/heading',
				supports: { color: {} },
			} ).presetSlugs.color
		).toEqual( [ 'global' ] );
	} );

	test( 'derives supported block style paths from theme tokens and block supports', () => {
		const contract = buildBlockStyleExecutionContractFromSettings(
			{
				features: COMPLETE_FEATURES,
			},
			{
				supports: {
					color: {
						background: true,
						text: true,
					},
					typography: {
						fontSize: true,
						fontFamily: true,
						lineHeight: true,
					},
					spacing: {
						blockGap: true,
					},
					border: {
						color: true,
						radius: true,
						style: true,
						width: true,
					},
					shadow: true,
					customCSS: true,
					background: {
						backgroundImage: true,
					},
				},
			}
		);

		expect( contract.supportedStylePaths ).toEqual(
			expect.arrayContaining( [
				{
					path: [ 'color', 'background' ],
					valueSource: 'color',
				},
				{
					path: [ 'color', 'text' ],
					valueSource: 'color',
				},
				{
					path: [ 'typography', 'fontSize' ],
					valueSource: 'font-size',
				},
				{
					path: [ 'typography', 'fontFamily' ],
					valueSource: 'font-family',
				},
				{
					path: [ 'spacing', 'blockGap' ],
					valueSource: 'spacing',
				},
				{
					path: [ 'shadow' ],
					valueSource: 'shadow',
				},
			] )
		);
		expect( contract.supportedStylePaths ).toEqual(
			expect.not.arrayContaining( [
				{
					path: [ 'customCSS' ],
					valueSource: 'freeform',
				},
				{
					path: [ 'background', 'backgroundImage' ],
					valueSource: 'freeform',
				},
			] )
		);
	} );

	test.each( [
		[ 'omitted', {}, [] ],
		[ 'disabled', { color: false }, [] ],
		[ 'null', { color: null }, [] ],
		[ 'boolean', { color: true }, [ 'background', 'text' ] ],
		[ 'empty object', { color: {} }, [ 'background', 'text' ] ],
		[
			'paragraph defaults',
			{
				color: {
					gradients: true,
					link: true,
					__experimentalDefaultControls: {
						background: true,
						text: true,
					},
				},
			},
			[ 'background', 'text' ],
		],
		[
			'explicit text',
			{ color: { text: true } },
			[ 'background', 'text' ],
		],
		[ 'background opt-out', { color: { background: false } }, [ 'text' ] ],
		[ 'text opt-out', { color: { text: false } }, [ 'background' ] ],
		[ 'both opt-outs', { color: { background: false, text: false } }, [] ],
	] )(
		'resolves %s block color support using WordPress defaults',
		( label, supports, facets ) => {
			const tokens = collectThemeTokensFromSettings( {
				features: COMPLETE_FEATURES,
			} );

			expect(
				getBlockStyleSupportedStylePathsFromTokens( tokens, supports )
			).toEqual(
				facets.map( ( facet ) => ( {
					path: [ 'color', facet ],
					valueSource: 'color',
				} ) )
			);
		}
	);

	test.each( [ 'background', 'text' ] )(
		'keeps the theme %s opt-out with default block color support',
		( facet ) => {
			const contract = buildBlockStyleExecutionContractFromSettings(
				{
					features: {
						...COMPLETE_FEATURES,
						color: { ...COMPLETE_FEATURES.color, [ facet ]: false },
					},
				},
				{ supports: { color: {} } }
			);

			expect( contract.supportedStylePaths ).toEqual( [
				{
					path: [ 'color', facet === 'text' ? 'background' : 'text' ],
					valueSource: 'color',
				},
			] );
		}
	);

	test( 'requires palette presets even with default block color support', () => {
		const contract = buildBlockStyleExecutionContractFromSettings(
			{ features: { color: { background: true, text: true } } },
			{ supports: { color: {} } }
		);

		expect( contract.supportedStylePaths ).toEqual( [] );
	} );
} );

describe( 'theme token source adapter', () => {
	test( 'keeps stable features active and only uses experimental gaps when parity is not proven', () => {
		const settings = {
			features: {
				color: {
					palette: {
						theme: [
							{
								name: 'Accent',
								slug: 'accent',
								color: '#000000',
							},
						],
					},
				},
			},
			__experimentalFeatures: COMPLETE_FEATURES,
			layout: {
				contentSize: '680px',
				wideSize: '1140px',
			},
		};

		expect( getThemeTokenSourceDetails( settings ) ).toEqual(
			expect.objectContaining( {
				source: 'stable-fallback',
				settingsKey: 'features',
				reason: 'stable-with-experimental-gaps',
			} )
		);
		expect(
			collectThemeTokensFromSettings( settings ).color.palette
		).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					slug: 'accent',
					color: '#000000',
				} ),
			] )
		);
	} );

	test( 'uses stable features only when parity with the experimental source is proven', () => {
		const settings = {
			features: COMPLETE_FEATURES,
			__experimentalFeatures: COMPLETE_FEATURES,
		};

		expect( getThemeTokenSourceDetails( settings ) ).toEqual(
			expect.objectContaining( {
				source: 'stable',
				settingsKey: 'features',
				reason: 'stable-parity',
			} )
		);
		expect(
			collectThemeTokensFromSettings( settings ).typography.fontFamilies
		).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					slug: 'display',
				} ),
			] )
		);
	} );

	test( 'preserves stable capability values when experimental data only fills missing gaps', () => {
		const settings = {
			features: {
				...COMPLETE_FEATURES,
				color: {
					...COMPLETE_FEATURES.color,
					link: false,
				},
				spacing: {
					...COMPLETE_FEATURES.spacing,
					units: [ 'px' ],
				},
			},
			__experimentalFeatures: COMPLETE_FEATURES,
		};

		expect( getThemeTokenSourceDetails( settings ) ).toEqual(
			expect.objectContaining( {
				source: 'stable-fallback',
				settingsKey: 'features',
				reason: 'stable-with-experimental-gaps',
			} )
		);
		expect( collectThemeTokensFromSettings( settings ) ).toEqual(
			expect.objectContaining( {
				color: expect.objectContaining( {
					linkEnabled: false,
				} ),
				spacing: expect.objectContaining( {
					units: [ 'px' ],
				} ),
			} )
		);
	} );

	test( 'reports a stable fallback when only stable settings exist', () => {
		const settings = {
			features: COMPLETE_FEATURES,
		};

		expect( getThemeTokenSourceDetails( settings ) ).toEqual(
			expect.objectContaining( {
				source: 'stable-fallback',
				settingsKey: 'features',
				reason: 'stable-unverified',
			} )
		);
		expect(
			collectThemeTokensFromSettings( settings ).shadow.presets
		).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					slug: 'soft',
				} ),
			] )
		);
	} );

	test( 'preserves origin-separated presets, layout fallback, element styles, and block pseudo styles', () => {
		const settings = {
			layout: {
				contentSize: '680px',
				wideSize: '1140px',
			},
			__experimentalFeatures: {
				...COMPLETE_FEATURES,
				layout: undefined,
			},
		};

		const tokens = collectThemeTokensFromSettings( settings );

		expect( getThemeTokenSourceDetails( settings ) ).toEqual(
			expect.objectContaining( {
				source: 'experimental',
				reason: 'experimental-only',
			} )
		);
		expect( tokens.color.palette ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					slug: 'base',
					color: '#111111',
				} ),
				expect.objectContaining( {
					slug: 'accent',
					color: '#ff5500',
				} ),
				expect.objectContaining( {
					slug: 'brand',
					color: '#0055ff',
				} ),
			] )
		);
		expect( tokens.layout ).toEqual(
			expect.objectContaining( {
				contentSize: '680px',
				wideSize: '1140px',
			} )
		);
		expect( tokens.elements ).toEqual(
			expect.objectContaining( {
				button: expect.objectContaining( {
					base: {
						text: 'var(--wp--preset--color--accent)',
					},
				} ),
			} )
		);
		expect( tokens.blockPseudoStyles ).toEqual( {
			'core/button': {
				':hover': {
					color: {
						text: '#ffffff',
					},
				},
			},
		} );
	} );

	test( 'degrades safely when no token settings are present', () => {
		expect( getThemeTokenSourceDetails( {} ) ).toEqual(
			expect.objectContaining( {
				source: 'none',
				reason: 'missing',
			} )
		);
		expect( collectThemeTokensFromSettings( {} ) ).toEqual( {
			color: expect.objectContaining( {
				palette: [],
				gradients: [],
				duotone: [],
			} ),
			typography: expect.objectContaining( {
				fontSizes: [],
				fontFamilies: [],
			} ),
			spacing: expect.objectContaining( {
				spacingSizes: [],
			} ),
			layout: expect.objectContaining( {
				contentSize: '',
				wideSize: '',
			} ),
			shadow: expect.objectContaining( {
				presets: [],
			} ),
			border: expect.any( Object ),
			background: expect.any( Object ),
			elements: {},
			blockPseudoStyles: {},
			diagnostics: {
				source: 'none',
				settingsKey: '',
				reason: 'missing',
			},
		} );
	} );

	test( 'collectThemeTokensFromSettings includes source diagnostics for downstream contracts', () => {
		const settings = {
			features: COMPLETE_FEATURES,
		};

		expect(
			collectThemeTokensFromSettings( settings ).diagnostics
		).toEqual( {
			source: 'stable-fallback',
			settingsKey: 'features',
			reason: 'stable-unverified',
		} );
	} );
} );
