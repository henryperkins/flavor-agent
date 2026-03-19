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
import { store as blockEditorStore } from '@wordpress/block-editor';
import { dispatch, select } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { matchesTemplatePartArea } from './template-part-areas';

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
