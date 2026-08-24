jest.mock( '@wordpress/i18n', () =>
	require( '../../test-utils/i18n-mock' ).createI18nMock()
);

import { COUNT_NOUNS, formatCount, humanizeString } from '../format-count';

const i18n = require( '@wordpress/i18n' );

describe( 'format-count', () => {
	test( 'humanizeString preserves zero values', () => {
		expect( humanizeString( 0 ) ).toBe( '0' );
	} );

	test( 'pluralizes through _n rather than appending an "s"', () => {
		expect( formatCount( 1, 'suggestion' ) ).toBe( '1 suggestion' );
		expect( formatCount( 2, 'suggestion' ) ).toBe( '2 suggestions' );
		expect( formatCount( 0, 'suggestion' ) ).toBe( '0 suggestions' );

		expect( i18n._n ).toHaveBeenCalledWith(
			'%d suggestion',
			'%d suggestions',
			1,
			'flavor-agent'
		);
	} );

	test( 'covers every noun the UI passes', () => {
		for ( const noun of COUNT_NOUNS ) {
			expect( formatCount( 1, noun ) ).toContain( '1 ' );
			expect( formatCount( 3, noun ) ).toContain( '3 ' );
			expect( formatCount( 1, noun ) ).not.toContain( '%d' );
		}
	} );

	test( 'multi-word nouns keep their full phrase', () => {
		expect( formatCount( 1, 'template operation' ) ).toBe(
			'1 template operation'
		);
		expect( formatCount( 4, 'override-ready block' ) ).toBe(
			'4 override-ready blocks'
		);
	} );

	test( 'unusable inputs return an empty string', () => {
		expect( formatCount( null, 'suggestion' ) ).toBe( '' );
		expect( formatCount( -1, 'suggestion' ) ).toBe( '' );
		expect( formatCount( Number.NaN, 'suggestion' ) ).toBe( '' );
		expect( formatCount( 2, '' ) ).toBe( '' );
	} );

	test( 'an unregistered noun still renders a count instead of vanishing', () => {
		expect( formatCount( 2, 'widget' ) ).toBe( '2 widget' );
	} );
} );
