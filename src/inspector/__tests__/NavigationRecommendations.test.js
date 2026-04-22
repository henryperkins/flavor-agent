const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockSerialize = jest.fn();
const mockFetchNavigationRecommendations = jest.fn();
const mockClearNavigationError = jest.fn();
const mockClearNavigationRecommendations = jest.fn();
const mockRevalidateNavigationReviewFreshness = jest.fn();
const mockCollectBlockContext = jest.fn();
const mockGetLiveBlockContextSignature = jest.fn();

jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );

jest.mock( '@wordpress/blocks', () => ( {
	serialize: ( ...args ) => mockSerialize( ...args ),
} ) );

jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

jest.mock( '@wordpress/data', () => ( {
	useDispatch: ( ...args ) => mockUseDispatch( ...args ),
	useSelect: ( ...args ) => mockUseSelect( ...args ),
} ) );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

jest.mock( '../../context/collector', () => ( {
	collectBlockContext: ( ...args ) => mockCollectBlockContext( ...args ),
	getLiveBlockContextSignature: ( ...args ) =>
		mockGetLiveBlockContextSignature( ...args ),
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );
const DOCUMENT_POSITION_FOLLOWING = 4;

import { buildContextSignature } from '../../utils/context-signature';
import NavigationRecommendations, {
	buildNavigationFetchInput,
} from '../NavigationRecommendations';

const { getContainer, getRoot } = setupReactTest();

let currentState = null;
function getState() {
	return currentState;
}

function getRequestNavigationEditorContext() {
	return {
		block: {
			name: 'core/navigation',
			title: 'Navigation',
			structuralIdentity: {
				role: 'header-navigation',
				location: 'header',
				templateArea: 'header',
				templatePartSlug: 'site-header',
			},
		},
		siblingsBefore: [ 'core/site-logo' ],
		siblingsAfter: [ 'core/buttons' ],
		structuralAncestors: [
			{
				block: 'core/template-part',
				role: 'header-slot',
				location: 'header',
				templateArea: 'header',
				templatePartSlug: 'site-header',
			},
		],
		structuralBranch: [
			{
				block: 'core/template-part',
				role: 'header-slot',
				location: 'header',
				templateArea: 'header',
				templatePartSlug: 'site-header',
				children: [
					{
						block: 'core/navigation',
						role: 'header-navigation',
						location: 'header',
					},
				],
			},
		],
	};
}

function getCollectedNavigationContext() {
	return {
		...getRequestNavigationEditorContext(),
		themeTokens: {
			colors: [ 'accent: #ff5500' ],
		},
	};
}

function buildStoredNavigationSignature(
	navigationMarkup,
	menuId = 42,
	prompt = ''
) {
	const signature = {
		menuId,
		navigationMarkup,
	};

	if ( prompt ) {
		signature.prompt = prompt;
	}

	signature.editorContext = getRequestNavigationEditorContext();

	return buildContextSignature( signature );
}

function createSelectors() {
	return {
		blockEditor: {
			getBlock: jest.fn(
				( clientId ) =>
					getState().blockEditor.blocks[ clientId ] || null
			),
		},
		store: {
			getNavigationRecommendations: jest.fn( ( clientId ) =>
				getState().store.navigationBlockClientId === clientId
					? getState().store.navigationRecommendations
					: []
			),
			getNavigationInteractionState: jest.fn( () => 'idle' ),
			getNavigationExplanation: jest.fn( ( clientId ) =>
				getState().store.navigationBlockClientId === clientId
					? getState().store.navigationExplanation
					: ''
			),
			getNavigationError: jest.fn( ( clientId ) =>
				getState().store.navigationBlockClientId === clientId
					? getState().store.navigationError
					: null
			),
			isNavigationLoading: jest.fn(
				( clientId ) =>
					getState().store.navigationBlockClientId === clientId &&
					getState().store.navigationStatus === 'loading'
			),
			getNavigationStatus: jest.fn( ( clientId ) =>
				getState().store.navigationBlockClientId === clientId
					? getState().store.navigationStatus
					: 'idle'
			),
			getNavigationRequestPrompt: jest.fn( ( clientId ) =>
				getState().store.navigationBlockClientId === clientId
					? getState().store.navigationRequestPrompt || ''
					: ''
			),
			getNavigationBlockClientId: jest.fn(
				() => getState().store.navigationBlockClientId
			),
			getNavigationContextSignature: jest.fn( ( clientId ) =>
				getState().store.navigationBlockClientId === clientId
					? getState().store.navigationContextSignature
					: null
			),
			getNavigationReviewContextSignature: jest.fn( ( clientId ) =>
				getState().store.navigationBlockClientId === clientId
					? getState().store.navigationReviewContextSignature || null
					: null
			),
			getNavigationReviewFreshnessStatus: jest.fn( ( clientId ) =>
				getState().store.navigationBlockClientId === clientId
					? getState().store.navigationReviewFreshnessStatus || 'idle'
					: 'idle'
			),
			getNavigationReviewStaleReason: jest.fn( ( clientId ) =>
				getState().store.navigationBlockClientId === clientId
					? getState().store.navigationReviewStaleReason || null
					: null
			),
			getSurfaceStatusNotice: jest.fn( ( surface, options = {} ) => {
				void surface;

				if ( options.requestError ) {
					return {
						source: 'request',
						tone: 'error',
						message: options.requestError,
						isDismissible: true,
					};
				}

				if ( options.emptyMessage ) {
					return {
						source: 'empty',
						tone: 'info',
						message: options.emptyMessage,
					};
				}

				return null;
			} ),
		},
	};
}

const selectors = createSelectors();

function renderComponent( clientId = 'nav-1' ) {
	act( () => {
		getRoot().render( <NavigationRecommendations clientId={ clientId } /> );
	} );
}

function renderEmbeddedComponent( clientId = 'nav-1' ) {
	act( () => {
		getRoot().render(
			<NavigationRecommendations clientId={ clientId } embedded />
		);
	} );
}

function updatePrompt( value ) {
	const textarea = getContainer().querySelector( 'textarea' );

	act( () => {
		textarea.value = value;
		textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}

beforeEach( () => {
	jest.clearAllMocks();
	currentState = {
		blockEditor: {
			blocks: {},
		},
		store: {
			navigationBlockClientId: null,
			navigationRecommendations: [],
			navigationExplanation: '',
			navigationError: null,
			navigationStatus: 'idle',
			navigationRequestPrompt: '',
			navigationContextSignature: null,
			navigationReviewContextSignature: null,
			navigationReviewFreshnessStatus: 'idle',
			navigationReviewStaleReason: null,
		},
	};
	window.flavorAgentData = {
		canRecommendNavigation: true,
	};
	mockGetLiveBlockContextSignature.mockImplementation(
		() => 'live-nav-signature'
	);
	mockCollectBlockContext.mockImplementation( () =>
		getCollectedNavigationContext()
	);

	mockUseSelect.mockImplementation( ( mapSelect ) =>
		mapSelect( ( storeName ) => {
			if ( storeName === 'flavor-agent' ) {
				return selectors.store;
			}

			if ( storeName === 'core/block-editor' ) {
				return selectors.blockEditor;
			}

			return {};
		} )
	);

	mockUseDispatch.mockImplementation( () => ( {
		clearNavigationError: mockClearNavigationError,
		clearNavigationRecommendations: mockClearNavigationRecommendations,
		fetchNavigationRecommendations: mockFetchNavigationRecommendations,
		revalidateNavigationReviewFreshness:
			mockRevalidateNavigationReviewFreshness,
	} ) );
	mockClearNavigationRecommendations.mockImplementation( () => {
		currentState.store = {
			...currentState.store,
			navigationBlockClientId: null,
			navigationRecommendations: [],
			navigationExplanation: '',
			navigationError: null,
			navigationStatus: 'idle',
			navigationRequestPrompt: '',
			navigationContextSignature: null,
			navigationReviewContextSignature: null,
			navigationReviewFreshnessStatus: 'idle',
			navigationReviewStaleReason: null,
		};
	} );
} );

afterEach( () => {
	delete window.flavorAgentData;
} );

describe( 'NavigationRecommendations', () => {
	test( 'does not render for non-navigation blocks', () => {
		currentState.blockEditor.blocks = {
			'block-1': {
				clientId: 'block-1',
				name: 'core/paragraph',
				attributes: {},
				innerBlocks: [],
			},
		};

		renderComponent( 'block-1' );

		expect( getContainer().textContent ).toBe( '' );
	} );

	test( 'shows a shared capability notice without a dead-end settings link when theme capability is missing', () => {
		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
				},
				innerBlocks: [],
			},
		};
		window.flavorAgentData = {
			canRecommendNavigation: false,
			capabilities: {
				surfaces: {
					navigation: {
						available: false,
						reason: 'missing_theme_capability',
						message:
							'Navigation recommendations require the edit_theme_options capability.',
					},
				},
			},
			settingsUrl:
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
		};

		renderComponent();

		expect( getContainer().textContent ).toContain(
			'Navigation recommendations require the edit_theme_options capability.'
		);
		expect( getContainer().textContent ).not.toContain(
			'Settings > Flavor Agent'
		);
		expect( getContainer().textContent ).not.toContain(
			'Get Navigation Suggestions'
		);
	} );

	test( 'fetches navigation suggestions with serialized block markup when structure is available', () => {
		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
					overlayMenu: 'mobile',
				},
				innerBlocks: [
					{
						clientId: 'link-1',
						name: 'core/navigation-link',
						attributes: {
							label: 'Home',
							url: '/',
						},
						innerBlocks: [],
					},
				],
			},
		};
		mockSerialize.mockReturnValue(
			'<!-- wp:navigation {"ref":42,"overlayMenu":"mobile"} --><!-- wp:navigation-link {"label":"Home","url":"/"} /--><!-- /wp:navigation -->'
		);

		renderComponent();

		const button = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find(
			( element ) => element.textContent === 'Get Navigation Suggestions'
		);

		act( () => {
			button.click();
		} );

		expect( mockSerialize ).toHaveBeenCalledWith( [
			currentState.blockEditor.blocks[ 'nav-1' ],
		] );
		expect( mockFetchNavigationRecommendations ).toHaveBeenCalledWith(
			expect.objectContaining( {
				blockClientId: 'nav-1',
				editorContext: getRequestNavigationEditorContext(),
				menuId: 42,
				navigationMarkup:
					'<!-- wp:navigation {"ref":42,"overlayMenu":"mobile"} --><!-- wp:navigation-link {"label":"Home","url":"/"} /--><!-- /wp:navigation -->',
				contextSignature: expect.any( String ),
			} )
		);
	} );

	test( 'includes serialized block markup when a referenced menu has no in-canvas inner structure', () => {
		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
					overlayMenu: 'always',
				},
				innerBlocks: [],
			},
		};
		mockSerialize.mockReturnValue(
			'<!-- wp:navigation {"ref":42,"overlayMenu":"always"} /-->'
		);

		renderComponent();

		const button = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find(
			( element ) => element.textContent === 'Get Navigation Suggestions'
		);

		act( () => {
			button.click();
		} );

		expect( mockSerialize ).toHaveBeenCalledWith( [
			currentState.blockEditor.blocks[ 'nav-1' ],
		] );
		expect( mockFetchNavigationRecommendations ).toHaveBeenCalledWith(
			expect.objectContaining( {
				blockClientId: 'nav-1',
				editorContext: getRequestNavigationEditorContext(),
				menuId: 42,
				navigationMarkup:
					'<!-- wp:navigation {"ref":42,"overlayMenu":"always"} /-->',
				contextSignature: expect.any( String ),
			} )
		);
	} );

	test( 'revalidates ready navigation results against the server freshness contract when the live input still matches', () => {
		const navigationMarkup = '<!-- wp:navigation {"ref":42} /-->';

		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
				},
				innerBlocks: [],
			},
		};
		currentState.store = {
			navigationBlockClientId: 'nav-1',
			navigationRecommendations: [ { label: 'Group utility links' } ],
			navigationExplanation: 'Existing guidance.',
			navigationError: null,
			navigationStatus: 'ready',
			navigationRequestPrompt: 'Simplify the header navigation.',
			navigationContextSignature: buildStoredNavigationSignature(
				navigationMarkup,
				42,
				'Simplify the header navigation.'
			),
			navigationReviewContextSignature: 'review-navigation-stored',
			navigationReviewFreshnessStatus: 'fresh',
			navigationReviewStaleReason: null,
		};
		mockSerialize.mockReturnValue( navigationMarkup );

		renderComponent();
		renderComponent();

		expect( mockRevalidateNavigationReviewFreshness ).toHaveBeenCalledWith(
			expect.any( String ),
			{
				menuId: 42,
				navigationMarkup,
				prompt: 'Simplify the header navigation.',
				editorContext: getRequestNavigationEditorContext(),
			}
		);
	} );

	test( 'keeps stale navigation results visible and refreshable when the stored context signature mismatches', () => {
		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
				},
				innerBlocks: [],
			},
		};
		currentState.store = {
			navigationBlockClientId: 'nav-1',
			navigationRecommendations: [ { label: 'Group utility links' } ],
			navigationExplanation: 'Existing guidance.',
			navigationError: null,
			navigationStatus: 'ready',
			navigationRequestPrompt: 'Simplify the footer navigation.',
			navigationContextSignature: 'stale-signature',
		};
		mockSerialize.mockReturnValue( '<!-- wp:navigation {"ref":42} /-->' );

		renderComponent();

		expect( getContainer().textContent ).toContain( 'Group utility links' );
		expect( getContainer().textContent ).toContain(
			'These ideas are shown for reference from the last request. Refresh before using them to change the current navigation block.'
		);

		const refreshButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( element ) => element.textContent === 'Refresh' );

		act( () => {
			refreshButton.click();
		} );

		expect( mockFetchNavigationRecommendations ).toHaveBeenCalledWith(
			expect.objectContaining( {
				blockClientId: 'nav-1',
				editorContext: getRequestNavigationEditorContext(),
				menuId: 42,
				navigationMarkup: '<!-- wp:navigation {"ref":42} /-->',
				prompt: 'Simplify the footer navigation.',
				contextSignature: expect.any( String ),
			} )
		);
	} );

	test( 'refreshes cleared prompts with the stored prompt signature so the next result stays fresh', () => {
		const navigationMarkup = '<!-- wp:navigation {"ref":42} /-->';

		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
				},
				innerBlocks: [],
			},
		};
		currentState.store = {
			navigationBlockClientId: 'nav-1',
			navigationRecommendations: [ { label: 'Group utility links' } ],
			navigationExplanation: 'Existing guidance.',
			navigationError: null,
			navigationStatus: 'ready',
			navigationRequestPrompt: 'Simplify the footer navigation.',
			navigationContextSignature: 'stale-signature',
		};
		mockSerialize.mockReturnValue( navigationMarkup );

		renderComponent();
		updatePrompt( '   ' );

		const refreshButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( element ) => element.textContent === 'Refresh' );

		act( () => {
			refreshButton.click();
		} );

		expect( mockFetchNavigationRecommendations ).toHaveBeenCalledWith( {
			blockClientId: 'nav-1',
			editorContext: getRequestNavigationEditorContext(),
			menuId: 42,
			navigationMarkup,
			prompt: 'Simplify the footer navigation.',
			contextSignature: buildStoredNavigationSignature(
				navigationMarkup,
				42,
				'Simplify the footer navigation.'
			),
		} );

		currentState.store = {
			navigationBlockClientId: 'nav-1',
			navigationRecommendations: [ { label: 'Group utility links' } ],
			navigationExplanation: 'Existing guidance.',
			navigationError: null,
			navigationStatus: 'ready',
			navigationRequestPrompt: 'Simplify the footer navigation.',
			navigationContextSignature: buildStoredNavigationSignature(
				navigationMarkup,
				42,
				'Simplify the footer navigation.'
			),
		};

		renderComponent();
		renderComponent();

		expect( getContainer().textContent ).not.toContain(
			'This navigation changed after the last request. Refresh before relying on the previous guidance.'
		);
		expect( getContainer().textContent ).not.toContain( 'Stale' );
	} );

	test( 'marks navigation results stale when only the prompt changes', () => {
		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
				},
				innerBlocks: [],
			},
		};
		currentState.store = {
			navigationBlockClientId: 'nav-1',
			navigationRecommendations: [ { label: 'Group utility links' } ],
			navigationExplanation: 'Existing guidance.',
			navigationError: null,
			navigationStatus: 'ready',
			navigationRequestPrompt: 'Simplify the header navigation.',
			navigationContextSignature: buildStoredNavigationSignature(
				'<!-- wp:navigation {"ref":42} /-->',
				42,
				'Simplify the header navigation.'
			),
		};
		mockSerialize.mockReturnValue( '<!-- wp:navigation {"ref":42} /-->' );

		renderComponent();

		updatePrompt( 'Group utility links by audience.' );

		expect( getContainer().textContent ).toContain( 'Group utility links' );
		expect( getContainer().textContent ).toContain( 'Stale' );
		expect( getContainer().textContent ).toContain(
			'This navigation changed after the last request. Refresh before relying on the previous guidance.'
		);
	} );

	test( 'keeps navigation results fresh when prompt edits only change surrounding whitespace', () => {
		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
				},
				innerBlocks: [],
			},
		};
		currentState.store = {
			navigationBlockClientId: 'nav-1',
			navigationRecommendations: [ { label: 'Group utility links' } ],
			navigationExplanation: 'Existing guidance.',
			navigationError: null,
			navigationStatus: 'ready',
			navigationRequestPrompt: 'Simplify the header navigation.',
			navigationContextSignature: buildStoredNavigationSignature(
				'<!-- wp:navigation {"ref":42} /-->',
				42,
				'Simplify the header navigation.'
			),
		};
		mockSerialize.mockReturnValue( '<!-- wp:navigation {"ref":42} /-->' );

		renderComponent();

		updatePrompt( '  Simplify the header navigation.  ' );

		expect( getContainer().textContent ).toContain( 'Group utility links' );
		expect( getContainer().textContent ).not.toContain(
			'This navigation changed after the last request. Refresh before relying on the previous guidance.'
		);
		expect( getContainer().textContent ).not.toContain( 'Stale' );
	} );

	test( 'marks ref-only navigation results stale when the saved menu context drifts on the server', () => {
		const navigationMarkup = '<!-- wp:navigation {"ref":42} /-->';

		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
				},
				innerBlocks: [],
			},
		};
		currentState.store = {
			navigationBlockClientId: 'nav-1',
			navigationRecommendations: [ { label: 'Group utility links' } ],
			navigationExplanation: 'Existing guidance.',
			navigationError: null,
			navigationStatus: 'ready',
			navigationRequestPrompt: 'Simplify the header navigation.',
			navigationContextSignature: buildStoredNavigationSignature(
				navigationMarkup,
				42,
				'Simplify the header navigation.'
			),
			navigationReviewContextSignature: 'review-navigation-stored',
			navigationReviewFreshnessStatus: 'stale',
			navigationReviewStaleReason: 'server-review',
		};
		mockSerialize.mockReturnValue( navigationMarkup );

		renderComponent();
		renderComponent();

		expect( getContainer().textContent ).toContain( 'Group utility links' );
		expect( getContainer().textContent ).toContain( 'Stale' );
		expect( getContainer().textContent ).toContain(
			'Server-resolved navigation context changed after the last request. Menu structure, overlay context, or theme constraints may have shifted. Refresh before relying on the previous guidance.'
		);
		expect( getContainer().textContent ).not.toContain(
			'This navigation changed after the last request. Refresh before relying on the previous guidance.'
		);
	} );

	test( 'shows a stale scope badge when the stored navigation result context mismatches', () => {
		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
				},
				innerBlocks: [],
			},
		};
		currentState.store = {
			navigationBlockClientId: 'nav-1',
			navigationRecommendations: [ { label: 'Group utility links' } ],
			navigationExplanation: 'Existing guidance.',
			navigationError: null,
			navigationStatus: 'ready',
			navigationContextSignature: 'stale-signature',
		};
		mockSerialize.mockReturnValue( '<!-- wp:navigation {"ref":42} /-->' );

		renderComponent();

		expect( getContainer().textContent ).toContain( 'Navigation Block' );
		expect( getContainer().textContent ).toContain( 'Menu ID 42' );
		expect( getContainer().textContent ).toContain( 'Stale' );
		expect( getContainer().textContent ).toContain(
			'This navigation changed after the last request. Refresh before relying on the previous guidance.'
		);
		expect(
			getContainer()
				.querySelector( '.flavor-agent-scope-bar' )
				?.getAttribute( 'role' )
		).toBe( 'status' );
	} );

	test( 'shows a lighter stale subsection in embedded mode when the stored navigation result context mismatches', () => {
		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
				},
				innerBlocks: [],
			},
		};
		currentState.store = {
			navigationBlockClientId: 'nav-1',
			navigationRecommendations: [ { label: 'Group utility links' } ],
			navigationExplanation: 'Existing guidance.',
			navigationError: null,
			navigationStatus: 'ready',
			navigationContextSignature: 'stale-signature',
		};
		mockSerialize.mockReturnValue( '<!-- wp:navigation {"ref":42} /-->' );

		renderEmbeddedComponent();

		expect( getContainer().textContent ).toContain( 'Navigation Ideas' );
		expect( getContainer().textContent ).toContain( 'Menu ID 42' );
		expect( getContainer().textContent ).toContain( 'Stale' );
		expect( getContainer().textContent ).toContain(
			'This navigation changed after the last request. Refresh before relying on the previous guidance.'
		);
		expect( getContainer().textContent ).toContain( 'Group utility links' );
		expect( getContainer().textContent ).not.toContain(
			'Navigation Recommendations'
		);
		expect( getContainer().textContent ).not.toContain(
			'Navigation Block'
		);
		expect( getContainer().textContent ).not.toContain(
			'Recommended Next Step'
		);
		expect( getContainer().textContent ).toContain( 'Refresh' );
	} );

	test( 'shows the navigation intro copy on first render', () => {
		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
				},
				innerBlocks: [],
			},
		};

		renderComponent();

		expect( getContainer().textContent ).toContain(
			'Ask for structure, overlay, or accessibility guidance for this navigation block.'
		);
	} );

	test( 'keeps advisory navigation ideas expanded when they are returned', () => {
		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
				},
				innerBlocks: [],
			},
		};
		currentState.store = {
			navigationBlockClientId: 'nav-1',
			navigationRecommendations: [
				{
					label: 'Group utility links',
					description:
						'Move utility links into a smaller secondary row.',
					category: 'structure',
					changes: [],
				},
			],
			navigationExplanation: 'Existing guidance.',
			navigationError: null,
			navigationStatus: 'ready',
			navigationContextSignature: null,
		};
		mockSerialize.mockReturnValue( '<!-- wp:navigation {"ref":42} /-->' );

		renderComponent();

		expect( getContainer().textContent ).toContain(
			'Recommended Next Changes'
		);
		expect( getContainer().textContent ).toContain(
			'Recommended Next Step'
		);
		expect( getContainer().textContent ).toContain( 'Group utility links' );
		expect( getContainer().textContent ).toContain(
			'Move utility links into a smaller secondary row.'
		);
		expect(
			getContainer()
				.querySelector( '.flavor-agent-recommendation-hero' )
				?.compareDocumentPosition(
					getContainer().querySelector( '.flavor-agent-explanation' )
				)
		).toBe( DOCUMENT_POSITION_FOLLOWING );
	} );

	test( 'keeps embedded navigation results in a subsection instead of the standalone scope and hero shells', () => {
		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
				},
				innerBlocks: [],
			},
		};
		currentState.store = {
			navigationBlockClientId: 'nav-1',
			navigationRecommendations: [
				{
					label: 'Group utility links',
					description:
						'Move utility links into a smaller secondary row.',
					category: 'structure',
					changes: [],
				},
			],
			navigationExplanation: 'Existing guidance.',
			navigationError: null,
			navigationStatus: 'ready',
			navigationContextSignature: null,
		};
		mockSerialize.mockReturnValue( '<!-- wp:navigation {"ref":42} /-->' );

		renderEmbeddedComponent();

		expect( getContainer().textContent ).toContain( 'Navigation Ideas' );
		expect( getContainer().textContent ).toContain(
			'Recommended next change'
		);
		expect( getContainer().textContent ).toContain( 'Group utility links' );
		expect( getContainer().textContent ).not.toContain(
			'Navigation Block'
		);
		expect( getContainer().textContent ).not.toContain( 'Current' );
		expect( getContainer().textContent ).not.toContain(
			'Recommended Next Step'
		);
		expect(
			getContainer()
				.querySelector( '.flavor-agent-navigation-embedded__section' )
				?.compareDocumentPosition(
					getContainer().querySelector( '.flavor-agent-explanation' )
				)
		).toBe( DOCUMENT_POSITION_FOLLOWING );
	} );

	test( 'keeps previous navigation results visible as stale when the selected block changes in place', () => {
		const initialMarkup =
			'<!-- wp:navigation --><!-- wp:navigation-link {"label":"Home"} /--><!-- /wp:navigation -->';
		const updatedMarkup =
			'<!-- wp:navigation --><!-- wp:navigation-link {"label":"Home"} /--><!-- wp:navigation-link {"label":"Contact"} /--><!-- /wp:navigation -->';

		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
				},
				innerBlocks: [
					{
						clientId: 'link-1',
						name: 'core/navigation-link',
						attributes: {
							label: 'Home',
							url: '/',
						},
						innerBlocks: [],
					},
				],
			},
		};
		currentState.store = {
			navigationBlockClientId: 'nav-1',
			navigationRecommendations: [ { label: 'Group utility links' } ],
			navigationExplanation: 'Existing guidance.',
			navigationError: null,
			navigationStatus: 'ready',
			navigationContextSignature:
				buildStoredNavigationSignature( initialMarkup ),
		};
		mockSerialize.mockImplementation( ( blocks ) =>
			blocks?.[ 0 ]?.innerBlocks?.length === 1
				? initialMarkup
				: updatedMarkup
		);

		renderEmbeddedComponent();

		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
				},
				innerBlocks: [
					{
						clientId: 'link-1',
						name: 'core/navigation-link',
						attributes: {
							label: 'Home',
							url: '/',
						},
						innerBlocks: [],
					},
					{
						clientId: 'link-2',
						name: 'core/navigation-link',
						attributes: {
							label: 'Contact',
							url: '/contact',
						},
						innerBlocks: [],
					},
				],
			},
		};

		renderEmbeddedComponent();
		renderEmbeddedComponent();

		expect( mockClearNavigationRecommendations ).not.toHaveBeenCalled();
		expect( getContainer().textContent ).toContain( 'Navigation Ideas' );
		expect( getContainer().textContent ).toContain( 'Stale' );
		expect( getContainer().textContent ).toContain(
			'This navigation changed after the last request. Refresh before relying on the previous guidance.'
		);
		expect( getContainer().textContent ).toContain( 'Group utility links' );
	} );

	test( 'buildNavigationFetchInput includes a trimmed prompt when present', () => {
		mockSerialize.mockReturnValue(
			'<!-- wp:navigation --><!-- wp:navigation-link {"label":"Home"} /--><!-- /wp:navigation -->'
		);

		expect(
			buildNavigationFetchInput( {
				block: {
					clientId: 'nav-1',
					name: 'core/navigation',
					attributes: {
						ref: 42,
					},
					innerBlocks: [
						{
							clientId: 'link-1',
							name: 'core/navigation-link',
							attributes: {
								label: 'Home',
							},
							innerBlocks: [],
						},
					],
				},
				blockClientId: 'nav-1',
				editorContext: getCollectedNavigationContext(),
				prompt: '  Simplify the header navigation.  ',
			} )
		).toEqual( {
			blockClientId: 'nav-1',
			editorContext: getRequestNavigationEditorContext(),
			menuId: 42,
			navigationMarkup:
				'<!-- wp:navigation --><!-- wp:navigation-link {"label":"Home"} /--><!-- /wp:navigation -->',
			prompt: 'Simplify the header navigation.',
		} );
	} );

	test( 'keeps previous navigation results visible as stale when only referenced-menu attributes change', () => {
		const mobileMarkup =
			'<!-- wp:navigation {"ref":42,"overlayMenu":"mobile"} /-->';
		const alwaysMarkup =
			'<!-- wp:navigation {"ref":42,"overlayMenu":"always"} /-->';

		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
					overlayMenu: 'mobile',
				},
				innerBlocks: [],
			},
		};
		currentState.store = {
			navigationBlockClientId: 'nav-1',
			navigationRecommendations: [ { label: 'Group utility links' } ],
			navigationExplanation: 'Existing guidance.',
			navigationError: null,
			navigationStatus: 'ready',
			navigationContextSignature:
				buildStoredNavigationSignature( mobileMarkup ),
		};
		mockSerialize.mockImplementation( ( blocks ) =>
			blocks?.[ 0 ]?.attributes?.overlayMenu === 'mobile'
				? mobileMarkup
				: alwaysMarkup
		);

		renderEmbeddedComponent();

		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
					overlayMenu: 'always',
				},
				innerBlocks: [],
			},
		};

		renderEmbeddedComponent();
		renderEmbeddedComponent();

		expect( mockClearNavigationRecommendations ).not.toHaveBeenCalled();
		expect( getContainer().textContent ).toContain( 'Navigation Ideas' );
		expect( getContainer().textContent ).toContain( 'Stale' );
	} );

	test( 'clears navigation results when the scoped navigation block changes', () => {
		currentState.blockEditor.blocks = {
			'nav-1': {
				clientId: 'nav-1',
				name: 'core/navigation',
				attributes: {
					ref: 42,
				},
				innerBlocks: [],
			},
			'nav-2': {
				clientId: 'nav-2',
				name: 'core/navigation',
				attributes: {
					ref: 84,
				},
				innerBlocks: [],
			},
		};
		currentState.store = {
			navigationBlockClientId: 'nav-1',
			navigationRecommendations: [ { label: 'Group utility links' } ],
			navigationExplanation: 'Existing guidance.',
			navigationError: null,
			navigationStatus: 'ready',
			navigationContextSignature: buildStoredNavigationSignature(
				'<!-- wp:navigation {"ref":42} /-->'
			),
		};
		mockSerialize.mockImplementation( ( blocks ) => {
			const menuId = Number( blocks?.[ 0 ]?.attributes?.ref || 0 );

			return `<!-- wp:navigation {"ref":${ menuId }} /-->`;
		} );

		renderComponent( 'nav-1' );
		renderComponent( 'nav-2' );
		renderComponent( 'nav-2' );

		expect( mockClearNavigationRecommendations ).toHaveBeenCalledTimes( 1 );
	} );
} );
