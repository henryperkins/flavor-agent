import {
	ACTIONABILITY_REASON_MISSING_PATTERN_CONTEXT,
	ACTIONABILITY_REASON_SAFE_LOCAL_ATTRIBUTE_UPDATE,
	ACTIONABILITY_REASON_UNSUPPORTED_OPERATION,
	ACTIONABILITY_SOURCE_VALIDATOR,
	ACTIONABILITY_TIER_ADVISORY,
	ACTIONABILITY_TIER_INLINE_SAFE,
	ACTIONABILITY_TIER_REVIEW_SAFE,
	classifyBlockSuggestionActionability,
	classifyOperationActionability,
	summarizeActionability,
} from '../recommendation-actionability';

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
