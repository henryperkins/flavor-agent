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
			},
			border: {
				color: false,
				radius: false,
			},
			blockPseudoStyles: {},
		} );

		expect( summary.duotone ).toEqual( [
			'midnight: #111111 / #f5f5f5',
			'sepia',
		] );
	} );
} );
