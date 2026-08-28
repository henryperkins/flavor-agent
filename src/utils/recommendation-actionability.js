import {
	BLOCK_OPERATION_ERROR_ACTION_NOT_ALLOWED,
	BLOCK_OPERATION_ERROR_CLIENT_SERVER_OPERATION_MISMATCH,
	BLOCK_OPERATION_ERROR_CONTENT_ONLY_TARGET,
	BLOCK_OPERATION_ERROR_CROSS_SURFACE_TARGET,
	BLOCK_OPERATION_ERROR_INVALID_TARGET_TYPE,
	BLOCK_OPERATION_ERROR_LOCKED_TARGET,
	BLOCK_OPERATION_ERROR_MISSING_PATTERN_NAME,
	BLOCK_OPERATION_ERROR_MISSING_TARGET_CLIENT_ID,
	BLOCK_OPERATION_ERROR_MULTI_OPERATION_UNSUPPORTED,
	BLOCK_OPERATION_ERROR_PATTERN_NOT_AVAILABLE,
	BLOCK_OPERATION_ERROR_STALE_TARGET,
	BLOCK_OPERATION_ERROR_STRUCTURAL_ACTIONS_DISABLED,
} from './block-operation-catalog';
import { __ } from '@wordpress/i18n';

export const ACTIONABILITY_TIER_INLINE_SAFE = 'inline-safe';
export const ACTIONABILITY_TIER_REVIEW_SAFE = 'review-safe';
export const ACTIONABILITY_TIER_ADVISORY = 'advisory';
export const ACTIONABILITY_SOURCE_VALIDATOR = 'validator';

export const ACTIONABILITY_REASON_SAFE_LOCAL_ATTRIBUTE_UPDATE =
	'safe-local-attribute-update';
const ACTIONABILITY_REASON_VALID_STRUCTURAL_OPERATION =
	'valid-structural-operation';
export const ACTIONABILITY_REASON_MISSING_PATTERN_CONTEXT =
	'missing-pattern-context';
export const ACTIONABILITY_REASON_PATTERN_NOT_AVAILABLE =
	'pattern-not-available';
export const ACTIONABILITY_REASON_TARGET_STALE = 'target-stale';
export const ACTIONABILITY_REASON_TARGET_AMBIGUOUS = 'target-ambiguous';
export const ACTIONABILITY_REASON_LOCKED_TARGET = 'locked-target';
export const ACTIONABILITY_REASON_UNSUPPORTED_OPERATION =
	'unsupported-operation';
export const ACTIONABILITY_REASON_MULTI_TARGET_STRUCTURAL_CHANGE =
	'multi-target-structural-change';
export const ACTIONABILITY_REASON_MANUAL_COPY_ONLY = 'manual-copy-only';

const TIER_LABELS = Object.freeze( {
	[ ACTIONABILITY_TIER_INLINE_SAFE ]: __( 'Inline-safe', 'flavor-agent' ),
	[ ACTIONABILITY_TIER_REVIEW_SAFE ]: __( 'Review-safe', 'flavor-agent' ),
	[ ACTIONABILITY_TIER_ADVISORY ]: __( 'Advisory', 'flavor-agent' ),
} );

const REASON_LABELS = Object.freeze( {
	[ ACTIONABILITY_REASON_SAFE_LOCAL_ATTRIBUTE_UPDATE ]: __(
		'Safe local attribute update',
		'flavor-agent'
	),
	[ ACTIONABILITY_REASON_VALID_STRUCTURAL_OPERATION ]: __(
		'Valid structural operation',
		'flavor-agent'
	),
	[ ACTIONABILITY_REASON_MISSING_PATTERN_CONTEXT ]: __(
		'Missing pattern context',
		'flavor-agent'
	),
	[ ACTIONABILITY_REASON_PATTERN_NOT_AVAILABLE ]: __(
		'Pattern unavailable',
		'flavor-agent'
	),
	[ ACTIONABILITY_REASON_TARGET_STALE ]: __( 'Target stale', 'flavor-agent' ),
	[ ACTIONABILITY_REASON_TARGET_AMBIGUOUS ]: __(
		'Target ambiguous',
		'flavor-agent'
	),
	[ ACTIONABILITY_REASON_LOCKED_TARGET ]: __(
		'Locked target',
		'flavor-agent'
	),
	[ ACTIONABILITY_REASON_UNSUPPORTED_OPERATION ]: __(
		'Unsupported operation',
		'flavor-agent'
	),
	[ ACTIONABILITY_REASON_MULTI_TARGET_STRUCTURAL_CHANGE ]: __(
		'Multi-target structural change',
		'flavor-agent'
	),
	[ ACTIONABILITY_REASON_MANUAL_COPY_ONLY ]: __(
		'Manual follow-through',
		'flavor-agent'
	),
} );

export function getActionabilityLabel( tier = '' ) {
	return TIER_LABELS[ tier ] || TIER_LABELS[ ACTIONABILITY_TIER_ADVISORY ];
}

export function getActionabilityReasonLabel( reason = '' ) {
	return REASON_LABELS[ reason ] || '';
}

function normalizeReasons( reasons = [] ) {
	return [
		...new Set(
			( Array.isArray( reasons ) ? reasons : [ reasons ] ).filter(
				( reason ) => typeof reason === 'string' && reason.trim() !== ''
			)
		),
	];
}

function normalizeExecutableOperations( operations = [] ) {
	return Array.isArray( operations ) ? operations.filter( Boolean ) : [];
}

function getRejectionOperations( rejectedOperations = [] ) {
	return Array.isArray( rejectedOperations )
		? rejectedOperations
				.map( ( rejection ) => rejection?.operation )
				.filter( Boolean )
		: [];
}

function mapOperationRejectionCodeToReason( code = '' ) {
	switch ( code ) {
		case BLOCK_OPERATION_ERROR_MISSING_PATTERN_NAME:
		case BLOCK_OPERATION_ERROR_PATTERN_NOT_AVAILABLE:
		case BLOCK_OPERATION_ERROR_ACTION_NOT_ALLOWED:
			return ACTIONABILITY_REASON_PATTERN_NOT_AVAILABLE;
		case BLOCK_OPERATION_ERROR_CLIENT_SERVER_OPERATION_MISMATCH:
		case BLOCK_OPERATION_ERROR_STALE_TARGET:
			return ACTIONABILITY_REASON_TARGET_STALE;
		case BLOCK_OPERATION_ERROR_MISSING_TARGET_CLIENT_ID:
			return ACTIONABILITY_REASON_TARGET_AMBIGUOUS;
		case BLOCK_OPERATION_ERROR_LOCKED_TARGET:
		case BLOCK_OPERATION_ERROR_CONTENT_ONLY_TARGET:
			return ACTIONABILITY_REASON_LOCKED_TARGET;
		case BLOCK_OPERATION_ERROR_CROSS_SURFACE_TARGET:
		case BLOCK_OPERATION_ERROR_MULTI_OPERATION_UNSUPPORTED:
			return ACTIONABILITY_REASON_MULTI_TARGET_STRUCTURAL_CHANGE;
		case BLOCK_OPERATION_ERROR_STRUCTURAL_ACTIONS_DISABLED:
			return ACTIONABILITY_REASON_MISSING_PATTERN_CONTEXT;
		case BLOCK_OPERATION_ERROR_INVALID_TARGET_TYPE:
			return ACTIONABILITY_REASON_UNSUPPORTED_OPERATION;
		default:
			return ACTIONABILITY_REASON_UNSUPPORTED_OPERATION;
	}
}

function getOperationRejectionReasons( rejectedOperations = [] ) {
	return normalizeReasons(
		( Array.isArray( rejectedOperations ) ? rejectedOperations : [] ).map(
			( rejection ) =>
				mapOperationRejectionCodeToReason( rejection?.code || '' )
		)
	);
}

function getSuggestionRejectedOperations( suggestion, validation ) {
	if ( Array.isArray( suggestion?.rejectedOperations ) ) {
		return suggestion.rejectedOperations;
	}

	if ( Array.isArray( validation?.rejectedOperations ) ) {
		return validation.rejectedOperations;
	}

	return [];
}

function getAdvisoryOnlyReason( suggestion, rejectedOperations ) {
	if ( rejectedOperations.length > 0 ) {
		return getOperationRejectionReasons( rejectedOperations );
	}

	if ( suggestion?.type === 'pattern_replacement' ) {
		return ACTIONABILITY_REASON_MISSING_PATTERN_CONTEXT;
	}

	return ACTIONABILITY_REASON_UNSUPPORTED_OPERATION;
}

function getRejectedOrFallbackOperations(
	suggestion,
	rejectedOperationPayloads
) {
	if ( rejectedOperationPayloads.length > 0 ) {
		return rejectedOperationPayloads;
	}

	if ( Array.isArray( suggestion?.operations ) ) {
		return suggestion.operations;
	}

	return [];
}

function getNonExecutableReason( hasProposedUpdates, rejectedOperations ) {
	if ( rejectedOperations.length > 0 ) {
		return getOperationRejectionReasons( rejectedOperations );
	}

	return hasProposedUpdates
		? ACTIONABILITY_REASON_UNSUPPORTED_OPERATION
		: ACTIONABILITY_REASON_MANUAL_COPY_ONLY;
}

function buildActionability( tier, reasons = [], options = {} ) {
	const resolvedTier = TIER_LABELS[ tier ]
		? tier
		: ACTIONABILITY_TIER_ADVISORY;
	const resolvedReasons = normalizeReasons( reasons );
	let resolvedBlockers = [];

	if ( resolvedTier === ACTIONABILITY_TIER_ADVISORY ) {
		resolvedBlockers =
			resolvedReasons.length > 0
				? resolvedReasons
				: [ ACTIONABILITY_REASON_MANUAL_COPY_ONLY ];
	}
	const executableOperations = normalizeExecutableOperations(
		options.executableOperations
	);
	const advisoryOperationsRejected = normalizeExecutableOperations(
		options.advisoryOperationsRejected
	);
	const reviewOperations = normalizeExecutableOperations(
		options.reviewOperations
	);
	const actionability = {
		tier: resolvedTier,
		source: ACTIONABILITY_SOURCE_VALIDATOR,
		blockers: resolvedBlockers,
		reasons:
			resolvedReasons.length > 0
				? resolvedReasons
				: [ ACTIONABILITY_REASON_MANUAL_COPY_ONLY ],
		executableOperations,
	};

	if ( advisoryOperationsRejected.length > 0 ) {
		actionability.advisoryOperationsRejected = advisoryOperationsRejected;
	}

	if ( reviewOperations.length > 0 ) {
		actionability.reviewOperations = reviewOperations;
	}

	return actionability;
}

export function classifyBlockSuggestionActionability( {
	suggestion = {},
	allowedUpdates = {},
	isAdvisoryOnly = false,
	restrictions = {},
	operationValidation = null,
} = {} ) {
	const hasAllowedUpdates =
		allowedUpdates &&
		typeof allowedUpdates === 'object' &&
		Object.keys( allowedUpdates ).length > 0;
	const hasProposedUpdates =
		suggestion?.attributeUpdates &&
		typeof suggestion.attributeUpdates === 'object' &&
		! Array.isArray( suggestion.attributeUpdates ) &&
		Object.keys( suggestion.attributeUpdates ).length > 0;
	const validatedOperations = Array.isArray( operationValidation?.operations )
		? operationValidation.operations
		: [];
	const rejectedOperations = getSuggestionRejectedOperations(
		suggestion,
		operationValidation
	);
	const rejectedOperationPayloads =
		getRejectionOperations( rejectedOperations );
	const hasSingleValidatedOperation = validatedOperations.length === 1;

	if ( isAdvisoryOnly && ! hasSingleValidatedOperation ) {
		return buildActionability(
			ACTIONABILITY_TIER_ADVISORY,
			getAdvisoryOnlyReason( suggestion, rejectedOperations ),
			{
				advisoryOperationsRejected: getRejectedOrFallbackOperations(
					suggestion,
					rejectedOperationPayloads
				),
			}
		);
	}

	if ( hasAllowedUpdates ) {
		return buildActionability(
			ACTIONABILITY_TIER_INLINE_SAFE,
			ACTIONABILITY_REASON_SAFE_LOCAL_ATTRIBUTE_UPDATE,
			{
				executableOperations: [
					{
						type: 'update_block_attributes',
						attributeUpdates: allowedUpdates,
					},
				],
				advisoryOperationsRejected: rejectedOperationPayloads,
				reviewOperations: hasSingleValidatedOperation
					? validatedOperations
					: [],
			}
		);
	}

	if ( hasSingleValidatedOperation ) {
		return buildActionability(
			ACTIONABILITY_TIER_REVIEW_SAFE,
			ACTIONABILITY_REASON_VALID_STRUCTURAL_OPERATION,
			{
				executableOperations: validatedOperations,
			}
		);
	}

	if (
		restrictions?.disabled ||
		( restrictions?.contentOnly && hasProposedUpdates )
	) {
		return buildActionability(
			ACTIONABILITY_TIER_ADVISORY,
			ACTIONABILITY_REASON_LOCKED_TARGET
		);
	}

	return buildActionability(
		ACTIONABILITY_TIER_ADVISORY,
		getNonExecutableReason( hasProposedUpdates, rejectedOperations ),
		{
			advisoryOperationsRejected: rejectedOperationPayloads,
		}
	);
}

export function classifyOperationActionability( {
	operations = [],
	validation = null,
} = {} ) {
	const hasOperations = Array.isArray( operations ) && operations.length > 0;
	const validatedOperations = Array.isArray( validation?.operations )
		? validation.operations
		: [];

	if ( validation?.ok && validatedOperations.length === 1 ) {
		return buildActionability(
			ACTIONABILITY_TIER_REVIEW_SAFE,
			ACTIONABILITY_REASON_VALID_STRUCTURAL_OPERATION,
			{
				executableOperations: validatedOperations,
			}
		);
	}

	if ( ! hasOperations ) {
		return buildActionability(
			ACTIONABILITY_TIER_ADVISORY,
			ACTIONABILITY_REASON_MANUAL_COPY_ONLY
		);
	}

	const validationError =
		typeof validation?.error === 'string' ? validation.error : '';
	let reasons = ACTIONABILITY_REASON_UNSUPPORTED_OPERATION;

	if (
		Array.isArray( validation?.rejectedOperations ) &&
		validation.rejectedOperations.length > 0
	) {
		reasons = getOperationRejectionReasons( validation.rejectedOperations );
	} else if (
		/overlap|multiple|more than|at most/i.test( validationError )
	) {
		reasons = ACTIONABILITY_REASON_MULTI_TARGET_STRUCTURAL_CHANGE;
	}

	return buildActionability( ACTIONABILITY_TIER_ADVISORY, reasons, {
		advisoryOperationsRejected: operations,
	} );
}

export function summarizeActionability( actionabilities = [] ) {
	const summary = {
		total: 0,
		tiers: {
			[ ACTIONABILITY_TIER_INLINE_SAFE ]: 0,
			[ ACTIONABILITY_TIER_REVIEW_SAFE ]: 0,
			[ ACTIONABILITY_TIER_ADVISORY ]: 0,
		},
		reasons: {},
	};

	for ( const actionability of actionabilities ) {
		const normalized = buildActionability(
			actionability?.tier,
			actionability?.reasons || actionability?.blockers
		);

		summary.total += 1;
		summary.tiers[ normalized.tier ] += 1;

		for ( const reason of normalized.reasons ) {
			summary.reasons[ reason ] = ( summary.reasons[ reason ] || 0 ) + 1;
		}
	}

	return summary;
}
