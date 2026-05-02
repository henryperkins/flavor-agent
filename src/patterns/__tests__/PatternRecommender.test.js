const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockCloneBlock = jest.fn();
const mockCreateBlock = jest.fn();
const mockParse = jest.fn();
const mockFetchPatternRecommendations = jest.fn();
const mockInsertBlocks = jest.fn();
const mockCreateSuccessNotice = jest.fn();
const mockCreateErrorNotice = jest.fn();
const mockCanInsertBlockType = jest.fn();
const mockGetBlockAttributes = jest.fn();
const mockGetAllowedPatterns = jest.fn();
const mockFindInserterContainer = jest.fn();
const mockFindInserterSearchInput = jest.fn();
const mockGetVisiblePatternNames = jest.fn();

jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );

jest.mock( '@wordpress/blocks', () => ( {
	cloneBlock: ( ...args ) => mockCloneBlock( ...args ),
	createBlock: ( ...args ) => mockCreateBlock( ...args ),
	parse: ( ...args ) => mockParse( ...args ),
} ) );

jest.mock( '@wordpress/data', () => ( {
	useDispatch: ( ...args ) => mockUseDispatch( ...args ),
	useSelect: ( ...args ) => mockUseSelect( ...args ),
} ) );

jest.mock( '@wordpress/editor', () => ( {
	store: 'core/editor',
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( value ) => value,
	sprintf: ( template, ...values ) => {
		let i = 0;
		return template
			.replace(
				/%(\d+)\$s/g,
				( _, n ) => values[ Number( n ) - 1 ] ?? ''
			)
			.replace( /%s/g, () => values[ i++ ] ?? '' );
	},
} ) );

jest.mock( '@wordpress/notices', () => ( {
	store: 'core/notices',
} ) );

jest.mock( '../pattern-settings', () => ( {
	getAllowedPatterns: ( ...args ) => mockGetAllowedPatterns( ...args ),
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
			canInsertBlockType: ( ...args ) =>
				mockCanInsertBlockType( ...args ),
		},
		'flavor-agent': {
			getPatternError: jest.fn( () => state.store.patternError ),
			getPatternStatus: jest.fn( () => state.store.patternStatus ),
			getPatternRecommendations: jest.fn(
				() => state.store.patternRecommendations
			),
			getPatternDiagnostics: jest.fn(
				() => state.store.patternDiagnostics
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
			allowedPatterns: [],
			editSite: {
				postType: 'page',
				postId: null,
			},
			blockEditor: {
				selectedBlockClientId: null,
				selectedBlockName: null,
				insertionPoint: {
					rootClientId: 'root-a',
					index: 0,
				},
				blockNames: { 'root-a': 'core/group' },
				blockOrder: { 'root-a': [] },
				blockRoots: { 'root-a': null },
				blockAttributes: {},
			},
			store: {
				patternError: '',
				patternStatus: 'idle',
				patternRecommendations: [],
				patternDiagnostics: null,
			},
		};
		mockUseDispatch.mockReset();
		mockUseSelect.mockReset();
		mockCloneBlock.mockReset();
		mockCreateBlock.mockReset();
		mockParse.mockReset();
		mockFetchPatternRecommendations.mockReset();
		mockInsertBlocks.mockReset();
		mockCreateSuccessNotice.mockReset();
		mockCreateErrorNotice.mockReset();
		mockCanInsertBlockType.mockReset();
		mockCanInsertBlockType.mockReturnValue( true );
		mockGetBlockAttributes.mockReset();
		mockGetAllowedPatterns.mockReset();
		mockFindInserterContainer.mockReset();
		mockFindInserterSearchInput.mockReset();
		mockGetVisiblePatternNames.mockReset();
		mockUseDispatch.mockImplementation( ( storeName ) => {
			if ( storeName === 'flavor-agent' ) {
				return {
					fetchPatternRecommendations:
						mockFetchPatternRecommendations,
				};
			}

			if ( storeName === 'core/block-editor' ) {
				return {
					insertBlocks: mockInsertBlocks,
				};
			}

			if ( storeName === 'core/notices' ) {
				return {
					createSuccessNotice: mockCreateSuccessNotice,
					createErrorNotice: mockCreateErrorNotice,
				};
			}

			return {};
		} );
		mockUseSelect.mockImplementation( ( callback ) =>
			callback( ( storeName ) => createSelectMap()[ storeName ] )
		);
		mockCloneBlock.mockImplementation( ( block ) => ( {
			...block,
			cloned: true,
		} ) );
		mockCreateBlock.mockImplementation( ( name, attributes ) => ( {
			name,
			attributes,
		} ) );
		mockParse.mockReturnValue( [] );
		mockGetBlockAttributes.mockImplementation(
			( clientId ) =>
				( state.blockEditor.blockAttributes || {} )[ clientId ] || {}
		);
		mockGetAllowedPatterns.mockImplementation(
			() => state.allowedPatterns
		);
		mockGetVisiblePatternNames.mockImplementation(
			() => state.visiblePatternNames
		);
		window.flavorAgentData = { canRecommendPatterns: true };
		originalMutationObserver = window.MutationObserver;
	} );

	afterEach( () => {
		try {
			act( () => {
				getRoot().unmount();
			} );
		} catch ( error ) {
			// Ignore unmount errors from already-unmounted roots in test cleanup.
		}
		document.body.innerHTML = '';
		state = null;
		delete window.flavorAgentData;
		window.MutationObserver = originalMutationObserver;
		jest.runOnlyPendingTimers();
		jest.useRealTimers();
	} );

	test( 'disconnects the observers cleanly when the inserter search input never appears', () => {
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
		expect( observerInstances ).toHaveLength( 2 );
		expect( observerInstances[ 0 ].observe ).toHaveBeenCalledWith(
			document.body,
			{
				childList: true,
				subtree: true,
			}
		);
		expect( observerInstances[ 1 ].observe ).toHaveBeenCalledWith(
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
		expect( observerInstances[ 1 ].disconnect ).toHaveBeenCalled();
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
	} );

	test( 'renders a loading notice inside the inserter while ranking patterns', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'loading';
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain(
			'Ranking patterns for this insertion point.'
		);
	} );

	test( 'renders an empty-state notice inside the inserter when no pattern matches are returned', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain(
			'Flavor Agent did not find a strong pattern match for this insertion point yet.'
		);
		expect( document.body.textContent ).toContain( 'No matches yet' );
	} );

	test( 'uses unreadable synced-pattern diagnostics for the empty state message', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [];
		state.store.patternDiagnostics = {
			filteredCandidates: {
				unreadableSyncedPatterns: 1,
			},
		};
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain(
			'1 synced pattern was skipped because current WordPress permissions do not allow read access.'
		);
		expect( document.body.textContent ).not.toContain( 'Private' );
	} );

	test( 'shows an unavailable-native-pattern message until the allowed pattern list hydrates', () => {
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
		];
		state.allowedPatterns = [];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain(
			'Flavor Agent found ranked patterns, but Gutenberg is not currently exposing those patterns for this insertion point.'
		);
		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 1 );

		state.allowedPatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				blocks: [ { name: 'core/paragraph', attributes: {} } ],
			},
		];

		renderComponent();

		expect( document.body.textContent ).toContain( 'Hero' );
		expect( document.body.textContent ).toContain(
			'AI-ranked patterns stay local to this shelf.'
		);
	} );

	test( 'renders an error notice with retry inside the inserter when ranking fails', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'error';
		state.store.patternError = 'Pattern recommendation request failed.';
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain(
			'Pattern recommendation request failed.'
		);
		expect( document.body.textContent ).toContain( 'Retry' );

		act( () => {
			Array.from( inserterContainer.querySelectorAll( 'button' ) )
				.find( ( button ) => button.textContent === 'Retry' )
				.click();
		} );

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

	test( 'renders a local inserter shelf and inserts matched allowed patterns', () => {
		const inserterContainer = document.createElement( 'div' );
		const allowedPattern = {
			name: 'theme/hero',
			title: 'Hero',
			blocks: [
				{
					name: 'core/paragraph',
					attributes: {
						content: 'Hello world',
					},
				},
			],
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		state.allowedPatterns = [ allowedPattern ];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain( 'Hero' );
		expect( document.body.textContent ).toContain(
			'Recommended hero pattern.'
		);
		expect( document.body.textContent ).toContain(
			'AI-ranked patterns stay local to this shelf.'
		);

		act( () => {
			Array.from( inserterContainer.querySelectorAll( 'button' ) )
				.find( ( button ) => button.textContent === 'Insert' )
				.click();
		} );

		expect( mockCloneBlock ).toHaveBeenCalledWith(
			allowedPattern.blocks[ 0 ]
		);
		expect( mockInsertBlocks ).toHaveBeenCalledWith(
			[
				{
					...allowedPattern.blocks[ 0 ],
					cloned: true,
				},
			],
			0,
			'root-a',
			false
		);
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Block pattern "Hero" inserted.',
			{
				type: 'snackbar',
				id: 'inserter-notice',
			}
		);
	} );

	test( 'shows a safe unreadable synced-pattern notice when renderable recommendations remain', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternDiagnostics = {
			filteredCandidates: {
				unreadableSyncedPatterns: 2,
			},
		};
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
				categories: [ 'hero' ],
				ranking: {
					sourceSignals: [ 'qdrant_semantic', 'llm_ranker' ],
					rankingHint: {
						matchesNearbyBlock: true,
					},
				},
			},
		];
		state.allowedPatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				categories: [ 'featured' ],
				blocks: [ { name: 'core/paragraph', attributes: {} } ],
			},
		];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain(
			'2 synced patterns were skipped because current WordPress permissions do not allow read access.'
		);
		expect( document.body.textContent ).toContain( 'Semantic match' );
		expect( document.body.textContent ).toContain( 'Model ranked' );
		expect( document.body.textContent ).toContain( 'Category: hero' );
		expect( document.body.textContent ).toContain( 'Allowed here' );
		expect( document.body.textContent ).toContain( 'Nearby block fit' );
	} );

	test( 'inserts synced user patterns via a core/block reference', () => {
		const inserterContainer = document.createElement( 'div' );
		const syncedPattern = {
			name: 'core/block-flavor-agent-sync',
			title: 'Synced Hero',
			type: 'user',
			syncStatus: 'fully',
			id: 77,
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: syncedPattern.name,
				score: 0.98,
				reason: 'Best reusable match.',
			},
		];
		state.allowedPatterns = [ syncedPattern ];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		act( () => {
			Array.from( inserterContainer.querySelectorAll( 'button' ) )
				.find( ( button ) => button.textContent === 'Insert' )
				.click();
		} );

		expect( mockCreateBlock ).toHaveBeenCalledWith( 'core/block', {
			ref: 77,
		} );
		expect( mockInsertBlocks ).toHaveBeenCalledWith(
			[
				{
					name: 'core/block',
					attributes: {
						ref: 77,
					},
					cloned: true,
				},
			],
			0,
			'root-a',
			false
		);
	} );

	test( 'filters out recommendations whose top-level blocks cannot be inserted at the current root', () => {
		const inserterContainer = document.createElement( 'div' );
		const insertablePattern = {
			name: 'theme/hero',
			title: 'Hero',
			blocks: [ { name: 'core/paragraph', attributes: {} } ],
		};
		const templateOnlyPattern = {
			name: 'twentytwentyfive/template-page-photo-blog',
			title: 'Photo blog page',
			blocks: [
				{ name: 'core/template-part', attributes: { slug: 'header' } },
				{ name: 'core/group', attributes: {}, innerBlocks: [] },
				{ name: 'core/template-part', attributes: { slug: 'footer' } },
			],
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: templateOnlyPattern.name,
				score: 0.97,
				reason: 'Recommended template page.',
			},
			{
				name: insertablePattern.name,
				score: 0.92,
				reason: 'Recommended hero pattern.',
			},
		];
		state.allowedPatterns = [ templateOnlyPattern, insertablePattern ];
		mockCanInsertBlockType.mockImplementation(
			( blockName ) => blockName !== 'core/template-part'
		);
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain( 'Hero' );
		expect( document.body.textContent ).not.toContain( 'Photo blog page' );

		act( () => {
			Array.from( inserterContainer.querySelectorAll( 'button' ) )
				.find( ( button ) => button.textContent === 'Insert' )
				.click();
		} );

		expect( mockInsertBlocks ).toHaveBeenCalledTimes( 1 );
		expect( mockInsertBlocks ).toHaveBeenCalledWith(
			[
				{
					...insertablePattern.blocks[ 0 ],
					cloned: true,
				},
			],
			0,
			'root-a',
			false
		);
		expect( mockCreateErrorNotice ).not.toHaveBeenCalled();
	} );

	test( 'explains when allowed recommendations are rejected by insertability checks', () => {
		const inserterContainer = document.createElement( 'div' );
		const blockedPattern = {
			name: 'theme/template-with-parts',
			title: 'Template with parts',
			blocks: [
				{ name: 'core/template-part', attributes: { slug: 'header' } },
			],
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: blockedPattern.name,
				score: 0.96,
				reason: 'Strong template match.',
			},
		];
		state.allowedPatterns = [ blockedPattern ];
		mockCanInsertBlockType.mockReturnValue( false );
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain(
			'Flavor Agent found ranked patterns, but the matched pattern blocks are not allowed at this insertion point.'
		);
		expect( document.body.textContent ).not.toContain(
			'Gutenberg is not currently exposing those patterns'
		);
		expect(
			inserterContainer.querySelector(
				'.flavor-agent-pattern-shelf__item'
			)
		).toBeNull();
	} );

	test( 'shows an error notice and skips dispatch when the resolved blocks are not allowed at the insertion point', () => {
		// Defense in depth: if pre-filter is bypassed (e.g., a click races a
		// settings change), the click handler must surface a clear error
		// rather than silently dispatch a no-op.
		const inserterContainer = document.createElement( 'div' );
		const blockedPattern = {
			name: 'twentytwentyfive/template-page-photo-blog',
			title: 'Photo blog page',
			blocks: [
				{ name: 'core/template-part', attributes: { slug: 'header' } },
				{ name: 'core/group', attributes: {}, innerBlocks: [] },
				{ name: 'core/template-part', attributes: { slug: 'footer' } },
			],
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: blockedPattern.name,
				score: 0.97,
				reason: 'Recommended template page.',
			},
		];
		state.allowedPatterns = [ blockedPattern ];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		// Pre-filter pass-through (true), but a fresh select at click time
		// rejects the template-part blocks.
		const wpSelect = jest.fn().mockReturnValue( {
			canInsertBlockType: ( blockName ) =>
				blockName !== 'core/template-part',
		} );
		const previousWp = window.wp;
		window.wp = { data: { select: wpSelect } };

		try {
			renderComponent();

			expect( document.body.textContent ).toContain( 'Photo blog page' );

			act( () => {
				Array.from( inserterContainer.querySelectorAll( 'button' ) )
					.find( ( button ) => button.textContent === 'Insert' )
					.click();
			} );

			expect( mockInsertBlocks ).not.toHaveBeenCalled();
			expect( mockCreateErrorNotice ).toHaveBeenCalledTimes( 1 );
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'Cannot insert pattern "Photo blog page" here. The following blocks are not allowed at this insertion point: core/template-part, core/template-part.',
				{
					type: 'snackbar',
					id: 'inserter-notice',
				}
			);
			expect( mockCreateSuccessNotice ).not.toHaveBeenCalled();
		} finally {
			window.wp = previousWp;
		}
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

	test( 'reattaches the inserter shelf when Gutenberg replaces the container', () => {
		const firstContainer = document.createElement( 'div' );
		const secondContainer = document.createElement( 'div' );
		const searchInput = {
			addEventListener: jest.fn(),
			removeEventListener: jest.fn(),
		};
		const observerInstances = [];
		const observerCallbacks = [];
		let currentContainer = firstContainer;

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
		state.allowedPatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				blocks: [ { name: 'core/paragraph', attributes: {} } ],
			},
		];
		mockFindInserterContainer.mockImplementation( () => currentContainer );
		mockFindInserterSearchInput.mockReturnValue( searchInput );
		window.MutationObserver = class MockMutationObserver {
			constructor( callback ) {
				this.observe = jest.fn();
				this.disconnect = jest.fn();
				observerInstances.push( this );
				observerCallbacks.push( callback );
			}
		};

		renderComponent();

		expect(
			firstContainer.querySelector(
				'.flavor-agent-pattern-inserter-slot'
			)
		).not.toBeNull();
		expect( observerInstances ).toHaveLength( 2 );

		firstContainer.remove();
		document.body.appendChild( secondContainer );
		currentContainer = secondContainer;

		act( () => {
			observerCallbacks.forEach( ( callback ) => callback( [] ) );
		} );

		expect(
			secondContainer.querySelector(
				'.flavor-agent-pattern-inserter-slot'
			)
		).not.toBeNull();
		expect( observerInstances[ 0 ].disconnect ).not.toHaveBeenCalled();
		expect( observerInstances[ 1 ].disconnect ).not.toHaveBeenCalled();

		act( () => {
			getRoot().unmount();
		} );

		observerInstances.forEach( ( observer ) => {
			expect( observer.disconnect ).toHaveBeenCalled();
		} );
		secondContainer.remove();
	} );

	test( 'keeps search-triggered fetches debounced and includes the selected block context', () => {
		const searchInput = {
			addEventListener: jest.fn(),
			removeEventListener: jest.fn(),
		};
		let inputListener = null;

		state.blockEditor.selectedBlockClientId = 'block-1';
		state.blockEditor.selectedBlockName = 'core/heading';
		mockFindInserterSearchInput.mockReturnValue( searchInput );
		searchInput.addEventListener.mockImplementation(
			( event, listener ) => {
				if ( event === 'input' ) {
					inputListener = listener;
				}
			}
		);

		renderComponent();

		expect( inputListener ).toEqual( expect.any( Function ) );
		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 1 );

		act( () => {
			inputListener( {
				target: {
					value: 'hero',
				},
			} );
			jest.advanceTimersByTime( 399 );
		} );

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 1 );

		act( () => {
			jest.advanceTimersByTime( 1 );
		} );

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 2 );
		expect( mockFetchPatternRecommendations ).toHaveBeenLastCalledWith( {
			postType: 'page',
			visiblePatternNames: [ 'theme/hero' ],
			insertionContext: {
				rootBlock: 'core/group',
				ancestors: [ 'core/group' ],
				nearbySiblings: [],
			},
			prompt: 'hero',
			blockContext: {
				blockName: 'core/heading',
			},
		} );
	} );

	test( 'reattaches the inserter search listener when Gutenberg replaces the input', () => {
		const firstSearchInput = {
			addEventListener: jest.fn(),
			removeEventListener: jest.fn(),
		};
		const secondSearchInput = {
			addEventListener: jest.fn(),
			removeEventListener: jest.fn(),
		};
		const observerCallbacks = [];
		let currentSearchInput = firstSearchInput;
		let secondInputListener = null;

		state.blockEditor.selectedBlockClientId = 'block-1';
		state.blockEditor.selectedBlockName = 'core/heading';
		mockFindInserterSearchInput.mockImplementation(
			() => currentSearchInput
		);
		secondSearchInput.addEventListener.mockImplementation(
			( event, listener ) => {
				if ( event === 'input' ) {
					secondInputListener = listener;
				}
			}
		);
		window.MutationObserver = class MockMutationObserver {
			constructor( callback ) {
				this.observe = jest.fn();
				this.disconnect = jest.fn();
				observerCallbacks.push( callback );
			}
		};

		renderComponent();

		expect( firstSearchInput.addEventListener ).toHaveBeenCalledWith(
			'input',
			expect.any( Function )
		);

		currentSearchInput = secondSearchInput;
		act( () => {
			observerCallbacks.forEach( ( callback ) => callback( [] ) );
		} );

		expect( firstSearchInput.removeEventListener ).toHaveBeenCalledWith(
			'input',
			firstSearchInput.addEventListener.mock.calls[ 0 ][ 1 ]
		);
		expect( secondSearchInput.addEventListener ).toHaveBeenCalledWith(
			'input',
			expect.any( Function )
		);

		act( () => {
			secondInputListener( {
				target: {
					value: 'gallery',
				},
			} );
			jest.advanceTimersByTime( 400 );
		} );

		expect( mockFetchPatternRecommendations ).toHaveBeenLastCalledWith( {
			postType: 'page',
			visiblePatternNames: [ 'theme/hero' ],
			insertionContext: {
				rootBlock: 'core/group',
				ancestors: [ 'core/group' ],
				nearbySiblings: [],
			},
			prompt: 'gallery',
			blockContext: {
				blockName: 'core/heading',
			},
		} );
	} );

	test( 'renders template recommendations with normalized template type when editing a site template', () => {
		state.editSite = {
			postType: 'wp_template',
			postId: 'custom//front-page',
		};

		renderComponent();

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledWith( {
			postType: 'page',
			templateType: 'front-page',
			visiblePatternNames: [ 'theme/hero' ],
			insertionContext: {
				rootBlock: 'core/group',
				ancestors: [ 'core/group' ],
				nearbySiblings: [],
			},
		} );
	} );

	test( 'mounts cleanly in a second root after the first editor session unmounts', () => {
		let secondContainer = null;
		let secondRoot = null;

		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		state.allowedPatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				blocks: [ { name: 'core/paragraph', attributes: {} } ],
			},
		];

		renderComponent();

		act( () => {
			getRoot().unmount();
		} );

		secondContainer = document.createElement( 'div' );
		document.body.appendChild( secondContainer );
		secondRoot = createRoot( secondContainer );

		act( () => {
			secondRoot.render( <PatternRecommender /> );
		} );

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 2 );

		act( () => {
			secondRoot.unmount();
		} );
		secondContainer.remove();
	} );
} );
