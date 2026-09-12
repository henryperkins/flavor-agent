const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockUseRegistry = jest.fn();
const mockSetActivitySession = jest.fn();
const mockLoadActivitySession = jest.fn();
const mockGetStyleBookUiState = jest.fn();
const mockSubscribeToStyleBookUi = jest.fn();
const mockInstallSaveObserver = jest.fn( () => () => {} );

jest.mock( '../../store/save-persistence', () => ( {
	...jest.requireActual( '../../store/save-persistence' ),
	installSavePersistenceObserver: ( ...args ) =>
		mockInstallSaveObserver( ...args ),
} ) );

jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );

	return Object.create( actual, {
		useDispatch: {
			enumerable: true,
			value: ( ...args ) => mockUseDispatch( ...args ),
		},
		useSelect: {
			enumerable: true,
			value: ( ...args ) => mockUseSelect( ...args ),
		},
		useRegistry: {
			enumerable: true,
			value: ( ...args ) => mockUseRegistry( ...args ),
		},
	} );
} );

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
import {
	readPersistedActivityLog,
	writePersistedActivityLog,
} from '../../store/activity-history';

const { getRoot } = setupReactTest();

let currentEditorState = null;
let currentInterfaceState = null;
let currentCoreState = null;
let styleBookUiSubscription = null;

beforeEach( () => {
	jest.clearAllMocks();
	window.sessionStorage.clear();
	mockUseRegistry.mockReturnValue( {
		select: () => ( {
			getActivityScopeKey: () => 'post:99',
			getActivityLog: () => [],
		} ),
	} );
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
	styleBookUiSubscription = null;
	mockSubscribeToStyleBookUi.mockImplementation(
		( documentRef, callback ) => {
			void documentRef;
			styleBookUiSubscription = callback;

			return () => {};
		}
	);

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
		setActivitySession: mockSetActivitySession,
	} ) );
} );

describe( 'ActivitySessionBootstrap', () => {
	test( 'merges server facts into the original scope after navigation without replacing the active scope', () => {
		writePersistedActivityLog( 'post:42', [
			{
				id: 'apply-42',
				type: 'apply_suggestion',
				surface: 'block',
				document: { scopeKey: 'post:42' },
				target: { clientId: 'block-42' },
			},
		] );
		act( () => {
			getRoot().render( <ActivitySessionBootstrap /> );
		} );
		const onReconcile =
			mockInstallSaveObserver.mock.calls[ 0 ][ 0 ].onReconcile;
		onReconcile( [
			{
				id: 'apply-42',
				document: { scopeKey: 'post:42' },
				persistenceVerdict: {
					state: 'save_confirmed',
					saveSequence: 2,
					label: 'Persisted',
				},
				requestStatus: {
					state: 'save_failed',
					label: 'Save request failed',
				},
				undoState: { state: 'undone', label: 'Undone in editor' },
			},
		] );
		expect( readPersistedActivityLog( 'post:42' )[ 0 ] ).toMatchObject( {
			target: { clientId: 'block-42' },
			persistenceVerdict: { state: 'save_confirmed' },
			requestStatus: { state: 'save_failed' },
			undoState: { state: 'undone' },
		} );
		expect( mockSetActivitySession ).not.toHaveBeenCalled();
	} );

	test( 'installs the editor-wide observer independently of the active document and keeps it through scope changes', () => {
		act( () => {
			getRoot().render( <ActivitySessionBootstrap /> );
		} );
		expect( mockInstallSaveObserver ).toHaveBeenCalledTimes( 1 );
		expect( mockInstallSaveObserver ).toHaveBeenCalledWith( {
			selectCore: expect.any( Function ),
			onReconcile: expect.any( Function ),
		} );
		currentEditorState = { postType: 'wp_template', postId: 'theme//home' };
		act( () => {
			getRoot().render( <ActivitySessionBootstrap /> );
		} );
		expect( mockInstallSaveObserver ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'does not subscribe to Style Book DOM outside the Styles sidebar', () => {
		act( () => {
			getRoot().render( <ActivitySessionBootstrap /> );
		} );

		expect( mockSubscribeToStyleBookUi ).not.toHaveBeenCalled();
	} );

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

	test( 'reloads the activity session when the Style Book metadata changes for the same scope key', () => {
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

		expect( mockLoadActivitySession ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				scope: expect.objectContaining( {
					key: 'style_book:17:core/paragraph',
					blockTitle: 'Paragraph',
				} ),
			} )
		);

		act( () => {
			styleBookUiSubscription( {
				isActive: true,
				target: {
					blockName: 'core/paragraph',
					blockTitle: 'Text',
				},
			} );
		} );

		expect( mockLoadActivitySession ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				scope: expect.objectContaining( {
					key: 'style_book:17:core/paragraph',
					blockTitle: 'Text',
				} ),
			} )
		);
	} );
} );
