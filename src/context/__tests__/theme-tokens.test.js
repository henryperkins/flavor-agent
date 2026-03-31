jest.mock( '@wordpress/data', () => ( {
	select: jest.fn(),
} ) );

jest.mock( '@wordpress/block-editor', () => ( {
	store: {},
} ) );

const {
	collectThemeTokensFromSettings,
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
		lineHeight: true,
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
				palette: [],
				gradients: [],
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
				fontSizes: [],
				fontFamilies: [],
				lineHeight: false,
				dropCap: true,
				fluidTypography: false,
			},
			spacing: {
				spacingSizes: [],
				margin: false,
				padding: false,
			},
			shadow: {
				presets: [],
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
