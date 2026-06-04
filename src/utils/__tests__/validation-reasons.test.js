import {
	VALIDATION_REASONS_VERSION,
	getValidationReasonSeverity,
	primaryValidationReason,
} from '../validation-reasons';
import {
	BLOCK_OPERATION_ERROR_STALE_TARGET,
	BLOCK_OPERATION_ERROR_ACTION_NOT_ALLOWED,
} from '../block-operation-catalog';

describe( 'validation-reasons vocabulary', () => {
	it( 'exposes the v1 version', () => {
		expect( VALIDATION_REASONS_VERSION ).toBe( 'validation-reasons-v1' );
	} );

	it( 'contains every block catalog code (cross-language parity)', () => {
		// The vocabulary must be a superset of the block codes that the
		// client re-validation can emit on validation_blocked outcomes.
		expect(
			getValidationReasonSeverity( BLOCK_OPERATION_ERROR_STALE_TARGET )
		).toBe( 'rejected' );
		expect(
			getValidationReasonSeverity(
				BLOCK_OPERATION_ERROR_ACTION_NOT_ALLOWED
			)
		).toBe( 'rejected' );
	} );

	it( 'resolves the primary reason by highest severity then first', () => {
		expect(
			primaryValidationReason( [
				{ code: 'failed_contrast', severity: 'downgraded' },
				{ code: 'unsupported_path' },
			] )?.code
		).toBe( 'unsupported_path' );
	} );

	it( 'returns null primary for an empty list', () => {
		expect( primaryValidationReason( [] ) ).toBeNull();
	} );
} );
