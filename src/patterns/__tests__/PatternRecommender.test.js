const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockFetchPatternRecommendations = jest.fn();
const mockGetBlockPatterns = jest.fn();
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
	getBlockPatterns: ( ...args ) => mockGetBlockPatterns( ...args ),
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

import PatternRecommender from '../PatternRecommender';

window.IS_REACT_ACT_ENVIRONMENT = true;

let container = null;
let root = null;
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
			getBlockName: jest.fn( () => state.blockEditor.selectedBlockName ),
			getBlockInsertionPoint: jest.fn(
				() => state.blockEditor.insertionPoint
			),
		},
		'flavor-agent': {
			getPatternRecommendations: jest.fn(
				() => state.store.patternRecommendations
			),
		},
	};
}

function renderComponent() {
	act( () => {
		root.render( <PatternRecommender /> );
	} );
}

describe( 'PatternRecommender', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
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
				selectedBlockClientId: null,
				selectedBlockName: null,
				insertionPoint: {
					rootClientId: 'root-a',
				},
			},
			store: {
				patternRecommendations: [],
			},
		};
		mockUseDispatch.mockReset();
		mockUseSelect.mockReset();
		mockFetchPatternRecommendations.mockReset();
		mockGetBlockPatterns.mockReset();
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
		mockGetVisiblePatternNames.mockImplementation(
			() => state.visiblePatternNames
		);
		window.flavorAgentData = { canRecommendPatterns: true };
		originalMutationObserver = window.MutationObserver;
	} );

	afterEach( () => {
		if ( root ) {
			act( () => {
				root.unmount();
			} );
		}
		if ( container?.parentNode ) {
			container.parentNode.removeChild( container );
		}
		root = null;
		container = null;
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
			root.unmount();
		} );

		expect( observerInstances[ 0 ].disconnect ).toHaveBeenCalled();
		root = null;
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
			root.unmount();
		} );

		expect( searchInput.removeEventListener ).toHaveBeenCalledWith(
			'input',
			searchInput.addEventListener.mock.calls[ 0 ][ 1 ]
		);
		root = null;
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
	} );

	test( 'refetches when visible pattern names hydrate after the initial empty load', () => {
		state.visiblePatternNames = [];

		renderComponent();

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 1 );
		expect( mockFetchPatternRecommendations ).toHaveBeenLastCalledWith( {
			postType: 'page',
			visiblePatternNames: [],
		} );

		state.visiblePatternNames = [ 'theme/hero' ];

		renderComponent();

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 2 );
		expect( mockFetchPatternRecommendations ).toHaveBeenLastCalledWith( {
			postType: 'page',
			visiblePatternNames: [ 'theme/hero' ],
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
			'Pattern recommendations rely on Flavor Agent'
		);
		expect( document.body.textContent ).toContain(
			'Settings > Flavor Agent'
		);
		expect(
			inserterContainer.querySelector(
				'.flavor-agent-pattern-notice-slot'
			)
		).not.toBeNull();

		act( () => {
			root.unmount();
		} );

		expect(
			inserterContainer.querySelector(
				'.flavor-agent-pattern-notice-slot'
			)
		).toBeNull();
		root = null;
	} );
} );
