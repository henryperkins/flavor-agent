const CSS_LENGTH_UNITS = [
	'px',
	'em',
	'rem',
	'vh',
	'vw',
	'vmin',
	'vmax',
	'svh',
	'lvh',
	'dvh',
	'svw',
	'lvw',
	'dvw',
	'ch',
	'ex',
	'cap',
	'ic',
	'lh',
	'rlh',
	'cm',
	'mm',
	'q',
	'in',
	'pt',
	'pc',
];

const PRESET_TYPE_DISPLAY_MAP = Object.freeze( {
	fontsize: 'font-size',
	fontfamily: 'font-family',
} );

export const FREEFORM_STYLE_VALIDATORS = Object.freeze( {
	BORDER_STYLE: 'border-style',
	COLOR: 'color',
	FONT_FAMILY: 'font-family',
	FONT_STYLE: 'font-style',
	FONT_WEIGHT: 'font-weight',
	LENGTH: 'length',
	LENGTH_OR_PERCENTAGE: 'length-or-percentage',
	LETTER_SPACING: 'letter-spacing',
	LINE_HEIGHT: 'line-height',
	SHADOW: 'shadow',
	TEXT_DECORATION: 'text-decoration',
	TEXT_TRANSFORM: 'text-transform',
} );

export function sanitizeStyleKey( value ) {
	return String( value || '' )
		.trim()
		.toLowerCase()
		.replace( /[^a-z0-9_-]/g, '' );
}

export function normalizePresetType( value ) {
	return sanitizeStyleKey( value ).replaceAll( '-', '' );
}

export function displayPresetType( presetType ) {
	return (
		PRESET_TYPE_DISPLAY_MAP[ normalizePresetType( presetType ) ] ||
		sanitizeStyleKey( presetType )
	);
}

function buildCssLengthPattern( {
	allowPercentage = false,
	allowUnitlessZero = true,
} = {} ) {
	const suffix = allowPercentage
		? [ ...CSS_LENGTH_UNITS, '%' ].join( '|' )
		: CSS_LENGTH_UNITS.join( '|' );

	return allowUnitlessZero
		? new RegExp( `^0(?:\\.0+)?(?:${ suffix })?$`, 'i' )
		: new RegExp( `^(?:\\d+|\\d*\\.\\d+)(?:${ suffix })$`, 'i' );
}

function isPositiveNumberString( value ) {
	return /^(?:\d+|\d*\.\d+)$/.test( value ) && Number( value ) > 0;
}

function isPositiveCssLength( value, { allowPercentage = false } = {} ) {
	return (
		buildCssLengthPattern( {
			allowPercentage,
			allowUnitlessZero: false,
		} ).test( value ) && Number.parseFloat( value ) > 0
	);
}

function isZeroCssLength( value, { allowPercentage = false } = {} ) {
	return buildCssLengthPattern( {
		allowPercentage,
		allowUnitlessZero: true,
	} ).test( value );
}

function looksLikeCssPayloadValue( value ) {
	const normalizedValue = value.trim();

	if ( ! normalizedValue ) {
		return false;
	}

	if ( /[{};]/.test( normalizedValue ) ) {
		return true;
	}

	if ( /!\s*important\b/i.test( normalizedValue ) ) {
		return true;
	}

	if (
		/^@(container|font-face|import|keyframes|layer|media|supports)\b/i.test(
			normalizedValue
		)
	) {
		return true;
	}

	return (
		/^[a-z-]+\s*:\s*.+$/i.test( normalizedValue ) &&
		! /^var:preset\|/i.test( normalizedValue ) &&
		! /^var\(--/i.test( normalizedValue )
	);
}

export function validateCssCustomPropertyReference( value ) {
	if ( typeof value !== 'string' ) {
		return { valid: false, value: null };
	}

	const normalizedValue = value.trim();

	return /^var\(\s*--[a-z0-9_-]+\s*\)$/i.test( normalizedValue )
		? { valid: true, value: normalizedValue }
		: { valid: false, value: null };
}

function validateSafeScalarStringValue( value ) {
	if ( typeof value !== 'string' ) {
		return { valid: false, value: null };
	}

	const normalizedValue = value.trim();

	if ( ! normalizedValue || looksLikeCssPayloadValue( normalizedValue ) ) {
		return { valid: false, value: null };
	}

	return {
		valid: true,
		value: normalizedValue,
	};
}

function validateLineHeightValue( value ) {
	if ( typeof value === 'number' ) {
		return value > 0
			? { valid: true, value }
			: { valid: false, value: null };
	}

	if ( typeof value !== 'string' ) {
		return { valid: false, value: null };
	}

	const normalizedValue = value.trim();

	if ( ! normalizedValue ) {
		return { valid: false, value: null };
	}

	if (
		isPositiveNumberString( normalizedValue ) ||
		isPositiveCssLength( normalizedValue, {
			allowPercentage: true,
		} )
	) {
		return {
			valid: true,
			value: normalizedValue,
		};
	}

	return { valid: false, value: null };
}

function validateLengthValue( value, { allowPercentage = false } = {} ) {
	if ( typeof value === 'number' ) {
		return value === 0
			? { valid: true, value }
			: { valid: false, value: null };
	}

	if ( typeof value !== 'string' ) {
		return { valid: false, value: null };
	}

	const normalizedValue = value.trim();

	if ( ! normalizedValue ) {
		return { valid: false, value: null };
	}

	if (
		isZeroCssLength( normalizedValue, {
			allowPercentage,
		} ) ||
		isPositiveCssLength( normalizedValue, {
			allowPercentage,
		} )
	) {
		return {
			valid: true,
			value: normalizedValue,
		};
	}

	return { valid: false, value: null };
}

function validateColorValue( value ) {
	const safeString = validateSafeScalarStringValue( value );

	if ( ! safeString.valid ) {
		return safeString;
	}

	if (
		/^#[0-9a-f]{3,4}$/i.test( safeString.value ) ||
		/^#[0-9a-f]{6}$/i.test( safeString.value ) ||
		/^#[0-9a-f]{8}$/i.test( safeString.value ) ||
		/^(?:rgba?|hsla?)\(\s*[-\d.%\s,\/]+\)$/i.test( safeString.value ) ||
		/^[a-z-]+$/i.test( safeString.value )
	) {
		return safeString;
	}

	return { valid: false, value: null };
}

function validateFontFamilyValue( value ) {
	return validateSafeScalarStringValue( value );
}

function validateFontStyleValue( value ) {
	const safeString = validateSafeScalarStringValue( value );

	if ( ! safeString.valid ) {
		return safeString;
	}

	const normalizedValue = safeString.value.toLowerCase();

	return new Set( [ 'normal', 'italic', 'oblique' ] ).has( normalizedValue )
		? { valid: true, value: normalizedValue }
		: { valid: false, value: null };
}

function validateFontWeightValue( value ) {
	if (
		typeof value === 'number' &&
		Number.isInteger( value ) &&
		value >= 1 &&
		value <= 1000
	) {
		return {
			valid: true,
			value,
		};
	}

	if ( typeof value !== 'string' ) {
		return { valid: false, value: null };
	}

	const normalizedValue = value.trim().toLowerCase();

	if ( ! normalizedValue ) {
		return { valid: false, value: null };
	}

	if (
		new Set( [ 'normal', 'bold', 'bolder', 'lighter' ] ).has(
			normalizedValue
		)
	) {
		return {
			valid: true,
			value: normalizedValue,
		};
	}

	if (
		/^\d{1,4}$/.test( normalizedValue ) &&
		Number( normalizedValue ) >= 1 &&
		Number( normalizedValue ) <= 1000
	) {
		return {
			valid: true,
			value: value.trim(),
		};
	}

	return { valid: false, value: null };
}

function validateSignedLengthValue( value ) {
	if ( typeof value === 'number' ) {
		return value === 0
			? { valid: true, value }
			: { valid: false, value: null };
	}

	if ( typeof value !== 'string' ) {
		return { valid: false, value: null };
	}

	const normalizedValue = value.trim();

	if ( ! normalizedValue ) {
		return { valid: false, value: null };
	}

	const suffix = CSS_LENGTH_UNITS.join( '|' );
	const zeroPattern = new RegExp( `^-?0(?:\\.0+)?(?:${ suffix })?$`, 'i' );
	const nonZeroPattern = new RegExp(
		`^-?(?:\\d+|\\d*\\.\\d+)(?:${ suffix })$`,
		'i'
	);

	if (
		zeroPattern.test( normalizedValue ) ||
		( nonZeroPattern.test( normalizedValue ) &&
			Number.parseFloat( normalizedValue ) !== 0 )
	) {
		return {
			valid: true,
			value: normalizedValue,
		};
	}

	return { valid: false, value: null };
}

function validateLetterSpacingValue( value ) {
	if (
		typeof value === 'string' &&
		value.trim().toLowerCase() === 'normal'
	) {
		return {
			valid: true,
			value: 'normal',
		};
	}

	return validateSignedLengthValue( value );
}

function validateShadowValue( value ) {
	return validateSafeScalarStringValue( value );
}

function validateTextDecorationValue( value ) {
	const safeString = validateSafeScalarStringValue( value );

	if ( ! safeString.valid ) {
		return safeString;
	}

	const normalizedValue = safeString.value.toLowerCase();

	if ( normalizedValue === 'none' ) {
		return {
			valid: true,
			value: normalizedValue,
		};
	}

	const tokens = normalizedValue.split( /\s+/ ).filter( Boolean );
	const allowedValues = new Set( [
		'underline',
		'overline',
		'line-through',
	] );

	return tokens.length > 0 &&
		tokens.every( ( token ) => allowedValues.has( token ) ) &&
		new Set( tokens ).size === tokens.length
		? {
				valid: true,
				value: tokens.join( ' ' ),
		  }
		: { valid: false, value: null };
}

function validateTextTransformValue( value ) {
	const safeString = validateSafeScalarStringValue( value );

	if ( ! safeString.valid ) {
		return safeString;
	}

	const normalizedValue = safeString.value.toLowerCase();
	const allowedValues = new Set( [
		'none',
		'capitalize',
		'uppercase',
		'lowercase',
		'full-width',
		'full-size-kana',
	] );

	return allowedValues.has( normalizedValue )
		? { valid: true, value: normalizedValue }
		: { valid: false, value: null };
}

function validateBorderStyleValue( value ) {
	if ( typeof value !== 'string' ) {
		return { valid: false, value: null };
	}

	const normalizedValue = value.trim().toLowerCase();
	const allowedValues = new Set( [
		'none',
		'solid',
		'dashed',
		'dotted',
		'double',
		'groove',
		'ridge',
		'inset',
		'outset',
		'hidden',
	] );

	if ( ! allowedValues.has( normalizedValue ) ) {
		return { valid: false, value: null };
	}

	return {
		valid: true,
		value: normalizedValue,
	};
}

export function validateFreeformStyleValueByKind( kind, value ) {
	const customPropertyReference = validateCssCustomPropertyReference( value );

	if ( customPropertyReference.valid ) {
		return customPropertyReference;
	}

	switch ( kind ) {
		case FREEFORM_STYLE_VALIDATORS.COLOR:
			return validateColorValue( value );
		case FREEFORM_STYLE_VALIDATORS.FONT_FAMILY:
			return validateFontFamilyValue( value );
		case FREEFORM_STYLE_VALIDATORS.FONT_STYLE:
			return validateFontStyleValue( value );
		case FREEFORM_STYLE_VALIDATORS.FONT_WEIGHT:
			return validateFontWeightValue( value );
		case FREEFORM_STYLE_VALIDATORS.LINE_HEIGHT:
			return validateLineHeightValue( value );
		case FREEFORM_STYLE_VALIDATORS.LENGTH_OR_PERCENTAGE:
			return validateLengthValue( value, {
				allowPercentage: true,
			} );
		case FREEFORM_STYLE_VALIDATORS.LENGTH:
			return validateLengthValue( value );
		case FREEFORM_STYLE_VALIDATORS.LETTER_SPACING:
			return validateLetterSpacingValue( value );
		case FREEFORM_STYLE_VALIDATORS.BORDER_STYLE:
			return validateBorderStyleValue( value );
		case FREEFORM_STYLE_VALIDATORS.SHADOW:
			return validateShadowValue( value );
		case FREEFORM_STYLE_VALIDATORS.TEXT_DECORATION:
			return validateTextDecorationValue( value );
		case FREEFORM_STYLE_VALIDATORS.TEXT_TRANSFORM:
			return validateTextTransformValue( value );
		default:
			return {
				valid: false,
				value: null,
				error: `Unsupported freeform Global Styles validator: ${
					kind || 'unknown'
				}.`,
			};
	}
}

export { CSS_LENGTH_UNITS };
