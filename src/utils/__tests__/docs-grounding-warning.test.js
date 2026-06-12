import {
	getDocsGroundingWarningMessage,
	deriveDocsGroundingWarning,
} from '../docs-grounding-warning';

describe( 'deriveDocsGroundingWarning', () => {
	test( 'returns null when grounding is available', () => {
		expect(
			deriveDocsGroundingWarning( {
				available: true,
				sourceTypes: [ 'developer-docs' ],
				count: 2,
			} )
		).toBeNull();
	} );

	test( 'returns null when grounding is absent from the payload', () => {
		expect( deriveDocsGroundingWarning( null ) ).toBeNull();
		expect( deriveDocsGroundingWarning( undefined ) ).toBeNull();
		expect( deriveDocsGroundingWarning( {} ) ).toBeNull();
	} );

	test( 'returns a single soft notice when grounding is unavailable', () => {
		const warning = deriveDocsGroundingWarning( {
			available: false,
			sourceTypes: [],
			count: 0,
		} );

		expect( warning ).not.toBeNull();
		expect( warning.tone ).toBe( 'info' );
		expect( warning.message ).toContain(
			'running without developer-docs grounding'
		);
	} );
} );

describe( 'getDocsGroundingWarningMessage', () => {
	test( 'returns the warning message verbatim', () => {
		const warning = deriveDocsGroundingWarning( { available: false } );

		expect( getDocsGroundingWarningMessage( warning ) ).toBe(
			warning.message
		);
	} );

	test( 'returns an empty string for null or malformed warnings', () => {
		expect( getDocsGroundingWarningMessage( null ) ).toBe( '' );
		expect( getDocsGroundingWarningMessage( 'nope' ) ).toBe( '' );
		expect( getDocsGroundingWarningMessage( {} ) ).toBe( '' );
	} );
} );
