const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockFetchPatternRecommendations = jest.fn();
const mockGetBlockAttributes = jest.fn();
const mockGetBlockPatternCategories = jest.fn();
const mockGetBlockPatterns = jest.fn();
const mockSetBlockPatternCategories = jest.fn();
const mockSetBlockPatterns = jest.fn();
const mockFindInserterContainer = jest.fn();
const mockFindInserterSearchInput = jest.fn();
const mockGetVisiblePatternNames = jest.fn();

jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );

jest.mock( '@wordpress/data', () => ( {
	useDispatch: ( ...args ) => mockUseDispatch( ...args ),
	useSelect: ( ...args ) => mockUseSelect( ...args ),
} ) );

jest.mock( '@wordpress/editor', () => ( {
	store: 'core/editor',
} ) );

jest.mock( '../pattern-settings', () => ( {
	getBlockPatternCategories: ( ...args ) =>
		mockGetBlockPatternCategories( ...args ),
	getBlockPatterns: ( ...args ) => mockGetBlockPatterns( ...args ),
	setBlockPatternCategories: ( ...args ) =>
		mockSetBlockPatternCategories( ...args ),
	setBlockPatterns: ( ...args ) => mockSetBlockPatterns( ...args ),
} ) );

jest.mock( '../inserter-dom', () => ( {
	findInserterContainer: ( ...args ) => mockFindInserterContainer( ...args ),
	findInserterSearchInput: ( ...args ) =>
		mockFindInserterSearchInput( ...args ),
} ) );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

jest.mock( '../../utils/visible-patterns', () => ( {
	getVisiblePatternNames: ( ...args ) =>
		mockGetVisiblePatternNames( ...args ),
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createRoot } = require( '@wordpress/element' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import PatternRecommender from '../PatternRecommender';

const { getRoot } = setupReactTest();

let state = null;
let originalMutationObserver = null;

function createSelectMap() {
	return {
		'core/editor': {
			getCurrentPostType: jest.fn( () => state.postType ),
			isInserterOpened: jest.fn( () => state.isInserterOpen ),
		},
		'core/edit-site': {
			getEditedPostType: jest.fn( () => state.editSite.postType ),
			getEditedPostId: jest.fn( () => state.editSite.postId ),
		},
		'core/block-editor': {
			getSettings: jest.fn( () => state.blockEditor.settings || {} ),
			getSelectedBlockClientId: jest.fn(
				() => state.blockEditor.selectedBlockClientId
			),
			getBlockName: jest.fn( ( clientId ) => {
				if ( clientId === state.blockEditor.selectedBlockClientId ) {
					return state.blockEditor.selectedBlockName;
				}
				return (
					( state.blockEditor.blockNames || {} )[ clientId ] ?? null
				);
			} ),
			getBlockInsertionPoint: jest.fn(
				() => state.blockEditor.insertionPoint
			),
			getBlockOrder: jest.fn(
				( rootClientId ) =>
					( state.blockEditor.blockOrder || {} )[ rootClientId ] ?? []
			),
			getBlockRootClientId: jest.fn(
				( clientId ) =>
					( state.blockEditor.blockRoots || {} )[ clientId ] ?? null
			),
			getBlockAttributes: mockGetBlockAttributes,
		},
		'flavor-agent': {
			getPatternStatus: jest.fn( () => state.store.patternStatus ),
			getPatternRecommendations: jest.fn(
				() => state.store.patternRecommendations
			),
		},
	};
}

function renderComponent() {
	act( () => {
		getRoot().render( <PatternRecommender /> );
	} );
}

describe( 'PatternRecommender', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		state = {
			postType: 'page',
			isInserterOpen: true,
			visiblePatternNames: [ 'theme/hero' ],
			editSite: {
				postType: 'page',
				postId: null,
			},
			blockEditor: {
				settings: {},
				runtimeCategories: [],
				selectedBlockClientId: null,
				selectedBlockName: null,
				insertionPoint: {
					rootClientId: 'root-a',
				},
				blockNames: { 'root-a': 'core/group' },
				blockOrder: { 'root-a': [] },
				blockRoots: { 'root-a': null },
				blockAttributes: {},
			},
			store: {
				patternStatus: 'idle',
				patternRecommendations: [],
			},
		};
		mockUseDispatch.mockReset();
		mockUseSelect.mockReset();
		mockFetchPatternRecommendations.mockReset();
		mockGetBlockAttributes.mockReset();
		mockGetBlockPatternCategories.mockReset();
		mockGetBlockPatterns.mockReset();
		mockSetBlockPatternCategories.mockReset();
		mockSetBlockPatterns.mockReset();
		mockFindInserterContainer.mockReset();
		mockFindInserterSearchInput.mockReset();
		mockGetVisiblePatternNames.mockReset();
		mockUseDispatch.mockReturnValue( {
			fetchPatternRecommendations: mockFetchPatternRecommendations,
		} );
		mockUseSelect.mockImplementation( ( callback ) =>
			callback( ( storeName ) => createSelectMap()[ storeName ] )
		);
		mockGetBlockPatterns.mockImplementation(
			() => state.blockEditor.runtimePatterns || []
		);
		mockGetBlockAttributes.mockImplementation(
			( clientId ) =>
				( state.blockEditor.blockAttributes || {} )[ clientId ] || {}
		);
		mockGetBlockPatternCategories.mockImplementation(
			() => state.blockEditor.runtimeCategories || []
		);
		mockGetVisiblePatternNames.mockImplementation(
			() => state.visiblePatternNames
		);
		window.flavorAgentData = { canRecommendPatterns: true };
		originalMutationObserver = window.MutationObserver;
	} );

	afterEach( () => {
		state = null;
		delete window.flavorAgentData;
		window.MutationObserver = originalMutationObserver;
		jest.runOnlyPendingTimers();
		jest.useRealTimers();
	} );

	test( 'disconnects the observer cleanly when the inserter search input never appears', () => {
		const observerInstances = [];

		mockFindInserterSearchInput.mockReturnValue( null );
		window.MutationObserver = class MockMutationObserver {
			constructor() {
				this.observe = jest.fn();
				this.disconnect = jest.fn();
				observerInstances.push( this );
			}
		};

		renderComponent();

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledWith( {
			postType: 'page',
			visiblePatternNames: [ 'theme/hero' ],
			insertionContext: {
				rootBlock: 'core/group',
				ancestors: [ 'core/group' ],
				nearbySiblings: [],
			},
		} );
		expect( observerInstances ).toHaveLength( 1 );
		expect( observerInstances[ 0 ].observe ).toHaveBeenCalledWith(
			document.body,
			{
				childList: true,
				subtree: true,
			}
		);

		act( () => {
			getRoot().unmount();
		} );

		expect( observerInstances[ 0 ].disconnect ).toHaveBeenCalled();
	} );

	test( 'derives template-part metadata from ancestor attributes and lookup data', () => {
		state.blockEditor.insertionPoint = {
			rootClientId: 'group-a',
			index: 1,
		};
		state.blockEditor.blockNames = {
			'tpl-a': 'core/template-part',
			'group-a': 'core/group',
			'sibling-a': 'core/paragraph',
			'sibling-b': 'core/image',
			'sibling-c': 'core/buttons',
		};
		state.blockEditor.blockRoots = {
			'group-a': 'tpl-a',
			'tpl-a': null,
		};
		state.blockEditor.blockOrder = {
			'group-a': [ 'sibling-a', 'sibling-b', 'sibling-c' ],
		};
		state.blockEditor.blockAttributes = {
			'tpl-a': {
				slug: 'site-header',
			},
			'group-a': {
				layout: {
					type: 'flex',
				},
			},
		};
		window.flavorAgentData = {
			canRecommendPatterns: true,
			templatePartAreas: {
				'site-header': 'header',
			},
		};

		renderComponent();

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledWith( {
			postType: 'page',
			visiblePatternNames: [ 'theme/hero' ],
			insertionContext: {
				rootBlock: 'core/group',
				ancestors: [ 'core/template-part', 'core/group' ],
				nearbySiblings: [
					'core/paragraph',
					'core/image',
					'core/buttons',
				],
				templatePartArea: 'header',
				templatePartSlug: 'site-header',
				containerLayout: 'flex',
			},
		} );
		expect( mockGetBlockAttributes ).toHaveBeenCalledWith( 'group-a' );
		expect( mockGetBlockAttributes ).toHaveBeenCalledWith( 'tpl-a' );
	} );

	test( 'removes the input listener on unmount when a search field is found immediately', () => {
		const searchInput = {
			addEventListener: jest.fn(),
			removeEventListener: jest.fn(),
		};

		mockFindInserterSearchInput.mockReturnValue( searchInput );

		renderComponent();

		expect( searchInput.addEventListener ).toHaveBeenCalledWith(
			'input',
			expect.any( Function )
		);

		act( () => {
			getRoot().unmount();
		} );

		expect( searchInput.removeEventListener ).toHaveBeenCalledWith(
			'input',
			searchInput.addEventListener.mock.calls[ 0 ][ 1 ]
		);
	} );

	test( 'reapplies recommendations when the pattern registry becomes available after the initial fetch', () => {
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		state.blockEditor.settings = {};
		state.blockEditor.runtimePatterns = [];

		renderComponent();

		expect( mockSetBlockPatterns ).not.toHaveBeenCalled();

		state.blockEditor.settings = {
			blockPatterns: [
				{
					name: 'theme/hero',
					title: 'Hero',
					categories: [ 'featured' ],
				},
			],
		};
		state.blockEditor.runtimeCategories = [
			{ name: 'featured', label: 'Featured' },
		];
		state.blockEditor.runtimePatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				categories: [ 'featured' ],
			},
		];

		renderComponent();

		expect( mockSetBlockPatterns ).toHaveBeenCalledWith( [
			{
				name: 'theme/hero',
				title: 'Hero',
				description: 'Recommended hero pattern.',
				categories: [ 'featured', 'recommended' ],
				keywords: [ 'recommended', 'hero', 'pattern' ],
			},
		] );
		expect( mockSetBlockPatternCategories ).toHaveBeenCalledWith( [
			{ name: 'featured', label: 'Featured' },
			{ name: 'recommended', label: 'Recommended' },
		] );
	} );

	test( 'does not remove a native recommended category after a new editor session mounts', () => {
		let secondContainer = null;
		let secondRoot = null;

		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		state.blockEditor.settings = {
			blockPatterns: [
				{
					name: 'theme/hero',
					title: 'Hero',
					categories: [ 'featured' ],
				},
			],
			blockPatternCategories: [ { name: 'featured', label: 'Featured' } ],
		};
		state.blockEditor.runtimeCategories = [
			{ name: 'featured', label: 'Featured' },
		];
		state.blockEditor.runtimePatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				categories: [ 'featured' ],
			},
		];

		renderComponent();

		act( () => {
			getRoot().unmount();
		} );

		mockSetBlockPatternCategories.mockClear();
		mockSetBlockPatterns.mockClear();

		state.store.patternRecommendations = [];
		state.blockEditor.settings = {
			blockPatterns: [
				{
					name: 'theme/hero',
					title: 'Hero',
					categories: [ 'featured' ],
				},
			],
			blockPatternCategories: [
				{ name: 'featured', label: 'Featured' },
				{ name: 'recommended', label: 'Recommended' },
			],
		};
		state.blockEditor.runtimeCategories = [
			{ name: 'featured', label: 'Featured' },
			{ name: 'recommended', label: 'Recommended' },
		];
		state.blockEditor.runtimePatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				categories: [ 'featured' ],
			},
		];

		secondContainer = document.createElement( 'div' );
		document.body.appendChild( secondContainer );
		secondRoot = createRoot( secondContainer );

		act( () => {
			secondRoot.render( <PatternRecommender /> );
		} );

		expect( mockSetBlockPatternCategories ).toHaveBeenLastCalledWith( [
			{ name: 'featured', label: 'Featured' },
			{ name: 'recommended', label: 'Recommended' },
		] );

		act( () => {
			secondRoot.unmount();
		} );
		secondContainer.remove();
	} );

	test( 'stops owning the recommended category when the registry replaces it with a native entry', () => {
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		state.blockEditor.settings = {
			blockPatterns: [
				{
					name: 'theme/hero',
					title: 'Hero',
					categories: [ 'featured' ],
				},
			],
			blockPatternCategories: [ { name: 'featured', label: 'Featured' } ],
		};
		state.blockEditor.runtimeCategories = [
			{ name: 'featured', label: 'Featured' },
		];
		state.blockEditor.runtimePatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				categories: [ 'featured' ],
			},
		];

		renderComponent();

		state.blockEditor.settings = {
			blockPatterns: [
				{
					name: 'theme/hero',
					title: 'Hero',
					categories: [ 'featured' ],
				},
			],
			blockPatternCategories: [
				{ name: 'featured', label: 'Featured' },
				{ name: 'recommended', label: 'Recommended' },
			],
		};
		state.blockEditor.runtimeCategories = [
			{ name: 'featured', label: 'Featured' },
			{ name: 'recommended', label: 'Recommended' },
		];

		renderComponent();

		state.store.patternRecommendations = [];

		renderComponent();

		expect( mockSetBlockPatternCategories ).toHaveBeenLastCalledWith( [
			{ name: 'featured', label: 'Featured' },
			{ name: 'recommended', label: 'Recommended' },
		] );
	} );

	test( 'refetches when visible pattern names hydrate after the initial empty load', () => {
		state.visiblePatternNames = [];

		renderComponent();

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 1 );
		expect( mockFetchPatternRecommendations ).toHaveBeenLastCalledWith( {
			postType: 'page',
			visiblePatternNames: [],
			insertionContext: {
				rootBlock: 'core/group',
				ancestors: [ 'core/group' ],
				nearbySiblings: [],
			},
		} );

		state.visiblePatternNames = [ 'theme/hero' ];

		renderComponent();

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 2 );
		expect( mockFetchPatternRecommendations ).toHaveBeenLastCalledWith( {
			postType: 'page',
			visiblePatternNames: [ 'theme/hero' ],
			insertionContext: {
				rootBlock: 'core/group',
				ancestors: [ 'core/group' ],
				nearbySiblings: [],
			},
		} );
	} );

	test( 'shows a shared capability notice inside the inserter when pattern recommendations are unavailable', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		window.flavorAgentData = {
			canRecommendPatterns: false,
			settingsUrl:
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
		};
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( mockFetchPatternRecommendations ).not.toHaveBeenCalled();
		expect( document.body.textContent ).toContain(
			'Pattern recommendations need a compatible embedding backend and Qdrant'
		);
		expect( document.body.textContent ).toContain(
			'Settings > Flavor Agent'
		);
		expect(
			inserterContainer.querySelector(
				'.flavor-agent-pattern-inserter-slot'
			)
		).not.toBeNull();

		act( () => {
			getRoot().unmount();
		} );

		expect(
			inserterContainer.querySelector(
				'.flavor-agent-pattern-inserter-slot'
			)
		).toBeNull();
	} );

	test( 'renders a persistent summary inside the inserter when recommendations are ready', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
			{
				name: 'theme/footer',
				score: 0.91,
				reason: 'Recommended footer pattern.',
			},
		];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain(
			'Recommended now includes 2 AI-ranked patterns for this insertion point.'
		);
		expect(
			inserterContainer.querySelector(
				'.flavor-agent-pattern-inserter-slot'
			)
		).not.toBeNull();
	} );

	test( 'reattaches the inserter summary when Gutenberg replaces the container', () => {
		const firstContainer = document.createElement( 'div' );
		const secondContainer = document.createElement( 'div' );
		const searchInput = {
			addEventListener: jest.fn(),
			removeEventListener: jest.fn(),
		};
		const observerInstances = [];
		let currentContainer = firstContainer;
		let triggerObserver = null;

		firstContainer.className = 'block-editor-inserter__panel-content';
		secondContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( firstContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		mockFindInserterContainer.mockImplementation( () => currentContainer );
		mockFindInserterSearchInput.mockReturnValue( searchInput );
		window.MutationObserver = class MockMutationObserver {
			constructor( callback ) {
				this.observe = jest.fn();
				this.disconnect = jest.fn();
				observerInstances.push( this );
				triggerObserver = callback;
			}
		};

		renderComponent();

		expect(
			firstContainer.querySelector(
				'.flavor-agent-pattern-inserter-slot'
			)
		).not.toBeNull();
		expect( observerInstances ).toHaveLength( 1 );

		firstContainer.remove();
		document.body.appendChild( secondContainer );
		currentContainer = secondContainer;

		act( () => {
			triggerObserver( [] );
		} );

		expect(
			secondContainer.querySelector(
				'.flavor-agent-pattern-inserter-slot'
			)
		).not.toBeNull();
		expect( observerInstances[ 0 ].disconnect ).not.toHaveBeenCalled();

		act( () => {
			getRoot().unmount();
		} );

		expect( observerInstances[ 0 ].disconnect ).toHaveBeenCalled();
		secondContainer.remove();
	} );
} );
