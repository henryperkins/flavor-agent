const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockSerialize = jest.fn();
const mockFetchNavigationRecommendations = jest.fn();
const mockClearNavigationError = jest.fn();
const mockClearNavigationRecommendations = jest.fn();

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

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import NavigationRecommendations, {
	buildNavigationFetchInput,
} from '../NavigationRecommendations';

const { getContainer, getRoot } = setupReactTest();

let currentState = null;
function getState() {
	return currentState;
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
		},
	};
	window.flavorAgentData = {
		canRecommendNavigation: true,
	};

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
				menuId: 42,
				navigationMarkup:
					'<!-- wp:navigation {"ref":42,"overlayMenu":"always"} /-->',
				contextSignature: expect.any( String ),
			} )
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
				menuId: 42,
				navigationMarkup: '<!-- wp:navigation {"ref":42} /-->',
				prompt: 'Simplify the footer navigation.',
				contextSignature: expect.any( String ),
			} )
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
	} );

	test( 'shows the stale scope badge in embedded mode when the stored navigation result context mismatches', () => {
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

		expect( getContainer().textContent ).toContain( 'Navigation Block' );
		expect( getContainer().textContent ).toContain( 'Menu ID 42' );
		expect( getContainer().textContent ).toContain( 'Stale' );
		expect( getContainer().textContent ).toContain(
			'This navigation changed after the last request. Refresh before relying on the previous guidance.'
		);
		expect( getContainer().textContent ).toContain( 'Group utility links' );
		expect( getContainer().textContent ).not.toContain(
			'Navigation Recommendations'
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
			navigationContextSignature: JSON.stringify( {
				menuId: 42,
				navigationMarkup: initialMarkup,
			} ),
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
		expect( getContainer().textContent ).toContain( 'Navigation Block' );
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
				prompt: '  Simplify the header navigation.  ',
			} )
		).toEqual( {
			blockClientId: 'nav-1',
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
			navigationContextSignature: JSON.stringify( {
				menuId: 42,
				navigationMarkup: mobileMarkup,
			} ),
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
		expect( getContainer().textContent ).toContain( 'Navigation Block' );
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
			navigationContextSignature: JSON.stringify( {
				menuId: 42,
				navigationMarkup: '<!-- wp:navigation {"ref":42} /-->',
			} ),
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
