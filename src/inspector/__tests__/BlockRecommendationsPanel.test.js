const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockSuggestionChips = jest.fn();
const mockFetchBlockRecommendations = jest.fn();
const mockCollectBlockContext = jest.fn();
const mockClearBlockError = jest.fn();
const mockClearUndoError = jest.fn();
const mockUndoActivity = jest.fn();
const mockGetLatestAppliedActivity = jest.fn();
const mockGetLatestUndoableActivity = jest.fn();
const mockGetResolvedActivityEntries = jest.fn();
const mockGetBlockActivityUndoState = jest.fn();
const mockRenderAIActivitySection = jest.fn();

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
	getLiveBlockContextSignature: ( _select, clientId ) => {
		const context = mockCollectBlockContext( clientId );

		return context ? JSON.stringify( context ) : '';
	},
} ) );

jest.mock( '../../utils/block-recommendation-context', () => ( {
	buildBlockRecommendationContextSignature: ( context = {} ) =>
		JSON.stringify( context || {} ),
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

jest.mock( '../../components/AIActivitySection', () => ( props ) => {
	mockRenderAIActivitySection( props );
	return null;
} );
jest.mock( '../NavigationRecommendations', () => () => null );
jest.mock( '../SuggestionChips', () => ( props ) => {
	mockSuggestionChips( props );
	return null;
} );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import {
	BlockRecommendationsContent,
	BlockRecommendationsDocumentPanel,
} from '../BlockRecommendationsPanel';

const { getContainer, getRoot } = setupReactTest();

let currentState = null;
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
			blockContextSignatures: {},
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
			getBlockStatus: jest.fn(
				( clientId ) =>
					getState().store.blockStatuses[ clientId ] || 'idle'
			),
			getBlockRecommendationContextSignature: jest.fn(
				( clientId ) =>
					getState().store.blockContextSignatures[ clientId ] || null
			),
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
		getRoot().render( <BlockRecommendationsDocumentPanel /> );
	} );
}

function renderContent( clientId = 'block-1' ) {
	act( () => {
		getRoot().render(
			<BlockRecommendationsContent clientId={ clientId } />
		);
	} );
}

function getTextarea() {
	return getContainer().querySelector( 'textarea' );
}

beforeEach( () => {
	jest.clearAllMocks();
	mockSuggestionChips.mockReset();
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
} );

afterEach( () => {
	delete window.flavorAgentData;
	currentState = null;
} );

describe( 'BlockRecommendationsDocumentPanel', () => {
	test( 'renders the last selected block panel after selection clears', () => {
		renderPanel();
		expect( getContainer().textContent ).toBe( '' );

		currentState = createState( {
			blockEditor: {
				selectedBlockClientId: null,
			},
		} );

		renderPanel();

		expect( getContainer().textContent ).toContain( 'Last Selected Block' );
		expect( getContainer().textContent ).toContain( 'Get Suggestions' );
		expect(
			getContainer().querySelector(
				'[data-panel-title="AI Recommendations"]'
			)
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
			getContainer().querySelectorAll( 'button' )
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

		expect( getContainer().textContent ).toBe( '' );
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

		expect( getContainer().textContent ).toContain(
			'Settings > Flavor Agent'
		);
		expect( getContainer().textContent ).toContain(
			'Settings > Connectors'
		);
		expect( getContainer().textContent ).toContain(
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

		expect( getContainer().textContent ).toContain(
			'Applied Refresh hero copy.'
		);
		expect(
			mockRenderAIActivitySection.mock.calls[
				mockRenderAIActivitySection.mock.calls.length - 1
			][ 0 ]
		).toEqual(
			expect.objectContaining( {
				description:
					'Undo follows the same latest-valid-action rule used across every executable Flavor Agent surface.',
				entries: expect.any( Array ),
				resetKey: 'block-1',
			} )
		);

		const undoButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( element ) => element.textContent === 'Undo' );

		expect( undoButton ).toBeDefined();

		act( () => {
			undoButton.click();
		} );

		expect( mockUndoActivity ).toHaveBeenCalledWith( 'activity-1' );
	} );

	test( 'separates executable block suggestions from advisory structural ideas', () => {
		renderPanel();

		currentState = createState( {
			blockEditor: {
				selectedBlockClientId: null,
			},
			store: {
				blockRecommendations: {
					'block-1': {
						block: [
							{
								label: 'Hide on mobile',
								description:
									'Use viewport visibility for mobile.',
								attributeUpdates: {
									metadata: {
										blockVisibility: {
											viewport: {
												mobile: false,
											},
										},
									},
								},
							},
							{
								label: 'Wrap this block in a Group',
								description:
									'Use a Group parent to add spacing and background controls.',
								type: 'structural_recommendation',
							},
							{
								label: 'Replace with a callout pattern',
								description:
									'Swap to a richer callout pattern when stronger layout controls are needed.',
								type: 'pattern_replacement',
							},
						],
						blockContext: {
							name: 'plugin/plain-block',
						},
					},
				},
				blockContextSignatures: {
					'block-1': JSON.stringify( {
						block: {
							name: 'core/paragraph',
						},
					} ),
				},
				blockStatuses: {
					'block-1': 'ready',
				},
			},
		} );

		renderPanel();

		expect( getContainer().textContent ).toContain( 'Apply Now' );
		expect( getContainer().textContent ).toContain( 'Manual Ideas' );
		expect( getContainer().textContent ).toContain(
			'Recommended Next Step'
		);
		expect( getContainer().textContent ).toContain(
			'Wrap this block in a Group'
		);
		expect( getContainer().textContent ).toContain(
			'Replace with a callout pattern'
		);
		expect( getContainer().textContent ).not.toContain(
			'One-click apply stays available when Flavor Agent can safely change this block'
		);
		expect( getContainer().textContent ).not.toContain(
			'These ideas need manual follow-through or a broader preview/apply flow'
		);
		expect( mockSuggestionChips ).toHaveBeenCalledTimes( 1 );
		expect( mockSuggestionChips.mock.calls[ 0 ][ 0 ] ).toEqual(
			expect.objectContaining( {
				clientId: 'block-1',
				label: 'AI block suggestions',
				suggestions: [
					expect.objectContaining( {
						label: 'Hide on mobile',
					} ),
				],
			} )
		);
	} );

	test( 'keeps purely advisory block suggestions out of one-click chips', () => {
		renderPanel();

		currentState = createState( {
			blockEditor: {
				selectedBlockClientId: null,
			},
			store: {
				blockRecommendations: {
					'block-1': {
						block: [
							{
								label: 'Wrap this block in a Group',
								description:
									'Use a Group parent to unlock spacing and background controls.',
								type: 'structural_recommendation',
							},
						],
						blockContext: {
							name: 'plugin/plain-block',
						},
					},
				},
				blockContextSignatures: {
					'block-1': JSON.stringify( {
						block: {
							name: 'core/paragraph',
						},
					} ),
				},
				blockStatuses: {
					'block-1': 'ready',
				},
			},
		} );

		renderPanel();

		expect( getContainer().textContent ).toContain( 'Manual Ideas' );
		expect( getContainer().textContent ).toContain(
			'Wrap this block in a Group'
		);
		expect( mockSuggestionChips ).not.toHaveBeenCalled();
	} );

	test( 'keeps structural and pattern suggestions advisory even when they include safe local updates', () => {
		renderPanel();

		currentState = createState( {
			blockEditor: {
				selectedBlockClientId: null,
			},
			store: {
				blockRecommendations: {
					'block-1': {
						block: [
							{
								label: 'Wrap this block in a Group',
								description:
									'Use a Group parent to unlock spacing and background controls.',
								type: 'structural_recommendation',
								attributeUpdates: {
									metadata: {
										blockVisibility: {
											viewport: {
												mobile: false,
											},
										},
									},
								},
							},
							{
								label: 'Replace with a callout pattern',
								description:
									'Swap to a richer pattern with stronger layout affordances.',
								type: 'pattern_replacement',
								attributeUpdates: {
									className: 'is-style-outline',
								},
							},
						],
						blockContext: {
							name: 'plugin/plain-block',
						},
					},
				},
				blockContextSignatures: {
					'block-1': JSON.stringify( {
						block: {
							name: 'core/paragraph',
						},
					} ),
				},
				blockStatuses: {
					'block-1': 'ready',
				},
			},
		} );

		renderPanel();

		expect( getContainer().textContent ).toContain( 'Manual Ideas' );
		expect( getContainer().textContent ).toContain(
			'Wrap this block in a Group'
		);
		expect( getContainer().textContent ).toContain(
			'Replace with a callout pattern'
		);
		expect( mockSuggestionChips ).not.toHaveBeenCalled();
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

		expect( getContainer().textContent ).not.toContain(
			'Undid Refresh hero copy.'
		);
		expect(
			getContainer().querySelector( '[data-status-notice="true"]' )
		).toBeNull();
	} );

	test( 'shows the current block scope even before recommendations exist', () => {
		renderPanel();

		currentState = createState( {
			blockEditor: {
				selectedBlockClientId: null,
				blockLookup: {
					'block-1': {
						clientId: 'block-1',
						name: 'core/heading',
						attributes: {},
						innerBlocks: [],
					},
				},
				blocks: [
					{
						clientId: 'block-1',
						name: 'core/heading',
						attributes: {},
						innerBlocks: [],
					},
				],
			},
		} );

		renderPanel();

		expect( getContainer().textContent ).toContain( 'core/heading' );
		expect( getContainer().textContent ).not.toContain( 'Current' );
		expect( getContainer().textContent ).not.toContain( 'Stale' );
	} );

	test( 'marks stored block results stale and keeps them visible until the user refreshes', () => {
		currentState = createState( {
			store: {
				blockRecommendations: {
					'block-1': {
						block: [
							{
								label: 'Hide on mobile',
								attributeUpdates: {
									metadata: {
										blockVisibility: {
											viewport: {
												mobile: false,
											},
										},
									},
								},
							},
						],
						explanation: 'Use viewport visibility for mobile.',
					},
				},
				blockContextSignatures: {
					'block-1': JSON.stringify( {
						block: {
							name: 'core/paragraph',
						},
					} ),
				},
				blockStatuses: {
					'block-1': 'ready',
				},
			},
		} );
		mockCollectBlockContext.mockReturnValue( {
			block: {
				name: 'core/quote',
			},
		} );

		renderContent();

		expect( getContainer().textContent ).toContain( 'Stale' );
		expect( getContainer().textContent ).toContain(
			'This block changed after the last request.'
		);
		expect( getContainer().textContent ).toContain(
			'Refresh recommendations for the current block'
		);
		expect( mockSuggestionChips ).toHaveBeenCalledWith(
			expect.objectContaining( {
				suggestions: [
					expect.objectContaining( {
						label: 'Hide on mobile',
					} ),
				],
				disabled: true,
			} )
		);
	} );

	test( 'marks same-clientId block edits stale and refreshes against the updated live context', () => {
		const initialContext = {
			block: {
				name: 'core/paragraph',
				currentAttributes: {
					content: 'Original copy',
				},
			},
		};
		const updatedContext = {
			block: {
				name: 'core/paragraph',
				currentAttributes: {
					content: 'Updated copy',
				},
			},
		};

		currentState = createState( {
			store: {
				blockRecommendations: {
					'block-1': {
						block: [
							{
								label: 'Hide on mobile',
								attributeUpdates: {
									metadata: {
										blockVisibility: {
											viewport: {
												mobile: false,
											},
										},
									},
								},
							},
						],
						explanation: 'Use viewport visibility for mobile.',
					},
				},
				blockContextSignatures: {
					'block-1': JSON.stringify( initialContext ),
				},
				blockStatuses: {
					'block-1': 'ready',
				},
			},
		} );
		mockCollectBlockContext.mockReturnValue( initialContext );

		renderContent();

		expect( getContainer().textContent ).not.toContain( 'Stale' );

		mockCollectBlockContext.mockReturnValue( updatedContext );

		renderContent();

		expect( getContainer().textContent ).toContain( 'Stale' );
		expect( getContainer().textContent ).toContain(
			'This block changed after the last request.'
		);

		mockFetchBlockRecommendations.mockClear();

		const refreshButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( element ) => element.textContent === 'Refresh' );

		expect( refreshButton ).toBeDefined();

		act( () => {
			refreshButton.click();
		} );

		expect( mockFetchBlockRecommendations ).toHaveBeenCalledWith(
			'block-1',
			updatedContext,
			''
		);
	} );
} );
