const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockFetchStyleBookRecommendations = jest.fn();
const mockApplyStyleBookSuggestion = jest.fn();
const mockClearStyleBookRecommendations = jest.fn();
const mockSetStyleBookSelectedSuggestion = jest.fn();
const mockUndoActivity = jest.fn();
const mockGetGlobalStylesUserConfig = jest.fn();
const mockGetGlobalStylesActivityUndoState = jest.fn();
const mockGetStyleBookUiState = jest.fn();
const mockSubscribeToStyleBookUi = jest.fn();
const mockRenderAIStatusNotice = jest.fn();
const DEFAULT_EXECUTION_CONTRACT = {
	supportedStylePaths: [
		{
			path: [ 'color', 'text' ],
			valueSource: 'color',
		},
		{
			path: [ 'spacing', 'blockGap' ],
			valueSource: 'spacing',
		},
	],
	presetSlugs: {
		color: [ 'accent', 'contrast' ],
		spacing: [ '30', '40' ],
	},
};

jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

jest.mock( '@wordpress/data', () => ( {
	useDispatch: ( ...args ) => mockUseDispatch( ...args ),
	useSelect: ( ...args ) => mockUseSelect( ...args ),
} ) );

jest.mock( '@wordpress/blocks', () => ( {
	store: 'core/blocks',
} ) );

jest.mock( '@wordpress/editor', () => ( {
	PluginDocumentSettingPanel: ( { children } ) => children,
} ) );

jest.mock( '../../components/CapabilityNotice', () => () => null );
jest.mock( '../../components/AIStatusNotice', () => {
	const { createElement } = require( '@wordpress/element' );

	return ( props ) => {
		mockRenderAIStatusNotice( props );

		if ( ! props.notice?.message ) {
			return null;
		}

		return createElement(
			'div',
			{ 'data-status-notice': 'true' },
			props.notice.message
		);
	};
} );
jest.mock( '../../components/AIActivitySection', () => () => null );
jest.mock(
	'../../components/AIReviewSection',
	() =>
		( { children } ) =>
			children
);

jest.mock( '../../utils/capability-flags', () => ( {
	getSurfaceCapability: () => ( {
		available: true,
	} ),
} ) );

jest.mock( '../../context/theme-tokens', () => ( {
	collectThemeTokenDiagnosticsFromSettings: ( settings = {} ) =>
		settings?.__diagnostics || {
			source: 'stable',
		},
	buildBlockStyleExecutionContractFromSettings: ( settings = {} ) =>
		settings?.__executionContract || DEFAULT_EXECUTION_CONTRACT,
} ) );

jest.mock( '../../utils/style-operations', () => ( {
	...jest.requireActual( '../../utils/style-operations' ),
	getGlobalStylesUserConfig: ( ...args ) =>
		mockGetGlobalStylesUserConfig( ...args ),
	getGlobalStylesActivityUndoState: ( ...args ) =>
		mockGetGlobalStylesActivityUndoState( ...args ),
} ) );

jest.mock( '../dom', () => ( {
	findStylesSidebarMountNode: ( root ) => {
		const resolvedRoot = root || global.document;

		return (
			resolvedRoot.querySelector(
				'.editor-global-styles-sidebar__panel'
			) ||
			resolvedRoot.querySelector( '.editor-global-styles-sidebar' ) ||
			resolvedRoot.querySelector( '[role="region"][aria-label="Styles"]' )
		);
	},
	getStyleBookUiState: ( ...args ) => mockGetStyleBookUiState( ...args ),
	subscribeToStyleBookUi: ( ...args ) =>
		mockSubscribeToStyleBookUi( ...args ),
} ) );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createRoot } = require( '@wordpress/element' );
const {
	buildGlobalStylesRecommendationContextSignature,
} = require( '../../utils/style-operations' );

import StyleBookRecommender from '../StyleBookRecommender';

let container = null;
let root = null;
let sidebar = null;
let currentBlockEditorSettings = null;
let currentBlockType = null;
let currentGlobalStylesData = null;
let currentStoreState = null;
let currentStyleBookUiState = null;
let styleBookUiSubscriber = null;
let currentEditedTemplateId = null;

window.IS_REACT_ACT_ENVIRONMENT = true;

function createStyleVariations() {
	return [
		{
			title: 'Default',
			settings: {},
			styles: {},
		},
		{
			title: 'Midnight',
			description: 'Dark editorial palette',
			settings: {},
			styles: {
				color: {
					background: 'var:preset|color|accent',
				},
			},
		},
	];
}

function createGlobalStylesData( globalStylesId = '17' ) {
	return {
		globalStylesId,
		userConfig: {
			settings: {},
			styles: {
				blocks: {
					'core/paragraph': {
						color: {
							text: 'var:preset|color|contrast',
						},
						spacing: {
							blockGap: 'var:preset|spacing|30',
						},
					},
				},
			},
			_links: {},
		},
		mergedConfig: {
			settings: {},
			styles: {
				blocks: {
					'core/paragraph': {
						color: {
							text: 'var:preset|color|contrast',
						},
						spacing: {
							blockGap: 'var:preset|spacing|30',
						},
					},
				},
			},
			_links: {},
		},
		variations: createStyleVariations(),
	};
}

function buildContextSignature() {
	return buildGlobalStylesRecommendationContextSignature( {
		scope: {
			scopeKey: 'style_book:17:core/paragraph',
			globalStylesId: '17',
			templateSlug: currentEditedTemplateId,
			templateType: 'home',
			blockName: 'core/paragraph',
			blockTitle: 'Paragraph',
		},
		currentConfig: currentGlobalStylesData.userConfig,
		mergedConfig: currentGlobalStylesData.mergedConfig,
		themeTokenDiagnostics: currentBlockEditorSettings.__diagnostics || {},
		executionContract:
			currentBlockEditorSettings.__executionContract ||
			DEFAULT_EXECUTION_CONTRACT,
	} );
}

beforeEach( () => {
	jest.clearAllMocks();
	currentBlockEditorSettings = {
		__diagnostics: {
			source: 'stable',
		},
		__executionContract: DEFAULT_EXECUTION_CONTRACT,
	};
	currentBlockType = {
		name: 'core/paragraph',
		title: 'Paragraph',
		description: 'Primary intro copy block.',
		supports: {
			color: {
				text: true,
			},
			spacing: {
				blockGap: true,
			},
		},
	};
	currentGlobalStylesData = createGlobalStylesData();
	currentEditedTemplateId = 'theme//home';
	currentStoreState = {
		activityLog: [],
		recommendations: [],
		explanation: '',
		status: 'idle',
		error: null,
		resultRef: null,
		contextSignature: null,
		selectedSuggestionKey: null,
		applyStatus: 'idle',
		undoStatus: 'idle',
		undoError: null,
		lastUndoneActivityId: null,
	};
	currentStyleBookUiState = {
		isActive: true,
		target: {
			blockName: 'core/paragraph',
			blockTitle: 'Paragraph',
		},
	};
	styleBookUiSubscriber = null;

	mockGetGlobalStylesUserConfig.mockImplementation(
		() => currentGlobalStylesData
	);
	mockGetGlobalStylesActivityUndoState.mockReturnValue( {
		canUndo: true,
		status: 'available',
		error: null,
	} );
	mockGetStyleBookUiState.mockImplementation( () => currentStyleBookUiState );
	mockSubscribeToStyleBookUi.mockImplementation( ( _root, onChange ) => {
		styleBookUiSubscriber = onChange;
		onChange( currentStyleBookUiState );
		return () => {};
	} );

	mockUseSelect.mockImplementation( ( mapSelect ) =>
		mapSelect( ( storeName ) => {
			if ( storeName === 'core/block-editor' ) {
				return {
					getSettings: () => currentBlockEditorSettings,
				};
			}

			if ( storeName === 'core/interface' ) {
				return {
					getActiveComplementaryArea: () => 'edit-site/global-styles',
				};
			}

			if ( storeName === 'core/edit-site' ) {
				return {
					getEditedPostType: () => 'wp_template',
					getEditedPostId: () => currentEditedTemplateId,
				};
			}

			if ( storeName === 'core/blocks' ) {
				return {
					getBlockType: () => currentBlockType,
				};
			}

			if ( storeName === 'flavor-agent' ) {
				return {
					getActivityLog: () => currentStoreState.activityLog,
					getStyleBookRecommendations: () =>
						currentStoreState.recommendations,
					getStyleBookExplanation: () =>
						currentStoreState.explanation,
					getStyleBookStatus: () => currentStoreState.status,
					getStyleBookError: () => currentStoreState.error,
					getStyleBookResultRef: () => currentStoreState.resultRef,
					getStyleBookContextSignature: () =>
						currentStoreState.contextSignature,
					getStyleBookSelectedSuggestionKey: () =>
						currentStoreState.selectedSuggestionKey,
					getStyleBookApplyStatus: () =>
						currentStoreState.applyStatus,
					getUndoStatus: () => currentStoreState.undoStatus,
					getUndoError: () => currentStoreState.undoError,
					getLastUndoneActivityId: () =>
						currentStoreState.lastUndoneActivityId,
					getSurfaceStatusNotice: ( surface, options = {} ) => {
						void surface;

						if ( options.requestError ) {
							return {
								source: 'request',
								tone: 'error',
								message: options.requestError,
							};
						}

						return null;
					},
				};
			}

			return {};
		} )
	);
	mockUseDispatch.mockImplementation( () => ( {
		fetchStyleBookRecommendations: mockFetchStyleBookRecommendations,
		applyStyleBookSuggestion: mockApplyStyleBookSuggestion,
		clearStyleBookRecommendations: mockClearStyleBookRecommendations,
		setStyleBookSelectedSuggestion: mockSetStyleBookSelectedSuggestion,
		undoActivity: mockUndoActivity,
	} ) );

	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );

	sidebar = document.createElement( 'div' );
	sidebar.className = 'editor-global-styles-sidebar__panel';
	document.body.appendChild( sidebar );
} );

afterEach( () => {
	act( () => {
		root.unmount();
	} );
	sidebar.remove();
	container.remove();
} );

describe( 'StyleBookRecommender', () => {
	test( 'submits a block-scoped style recommendation request from the Style Book sidebar', () => {
		act( () => {
			root.render( <StyleBookRecommender /> );
		} );

		const textarea = sidebar.querySelector( 'textarea' );
		const button = sidebar.querySelector( 'button' );

		act( () => {
			const descriptor = Object.getOwnPropertyDescriptor(
				window.HTMLTextAreaElement.prototype,
				'value'
			);

			descriptor.set.call(
				textarea,
				'Make the paragraph example feel more editorial.'
			);
			textarea.dispatchEvent(
				new window.Event( 'input', { bubbles: true } )
			);
		} );

		act( () => {
			button.click();
		} );

		const requestInput =
			mockFetchStyleBookRecommendations.mock.calls[ 0 ][ 0 ];

		expect( mockFetchStyleBookRecommendations ).toHaveBeenCalledWith(
			expect.objectContaining( {
				scope: expect.objectContaining( {
					surface: 'style-book',
					scopeKey: 'style_book:17:core/paragraph',
					globalStylesId: '17',
					entityId: 'core/paragraph',
					templateSlug: 'theme//home',
					templateType: 'home',
					blockName: 'core/paragraph',
					blockTitle: 'Paragraph',
				} ),
				styleContext: expect.objectContaining( {
					currentConfig: currentGlobalStylesData.userConfig,
					mergedConfig: currentGlobalStylesData.mergedConfig,
					styleBookTarget: {
						blockName: 'core/paragraph',
						blockTitle: 'Paragraph',
						description: 'Primary intro copy block.',
						currentStyles: {
							color: {
								text: 'var:preset|color|contrast',
							},
							spacing: {
								blockGap: 'var:preset|spacing|30',
							},
						},
						mergedStyles: {
							color: {
								text: 'var:preset|color|contrast',
							},
							spacing: {
								blockGap: 'var:preset|spacing|30',
							},
						},
					},
				} ),
				prompt: 'Make the paragraph example feel more editorial.',
			} )
		);
		expect( requestInput.styleContext.availableVariations ).toBeUndefined();
	} );

	test( 'shows a selection notice and disables the request button when no style-book target is selected', () => {
		currentStyleBookUiState = {
			isActive: true,
			target: null,
		};

		act( () => {
			root.render( <StyleBookRecommender /> );
		} );

		expect( sidebar.textContent ).toContain(
			'Select a block example in Style Book to request recommendations.'
		);
		expect( sidebar.querySelector( 'button' )?.disabled ).toBe( true );
		expect( mockFetchStyleBookRecommendations ).not.toHaveBeenCalled();
	} );

	test( 'renders shared style card badges and review state for executable style book suggestions', () => {
		currentStoreState = {
			...currentStoreState,
			recommendations: [
				{
					suggestionKey: 'style-book-1',
					label: 'Refine paragraph rhythm',
					description:
						'Increase the block gap to give the example more breathing room.',
					category: 'spacing',
					tone: 'executable',
					operations: [
						{
							type: 'set_block_styles',
							path: [ 'spacing', 'blockGap' ],
							value: 'var:preset|spacing|40',
							presetSlug: '40',
						},
					],
				},
			],
			explanation: 'Push spacing slightly further for the example block.',
			status: 'ready',
			resultRef: 'style_book:17:core/paragraph',
			contextSignature: buildContextSignature(),
			selectedSuggestionKey: 'style-book-1',
		};

		act( () => {
			root.render( <StyleBookRecommender /> );
		} );

		expect( sidebar.textContent ).toContain( 'Style Book' );
		expect( sidebar.textContent ).toContain( 'Paragraph' );
		expect( sidebar.textContent ).toContain( 'Review to apply' );
		expect( sidebar.textContent ).toContain( 'Spacing' );
		expect( sidebar.textContent ).toContain( 'Review open' );
		expect( sidebar.textContent ).toContain( 'spacing.blockGap → 40' );
	} );

	test( 'drops stale recommendations when the selected Style Book block changes', () => {
		currentStoreState = {
			...currentStoreState,
			recommendations: [ { label: 'Tighten paragraph rhythm' } ],
			explanation: 'Existing explanation',
			status: 'ready',
			resultRef: 'style_book:17:core/paragraph',
			contextSignature: buildContextSignature(),
		};

		act( () => {
			root.render( <StyleBookRecommender /> );
		} );

		expect( mockClearStyleBookRecommendations ).not.toHaveBeenCalled();

		currentStyleBookUiState = {
			isActive: true,
			target: {
				blockName: 'core/heading',
				blockTitle: 'Heading',
			},
		};
		currentBlockType = {
			name: 'core/heading',
			title: 'Heading',
			supports: {
				color: {
					text: true,
				},
			},
		};

		act( () => {
			styleBookUiSubscriber( currentStyleBookUiState );
		} );

		expect( mockClearStyleBookRecommendations ).toHaveBeenCalledTimes( 1 );
	} );
} );
