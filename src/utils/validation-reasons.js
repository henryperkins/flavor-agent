import { __ } from '@wordpress/i18n';

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
	failed_contrast: __( 'Insufficient color contrast', 'flavor-agent' ),
	unsupported_scope: __( 'Scope not supported', 'flavor-agent' ),
	unsupported_path: __( 'Style path not supported', 'flavor-agent' ),
	preset_required: __( 'A preset value is required', 'flavor-agent' ),
	preset_unavailable: __( 'Preset unavailable', 'flavor-agent' ),
	invalid_freeform_value: __( 'Invalid value', 'flavor-agent' ),
	unavailable_variation: __( 'Style variation unavailable', 'flavor-agent' ),
	no_executable_operations: __( 'No applicable changes', 'flavor-agent' ),
	invalid_template_area: __( 'Template area not allowed', 'flavor-agent' ),
	no_assigned_part: __( 'No template part assigned', 'flavor-agent' ),
	area_mismatch: __( 'Template area mismatch', 'flavor-agent' ),
	unknown_pattern: __( 'Pattern unavailable', 'flavor-agent' ),
	repeated_pattern_insert: __( 'Pattern already inserted', 'flavor-agent' ),
	too_many_operations: __(
		'Too many changes to apply at once',
		'flavor-agent'
	),
	advisory_only: __( 'Advisory only', 'flavor-agent' ),
	operation_validation_failed: __(
		'Could not validate the change',
		'flavor-agent'
	),
	no_op: __( 'No change needed', 'flavor-agent' ),
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
