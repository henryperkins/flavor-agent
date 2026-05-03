const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockSuggestionChips = jest.fn();
const mockFetchBlockRecommendations = jest.fn();
const mockApplyBlockStructuralSuggestion = jest.fn();
const mockRevalidateBlockReviewFreshness = jest.fn();
const mockCollectBlockContext = jest.fn();
const mockClearBlockError = jest.fn();
const mockClearUndoError = jest.fn();
const mockUndoActivity = jest.fn();
const mockGetLatestAppliedActivity = jest.fn();
const mockGetLatestUndoableActivity = jest.fn();
const mockGetResolvedActivityEntries = jest.fn();
const mockGetBlockActivityUndoState = jest.fn();
const mockRenderAIActivitySection = jest.fn();
const mockRenderNavigationRecommendations = jest.fn();
let mockShouldRenderNavigationRecommendations = false;
const DOCUMENT_POSITION_FOLLOWING = 4;

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
jest.mock( '../NavigationRecommendations', () => ( props ) => {
	mockRenderNavigationRecommendations( props );

	if ( ! mockShouldRenderNavigationRecommendations ) {
		return null;
	}

	return (
		<div
			data-navigation-recommendations={
				props.embedded ? 'embedded' : 'full'
			}
		>
			{ props.embedded
				? 'Embedded Navigation Recommendations'
				: 'Navigation Recommendations' }
		</div>
	);
} );
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
			blockApplyErrors: {},
			blockErrors: {},
			blockRecommendations: {},
			blockContextSignatures: {},
			blockResolvedContextSignatures: {},
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
			getBlockApplyError: jest.fn(
				( clientId ) =>
					getState().store.blockApplyErrors[ clientId ] || null
			),
			getBlockApplyStatus: jest.fn(
				( clientId ) =>
					getState().store.blockApplyStatuses?.[ clientId ] || 'idle'
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
			getBlockRequestDiagnostics: jest.fn(
				( clientId ) =>
					getState().store.blockRequestDiagnostics?.[ clientId ] ||
					null
			),
			getBlockRequestToken: jest.fn(
				( clientId ) =>
					getState().store.blockRequestTokens?.[ clientId ] || 0
			),
			getBlockResolvedContextSignature: jest.fn(
				( clientId ) =>
					getState().store.blockResolvedContextSignatures?.[
						clientId
					] || null
			),
			getBlockStaleReason: jest.fn(
				( clientId ) =>
					getState().store.blockStaleReasons?.[ clientId ] || null
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

				if ( options.applyError ) {
					return {
						source: 'apply',
						tone: 'error',
						message: options.applyError,
						isDismissible: true,
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
	jest.useFakeTimers();
	mockSuggestionChips.mockReset();
	mockShouldRenderNavigationRecommendations = false;
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
		applyBlockStructuralSuggestion: mockApplyBlockStructuralSuggestion,
		fetchBlockRecommendations: mockFetchBlockRecommendations,
		revalidateBlockReviewFreshness: mockRevalidateBlockReviewFreshness,
		undoActivity: mockUndoActivity,
	} ) );
	mockUseSelect.mockImplementation( ( mapSelect ) =>
		mapSelect( selectStore )
	);
} );

afterEach( () => {
	jest.clearAllTimers();
	jest.useRealTimers();
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
		expect( getContainer().textContent ).toContain(
			'Saving cleared block selection. Flavor Agent stays scoped to the last block you selected until you choose another block.'
		);
		expect( getContainer().textContent ).toContain(
			'Flavor Agent keeps one-click apply limited to safe local block attribute changes.'
		);
		expect( getContainer().textContent ).toContain( 'Get Suggestions' );
		expect(
			getContainer().querySelector(
				'[data-panel-title="AI Recommendations"]'
			)
		).not.toBeNull();
	} );

	test( 'revalidates ready last-selected block results in the document panel', () => {
		const context = {
			block: {
				name: 'core/paragraph',
			},
		};
		const contextSignature = JSON.stringify( context );

		currentState = createState( {
			store: {
				blockRecommendations: {
					'block-1': {
						block: [ { label: 'Tighten copy' } ],
						prompt: 'Improve this block.',
					},
				},
				blockContextSignatures: {
					'block-1': contextSignature,
				},
				blockRequestTokens: {
					'block-1': 7,
				},
				blockStatuses: {
					'block-1': 'ready',
				},
			},
		} );
		mockCollectBlockContext.mockReturnValue( context );

		renderPanel();
		currentState = createState( {
			blockEditor: {
				selectedBlockClientId: null,
			},
			store: currentState.store,
		} );

		renderPanel();

		expect( mockRevalidateBlockReviewFreshness ).not.toHaveBeenCalled();

		act( () => {
			jest.advanceTimersByTime( 300 );
		} );

		expect( mockRevalidateBlockReviewFreshness ).toHaveBeenCalledWith(
			'block-1',
			{
				clientId: 'block-1',
				editorContext: context,
				contextSignature,
				prompt: 'Improve this block.',
			},
			expect.objectContaining( {
				requestToken: 7,
				requestSignature: expect.any( String ),
			} )
		);
	} );

	test( 'does not background revalidate already client-stale block results', () => {
		const storedContext = {
			block: {
				name: 'core/paragraph',
			},
		};
		const liveContext = {
			block: {
				name: 'core/quote',
			},
		};

		currentState = createState( {
			store: {
				blockRecommendations: {
					'block-1': {
						block: [ { label: 'Tighten copy' } ],
						prompt: 'Improve this block.',
					},
				},
				blockContextSignatures: {
					'block-1': JSON.stringify( storedContext ),
				},
				blockStatuses: {
					'block-1': 'ready',
				},
			},
		} );
		mockCollectBlockContext.mockReturnValue( liveContext );

		renderContent();

		act( () => {
			jest.advanceTimersByTime( 300 );
		} );

		expect( mockRevalidateBlockReviewFreshness ).not.toHaveBeenCalled();
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
			'Settings > Connectors'
		);
		expect( getContainer().textContent ).toContain(
			'Configure a text-generation provider in Settings > Connectors'
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

		expect( getContainer().textContent ).toContain( 'Apply now' );
		expect( getContainer().textContent ).toContain( 'Manual ideas' );
		expect( getContainer().textContent ).toContain( 'Advisory only' );
		expect( getContainer().textContent ).toContain( 'Inline-safe' );
		expect( getContainer().textContent ).toContain( 'Advisory' );
		expect( getContainer().textContent ).toContain( 'Validator computed' );
		expect( getContainer().textContent ).toContain(
			'Eligibility blockers: Unsupported operation.'
		);
		expect( getContainer().textContent ).toContain(
			'Eligibility blockers: Missing pattern context.'
		);
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
				currentRequestInput: expect.objectContaining( {
					clientId: 'block-1',
					editorContext: expect.objectContaining( {
						block: expect.objectContaining( {
							name: 'core/paragraph',
						} ),
					} ),
				} ),
				currentRequestSignature: expect.any( String ),
				label: 'AI block suggestions',
				suggestions: [
					expect.objectContaining( {
						label: 'Hide on mobile',
						eligibility: expect.objectContaining( {
							source: 'validator',
							tier: 'inline-safe',
						} ),
					} ),
				],
			} )
		);
	} );

	test( 'renders settings and style suggestions as executable lanes in the main panel', () => {
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
						],
						settings: [
							{
								label: 'Pin block',
								panel: 'position',
							},
						],
						styles: [
							{
								label: 'Use accent color',
								panel: 'color',
							},
						],
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

		expect( getContainer().textContent ).toContain(
			'Settings suggestions'
		);
		expect( getContainer().textContent ).toContain( 'Style suggestions' );
		expect( mockSuggestionChips ).toHaveBeenCalledTimes( 3 );
		expect( mockSuggestionChips.mock.calls[ 0 ][ 0 ] ).toEqual(
			expect.objectContaining( {
				label: 'AI block suggestions',
				suggestions: [
					expect.objectContaining( {
						label: 'Hide on mobile',
					} ),
				],
			} )
		);
		expect( mockSuggestionChips.mock.calls[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( {
				label: 'AI settings suggestions',
				suggestions: [
					expect.objectContaining( {
						label: 'Pin block',
					} ),
				],
			} )
		);
		expect( mockSuggestionChips.mock.calls[ 2 ][ 0 ] ).toEqual(
			expect.objectContaining( {
				label: 'AI style suggestions',
				suggestions: [
					expect.objectContaining( {
						label: 'Use accent color',
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

		expect( getContainer().textContent ).toContain( 'Manual ideas' );
		expect( getContainer().textContent ).toContain( 'Advisory only' );
		expect( getContainer().textContent ).toContain( 'Validator computed' );
		expect( getContainer().textContent ).toContain(
			'Eligibility blockers: Unsupported operation.'
		);
		expect( getContainer().textContent ).toContain(
			'Wrap this block in a Group'
		);
		expect( mockSuggestionChips ).not.toHaveBeenCalled();
	} );

	test( 'keeps structural and pattern suggestions advisory even when they include safe local updates', () => {
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

		renderContent();

		expect( getContainer().textContent ).toContain( 'Manual ideas' );
		expect( getContainer().textContent ).toContain( 'Advisory only' );
		expect( getContainer().textContent ).toContain( 'Validator computed' );
		expect( getContainer().textContent ).toContain(
			'Eligibility blockers: Unsupported operation.'
		);
		expect( getContainer().textContent ).toContain(
			'Eligibility blockers: Missing pattern context.'
		);
		expect( getContainer().textContent ).toContain(
			'Wrap this block in a Group'
		);
		expect( getContainer().textContent ).toContain(
			'Replace with a callout pattern'
		);
		expect( mockSuggestionChips ).not.toHaveBeenCalled();
	} );

	test( 'renders review-safe structural operations with a guarded review apply control', () => {
		window.flavorAgentData = {
			canRecommendBlocks: true,
			enableBlockStructuralActions: true,
		};
		const liveContext = {
			block: {
				name: 'core/group',
			},
		};
		const contextSignature = JSON.stringify( liveContext );
		const operation = {
			catalogVersion: 1,
			type: 'insert_pattern',
			patternName: 'theme/hero',
			targetClientId: 'block-1',
			targetSignature: 'target-sig',
			targetType: 'block',
			expectedTarget: {
				clientId: 'block-1',
				name: 'core/group',
			},
			position: 'insert_after',
		};

		mockCollectBlockContext.mockReturnValue( liveContext );
		currentState = createState( {
			blockEditor: {
				selectedBlockClientId: null,
				blockLookup: {
					'block-1': {
						clientId: 'block-1',
						name: 'core/group',
						attributes: {},
						innerBlocks: [],
					},
				},
				blocks: [
					{
						clientId: 'block-1',
						name: 'core/group',
						attributes: {},
						innerBlocks: [],
					},
				],
			},
			store: {
				blockRecommendations: {
					'block-1': {
						block: [
							{
								label: 'Add a hero pattern after this group',
								description:
									'Use a stronger CTA section after the selected group.',
								type: 'pattern_replacement',
								operations: [ operation ],
								proposedOperations: [ operation ],
								rejectedOperations: [],
							},
						],
						blockContext: {
							name: 'core/group',
							blockOperationContext: {
								enableBlockStructuralActions: true,
								targetClientId: 'block-1',
								targetBlockName: 'core/group',
								targetSignature: 'target-sig',
								isTargetLocked: false,
								isContentOnly: false,
								allowedPatterns: [
									{
										name: 'theme/hero',
										title: 'Hero',
										allowedActions: [ 'insert_after' ],
									},
								],
							},
						},
					},
				},
				blockContextSignatures: {
					'block-1': contextSignature,
				},
				blockRequestTokens: {
					'block-1': 7,
				},
				blockStatuses: {
					'block-1': 'ready',
				},
			},
		} );

		renderContent();

		expect( getContainer().textContent ).toContain( 'Review first' );
		expect( getContainer().textContent ).toContain( 'Review-safe' );
		expect( getContainer().textContent ).toContain(
			'Validated structural operations require review before apply.'
		);
		expect( getContainer().textContent ).toContain(
			'Add a hero pattern after this group'
		);
		expect( getContainer().textContent ).toContain(
			'Insert pattern after the selected block.'
		);
		expect( getContainer().textContent ).toContain( 'Pattern: theme/hero' );
		expect( getContainer().textContent ).toContain( 'Target: block-1' );
		expect( getContainer().textContent ).toContain(
			'Expected block: core/group'
		);
		expect( getContainer().textContent ).toContain(
			'Target signature: target-sig'
		);
		expect( getContainer().textContent ).toContain(
			'Position: insert_after'
		);
		expect( getContainer().textContent ).not.toContain( 'Manual ideas' );
		expect( mockSuggestionChips ).not.toHaveBeenCalled();

		const reviewButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( element ) => element.textContent === 'Review' );

		expect( reviewButton ).toBeDefined();
		expect( reviewButton.disabled ).toBe( false );
		expect( reviewButton.getAttribute( 'aria-label' ) ).toBe(
			'Review Add a hero pattern after this group'
		);
		expect( reviewButton.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( reviewButton.getAttribute( 'aria-controls' ) ).toBeNull();

		act( () => {
			reviewButton.click();
		} );

		const activeReviewButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( element ) => element.textContent === 'Review' );
		const reviewDetailsId =
			activeReviewButton.getAttribute( 'aria-controls' );

		expect( activeReviewButton.getAttribute( 'aria-expanded' ) ).toBe(
			'true'
		);
		expect( reviewDetailsId ).toMatch( /^flavor-agent-block-review-/ );
		expect(
			getContainer().querySelector( `#${ reviewDetailsId }` )
		).not.toBeNull();
		expect( getContainer().textContent ).toContain(
			'Selected structural review'
		);
		expect( getContainer().textContent ).toContain(
			'This review is scoped to the current block, request token, and request signature.'
		);
		expect( getContainer().textContent ).toContain(
			'Apply reviewed structure'
		);

		const applyButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find(
			( element ) => element.textContent === 'Apply reviewed structure'
		);

		expect( applyButton ).toBeDefined();

		act( () => {
			applyButton.click();
		} );

		expect( mockApplyBlockStructuralSuggestion ).toHaveBeenCalledWith(
			'block-1',
			expect.objectContaining( {
				label: 'Add a hero pattern after this group',
				actionability: expect.objectContaining( {
					tier: 'review-safe',
					executableOperations: [ operation ],
				} ),
			} ),
			expect.any( String ),
			expect.objectContaining( {
				clientId: 'block-1',
				editorContext: liveContext,
			} )
		);
	} );

	test( 'keeps approved structural operations reviewable when inline-safe updates can apply', () => {
		const liveContext = {
			block: {
				name: 'core/group',
			},
		};
		const contextSignature = JSON.stringify( liveContext );
		const operation = {
			catalogVersion: 1,
			type: 'insert_pattern',
			patternName: 'theme/hero',
			targetClientId: 'block-1',
			targetSignature: 'target-sig',
			targetType: 'block',
			expectedTarget: {
				clientId: 'block-1',
				name: 'core/group',
			},
			position: 'insert_after',
		};

		mockCollectBlockContext.mockReturnValue( liveContext );
		currentState = createState( {
			blockEditor: {
				selectedBlockClientId: null,
				blockLookup: {
					'block-1': {
						clientId: 'block-1',
						name: 'core/group',
						attributes: {},
						innerBlocks: [],
					},
				},
				blocks: [
					{
						clientId: 'block-1',
						name: 'core/group',
						attributes: {},
						innerBlocks: [],
					},
				],
			},
			store: {
				blockRecommendations: {
					'block-1': {
						block: [
							{
								label: 'Tighten copy and add a hero',
								description:
									'Update local copy now and review the structural hero placement.',
								attributeUpdates: {
									metadata: {
										blockVisibility: {
											viewport: {
												mobile: false,
											},
										},
									},
								},
								operations: [ operation ],
								proposedOperations: [ operation ],
								rejectedOperations: [],
							},
						],
						blockContext: {
							name: 'core/group',
							blockOperationContext: {
								enableBlockStructuralActions: true,
								targetClientId: 'block-1',
								targetBlockName: 'core/group',
								targetSignature: 'target-sig',
								isTargetLocked: false,
								isContentOnly: false,
								allowedPatterns: [
									{
										name: 'theme/hero',
										title: 'Hero',
										allowedActions: [ 'insert_after' ],
									},
								],
							},
						},
					},
				},
				blockContextSignatures: {
					'block-1': contextSignature,
				},
				blockRequestTokens: {
					'block-1': 7,
				},
				blockStatuses: {
					'block-1': 'ready',
				},
			},
		} );

		renderContent();

		expect( getContainer().textContent ).toContain( 'Apply now' );
		expect( getContainer().textContent ).toContain( 'Review first' );
		expect( getContainer().textContent ).toContain(
			'Tighten copy and add a hero'
		);
		expect( getContainer().textContent ).toContain(
			'Insert pattern after the selected block.'
		);
		expect( getContainer().textContent ).toContain( 'Pattern: theme/hero' );
		expect( getContainer().textContent ).toContain(
			'Position: insert_after'
		);
		expect( mockSuggestionChips ).toHaveBeenCalledWith(
			expect.objectContaining( {
				suggestions: [
					expect.objectContaining( {
						label: 'Tighten copy and add a hero',
						eligibility: expect.objectContaining( {
							tier: 'inline-safe',
						} ),
					} ),
				],
			} )
		);
	} );

	test( 'clears an open structural review when the result becomes stale', () => {
		const initialContext = {
			block: {
				name: 'core/group',
			},
		};
		const updatedContext = {
			block: {
				name: 'core/quote',
			},
		};
		const contextSignature = JSON.stringify( initialContext );
		const operation = {
			catalogVersion: 1,
			type: 'insert_pattern',
			patternName: 'theme/hero',
			targetClientId: 'block-1',
			targetSignature: 'target-sig',
			targetType: 'block',
			position: 'insert_after',
			expectedTarget: {
				clientId: 'block-1',
				name: 'core/group',
			},
		};

		mockCollectBlockContext.mockReturnValue( initialContext );
		currentState = createState( {
			blockEditor: {
				selectedBlockClientId: null,
				blockLookup: {
					'block-1': {
						clientId: 'block-1',
						name: 'core/group',
						attributes: {},
						innerBlocks: [],
					},
				},
				blocks: [
					{
						clientId: 'block-1',
						name: 'core/group',
						attributes: {},
						innerBlocks: [],
					},
				],
			},
			store: {
				blockRecommendations: {
					'block-1': {
						block: [
							{
								label: 'Add a hero pattern after this group',
								description:
									'Use a stronger CTA section after the selected group.',
								type: 'pattern_replacement',
								operations: [ operation ],
								proposedOperations: [ operation ],
								rejectedOperations: [],
							},
						],
						blockContext: {
							name: 'core/group',
							blockOperationContext: {
								enableBlockStructuralActions: true,
								targetClientId: 'block-1',
								targetBlockName: 'core/group',
								targetSignature: 'target-sig',
								isTargetLocked: false,
								isContentOnly: false,
								allowedPatterns: [
									{
										name: 'theme/hero',
										title: 'Hero',
										allowedActions: [ 'insert_after' ],
									},
								],
							},
						},
					},
				},
				blockContextSignatures: {
					'block-1': contextSignature,
				},
				blockRequestTokens: {
					'block-1': 7,
				},
				blockStatuses: {
					'block-1': 'ready',
				},
			},
		} );

		renderContent();

		const reviewButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( element ) => element.textContent === 'Review' );

		act( () => {
			reviewButton.click();
		} );

		expect( getContainer().textContent ).toContain(
			'Selected structural review'
		);

		mockCollectBlockContext.mockReturnValue( updatedContext );

		renderContent();

		expect( getContainer().textContent ).toContain( 'Review first' );
		expect( getContainer().textContent ).toContain( 'Stale' );
		expect( getContainer().textContent ).toContain(
			'These structural suggestions are shown for reference from the last request. Refresh before reviewing them.'
		);
		expect( getContainer().textContent ).not.toContain(
			'Selected structural review'
		);

		const staleReviewButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( element ) => element.textContent === 'Refresh to review' );

		expect( staleReviewButton ).toBeDefined();
		expect( staleReviewButton.disabled ).toBe( true );
	} );

	test( 'keeps stale review-safe structural operations visible but blocks review entry', () => {
		const storedContext = {
			block: {
				name: 'core/group',
			},
		};
		const liveContext = {
			block: {
				name: 'core/quote',
			},
		};
		const operation = {
			catalogVersion: 1,
			type: 'replace_block_with_pattern',
			patternName: 'theme/callout',
			targetClientId: 'block-1',
			targetSignature: 'target-sig',
			targetType: 'block',
			action: 'replace',
			expectedTarget: {
				clientId: 'block-1',
				name: 'core/group',
			},
		};

		mockCollectBlockContext.mockReturnValue( liveContext );
		currentState = createState( {
			blockEditor: {
				selectedBlockClientId: null,
				blockLookup: {
					'block-1': {
						clientId: 'block-1',
						name: 'core/group',
						attributes: {},
						innerBlocks: [],
					},
				},
				blocks: [
					{
						clientId: 'block-1',
						name: 'core/group',
						attributes: {},
						innerBlocks: [],
					},
				],
			},
			store: {
				blockRecommendations: {
					'block-1': {
						block: [
							{
								label: 'Replace with a callout pattern',
								type: 'pattern_replacement',
								operations: [ operation ],
								proposedOperations: [ operation ],
								rejectedOperations: [],
							},
						],
						blockContext: {
							name: 'core/group',
							blockOperationContext: {
								enableBlockStructuralActions: true,
								targetClientId: 'block-1',
								targetBlockName: 'core/group',
								targetSignature: 'target-sig',
								isTargetLocked: false,
								isContentOnly: false,
								allowedPatterns: [
									{
										name: 'theme/callout',
										title: 'Callout',
										allowedActions: [ 'replace' ],
									},
								],
							},
						},
					},
				},
				blockContextSignatures: {
					'block-1': JSON.stringify( storedContext ),
				},
				blockRequestTokens: {
					'block-1': 4,
				},
				blockStatuses: {
					'block-1': 'ready',
				},
			},
		} );

		renderContent();

		expect( getContainer().textContent ).toContain( 'Review first' );
		expect( getContainer().textContent ).toContain( 'Stale' );
		expect( getContainer().textContent ).toContain(
			'These structural suggestions are shown for reference from the last request. Refresh before reviewing them.'
		);
		expect( getContainer().textContent ).toContain(
			'Replace with a callout pattern'
		);

		const reviewButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( element ) => element.textContent === 'Refresh to review' );

		expect( reviewButton ).toBeDefined();
		expect( reviewButton.disabled ).toBe( true );
	} );

	test( 'keeps rejected structural proposals visible as advisory remainder when local attributes can apply', () => {
		const liveContext = {
			block: {
				name: 'core/paragraph',
			},
		};

		mockCollectBlockContext.mockReturnValue( liveContext );
		currentState = createState( {
			blockEditor: {
				selectedBlockClientId: null,
			},
			store: {
				blockRecommendations: {
					'block-1': {
						block: [
							{
								label: 'Tighten copy and consider a hero pattern',
								description:
									'Update local copy now, and keep the pattern idea as review guidance.',
								attributeUpdates: {
									metadata: {
										blockVisibility: {
											viewport: {
												mobile: false,
											},
										},
									},
								},
								proposedOperations: [
									{
										type: 'insert_pattern',
										patternName: 'theme/hero',
										targetClientId: 'block-1',
										position: 'insert_after',
									},
								],
								rejectedOperations: [
									{
										code: 'block_structural_actions_disabled',
										message:
											'Block structural actions are disabled for this environment.',
										operation: {
											type: 'insert_pattern',
											patternName: 'theme/hero',
											targetClientId: 'block-1',
											position: 'insert_after',
										},
									},
								],
							},
						],
						blockContext: {
							name: 'core/paragraph',
						},
					},
				},
				blockContextSignatures: {
					'block-1': JSON.stringify( liveContext ),
				},
				blockStatuses: {
					'block-1': 'ready',
				},
			},
		} );

		renderContent();

		expect( getContainer().textContent ).toContain( 'Apply now' );
		expect( getContainer().textContent ).toContain( 'Manual ideas' );
		expect( getContainer().textContent ).toContain(
			'Tighten copy and consider a hero pattern'
		);
		expect( getContainer().textContent ).toContain(
			'Eligibility blockers: Missing pattern context.'
		);
		expect( mockSuggestionChips ).toHaveBeenCalledWith(
			expect.objectContaining( {
				suggestions: [
					expect.objectContaining( {
						label: 'Tighten copy and consider a hero pattern',
						eligibility: expect.objectContaining( {
							tier: 'inline-safe',
						} ),
					} ),
				],
			} )
		);
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

		expect( getContainer().textContent ).toContain( 'Heading' );
		expect( getContainer().textContent ).not.toContain( 'core/heading' );
		expect( getContainer().textContent ).toContain( 'Last Selected Block' );
		expect( getContainer().textContent ).not.toContain( 'Current' );
		expect( getContainer().textContent ).not.toContain( 'Stale' );
	} );

	test( 'renders the shared intro shell and a shorter composer helper in the main block panel', () => {
		renderContent();

		expect( getContainer().textContent ).toContain( 'Selected Block' );
		expect( getContainer().textContent ).toContain(
			'Ask for a specific outcome or fetch recommendations based on the current block context.'
		);
		expect( getContainer().textContent ).toContain(
			'Flavor Agent keeps one-click apply limited to safe local block attribute changes.'
		);
	} );

	test( 'humanizes the scope label instead of exposing the raw block slug', () => {
		renderContent();

		expect( getContainer().textContent ).toContain( 'Paragraph' );
		expect( getContainer().textContent ).not.toContain( 'core/paragraph' );
	} );

	test( 'clarifies that content-restricted blocks can still surface manual guidance', () => {
		currentState = createState( {
			blockEditor: {
				editingModes: {
					'block-1': 'contentOnly',
				},
			},
		} );

		renderContent();

		expect( getContainer().textContent ).toContain(
			'This block is content-restricted. Flavor Agent will stay within editable content and may keep broader block ideas as manual guidance only.'
		);
		expect( getContainer().textContent ).not.toContain(
			'Only content edits are available.'
		);
	} );

	test( 'renders embedded navigation after the block lanes when navigation guidance is present', () => {
		mockShouldRenderNavigationRecommendations = true;
		mockCollectBlockContext.mockReturnValue( {
			block: {
				name: 'core/navigation',
				inspectorPanels: {
					dimensions: [ 'spacing.blockGap' ],
				},
			},
		} );
		currentState = createState( {
			blockEditor: {
				selectedBlockClientId: null,
				blockLookup: {
					'block-1': {
						clientId: 'block-1',
						name: 'core/navigation',
						attributes: {
							ref: 12,
						},
						innerBlocks: [],
					},
				},
				blocks: [
					{
						clientId: 'block-1',
						name: 'core/navigation',
						attributes: {
							ref: 12,
						},
						innerBlocks: [],
					},
				],
			},
			store: {
				blockRecommendations: {
					'block-1': {
						block: [
							{
								label: 'Tighten spacing',
								description:
									'Use safer spacing for the menu block.',
								panel: 'dimensions',
								attributeUpdates: {
									style: {
										spacing: {
											blockGap: '1rem',
										},
									},
								},
							},
							{
								label: 'Improve menu hierarchy',
								description:
									'Group related destinations more clearly.',
								type: 'structural_recommendation',
							},
						],
						blockContext: {
							name: 'core/navigation',
							inspectorPanels: {
								dimensions: [ 'spacing.blockGap' ],
							},
						},
						executionContract: {
							allowedPanels: [ 'dimensions' ],
							panelMappingKnown: true,
							presetSlugs: {
								spacing: [],
							},
							styleSupportPaths: [ 'spacing.blockGap' ],
						},
					},
				},
				blockContextSignatures: {
					'block-1': JSON.stringify( {
						block: {
							name: 'core/navigation',
						},
					} ),
				},
				blockStatuses: {
					'block-1': 'ready',
				},
			},
		} );

		renderContent();

		const panelText = getContainer().textContent;
		expect( panelText.indexOf( 'Apply now' ) ).toBeGreaterThan( -1 );
		expect( panelText.indexOf( 'Manual ideas' ) ).toBeGreaterThan( -1 );
		expect(
			panelText.indexOf( 'Embedded Navigation Recommendations' )
		).toBeGreaterThan( panelText.indexOf( 'Manual ideas' ) );
		expect( mockRenderNavigationRecommendations ).toHaveBeenCalledWith(
			expect.objectContaining( {
				clientId: 'block-1',
				embedded: true,
			} )
		);
	} );

	test( 'adds an activity diagnostic row when a fresh request returns no block-lane suggestions', () => {
		currentState = createState( {
			store: {
				blockRecommendations: {
					'block-1': {
						block: [],
						settings: [],
						styles: [ { label: 'Use accent text' } ],
						explanation: 'Use stronger editorial contrast.',
						prompt: 'Make this feel more editorial.',
					},
				},
				blockContextSignatures: {
					'block-1': JSON.stringify( {
						block: {
							name: 'core/paragraph',
						},
					} ),
				},
				blockRequestDiagnostics: {
					'block-1': {
						hasEmptyBlockResult: true,
						title: 'No block-lane suggestions returned',
						detailLines: [
							'Flavor Agent returned 1 style, but none in the block lane.',
						],
						blockName: 'core/paragraph',
						prompt: 'Make this feel more editorial.',
						requestToken: 3,
						timestamp: '2026-04-06T12:00:00Z',
					},
				},
				blockStatuses: {
					'block-1': 'ready',
				},
			},
		} );

		renderContent();

		const latestActivityProps =
			mockRenderAIActivitySection.mock.calls[
				mockRenderAIActivitySection.mock.calls.length - 1
			][ 0 ];

		expect( latestActivityProps.description ).toContain(
			'Recent request diagnostics and applied actions'
		);
		expect( latestActivityProps.entries[ 0 ] ).toEqual(
			expect.objectContaining( {
				type: 'request_diagnostic',
				suggestion: 'No block-lane suggestions returned',
				diagnostic: expect.objectContaining( {
					detailLines: [
						'Flavor Agent returned 1 style, but none in the block lane.',
					],
				} ),
				executionResult: 'review',
			} )
		);
	} );

	test( 'adds an activity diagnostic row when a block request fails with request metadata', () => {
		currentState = createState( {
			store: {
				blockRecommendations: {
					'block-1': {
						block: [],
						settings: [],
						styles: [],
						explanation: '',
						prompt: 'Make this feel more editorial.',
					},
				},
				blockRequestDiagnostics: {
					'block-1': {
						type: 'failure',
						title: 'Block request failed: Azure OpenAI responses request timed out after 180 seconds.',
						detailLines: [
							'Transport detail: cURL error 28: Operation timed out after 180001 milliseconds with 0 bytes received',
						],
						blockName: 'core/paragraph',
						prompt: 'Make this feel more editorial.',
						requestToken: 4,
						timestamp: '2026-04-08T12:00:00Z',
						requestMeta: {
							backendLabel: 'Azure OpenAI responses',
							model: 'gpt-5.4-mini',
						},
						errorCode: 'http_request_failed',
						errorMessage:
							'Azure OpenAI responses request timed out after 180 seconds.',
					},
				},
				blockStatuses: {
					'block-1': 'error',
				},
			},
		} );

		renderContent();

		const latestActivityProps =
			mockRenderAIActivitySection.mock.calls[
				mockRenderAIActivitySection.mock.calls.length - 1
			][ 0 ];

		expect( latestActivityProps.entries[ 0 ] ).toEqual(
			expect.objectContaining( {
				type: 'request_diagnostic',
				suggestion:
					'Block request failed: Azure OpenAI responses request timed out after 180 seconds.',
				request: expect.objectContaining( {
					error: expect.objectContaining( {
						code: 'http_request_failed',
						message:
							'Azure OpenAI responses request timed out after 180 seconds.',
					} ),
				} ),
				undo: expect.objectContaining( {
					status: 'failed',
				} ),
			} )
		);
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
			'This result no longer matches the current block or prompt.'
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
		expect(
			getContainer()
				.querySelector( '.flavor-agent-scope-bar' )
				?.getAttribute( 'role' )
		).toBe( 'status' );
		expect(
			getContainer()
				.querySelector( '.flavor-agent-recommendation-hero' )
				?.compareDocumentPosition(
					getContainer().querySelector( '.flavor-agent-explanation' )
				)
		).toBe( DOCUMENT_POSITION_FOLLOWING );
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
			'This result no longer matches the current block or prompt.'
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
