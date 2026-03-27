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

jest.mock( '@wordpress/components', () => {
	const { createElement } = require( '@wordpress/element' );

	return {
		Button: ( { children, className, disabled, onClick } ) =>
			createElement(
				'button',
				{
					type: 'button',
					className,
					disabled,
					onClick,
				},
				children
			),
		Notice: ( { children } ) =>
			createElement( 'div', { role: 'alert' }, children ),
		TextareaControl: ( { label, onChange, placeholder, rows, value } ) =>
			createElement(
				'label',
				null,
				createElement( 'span', null, label ),
				createElement( 'textarea', {
					'aria-label': label,
					rows,
					placeholder,
					value,
					onChange: ( event ) => onChange( event.target.value ),
				} )
			),
	};
} );

jest.mock( '@wordpress/data', () => ( {
	useDispatch: ( ...args ) => mockUseDispatch( ...args ),
	useSelect: ( ...args ) => mockUseSelect( ...args ),
} ) );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createRoot } = require( '@wordpress/element' );

import NavigationRecommendations, {
	buildNavigationFetchInput,
} from '../NavigationRecommendations';

let currentState = null;
let container = null;
let root = null;

window.IS_REACT_ACT_ENVIRONMENT = true;

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
			getNavigationBlockClientId: jest.fn(
				() => getState().store.navigationBlockClientId
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
		root.render( <NavigationRecommendations clientId={ clientId } /> );
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

	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
} );

afterEach( () => {
	act( () => {
		root.unmount();
	} );
	container.remove();
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

		expect( container.textContent ).toBe( '' );
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

		expect( container.textContent ).toContain(
			'Navigation recommendations require the edit_theme_options capability.'
		);
		expect( container.textContent ).not.toContain(
			'Settings > Flavor Agent'
		);
		expect( container.textContent ).not.toContain(
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
			container.querySelectorAll( 'button' )
		).find(
			( element ) => element.textContent === 'Get Navigation Suggestions'
		);

		act( () => {
			button.click();
		} );

		expect( mockSerialize ).toHaveBeenCalledWith( [
			currentState.blockEditor.blocks[ 'nav-1' ],
		] );
		expect( mockFetchNavigationRecommendations ).toHaveBeenCalledWith( {
			blockClientId: 'nav-1',
			menuId: 42,
			navigationMarkup:
				'<!-- wp:navigation {"ref":42,"overlayMenu":"mobile"} --><!-- wp:navigation-link {"label":"Home","url":"/"} /--><!-- /wp:navigation -->',
		} );
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
			container.querySelectorAll( 'button' )
		).find(
			( element ) => element.textContent === 'Get Navigation Suggestions'
		);

		act( () => {
			button.click();
		} );

		expect( mockSerialize ).toHaveBeenCalledWith( [
			currentState.blockEditor.blocks[ 'nav-1' ],
		] );
		expect( mockFetchNavigationRecommendations ).toHaveBeenCalledWith( {
			blockClientId: 'nav-1',
			menuId: 42,
			navigationMarkup:
				'<!-- wp:navigation {"ref":42,"overlayMenu":"always"} /-->',
		} );
	} );

	test( 'clears stale navigation recommendations when the selected block changes in place', () => {
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
		};
		mockSerialize.mockImplementation( ( blocks ) =>
			blocks?.[ 0 ]?.innerBlocks?.length === 1
				? '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Home"} /--><!-- /wp:navigation -->'
				: '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Home"} /--><!-- wp:navigation-link {"label":"Contact"} /--><!-- /wp:navigation -->'
		);

		renderComponent();

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

		renderComponent();

		expect( mockClearNavigationRecommendations ).toHaveBeenCalledTimes( 1 );
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

	test( 'clears stale navigation recommendations when only referenced-menu attributes change', () => {
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
		};
		mockSerialize.mockImplementation( ( blocks ) =>
			blocks?.[ 0 ]?.attributes?.overlayMenu === 'mobile'
				? '<!-- wp:navigation {"ref":42,"overlayMenu":"mobile"} /-->'
				: '<!-- wp:navigation {"ref":42,"overlayMenu":"always"} /-->'
		);

		renderComponent();

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

		renderComponent();

		expect( mockClearNavigationRecommendations ).toHaveBeenCalledTimes( 1 );
	} );
} );
