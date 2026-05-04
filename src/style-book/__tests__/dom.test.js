import {
	findStyleBookIframe,
	findStylesSidebarMountNode,
	getSelectedStyleBookTarget,
	getStyleBookUiState,
	subscribeToStyleBookUi,
} from '../dom';

function setIframeMarkup( iframe, html ) {
	iframe.contentDocument.body.innerHTML = html;
}

describe( 'style-book/dom selectors', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	afterEach( () => {
		document.body.innerHTML = '';
	} );

	describe( 'findStylesSidebarMountNode', () => {
		test( 'prefers the panel selector', () => {
			document.body.innerHTML = `
				<div id="panel" class="editor-global-styles-sidebar__panel"></div>
				<div id="sidebar" class="editor-global-styles-sidebar"></div>
				<div role="region" aria-label="Styles" id="region"></div>
			`;
			expect( findStylesSidebarMountNode( document )?.id ).toBe(
				'panel'
			);
		} );

		test( 'falls back to the sidebar selector when the panel is missing', () => {
			document.body.innerHTML = `
				<div id="sidebar" class="editor-global-styles-sidebar"></div>
				<div role="region" aria-label="Styles" id="region"></div>
			`;
			expect( findStylesSidebarMountNode( document )?.id ).toBe(
				'sidebar'
			);
		} );

		test( 'falls back to the aria-labelled Styles region', () => {
			document.body.innerHTML = `
				<div role="region" aria-label="Styles" id="region"></div>
			`;
			expect( findStylesSidebarMountNode( document )?.id ).toBe(
				'region'
			);
		} );

		test( 'returns null when no candidate exists', () => {
			expect( findStylesSidebarMountNode( document ) ).toBeNull();
		} );

		test( 'returns null for invalid roots', () => {
			expect( findStylesSidebarMountNode( null ) ).toBeNull();
			expect( findStylesSidebarMountNode( {} ) ).toBeNull();
		} );
	} );

	describe( 'findStyleBookIframe', () => {
		test( 'finds the style-book iframe by class', () => {
			document.body.innerHTML = `
				<iframe id="other"></iframe>
				<iframe class="editor-style-book__iframe" id="sb"></iframe>
			`;
			expect( findStyleBookIframe( document )?.id ).toBe( 'sb' );
		} );

		test( 'returns null when no style-book iframe is present', () => {
			document.body.innerHTML = '<iframe id="other"></iframe>';
			expect( findStyleBookIframe( document ) ).toBeNull();
		} );
	} );

	describe( 'getSelectedStyleBookTarget', () => {
		test( 'returns null when no iframe is mounted', () => {
			expect( getSelectedStyleBookTarget( document ) ).toBeNull();
		} );

		test( 'returns the selected example block name and title', () => {
			document.body.innerHTML =
				'<iframe class="editor-style-book__iframe"></iframe>';
			const iframe = document.querySelector( 'iframe' );
			setIframeMarkup(
				iframe,
				`
					<div class="editor-style-book__example" id="example-core/paragraph"></div>
					<div class="editor-style-book__example is-selected" id="example-core/heading">
						<div class="editor-style-book__example-title">  Heading  </div>
					</div>
				`
			);

			expect( getSelectedStyleBookTarget( document ) ).toEqual( {
				blockName: 'core/heading',
				blockTitle: 'Heading',
			} );
		} );

		test( 'decodes percent-encoded block names', () => {
			document.body.innerHTML =
				'<iframe class="editor-style-book__iframe"></iframe>';
			const iframe = document.querySelector( 'iframe' );
			setIframeMarkup(
				iframe,
				`<div class="editor-style-book__example is-selected" id="example-core%2Fparagraph"></div>`
			);

			expect( getSelectedStyleBookTarget( document ) ).toEqual( {
				blockName: 'core/paragraph',
				blockTitle: '',
			} );
		} );

		test( 'returns null when the selected example id has no example- prefix', () => {
			document.body.innerHTML =
				'<iframe class="editor-style-book__iframe"></iframe>';
			const iframe = document.querySelector( 'iframe' );
			setIframeMarkup(
				iframe,
				`<div class="editor-style-book__example is-selected" id="not-prefixed"></div>`
			);

			expect( getSelectedStyleBookTarget( document ) ).toBeNull();
		} );

		test( 'returns null when no example is selected', () => {
			document.body.innerHTML =
				'<iframe class="editor-style-book__iframe"></iframe>';
			const iframe = document.querySelector( 'iframe' );
			setIframeMarkup(
				iframe,
				`<div class="editor-style-book__example" id="example-core/heading"></div>`
			);

			expect( getSelectedStyleBookTarget( document ) ).toBeNull();
		} );

		test( 'returns null when the example id has no block name after the prefix', () => {
			document.body.innerHTML =
				'<iframe class="editor-style-book__iframe"></iframe>';
			const iframe = document.querySelector( 'iframe' );
			setIframeMarkup(
				iframe,
				`<div class="editor-style-book__example is-selected" id="example-"></div>`
			);

			expect( getSelectedStyleBookTarget( document ) ).toBeNull();
		} );
	} );

	describe( 'getStyleBookUiState', () => {
		test( 'reports inactive state with no target when no iframe exists', () => {
			expect( getStyleBookUiState( document ) ).toEqual( {
				isActive: false,
				target: null,
			} );
		} );

		test( 'reports active state and resolved target when both exist', () => {
			document.body.innerHTML =
				'<iframe class="editor-style-book__iframe"></iframe>';
			const iframe = document.querySelector( 'iframe' );
			setIframeMarkup(
				iframe,
				`<div class="editor-style-book__example is-selected" id="example-core/heading">
					<div class="editor-style-book__example-title">Heading</div>
				</div>`
			);

			expect( getStyleBookUiState( document ) ).toEqual( {
				isActive: true,
				target: { blockName: 'core/heading', blockTitle: 'Heading' },
			} );
		} );
	} );
} );

describe( 'subscribeToStyleBookUi', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	afterEach( () => {
		document.body.innerHTML = '';
	} );

	test( 'returns a no-op unsubscriber for invalid input', () => {
		expect( typeof subscribeToStyleBookUi( null, () => {} ) ).toBe(
			'function'
		);
		expect(
			typeof subscribeToStyleBookUi( document, 'not-a-function' )
		).toBe( 'function' );
		// Calling the returned no-op unsubscribe should not throw.
		subscribeToStyleBookUi( null, () => {} )();
	} );

	test( 'pushes initial state to a new subscriber', () => {
		const callback = jest.fn();
		const unsubscribe = subscribeToStyleBookUi( document, callback );

		expect( callback ).toHaveBeenCalledTimes( 1 );
		expect( callback ).toHaveBeenCalledWith( {
			isActive: false,
			target: null,
		} );

		unsubscribe();
	} );

	test( 'shares observers across multiple subscribers and tears them down on last unsubscribe', () => {
		const a = jest.fn();
		const b = jest.fn();

		const unsubA = subscribeToStyleBookUi( document, a );
		const unsubB = subscribeToStyleBookUi( document, b );

		// Each subscriber received its initial state push exactly once.
		expect( a ).toHaveBeenCalledTimes( 1 );
		expect( b ).toHaveBeenCalledTimes( 1 );

		// Unsubscribing one should not throw or affect the other; the second
		// unsubscribe should reset shared state cleanly.
		expect( () => {
			unsubA();
			unsubB();
		} ).not.toThrow();

		// After full teardown, a fresh subscription should still bootstrap
		// (proves shared state was reset rather than left in a half-detached state).
		const c = jest.fn();
		const unsubC = subscribeToStyleBookUi( document, c );
		expect( c ).toHaveBeenCalledTimes( 1 );
		unsubC();
	} );

	test( 'a subscriber that unsubscribes during its callback does not break the iteration', () => {
		const a = jest.fn();
		let unsubB;
		const b = jest.fn( () => {
			if ( unsubB ) {
				unsubB();
			}
		} );

		const unsubA = subscribeToStyleBookUi( document, a );
		unsubB = subscribeToStyleBookUi( document, b );

		// Initial push handled both callbacks without throwing.
		expect( a ).toHaveBeenCalledTimes( 1 );
		expect( b ).toHaveBeenCalledTimes( 1 );

		unsubA();
	} );
} );
