const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockFetchBlockRecommendations = jest.fn();
const mockCollectBlockContext = jest.fn();
const mockClearBlockError = jest.fn();
const mockClearUndoError = jest.fn();
const mockUndoActivity = jest.fn();
const mockGetLatestAppliedActivity = jest.fn();
const mockGetLatestUndoableActivity = jest.fn();
const mockGetResolvedActivityEntries = jest.fn();
const mockGetBlockActivityUndoState = jest.fn();

jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );

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
			createElement( 'aside', { 'data-panel-title': title }, children ),
	};
} );

jest.mock( '@wordpress/icons', () => ( {
	starFilled: 'star-filled',
} ) );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

jest.mock( '../../context/collector', () => ( {
	collectBlockContext: ( ...args ) => mockCollectBlockContext( ...args ),
} ) );

jest.mock( '../../store/activity-history', () => ( {
	getBlockActivityUndoState: ( ...args ) =>
		mockGetBlockActivityUndoState( ...args ),
	getLatestAppliedActivity: ( ...args ) =>
		mockGetLatestAppliedActivity( ...args ),
	getLatestUndoableActivity: ( ...args ) =>
		mockGetLatestUndoableActivity( ...args ),
	getResolvedActivityEntries: ( ...args ) =>
		mockGetResolvedActivityEntries( ...args ),
} ) );

jest.mock( '../../components/AIActivitySection', () => () => null );
jest.mock( '../NavigationRecommendations', () => () => null );
jest.mock( '../SuggestionChips', () => () => null );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createRoot } = require( '@wordpress/element' );

import { BlockRecommendationsDocumentPanel } from '../BlockRecommendationsPanel';

let currentState = null;
let container = null;
let root = null;

window.IS_REACT_ACT_ENVIRONMENT = true;

function getState() {
	return currentState;
}

function createState( overrides = {} ) {
	return {
		blockEditor: {
			selectedBlockClientId: 'block-1',
			blocks: [
				{
					clientId: 'block-1',
					name: 'core/paragraph',
					attributes: {},
					innerBlocks: [],
				},
			],
			blockLookup: {
				'block-1': {
					clientId: 'block-1',
					name: 'core/paragraph',
					attributes: {},
					innerBlocks: [],
				},
			},
			editingModes: {},
			blockParents: {},
			...overrides.blockEditor,
		},
		store: {
			activityLog: [],
			blockErrors: {},
			blockRecommendations: {},
			blockStatuses: {},
			lastUndoneActivityId: null,
			undoError: null,
			undoStatus: 'idle',
			...overrides.store,
		},
	};
}

function selectStore( storeName ) {
	if ( storeName === 'core/block-editor' ) {
		return {
			getBlock: jest.fn(
				( clientId ) =>
					getState().blockEditor.blockLookup[ clientId ] || null
			),
			getBlockEditingMode: jest.fn(
				( clientId ) =>
					getState().blockEditor.editingModes[ clientId ] || 'default'
			),
			getBlockParents: jest.fn(
				( clientId ) =>
					getState().blockEditor.blockParents[ clientId ] || []
			),
			getBlocks: jest.fn( () => getState().blockEditor.blocks ),
			getSelectedBlockClientId: jest.fn(
				() => getState().blockEditor.selectedBlockClientId
			),
		};
	}

	if ( storeName === 'flavor-agent' ) {
		return {
			getActivityLog: jest.fn( () => getState().store.activityLog ),
			getBlockError: jest.fn(
				( clientId ) => getState().store.blockErrors[ clientId ] || null
			),
			getBlockInteractionState: jest.fn( () => 'idle' ),
			getBlockRecommendations: jest.fn(
				( clientId ) =>
					getState().store.blockRecommendations[ clientId ] || null
			),
			getLastUndoneActivityId: jest.fn(
				() => getState().store.lastUndoneActivityId
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

				if ( options.undoError ) {
					return {
						source: 'undo',
						tone: 'error',
						message: options.undoError,
						isDismissible: true,
					};
				}

				if ( options.applySuccessMessage ) {
					return {
						source: 'apply',
						tone: 'success',
						message: options.applySuccessMessage,
						actionType: 'undo',
						actionLabel: 'Undo',
					};
				}

				if ( options.undoSuccessMessage ) {
					return {
						source: 'undo',
						tone: 'success',
						message: options.undoSuccessMessage,
					};
				}

				return null;
			} ),
			getUndoError: jest.fn( () => getState().store.undoError ),
			getUndoStatus: jest.fn( () => getState().store.undoStatus ),
			isBlockLoading: jest.fn(
				( clientId ) =>
					getState().store.blockStatuses[ clientId ] === 'loading'
			),
		};
	}

	return {};
}

function renderPanel() {
	act( () => {
		root.render( <BlockRecommendationsDocumentPanel /> );
	} );
}

function getTextarea() {
	return container.querySelector( 'textarea' );
}

beforeEach( () => {
	jest.clearAllMocks();
	currentState = createState();
	window.flavorAgentData = {
		canRecommendBlocks: true,
	};
	mockCollectBlockContext.mockReturnValue( {
		block: {
			name: 'core/paragraph',
		},
	} );
	mockGetResolvedActivityEntries.mockImplementation(
		( entries ) => entries || []
	);
	mockGetBlockActivityUndoState.mockImplementation(
		( entry ) => entry?.undo || {}
	);
	mockGetLatestAppliedActivity.mockImplementation(
		( entries ) => entries?.[ entries.length - 1 ] || null
	);
	mockGetLatestUndoableActivity.mockImplementation(
		( entries ) =>
			[ ...( entries || [] ) ]
				.reverse()
				.find( ( entry ) => entry?.undo?.canUndo ) || null
	);
	mockUseDispatch.mockImplementation( () => ( {
		clearBlockError: mockClearBlockError,
		clearUndoError: mockClearUndoError,
		fetchBlockRecommendations: mockFetchBlockRecommendations,
		undoActivity: mockUndoActivity,
	} ) );
	mockUseSelect.mockImplementation( ( mapSelect ) =>
		mapSelect( selectStore )
	);
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
} );

afterEach( () => {
	delete window.flavorAgentData;
	act( () => {
		root.unmount();
	} );
	container.remove();
	root = null;
	container = null;
	currentState = null;
} );

describe( 'BlockRecommendationsDocumentPanel', () => {
	test( 'renders the last selected block panel after selection clears', () => {
		renderPanel();
		expect( container.textContent ).toBe( '' );

		currentState = createState( {
			blockEditor: {
				selectedBlockClientId: null,
			},
		} );

		renderPanel();

		expect( container.textContent ).toContain( 'Last Selected Block' );
		expect( container.textContent ).toContain( 'Get Suggestions' );
		expect(
			container.querySelector( '[data-panel-title="AI Recommendations"]' )
		).not.toBeNull();
	} );

	test( 'fetches block recommendations for the remembered block after save clears selection', () => {
		renderPanel();

		currentState = createState( {
			blockEditor: {
				selectedBlockClientId: null,
			},
		} );

		renderPanel();

		const textarea = getTextarea();
		const descriptor = Object.getOwnPropertyDescriptor(
			window.HTMLTextAreaElement.prototype,
			'value'
		);

		act( () => {
			descriptor.set.call( textarea, 'Tighten the hero copy.' );
			textarea.dispatchEvent(
				new window.Event( 'input', { bubbles: true } )
			);
		} );

		const button = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( element ) => element.textContent === 'Get Suggestions' );

		act( () => {
			button.click();
		} );

		expect( mockCollectBlockContext ).toHaveBeenCalledWith( 'block-1' );
		expect( mockFetchBlockRecommendations ).toHaveBeenCalledWith(
			'block-1',
			{
				block: {
					name: 'core/paragraph',
				},
			},
			'Tighten the hero copy.'
		);
	} );

	test( 'does not render when the remembered block is no longer present', () => {
		renderPanel();

		currentState = createState( {
			blockEditor: {
				selectedBlockClientId: null,
				blockLookup: {},
				blocks: [],
			},
		} );

		renderPanel();

		expect( container.textContent ).toBe( '' );
	} );

	test( 'shows the shared capability notice when block recommendations are unavailable', () => {
		window.flavorAgentData = {
			canRecommendBlocks: false,
			settingsUrl:
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
			connectorsUrl:
				'https://example.test/wp-admin/options-connectors.php',
		};

		renderPanel();

		currentState = createState( {
			blockEditor: {
				selectedBlockClientId: null,
			},
		} );

		renderPanel();

		expect( container.textContent ).toContain( 'Settings > Flavor Agent' );
		expect( container.textContent ).toContain( 'Settings > Connectors' );
		expect( container.textContent ).toContain(
			'Configure Azure OpenAI or OpenAI Native in Settings > Flavor Agent'
		);
	} );

	test( 'shows an undo action on apply success notices and dispatches undo for the latest block activity', () => {
		currentState = createState( {
			store: {
				activityLog: [
					{
						id: 'activity-1',
						surface: 'block',
						suggestion: 'Refresh hero copy',
						target: {
							clientId: 'block-1',
						},
						undo: {
							canUndo: true,
							status: 'available',
							error: null,
						},
					},
				],
			},
		} );

		renderPanel();
		currentState = createState( {
			blockEditor: {
				selectedBlockClientId: null,
			},
			store: {
				activityLog: [
					{
						id: 'activity-1',
						surface: 'block',
						suggestion: 'Refresh hero copy',
						target: {
							clientId: 'block-1',
						},
						undo: {
							canUndo: true,
							status: 'available',
							error: null,
						},
					},
				],
			},
		} );
		renderPanel();

		expect( container.textContent ).toContain(
			'Applied Refresh hero copy.'
		);

		const undoButton = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( element ) => element.textContent === 'Undo' );

		expect( undoButton ).toBeDefined();

		act( () => {
			undoButton.click();
		} );

		expect( mockUndoActivity ).toHaveBeenCalledWith( 'activity-1' );
	} );

	test( 'does not keep an undo success notice once the resolved entry is no longer undone', () => {
		mockGetLatestAppliedActivity.mockReturnValue( null );
		mockGetLatestUndoableActivity.mockReturnValue( null );
		currentState = createState( {
			store: {
				activityLog: [
					{
						id: 'activity-1',
						surface: 'block',
						suggestion: 'Refresh hero copy',
						target: {
							clientId: 'block-1',
						},
						undo: {
							canUndo: true,
							status: 'available',
							error: null,
						},
					},
				],
				lastUndoneActivityId: 'activity-1',
				undoStatus: 'success',
			},
		} );

		renderPanel();

		expect( container.textContent ).not.toContain(
			'Undid Refresh hero copy.'
		);
		expect(
			container.querySelector( '[data-status-notice="true"]' )
		).toBeNull();
	} );
} );
