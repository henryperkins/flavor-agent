const {
	FREEFORM_STYLE_VALIDATORS,
	displayPresetType,
	normalizePresetType,
	sanitizeStyleKey,
	validateCssCustomPropertyReference,
	validateFreeformStyleValueByKind,
} = require( '../style-validation' );

describe( 'style-validation', () => {
	test.each( [
		[ 'trims preset labels', '  Font Family  ', 'fontfamily' ],
		[ 'preserves hyphenated keys', 'font-size', 'font-size' ],
	] )( '%s', ( _, value, expected ) => {
		expect( sanitizeStyleKey( value ) ).toBe( expected );
	} );

	test.each( [
		[ 'font family', 'fontfamily' ],
		[ 'font-size', 'fontsize' ],
		[ 'font-family', 'fontfamily' ],
	] )( 'normalizePresetType(%s)', ( value, expected ) => {
		expect( normalizePresetType( value ) ).toBe( expected );
	} );

	test.each( [
		[ 'fontfamily', 'font-family' ],
		[ 'font-size', 'font-size' ],
		[ 'fontfamily ', 'font-family' ],
	] )( 'displayPresetType(%s)', ( value, expected ) => {
		expect( displayPresetType( value ) ).toBe( expected );
	} );

	test.each( [
		[
			'accepts a trimmed custom property reference',
			'var(--brand-color)',
			{ valid: true, value: 'var(--brand-color)' },
		],
		[
			'accepts a spaced custom property reference',
			'  var(--wp--preset--spacing--40)  ',
			{ valid: true, value: 'var(--wp--preset--spacing--40)' },
		],
		[
			'rejects fallback syntax',
			'var(--brand-color, red)',
			{ valid: false, value: null },
		],
		[
			'rejects plain tokens',
			'brand-color',
			{ valid: false, value: null },
		],
		[
			'rejects non-strings',
			42,
			{ valid: false, value: null },
		],
	] )( 'validateCssCustomPropertyReference: %s', ( _, value, expected ) => {
		expect( validateCssCustomPropertyReference( value ) ).toEqual( expected );
	} );

	test.each( [
		[
			'accepts trimmed hex colors',
			FREEFORM_STYLE_VALIDATORS.COLOR,
			'  #abc  ',
			{ valid: true, value: '#abc' },
		],
		[
			'accepts custom property colors',
			FREEFORM_STYLE_VALIDATORS.COLOR,
			'var(--brand-color)',
			{ valid: true, value: 'var(--brand-color)' },
		],
		[
			'rejects injected color payloads',
			FREEFORM_STYLE_VALIDATORS.COLOR,
			'red; background:url(javascript:alert(1))',
			{ valid: false, value: null },
		],
		[
			'rejects malformed color functions',
			FREEFORM_STYLE_VALIDATORS.COLOR,
			'url(javascript:alert(1))',
			{ valid: false, value: null },
		],
		[
			'accepts zero lengths',
			FREEFORM_STYLE_VALIDATORS.LENGTH,
			'0',
			{ valid: true, value: '0' },
		],
		[
			'accepts positive lengths',
			FREEFORM_STYLE_VALIDATORS.LENGTH,
			'12px',
			{ valid: true, value: '12px' },
		],
		[
			'rejects negative lengths',
			FREEFORM_STYLE_VALIDATORS.LENGTH,
			'-1px',
			{ valid: false, value: null },
		],
		[
			'rejects calc lengths',
			FREEFORM_STYLE_VALIDATORS.LENGTH,
			'calc(100% - 1rem)',
			{ valid: false, value: null },
		],
		[
			'accepts custom property lengths',
			FREEFORM_STYLE_VALIDATORS.LENGTH_OR_PERCENTAGE,
			'  var(--spacing-unit)  ',
			{ valid: true, value: 'var(--spacing-unit)' },
		],
		[
			'accepts percentages',
			FREEFORM_STYLE_VALIDATORS.LENGTH_OR_PERCENTAGE,
			'50%',
			{ valid: true, value: '50%' },
		],
		[
			'rejects unitless non-zero lengths',
			FREEFORM_STYLE_VALIDATORS.LENGTH_OR_PERCENTAGE,
			'20',
			{ valid: false, value: null },
		],
		[
			'accepts numeric line heights',
			FREEFORM_STYLE_VALIDATORS.LINE_HEIGHT,
			1.4,
			{ valid: true, value: 1.4 },
		],
		[
			'accepts percentage line heights',
			FREEFORM_STYLE_VALIDATORS.LINE_HEIGHT,
			'120%',
			{ valid: true, value: '120%' },
		],
		[
			'rejects zero line heights',
			FREEFORM_STYLE_VALIDATORS.LINE_HEIGHT,
			'0',
			{ valid: false, value: null },
		],
		[
			'rejects calc line heights',
			FREEFORM_STYLE_VALIDATORS.LINE_HEIGHT,
			'calc(1 + 1)',
			{ valid: false, value: null },
		],
		[
			'accepts numeric font weights',
			FREEFORM_STYLE_VALIDATORS.FONT_WEIGHT,
			700,
			{ valid: true, value: 700 },
		],
		[
			'accepts named font weights',
			FREEFORM_STYLE_VALIDATORS.FONT_WEIGHT,
			'Bold',
			{ valid: true, value: 'bold' },
		],
		[
			'rejects oversized font weights',
			FREEFORM_STYLE_VALIDATORS.FONT_WEIGHT,
			'9500',
			{ valid: false, value: null },
		],
		[
			'accepts font styles',
			FREEFORM_STYLE_VALIDATORS.FONT_STYLE,
			'Italic',
			{ valid: true, value: 'italic' },
		],
		[
			'accepts shadow strings',
			FREEFORM_STYLE_VALIDATORS.SHADOW,
			'0 1px 2px rgba(0, 0, 0, 0.25)',
			{ valid: true, value: '0 1px 2px rgba(0, 0, 0, 0.25)' },
		],
		[
			'rejects injected shadow payloads',
			FREEFORM_STYLE_VALIDATORS.SHADOW,
			'0 0 0 red; background:red',
			{ valid: false, value: null },
		],
		[
			'accepts text-decoration tokens',
			FREEFORM_STYLE_VALIDATORS.TEXT_DECORATION,
			'Underline Overline',
			{ valid: true, value: 'underline overline' },
		],
		[
			'rejects duplicate text-decoration tokens',
			FREEFORM_STYLE_VALIDATORS.TEXT_DECORATION,
			'underline underline',
			{ valid: false, value: null },
		],
		[
			'accepts text transforms',
			FREEFORM_STYLE_VALIDATORS.TEXT_TRANSFORM,
			'Uppercase',
			{ valid: true, value: 'uppercase' },
		],
		[
			'rejects injected text transforms',
			FREEFORM_STYLE_VALIDATORS.TEXT_TRANSFORM,
			'capitalize; background:red',
			{ valid: false, value: null },
		],
		[
			'accepts border styles',
			FREEFORM_STYLE_VALIDATORS.BORDER_STYLE,
			'Dashed',
			{ valid: true, value: 'dashed' },
		],
		[
			'rejects invalid border style combinations',
			FREEFORM_STYLE_VALIDATORS.BORDER_STYLE,
			'solid groove',
			{ valid: false, value: null },
		],
		[
			'accepts font families with commas',
			FREEFORM_STYLE_VALIDATORS.FONT_FAMILY,
			'Inter, sans-serif',
			{ valid: true, value: 'Inter, sans-serif' },
		],
		[
			'rejects injected font families',
			FREEFORM_STYLE_VALIDATORS.FONT_FAMILY,
			'Inter; font-weight: 900',
			{ valid: false, value: null },
		],
	] )(
		'validateFreeformStyleValueByKind: %s',
		( _, kind, value, expected ) => {
			expect( validateFreeformStyleValueByKind( kind, value ) ).toEqual(
				expected
			);
		}
	);

	test( 'rejects unsupported validator kinds with a clear error', () => {
		expect(
			validateFreeformStyleValueByKind( 'missing-validator', '12px' )
		).toEqual( {
			valid: false,
			value: null,
			error:
				'Unsupported freeform Global Styles validator: missing-validator.',
		} );
	} );
} );
