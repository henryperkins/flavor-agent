const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockLoadActivitySession = jest.fn();
const mockGetStyleBookUiState = jest.fn();
const mockSubscribeToStyleBookUi = jest.fn();

jest.mock( '@wordpress/data', () => ( {
	useDispatch: ( ...args ) => mockUseDispatch( ...args ),
	useSelect: ( ...args ) => mockUseSelect( ...args ),
} ) );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

jest.mock( '../../style-book/dom', () => ( {
	getStyleBookUiState: ( ...args ) => mockGetStyleBookUiState( ...args ),
	subscribeToStyleBookUi: ( ...args ) =>
		mockSubscribeToStyleBookUi( ...args ),
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createRoot } = require( '@wordpress/element' );

import ActivitySessionBootstrap from '../ActivitySessionBootstrap';

let container = null;
let root = null;
let currentEditorState = null;
let currentInterfaceState = null;
let currentCoreState = null;

window.IS_REACT_ACT_ENVIRONMENT = true;

beforeEach( () => {
	jest.clearAllMocks();
	currentEditorState = {
		postType: 'post',
		postId: null,
	};
	currentInterfaceState = {
		activeComplementaryArea: '',
	};
	currentCoreState = {
		globalStylesId: null,
	};
	mockGetStyleBookUiState.mockReturnValue( {
		isActive: false,
		target: null,
	} );
	mockSubscribeToStyleBookUi.mockImplementation( () => () => {} );

	mockUseSelect.mockImplementation( ( mapSelect ) =>
		mapSelect( ( storeName ) => {
			if ( storeName === 'core/editor' ) {
				return {
					getCurrentPostType: () => currentEditorState.postType,
					getCurrentPostId: () => currentEditorState.postId,
				};
			}

			if ( storeName === 'core/interface' ) {
				return {
					getActiveComplementaryArea: () =>
						currentInterfaceState.activeComplementaryArea,
				};
			}

			if ( storeName === 'core' ) {
				return {
					__experimentalGetCurrentGlobalStylesId: () =>
						currentCoreState.globalStylesId,
				};
			}

			return {};
		} )
	);
	mockUseDispatch.mockImplementation( () => ( {
		loadActivitySession: mockLoadActivitySession,
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
} );

describe( 'ActivitySessionBootstrap', () => {
	test( 'only enables unsaved activity migration on an in-place unsaved-to-saved transition', () => {
		act( () => {
			root.render( <ActivitySessionBootstrap /> );
		} );

		expect( mockLoadActivitySession ).toHaveBeenCalledWith( {
			allowUnsavedMigration: false,
		} );

		currentEditorState = {
			postType: 'post',
			postId: 42,
		};

		act( () => {
			root.render( <ActivitySessionBootstrap /> );
		} );

		expect( mockLoadActivitySession ).toHaveBeenLastCalledWith( {
			allowUnsavedMigration: true,
		} );

		currentEditorState = {
			postType: 'post',
			postId: 99,
		};

		act( () => {
			root.render( <ActivitySessionBootstrap /> );
		} );

		expect( mockLoadActivitySession ).toHaveBeenLastCalledWith( {
			allowUnsavedMigration: false,
		} );
	} );

	test( 'switches to the explicit global styles scope when the Styles sidebar is active', () => {
		currentInterfaceState = {
			activeComplementaryArea: 'edit-site/global-styles',
		};
		currentCoreState = {
			globalStylesId: '17',
		};

		act( () => {
			root.render( <ActivitySessionBootstrap /> );
		} );

		expect( mockLoadActivitySession ).toHaveBeenCalledWith( {
			allowUnsavedMigration: false,
		} );
	} );

	test( 'switches to a style-book scoped session when the Style Book target is active', () => {
		currentInterfaceState = {
			activeComplementaryArea: 'edit-site/global-styles',
		};
		currentCoreState = {
			globalStylesId: '17',
		};
		mockGetStyleBookUiState.mockReturnValue( {
			isActive: true,
			target: {
				blockName: 'core/paragraph',
				blockTitle: 'Paragraph',
			},
		} );

		act( () => {
			root.render( <ActivitySessionBootstrap /> );
		} );

		expect( mockLoadActivitySession ).toHaveBeenCalledWith( {
			allowUnsavedMigration: false,
		} );
	} );
} );
