const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockFetchGlobalStylesRecommendations = jest.fn();
const mockApplyGlobalStylesSuggestion = jest.fn();
const mockClearGlobalStylesRecommendations = jest.fn();
const mockSetGlobalStylesSelectedSuggestion = jest.fn();
const mockUndoActivity = jest.fn();
const mockGetGlobalStylesUserConfig = jest.fn();
const mockGetGlobalStylesActivityUndoState = jest.fn();
const mockRenderAIStatusNotice = jest.fn();
const mockRenderAIActivitySection = jest.fn();
const mockGetStyleBookUiState = jest.fn();
const mockSubscribeToStyleBookUi = jest.fn();
const DEFAULT_EXECUTION_CONTRACT = {
	supportedStylePaths: [
		{
			path: [ 'color', 'background' ],
			valueSource: 'color',
		},
		{
			path: [ 'color', 'text' ],
			valueSource: 'color',
		},
	],
	presetSlugs: {
		color: [ 'accent', 'base', 'contrast' ],
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
jest.mock( '../../components/AIActivitySection', () => {
	const { createElement } = require( '@wordpress/element' );

	return ( props ) => {
		mockRenderAIActivitySection( props );

		return createElement( 'div', {
			'data-activity-section': 'true',
			'data-is-undoing': props.isUndoing ? 'true' : 'false',
		} );
	};
} );
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

jest.mock( '../../style-book/dom', () => ( {
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

jest.mock( '../../context/theme-tokens', () => ( {
	collectThemeTokenDiagnosticsFromSettings: ( settings = {} ) =>
		settings?.__diagnostics || {
			source: 'stable',
			settingsKey: 'features',
			reason: 'stable-parity',
		},
	buildGlobalStylesExecutionContractFromSettings: ( settings = {} ) =>
		settings?.__executionContract || DEFAULT_EXECUTION_CONTRACT,
} ) );

jest.mock( '../../utils/style-operations', () => ( {
	...jest.requireActual( '../../utils/style-operations' ),
	getGlobalStylesUserConfig: ( ...args ) =>
		mockGetGlobalStylesUserConfig( ...args ),
	getGlobalStylesActivityUndoState: ( ...args ) =>
		mockGetGlobalStylesActivityUndoState( ...args ),
} ) );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );
const {
	buildGlobalStylesRecommendationContextSignature,
} = require( '../../utils/style-operations' );

import GlobalStylesRecommender from '../GlobalStylesRecommender';

const { getContainer, getRoot } = setupReactTest();

let sidebar = null;
let currentBlockEditorSettings = null;
let currentGlobalStylesData = null;
let currentStoreState = null;
let currentStyleBookUiState = null;
let currentEditedTemplateId = null;
let currentEditedBlocks = null;

function createGlobalStylesData( globalStylesId = '17' ) {
	return {
		globalStylesId,
		userConfig: {
			settings: {},
			styles: {
				color: {
					background: 'var:preset|color|base',
				},
			},
			_links: {},
		},
		mergedConfig: {
			settings: {},
			styles: {
				color: {
					background: 'var:preset|color|base',
					text: 'var:preset|color|contrast',
				},
			},
			_links: {},
		},
		variations: [
			{
				title: 'Default',
				settings: {},
				styles: {},
			},
			{
				title: 'Midnight',
				settings: {},
				styles: {
					color: {
						background: 'var:preset|color|accent',
					},
				},
			},
		],
	};
}

function createEditedBlocks() {
	return [
		{
			name: 'core/template-part',
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
}

function buildExpectedTemplateStructure() {
	return [
		{
			name: 'core/template-part',
			innerBlocks: [ { name: 'core/site-title' } ],
		},
		{
			name: 'core/group',
			innerBlocks: [ { name: 'core/query-title' } ],
		},
	];
}

function getCurrentThemeTokenDiagnostics() {
	return (
		currentBlockEditorSettings?.__diagnostics || {
			source: 'stable',
			settingsKey: 'features',
			reason: 'stable-parity',
		}
	);
}

function buildContextSignature(
	globalStylesData,
	themeTokenDiagnostics = getCurrentThemeTokenDiagnostics(),
	executionContract = currentBlockEditorSettings?.__executionContract ||
		DEFAULT_EXECUTION_CONTRACT
) {
	return buildGlobalStylesRecommendationContextSignature( {
		scope: {
			scopeKey: `global_styles:${ globalStylesData.globalStylesId }`,
			globalStylesId: globalStylesData.globalStylesId,
			stylesheet: '',
			templateSlug: currentEditedTemplateId,
			templateType: 'home',
		},
		currentConfig: globalStylesData.userConfig,
		mergedConfig: globalStylesData.mergedConfig,
		availableVariations: globalStylesData.variations,
		templateStructure: buildExpectedTemplateStructure(),
		themeTokenDiagnostics,
		executionContract,
	} );
}

beforeEach( () => {
	jest.clearAllMocks();
	currentBlockEditorSettings = {
		__diagnostics: {
			source: 'stable',
			settingsKey: 'features',
			reason: 'stable-parity',
		},
		__executionContract: DEFAULT_EXECUTION_CONTRACT,
	};
	currentGlobalStylesData = createGlobalStylesData();
	currentEditedTemplateId = 'theme//home';
	currentEditedBlocks = createEditedBlocks();
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
		isActive: false,
		target: null,
	};

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

			if ( storeName === 'flavor-agent' ) {
				return {
					getActivityLog: () => currentStoreState.activityLog,
					getGlobalStylesRecommendations: () =>
						currentStoreState.recommendations,
					getGlobalStylesExplanation: () =>
						currentStoreState.explanation,
					getGlobalStylesStatus: () => currentStoreState.status,
					getGlobalStylesError: () => currentStoreState.error,
					getGlobalStylesResultRef: () => currentStoreState.resultRef,
					getGlobalStylesContextSignature: () =>
						currentStoreState.contextSignature,
					getGlobalStylesSelectedSuggestionKey: () =>
						currentStoreState.selectedSuggestionKey,
					getGlobalStylesApplyStatus: () =>
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

						if ( options.undoError ) {
							return {
								source: 'undo',
								tone: 'error',
								message: options.undoError,
							};
						}

						if ( options.undoSuccessMessage ) {
							return {
								source: 'undo',
								tone: 'success',
								message: options.undoSuccessMessage,
							};
						}

						if ( options.applyError ) {
							return {
								source: 'apply',
								tone: 'error',
								message: options.applyError,
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
		fetchGlobalStylesRecommendations: mockFetchGlobalStylesRecommendations,
		applyGlobalStylesSuggestion: mockApplyGlobalStylesSuggestion,
		clearGlobalStylesRecommendations: mockClearGlobalStylesRecommendations,
		setGlobalStylesSelectedSuggestion:
			mockSetGlobalStylesSelectedSuggestion,
		undoActivity: mockUndoActivity,
	} ) );


	sidebar = document.createElement( 'div' );
	sidebar.className = 'editor-global-styles-sidebar__panel';
	document.body.appendChild( sidebar );
} );

afterEach( () => {
	sidebar.remove();
} );

describe( 'GlobalStylesRecommender', () => {
	test( 'stays hidden while the Style Book surface is active', () => {
		currentStyleBookUiState = {
			isActive: true,
			target: {
				blockName: 'core/paragraph',
				blockTitle: 'Paragraph',
			},
		};

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		expect( getContainer().textContent ).toBe( '' );
		expect(
			sidebar.querySelector( '.flavor-agent-global-styles-sidebar-slot' )
		).toBeNull();
	} );

	test( 'submits a scoped style recommendation request from the Styles sidebar', () => {
		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		const textarea = sidebar.querySelector( 'textarea' );
		const button = sidebar.querySelector( 'button' );

		expect( textarea ).not.toBeNull();
		expect( button ).not.toBeNull();

		act( () => {
			const descriptor = Object.getOwnPropertyDescriptor(
				window.HTMLTextAreaElement.prototype,
				'value'
			);

			descriptor.set.call(
				textarea,
				'Make the site feel more editorial.'
			);
			textarea.dispatchEvent(
				new window.Event( 'input', { bubbles: true } )
			);
		} );

		act( () => {
			button.click();
		} );

		expect( mockFetchGlobalStylesRecommendations ).toHaveBeenCalledWith(
			expect.objectContaining( {
				prompt: 'Make the site feel more editorial.',
				scope: expect.objectContaining( {
					surface: 'global-styles',
					scopeKey: 'global_styles:17',
					globalStylesId: '17',
					templateSlug: 'theme//home',
					templateType: 'home',
				} ),
				styleContext: expect.objectContaining( {
					mergedConfig: {
						settings: {},
						styles: {
							color: {
								background: 'var:preset|color|base',
								text: 'var:preset|color|contrast',
							},
						},
						_links: {},
					},
					availableVariations: expect.any( Array ),
					templateStructure: buildExpectedTemplateStructure(),
					themeTokenDiagnostics: {
						source: 'stable',
						settingsKey: 'features',
						reason: 'stable-parity',
					},
				} ),
			} )
		);
	} );

	test( 'submits a scoped style recommendation request when the prompt is empty', () => {
		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		const button = sidebar.querySelector( 'button' );

		expect( button ).not.toBeNull();
		expect( button.disabled ).toBe( false );

		act( () => {
			button.click();
		} );

		expect( mockFetchGlobalStylesRecommendations ).toHaveBeenCalledWith(
			expect.objectContaining( {
				scope: expect.objectContaining( {
					surface: 'global-styles',
					scopeKey: 'global_styles:17',
					globalStylesId: '17',
					templateSlug: 'theme//home',
					templateType: 'home',
				} ),
				styleContext: expect.objectContaining( {
					availableVariations: expect.any( Array ),
					templateStructure: buildExpectedTemplateStructure(),
					themeTokenDiagnostics: {
						source: 'stable',
						settingsKey: 'features',
						reason: 'stable-parity',
					},
				} ),
			} )
		);
		expect(
			mockFetchGlobalStylesRecommendations.mock.calls[ 0 ][ 0 ]
		).not.toHaveProperty( 'prompt' );
	} );

	test( 'mounts into the Styles sidebar after it appears later', async () => {
		sidebar.remove();

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		expect(
			document.body.querySelector(
				'.editor-global-styles-sidebar__panel textarea'
			)
		).toBeNull();

		sidebar = document.createElement( 'div' );
		sidebar.className = 'editor-global-styles-sidebar__panel';

		await act( async () => {
			document.body.appendChild( sidebar );
			await Promise.resolve();
		} );

		expect( sidebar.querySelector( 'textarea' ) ).not.toBeNull();
		expect(
			sidebar.querySelector( '.flavor-agent-global-styles-sidebar-slot' )
		).not.toBeNull();
	} );

	test( 'mounts into the Styles sidebar wrapper when the panel class is absent', () => {
		sidebar.remove();
		sidebar = document.createElement( 'div' );
		sidebar.className = 'editor-global-styles-sidebar';
		document.body.appendChild( sidebar );

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		expect( sidebar.querySelector( 'textarea' ) ).not.toBeNull();
		expect(
			sidebar.querySelector( '.flavor-agent-global-styles-sidebar-slot' )
		).not.toBeNull();
	} );

	test( 'mounts into the legacy Styles region as a last-resort fallback', () => {
		sidebar.remove();
		sidebar = document.createElement( 'div' );
		sidebar.setAttribute( 'role', 'region' );
		sidebar.setAttribute( 'aria-label', 'Styles' );
		document.body.appendChild( sidebar );

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		expect( sidebar.querySelector( 'textarea' ) ).not.toBeNull();
		expect(
			sidebar.querySelector( '.flavor-agent-global-styles-sidebar-slot' )
		).not.toBeNull();
	} );

	test( 'does not render stale results from another Global Styles entity', () => {
		currentGlobalStylesData = createGlobalStylesData( '18' );
		currentStoreState = {
			...currentStoreState,
			recommendations: [
				{
					label: 'Use accent canvas',
					description:
						'Apply the accent preset to the site background.',
					category: 'color',
					tone: 'executable',
					operations: [
						{
							type: 'set_styles',
							path: [ 'color', 'background' ],
							value: 'var:preset|color|accent',
							presetSlug: 'accent',
						},
					],
				},
			],
			explanation: 'Prefer accent palette values.',
			status: 'ready',
			resultRef: '17',
			contextSignature: buildContextSignature(
				createGlobalStylesData( '17' )
			),
		};

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		expect( sidebar.textContent ).not.toContain( 'Use accent canvas' );
		expect( sidebar.textContent ).not.toContain(
			'Prefer accent palette values.'
		);
		expect( sidebar.querySelectorAll( 'button' ).length ).toBeGreaterThan(
			0
		);
	} );

	test( 'clears stale results after the active Global Styles entity changes', () => {
		currentStoreState = {
			...currentStoreState,
			recommendations: [
				{
					label: 'Use accent canvas',
					description:
						'Apply the accent preset to the site background.',
					category: 'color',
					tone: 'executable',
					operations: [],
				},
			],
			explanation: 'Prefer accent palette values.',
			status: 'ready',
			resultRef: '17',
			contextSignature: buildContextSignature(
				createGlobalStylesData( '17' )
			),
		};

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		expect( mockClearGlobalStylesRecommendations ).not.toHaveBeenCalled();

		currentGlobalStylesData = createGlobalStylesData( '18' );

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		expect( mockClearGlobalStylesRecommendations ).toHaveBeenCalledTimes(
			1
		);
	} );

	test( 'clears stale results after the active Global Styles config changes on the same entity', () => {
		currentStoreState = {
			...currentStoreState,
			recommendations: [
				{
					label: 'Use accent canvas',
					description:
						'Apply the accent preset to the site background.',
					category: 'color',
					tone: 'executable',
					operations: [],
				},
			],
			explanation: 'Prefer accent palette values.',
			status: 'ready',
			resultRef: '17',
			contextSignature: buildContextSignature(
				createGlobalStylesData( '17' )
			),
		};

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		expect( sidebar.textContent ).toContain( 'Use accent canvas' );
		expect( mockClearGlobalStylesRecommendations ).not.toHaveBeenCalled();

		currentGlobalStylesData = {
			...createGlobalStylesData( '17' ),
			mergedConfig: {
				settings: {},
				styles: {
					color: {
						background: 'var:preset|color|base',
						text: 'var:preset|color|brand',
					},
				},
				_links: {},
			},
		};

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		expect( mockClearGlobalStylesRecommendations ).toHaveBeenCalledTimes(
			1
		);
		expect( sidebar.textContent ).not.toContain( 'Use accent canvas' );
		expect( sidebar.textContent ).not.toContain(
			'Prefer accent palette values.'
		);
	} );

	test( 'clears stale results after block editor settings change the theme token diagnostics', () => {
		currentStoreState = {
			...currentStoreState,
			recommendations: [
				{
					label: 'Use accent canvas',
					description:
						'Apply the accent preset to the site background.',
					category: 'color',
					tone: 'executable',
					operations: [],
				},
			],
			explanation: 'Prefer accent palette values.',
			status: 'ready',
			resultRef: '17',
			contextSignature: buildContextSignature(
				createGlobalStylesData( '17' )
			),
		};

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		expect( sidebar.textContent ).toContain( 'Use accent canvas' );
		expect( mockClearGlobalStylesRecommendations ).not.toHaveBeenCalled();

		currentBlockEditorSettings = {
			__diagnostics: {
				source: 'stable-fallback',
				settingsKey: 'features',
				reason: 'stable-with-experimental-gaps',
			},
			__executionContract: DEFAULT_EXECUTION_CONTRACT,
		};

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		expect( mockClearGlobalStylesRecommendations ).toHaveBeenCalledTimes(
			1
		);
		expect( sidebar.textContent ).not.toContain( 'Use accent canvas' );
		expect( sidebar.textContent ).not.toContain(
			'Prefer accent palette values.'
		);
	} );

	test( 'clears stale results after supported style paths change without diagnostics drift', () => {
		currentStoreState = {
			...currentStoreState,
			recommendations: [
				{
					label: 'Use accent canvas',
					description:
						'Apply the accent preset to the site background.',
					category: 'color',
					tone: 'executable',
					operations: [],
				},
			],
			explanation: 'Prefer accent palette values.',
			status: 'ready',
			resultRef: '17',
			contextSignature: buildContextSignature(
				createGlobalStylesData( '17' )
			),
		};

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		expect( sidebar.textContent ).toContain( 'Use accent canvas' );
		expect( mockClearGlobalStylesRecommendations ).not.toHaveBeenCalled();

		currentBlockEditorSettings = {
			__diagnostics: {
				source: 'stable',
				settingsKey: 'features',
				reason: 'stable-parity',
			},
			__executionContract: {
				...DEFAULT_EXECUTION_CONTRACT,
				supportedStylePaths: [
					{
						path: [ 'color', 'text' ],
						valueSource: 'color',
					},
				],
			},
		};

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		expect( mockClearGlobalStylesRecommendations ).toHaveBeenCalledTimes(
			1
		);
		expect( sidebar.textContent ).not.toContain( 'Use accent canvas' );
	} );

	test( 'clears stale results after preset slugs change without diagnostics drift', () => {
		currentStoreState = {
			...currentStoreState,
			recommendations: [
				{
					label: 'Use accent canvas',
					description:
						'Apply the accent preset to the site background.',
					category: 'color',
					tone: 'executable',
					operations: [],
				},
			],
			explanation: 'Prefer accent palette values.',
			status: 'ready',
			resultRef: '17',
			contextSignature: buildContextSignature(
				createGlobalStylesData( '17' )
			),
		};

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		expect( sidebar.textContent ).toContain( 'Use accent canvas' );
		expect( mockClearGlobalStylesRecommendations ).not.toHaveBeenCalled();

		currentBlockEditorSettings = {
			__diagnostics: {
				source: 'stable',
				settingsKey: 'features',
				reason: 'stable-parity',
			},
			__executionContract: {
				...DEFAULT_EXECUTION_CONTRACT,
				presetSlugs: {
					color: [ 'base', 'contrast' ],
				},
			},
		};

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		expect( mockClearGlobalStylesRecommendations ).toHaveBeenCalledTimes(
			1
		);
		expect( sidebar.textContent ).not.toContain( 'Use accent canvas' );
	} );

	test( 'renders shared style card badges and review state for executable suggestions', () => {
		currentStoreState = {
			...currentStoreState,
			recommendations: [
				{
					suggestionKey: 'global-style-1',
					label: 'Use accent canvas',
					description:
						'Apply the accent preset to the site background.',
					category: 'color',
					tone: 'executable',
					operations: [
						{
							type: 'set_styles',
							path: [ 'color', 'background' ],
							value: 'var:preset|color|accent',
							presetSlug: 'accent',
						},
					],
				},
			],
			explanation: 'Prefer accent palette values.',
			status: 'ready',
			resultRef: '17',
			contextSignature: buildContextSignature(
				createGlobalStylesData( '17' )
			),
			selectedSuggestionKey: 'global-style-1',
		};

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		expect( sidebar.textContent ).toContain( 'Review to apply' );
		expect( sidebar.textContent ).toContain( 'Color' );
		expect( sidebar.textContent ).toContain( 'Review open' );
		expect( sidebar.textContent ).toContain( 'color.background → accent' );
	} );

	test( 'dispatches undo from the Global Styles apply success notice for the latest activity', () => {
		currentStoreState = {
			...currentStoreState,
			activityLog: [
				{
					id: 'activity-1',
					surface: 'global-styles',
					suggestion: 'Use accent canvas',
					target: {
						globalStylesId: '17',
					},
					undo: {
						canUndo: true,
						status: 'available',
						error: null,
					},
				},
			],
			applyStatus: 'success',
		};

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		const undoButton = Array.from(
			sidebar.querySelectorAll( 'button' )
		).find( ( element ) => element.textContent === 'Undo' );

		expect( undoButton ).toBeDefined();

		act( () => {
			undoButton.click();
		} );

		expect( mockUndoActivity ).toHaveBeenCalledWith( 'activity-1' );
	} );

	test( 'does not mark activity history as undoing while apply is in flight', () => {
		currentStoreState = {
			...currentStoreState,
			activityLog: [
				{
					id: 'activity-1',
					surface: 'global-styles',
					suggestion: 'Use accent canvas',
					target: {
						globalStylesId: '17',
					},
					undo: {
						canUndo: true,
						status: 'available',
						error: null,
					},
				},
			],
			applyStatus: 'applying',
			undoStatus: 'idle',
		};

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		const lastCall =
			mockRenderAIActivitySection.mock.calls[
				mockRenderAIActivitySection.mock.calls.length - 1
			][ 0 ];

		expect( lastCall.isUndoing ).toBe( false );
		expect(
			sidebar.querySelector( '[data-is-undoing="false"]' )
		).not.toBeNull();
	} );

	test( 'does not keep an undo success notice once the resolved style activity is available again', () => {
		currentStoreState = {
			...currentStoreState,
			activityLog: [
				{
					id: 'activity-1',
					surface: 'global-styles',
					suggestion: 'Use accent canvas',
					target: {
						globalStylesId: '17',
					},
					undo: {
						canUndo: true,
						status: 'available',
						error: null,
					},
				},
			],
			undoStatus: 'success',
			lastUndoneActivityId: 'activity-1',
		};

		act( () => {
			getRoot().render( <GlobalStylesRecommender /> );
		} );

		expect( sidebar.textContent ).not.toContain(
			'Flavor Agent restored the previous Global Styles config.'
		);
		expect(
			sidebar.querySelector( '[data-status-notice="true"]' )
		).toBeNull();
	} );
} );
