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
		[ 'rejects non-strings', 42, { valid: false, value: null } ],
	] )( 'validateCssCustomPropertyReference: %s', ( _, value, expected ) => {
		expect( validateCssCustomPropertyReference( value ) ).toEqual(
			expected
		);
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

	// Mirrors the table in tests/phpunit/StylePromptTest.php
	// (text_shadow_value_provider). The two validators are a cross-language
	// pair; keep the cases in sync or drift becomes invisible.
	test.each( [
		[ 'two offsets', '1px 1px', true ],
		[ 'offsets with blur', '1px 1px 2px', true ],
		[ 'trailing hex color', '1px 1px 2px #000000', true ],
		[ 'leading color', '#000000 1px 1px 2px', true ],
		[ 'negative offsets', '-1px -2px 3px', true ],
		[ 'zero offsets', '0 1px 2px', true ],
		[ 'rgba inner commas', '0 1px 2px rgba(0, 0, 0, 0.3)', true ],
		[ 'var reference', '0 1px 2px var(--wp--preset--color--accent)', true ],
		[ 'multiple layers', '1px 1px 2px #000000, 0 0 4px #ffffff', true ],
		[ 'currentColor', '0 1px currentColor', true ],

		[ 'single length', '1px', false ],
		[ 'spread radius', '1px 1px 2px 3px #000', false ],
		[ 'inset keyword', 'inset 1px 1px #000', false ],
		[ 'negative blur', '1px 1px -2px', false ],
		[ 'bare color name', '1px 1px 2px red', false ],
		[ 'url function', '1px 1px 2px url(evil.png)', false ],
		[ 'declaration terminator', '1px 1px 2px #000; color: red', false ],
		[ 'important', '1px 1px 2px #000 !important', false ],
		[ 'comment syntax', '1px 1px /* x */ 2px', false ],
		[ 'closing brace', '1px 1px 2px #000 }', false ],
		[
			'too many layers',
			'1px 1px, 2px 2px, 3px 3px, 4px 4px, 5px 5px',
			false,
		],
		[ 'unbalanced paren', '1px 1px 2px rgba(0,0,0,0.3', false ],
		[ 'blank', '   ', false ],
		[ 'percentage offsets', '10% 10%', false ],
		[ 'doubled sign', '--1px 1px', false ],
	] )( 'text-shadow validator: %s', ( _, value, expectedValid ) => {
		expect(
			validateFreeformStyleValueByKind(
				FREEFORM_STYLE_VALIDATORS.TEXT_SHADOW,
				value
			)
		).toEqual(
			expectedValid
				? { valid: true, value: value.trim() }
				: { valid: false, value: null }
		);
	} );

	test( 'text-shadow accepts a whole-value custom property reference', () => {
		expect(
			validateFreeformStyleValueByKind(
				FREEFORM_STYLE_VALIDATORS.TEXT_SHADOW,
				'var(--flavor-agent-shadow)'
			)
		).toEqual( { valid: true, value: 'var(--flavor-agent-shadow)' } );
	} );

	test( 'text-shadow rejects an over-long value', () => {
		expect(
			validateFreeformStyleValueByKind(
				FREEFORM_STYLE_VALIDATORS.TEXT_SHADOW,
				`1px 1px 2px #000000 /* ${ 'x'.repeat( 400 ) } */`
			)
		).toEqual( { valid: false, value: null } );
	} );

	test( 'rejects unsupported validator kinds with a clear error', () => {
		expect(
			validateFreeformStyleValueByKind( 'missing-validator', '12px' )
		).toEqual( {
			valid: false,
			value: null,
			error: 'Unsupported freeform Global Styles validator: missing-validator.',
		} );
	} );
} );
