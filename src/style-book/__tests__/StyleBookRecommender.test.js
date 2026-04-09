const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockFetchStyleBookRecommendations = jest.fn();
const mockRevalidateStyleBookReviewFreshness = jest.fn();
const mockApplyStyleBookSuggestion = jest.fn();
const mockClearStyleBookRecommendations = jest.fn();
const mockSetStyleBookSelectedSuggestion = jest.fn();
const mockUndoActivity = jest.fn();
const mockGetGlobalStylesUserConfig = jest.fn();
const mockGetGlobalStylesActivityUndoState = jest.fn();
const mockGetStyleBookUiState = jest.fn();
const mockSubscribeToStyleBookUi = jest.fn();
const mockRenderAIStatusNotice = jest.fn();
const mockRenderAIActivitySection = jest.fn();
const mockRenderAIReviewSection = jest.fn();
let mockSurfaceCapability = null;
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
			createElement( 'span', null, props.notice.message ),
			props.onAction && props.notice.actionLabel
				? createElement(
						'button',
						{
							type: 'button',
							onClick: props.onAction,
						},
						props.notice.actionLabel
				  )
				: null
		);
	};
} );
jest.mock( '../../components/AIActivitySection', () => ( props ) => {
	mockRenderAIActivitySection( props );
	return null;
} );
jest.mock( '../../components/AIReviewSection', () => {
	const { createElement } = require( '@wordpress/element' );

	return ( props ) => {
		mockRenderAIReviewSection( props );

		return createElement(
			'section',
			{ 'data-review-section': 'true' },
			createElement(
				'div',
				{ 'data-review-title': 'true' },
				props.title || ''
			),
			createElement(
				'div',
				{ 'data-review-status': 'true' },
				props.statusLabel || ''
			),
			props.summary
				? createElement(
						'div',
						{ 'data-review-summary': 'true' },
						props.summary
				  )
				: null,
			props.children
		);
	};
} );

jest.mock( '../../utils/capability-flags', () => ( {
	getSurfaceCapability: () =>
		mockSurfaceCapability || {
			available: true,
		},
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
const { setupReactTest } = require( '../../test-utils/setup-react-test' );
const DOCUMENT_POSITION_FOLLOWING = 4;
const {
	buildGlobalStylesRecommendationContextSignature,
} = require( '../../utils/style-operations' );
const {
	collectViewportVisibilitySummary,
} = require( '../../utils/editor-context-metadata' );
const {
	buildStyleBookDesignSemantics,
} = require( '../../utils/style-design-semantics' );

import StyleBookRecommender from '../StyleBookRecommender';

const { getRoot } = setupReactTest();

let sidebar = null;
let currentBlockEditorSettings = null;
let currentBlockType = null;
let currentGlobalStylesData = null;
let currentStoreState = null;
let currentStyleBookUiState = null;
let styleBookUiSubscriber = null;
let currentEditedTemplateId = null;
let currentEditedBlocks = null;

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

function createEditedBlocks() {
	return [
		{
			name: 'core/template-part',
			attributes: {
				slug: 'footer',
				area: 'footer',
			},
			innerBlocks: [
				{
					name: 'core/heading',
					innerBlocks: [],
				},
				{
					name: 'core/paragraph',
					innerBlocks: [],
				},
				{
					name: 'core/site-title',
					innerBlocks: [],
				},
			],
		},
		{
			name: 'core/group',
			innerBlocks: [
				{
					name: 'core/query-title',
					innerBlocks: [],
				},
			],
		},
	];
}

function buildExpectedTemplateStructure() {
	return [
		{
			name: 'core/template-part',
			innerBlocks: [
				{ name: 'core/heading' },
				{ name: 'core/paragraph' },
				{ name: 'core/site-title' },
			],
		},
		{
			name: 'core/group',
			innerBlocks: [ { name: 'core/query-title' } ],
		},
	];
}

function buildExpectedDesignSemantics( blocks = currentEditedBlocks ) {
	return buildStyleBookDesignSemantics( blocks, {
		blockName: 'core/paragraph',
		blockTitle: 'Paragraph',
		templateType: 'home',
	} );
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
		templateStructure: buildExpectedTemplateStructure(),
		templateVisibility:
			collectViewportVisibilitySummary( currentEditedBlocks ),
		designSemantics: buildExpectedDesignSemantics(),
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
	currentEditedBlocks = createEditedBlocks();
	currentStoreState = {
		activityLog: [],
		recommendations: [],
		explanation: '',
		requestPrompt: '',
		status: 'idle',
		error: null,
		resultRef: null,
		contextSignature: null,
		reviewContextSignature: null,
		reviewStaleReason: null,
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
	mockSurfaceCapability = {
		available: true,
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
					getBlocks: () => currentEditedBlocks,
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
					getStyleBookRequestPrompt: () =>
						currentStoreState.requestPrompt,
					getStyleBookStatus: () => currentStoreState.status,
					getStyleBookError: () => currentStoreState.error,
					getStyleBookResultRef: () => currentStoreState.resultRef,
					getStyleBookContextSignature: () =>
						currentStoreState.contextSignature,
					getStyleBookReviewContextSignature: () =>
						currentStoreState.reviewContextSignature,
					getStyleBookSelectedSuggestionKey: () =>
						currentStoreState.selectedSuggestionKey,
					getStyleBookReviewStaleReason: () =>
						currentStoreState.reviewStaleReason,
					getStyleBookApplyStatus: () =>
						currentStoreState.applyStatus,
					getUndoStatus: () => currentStoreState.undoStatus,
					getUndoError: () => currentStoreState.undoError,
					getLastUndoneActivityId: () =>
						currentStoreState.lastUndoneActivityId,
					getSurfaceStatusNotice: ( surface, options = {} ) => {
						void surface;

						if ( options.undoError ) {
							return {
								source: 'undo',
								tone: 'error',
								message: options.undoError,
								actionType: null,
								actionLabel: '',
							};
						}

						if ( options.undoSuccessMessage ) {
							return {
								source: 'undo',
								tone: 'success',
								message: options.undoSuccessMessage,
								actionType: null,
								actionLabel: '',
							};
						}

						if ( options.applyError ) {
							return {
								source: 'apply',
								tone: 'error',
								message: options.applyError,
								actionType: null,
								actionLabel: '',
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

						if ( options.requestError ) {
							return {
								source: 'request',
								tone: 'error',
								message: options.requestError,
							};
						}

						if (
							options.hasResult &&
							! options.hasSuggestions &&
							options.emptyMessage
						) {
							return {
								source: 'empty',
								tone: 'info',
								message: options.emptyMessage,
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
		revalidateStyleBookReviewFreshness:
			mockRevalidateStyleBookReviewFreshness,
		setStyleBookSelectedSuggestion: mockSetStyleBookSelectedSuggestion,
		undoActivity: mockUndoActivity,
	} ) );

	sidebar = document.createElement( 'div' );
	sidebar.className = 'editor-global-styles-sidebar__panel';
	document.body.appendChild( sidebar );
} );

afterEach( () => {
	sidebar.remove();
} );

describe( 'StyleBookRecommender', () => {
	test( 'submits a block-scoped style recommendation request from the Style Book sidebar', () => {
		act( () => {
			getRoot().render( <StyleBookRecommender /> );
		} );

		const textarea = sidebar.querySelector( 'textarea' );
		const button = Array.from( sidebar.querySelectorAll( 'button' ) ).find(
			( candidate ) => candidate.textContent === 'Get Style Suggestions'
		);

		expect( sidebar.textContent ).toContain(
			'Press Cmd/Ctrl+Enter to submit.'
		);

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
					templateStructure: buildExpectedTemplateStructure(),
					templateVisibility: {
						hasVisibilityRules: false,
						blockCount: 0,
						blocks: [],
					},
					designSemantics: buildExpectedDesignSemantics(),
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
			getRoot().render( <StyleBookRecommender /> );
		} );

		expect( sidebar.textContent ).toContain(
			'Select a block example in Style Book to request recommendations.'
		);
		expect( sidebar.querySelector( 'textarea' )?.disabled ).toBe( true );
		expect( sidebar.querySelector( 'button' )?.disabled ).toBe( true );
		expect( mockFetchStyleBookRecommendations ).not.toHaveBeenCalled();
	} );

	test( 'disables the composer when Style Book recommendations are unavailable', () => {
		mockSurfaceCapability = {
			available: false,
		};

		act( () => {
			getRoot().render( <StyleBookRecommender /> );
		} );

		expect( sidebar.querySelector( 'textarea' )?.disabled ).toBe( true );
		expect( sidebar.querySelector( 'button' )?.disabled ).toBe( true );
	} );

	test( 'passes the undo guidance to the recent style-book activity section', () => {
		currentStoreState = {
			...currentStoreState,
			activityLog: [
				{
					id: 'activity-1',
					surface: 'style-book',
					suggestion: 'Refine paragraph spacing',
					target: {
						globalStylesId: '17',
						blockName: 'core/paragraph',
						blockTitle: 'Paragraph',
					},
					undo: {
						canUndo: true,
						status: 'available',
						error: null,
					},
				},
			],
		};

		act( () => {
			getRoot().render( <StyleBookRecommender /> );
		} );

		const lastCall =
			mockRenderAIActivitySection.mock.calls[
				mockRenderAIActivitySection.mock.calls.length - 1
			][ 0 ];

		expect( lastCall.entries ).toHaveLength( 1 );
		expect( lastCall.description ).toBe(
			'Undo is only available while the current Style Book block styles still match the applied AI change.'
		);
	} );

	test( 'keeps the apply success undo notice visible after review closes while suggestions remain', () => {
		currentStoreState = {
			...currentStoreState,
			activityLog: [
				{
					id: 'activity-1',
					surface: 'style-book',
					suggestion: 'Refine paragraph rhythm',
					target: {
						globalStylesId: '17',
						blockName: 'core/paragraph',
						blockTitle: 'Paragraph',
					},
					undo: {
						canUndo: true,
						status: 'available',
						error: null,
					},
				},
			],
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
			applyStatus: 'success',
			selectedSuggestionKey: null,
		};

		act( () => {
			getRoot().render( <StyleBookRecommender /> );
		} );

		const notices = Array.from(
			sidebar.querySelectorAll( '[data-status-notice="true"]' )
		);
		const applySuccessNotice = notices.find( ( element ) =>
			element.textContent.includes(
				'Flavor Agent applied the selected Style Book change.'
			)
		);

		expect( applySuccessNotice ).toBeDefined();
		expect( applySuccessNotice.textContent ).toContain( 'Undo' );
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
			getRoot().render( <StyleBookRecommender /> );
		} );

		const reviewSection = sidebar.querySelector(
			'[data-review-section="true"]'
		);

		expect( reviewSection ).not.toBeNull();
		expect( sidebar.textContent ).toContain( 'Style Book' );
		expect( sidebar.textContent ).toContain( 'Paragraph' );
		expect( sidebar.textContent ).toContain( 'Review first' );
		expect( sidebar.textContent ).toContain( 'Spacing' );
		expect( sidebar.textContent ).toContain( 'Review open' );
		expect( sidebar.textContent ).toContain( 'spacing.blockGap → 40' );
		expect( mockRenderAIReviewSection ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				title: 'Review Before Apply',
				statusLabel: 'Review first',
				confirmLabel: 'Confirm Apply',
				onCancel: expect.any( Function ),
				onConfirm: expect.any( Function ),
			} )
		);
		expect( sidebar.textContent ).not.toContain(
			'Raw CSS and custom CSS are out of scope.'
		);
		expect( sidebar.textContent ).not.toContain(
			'Preview the exact operations before applying them to Paragraph.'
		);
	} );

	test( 'shows a stale scope badge when the stored Style Book result context mismatches', () => {
		currentStoreState = {
			...currentStoreState,
			recommendations: [
				{
					label: 'Tighten paragraph rhythm',
					description: 'Keep the example more compact.',
					category: 'spacing',
					tone: 'executable',
					operations: [],
				},
			],
			explanation: 'Existing explanation',
			status: 'ready',
			resultRef: 'style_book:17:core/paragraph',
			contextSignature: 'stale-signature',
		};

		act( () => {
			getRoot().render( <StyleBookRecommender /> );
		} );

		expect( sidebar.textContent ).toContain( 'Style Book' );
		expect( sidebar.textContent ).toContain( 'Paragraph' );
		expect( sidebar.textContent ).toContain( 'Stale' );
		expect( sidebar.textContent ).toContain(
			'This Style Book result no longer matches the current live block styles or prompt. Refresh before reviewing or applying anything from the previous result.'
		);
		expect( sidebar.textContent ).toContain( 'Tighten paragraph rhythm' );
		expect(
			Array.from( sidebar.querySelectorAll( 'button' ) ).find(
				( button ) => button.textContent === 'Review'
			)?.disabled
		).toBe( true );
		expect(
			sidebar
				.querySelector( '.flavor-agent-scope-bar' )
				?.getAttribute( 'role' )
		).toBe( 'status' );
	} );

	test( 'renders the Style Book explanation after the primary recommendation hero', () => {
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
					operations: [],
				},
			],
			explanation: 'Push spacing slightly further for the example block.',
			status: 'ready',
			resultRef: 'style_book:17:core/paragraph',
			contextSignature: buildContextSignature(),
			selectedSuggestionKey: null,
		};

		act( () => {
			getRoot().render( <StyleBookRecommender /> );
		} );

		expect(
			sidebar
				.querySelector( '.flavor-agent-recommendation-hero' )
				?.compareDocumentPosition(
					sidebar.querySelector( '.flavor-agent-panel__note' )
				)
		).toBe( DOCUMENT_POSITION_FOLLOWING );
	} );

	test( 'does not show the current scope badge when the latest Style Book request failed', () => {
		currentStoreState = {
			...currentStoreState,
			recommendations: [],
			explanation: '',
			status: 'error',
			error: 'Request failed.',
			resultRef: 'style_book:17:core/paragraph',
			contextSignature: buildContextSignature(),
		};

		act( () => {
			getRoot().render( <StyleBookRecommender /> );
		} );

		expect( sidebar.textContent ).toContain( 'Request failed.' );
		expect( sidebar.textContent ).not.toContain( 'Current' );
	} );

	test( 'treats an empty successful Style Book response as a current result', () => {
		currentStoreState = {
			...currentStoreState,
			recommendations: [],
			explanation: '',
			status: 'ready',
			resultRef: 'style_book:17:core/paragraph',
			contextSignature: buildContextSignature(),
		};

		act( () => {
			getRoot().render( <StyleBookRecommender /> );
		} );

		expect( sidebar.textContent ).toContain( 'Current' );
		expect( sidebar.textContent ).toContain(
			'No safe Style Book changes were returned for this prompt.'
		);
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
			getRoot().render( <StyleBookRecommender /> );
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

	test( 'preserves stale recommendations after template viewport visibility changes', () => {
		currentStoreState = {
			...currentStoreState,
			recommendations: [
				{
					label: 'Tighten paragraph rhythm',
					description: 'Keep the example more compact.',
					category: 'spacing',
					tone: 'executable',
					operations: [],
				},
			],
			explanation: 'Existing explanation',
			status: 'ready',
			resultRef: 'style_book:17:core/paragraph',
			contextSignature: buildContextSignature(),
		};

		act( () => {
			getRoot().render( <StyleBookRecommender /> );
		} );

		expect( sidebar.textContent ).toContain( 'Tighten paragraph rhythm' );
		expect( mockClearStyleBookRecommendations ).not.toHaveBeenCalled();

		currentEditedBlocks = [
			{
				name: 'core/template-part',
				attributes: {
					metadata: {
						blockVisibility: {
							viewport: {
								mobile: false,
								desktop: true,
							},
						},
					},
				},
				innerBlocks: [
					{
						name: 'core/site-title',
						innerBlocks: [],
					},
				],
			},
			{
				name: 'core/group',
				innerBlocks: [
					{
						name: 'core/query-title',
						innerBlocks: [],
					},
				],
			},
		];

		act( () => {
			getRoot().render( <StyleBookRecommender /> );
		} );

		expect( mockClearStyleBookRecommendations ).not.toHaveBeenCalled();
		expect( sidebar.textContent ).toContain( 'Tighten paragraph rhythm' );
		expect( sidebar.textContent ).toContain( 'Stale' );
	} );

	test( 'preserves stale recommendations after surrounding semantic context changes without structure drift', () => {
		currentStoreState = {
			...currentStoreState,
			recommendations: [
				{
					label: 'Tighten paragraph rhythm',
					description: 'Keep the example more compact.',
					category: 'spacing',
					tone: 'executable',
					operations: [],
				},
			],
			explanation: 'Existing explanation',
			status: 'ready',
			resultRef: 'style_book:17:core/paragraph',
			contextSignature: buildContextSignature(),
		};

		act( () => {
			getRoot().render( <StyleBookRecommender /> );
		} );

		expect( sidebar.textContent ).toContain( 'Tighten paragraph rhythm' );
		expect( mockClearStyleBookRecommendations ).not.toHaveBeenCalled();

		currentEditedBlocks = [
			{
				name: 'core/template-part',
				attributes: {
					slug: 'header',
					area: 'header',
				},
				innerBlocks: [
					{
						name: 'core/heading',
						innerBlocks: [],
					},
					{
						name: 'core/paragraph',
						innerBlocks: [],
					},
					{
						name: 'core/site-title',
						innerBlocks: [],
					},
				],
			},
			{
				name: 'core/group',
				innerBlocks: [
					{
						name: 'core/query-title',
						innerBlocks: [],
					},
				],
			},
		];

		act( () => {
			getRoot().render( <StyleBookRecommender /> );
		} );

		expect( mockClearStyleBookRecommendations ).not.toHaveBeenCalled();
		expect( sidebar.textContent ).toContain( 'Tighten paragraph rhythm' );
		expect( sidebar.textContent ).toContain( 'Stale' );
	} );
} );
