const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockFetchBlockRecommendations = jest.fn();
const mockCollectBlockContext = jest.fn();

jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
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
		PanelBody: ( { children, title } ) =>
			createElement( 'section', { 'data-panel-title': title }, children ),
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
	getLatestAppliedActivity: jest.fn( () => null ),
	getLatestUndoableActivity: jest.fn( () => null ),
	getResolvedActivityEntries: jest.fn( ( entries ) => entries || [] ),
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
			getBlockRecommendations: jest.fn(
				( clientId ) =>
					getState().store.blockRecommendations[ clientId ] || null
			),
			getLastUndoneActivityId: jest.fn(
				() => getState().store.lastUndoneActivityId
			),
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
	mockUseDispatch.mockImplementation( () => ( {
		clearBlockError: jest.fn(),
		clearUndoError: jest.fn(),
		fetchBlockRecommendations: mockFetchBlockRecommendations,
		undoActivity: jest.fn(),
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
} );
