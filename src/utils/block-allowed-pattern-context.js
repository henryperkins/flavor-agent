import { buildContextSignature } from './context-signature';
import {
	BLOCK_OPERATION_ACTION_INSERT_AFTER,
	BLOCK_OPERATION_ACTION_INSERT_BEFORE,
	BLOCK_OPERATION_ACTION_REPLACE,
} from './block-operation-catalog';

const ALLOWED_PATTERN_SOURCES = new Set( [
	'core',
	'theme',
	'plugin',
	'user',
] );

function toNonEmptyString( value ) {
	return typeof value === 'string' && value.trim() !== '' ? value.trim() : '';
}

function normalizeStringArray( values ) {
	return Array.isArray( values )
		? [
				...new Set(
					values
						.map( toNonEmptyString )
						.filter( ( value ) => value !== '' )
				),
		  ]
		: [];
}

function normalizePatternSource( pattern, name ) {
	const source = toNonEmptyString( pattern?.source );

	if ( ALLOWED_PATTERN_SOURCES.has( source ) ) {
		return source;
	}

	if (
		pattern?.type === 'user' ||
		pattern?.source === 'synced' ||
		name.startsWith( 'core/block/' )
	) {
		return 'user';
	}

	if ( source === 'pattern-directory' || name.startsWith( 'core/' ) ) {
		return 'core';
	}

	return 'theme';
}

function patternHasRenderableContent( pattern ) {
	if ( Array.isArray( pattern?.blocks ) && pattern.blocks.length > 0 ) {
		return true;
	}

	return (
		typeof pattern?.content === 'string' && pattern.content.trim() !== ''
	);
}

function targetAllowsStructuralActions( target = {} ) {
	const editingMode = toNonEmptyString( target.editingMode || 'default' );

	return (
		toNonEmptyString( target.targetClientId ) !== '' &&
		toNonEmptyString( target.targetBlockName ) !== '' &&
		editingMode !== 'disabled' &&
		editingMode !== 'contentOnly' &&
		target.isInsideContentOnly !== true &&
		target.isTargetLocked !== true
	);
}

function getAllowedActionsForPattern( pattern, target ) {
	if ( ! targetAllowsStructuralActions( target ) ) {
		return [];
	}

	const actions = [
		BLOCK_OPERATION_ACTION_INSERT_BEFORE,
		BLOCK_OPERATION_ACTION_INSERT_AFTER,
	];
	const blockTypes = normalizeStringArray( pattern.blockTypes );

	if (
		blockTypes.length === 0 ||
		blockTypes.includes( target.targetBlockName )
	) {
		actions.push( BLOCK_OPERATION_ACTION_REPLACE );
	}

	return actions;
}

export function buildBlockOperationTargetSignature( target = {} ) {
	return buildContextSignature( {
		clientId: toNonEmptyString( target.clientId || target.targetClientId ),
		name: toNonEmptyString( target.name || target.targetBlockName ),
		structuralIdentity: target.structuralIdentity || {},
		editingMode: toNonEmptyString( target.editingMode || 'default' ),
		isInsideContentOnly: target.isInsideContentOnly === true,
		isTargetLocked: target.isTargetLocked === true,
		childCount: Number.isFinite( target.childCount )
			? target.childCount
			: null,
	} );
}

export function buildAllowedPatternContext( patterns = [], target = {} ) {
	const targetClientId = toNonEmptyString( target.targetClientId );
	const targetBlockName = toNonEmptyString( target.targetBlockName );
	const targetSignature = toNonEmptyString( target.targetSignature );
	const seen = new Set();
	const allowedPatterns = [];

	for ( const pattern of Array.isArray( patterns ) ? patterns : [] ) {
		const name = toNonEmptyString( pattern?.name );

		if (
			! name ||
			seen.has( name ) ||
			pattern?.inserter === false ||
			! patternHasRenderableContent( pattern )
		) {
			continue;
		}

		const allowedActions = getAllowedActionsForPattern( pattern, {
			...target,
			targetClientId,
			targetBlockName,
		} );

		if ( allowedActions.length === 0 ) {
			continue;
		}

		seen.add( name );
		allowedPatterns.push( {
			name,
			title: toNonEmptyString( pattern.title ),
			source: normalizePatternSource( pattern, name ),
			categories: normalizeStringArray( pattern.categories ),
			blockTypes: normalizeStringArray( pattern.blockTypes ),
			allowedActions,
		} );
	}

	return {
		targetClientId,
		targetBlockName,
		targetSignature,
		allowedPatterns,
	};
}
