const INSERTER_CONTAINER_SELECTORS = [
	'.block-editor-inserter__panel-content',
	'.block-editor-inserter__content',
	'.editor-inserter-sidebar__content',
	'.block-editor-tabbed-sidebar',
	'.block-editor-inserter__menu',
];

const INSERTER_SEARCH_SELECTORS = [
	'.block-editor-inserter__search input[type="search"]',
	'.block-editor-inserter__search input',
	'input[type="search"]',
	'[role="searchbox"]',
];

function findSearchInputWithinContainer( container ) {
	for ( const selector of INSERTER_SEARCH_SELECTORS ) {
		const input = container.querySelector( selector );

		if ( input ) {
			return input;
		}
	}

	return null;
}

export function findInserterSearchInput( root ) {
	if ( ! root?.querySelectorAll ) {
		return null;
	}

	for ( const containerSelector of INSERTER_CONTAINER_SELECTORS ) {
		const containers = root.querySelectorAll( containerSelector );

		for ( const container of containers ) {
			const input = findSearchInputWithinContainer( container );

			if ( input ) {
				return input;
			}
		}
	}

	return null;
}
