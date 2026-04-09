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
import { createBlock, getBlockType, rawHandler } from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { dispatch, select } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { toHTMLString } from '@wordpress/rich-text';
import { getAllowedPatterns } from '../patterns/pattern-settings';
import {
	TEMPLATE_OPERATION_ASSIGN,
	TEMPLATE_OPERATION_INSERT_PATTERN,
	TEMPLATE_OPERATION_REMOVE_BLOCK,
	TEMPLATE_PART_PLACEMENT_END,
	TEMPLATE_PART_PLACEMENT_AFTER_BLOCK_PATH,
	TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH,
	TEMPLATE_PART_PLACEMENT_START,
	TEMPLATE_OPERATION_REPLACE,
	TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN,
	validateTemplatePartOperationSequence,
	validateTemplateOperationSequence,
} from './template-operation-sequence';
import {
	getTemplatePartAreaLookup,
	inferTemplatePartArea,
	isTemplatePartSlugRegisteredForArea,
	matchesTemplatePartArea,
} from './template-part-areas';
import { deepStructuralEqual } from './structural-equality';

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

function getNormalizedTemplateLock( value ) {
	return typeof value === 'string' && value.trim() !== '' ? value.trim() : '';
}

function getBlockLockValue( block, key ) {
	const lock =
		block?.attributes?.lock && typeof block.attributes.lock === 'object'
			? block.attributes.lock
			: null;

	return typeof lock?.[ key ] === 'boolean' ? lock[ key ] : null;
}

function templateLockBlocksInsertion( templateLock ) {
	return (
		templateLock === 'all' ||
		templateLock === 'insert' ||
		templateLock === 'contentOnly'
	);
}

function templateLockBlocksRemoval( templateLock ) {
	return (
		templateLock === 'all' ||
		templateLock === 'insert' ||
		templateLock === 'contentOnly'
	);
}

function getEffectiveTemplateLockForContainer(
	containerPath = [],
	blocks = getBlocks(),
	blockEditorSelect = select( blockEditorStore )
) {
	if ( ! Array.isArray( containerPath ) || containerPath.length === 0 ) {
		return getNormalizedTemplateLock(
			blockEditorSelect?.getTemplateLock?.() || ''
		);
	}

	const containerBlock = getBlockByPath( blocks, containerPath );

	if ( ! containerBlock ) {
		return '';
	}

	const containerTemplateLock = getNormalizedTemplateLock(
		containerBlock?.attributes?.templateLock
	);

	if ( containerTemplateLock ) {
		return containerTemplateLock;
	}

	return getEffectiveTemplateLockForContainer(
		containerPath.slice( 0, -1 ),
		blocks,
		blockEditorSelect
	);
}

function isBlockPresent(
	clientId,
	blockEditorSelect = select( blockEditorStore )
) {
	if ( ! clientId ) {
		return false;
	}

	if ( typeof blockEditorSelect?.getBlock === 'function' ) {
		return Boolean( blockEditorSelect.getBlock( clientId ) );
	}

	return Boolean(
		findBlockPath( blockEditorSelect?.getBlocks?.() || [], clientId )
	);
}

function getStructuralMutationLockError( {
	targetBlock = null,
	targetPath = [],
	containerPath = [],
	blocks = getBlocks(),
	blockEditorSelect = select( blockEditorStore ),
	operation = 'remove',
	surfaceLabel = 'template-part',
} ) {
	const effectiveTemplateLock = getEffectiveTemplateLockForContainer(
		containerPath,
		blocks,
		blockEditorSelect
	);
	const pathLabel = Array.isArray( targetPath )
		? targetPath.join( ' > ' )
		: '';

	if (
		( operation === 'insert' || operation === 'replace' ) &&
		templateLockBlocksInsertion( effectiveTemplateLock )
	) {
		return operation === 'insert'
			? `The insertion container for this ${ surfaceLabel } operation is locked and cannot accept new blocks automatically.`
			: `The target block at path ${ pathLabel } is inside a locked container and cannot be replaced automatically.`;
	}

	const explicitRemoveLock = getBlockLockValue( targetBlock, 'remove' );

	if ( explicitRemoveLock === true ) {
		return operation === 'replace'
			? `The target block at path ${ pathLabel } is locked and cannot be replaced automatically.`
			: `The target block at path ${ pathLabel } is locked and cannot be removed automatically.`;
	}

	if (
		( operation === 'remove' || operation === 'replace' ) &&
		explicitRemoveLock !== false &&
		templateLockBlocksRemoval( effectiveTemplateLock )
	) {
		return operation === 'replace'
			? `The target block at path ${ pathLabel } is inside a locked container and cannot be replaced automatically.`
			: `The target block at path ${ pathLabel } is inside a locked container and cannot be removed automatically.`;
	}

	return null;
}

function getBlocks() {
	return select( blockEditorStore ).getBlocks();
}

function getPatternRegistry( rootClientId = null ) {
	return getAllowedPatterns( rootClientId );
}

function normalizeRichTextValue( value ) {
	if ( value === null || value === undefined ) {
		return null;
	}

	try {
		if ( typeof value?.toHTMLString === 'function' ) {
			return value.toHTMLString();
		}
	} catch {}

	try {
		return toHTMLString( { value } );
	} catch {}

	return null;
}

function normalizeSerializableValue( value ) {
	if ( Array.isArray( value ) ) {
		const richTextValue = normalizeRichTextValue( value );

		if ( richTextValue !== null ) {
			return richTextValue;
		}

		return value.map( ( item ) =>
			normalizeSerializableValue( item === undefined ? null : item )
		);
	}

	if ( value && typeof value === 'object' ) {
		const richTextValue = normalizeRichTextValue( value );

		if ( richTextValue !== null ) {
			return richTextValue;
		}

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
		? blocks
				.filter( Boolean )
				.map( ( block ) => normalizeBlockSnapshot( block ) )
		: [];
}

function snapshotValuesEqual( currentValue, expectedValue ) {
	if ( Array.isArray( currentValue ) || Array.isArray( expectedValue ) ) {
		if (
			! Array.isArray( currentValue ) ||
			! Array.isArray( expectedValue ) ||
			currentValue.length !== expectedValue.length
		) {
			return false;
		}

		return currentValue.every( ( entry, index ) =>
			snapshotValuesEqual( entry, expectedValue[ index ] )
		);
	}

	if (
		currentValue &&
		typeof currentValue === 'object' &&
		expectedValue &&
		typeof expectedValue === 'object'
	) {
		const currentKeys = Object.keys( currentValue );
		const expectedKeys = Object.keys( expectedValue );

		if ( currentKeys.length !== expectedKeys.length ) {
			return false;
		}

		return currentKeys.every(
			( key ) =>
				Object.prototype.hasOwnProperty.call( expectedValue, key ) &&
				snapshotValuesEqual( currentValue[ key ], expectedValue[ key ] )
		);
	}

	return currentValue === expectedValue;
}

function isDefaultBlockAttributeValue( blockName, attributeKey, value ) {
	const definition = getBlockType( blockName )?.attributes?.[ attributeKey ];

	if ( ! definition ) {
		return value === undefined;
	}

	if ( Object.prototype.hasOwnProperty.call( definition, 'default' ) ) {
		return snapshotValuesEqual(
			value,
			normalizeSerializableValue( definition.default )
		);
	}

	if ( definition.type === 'rich-text' ) {
		return value === undefined || value === null || value === '';
	}

	return value === undefined;
}

function snapshotAttributesMatchBlockDefaults(
	currentAttributes,
	expectedAttributes,
	blockName
) {
	const current =
		currentAttributes &&
		typeof currentAttributes === 'object' &&
		! Array.isArray( currentAttributes )
			? currentAttributes
			: {};
	const expected =
		expectedAttributes &&
		typeof expectedAttributes === 'object' &&
		! Array.isArray( expectedAttributes )
			? expectedAttributes
			: {};
	const attributeKeys = new Set( [
		...Object.keys( current ),
		...Object.keys( expected ),
	] );

	for ( const key of attributeKeys ) {
		const hasCurrent = Object.prototype.hasOwnProperty.call( current, key );
		const hasExpected = Object.prototype.hasOwnProperty.call(
			expected,
			key
		);

		if ( hasCurrent && hasExpected ) {
			if ( ! snapshotValuesEqual( current[ key ], expected[ key ] ) ) {
				return false;
			}

			continue;
		}

		const value = hasCurrent ? current[ key ] : expected[ key ];

		if ( ! isDefaultBlockAttributeValue( blockName, key, value ) ) {
			return false;
		}
	}

	return true;
}

function snapshotMatchesExpectedBlock( currentBlock, expectedBlock ) {
	if (
		! currentBlock ||
		! expectedBlock ||
		currentBlock.name !== expectedBlock.name
	) {
		return false;
	}

	return (
		snapshotAttributesMatchBlockDefaults(
			currentBlock.attributes || {},
			expectedBlock.attributes || {},
			currentBlock.name
		) &&
		snapshotMatchesExpectedBlocks(
			currentBlock.innerBlocks || [],
			expectedBlock.innerBlocks || []
		)
	);
}

function snapshotMatchesExpectedBlocks(
	currentBlocks = [],
	expectedBlocks = []
) {
	return (
		Array.isArray( currentBlocks ) &&
		Array.isArray( expectedBlocks ) &&
		currentBlocks.length === expectedBlocks.length &&
		expectedBlocks.every( ( expectedBlock, index ) =>
			snapshotMatchesExpectedBlock(
				currentBlocks[ index ],
				expectedBlock
			)
		)
	);
}

function resolveRecordedInsertedBlocksSnapshot(
	insertedSlice,
	expectedBlocks = []
) {
	const expectedSnapshot = normalizeBlockSnapshots( expectedBlocks );
	const currentSnapshot = normalizeBlockSnapshots(
		insertedSlice?.blocks || []
	);

	if (
		currentSnapshot.length > 0 &&
		snapshotMatchesExpectedBlocks( currentSnapshot, expectedSnapshot )
	) {
		return currentSnapshot;
	}

	return expectedSnapshot;
}

function cloneBlockTree( blocks = [] ) {
	return Array.isArray( blocks )
		? blocks.filter( Boolean ).map( ( block ) => ( {
				...block,
				attributes: normalizeSerializableValue(
					block.attributes || {}
				),
				innerBlocks: cloneBlockTree( block.innerBlocks || [] ),
		  } ) )
		: [];
}

function getBlockContainerByPath( blocks, path = [] ) {
	let currentBlocks = blocks;

	for ( const index of path ) {
		const block = currentBlocks?.[ index ];

		if ( ! block || ! Array.isArray( block.innerBlocks ) ) {
			return null;
		}

		currentBlocks = block.innerBlocks;
	}

	return Array.isArray( currentBlocks ) ? currentBlocks : null;
}

function buildRootLocatorFromPath( blocks, path = [] ) {
	if ( ! Array.isArray( path ) || path.length === 0 ) {
		return {
			type: 'root',
			path: [],
		};
	}

	const block = getBlockByPath( blocks, path );

	if ( ! block ) {
		return null;
	}

	return {
		type: 'block',
		path,
		blockName: block.name || '',
	};
}

function resolveInsertionRootClientId(
	rootLocator,
	blockEditorSelect = select( blockEditorStore )
) {
	const resolvedRoot = resolveRootLocator( rootLocator, blockEditorSelect );

	if ( ! resolvedRoot ) {
		return {
			ok: false,
			error: 'Flavor Agent could not resolve the current insertion container for this template-part operation.',
		};
	}

	return {
		ok: true,
		rootClientId: resolvedRoot.rootClientId ?? null,
	};
}

function rebuildBlockFromSnapshot( snapshot ) {
	if ( ! snapshot?.name ) {
		return null;
	}

	const innerBlocks = Array.isArray( snapshot.innerBlocks )
		? snapshot.innerBlocks
				.map( ( innerSnapshot ) =>
					rebuildBlockFromSnapshot( innerSnapshot )
				)
				.filter( Boolean )
		: [];

	try {
		return createBlock(
			snapshot.name,
			snapshot.attributes || {},
			innerBlocks
		);
	} catch {
		return {
			name: snapshot.name,
			attributes: snapshot.attributes || {},
			innerBlocks,
		};
	}
}

function rebuildBlocksFromSnapshots( snapshots = [] ) {
	return Array.isArray( snapshots )
		? snapshots
				.map( ( snapshot ) => rebuildBlockFromSnapshot( snapshot ) )
				.filter( Boolean )
		: [];
}

function restoreBlockSnapshotsAtInsertionPoint(
	{ rootLocator, index, snapshots = [] },
	blockEditorDispatch = dispatch( blockEditorStore ),
	blockEditorSelect = select( blockEditorStore )
) {
	if ( ! Number.isInteger( index ) || index < 0 || snapshots.length === 0 ) {
		return {
			ok: false,
			error: 'Flavor Agent could not resolve the insertion point needed to restore the previous block state.',
		};
	}

	const resolvedRoot = resolveInsertionRootClientId(
		rootLocator,
		blockEditorSelect
	);

	if ( ! resolvedRoot.ok ) {
		return resolvedRoot;
	}

	blockEditorDispatch.insertBlocks(
		rebuildBlocksFromSnapshots( snapshots ),
		index,
		resolvedRoot.rootClientId,
		true,
		0
	);

	const restoredSlice = getCurrentBlockSlice(
		{
			rootLocator,
			index,
			count: snapshots.length,
		},
		blockEditorSelect
	);

	if (
		! restoredSlice ||
		! snapshotMatchesExpectedBlocks(
			normalizeBlockSnapshots( restoredSlice.blocks ),
			snapshots
		)
	) {
		return {
			ok: false,
			error: 'Flavor Agent could not restore the previous block state automatically.',
		};
	}

	return {
		ok: true,
	};
}

function resolveExpectedTargetForOperation(
	rawExpectedTarget,
	expectedBlockName = ''
) {
	if (
		rawExpectedTarget &&
		typeof rawExpectedTarget === 'object' &&
		! Array.isArray( rawExpectedTarget )
	) {
		return rawExpectedTarget;
	}

	if ( expectedBlockName ) {
		return {
			name: expectedBlockName,
		};
	}

	return null;
}

function isAncestorPath( ancestorPath = [], descendantPath = [] ) {
	if ( ancestorPath.length >= descendantPath.length ) {
		return false;
	}

	return ancestorPath.every(
		( segment, index ) => descendantPath[ index ] === segment
	);
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

function validateTemplateDocumentState(
	blockEditorDispatch = dispatch( blockEditorStore ),
	blockEditorSelect = select( blockEditorStore )
) {
	const currentBlocks = blockEditorSelect?.getBlocks?.();
	const validateBlocksToTemplate =
		blockEditorDispatch?.validateBlocksToTemplate;
	const readTemplateValidity = blockEditorSelect?.isValidTemplate;

	if ( ! Array.isArray( currentBlocks ) ) {
		return { ok: true };
	}

	let isValid = null;

	if ( typeof validateBlocksToTemplate === 'function' ) {
		isValid = validateBlocksToTemplate( currentBlocks );
	}

	if (
		typeof isValid !== 'boolean' &&
		typeof readTemplateValidity === 'function'
	) {
		isValid = readTemplateValidity();
	}

	if ( isValid === false ) {
		return {
			ok: false,
			error: 'Flavor Agent could not keep this document aligned with the current WordPress template constraints. The changes were reverted.',
		};
	}

	return { ok: true };
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
					attributes: normalizeSerializableValue(
						block.attributes || {}
					),
					clientId: block.clientId || null,
					area,
					slug,
				};

				if ( area ) {
					const currentAreaEntry = state.byArea.get( area ) || null;

					if (
						! currentAreaEntry ||
						( currentAreaEntry.slug && ! slug )
					) {
						state.byArea.set( area, entry );
					}
				}

				if ( slug ) {
					state.bySlug.set( slug, entry );
				}
			}

			if (
				Array.isArray( block?.innerBlocks ) &&
				block.innerBlocks.length
			) {
				visitBlocks( block.innerBlocks );
			}
		}
	};

	visitBlocks( blockEditorSelect?.getBlocks?.() || [] );

	return state;
}

function resolveWorkingTemplatePartTarget( operation, workingState ) {
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
		attributes: {
			...( block.attributes || {} ),
			...( nextAttributes || {} ),
		},
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
	return isTemplatePartSlugRegisteredForArea( slug, area, areaLookup );
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
	targetPath = null,
	blocks = getBlocks()
) {
	const rootBlocks = Array.isArray( blocks ) ? blocks : [];

	if ( placement === TEMPLATE_PART_PLACEMENT_START ) {
		return {
			rootLocator: {
				type: 'root',
				path: [],
			},
			index: 0,
			targetPath: null,
			targetBlockName: '',
		};
	}

	if ( placement === TEMPLATE_PART_PLACEMENT_END ) {
		return {
			rootLocator: {
				type: 'root',
				path: [],
			},
			index: rootBlocks.length,
			targetPath: null,
			targetBlockName: '',
		};
	}

	if (
		placement !== TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH &&
		placement !== TEMPLATE_PART_PLACEMENT_AFTER_BLOCK_PATH
	) {
		return null;
	}

	if ( ! Array.isArray( targetPath ) || targetPath.length === 0 ) {
		return null;
	}

	const parentPath = targetPath.slice( 0, -1 );
	const targetIndex = targetPath[ targetPath.length - 1 ];
	const container = getBlockContainerByPath( blocks, parentPath );
	const targetBlock = Array.isArray( container )
		? container[ targetIndex ] || null
		: null;

	if ( ! targetBlock ) {
		return null;
	}

	return {
		rootLocator: buildRootLocatorFromPath( blocks, parentPath ),
		index:
			placement === TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH
				? targetIndex
				: targetIndex + 1,
		targetPath,
		targetBlockName: targetBlock.name || '',
	};
}

function resolveTemplateInsertionPoint(
	placement,
	targetPath = null,
	blocks = getBlocks()
) {
	if ( ! placement ) {
		return resolveInsertionPoint();
	}

	if ( placement === TEMPLATE_PART_PLACEMENT_START ) {
		return {
			rootLocator: {
				type: 'root',
				path: [],
			},
			index: 0,
			targetPath: null,
			targetBlockName: '',
		};
	}

	if ( placement === TEMPLATE_PART_PLACEMENT_END ) {
		return {
			rootLocator: {
				type: 'root',
				path: [],
			},
			index: Array.isArray( blocks ) ? blocks.length : 0,
			targetPath: null,
			targetBlockName: '',
		};
	}

	return resolveTemplatePartInsertionPoint( placement, targetPath, blocks );
}

function matchesExpectedTemplateTarget( block, expectedTarget = {} ) {
	if ( ! block || ! expectedTarget?.name ) {
		return false;
	}

	if ( block.name !== expectedTarget.name ) {
		return false;
	}

	if (
		Number.isInteger( expectedTarget.childCount ) &&
		( Array.isArray( block.innerBlocks )
			? block.innerBlocks.length
			: 0 ) !== expectedTarget.childCount
	) {
		return false;
	}

	const expectedAttributes =
		expectedTarget.attributes &&
		typeof expectedTarget.attributes === 'object'
			? expectedTarget.attributes
			: {};
	const blockAttributes =
		block && typeof block.attributes === 'object' ? block.attributes : {};

	for ( const [ key, expectedValue ] of Object.entries(
		expectedAttributes
	) ) {
		if ( blockAttributes?.[ key ] !== expectedValue ) {
			return false;
		}
	}

	if ( expectedTarget.slot && typeof expectedTarget.slot === 'object' ) {
		const liveSlug =
			typeof blockAttributes?.slug === 'string'
				? blockAttributes.slug.trim()
				: '';
		const liveArea = inferTemplatePartArea( blockAttributes );
		const liveIsEmpty = ! liveSlug;

		if ( liveSlug !== ( expectedTarget.slot.slug || '' ) ) {
			return false;
		}

		if ( liveArea !== ( expectedTarget.slot.area || '' ) ) {
			return false;
		}

		if ( liveIsEmpty !== Boolean( expectedTarget.slot.isEmpty ) ) {
			return false;
		}
	}

	return true;
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
	const previousAttributes = {
		slug: Object.prototype.hasOwnProperty.call(
			block.attributes || {},
			'slug'
		)
			? block.attributes.slug
			: undefined,
		area: Object.prototype.hasOwnProperty.call(
			block.attributes || {},
			'area'
		)
			? block.attributes.area
			: undefined,
	};

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

	const preparedOperation = {
		type: operation.type,
		clientId: block.clientId,
		slug,
		area,
		currentSlug,
		previousAttributes,
		nextAttributes: {
			slug,
			area,
		},
		undoLocator: {
			area,
			expectedSlug: slug,
		},
	};

	updateWorkingTemplatePartState(
		workingState,
		block,
		preparedOperation.nextAttributes
	);

	return preparedOperation;
}

function prepareInsertPatternOperation(
	operation,
	workingBlocks = getBlocks(),
	blockEditorSelect = select( blockEditorStore )
) {
	const patternName = operation?.patternName || '';
	const placement = operation?.placement || '';
	const targetPath = Array.isArray( operation?.targetPath )
		? operation.targetPath
		: null;
	const expectedTarget =
		operation?.expectedTarget &&
		typeof operation.expectedTarget === 'object' &&
		! Array.isArray( operation.expectedTarget )
			? operation.expectedTarget
			: null;
	const insertionPoint = resolveTemplateInsertionPoint(
		placement,
		targetPath,
		workingBlocks
	);

	if ( ! insertionPoint ) {
		return {
			error:
				placement === TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH ||
				placement === TEMPLATE_PART_PLACEMENT_AFTER_BLOCK_PATH
					? 'Flavor Agent could not resolve the targetPath for this template insertion.'
					: `Flavor Agent could not resolve the ${ placement } insertion point for this template.`,
		};
	}

	if ( targetPath && expectedTarget ) {
		const liveTarget = getBlockByPath( workingBlocks, targetPath );

		if ( ! matchesExpectedTemplateTarget( liveTarget, expectedTarget ) ) {
			const expectedLabel =
				expectedTarget.label || expectedTarget.name || 'target block';

			return {
				error: `The anchored insertion target at path ${ targetPath.join(
					' > '
				) } no longer matches the expected ${ expectedLabel }. Regenerate recommendations and try again.`,
			};
		}
	}

	const insertionLockError = getStructuralMutationLockError( {
		containerPath: insertionPoint.rootLocator?.path || [],
		blocks: workingBlocks,
		blockEditorSelect,
		operation: 'insert',
		surfaceLabel: 'template',
	} );

	if ( insertionLockError ) {
		return {
			error: insertionLockError,
		};
	}

	const resolvedRoot =
		placement !== ''
			? resolveInsertionRootClientId(
					insertionPoint.rootLocator,
					blockEditorSelect
			  )
			: {
					ok: true,
					rootClientId: insertionPoint.rootClientId ?? null,
			  };

	if ( ! resolvedRoot.ok ) {
		return {
			error: resolvedRoot.error,
		};
	}

	const rootLocator =
		placement !== ''
			? insertionPoint.rootLocator
			: buildRootLocator( resolvedRoot.rootClientId );

	if ( ! rootLocator ) {
		return {
			error: 'Flavor Agent could not resolve the current insertion container for this pattern.',
		};
	}

	const pattern = resolvePatternByName(
		patternName,
		resolvedRoot.rootClientId
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

	const canInsertAll = blocks.every(
		( block ) =>
			! block?.name ||
			blockEditorSelect?.canInsertBlockType?.(
				block.name,
				resolvedRoot.rootClientId
			) !== false
	);

	if ( ! canInsertAll ) {
		let placementLabel = 'current insertion point';

		if (
			placement === TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH ||
			placement === TEMPLATE_PART_PLACEMENT_AFTER_BLOCK_PATH
		) {
			placementLabel = `${ placement.replaceAll( '_', ' ' ) } target`;
		} else if ( placement ) {
			placementLabel = `${ placement } of the template`;
		}

		return {
			error: `Pattern “${
				pattern.title || patternName
			}” cannot be inserted at the ${ placementLabel }.`,
		};
	}

	const preparedOperation = {
		type: TEMPLATE_OPERATION_INSERT_PATTERN,
		patternName,
		patternTitle: pattern.title || patternName,
		blocks,
		rootClientId: resolvedRoot.rootClientId,
		rootLocator,
		index: insertionPoint.index,
	};

	if ( placement ) {
		preparedOperation.placement = placement;
	}

	if ( targetPath ) {
		preparedOperation.targetPath = targetPath;
	}

	if ( expectedTarget ) {
		preparedOperation.expectedTarget = expectedTarget;
	}

	if ( insertionPoint.targetBlockName ) {
		preparedOperation.targetBlockName = insertionPoint.targetBlockName;
	}

	return preparedOperation;
}

function prepareTemplatePartInsertPatternOperation(
	operation,
	workingBlocks = getBlocks(),
	blockEditorSelect = select( blockEditorStore )
) {
	const patternName = operation?.patternName || '';
	const placement = operation?.placement || '';
	const targetPath = Array.isArray( operation?.targetPath )
		? operation.targetPath
		: null;
	const expectedTarget =
		operation?.expectedTarget &&
		typeof operation.expectedTarget === 'object' &&
		! Array.isArray( operation.expectedTarget )
			? operation.expectedTarget
			: null;
	const insertionPoint = resolveTemplatePartInsertionPoint(
		placement,
		targetPath,
		workingBlocks
	);

	if ( ! insertionPoint?.rootLocator ) {
		return {
			error:
				placement === TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH ||
				placement === TEMPLATE_PART_PLACEMENT_AFTER_BLOCK_PATH
					? 'Flavor Agent could not resolve the targetPath for this template-part insertion.'
					: `Flavor Agent could not resolve the ${ placement } insertion point for this template part.`,
		};
	}

	if ( targetPath && expectedTarget ) {
		const liveTarget = getBlockByPath( workingBlocks, targetPath );

		if ( ! matchesExpectedTemplateTarget( liveTarget, expectedTarget ) ) {
			const expectedLabel =
				expectedTarget.label || expectedTarget.name || 'target block';

			return {
				error: `The anchored insertion target at path ${ targetPath.join(
					' > '
				) } no longer matches the expected ${ expectedLabel }. Regenerate recommendations and try again.`,
			};
		}
	}

	const insertionLockError = getStructuralMutationLockError( {
		containerPath: insertionPoint.rootLocator?.path || [],
		blocks: workingBlocks,
		blockEditorSelect,
		operation: 'insert',
		surfaceLabel: 'template-part',
	} );

	if ( insertionLockError ) {
		return {
			error: insertionLockError,
		};
	}

	const resolvedRoot = resolveInsertionRootClientId(
		insertionPoint.rootLocator,
		blockEditorSelect
	);

	if ( ! resolvedRoot.ok ) {
		return {
			error: resolvedRoot.error,
		};
	}

	const pattern = resolvePatternByName(
		patternName,
		resolvedRoot.rootClientId
	);

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
				resolvedRoot.rootClientId
			) !== false
	);

	if ( ! canInsertAll ) {
		const placementLabel =
			placement === TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH ||
			placement === TEMPLATE_PART_PLACEMENT_AFTER_BLOCK_PATH
				? `${ placement.replaceAll( '_', ' ' ) } target`
				: `${ placement } of this template part`;

		return {
			error: `Pattern “${
				pattern.title || patternName
			}” cannot be inserted at the ${ placementLabel }.`,
		};
	}

	return {
		type: TEMPLATE_OPERATION_INSERT_PATTERN,
		patternName,
		patternTitle: pattern.title || patternName,
		placement,
		blocks,
		targetPath: insertionPoint.targetPath,
		expectedTarget,
		targetBlockName: insertionPoint.targetBlockName,
		rootLocator: insertionPoint.rootLocator,
		index: insertionPoint.index,
	};
}

function resolveTemplatePartTargetByPath( targetPath, workingBlocks ) {
	if ( ! Array.isArray( targetPath ) || targetPath.length === 0 ) {
		return {
			error: 'Flavor Agent could not resolve the targetPath for this template-part operation.',
		};
	}

	const targetBlock = getBlockByPath( workingBlocks, targetPath );

	if ( ! targetBlock ) {
		return {
			error: 'The target block path no longer exists in this template part. Regenerate recommendations and try again.',
		};
	}

	const parentPath = targetPath.slice( 0, -1 );
	const index = targetPath[ targetPath.length - 1 ];
	const rootLocator = buildRootLocatorFromPath( workingBlocks, parentPath );

	if ( ! rootLocator ) {
		return {
			error: 'Flavor Agent could not resolve the parent container for this template-part operation.',
		};
	}

	return {
		targetBlock,
		parentPath,
		rootLocator,
		index,
	};
}

function removeWorkingBlockAtPath( blocks, targetPath ) {
	const parentPath = targetPath.slice( 0, -1 );
	const index = targetPath[ targetPath.length - 1 ];
	const container = getBlockContainerByPath( blocks, parentPath );

	if ( ! Array.isArray( container ) || ! container[ index ] ) {
		return null;
	}

	return container.splice( index, 1 )[ 0 ] || null;
}

function insertWorkingBlocksAtIndex(
	blocks,
	parentPath,
	index,
	blocksToInsert
) {
	const container = getBlockContainerByPath( blocks, parentPath );

	if ( ! Array.isArray( container ) ) {
		return false;
	}

	container.splice( index, 0, ...cloneBlockTree( blocksToInsert ) );

	return true;
}

function replaceWorkingBlockAtPath( blocks, targetPath, blocksToInsert ) {
	const parentPath = targetPath.slice( 0, -1 );
	const index = targetPath[ targetPath.length - 1 ];
	const container = getBlockContainerByPath( blocks, parentPath );

	if ( ! Array.isArray( container ) || ! container[ index ] ) {
		return null;
	}

	return {
		removedBlock:
			container.splice(
				index,
				1,
				...cloneBlockTree( blocksToInsert )
			)[ 0 ] || null,
		index,
		parentPath,
	};
}

function buildRemovalAnchor(
	rootLocator,
	index,
	blockEditorSelect = select( blockEditorStore )
) {
	const nextSlice = getCurrentBlockSlice(
		{
			rootLocator,
			index,
			count: 1,
		},
		blockEditorSelect
	);

	if ( nextSlice?.blocks?.length ) {
		return {
			type: 'next-block',
			blocksSnapshot: normalizeBlockSnapshots( nextSlice.blocks ),
		};
	}

	return {
		type: 'end',
	};
}

function validateRemovalAnchor(
	anchor,
	rootLocator,
	index,
	blockEditorSelect = select( blockEditorStore )
) {
	if ( anchor?.type === 'next-block' ) {
		const expectedBlocks = Array.isArray( anchor.blocksSnapshot )
			? anchor.blocksSnapshot
			: [];
		const currentSlice = getCurrentBlockSlice(
			{
				rootLocator,
				index,
				count: expectedBlocks.length,
			},
			blockEditorSelect
		);

		if (
			! currentSlice ||
			! deepStructuralEqual(
				normalizeBlockSnapshots( currentSlice.blocks ),
				expectedBlocks
			)
		) {
			return {
				ok: false,
				error: 'Template-part content changed after this block was removed and cannot be undone automatically.',
			};
		}

		return {
			ok: true,
		};
	}

	const resolvedRoot = resolveRootLocator( rootLocator, blockEditorSelect );

	if ( ! resolvedRoot ) {
		return {
			ok: false,
			error: 'Flavor Agent could not resolve the parent container for this removed block.',
		};
	}

	if ( resolvedRoot.blocks.length !== index ) {
		return {
			ok: false,
			error: 'Template-part content changed after this block was removed and cannot be undone automatically.',
		};
	}

	return {
		ok: true,
	};
}

function prepareTemplatePartReplaceBlockOperation(
	operation,
	workingBlocks = getBlocks(),
	blockEditorSelect = select( blockEditorStore )
) {
	const patternName = operation?.patternName || '';
	const expectedBlockName = operation?.expectedBlockName || '';
	const targetPath = Array.isArray( operation?.targetPath )
		? operation.targetPath
		: [];
	const resolvedTarget = resolveTemplatePartTargetByPath(
		targetPath,
		workingBlocks
	);

	if ( resolvedTarget.error ) {
		return resolvedTarget;
	}

	const expectedTarget = resolveExpectedTargetForOperation(
		operation?.expectedTarget,
		expectedBlockName
	);

	if (
		expectedTarget &&
		! matchesExpectedTemplateTarget(
			resolvedTarget.targetBlock,
			expectedTarget
		)
	) {
		const expectedLabel =
			expectedTarget.label || expectedTarget.name || expectedBlockName;

		return {
			error: `The target block at path ${ targetPath.join(
				' > '
			) } no longer matches the expected ${ expectedLabel }.`,
		};
	}

	if ( resolvedTarget.targetBlock?.name !== expectedBlockName ) {
		return {
			error: `The target block at path ${ targetPath.join(
				' > '
			) } is no longer a “${ expectedBlockName }” block.`,
		};
	}

	const lockError = getStructuralMutationLockError( {
		targetBlock: resolvedTarget.targetBlock,
		targetPath,
		containerPath: resolvedTarget.parentPath,
		blocks: workingBlocks,
		blockEditorSelect,
		operation: 'replace',
		surfaceLabel: 'template-part',
	} );

	if ( lockError ) {
		return {
			error: lockError,
		};
	}

	const resolvedRoot = resolveInsertionRootClientId(
		resolvedTarget.rootLocator,
		blockEditorSelect
	);

	if ( ! resolvedRoot.ok ) {
		return {
			error: resolvedRoot.error,
		};
	}

	const pattern = resolvePatternByName(
		patternName,
		resolvedRoot.rootClientId
	);

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
				resolvedRoot.rootClientId
			) !== false
	);

	if ( ! canInsertAll ) {
		return {
			error: `Pattern “${
				pattern.title || patternName
			}” cannot replace the targeted block at path ${ targetPath.join(
				' > '
			) }.`,
		};
	}

	return {
		type: TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN,
		patternName,
		patternTitle: pattern.title || patternName,
		expectedBlockName,
		expectedTarget,
		targetPath,
		rootLocator: resolvedTarget.rootLocator,
		index: resolvedTarget.index,
		blocks,
	};
}

function prepareTemplatePartRemoveBlockOperation(
	operation,
	workingBlocks = getBlocks(),
	blockEditorSelect = select( blockEditorStore )
) {
	const expectedBlockName = operation?.expectedBlockName || '';
	const targetPath = Array.isArray( operation?.targetPath )
		? operation.targetPath
		: [];
	const resolvedTarget = resolveTemplatePartTargetByPath(
		targetPath,
		workingBlocks
	);

	if ( resolvedTarget.error ) {
		return resolvedTarget;
	}

	const expectedTarget = resolveExpectedTargetForOperation(
		operation?.expectedTarget,
		expectedBlockName
	);

	if (
		expectedTarget &&
		! matchesExpectedTemplateTarget(
			resolvedTarget.targetBlock,
			expectedTarget
		)
	) {
		const expectedLabel =
			expectedTarget.label || expectedTarget.name || expectedBlockName;

		return {
			error: `The target block at path ${ targetPath.join(
				' > '
			) } no longer matches the expected ${ expectedLabel }.`,
		};
	}

	if ( resolvedTarget.targetBlock?.name !== expectedBlockName ) {
		return {
			error: `The target block at path ${ targetPath.join(
				' > '
			) } is no longer a “${ expectedBlockName }” block.`,
		};
	}

	const lockError = getStructuralMutationLockError( {
		targetBlock: resolvedTarget.targetBlock,
		targetPath,
		containerPath: resolvedTarget.parentPath,
		blocks: workingBlocks,
		blockEditorSelect,
		operation: 'remove',
		surfaceLabel: 'template-part',
	} );

	if ( lockError ) {
		return {
			error: lockError,
		};
	}

	return {
		type: TEMPLATE_OPERATION_REMOVE_BLOCK,
		expectedBlockName,
		expectedTarget,
		targetPath,
		rootLocator: resolvedTarget.rootLocator,
		index: resolvedTarget.index,
	};
}

export function prepareTemplateSuggestionOperations( suggestion ) {
	const sequence = validateTemplateOperationSequence(
		suggestion?.operations
	);

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
				const blockEditorSelect = select( blockEditorStore );
				const prepared = prepareInsertPatternOperation(
					operation,
					blockEditorSelect?.getBlocks?.() || [],
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
	let workingBlocks;
	const getWorkingBlocks = () => {
		if ( ! workingBlocks ) {
			workingBlocks = cloneBlockTree(
				blockEditorSelect?.getBlocks?.() || []
			);
		}

		return workingBlocks;
	};
	const targetedPaths = [];

	for ( const operation of sequence.operations ) {
		const targetPath = Array.isArray( operation?.targetPath )
			? operation.targetPath
			: null;

		if ( targetPath ) {
			const hasConflictingTarget = targetedPaths.some(
				( candidate ) =>
					candidate.join( '|' ) === targetPath.join( '|' ) ||
					isAncestorPath( candidate, targetPath ) ||
					isAncestorPath( targetPath, candidate )
			);

			if ( hasConflictingTarget ) {
				return {
					ok: false,
					error: 'This suggestion targets overlapping template-part block paths and cannot be applied automatically.',
				};
			}
		}

		const currentWorkingBlocks = getWorkingBlocks();

		switch ( operation?.type ) {
			case TEMPLATE_OPERATION_INSERT_PATTERN: {
				const prepared = prepareTemplatePartInsertPatternOperation(
					operation,
					currentWorkingBlocks,
					blockEditorSelect
				);

				if ( prepared?.error ) {
					return { ok: false, error: prepared.error };
				}

				preparedOperations.push( prepared );

				if (
					! insertWorkingBlocksAtIndex(
						currentWorkingBlocks,
						prepared.rootLocator?.path || [],
						prepared.index,
						prepared.blocks
					)
				) {
					return {
						ok: false,
						error: 'Flavor Agent could not update the working template-part structure for this insertion.',
					};
				}

				break;
			}

			case TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN: {
				const prepared = prepareTemplatePartReplaceBlockOperation(
					operation,
					currentWorkingBlocks,
					blockEditorSelect
				);

				if ( prepared?.error ) {
					return { ok: false, error: prepared.error };
				}

				preparedOperations.push( prepared );

				if (
					! replaceWorkingBlockAtPath(
						currentWorkingBlocks,
						prepared.targetPath,
						prepared.blocks
					)
				) {
					return {
						ok: false,
						error: 'Flavor Agent could not update the working template-part structure for this replacement.',
					};
				}

				break;
			}

			case TEMPLATE_OPERATION_REMOVE_BLOCK: {
				const prepared = prepareTemplatePartRemoveBlockOperation(
					operation,
					currentWorkingBlocks,
					blockEditorSelect
				);

				if ( prepared?.error ) {
					return { ok: false, error: prepared.error };
				}

				preparedOperations.push( prepared );

				if (
					! removeWorkingBlockAtPath(
						currentWorkingBlocks,
						prepared.targetPath
					)
				) {
					return {
						ok: false,
						error: 'Flavor Agent could not update the working template-part structure for this removal.',
					};
				}

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

		if ( targetPath ) {
			targetedPaths.push( targetPath );
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

			case TEMPLATE_OPERATION_INSERT_PATTERN: {
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

				if ( ! insertedSlice?.blocks?.length ) {
					return {
						ok: false,
						error: `Pattern “${
							operation.patternTitle ||
							operation.patternName ||
							'unknown'
						}” could not be inserted into this template.`,
					};
				}

				appliedOperations.push( {
					type: operation.type,
					patternName: operation.patternName,
					patternTitle: operation.patternTitle,
					placement: operation.placement || '',
					targetPath: operation.targetPath || null,
					expectedTarget: operation.expectedTarget || null,
					targetBlockName: operation.targetBlockName || '',
					rootLocator: operation.rootLocator,
					index: operation.index,
					insertedBlocksSnapshot:
						resolveRecordedInsertedBlocksSnapshot(
							insertedSlice,
							operation.blocks
						),
				} );
				break;
			}
		}
	}

	const templateValidity = validateTemplateDocumentState(
		blockEditorDispatch,
		blockEditorSelect
	);

	if ( ! templateValidity.ok ) {
		const rollback = undoTemplateSuggestionOperations( {
			operations: appliedOperations,
		} );

		return {
			ok: false,
			error: rollback.ok
				? templateValidity.error
				: rollback.error ||
				  `${ templateValidity.error } Flavor Agent could not restore the previous state automatically.`,
		};
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

	const blockEditorSelect = select( blockEditorStore );
	let blockEditorDispatch;
	const getBlockEditorDispatch = () => {
		if ( ! blockEditorDispatch ) {
			blockEditorDispatch = dispatch( blockEditorStore );
		}

		return blockEditorDispatch;
	};
	const appliedOperations = [];

	for ( const operation of prepared.operations ) {
		switch ( operation.type ) {
			case TEMPLATE_OPERATION_INSERT_PATTERN: {
				if ( operation.targetPath && operation.expectedTarget ) {
					const liveTarget = getBlockByPath(
						blockEditorSelect?.getBlocks?.() || [],
						operation.targetPath
					);

					if (
						! matchesExpectedTemplateTarget(
							liveTarget,
							operation.expectedTarget
						)
					) {
						const expectedLabel =
							operation.expectedTarget.label ||
							operation.expectedTarget.name ||
							'target block';

						return {
							ok: false,
							error: `The anchored insertion target at path ${ operation.targetPath.join(
								' > '
							) } no longer matches the expected ${ expectedLabel }. Regenerate recommendations and try again.`,
						};
					}
				}

				const insertionLockError = getStructuralMutationLockError( {
					containerPath: operation.rootLocator?.path || [],
					blocks: blockEditorSelect?.getBlocks?.() || [],
					blockEditorSelect,
					operation: 'insert',
					surfaceLabel: 'template-part',
				} );

				if ( insertionLockError ) {
					return {
						ok: false,
						error: insertionLockError,
					};
				}

				const resolvedRoot = resolveInsertionRootClientId(
					operation.rootLocator,
					blockEditorSelect
				);

				if ( ! resolvedRoot.ok ) {
					return {
						ok: false,
						error: resolvedRoot.error,
					};
				}

				const editorDispatch = getBlockEditorDispatch();

				editorDispatch.insertBlocks(
					operation.blocks,
					operation.index,
					resolvedRoot.rootClientId,
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
				const insertedBlocksSnapshot =
					resolveRecordedInsertedBlocksSnapshot(
						insertedSlice,
						operation.blocks
					);

				if ( ! insertedSlice?.blocks?.length ) {
					return {
						ok: false,
						error: `Pattern “${
							operation.patternTitle ||
							operation.patternName ||
							'unknown'
						}” could not be inserted into this template part.`,
					};
				}
				appliedOperations.push( {
					type: operation.type,
					patternName: operation.patternName,
					patternTitle: operation.patternTitle,
					placement: operation.placement,
					targetPath: operation.targetPath || null,
					expectedTarget: operation.expectedTarget || null,
					targetBlockName: operation.targetBlockName || '',
					rootLocator: operation.rootLocator,
					index: operation.index,
					insertedBlocksSnapshot,
				} );
				break;
			}

			case TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN: {
				const targetBlock = getBlockByPath(
					blockEditorSelect?.getBlocks?.() || [],
					operation.targetPath
				);
				const resolvedRoot = resolveInsertionRootClientId(
					operation.rootLocator,
					blockEditorSelect
				);

				if ( ! targetBlock?.clientId ) {
					return {
						ok: false,
						error: 'The targeted block is no longer available for replacement. Regenerate recommendations and try again.',
					};
				}

				if ( targetBlock.name !== operation.expectedBlockName ) {
					return {
						ok: false,
						error: `The target block at path ${ operation.targetPath.join(
							' > '
						) } is no longer a “${
							operation.expectedBlockName
						}” block.`,
					};
				}

				if (
					operation.expectedTarget &&
					! matchesExpectedTemplateTarget(
						targetBlock,
						operation.expectedTarget
					)
				) {
					const expectedLabel =
						operation.expectedTarget.label ||
						operation.expectedTarget.name ||
						operation.expectedBlockName;

					return {
						ok: false,
						error: `The target block at path ${ operation.targetPath.join(
							' > '
						) } no longer matches the expected ${ expectedLabel }.`,
					};
				}

				if ( ! resolvedRoot.ok ) {
					return {
						ok: false,
						error: resolvedRoot.error,
					};
				}

				const removedBlocksSnapshot = normalizeBlockSnapshots( [
					targetBlock,
				] );
				const editorDispatch = getBlockEditorDispatch();
				const lockError = getStructuralMutationLockError( {
					targetBlock,
					targetPath: operation.targetPath,
					containerPath: operation.targetPath.slice( 0, -1 ),
					blocks: blockEditorSelect?.getBlocks?.() || [],
					blockEditorSelect,
					operation: 'replace',
					surfaceLabel: 'template-part',
				} );

				if ( lockError ) {
					return {
						ok: false,
						error: lockError,
					};
				}

				editorDispatch.removeBlocks( [ targetBlock.clientId ], false );

				if (
					isBlockPresent( targetBlock.clientId, blockEditorSelect )
				) {
					return {
						ok: false,
						error: `The target block at path ${ operation.targetPath.join(
							' > '
						) } could not be removed automatically.`,
					};
				}

				editorDispatch.insertBlocks(
					operation.blocks,
					operation.index,
					resolvedRoot.rootClientId,
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

				if ( ! insertedSlice?.blocks?.length ) {
					const restoreResult = restoreBlockSnapshotsAtInsertionPoint(
						{
							rootLocator: operation.rootLocator,
							index: operation.index,
							snapshots: removedBlocksSnapshot,
						},
						editorDispatch,
						blockEditorSelect
					);

					return {
						ok: false,
						error: restoreResult.ok
							? `Pattern “${
									operation.patternTitle ||
									operation.patternName ||
									'unknown'
							  }” could not replace the targeted block in this template part.`
							: `Pattern “${
									operation.patternTitle ||
									operation.patternName ||
									'unknown'
							  }” could not replace the targeted block in this template part. ${
									restoreResult.error
							  }`,
					};
				}

				appliedOperations.push( {
					type: operation.type,
					patternName: operation.patternName,
					patternTitle: operation.patternTitle,
					expectedBlockName: operation.expectedBlockName,
					expectedTarget: operation.expectedTarget || null,
					targetPath: operation.targetPath,
					rootLocator: operation.rootLocator,
					index: operation.index,
					removedBlocksSnapshot,
					insertedBlocksSnapshot:
						resolveRecordedInsertedBlocksSnapshot(
							insertedSlice,
							operation.blocks
						),
				} );
				break;
			}

			case TEMPLATE_OPERATION_REMOVE_BLOCK: {
				const targetBlock = getBlockByPath(
					blockEditorSelect?.getBlocks?.() || [],
					operation.targetPath
				);

				if ( ! targetBlock?.clientId ) {
					return {
						ok: false,
						error: 'The targeted block is no longer available for removal. Regenerate recommendations and try again.',
					};
				}

				if ( targetBlock.name !== operation.expectedBlockName ) {
					return {
						ok: false,
						error: `The target block at path ${ operation.targetPath.join(
							' > '
						) } is no longer a “${
							operation.expectedBlockName
						}” block.`,
					};
				}

				if (
					operation.expectedTarget &&
					! matchesExpectedTemplateTarget(
						targetBlock,
						operation.expectedTarget
					)
				) {
					const expectedLabel =
						operation.expectedTarget.label ||
						operation.expectedTarget.name ||
						operation.expectedBlockName;

					return {
						ok: false,
						error: `The target block at path ${ operation.targetPath.join(
							' > '
						) } no longer matches the expected ${ expectedLabel }.`,
					};
				}

				const lockError = getStructuralMutationLockError( {
					targetBlock,
					targetPath: operation.targetPath,
					containerPath: operation.targetPath.slice( 0, -1 ),
					blocks: blockEditorSelect?.getBlocks?.() || [],
					blockEditorSelect,
					operation: 'remove',
					surfaceLabel: 'template-part',
				} );

				if ( lockError ) {
					return {
						ok: false,
						error: lockError,
					};
				}

				getBlockEditorDispatch().removeBlocks(
					[ targetBlock.clientId ],
					false
				);

				if (
					isBlockPresent( targetBlock.clientId, blockEditorSelect )
				) {
					return {
						ok: false,
						error: `The target block at path ${ operation.targetPath.join(
							' > '
						) } could not be removed automatically.`,
					};
				}

				appliedOperations.push( {
					type: operation.type,
					expectedBlockName: operation.expectedBlockName,
					expectedTarget: operation.expectedTarget || null,
					targetPath: operation.targetPath,
					rootLocator: operation.rootLocator,
					index: operation.index,
					removedBlocksSnapshot: normalizeBlockSnapshots( [
						targetBlock,
					] ),
					postApplyAnchor: buildRemovalAnchor(
						operation.rootLocator,
						operation.index,
						blockEditorSelect
					),
				} );
				break;
			}
		}
	}

	const templateValidity = validateTemplateDocumentState(
		getBlockEditorDispatch(),
		blockEditorSelect
	);

	if ( ! templateValidity.ok ) {
		const rollback = undoTemplatePartSuggestionOperations( {
			operations: appliedOperations,
		} );

		return {
			ok: false,
			error: rollback.ok
				? templateValidity.error
				: rollback.error ||
				  `${ templateValidity.error } Flavor Agent could not restore the previous state automatically.`,
		};
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
			matchesTemplatePartArea( candidate, expectedArea, areaLookup )
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
			slug: undefined,
			area: undefined,
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

	if ( ! operation?.rootLocator || ! Number.isInteger( operation?.index ) ) {
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
		! deepStructuralEqual( currentSnapshot, insertedBlocksSnapshot ) &&
		! snapshotMatchesExpectedBlocks(
			currentSnapshot,
			insertedBlocksSnapshot
		)
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

function prepareUndoReplaceBlockWithPatternOperation(
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

	if (
		! Array.isArray( operation?.removedBlocksSnapshot ) ||
		operation.removedBlocksSnapshot.length === 0
	) {
		return {
			error: 'This template-part block replacement is missing the removed-block snapshot needed for automatic undo.',
		};
	}

	return {
		type: TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN,
		insertedClientIds: resolvedSlice.insertedClientIds,
		rootLocator: operation.rootLocator,
		index: operation.index,
		removedBlocksSnapshot: operation.removedBlocksSnapshot,
	};
}

function prepareUndoRemoveBlockOperation(
	operation,
	blockEditorSelect = select( blockEditorStore )
) {
	if (
		! Array.isArray( operation?.removedBlocksSnapshot ) ||
		operation.removedBlocksSnapshot.length === 0
	) {
		return {
			error: 'This template-part block removal is missing the removed-block snapshot needed for automatic undo.',
		};
	}

	const anchorCheck = validateRemovalAnchor(
		operation?.postApplyAnchor,
		operation?.rootLocator,
		operation?.index,
		blockEditorSelect
	);

	if ( ! anchorCheck.ok ) {
		return {
			error: anchorCheck.error,
		};
	}

	return {
		type: TEMPLATE_OPERATION_REMOVE_BLOCK,
		rootLocator: operation.rootLocator,
		index: operation.index,
		removedBlocksSnapshot: operation.removedBlocksSnapshot,
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

			case TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN: {
				const prepared = prepareUndoReplaceBlockWithPatternOperation(
					operation,
					blockEditorSelect
				);

				if ( prepared?.error ) {
					return { ok: false, error: prepared.error };
				}

				preparedOperations.push( prepared );
				break;
			}

			case TEMPLATE_OPERATION_REMOVE_BLOCK: {
				const prepared = prepareUndoRemoveBlockOperation(
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
	const blockEditorSelect = select( blockEditorStore );
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

			case TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN: {
				const resolvedRoot = resolveInsertionRootClientId(
					operation.rootLocator,
					blockEditorSelect
				);

				if ( ! resolvedRoot.ok ) {
					return {
						ok: false,
						error: resolvedRoot.error,
					};
				}

				blockEditorDispatch.removeBlocks(
					operation.insertedClientIds,
					false
				);
				blockEditorDispatch.insertBlocks(
					rebuildBlocksFromSnapshots(
						operation.removedBlocksSnapshot
					),
					operation.index,
					resolvedRoot.rootClientId,
					true,
					0
				);
				undoneOperations.push( {
					type: operation.type,
					insertedClientIds: operation.insertedClientIds,
				} );
				break;
			}

			case TEMPLATE_OPERATION_REMOVE_BLOCK: {
				const resolvedRoot = resolveInsertionRootClientId(
					operation.rootLocator,
					blockEditorSelect
				);

				if ( ! resolvedRoot.ok ) {
					return {
						ok: false,
						error: resolvedRoot.error,
					};
				}

				blockEditorDispatch.insertBlocks(
					rebuildBlocksFromSnapshots(
						operation.removedBlocksSnapshot
					),
					operation.index,
					resolvedRoot.rootClientId,
					true,
					0
				);
				undoneOperations.push( {
					type: operation.type,
				} );
				break;
			}
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
