/**
 * Inserter DOM selectors are centralized here so caller code can treat
 * missing editor markup as a normal degraded path.
 */

export const INSERTER_CONTAINER_SELECTORS = [
	'.block-editor-inserter__panel-content',
	'.block-editor-inserter__content',
	'.editor-inserter-sidebar__content',
	'.block-editor-tabbed-sidebar',
	'.block-editor-inserter__menu',
];

export const INSERTER_SEARCH_SELECTORS = [
	'.block-editor-inserter__search input[type="search"]',
	'.block-editor-inserter__search input',
	'input[type="search"]',
	'[role="searchbox"]',
];

export const INSERTER_TOGGLE_SELECTOR = 'button.block-editor-inserter__toggle';

export const INSERTER_TOGGLE_TOOLBAR_SELECTORS = [
	'.edit-post-header-toolbar',
	'.edit-site-header__start',
];

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

export function findInserterToggle( root = document ) {
	if ( ! root?.querySelector || ! root?.querySelectorAll ) {
		return null;
	}

	const primary = root.querySelector( INSERTER_TOGGLE_SELECTOR );

	if ( primary ) {
		return primary;
	}

	const allButtons = root.querySelectorAll(
		INSERTER_TOGGLE_TOOLBAR_SELECTORS.map(
			( selector ) => `${ selector } button`
		).join( ', ' )
	);

	for ( const button of allButtons ) {
		const label = button.getAttribute( 'aria-label' ) || '';

		if ( /inserter/i.test( label ) ) {
			return button;
		}
	}

	return null;
}
