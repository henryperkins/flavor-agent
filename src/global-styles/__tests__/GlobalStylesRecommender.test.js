const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockFetchGlobalStylesRecommendations = jest.fn();
const mockApplyGlobalStylesSuggestion = jest.fn();
const mockClearGlobalStylesRecommendations = jest.fn();
const mockSetGlobalStylesSelectedSuggestion = jest.fn();
const mockUndoActivity = jest.fn();
const mockGetGlobalStylesUserConfig = jest.fn();
const mockGetGlobalStylesActivityUndoState = jest.fn();

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
jest.mock( '../../components/AIStatusNotice', () => () => null );
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
			settingsKey: 'features',
			reason: 'stable-parity',
		},
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
	themeTokenDiagnostics = getCurrentThemeTokenDiagnostics()
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
					getSurfaceStatusNotice: () => null,
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
} );
