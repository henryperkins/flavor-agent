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
	LENGTH: 'length',
	LENGTH_OR_PERCENTAGE: 'length-or-percentage',
	LINE_HEIGHT: 'line-height',
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
	switch ( kind ) {
		case FREEFORM_STYLE_VALIDATORS.LINE_HEIGHT:
			return validateLineHeightValue( value );
		case FREEFORM_STYLE_VALIDATORS.LENGTH_OR_PERCENTAGE:
			return validateLengthValue( value, {
				allowPercentage: true,
			} );
		case FREEFORM_STYLE_VALIDATORS.LENGTH:
			return validateLengthValue( value );
		case FREEFORM_STYLE_VALIDATORS.BORDER_STYLE:
			return validateBorderStyleValue( value );
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
