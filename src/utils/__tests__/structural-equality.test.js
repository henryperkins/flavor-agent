import { deepStructuralEqual } from '../structural-equality';

describe( 'deepStructuralEqual', () => {
	test( 'returns false when only one value is null', () => {
		expect( deepStructuralEqual( null, {} ) ).toBe( false );
		expect( deepStructuralEqual( {}, null ) ).toBe( false );
	} );

	test( 'returns true when both values are null', () => {
		expect( deepStructuralEqual( null, null ) ).toBe( true );
	} );
} );
