jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );

const mockRegistrySelect = jest.fn();
const mockRegistryDispatch = jest.fn();

jest.mock( '@wordpress/data', () => ( {
	select: ( ...args ) => mockRegistrySelect( ...args ),
	dispatch: ( ...args ) => mockRegistryDispatch( ...args ),
} ) );

import {
	getBlockPatterns,
	setBlockPatterns,
	getBlockPatternCategories,
	getAllowedPatterns,
	getPatternAPIPath,
	getPatternRuntimeDiagnostics,
	findInserterSearchInput,
	findInserterToggle,
	INSERTER_CONTAINER_SELECTORS,
	INSERTER_SEARCH_SELECTORS,
	INSERTER_TOGGLE_SELECTOR,
	INSERTER_TOGGLE_TOOLBAR_SELECTORS,
} from '../compat';

/* ------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------- */

function mockBlockEditorStore( settings = {}, selectors = {} ) {
	const getSettings = jest.fn().mockReturnValue( settings );
	const blockEditor = { getSettings, ...selectors };

	mockRegistrySelect.mockReturnValue( blockEditor );

	return blockEditor;
}

function mockUpdateSettings() {
	const updateSettings = jest.fn();

	mockRegistryDispatch.mockReturnValue( { updateSettings } );

	return updateSettings;
}

/* ------------------------------------------------------------------
 * Pattern data adapter tests
 * ---------------------------------------------------------------- */

describe( 'getBlockPatterns', () => {
	beforeEach( () => mockRegistrySelect.mockReset() );

	test( 'returns stable blockPatterns when available', () => {
		const stablePatterns = [ { name: 'theme/hero' } ];

		mockBlockEditorStore( {
			blockPatterns: stablePatterns,
			__experimentalBlockPatterns: [ { name: 'stale/pattern' } ],
		} );

		expect( getBlockPatterns() ).toBe( stablePatterns );
	} );

	test( 'falls back to __experimentalBlockPatterns when stable key is absent', () => {
		const experimentalPatterns = [ { name: 'theme/cta' } ];

		mockBlockEditorStore( {
			__experimentalBlockPatterns: experimentalPatterns,
		} );

		expect( getBlockPatterns() ).toBe( experimentalPatterns );
	} );

	test( 'prefers __experimentalAdditionalBlockPatterns over legacy experimental patterns', () => {
		const additionalPatterns = [ { name: 'theme/additional-hero' } ];

		mockBlockEditorStore( {
			__experimentalAdditionalBlockPatterns: additionalPatterns,
			__experimentalBlockPatterns: [ { name: 'theme/stale-pattern' } ],
		} );

		expect( getBlockPatterns() ).toBe( additionalPatterns );
	} );

	test( 'returns empty array when neither key is present', () => {
		mockBlockEditorStore( {} );

		expect( getBlockPatterns() ).toEqual( [] );
	} );

	test( 'returns empty array when getSettings is unavailable', () => {
		mockRegistrySelect.mockReturnValue( {} );

		expect( getBlockPatterns() ).toEqual( [] );
	} );
} );

describe( 'setBlockPatterns', () => {
	beforeEach( () => {
		mockRegistrySelect.mockReset();
		mockRegistryDispatch.mockReset();
	} );

	test( 'writes to stable key when it is populated', () => {
		mockBlockEditorStore( {
			blockPatterns: [ { name: 'theme/old' } ],
			__experimentalBlockPatterns: [],
		} );
		const updateSettings = mockUpdateSettings();

		const newPatterns = [ { name: 'theme/new' } ];
		setBlockPatterns( newPatterns );

		expect( updateSettings ).toHaveBeenCalledWith( {
			blockPatterns: newPatterns,
		} );
	} );

	test( 'writes to experimental key when stable key is absent', () => {
		mockBlockEditorStore( {
			__experimentalBlockPatterns: [ { name: 'theme/old' } ],
		} );
		const updateSettings = mockUpdateSettings();

		const newPatterns = [ { name: 'theme/new' } ];
		setBlockPatterns( newPatterns );

		expect( updateSettings ).toHaveBeenCalledWith( {
			__experimentalBlockPatterns: newPatterns,
		} );
	} );

	test( 'writes to __experimentalAdditionalBlockPatterns when that key is populated', () => {
		mockBlockEditorStore( {
			__experimentalAdditionalBlockPatterns: [ { name: 'theme/old' } ],
			__experimentalBlockPatterns: [ { name: 'theme/stale' } ],
		} );
		const updateSettings = mockUpdateSettings();

		const newPatterns = [ { name: 'theme/new' } ];
		setBlockPatterns( newPatterns );

		expect( updateSettings ).toHaveBeenCalledWith( {
			__experimentalAdditionalBlockPatterns: newPatterns,
		} );
	} );

	test( 'defaults to experimental key when neither key exists', () => {
		mockBlockEditorStore( {} );
		const updateSettings = mockUpdateSettings();

		setBlockPatterns( [ { name: 'theme/first' } ] );

		expect( updateSettings ).toHaveBeenCalledWith(
			expect.objectContaining( {
				__experimentalBlockPatterns: [ { name: 'theme/first' } ],
			} )
		);
	} );
} );

describe( 'getBlockPatternCategories', () => {
	beforeEach( () => mockRegistrySelect.mockReset() );

	test( 'returns stable blockPatternCategories when available', () => {
		const cats = [ { name: 'featured', label: 'Featured' } ];

		mockBlockEditorStore( {
			blockPatternCategories: cats,
			__experimentalBlockPatternCategories: [],
		} );

		expect( getBlockPatternCategories() ).toBe( cats );
	} );

	test( 'falls back to __experimentalBlockPatternCategories', () => {
		const cats = [ { name: 'media', label: 'Media' } ];

		mockBlockEditorStore( {
			__experimentalBlockPatternCategories: cats,
		} );

		expect( getBlockPatternCategories() ).toBe( cats );
	} );

	test( 'prefers __experimentalAdditionalBlockPatternCategories over legacy experimental categories', () => {
		const cats = [ { name: 'featured', label: 'Featured' } ];

		mockBlockEditorStore( {
			__experimentalAdditionalBlockPatternCategories: cats,
			__experimentalBlockPatternCategories: [
				{ name: 'stale', label: 'Stale' },
			],
		} );

		expect( getBlockPatternCategories() ).toBe( cats );
	} );

	test( 'returns empty array when no categories exist', () => {
		mockBlockEditorStore( {} );

		expect( getBlockPatternCategories() ).toEqual( [] );
	} );
} );

describe( 'getAllowedPatterns', () => {
	beforeEach( () => mockRegistrySelect.mockReset() );

	test( 'uses stable getAllowedPatterns selector when available', () => {
		const allowed = [ { name: 'theme/hero' }, { name: 'theme/cta' } ];
		const stableSelector = jest.fn().mockReturnValue( allowed );

		mockBlockEditorStore(
			{},
			{
				getAllowedPatterns: stableSelector,
				__experimentalGetAllowedPatterns: jest
					.fn()
					.mockReturnValue( [] ),
			}
		);

		expect( getAllowedPatterns( 'root-1' ) ).toBe( allowed );
		expect( stableSelector ).toHaveBeenCalledWith( 'root-1' );
	} );

	test( 'falls back to __experimentalGetAllowedPatterns', () => {
		const allowed = [ { name: 'theme/gallery' } ];
		const experimentalSelector = jest.fn().mockReturnValue( allowed );

		mockBlockEditorStore(
			{},
			{ __experimentalGetAllowedPatterns: experimentalSelector }
		);

		expect( getAllowedPatterns( null ) ).toBe( allowed );
		expect( experimentalSelector ).toHaveBeenCalledWith( null );
	} );

	test( 'fails closed when no contextual allowed-pattern selector exists', () => {
		mockBlockEditorStore( {
			__experimentalBlockPatterns: [ { name: 'theme/fallback' } ],
		} );

		expect( getAllowedPatterns() ).toEqual( [] );
	} );

	test( 'returns empty array when everything is missing', () => {
		mockBlockEditorStore( {} );

		expect( getAllowedPatterns() ).toEqual( [] );
	} );
} );

describe( 'getPatternAPIPath', () => {
	beforeEach( () => mockRegistrySelect.mockReset() );

	test( 'returns "stable" when blockPatterns key exists', () => {
		mockBlockEditorStore( { blockPatterns: [] } );

		expect( getPatternAPIPath() ).toBe( 'stable' );
	} );

	test( 'returns "experimental" when only experimental key exists', () => {
		mockBlockEditorStore( { __experimentalBlockPatterns: [] } );

		expect( getPatternAPIPath() ).toBe( 'experimental' );
	} );

	test( 'returns "experimental" when additional experimental key exists', () => {
		mockBlockEditorStore( {
			__experimentalAdditionalBlockPatterns: [],
		} );

		expect( getPatternAPIPath() ).toBe( 'experimental' );
	} );

	test( 'returns "none" when no pattern key exists', () => {
		mockBlockEditorStore( {} );

		expect( getPatternAPIPath() ).toBe( 'none' );
	} );
} );

describe( 'getPatternRuntimeDiagnostics', () => {
	beforeEach( () => mockRegistrySelect.mockReset() );

	test( 'reports missing contextual selector support explicitly when selectors are unavailable', () => {
		mockBlockEditorStore( {
			__experimentalBlockPatterns: [ { name: 'theme/fallback' } ],
		} );

		expect( getPatternRuntimeDiagnostics() ).toEqual( {
			patternsPath: 'experimental',
			categoriesPath: 'none',
			allowedPatternsPath: 'missing-selector',
			allowedPatternsFallbackMode: 'none',
		} );
	} );

	test( 'reports contextual selector usage when the experimental selector is active', () => {
		mockBlockEditorStore(
			{
				__experimentalBlockPatterns: [ { name: 'theme/fallback' } ],
			},
			{
				__experimentalGetAllowedPatterns: jest
					.fn()
					.mockReturnValue( [ { name: 'theme/scoped' } ] ),
			}
		);

		expect( getPatternRuntimeDiagnostics( 'root-1' ) ).toEqual( {
			patternsPath: 'experimental',
			categoriesPath: 'none',
			allowedPatternsPath: 'experimental-selector',
			allowedPatternsFallbackMode: 'contextual',
		} );
	} );
} );

/* ------------------------------------------------------------------
 * Inserter DOM helper tests
 * ---------------------------------------------------------------- */

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

	test( 'ignores global searchbox roles outside inserter containers', () => {
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

	test( 'returns null when root is null or has no querySelectorAll', () => {
		expect( findInserterSearchInput( null ) ).toBeNull();
		expect( findInserterSearchInput( {} ) ).toBeNull();
	} );

	test( 'finds input inside the tabbed sidebar variant', () => {
		document.body.innerHTML = `
			<div class="block-editor-tabbed-sidebar">
				<input id="tabbed-search" type="search" />
			</div>
		`;

		expect( findInserterSearchInput( document )?.id ).toBe(
			'tabbed-search'
		);
	} );
} );

describe( 'findInserterToggle', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	afterEach( () => {
		document.body.innerHTML = '';
	} );

	test( 'finds the inserter toggle by primary class selector', () => {
		document.body.innerHTML = `
			<div class="edit-post-header-toolbar">
				<button class="block-editor-inserter__toggle" aria-label="Toggle block inserter">+</button>
			</div>
		`;

		const toggle = findInserterToggle();

		expect( toggle ).not.toBeNull();
		expect( toggle.textContent ).toBe( '+' );
	} );

	test( 'falls back to aria-label scanning when class selector misses', () => {
		document.body.innerHTML = `
			<div class="edit-post-header-toolbar">
				<button aria-label="Open inserter panel">+</button>
				<button aria-label="Undo">u</button>
			</div>
		`;

		const toggle = findInserterToggle();

		expect( toggle ).not.toBeNull();
		expect( toggle.getAttribute( 'aria-label' ) ).toBe(
			'Open inserter panel'
		);
	} );

	test( 'finds toggle in site editor toolbar', () => {
		document.body.innerHTML = `
			<div class="edit-site-header__start">
				<button aria-label="Block Inserter">+</button>
			</div>
		`;

		const toggle = findInserterToggle();

		expect( toggle ).not.toBeNull();
	} );

	test( 'returns null when no matching button exists', () => {
		document.body.innerHTML = `
			<div class="edit-post-header-toolbar">
				<button aria-label="Undo">u</button>
				<button aria-label="Redo">r</button>
			</div>
		`;

		expect( findInserterToggle() ).toBeNull();
	} );

	test( 'returns null when the page has no toolbar at all', () => {
		document.body.innerHTML = '<div></div>';

		expect( findInserterToggle() ).toBeNull();
	} );

	test( 'returns null when the provided root cannot query the DOM', () => {
		expect( findInserterToggle( null ) ).toBeNull();
		expect( findInserterToggle( {} ) ).toBeNull();
	} );
} );

/* ------------------------------------------------------------------
 * Selector constant sanity checks
 * ---------------------------------------------------------------- */

describe( 'exported selector constants', () => {
	test( 'container selectors include the expected modern and legacy entries', () => {
		expect( INSERTER_CONTAINER_SELECTORS ).toContain(
			'.block-editor-inserter__panel-content'
		);
		expect( INSERTER_CONTAINER_SELECTORS ).toContain(
			'.block-editor-inserter__menu'
		);
		expect( INSERTER_CONTAINER_SELECTORS.length ).toBeGreaterThanOrEqual(
			3
		);
	} );

	test( 'search selectors include type="search" and role="searchbox"', () => {
		expect(
			INSERTER_SEARCH_SELECTORS.some( ( s ) => s.includes( 'search' ) )
		).toBe( true );
		expect(
			INSERTER_SEARCH_SELECTORS.some( ( s ) => s.includes( 'searchbox' ) )
		).toBe( true );
	} );

	test( 'toggle selector targets the known inserter toggle class', () => {
		expect( INSERTER_TOGGLE_SELECTOR ).toContain(
			'block-editor-inserter__toggle'
		);
	} );

	test( 'toolbar selectors cover both post and site editor', () => {
		expect( INSERTER_TOGGLE_TOOLBAR_SELECTORS ).toContain(
			'.edit-post-header-toolbar'
		);
		expect( INSERTER_TOGGLE_TOOLBAR_SELECTORS ).toContain(
			'.edit-site-header__start'
		);
	} );
} );
