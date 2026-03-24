/**
 * Pattern Compatibility Adapter
 *
 * Single entry point for reading and writing block pattern data and
 * categories from WordPress block editor settings.  Every pattern
 * surface in the plugin imports from this module instead of touching
 * experimental settings keys or DOM selectors directly.
 *
 * API preference order for each operation:
 *   1. Stable key / selector (when WordPress core promotes it).
 *   2. Experimental key / selector (current Gutenberg convention).
 *   3. No-op / empty fallback (graceful degradation).
 *
 * Migration note
 * ──────────────
 * When stable `blockPatterns`, `getAllowedPatterns`, and
 * `blockPatternCategories` settings keys land in WordPress core:
 *   1. Update the STABLE_* constants below.
 *   2. Remove experimental fallbacks once the minimum supported WP
 *      version ships the stable keys.
 *   3. Run `npm run test:unit` — the compat tests should catch any
 *      regressions from removing a fallback path.
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import {
	select as registrySelect,
	dispatch as registryDispatch,
} from '@wordpress/data';

/* ------------------------------------------------------------------
 * Settings keys
 * ---------------------------------------------------------------- */

/** Stable settings key for block patterns (not yet shipped in core). */
const STABLE_PATTERNS_KEY = 'blockPatterns';

/** Current experimental settings key for block patterns. */
const EXPERIMENTAL_PATTERNS_KEY = '__experimentalBlockPatterns';

/** Current experimental override key for merged/extended block patterns. */
const EXPERIMENTAL_ADDITIONAL_PATTERNS_KEY =
	'__experimentalAdditionalBlockPatterns';

/** Stable settings key for pattern categories (not yet shipped). */
const STABLE_CATEGORIES_KEY = 'blockPatternCategories';

/** Current experimental settings key for pattern categories. */
const EXPERIMENTAL_CATEGORIES_KEY = '__experimentalBlockPatternCategories';

/** Current experimental override key for merged/extended pattern categories. */
const EXPERIMENTAL_ADDITIONAL_CATEGORIES_KEY =
	'__experimentalAdditionalBlockPatternCategories';

/* ------------------------------------------------------------------
 * Selector names
 * ---------------------------------------------------------------- */

/** Stable selector name for context-aware allowed patterns. */
const STABLE_ALLOWED_SELECTOR = 'getAllowedPatterns';

/** Current experimental selector for allowed patterns. */
const EXPERIMENTAL_ALLOWED_SELECTOR = '__experimentalGetAllowedPatterns';

/* ------------------------------------------------------------------
 * Inserter DOM selectors
 *
 * Version notes:
 * - .block-editor-inserter__panel-content  — Modern inserter (WP 6.3+)
 * - .block-editor-inserter__content        — Alternate content wrapper
 * - .editor-inserter-sidebar__content      — Sidebar inserter (post editor)
 * - .block-editor-tabbed-sidebar           — Tabbed sidebar variant (WP 6.6+)
 * - .block-editor-inserter__menu           — Legacy inserter menu (WP 5.x–6.2)
 *
 * - .block-editor-inserter__toggle         — Primary inserter toggle button
 * - .edit-post-header-toolbar              — Post editor toolbar (WP 6.x+)
 * - .edit-site-header__start               — Site editor header (WP 6.x+)
 * ---------------------------------------------------------------- */

/**
 * @type {string[]} Inserter container selectors in priority order.
 */
export const INSERTER_CONTAINER_SELECTORS = [
	'.block-editor-inserter__panel-content',
	'.block-editor-inserter__content',
	'.editor-inserter-sidebar__content',
	'.block-editor-tabbed-sidebar',
	'.block-editor-inserter__menu',
];

/**
 * @type {string[]} Search input selectors tried within each container.
 */
export const INSERTER_SEARCH_SELECTORS = [
	'.block-editor-inserter__search input[type="search"]',
	'.block-editor-inserter__search input',
	'input[type="search"]',
	'[role="searchbox"]',
];

/**
 * @type {string} Primary class selector for the inserter toggle button.
 */
export const INSERTER_TOGGLE_SELECTOR = 'button.block-editor-inserter__toggle';

/**
 * @type {string[]} Toolbar container selectors for aria-label fallback.
 */
export const INSERTER_TOGGLE_TOOLBAR_SELECTORS = [
	'.edit-post-header-toolbar',
	'.edit-site-header__start',
];

/* ------------------------------------------------------------------
 * Pattern data helpers
 * ---------------------------------------------------------------- */

/**
 * Read block editor settings once.
 *
 * @return {Object} Current block editor settings.
 */
function getSettings() {
	return registrySelect( blockEditorStore ).getSettings?.() || {};
}

/**
 * Resolve the settings key currently populated for block patterns.
 *
 * @param {Object} settings Block editor settings object.
 * @return {string} The key holding the patterns array.
 */
function resolvePatternsKey( settings ) {
	if ( Array.isArray( settings[ STABLE_PATTERNS_KEY ] ) ) {
		return STABLE_PATTERNS_KEY;
	}

	if ( Array.isArray( settings[ EXPERIMENTAL_ADDITIONAL_PATTERNS_KEY ] ) ) {
		return EXPERIMENTAL_ADDITIONAL_PATTERNS_KEY;
	}

	return EXPERIMENTAL_PATTERNS_KEY;
}

/**
 * Resolve the settings key currently populated for pattern categories.
 *
 * @param {Object} settings Block editor settings object.
 * @return {string} The key holding the categories array.
 */
function resolveCategoriesKey( settings ) {
	if ( Array.isArray( settings[ STABLE_CATEGORIES_KEY ] ) ) {
		return STABLE_CATEGORIES_KEY;
	}

	if ( Array.isArray( settings[ EXPERIMENTAL_ADDITIONAL_CATEGORIES_KEY ] ) ) {
		return EXPERIMENTAL_ADDITIONAL_CATEGORIES_KEY;
	}

	return EXPERIMENTAL_CATEGORIES_KEY;
}

/**
 * Read the current block patterns array from editor settings.
 * Prefers stable API, falls back to experimental.
 *
 * @return {Array} Registered block patterns (may be empty).
 */
export function getBlockPatterns() {
	const settings = getSettings();
	const key = resolvePatternsKey( settings );
	const patterns = settings[ key ];

	return Array.isArray( patterns ) ? patterns : [];
}

/**
 * Write an updated block patterns array to editor settings.
 * Writes to whichever key is currently populated; defaults to
 * experimental if neither key is present yet.
 *
 * @param {Array} patterns Updated patterns array.
 */
export function setBlockPatterns( patterns ) {
	const settings = getSettings();
	const key = resolvePatternsKey( settings );

	registryDispatch( blockEditorStore ).updateSettings( {
		[ key ]: patterns,
	} );
}

/**
 * Read pattern categories from editor settings.
 * Prefers stable API, falls back to experimental.
 *
 * @return {Array} Pattern categories (may be empty).
 */
export function getBlockPatternCategories() {
	const settings = getSettings();
	const key = resolveCategoriesKey( settings );
	const categories = settings[ key ];

	return Array.isArray( categories ) ? categories : [];
}

/**
 * Get context-aware allowed patterns for a given insertion root.
 * Prefers stable selector, falls back to experimental, then to the
 * full settings read as a last resort.
 *
 * @param {?string} rootClientId  Inserter root client ID.
 * @param {Object}  [blockEditor] Optional block-editor selector object.
 * @return {Array} Allowed patterns for the context.
 */
export function getAllowedPatterns(
	rootClientId = null,
	blockEditor = registrySelect( blockEditorStore )
) {
	if ( typeof blockEditor[ STABLE_ALLOWED_SELECTOR ] === 'function' ) {
		return blockEditor[ STABLE_ALLOWED_SELECTOR ]( rootClientId ) || [];
	}

	if ( typeof blockEditor[ EXPERIMENTAL_ALLOWED_SELECTOR ] === 'function' ) {
		return (
			blockEditor[ EXPERIMENTAL_ALLOWED_SELECTOR ]( rootClientId ) || []
		);
	}

	return getBlockPatterns();
}

/**
 * Identify which API path is currently in use for block patterns.
 * Useful for diagnostics, tests, and migration tracking.
 *
 * @return {'stable'|'experimental'|'none'} API path in use.
 */
export function getPatternAPIPath() {
	const settings = getSettings();

	if ( Array.isArray( settings[ STABLE_PATTERNS_KEY ] ) ) {
		return 'stable';
	}

	if (
		Array.isArray( settings[ EXPERIMENTAL_ADDITIONAL_PATTERNS_KEY ] ) ||
		Array.isArray( settings[ EXPERIMENTAL_PATTERNS_KEY ] )
	) {
		return 'experimental';
	}

	return 'none';
}

/* ------------------------------------------------------------------
 * Inserter DOM helpers
 * ---------------------------------------------------------------- */

/**
 * Find the inserter search input within a DOM root.
 *
 * Uses a two-level selector strategy: first finds inserter containers
 * (in priority order), then searches for input elements within each.
 * This ensures only inserter-scoped inputs are returned, never
 * unrelated page-level search fields.
 *
 * @param {Document|Element} root DOM root to search within.
 * @return {HTMLInputElement|null} The inserter search input, or null.
 */
export function findInserterSearchInput( root ) {
	if ( ! root?.querySelectorAll ) {
		return null;
	}

	for ( const containerSelector of INSERTER_CONTAINER_SELECTORS ) {
		const containers = root.querySelectorAll( containerSelector );

		for ( const container of containers ) {
			for ( const searchSelector of INSERTER_SEARCH_SELECTORS ) {
				const input = container.querySelector( searchSelector );

				if ( input ) {
					return input;
				}
			}
		}
	}

	return null;
}

/**
 * Find the inserter toggle button in the editor toolbar.
 *
 * Primary: stable `.block-editor-inserter__toggle` class selector.
 * Fallback: scan toolbar buttons by `aria-label` containing "inserter"
 * (covers renamed/restructured toolbars across editor contexts).
 *
 * @return {HTMLButtonElement|null} The toggle button, or null.
 */
export function findInserterToggle() {
	const primary = document.querySelector( INSERTER_TOGGLE_SELECTOR );

	if ( primary ) {
		return primary;
	}

	const allButtons = document.querySelectorAll(
		INSERTER_TOGGLE_TOOLBAR_SELECTORS.map( ( s ) => `${ s } button` ).join(
			', '
		)
	);

	for ( const button of allButtons ) {
		const label = button.getAttribute( 'aria-label' ) || '';

		if ( /inserter/i.test( label ) ) {
			return button;
		}
	}

	return null;
}
