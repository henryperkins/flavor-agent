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
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import ActivitySessionBootstrap from '../ActivitySessionBootstrap';

const { getContainer, getRoot } = setupReactTest();

let currentEditorState = null;
let currentInterfaceState = null;
let currentCoreState = null;

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
} );

describe( 'ActivitySessionBootstrap', () => {
	test( 'only enables unsaved activity migration on an in-place unsaved-to-saved transition', () => {
		act( () => {
			getRoot().render( <ActivitySessionBootstrap /> );
		} );

		expect( mockLoadActivitySession ).toHaveBeenCalledWith( {
			allowUnsavedMigration: false,
			scope: expect.objectContaining( {
				postType: 'post',
				hint: 'post:__unsaved__',
			} ),
		} );

		currentEditorState = {
			postType: 'post',
			postId: 42,
		};

		act( () => {
			getRoot().render( <ActivitySessionBootstrap /> );
		} );

		expect( mockLoadActivitySession ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				allowUnsavedMigration: true,
				scope: expect.objectContaining( {
					key: 'post:42',
					entityId: '42',
				} ),
			} )
		);

		currentEditorState = {
			postType: 'post',
			postId: 99,
		};

		act( () => {
			getRoot().render( <ActivitySessionBootstrap /> );
		} );

		expect( mockLoadActivitySession ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				allowUnsavedMigration: false,
				scope: expect.objectContaining( {
					key: 'post:99',
					entityId: '99',
				} ),
			} )
		);
	} );

	test( 'switches to the explicit global styles scope when the Styles sidebar is active', () => {
		currentInterfaceState = {
			activeComplementaryArea: 'edit-site/global-styles',
		};
		currentCoreState = {
			globalStylesId: '17',
		};

		act( () => {
			getRoot().render( <ActivitySessionBootstrap /> );
		} );

		expect( mockLoadActivitySession ).toHaveBeenCalledWith(
			expect.objectContaining( {
				allowUnsavedMigration: false,
				scope: expect.objectContaining( {
					key: 'global_styles:17',
					entityId: '17',
				} ),
			} )
		);
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
			getRoot().render( <ActivitySessionBootstrap /> );
		} );

		expect( mockLoadActivitySession ).toHaveBeenCalledWith(
			expect.objectContaining( {
				allowUnsavedMigration: false,
				scope: expect.objectContaining( {
					key: 'style_book:17:core/paragraph',
					globalStylesId: '17',
					blockName: 'core/paragraph',
				} ),
			} )
		);
	} );
} );
