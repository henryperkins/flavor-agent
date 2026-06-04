import vocabulary from '../../shared/validation-reasons.json';

export const VALIDATION_REASONS_VERSION = vocabulary.version;

const SEVERITY_RANK = { rejected: 2, downgraded: 1, no_op: 0 };

/**
 * Concise, human-readable labels for validation reason codes surfaced on
 * rejected-but-advisory suggestions. Codes without an explicit entry fall back
 * to a humanized form of the code (see {@link getValidationReasonLabel}).
 *
 * @type {Object<string, string>}
 */
const REASON_LABELS = Object.freeze( {
	failed_contrast: 'Insufficient color contrast',
	unsupported_scope: 'Scope not supported',
	unsupported_path: 'Style path not supported',
	preset_required: 'A preset value is required',
	preset_unavailable: 'Preset unavailable',
	invalid_freeform_value: 'Invalid value',
	unavailable_variation: 'Style variation unavailable',
	no_executable_operations: 'No applicable changes',
	invalid_template_area: 'Template area not allowed',
	no_assigned_part: 'No template part assigned',
	area_mismatch: 'Template area mismatch',
	unknown_pattern: 'Pattern unavailable',
	repeated_pattern_insert: 'Pattern already inserted',
	too_many_operations: 'Too many changes to apply at once',
	advisory_only: 'Advisory only',
	operation_validation_failed: 'Could not validate the change',
	no_op: 'No change needed',
} );

/**
 * @param {string} code Reason code.
 * @return {string} Severity, defaulting to 'rejected' for unknown codes.
 */
export function getValidationReasonSeverity( code ) {
	return vocabulary.reasons?.[ code ]?.severity || 'rejected';
}

/**
 * Resolve a concise, human-readable label for a validation reason code.
 * Falls back to a humanized form of the code (underscores → spaces,
 * sentence-cased) so newly-added codes still render something readable.
 *
 * @param {string} [code] Reason code.
 * @return {string} Concise label, or an empty string for an empty code.
 */
export function getValidationReasonLabel( code = '' ) {
	if ( typeof code !== 'string' || code.trim() === '' ) {
		return '';
	}

	if ( REASON_LABELS[ code ] ) {
		return REASON_LABELS[ code ];
	}

	const humanized = code.replace( /[_-]+/g, ' ' ).trim();
	return humanized.charAt( 0 ).toUpperCase() + humanized.slice( 1 );
}

/**
 * @param {Array<{code: string, severity?: string}>} reasons Reason list.
 * @return {{code: string, severity: string}|null} Highest-severity reason, else null.
 */
export function primaryValidationReason( reasons = [] ) {
	if ( ! Array.isArray( reasons ) || reasons.length === 0 ) {
		return null;
	}

	const ranked = reasons
		.filter( ( r ) => r && typeof r.code === 'string' && r.code )
		.map( ( r ) => ( {
			code: r.code,
			severity: r.severity || getValidationReasonSeverity( r.code ),
		} ) );

	if ( ranked.length === 0 ) {
		return null;
	}

	return ranked.reduce( ( best, current ) =>
		( SEVERITY_RANK[ current.severity ] ?? 0 ) >
		( SEVERITY_RANK[ best.severity ] ?? 0 )
			? current
			: best
	);
}
