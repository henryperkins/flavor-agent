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

jest.mock( '@wordpress/components', () => {
	const { createElement } = require( '@wordpress/element' );

	return {
		Button: ( { children, disabled, onClick, ...props } ) =>
			createElement(
				'button',
				{
					type: 'button',
					disabled,
					onClick,
					...props,
				},
				children
			),
		TextareaControl: ( { label, value, onChange } ) =>
			createElement(
				'label',
				{},
				label,
				createElement( 'textarea', {
					value,
					onInput: ( event ) => onChange( event.target.value ),
					onChange: ( event ) => onChange( event.target.value ),
				} )
			),
	};
} );

jest.mock( '@wordpress/data', () => ( {
	useDispatch: ( ...args ) => mockUseDispatch( ...args ),
	useSelect: ( ...args ) => mockUseSelect( ...args ),
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
const { createRoot } = require( '@wordpress/element' );
const {
	buildGlobalStylesRecommendationContextSignature,
} = require( '../../utils/style-operations' );

import GlobalStylesRecommender from '../GlobalStylesRecommender';

let container = null;
let root = null;
let sidebar = null;
let currentBlockEditorSettings = null;
let currentGlobalStylesData = null;
let currentStoreState = null;

window.IS_REACT_ACT_ENVIRONMENT = true;

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
		},
		currentConfig: globalStylesData.userConfig,
		mergedConfig: globalStylesData.mergedConfig,
		availableVariations: globalStylesData.variations,
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

	mockGetGlobalStylesUserConfig.mockImplementation(
		() => currentGlobalStylesData
	);
	mockGetGlobalStylesActivityUndoState.mockReturnValue( {
		canUndo: true,
		status: 'available',
		error: null,
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

describe( 'GlobalStylesRecommender', () => {
	test( 'submits a scoped style recommendation request from the Styles sidebar', () => {
		act( () => {
			root.render( <GlobalStylesRecommender /> );
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
					themeTokenDiagnostics: {
						source: 'stable',
						settingsKey: 'features',
						reason: 'stable-parity',
					},
				} ),
			} )
		);
	} );

	test( 'mounts into the Styles sidebar after it appears later', async () => {
		sidebar.remove();

		act( () => {
			root.render( <GlobalStylesRecommender /> );
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

	test( 'mounts into the WP 7.0 Styles region when the legacy sidebar class is absent', () => {
		sidebar.remove();
		sidebar = document.createElement( 'div' );
		sidebar.setAttribute( 'role', 'region' );
		sidebar.setAttribute( 'aria-label', 'Styles' );
		document.body.appendChild( sidebar );

		act( () => {
			root.render( <GlobalStylesRecommender /> );
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
			root.render( <GlobalStylesRecommender /> );
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
			root.render( <GlobalStylesRecommender /> );
		} );

		expect( mockClearGlobalStylesRecommendations ).not.toHaveBeenCalled();

		currentGlobalStylesData = createGlobalStylesData( '18' );

		act( () => {
			root.render( <GlobalStylesRecommender /> );
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
			root.render( <GlobalStylesRecommender /> );
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
			root.render( <GlobalStylesRecommender /> );
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
			root.render( <GlobalStylesRecommender /> );
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
			root.render( <GlobalStylesRecommender /> );
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
			root.render( <GlobalStylesRecommender /> );
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
			root.render( <GlobalStylesRecommender /> );
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
			root.render( <GlobalStylesRecommender /> );
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
			root.render( <GlobalStylesRecommender /> );
		} );

		expect( mockClearGlobalStylesRecommendations ).toHaveBeenCalledTimes(
			1
		);
		expect( sidebar.textContent ).not.toContain( 'Use accent canvas' );
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
			root.render( <GlobalStylesRecommender /> );
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
			root.render( <GlobalStylesRecommender /> );
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
} );
