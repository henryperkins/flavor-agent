/**
 * Template editor action utilities.
 *
 * Each function targets a specific block-editor UI element:
 *   - Template-part slugs / areas → selectBlock (highlights in canvas,
 *     shows settings in the block inspector).
 *   - Patterns → setIsInserterOpened (opens the Inserter on the
 *     Patterns tab, pre-filtered to the exact pattern so the user sees
 *     a live preview and can choose an insertion point).
 */
import { rawHandler } from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { dispatch, select } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { getAllowedPatterns } from '../patterns/pattern-settings';
import {
	TEMPLATE_OPERATION_ASSIGN,
	TEMPLATE_OPERATION_INSERT_PATTERN,
	TEMPLATE_PART_PLACEMENT_END,
	TEMPLATE_PART_PLACEMENT_START,
	TEMPLATE_OPERATION_REPLACE,
	validateTemplatePartOperationSequence,
	validateTemplateOperationSequence,
} from './template-operation-sequence';
import {
	getTemplatePartAreaLookup,
	inferTemplatePartArea,
	matchesTemplatePartArea,
} from './template-part-areas';

/* ------------------------------------------------------------------ */
/*  Block-tree helpers                                                 */
/* ------------------------------------------------------------------ */

function findTemplatePart( blocks, predicate ) {
	for ( const block of blocks ) {
		if ( block.name === 'core/template-part' && predicate( block ) ) {
			return block;
		}
		if ( block.innerBlocks?.length > 0 ) {
			const found = findTemplatePart( block.innerBlocks, predicate );
			if ( found ) {
				return found;
			}
		}
	}
	return null;
}

function findBlockPath( blocks, clientId, path = [] ) {
	for ( let index = 0; index < blocks.length; index++ ) {
		const block = blocks[ index ];
		const nextPath = [ ...path, index ];

		if ( block?.clientId === clientId ) {
			return nextPath;
		}

		if ( Array.isArray( block?.innerBlocks ) && block.innerBlocks.length ) {
			const nestedPath = findBlockPath(
				block.innerBlocks,
				clientId,
				nextPath
			);

			if ( nestedPath ) {
				return nestedPath;
			}
		}
	}

	return null;
}

function getBlockByPath( blocks, path = [] ) {
	let currentBlocks = blocks;
	let block = null;

	for ( const index of path ) {
		if ( ! Array.isArray( currentBlocks ) ) {
			return null;
		}

		block = currentBlocks[ index ] || null;

		if ( ! block ) {
			return null;
		}

		currentBlocks = block.innerBlocks || [];
	}

	return block;
}

function getBlocks() {
	return select( blockEditorStore ).getBlocks();
}

function getPatternRegistry( rootClientId = null ) {
	return getAllowedPatterns( rootClientId );
}

function normalizeSerializableValue( value ) {
	if ( Array.isArray( value ) ) {
		return value.map( ( item ) =>
			normalizeSerializableValue(
				item === undefined ? null : item
			)
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

export function normalizeBlockSnapshot( block ) {
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
		? blocks.filter( Boolean ).map( ( block ) => normalizeBlockSnapshot( block ) )
		: [];
}

function buildRootLocator( rootClientId ) {
	if ( ! rootClientId ) {
		return {
			type: 'root',
			path: [],
		};
	}

	const blocks = getBlocks();
	const path = findBlockPath( blocks, rootClientId );
	const block = path ? getBlockByPath( blocks, path ) : null;

	if ( ! Array.isArray( path ) || ! block ) {
		return null;
	}

	return {
		type: 'block',
		path,
		blockName: block.name || '',
	};
}

function resolveRootLocator(
	rootLocator,
	blockEditorSelect = select( blockEditorStore )
) {
	const blocks = blockEditorSelect?.getBlocks?.() || [];

	if (
		! rootLocator ||
		rootLocator.type === 'root' ||
		( Array.isArray( rootLocator.path ) && rootLocator.path.length === 0 )
	) {
		return {
			rootClientId: null,
			blocks,
		};
	}

	if ( ! Array.isArray( rootLocator.path ) ) {
		return null;
	}

	const rootBlock = getBlockByPath( blocks, rootLocator.path );

	if ( ! rootBlock ) {
		return null;
	}

	if ( rootLocator.blockName && rootBlock.name !== rootLocator.blockName ) {
		return null;
	}

	return {
		rootClientId: rootBlock.clientId || null,
		blocks: Array.isArray( rootBlock.innerBlocks )
			? rootBlock.innerBlocks
			: [],
	};
}

function getCurrentBlockSlice(
	{ rootLocator, index, count },
	blockEditorSelect = select( blockEditorStore )
) {
	if ( ! Number.isInteger( index ) || index < 0 || count < 1 ) {
		return null;
	}

	const root = resolveRootLocator( rootLocator, blockEditorSelect );

	if ( ! root ) {
		return null;
	}

	const slice = root.blocks.slice( index, index + count );

	if ( slice.length !== count ) {
		return null;
	}

	return {
		rootClientId: root.rootClientId,
		blocks: slice,
	};
}

function buildTemplatePartWorkingState(
	areaLookup = getTemplatePartAreaLookup(),
	blockEditorSelect = select( blockEditorStore )
) {
	const state = {
		byArea: new Map(),
		bySlug: new Map(),
	};

	const visitBlocks = ( blocks = [] ) => {
		for ( const block of blocks ) {
			if ( block?.name === 'core/template-part' ) {
				const area = inferTemplatePartArea(
					block.attributes,
					areaLookup
				);
				const slug =
					typeof block.attributes?.slug === 'string'
						? block.attributes.slug
						: '';
				const entry = {
					clientId: block.clientId || null,
					area,
					slug,
				};

				if ( area ) {
					const currentAreaEntry = state.byArea.get( area ) || null;

					if ( ! currentAreaEntry || ( currentAreaEntry.slug && ! slug ) ) {
						state.byArea.set( area, entry );
					}
				}

				if ( slug ) {
					state.bySlug.set( slug, entry );
				}
			}

			if ( Array.isArray( block?.innerBlocks ) && block.innerBlocks.length ) {
				visitBlocks( block.innerBlocks );
			}
		}
	};

	visitBlocks( blockEditorSelect?.getBlocks?.() || [] );

	return state;
}

function resolveWorkingTemplatePartTarget(
	operation,
	workingState
) {
	const currentSlug = operation?.currentSlug || '';
	const area = operation?.area || '';
	let block = null;

	if ( currentSlug ) {
		block = workingState.bySlug.get( currentSlug ) || null;

		if ( block && area && block.area !== area ) {
			block = null;
		}
	}

	if ( ! block && area ) {
		block = workingState.byArea.get( area ) || null;
	}

	return block;
}

function updateWorkingTemplatePartState( workingState, block, nextAttributes ) {
	if ( ! block ) {
		return;
	}

	if ( block.area ) {
		workingState.byArea.delete( block.area );
	}

	if ( block.slug ) {
		workingState.bySlug.delete( block.slug );
	}

	const nextEntry = {
		...block,
		area: nextAttributes?.area || block.area || '',
		slug: nextAttributes?.slug || '',
	};

	if ( nextEntry.area ) {
		workingState.byArea.set( nextEntry.area, nextEntry );
	}

	if ( nextEntry.slug ) {
		workingState.bySlug.set( nextEntry.slug, nextEntry );
	}
}

function validateTemplatePartSlugForArea(
	slug,
	area,
	areaLookup = getTemplatePartAreaLookup()
) {
	if ( ! slug || ! area ) {
		return false;
	}

	const registeredArea = areaLookup?.[ slug ] || '';

	return registeredArea !== '' && registeredArea === area;
}

function resolveInsertionPoint() {
	const blockEditor = select( blockEditorStore );
	const insertionPoint = blockEditor?.getBlockInsertionPoint?.();

	if ( insertionPoint && Number.isFinite( insertionPoint.index ) ) {
		return {
			rootClientId: insertionPoint.rootClientId ?? null,
			index: insertionPoint.index,
		};
	}

	return {
		rootClientId: null,
		index: getBlocks().length,
	};
}

function resolveTemplatePartInsertionPoint(
	placement,
	blockEditorSelect = select( blockEditorStore )
) {
	const blocks = blockEditorSelect?.getBlocks?.() || [];

	return {
		rootClientId: null,
		index:
			placement === TEMPLATE_PART_PLACEMENT_START ? 0 : blocks.length,
	};
}

function resolvePatternByName( patternName, rootClientId = null ) {
	return (
		getPatternRegistry( rootClientId ).find(
			( pattern ) => pattern?.name === patternName
		) || null
	);
}

function prepareTemplatePartOperation(
	operation,
	areaLookup = getTemplatePartAreaLookup(),
	workingState = buildTemplatePartWorkingState( areaLookup )
) {
	const slug = operation?.slug || '';
	const area = operation?.area || '';
	const currentSlug = operation?.currentSlug || '';

	if ( ! validateTemplatePartSlugForArea( slug, area, areaLookup ) ) {
		return {
			error: `Template part “${
				slug || 'unknown'
			}” is not registered for the “${ area || 'unknown' }” area.`,
		};
	}

	const block = resolveWorkingTemplatePartTarget( operation, workingState );

	if ( ! block ) {
		return {
			error: `The template no longer has a live template-part block for the “${ area }” area. Regenerate recommendations and try again.`,
		};
	}

	const previousSlug = block.slug || '';

	if ( operation?.type === TEMPLATE_OPERATION_ASSIGN && previousSlug ) {
		return {
			error: `The “${ area }” area already uses “${ previousSlug }”. Use replace_template_part for occupied areas.`,
		};
	}

	if (
		operation?.type === TEMPLATE_OPERATION_REPLACE &&
		currentSlug &&
		previousSlug !== currentSlug
	) {
		return {
			error: `The “${ currentSlug }” template part is no longer assigned in the “${ area }” area.`,
		};
	}

	if ( previousSlug === slug ) {
		return {
			error: `The “${ area }” area already uses “${ slug }”.`,
		};
	}

	const previousArea = block.area || area;
	const preparedOperation = {
		type: operation.type,
		clientId: block.clientId,
		slug,
		area,
		currentSlug,
		previousAttributes: {
			slug: previousSlug,
			area: previousArea,
		},
		nextAttributes: {
			slug,
			area,
		},
		undoLocator: {
			area,
			expectedSlug: slug,
		},
	};

	updateWorkingTemplatePartState( workingState, block, preparedOperation.nextAttributes );

	return preparedOperation;
}

function prepareInsertPatternOperation( operation ) {
	const patternName = operation?.patternName || '';
	const insertionPoint = resolveInsertionPoint();
	const pattern = resolvePatternByName(
		patternName,
		insertionPoint.rootClientId
	);

	if (
		! pattern ||
		typeof pattern.content !== 'string' ||
		pattern.content.trim() === ''
	) {
		return {
			error: `Pattern “${
				patternName || 'unknown'
			}” is not available in the current editor context.`,
		};
	}

	const blocks = rawHandler( { HTML: pattern.content } ).filter( Boolean );

	if ( blocks.length === 0 ) {
		return {
			error: `Pattern “${
				pattern.title || patternName
			}” could not be converted into blocks.`,
		};
	}

	const blockEditor = select( blockEditorStore );
	const canInsertAll = blocks.every(
		( block ) =>
			! block?.name ||
			blockEditor?.canInsertBlockType?.(
				block.name,
				insertionPoint.rootClientId
			) !== false
	);

	if ( ! canInsertAll ) {
		return {
			error: `Pattern “${
				pattern.title || patternName
			}” cannot be inserted at the current insertion point.`,
		};
	}

	const rootLocator = buildRootLocator( insertionPoint.rootClientId );

	if ( ! rootLocator ) {
		return {
			error: 'Flavor Agent could not resolve the current insertion container for this pattern.',
		};
	}

	return {
		type: TEMPLATE_OPERATION_INSERT_PATTERN,
		patternName,
		patternTitle: pattern.title || patternName,
		blocks,
		rootClientId: insertionPoint.rootClientId,
		rootLocator,
		index: insertionPoint.index,
	};
}

function prepareTemplatePartInsertPatternOperation(
	operation,
	blockEditorSelect = select( blockEditorStore )
) {
	const patternName = operation?.patternName || '';
	const placement = operation?.placement || '';
	const insertionPoint = resolveTemplatePartInsertionPoint(
		placement,
		blockEditorSelect
	);
	const pattern = resolvePatternByName( patternName, insertionPoint.rootClientId );

	if (
		! pattern ||
		typeof pattern.content !== 'string' ||
		pattern.content.trim() === ''
	) {
		return {
			error: `Pattern “${
				patternName || 'unknown'
			}” is not available in the current template-part context.`,
		};
	}

	const blocks = rawHandler( { HTML: pattern.content } ).filter( Boolean );

	if ( blocks.length === 0 ) {
		return {
			error: `Pattern “${
				pattern.title || patternName
			}” could not be converted into blocks.`,
		};
	}

	const canInsertAll = blocks.every(
		( block ) =>
			! block?.name ||
			blockEditorSelect?.canInsertBlockType?.(
				block.name,
				insertionPoint.rootClientId
			) !== false
	);

	if ( ! canInsertAll ) {
		return {
			error: `Pattern “${
				pattern.title || patternName
			}” cannot be inserted at the ${ placement } of this template part.`,
		};
	}

	return {
		type: TEMPLATE_OPERATION_INSERT_PATTERN,
		patternName,
		patternTitle: pattern.title || patternName,
		placement,
		blocks,
		rootClientId: insertionPoint.rootClientId,
		rootLocator: {
			type: 'root',
			path: [],
		},
		index: insertionPoint.index,
	};
}

export function prepareTemplateSuggestionOperations( suggestion ) {
	const sequence = validateTemplateOperationSequence( suggestion?.operations );

	if ( ! sequence.ok ) {
		return sequence;
	}

	const preparedOperations = [];
	const areaLookup = getTemplatePartAreaLookup();
	const workingState = buildTemplatePartWorkingState( areaLookup );

	for ( const operation of sequence.operations ) {
		switch ( operation?.type ) {
			case TEMPLATE_OPERATION_ASSIGN:
			case TEMPLATE_OPERATION_REPLACE: {
				const prepared = prepareTemplatePartOperation(
					operation,
					areaLookup,
					workingState
				);

				if ( prepared?.error ) {
					return { ok: false, error: prepared.error };
				}

				preparedOperations.push( prepared );
				break;
			}

			case TEMPLATE_OPERATION_INSERT_PATTERN: {
				const prepared = prepareInsertPatternOperation( operation );

				if ( prepared?.error ) {
					return { ok: false, error: prepared.error };
				}

				preparedOperations.push( prepared );
				break;
			}

			default:
				return {
					ok: false,
					error: `Unsupported template operation “${
						operation?.type || 'unknown'
					}”.`,
				};
		}
	}

	return { ok: true, operations: preparedOperations };
}

export function prepareTemplatePartSuggestionOperations( suggestion ) {
	const sequence = validateTemplatePartOperationSequence(
		suggestion?.operations
	);

	if ( ! sequence.ok ) {
		return sequence;
	}

	const preparedOperations = [];
	const blockEditorSelect = select( blockEditorStore );

	for ( const operation of sequence.operations ) {
		switch ( operation?.type ) {
			case TEMPLATE_OPERATION_INSERT_PATTERN: {
				const prepared = prepareTemplatePartInsertPatternOperation(
					operation,
					blockEditorSelect
				);

				if ( prepared?.error ) {
					return { ok: false, error: prepared.error };
				}

				preparedOperations.push( prepared );
				break;
			}

			default:
				return {
					ok: false,
					error: `Unsupported template-part operation “${
						operation?.type || 'unknown'
					}”.`,
				};
		}
	}

	return { ok: true, operations: preparedOperations };
}

export function applyTemplateSuggestionOperations( suggestion ) {
	const prepared = prepareTemplateSuggestionOperations( suggestion );

	if ( ! prepared.ok ) {
		return prepared;
	}

	const blockEditorDispatch = dispatch( blockEditorStore );
	const blockEditorSelect = select( blockEditorStore );
	const appliedOperations = [];

	for ( const operation of prepared.operations ) {
		switch ( operation.type ) {
			case TEMPLATE_OPERATION_ASSIGN:
			case TEMPLATE_OPERATION_REPLACE:
				blockEditorDispatch.updateBlockAttributes(
					operation.clientId,
					operation.nextAttributes
				);
				blockEditorDispatch.selectBlock( operation.clientId );
				appliedOperations.push( {
					type: operation.type,
					slug: operation.slug,
					area: operation.area,
					currentSlug:
						operation.currentSlug ||
						operation.previousAttributes?.slug ||
						'',
					previousAttributes: operation.previousAttributes,
					nextAttributes: operation.nextAttributes,
					undoLocator: operation.undoLocator,
				} );
				break;

			case TEMPLATE_OPERATION_INSERT_PATTERN:
				blockEditorDispatch.insertBlocks(
					operation.blocks,
					operation.index,
					operation.rootClientId,
					true,
					0
				);
				const insertedSlice =
					getCurrentBlockSlice(
						{
							rootLocator: operation.rootLocator,
							index: operation.index,
							count: operation.blocks.length,
						},
						blockEditorSelect
					) || null;
				appliedOperations.push( {
					type: operation.type,
					patternName: operation.patternName,
					patternTitle: operation.patternTitle,
					rootLocator: operation.rootLocator,
					index: operation.index,
					insertedBlocksSnapshot: normalizeBlockSnapshots(
						insertedSlice?.blocks || operation.blocks
					),
				} );
				break;
		}
	}

	return {
		ok: true,
		operations: appliedOperations,
	};
}

export function applyTemplatePartSuggestionOperations( suggestion ) {
	const prepared = prepareTemplatePartSuggestionOperations( suggestion );

	if ( ! prepared.ok ) {
		return prepared;
	}

	const blockEditorDispatch = dispatch( blockEditorStore );
	const blockEditorSelect = select( blockEditorStore );
	const appliedOperations = [];

	for ( const operation of prepared.operations ) {
		switch ( operation.type ) {
			case TEMPLATE_OPERATION_INSERT_PATTERN:
				blockEditorDispatch.insertBlocks(
					operation.blocks,
					operation.index,
					operation.rootClientId,
					true,
					0
				);
				const insertedSlice =
					getCurrentBlockSlice(
						{
							rootLocator: operation.rootLocator,
							index: operation.index,
							count: operation.blocks.length,
						},
						blockEditorSelect
					) || null;
				appliedOperations.push( {
					type: operation.type,
					patternName: operation.patternName,
					patternTitle: operation.patternTitle,
					placement: operation.placement,
					rootLocator: operation.rootLocator,
					index: operation.index,
					insertedBlocksSnapshot: normalizeBlockSnapshots(
						insertedSlice?.blocks || operation.blocks
					),
				} );
				break;
		}
	}

	return {
		ok: true,
		operations: appliedOperations,
	};
}

function getUndoSourceOperations( activity ) {
	if ( Array.isArray( activity?.after?.operations ) ) {
		return activity.after.operations;
	}

	return Array.isArray( activity?.operations ) ? activity.operations : [];
}

export function resolveTemplatePartUndoTarget(
	operation,
	areaLookup = getTemplatePartAreaLookup(),
	blockEditorSelect = select( blockEditorStore )
) {
	const expectedSlug =
		operation?.undoLocator?.expectedSlug ||
		operation?.nextAttributes?.slug ||
		operation?.slug ||
		'';
	const expectedArea =
		operation?.undoLocator?.area ||
		operation?.nextAttributes?.area ||
		operation?.area ||
		'';
	const blocks = blockEditorSelect?.getBlocks?.() || [];

	let block = null;

	if ( expectedSlug ) {
		block = findTemplatePart( blocks, ( candidate ) => {
			if ( candidate?.attributes?.slug !== expectedSlug ) {
				return false;
			}

			if ( ! expectedArea ) {
				return true;
			}

			return (
				inferTemplatePartArea( candidate.attributes, areaLookup ) ===
				expectedArea
			);
		} );
	}

	if ( ! block && expectedArea ) {
		block = findTemplatePart( blocks, ( candidate ) =>
			matchesTemplatePartArea( candidate, expectedArea )
		);
	}

	return block || null;
}

function prepareUndoTemplatePartOperation(
	operation,
	areaLookup = getTemplatePartAreaLookup(),
	blockEditorSelect = select( blockEditorStore )
) {
	const block = resolveTemplatePartUndoTarget(
		operation,
		areaLookup,
		blockEditorSelect
	);

	if ( ! block ) {
		return {
			error: 'A template-part block from this AI action is no longer available to undo.',
		};
	}

	const currentSlug =
		typeof block.attributes?.slug === 'string' ? block.attributes.slug : '';
	const expectedSlug =
		operation?.nextAttributes?.slug || operation?.slug || '';
	const expectedArea =
		operation?.nextAttributes?.area || operation?.area || '';

	if ( expectedSlug && currentSlug !== expectedSlug ) {
		return {
			error: `The “${ expectedSlug }” template part is no longer assigned where Flavor Agent applied it, so the change cannot be undone automatically.`,
		};
	}

	const currentArea = inferTemplatePartArea( block.attributes, areaLookup );

	if ( expectedArea && currentArea !== expectedArea ) {
		return {
			error: `The “${ expectedArea }” template-part area changed after apply and cannot be undone automatically.`,
		};
	}

	return {
		type: operation.type,
		clientId: block.clientId,
		previousAttributes: operation.previousAttributes || {
			slug: '',
			area: '',
		},
	};
}

export function resolveInsertedPatternSlice(
	operation,
	blockEditorSelect = select( blockEditorStore )
) {
	const insertedBlocksSnapshot = Array.isArray(
		operation?.insertedBlocksSnapshot
	)
		? operation.insertedBlocksSnapshot
		: [];

	if (
		! operation?.rootLocator ||
		! Number.isInteger( operation?.index )
	) {
		return {
			ok: false,
			error:
				Array.isArray( operation?.insertedClientIds ) &&
				operation.insertedClientIds.length > 0
					? 'This pattern insertion was recorded before refresh-safe undo support and cannot be undone automatically.'
					: 'This pattern insertion does not include the stable insertion locator needed for automatic undo.',
		};
	}

	if ( insertedBlocksSnapshot.length === 0 ) {
		return {
			ok: false,
			error:
				Array.isArray( operation?.insertedClientIds ) &&
				operation.insertedClientIds.length > 0
					? 'This pattern insertion was recorded before refresh-safe undo support and cannot be undone automatically.'
					: 'This pattern insertion does not include the recorded post-apply snapshot needed for automatic undo.',
		};
	}

	const slice = getCurrentBlockSlice(
		{
			rootLocator: operation?.rootLocator,
			index: operation?.index,
			count: insertedBlocksSnapshot.length,
		},
		blockEditorSelect
	);

	if ( ! slice ) {
		return {
			ok: false,
			error: 'Inserted pattern content changed after apply and cannot be undone automatically.',
		};
	}

	const currentSnapshot = normalizeBlockSnapshots( slice.blocks );

	if (
		JSON.stringify( currentSnapshot ) !==
		JSON.stringify( insertedBlocksSnapshot )
	) {
		return {
			ok: false,
			error: 'Inserted pattern content changed after apply and cannot be undone automatically.',
		};
	}

	return {
		ok: true,
		blocks: slice.blocks,
		insertedClientIds: slice.blocks
			.map( ( block ) => block?.clientId )
			.filter( Boolean ),
	};
}

function prepareUndoInsertPatternOperation(
	operation,
	blockEditorSelect = select( blockEditorStore )
) {
	const resolvedSlice = resolveInsertedPatternSlice(
		operation,
		blockEditorSelect
	);

	if ( ! resolvedSlice.ok ) {
		return {
			error: resolvedSlice.error,
		};
	}

	return {
		type: TEMPLATE_OPERATION_INSERT_PATTERN,
		insertedClientIds: resolvedSlice.insertedClientIds,
		patternName: operation?.patternName || '',
		patternTitle: operation?.patternTitle || operation?.patternName || '',
	};
}

export function prepareTemplateUndoOperations(
	activity,
	blockEditorSelect = select( blockEditorStore )
) {
	const operations = getUndoSourceOperations( activity );

	if ( operations.length === 0 ) {
		return {
			ok: false,
			error: 'This AI template action does not include undoable operations.',
		};
	}

	const preparedOperations = [];
	const areaLookup = getTemplatePartAreaLookup();

	for ( const operation of [ ...operations ].reverse() ) {
		switch ( operation?.type ) {
			case TEMPLATE_OPERATION_ASSIGN:
			case TEMPLATE_OPERATION_REPLACE: {
				const prepared = prepareUndoTemplatePartOperation(
					operation,
					areaLookup,
					blockEditorSelect
				);

				if ( prepared?.error ) {
					return { ok: false, error: prepared.error };
				}

				preparedOperations.push( prepared );
				break;
			}

			case TEMPLATE_OPERATION_INSERT_PATTERN: {
				const prepared = prepareUndoInsertPatternOperation(
					operation,
					blockEditorSelect
				);

				if ( prepared?.error ) {
					return { ok: false, error: prepared.error };
				}

				preparedOperations.push( prepared );
				break;
			}

			default:
				return {
					ok: false,
					error: `Unsupported template undo operation “${
						operation?.type || 'unknown'
					}”.`,
				};
		}
	}

	return {
		ok: true,
		operations: preparedOperations,
	};
}

export function prepareTemplatePartUndoOperations(
	activity,
	blockEditorSelect = select( blockEditorStore )
) {
	const operations = getUndoSourceOperations( activity );

	if ( operations.length === 0 ) {
		return {
			ok: false,
			error: 'This AI template-part action does not include undoable operations.',
		};
	}

	const preparedOperations = [];

	for ( const operation of [ ...operations ].reverse() ) {
		switch ( operation?.type ) {
			case TEMPLATE_OPERATION_INSERT_PATTERN: {
				const prepared = prepareUndoInsertPatternOperation(
					operation,
					blockEditorSelect
				);

				if ( prepared?.error ) {
					return { ok: false, error: prepared.error };
				}

				preparedOperations.push( prepared );
				break;
			}

			default:
				return {
					ok: false,
					error: `Unsupported template-part undo operation “${
						operation?.type || 'unknown'
					}”.`,
				};
		}
	}

	return {
		ok: true,
		operations: preparedOperations,
	};
}

export function getTemplateActivityUndoState(
	activity,
	blockEditorSelect = select( blockEditorStore )
) {
	const existingUndo = activity?.undo || {};

	if ( activity?.surface !== 'template' ) {
		return existingUndo;
	}

	if ( existingUndo.status === 'undone' ) {
		return existingUndo;
	}

	if ( existingUndo.status === 'failed' && existingUndo.canUndo === false ) {
		return existingUndo;
	}

	const prepared = prepareTemplateUndoOperations(
		activity,
		blockEditorSelect
	);

	if ( prepared.ok ) {
		return {
			...existingUndo,
			canUndo: true,
			status: 'available',
			error: null,
		};
	}

	return {
		...existingUndo,
		canUndo: false,
		status: 'failed',
		error:
			prepared.error ||
			existingUndo.error ||
			'This AI template action can no longer be undone automatically.',
	};
}

export function getTemplatePartActivityUndoState(
	activity,
	blockEditorSelect = select( blockEditorStore )
) {
	const existingUndo = activity?.undo || {};

	if ( activity?.surface !== 'template-part' ) {
		return existingUndo;
	}

	if ( existingUndo.status === 'undone' ) {
		return existingUndo;
	}

	if ( existingUndo.status === 'failed' && existingUndo.canUndo === false ) {
		return existingUndo;
	}

	const prepared = prepareTemplatePartUndoOperations(
		activity,
		blockEditorSelect
	);

	if ( prepared.ok ) {
		return {
			...existingUndo,
			canUndo: true,
			status: 'available',
			error: null,
		};
	}

	return {
		...existingUndo,
		canUndo: false,
		status: 'failed',
		error:
			prepared.error ||
			existingUndo.error ||
			'This AI template-part action can no longer be undone automatically.',
	};
}

export function undoTemplateSuggestionOperations( activity ) {
	const prepared = prepareTemplateUndoOperations( activity );

	if ( ! prepared.ok ) {
		return prepared;
	}

	const blockEditorDispatch = dispatch( blockEditorStore );
	const undoneOperations = [];

	for ( const operation of prepared.operations ) {
		switch ( operation.type ) {
			case TEMPLATE_OPERATION_ASSIGN:
			case TEMPLATE_OPERATION_REPLACE:
				blockEditorDispatch.updateBlockAttributes(
					operation.clientId,
					operation.previousAttributes
				);
				undoneOperations.push( {
					type: operation.type,
					clientId: operation.clientId,
				} );
				break;

			case TEMPLATE_OPERATION_INSERT_PATTERN:
				blockEditorDispatch.removeBlocks(
					operation.insertedClientIds,
					false
				);
				undoneOperations.push( {
					type: operation.type,
					insertedClientIds: operation.insertedClientIds,
				} );
				break;
		}
	}

	return {
		ok: true,
		operations: undoneOperations,
	};
}

export function undoTemplatePartSuggestionOperations( activity ) {
	const prepared = prepareTemplatePartUndoOperations( activity );

	if ( ! prepared.ok ) {
		return prepared;
	}

	const blockEditorDispatch = dispatch( blockEditorStore );
	const undoneOperations = [];

	for ( const operation of prepared.operations ) {
		switch ( operation.type ) {
			case TEMPLATE_OPERATION_INSERT_PATTERN:
				blockEditorDispatch.removeBlocks(
					operation.insertedClientIds,
					false
				);
				undoneOperations.push( {
					type: operation.type,
					insertedClientIds: operation.insertedClientIds,
				} );
				break;
		}
	}

	return {
		ok: true,
		operations: undoneOperations,
	};
}

/**
 * Find a template-part block by area.
 * Prefers empty (unassigned) placeholders over assigned ones.
 *
 * @param {string} area Template area slug.
 * @return {Object|null} Matching block or null.
 */
export function findBlockByArea( area ) {
	const blocks = getBlocks();
	const empty = findTemplatePart(
		blocks,
		( b ) => matchesTemplatePartArea( b, area ) && ! b.attributes?.slug
	);

	return (
		empty ||
		findTemplatePart( blocks, ( b ) => matchesTemplatePartArea( b, area ) )
	);
}

/**
 * Find a template-part block by its assigned slug.
 *
 * @param {string} slug Template part slug.
 * @return {Object|null} Matching block or null.
 */
export function findBlockBySlug( slug ) {
	return findTemplatePart(
		getBlocks(),
		( b ) => b.attributes?.slug === slug
	);
}

/**
 * Select and scroll-to a block by its current structural path.
 *
 * @param {number[]} path Block path from the current editor root.
 * @return {boolean} Whether a matching block was selected.
 */
export function selectBlockByPath( path ) {
	if ( ! Array.isArray( path ) || path.length === 0 ) {
		return false;
	}

	const block = getBlockByPath( getBlocks(), path );

	if ( block?.clientId ) {
		dispatch( blockEditorStore ).selectBlock( block.clientId );
		return true;
	}

	return false;
}

/* ------------------------------------------------------------------ */
/*  Navigation actions (non-destructive)                               */
/* ------------------------------------------------------------------ */

/**
 * Select and scroll-to a template-part block by area.
 * The block inspector will show the template-part controls
 * (slug assignment dropdown, "Edit" link, etc.).
 *
 * @param {string} area Template area slug.
 * @return {boolean} Whether a matching block was selected.
 */
export function selectBlockByArea( area ) {
	const block = findBlockByArea( area );
	if ( block ) {
		dispatch( blockEditorStore ).selectBlock( block.clientId );
		return true;
	}
	return false;
}

/**
 * Select a template-part block by slug.  Falls back to area lookup
 * when the slug hasn't been assigned to a block yet.
 *
 * @param {string} slug         Template part slug.
 * @param {string} fallbackArea Template area fallback.
 * @return {boolean} Whether a matching block was selected.
 */
export function selectBlockBySlugOrArea( slug, fallbackArea ) {
	const bySlug = findBlockBySlug( slug );
	if ( bySlug ) {
		dispatch( blockEditorStore ).selectBlock( bySlug.clientId );
		return true;
	}
	return fallbackArea ? selectBlockByArea( fallbackArea ) : false;
}

/**
 * Open the block Inserter on the Patterns tab, pre-filtered to a
 * specific pattern title.  The user sees the live pattern preview
 * inside the inserter and can choose an insertion point.
 *
 * @param {string} filterValue Pattern display title (or slug) to search for.
 */
export function openInserterForPattern( filterValue ) {
	try {
		dispatch( editorStore ).setIsInserterOpened( {
			filterValue,
			tab: 'patterns',
		} );
		return true;
	} catch {
		return false;
	}
}
