import {
	BLOCK_OPERATION_ACTION_INSERT_AFTER,
	BLOCK_OPERATION_ACTION_INSERT_BEFORE,
	BLOCK_OPERATION_ACTION_REPLACE,
	BLOCK_OPERATION_CATALOG_VERSION,
	BLOCK_OPERATION_ERROR_ACTION_NOT_ALLOWED,
	BLOCK_OPERATION_ERROR_CONTENT_ONLY_TARGET,
	BLOCK_OPERATION_ERROR_CROSS_SURFACE_TARGET,
	BLOCK_OPERATION_ERROR_INVALID_INSERTION_POSITION,
	BLOCK_OPERATION_ERROR_INVALID_TARGET_TYPE,
	BLOCK_OPERATION_ERROR_LOCKED_TARGET,
	BLOCK_OPERATION_ERROR_MISSING_PATTERN_NAME,
	BLOCK_OPERATION_ERROR_MISSING_TARGET_CLIENT_ID,
	BLOCK_OPERATION_ERROR_NO_OPERATIONS,
	BLOCK_OPERATION_ERROR_PATTERN_NOT_AVAILABLE,
	BLOCK_OPERATION_ERROR_STALE_TARGET,
	BLOCK_OPERATION_ERROR_UNKNOWN_OPERATION_TYPE,
	BLOCK_OPERATION_INSERT_PATTERN,
	BLOCK_OPERATION_REPLACE_BLOCK_WITH_PATTERN,
	normalizeAllowedPatternsForBlockOperations,
	validateBlockOperationSequence,
} from '../block-operation-catalog';

const allowedPatterns = [
	{
		name: 'flavor-agent/cta-with-image',
		title: 'CTA with image',
		source: 'plugin',
		categories: [ 'call-to-action' ],
		blockTypes: [ 'core/group' ],
		allowedActions: [
			BLOCK_OPERATION_ACTION_INSERT_BEFORE,
			BLOCK_OPERATION_ACTION_INSERT_AFTER,
			BLOCK_OPERATION_ACTION_REPLACE,
		],
	},
	{
		name: 'theme/text-band',
		title: 'Text band',
		source: 'theme',
		categories: [],
		blockTypes: [],
		allowedActions: [ BLOCK_OPERATION_ACTION_INSERT_AFTER ],
	},
];

const baseContext = {
	targetClientId: 'block-1',
	targetBlockName: 'core/group',
	targetSignature: 'sig:block-1',
	allowedPatterns,
};

function getRejectedCodes( result ) {
	return result.rejectedOperations.map( ( rejection ) => rejection.code );
}

describe( 'block operation catalog', () => {
	test( 'normalizes allowed pattern context for prompt inputs', () => {
		expect(
			normalizeAllowedPatternsForBlockOperations( [
				...allowedPatterns,
				{
					name: '',
					allowedActions: [ BLOCK_OPERATION_ACTION_REPLACE ],
				},
			] )
		).toEqual( allowedPatterns );
	} );

	test( 'validates insert and replace operations against the catalog and allowed pattern actions', () => {
		expect(
			validateBlockOperationSequence(
				[
					{
						type: BLOCK_OPERATION_INSERT_PATTERN,
						patternName: 'flavor-agent/cta-with-image',
						targetClientId: 'block-1',
						targetSignature: 'sig:block-1',
						position: BLOCK_OPERATION_ACTION_INSERT_AFTER,
					},
					{
						type: BLOCK_OPERATION_REPLACE_BLOCK_WITH_PATTERN,
						patternName: 'flavor-agent/cta-with-image',
						targetClientId: 'block-1',
					},
				],
				baseContext
			)
		).toEqual( {
			catalogVersion: BLOCK_OPERATION_CATALOG_VERSION,
			ok: true,
			operations: [
				{
					catalogVersion: BLOCK_OPERATION_CATALOG_VERSION,
					expectedTarget: {
						clientId: 'block-1',
						name: 'core/group',
					},
					patternName: 'flavor-agent/cta-with-image',
					position: BLOCK_OPERATION_ACTION_INSERT_AFTER,
					rollback: {
						requiredRuntimeFields: [
							'insertedClientIds',
							'postApplySignature',
						],
						type: 'remove_inserted_pattern_blocks',
					},
					targetClientId: 'block-1',
					targetSignature: 'sig:block-1',
					targetType: 'block',
					type: BLOCK_OPERATION_INSERT_PATTERN,
				},
				{
					action: BLOCK_OPERATION_ACTION_REPLACE,
					catalogVersion: BLOCK_OPERATION_CATALOG_VERSION,
					expectedTarget: {
						clientId: 'block-1',
						name: 'core/group',
					},
					patternName: 'flavor-agent/cta-with-image',
					rollback: {
						requiredRuntimeFields: [
							'originalBlock',
							'replacementClientIds',
							'postApplySignature',
						],
						type: 'restore_replaced_block',
					},
					targetClientId: 'block-1',
					targetSignature: 'sig:block-1',
					targetType: 'block',
					type: BLOCK_OPERATION_REPLACE_BLOCK_WITH_PATTERN,
				},
			],
			proposedCount: 2,
			rejectedOperations: [],
		} );
	} );

	test( 'keeps mixed recommendations partially executable with advisory rejections', () => {
		const result = validateBlockOperationSequence(
			[
				{
					type: BLOCK_OPERATION_INSERT_PATTERN,
					patternName: 'theme/text-band',
					targetClientId: 'block-1',
					position: BLOCK_OPERATION_ACTION_INSERT_AFTER,
				},
				{
					type: BLOCK_OPERATION_REPLACE_BLOCK_WITH_PATTERN,
					patternName: 'theme/text-band',
					targetClientId: 'block-1',
				},
			],
			baseContext
		);

		expect( result.ok ).toBe( true );
		expect( result.operations ).toEqual( [
			expect.objectContaining( {
				patternName: 'theme/text-band',
				position: BLOCK_OPERATION_ACTION_INSERT_AFTER,
				type: BLOCK_OPERATION_INSERT_PATTERN,
			} ),
		] );
		expect( getRejectedCodes( result ) ).toEqual( [
			BLOCK_OPERATION_ERROR_ACTION_NOT_ALLOWED,
		] );
	} );

	test.each( [
		[
			'no operations',
			[],
			baseContext,
			[ BLOCK_OPERATION_ERROR_NO_OPERATIONS ],
		],
		[
			'unknown operation types',
			[
				{
					type: 'remove_block',
					patternName: 'flavor-agent/cta-with-image',
					targetClientId: 'block-1',
				},
			],
			baseContext,
			[ BLOCK_OPERATION_ERROR_UNKNOWN_OPERATION_TYPE ],
		],
		[
			'unknown pattern names',
			[
				{
					type: BLOCK_OPERATION_INSERT_PATTERN,
					patternName: 'theme/missing-pattern',
					targetClientId: 'block-1',
					position: BLOCK_OPERATION_ACTION_INSERT_AFTER,
				},
			],
			baseContext,
			[ BLOCK_OPERATION_ERROR_PATTERN_NOT_AVAILABLE ],
		],
		[
			'missing pattern names',
			[
				{
					type: BLOCK_OPERATION_INSERT_PATTERN,
					targetClientId: 'block-1',
					position: BLOCK_OPERATION_ACTION_INSERT_AFTER,
				},
			],
			baseContext,
			[ BLOCK_OPERATION_ERROR_MISSING_PATTERN_NAME ],
		],
		[
			'missing target client IDs',
			[
				{
					type: BLOCK_OPERATION_INSERT_PATTERN,
					patternName: 'flavor-agent/cta-with-image',
					position: BLOCK_OPERATION_ACTION_INSERT_AFTER,
				},
			],
			baseContext,
			[ BLOCK_OPERATION_ERROR_MISSING_TARGET_CLIENT_ID ],
		],
		[
			'stale targets',
			[
				{
					type: BLOCK_OPERATION_INSERT_PATTERN,
					patternName: 'flavor-agent/cta-with-image',
					targetClientId: 'other-block',
					position: BLOCK_OPERATION_ACTION_INSERT_AFTER,
				},
			],
			baseContext,
			[ BLOCK_OPERATION_ERROR_STALE_TARGET ],
		],
		[
			'stale target signatures',
			[
				{
					type: BLOCK_OPERATION_INSERT_PATTERN,
					patternName: 'flavor-agent/cta-with-image',
					targetClientId: 'block-1',
					targetSignature: 'old-sig',
					position: BLOCK_OPERATION_ACTION_INSERT_AFTER,
				},
			],
			baseContext,
			[ BLOCK_OPERATION_ERROR_STALE_TARGET ],
		],
		[
			'cross-surface targets',
			[
				{
					type: BLOCK_OPERATION_INSERT_PATTERN,
					patternName: 'flavor-agent/cta-with-image',
					surface: 'template-part',
					targetClientId: 'block-1',
					position: BLOCK_OPERATION_ACTION_INSERT_AFTER,
				},
			],
			baseContext,
			[ BLOCK_OPERATION_ERROR_CROSS_SURFACE_TARGET ],
		],
		[
			'invalid target types',
			[
				{
					type: BLOCK_OPERATION_INSERT_PATTERN,
					patternName: 'flavor-agent/cta-with-image',
					targetClientId: 'block-1',
					targetType: 'template-part',
					position: BLOCK_OPERATION_ACTION_INSERT_AFTER,
				},
			],
			baseContext,
			[ BLOCK_OPERATION_ERROR_INVALID_TARGET_TYPE ],
		],
		[
			'locked targets',
			[
				{
					type: BLOCK_OPERATION_INSERT_PATTERN,
					patternName: 'flavor-agent/cta-with-image',
					targetClientId: 'block-1',
					position: BLOCK_OPERATION_ACTION_INSERT_AFTER,
				},
			],
			{
				...baseContext,
				isTargetLocked: true,
			},
			[ BLOCK_OPERATION_ERROR_LOCKED_TARGET ],
		],
		[
			'content-only targets',
			[
				{
					type: BLOCK_OPERATION_INSERT_PATTERN,
					patternName: 'flavor-agent/cta-with-image',
					targetClientId: 'block-1',
					position: BLOCK_OPERATION_ACTION_INSERT_AFTER,
				},
			],
			{
				...baseContext,
				editingMode: 'contentOnly',
			},
			[ BLOCK_OPERATION_ERROR_CONTENT_ONLY_TARGET ],
		],
		[
			'invalid insertion positions',
			[
				{
					type: BLOCK_OPERATION_INSERT_PATTERN,
					patternName: 'flavor-agent/cta-with-image',
					targetClientId: 'block-1',
					position: 'start',
				},
			],
			baseContext,
			[ BLOCK_OPERATION_ERROR_INVALID_INSERTION_POSITION ],
		],
		[
			'actions not allowed by pattern context',
			[
				{
					type: BLOCK_OPERATION_INSERT_PATTERN,
					patternName: 'theme/text-band',
					targetClientId: 'block-1',
					position: BLOCK_OPERATION_ACTION_INSERT_BEFORE,
				},
			],
			baseContext,
			[ BLOCK_OPERATION_ERROR_ACTION_NOT_ALLOWED ],
		],
	] )( 'rejects %s', ( _label, operations, context, expectedCodes ) => {
		const result = validateBlockOperationSequence( operations, context );

		expect( result.ok ).toBe( false );
		expect( result.operations ).toEqual( [] );
		expect( getRejectedCodes( result ) ).toEqual( expectedCodes );
	} );
} );
