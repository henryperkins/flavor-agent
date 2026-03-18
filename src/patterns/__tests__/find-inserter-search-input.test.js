import { findInserterSearchInput } from '../find-inserter-search-input';

describe( 'findInserterSearchInput', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	afterEach( () => {
		document.body.innerHTML = '';
	} );

	test( 'prefers the inserter search input over unrelated page search fields', () => {
		document.body.innerHTML = `
			<input id="page-search" type="search" />
			<div class="block-editor-inserter__panel-content">
				<div class="block-editor-inserter__search">
					<input id="inserter-search" type="search" />
				</div>
			</div>
		`;

		expect( findInserterSearchInput( document )?.id ).toBe(
			'inserter-search'
		);
	} );

	test( 'ignores global searchbox roles that are outside inserter containers', () => {
		document.body.innerHTML = `
			<input id="global-searchbox" role="searchbox" />
		`;

		expect( findInserterSearchInput( document ) ).toBeNull();
	} );

	test( 'returns null when no inserter container is present', () => {
		document.body.innerHTML = `
			<div class="editor-header">
				<input id="page-search" type="search" />
			</div>
		`;

		expect( findInserterSearchInput( document ) ).toBeNull();
	} );

	test( 'uses inserter container priority order instead of document order', () => {
		document.body.innerHTML = `
			<div class="block-editor-inserter__menu">
				<input id="menu-search" type="search" />
			</div>
			<div class="block-editor-inserter__content">
				<input id="content-search" role="searchbox" />
			</div>
		`;

		expect( findInserterSearchInput( document )?.id ).toBe(
			'content-search'
		);
	} );
} );
