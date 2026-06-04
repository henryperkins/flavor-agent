import vocabulary from '../../shared/validation-reasons.json';

export const VALIDATION_REASONS_VERSION = vocabulary.version;

const SEVERITY_RANK = { rejected: 2, downgraded: 1, no_op: 0 };

/**
 * @param {string} code Reason code.
 * @return {string} Severity, defaulting to 'rejected' for unknown codes.
 */
export function getValidationReasonSeverity( code ) {
	return vocabulary.reasons?.[ code ]?.severity || 'rejected';
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
