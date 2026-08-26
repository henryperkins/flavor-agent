import {
	BLOCK_OPERATION_ERROR_ACTION_NOT_ALLOWED,
	BLOCK_OPERATION_ERROR_CONTENT_ONLY_TARGET,
	BLOCK_OPERATION_ERROR_LOCKED_TARGET,
	BLOCK_OPERATION_ERROR_PATTERN_NOT_AVAILABLE,
	BLOCK_OPERATION_ERROR_STALE_TARGET,
	BLOCK_OPERATION_ERROR_STRUCTURAL_ACTIONS_DISABLED,
	BLOCK_OPERATION_INSERT_PATTERN,
	BLOCK_OPERATION_REPLACE_BLOCK_WITH_PATTERN,
	validateBlockOperationSequence,
} from './block-operation-catalog';
import { buildContextSignature } from './context-signature';
import { cloneBlock } from '@wordpress/blocks';
import { __, sprintf } from '@wordpress/i18n';

const STRUCTURAL_DRIFT_UNDO_ERROR = __(
	'The block structure changed after Flavor Agent applied this suggestion and cannot be undone automatically.',
	'flavor-agent'
);
const STRUCTURAL_ROLLBACK_ERROR = __(
	'Flavor Agent could not safely roll back the structural change. Review the block structure before continuing.',
	'flavor-agent'
);
const STRUCTURAL_RESTORE_ERROR = __(
	'Flavor Agent could not restore the replaced block after the structural change failed. Review the block structure before continuing.',
	'flavor-agent'
);
const STRUCTURAL_UNDO_PERMISSION_ERROR = __(
	'The current editor constraints do not allow this structural action to be undone automatically.',
	'flavor-agent'
);
const STRUCTURAL_UNDO_INCOMPLETE_ERROR = __(
	'The structural action could not be undone completely. Review the block structure before continuing.',
	'flavor-agent'
);
const STRUCTURAL_UNDO_SESSION_ERROR = __(
	'The recorded blocks are no longer available in this editor session, so this structural action cannot be undone automatically.',
	'flavor-agent'
);
const STRUCTURAL_MISSING_UNDO_ERROR = __(
	'This block structural action is missing its recorded structure and cannot be undone automatically.',
	'flavor-agent'
);

function cloneValue( value ) {
	if ( value === undefined ) {
		return undefined;
	}

	return JSON.parse( JSON.stringify( value ) );
}

function normalizeSerializableValue( value ) {
	if ( Array.isArray( value ) ) {
		return value.map( ( item ) =>
			normalizeSerializableValue( item === undefined ? null : item )
		);
	}

	if ( value && typeof value === 'object' ) {
		return Object.fromEntries(
			Object.entries( value )
				.filter( ( [ , entryValue ] ) => entryValue !== undefined )
				.sort( ( [ leftKey ], [ rightKey ] ) =>
					leftKey.localeCompare( rightKey )
				)
				.map( ( [ key, entryValue ] ) => [
					key,
					normalizeSerializableValue( entryValue ),
				] )
		);
	}

	return value;
}

function normalizeBlockSnapshot( block ) {
	if ( ! block ) {
		return null;
	}

	return {
		name: block.name || '',
		attributes: normalizeSerializableValue( block.attributes || {} ),
		innerBlocks: Array.isArray( block.innerBlocks )
			? block.innerBlocks
					.filter( Boolean )
					.map( ( innerBlock ) =>
						normalizeBlockSnapshot( innerBlock )
					)
			: [],
	};
}

function normalizeBlockSnapshots( blocks = [] ) {
	return Array.isArray( blocks )
		? blocks
				.filter( Boolean )
				.map( ( block ) => normalizeBlockSnapshot( block ) )
		: [];
}

function cloneBlockTree( blocks = [] ) {
	return Array.isArray( blocks ) ? cloneValue( blocks ) : [];
}

function getRequestedTopLevelClientIds( blocks = [] ) {
	const ids = blocks.map( ( block ) => block?.clientId || '' );

	return ids.every( Boolean ) && new Set( ids ).size === ids.length
		? ids
		: [];
}

function canInsertParsedBlocks( blocks, rootClientId, blockEditorSelect ) {
	if ( typeof blockEditorSelect?.canInsertBlockType !== 'function' ) {
		return false;
	}

	return blocks.every(
		( block ) =>
			Boolean( block?.name ) &&
			blockEditorSelect.canInsertBlockType( block.name, rootClientId ) ===
				true
	);
}

function parsedBlocksAreRollbackCapable( blocks = [] ) {
	return blocks.every( ( block ) => ! block?.attributes?.lock?.remove );
}

function blockSnapshotsMatch( left = [], right = [] ) {
	return (
		JSON.stringify( normalizeBlockSnapshots( left ) ) ===
		JSON.stringify( normalizeBlockSnapshots( right ) )
	);
}

function getExecutableOperations( suggestion = {} ) {
	const actionability = suggestion?.actionability || suggestion?.eligibility;

	if ( Array.isArray( actionability?.executableOperations ) ) {
		return actionability.executableOperations;
	}

	return [];
}

function findBlockLocation( blocks = [], clientId, rootClientId = null ) {
	for ( let index = 0; index < blocks.length; index++ ) {
		const block = blocks[ index ];

		if ( block?.clientId === clientId ) {
			return {
				block,
				index,
				rootClientId,
			};
		}

		const nested = findBlockLocation(
			Array.isArray( block?.innerBlocks ) ? block.innerBlocks : [],
			clientId,
			block?.clientId || null
		);

		if ( nested ) {
			return nested;
		}
	}

	return null;
}

function getRootBlocks( blockEditorSelect = {}, rootClientId = null ) {
	const blocks = blockEditorSelect.getBlocks?.( rootClientId );

	if ( Array.isArray( blocks ) ) {
		return blocks;
	}

	return [];
}

function getLiveBlockLocation( blockEditorSelect = {}, targetClientId = '' ) {
	if ( ! targetClientId ) {
		return null;
	}

	const rootClientId =
		blockEditorSelect.getBlockRootClientId?.( targetClientId ) ?? null;
	const index = blockEditorSelect.getBlockIndex?.(
		targetClientId,
		rootClientId
	);
	const liveBlock = blockEditorSelect.getBlock?.( targetClientId ) || null;

	if ( liveBlock?.clientId && Number.isInteger( index ) && index >= 0 ) {
		return {
			block: liveBlock,
			index,
			rootClientId,
		};
	}

	return findBlockLocation(
		getRootBlocks( blockEditorSelect, null ),
		targetClientId
	);
}

function buildRootLocator( rootClientId, blockEditorSelect = {} ) {
	if ( ! rootClientId ) {
		return {
			type: 'root',
			rootClientId: null,
		};
	}

	const rootBlock = blockEditorSelect.getBlock?.( rootClientId ) || null;

	return {
		type: 'block',
		rootClientId,
		blockName: rootBlock?.name || '',
	};
}

function resolveRootClientId( rootLocator = null ) {
	if (
		rootLocator &&
		Object.prototype.hasOwnProperty.call( rootLocator, 'rootClientId' )
	) {
		return rootLocator.rootClientId || null;
	}

	return null;
}

function getBlockSlice( blockEditorSelect = {}, rootLocator, index, count ) {
	if ( ! Number.isInteger( index ) || ! Number.isInteger( count ) ) {
		return [];
	}

	const rootClientId = resolveRootClientId( rootLocator );
	const rootBlocks = getRootBlocks( blockEditorSelect, rootClientId );

	return rootBlocks.slice( index, index + count );
}

function getRootSnapshot( blockEditorSelect = {}, rootLocator ) {
	return normalizeBlockSnapshots(
		getRootBlocks( blockEditorSelect, resolveRootClientId( rootLocator ) )
	);
}

function buildStructuralSignature( operations = [], blockEditorSelect = {} ) {
	const rootSnapshots = new Map();

	for ( const operation of operations ) {
		const rootLocator = operation?.rootLocator || {
			type: 'root',
			rootClientId: null,
		};
		const key = JSON.stringify( rootLocator );

		if ( rootSnapshots.has( key ) ) {
			continue;
		}

		rootSnapshots.set( key, {
			rootLocator,
			blocks: getRootSnapshot( blockEditorSelect, rootLocator ),
		} );
	}

	return buildContextSignature( {
		roots: Array.from( rootSnapshots.values() ),
	} );
}

function mapValidationFailureCode( validation ) {
	const code = validation?.rejectedOperations?.[ 0 ]?.code || '';

	switch ( code ) {
		case BLOCK_OPERATION_ERROR_STALE_TARGET:
			return 'target_mismatch';
		case BLOCK_OPERATION_ERROR_STRUCTURAL_ACTIONS_DISABLED:
			return 'structural_actions_disabled';
		case BLOCK_OPERATION_ERROR_LOCKED_TARGET:
			return 'locked_target';
		case BLOCK_OPERATION_ERROR_CONTENT_ONLY_TARGET:
			return 'content_only_target';
		case BLOCK_OPERATION_ERROR_PATTERN_NOT_AVAILABLE:
			return 'pattern_missing';
		case BLOCK_OPERATION_ERROR_ACTION_NOT_ALLOWED:
			return 'operation_invalid';
		default:
			return 'operation_invalid';
	}
}

export function getBlockStructuralActionErrorMessage( code = '' ) {
	const messages = {
		target_missing: __(
			'The selected block is no longer available. Refresh recommendations and try again.',
			'flavor-agent'
		),
		target_mismatch: __(
			'The selected block no longer matches the reviewed operation. Refresh recommendations and try again.',
			'flavor-agent'
		),
		pattern_missing: __(
			'The recommended pattern is no longer available. Refresh recommendations and try again.',
			'flavor-agent'
		),
		locked_target: __(
			'The selected block is locked and cannot be structurally changed.',
			'flavor-agent'
		),
		content_only_target: __(
			'The selected block is content-only and cannot be structurally changed.',
			'flavor-agent'
		),
		structural_actions_disabled: __(
			'Block structural actions are disabled for this environment.',
			'flavor-agent'
		),
		operation_invalid: __(
			'The structural operation is no longer valid. Refresh recommendations and try again.',
			'flavor-agent'
		),
		rollback_failed: STRUCTURAL_ROLLBACK_ERROR,
		restore_failed: STRUCTURAL_RESTORE_ERROR,
	};

	return messages[ code ] || messages.operation_invalid;
}

export function prepareBlockStructuralOperation( {
	operation,
	blockOperationContext,
	blockEditorSelect,
} ) {
	const targetClientId = operation?.targetClientId || '';
	const liveBlock = blockEditorSelect?.getBlock?.( targetClientId );

	if ( ! liveBlock?.clientId ) {
		return { ok: false, code: 'target_missing' };
	}

	if (
		operation?.expectedTarget?.name &&
		liveBlock.name !== operation.expectedTarget.name
	) {
		return { ok: false, code: 'target_mismatch' };
	}

	if (
		operation?.targetSignature &&
		blockOperationContext?.targetSignature &&
		operation.targetSignature !== blockOperationContext.targetSignature
	) {
		return { ok: false, code: 'target_mismatch' };
	}

	const editingMode =
		blockEditorSelect?.getBlockEditingMode?.( liveBlock.clientId ) ||
		blockOperationContext?.editingMode ||
		'default';
	const validation = validateBlockOperationSequence( [ operation ], {
		...blockOperationContext,
		targetBlockName: liveBlock.name,
		editingMode,
		isContentOnly:
			blockOperationContext?.isContentOnly === true ||
			blockOperationContext?.isInsideContentOnly === true ||
			editingMode === 'contentOnly',
	} );

	if ( ! validation.ok || validation.operations.length !== 1 ) {
		return {
			ok: false,
			code: mapValidationFailureCode( validation ),
			validation,
		};
	}

	return {
		ok: true,
		operation: validation.operations[ 0 ],
		liveBlock,
	};
}

function parseBlocksForOperation( operation, parsePatternBlocks ) {
	if ( typeof parsePatternBlocks !== 'function' ) {
		return {
			ok: false,
			code: 'pattern_missing',
			error: getBlockStructuralActionErrorMessage( 'pattern_missing' ),
		};
	}

	try {
		const blocks = parsePatternBlocks( operation.patternName, operation );

		if ( ! Array.isArray( blocks ) || blocks.length === 0 ) {
			return {
				ok: false,
				code: 'pattern_missing',
				error: getBlockStructuralActionErrorMessage(
					'pattern_missing'
				),
			};
		}

		return {
			ok: true,
			blocks: blocks.map( ( block ) => cloneBlock( block ) ),
		};
	} catch ( error ) {
		const code =
			error?.code === 'pattern_missing'
				? 'pattern_missing'
				: 'operation_invalid';

		return {
			ok: false,
			code,
			error:
				error?.message || getBlockStructuralActionErrorMessage( code ),
		};
	}
}

function normalizeRootClientId( rootClientId ) {
	return rootClientId || null;
}

function prepareParsedInsertion(
	blocks,
	rootClientId,
	blockEditorSelect = {}
) {
	const requestedClientIds = getRequestedTopLevelClientIds( blocks );

	if (
		requestedClientIds.length !== blocks.length ||
		! parsedBlocksAreRollbackCapable( blocks ) ||
		typeof blockEditorSelect.getBlock !== 'function' ||
		typeof blockEditorSelect.getBlockRootClientId !== 'function' ||
		typeof blockEditorSelect.canRemoveBlocks !== 'function' ||
		! canInsertParsedBlocks( blocks, rootClientId, blockEditorSelect )
	) {
		return null;
	}

	const beforePresentClientIds = new Set(
		requestedClientIds.filter( ( clientId ) =>
			Boolean( blockEditorSelect.getBlock( clientId ) )
		)
	);

	if ( beforePresentClientIds.size > 0 ) {
		return null;
	}

	return {
		requestedClientIds,
		beforePresentClientIds,
	};
}

function getNewlyPresentRequestedClientIds(
	requestedClientIds,
	beforePresentClientIds,
	blockEditorSelect
) {
	return requestedClientIds.filter(
		( clientId ) =>
			! beforePresentClientIds.has( clientId ) &&
			Boolean( blockEditorSelect.getBlock?.( clientId ) )
	);
}

function insertedBlocksMatchRequest( {
	blocks,
	clientIds,
	index,
	rootClientId,
	rootLocator,
	blockEditorSelect,
} ) {
	if ( clientIds.length !== blocks.length ) {
		return false;
	}

	const insertedSlice = getBlockSlice(
		blockEditorSelect,
		rootLocator,
		index,
		blocks.length
	);
	const sliceClientIds = insertedSlice.map(
		( block ) => block?.clientId || ''
	);

	return (
		sliceClientIds.length === clientIds.length &&
		sliceClientIds.every(
			( clientId, clientIndex ) => clientId === clientIds[ clientIndex ]
		) &&
		blockSnapshotsMatch( insertedSlice, blocks ) &&
		clientIds.every(
			( clientId ) =>
				Boolean( blockEditorSelect.getBlock?.( clientId ) ) &&
				normalizeRootClientId(
					blockEditorSelect.getBlockRootClientId?.( clientId )
				) === normalizeRootClientId( rootClientId )
		)
	);
}

function removeExactInsertedBlocks(
	clientIds,
	blockEditorSelect,
	blockEditorDispatch
) {
	if ( clientIds.length === 0 ) {
		return true;
	}

	if (
		typeof blockEditorSelect?.canRemoveBlocks !== 'function' ||
		blockEditorSelect.canRemoveBlocks( clientIds ) !== true
	) {
		return false;
	}

	blockEditorDispatch.removeBlocks?.( clientIds, false );

	return clientIds.every(
		( clientId ) => ! blockEditorSelect.getBlock?.( clientId )
	);
}

function restoreRemovedBlocks(
	operation,
	blockEditorSelect,
	blockEditorDispatch
) {
	const removedBlocks = cloneBlockTree( operation?.removedBlocksSnapshot );

	if ( removedBlocks.length === 0 ) {
		return false;
	}

	const rootClientId = resolveRootClientId( operation.rootLocator );

	if (
		! canInsertParsedBlocks(
			removedBlocks,
			rootClientId,
			blockEditorSelect
		)
	) {
		return false;
	}

	blockEditorDispatch.insertBlocks?.(
		removedBlocks,
		operation.index,
		// Core keys the controlled top-level block list by '' and its
		// insertBlocks reducer does not normalize a null root to it: dispatching
		// null records the blocks in byClientId without adding them to the root
		// order, so the restored block never renders. Normalize to '' (nested
		// string roots are preserved by resolveRootClientId).
		rootClientId ?? '',
		true,
		0
	);

	const restoredSlice = getBlockSlice(
		blockEditorSelect,
		operation.rootLocator,
		operation.index,
		removedBlocks.length
	);
	const expectedClientIds = getRequestedTopLevelClientIds( removedBlocks );

	return (
		expectedClientIds.length === removedBlocks.length &&
		restoredSlice.length === removedBlocks.length &&
		restoredSlice.every(
			( block, index ) => block?.clientId === expectedClientIds[ index ]
		) &&
		blockSnapshotsMatch( restoredSlice, removedBlocks ) &&
		expectedClientIds.every(
			( clientId ) =>
				Boolean( blockEditorSelect.getBlock?.( clientId ) ) &&
				normalizeRootClientId(
					blockEditorSelect.getBlockRootClientId?.( clientId )
				) === normalizeRootClientId( rootClientId )
		)
	);
}

function applyInsertPatternOperation( {
	operation,
	liveBlock,
	blockEditorSelect,
	blockEditorDispatch,
	parsePatternBlocks,
} ) {
	const parsed = parseBlocksForOperation( operation, parsePatternBlocks );

	if ( ! parsed.ok ) {
		return parsed;
	}

	const location = getLiveBlockLocation(
		blockEditorSelect,
		liveBlock.clientId
	);

	if ( ! location ) {
		return {
			ok: false,
			code: 'target_missing',
			error: getBlockStructuralActionErrorMessage( 'target_missing' ),
		};
	}

	const insertion = prepareParsedInsertion(
		parsed.blocks,
		location.rootClientId,
		blockEditorSelect
	);

	if ( ! insertion ) {
		return {
			ok: false,
			code: 'operation_invalid',
			error: getBlockStructuralActionErrorMessage( 'operation_invalid' ),
		};
	}

	if (
		! canInsertParsedBlocks(
			parsed.blocks,
			location.rootClientId,
			blockEditorSelect
		)
	) {
		return {
			ok: false,
			code: 'operation_invalid',
			error: getBlockStructuralActionErrorMessage( 'operation_invalid' ),
		};
	}

	const rootLocator = buildRootLocator(
		location.rootClientId,
		blockEditorSelect
	);
	const index =
		operation.position === 'insert_before'
			? location.index
			: location.index + 1;

	blockEditorDispatch.insertBlocks?.(
		parsed.blocks,
		index,
		location.rootClientId,
		true,
		0
	);

	const insertedClientIds = getNewlyPresentRequestedClientIds(
		insertion.requestedClientIds,
		insertion.beforePresentClientIds,
		blockEditorSelect
	);
	const insertedBlocksAreValid = insertedBlocksMatchRequest( {
		blocks: parsed.blocks,
		clientIds: insertedClientIds,
		index,
		rootClientId: location.rootClientId,
		rootLocator,
		blockEditorSelect,
	} );
	const insertedBlocksAreRemovable =
		insertedClientIds.length > 0 &&
		blockEditorSelect.canRemoveBlocks( insertedClientIds ) === true;

	if ( ! insertedBlocksAreValid || ! insertedBlocksAreRemovable ) {
		if (
			! removeExactInsertedBlocks(
				insertedClientIds,
				blockEditorSelect,
				blockEditorDispatch
			)
		) {
			return {
				ok: false,
				code: 'rollback_failed',
				error: STRUCTURAL_ROLLBACK_ERROR,
			};
		}

		return {
			ok: false,
			code: 'operation_invalid',
			error: sprintf(
				/* translators: %s: block pattern name. */
				__(
					'Pattern “%s” could not be inserted for the selected block.',
					'flavor-agent'
				),
				operation.patternName || __( 'unknown', 'flavor-agent' )
			),
		};
	}

	return {
		ok: true,
		operation: {
			...operation,
			rootLocator,
			index,
			insertedClientIds,
			insertedBlocksSnapshot: normalizeBlockSnapshots( parsed.blocks ),
		},
	};
}

function applyReplaceBlockWithPatternOperation( {
	operation,
	liveBlock,
	blockEditorSelect,
	blockEditorDispatch,
	parsePatternBlocks,
} ) {
	const parsed = parseBlocksForOperation( operation, parsePatternBlocks );

	if ( ! parsed.ok ) {
		return parsed;
	}

	const location = getLiveBlockLocation(
		blockEditorSelect,
		liveBlock.clientId
	);

	if ( ! location ) {
		return {
			ok: false,
			code: 'target_missing',
			error: getBlockStructuralActionErrorMessage( 'target_missing' ),
		};
	}

	const removedBlocksSnapshot = cloneBlockTree( [ liveBlock ] );
	const insertion = prepareParsedInsertion(
		parsed.blocks,
		location.rootClientId,
		blockEditorSelect
	);

	if (
		! insertion ||
		typeof blockEditorSelect.canRemoveBlock !== 'function' ||
		blockEditorSelect.canRemoveBlock( liveBlock.clientId ) !== true ||
		! canInsertParsedBlocks(
			removedBlocksSnapshot,
			location.rootClientId,
			blockEditorSelect
		)
	) {
		return {
			ok: false,
			code: 'operation_invalid',
			error: getBlockStructuralActionErrorMessage( 'operation_invalid' ),
		};
	}

	const rootLocator = buildRootLocator(
		location.rootClientId,
		blockEditorSelect
	);

	const baseOperation = {
		...operation,
		rootLocator,
		index: location.index,
		removedBlocksSnapshot,
		insertedBlocksSnapshot: normalizeBlockSnapshots( parsed.blocks ),
	};

	if ( blockEditorSelect.canRemoveBlock( liveBlock.clientId ) !== true ) {
		return {
			ok: false,
			code: 'operation_invalid',
			error: getBlockStructuralActionErrorMessage( 'operation_invalid' ),
		};
	}

	blockEditorDispatch.removeBlocks?.( [ liveBlock.clientId ], false );

	if ( blockEditorSelect.getBlock?.( liveBlock.clientId ) ) {
		return {
			ok: false,
			code: 'operation_invalid',
			error: __(
				'The selected block could not be removed before replacement.',
				'flavor-agent'
			),
		};
	}

	if (
		! canInsertParsedBlocks(
			parsed.blocks,
			location.rootClientId,
			blockEditorSelect
		)
	) {
		if (
			! restoreRemovedBlocks(
				baseOperation,
				blockEditorSelect,
				blockEditorDispatch
			)
		) {
			return {
				ok: false,
				code: 'restore_failed',
				error: STRUCTURAL_RESTORE_ERROR,
			};
		}

		return {
			ok: false,
			code: 'operation_invalid',
			error: getBlockStructuralActionErrorMessage( 'operation_invalid' ),
		};
	}

	blockEditorDispatch.insertBlocks?.(
		parsed.blocks,
		location.index,
		location.rootClientId,
		true,
		0
	);

	const replacementClientIds = getNewlyPresentRequestedClientIds(
		insertion.requestedClientIds,
		insertion.beforePresentClientIds,
		blockEditorSelect
	);
	const replacementBlocksAreValid = insertedBlocksMatchRequest( {
		blocks: parsed.blocks,
		clientIds: replacementClientIds,
		index: location.index,
		rootClientId: location.rootClientId,
		rootLocator,
		blockEditorSelect,
	} );
	const replacementBlocksAreRemovable =
		replacementClientIds.length > 0 &&
		blockEditorSelect.canRemoveBlocks( replacementClientIds ) === true;

	if ( ! replacementBlocksAreValid || ! replacementBlocksAreRemovable ) {
		if (
			! removeExactInsertedBlocks(
				replacementClientIds,
				blockEditorSelect,
				blockEditorDispatch
			)
		) {
			return {
				ok: false,
				code: 'rollback_failed',
				error: STRUCTURAL_ROLLBACK_ERROR,
			};
		}

		if (
			! restoreRemovedBlocks(
				baseOperation,
				blockEditorSelect,
				blockEditorDispatch
			)
		) {
			return {
				ok: false,
				code: 'restore_failed',
				error: STRUCTURAL_RESTORE_ERROR,
			};
		}

		return {
			ok: false,
			code: 'operation_invalid',
			error: sprintf(
				/* translators: %s: block pattern name. */
				__(
					'Pattern “%s” could not replace the selected block.',
					'flavor-agent'
				),
				operation.patternName || __( 'unknown', 'flavor-agent' )
			),
		};
	}

	return {
		ok: true,
		operation: {
			...baseOperation,
			replacementClientIds,
			insertedBlocksSnapshot: normalizeBlockSnapshots( parsed.blocks ),
		},
	};
}

export function applyBlockStructuralSuggestionOperations( {
	suggestion,
	blockOperationContext,
	blockEditorSelect,
	blockEditorDispatch,
	parsePatternBlocks,
} ) {
	const executableOperations = getExecutableOperations( suggestion );

	if ( executableOperations.length !== 1 ) {
		return {
			ok: false,
			code: 'operation_invalid',
			error: getBlockStructuralActionErrorMessage( 'operation_invalid' ),
		};
	}

	const prepareResult = prepareBlockStructuralOperation( {
		operation: executableOperations[ 0 ],
		blockOperationContext,
		blockEditorSelect,
	} );

	if ( ! prepareResult.ok ) {
		return {
			...prepareResult,
			error: getBlockStructuralActionErrorMessage( prepareResult.code ),
		};
	}

	const operation = prepareResult.operation;
	const beforeRootLocator = buildRootLocator(
		getLiveBlockLocation(
			blockEditorSelect,
			prepareResult.liveBlock.clientId
		)?.rootClientId ?? null,
		blockEditorSelect
	);
	const beforeSignature = buildStructuralSignature(
		[ { rootLocator: beforeRootLocator } ],
		blockEditorSelect
	);
	const applyArgs = {
		operation,
		liveBlock: prepareResult.liveBlock,
		blockEditorSelect,
		blockEditorDispatch,
		parsePatternBlocks,
	};
	const result =
		operation.type === BLOCK_OPERATION_REPLACE_BLOCK_WITH_PATTERN
			? applyReplaceBlockWithPatternOperation( applyArgs )
			: applyInsertPatternOperation( applyArgs );

	if ( ! result.ok ) {
		return {
			...result,
			beforeSignature,
		};
	}

	const operations = [ result.operation ];
	const afterSignature = buildStructuralSignature(
		operations,
		blockEditorSelect
	);

	return {
		ok: true,
		operations,
		beforeSignature,
		afterSignature,
	};
}

export function getBlockStructuralActivitySignature(
	activity,
	blockEditorSelect = {}
) {
	let operations = [];

	if ( Array.isArray( activity?.after?.operations ) ) {
		operations = activity.after.operations;
	} else if ( Array.isArray( activity?.operations ) ) {
		operations = activity.operations;
	}

	if ( operations.length === 0 ) {
		return '';
	}

	return buildStructuralSignature( operations, blockEditorSelect );
}

function getOperationRuntimeClientIds( operation ) {
	switch ( operation?.type ) {
		case BLOCK_OPERATION_INSERT_PATTERN:
			return operation.insertedClientIds;
		case BLOCK_OPERATION_REPLACE_BLOCK_WITH_PATTERN:
			return operation.replacementClientIds;
		default:
			return null;
	}
}

function validateStructuralUndoOperations(
	operations,
	blockEditorSelect = {}
) {
	const seenClientIds = new Set();

	for ( const operation of operations ) {
		const runtimeClientIds = getOperationRuntimeClientIds( operation );

		if (
			! Array.isArray( runtimeClientIds ) ||
			runtimeClientIds.length === 0 ||
			new Set( runtimeClientIds ).size !== runtimeClientIds.length ||
			runtimeClientIds.some(
				( clientId ) =>
					typeof clientId !== 'string' ||
					! clientId ||
					seenClientIds.has( clientId )
			)
		) {
			return {
				ok: false,
				error: STRUCTURAL_MISSING_UNDO_ERROR,
			};
		}

		runtimeClientIds.forEach( ( clientId ) =>
			seenClientIds.add( clientId )
		);

		if (
			typeof blockEditorSelect.getBlock !== 'function' ||
			typeof blockEditorSelect.getBlockRootClientId !== 'function'
		) {
			return {
				ok: false,
				error: STRUCTURAL_UNDO_SESSION_ERROR,
			};
		}

		const rootClientId = resolveRootClientId( operation.rootLocator );
		const runtimeBlocks = runtimeClientIds.map( ( clientId ) =>
			blockEditorSelect.getBlock( clientId )
		);

		if (
			runtimeBlocks.some( ( block ) => ! block?.clientId ) ||
			runtimeClientIds.some(
				( clientId ) =>
					normalizeRootClientId(
						blockEditorSelect.getBlockRootClientId( clientId )
					) !== normalizeRootClientId( rootClientId )
			)
		) {
			return {
				ok: false,
				error: STRUCTURAL_UNDO_SESSION_ERROR,
			};
		}

		if (
			typeof blockEditorSelect.canRemoveBlocks !== 'function' ||
			blockEditorSelect.canRemoveBlocks( runtimeClientIds ) !== true
		) {
			return {
				ok: false,
				error: STRUCTURAL_UNDO_PERMISSION_ERROR,
			};
		}

		if ( operation.type === BLOCK_OPERATION_REPLACE_BLOCK_WITH_PATTERN ) {
			const removedBlocks = cloneBlockTree(
				operation.removedBlocksSnapshot
			);

			if ( removedBlocks.length === 0 ) {
				return {
					ok: false,
					error: STRUCTURAL_MISSING_UNDO_ERROR,
				};
			}

			if (
				! canInsertParsedBlocks(
					removedBlocks,
					rootClientId,
					blockEditorSelect
				)
			) {
				return {
					ok: false,
					error: STRUCTURAL_UNDO_PERMISSION_ERROR,
				};
			}
		}
	}

	return { ok: true };
}

export function getBlockStructuralActivityUndoState(
	entry,
	blockEditorSelect = {}
) {
	const existingUndo = entry?.undo || {};

	if ( entry?.surface !== 'block' ) {
		return existingUndo;
	}

	if ( entry?.type !== 'apply_block_structural_suggestion' ) {
		return existingUndo;
	}

	const afterSignature = entry?.after?.structuralSignature || '';
	const beforeSignature = entry?.before?.structuralSignature || '';
	const currentSignature = getBlockStructuralActivitySignature(
		entry,
		blockEditorSelect
	);

	if ( ! afterSignature || ! currentSignature ) {
		return {
			...existingUndo,
			canUndo: false,
			status: 'failed',
			error: STRUCTURAL_MISSING_UNDO_ERROR,
		};
	}

	if ( currentSignature === afterSignature ) {
		const operations = Array.isArray( entry?.after?.operations )
			? entry.after.operations
			: [];
		const validation = validateStructuralUndoOperations(
			operations,
			blockEditorSelect
		);

		if ( ! validation.ok ) {
			return {
				...existingUndo,
				canUndo: false,
				status: 'failed',
				error: validation.error,
			};
		}

		return {
			...existingUndo,
			canUndo: true,
			status: 'available',
			error: null,
		};
	}

	if ( beforeSignature && currentSignature === beforeSignature ) {
		return {
			...existingUndo,
			canUndo: false,
			status: 'undone',
			error: null,
		};
	}

	return {
		...existingUndo,
		canUndo: false,
		status: 'failed',
		error: STRUCTURAL_DRIFT_UNDO_ERROR,
	};
}

export function undoBlockStructuralSuggestionOperations( activity, registry ) {
	const blockEditorSelect = registry?.select?.( 'core/block-editor' ) || {};
	const blockEditorDispatch =
		registry?.dispatch?.( 'core/block-editor' ) || {};
	const operations = Array.isArray( activity?.after?.operations )
		? activity.after.operations
		: [];
	const expectedAfterSignature = activity?.after?.structuralSignature || '';

	if ( operations.length === 0 || ! expectedAfterSignature ) {
		return {
			ok: false,
			error: STRUCTURAL_MISSING_UNDO_ERROR,
		};
	}

	const currentSignature = buildStructuralSignature(
		operations,
		blockEditorSelect
	);

	if ( currentSignature !== expectedAfterSignature ) {
		return {
			ok: false,
			error: STRUCTURAL_DRIFT_UNDO_ERROR,
		};
	}

	const validation = validateStructuralUndoOperations(
		operations,
		blockEditorSelect
	);

	if ( ! validation.ok ) {
		return validation;
	}

	for ( const operation of [ ...operations ].reverse() ) {
		const runtimeClientIds = getOperationRuntimeClientIds( operation );

		if ( blockEditorSelect.canRemoveBlocks( runtimeClientIds ) !== true ) {
			return {
				ok: false,
				error: STRUCTURAL_UNDO_PERMISSION_ERROR,
			};
		}

		blockEditorDispatch.removeBlocks?.( runtimeClientIds, false );

		if (
			runtimeClientIds.some( ( clientId ) =>
				Boolean( blockEditorSelect.getBlock( clientId ) )
			)
		) {
			return {
				ok: false,
				error: STRUCTURAL_UNDO_INCOMPLETE_ERROR,
			};
		}

		switch ( operation.type ) {
			case BLOCK_OPERATION_REPLACE_BLOCK_WITH_PATTERN:
				if (
					! canInsertParsedBlocks(
						cloneBlockTree( operation.removedBlocksSnapshot ),
						resolveRootClientId( operation.rootLocator ),
						blockEditorSelect
					)
				) {
					return {
						ok: false,
						error: STRUCTURAL_UNDO_PERMISSION_ERROR,
					};
				}

				if (
					! restoreRemovedBlocks(
						operation,
						blockEditorSelect,
						blockEditorDispatch
					)
				) {
					return {
						ok: false,
						error: STRUCTURAL_UNDO_INCOMPLETE_ERROR,
					};
				}
				break;
		}
	}

	return { ok: true };
}
