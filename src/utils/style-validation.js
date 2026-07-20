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
	TEXT_SHADOW: 'text-shadow',
	TEXT_TRANSFORM: 'text-transform',
} );

/**
 * Comma-separated shadow layers are capped so a recommendation cannot turn into
 * a paint-performance problem on every page that uses the style.
 */
const TEXT_SHADOW_MAX_LAYERS = 4;

const TEXT_SHADOW_MAX_LENGTH = 200;

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

/**
 * Splits on top-level separators, leaving `rgba(0, 0, 0, .3)` intact.
 *
 * @param {string} value     Value to split.
 * @param {string} separator Either ',' for layers or ' ' for tokens.
 * @return {string[]} Parts, or an empty array when parentheses are unbalanced.
 */
function splitCssParts( value, separator ) {
	const parts = [];
	let buffer = '';
	let depth = 0;

	for ( const char of value ) {
		if ( char === '(' ) {
			depth += 1;
		} else if ( char === ')' ) {
			depth -= 1;

			if ( depth < 0 ) {
				return [];
			}
		}

		const isBreak =
			depth === 0 &&
			( separator === ',' ? char === ',' : /\s/.test( char ) );

		if ( isBreak ) {
			if ( separator === ' ' ) {
				if ( buffer ) {
					parts.push( buffer );
					buffer = '';
				}

				continue;
			}

			parts.push( buffer.trim() );
			buffer = '';

			continue;
		}

		buffer += char;
	}

	if ( depth !== 0 ) {
		return [];
	}

	if ( separator === ' ' ) {
		if ( buffer ) {
			parts.push( buffer );
		}

		return parts;
	}

	parts.push( buffer.trim() );

	return parts.some( ( part ) => ! part ) ? [] : parts;
}

function isShadowLength( token ) {
	let magnitude = token;
	let signed = false;

	if ( token && ( token[ 0 ] === '+' || token[ 0 ] === '-' ) ) {
		magnitude = token.slice( 1 );
		signed = true;
	}

	if ( ! magnitude ) {
		return false;
	}

	// Reject a second sign, e.g. '--5px'.
	if ( signed && ( magnitude[ 0 ] === '+' || magnitude[ 0 ] === '-' ) ) {
		return false;
	}

	return (
		isZeroCssLength( magnitude, { allowPercentage: false } ) ||
		isPositiveCssLength( magnitude, { allowPercentage: false } )
	);
}

function isNegativeCssLength( token ) {
	if ( ! token.startsWith( '-' ) ) {
		return false;
	}

	return ! isZeroCssLength( token.slice( 1 ), { allowPercentage: false } );
}

/**
 * Accepts hex, the standard color functions, `var()` references, and the two
 * context keywords. Bare color names are refused so the grammar stays small and
 * predictable; the prompt contract steers the model to hex/rgb().
 *
 * @param {string} token Candidate color token.
 * @return {boolean} Whether the token is an accepted color.
 */
function isShadowColor( token ) {
	if ( /^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test( token ) ) {
		return true;
	}

	if ( /^var\(\s*--[a-z0-9_-]+\s*\)$/i.test( token ) ) {
		return true;
	}

	if ( /^(?:rgb|rgba|hsl|hsla)\([0-9a-z%.,/\s+-]*\)$/i.test( token ) ) {
		return true;
	}

	return [ 'transparent', 'currentcolor' ].includes( token.toLowerCase() );
}

function isValidTextShadowLayer( layer ) {
	const tokens = splitCssParts( layer, ' ' );

	if ( tokens.length < 2 || tokens.length > 4 ) {
		return false;
	}

	const lengths = [];
	let colors = 0;

	for ( const token of tokens ) {
		if ( isShadowLength( token ) ) {
			lengths.push( token );

			continue;
		}

		if ( isShadowColor( token ) ) {
			colors += 1;

			continue;
		}

		return false;
	}

	if ( colors > 1 || lengths.length < 2 || lengths.length > 3 ) {
		return false;
	}

	// Blur radius cannot be negative.
	return ! ( lengths.length === 3 && isNegativeCssLength( lengths[ 2 ] ) );
}

/**
 * Validates a `text-shadow` value structurally.
 *
 * This value is written into the site's global stylesheet, and for users with
 * `unfiltered_html` nothing downstream re-checks it — `safe_style_css` never
 * sees it. So the grammar is validated positively (2-3 lengths plus at most one
 * color per layer) rather than by scanning for known-bad strings.
 *
 * `inset` and a fourth length (spread) are `box-shadow`-only; a browser drops
 * the entire declaration when it sees them, so both are refused.
 *
 * Kept behaviorally identical to `StylePrompt::validate_text_shadow_value()`.
 *
 * @param {*} value Candidate value.
 * @return {{valid: boolean, value: *}} Validation result.
 */
function validateTextShadowValue( value ) {
	if ( typeof value !== 'string' ) {
		return { valid: false, value: null };
	}

	const normalizedValue = value.trim();

	if (
		! normalizedValue ||
		normalizedValue.length > TEXT_SHADOW_MAX_LENGTH
	) {
		return { valid: false, value: null };
	}

	// Printable ASCII only: CSS escape sequences and control characters can
	// reconstruct tokens the checks below would otherwise reject.
	if ( ! /^[\x20-\x7E]+$/.test( normalizedValue ) ) {
		return { valid: false, value: null };
	}

	// Terminating the declaration, opening a rule or comment, or forcing
	// precedence would let a value escape its property.
	if ( /[;{}\\]|\/\*|\*\/|!\s*important/i.test( normalizedValue ) ) {
		return { valid: false, value: null };
	}

	const layers = splitCssParts( normalizedValue, ',' );

	if ( ! layers.length || layers.length > TEXT_SHADOW_MAX_LAYERS ) {
		return { valid: false, value: null };
	}

	if ( ! layers.every( isValidTextShadowLayer ) ) {
		return { valid: false, value: null };
	}

	return { valid: true, value: normalizedValue };
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
		case FREEFORM_STYLE_VALIDATORS.TEXT_SHADOW:
			return validateTextShadowValue( value );
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
