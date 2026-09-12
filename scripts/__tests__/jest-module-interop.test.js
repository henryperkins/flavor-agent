'use strict';

// Exercise the real dependency boundary used by block apply/undo tests: UUID's
// ESM source must load alongside Babel-transformed WordPress packages.
const { cloneBlock } = jest.requireActual( '@wordpress/blocks' );
const { v4: uuid, validate, version } = jest.requireActual( 'uuid' );

describe( 'Jest WordPress module interoperability', () => {
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
