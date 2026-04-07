const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockFetchContentRecommendations = jest.fn();
const mockSetContentMode = jest.fn();
const mockClearContentError = jest.fn();

jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

jest.mock( '@wordpress/data', () => ( {
	useDispatch: ( ...args ) => mockUseDispatch( ...args ),
	useSelect: ( ...args ) => mockUseSelect( ...args ),
} ) );

jest.mock( '@wordpress/editor', () => {
	const { createElement } = require( '@wordpress/element' );

	return {
		PluginDocumentSettingPanel: ( { children, title } ) =>
			createElement( 'section', { 'data-panel-title': title }, children ),
	};
} );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import ContentRecommender from '../ContentRecommender';

const { getContainer, getRoot } = setupReactTest();

let currentState = null;

function getState() {
	return currentState;
}

function createState( overrides = {} ) {
	return {
		editor: {
			postId: 42,
			postType: 'post',
			attributes: {
				title: 'Working draft',
				excerpt: '',
				content: 'Retail floors. WordPress themes.',
				slug: 'working-draft',
				status: 'draft',
			},
			...overrides.editor,
		},
		store: {
			activityLog: [],
			contentError: null,
			contentMode: 'draft',
			contentRecommendation: null,
			contentStatus: 'idle',
			...overrides.store,
		},
	};
}

function selectStore( storeName ) {
	if ( storeName === 'core/editor' ) {
		return {
			getCurrentPostId: jest.fn( () => getState().editor.postId ),
			getCurrentPostType: jest.fn( () => getState().editor.postType ),
			getEditedPostAttribute: jest.fn(
				( key ) => getState().editor.attributes[ key ]
			),
		};
	}

	if ( storeName === 'flavor-agent' ) {
		return {
			getActivityLog: jest.fn( () => getState().store.activityLog ),
			getContentError: jest.fn( () => getState().store.contentError ),
			getContentMode: jest.fn( () => getState().store.contentMode ),
			getContentRecommendation: jest.fn(
				() => getState().store.contentRecommendation
			),
			getContentStatus: jest.fn( () => getState().store.contentStatus ),
			getSurfaceStatusNotice: jest.fn( () => null ),
		};
	}

	return {};
}

beforeEach( () => {
	jest.clearAllMocks();
	currentState = createState();
	window.flavorAgentData = {
		canRecommendContent: true,
		capabilities: {
			surfaces: {
				content: {
					available: true,
					reason: 'ready',
				},
			},
		},
	};
	mockUseSelect.mockImplementation( ( mapSelect ) =>
		mapSelect( ( storeName ) => selectStore( storeName ) )
	);
	mockUseDispatch.mockReturnValue( {
		clearContentError: mockClearContentError,
		fetchContentRecommendations: mockFetchContentRecommendations,
		setContentMode: mockSetContentMode,
	} );
} );

describe( 'ContentRecommender', () => {
	test( 'renders on supported posts and sends current post context with requests', () => {
		act( () => {
			getRoot().render( <ContentRecommender /> );
		} );

		const text = getContainer().textContent;

		expect( text ).toContain( 'Post' );
		expect( text ).toContain( 'Working draft' );
		expect( text ).toContain( 'Start a fresh draft' );
		expect( text ).toContain( 'Generate Draft' );
		expect( text ).not.toContain( 'Current document' );

		const textarea = getContainer().querySelector( 'textarea' );
		act( () => {
			textarea.value = 'Tighten the opening.';
			textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );

		const fetchButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Generate Draft' );

		act( () => {
			fetchButton.click();
		} );

		expect( mockFetchContentRecommendations ).toHaveBeenCalledWith( {
			mode: 'draft',
			prompt: 'Tighten the opening.',
			postContext: {
				postType: 'post',
				title: 'Working draft',
				excerpt: '',
				content: 'Retail floors. WordPress themes.',
				slug: 'working-draft',
				status: 'draft',
			},
		} );
	} );

	test( 'uses the mode switcher instead of the old scope strip', () => {
		act( () => {
			getRoot().render( <ContentRecommender /> );
		} );

		const modeButtons = Array.from( getContainer().querySelectorAll( 'button' ) );
		const draftButton = modeButtons.find(
			( button ) => button.textContent === 'Draft'
		);
		const editButton = modeButtons.find(
			( button ) => button.textContent === 'Edit'
		);

		expect( draftButton.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
		expect( editButton.getAttribute( 'aria-pressed' ) ).toBe( 'false' );

		act( () => {
			editButton.click();
		} );

		expect( mockSetContentMode ).toHaveBeenCalledWith( 'edit' );
		expect( getContainer().textContent ).not.toContain( 'Current document' );
	} );

	test( 'does not render for unsupported editor entities', () => {
		currentState = createState( {
			editor: {
				postType: 'wp_template',
				postId: 'theme//home',
			},
		} );

		act( () => {
			getRoot().render( <ContentRecommender /> );
		} );

		expect( getContainer().textContent ).toBe( '' );
	} );
} );
