const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockLoadActivitySession = jest.fn();

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

import ActivitySessionBootstrap from '../ActivitySessionBootstrap';

let container = null;
let root = null;
let currentEditorState = null;

window.IS_REACT_ACT_ENVIRONMENT = true;

beforeEach( () => {
	jest.clearAllMocks();
	currentEditorState = {
		postType: 'post',
		postId: null,
	};

	mockUseSelect.mockImplementation( ( mapSelect ) =>
		mapSelect( ( storeName ) => {
			if ( storeName === 'core/editor' ) {
				return {
					getCurrentPostType: () => currentEditorState.postType,
					getCurrentPostId: () => currentEditorState.postId,
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
} );
