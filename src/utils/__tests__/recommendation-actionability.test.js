import {
	ACTIONABILITY_REASON_MISSING_PATTERN_CONTEXT,
	ACTIONABILITY_REASON_LOCKED_TARGET,
	ACTIONABILITY_REASON_MULTI_TARGET_STRUCTURAL_CHANGE,
	ACTIONABILITY_REASON_PATTERN_NOT_AVAILABLE,
	ACTIONABILITY_REASON_SAFE_LOCAL_ATTRIBUTE_UPDATE,
	ACTIONABILITY_REASON_TARGET_STALE,
	ACTIONABILITY_REASON_UNSUPPORTED_OPERATION,
	ACTIONABILITY_SOURCE_VALIDATOR,
	ACTIONABILITY_TIER_ADVISORY,
	ACTIONABILITY_TIER_INLINE_SAFE,
	ACTIONABILITY_TIER_REVIEW_SAFE,
	classifyBlockSuggestionActionability,
	classifyOperationActionability,
	summarizeActionability,
} from '../recommendation-actionability';
import {
	BLOCK_OPERATION_ERROR_CONTENT_ONLY_TARGET,
	BLOCK_OPERATION_ERROR_CROSS_SURFACE_TARGET,
	BLOCK_OPERATION_ERROR_LOCKED_TARGET,
	BLOCK_OPERATION_ERROR_MULTI_OPERATION_UNSUPPORTED,
	BLOCK_OPERATION_ERROR_PATTERN_NOT_AVAILABLE,
	BLOCK_OPERATION_ERROR_STALE_TARGET,
} from '../block-operation-catalog';

describe( 'recommendation actionability', () => {
	test( 'computes block eligibility from validator state instead of model-provided metadata', () => {
		expect(
			classifyBlockSuggestionActionability( {
				suggestion: {
					eligibility: {
						source: 'model',
						tier: ACTIONABILITY_TIER_ADVISORY,
					},
					attributeUpdates: {
						content: 'Better copy',
					},
				},
				allowedUpdates: {
					content: 'Better copy',
				},
			} )
		).toEqual( {
			blockers: [],
			executableOperations: [
				{
					type: 'update_block_attributes',
					attributeUpdates: {
						content: 'Better copy',
					},
				},
			],
			reasons: [ ACTIONABILITY_REASON_SAFE_LOCAL_ATTRIBUTE_UPDATE ],
			source: ACTIONABILITY_SOURCE_VALIDATOR,
			tier: ACTIONABILITY_TIER_INLINE_SAFE,
		} );
	} );

	test( 'preserves validated structural operations for review when local updates can apply', () => {
		const operation = {
			type: 'insert_pattern',
			patternName: 'theme/hero',
			targetClientId: 'block-1',
			position: 'insert_after',
		};

		expect(
			classifyBlockSuggestionActionability( {
				suggestion: {
					attributeUpdates: {
						content: 'Better copy',
					},
				},
				allowedUpdates: {
					content: 'Better copy',
				},
				operationValidation: {
					ok: true,
					operations: [ operation ],
					rejectedOperations: [],
				},
			} )
		).toEqual(
			expect.objectContaining( {
				executableOperations: [
					{
						type: 'update_block_attributes',
						attributeUpdates: {
							content: 'Better copy',
						},
					},
				],
				reviewOperations: [ operation ],
				tier: ACTIONABILITY_TIER_INLINE_SAFE,
			} )
		);
	} );

	test( 'keeps pattern replacements advisory without deterministic allowed pattern context', () => {
		expect(
			classifyBlockSuggestionActionability( {
				suggestion: {
					type: 'pattern_replacement',
					operations: [
						{
							type: 'replace_block_with_pattern',
							patternName: 'flavor-agent/cta-with-image',
						},
					],
				},
				isAdvisoryOnly: true,
			} )
		).toEqual( {
			advisoryOperationsRejected: [
				{
					type: 'replace_block_with_pattern',
					patternName: 'flavor-agent/cta-with-image',
				},
			],
			blockers: [ ACTIONABILITY_REASON_MISSING_PATTERN_CONTEXT ],
			executableOperations: [],
			reasons: [ ACTIONABILITY_REASON_MISSING_PATTERN_CONTEXT ],
			source: ACTIONABILITY_SOURCE_VALIDATOR,
			tier: ACTIONABILITY_TIER_ADVISORY,
		} );
	} );

	test( 'promotes only validated operations to review-safe', () => {
		const operation = {
			type: 'replace_block_with_pattern',
			patternName: 'flavor-agent/cta-with-image',
		};

		expect(
			classifyOperationActionability( {
				operations: [ operation ],
				validation: {
					ok: true,
					operations: [ operation ],
				},
			} )
		).toEqual(
			expect.objectContaining( {
				executableOperations: [ operation ],
				source: ACTIONABILITY_SOURCE_VALIDATOR,
				tier: ACTIONABILITY_TIER_REVIEW_SAFE,
			} )
		);

		expect(
			classifyOperationActionability( {
				operations: [ operation ],
				validation: {
					ok: false,
					error: 'Unknown operation type.',
				},
			} )
		).toEqual(
			expect.objectContaining( {
				advisoryOperationsRejected: [ operation ],
				blockers: [ ACTIONABILITY_REASON_UNSUPPORTED_OPERATION ],
				executableOperations: [],
				tier: ACTIONABILITY_TIER_ADVISORY,
			} )
		);
	} );

	test.each( [
		[
			BLOCK_OPERATION_ERROR_PATTERN_NOT_AVAILABLE,
			ACTIONABILITY_REASON_PATTERN_NOT_AVAILABLE,
		],
		[
			BLOCK_OPERATION_ERROR_STALE_TARGET,
			ACTIONABILITY_REASON_TARGET_STALE,
		],
		[
			BLOCK_OPERATION_ERROR_LOCKED_TARGET,
			ACTIONABILITY_REASON_LOCKED_TARGET,
		],
		[
			BLOCK_OPERATION_ERROR_CONTENT_ONLY_TARGET,
			ACTIONABILITY_REASON_LOCKED_TARGET,
		],
		[
			BLOCK_OPERATION_ERROR_CROSS_SURFACE_TARGET,
			ACTIONABILITY_REASON_MULTI_TARGET_STRUCTURAL_CHANGE,
		],
		[
			BLOCK_OPERATION_ERROR_MULTI_OPERATION_UNSUPPORTED,
			ACTIONABILITY_REASON_MULTI_TARGET_STRUCTURAL_CHANGE,
		],
	] )( 'maps %s rejection codes to blocker reasons', ( code, reason ) => {
		expect(
			classifyOperationActionability( {
				operations: [ { type: 'insert_pattern' } ],
				validation: {
					ok: false,
					operations: [],
					rejectedOperations: [
						{
							code,
							operation: { type: 'insert_pattern' },
						},
					],
				},
			} )
		).toEqual(
			expect.objectContaining( {
				blockers: [ reason ],
				reasons: [ reason ],
				tier: ACTIONABILITY_TIER_ADVISORY,
			} )
		);
	} );

	test( 'summarizes validator-computed tiers and blockers', () => {
		expect(
			summarizeActionability( [
				{
					tier: ACTIONABILITY_TIER_INLINE_SAFE,
					reasons: [
						ACTIONABILITY_REASON_SAFE_LOCAL_ATTRIBUTE_UPDATE,
					],
				},
				{
					tier: ACTIONABILITY_TIER_ADVISORY,
					blockers: [ ACTIONABILITY_REASON_UNSUPPORTED_OPERATION ],
				},
			] )
		).toEqual( {
			total: 2,
			tiers: {
				[ ACTIONABILITY_TIER_INLINE_SAFE ]: 1,
				[ ACTIONABILITY_TIER_REVIEW_SAFE ]: 0,
				[ ACTIONABILITY_TIER_ADVISORY ]: 1,
			},
			reasons: {
				[ ACTIONABILITY_REASON_SAFE_LOCAL_ATTRIBUTE_UPDATE ]: 1,
				[ ACTIONABILITY_REASON_UNSUPPORTED_OPERATION ]: 1,
			},
		} );
	} );
} );
