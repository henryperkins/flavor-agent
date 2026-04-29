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
			surfaceStatusNotice: null,
			...overrides.store,
		},
	};
}

function getMockSurfaceStatusNotice( options = {} ) {
	if ( getState().store.surfaceStatusNotice ) {
		return getState().store.surfaceStatusNotice;
	}

	if ( options.requestError ) {
		return {
			message: options.requestError,
			tone: 'error',
			isDismissible: Boolean( options.onDismissAction ),
		};
	}

	if (
		options.hasResult &&
		! options.hasSuggestions &&
		options.emptyMessage
	) {
		return {
			message: options.emptyMessage,
			tone: 'info',
			isDismissible: false,
		};
	}

	return null;
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
			getSurfaceStatusNotice: jest.fn( ( surface, options ) =>
				getMockSurfaceStatusNotice( options )
			),
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
		expect( text ).toContain(
			'What should Flavor Agent do with this post?'
		);
		expect( text ).toContain(
			'Works from a title, short brief, or rough outline.'
		);

		// The composer label must be visually rendered (not hidden off-screen).
		const labelSpan = Array.from(
			getContainer().querySelectorAll( 'span' )
		).find(
			( span ) =>
				span.textContent ===
				'What should Flavor Agent do with this post?'
		);
		expect( labelSpan ).not.toBeUndefined();
		expect( labelSpan.style.position ).not.toBe( 'absolute' );
		expect( labelSpan.style.left ).not.toBe( '-9999px' );

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

		const modeButtons = Array.from(
			getContainer().querySelectorAll( 'button' )
		);
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
		expect( getContainer().textContent ).not.toContain(
			'Current document'
		);
	} );

	test( 'renders ready recommendations with draft copy, notes, issues, and activity', () => {
		currentState = createState( {
			store: {
				contentStatus: 'ready',
				contentMode: 'critique',
				contentRecommendation: {
					mode: 'critique',
					title: 'Retail floors to agent workflows',
					summary:
						'Lead with the concrete progression before the abstraction.',
					content:
						'Retail floors.\n\nWordPress themes.\n\nCloud platforms.',
					notes: [ 'Cut the throat-clearing in the opener.' ],
					issues: [
						{
							original: 'Technology is rapidly evolving.',
							problem: 'Too abstract.',
							revision:
								'WordPress changed. Cloud changed. The customer still needed the thing to work.',
						},
					],
				},
				activityLog: [
					{
						id: 'activity-content-1',
						type: 'request_diagnostic',
						surface: 'content',
						suggestion: 'Content recommendation request',
						request: {
							prompt: 'Stress-test the intro.',
						},
						undo: {
							status: 'review',
						},
					},
				],
			},
		} );

		act( () => {
			getRoot().render( <ContentRecommender /> );
		} );

		const text = getContainer().textContent;

		expect( text ).toContain( 'Latest Content Recommendation' );
		expect( text ).toContain( 'Retail floors to agent workflows' );
		expect( text ).toContain(
			'Lead with the concrete progression before the abstraction.'
		);
		expect( text ).toContain( 'Retail floors.' );
		expect( text ).toContain( 'WordPress themes.' );
		expect( text ).toContain( 'Cloud platforms.' );
		expect( text ).toContain( 'Editorial Notes' );
		expect( text ).toContain( 'Cut the throat-clearing in the opener.' );
		expect( text ).toContain( 'Technology is rapidly evolving.' );
		expect( text ).toContain( 'Too abstract.' );
		expect( text ).toContain(
			'WordPress changed. Cloud changed. The customer still needed the thing to work.'
		);
		expect( text ).toContain( 'Recent Content Requests' );

		const activityToggle = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) =>
			button.textContent.includes( 'Recent Content Requests' )
		);

		act( () => {
			activityToggle.click();
		} );

		expect( getContainer().textContent ).toContain(
			'Content recommendation request'
		);
	} );

	test( 'renders an empty-result status notice when the response has no usable output', () => {
		currentState = createState( {
			store: {
				contentStatus: 'ready',
				contentRecommendation: {
					mode: 'draft',
					title: '',
					summary: '',
					content: '',
					notes: [],
					issues: [],
				},
			},
		} );

		act( () => {
			getRoot().render( <ContentRecommender /> );
		} );

		expect( getContainer().textContent ).toContain(
			'No content recommendation was returned for the current request.'
		);
	} );

	test( 'renders a dismissible request error and clears it when dismissed', () => {
		currentState = createState( {
			store: {
				contentError: 'Content endpoint failed.',
				contentStatus: 'error',
			},
		} );

		act( () => {
			getRoot().render( <ContentRecommender /> );
		} );

		expect( getContainer().textContent ).toContain(
			'Content endpoint failed.'
		);

		const dismissButton = getContainer().querySelector(
			'button[data-dismiss="true"]'
		);

		act( () => {
			dismissButton.click();
		} );

		expect( mockClearContentError ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'renders provider capability guidance instead of request controls when unavailable', () => {
		window.flavorAgentData = {
			canRecommendContent: false,
			connectorsUrl:
				'https://example.test/wp-admin/options-connectors.php',
			capabilities: {
				surfaces: {
					content: {
						available: false,
						reason: 'plugin_provider_unconfigured',
					},
				},
			},
		};

		act( () => {
			getRoot().render( <ContentRecommender /> );
		} );

		const text = getContainer().textContent;

		expect( text ).toContain(
			'Content recommendations need a text-generation provider configured in Settings > Connectors.'
		);
		expect( text ).not.toContain( 'Generate Draft' );
		expect( getContainer().querySelector( 'textarea' ) ).toBeNull();
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
