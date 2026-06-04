import {
	findInserterContainer,
	findInserterSearchInput,
	findInserterToggle,
} from '../inserter-dom';

describe( 'inserter-dom', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	afterEach( () => {
		document.body.innerHTML = '';
	} );

	describe( 'findInserterContainer', () => {
		test( 'prefers the container that owns the active search input', () => {
			document.body.innerHTML = `
				<div id="empty-menu" class="block-editor-inserter__menu"></div>
				<div id="active-panel" class="block-editor-inserter__panel-content">
					<div class="block-editor-inserter__search">
						<input type="search" />
					</div>
				</div>
			`;

			expect( findInserterContainer( document )?.id ).toBe(
				'active-panel'
			);
		} );

		test( 'falls back to selector priority order when no search input exists', () => {
			document.body.innerHTML = `
				<div id="menu" class="block-editor-inserter__menu"></div>
				<div id="content" class="block-editor-inserter__content"></div>
				<div id="panel" class="block-editor-inserter__panel-content"></div>
			`;

			// First selector in INSERTER_CONTAINER_SELECTORS wins.
			expect( findInserterContainer( document )?.id ).toBe( 'panel' );
		} );

		test( 'returns null when nothing inserter-shaped is on the page', () => {
			document.body.innerHTML = `<div class="editor-header"></div>`;
			expect( findInserterContainer( document ) ).toBeNull();
		} );

		test( 'returns null for roots that lack querySelector', () => {
			expect( findInserterContainer( null ) ).toBeNull();
			expect( findInserterContainer( {} ) ).toBeNull();
		} );
	} );

	describe( 'findInserterSearchInput', () => {
		test( 'returns null when root cannot run querySelectorAll', () => {
			expect( findInserterSearchInput( null ) ).toBeNull();
			expect( findInserterSearchInput( {} ) ).toBeNull();
		} );

		test( 'finds inputs inside any inserter container by selector priority', () => {
			document.body.innerHTML = `
				<div class="block-editor-inserter__menu">
					<input id="menu-input" role="searchbox" />
				</div>
			`;
			expect( findInserterSearchInput( document )?.id ).toBe(
				'menu-input'
			);
		} );
	} );

	describe( 'findInserterToggle', () => {
		test( 'returns the canonical inserter toggle button when present', () => {
			document.body.innerHTML = `
				<button class="block-editor-inserter__toggle" aria-label="Toggle block inserter"></button>
			`;
			expect(
				findInserterToggle( document )?.classList.contains(
					'block-editor-inserter__toggle'
				)
			).toBe( true );
		} );

		test( 'falls back only to explicit block inserter labels', () => {
			document.body.innerHTML = `
				<div class="edit-site-header__start">
					<button id="undo" aria-label="Undo"></button>
					<button id="ins" aria-label="Toggle block inserter"></button>
				</div>
			`;

			expect( findInserterToggle( document )?.id ).toBe( 'ins' );
		} );

		test( 'rejects list view and document outline buttons near the inserter', () => {
			document.body.innerHTML = `
				<div class="edit-post-header-toolbar">
					<button id="outline" aria-label="Document overview"></button>
					<button id="list" aria-label="List View"></button>
					<button id="ins" class="block-editor-inserter__toggle" aria-label="Toggle block inserter"></button>
				</div>
			`;

			expect( findInserterToggle( document )?.id ).toBe( 'ins' );
		} );

		test( 'does not use generic inserter labels without block intent', () => {
			document.body.innerHTML = `
				<div class="edit-post-header-toolbar">
					<button id="generic" aria-label="Open inserter tools"></button>
				</div>
			`;

			expect( findInserterToggle( document ) ).toBeNull();
		} );

		test( 'ignores toolbar buttons whose aria-label does not match', () => {
			document.body.innerHTML = `
				<div class="edit-post-header-toolbar">
					<button aria-label="Undo"></button>
					<button aria-label="Redo"></button>
				</div>
			`;
			expect( findInserterToggle( document ) ).toBeNull();
		} );

		test( 'returns null for invalid roots', () => {
			expect( findInserterToggle( null ) ).toBeNull();
			expect( findInserterToggle( {} ) ).toBeNull();
		} );
	} );
} );
