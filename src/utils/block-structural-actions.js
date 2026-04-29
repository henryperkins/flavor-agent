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

const STRUCTURAL_DRIFT_UNDO_ERROR =
	'The block structure changed after Flavor Agent applied this suggestion and cannot be undone automatically.';

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

function hasLockedBlockAttribute( block = {} ) {
	const lock =
		block?.attributes?.lock &&
		typeof block.attributes.lock === 'object' &&
		! Array.isArray( block.attributes.lock )
			? block.attributes.lock
			: null;

	return Boolean( lock && Object.keys( lock ).length > 0 );
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
		target_missing:
			'The selected block is no longer available. Refresh recommendations and try again.',
		target_mismatch:
			'The selected block no longer matches the reviewed operation. Refresh recommendations and try again.',
		pattern_missing:
			'The recommended pattern is no longer available. Refresh recommendations and try again.',
		locked_target:
			'The selected block is locked and cannot be structurally changed.',
		content_only_target:
			'The selected block is content-only and cannot be structurally changed.',
		structural_actions_disabled:
			'Block structural actions are disabled for this environment.',
		operation_invalid:
			'The structural operation is no longer valid. Refresh recommendations and try again.',
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
		isTargetLocked:
			blockOperationContext?.isTargetLocked === true ||
			hasLockedBlockAttribute( liveBlock ),
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
			blocks: cloneBlockTree( blocks ),
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

function removeInsertedSlice(
	operation,
	blockEditorSelect,
	blockEditorDispatch
) {
	const insertedCount = Array.isArray( operation?.insertedBlocksSnapshot )
		? operation.insertedBlocksSnapshot.length
		: 0;
	const slice = getBlockSlice(
		blockEditorSelect,
		operation?.rootLocator,
		operation?.index,
		insertedCount
	);
	const clientIds = slice
		.map( ( block ) => block?.clientId )
		.filter( Boolean );

	if ( clientIds.length > 0 ) {
		blockEditorDispatch.removeBlocks?.( clientIds, false );
	}
}

function restoreRemovedBlocks( operation, blockEditorDispatch ) {
	const removedBlocks = cloneBlockTree( operation?.removedBlocksSnapshot );

	if ( removedBlocks.length === 0 ) {
		return;
	}

	blockEditorDispatch.insertBlocks?.(
		removedBlocks,
		operation.index,
		resolveRootClientId( operation.rootLocator ),
		true,
		0
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

	const insertedSlice = getBlockSlice(
		blockEditorSelect,
		rootLocator,
		index,
		parsed.blocks.length
	);

	if (
		insertedSlice.length !== parsed.blocks.length ||
		! blockSnapshotsMatch( insertedSlice, parsed.blocks )
	) {
		const rollbackOperation = {
			...operation,
			rootLocator,
			index,
			insertedBlocksSnapshot: normalizeBlockSnapshots( parsed.blocks ),
		};
		removeInsertedSlice(
			rollbackOperation,
			blockEditorSelect,
			blockEditorDispatch
		);

		return {
			ok: false,
			code: 'operation_invalid',
			error: `Pattern “${
				operation.patternName || 'unknown'
			}” could not be inserted for the selected block.`,
		};
	}

	return {
		ok: true,
		operation: {
			...operation,
			rootLocator,
			index,
			insertedBlocksSnapshot: normalizeBlockSnapshots( insertedSlice ),
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

	const rootLocator = buildRootLocator(
		location.rootClientId,
		blockEditorSelect
	);
	const removedBlocksSnapshot = cloneBlockTree( [ liveBlock ] );
	const baseOperation = {
		...operation,
		rootLocator,
		index: location.index,
		removedBlocksSnapshot,
		insertedBlocksSnapshot: normalizeBlockSnapshots( parsed.blocks ),
	};

	blockEditorDispatch.removeBlocks?.( [ liveBlock.clientId ], false );

	if ( blockEditorSelect.getBlock?.( liveBlock.clientId ) ) {
		return {
			ok: false,
			code: 'operation_invalid',
			error: 'The selected block could not be removed before replacement.',
		};
	}

	blockEditorDispatch.insertBlocks?.(
		parsed.blocks,
		location.index,
		location.rootClientId,
		true,
		0
	);

	const insertedSlice = getBlockSlice(
		blockEditorSelect,
		rootLocator,
		location.index,
		parsed.blocks.length
	);

	if (
		insertedSlice.length !== parsed.blocks.length ||
		! blockSnapshotsMatch( insertedSlice, parsed.blocks )
	) {
		removeInsertedSlice(
			baseOperation,
			blockEditorSelect,
			blockEditorDispatch
		);
		restoreRemovedBlocks( baseOperation, blockEditorDispatch );

		return {
			ok: false,
			code: 'operation_invalid',
			error: `Pattern “${
				operation.patternName || 'unknown'
			}” could not replace the selected block.`,
		};
	}

	return {
		ok: true,
		operation: {
			...baseOperation,
			insertedBlocksSnapshot: normalizeBlockSnapshots( insertedSlice ),
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
			error: 'This block structural action is missing its recorded structure and cannot be undone automatically.',
		};
	}

	if ( currentSignature === afterSignature ) {
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
			error: 'This block structural action is missing its recorded structure and cannot be undone automatically.',
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

	for ( const operation of [ ...operations ].reverse() ) {
		switch ( operation.type ) {
			case BLOCK_OPERATION_INSERT_PATTERN:
				removeInsertedSlice(
					operation,
					blockEditorSelect,
					blockEditorDispatch
				);
				break;

			case BLOCK_OPERATION_REPLACE_BLOCK_WITH_PATTERN:
				removeInsertedSlice(
					operation,
					blockEditorSelect,
					blockEditorDispatch
				);
				restoreRemovedBlocks( operation, blockEditorDispatch );
				break;
		}
	}

	return { ok: true };
}
