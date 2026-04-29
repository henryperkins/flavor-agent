export const ACTIONABILITY_TIER_INLINE_SAFE = 'inline-safe';
export const ACTIONABILITY_TIER_REVIEW_SAFE = 'review-safe';
export const ACTIONABILITY_TIER_ADVISORY = 'advisory';
export const ACTIONABILITY_SOURCE_VALIDATOR = 'validator';

export const ACTIONABILITY_REASON_SAFE_LOCAL_ATTRIBUTE_UPDATE =
	'safe-local-attribute-update';
export const ACTIONABILITY_REASON_VALID_STRUCTURAL_OPERATION =
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
	[ ACTIONABILITY_TIER_INLINE_SAFE ]: 'Inline-safe',
	[ ACTIONABILITY_TIER_REVIEW_SAFE ]: 'Review-safe',
	[ ACTIONABILITY_TIER_ADVISORY ]: 'Advisory',
} );

const REASON_LABELS = Object.freeze( {
	[ ACTIONABILITY_REASON_SAFE_LOCAL_ATTRIBUTE_UPDATE ]:
		'Safe local attribute update',
	[ ACTIONABILITY_REASON_VALID_STRUCTURAL_OPERATION ]:
		'Valid structural operation',
	[ ACTIONABILITY_REASON_MISSING_PATTERN_CONTEXT ]: 'Missing pattern context',
	[ ACTIONABILITY_REASON_PATTERN_NOT_AVAILABLE ]: 'Pattern unavailable',
	[ ACTIONABILITY_REASON_TARGET_STALE ]: 'Target stale',
	[ ACTIONABILITY_REASON_TARGET_AMBIGUOUS ]: 'Target ambiguous',
	[ ACTIONABILITY_REASON_LOCKED_TARGET ]: 'Locked target',
	[ ACTIONABILITY_REASON_UNSUPPORTED_OPERATION ]: 'Unsupported operation',
	[ ACTIONABILITY_REASON_MULTI_TARGET_STRUCTURAL_CHANGE ]:
		'Multi-target structural change',
	[ ACTIONABILITY_REASON_MANUAL_COPY_ONLY ]: 'Manual follow-through',
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

export function buildActionability( tier, reasons = [], options = {} ) {
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

	return actionability;
}

export function classifyBlockSuggestionActionability( {
	suggestion = {},
	allowedUpdates = {},
	isAdvisoryOnly = false,
	restrictions = {},
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

	if ( isAdvisoryOnly ) {
		return buildActionability(
			ACTIONABILITY_TIER_ADVISORY,
			suggestion?.type === 'pattern_replacement'
				? ACTIONABILITY_REASON_MISSING_PATTERN_CONTEXT
				: ACTIONABILITY_REASON_UNSUPPORTED_OPERATION,
			{
				advisoryOperationsRejected: Array.isArray(
					suggestion?.operations
				)
					? suggestion.operations
					: [],
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
		hasProposedUpdates
			? ACTIONABILITY_REASON_UNSUPPORTED_OPERATION
			: ACTIONABILITY_REASON_MANUAL_COPY_ONLY
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

	if ( validation?.ok && validatedOperations.length > 0 ) {
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
	const reason = /overlap|multiple|more than|at most/i.test( validationError )
		? ACTIONABILITY_REASON_MULTI_TARGET_STRUCTURAL_CHANGE
		: ACTIONABILITY_REASON_UNSUPPORTED_OPERATION;

	return buildActionability( ACTIONABILITY_TIER_ADVISORY, reason, {
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
