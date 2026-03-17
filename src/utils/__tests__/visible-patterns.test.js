import { extractPatternNames } from '../pattern-names';

describe( 'visible-patterns', () => {
	test( 'extractPatternNames returns unique pattern names in order', () => {
		expect(
			extractPatternNames( [
				{ name: 'theme/header' },
				{ name: 'theme/footer' },
				{ name: 'theme/header' },
			] )
		).toEqual( [ 'theme/header', 'theme/footer' ] );
	} );

	test( 'extractPatternNames ignores missing names and non-arrays', () => {
		expect(
			extractPatternNames( [
				{ name: 'theme/hero' },
				{},
				null,
				{ name: '' },
			] )
		).toEqual( [ 'theme/hero' ] );
		expect( extractPatternNames( null ) ).toEqual( [] );
	} );
} );
