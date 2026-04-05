import { humanizeString } from '../format-count';

describe( 'format-count', () => {
	test( 'humanizeString preserves zero values', () => {
		expect( humanizeString( 0 ) ).toBe( '0' );
	} );
} );
