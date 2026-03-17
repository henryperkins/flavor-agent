/**
 * Template editor action utilities.
 *
 * Each function targets a specific block-editor UI element:
 *   - Template-part slugs / areas → selectBlock (highlights in canvas,
 *     shows settings in the block inspector).
 *   - Patterns → setIsInserterOpened (opens the Inserter on the
 *     Patterns tab, pre-filtered to the exact pattern so the user sees
 *     a live preview and can choose an insertion point).
 *   - Assign / Insert → direct block-tree mutations.
 */
import { parse } from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { dispatch, select } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';

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

function getBlocks() {
	return select( blockEditorStore ).getBlocks();
}

/**
 * Find a template-part block by area.
 * Prefers empty (unassigned) placeholders over assigned ones.
 */
export function findBlockByArea( area ) {
	const blocks = getBlocks();
	const empty = findTemplatePart(
		blocks,
		( b ) => b.attributes?.area === area && ! b.attributes?.slug
	);
	return (
		empty ||
		findTemplatePart( blocks, ( b ) => b.attributes?.area === area )
	);
}

/**
 * Find a template-part block by its assigned slug.
 */
export function findBlockBySlug( slug ) {
	return findTemplatePart(
		getBlocks(),
		( b ) => b.attributes?.slug === slug
	);
}

/* ------------------------------------------------------------------ */
/*  Navigation actions (non-destructive)                               */
/* ------------------------------------------------------------------ */

/**
 * Select and scroll-to a template-part block by area.
 * The block inspector will show the template-part controls
 * (slug assignment dropdown, "Edit" link, etc.).
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

/* ------------------------------------------------------------------ */
/*  Mutation actions                                                   */
/* ------------------------------------------------------------------ */

/**
 * Assign a template-part slug to an area's placeholder block,
 * then select the block so the user sees the result.
 */
export function assignTemplatePart( slug, area ) {
	const block = findBlockByArea( area );
	if ( ! block ) {
		return false;
	}
	dispatch( blockEditorStore ).updateBlockAttributes( block.clientId, {
		slug,
	} );
	dispatch( blockEditorStore ).selectBlock( block.clientId );
	return true;
}

/**
 * Get a registered pattern by name from block editor settings.
 */
export function getPatternByName( name ) {
	const settings = select( blockEditorStore ).getSettings();
	const patterns = settings?.__experimentalBlockPatterns || [];
	return patterns.find( ( p ) => p.name === name ) || null;
}

/**
 * Parse a pattern's content into blocks (for preview or insertion).
 */
export function parsePatternBlocks( name ) {
	const pattern = getPatternByName( name );
	if ( ! pattern?.content ) {
		return [];
	}
	return parse( pattern.content );
}

/**
 * Insert a pattern's parsed blocks into the editor root.
 */
export function insertPatternByName( name ) {
	const blocks = parsePatternBlocks( name );
	if ( blocks.length === 0 ) {
		return false;
	}
	dispatch( blockEditorStore ).insertBlocks( blocks );
	return true;
}

/**
 * Apply an entire template suggestion — assign all parts, insert all patterns.
 */
export function applySuggestion( suggestion ) {
	const results = { parts: [], patterns: [] };

	if ( suggestion.templateParts?.length > 0 ) {
		for ( const part of suggestion.templateParts ) {
			results.parts.push( {
				slug: part.slug,
				area: part.area,
				applied: assignTemplatePart( part.slug, part.area ),
			} );
		}
	}

	if ( suggestion.patternSuggestions?.length > 0 ) {
		for ( const name of suggestion.patternSuggestions ) {
			results.patterns.push( {
				name,
				inserted: insertPatternByName( name ),
			} );
		}
	}

	return results;
}
