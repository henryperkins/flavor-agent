jest.mock( '@wordpress/data', () => ( {
	select: jest.fn(),
} ) );

jest.mock( '@wordpress/block-editor', () => ( {
	store: {},
} ) );

const { summarizeTokens } = require( '../theme-tokens' );

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
	} );
} );
