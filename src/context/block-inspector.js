/**
 * Block Introspector
 *
 * Recursively analyzes a block tree and produces a capability manifest
 * for every block — its supports, attributes schema, registered styles,
 * variations, current attribute values, and which Inspector panels it
 * exposes. This manifest is what the LLM uses to make specific,
 * actionable recommendations per Inspector tab.
 *
 * The full recursive tree is built internally; a summarized version
 * is what gets sent to the LLM to stay within token budgets.
 */
import { select } from '@wordpress/data';
import { store as blocksStore } from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';

// ── Supports → Inspector panel mapping ──────────────────────

const SUPPORT_TO_PANEL = {
	'color.background': 'color',
	'color.text': 'color',
	'color.link': 'color',
	'color.heading': 'color',
	'color.button': 'color',
	'color.gradients': 'color',
	'typography.fontSize': 'typography',
	'typography.lineHeight': 'typography',
	'typography.textAlign': 'typography',
	'spacing.margin': 'dimensions',
	'spacing.padding': 'dimensions',
	'spacing.blockGap': 'dimensions',
	'dimensions.aspectRatio': 'dimensions',
	'dimensions.minHeight': 'dimensions',
	'dimensions.height': 'dimensions',
	'dimensions.width': 'dimensions',
	'border.color': 'border',
	'border.radius': 'border',
	'border.style': 'border',
	'border.width': 'border',
	shadow: 'shadow',
	'filter.duotone': 'filter',
	'background.backgroundImage': 'background',
	'background.backgroundSize': 'background',
	'position.sticky': 'position',
	'position.fixed': 'position',
	layout: 'layout',
	anchor: 'advanced',
};

/**
 * Flatten a nested supports object into dot-path → value entries.
 * @param obj
 * @param prefix
 */
function flattenSupports( obj, prefix = '' ) {
	const entries = [];
	if ( obj == null || typeof obj !== 'object' ) {
		return entries;
	}

	for ( const [ key, val ] of Object.entries( obj ) ) {
		const path = prefix ? `${ prefix }.${ key }` : key;

		if (
			typeof val === 'boolean' ||
			typeof val === 'string' ||
			Array.isArray( val )
		) {
			entries.push( [ path, val ] );
		} else if ( val === true ) {
			entries.push( [ path, true ] );
		} else if ( typeof val === 'object' && val !== null ) {
			entries.push( ...flattenSupports( val, path ) );
		}
	}
	return entries;
}

/**
 * Determine which Inspector panels a block exposes based on supports.
 * @param supports
 */
export function resolveInspectorPanels( supports ) {
	const panels = {};
	const flat = flattenSupports( supports );

	for ( const [ path, value ] of flat ) {
		const panelKey = SUPPORT_TO_PANEL[ path ];
		if ( panelKey && isTruthy( value ) ) {
			if ( ! panels[ panelKey ] ) {
				panels[ panelKey ] = [];
			}
			panels[ panelKey ].push( path );
		}
	}

	return panels;
}

function isTruthy( val ) {
	if ( val === true ) {
		return true;
	}
	if ( val === false || val == null ) {
		return false;
	}
	if ( Array.isArray( val ) ) {
		return val.length > 0;
	}
	if ( typeof val === 'object' ) {
		return Object.keys( val ).length > 0;
	}
	return !! val;
}

/**
 * Introspect a single block type by name.
 * @param blockName
 */
export function introspectBlockType( blockName ) {
	const store = select( blocksStore );
	const blockType = store.getBlockType( blockName );
	if ( ! blockType ) {
		return null;
	}

	const supports = blockType.supports || {};
	const attributes = blockType.attributes || {};
	const styles = store.getBlockStyles( blockName ) || [];
	const variations = store.getBlockVariations( blockName, 'block' ) || [];

	const contentAttrs = {};
	const configAttrs = {};
	for ( const [ name, def ] of Object.entries( attributes ) ) {
		const entry = {
			type: def.type,
			default: def.default,
			role: def.role,
		};
		if ( def.enum ) {
			entry.enum = def.enum;
		}
		if ( def.source ) {
			entry.source = def.source;
		}

		if ( def.role === 'content' ) {
			contentAttrs[ name ] = entry;
		} else {
			configAttrs[ name ] = entry;
		}
	}

	return {
		name: blockName,
		title: blockType.title,
		category: blockType.category,
		description: blockType.description,
		supports,
		inspectorPanels: resolveInspectorPanels( supports ),
		contentAttributes: contentAttrs,
		configAttributes: configAttrs,
		styles: styles.map( ( s ) => ( {
			name: s.name,
			label: s.label,
			isDefault: s.isDefault || false,
		} ) ),
		variations: variations.map( ( v ) => ( {
			name: v.name,
			title: v.title,
			description: v.description,
			scope: v.scope,
		} ) ),
		parent: blockType.parent || null,
		allowedBlocks: blockType.allowedBlocks || null,
		apiVersion: blockType.apiVersion || 1,
	};
}

/**
 * Introspect a live block instance.
 * @param clientId
 */
export function introspectBlockInstance( clientId ) {
	const editor = select( blockEditorStore );
	const blockName = editor.getBlockName( clientId );
	if ( ! blockName ) {
		return null;
	}

	const typeMeta = introspectBlockType( blockName );
	if ( ! typeMeta ) {
		return null;
	}

	const currentAttrs = editor.getBlockAttributes( clientId );
	const editingMode = editor.getBlockEditingMode( clientId );
	const parentIds = editor.getBlockParents( clientId );
	const childCount = editor.getBlockCount( clientId );
	const isInsideContentOnly = parentIds.some(
		( parentId ) => editor.getBlockEditingMode( parentId ) === 'contentOnly'
	);
	const blockVisibility = currentAttrs?.metadata?.blockVisibility ?? null;

	return {
		...typeMeta,
		clientId,
		currentAttributes: currentAttrs,
		editingMode,
		parentChain: parentIds,
		childCount,
		isInsideContentOnly,
		blockVisibility,
		activeStyle: currentAttrs?.className
			? extractActiveStyle( currentAttrs.className, typeMeta.styles )
			: null,
	};
}

function extractActiveStyle( className, registeredStyles ) {
	if ( ! className ) {
		return null;
	}
	for ( const style of registeredStyles ) {
		if ( className.includes( `is-style-${ style.name }` ) ) {
			return style.name;
		}
	}
	return null;
}

/**
 * Recursively introspect an entire block tree from a root.
 * @param rootClientId
 * @param maxDepth
 */
export function introspectBlockTree( rootClientId = null, maxDepth = 10 ) {
	if ( maxDepth <= 0 ) {
		return [];
	}

	const editor = select( blockEditorStore );
	const childIds = editor.getBlockOrder( rootClientId || '' );

	return childIds
		.map( ( clientId ) => {
			const instance = introspectBlockInstance( clientId );
			if ( ! instance ) {
				return null;
			}

			const children = introspectBlockTree( clientId, maxDepth - 1 );

			return {
				...instance,
				innerBlocks: children.filter( Boolean ),
			};
		} )
		.filter( Boolean );
}

/**
 * Summarize a full introspected tree for the LLM prompt.
 * @param tree
 */
export function summarizeTree( tree ) {
	return tree.map( ( node ) => {
		const summary = {
			block: node.name,
			title: node.title,
		};

		const meaningful = pickMeaningfulAttributes(
			node.currentAttributes,
			node.name
		);
		if ( Object.keys( meaningful ).length ) {
			summary.currentValues = meaningful;
		}

		const panels = Object.keys( node.inspectorPanels );
		if ( panels.length ) {
			summary.availablePanels = panels;
		}

		if ( node.activeStyle ) {
			summary.activeStyle = node.activeStyle;
		}

		if ( node.styles.length > 1 ) {
			summary.styleOptions = node.styles.map( ( s ) => s.name );
		}

		if ( node.editingMode !== 'default' ) {
			summary.editingMode = node.editingMode;
		}

		if ( node.innerBlocks.length ) {
			summary.children = summarizeTree( node.innerBlocks );
		}

		return summary;
	} );
}

function pickMeaningfulAttributes( attrs, blockName ) {
	if ( ! attrs ) {
		return {};
	}

	const SKIP_KEYS = new Set( [ 'lock', 'metadata', 'className' ] );

	const result = {};
	for ( const [ key, val ] of Object.entries( attrs ) ) {
		if ( SKIP_KEYS.has( key ) ) {
			continue;
		}
		if ( val === undefined || val === null || val === '' ) {
			continue;
		}
		if ( typeof val === 'object' && Object.keys( val ).length === 0 ) {
			continue;
		}

		result[ key ] = val;
	}
	return result;
}

/**
 * Build a deduplicated block capability index for all unique block types
 * present in a tree.
 * @param tree
 */
export function buildCapabilityIndex( tree ) {
	const index = {};

	function walk( nodes ) {
		for ( const node of nodes ) {
			if ( ! index[ node.name ] ) {
				index[ node.name ] = {
					title: node.title,
					inspectorPanels: node.inspectorPanels,
					styles: node.styles,
					variations: node.variations.slice( 0, 5 ),
					contentAttributes: Object.keys( node.contentAttributes ),
					configAttributes: Object.keys( node.configAttributes ),
					supportsSummary: Object.keys( node.inspectorPanels ),
				};
			}
			if ( node.innerBlocks.length ) {
				walk( node.innerBlocks );
			}
		}
	}
	walk( tree );
	return index;
}
