import { normalizeSuggestionPreviewColor } from '../suggestion-preview-color';

describe( 'normalizeSuggestionPreviewColor', () => {
	const valid = new Map( [
		[ '#ABC', '#abc' ],
		[ ' #AbC8 ', '#abc8' ],
		[ '#AABBCC', '#aabbcc' ],
		[ '#AABBCCDD', '#aabbccdd' ],
	] );
	const invalid = [
		null,
		undefined,
		'',
		'url(https://preview-probe.invalid/pixel)',
		'linear-gradient(#fff, #000)',
		'red',
		'var(--token)',
		'#12',
		'#12345',
		'#123456789',
		'#ggg',
		'#fff; background: url(https://preview-probe.invalid/pixel)',
	];

	test.each( [ ...valid ] )(
		'canonicalizes valid hex preview %s',
		( value, expected ) => {
			expect( normalizeSuggestionPreviewColor( value ) ).toBe( expected );
		}
	);

	test.each( invalid )( 'rejects invalid preview %p', ( value ) => {
		expect( normalizeSuggestionPreviewColor( value ) ).toBeNull();
	} );
} );
