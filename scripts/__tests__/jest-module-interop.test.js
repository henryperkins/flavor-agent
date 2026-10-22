'use strict';

// Exercise the real ESM dependencies used by WordPress block and Markdown
// handling alongside Babel-transformed WordPress packages.
const { cloneBlock } = jest.requireActual( '@wordpress/blocks' );
const { ThemeProvider } = jest.requireActual( '@wordpress/theme' );
const { Marked } = jest.requireActual( 'marked' );
const { v4: uuid, validate, version } = jest.requireActual( 'uuid' );

describe( 'Jest WordPress module interoperability', () => {
	test( 'loads the real ThemeProvider from its published mjs entry', () => {
		expect( ThemeProvider ).toEqual( expect.any( Function ) );
	} );

	test( 'renders Markdown through the real Marked ESM implementation', () => {
		const parser = new Marked( { gfm: true } );

		expect(
			parser.parse( '# Heading\n\n**Bold** and `code`.\n', {
				async: false,
			} )
		).toBe(
			'<h1>Heading</h1>\n<p><strong>Bold</strong> and <code>code</code>.</p>\n'
		);
	} );

	test( 'loads the real UUID implementation with its random-byte options', () => {
		expect(
			uuid( {
				random: Uint8Array.from( [
					0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15,
				] ),
			} )
		).toBe( '00010203-0405-4607-8809-0a0b0c0d0e0f' );
	} );

	test( 'clones nested WordPress blocks with distinct valid UUIDs', () => {
		const cloned = cloneBlock( {
			clientId: 'original-parent',
			name: 'core/group',
			attributes: {},
			innerBlocks: [
				{
					clientId: 'original-child',
					name: 'core/paragraph',
					attributes: { content: 'Example' },
					innerBlocks: [],
				},
			],
		} );

		for ( const block of [ cloned, cloned.innerBlocks[ 0 ] ] ) {
			expect( validate( block.clientId ) ).toBe( true );
			expect( version( block.clientId ) ).toBe( 4 );
		}
		expect( cloned.clientId ).not.toBe( cloned.innerBlocks[ 0 ].clientId );
		expect( cloned.innerBlocks[ 0 ].attributes ).toEqual( {
			content: 'Example',
		} );
	} );
} );
