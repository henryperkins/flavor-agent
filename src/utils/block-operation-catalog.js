export const BLOCK_OPERATION_CATALOG_VERSION = 1;

export const BLOCK_OPERATION_INSERT_PATTERN = 'insert_pattern';
export const BLOCK_OPERATION_REPLACE_BLOCK_WITH_PATTERN =
	'replace_block_with_pattern';

export const BLOCK_OPERATION_ACTION_INSERT_BEFORE = 'insert_before';
export const BLOCK_OPERATION_ACTION_INSERT_AFTER = 'insert_after';
export const BLOCK_OPERATION_ACTION_REPLACE = 'replace';

export const BLOCK_OPERATION_TARGET_BLOCK = 'block';

export const BLOCK_OPERATION_ERROR_NO_OPERATIONS = 'no_operations';
export const BLOCK_OPERATION_ERROR_INVALID_OPERATION_PAYLOAD =
	'invalid_operation_payload';
export const BLOCK_OPERATION_ERROR_UNKNOWN_OPERATION_TYPE =
	'unknown_operation_type';
export const BLOCK_OPERATION_ERROR_MISSING_PATTERN_NAME =
	'missing_pattern_name';
export const BLOCK_OPERATION_ERROR_PATTERN_NOT_AVAILABLE =
	'pattern_not_available';
export const BLOCK_OPERATION_ERROR_MISSING_TARGET_CLIENT_ID =
	'missing_target_client_id';
export const BLOCK_OPERATION_ERROR_STALE_TARGET = 'stale_target';
export const BLOCK_OPERATION_ERROR_CROSS_SURFACE_TARGET =
	'cross_surface_target';
export const BLOCK_OPERATION_ERROR_INVALID_TARGET_TYPE = 'invalid_target_type';
export const BLOCK_OPERATION_ERROR_LOCKED_TARGET = 'locked_target';
export const BLOCK_OPERATION_ERROR_CONTENT_ONLY_TARGET = 'content_only_target';
export const BLOCK_OPERATION_ERROR_INVALID_INSERTION_POSITION =
	'invalid_insertion_position';
export const BLOCK_OPERATION_ERROR_ACTION_NOT_ALLOWED = 'action_not_allowed';
export const BLOCK_OPERATION_ERROR_STRUCTURAL_ACTIONS_DISABLED =
	'block_structural_actions_disabled';
export const BLOCK_OPERATION_ERROR_MULTI_OPERATION_UNSUPPORTED =
	'multi_operation_unsupported';
export const BLOCK_OPERATION_ERROR_CLIENT_SERVER_OPERATION_MISMATCH =
	'client_server_operation_mismatch';

export const BLOCK_OPERATION_CATALOG = Object.freeze( {
	version: BLOCK_OPERATION_CATALOG_VERSION,
	operations: {
		[ BLOCK_OPERATION_INSERT_PATTERN ]: {
			type: BLOCK_OPERATION_INSERT_PATTERN,
			requiredFields: [ 'patternName', 'targetClientId', 'position' ],
			allowedTargetTypes: [ BLOCK_OPERATION_TARGET_BLOCK ],
			allowedActions: [
				BLOCK_OPERATION_ACTION_INSERT_BEFORE,
				BLOCK_OPERATION_ACTION_INSERT_AFTER,
			],
			rollbackPayload: {
				type: 'remove_inserted_pattern_blocks',
				requiredRuntimeFields: [
					'insertedClientIds',
					'postApplySignature',
				],
			},
		},
		[ BLOCK_OPERATION_REPLACE_BLOCK_WITH_PATTERN ]: {
			type: BLOCK_OPERATION_REPLACE_BLOCK_WITH_PATTERN,
			requiredFields: [ 'patternName', 'targetClientId' ],
			allowedTargetTypes: [ BLOCK_OPERATION_TARGET_BLOCK ],
			allowedActions: [ BLOCK_OPERATION_ACTION_REPLACE ],
			rollbackPayload: {
				type: 'restore_replaced_block',
				requiredRuntimeFields: [
					'originalBlock',
					'replacementClientIds',
					'postApplySignature',
				],
			},
		},
	},
} );

const ALLOWED_INSERT_POSITIONS = new Set( [
	BLOCK_OPERATION_ACTION_INSERT_BEFORE,
	BLOCK_OPERATION_ACTION_INSERT_AFTER,
] );

function toNonEmptyString( value ) {
	return typeof value === 'string' && value.trim() !== '' ? value.trim() : '';
}

function getContextTargetClientId( context = {} ) {
	return toNonEmptyString( context.targetClientId || context.clientId );
}

function getContextTargetSignature( context = {} ) {
	return toNonEmptyString(
		context.targetSignature || context.contextSignature || context.signature
	);
}

function getOperationTargetClientId( rawOperation = {} ) {
	return toNonEmptyString(
		rawOperation.targetClientId || rawOperation.target?.clientId
	);
}

function getOperationTargetSignature( rawOperation = {} ) {
	return toNonEmptyString(
		rawOperation.targetSignature || rawOperation.target?.signature
	);
}

function getOperationTargetSurface( rawOperation = {} ) {
	return toNonEmptyString(
		rawOperation.surface ||
			rawOperation.targetSurface ||
			rawOperation.target?.surface ||
			'block'
	);
}

function getOperationTargetType( rawOperation = {} ) {
	return toNonEmptyString(
		rawOperation.targetType || rawOperation.target?.type || 'block'
	);
}

function getAllowedActions( pattern = {} ) {
	return Array.isArray( pattern.allowedActions )
		? pattern.allowedActions.filter(
				( action ) => typeof action === 'string' && action.trim() !== ''
		  )
		: [];
}

function normalizeAllowedPattern( pattern = {} ) {
	const name = toNonEmptyString( pattern.name );

	if ( ! name ) {
		return null;
	}

	return {
		name,
		title: toNonEmptyString( pattern.title ),
		source: toNonEmptyString( pattern.source ),
		categories: Array.isArray( pattern.categories )
			? pattern.categories.filter( Boolean )
			: [],
		blockTypes: Array.isArray( pattern.blockTypes )
			? pattern.blockTypes.filter( Boolean )
			: [],
		allowedActions: getAllowedActions( pattern ),
	};
}

function getAllowedPatternLookup( allowedPatterns = [] ) {
	const patterns = Array.isArray( allowedPatterns ) ? allowedPatterns : [];
	const entries = patterns
		.map( normalizeAllowedPattern )
		.filter( Boolean )
		.map( ( pattern ) => [ pattern.name, pattern ] );

	return new Map( entries );
}

function rejectOperation( rawOperation, code, message ) {
	return {
		code,
		message,
		operation: rawOperation || null,
	};
}

export function isBlockStructuralActionsEnabled( data = null ) {
	if ( data && typeof data === 'object' ) {
		return data.enableBlockStructuralActions === true;
	}

	if ( typeof window !== 'undefined' ) {
		return window.flavorAgentData?.enableBlockStructuralActions === true;
	}

	return false;
}

function contextEnablesBlockStructuralActions( context = {} ) {
	if ( typeof context.enableBlockStructuralActions === 'boolean' ) {
		return context.enableBlockStructuralActions === true;
	}

	return isBlockStructuralActionsEnabled();
}

export function buildBlockOperationValidationContext( blockContext = {} ) {
	const operationContext = blockContext.blockOperationContext || blockContext;
	let enableBlockStructuralActions;

	if ( typeof blockContext.enableBlockStructuralActions === 'boolean' ) {
		enableBlockStructuralActions =
			blockContext.enableBlockStructuralActions === true;
	} else if (
		typeof operationContext.enableBlockStructuralActions === 'boolean'
	) {
		enableBlockStructuralActions =
			operationContext.enableBlockStructuralActions === true;
	} else {
		enableBlockStructuralActions = isBlockStructuralActionsEnabled();
	}

	return {
		enableBlockStructuralActions,
		targetClientId: operationContext.targetClientId || '',
		targetBlockName: operationContext.targetBlockName || '',
		targetSignature: operationContext.targetSignature || '',
		allowedPatterns: operationContext.allowedPatterns || [],
		isTargetLocked: operationContext.isTargetLocked === true,
		isContentOnly:
			operationContext.isContentOnly === true ||
			operationContext.isInsideContentOnly === true ||
			operationContext.editingMode === 'contentOnly',
		editingMode: operationContext.editingMode || 'default',
	};
}

function createRollbackPayload( type ) {
	return {
		...BLOCK_OPERATION_CATALOG.operations[ type ].rollbackPayload,
	};
}

function createBaseNormalizedOperation( rawOperation, context, pattern ) {
	const targetClientId = getOperationTargetClientId( rawOperation );
	const operationTargetSignature =
		getOperationTargetSignature( rawOperation );
	const contextTargetSignature = getContextTargetSignature( context );
	const normalized = {
		catalogVersion: BLOCK_OPERATION_CATALOG_VERSION,
		type: rawOperation.type,
		patternName: pattern.name,
		targetClientId,
		targetType: BLOCK_OPERATION_TARGET_BLOCK,
		rollback: createRollbackPayload( rawOperation.type ),
	};

	if ( operationTargetSignature || contextTargetSignature ) {
		normalized.targetSignature =
			operationTargetSignature || contextTargetSignature;
	}

	if ( context.targetBlockName ) {
		normalized.expectedTarget = {
			clientId: targetClientId,
			name: context.targetBlockName,
		};
	}

	return normalized;
}

function validateSharedOperationFields( rawOperation, context, patternLookup ) {
	if ( ! rawOperation || typeof rawOperation !== 'object' ) {
		return rejectOperation(
			rawOperation,
			BLOCK_OPERATION_ERROR_INVALID_OPERATION_PAYLOAD,
			'Block operations must be objects.'
		);
	}

	const type = toNonEmptyString( rawOperation.type );

	if ( ! BLOCK_OPERATION_CATALOG.operations[ type ] ) {
		return rejectOperation(
			rawOperation,
			BLOCK_OPERATION_ERROR_UNKNOWN_OPERATION_TYPE,
			`Unsupported block operation “${ type || 'unknown' }”.`
		);
	}

	if ( getOperationTargetSurface( rawOperation ) !== 'block' ) {
		return rejectOperation(
			rawOperation,
			BLOCK_OPERATION_ERROR_CROSS_SURFACE_TARGET,
			'Block operations cannot target another recommendation surface.'
		);
	}

	if (
		getOperationTargetType( rawOperation ) !== BLOCK_OPERATION_TARGET_BLOCK
	) {
		return rejectOperation(
			rawOperation,
			BLOCK_OPERATION_ERROR_INVALID_TARGET_TYPE,
			'Block operations must target the selected block.'
		);
	}

	const targetClientId = getOperationTargetClientId( rawOperation );

	if ( ! targetClientId ) {
		return rejectOperation(
			rawOperation,
			BLOCK_OPERATION_ERROR_MISSING_TARGET_CLIENT_ID,
			'Block operations must include targetClientId.'
		);
	}

	const contextTargetClientId = getContextTargetClientId( context );

	if ( contextTargetClientId && targetClientId !== contextTargetClientId ) {
		return rejectOperation(
			rawOperation,
			BLOCK_OPERATION_ERROR_STALE_TARGET,
			'Block operations must target the current selected block.'
		);
	}

	const operationTargetSignature =
		getOperationTargetSignature( rawOperation );
	const contextTargetSignature = getContextTargetSignature( context );

	if (
		operationTargetSignature &&
		contextTargetSignature &&
		operationTargetSignature !== contextTargetSignature
	) {
		return rejectOperation(
			rawOperation,
			BLOCK_OPERATION_ERROR_STALE_TARGET,
			'Block operations must match the current target signature.'
		);
	}

	if ( context.isTargetLocked || context.locked ) {
		return rejectOperation(
			rawOperation,
			BLOCK_OPERATION_ERROR_LOCKED_TARGET,
			'Block operations cannot mutate a locked target.'
		);
	}

	if ( context.isContentOnly || context.editingMode === 'contentOnly' ) {
		return rejectOperation(
			rawOperation,
			BLOCK_OPERATION_ERROR_CONTENT_ONLY_TARGET,
			'Block operations cannot mutate a content-only target.'
		);
	}

	const patternName = toNonEmptyString( rawOperation.patternName );

	if ( ! patternName ) {
		return rejectOperation(
			rawOperation,
			BLOCK_OPERATION_ERROR_MISSING_PATTERN_NAME,
			'Block operations must include patternName.'
		);
	}

	const pattern = patternLookup.get( patternName );

	if ( ! pattern ) {
		return rejectOperation(
			rawOperation,
			BLOCK_OPERATION_ERROR_PATTERN_NOT_AVAILABLE,
			'Block operations must choose an allowed pattern.'
		);
	}

	return { ok: true, type, pattern };
}

function validateInsertPatternOperation(
	rawOperation,
	context,
	patternLookup
) {
	const sharedValidation = validateSharedOperationFields(
		rawOperation,
		context,
		patternLookup
	);

	if ( sharedValidation?.ok !== true ) {
		return sharedValidation;
	}

	const position = toNonEmptyString( rawOperation.position );

	if ( ! ALLOWED_INSERT_POSITIONS.has( position ) ) {
		return rejectOperation(
			rawOperation,
			BLOCK_OPERATION_ERROR_INVALID_INSERTION_POSITION,
			'Pattern insertions must use insert_before or insert_after.'
		);
	}

	if ( ! sharedValidation.pattern.allowedActions.includes( position ) ) {
		return rejectOperation(
			rawOperation,
			BLOCK_OPERATION_ERROR_ACTION_NOT_ALLOWED,
			'The selected pattern is not allowed at that insertion position.'
		);
	}

	return {
		ok: true,
		operation: {
			...createBaseNormalizedOperation(
				rawOperation,
				context,
				sharedValidation.pattern
			),
			position,
		},
	};
}

function validateReplaceBlockWithPatternOperation(
	rawOperation,
	context,
	patternLookup
) {
	const sharedValidation = validateSharedOperationFields(
		rawOperation,
		context,
		patternLookup
	);

	if ( sharedValidation?.ok !== true ) {
		return sharedValidation;
	}

	if (
		! sharedValidation.pattern.allowedActions.includes(
			BLOCK_OPERATION_ACTION_REPLACE
		)
	) {
		return rejectOperation(
			rawOperation,
			BLOCK_OPERATION_ERROR_ACTION_NOT_ALLOWED,
			'The selected pattern is not allowed to replace this block.'
		);
	}

	return {
		ok: true,
		operation: {
			...createBaseNormalizedOperation(
				rawOperation,
				context,
				sharedValidation.pattern
			),
			action: BLOCK_OPERATION_ACTION_REPLACE,
		},
	};
}

function validateBlockOperation( rawOperation, context, patternLookup ) {
	const type = toNonEmptyString( rawOperation?.type );

	switch ( type ) {
		case BLOCK_OPERATION_INSERT_PATTERN:
			return validateInsertPatternOperation(
				rawOperation,
				context,
				patternLookup
			);
		case BLOCK_OPERATION_REPLACE_BLOCK_WITH_PATTERN:
			return validateReplaceBlockWithPatternOperation(
				rawOperation,
				context,
				patternLookup
			);
		default:
			return validateSharedOperationFields(
				rawOperation,
				context,
				patternLookup
			);
	}
}

export function normalizeAllowedPatternsForBlockOperations(
	allowedPatterns = []
) {
	return Array.from( getAllowedPatternLookup( allowedPatterns ).values() );
}

export function validateBlockOperationSequence(
	operations = [],
	context = {}
) {
	const rawOperations = Array.isArray( operations ) ? operations : [];

	if ( rawOperations.length === 0 ) {
		return {
			ok: false,
			catalogVersion: BLOCK_OPERATION_CATALOG_VERSION,
			operations: [],
			rejectedOperations: [],
			proposedCount: 0,
		};
	}

	if ( ! contextEnablesBlockStructuralActions( context ) ) {
		return {
			ok: false,
			catalogVersion: BLOCK_OPERATION_CATALOG_VERSION,
			operations: [],
			rejectedOperations: rawOperations.map( ( rawOperation ) =>
				rejectOperation(
					rawOperation,
					BLOCK_OPERATION_ERROR_STRUCTURAL_ACTIONS_DISABLED,
					'Block structural actions are disabled for this environment.'
				)
			),
			proposedCount: rawOperations.length,
		};
	}

	if ( rawOperations.length > 1 ) {
		return {
			ok: false,
			catalogVersion: BLOCK_OPERATION_CATALOG_VERSION,
			operations: [],
			rejectedOperations: rawOperations.map( ( rawOperation ) =>
				rejectOperation(
					rawOperation,
					BLOCK_OPERATION_ERROR_MULTI_OPERATION_UNSUPPORTED,
					'Only one block structural operation can be executable in this milestone.'
				)
			),
			proposedCount: rawOperations.length,
		};
	}

	const normalizedOperations = [];
	const rejectedOperations = [];
	const patternLookup = getAllowedPatternLookup( context.allowedPatterns );

	for ( const rawOperation of rawOperations ) {
		const result = validateBlockOperation(
			rawOperation,
			context,
			patternLookup
		);

		if ( result?.ok === true ) {
			normalizedOperations.push( result.operation );
		} else {
			rejectedOperations.push( result );
		}
	}

	return {
		ok: normalizedOperations.length > 0,
		catalogVersion: BLOCK_OPERATION_CATALOG_VERSION,
		operations: normalizedOperations,
		rejectedOperations: rejectedOperations.filter( Boolean ),
		proposedCount: rawOperations.length,
	};
}
