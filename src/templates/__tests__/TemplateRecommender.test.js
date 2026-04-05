const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockClearTemplateRecommendations = jest.fn();
const mockFetchTemplateRecommendations = jest.fn();
const mockSetTemplateSelectedSuggestion = jest.fn();
const mockUndoActivity = jest.fn();
const mockGetAllowedPatterns = jest.fn();
const mockGetBlockPatterns = jest.fn();
const mockGetTemplateActivityUndoState = jest.fn(
	( activity ) => activity?.undo || {}
);
const mockOpenInserterForPattern = jest.fn();
const mockSelectBlockByArea = jest.fn();
const mockSelectBlockBySlugOrArea = jest.fn();

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
			createElement( 'section', { 'data-panel-title': title }, children ),
	};
} );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

jest.mock( '../../patterns/compat', () => ( {
	getAllowedPatterns: ( ...args ) => mockGetAllowedPatterns( ...args ),
	getBlockPatterns: ( ...args ) => mockGetBlockPatterns( ...args ),
} ) );

jest.mock( '../../utils/template-actions', () => ( {
	getTemplateActivityUndoState: ( ...args ) =>
		mockGetTemplateActivityUndoState( ...args ),
	openInserterForPattern: ( ...args ) =>
		mockOpenInserterForPattern( ...args ),
	selectBlockByArea: ( ...args ) => mockSelectBlockByArea( ...args ),
	selectBlockBySlugOrArea: ( ...args ) =>
		mockSelectBlockBySlugOrArea( ...args ),
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import TemplateRecommender from '../TemplateRecommender';
import {
	getSuggestionCardKey,
	TEMPLATE_OPERATION_INSERT_PATTERN,
} from '../template-recommender-helpers';

const { getContainer, getRoot } = setupReactTest();

const TEMPLATE_REF = 'theme//home';
const NEXT_TEMPLATE_REF = 'theme//single';
const SUGGESTION = {
	label: 'Add hero intro',
	description: 'Insert a hero pattern at the start of the template.',
	operations: [
		{
			type: TEMPLATE_OPERATION_INSERT_PATTERN,
			patternName: 'theme/hero',
			placement: 'start',
		},
	],
};
const SUGGESTION_KEY = getSuggestionCardKey( SUGGESTION, 0 );

let currentState = null;
function getState() {
	return currentState;
}

function getBlockRecord( clientId ) {
	if ( ! clientId ) {
		return null;
	}

	const state = getState();

	return (
		state.blockEditor.blockLookup[ clientId ] || {
			clientId,
			name: 'core/group',
			attributes: {},
			innerBlocks: [],
		}
	);
}

function createSelectors() {
	return {
		editor: {
			getCurrentPostId: jest.fn( () => getState().editor.postId ),
			getCurrentPostType: jest.fn( () => getState().editor.postType ),
		},
		blockEditor: {
			getAllowedPatterns: jest.fn( ( rootClientId ) => {
				const state = getState();
				const key =
					rootClientId === null ? 'null' : String( rootClientId );

				return state.blockEditor.allowedPatternsByRoot[ key ] || [];
			} ),
			getBlock: jest.fn( ( clientId ) => getBlockRecord( clientId ) ),
			getBlockInsertionPoint: jest.fn(
				() => getState().blockEditor.insertionPoint
			),
			getBlocks: jest.fn( () => getState().blockEditor.blocks ),
			getSelectedBlockClientId: jest.fn(
				() => getState().blockEditor.selectedBlockClientId
			),
		},
		editSite: {
			getEditedPostId: jest.fn( () => getState().editSite.postId ),
			getEditedPostType: jest.fn( () => getState().editSite.postType ),
		},
		store: {
			getActivityLog: jest.fn( () => getState().store.activityLog ),
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

				return null;
			} ),
			getTemplateApplyError: jest.fn(
				() => getState().store.templateApplyError
			),
			getTemplateInteractionState: jest.fn( () => 'idle' ),
			getTemplateApplyStatus: jest.fn(
				() => getState().store.templateApplyStatus
			),
			getTemplateError: jest.fn( () => getState().store.templateError ),
			getTemplateExplanation: jest.fn(
				() => getState().store.templateExplanation
			),
			getTemplateLastAppliedOperations: jest.fn(
				() => getState().store.templateLastAppliedOperations
			),
			getTemplateLastAppliedSuggestionKey: jest.fn(
				() => getState().store.templateLastAppliedSuggestionKey
			),
			getTemplateRecommendations: jest.fn(
				() => getState().store.templateRecommendations
			),
			getTemplateResultRef: jest.fn(
				() => getState().store.templateResultRef
			),
			getTemplateResultToken: jest.fn(
				() => getState().store.templateResultToken
			),
			getTemplateSelectedSuggestionKey: jest.fn(
				() => getState().store.templateSelectedSuggestionKey
			),
			getUndoError: jest.fn( () => getState().store.undoError ),
			getUndoStatus: jest.fn( () => getState().store.undoStatus ),
			isTemplateLoading: jest.fn(
				() => getState().store.templateStatus === 'loading'
			),
		},
	};
}

const selectors = createSelectors();

function createDispatchers() {
	return {
		applyTemplateSuggestion: jest.fn(),
		clearTemplateRecommendations: mockClearTemplateRecommendations,
		clearUndoError: jest.fn(),
		fetchTemplateRecommendations: mockFetchTemplateRecommendations,
		setTemplateSelectedSuggestion: mockSetTemplateSelectedSuggestion,
		undoActivity: mockUndoActivity,
	};
}

const dispatchers = createDispatchers();

function createState( overrides = {} ) {
	return {
		editor: {
			postId: TEMPLATE_REF,
			postType: 'wp_template',
			...overrides.editor,
		},
		editSite: {
			postId: TEMPLATE_REF,
			postType: 'wp_template',
			...overrides.editSite,
		},
		blockEditor: {
			allowedPatternsByRoot: {
				null: [ { name: 'theme/hero' }, { name: 'theme/footer' } ],
				'root-a': [ { name: 'theme/hero' }, { name: 'theme/footer' } ],
				'root-b': [ { name: 'theme/footer' } ],
			},
			blockLookup: {
				'root-a': {
					clientId: 'root-a',
					name: 'core/group',
					attributes: {},
					innerBlocks: [],
				},
				'root-b': {
					clientId: 'root-b',
					name: 'core/group',
					attributes: {},
					innerBlocks: [],
				},
			},
			blocks: [
				{
					clientId: 'group-1',
					name: 'core/group',
					attributes: {},
					innerBlocks: [
						{
							clientId: 'part-header',
							name: 'core/template-part',
							attributes: {
								slug: 'site-header',
								area: 'header',
							},
							innerBlocks: [],
						},
					],
				},
				{
					clientId: 'part-footer',
					name: 'core/template-part',
					attributes: {
						area: 'footer',
					},
					innerBlocks: [],
				},
			],
			insertionPoint: {
				rootClientId: 'root-a',
				index: 0,
			},
			selectedBlockClientId: null,
			...overrides.blockEditor,
		},
		store: {
			activityLog: [],
			lastUndoneActivityId: null,
			templateApplyError: 'The previous apply state should be cleared.',
			templateApplyStatus: 'error',
			templateError: null,
			templateExplanation:
				'A focused hero pattern would strengthen the intro.',
			templateLastAppliedOperations: [],
			templateLastAppliedSuggestionKey: null,
			templateRecommendations: [ SUGGESTION ],
			templateResultRef: TEMPLATE_REF,
			templateResultToken: 1,
			templateSelectedSuggestionKey: SUGGESTION_KEY,
			templateStatus: 'ready',
			undoError: null,
			undoStatus: 'idle',
			...overrides.store,
		},
	};
}

function selectStore( storeName ) {
	if ( storeName === 'core/editor' ) {
		return selectors.editor;
	}

	if ( storeName === 'core/block-editor' ) {
		return selectors.blockEditor;
	}

	if ( storeName === 'core/edit-site' ) {
		return selectors.editSite;
	}

	if ( storeName === 'flavor-agent' ) {
		return selectors.store;
	}

	return {};
}

function hasText( value ) {
	return getContainer().textContent.includes( value );
}

function getTextarea() {
	return getContainer().querySelector( 'textarea' );
}

async function setPromptValue( value ) {
	const textarea = getTextarea();

	await act( async () => {
		const descriptor = Object.getOwnPropertyDescriptor(
			window.HTMLTextAreaElement.prototype,
			'value'
		);

		descriptor.set.call( textarea, value );
		textarea.dispatchEvent(
			new window.Event( 'input', { bubbles: true } )
		);
	} );
}

async function renderPanel() {
	await act( async () => {
		getRoot().render( <TemplateRecommender /> );
	} );
}

beforeEach( async () => {
	jest.clearAllMocks();
	currentState = createState();
	mockGetTemplateActivityUndoState.mockImplementation(
		( activity ) => activity?.undo || {}
	);
	window.flavorAgentData = {
		canRecommendTemplates: true,
	};
	mockGetBlockPatterns.mockReturnValue( [
		{
			name: 'theme/hero',
			title: 'Hero',
		},
		{
			name: 'theme/footer',
			title: 'Footer',
		},
	] );
	mockGetAllowedPatterns.mockImplementation(
		( rootClientId, blockEditor ) =>
			blockEditor?.getAllowedPatterns?.( rootClientId ) || []
	);
	mockUseDispatch.mockImplementation( () => dispatchers );
	mockUseSelect.mockImplementation( ( mapSelect ) =>
		mapSelect( selectStore )
	);
	mockSetTemplateSelectedSuggestion.mockImplementation( ( suggestionKey ) => {
		currentState = {
			...getState(),
			store: {
				...getState().store,
				templateSelectedSuggestionKey: suggestionKey ?? null,
			},
		};
	} );
	mockClearTemplateRecommendations.mockImplementation( () => {
		currentState = {
			...getState(),
			store: {
				...getState().store,
				templateRecommendations: [],
				templateExplanation: '',
				templateError: null,
				templateStatus: 'idle',
				templateResultRef: null,
				templateResultToken: getState().store.templateResultToken + 1,
				templateSelectedSuggestionKey: null,
				templateApplyStatus: 'idle',
				templateApplyError: null,
				templateLastAppliedSuggestionKey: null,
				templateLastAppliedOperations: [],
			},
		};
	} );
	await renderPanel();
} );

afterEach( async () => {
	delete window.flavorAgentData;
	currentState = null;
} );

describe( 'TemplateRecommender', () => {
	test( 'clears stale recommendations when template-global visible patterns change without resetting the prompt', async () => {
		expect( hasText( 'Add hero intro' ) ).toBe( true );
		expect( hasText( 'Confirm Apply' ) ).toBe( true );
		expect( hasText( 'The previous apply state should be cleared.' ) ).toBe(
			true
		);

		await setPromptValue( 'Keep this prompt.' );
		expect( getTextarea().value ).toBe( 'Keep this prompt.' );

		currentState = {
			...getState(),
			blockEditor: {
				...getState().blockEditor,
				allowedPatternsByRoot: {
					...getState().blockEditor.allowedPatternsByRoot,
					null: [ { name: 'theme/footer' } ],
				},
			},
		};

		await renderPanel();
		await renderPanel();

		expect( mockClearTemplateRecommendations ).toHaveBeenCalledTimes( 1 );
		expect( hasText( 'Add hero intro' ) ).toBe( false );
		expect( hasText( 'Confirm Apply' ) ).toBe( false );
		expect( hasText( 'The previous apply state should be cleared.' ) ).toBe(
			false
		);
		expect( getTextarea().value ).toBe( 'Keep this prompt.' );
	} );

	test( 'does not clear when visible patterns only reorder', async () => {
		currentState = {
			...getState(),
			blockEditor: {
				...getState().blockEditor,
				allowedPatternsByRoot: {
					...getState().blockEditor.allowedPatternsByRoot,
					null: [
						{ name: 'theme/footer' },
						{ name: 'theme/hero' },
						{ name: 'theme/footer' },
					],
				},
			},
		};

		await renderPanel();

		expect( mockClearTemplateRecommendations ).not.toHaveBeenCalled();
		expect( hasText( 'Add hero intro' ) ).toBe( true );
	} );

	test( 'submits live override and viewport metadata with template recommendation requests', async () => {
		currentState = createState( {
			blockEditor: {
				...getState().blockEditor,
				blocks: [
					{
						clientId: 'group-1',
						name: 'core/group',
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
								clientId: 'heading-1',
								name: 'core/heading',
								attributes: {
									metadata: {
										bindings: {
											content: {
												source: 'core/pattern-overrides',
											},
										},
									},
								},
								innerBlocks: [],
							},
						],
					},
				],
			},
		} );

		await renderPanel();

		await act( async () => {
			Array.from( getContainer().querySelectorAll( 'button' ) )
				.find( ( element ) => element.textContent === 'Get Suggestions' )
				.click();
		} );

		expect( mockFetchTemplateRecommendations ).toHaveBeenCalledWith(
			expect.objectContaining( {
				templateRef: TEMPLATE_REF,
				editorStructure: expect.objectContaining( {
					currentPatternOverrides: {
						hasOverrides: true,
						blockCount: 1,
						blockNames: [ 'core/heading' ],
						blocks: [
							{
								path: [ 0, 0 ],
								name: 'core/heading',
								label: 'Heading',
								overrideAttributes: [ 'content' ],
								usesDefaultBinding: false,
							},
						],
					},
					currentViewportVisibility: {
						hasVisibilityRules: true,
						blockCount: 1,
						blocks: [
							{
								path: [ 0 ],
								name: 'core/group',
								label: 'Group',
								hiddenViewports: [ 'mobile' ],
								visibleViewports: [ 'desktop' ],
							},
						],
					},
				} ),
			} )
		);
	} );

	test( 'clears stale recommendations when live override metadata changes', async () => {
		currentState = createState( {
			blockEditor: {
				...getState().blockEditor,
				blocks: [
					{
						clientId: 'group-1',
						name: 'core/group',
						attributes: {},
						innerBlocks: [
							{
								clientId: 'heading-1',
								name: 'core/heading',
								attributes: {
									metadata: {
										bindings: {
											content: {
												source: 'core/pattern-overrides',
											},
										},
									},
								},
								innerBlocks: [],
							},
						],
					},
				],
			},
		} );

		await renderPanel();
		expect( hasText( 'Add hero intro' ) ).toBe( true );

		currentState = {
			...getState(),
			blockEditor: {
				...getState().blockEditor,
				blocks: [
					{
						clientId: 'group-1',
						name: 'core/group',
						attributes: {},
						innerBlocks: [
							{
								clientId: 'heading-1',
								name: 'core/heading',
								attributes: {},
								innerBlocks: [],
							},
						],
					},
				],
			},
		};

		await renderPanel();
		await renderPanel();

		expect( mockClearTemplateRecommendations ).toHaveBeenCalledTimes( 1 );
		expect( hasText( 'Add hero intro' ) ).toBe( false );
	} );

	test( 'clears stale recommendations when the top-level template structure changes without changing slots', async () => {
		expect( hasText( 'Add hero intro' ) ).toBe( true );

		currentState = {
			...getState(),
			blockEditor: {
				...getState().blockEditor,
				blocks: [
					{
						clientId: 'cover-1',
						name: 'core/cover',
						attributes: {
							align: 'full',
						},
						innerBlocks: [],
					},
					...getState().blockEditor.blocks,
				],
			},
		};

		await renderPanel();
		await renderPanel();

		expect( mockClearTemplateRecommendations ).toHaveBeenCalledTimes( 1 );
		expect( hasText( 'Add hero intro' ) ).toBe( false );
		expect( hasText( 'Confirm Apply' ) ).toBe( false );
	} );

	test( 'clears recommendations and resets the prompt when the template changes', async () => {
		await setPromptValue( 'Reset this prompt on template switch.' );
		expect( getTextarea().value ).toBe(
			'Reset this prompt on template switch.'
		);

		currentState = {
			...getState(),
			editor: {
				postId: NEXT_TEMPLATE_REF,
				postType: 'wp_template',
			},
			editSite: {
				postId: NEXT_TEMPLATE_REF,
				postType: 'wp_template',
			},
		};

		await renderPanel();
		await renderPanel();

		expect( mockClearTemplateRecommendations ).toHaveBeenCalledTimes( 1 );
		expect( hasText( 'Add hero intro' ) ).toBe( false );
		expect( getTextarea().value ).toBe( '' );
	} );

	test( 'clears an in-flight request when the context changes while loading', async () => {
		currentState = createState( {
			store: {
				templateApplyError: null,
				templateApplyStatus: 'idle',
				templateExplanation: '',
				templateRecommendations: [],
				templateResultRef: null,
				templateSelectedSuggestionKey: null,
				templateStatus: 'loading',
			},
		} );

		await renderPanel();
		expect( hasText( 'Analyzing template structure…' ) ).toBe( true );

		currentState = {
			...getState(),
			blockEditor: {
				...getState().blockEditor,
				allowedPatternsByRoot: {
					...getState().blockEditor.allowedPatternsByRoot,
					null: [ { name: 'theme/footer' } ],
				},
			},
		};

		await renderPanel();
		await renderPanel();

		expect( mockClearTemplateRecommendations ).toHaveBeenCalledTimes( 1 );
		expect( hasText( 'Analyzing template structure…' ) ).toBe( false );
	} );

	test( 'shows the undo success notice even when the last template activity is now undone', async () => {
		currentState = createState( {
			store: {
				activityLog: [
					{
						id: 'activity-1',
						type: 'apply_template_suggestion',
						surface: 'template',
						suggestion: 'Clarify hierarchy',
						target: {
							templateRef: TEMPLATE_REF,
						},
						undo: {
							canUndo: false,
							status: 'undone',
							error: null,
						},
					},
				],
				lastUndoneActivityId: 'activity-1',
				undoStatus: 'success',
			},
		} );

		await renderPanel();

		expect( hasText( 'Undid Clarify hierarchy.' ) ).toBe( true );
	} );

	test( 'shows an undo action on apply success notices and dispatches undo for the latest template activity', async () => {
		currentState = createState( {
			store: {
				activityLog: [
					{
						id: 'activity-1',
						type: 'apply_template_suggestion',
						surface: 'template',
						suggestion: 'Add hero intro',
						target: {
							templateRef: TEMPLATE_REF,
						},
						undo: {
							canUndo: true,
							status: 'available',
							error: null,
						},
					},
				],
				templateApplyError: null,
				templateApplyStatus: 'success',
				templateLastAppliedSuggestionKey: SUGGESTION_KEY,
				templateLastAppliedOperations: [
					{
						type: TEMPLATE_OPERATION_INSERT_PATTERN,
						patternName: 'theme/hero',
					},
				],
				templateSelectedSuggestionKey: null,
			},
		} );

		await renderPanel();

		expect( hasText( 'Applied 1 template operation.' ) ).toBe( true );

		const undoButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( element ) => element.textContent === 'Undo' );

		expect( undoButton ).toBeDefined();

		await act( async () => {
			undoButton.click();
		} );

		expect( mockUndoActivity ).toHaveBeenCalledWith( 'activity-1' );
	} );

	test( 'does not render while editing a page even if edit-site still exposes a template ref', async () => {
		currentState = createState( {
			editor: {
				postId: 42,
				postType: 'page',
			},
			editSite: {
				postId: TEMPLATE_REF,
				postType: 'wp_template',
			},
		} );

		await renderPanel();

		expect(
			getContainer().querySelector(
				'[data-panel-title="AI Template Recommendations"]'
			)
		).toBeNull();
	} );

	test( 'keeps undo history visible when template recommendations are unavailable', async () => {
		currentState = createState( {
			store: {
				activityLog: [
					{
						id: 'activity-1',
						type: 'apply_template_suggestion',
						surface: 'template',
						suggestion: 'Clarify hierarchy',
						target: {
							templateRef: TEMPLATE_REF,
						},
						undo: {
							canUndo: true,
							status: 'available',
							error: null,
						},
						persistence: {
							status: 'server',
						},
					},
				],
			},
		} );
		window.flavorAgentData = {
			canRecommendTemplates: false,
			settingsUrl:
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
		};

		await renderPanel();

		expect(
			getContainer().querySelector(
				'[data-panel-title="AI Template Recommendations"]'
			)
		).not.toBeNull();
		expect( hasText( 'Settings > Flavor Agent' ) ).toBe( true );
		expect( hasText( 'Recent AI Actions' ) ).toBe( true );
		expect( hasText( 'Clarify hierarchy' ) ).toBe( true );
		expect( hasText( 'Undo available' ) ).toBe( true );
		expect( hasText( 'Suggested Composition' ) ).toBe( false );
		expect( getTextarea() ).toBeNull();
	} );

	test( 'recomputes template undo availability when the block tree changes', async () => {
		mockGetTemplateActivityUndoState.mockImplementation(
			( activity, blockEditorSelect ) =>
				( blockEditorSelect?.getBlocks?.() || [] ).length > 0
					? {
							canUndo: true,
							status: 'available',
							error: null,
					  }
					: {
							canUndo: false,
							status: 'failed',
							error: 'Inserted pattern content changed after apply and cannot be undone automatically.',
					  }
		);
		currentState = createState( {
			store: {
				activityLog: [
					{
						id: 'activity-1',
						type: 'apply_template_suggestion',
						surface: 'template',
						suggestion: 'Clarify hierarchy',
						target: {
							templateRef: TEMPLATE_REF,
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

		await renderPanel();

		expect( hasText( 'Undo available' ) ).toBe( true );

		currentState = {
			...getState(),
			blockEditor: {
				...getState().blockEditor,
				blocks: [],
			},
		};

		await renderPanel();

		expect( hasText( 'Undo unavailable' ) ).toBe( true );
		expect(
			hasText(
				'Inserted pattern content changed after apply and cannot be undone automatically.'
			)
		).toBe( true );
	} );

	test( 'renders non-interactive preview tokens in the review overlay', () => {
		expect(
			getContainer().querySelector(
				'.flavor-agent-template-preview .flavor-agent-preview-token--pattern'
			)
		).not.toBeNull();
		expect(
			getContainer().querySelector(
				'.flavor-agent-template-preview .flavor-agent-action-link'
			)
		).toBeNull();
	} );

	test( 'renders anchored template insertion previews against the current block tree', async () => {
		currentState = createState( {
			store: {
				templateRecommendations: [
					{
						...SUGGESTION,
						operations: [
							{
								type: TEMPLATE_OPERATION_INSERT_PATTERN,
								patternName: 'theme/hero',
								placement: 'before_block_path',
								targetPath: [ 1 ],
							},
						],
					},
				],
				templateSelectedSuggestionKey: SUGGESTION_KEY,
			},
		} );

		await renderPanel();

		expect( hasText( 'Before target block (Path 2)' ) ).toBe( true );
		expect( hasText( 'before footer at Path 2.' ) ).toBe( true );
	} );
} );
